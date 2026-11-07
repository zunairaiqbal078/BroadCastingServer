<?php
/**
 * CastPanel DJ Portal — API: Control de Radio
 * Acciones: start, stop, autodj_start, autodj_stop
 * Usa castpanelCtl() para IPC con el daemon
 */
header('Content-Type: application/json');

if (session_status() === PHP_SESSION_NONE) session_start();
if (!isset($_SESSION['dj_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'No autenticado']);
    exit;
}

require_once '/var/www/castpanel/config/database.php';
$db = getDB();

// Load DJ + verify permissions
$stmt = $db->prepare("
    SELECT d.*, e.nombre AS estacion_nombre, e.slug, e.server_type,
           e.puerto_stream, e.icecast_port, e.ip_stream, e.activa, e.id AS eid
    FROM cuentas_dj d
    JOIN estaciones e ON e.id = d.estacion_id
    WHERE d.id = ? AND d.activo = 1
");
$stmt->execute([$_SESSION['dj_id']]);
$dj = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$dj) {
    echo json_encode(['success' => false, 'message' => 'Cuenta DJ no encontrada']);
    exit;
}

// Read JSON body
$input = json_decode(file_get_contents('php://input'), true);
$action = $input['action'] ?? '';
$estacionId = (int)($input['estacion_id'] ?? $dj['estacion_id']);

// Verify station matches DJ
if ($estacionId !== (int)$dj['estacion_id']) {
    echo json_encode(['success' => false, 'message' => 'No autorizado para esta estación']);
    exit;
}

// castpanelCtl helper — file-based IPC at /var/run/castpanel/
if (!function_exists('castpanelCtl')) {
    require_once '/var/www/castpanel/includes/functions.php';
}

switch ($action) {
    case 'start':
        if (!$dj['perm_start_stop']) {
            echo json_encode(['success' => false, 'message' => 'Sin permiso para iniciar/detener']);
            exit;
        }
        $result = castpanelCtl('start', $estacionId);
        echo json_encode([
            'success' => (bool)$result,
            'message' => $result ? 'Radio iniciada correctamente' : 'No se pudo iniciar la radio'
        ]);
        break;

    case 'stop':
        if (!$dj['perm_start_stop']) {
            echo json_encode(['success' => false, 'message' => 'Sin permiso para iniciar/detener']);
            exit;
        }
        $result = castpanelCtl('stop', $estacionId);
        echo json_encode([
            'success' => (bool)$result,
            'message' => $result ? 'Radio detenida correctamente' : 'No se pudo detener la radio'
        ]);
        break;

    case 'autodj_start':
        if (!$dj['perm_autodj']) {
            echo json_encode(['success' => false, 'message' => 'Sin permiso para AutoDJ']);
            exit;
        }
        $result = castpanelCtl('autodj_start', $estacionId);
        echo json_encode([
            'success' => (bool)$result,
            'message' => $result ? 'AutoDJ iniciado' : 'No se pudo iniciar el AutoDJ'
        ]);
        break;

    case 'autodj_stop':
        if (!$dj['perm_autodj']) {
            echo json_encode(['success' => false, 'message' => 'Sin permiso para AutoDJ']);
            exit;
        }
        $result = castpanelCtl('autodj_stop', $estacionId);
        echo json_encode([
            'success' => (bool)$result,
            'message' => $result ? 'AutoDJ detenido' : 'No se pudo detener el AutoDJ'
        ]);
        break;

    default:
        echo json_encode(['success' => false, 'message' => 'Acción no válida']);
}
