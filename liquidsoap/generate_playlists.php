<?php
/**
 * CastPanel v2.0 - Regenerador de playlists M3U
 *
 * Uso: php generate_playlists.php <estacion_id>
 *
 * Regenera el archivo M3U de la playlist activa.
 * Llamar desde el panel cuando:
 *   - Se cambia la playlist activa
 *   - Se agregan/eliminan canciones de una playlist
 *   - Se reordena una playlist
 *
 * Liquidsoap detecta el cambio automaticamente (reload_mode=watch)
 * y recarga la playlist sin reiniciar.
 */
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/liquidsoap_playlist.php';

if (php_sapi_name() !== 'cli') {
    die('Este script solo se ejecuta desde CLI');
}

$estacionId = (int)($argv[1] ?? 0);
if (!$estacionId) {
    echo "Uso: php generate_playlists.php <estacion_id>\n";
    exit(1);
}

$db = getDB();
$stmt = $db->prepare("SELECT id, slug, autodj_playlist_activa FROM estaciones WHERE id = ?");
$stmt->execute([$estacionId]);
$estacion = $stmt->fetch();

if (!$estacion) {
    echo "Error: Estacion #$estacionId no encontrada\n";
    exit(1);
}

$result = castpanelWriteActivePlaylistM3u($estacion);
if (!$result['success']) {
    echo "Error: " . ($result['error'] ?? 'M3U') . "\n";
    exit(1);
}

echo "OK: Playlist activa actualizada\n";
echo "Archivo: {$result['path']}\n";
echo "Canciones: {$result['count']}\n";
echo "Liquidsoap recargara automaticamente (watch en active.m3u).\n";
