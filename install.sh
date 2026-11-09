#!/bin/bash
# =============================================================
# CastPanel v2.7.4 - Instalador Automatico Completo
# Compatible con: AlmaLinux 8/9, Rocky 8/9, Ubuntu 20/22/24, Debian 11/12
# =============================================================
# Uso:
#   chmod +x install.sh
#   sudo ./install.sh
#
# Desde un servidor remoto:
#   bash <(curl -sL https://tudominio.com/install.sh)
#
# Instala y configura automaticamente:
#   - Apache + PHP 8.x + MariaDB
#   - Docker + Liquidsoap (via Docker)
#   - Icecast 2.4 (compilado desde fuente en RHEL, paquete en Debian)
#   - Proxy inverso Apache /live/ -> Icecast :8000
#   - SSL con Let's Encrypt (Certbot)
#   - Firewall, SELinux, permisos
#   - SHOUTcast DNAS v2 (sc_serv2) + auto-start systemd
#   - Servicio systemd para Liquidsoap
# =============================================================

set -e

# ---- COLORES ----
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
CYAN='\033[0;36m'
WHITE='\033[1;37m'
NC='\033[0m'

# ---- CONFIGURACION POR DEFECTO ----
PANEL_DIR="/var/www/castpanel"
MEDIA_DIR="/var/castpanel/media"
ANUNCIOS_DIR="/var/castpanel/anuncios"
DB_NAME="castpanel"
DB_USER="castpanel"
LIQUIDSOAP_DIR="/etc/liquidsoap/stations"
ICECAST_CONF_DIR="/etc/icecast2"
LIQUIDSOAP_DOCKER_IMAGE="savonet/liquidsoap:v2.2.5"

# URL de descarga (argumento opcional)
PANEL_DOWNLOAD_URL="${1:-}"

# ---- FUNCIONES DE LOG ----

print_header() {
    clear
    echo -e "${CYAN}"
    echo "  ================================================================"
    echo "  |                                                              |"
    echo "  |          CastPanel v2.7.4 - Instalador Automatico            |"
    echo "  |       Panel de Radio Streaming (PHP + MySQL)                |"
    echo "  |                                                              |"
    echo "  |   Icecast + Liquidsoap (Docker) + Apache + MariaDB          |"
    echo "  |                                                              |"
    echo "  ================================================================"
    echo -e "${NC}"
    echo ""
}

log_info()    { echo -e "  ${BLUE}[INFO]${NC}    $1"; }
log_success() { echo -e "  ${GREEN}[OK]${NC}      $1"; }
log_warning() { echo -e "  ${YELLOW}[!]${NC}       $1"; }
log_error()   { echo -e "  ${RED}[ERROR]${NC}   $1"; }
log_step()    { echo -e "\n  ${CYAN}=== $1 ===${NC}\n"; }

# ---- VERIFICACIONES ----

check_root() {
    if [ "$(id -u)" -ne 0 ]; then
        log_error "Este instalador debe ejecutarse como root (sudo)"
        exit 1
    fi
}

detect_os() {
    if [ -f /etc/os-release ]; then
        . /etc/os-release
        OS_ID="$ID"
        OS_VERSION="$VERSION_ID"
        OS_NAME="$PRETTY_NAME"
    else
        log_error "No se pudo detectar el sistema operativo"
        exit 1
    fi

    case "$OS_ID" in
        ubuntu|debian)
            PKG_MANAGER="apt"
            WEB_USER="www-data"
            WEB_SERVICE="apache2"
            VHOST_DIR="/etc/apache2/sites-available"
            APACHE_LOG_DIR="/var/log/apache2"
            ;;
        almalinux|rocky|centos|rhel)
            if command -v dnf &>/dev/null; then
                PKG_MANAGER="dnf"
            else
                PKG_MANAGER="yum"
            fi
            WEB_USER="apache"
            WEB_SERVICE="httpd"
            VHOST_DIR="/etc/httpd/conf.d"
            APACHE_LOG_DIR="/var/log/httpd"
            ;;
        *)
            log_error "Sistema operativo no soportado: $OS_NAME"
            log_info "Soportados: Ubuntu 20+, Debian 11+, AlmaLinux 8+, Rocky 8+"
            exit 1
            ;;
    esac

    log_success "Sistema detectado: $OS_NAME"
    log_info "Gestor de paquetes: $PKG_MANAGER"
}

# ---- CONFIGURACION INTERACTIVA ----

prompt_config() {
    log_step "CONFIGURACION"

    # Dominio
    read -p "  Dominio del panel (ej: cast.midominio.com): " PANEL_DOMAIN
    if [ -z "$PANEL_DOMAIN" ]; then
        PANEL_DOMAIN="$(hostname -f 2>/dev/null || hostname)"
    fi

    # Puerto Icecast
    read -p "  Puerto Icecast [8000]: " ICECAST_PORT
    ICECAST_PORT="${ICECAST_PORT:-8000}"

    # Contrasena admin Icecast
    ICECAST_ADMIN_PASS=$(openssl rand -base64 12 2>/dev/null | tr -d '/+=' | head -c 16)
    read -p "  Contrasena admin Icecast [autogenerada: $ICECAST_ADMIN_PASS]: " INPUT_ICE_PASS
    if [ -n "$INPUT_ICE_PASS" ]; then
        ICECAST_ADMIN_PASS="$INPUT_ICE_PASS"
    fi

    # Contrasena source Icecast (para Liquidsoap)
    ICECAST_SOURCE_PASS=$(openssl rand -base64 12 2>/dev/null | tr -d '/+=' | head -c 16)
    read -p "  Contrasena source Icecast [autogenerada: $ICECAST_SOURCE_PASS]: " INPUT_SRC_PASS
    if [ -n "$INPUT_SRC_PASS" ]; then
        ICECAST_SOURCE_PASS="$INPUT_SRC_PASS"
    fi

    # Contrasena MySQL
    DB_PASS=$(openssl rand -base64 16 2>/dev/null | tr -d '/+=' | head -c 20)
    read -p "  Contrasena MySQL para '$DB_USER' [autogenerada]: " INPUT_DB_PASS
    if [ -n "$INPUT_DB_PASS" ]; then
        DB_PASS="$INPUT_DB_PASS"
    fi

    # Contrasena admin panel
    read -p "  Contrasena admin del panel [password]: " ADMIN_PASS
    ADMIN_PASS="${ADMIN_PASS:-password}"

    # SSL
    read -p "  Instalar SSL con Let's Encrypt? (s/n) [s]: " INSTALL_SSL
    INSTALL_SSL="${INSTALL_SSL:-s}"

    if [ "$INSTALL_SSL" = "s" ] || [ "$INSTALL_SSL" = "S" ]; then
        read -p "  Email para Let's Encrypt (obligatorio): " LE_EMAIL
        if [ -z "$LE_EMAIL" ]; then
            log_warning "Sin email, SSL no se configurara automaticamente"
            INSTALL_SSL="n"
        fi
    fi

    echo ""
    echo -e "  ${YELLOW}Resumen de configuracion:${NC}"
    echo "  ----------------------------------------"
    echo "  Dominio:            $PANEL_DOMAIN"
    echo "  Directorio:         $PANEL_DIR"
    echo "  Puerto Icecast:     $ICECAST_PORT"
    echo "  BD:                 $DB_NAME / $DB_USER"
    echo "  SSL:                $([ "$INSTALL_SSL" = "s" ] || [ "$INSTALL_SSL" = "S" ] && echo 'Si' || echo 'No')"
    echo "  Docker/Liquidsoap:  $LIQUIDSOAP_DOCKER_IMAGE"
    echo "  ----------------------------------------"
    echo ""
    read -p "  Continuar con la instalacion? (s/n) [s]: " CONFIRM
    if [ "$CONFIRM" = "n" ] || [ "$CONFIRM" = "N" ]; then
        echo "  Instalacion cancelada."
        exit 0
    fi
}

