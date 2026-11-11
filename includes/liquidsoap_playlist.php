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
 */
function castpanelWriteActivePlaylistM3u(array $estacion): array {
    $db = getDB();
    $estacionId = (int)$estacion['id'];
    $slug = $estacion['slug'];
    $mediaRoot = defined('MEDIA_PATH') ? MEDIA_PATH : '/var/castpanel/media';
    $stationDir = rtrim($mediaRoot, '/') . '/' . $slug;
    $m3uPath = $stationDir . '/active.m3u';
    $activePlaylistId = $estacion['autodj_playlist_activa'] ?? null;

    if ($activePlaylistId) {
        $stmt = $db->prepare(
            'SELECT ruta_archivo, titulo, artista, duracion_segundos FROM canciones WHERE estacion_id = ? AND playlist_id = ? AND activa = 1 ORDER BY orden ASC'
        );
        $stmt->execute([$estacionId, $activePlaylistId]);
    } else {
        $stmt = $db->prepare(
            'SELECT ruta_archivo, titulo, artista, duracion_segundos FROM canciones WHERE estacion_id = ? AND activa = 1 ORDER BY orden ASC'
        );
        $stmt->execute([$estacionId]);
    }
    $songs = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (!is_dir($stationDir)) {
        @mkdir($stationDir, 0775, true);
    }

    // Empty playlist is OK: Liquidsoap falls back to silence until uploads arrive.
    $lines = ["#EXTM3U", '# CastPanel — playlist activa'];
    foreach ($songs as $row) {
        $hostPath = (string)($row['ruta_archivo'] ?? '');
        if ($hostPath === '') {
            continue;
        }
        $artist = trim((string)($row['artista'] ?? ''));
        $title = trim((string)($row['titulo'] ?? ''));
        if ($title === '') {
            $title = pathinfo($hostPath, PATHINFO_FILENAME);
        }
        $display = $artist !== '' ? ($artist . ' - ' . $title) : $title;
        $dur = max(1, (int)($row['duracion_segundos'] ?? 0));
        $lines[] = '#EXTINF:' . $dur . ',' . $display;
        $lines[] = castpanelContainerMediaPath($hostPath, $slug);
    }
    if (file_put_contents($m3uPath, implode("\n", $lines) . "\n") === false) {
        return ['success' => false, 'error' => "No se pudo escribir $m3uPath", 'count' => 0];
    }

    return [
        'success' => true,
        'path' => $m3uPath,
        'count' => count($songs),
        'liquidsoap_uri' => '/media/active.m3u',
    ];
}
