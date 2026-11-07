<?php
/**
 * CastPanel — Funciones de Retransmision a Redes Sociales
 * Requiere FFmpeg + castpanel-restream.sh en /usr/local/bin/
 */

define('RESTREAM_SCRIPT', '/usr/local/bin/castpanel-restream.sh');
define('RESTREAM_UPLOAD_DIR', '/var/www/castpanel/uploads/restream');
define('RESTREAM_LOG_DIR', '/var/log/castpanel');

/**
 * Obtener URL del stream de audio de la estacion
 */
function getStationAudioUrl($estacion) {
    $type = $estacion['server_type'] ?? 'shoutcast_v2';

    if ($type === 'icecast') {
        $port = $estacion['icecast_port'] ?? 8000;
        $mount = $estacion['icecast_mount'] ?? '/live';
        if (strpos($mount, '/') !== 0) $mount = '/' . $mount;
        return "http://127.0.0.1:{$port}{$mount}";
    } else {
        $port = $estacion['puerto_stream'] ?? 8010;
        $sid  = $estacion['shoutcast_sid'] ?? 1;
        return "http://127.0.0.1:{$port}/stream/{$sid}/";
    }
}

/**
 * Info de plataformas soportadas
 */
function getRestreamPlatforms() {
    return [
        'facebook' => [
            'name'     => 'Facebook Live',
            'rtmp_url' => 'rtmps://live-api-s.facebook.com:443/rtmp/',
            'color'    => '#1877F2',
            'help'     => 'facebook.com/live/producer &rarr; Clave de transmision',
        ],
        'youtube' => [
            'name'     => 'YouTube Live',
            'rtmp_url' => 'rtmp://a.rtmp.youtube.com/live2/',
            'color'    => '#FF0000',
            'help'     => 'studio.youtube.com &rarr; Transmitir en vivo &rarr; Clave',
        ],
        'tiktok' => [
            'name'     => 'TikTok Live',
            'rtmp_url' => 'rtmp://push.tiktok.com/live/',
            'color'    => '#010101',
            'help'     => 'TikTok &rarr; LIVE &rarr; Obtener URL y clave del servidor',
        ],
        'instagram' => [
            'name'     => 'Instagram Live',
            'rtmp_url' => 'rtmps://live-upload.instagram.com:443/rtmp/',
            'color'    => '#E4405F',
            'help'     => 'Usa Instagram API o herramientas de terceros para la URL RTMP',
        ],
        'custom' => [
            'name'     => 'Personalizado',
            'rtmp_url' => '',
            'color'    => '#6c757d',
            'help'     => 'Ingresa la URL RTMP de cualquier servidor compatible',
        ],
    ];
}

/**
 * Iniciar retransmision via shell script (sudo)
 */
function startRestream($restreamId, $estacionId) {
    $db = getDB();

    $stmt = $db->prepare("
        SELECT r.*, e.server_type, e.puerto_stream, e.icecast_port, e.icecast_mount, e.shoutcast_sid
        FROM retransmisiones r
        JOIN estaciones e ON e.id = r.estacion_id
        WHERE r.id = ? AND r.estacion_id = ?
    ");
    $stmt->execute([$restreamId, $estacionId]);
    $r = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$r) return ['success' => false, 'error' => 'No encontrada'];

    // Ya corriendo?
    if ($r['estado'] === 'running' && $r['pid']) {
        $chk = trim(shell_exec("sudo " . RESTREAM_SCRIPT . " status " . intval($r['pid']) . " 2>&1"));
        if ($chk === 'running') {
            return ['success' => false, 'error' => 'Ya esta retransmitiendo'];
        }
    }

    // Cover image
    $cover = RESTREAM_UPLOAD_DIR . '/' . basename($r['cover_image'] ?? '');
    if (!$r['cover_image'] || !file_exists($cover)) {
        return ['success' => false, 'error' => 'Sube una imagen de portada primero'];
    }

    // Audio URL
    $audioUrl = getStationAudioUrl($r);

    // RTMP destination
    $rtmpDest = rtrim($r['rtmp_url'], '/') . '/' . $r['stream_key'];

    // Log file
    $logFile = RESTREAM_LOG_DIR . "/restream_{$restreamId}.log";

    // Execute via sudo
    $cmd = sprintf(
        "sudo %s start %d %s %s %s %s 2>&1",
        escapeshellarg(RESTREAM_SCRIPT),
        $restreamId,
        escapeshellarg($cover),
        escapeshellarg($audioUrl),
        escapeshellarg($rtmpDest),
        escapeshellarg($logFile)
    );

    $output = trim(shell_exec($cmd));

    if (strpos($output, 'PID:') === 0) {
        $pid = intval(substr($output, 4));
        $db->prepare("UPDATE retransmisiones SET estado='running', pid=?, ultimo_error=NULL, updated_at=NOW() WHERE id=?")
           ->execute([$pid, $restreamId]);
        return ['success' => true, 'pid' => $pid];
    }

    $errMsg = str_replace('ERROR:', '', $output);
    $db->prepare("UPDATE retransmisiones SET estado='error', ultimo_error=?, updated_at=NOW() WHERE id=?")
       ->execute([$errMsg, $restreamId]);

    return ['success' => false, 'error' => $errMsg];
}

