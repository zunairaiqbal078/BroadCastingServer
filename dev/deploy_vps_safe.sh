#!/usr/bin/env bash
# Safe VPS deploy — only run after local checks AND explicit approval.
#
# Local first (always):
#   ./dev/regression_smoke.sh
#   docker exec dev-web-1 php /var/www/castpanel/dev/test_autodj_track_logic.php
#
# Then deploy (you must set CONFIRM=1):
#   CONFIRM=1 CASTPANEL_VPS_HOST=ubuntu@148.113.240.183 ./dev/deploy_vps_safe.sh
#
set -euo pipefail

if [[ "${CONFIRM:-}" != "1" ]]; then
  echo "Refusing to deploy: set CONFIRM=1 after you reviewed changes and local tests passed."
  exit 1
fi

HOST="${CASTPANEL_VPS_HOST:-ubuntu@148.113.240.183}"
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
REMOTE="${CASTPANEL_VPS_REMOTE:-$HOST:~/castpanel-sync/safe}"

echo "== Local PHP syntax =="
PHP_LINT="php"
if ! command -v php >/dev/null 2>&1 && docker ps --format '{{.Names}}' 2>/dev/null | grep -q '^dev-web-1$'; then
  PHP_LINT="docker exec dev-web-1 php"
fi
while IFS= read -r f; do
  rel="${f#$ROOT/}"
  $PHP_LINT -l "/var/www/castpanel/$rel" 2>/dev/null || $PHP_LINT -l "$f" >/dev/null
  echo "  OK $rel"
done <<EOF
$ROOT/includes/functions.php
$ROOT/includes/provisioning.php
$ROOT/includes/auth.php
$ROOT/includes/autodj_player.php
$ROOT/includes/shoutcast.php
$ROOT/cliente/index.php
$ROOT/widgets/player/index.php
$ROOT/api/dj_auth.php
$ROOT/api/autodj_player.php
$ROOT/install/castpanel-ctl.sh
EOF

echo "== rsync (explicit paths only) =="
rsync -avz "$ROOT/includes/functions.php" "$ROOT/includes/provisioning.php" "$ROOT/includes/auth.php" \
  "$ROOT/includes/autodj_player.php" "$ROOT/includes/shoutcast.php" \
  "$REMOTE/includes/"
rsync -avz "$ROOT/cliente/index.php" "$REMOTE/cliente-index.php"
rsync -avz "$ROOT/widgets/player/index.php" "$REMOTE/widget-player-index.php"
rsync -avz "$ROOT/api/dj_auth.php" "$REMOTE/dj_auth.php"
rsync -avz "$ROOT/api/autodj_player.php" "$REMOTE/api-autodj_player.php"
rsync -avz "$ROOT/install/castpanel-ctl.sh" "$REMOTE/castpanel-ctl.sh"

echo "== Remote install + smoke =="
ssh "$HOST" bash -s <<'REMOTE'
set -euo pipefail
BASE=~/castpanel-sync/safe
sudo cp "$BASE/includes/"*.php /var/www/castpanel/includes/
sudo cp "$BASE/cliente-index.php" /var/www/castpanel/cliente/index.php
sudo cp "$BASE/widget-player-index.php" /var/www/castpanel/widgets/player/index.php
sudo cp "$BASE/dj_auth.php" /var/www/castpanel/api/dj_auth.php
sudo cp "$BASE/api-autodj_player.php" /var/www/castpanel/api/autodj_player.php
sudo install -m 755 "$BASE/castpanel-ctl.sh" /usr/local/bin/castpanel-ctl
sudo systemctl restart castpanel-ctl
head -8 /var/www/castpanel/cliente/index.php | grep -q 'Dashboard Cliente' || { echo "WRONG cliente/index.php"; exit 1; }
head -3 /var/www/castpanel/widgets/player/index.php | grep -q 'Reproductor' || { echo "WRONG widgets/player"; exit 1; }
code=$(curl -s -o /dev/null -w "%{http_code}" http://127.0.0.1:8080/cliente/index.php)
[[ "$code" == "302" || "$code" == "200" ]] || { echo "cliente/index HTTP $code"; exit 1; }
code=$(curl -s -o /dev/null -w "%{http_code}" "http://127.0.0.1:8080/widgets/player/?slug=demo-autodj-radio")
[[ "$code" == "200" ]] || echo "WARN widget slug HTTP $code (check slug exists)"
echo "Deploy smoke OK"
REMOTE

echo "Done. Optional: sudo -u www-data php /var/www/castpanel/dev/vps_regenerate_streaming.php"
