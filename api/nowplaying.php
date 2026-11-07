<?php
/**
 * CastPanel v2.0 - API: Now Playing (JSON público)
 */
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');

$estacionId = (int)($_GET['estacion'] ?? 0);
$slug = $_GET['slug'] ?? '';

$db = getDB();

if ($slug) {
    $stmt = $db->prepare("SELECT * FROM estaciones WHERE slug = ? AND activa = 1");
    $stmt->execute([$slug]);
} elseif ($estacionId) {
    $stmt = $db->prepare("SELECT * FROM estaciones WHERE id = ? AND activa = 1");
    $stmt->execute([$estacionId]);
} else {
    jsonResponse(['error' => 'estacion o slug requerido'], 400);
}

$estacion = $stmt->fetch();
if (!$estacion) {
    jsonResponse(['error' => 'estacion no encontrada'], 404);
}

$iceStatus = getStreamStatus($estacion);

// Última canción del log
$stmt = $db->prepare("SELECT * FROM log_emision WHERE estacion_id = ? AND tipo = 'cancion' ORDER BY emitido_at DESC LIMIT 1");
$stmt->execute([$estacion['id']]);
$lastSong = $stmt->fetch();

// Historial reciente
$stmt = $db->prepare("SELECT tipo, titulo, artista, emitido_at FROM log_emision WHERE estacion_id = ? ORDER BY emitido_at DESC LIMIT 10");
$stmt->execute([$estacion['id']]);
$history = $stmt->fetchAll();

// URL del stream segun tipo de servidor
if (in_array($estacion['server_type'] ?? '', ['shoutcast_v1', 'shoutcast_v2', 'shoutcast_v25'])) {
    $streamUrl = 'https://' . SSL_DOMAIN . '/shoutcast/' . $estacion['slug'];
    $fallbackUrl = 'https://' . SSL_DOMAIN . '/live/' . $estacion['slug'];
} else {
    $streamUrl = 'https://' . SSL_DOMAIN . '/live/' . $estacion['slug'];
    $fallbackUrl = '';
}

jsonResponse([
    'station' => [
        'name' => $estacion['nombre'],
        'slug' => $estacion['slug'],
        'genre' => $estacion['genero'],
        'url' => $estacion['url_web'],
        'stream_url' => $streamUrl,
        'stream_fallback' => $fallbackUrl ?: null,
        'codec' => $estacion['codec'],
        'bitrate' => $estacion['bitrate'],
    ],
    'live' => [
        'online' => $iceStatus['online'],
        'listeners' => $iceStatus['oyentes'],
        'title' => $iceStatus['titulo'],
    ],
    'now_playing' => $lastSong ? [
        'title' => $lastSong['titulo'],
        'artist' => $lastSong['artista'],
        'cover' => $lastSong['caratula'],
        'started_at' => $lastSong['emitido_at'],
    ] : null,
    'history' => array_map(function($h) {
        return [
            'type' => $h['tipo'],
            'title' => $h['titulo'],
            'artist' => $h['artista'],
            'played_at' => $h['emitido_at'],
        ];
    }, $history),
    'timestamp' => date('c'),
]);
