<?php
/**
 * CastPanel DJ Portal — API: Procesador de Audio
 * Permite a los DJs seleccionar presets de procesamiento para su estacion.
 * Funciona tanto para estaciones con AutoDJ como sin AutoDJ.
 */
header('Content-Type: application/json');

if (session_status() === PHP_SESSION_NONE) session_start();
if (!isset($_SESSION['dj_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'No autenticado']);
    exit;
}

require_once '/var/www/castpanel/config/database.php';
require_once '/var/www/castpanel/includes/audio_processor.php';

$db = getDB();

// Cargar DJ con datos de estacion
$stmt = $db->prepare("
    SELECT d.*, e.id AS estacion_id, e.nombre AS estacion_nombre, e.slug,
           e.server_type, e.puerto_stream, COALESCE(p.autodj_enabled, 0) AS autodj_enabled
    FROM cuentas_dj d
    JOIN estaciones e ON e.id = d.estacion_id
    LEFT JOIN paquetes p ON p.id = e.paquete_id
    WHERE d.id = ? AND d.activo = 1
");
$stmt->execute([$_SESSION['dj_id']]);
$dj = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$dj) {
    echo json_encode(['success' => false, 'message' => 'Cuenta DJ no encontrada']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
$action = $input['action'] ?? '';

switch ($action) {
    case 'get_config':
        // Obtener configuracion actual del procesador
        try {
            $proc = getProcessorConfig($dj['estacion_id']);
            $presets = getProcessorPresets();
            echo json_encode([
                'success' => true,
                'config' => $proc,
                'presets' => $presets,
                'current_preset' => $proc['preset'] ?? 'standard',
                'autodj_enabled' => !empty($dj['autodj_enabled']),
            ]);
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'message' => 'Error al obtener config: ' . $e->getMessage()]);
        }
        break;

    case 'apply_preset':
        $presetKey = $input['preset'] ?? '';
        $presets = getProcessorPresets();

        if (!isset($presets[$presetKey])) {
            echo json_encode(['success' => false, 'message' => 'Preset no valido']);
            exit;
        }

        try {
            if (applyProcessorPreset($dj['estacion_id'], $presetKey)) {
                $presetName = $presets[$presetKey]['nombre'] ?? $presetKey;

                // Si tiene AutoDJ, regenerar Liquidsoap config y reiniciar
                if (!empty($dj['autodj_enabled'])) {
                    require_once '/var/www/castpanel/includes/provisioning.php';
                    $stData = $db->prepare("SELECT * FROM estaciones WHERE id = ?");
                    $stData->execute([$dj['estacion_id']]);
                    $stRow = $stData->fetch();
                    if ($stRow) {
                        generateLiquidsoapConfig($stRow);
                        restartLiquidsoapService($stRow);
                    }
                    echo json_encode([
                        'success' => true,
                        'message' => "Preset \"$presetName\" aplicado. La estacion se reiniciara para aplicar los cambios.",
                        'applied_live' => true,
                    ]);
                } else {
                    echo json_encode([
                        'success' => true,
                        'message' => "Preset \"$presetName\" guardado. Se aplicara cuando se active AutoDJ o Liquidsoap.",
                        'applied_live' => false,
                    ]);
                }
            } else {
                echo json_encode(['success' => false, 'message' => 'Error al aplicar el preset.']);
            }
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
        }
        break;

    default:
        echo json_encode(['success' => false, 'message' => 'Accion no valida']);
}