# ---- INSTALACION DE PAQUETES (APT) ----

install_packages_apt() {
    log_step "INSTALANDO PAQUETES (APT)"
    export DEBIAN_FRONTEND=noninteractive

    apt-get update -qq

    # PHP y extensiones
    log_info "Instalando PHP y extensiones..."
    apt-get install -y -qq php php-cli php-mysql php-mbstring php-curl php-xml \
        php-json php-gd php-zip php-fpm libapache2-mod-php 2>/dev/null
    log_success "PHP instalado: $(php -v 2>/dev/null | head -1)"

    # MariaDB
    log_info "Instalando MariaDB..."
    apt-get install -y -qq mariadb-server mariadb-client 2>/dev/null
    systemctl enable mariadb 2>/dev/null || true
    systemctl start mariadb 2>/dev/null || true
    log_success "MariaDB instalado"

    # Apache
    log_info "Instalando Apache..."
    apt-get install -y -qq apache2 2>/dev/null
    a2enmod rewrite proxy proxy_http ssl headers 2>/dev/null || true
    systemctl enable apache2 2>/dev/null || true
    log_success "Apache instalado"

    # Icecast
    log_info "Instalando Icecast2..."
    echo "icecast2 icecast2/icecast-setup boolean false" | debconf-set-selections 2>/dev/null || true
    apt-get install -y -qq icecast2 2>/dev/null
    log_success "Icecast2 instalado"

    # FFmpeg
    log_info "Instalando FFmpeg..."
    apt-get install -y -qq ffmpeg 2>/dev/null
    log_success "FFmpeg instalado"

    # Certbot
    log_info "Instalando Certbot..."
    apt-get install -y -qq certbot python3-certbot-apache 2>/dev/null
    log_success "Certbot instalado"

    # Herramientas
    apt-get install -y -qq unzip curl wget 2>/dev/null
}

# ---- INSTALACION DE PAQUETES (DNF/YUM) ----

install_packages_dnf() {
    log_step "INSTALANDO PAQUETES (DNF/YUM)"

    # EPEL
    log_info "Habilitando EPEL..."
    $PKG_MANAGER install -y epel-release 2>/dev/null || true

    # Remi para PHP moderno
    if [ "$OS_ID" = "almalinux" ] || [ "$OS_ID" = "rocky" ] || [ "$OS_ID" = "centos" ]; then
        log_info "Habilitando repositorio Remi..."
        $PKG_MANAGER install -y "https://rpms.remirepo.net/enterprise/remi-release-${OS_VERSION%%.*}.rpm" 2>/dev/null || true
        $PKG_MANAGER module reset php -y 2>/dev/null || true
        $PKG_MANAGER module enable php:remi-8.2 -y 2>/dev/null || \
        $PKG_MANAGER module enable php:remi-8.1 -y 2>/dev/null || true
    fi

    # PHP
    log_info "Instalando PHP y extensiones..."
    $PKG_MANAGER install -y php php-cli php-mysqlnd php-mbstring php-curl php-xml \
        php-json php-gd php-zip php-fpm 2>/dev/null
    log_success "PHP instalado: $(php -v 2>/dev/null | head -1)"

    # MariaDB
    log_info "Instalando MariaDB..."
    $PKG_MANAGER install -y mariadb-server mariadb 2>/dev/null
    systemctl enable mariadb 2>/dev/null || true
    systemctl start mariadb 2>/dev/null || true
    log_success "MariaDB instalado"

    # Apache
    log_info "Instalando Apache (httpd)..."
    $PKG_MANAGER install -y httpd mod_php mod_ssl 2>/dev/null || $PKG_MANAGER install -y httpd php mod_ssl 2>/dev/null
    systemctl enable httpd 2>/dev/null || true
    log_success "Apache instalado"

    # Icecast (compilar desde fuente en RHEL-based)
    install_icecast_from_source

    # FFmpeg
    log_info "Instalando FFmpeg..."
    $PKG_MANAGER install -y ffmpeg 2>/dev/null || {
        log_info "Habilitando RPM Fusion para FFmpeg..."
        $PKG_MANAGER install -y "https://download1.rpmfusion.org/free/el/rpmfusion-free-release-${OS_VERSION%%.*}.noarch.rpm" 2>/dev/null || true
        $PKG_MANAGER install -y ffmpeg 2>/dev/null || log_warning "FFmpeg no se pudo instalar automaticamente"
    }

    # Certbot
    log_info "Instalando Certbot..."
    $PKG_MANAGER install -y certbot python3-certbot-apache 2>/dev/null || \
    $PKG_MANAGER install -y certbot 2>/dev/null || true
    log_success "Certbot instalado"

    # Herramientas
    $PKG_MANAGER install -y unzip curl wget 2>/dev/null

    # Firewall
    setup_firewall
}

# ---- COMPILAR ICECAST DESDE FUENTE (RHEL) ----

