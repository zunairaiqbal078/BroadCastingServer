<?php
/**
 * JSON API — Quick Control (Start / Stop / Restart / active playlist) without leaving the dashboard.
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
requireLogin();

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !verifyCsrf()) {
    jsonResponse(['success' => false, 'error' => 'Invalid request'], 403);
}

$estacion = getCurrentStation();
if (!$estacion) {
    jsonResponse(['success' => false, 'error' => 'No station assigned'], 400);
}

require_once __DIR__ . '/../includes/provisioning.php';

$action = $_POST['action'] ?? '';

if ($action === 'autodj_control') {
    if (empty($estacion['autodj_enabled'])) {
        jsonResponse(['success' => false, 'error' => 'AutoDJ is not enabled for this station']);
    }
    $cmd = $_POST['cmd'] ?? '';
    $resultado = ['success' => false, 'error' => 'Unknown command'];
    if ($cmd === 'iniciar') {
        $resultado = ensureAutoDJPlaying($estacion);
    } elseif ($cmd === 'detener') {
        $resultado = stopLiquidsoapService($estacion);
    } elseif ($cmd === 'reiniciar') {
        $resultado = restartStation($estacion);
    }
    clearStreamStatusCache((int)$estacion['id']);
    $fresh = loadStationWithPackage((int)$estacion['id']);
    $autodjOnline = ($fresh['autodj_estado'] ?? 'offline') === 'online';
    jsonResponse([
        'success' => (bool)($resultado['success'] ?? false),
        'error' => $resultado['error'] ?? null,
        'message' => $resultado['message'] ?? null,
        'autodj_online' => $autodjOnline,
        'cmd' => $cmd,
    ]);
}

if ($action === 'station_control') {
    $cmd = $_POST['cmd'] ?? '';
    $resultado = ['success' => false, 'error' => 'Unknown command'];
    if ($cmd === 'iniciar') {
        $resultado = ensureAutoDJPlaying($estacion);
    } elseif ($cmd === 'detener') {
        $resultado = stopStation($estacion);
    } elseif ($cmd === 'reiniciar') {
        $resultado = restartStation($estacion);
    }
    clearStreamStatusCache((int)$estacion['id']);
    $ice = getStreamStatus(loadStationWithPackage((int)$estacion['id']) ?: $estacion);
    jsonResponse([
        'success' => (bool)($resultado['success'] ?? false),
        'error' => $resultado['error'] ?? null,
        'stream_online' => !empty($ice['online']),
        'cmd' => $cmd,
    ]);
}

if ($action === 'set_active_playlist') {
    if (empty($estacion['autodj_enabled'])) {
        jsonResponse(['success' => false, 'error' => 'AutoDJ is not enabled']);
    }
    $plId = isset($_POST['playlist_id']) && $_POST['playlist_id'] !== ''
        ? (int)$_POST['playlist_id']
        : null;
    $result = castpanelSetAutoDJActivePlaylist((int)$estacion['id'], $plId);
    jsonResponse($result, ($result['success'] ?? false) ? 200 : 422);
}

jsonResponse(['success' => false, 'error' => 'Unknown action'], 400);