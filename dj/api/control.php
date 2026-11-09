<?php
/**
 * CastPanel DJ Portal — API: Control de Radio
 * Acciones: start, stop, autodj_start, autodj_stop
 *
 * Uses the same provisioning functions as the admin and client panels
 * (startStation / stopStation / Liquidsoap service), so the DJ buttons send
 * the same daemon commands that already work there.
 */
header('Content-Type: application/json');

if (session_status() === PHP_SESSION_NONE) session_start();
if (!isset($_SESSION['dj_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'No autenticado']);
    exit;
}

require_once '/var/www/castpanel/config/database.php';
require_once __DIR__ . '/../../includes/provisioning.php';
$db = getDB();

// Load DJ + permissions
$stmt = $db->prepare("SELECT d.* FROM cuentas_dj d JOIN estaciones e ON e.id = d.estacion_id WHERE d.id = ? AND d.activo = 1");
$stmt->execute([$_SESSION['dj_id']]);
$dj = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$dj) {
    echo json_encode(['success' => false, 'message' => 'Cuenta DJ no encontrada']);
    exit;
}

// Read JSON body
$input = json_decode(file_get_contents('php://input'), true) ?: [];
$action = $input['action'] ?? '';
$estacionId = (int)($input['estacion_id'] ?? $dj['estacion_id']);

// A DJ may only control their own station
if ($estacionId !== (int)$dj['estacion_id']) {
    echo json_encode(['success' => false, 'message' => 'No autorizado para esta estación']);
    exit;
}

$estacion = loadStationWithPackage($estacionId);
if (!$estacion || empty($estacion['activa'])) {
    echo json_encode(['success' => false, 'message' => 'Estación no encontrada']);
    exit;
}

/** JSON reply; on failure include the real reason from the daemon/provisioning */
function djReply(array $result, string $okMsg, string $failMsg): void {
    $ok = !empty($result['success']);
    $msg = $ok ? $okMsg : $failMsg;
    if (!$ok && !empty($result['error'])) {
        $msg .= ': ' . $result['error'];
    }
    echo json_encode(['success' => $ok, 'message' => $msg]);
}

switch ($action) {
    case 'start':
        if (!$dj['perm_start_stop']) {
            echo json_encode(['success' => false, 'message' => 'Sin permiso para iniciar/detener']);
            exit;
        }
        clearStreamStatusCache($estacionId);
        djReply(startStation($estacion), 'Radio iniciada correctamente', 'No se pudo iniciar la radio');
        break;

    case 'stop':
        if (!$dj['perm_start_stop']) {
            echo json_encode(['success' => false, 'message' => 'Sin permiso para iniciar/detener']);
            exit;
        }
        clearStreamStatusCache($estacionId);
        djReply(stopStation($estacion), 'Radio detenida correctamente', 'No se pudo detener la radio');
        break;

    case 'autodj_start':
        if (!$dj['perm_autodj']) {
            echo json_encode(['success' => false, 'message' => 'Sin permiso para AutoDJ']);
            exit;
        }
        if (!stationHasAutoDJ($estacion)) {
            echo json_encode(['success' => false, 'message' => 'Sin permiso para AutoDJ']);
            exit;
        }
        clearStreamStatusCache($estacionId);
        // startStation() starts SHOUTcast first (if needed) and then Liquidsoap
        djReply(startStation($estacion), 'AutoDJ iniciado', 'No se pudo iniciar el AutoDJ');
        break;

    case 'autodj_stop':
        if (!$dj['perm_autodj']) {
            echo json_encode(['success' => false, 'message' => 'Sin permiso para AutoDJ']);
            exit;
        }
        clearStreamStatusCache($estacionId);
        djReply(stopLiquidsoapService($estacion), 'AutoDJ detenido', 'No se pudo detener el AutoDJ');
        break;

    default:
        echo json_encode(['success' => false, 'message' => 'Acción no válida']);
}