install_icecast_from_source() {
    if command -v icecast &>/dev/null || [ -f /usr/local/bin/icecast ] || [ -f /usr/bin/icecast ]; then
        log_success "Icecast ya esta instalado"
        return 0
    fi

    log_info "Compilando Icecast 2.4.4 desde fuente..."

    # Dependencias de compilacion
    $PKG_MANAGER install -y gcc gcc-c++ make autoconf automake libtool \
        libxml2-devel libxslt-devel curl-devel libogg-devel libvorbis-devel \
        libtheora-devel speex-devel openssl-devel 2>/dev/null

    # Descargar y compilar
    cd /tmp
    ICECAST_VER="2.4.4"
    if [ ! -f "icecast-${ICECAST_VER}.tar.gz" ]; then
        curl -fSL "https://downloads.xiph.org/releases/icecast/icecast-${ICECAST_VER}.tar.gz" \
            -o "icecast-${ICECAST_VER}.tar.gz" 2>/dev/null || {
            log_warning "No se pudo descargar Icecast. Instale manualmente."
            return 1
        }
    fi

    tar xzf "icecast-${ICECAST_VER}.tar.gz"
    cd "icecast-${ICECAST_VER}"
    ./configure --prefix=/usr/local 2>/dev/null
    make -j"$(nproc)" 2>/dev/null
    make install 2>/dev/null

    # Crear usuario icecast si no existe
    id -u icecast &>/dev/null || useradd -r -s /sbin/nologin icecast

    # Crear directorios web de Icecast
    mkdir -p /usr/share/icecast/web /usr/share/icecast/admin
    if [ -d /usr/local/share/icecast ]; then
        cp -r /usr/local/share/icecast/* /usr/share/icecast/ 2>/dev/null || true
    fi

    # Limpiar
    cd /
    rm -rf /tmp/icecast-${ICECAST_VER}*

    log_success "Icecast ${ICECAST_VER} compilado e instalado"
}

# ---- FIREWALL ----

setup_firewall() {
    if command -v firewall-cmd &>/dev/null; then
        log_info "Configurando firewall..."
        firewall-cmd --permanent --add-service=http 2>/dev/null || true
        firewall-cmd --permanent --add-service=https 2>/dev/null || true
        firewall-cmd --permanent --add-port="${ICECAST_PORT}/tcp" 2>/dev/null || true
        firewall-cmd --reload 2>/dev/null || true
        log_success "Firewall configurado (HTTP, HTTPS, puerto ${ICECAST_PORT})"
    fi
}

# ---- DOCKER ----

install_docker() {
    log_step "INSTALANDO DOCKER"

    if command -v docker &>/dev/null; then
        log_success "Docker ya esta instalado: $(docker --version 2>/dev/null)"
    else
        log_info "Instalando Docker..."

        if [ "$PKG_MANAGER" = "apt" ]; then
            # Docker en Debian/Ubuntu
            apt-get install -y -qq ca-certificates gnupg 2>/dev/null
            install -m 0755 -d /etc/apt/keyrings 2>/dev/null || true
            curl -fsSL "https://download.docker.com/linux/${OS_ID}/gpg" | \
                gpg --dearmor -o /etc/apt/keyrings/docker.gpg 2>/dev/null
            chmod a+r /etc/apt/keyrings/docker.gpg
            echo "deb [arch=$(dpkg --print-architecture) signed-by=/etc/apt/keyrings/docker.gpg] \
                https://download.docker.com/linux/${OS_ID} $(lsb_release -cs) stable" > \
                /etc/apt/sources.list.d/docker.list
            apt-get update -qq 2>/dev/null
            apt-get install -y -qq docker-ce docker-ce-cli containerd.io 2>/dev/null
        else
            # Docker en RHEL/AlmaLinux/Rocky
            $PKG_MANAGER install -y yum-utils 2>/dev/null || true
            yum-config-manager --add-repo https://download.docker.com/linux/centos/docker-ce.repo 2>/dev/null || true
            $PKG_MANAGER install -y docker-ce docker-ce-cli containerd.io 2>/dev/null
        fi

        systemctl enable docker 2>/dev/null || true
        systemctl start docker 2>/dev/null || true

        if command -v docker &>/dev/null; then
            log_success "Docker instalado: $(docker --version 2>/dev/null)"
        else
            log_error "No se pudo instalar Docker automaticamente"
            log_info "Instale Docker manualmente: https://docs.docker.com/engine/install/"
            log_info "Luego ejecute de nuevo este instalador"
            exit 1
        fi
    fi

    # Descargar imagen de Liquidsoap
    log_info "Descargando imagen Docker de Liquidsoap..."
    docker pull "$LIQUIDSOAP_DOCKER_IMAGE" 2>/dev/null
    if [ $? -eq 0 ]; then
        log_success "Imagen Liquidsoap descargada: $LIQUIDSOAP_DOCKER_IMAGE"
    else
        log_warning "No se pudo descargar la imagen de Liquidsoap"
        log_info "Ejecute: docker pull $LIQUIDSOAP_DOCKER_IMAGE"
    fi
}

# ---- DESPLEGAR ARCHIVOS DEL PANEL ----

deploy_panel() {
    log_step "DESPLEGANDO ARCHIVOS DEL PANEL"

    mkdir -p "$PANEL_DIR"

    if [ -n "$PANEL_DOWNLOAD_URL" ]; then
        log_info "Descargando CastPanel desde: $PANEL_DOWNLOAD_URL"
        TMPZIP="/tmp/castpanel_$$.zip"
        curl -fSL "$PANEL_DOWNLOAD_URL" -o "$TMPZIP" 2>/dev/null
        if [ -f "$TMPZIP" ] && [ -s "$TMPZIP" ]; then
            if file "$TMPZIP" | grep -qi zip; then
                unzip -qo "$TMPZIP" -d "$PANEL_DIR"
                rm -f "$TMPZIP"
                log_success "Panel descargado y extraido en $PANEL_DIR"
            else
                log_error "El archivo descargado no es un ZIP valido"
                rm -f "$TMPZIP"
                exit 1
            fi
        else
            log_error "No se pudo descargar el panel"
            exit 1
        fi
    else
        SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
        if [ -f "$SCRIPT_DIR/config/database.php" ]; then
            log_info "Copiando archivos desde $SCRIPT_DIR..."
            cp -r "$SCRIPT_DIR"/* "$PANEL_DIR/" 2>/dev/null
            cp -r "$SCRIPT_DIR"/.htaccess "$PANEL_DIR/" 2>/dev/null || true
            log_success "Archivos copiados a $PANEL_DIR"
        else
            log_error "No se encontraron archivos del panel"
            log_info "Coloque los archivos en el mismo directorio que install.sh"
            exit 1
        fi
    fi

    # Generar config/database.php con datos reales
    log_info "Generando configuracion de base de datos..."
    cat > "$PANEL_DIR/config/database.php" << 'PHPEOF'
<?php
/**
 * CastPanel v2.3 - Configuracion de Base de Datos
 * Generado automaticamente por el instalador
 */

define('DB_HOST', '127.0.0.1');
define('DB_NAME', '%%DB_NAME%%');
define('DB_USER', '%%DB_USER%%');
define('DB_PASS', '%%DB_PASS%%');
define('DB_CHARSET', 'utf8mb4');

// Ruta base de la aplicacion
define('BASE_PATH', '%%PANEL_DIR%%');
define('BASE_URL', '');

// Rutas de archivos
define('MEDIA_PATH', '%%MEDIA_DIR%%');
define('ANUNCIOS_PATH', '%%ANUNCIOS_DIR%%');

// Icecast
define('ICECAST_HOST', '127.0.0.1');
define('ICECAST_PORT', %%ICECAST_PORT%%);
define('ICECAST_ADMIN_USER', 'admin');
define('ICECAST_ADMIN_PASS', '%%ICECAST_ADMIN_PASS%%');

// Icecast source password (para Liquidsoap)
define('ICECAST_SOURCE_PASS', '%%ICECAST_SOURCE_PASS%%');

// Liquidsoap
define('LIQUIDSOAP_TELNET_HOST', '127.0.0.1');
define('LIQUIDSOAP_TELNET_PORT', 1234);
define('LIQUIDSOAP_HOST', '127.0.0.1');
define('LIQUIDSOAP_PORT', 1234);

// Docker
define('LIQUIDSOAP_DOCKER_IMAGE', '%%LIQUIDSOAP_DOCKER_IMAGE%%');

// SSL / Dominio publico
define('SSL_DOMAIN', '%%PANEL_DOMAIN%%');

// Sesion
define('SESSION_NAME', 'castpanel_session');
define('SESSION_LIFETIME', 86400);

// Zona horaria
date_default_timezone_set('America/Guayaquil');

/**
 * Conexion PDO
 */
function getDB(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        $dsn = 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=' . DB_CHARSET;
        $options = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ];
        $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
    }
    return $pdo;
}
PHPEOF

    # Reemplazar placeholders con valores reales
    sed -i "s|%%DB_NAME%%|${DB_NAME}|g" "$PANEL_DIR/config/database.php"
    sed -i "s|%%DB_USER%%|${DB_USER}|g" "$PANEL_DIR/config/database.php"
    sed -i "s|%%DB_PASS%%|${DB_PASS}|g" "$PANEL_DIR/config/database.php"
    sed -i "s|%%PANEL_DIR%%|${PANEL_DIR}|g" "$PANEL_DIR/config/database.php"
    sed -i "s|%%MEDIA_DIR%%|${MEDIA_DIR}|g" "$PANEL_DIR/config/database.php"
    sed -i "s|%%ANUNCIOS_DIR%%|${ANUNCIOS_DIR}|g" "$PANEL_DIR/config/database.php"
    sed -i "s|%%ICECAST_PORT%%|${ICECAST_PORT}|g" "$PANEL_DIR/config/database.php"
    sed -i "s|%%ICECAST_ADMIN_PASS%%|${ICECAST_ADMIN_PASS}|g" "$PANEL_DIR/config/database.php"
    sed -i "s|%%ICECAST_SOURCE_PASS%%|${ICECAST_SOURCE_PASS}|g" "$PANEL_DIR/config/database.php"
    sed -i "s|%%LIQUIDSOAP_DOCKER_IMAGE%%|${LIQUIDSOAP_DOCKER_IMAGE}|g" "$PANEL_DIR/config/database.php"
    sed -i "s|%%PANEL_DOMAIN%%|${PANEL_DOMAIN}|g" "$PANEL_DIR/config/database.php"

    log_success "Configuracion generada con credenciales seguras"
}

