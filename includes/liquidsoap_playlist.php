<?php
/**
 * Active playlist M3U for Liquidsoap (Docker mounts station media at /media).
 */

function castpanelContainerMediaPath(string $rutaArchivo, string $slug): string {
    if (str_starts_with($rutaArchivo, '/media/')) {
        return $rutaArchivo;
    }
    $mediaRoot = defined('MEDIA_PATH') ? MEDIA_PATH : '/var/castpanel/media';
    $stationDir = rtrim($mediaRoot, '/') . '/' . $slug . '/';
    if (str_starts_with($rutaArchivo, $stationDir)) {
        return '/media/' . substr($rutaArchivo, strlen($stationDir));
    }
    return '/media/' . basename($rutaArchivo);
}

/**
 * Write active.m3u on disk (host) with container paths for Liquidsoap.
 * What goes in it (default folder, AutoDJ schedule or folder rotation) is
 * decided by includes/autodj_schedule.php.
 */
function castpanelWriteActivePlaylistM3u(array $estacion): array {
    require_once __DIR__ . '/autodj_schedule.php';
    $r = castpanelWriteMusicM3u($estacion);
    if (!$r['ok']) {
        return ['success' => false, 'error' => 'No se pudo escribir ' . $r['path'], 'count' => 0];
    }
    return [
        'success' => true,
        'path' => $r['path'],
        'count' => $r['count'],
        'changed' => $r['changed'],
        'liquidsoap_uri' => '/media/active.m3u',
    ];
}
