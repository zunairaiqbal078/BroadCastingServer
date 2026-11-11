<?php
/**
 * CastPanel v2.4 - Configuracion de Base de Datos
 * Para instalacion nueva: el install.sh reemplaza los valores __PLACEHOLDER__
 */

define('DB_HOST', '__DB_HOST__');
define('DB_NAME', '__DB_NAME__');
define('DB_USER', '__DB_USER__');
define('DB_PASS', '__DB_PASS__');
define('DB_CHARSET', 'utf8mb4');

// Ruta base de la aplicacion
define('BASE_PATH', dirname(__DIR__));
define('BASE_URL', ''); // Ej: /castpanel o vacio si esta en raiz

// Rutas de archivos
define('MEDIA_PATH', '/var/castpanel/media');
define('ANUNCIOS_PATH', '/var/castpanel/anuncios');
// Liquidsoap .liq files (must live under /var — Apache cannot write under /etc)
define('LIQUIDSOAP_CONFIG_DIR', '/var/castpanel/liquidsoap/stations');

// Icecast
define('ICECAST_HOST', '127.0.0.1');
define('ICECAST_PORT', 8000);
define('ICECAST_ADMIN_USER', 'admin');
define('ICECAST_ADMIN_PASS', '__ICECAST_ADMIN_PASS__');

// Liquidsoap
define('LIQUIDSOAP_TELNET_HOST', '127.0.0.1');
define('LIQUIDSOAP_TELNET_PORT', 1234);
define('LIQUIDSOAP_HOST', '127.0.0.1');
define('LIQUIDSOAP_PORT', 1234);

// SSL / Dominio publico
define('SSL_DOMAIN', '__SSL_DOMAIN__');

// Sesion
define('SESSION_NAME', 'castpanel_session');
define('SESSION_LIFETIME', 86400); // 24 horas

// Zona horaria
date_default_timezone_set('__TIMEZONE__');

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

// Harbor (puerto de entrada para DJs en vivo - Liquidsoap input.harbor)
// Formula: puerto_stream + 5
define('HARBOR_PORT_BASE', 9000);