# ---- BASE DE DATOS ----

setup_database() {
    log_step "CONFIGURANDO BASE DE DATOS"

    log_info "Creando base de datos y usuario..."

    mysql -e "CREATE DATABASE IF NOT EXISTS \`${DB_NAME}\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;" 2>/dev/null
    mysql -e "CREATE USER IF NOT EXISTS '${DB_USER}'@'localhost' IDENTIFIED BY '${DB_PASS}';" 2>/dev/null || \
    mysql -e "GRANT ALL ON \`${DB_NAME}\`.* TO '${DB_USER}'@'localhost' IDENTIFIED BY '${DB_PASS}';" 2>/dev/null
    mysql -e "GRANT ALL PRIVILEGES ON \`${DB_NAME}\`.* TO '${DB_USER}'@'localhost';" 2>/dev/null
    mysql -e "FLUSH PRIVILEGES;" 2>/dev/null

    log_success "Base de datos '$DB_NAME' creada"
    log_success "Usuario '$DB_USER' creado"

    # Importar schema principal
    if [ -f "$PANEL_DIR/install/schema.sql" ]; then
        log_info "Importando schema principal..."
        mysql "$DB_NAME" < "$PANEL_DIR/install/schema.sql" 2>/dev/null
        log_success "Schema principal importado"
    else
        log_error "No se encontro schema.sql en $PANEL_DIR/install/"
    fi

    # Importar schemas adicionales
    for extra_schema in schema_branding.sql schema_processor.sql schema_player_config.sql schema_update.sql schema_update_v2.3.sql; do
        if [ -f "$PANEL_DIR/install/$extra_schema" ]; then
            log_info "Importando $extra_schema..."
            mysql "$DB_NAME" < "$PANEL_DIR/install/$extra_schema" 2>/dev/null || true
            log_success "$extra_schema importado"
        fi
    done

    # Actualizar contrasena admin si se cambio
    if [ "$ADMIN_PASS" != "password" ]; then
        ADMIN_HASH=$(php -r "echo password_hash('${ADMIN_PASS}', PASSWORD_DEFAULT);")
        mysql "$DB_NAME" -e "UPDATE usuarios SET password='${ADMIN_HASH}' WHERE username='admin';" 2>/dev/null
        log_success "Contrasena del admin actualizada"
    fi

    # Guardar credenciales de Icecast en la tabla configuracion
    mysql "$DB_NAME" -e "INSERT INTO configuracion (clave, valor) VALUES ('icecast_admin_user', 'admin') \
        ON DUPLICATE KEY UPDATE valor='admin';" 2>/dev/null || true
    mysql "$DB_NAME" -e "INSERT INTO configuracion (clave, valor) VALUES ('icecast_admin_pass', '${ICECAST_ADMIN_PASS}') \
        ON DUPLICATE KEY UPDATE valor='${ICECAST_ADMIN_PASS}';" 2>/dev/null || true
    mysql "$DB_NAME" -e "INSERT INTO configuracion (clave, valor) VALUES ('icecast_source_pass', '${ICECAST_SOURCE_PASS}') \
        ON DUPLICATE KEY UPDATE valor='${ICECAST_SOURCE_PASS}';" 2>/dev/null || true
    mysql "$DB_NAME" -e "INSERT INTO configuracion (clave, valor) VALUES ('icecast_host', '127.0.0.1') \
        ON DUPLICATE KEY UPDATE valor='127.0.0.1';" 2>/dev/null || true
    mysql "$DB_NAME" -e "INSERT INTO configuracion (clave, valor) VALUES ('icecast_port', '${ICECAST_PORT}') \
        ON DUPLICATE KEY UPDATE valor='${ICECAST_PORT}';" 2>/dev/null || true

    log_success "Credenciales Icecast guardadas en BD"
}

# ---- DIRECTORIOS Y PERMISOS ----

setup_directories() {
    log_step "CREANDO DIRECTORIOS Y PERMISOS"

    # Directorios del panel
    mkdir -p "$MEDIA_DIR"
    mkdir -p "$ANUNCIOS_DIR"
    mkdir -p "$PANEL_DIR/assets/covers"
    mkdir -p "$PANEL_DIR/assets/img"
    mkdir -p "$PANEL_DIR/uploads/player"
    mkdir -p "$PANEL_DIR/uploads/branding/global"

    # Directorios de Liquidsoap
    mkdir -p "$LIQUIDSOAP_DIR"
    mkdir -p /var/log/liquidsoap
    mkdir -p /var/run/liquidsoap

    # Directorio de Icecast
    mkdir -p "$ICECAST_CONF_DIR"
    mkdir -p /var/log/icecast2

    # Permisos
    chown -R "$WEB_USER:$WEB_USER" "$PANEL_DIR"
    chown -R "$WEB_USER:$WEB_USER" "$MEDIA_DIR"
    chown -R "$WEB_USER:$WEB_USER" "$ANUNCIOS_DIR"
    chown -R "$WEB_USER:$WEB_USER" "$LIQUIDSOAP_DIR"
    chown -R "$WEB_USER:$WEB_USER" /var/log/liquidsoap
    chown -R "$WEB_USER:$WEB_USER" /var/run/liquidsoap

    chmod -R 755 "$PANEL_DIR"
    chmod -R 775 "$MEDIA_DIR"
    chmod -R 775 "$ANUNCIOS_DIR"
    chmod -R 775 "$PANEL_DIR/assets/covers"
    chmod -R 775 "$PANEL_DIR/uploads"
    chmod 775 "$LIQUIDSOAP_DIR"

    log_success "Directorios creados y permisos configurados"
}

# ---- CONFIGURAR ICECAST ----

