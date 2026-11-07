#!/bin/bash
# =============================================================
# CastPanel v2.0 - Script de gestión de estaciones Liquidsoap
# =============================================================
# Uso:
#   ./manage.sh start   <slug>   - Iniciar estación
#   ./manage.sh stop    <slug>   - Detener estación
#   ./manage.sh restart <slug>   - Reiniciar estación
#   ./manage.sh status  <slug>   - Ver estado
#   ./manage.sh startall         - Iniciar todas
#   ./manage.sh stopall          - Detener todas
# =============================================================

CONFIG_DIR="/etc/liquidsoap/stations"
PID_DIR="/var/run/liquidsoap"
LOG_DIR="/var/log/liquidsoap"

mkdir -p "$PID_DIR" "$LOG_DIR"

start_station() {
    local slug="$1"
    local config="$CONFIG_DIR/${slug}.liq"
    local pidfile="$PID_DIR/${slug}.pid"

    if [ ! -f "$config" ]; then
        echo "ERROR: No se encontró configuración para '$slug'"
        echo "       Ejecute: php /path/to/castpanel/liquidsoap/generate_config.php <estacion_id>"
        exit 1
    fi

    if [ -f "$pidfile" ] && kill -0 "$(cat "$pidfile")" 2>/dev/null; then
        echo "La estación '$slug' ya está corriendo (PID: $(cat "$pidfile"))"
        return 1
    fi

    echo "Iniciando estación '$slug'..."
    liquidsoap "$config" &
    local pid=$!
    echo $pid > "$pidfile"
    echo "Estación '$slug' iniciada (PID: $pid)"
}

stop_station() {
    local slug="$1"
    local pidfile="$PID_DIR/${slug}.pid"

    if [ ! -f "$pidfile" ]; then
        echo "La estación '$slug' no está corriendo"
        return 1
    fi

    local pid=$(cat "$pidfile")
    if kill -0 "$pid" 2>/dev/null; then
        echo "Deteniendo estación '$slug' (PID: $pid)..."
        kill "$pid"
        sleep 2
        if kill -0 "$pid" 2>/dev/null; then
            kill -9 "$pid"
        fi
        echo "Estación '$slug' detenida"
    else
        echo "El proceso $pid ya no existe"
    fi

    rm -f "$pidfile"
}

restart_station() {
    local slug="$1"
    stop_station "$slug"
    sleep 1
    start_station "$slug"
}

status_station() {
    local slug="$1"
    local pidfile="$PID_DIR/${slug}.pid"

    if [ -f "$pidfile" ] && kill -0 "$(cat "$pidfile")" 2>/dev/null; then
        echo "[$slug] ONLINE (PID: $(cat "$pidfile"))"
    else
        echo "[$slug] OFFLINE"
    fi
}

start_all() {
    echo "Iniciando todas las estaciones..."
    for config in "$CONFIG_DIR"/*.liq; do
        [ -f "$config" ] || continue
        local slug=$(basename "$config" .liq)
        start_station "$slug"
    done
}

stop_all() {
    echo "Deteniendo todas las estaciones..."
    for pidfile in "$PID_DIR"/*.pid; do
        [ -f "$pidfile" ] || continue
        local slug=$(basename "$pidfile" .pid)
        stop_station "$slug"
    done
}

# Main
case "$1" in
    start)
        [ -z "$2" ] && { echo "Uso: $0 start <slug>"; exit 1; }
        start_station "$2"
        ;;
    stop)
        [ -z "$2" ] && { echo "Uso: $0 stop <slug>"; exit 1; }
        stop_station "$2"
        ;;
    restart)
        [ -z "$2" ] && { echo "Uso: $0 restart <slug>"; exit 1; }
        restart_station "$2"
        ;;
    status)
        if [ -n "$2" ]; then
            status_station "$2"
        else
            echo "=== Estado de Estaciones ==="
            for config in "$CONFIG_DIR"/*.liq; do
                [ -f "$config" ] || continue
                status_station "$(basename "$config" .liq)"
            done
        fi
        ;;
    startall)
        start_all
        ;;
    stopall)
        stop_all
        ;;
    *)
        echo "CastPanel - Gestión de Liquidsoap"
        echo ""
        echo "Uso: $0 {start|stop|restart|status|startall|stopall} [slug]"
        echo ""
        echo "Comandos:"
        echo "  start   <slug>  - Iniciar una estación"
        echo "  stop    <slug>  - Detener una estación"
        echo "  restart <slug>  - Reiniciar una estación"
        echo "  status  [slug]  - Ver estado (todas si no se especifica)"
        echo "  startall        - Iniciar todas las estaciones"
        echo "  stopall         - Detener todas las estaciones"
        exit 1
        ;;
esac
