#!/bin/bash
# =============================================================
# castpanel-ctl - privileged helper daemon for CastPanel
# =============================================================
# The PHP panel runs as the web user and cannot start SHOUTcast, Docker
# containers, touch the firewall or restart services. It writes one command
# to /var/run/castpanel/command (see castpanelCtl() in
# includes/provisioning.php); this daemon, running as root, executes it and
# writes a JSON answer to /var/run/castpanel/result.
#
# Only the commands below are accepted, and every argument is validated, so
# the web user can never make root run anything else.
#
#   start-shoutcast-<slug>   stop-shoutcast-<slug>
#   start-liquidsoap-<slug>  stop-liquidsoap-<slug>  restart-liquidsoap-<slug>
#   start-icecast            stop-icecast            update-shoutcast-proxy
#   firewall_open <port[-port]/tcp|udp | http|https|ssh>
#   firewall_close <port[-port]/tcp|udp>   firewall_reload   firewall_info
#   service_status|service_start|service_stop|service_restart <service>
#   set_hostname <hostname>  last_logins <n>
#
# Settings can be overridden in /etc/castpanel/ctl.conf (shell syntax).
# =============================================================

IPC_DIR="/var/run/castpanel"
WEB_USER=""
LIQUIDSOAP_IMAGE="savonet/liquidsoap:v2.2.5"
LIQUIDSOAP_CONF_DIR="/etc/liquidsoap/stations"
LIQUIDSOAP_LOG_DIR="/var/log/liquidsoap"
MEDIA_DIR="/var/castpanel/media"
ANUNCIOS_DIR="/var/castpanel/anuncios"
SHOUTCAST_DIR="/opt/shoutcast"
SHOUTCAST_LOG_DIR="/var/log/shoutcast"
[ -f /etc/castpanel/ctl.conf ] && . /etc/castpanel/ctl.conf

if [ -z "$WEB_USER" ]; then
    if id apache >/dev/null 2>&1; then WEB_USER="apache"; else WEB_USER="www-data"; fi
fi

log() { echo "$(date '+%F %T') $*"; }

# ---------- JSON helpers ----------
json_str() {  # escape a string for JSON
    printf '%s' "$1" | sed -e 's/\\/\\\\/g' -e 's/"/\\"/g' -e 's/\t/\\t/g' -e 's/\r//g' \
        | awk 'BEGIN{ORS=""} NR>1{print "\\n"} {print}'
}
REPLY_JSON=""
ok()   { REPLY_JSON="{\"success\":true${1:+,$1}}"; }                       # ok '"output":"..."'
fail() { REPLY_JSON="{\"success\":false,\"error\":\"$(json_str "$1")\"}"; }

# ---------- validation ----------
valid_slug()  { [[ "$1" =~ ^[a-z0-9][a-z0-9_-]{0,63}$ ]]; }
valid_ports() { [[ "$1" =~ ^[0-9]{1,5}(-[0-9]{1,5})?/(tcp|udp)$ ]]; }

# ---------- service names (panel name -> real unit on this distro) ----------
resolve_service() {
    local c
    case "$1" in
        httpd|apache2|apache) set -- httpd apache2 ;;
        mariadb|mysql)        set -- mariadb mysql ;;
        sshd|ssh)             set -- sshd ssh ;;
        icecast|icecast2)     set -- icecast icecast2 ;;
        crond|cron)           set -- crond cron ;;
        docker|firewalld|fail2ban|postfix|liquidsoap|castpanel-shoutcast|php-fpm) set -- "$1" ;;
        *) return 1 ;;
    esac
    for c in "$@"; do
        if systemctl list-unit-files "$c.service" 2>/dev/null | grep -q "^$c.service"; then
            echo "$c"; return 0
        fi
    done
    echo "$1"
}

# ---------- SHOUTcast ----------
sc_pid() { pgrep -f "$SHOUTCAST_DIR/configs/$1.conf" | head -1; }