setup_icecast() {
    log_step "CONFIGURANDO ICECAST"

    # Buscar config existente o crear nueva
    ICECAST_CONF=""
    for candidate in /etc/icecast2/icecast.xml /etc/icecast.xml /usr/local/etc/icecast.xml; do
        if [ -f "$candidate" ]; then
            ICECAST_CONF="$candidate"
            break
        fi
    done

    if [ -z "$ICECAST_CONF" ]; then
        ICECAST_CONF="$ICECAST_CONF_DIR/icecast.xml"
    fi

    # Crear usuario icecast si no existe
    id -u icecast &>/dev/null || useradd -r -s /sbin/nologin icecast 2>/dev/null || true

    # Backup si existe
    if [ -f "$ICECAST_CONF" ]; then
        cp "$ICECAST_CONF" "${ICECAST_CONF}.bak.$(date +%s)"
    fi

    # Generar icecast.xml completo
    log_info "Generando configuracion Icecast..."
    mkdir -p "$(dirname "$ICECAST_CONF")"
    cat > "$ICECAST_CONF" << ICECASTEOF
<icecast>
    <location>CastPanel Server</location>
    <admin>admin@${PANEL_DOMAIN}</admin>

    <limits>
        <clients>500</clients>
        <sources>50</sources>
        <queue-size>524288</queue-size>
        <client-timeout>30</client-timeout>
        <header-timeout>15</header-timeout>
        <source-timeout>10</source-timeout>
        <burst-on-connect>1</burst-on-connect>
        <burst-size>65535</burst-size>
    </limits>

    <authentication>
        <source-password>${ICECAST_SOURCE_PASS}</source-password>
        <relay-password>${ICECAST_SOURCE_PASS}</relay-password>
        <admin-user>admin</admin-user>
        <admin-password>${ICECAST_ADMIN_PASS}</admin-password>
    </authentication>

    <hostname>${PANEL_DOMAIN}</hostname>

    <listen-socket>
        <port>${ICECAST_PORT}</port>
        <bind-address>127.0.0.1</bind-address>
    </listen-socket>

    <http-headers>
        <header name="Access-Control-Allow-Origin" value="*" />
        <header name="Access-Control-Allow-Headers" value="Origin, Accept, X-Requested-With, Content-Type" />
        <header name="Access-Control-Allow-Methods" value="GET, OPTIONS, HEAD" />
    </http-headers>

    <fileserve>1</fileserve>

    <paths>
        <basedir>/usr/share/icecast</basedir>
        <logdir>/var/log/icecast2</logdir>
        <webroot>/usr/share/icecast/web</webroot>
        <adminroot>/usr/share/icecast/admin</adminroot>
        <alias source="/" destination="/status.xsl"/>
    </paths>

    <logging>
        <accesslog>access.log</accesslog>
        <errorlog>error.log</errorlog>
        <loglevel>3</loglevel>
        <logsize>10000</logsize>
    </logging>

    <security>
        <chroot>0</chroot>
        <changeowner>
            <user>icecast</user>
            <group>icecast</group>
        </changeowner>
    </security>
</icecast>
ICECASTEOF

    # Permisos
    chown icecast:icecast "$ICECAST_CONF" 2>/dev/null || true
    chown -R icecast:icecast /var/log/icecast2 2>/dev/null || true

    # Crear servicio systemd para Icecast si no existe
    if ! systemctl list-unit-files | grep -qE "^icecast[2]?.service"; then
        log_info "Creando servicio systemd para Icecast..."
        ICECAST_BIN=$(which icecast 2>/dev/null || echo "/usr/local/bin/icecast")
        cat > /etc/systemd/system/icecast.service << SVCEOF
[Unit]
Description=Icecast Streaming Server
After=network.target

[Service]
Type=simple
User=icecast
Group=icecast
ExecStart=${ICECAST_BIN} -c ${ICECAST_CONF}
Restart=on-failure
RestartSec=5
LimitNOFILE=65536

[Install]
WantedBy=multi-user.target
SVCEOF
        systemctl daemon-reload
    fi

    # Habilitar y arrancar
    if [ -f /etc/default/icecast2 ]; then
        sed -i 's/ENABLE=false/ENABLE=true/' /etc/default/icecast2
    fi

    systemctl enable icecast 2>/dev/null || systemctl enable icecast2 2>/dev/null || true
    systemctl restart icecast 2>/dev/null || systemctl restart icecast2 2>/dev/null || true

    log_success "Icecast configurado en puerto $ICECAST_PORT"
}

# ---- SERVICIO LIQUIDSOAP (DOCKER) ----

setup_liquidsoap_service() {
    log_step "CONFIGURANDO LIQUIDSOAP (DOCKER)"

    # Crear servicio systemd para Liquidsoap via Docker
    log_info "Creando servicio systemd para Liquidsoap..."

    cat > /etc/systemd/system/liquidsoap.service << SVCEOF
[Unit]
Description=CastPanel Liquidsoap AutoDJ (Docker)
After=docker.service icecast.service icecast2.service
Requires=docker.service

[Service]
Type=simple
Restart=on-failure
RestartSec=10

# Detener y eliminar contenedor anterior si existe
ExecStartPre=-/usr/bin/docker stop liquidsoap-autodj
ExecStartPre=-/usr/bin/docker rm liquidsoap-autodj

# Iniciar: busca configs .liq activos y ejecuta el primero encontrado
# El panel genera los .liq en /etc/liquidsoap/stations/
ExecStart=/bin/bash -c '\
    CONFIG=\$(find /etc/liquidsoap/stations/ -name "*.liq" -type f | head -1); \
    if [ -z "\$CONFIG" ]; then \
        echo "No hay configuraciones Liquidsoap activas"; \
        sleep infinity; \
    else \
        SLUG=\$(basename "\$CONFIG" .liq); \
        /usr/bin/docker run --rm --name liquidsoap-autodj \
            --network host \
            -v /etc/liquidsoap/stations:/config:ro \
            -v /var/castpanel/media:/media:ro \
            -v /var/castpanel/anuncios:/anuncios:ro \
            -v /var/log/liquidsoap:/var/log/liquidsoap \
            ${LIQUIDSOAP_DOCKER_IMAGE} \
            liquidsoap /config/\${SLUG}.liq; \
    fi'

ExecStop=/usr/bin/docker stop liquidsoap-autodj

[Install]
WantedBy=multi-user.target
SVCEOF

    systemctl daemon-reload
    # No se habilita: castpanel-ctl arranca un contenedor por estacion
    # (liquidsoap-<slug>, --restart unless-stopped). Este servicio solo
    # arrancaba la primera estacion y chocaria con esos contenedores.
    systemctl disable liquidsoap 2>/dev/null || true

    log_success "Liquidsoap: un contenedor Docker por estacion (gestionado por castpanel-ctl)"

    # Copiar script de gestion
    if [ -f "$PANEL_DIR/liquidsoap/manage.sh" ]; then
        cp "$PANEL_DIR/liquidsoap/manage.sh" /usr/local/bin/castpanel-liquidsoap
        chmod +x /usr/local/bin/castpanel-liquidsoap
        log_success "Script de gestion: /usr/local/bin/castpanel-liquidsoap"
    fi
}

# ---- SHOUTCAST DNAS v2 ----

