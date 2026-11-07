<?php
/**
 * CastPanel v2.0 - Webhook: Recibe notificaciones de Liquidsoap
 *
 * Liquidsoap envía aquí los cambios de pista para el log de emisión
 */
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit;
}

$stationSlug = $_POST['station'] ?? '';
$title = trim($_POST['title'] ?? '');
$artist = trim($_POST['artist'] ?? '');
$type = $_POST['type'] ?? 'cancion'; // cancion o anuncio

if (!$stationSlug) {
    jsonResponse(['error' => 'station required'], 400);
}

$db = getDB();
$stmt = $db->prepare("SELECT id FROM estaciones WHERE slug = ?");
$stmt->execute([$stationSlug]);
$estacion = $stmt->fetch();

if (!$estacion) {
    jsonResponse(['error' => 'station not found'], 404);
}

// Evitar duplicados (misma canción en los últimos 5 segundos)
$stmt = $db->prepare("SELECT id FROM log_emision WHERE estacion_id = ? AND titulo = ? AND artista = ? AND emitido_at > DATE_SUB(NOW(), INTERVAL 5 SECOND)");
$stmt->execute([$estacion['id'], $title, $artist]);
if ($stmt->fetch()) {
    jsonResponse(['ok' => true, 'msg' => 'duplicate skipped']);
}

// Buscar carátula en la biblioteca
$caratula = null;
if ($type === 'cancion' && $title) {
    $stmt = $db->prepare("SELECT caratula FROM canciones WHERE estacion_id = ? AND (titulo = ? OR nombre_original LIKE ?) LIMIT 1");
    $stmt->execute([$estacion['id'], $title, "%$title%"]);
    $row = $stmt->fetch();
    if ($row && $row['caratula']) {
        $caratula = $row['caratula'];
    }
}

// Insertar en log
$stmt = $db->prepare("INSERT INTO log_emision (estacion_id, tipo, titulo, artista, caratula) VALUES (?,?,?,?,?)");
$stmt->execute([$estacion['id'], $type, $title ?: 'Desconocido', $artist, $caratula]);

// Actualizar estado de la estación
$db->prepare("UPDATE estaciones SET autodj_estado = 'online' WHERE id = ?")->execute([$estacion['id']]);

jsonResponse(['ok' => true, 'log_id' => $db->lastInsertId()]);
