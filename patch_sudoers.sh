#!/bin/bash
# =============================================================
# CastPanel - Actualizar sudoers para auto-inicio
# USO: sudo bash /tmp/patch_sudoers.sh
# =============================================================

SUDOERS_FILE="/etc/sudoers.d/castpanel"

echo "=== CastPanel - Parche Sudoers ==="

if [ ! -f "$SUDOERS_FILE" ]; then
    echo "ERROR: $SUDOERS_FILE no existe"
    exit 1
fi

echo "Contenido actual:"
cat "$SUDOERS_FILE"
echo ""

# Verificar si ya tiene firewall-cmd
if grep -q "firewall-cmd" "$SUDOERS_FILE"; then
    echo "✓ firewall-cmd ya está en sudoers"
else
    echo "Agregando permiso para firewall-cmd..."
    echo 'apache ALL=(ALL) NOPASSWD: /usr/bin/firewall-cmd *' >> "$SUDOERS_FILE"
    echo "✓ firewall-cmd agregado"
fi

# Verificar si tiene docker run sin --rm (para --restart unless-stopped)
if grep -q "docker run -d" "$SUDOERS_FILE" || grep -q 'docker run \*' "$SUDOERS_FILE"; then
    echo "✓ docker run -d ya está permitido"
else
    echo "Agregando permiso para docker run -d (contenedores persistentes)..."
    echo 'apache ALL=(ALL) NOPASSWD: /usr/bin/docker run -d --name liquidsoap-*' >> "$SUDOERS_FILE"
    echo "✓ docker run -d agregado"
fi

# Verificar si tiene docker start
if grep -q "docker start" "$SUDOERS_FILE"; then
    echo "✓ docker start ya está en sudoers"
else
    echo "Agregando permiso para docker start..."
    echo 'apache ALL=(ALL) NOPASSWD: /usr/bin/docker start *' >> "$SUDOERS_FILE"
    echo "✓ docker start agregado"
fi

# Verificar si tiene docker restart
if grep -q "docker restart" "$SUDOERS_FILE"; then
    echo "✓ docker restart ya está en sudoers"
else
    echo "Agregando permiso para docker restart..."
    echo 'apache ALL=(ALL) NOPASSWD: /usr/bin/docker restart *' >> "$SUDOERS_FILE"
    echo "✓ docker restart agregado"
fi

# Verificar sintaxis de sudoers
echo ""
echo "Verificando sintaxis sudoers..."
visudo -c -f "$SUDOERS_FILE"
if [ $? -ne 0 ]; then
    echo "ERROR: Sintaxis inválida en sudoers!"
    exit 1
fi

echo ""
echo "Contenido actualizado:"
cat "$SUDOERS_FILE"
echo ""
echo "=== Sudoers actualizado correctamente ==="