setup_shoutcast() {
    log_step "CONFIGURANDO SHOUTCAST DNAS v2"

    SHOUTCAST_DIR="/opt/shoutcast"
    SHOUTCAST_CONF_DIR="$SHOUTCAST_DIR/configs"
    SHOUTCAST_LOG_DIR="/var/log/shoutcast"

    # Crear directorios
    mkdir -p "$SHOUTCAST_DIR" "$SHOUTCAST_CONF_DIR" "$SHOUTCAST_LOG_DIR"

    # The current Nullsoft download ships the binary as "sc_serv" (older ones
    # as "sc_serv2"); the panel and castpanel-ctl use sc_serv2, so link it.
    sc_link_binary() {
        [ -e "$SHOUTCAST_DIR/sc_serv2" ] && return 0
        local found
        found=$(find "$SHOUTCAST_DIR" -maxdepth 2 -type f \( -name sc_serv -o -name sc_serv2 \) 2>/dev/null | head -1)
        if [ -n "$found" ]; then
            chmod +x "$found"
            ln -sf "$found" "$SHOUTCAST_DIR/sc_serv2"
            log_success "SHOUTcast: $SHOUTCAST_DIR/sc_serv2 -> $found"
        fi
    }
    sc_link_binary

    # Verificar si sc_serv2 ya existe
    if [ -e "$SHOUTCAST_DIR/sc_serv2" ]; then
        log_success "SHOUTcast sc_serv2 ya esta instalado"
    else
        log_info "Descargando SHOUTcast DNAS v2..."
        cd /tmp
        ARCH=$(uname -m)
        if [ "$ARCH" = "x86_64" ]; then
            SC_URL="https://download.nullsoft.com/shoutcast/tools/sc_serv2_linux_x64-latest.tar.gz"
        else
            SC_URL="https://download.nullsoft.com/shoutcast/tools/sc_serv2_linux-latest.tar.gz"
        fi
        curl -fSL "$SC_URL" -o sc_serv2.tar.gz 2>/dev/null || {
            log_warning "No se pudo descargar SHOUTcast automaticamente"
            log_info "Descargue sc_serv2 manualmente y coloquelo en $SHOUTCAST_DIR/"
        }
        if [ -f sc_serv2.tar.gz ]; then
            tar xzf sc_serv2.tar.gz -C "$SHOUTCAST_DIR/" 2>/dev/null
            rm -f sc_serv2.tar.gz
            sc_link_binary
            if [ -e "$SHOUTCAST_DIR/sc_serv2" ]; then
                log_success "SHOUTcast sc_serv2 instalado en $SHOUTCAST_DIR/"
            else
                log_warning "No se encontro el binario sc_serv/sc_serv2 dentro del paquete descargado"
            fi
        fi
        cd /
    fi

    # Copiar scripts de auto-start
    if [ -f "$PANEL_DIR/install/systemd/start-all.sh" ]; then
        cp "$PANEL_DIR/install/systemd/start-all.sh" "$SHOUTCAST_DIR/start-all.sh"
        chmod +x "$SHOUTCAST_DIR/start-all.sh"
        log_success "Script start-all.sh copiado"
    fi
    if [ -f "$PANEL_DIR/install/systemd/stop-all.sh" ]; then
        cp "$PANEL_DIR/install/systemd/stop-all.sh" "$SHOUTCAST_DIR/stop-all.sh"
        chmod +x "$SHOUTCAST_DIR/stop-all.sh"
        log_success "Script stop-all.sh copiado"
    fi

    # Instalar servicio systemd para auto-start SHOUTcast al boot
    if [ -f "$PANEL_DIR/install/systemd/castpanel-shoutcast.service" ]; then
        cp "$PANEL_DIR/install/systemd/castpanel-shoutcast.service" /etc/systemd/system/
        systemctl daemon-reload
        systemctl enable castpanel-shoutcast 2>/dev/null || true
        log_success "Servicio systemd castpanel-shoutcast habilitado (auto-start al boot)"
    fi

    # Crear archivo vacio de proxy rules para Apache (se llena al crear estaciones)
    if [ "$PKG_MANAGER" = "apt" ]; then
        PROXY_RULES_FILE="/etc/apache2/shoutcast-proxy-rules.inc"
    else
        PROXY_RULES_FILE="/etc/httpd/shoutcast-proxy-rules.inc"
    fi
    if [ ! -f "$PROXY_RULES_FILE" ]; then
        touch "$PROXY_RULES_FILE"
        log_success "Archivo proxy rules SHOUTcast creado: $PROXY_RULES_FILE"
    fi

    # El panel busca /usr/local/bin/sc_serv2 (includes/provisioning.php)
    if [ -x "$SHOUTCAST_DIR/sc_serv2" ] && [ ! -e /usr/local/bin/sc_serv2 ]; then
        ln -s "$SHOUTCAST_DIR/sc_serv2" /usr/local/bin/sc_serv2
    fi

    # Permisos: el panel (usuario web) escribe aqui la config y el wrapper de
    # cada estacion; antes eran de root y la escritura fallaba en silencio
    mkdir -p "$SHOUTCAST_DIR/bin"
    chown -R "$WEB_USER:$WEB_USER" "$SHOUTCAST_LOG_DIR" "$SHOUTCAST_CONF_DIR" "$SHOUTCAST_DIR/bin"
    chmod 775 "$SHOUTCAST_CONF_DIR" "$SHOUTCAST_DIR/bin"

    # Firewall: abrir rango de puertos para estaciones SHOUTcast (8100-8199)
    if command -v firewall-cmd &>/dev/null; then
        firewall-cmd --permanent --add-port=8100-8199/tcp 2>/dev/null || true
        firewall-cmd --reload 2>/dev/null || true
        log_success "Firewall: puertos 8100-8199 abiertos para SHOUTcast"
    fi

    log_success "SHOUTcast DNAS v2 configurado"
}

# ---- APACHE: VHOST + PROXY INVERSO ----

setup_apache() {
    log_step "CONFIGURANDO APACHE"

    # Habilitar modulos necesarios
    if [ "$PKG_MANAGER" = "apt" ]; then
        a2enmod rewrite proxy proxy_http ssl headers 2>/dev/null || true
    fi

    # Ruta del archivo proxy rules. Debe definirse ANTES del heredoc de abajo:
    # si no, IncludeOptional queda sin argumento y Apache no arranca.
    if [ "$PKG_MANAGER" = "apt" ]; then
        PROXY_RULES_FILE_PATH="/etc/apache2/shoutcast-proxy-rules.inc"
    else
        PROXY_RULES_FILE_PATH="/etc/httpd/shoutcast-proxy-rules.inc"
    fi
    touch "$PROXY_RULES_FILE_PATH" 2>/dev/null || true

    # VirtualHost principal (HTTP)
    VHOST_FILE="$VHOST_DIR/castpanel.conf"

    cat > "$VHOST_FILE" << VHOSTEOF
<VirtualHost *:80>
    ServerName ${PANEL_DOMAIN}
    DocumentRoot ${PANEL_DIR}

    <Directory ${PANEL_DIR}>
        AllowOverride All
        Require all granted
        Options -Indexes +FollowSymLinks
    </Directory>

    # Seguridad: bloquear acceso a archivos sensibles
    <FilesMatch "\.(sql|sh|liq|md)$">
        Require all denied
    </FilesMatch>

    <Directory ${PANEL_DIR}/config>
        Require all denied
    </Directory>

    <Directory ${PANEL_DIR}/install>
        Require all denied
    </Directory>

    <Directory ${PANEL_DIR}/liquidsoap>
        Require all denied
    </Directory>

    # SHOUTcast proxy rules (se llena automaticamente al crear estaciones)
    IncludeOptional ${PROXY_RULES_FILE_PATH}

    ErrorLog ${APACHE_LOG_DIR}/castpanel_error.log
    CustomLog ${APACHE_LOG_DIR}/castpanel_access.log combined
</VirtualHost>
VHOSTEOF


    if [ "$PKG_MANAGER" = "apt" ]; then
        a2ensite castpanel.conf 2>/dev/null || true
        a2dissite 000-default.conf 2>/dev/null || true
    fi

    log_success "VirtualHost HTTP configurado"

    # Proxy inverso para streaming: /live/ -> Icecast
    setup_streaming_proxy

    # SELinux (RHEL)
    if command -v setsebool &>/dev/null; then
        log_info "Configurando SELinux para proxy..."
        setsebool -P httpd_can_network_connect 1 2>/dev/null || true
        log_success "SELinux configurado"
    fi

    # Reiniciar Apache
    systemctl restart "$WEB_SERVICE"
    log_success "Apache reiniciado"
}

# ---- PROXY INVERSO STREAMING ----

setup_streaming_proxy() {
    log_info "Configurando proxy inverso /live/ -> Icecast..."

    if [ "$PKG_MANAGER" = "apt" ]; then
        PROXY_FILE="/etc/apache2/conf-available/icecast-proxy.conf"
    else
        PROXY_FILE="/etc/httpd/conf.d/icecast-proxy.conf"
    fi

    cat > "$PROXY_FILE" << PROXYEOF
# CastPanel - Proxy inverso para streaming
# /live/ -> Icecast :${ICECAST_PORT}
# Permite reproducir streams via HTTPS

ProxyPreserveHost Off
ProxyPass /live/ http://127.0.0.1:${ICECAST_PORT}/ retry=1
ProxyPassReverse /live/ http://127.0.0.1:${ICECAST_PORT}/
PROXYEOF

    if [ "$PKG_MANAGER" = "apt" ]; then
        a2enconf icecast-proxy 2>/dev/null || true
    fi

    log_success "Proxy /live/ -> Icecast:${ICECAST_PORT} configurado"
}

# ---- SSL (LET'S ENCRYPT) ----

