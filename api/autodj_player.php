<?php
/**
 * AutoDJ Quick Control API (logged-in client): status, skip next/previous.
 */
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/autodj_player.php';

header('Content-Type: application/json; charset=utf-8');

if (!isLoggedIn()) {
    jsonResponse(['success' => false, 'error' => 'Login required'], 401);
}

$estacionId = (int)($_REQUEST['estacion'] ?? ($_SESSION['estacion_id'] ?? 0));
if ($estacionId <= 0) {
    jsonResponse(['success' => false, 'error' => 'Station required'], 400);
}

$db = getDB();
$stmt = $db->prepare(
    'SELECT e.*, p.autodj_enabled FROM estaciones e
     LEFT JOIN paquetes p ON p.id = e.paquete_id
     WHERE e.id = ? AND e.activa = 1'
);
$stmt->execute([$estacionId]);
$estacion = $stmt->fetch();

if (!$estacion) {
    jsonResponse(['success' => false, 'error' => 'Station not found'], 404);
}

if ($_SESSION['user_rol'] === 'cliente' && (int)$estacion['usuario_id'] !== (int)$_SESSION['user_id']) {
    jsonResponse(['success' => false, 'error' => 'Forbidden'], 403);
}

$action = $_REQUEST['action'] ?? 'status';

if ($action === 'history') {
    $stmt = $db->prepare(
        "SELECT tipo, titulo, artista, emitido_at, estado FROM log_emision
         WHERE estacion_id = ? ORDER BY emitido_at DESC LIMIT 20"
    );
    $stmt->execute([$estacionId]);
    jsonResponse(['success' => true, 'history' => $stmt->fetchAll()]);
}

if (in_array($action, ['next', 'previous', 'skip_next', 'skip_previous'], true)) {
    require_once __DIR__ . '/../includes/provisioning.php';
    if (!stationHasAutoDJ($estacion)) {
        jsonResponse(['success' => false, 'error' => 'AutoDJ not enabled'], 400);
    }
    $ok = match ($action) {
        'next', 'skip_next' => castpanelLiquidsoapSkipNext($estacion),
        'previous', 'skip_previous' => castpanelLiquidsoapSkipPrevious($estacion),
        default => false,
    };
    clearStreamStatusCache($estacionId);
    if ($ok) {
        $slug = $estacion['slug'] ?? '';
        if ($slug !== '') {
            castpanelLiquidsoapWaitPreparedUri($slug, null, 0.5);
        }
    }
    jsonResponse([
        'success' => $ok,
        'player' => castpanelQuickPlayerPayload($estacion),
    ]);
}

jsonResponse([
    'success' => true,
    'player' => castpanelQuickPlayerPayload($estacion),
]);
