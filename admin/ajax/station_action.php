<?php
/**
 * CastPanel v2.6 - AJAX: Acciones sobre estaciones
 * Soporta: start, stop, restart, suspend, reactivate, save, save_autodj
 */
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/provisioning.php';
requireRole('admin');

// Verificar CSRF
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'error' => 'Metodo no permitido']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
if (!$input) {
    // Fallback a POST normal
    $input = $_POST;
}

// Verificar CSRF token
$csrfToken = $input['csrf_token'] ?? '';
if (!$csrfToken || !isset($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $csrfToken)) {
    echo json_encode(['success' => false, 'error' => 'Token CSRF invalido. Recarga la pagina.']);
    exit;
}

$action = $input['action'] ?? '';
$stationId = (int)($input['station_id'] ?? 0);

if ($stationId <= 0) {
    echo json_encode(['success' => false, 'error' => 'ID de estacion invalido']);
    exit;
}

$db = getDB();

// Obtener estacion
$stmt = $db->prepare("SELECT * FROM estaciones WHERE id = ?");
$stmt->execute([$stationId]);
$estacion = $stmt->fetch();

if (!$estacion) {
    echo json_encode(['success' => false, 'error' => 'Estacion no encontrada']);
    exit;
}

// Station state is about to change: drop its cached on-air status
clearStreamStatusCache($stationId);

try {
    switch ($action) {
        case 'start':
            $result = startStation($estacion);
            if ($result['success']) {
                $db->prepare("UPDATE estaciones SET servicio_estado='running', autodj_estado='online' WHERE id=?")->execute([$stationId]);
            }
            echo json_encode(['success' => $result['success'], 'message' => $result['success'] ? 'Estacion iniciada' : ($result['error'] ?? 'Error al iniciar')]);
            break;

        case 'stop':
            $result = stopStation($estacion);
            $db->prepare("UPDATE estaciones SET servicio_estado='stopped', autodj_estado='offline' WHERE id=?")->execute([$stationId]);
            echo json_encode(['success' => true, 'message' => 'Estacion detenida']);
            break;

        case 'restart':
            $result = restartStation($estacion);
            if ($result['success']) {
                $db->prepare("UPDATE estaciones SET servicio_estado='running', autodj_estado='online' WHERE id=?")->execute([$stationId]);
            }
            echo json_encode(['success' => $result['success'], 'message' => $result['success'] ? 'Estacion reiniciada' : ($result['error'] ?? 'Error al reiniciar')]);
            break;

        case 'suspend':
            $db->prepare("UPDATE estaciones SET activa = 0 WHERE id = ?")->execute([$stationId]);
            // Detener servicios
            stopStation($estacion);
            $db->prepare("UPDATE estaciones SET servicio_estado='stopped', autodj_estado='offline' WHERE id=?")->execute([$stationId]);
            echo json_encode(['success' => true, 'message' => 'Estacion suspendida']);
            break;

        case 'reactivate':
            $db->prepare("UPDATE estaciones SET activa = 1 WHERE id = ?")->execute([$stationId]);
            echo json_encode(['success' => true, 'message' => 'Estacion reactivada']);
            break;

        case 'save':
            // Guardar datos de la tab "Administrar Radio"
            $fields = [
                'server_type'      => $input['server_type'] ?? $estacion['server_type'],
                'paquete_id'       => (int)($input['paquete_id'] ?? $estacion['paquete_id']),
                'icecast_password' => $input['icecast_password'] ?? $estacion['icecast_password'],
                'max_oyentes_override' => isset($input['max_oyentes']) ? (int)$input['max_oyentes'] : null,
                'bitrate'          => (int)($input['bitrate'] ?? $estacion['bitrate']),
                'codec'            => $input['codec'] ?? $estacion['codec'],
                'samplerate'       => (int)($input['samplerate'] ?? $estacion['samplerate']),
            ];

            $db->prepare("UPDATE estaciones SET server_type=?, paquete_id=?, icecast_password=?, bitrate=?, codec=?, samplerate=? WHERE id=?")
               ->execute([
                   $fields['server_type'],
                   $fields['paquete_id'],
                   $fields['icecast_password'],
                   $fields['bitrate'],
                   $fields['codec'],
                   $fields['samplerate'],
                   $stationId
               ]);

            echo json_encode(['success' => true, 'message' => 'Configuracion guardada']);
            break;

        case 'save_autodj':
            // Guardar configuracion AutoDJ
            $autodj = [
                'modo_reproduccion'      => $input['modo_reproduccion'] ?? $estacion['modo_reproduccion'],
                'crossfade_seconds'      => (int)($input['crossfade_seconds'] ?? $estacion['crossfade_seconds']),
                'mostrar_titulos'        => isset($input['mostrar_titulos']) ? (int)$input['mostrar_titulos'] : (int)$estacion['mostrar_titulos'],
                'grabar_dj'              => isset($input['grabar_dj']) ? (int)$input['grabar_dj'] : (int)$estacion['grabar_dj'],
                'calidad_grabacion'      => $input['calidad_grabacion'] ?? $estacion['calidad_grabacion'],
                'buffer_dj_delay'        => (int)($input['buffer_dj_delay'] ?? $estacion['buffer_dj_delay']),
                'titulo_radio'           => $input['titulo_radio'] ?? $estacion['titulo_radio'],
                'genero'                 => $input['genero'] ?? $estacion['genero'],
                'url_web'                => $input['url_web'] ?? $estacion['url_web'],
                'autodj_playlist_activa' => $input['autodj_playlist_activa'] ?? $estacion['autodj_playlist_activa'],
            ];

            $db->prepare("UPDATE estaciones SET modo_reproduccion=?, crossfade_seconds=?, mostrar_titulos=?, grabar_dj=?, calidad_grabacion=?, buffer_dj_delay=?, titulo_radio=?, genero=?, url_web=?, autodj_playlist_activa=? WHERE id=?")
               ->execute([
                   $autodj['modo_reproduccion'],
                   $autodj['crossfade_seconds'],
                   $autodj['mostrar_titulos'],
                   $autodj['grabar_dj'],
                   $autodj['calidad_grabacion'],
                   $autodj['buffer_dj_delay'],
                   $autodj['titulo_radio'],
                   $autodj['genero'],
                   $autodj['url_web'],
                   $autodj['autodj_playlist_activa'],
                   $stationId
               ]);

            echo json_encode(['success' => true, 'message' => 'Configuracion AutoDJ guardada']);
            break;

        case 'delete':
            deprovisionStation($estacion);
            $db->prepare("DELETE FROM estaciones WHERE id = ?")->execute([$stationId]);
            $mediaDir = MEDIA_PATH . '/' . $estacion['slug'];
            $adsDir = ANUNCIOS_PATH . '/' . $estacion['slug'];
            if (is_dir($mediaDir)) {
                array_map('unlink', glob("$mediaDir/*"));
                @rmdir($mediaDir);
            }
            if (is_dir($adsDir)) {
                array_map('unlink', glob("$adsDir/*"));
                @rmdir($adsDir);
            }
            echo json_encode(['success' => true, 'message' => 'Estacion eliminada y des-aprovisionada']);
            break;

        default:
            echo json_encode(['success' => false, 'error' => 'Accion desconocida: ' . $action]);
    }
} catch (\Exception $e) {
    echo json_encode(['success' => false, 'error' => 'Error: ' . $e->getMessage()]);
}