setup_ssl() {
    if [ "$INSTALL_SSL" != "s" ] && [ "$INSTALL_SSL" != "S" ]; then
        return 0
    fi

    log_step "CONFIGURANDO SSL (LET'S ENCRYPT)"

    if ! command -v certbot &>/dev/null; then
        log_warning "Certbot no esta instalado. Instale SSL manualmente."
        return 1
    fi

    # Asegurar que Apache esta corriendo
    systemctl start "$WEB_SERVICE" 2>/dev/null

    log_info "Solicitando certificado SSL para ${PANEL_DOMAIN}..."

    if [ "$PKG_MANAGER" = "apt" ]; then
        certbot --apache -d "$PANEL_DOMAIN" --non-interactive --agree-tos -m "$LE_EMAIL" \
            --redirect 2>/dev/null
    else
        certbot --apache -d "$PANEL_DOMAIN" --non-interactive --agree-tos -m "$LE_EMAIL" \
            --redirect 2>/dev/null || \
        certbot certonly --webroot -w "$PANEL_DIR" -d "$PANEL_DOMAIN" --non-interactive \
            --agree-tos -m "$LE_EMAIL" 2>/dev/null
    fi

    if [ $? -eq 0 ]; then
        log_success "Certificado SSL instalado para ${PANEL_DOMAIN}"

        # Agregar proxy /live/ al VirtualHost SSL tambien
        if [ "$PKG_MANAGER" != "apt" ]; then
            SSL_VHOST=$(find /etc/httpd/conf.d/ -name "*ssl*" -o -name "*le-ssl*" 2>/dev/null | head -1)
            if [ -n "$SSL_VHOST" ] && ! grep -q "ProxyPass.*live" "$SSL_VHOST" 2>/dev/null; then
                sed -i "/<\/VirtualHost>/i \\
    # Proxy streaming\\n    ProxyPreserveHost Off\\n    ProxyPass /live/ http://127.0.0.1:${ICECAST_PORT}/\\n    ProxyPassReverse /live/ http://127.0.0.1:${ICECAST_PORT}/" "$SSL_VHOST"
                log_success "Proxy /live/ agregado al VirtualHost SSL"
            fi
        fi

        systemctl restart "$WEB_SERVICE"

        # Configurar renovacion automatica
        if ! crontab -l 2>/dev/null | grep -q certbot; then
            (crontab -l 2>/dev/null; echo "0 3 * * * certbot renew --quiet --post-hook 'systemctl restart ${WEB_SERVICE}'") | crontab -
            log_success "Renovacion SSL automatica configurada"
        fi
    else
        log_warning "No se pudo instalar SSL automaticamente"
        log_info "Ejecute manualmente: certbot --apache -d ${PANEL_DOMAIN}"
    fi
}

# ---- CASTPANEL-CTL (DAEMON ROOT PARA EL PANEL) ----

setup_castpanel_ctl() {
    log_step "INSTALANDO CASTPANEL-CTL"
    WEB_USER="$WEB_USER" LIQUIDSOAP_IMAGE="$LIQUIDSOAP_DOCKER_IMAGE" \
    LIQUIDSOAP_CONF_DIR="$LIQUIDSOAP_DIR" MEDIA_DIR="$MEDIA_DIR" ANUNCIOS_DIR="$ANUNCIOS_DIR" \
        bash "$PANEL_DIR/install/setup_castpanel_ctl.sh" "$PANEL_DIR" \
        || log_warning "castpanel-ctl no se pudo instalar (las estaciones no arrancaran)"
}

# ---- DJ CONTROL CENTER (PUERTO 2080) ----

setup_dj_portal() {
    log_step "CONFIGURANDO DJ CONTROL CENTER (PUERTO 2080)"
    if [ -f "$PANEL_DIR/install/setup_dj_port.sh" ]; then
        bash "$PANEL_DIR/install/setup_dj_port.sh" "$PANEL_DIR" "$PANEL_DOMAIN" \
            || log_warning "No se pudo configurar el puerto 2080 (el panel principal sigue funcionando)"
    else
        log_warning "No se encontro install/setup_dj_port.sh"
    fi
}

# ---- SUDOERS PARA PHP ----

setup_sudoers() {
    log_info "Configurando permisos sudo para el panel..."

    cat > /etc/sudoers.d/castpanel << SUDOEOF
# CastPanel - Permisos para gestion de servicios de streaming
# Docker: permite run/stop/rm/inspect/logs para contenedores liquidsoap-*
${WEB_USER} ALL=(ALL) NOPASSWD: /usr/bin/docker run *
${WEB_USER} ALL=(ALL) NOPASSWD: /usr/bin/docker stop *
${WEB_USER} ALL=(ALL) NOPASSWD: /usr/bin/docker rm *
${WEB_USER} ALL=(ALL) NOPASSWD: /usr/bin/docker start *
${WEB_USER} ALL=(ALL) NOPASSWD: /usr/bin/docker restart *
${WEB_USER} ALL=(ALL) NOPASSWD: /usr/bin/docker inspect *
${WEB_USER} ALL=(ALL) NOPASSWD: /usr/bin/docker logs *
# Icecast
${WEB_USER} ALL=(ALL) NOPASSWD: /usr/bin/systemctl reload icecast2
${WEB_USER} ALL=(ALL) NOPASSWD: /usr/bin/systemctl reload icecast
${WEB_USER} ALL=(ALL) NOPASSWD: /usr/bin/systemctl restart icecast2
# SHOUTcast sc_serv2
${WEB_USER} ALL=(ALL) NOPASSWD: /opt/shoutcast/sc_serv2 *
${WEB_USER} ALL=(ALL) NOPASSWD: /usr/bin/killall sc_serv2
${WEB_USER} ALL=(ALL) NOPASSWD: /usr/bin/pkill -f sc_serv2
${WEB_USER} ALL=(ALL) NOPASSWD: /usr/bin/ss -tlnp
# Firewall: abrir puertos para estaciones
${WEB_USER} ALL=(ALL) NOPASSWD: /usr/bin/firewall-cmd *
# Apache reload (para proxy rules de SHOUTcast)
${WEB_USER} ALL=(ALL) NOPASSWD: /usr/bin/systemctl reload httpd
${WEB_USER} ALL=(ALL) NOPASSWD: /usr/bin/systemctl reload apache2
SUDOEOF

    chmod 440 /etc/sudoers.d/castpanel
    log_success "Permisos sudo configurados"
}

# ---- VERIFICACION FINAL ----

verify_install() {
    log_step "VERIFICANDO INSTALACION"

    # PHP
    if command -v php &>/dev/null; then
        log_success "PHP: $(php -v | head -1 | awk '{print $2}')"
    else
        log_error "PHP no encontrado"
    fi

    # Extensiones PHP
    for ext in pdo pdo_mysql mbstring curl xml; do
        if php -m 2>/dev/null | grep -qi "^${ext}$"; then
            log_success "ext-$ext: OK"
        else
            log_warning "ext-$ext: FALTA"
        fi
    done

    # MySQL
    if command -v mysql &>/dev/null; then
        log_success "MariaDB: $(mysql --version 2>/dev/null | awk '{print $5}' | tr -d ',')"
    else
        log_error "MySQL/MariaDB no encontrado"
    fi

    # FFmpeg
    if command -v ffprobe &>/dev/null; then
        log_success "FFprobe: OK"
    else
        log_warning "FFprobe: No encontrado (instale ffmpeg)"
    fi

    # Docker
    if command -v docker &>/dev/null; then
        log_success "Docker: $(docker --version 2>/dev/null | awk '{print $3}' | tr -d ',')"
    else
        log_error "Docker no encontrado"
    fi

    # Imagen Liquidsoap
    if docker images --format '{{.Repository}}:{{.Tag}}' 2>/dev/null | grep -q "$LIQUIDSOAP_DOCKER_IMAGE"; then
        log_success "Liquidsoap Docker: $LIQUIDSOAP_DOCKER_IMAGE"
    else
        log_warning "Imagen Liquidsoap no encontrada"
    fi

    # Icecast
    if systemctl is-active icecast 2>/dev/null | grep -q active || \
       systemctl is-active icecast2 2>/dev/null | grep -q active; then
        log_success "Icecast: Activo"
    else
        log_warning "Icecast: No activo"
    fi

    # Apache
    if systemctl is-active "$WEB_SERVICE" 2>/dev/null | grep -q active; then
        log_success "Apache: Activo"
    else
        log_warning "Apache: No activo"
    fi

    # Proxy /live/
    if curl -s -o /dev/null -w '%{http_code}' --max-time 3 "http://127.0.0.1/live/" 2>/dev/null | grep -qE "200|404|400"; then
        log_success "Proxy /live/: OK"
    else
        log_warning "Proxy /live/: No verificado"
    fi

    # Conexion BD
    if php -r "
        try {
            new PDO('mysql:host=127.0.0.1;dbname=${DB_NAME}', '${DB_USER}', '${DB_PASS}');
            echo 'OK';
        } catch(Exception \$e) {
            echo 'FAIL';
        }
    " 2>/dev/null | grep -q "OK"; then
        log_success "Conexion BD: OK"
    else
        log_warning "Conexion BD: Error"
    fi

    # SHOUTcast
    if [ -e "/opt/shoutcast/sc_serv2" ]; then
        log_success "SHOUTcast sc_serv2: Instalado"
    else
        log_warning "SHOUTcast sc_serv2: No encontrado"
    fi

    if systemctl is-enabled castpanel-shoutcast 2>/dev/null | grep -q enabled; then
        log_success "SHOUTcast auto-start: Habilitado"
    else
        log_warning "SHOUTcast auto-start: No habilitado"
    fi

    # SSL
    if [ -f "/etc/letsencrypt/live/${PANEL_DOMAIN}/fullchain.pem" ]; then
        log_success "SSL: Certificado instalado"
    else
        log_warning "SSL: Sin certificado"
    fi
}