sc_binary() {  # binary chosen by the panel, falling back to the installed one
    local conf="$SHOUTCAST_DIR/configs/$1.conf" b
    b=$(grep -m1 '^; sc_binary=' "$conf" 2>/dev/null | cut -d= -f2-)
    for cand in "$b" "$SHOUTCAST_DIR/sc_serv2" /usr/local/bin/sc_serv2 "$SHOUTCAST_DIR/sc_serv"; do
        [ -n "$cand" ] && [ -x "$cand" ] && { echo "$cand"; return 0; }
    done
    return 1
}

start_shoutcast() {
    local slug="$1" conf="$SHOUTCAST_DIR/configs/$1.conf" bin pid
    [ -f "$conf" ] || { fail "SHOUTcast config not found: $conf"; return; }
    pid=$(sc_pid "$slug")
    if [ -n "$pid" ]; then ok "\"output\":\"$pid\",\"message\":\"already running\""; return; fi
    bin=$(sc_binary "$slug") || { fail "SHOUTcast binary (sc_serv2 / sc_serv) not found in $SHOUTCAST_DIR"; return; }
    mkdir -p "$SHOUTCAST_LOG_DIR" && chown "$WEB_USER" "$SHOUTCAST_LOG_DIR"
    # run as the web user, never as root
    ( cd "$SHOUTCAST_DIR" && runuser -u "$WEB_USER" -- "$bin" daemon "$conf" >/dev/null 2>&1 ) &
    for _ in 1 2 3 4 5 6 7 8 9 10; do
        sleep 0.5; pid=$(sc_pid "$slug"); [ -n "$pid" ] && break
    done
    if [ -n "$pid" ]; then
        ok "\"output\":\"$pid\""
    else
        fail "sc_serv2 did not start: $(tail -n 5 "$SHOUTCAST_LOG_DIR/$slug.log" 2>/dev/null)"
    fi
}

stop_shoutcast() {
    local slug="$1"
    pkill -f "$SHOUTCAST_DIR/configs/$slug.conf" 2>/dev/null
    for _ in 1 2 3 4 5 6; do sleep 0.5; [ -z "$(sc_pid "$slug")" ] && break; done
    pkill -9 -f "$SHOUTCAST_DIR/configs/$slug.conf" 2>/dev/null
    ok
}

# ---------- Liquidsoap (one Docker container per station) ----------
start_liquidsoap() {
    local slug="$1" name="liquidsoap-$1" conf="$LIQUIDSOAP_CONF_DIR/$1.liq" state
    [ -f "$conf" ] || { fail "Liquidsoap config not found: $conf"; return; }
    command -v docker >/dev/null 2>&1 || { fail "docker is not installed"; return; }
    mkdir -p "$MEDIA_DIR/$slug" "$ANUNCIOS_DIR/$slug" "$LIQUIDSOAP_LOG_DIR"
    chmod 1777 "$LIQUIDSOAP_LOG_DIR"          # container user must be able to write its log
    docker rm -f "$name" >/dev/null 2>&1
    # host network: reaches SHOUTcast/Icecast on 127.0.0.1, exposes harbor + telnet ports
    if ! out=$(docker run -d --name "$name" --restart unless-stopped --network host \
            -v "$LIQUIDSOAP_CONF_DIR:/config:ro" \
            -v "$MEDIA_DIR/$slug:/media:ro" \
            -v "$ANUNCIOS_DIR/$slug:/anuncios:ro" \
            -v "$LIQUIDSOAP_LOG_DIR:$LIQUIDSOAP_LOG_DIR" \
            "$LIQUIDSOAP_IMAGE" liquidsoap "/config/$slug.liq" 2>&1); then
        fail "docker run failed: $out"; return
    fi
    sleep 4   # let Liquidsoap parse the config; a bad config exits right away
    state=$(docker inspect -f '{{.State.Running}}' "$name" 2>/dev/null)
    if [ "$state" = "true" ]; then
        ok "\"output\":\"$name\""
    else
        fail "Liquidsoap stopped right after starting: $(docker logs --tail 15 "$name" 2>&1)"
    fi
}

