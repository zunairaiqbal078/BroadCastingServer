<?php
/**
 * CastPanel v2.0 - API: Estadísticas en tiempo real (AJAX)
 */
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';

header('Content-Type: application/json; charset=utf-8');

$estacionId = (int)($_GET['estacion'] ?? 0);
if (!$estacionId) {
    jsonResponse(['error' => 'estacion requerida'], 400);
}

$db = getDB();
$stmt = $db->prepare("SELECT * FROM estaciones WHERE id = ? AND activa = 1");
$stmt->execute([$estacionId]);
$estacion = $stmt->fetch();

if (!$estacion) {
    jsonResponse(['error' => 'estacion no encontrada'], 404);
}

$iceStatus = getStreamStatus($estacion);

jsonResponse([
    'oyentes' => $iceStatus['oyentes'],
    'online' => $iceStatus['online'],
    'titulo' => $iceStatus['titulo'],
    'pico' => getPeakListeners($estacionId, 24),
    'autodj_estado' => $estacion['autodj_estado'],
    'timestamp' => date('Y-m-d H:i:s'),
]);
