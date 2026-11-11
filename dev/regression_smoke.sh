#!/usr/bin/env bash
# Quick local smoke: HTTP status for main entry points (expects Docker on 8080/2080).
set -euo pipefail
BASE="${BASE_URL:-http://127.0.0.1:8080}"
DJ="${DJ_URL:-http://127.0.0.1:2080}"
ok=0 fail=0
check() {
  local code path
  path="$1"
  code=$(curl -s -o /dev/null -w "%{http_code}" --connect-timeout 5 "$BASE$path" || echo "000")
  if [[ "$code" =~ ^(200|302|301)$ ]]; then
    echo "OK  $code $path"
    ok=$((ok + 1))
  else
    echo "FAIL $code $path"
    fail=$((fail + 1))
  fi
}
check "/"
check "/admin/index.php"
check "/cliente/index.php"
check "/reseller/index.php"
check "/api/nowplaying.php?slug=demo-autodj"
code=$(curl -s -o /dev/null -w "%{http_code}" --connect-timeout 5 "$DJ/" || echo "000")
if [[ "$code" =~ ^(200|302)$ ]]; then
  echo "OK  $code DJ portal /"
  ok=$((ok + 1))
else
  echo "FAIL $code DJ portal /"
  fail=$((fail + 1))
fi
docker exec dev-web-1 php /var/www/castpanel/dev/test_autodj_track_logic.php >/dev/null && echo "OK  autodj track logic" && ok=$((ok + 1)) || { echo "FAIL autodj track logic"; fail=$((fail + 1)); }
echo "--- $ok passed, $fail failed ---"
exit "$fail"