stop_liquidsoap() {
    docker rm -f "liquidsoap-$1" >/dev/null 2>&1
    ok
}

# ---------- Apache ----------
reload_apache() {
    local svc ctl
    svc=$(resolve_service httpd)
    if command -v apache2ctl >/dev/null 2>&1; then ctl=apache2ctl; else ctl=apachectl; fi
    if ! out=$($ctl configtest 2>&1); then fail "Apache config test failed: $out"; return; fi
    if systemctl reload "$svc" 2>/dev/null; then ok; else fail "could not reload $svc"; fi
}

# ---------- firewall ----------
fw_kind() {
    if command -v firewall-cmd >/dev/null 2>&1 && firewall-cmd --state >/dev/null 2>&1; then echo firewalld
    elif command -v ufw >/dev/null 2>&1 && ufw status 2>/dev/null | grep -q "Status: active"; then echo ufw
    else echo none; fi
}

firewall_open() {
    local a="$1" kind; kind=$(fw_kind)
    [[ "$a" =~ ^(http|https|ssh)$ ]] || valid_ports "$a" || { fail "invalid port: $a"; return; }
    case "$kind" in
        firewalld)
            if [[ "$a" =~ ^(http|https|ssh)$ ]]; then firewall-cmd --permanent --add-service="$a" >/dev/null
            else firewall-cmd --permanent --add-port="$a" >/dev/null; fi && ok || fail "firewall-cmd failed" ;;
        ufw)
            ufw allow "${a/-/:}" >/dev/null && ok || fail "ufw failed" ;;
        *) ok "\"message\":\"no active firewall\"" ;;
    esac
}

firewall_close() {
    local a="$1" kind; kind=$(fw_kind)
    valid_ports "$a" || { fail "invalid port: $a"; return; }
    case "$kind" in
        firewalld) firewall-cmd --permanent --remove-port="$a" >/dev/null && ok || fail "firewall-cmd failed" ;;
        ufw)       ufw delete allow "${a/-/:}" >/dev/null && ok || fail "ufw failed" ;;
        *)         ok "\"message\":\"no active firewall\"" ;;
    esac
}

firewall_reload() {
    case "$(fw_kind)" in
        firewalld) firewall-cmd --reload >/dev/null && ok || fail "firewall-cmd --reload failed" ;;
        ufw)       ufw reload >/dev/null && ok || fail "ufw reload failed" ;;
        *)         ok "\"message\":\"no active firewall\"" ;;
    esac
}

firewall_info() {
    local ports services zone
    case "$(fw_kind)" in
        firewalld)
            zone=$(firewall-cmd --get-default-zone 2>/dev/null)
            ports=$(firewall-cmd --list-ports 2>/dev/null)
            services=$(firewall-cmd --list-services 2>/dev/null)
            ok "\"active\":true,\"zone\":\"$(json_str "$zone")\",\"ports\":\"$(json_str "$ports")\",\"services\":\"$(json_str "$services")\"" ;;
        ufw)
            ports=$(ufw status 2>/dev/null | awk '/ALLOW/ {print $1}' | grep -v '(v6)' | sort -u | tr '\n' ' ')
            ok "\"active\":true,\"zone\":\"ufw\",\"ports\":\"$(json_str "$ports")\",\"services\":\"\"" ;;
        *)  ok "\"active\":false,\"zone\":\"\",\"ports\":\"\",\"services\":\"\"" ;;
    esac
}

# ---------- services ----------
service_action() {
    local action="$1" svc
    svc=$(resolve_service "$2") || { fail "service not allowed: $2"; return; }
    if [ "$action" = "status" ]; then
        local st en
        st=$(systemctl is-active "$svc" 2>/dev/null)
        systemctl is-enabled "$svc" >/dev/null 2>&1 && en=true || en=false
        ok "\"status\":\"$(json_str "${st:-unknown}")\",\"enabled\":$en,\"service\":\"$svc\""
        return
    fi
    if out=$(systemctl "$action" "$svc" 2>&1); then ok; else fail "systemctl $action $svc: $out"; fi
}