# ---- RESUMEN FINAL ----

print_summary() {
    echo ""
    echo -e "${GREEN}"
    echo "  ================================================================"
    echo "  |                                                              |"
    echo "  |     CastPanel v2.7.4 - Instalado Correctamente              |"
    echo "  |                                                              |"
    echo "  ================================================================"
    echo -e "${NC}"
    echo ""
    echo -e "  ${CYAN}ACCESO AL PANEL:${NC}"
    echo "  ----------------------------------------"
    if [ -f "/etc/letsencrypt/live/${PANEL_DOMAIN}/fullchain.pem" ]; then
        echo -e "  URL:            ${GREEN}https://${PANEL_DOMAIN}${NC}"
    else
        echo -e "  URL:            ${GREEN}http://${PANEL_DOMAIN}${NC}"
    fi
    echo -e "  Usuario:        ${GREEN}admin${NC}"
    echo -e "  Contrasena:     ${GREEN}${ADMIN_PASS}${NC}"
    echo "  ----------------------------------------"
    echo ""
    echo -e "  ${CYAN}BASE DE DATOS:${NC}"
    echo "  ----------------------------------------"
    echo "  Host:           127.0.0.1"
    echo "  BD:             ${DB_NAME}"
    echo "  Usuario BD:     ${DB_USER}"
    echo "  Contrasena BD:  ${DB_PASS}"
    echo "  ----------------------------------------"
    echo ""
    echo -e "  ${CYAN}ICECAST:${NC}"
    echo "  ----------------------------------------"
    echo "  Puerto:         ${ICECAST_PORT} (solo localhost)"
    echo "  Admin:          admin / ${ICECAST_ADMIN_PASS}"
    echo "  Source pass:    ${ICECAST_SOURCE_PASS}"
    echo "  Proxy:          /live/ -> :${ICECAST_PORT}"
    echo "  ----------------------------------------"
    echo ""
    echo -e "  ${CYAN}LIQUIDSOAP (DOCKER):${NC}"
    echo "  ----------------------------------------"
    echo "  Imagen:         ${LIQUIDSOAP_DOCKER_IMAGE}"
    echo "  Servicio:       systemctl {start|stop|restart} liquidsoap"
    echo "  Configs:        ${LIQUIDSOAP_DIR}/"
    echo "  ----------------------------------------"
    echo ""
    echo -e "  ${CYAN}SHOUTCAST:${NC}"
    echo "  ----------------------------------------"
    echo "  Binario:        /opt/shoutcast/sc_serv2"
    echo "  Configs:        /opt/shoutcast/configs/"
    echo "  Auto-start:     systemctl {start|stop} castpanel-shoutcast"
    echo "  Proxy:          /shoutcast/{slug} -> :puerto/stream/1/"
    echo "  ----------------------------------------"
    echo ""
    echo -e "  ${CYAN}ARCHIVOS:${NC}"
    echo "  ----------------------------------------"
    echo "  Panel:          ${PANEL_DIR}"
    echo "  Media:          ${MEDIA_DIR}"
    echo "  Anuncios:       ${ANUNCIOS_DIR}"
    echo "  Config DB:      ${PANEL_DIR}/config/database.php"
    echo "  ----------------------------------------"
    echo ""
    echo -e "  ${YELLOW}IMPORTANTE:${NC}"
    echo "  - Cambie la contrasena del admin inmediatamente"
    if [ ! -f "/etc/letsencrypt/live/${PANEL_DOMAIN}/fullchain.pem" ]; then
        echo "  - Configure SSL: certbot --apache -d ${PANEL_DOMAIN}"
    fi
    echo "  - El AutoDJ se inicia al crear una estacion desde el panel"
    echo "  - Stream URL: https://${PANEL_DOMAIN}/live/{slug}"
    echo ""
    echo -e "  ${GREEN}CastPanel v2.7.4 - TECNOHOST S.A.S${NC}"
    echo ""

    # Guardar credenciales en archivo seguro
    cat > /root/castpanel-credenciales.txt << CREDEOF
============================================
CastPanel v2.7.4 - Credenciales de Instalacion
Fecha: $(date)
============================================

Panel URL:        https://${PANEL_DOMAIN}
Admin:            admin / ${ADMIN_PASS}

Base de Datos:
  Host:           127.0.0.1
  BD:             ${DB_NAME}
  Usuario:        ${DB_USER}
  Contrasena:     ${DB_PASS}

Icecast:
  Puerto:         ${ICECAST_PORT}
  Admin:          admin / ${ICECAST_ADMIN_PASS}
  Source pass:     ${ICECAST_SOURCE_PASS}

Docker/Liquidsoap: ${LIQUIDSOAP_DOCKER_IMAGE}

SHOUTcast:
  Binario:        /opt/shoutcast/sc_serv2
  Configs:        /opt/shoutcast/configs/
  Auto-start:     systemctl {start|stop} castpanel-shoutcast

Directorios:
  Panel:          ${PANEL_DIR}
  Media:          ${MEDIA_DIR}
  Anuncios:       ${ANUNCIOS_DIR}
  Liquidsoap:     ${LIQUIDSOAP_DIR}
  Config:         ${PANEL_DIR}/config/database.php
============================================
CREDEOF
    chmod 600 /root/castpanel-credenciales.txt
    log_info "Credenciales guardadas en: /root/castpanel-credenciales.txt"
}

# ================================================================
# EJECUCION PRINCIPAL
# ================================================================

print_header
check_root
detect_os
prompt_config

log_step "INICIANDO INSTALACION COMPLETA"

# 1. Paquetes base segun OS
if [ "$PKG_MANAGER" = "apt" ]; then
    install_packages_apt
else
    install_packages_dnf
fi

# 2. Docker + Liquidsoap image
install_docker

# 3. Desplegar archivos del panel
deploy_panel

# 4. Base de datos
setup_database

# 5. Directorios y permisos
setup_directories

# 6. Icecast
setup_icecast

# 7. Servicio Liquidsoap (Docker)
setup_liquidsoap_service

# 7b. SHOUTcast DNAS v2
setup_shoutcast

# 8. Apache + Proxy
setup_apache

# 9. Permisos sudo para PHP
setup_sudoers
setup_castpanel_ctl

# 10. SSL
setup_ssl
setup_dj_portal

# 11. Verificar
verify_install

# 12. Resumen
print_summary

exit 0
