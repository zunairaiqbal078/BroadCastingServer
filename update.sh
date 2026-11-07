#!/bin/bash
# =============================================================
# CastPanel v2.3 - Actualizador
# Descarga la ultima version desde tvpanelec.com y actualiza el VPS
# =============================================================
# Uso:
#   curl -L https://tvpanelec.com/cast/update.sh | sudo bash
# =============================================================

set -e

RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
CYAN='\033[0;36m'
NC='\033[0m'

PANEL_DIR="/var/www/castpanel"
PANEL_ZIP_URL="https://tvpanelec.com/cast/download.php?token=CP23-c9543eeec7b3bb0aebaa610c6e71c41f2956eb84"
TMP_ZIP="/tmp/castpanel_update.zip"

echo -e "${CYAN}"
echo "  ╔═══════════════════════════════════════╗"
echo "  ║   CastPanel v2.3 - Actualizador       ║"
echo "  ╚═══════════════════════════════════════╝"
echo -e "${NC}"

# Verificar que el panel esta instalado
if [ ! -d "$PANEL_DIR" ]; then
    echo -e "${RED}Error: No se encontro CastPanel en $PANEL_DIR${NC}"
    echo "Primero instale CastPanel con install.sh"
    exit 1
fi

# Descargar ZIP actualizado
echo -e "${YELLOW}[1/4] Descargando actualizacion...${NC}"
curl -sL "$PANEL_ZIP_URL" -o "$TMP_ZIP"

if [ ! -f "$TMP_ZIP" ] || [ $(stat -c%s "$TMP_ZIP" 2>/dev/null || echo 0) -lt 1000 ]; then
    echo -e "${RED}Error: No se pudo descargar el ZIP. Verifique que el archivo este en cPanel.${NC}"
    exit 1
fi

echo -e "${GREEN}  Descargado OK ($(du -h $TMP_ZIP | cut -f1))${NC}"

# Backup rapido de config
echo -e "${YELLOW}[2/4] Respaldando configuracion...${NC}"
if [ -f "$PANEL_DIR/config/database.php" ]; then
    cp "$PANEL_DIR/config/database.php" "$PANEL_DIR/config/database.php.bak"
    echo -e "${GREEN}  database.php respaldado${NC}"
fi

# Extraer archivos (sobreescribir)
echo -e "${YELLOW}[3/4] Actualizando archivos del panel...${NC}"
unzip -o "$TMP_ZIP" -d "$PANEL_DIR/" > /dev/null 2>&1

# Restaurar config original (no sobreescribir la config del VPS)
if [ -f "$PANEL_DIR/config/database.php.bak" ]; then
    mv "$PANEL_DIR/config/database.php.bak" "$PANEL_DIR/config/database.php"
    echo -e "${GREEN}  database.php restaurado (configuracion del VPS preservada)${NC}"
fi

# Permisos
chown -R www-data:www-data "$PANEL_DIR/" 2>/dev/null || chown -R apache:apache "$PANEL_DIR/" 2>/dev/null || chown -R nginx:nginx "$PANEL_DIR/" 2>/dev/null || true
chmod -R 755 "$PANEL_DIR/"
chmod -R 775 "$PANEL_DIR/uploads/" 2>/dev/null || true

# Limpiar OPcache si PHP-FPM esta corriendo
echo -e "${YELLOW}[4/4] Limpiando cache de PHP...${NC}"
php -r "if(function_exists('opcache_reset')){opcache_reset();echo 'OPcache limpiado';}" 2>/dev/null || true

# Reiniciar PHP-FPM
if systemctl is-active --quiet php8.2-fpm 2>/dev/null; then
    systemctl restart php8.2-fpm
    echo -e "${GREEN}  PHP-FPM 8.2 reiniciado${NC}"
elif systemctl is-active --quiet php8.1-fpm 2>/dev/null; then
    systemctl restart php8.1-fpm
    echo -e "${GREEN}  PHP-FPM 8.1 reiniciado${NC}"
elif systemctl is-active --quiet php-fpm 2>/dev/null; then
    systemctl restart php-fpm
    echo -e "${GREEN}  PHP-FPM reiniciado${NC}"
elif systemctl is-active --quiet httpd 2>/dev/null; then
    systemctl restart httpd
    echo -e "${GREEN}  Apache (httpd) reiniciado${NC}"
elif systemctl is-active --quiet apache2 2>/dev/null; then
    systemctl restart apache2
    echo -e "${GREEN}  Apache2 reiniciado${NC}"
fi

# Limpiar
rm -f "$TMP_ZIP"

echo ""
echo -e "${GREEN}  ╔═══════════════════════════════════════╗"
echo -e "  ║   Actualizacion completada con exito   ║"
echo -e "  ╚═══════════════════════════════════════╝${NC}"
echo ""
echo -e "  Panel: ${CYAN}https://tv.tvpanelec.com${NC}"
echo -e "  Recargue la pagina con ${YELLOW}Ctrl+F5${NC}"
echo ""
