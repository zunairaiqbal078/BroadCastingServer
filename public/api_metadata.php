<?php
/**
 * CastPanel v2.1 - API de Metadata Pública
 *
 * Endpoint JSON para polling de metadata en el reproductor público
 * GET /public/api_metadata.php?slug=mi-radio
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Cache-Control: no-cache, no-store, must-revalidate');

$slug = $_GET['slug'] ?? '';
if (!$slug) {
    echo json_encode(['success' => false, 'error' => 'slug requerido']);
    exit;
}

$db = getDB();

$stmt = $db->prepare("
    SELECT e.*, u.activo AS usuario_activo
    FROM estaciones e
    JOIN usuarios u ON u.id = e.usuario_id
    WHERE e.slug = ? AND e.activa = 1
");
$stmt->execute([$slug]);
$estacion = $stmt->fetch();

if (!$estacion || !$estacion['usuario_activo']) {
    echo json_encode(['success' => false, 'error' => 'Estación no encontrada']);
    exit;
}

// Obtener status del stream
$status = getStreamStatus($estacion);

// Obtener última canción del log
$stmt = $db->prepare("
    SELECT titulo, artista, caratula
    FROM log_emision
    WHERE estacion_id = ? AND estado = 'al_aire'
    ORDER BY emitido_at DESC
    LIMIT 1
");
$stmt->execute([$estacion['id']]);
$lastTrack = $stmt->fetch();

$title = $status['titulo'] ?? ($lastTrack['titulo'] ?? $estacion['titulo_radio'] ?? $estacion['nombre']);
$artist = $status['artista'] ?? ($lastTrack['artista'] ?? '');
$cover = $lastTrack['caratula'] ?? null;

echo json_encode([
    'success'   => true,
    'online'    => (bool)($status['en_linea'] ?? false),
    'title'     => $title,
    'artist'    => $artist,
    'cover'     => $cover,
    'listeners' => (int)($status['oyentes'] ?? 0),
    'bitrate'   => (int)($status['bitrate'] ?? $estacion['bitrate']),
    'genre'     => $estacion['genero'] ?? '',
    'station'   => $estacion['nombre'],
], JSON_UNESCAPED_UNICODE);
