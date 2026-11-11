#!/usr/bin/env bash
# Deploy panel fixes + demo track to the test VPS (148.113.240.183). Run from repo root on your Mac.
set -euo pipefail
HOST="${CASTPANEL_VPS_HOST:-ubuntu@148.113.240.183}"
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
rsync -avz "$ROOT/includes/" "$HOST:~/castpanel-sync/includes/"
rsync -avz "$ROOT/dev/" "$HOST:~/castpanel-sync/dev/"
rsync -avz "$ROOT/install/castpanel-ctl.sh" "$HOST:~/castpanel-sync/install/castpanel-ctl.sh"
ssh "$HOST" 'set -e
sudo rsync -a ~/castpanel-sync/includes/ /var/www/castpanel/includes/
sudo rsync -a ~/castpanel-sync/dev/ /var/www/castpanel/dev/
sudo install -m 755 ~/castpanel-sync/install/castpanel-ctl.sh /usr/local/bin/castpanel-ctl
sudo systemctl restart castpanel-ctl
sudo -u www-data php /var/www/castpanel/dev/vps_add_demo_track.php
sleep 8
sudo chmod 644 /var/log/liquidsoap/demo-autodj-radio.log 2>/dev/null || true
sudo -u www-data php -r "
require \"/var/www/castpanel/config/database.php\";
require \"/var/www/castpanel/includes/autodj_player.php\";
\$db = getDB();
\$e = \$db->query(\"SELECT * FROM estaciones WHERE slug='"'"'demo-autodj-radio'"'"'\")->fetch(PDO::FETCH_ASSOC);
echo json_encode(castpanelQuickPlayerPayload(\$e), JSON_PRETTY_PRINT), PHP_EOL;
"
curl -s "http://127.0.0.1:8080/api/nowplaying.php?slug=demo-autodj-radio"
echo
'
