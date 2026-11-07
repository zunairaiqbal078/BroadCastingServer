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

$slug = $estacion['slug'];
$activePlaylistId = $estacion['autodj_playlist_activa'];
$mediaPath = defined('MEDIA_PATH') ? MEDIA_PATH : '/var/castpanel/media';
$m3uPath = "$mediaPath/$slug/active.m3u";

// Obtener canciones
if ($activePlaylistId) {
    $stmtSongs = $db->prepare(
        "SELECT ruta_archivo FROM canciones WHERE estacion_id = ? AND playlist_id = ? AND activa = 1 ORDER BY orden ASC"
    );
    $stmtSongs->execute([$estacionId, $activePlaylistId]);

    // Nombre de la playlist
    $stmtPl = $db->prepare("SELECT nombre FROM playlists WHERE id = ?");
    $stmtPl->execute([$activePlaylistId]);
    $plName = $stmtPl->fetchColumn();
} else {
    // Sin playlist activa: todas las canciones
    $stmtSongs = $db->prepare(
        "SELECT ruta_archivo FROM canciones WHERE estacion_id = ? AND activa = 1 ORDER BY orden ASC"
    );
    $stmtSongs->execute([$estacionId]);
    $plName = 'Todas';
}

$songs = $stmtSongs->fetchAll(PDO::FETCH_COLUMN);

if (empty($songs)) {
    echo "Sin canciones para la estacion $slug\n";
    exit(1);
}

// Generar M3U
$m3uContent = "#EXTM3U\n";
$m3uContent .= "# Playlist: $plName\n";
$m3uContent .= "# Generado: " . date('Y-m-d H:i:s') . "\n";
foreach ($songs as $songPath) {
    $m3uContent .= $songPath . "\n";
}

file_put_contents($m3uPath, $m3uContent);

echo "OK: Playlist '$plName' actualizada\n";
echo "Archivo: $m3uPath\n";
echo "Canciones: " . count($songs) . "\n";
echo "Liquidsoap recargara automaticamente.\n";
