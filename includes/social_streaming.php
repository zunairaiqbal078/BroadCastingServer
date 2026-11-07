<?php
/**
 * CastPanel - Social Streaming Functions
 * Funciones compartidas para retransmision a YouTube, Facebook, TikTok, Instagram
 * v2 - Estilo SonicPanel + subida de imagen
 */

/**
 * Crear tabla retransmisiones si no existe
 */
function ensureSocialStreamTable() {
    $db = getDB();
    $db->exec("CREATE TABLE IF NOT EXISTS retransmisiones (
        id INT AUTO_INCREMENT PRIMARY KEY,
        estacion_id INT NOT NULL,
        plataforma VARCHAR(20) NOT NULL,
        rtmp_url VARCHAR(500) DEFAULT '',
        stream_key VARCHAR(500) DEFAULT '',
        use_image TINYINT(1) DEFAULT 1,
        estado ENUM('active','stopped','error') DEFAULT 'stopped',
        pid INT DEFAULT NULL,
        last_started DATETIME DEFAULT NULL,
        last_error TEXT DEFAULT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uq_estacion_plat (estacion_id, plataforma),
        FOREIGN KEY (estacion_id) REFERENCES estaciones(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

/**
 * URLs RTMP por defecto de cada plataforma
 */
function getDefaultRtmpUrl($platform) {
    $urls = [
        'youtube'   => 'rtmp://a.rtmp.youtube.com/live2/',
        'facebook'  => 'rtmps://live-api-s.facebook.com:443/rtmp/',
        'instagram' => 'rtmps://live-upload.instagram.com:443/rtmp/',
        'tiktok'    => '',
    ];
    return $urls[$platform] ?? '';
}

/**
 * Obtener config de retransmision
 */
function getSocialStreamConfig($estacionId, $platform) {
    $db = getDB();
    $st = $db->prepare("SELECT * FROM retransmisiones WHERE estacion_id = ? AND plataforma = ?");
    $st->execute([$estacionId, $platform]);
    $row = $st->fetch();
    if (!$row) {
        return [
            'stream_key' => '',
            'rtmp_url'   => getDefaultRtmpUrl($platform),
            'use_image'  => 1,
            'estado'     => 'stopped',
            'pid'        => null,
        ];
    }
    return $row;
}

/**
 * Guardar config de retransmision
 */
function saveSocialStreamConfig($estacionId, $platform, $data) {
    $db = getDB();
    $existing = $db->prepare("SELECT id FROM retransmisiones WHERE estacion_id = ? AND plataforma = ?");
    $existing->execute([$estacionId, $platform]);

    $rtmpUrl = $data['rtmp_url'] ?? getDefaultRtmpUrl($platform);
    $streamKey = $data['stream_key'] ?? '';
    $useImage = isset($data['use_image']) ? (int)$data['use_image'] : 1;

    if ($existing->fetch()) {
        $st = $db->prepare("UPDATE retransmisiones SET stream_key = ?, rtmp_url = ?, use_image = ? WHERE estacion_id = ? AND plataforma = ?");
        return $st->execute([$streamKey, $rtmpUrl, $useImage, $estacionId, $platform]);
    } else {
        $st = $db->prepare("INSERT INTO retransmisiones (estacion_id, plataforma, stream_key, rtmp_url, use_image) VALUES (?, ?, ?, ?, ?)");
        return $st->execute([$estacionId, $platform, $streamKey, $rtmpUrl, $useImage]);
    }
}

/**
 * Iniciar retransmision FFmpeg
 */
function startSocialStream($estacionId, $platform) {
    $config = getSocialStreamConfig($estacionId, $platform);
    $streamKey = $config['stream_key'] ?? '';
    if (empty($streamKey)) {
        return ['ok' => false, 'error' => 'No se ha configurado la clave de transmisi' . chr(0xC3) . chr(0xB3) . 'n'];
    }

    // Obtener URL del stream de la estacion
    $db = getDB();
    $st = $db->prepare("SELECT * FROM estaciones WHERE id = ?");
    $st->execute([$estacionId]);
    $station = $st->fetch();
    if (!$station) {
        return ['ok' => false, 'error' => 'Estaci' . chr(0xC3) . chr(0xB3) . 'n no encontrada'];
    }

    $rtmpUrl = $config['rtmp_url'] ?: getDefaultRtmpUrl($platform);
    $fullRtmp = rtrim($rtmpUrl, '/') . '/' . $streamKey;

    // Source URL (SHOUTcast)
    $port = $station['puerto'] ?? 8000;
    $sourceUrl = "http://127.0.0.1:{$port}/stream";

    // Verificar si hay imagen para usar como video
    $imagePath = getStreamImagePath($estacionId, $platform);
    $useImage = $config['use_image'] && $imagePath;

    // Construir comando FFmpeg
    if ($useImage) {
        $cmd = "ffmpeg -re -loop 1 -i " . escapeshellarg($imagePath)
             . " -i " . escapeshellarg($sourceUrl)
             . " -c:v libx264 -preset ultrafast -tune stillimage -pix_fmt yuv420p"
             . " -c:a aac -b:a 128k -ar 44100 -shortest"
             . " -f flv " . escapeshellarg($fullRtmp);
    } else {
        // Solo audio (sin imagen), genera video negro
        $cmd = "ffmpeg -f lavfi -i color=c=black:s=1280x720:r=1"
             . " -i " . escapeshellarg($sourceUrl)
             . " -c:v libx264 -preset ultrafast -tune stillimage -pix_fmt yuv420p"
             . " -c:a aac -b:a 128k -ar 44100 -shortest"
             . " -f flv " . escapeshellarg($fullRtmp);
    }

    // Directorio de logs
    $logDir = '/var/log/castpanel/streaming';
    if (!is_dir($logDir)) {
        @mkdir($logDir, 0755, true);
    }
    $logFile = $logDir . "/{$estacionId}_{$platform}.log";

    // Ejecutar via castpanelCtl o directamente
    $fullCmd = "nohup {$cmd} > " . escapeshellarg($logFile) . " 2>&1 & echo \$!";

    if (function_exists('castpanelCtl')) {
        $result = castpanelCtl('stream_start', [
            'station_id' => $estacionId,
            'platform'   => $platform,
            'command'     => $fullCmd,
        ]);
        $pid = $result['pid'] ?? 0;
    } else {
        $pid = (int)trim(shell_exec($fullCmd));
    }

    if ($pid > 0) {
        $up = $db->prepare("UPDATE retransmisiones SET estado = 'active', pid = ?, last_started = NOW(), last_error = NULL WHERE estacion_id = ? AND plataforma = ?");
        $up->execute([$pid, $estacionId, $platform]);
        return ['ok' => true, 'pid' => $pid];
    }

    $up = $db->prepare("UPDATE retransmisiones SET estado = 'error', last_error = 'No se pudo iniciar FFmpeg' WHERE estacion_id = ? AND plataforma = ?");
    $up->execute([$estacionId, $platform]);
    return ['ok' => false, 'error' => 'No se pudo iniciar el proceso FFmpeg'];
}

/**
 * Detener retransmision
 */
function stopSocialStream($estacionId, $platform) {
    $db = getDB();
    $st = $db->prepare("SELECT pid FROM retransmisiones WHERE estacion_id = ? AND plataforma = ?");
    $st->execute([$estacionId, $platform]);
    $row = $st->fetch();

    if ($row && $row['pid']) {
        if (function_exists('castpanelCtl')) {
            castpanelCtl('stream_stop', [
                'station_id' => $estacionId,
                'platform'   => $platform,
                'pid'        => $row['pid'],
            ]);
        } else {
            @posix_kill((int)$row['pid'], 15); // SIGTERM
            usleep(500000);
            if (@posix_kill((int)$row['pid'], 0)) {
                @posix_kill((int)$row['pid'], 9); // SIGKILL
            }
        }
    }

    $up = $db->prepare("UPDATE retransmisiones SET estado = 'stopped', pid = NULL WHERE estacion_id = ? AND plataforma = ?");
    $up->execute([$estacionId, $platform]);
    return true;
}

/**
 * Verificar estado del stream
 */
function checkSocialStreamStatus($estacionId, $platform) {
    $db = getDB();
    $st = $db->prepare("SELECT estado, pid FROM retransmisiones WHERE estacion_id = ? AND plataforma = ?");
    $st->execute([$estacionId, $platform]);
    $row = $st->fetch();

    if (!$row) return 'stopped';
    if ($row['estado'] === 'active' && $row['pid']) {
        // Verificar si el proceso sigue vivo
        $alive = false;
        if (function_exists('posix_kill')) {
            $alive = @posix_kill((int)$row['pid'], 0);
        } else {
            $alive = file_exists("/proc/{$row['pid']}");
        }
        if (!$alive) {
            $up = $db->prepare("UPDATE retransmisiones SET estado = 'stopped', pid = NULL WHERE estacion_id = ? AND plataforma = ?");
            $up->execute([$estacionId, $platform]);
            return 'stopped';
        }
    }
    return $row['estado'];
}

// ========== Funciones de Imagen ==========

/**
 * Directorio de imagenes de streaming
 */
function getStreamingImageDir() {
    return '/var/www/castpanel/uploads/streaming';
}

/**
 * Obtener ruta de imagen en disco
 */
function getStreamImagePath($estacionId, $platform) {
    $dir = getStreamingImageDir();
    foreach (['jpg', 'jpeg', 'png'] as $ext) {
        $path = $dir . '/' . (int)$estacionId . '_' . $platform . '.' . $ext;
        if (file_exists($path)) return $path;
    }
    return null;
}

/**
 * Obtener URL web de la imagen
 */
function getStreamImageUrl($estacionId, $platform) {
    $path = getStreamImagePath($estacionId, $platform);
    if ($path) {
        return '/uploads/streaming/' . basename($path) . '?t=' . filemtime($path);
    }
    return null;
}

/**
 * Subir imagen de streaming
 */
function uploadStreamImage($estacionId, $platform, $file) {
    if (empty($file['tmp_name']) || $file['error'] !== UPLOAD_ERR_OK) {
        return false;
    }

    // Validar tipo
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime = finfo_file($finfo, $file['tmp_name']);
    finfo_close($finfo);

    $allowed = ['image/jpeg' => 'jpg', 'image/png' => 'png'];
    if (!isset($allowed[$mime])) return false;

    // Validar tamano (3MB max)
    if ($file['size'] > 3 * 1024 * 1024) return false;

    $dir = getStreamingImageDir();
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }

    // Eliminar imagen anterior
    deleteStreamImage($estacionId, $platform);

    $ext = $allowed[$mime];
    $dest = $dir . '/' . (int)$estacionId . '_' . $platform . '.' . $ext;

    if (move_uploaded_file($file['tmp_name'], $dest)) {
        chmod($dest, 0644);
        return true;
    }
    return false;
}

/**
 * Eliminar imagen de streaming
 */
function deleteStreamImage($estacionId, $platform) {
    foreach (['jpg', 'jpeg', 'png'] as $ext) {
        $path = getStreamingImageDir() . '/' . (int)$estacionId . '_' . $platform . '.' . $ext;
        if (file_exists($path)) {
            @unlink($path);
        }
    }
}

// ========== Iconos SVG de plataformas ==========

function socialPlatformIcon($platform, $size = 28) {
    $s = (int)$size;
    $icons = [
        'youtube' => '<svg width="'.$s.'" height="'.$s.'" viewBox="0 0 24 24" fill="#FF0000"><path d="M23.498 6.186a3.016 3.016 0 00-2.122-2.136C19.505 3.546 12 3.546 12 3.546s-7.505 0-9.377.504A3.017 3.017 0 00.502 6.186C0 8.07 0 12 0 12s0 3.93.502 5.814a3.016 3.016 0 002.122 2.136c1.871.504 9.376.504 9.376.504s7.505 0 9.377-.504a3.015 3.015 0 002.122-2.136C24 15.93 24 12 24 12s0-3.93-.502-5.814zM9.545 15.568V8.432L15.818 12l-6.273 3.568z"/></svg>',
        'facebook' => '<svg width="'.$s.'" height="'.$s.'" viewBox="0 0 24 24" fill="#1877F2"><path d="M24 12.073c0-6.627-5.373-12-12-12s-12 5.373-12 12c0 5.99 4.388 10.954 10.125 11.854v-8.385H7.078v-3.47h3.047V9.43c0-3.007 1.792-4.669 4.533-4.669 1.312 0 2.686.235 2.686.235v2.953H15.83c-1.491 0-1.956.925-1.956 1.874v2.25h3.328l-.532 3.47h-2.796v8.385C19.612 23.027 24 18.062 24 12.073z"/></svg>',
        'tiktok' => '<svg width="'.$s.'" height="'.$s.'" viewBox="0 0 24 24" fill="#000"><path d="M19.59 6.69a4.83 4.83 0 01-3.77-4.25V2h-3.45v13.67a2.89 2.89 0 01-2.88 2.5 2.89 2.89 0 01-2.89-2.89 2.89 2.89 0 012.89-2.89c.28 0 .54.04.79.1v-3.5a6.37 6.37 0 00-.79-.05A6.34 6.34 0 003.15 15.2a6.34 6.34 0 0010.86 4.48V13.2a8.16 8.16 0 004.77 1.52v-3.4a4.85 4.85 0 01-.8-.15V6.69z"/></svg>',
        'instagram' => '<svg width="'.$s.'" height="'.$s.'" viewBox="0 0 24 24" fill="#E4405F"><path d="M12 2.163c3.204 0 3.584.012 4.85.07 3.252.148 4.771 1.691 4.919 4.919.058 1.265.069 1.645.069 4.849 0 3.205-.012 3.584-.069 4.849-.149 3.225-1.664 4.771-4.919 4.919-1.266.058-1.644.07-4.85.07-3.204 0-3.584-.012-4.849-.07-3.26-.149-4.771-1.699-4.919-4.92-.058-1.265-.07-1.644-.07-4.849 0-3.204.013-3.583.07-4.849.149-3.227 1.664-4.771 4.919-4.919 1.266-.057 1.645-.069 4.849-.069zM12 0C8.741 0 8.333.014 7.053.072 2.695.272.273 2.69.073 7.052.014 8.333 0 8.741 0 12c0 3.259.014 3.668.072 4.948.2 4.358 2.618 6.78 6.98 6.98C8.333 23.986 8.741 24 12 24c3.259 0 3.668-.014 4.948-.072 4.354-.2 6.782-2.618 6.979-6.98.059-1.28.073-1.689.073-4.948 0-3.259-.014-3.667-.072-4.947-.196-4.354-2.617-6.78-6.979-6.98C15.668.014 15.259 0 12 0zm0 5.838a6.162 6.162 0 100 12.324 6.162 6.162 0 000-12.324zM12 16a4 4 0 110-8 4 4 0 010 8zm6.406-11.845a1.44 1.44 0 100 2.881 1.44 1.44 0 000-2.881z"/></svg>',
    ];
    return $icons[$platform] ?? '';
}