/**
 * Detener retransmision
 */
function stopRestream($restreamId, $estacionId) {
    $db = getDB();

    $stmt = $db->prepare("SELECT * FROM retransmisiones WHERE id = ? AND estacion_id = ?");
    $stmt->execute([$restreamId, $estacionId]);
    $r = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$r) return ['success' => false, 'error' => 'No encontrada'];

    if ($r['pid']) {
        shell_exec("sudo " . RESTREAM_SCRIPT . " stop " . intval($r['pid']) . " 2>&1");
    }

    $db->prepare("UPDATE retransmisiones SET estado='stopped', pid=NULL, updated_at=NOW() WHERE id=?")
       ->execute([$restreamId]);

    return ['success' => true];
}

/**
 * Sincronizar estados reales de procesos
 */
function syncRestreamStatuses($estacionId) {
    $db = getDB();
    $stmt = $db->prepare("SELECT id, pid FROM retransmisiones WHERE estacion_id = ? AND estado = 'running'");
    $stmt->execute([$estacionId]);

    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        if (!$row['pid']) continue;
        $chk = trim(shell_exec("sudo " . RESTREAM_SCRIPT . " status " . intval($row['pid']) . " 2>&1"));
        if ($chk !== 'running') {
            $db->prepare("UPDATE retransmisiones SET estado='stopped', pid=NULL, ultimo_error='Proceso finalizado' WHERE id=?")
               ->execute([$row['id']]);
        }
    }
}

/**
 * Subir imagen de portada
 */
function uploadRestreamCover($file, $restreamId) {
    $allowed = ['image/jpeg', 'image/png', 'image/webp'];
    $maxSize = 5 * 1024 * 1024; // 5MB

    if ($file['error'] !== UPLOAD_ERR_OK) {
        return ['success' => false, 'error' => 'Error en la subida'];
    }
    if ($file['size'] > $maxSize) {
        return ['success' => false, 'error' => 'Imagen muy grande (max 5MB)'];
    }

    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = $finfo->file($file['tmp_name']);
    if (!in_array($mime, $allowed)) {
        return ['success' => false, 'error' => 'Solo JPG, PNG o WebP'];
    }

    $ext = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'][$mime];
    $filename = "cover_{$restreamId}_" . time() . ".{$ext}";
    $dest = RESTREAM_UPLOAD_DIR . '/' . $filename;

    if (!move_uploaded_file($file['tmp_name'], $dest)) {
        return ['success' => false, 'error' => 'No se pudo guardar el archivo'];
    }

    // Actualizar BD
    $db = getDB();

    // Borrar cover anterior
    $old = $db->prepare("SELECT cover_image FROM retransmisiones WHERE id = ?");
    $old->execute([$restreamId]);
    $oldFile = $old->fetchColumn();
    if ($oldFile) {
        $oldPath = RESTREAM_UPLOAD_DIR . '/' . basename($oldFile);
        if (file_exists($oldPath)) @unlink($oldPath);
    }

    $db->prepare("UPDATE retransmisiones SET cover_image = ?, updated_at = NOW() WHERE id = ?")
       ->execute([$filename, $restreamId]);

    return ['success' => true, 'filename' => $filename];
}
