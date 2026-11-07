#!/bin/bash
#
# start-all.sh — CastPanel SHOUTcast Auto-Start
# Inicia todos los servidores sc_serv2 que tengan config en /opt/shoutcast/configs/
# Se ejecuta al arrancar el servidor via systemd
#

SC_BIN="/opt/shoutcast/sc_serv2"
CONF_DIR="/opt/shoutcast/configs"
LOG_DIR="/var/log/shoutcast"

# Crear directorio de logs si no existe
mkdir -p "$LOG_DIR"

# Verificar que existe el binario
if [ ! -f "$SC_BIN" ]; then
    echo "ERROR: No se encuentra $SC_BIN"
    exit 1
fi

# Verificar que existe el directorio de configs
if [ ! -d "$CONF_DIR" ]; then
    echo "WARN: No existe $CONF_DIR — nada que iniciar"
    exit 0
fi

STARTED=0
FAILED=0

for conf in "$CONF_DIR"/*.conf; do
    [ -f "$conf" ] || continue

    slug=$(basename "$conf" .conf)

    # Obtener el puerto de la config
    port=$(grep -m1 '^portbase=' "$conf" | cut -d= -f2)

    if [ -z "$port" ]; then
        echo "SKIP: $slug — sin portbase en config"
        continue
    fi

    # Verificar si ya esta corriendo en ese puerto
    if ss -tlnp 2>/dev/null | grep -q ":${port} "; then
        echo "OK: $slug ya corriendo en puerto $port"
        STARTED=$((STARTED + 1))
        continue
    fi

    # Determinar binario correcto
    WRAPPER="/opt/shoutcast/bin/$slug"
    if [ -x "$WRAPPER" ]; then
        BIN_TO_USE="$WRAPPER"
    else
        BIN_TO_USE="$SC_BIN"
    fi

    # Iniciar sc_serv2
    echo "Iniciando $slug en puerto $port ..."
    $BIN_TO_USE daemon "$conf" 2>/dev/null

    # Verificar despues de 2 segundos
    sleep 2
    if ss -tlnp 2>/dev/null | grep -q ":${port} "; then
        echo "  OK: $slug activo en puerto $port"
        STARTED=$((STARTED + 1))
    else
        echo "  ERROR: $slug no inicio en puerto $port"
        FAILED=$((FAILED + 1))
    fi
done

echo ""
echo "SHOUTcast Auto-Start: $STARTED iniciados, $FAILED fallidos"
