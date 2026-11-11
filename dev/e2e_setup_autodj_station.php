<?php
/**
 * One-shot E2E: create SHOUTcast+AutoDJ station, import test MP3s, start playlist.
 * Run on VPS: sudo -u www-data php /path/e2e_setup_autodj_station.php
 */
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/provisioning.php';
require_once __DIR__ . '/../includes/functions.php';

$testUser = 'e2eautodj2026';
$testPass = 'E2eAutoDJ2026!';
$mp3Dir = '/tmp/e2e-mp3';

if (!is_dir($mp3Dir)) {
    fwrite(STDERR, "Missing $mp3Dir — upload sample-a.mp3 and sample-b.mp3 first.\n");
    exit(1);
}

$db = getDB();

// Reuse existing station if present
$check = $db->prepare('SELECT u.id AS uid, e.id AS eid, e.slug FROM usuarios u JOIN estaciones e ON e.usuario_id = u.id WHERE u.username = ?');
$check->execute([$testUser]);
$existing = $check->fetch(PDO::FETCH_ASSOC);

if ($existing) {
    $stationId = (int)$existing['eid'];
    $slug = $existing['slug'];
    echo "Station already exists: $slug (id=$stationId)\n";
} else {
    $result = createAccountWithStation([
        'username' => $testUser,
        'password' => $testPass,
        'email' => 'e2e@test.local',
        'nombre' => 'E2E AutoDJ',
        'paquete_id' => 1,
        'station_name' => 'E2E AutoDJ Test',
        'server_type' => 'shoutcast_v2',
        'codec' => 'mp3',
        'bitrate' => 128,
        'samplerate' => 44100,
        'source_password' => 'E2eSourcePass2026!',
    ]);
    if (!$result['success']) {
        fwrite(STDERR, 'createAccountWithStation: ' . ($result['error'] ?? 'failed') . "\n");
        exit(1);
    }
    $slug = $result['slug'] ?? '';
    $stationId = (int)($result['station_id'] ?? 0);
  if (!$stationId) {
        $row = $db->prepare('SELECT id FROM estaciones WHERE slug = ?');
        $row->execute([$slug]);
        $stationId = (int)$row->fetchColumn();
    }
    echo "Created station $slug (id=$stationId)\n";
}

$est = $db->query("SELECT * FROM estaciones WHERE id = $stationId")->fetch(PDO::FETCH_ASSOC);

$pl = $db->prepare('SELECT id FROM playlists WHERE estacion_id = ? ORDER BY id ASC LIMIT 1');
$pl->execute([$stationId]);
$playlistId = (int)$pl->fetchColumn();
if (!$playlistId) {
    fwrite(STDERR, "No playlist for station\n");
    exit(1);
}

$db->prepare('UPDATE estaciones SET autodj_playlist_activa = ? WHERE id = ?')
   ->execute([$playlistId, $stationId]);
$db->prepare('UPDATE playlists SET activa = 1 WHERE id = ?')->execute([$playlistId]);

$mediaDir = rtrim(MEDIA_PATH, '/') . '/' . $slug;
if (!is_dir($mediaDir)) {
    mkdir($mediaDir, 0775, true);
}
@chown($mediaDir, 'www-data');
@chgrp($mediaDir, 'www-data');

$files = [
    ['path' => $mp3Dir . '/sample-b.mp3', 'title' => 'E2E Sample Beep', 'artist' => 'CastPanel Test'],
    ['path' => $mp3Dir . '/sample-a.mp3', 'title' => 'E2E Enthusiast Demo', 'artist' => 'CastPanel Test'],
];

foreach ($files as $i => $meta) {
    if (!is_file($meta['path'])) {
        continue;
    }
    $base = basename($meta['path']);
    $unique = time() . '_' . $i . '_' . $base;
    $dest = $mediaDir . '/' . $unique;
    if (!copy($meta['path'], $dest)) {
        fwrite(STDERR, "Copy failed: {$meta['path']}\n");
        continue;
    }
    @chown($dest, 'www-data');
    $size = filesize($dest);
    $orden = $i + 1;
    $ins = $db->prepare('INSERT INTO canciones (estacion_id, playlist_id, nombre_archivo, nombre_original, titulo, artista, duracion_segundos, tamano_bytes, ruta_archivo, activa, orden) VALUES (?,?,?,?,?,?,?,?,?,1,?)');
    $ins->execute([
        $stationId, $playlistId, $unique, $base,
        $meta['title'], $meta['artist'], 0, $size, $dest, $orden,
    ]);
    echo "Added track: {$meta['title']}\n";
}

$db->prepare('UPDATE playlists SET total_canciones = (SELECT COUNT(*) FROM canciones WHERE playlist_id = ?) WHERE id = ?')
   ->execute([$playlistId, $playlistId]);

$est = loadStationWithPackage($stationId) ?: $est;
$restart = restartStation($est);
echo 'Restart: ' . json_encode($restart, JSON_UNESCAPED_UNICODE) . "\n";
echo "Panel login: $testUser / $testPass\n";
echo "Public: http://148.113.240.183:8080/public/radio.php?slug=$slug\n";
echo "Stream: http://148.113.240.183:8080/shoutcast/$slug\n";
