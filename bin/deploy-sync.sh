#!/usr/bin/env bash
# Deploy tracked application files, preserving server configuration and data.
set -Eeuo pipefail
umask 022
repo=$(realpath "${1:-/opt/tris2026}")
app=$(realpath "${TRIS_APP:-/var/www/Tris-Servis2026}")
history=${TRIS_DEPLOY_HISTORY:-/var/lib/tris2026-deploy}
[[ "$app" != / && "$app" != "$repo" && -f "$app/env.php" ]] || { echo 'Invalid application directory'; exit 1; }
mkdir -p "$history"
chmod 700 "$history"
exec 9>"$history/update.lock"
flock -n 9 || { echo 'Another deployment is running'; exit 1; }
cd "$repo"
[[ $(git branch --show-current) == main ]] || { echo 'Expected main branch'; exit 1; }
git diff --quiet && git diff --cached --quiet || { echo 'Tracked files have local changes'; exit 1; }
git pull --ff-only origin main
revision=$(git rev-parse HEAD)
previous=${TRIS_INITIAL_REVISION:-09c6dbc}
if [[ -f "$history/full-revision" ]]; then previous=$(cat "$history/full-revision"); fi
git cat-file -e "$previous^{commit}"

protected() {
    case "$1" in
        env.php|env.*|.env|.env.*|data|data/*|uploads|uploads/*|logs|logs/*|cache|cache/*|storage|storage/*|\
        .git|.git/*|.idea|.idea/*|.superpowers|.superpowers/*|.codex|.codex/*|.agents|.agents/*|\
        tests|tests/*|.gitignore|.gitattributes|*.pem|*.key|*.p12|*.pfx) return 0;;
        *) return 1;;
    esac
}
declare -A old new modes
read_tree() {
    local ref=$1 which=$2 record metadata path mode type hash
    while IFS= read -r -d '' record; do
        metadata=${record%%$'\t'*}; path=${record#*$'\t'}
        protected "$path" && continue
        read -r mode type hash <<< "$metadata"
        [[ "$mode" == 100644 || "$mode" == 100755 ]] || { echo "Unsupported file type: $path"; exit 1; }
        if [[ "$which" == old ]]; then old["$path"]=$hash;
        else new["$path"]=$hash; modes["$path"]=$mode; fi
    done < <(git ls-tree -r -z "$ref")
}
read_tree "$previous" old
read_tree HEAD new
# Migration from the old updater, which deployed only two files.
if [[ ! -f "$history/full-revision" && -f "$history/revision" ]]; then
    sync_previous=$(cat "$history/revision")
    for path in api/sync.php bin/process.php; do old["$path"]=$(git rev-parse "$sync_previous:$path"); done
fi
for path in "${!new[@]}"; do
    if [[ "$path" == *.php ]]; then php -l "$repo/$path"; fi
done
if [[ -f "$repo/tests/sync-regression.php" ]]; then php "$repo/tests/sync-regression.php"; fi
data_root=$(php -r 'require $argv[1]; echo DATA_ROOT;' "$app/env.php")
[[ -d "$data_root" ]] || { echo 'DATA_ROOT does not exist'; exit 1; }
exec 8>"$data_root/cron.lock"; flock -w 120 8
exec 7>"$data_root/color-links.lock"; flock -w 120 7
exec 6>"$data_root/ensure-dates.lock"; flock -w 120 6

declare -A seen
changes=()
for path in "${!old[@]}" "${!new[@]}"; do
    [[ -n "${seen[$path]:-}" ]] && continue
    seen["$path"]=1
    resolved=$(realpath -m "$app/$path")
    [[ "$resolved" == "$app/$path" && ! -L "$app/$path" ]] || { echo "Unsafe server path: $path"; exit 1; }
    if [[ -e "$app/$path" && ! -f "$app/$path" ]]; then echo "Not a regular file: $path"; exit 1; fi
    actual=''
    if [[ -f "$app/$path" ]]; then actual=$(git hash-object --no-filters "$app/$path"); fi
    target=${new[$path]:-}; expected=${old[$path]:-}
    [[ "$actual" == "$target" ]] && continue
    [[ "$actual" == "$expected" ]] || { echo "STOP: server changes conflict with Git: $path"; exit 1; }
    changes+=("$path")
done
backup=$(mktemp -d "$history/backup.XXXXXXXX")
printf '%s\n' "$previous" > "$backup/revision"
for path in "${changes[@]}"; do
    if [[ -f "$app/$path" ]]; then
        mkdir -p "$backup/files/$(dirname "$path")"
        cp -p "$app/$path" "$backup/files/$path"
    fi
    printf '%s\0' "$path" >> "$backup/changed-paths"
done
rollback() {
    trap - ERR INT TERM
    echo "Deployment failed; restoring code from $backup" >&2
    for path in "${changes[@]}"; do
        if [[ -f "$backup/files/$path" ]]; then cp -p "$backup/files/$path" "$app/$path";
        else rm -f -- "$app/$path"; fi
    done
    exit 1
}
trap rollback ERR INT TERM
for path in "${changes[@]}"; do
    if [[ -z "${new[$path]:-}" ]]; then
        rm -- "$app/$path"
        echo "DELETE $path"
    else
        mkdir -p "$app/$(dirname "$path")"
        temp=$(mktemp "$(dirname "$app/$path")/.deploy.XXXXXXXX")
        if [[ -f "$app/$path" ]]; then cp -p "$app/$path" "$temp"; fi
        git show "HEAD:$path" > "$temp"
        if [[ ! -f "$app/$path" ]]; then
            if [[ "${modes[$path]}" == 100755 ]]; then chmod 755 "$temp"; else chmod 644 "$temp"; fi
        fi
        mv -f "$temp" "$app/$path"
        echo "WRITE $path"
    fi
done
printf '%s\n' "$revision" > "$history/full-revision.tmp"
mv -f "$history/full-revision.tmp" "$history/full-revision"
trap - ERR INT TERM
echo "Deployed $revision; changed files: ${#changes[@]}"
echo "Code backup: $backup"
echo 'Server configuration, data and files outside the Git deployment list were preserved.'
