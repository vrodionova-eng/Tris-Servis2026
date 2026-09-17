#!/usr/bin/env bash
# Isolated Git/filesystem integration tests; php/flock are adapters here.
set -euo pipefail
updater=$(realpath "$(dirname "$0")/../bin/deploy-sync.sh")
test_root=$(mktemp -d)
mkdir -p "$test_root"/{tools,app/data,seed}
cat > "$test_root/tools/php" <<'PHP'
#!/usr/bin/env bash
if [[ "$1" == -r ]]; then printf '%s' "$TRIS_APP/data"; exit 0; fi
[[ -z "${TEST_FAIL_PHP:-}" ]]
PHP
cat > "$test_root/tools/flock" <<'FLOCK'
#!/usr/bin/env bash
exit 0
FLOCK
chmod +x "$test_root/tools/"*
export PATH="$test_root/tools:$PATH"
export TRIS_APP="$test_root/app" TRIS_DEPLOY_HISTORY="$test_root/history"
git init -q --bare "$test_root/remote.git"
git -C "$test_root/seed" init -q -b main
cd "$test_root/seed"
git config user.name Test
git config user.email test@example.invalid
mkdir -p api data css
printf 'old\n' > api/code.php
printf 'remove\n' > obsolete.txt
printf 'server-config\n' > env.php
printf 'server-key\n' > data/key.php
git add . && git commit -qm baseline
export TRIS_INITIAL_REVISION=$(git rev-parse HEAD)
git remote add origin "$test_root/remote.git"
git push -q -u origin main
cp -R api data env.php obsolete.txt "$TRIS_APP/"
printf 'server-only\n' > "$TRIS_APP/local.txt"
git clone -q -b main "$test_root/remote.git" "$test_root/checkout"
printf 'new\n' > api/code.php
printf 'css\n' > css/new.css
git rm -q obsolete.txt data/key.php
printf 'must-not-overwrite\n' > env.php
git add . && git commit -qm update && git push -q
bash "$updater" "$test_root/checkout" > "$test_root/run.log" 2>&1 || { cat "$test_root/run.log"; exit 1; }
[[ $(cat "$TRIS_APP/api/code.php") == new && -f "$TRIS_APP/css/new.css" && ! -f "$TRIS_APP/obsolete.txt" ]]
echo 'PASS add, update, delete tracked application files'
[[ $(cat "$TRIS_APP/env.php") == server-config && $(cat "$TRIS_APP/data/key.php") == server-key && -f "$TRIS_APP/local.txt" ]]
echo 'PASS protected configuration, removed tracked key and untracked file preserved'
grep -rq '^old$' "$TRIS_DEPLOY_HISTORY"/backup.*/files/api/code.php
echo 'PASS previous code backed up'
bash "$updater" "$test_root/checkout" > "$test_root/repeat.log" 2>&1
grep -q 'changed files: 0' "$test_root/repeat.log"
echo 'PASS repeat deployment makes no changes'
printf 'manual-change\n' > "$TRIS_APP/api/code.php"
if bash "$updater" "$test_root/checkout" > "$test_root/conflict.log" 2>&1; then exit 1; fi
grep -q 'STOP: server changes conflict' "$test_root/conflict.log"
[[ $(cat "$TRIS_APP/api/code.php") == manual-change ]]
echo 'PASS server edits block deployment without overwrite'
printf 'new\n' > "$TRIS_APP/api/code.php"
printf 'third\n' > api/code.php
git add . && git commit -qm third && git push -q
if TEST_FAIL_PHP=1 bash "$updater" "$test_root/checkout" > "$test_root/lint.log" 2>&1; then exit 1; fi
[[ $(cat "$TRIS_APP/api/code.php") == new ]]
echo 'PASS failed PHP check prevents deployment'
real_mv=$(command -v mv)
export TEST_REAL_MV="$real_mv"
cat > "$test_root/tools/mv" <<'MV'
#!/usr/bin/env bash
for arg in "$@"; do
    if [[ "$arg" == */full-revision.tmp ]]; then exit 1; fi
done
exec "$TEST_REAL_MV" "$@"
MV
chmod +x "$test_root/tools/mv"
before_revision=$(cat "$TRIS_DEPLOY_HISTORY/full-revision")
if bash "$updater" "$test_root/checkout" > "$test_root/rollback.log" 2>&1; then exit 1; fi
[[ $(cat "$TRIS_APP/api/code.php") == new && $(cat "$TRIS_DEPLOY_HISTORY/full-revision") == "$before_revision" ]]
grep -q 'restoring code' "$test_root/rollback.log"
echo 'PASS failed installation restores previous code and revision'
echo "7 checks passed; isolated fixtures: $test_root"