# ---------- dispatcher ----------
handle() {
    local cmd="$1" arg="$2"
    case "$cmd" in
        start-shoutcast-*)   s="${cmd#start-shoutcast-}";   valid_slug "$s" && start_shoutcast "$s"  || fail "invalid station: $s" ;;
        stop-shoutcast-*)    s="${cmd#stop-shoutcast-}";    valid_slug "$s" && stop_shoutcast "$s"   || fail "invalid station: $s" ;;
        start-liquidsoap-*)  s="${cmd#start-liquidsoap-}";  valid_slug "$s" && start_liquidsoap "$s" || fail "invalid station: $s" ;;
        stop-liquidsoap-*)   s="${cmd#stop-liquidsoap-}";   valid_slug "$s" && stop_liquidsoap "$s"  || fail "invalid station: $s" ;;
        restart-liquidsoap-*) s="${cmd#restart-liquidsoap-}"; valid_slug "$s" && start_liquidsoap "$s" || fail "invalid station: $s" ;;
        start-icecast)       service_action start icecast ;;
        stop-icecast)        service_action stop icecast ;;
        update-shoutcast-proxy) reload_apache ;;
        firewall_open)       firewall_open "$arg" ;;
        firewall_close)      firewall_close "$arg" ;;
        firewall_reload)     firewall_reload ;;
        firewall_info)       firewall_info ;;
        service_status)      service_action status "$arg" ;;
        service_start)       service_action start "$arg" ;;
        service_stop)        service_action stop "$arg" ;;
        service_restart)     service_action restart "$arg" ;;
        set_hostname)
            if [[ "$arg" =~ ^[a-zA-Z0-9]([a-zA-Z0-9.-]{0,251}[a-zA-Z0-9])?$ ]] && out=$(hostnamectl set-hostname "$arg" 2>&1); then ok
            else fail "could not set hostname: ${out:-invalid name}"; fi ;;
        last_logins)
            [[ "$arg" =~ ^[0-9]{1,2}$ ]] || arg=10
            ok "\"output\":\"$(json_str "$(last -n "$arg" 2>/dev/null)")\"" ;;
        stream_start|stream_stop)
            fail "$cmd is not supported (the panel does not send its parameters)" ;;
        *)  fail "unknown command: $cmd" ;;
    esac
}

# ---------- main loop ----------
[ "$(id -u)" = "0" ] || { echo "castpanel-ctl must run as root"; exit 1; }

prepare_ipc() {
    mkdir -p "$IPC_DIR"
    chown root:"$WEB_USER" "$IPC_DIR"
    chmod 2770 "$IPC_DIR"        # only root and the web user can talk to the daemon
}
prepare_ipc
log "castpanel-ctl started (web user: $WEB_USER)"

while true; do
    [ -d "$IPC_DIR" ] || prepare_ipc
    if [ -f "$IPC_DIR/command" ]; then
        sleep 0.1                                   # let PHP finish writing the file
        line=$(head -c 512 "$IPC_DIR/command" | tr -d '\r' | head -n 1)
        rm -f "$IPC_DIR/command"
        read -r cmd arg extra <<< "$line"
        if [ -n "$extra" ]; then
            fail "too many arguments"
        else
            handle "$cmd" "$arg"
        fi
        log "$cmd ${arg:-} -> $(printf '%s' "$REPLY_JSON" | head -c 200)"
        printf '%s' "$REPLY_JSON" > "$IPC_DIR/result.tmp"
        chown root:"$WEB_USER" "$IPC_DIR/result.tmp"; chmod 660 "$IPC_DIR/result.tmp"
        mv -f "$IPC_DIR/result.tmp" "$IPC_DIR/result"
    fi
    sleep 0.2
done
