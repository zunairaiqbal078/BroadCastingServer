<?php
/**
 * CastPanel v2.6 - AJAX: Obtener datos completos de una estacion
 * Retorna JSON con info de estacion, usuario, paquete, playlists, status
 */
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/provisioning.php';
requireRole('admin');

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) {
    echo json_encode(['success' => false, 'error' => 'ID invalido']);
    exit;
}

try {
    $db = getDB();

    // Estacion + usuario + paquete
    $stmt = $db->prepare("
        SELECT e.*,
               u.username, u.email AS user_email, u.nombre AS user_nombre, u.activo AS user_activo,
               p.nombre AS paquete_nombre, p.max_storage_mb, p.max_bitrate, p.max_oyentes,
               p.autodj_enabled, p.max_dj, p.anuncios_enabled, p.ssl_enabled,
               p.server_type AS paquete_server_type, p.allow_system_change
        FROM estaciones e
        JOIN usuarios u ON u.id = e.usuario_id
        LEFT JOIN paquetes p ON p.id = e.paquete_id
        WHERE e.id = ?
    ");
    $stmt->execute([$id]);
    $row = $stmt->fetch();

    if (!$row) {
        echo json_encode(['success' => false, 'error' => 'Estacion no encontrada']);
        exit;
    }

    // Playlists
    $stmtPl = $db->prepare("SELECT id, nombre, slug, total_canciones, activa FROM playlists WHERE estacion_id = ? ORDER BY nombre");
    $stmtPl->execute([$id]);
    $playlists = $stmtPl->fetchAll();

    // Estado del stream
    $streamStatus = ['status' => 'offline', 'listeners' => 0, 'title' => ''];
    try {
        $streamStatus = getStreamStatus($row);
    } catch (\Exception $ex) {
        // sin conexion al servidor de streaming
    }

    // IP del servidor (publica, no 127.0.0.1)
    $serverIp = '';
    // 1) Resolver desde SSL_DOMAIN
    if (defined('SSL_DOMAIN') && SSL_DOMAIN && strpos(SSL_DOMAIN, '__') === false) {
        $resolved = gethostbyname(SSL_DOMAIN);
        if ($resolved !== SSL_DOMAIN) {
            $serverIp = $resolved;
        }
    }
    // 2) Intentar hostname -I
    if (!$serverIp || $serverIp === '127.0.0.1') {
        $hostIp = trim(shell_exec('hostname -I 2>/dev/null') ?? '');
        if ($hostIp) {
            $parts = explode(' ', $hostIp);
            foreach ($parts as $ip) {
                $ip = trim($ip);
                if ($ip && $ip !== '127.0.0.1' && filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
                    $serverIp = $ip;
                    break;
                }
            }
        }
    }
    // 3) Fallback a SERVER_ADDR
    if (!$serverIp || $serverIp === '127.0.0.1') {
        $serverIp = $_SERVER['SERVER_ADDR'] ?? gethostbyname(gethostname());
    }

    // Puerto harbor (streaming + 5)
    $harborPort = ((int)($row['puerto_stream'] ?? 0)) + 5;

    // SSL URL
    $sslDomain = defined('SSL_DOMAIN') ? SSL_DOMAIN : $_SERVER['SERVER_NAME'];
    $isShoutcast = in_array($row['server_type'], ['shoutcast_v1', 'shoutcast_v2', 'shoutcast_v25']);
    $sslUrl = $isShoutcast
        ? 'https://' . $sslDomain . '/shoutcast/' . $row['slug']
        : 'https://' . $sslDomain . '/live/' . $row['slug'];

    // Paquetes disponibles (para el select)
    $paquetes = $db->query("SELECT id, nombre FROM paquetes WHERE activo = 1 ORDER BY nombre")->fetchAll();

    $data = [
        'success'    => true,
        'station'    => [
            'id'                => (int)$row['id'],
            'nombre'            => $row['nombre'],
            'slug'              => $row['slug'],
            'descripcion'       => $row['descripcion'] ?? '',
            'url_web'           => $row['url_web'] ?? '',
            'titulo_radio'      => $row['titulo_radio'] ?? '',
            'genero'            => $row['genero'] ?? '',
            'server_type'       => $row['server_type'],
            'icecast_mount'     => $row['icecast_mount'] ?? '',
            'icecast_port'      => (int)($row['icecast_port'] ?? 0),
            'icecast_password'  => $row['icecast_password'] ?? '',
            'shoutcast_sid'     => (int)($row['shoutcast_sid'] ?? 1),
            'codec'             => $row['codec'] ?? 'mp3',
            'bitrate'           => (int)($row['bitrate'] ?? 128),
            'samplerate'        => (int)($row['samplerate'] ?? 44100),
            'puerto_stream'     => (int)($row['puerto_stream'] ?? 0),
            'channels'          => (int)($row['channels'] ?? 2),
            'activa'            => (int)$row['activa'],
            'servicio_estado'   => $row['servicio_estado'] ?? 'stopped',
            'autodj_estado'     => $row['autodj_estado'] ?? 'offline',
            'autodj_playlist_activa' => $row['autodj_playlist_activa'] ?? '',
            'modo_reproduccion' => $row['modo_reproduccion'] ?? 'normal',
            'crossfade_seconds' => (int)($row['crossfade_seconds'] ?? 3),
            'mostrar_titulos'   => (int)($row['mostrar_titulos'] ?? 1),
            'grabar_dj'         => (int)($row['grabar_dj'] ?? 0),
            'calidad_grabacion' => $row['calidad_grabacion'] ?? 'alta',
            'buffer_dj_delay'   => (int)($row['buffer_dj_delay'] ?? 5),
            'paquete_id'        => (int)($row['paquete_id'] ?? 0),
            'usuario_id'        => (int)$row['usuario_id'],
        ],
        'user'       => [
            'username'   => $row['username'],
            'email'      => $row['user_email'] ?? '',
            'nombre'     => $row['user_nombre'] ?? '',
            'activo'     => (int)($row['user_activo'] ?? 1),
        ],
        'paquete'    => [
            'nombre'             => $row['paquete_nombre'] ?? 'Sin paquete',
            'max_storage_mb'     => (int)($row['max_storage_mb'] ?? 0),
            'max_bitrate'        => (int)($row['max_bitrate'] ?? 320),
            'max_oyentes'        => (int)($row['max_oyentes'] ?? 0),
            'autodj_enabled'     => (int)($row['autodj_enabled'] ?? 0),
            'max_dj'             => (int)($row['max_dj'] ?? 0),
            'ssl_enabled'        => (int)($row['ssl_enabled'] ?? 0),
            'allow_system_change'=> (int)($row['allow_system_change'] ?? 0),
        ],
        'playlists'  => $playlists,
        'stream'     => $streamStatus,
        'server_ip'  => $serverIp,
        'harbor_port'=> $harborPort,
        'ssl_url'    => $sslUrl,
        'ssl_domain' => $sslDomain,
        'paquetes_list' => $paquetes,
    ];

    echo json_encode($data, JSON_UNESCAPED_UNICODE);

} catch (\PDOException $e) {
    echo json_encode(['success' => false, 'error' => 'Error DB: ' . $e->getMessage()]);
}
