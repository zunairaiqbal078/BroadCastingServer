<?php
/**
 * Add dev/test-mp3/demo-60s.mp3 to demoradio active playlist and refresh M3U.
 * On VPS: sudo -u www-data php /var/www/castpanel/dev/vps_add_demo_track.php
 */
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/liquidsoap_playlist.php';
require_once __DIR__ . '/../includes/provisioning.php';

$slug = 'demo-autodj-radio';
$db = getDB();
$est = $db->prepare('SELECT * FROM estaciones WHERE slug = ? AND activa = 1');
$est->execute([$slug]);
$estacion = $est->fetch(PDO::FETCH_ASSOC);
if (!$estacion) {
    fwrite(STDERR, "Station $slug not found\n");
    exit(1);
}
$estacionId = (int)$estacion['id'];
$playlistId = (int)($estacion['autodj_playlist_activa'] ?? 0);
if ($playlistId <= 0) {
    $playlistId = (int)$db->query(
        "SELECT id FROM playlists WHERE estacion_id = $estacionId ORDER BY id ASC LIMIT 1"
    )->fetchColumn();
}
$src = __DIR__ . '/test-mp3/demo-60s.mp3';
if (!is_file($src)) {
    fwrite(STDERR, "Missing $src\n");
    exit(1);
}
$mediaDir = rtrim(MEDIA_PATH, '/') . '/' . $slug;
if (!is_dir($mediaDir)) {
    mkdir($mediaDir, 0775, true);
}
$destName = 'castpanel-demo-60s.mp3';
$dest = $mediaDir . '/' . $destName;
if (!copy($src, $dest)) {
    fwrite(STDERR, "Copy failed to $dest\n");
    exit(1);
}
@chmod($dest, 0644);
$exists = $db->prepare('SELECT id FROM canciones WHERE estacion_id = ? AND ruta_archivo = ?');
$exists->execute([$estacionId, $dest]);
$songId = (int)$exists->fetchColumn();
$dur = 60;
if ($songId <= 0) {
    $ord = (int)$db->query("SELECT COALESCE(MAX(orden),0)+1 FROM canciones WHERE playlist_id = $playlistId")->fetchColumn();
    $size = (int)@filesize($dest);
    $ins = $db->prepare(
        'INSERT INTO canciones (estacion_id, playlist_id, nombre_archivo, nombre_original, titulo, artista,
         duracion_segundos, tamano_bytes, ruta_archivo, activa, orden)
         VALUES (?,?,?,?,?,?,?,?,?,1,?)'
    );
    $ins->execute([
        $estacionId, $playlistId, $destName, $destName,
        'Demo 60s', 'CastPanel Test', $dur, $size, $dest, $ord,
    ]);
    echo "Inserted song id=" . $db->lastInsertId() . "\n";
} else {
    $db->prepare('UPDATE canciones SET titulo=?, artista=?, duracion_segundos=?, activa=1 WHERE id=?')
       ->execute(['Demo 60s', 'CastPanel Test', $dur, $songId]);
    echo "Updated song id=$songId\n";
}
$db->prepare('UPDATE playlists SET total_canciones = (SELECT COUNT(*) FROM canciones WHERE playlist_id = ?) WHERE id = ?')
   ->execute([$playlistId, $playlistId]);

castpanelWriteActivePlaylistM3u($estacion);
$cfg = generateLiquidsoapConfig($estacion);
if (!($cfg['success'] ?? false)) {
    fwrite(STDERR, 'generateLiquidsoapConfig: ' . ($cfg['error'] ?? '') . "\n");
    exit(1);
}
echo "active.m3u + .liq regenerated for $slug\n";
$r = restartStation($estacion);
echo ($r['success'] ?? false) ? "Station restarted OK\n" : ('Restart failed: ' . ($r['error'] ?? '') . "\n");
