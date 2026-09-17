#!/usr/bin/env bash
# Deploy only the two booking-sync files. Never copy env.php or data/.
set -Eeuo pipefail
umask 077
repo=${1:-/opt/tris2026}
app=/var/www/Tris-Servis2026
history=/var/lib/tris2026-deploy
mkdir -p "$history"
exec 9>"$history/update.lock"
flock -n 9 || { echo 'Another deployment is running'; exit 1; }
cd "$repo"
[[ $(git branch --show-current) == main ]] || { echo 'Expected main branch'; exit 1; }
git diff --quiet && git diff --cached --quiet || { echo 'Tracked files have local changes'; exit 1; }
git pull --ff-only origin main
revision=$(git rev-parse HEAD)
# Initial baseline was compared against the live server by the operator.
previous=09c6dbc
if [[ -f "$history/revision" ]]; then previous=$(cat "$history/revision"); fi
files=(api/sync.php bin/process.php)
for file in "${files[@]}"; do php -l "$repo/$file"; done
php "$repo/tests/sync-regression.php"

# Use the same data location and locks as the running cron workers.
data_root=$(php -r 'require $argv[1]; echo DATA_ROOT;' "$app/env.php")
[[ -d "$data_root" ]] || { echo 'DATA_ROOT does not exist'; exit 1; }
exec 8>"$data_root/cron.lock"
flock -w 120 8 || { echo 'Sync is busy'; exit 1; }
exec 7>"$data_root/color-links.lock"
flock -w 120 7 || { echo 'Color worker is busy'; exit 1; }

# Do not overwrite edits made directly on the server.
for file in "${files[@]}"; do
    expected=$(git rev-parse "$previous:$file")
    actual=$(git hash-object --no-filters "$app/$file")
    target=$(git rev-parse "HEAD:$file")
    [[ "$actual" == "$expected" || "$actual" == "$target" ]] || {
        echo "STOP: server file differs from the recorded version: $file"
        exit 1
    }
done
backup=$(mktemp -d "$history/backup.XXXXXXXX")
mkdir -p "$backup/api" "$backup/bin"
for file in "${files[@]}"; do cp -p "$app/$file" "$backup/$file"; done
printf '%s\n' "$previous" > "$backup/revision"
rollback() {
    echo "Deployment failed; restoring code from $backup" >&2
    for file in "${files[@]}"; do cp -p "$backup/$file" "$app/$file"; done
    exit 1
}
trap rollback ERR
for file in "${files[@]}"; do
    # Preserve live ownership/mode; rename each prepared file atomically.
    cp -p "$app/$file" "$app/$file.deploy-tmp"
    cat "$repo/$file" > "$app/$file.deploy-tmp"
    mv -f "$app/$file.deploy-tmp" "$app/$file"
done
printf '%s\n' "$revision" > "$history/revision.tmp"
mv -f "$history/revision.tmp" "$history/revision"
trap - ERR
echo "Deployed $revision (api/sync.php and bin/process.php only)"
echo "Code backup: $backup"
echo 'Settings, credentials and application state were not copied.'
