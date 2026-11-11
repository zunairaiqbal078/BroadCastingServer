#!/bin/bash
# =============================================================
# Install / update castpanel-ctl (the root helper the panel talks to)
# =============================================================
# Works on a new server and on an existing one. An existing
# /usr/local/bin/castpanel-ctl is backed up first (castpanel-ctl.bak-<date>),
# so it can be restored with:
#   cp /usr/local/bin/castpanel-ctl.bak-<date> /usr/local/bin/castpanel-ctl
#   systemctl restart castpanel-ctl
#
# Usage (as root):  bash install/setup_castpanel_ctl.sh [PANEL_DIR]
# Optional env:     WEB_USER, LIQUIDSOAP_IMAGE, LIQUIDSOAP_CONF_DIR, MEDIA_DIR, ANUNCIOS_DIR
# =============================================================

PANEL_DIR="${1:-/var/www/castpanel}"
SRC="$PANEL_DIR/install/castpanel-ctl.sh"
UNIT_SRC="$PANEL_DIR/install/systemd/castpanel-ctl.service"

ok()   { echo "  [OK]    $1"; }
warn() { echo "  [!]     $1"; }
fail() { echo "  [ERROR] $1"; exit 1; }

[ "$(id -u)" = "0" ] || fail "run as root"
[ -f "$SRC" ] || fail "not found: $SRC"
command -v systemctl >/dev/null 2>&1 || fail "systemd is required"

if [ -z "$WEB_USER" ]; then
    if id apache >/dev/null 2>&1; then WEB_USER="apache"; else WEB_USER="www-data"; fi
fi

# Back up whatever daemon is there now (e.g. the original one on a live server)
for old in /usr/local/bin/castpanel-ctl /usr/local/bin/castpanel-ctl.sh; do
    if [ -f "$old" ] && ! cmp -s "$old" "$SRC"; then
        cp -p "$old" "$old.bak-$(date +%Y%m%d%H%M%S)"
        ok "backup of existing $(basename "$old") saved next to it"
    fi
done
[ -f /etc/systemd/system/castpanel-ctl.service ] && \
    cp -p /etc/systemd/system/castpanel-ctl.service "/etc/systemd/system/castpanel-ctl.service.bak-$(date +%Y%m%d%H%M%S)"

install -m 755 "$SRC" /usr/local/bin/castpanel-ctl
mkdir -p /etc/castpanel
if [ ! -f /etc/castpanel/ctl.conf ]; then
    cat > /etc/castpanel/ctl.conf <<CONFEOF
# castpanel-ctl settings
WEB_USER="$WEB_USER"
LIQUIDSOAP_IMAGE="${LIQUIDSOAP_IMAGE:-savonet/liquidsoap:v2.2.5}"
LIQUIDSOAP_CONF_DIR="${LIQUIDSOAP_CONF_DIR:-/var/castpanel/liquidsoap/stations}"
MEDIA_DIR="${MEDIA_DIR:-/var/castpanel/media}"
ANUNCIOS_DIR="${ANUNCIOS_DIR:-/var/castpanel/anuncios}"
CONFEOF
else
    if grep -q '^LIQUIDSOAP_CONF_DIR=' /etc/castpanel/ctl.conf; then
        sed -i 's|^LIQUIDSOAP_CONF_DIR=.*|LIQUIDSOAP_CONF_DIR="/var/castpanel/liquidsoap/stations"|' /etc/castpanel/ctl.conf
    else
        echo 'LIQUIDSOAP_CONF_DIR="/var/castpanel/liquidsoap/stations"' >> /etc/castpanel/ctl.conf
    fi
fi

# Folders the panel (web user) writes into
mkdir -p /opt/shoutcast/configs /opt/shoutcast/bin /var/log/shoutcast
chown -R "$WEB_USER:$WEB_USER" /opt/shoutcast/configs /opt/shoutcast/bin /var/log/shoutcast

# AutoDJ: PHP must write <slug>.liq here before Docker starts Liquidsoap
LIQ_DIR="${LIQUIDSOAP_CONF_DIR:-/var/castpanel/liquidsoap/stations}"
mkdir -p "$LIQ_DIR" /var/log/liquidsoap /var/run/liquidsoap
chown -R "$WEB_USER:$WEB_USER" "$LIQ_DIR" /var/run/liquidsoap
chmod 1777 /var/log/liquidsoap
chmod 775 "$LIQ_DIR"
ok "Liquidsoap config dir writable by $WEB_USER ($LIQ_DIR)"

mkdir -p /var/castpanel/media /var/castpanel/anuncios
chown -R "$WEB_USER:$WEB_USER" /var/castpanel/media /var/castpanel/anuncios
chmod 775 /var/castpanel/media /var/castpanel/anuncios

mkdir -p /var/run/castpanel
chown root:"$WEB_USER" /var/run/castpanel
chmod 2770 /var/run/castpanel
# Newer SHOUTcast downloads name the binary "sc_serv"; the panel expects sc_serv2
if [ ! -e /opt/shoutcast/sc_serv2 ] && [ -f /opt/shoutcast/sc_serv ]; then
    chmod +x /opt/shoutcast/sc_serv
    ln -s /opt/shoutcast/sc_serv /opt/shoutcast/sc_serv2
    ok "linked /opt/shoutcast/sc_serv2 -> sc_serv"
fi
[ -x /opt/shoutcast/sc_serv2 ] && [ ! -e /usr/local/bin/sc_serv2 ] && ln -s /opt/shoutcast/sc_serv2 /usr/local/bin/sc_serv2

cp "$UNIT_SRC" /etc/systemd/system/castpanel-ctl.service
systemctl daemon-reload
systemctl enable castpanel-ctl >/dev/null 2>&1
systemctl restart castpanel-ctl
sleep 1
if systemctl is-active --quiet castpanel-ctl; then
    ok "castpanel-ctl running (log: /var/log/castpanel-ctl.log)"
else
    fail "castpanel-ctl did not start: journalctl -u castpanel-ctl -n 30"
fi
