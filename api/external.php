<?php
/**
 * CastPanel v2.1 - API Externa para WHMCS / THPanel / Facturación
 *
 * Endpoints (POST):
 *   action=create_account   — Crear usuario + estación + aprovisionamiento
 *   action=suspend          — Suspender cuenta (desactiva usuario + detiene servicio)
 *   action=unsuspend        — Reactivar cuenta
 *   action=terminate        — Eliminar cuenta completamente
 *   action=change_package   — Cambiar paquete de la estación
 *   action=info             — Obtener información de la cuenta
 *   action=list_packages    — Listar paquetes disponibles
 *
 * Autenticación: Header "X-Api-Key" + "X-Api-Secret"
 * o parámetros POST "api_key" + "api_secret"
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/provisioning.php';

header('Content-Type: application/json; charset=utf-8');

// Verificar que la API está habilitada
$db = getDB();
$stmt = $db->prepare("SELECT valor FROM configuracion WHERE clave = 'api_enabled'");
$stmt->execute();
if ($stmt->fetchColumn() !== '1') {
    http_response_code(503);
    echo json_encode(['success' => false, 'error' => 'API deshabilitada']);
    exit;
}

// ---- AUTENTICACIÓN ----
$apiKey = $_SERVER['HTTP_X_API_KEY'] ?? $_POST['api_key'] ?? $_GET['api_key'] ?? '';
$apiSecret = $_SERVER['HTTP_X_API_SECRET'] ?? $_POST['api_secret'] ?? $_GET['api_secret'] ?? '';

if (!$apiKey || !$apiSecret) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Credenciales API requeridas']);
    exit;
}

$stmt = $db->prepare("SELECT * FROM api_keys WHERE api_key = ? AND activa = 1");
$stmt->execute([$apiKey]);
$keyData = $stmt->fetch();

if (!$keyData || !password_verify($apiSecret, $keyData['api_secret'])) {
    // Intentar comparación directa por si no está hasheada
    if (!$keyData || $keyData['api_secret'] !== $apiSecret) {
        logApiCall(null, $_POST['action'] ?? 'unknown', '', 'error', 'Credenciales inválidas');
        http_response_code(401);
        echo json_encode(['success' => false, 'error' => 'Credenciales API inválidas']);
        exit;
    }
}

// Verificar IP permitida
if ($keyData['ip_permitida']) {
    $ipsPermitidas = array_map('trim', explode(',', $keyData['ip_permitida']));
    $clientIp = $_SERVER['REMOTE_ADDR'] ?? '';
    if (!in_array($clientIp, $ipsPermitidas)) {
        logApiCall($keyData['id'], $_POST['action'] ?? 'unknown', '', 'error', "IP no permitida: $clientIp");
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'IP no autorizada']);
        exit;
    }
}

// Actualizar último uso
$db->prepare("UPDATE api_keys SET ultimo_uso = NOW(), total_requests = total_requests + 1 WHERE id = ?")
   ->execute([$keyData['id']]);

// ---- PROCESAR ACCIÓN ----
$action = $_POST['action'] ?? $_GET['action'] ?? '';
$permisos = explode(',', $keyData['permisos']);

switch ($action) {

    // ========================================
    // CREAR CUENTA
    // ========================================
    case 'create_account':
        if (!in_array('crear', $permisos)) {
            respondError($keyData['id'], $action, 'Sin permiso para crear', 403);
        }

        $result = createAccountWithStation([
            'username'     => $_POST['username'] ?? '',
            'password'     => $_POST['password'] ?? '',
            'email'        => $_POST['email'] ?? '',
            'nombre'       => $_POST['nombre'] ?? '',
            'paquete_id'   => $_POST['paquete_id'] ?? $_POST['package_id'] ?? 0,
            'station_name' => $_POST['station_name'] ?? $_POST['domain'] ?? $_POST['username'] ?? '',
            'reseller_id'  => $_POST['reseller_id'] ?? 0,
            'server_type'  => $_POST['server_type'] ?? 'icecast',
            'codec'        => $_POST['codec'] ?? 'mp3',
            'bitrate'      => $_POST['bitrate'] ?? 128,
            'samplerate'   => $_POST['samplerate'] ?? 44100,
        ]);

        if ($result['success']) {
            // Auto-iniciar servicio si se quiere
            if (!empty($_POST['auto_start'])) {
                $stmtEst = $db->prepare("SELECT * FROM estaciones WHERE id = ?");
                $stmtEst->execute([$result['station_id']]);
                $est = $stmtEst->fetch();
                if ($est) {
                    startLiquidsoapService($est);
                }
            }

            logApiCall($keyData['id'], $action, json_encode(['username' => $result['username']]), 'success', "Cuenta creada: {$result['username']}");
            respond([
                'success'         => true,
                'user_id'         => $result['user_id'],
                'station_id'      => $result['station_id'],
                'username'        => $result['username'],
                'slug'            => $result['slug'],
                'mount'           => $result['mount'],
                'source_password' => $result['source_password'],
                'port'            => $result['puerto'],
            ]);
        } else {
            respondError($keyData['id'], $action, $result['error']);
        }
        break;

    // ========================================
    // SUSPENDER CUENTA
    // ========================================
    case 'suspend':
        if (!in_array('suspender', $permisos)) {
            respondError($keyData['id'], $action, 'Sin permiso para suspender', 403);
        }

        $userId = getUserId();
        if (!$userId) respondError($keyData['id'], $action, 'Usuario no encontrado');

        // Desactivar usuario
        $db->prepare("UPDATE usuarios SET activo = 0 WHERE id = ?")->execute([$userId]);

        // Detener todas las estaciones del usuario
        $estaciones = $db->prepare("SELECT * FROM estaciones WHERE usuario_id = ?");
        $estaciones->execute([$userId]);
        while ($est = $estaciones->fetch()) {
            stopLiquidsoapService($est);
            $db->prepare("UPDATE estaciones SET activa = 0 WHERE id = ?")->execute([$est['id']]);
        }

        logApiCall($keyData['id'], $action, json_encode(['user_id' => $userId]), 'success', "Cuenta suspendida");
        respond(['success' => true, 'message' => 'Cuenta suspendida']);
        break;

    // ========================================
    // REACTIVAR CUENTA
    // ========================================
    case 'unsuspend':
        if (!in_array('suspender', $permisos)) {
            respondError($keyData['id'], $action, 'Sin permiso para reactivar', 403);
        }

        $userId = getUserId();
        if (!$userId) respondError($keyData['id'], $action, 'Usuario no encontrado');

        // Activar usuario
        $db->prepare("UPDATE usuarios SET activo = 1 WHERE id = ?")->execute([$userId]);

        // Reactivar estaciones
        $db->prepare("UPDATE estaciones SET activa = 1 WHERE usuario_id = ?")->execute([$userId]);

        logApiCall($keyData['id'], $action, json_encode(['user_id' => $userId]), 'success', "Cuenta reactivada");
        respond(['success' => true, 'message' => 'Cuenta reactivada']);
        break;

    // ========================================
    // ELIMINAR CUENTA (TERMINATE)
    // ========================================
    case 'terminate':
        if (!in_array('eliminar', $permisos)) {
            respondError($keyData['id'], $action, 'Sin permiso para eliminar', 403);
        }

        $userId = getUserId();
        if (!$userId) respondError($keyData['id'], $action, 'Usuario no encontrado');

        // Des-aprovisionar todas las estaciones
        $estaciones = $db->prepare("SELECT * FROM estaciones WHERE usuario_id = ?");
        $estaciones->execute([$userId]);
        while ($est = $estaciones->fetch()) {
            deprovisionStation($est);
            // Eliminar archivos de media
            $mediaDir = MEDIA_PATH . '/' . $est['slug'];
            $adsDir = ANUNCIOS_PATH . '/' . $est['slug'];
            if (is_dir($mediaDir)) {
                array_map('unlink', glob("$mediaDir/*"));
                @rmdir($mediaDir);
            }
            if (is_dir($adsDir)) {
                array_map('unlink', glob("$adsDir/*"));
                @rmdir($adsDir);
            }
        }

        // Eliminar usuario (CASCADE borra estaciones, playlists, etc.)
        $db->prepare("DELETE FROM usuarios WHERE id = ?")->execute([$userId]);

        logApiCall($keyData['id'], $action, json_encode(['user_id' => $userId]), 'success', "Cuenta eliminada");
        respond(['success' => true, 'message' => 'Cuenta eliminada completamente']);
        break;

    // ========================================
    // CAMBIAR PAQUETE
    // ========================================
    case 'change_package':
        if (!in_array('modificar', $permisos)) {
            respondError($keyData['id'], $action, 'Sin permiso para modificar', 403);
        }

        $userId = getUserId();
        if (!$userId) respondError($keyData['id'], $action, 'Usuario no encontrado');

        $newPackageId = (int)($_POST['paquete_id'] ?? $_POST['package_id'] ?? 0);
        if (!$newPackageId) {
            respondError($keyData['id'], $action, 'paquete_id es obligatorio');
        }

        // Verificar paquete
        $stmt = $db->prepare("SELECT * FROM paquetes WHERE id = ? AND activo = 1");
        $stmt->execute([$newPackageId]);
        $paquete = $stmt->fetch();
        if (!$paquete) {
            respondError($keyData['id'], $action, 'Paquete no encontrado');
        }

        // Actualizar todas las estaciones del usuario
        $db->prepare("UPDATE estaciones SET paquete_id = ?, bitrate = LEAST(bitrate, ?) WHERE usuario_id = ?")
           ->execute([$newPackageId, $paquete['max_bitrate'], $userId]);

        // Regenerar configs de Icecast (para actualizar max-listeners)
        $estaciones = $db->prepare("SELECT * FROM estaciones WHERE usuario_id = ?");
        $estaciones->execute([$userId]);
        while ($est = $estaciones->fetch()) {
            if ($est['server_type'] === 'icecast') {
                removeIcecastMount($est);
                $est['paquete_id'] = $newPackageId;
                addIcecastMount($est);
            }
            generateLiquidsoapConfig($est);
        }

        logApiCall($keyData['id'], $action, json_encode(['user_id' => $userId, 'package' => $newPackageId]), 'success', "Paquete cambiado a: {$paquete['nombre']}");
        respond(['success' => true, 'message' => "Paquete actualizado a: {$paquete['nombre']}"]);
        break;

    // ========================================
    // INFORMACIÓN DE CUENTA
    // ========================================
    case 'info':
        if (!in_array('leer', $permisos)) {
            respondError($keyData['id'], $action, 'Sin permiso para leer', 403);
        }

        $userId = getUserId();
        if (!$userId) respondError($keyData['id'], $action, 'Usuario no encontrado');

        $stmt = $db->prepare("SELECT id, username, email, nombre, rol, activo, ultimo_login, created_at FROM usuarios WHERE id = ?");
        $stmt->execute([$userId]);
        $user = $stmt->fetch();

        // Estaciones
        $stmt = $db->prepare("
            SELECT e.id, e.nombre, e.slug, e.icecast_mount, e.puerto_stream, e.server_type,
                   e.codec, e.bitrate, e.activa, e.autodj_estado, e.servicio_estado,
                   p.nombre AS paquete_nombre, p.max_oyentes, p.max_bitrate, p.max_storage_mb
            FROM estaciones e
            LEFT JOIN paquetes p ON p.id = e.paquete_id
            WHERE e.usuario_id = ?
        ");
        $stmt->execute([$userId]);
        $stations = $stmt->fetchAll();

        // Agregar listeners actuales
        $allListeners = getIcecastListeners();
        foreach ($stations as &$s) {
            if ($s['server_type'] === 'icecast') {
                $s['oyentes_actuales'] = $allListeners[$s['icecast_mount']]['oyentes'] ?? 0;
            } else {
                $status = getStreamStatus($s);
                $s['oyentes_actuales'] = $status['oyentes'];
            }
        }

        logApiCall($keyData['id'], $action, json_encode(['user_id' => $userId]), 'success', 'Info consultada');
        respond([
            'success' => true,
            'user' => $user,
            'stations' => $stations,
        ]);
        break;

    // ========================================
    // LISTAR PAQUETES
    // ========================================
    case 'list_packages':
        if (!in_array('leer', $permisos)) {
            respondError($keyData['id'], $action, 'Sin permiso para leer', 403);
        }

        $paquetes = $db->query("SELECT id, nombre, max_storage_mb, max_bitrate, max_oyentes, autodj_enabled, max_dj, anuncios_enabled, ssl_enabled, precio, descripcion FROM paquetes WHERE activo = 1 ORDER BY precio")->fetchAll();

        logApiCall($keyData['id'], $action, '', 'success', 'Paquetes listados');
        respond(['success' => true, 'packages' => $paquetes]);
        break;

    default:
        respondError($keyData['id'], $action ?: 'none', 'Acción no reconocida. Acciones válidas: create_account, suspend, unsuspend, terminate, change_package, info, list_packages');
}

// ---- FUNCIONES AUXILIARES ----

function getUserId(): ?int {
    $db = getDB();
    $username = $_POST['username'] ?? $_GET['username'] ?? '';
    $userId = (int)($_POST['user_id'] ?? $_GET['user_id'] ?? 0);

    if ($userId) {
        $stmt = $db->prepare("SELECT id FROM usuarios WHERE id = ?");
        $stmt->execute([$userId]);
    } elseif ($username) {
        $stmt = $db->prepare("SELECT id FROM usuarios WHERE username = ?");
        $stmt->execute([$username]);
    } else {
        return null;
    }

    $row = $stmt->fetch();
    return $row ? (int)$row['id'] : null;
}

function respond(array $data): void {
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;
}

function respondError(?int $keyId, string $action, string $error, int $code = 400): void {
    logApiCall($keyId, $action, '', 'error', $error);
    http_response_code($code);
    echo json_encode(['success' => false, 'error' => $error], JSON_UNESCAPED_UNICODE);
    exit;
}

function logApiCall(?int $keyId, string $action, string $params, string $result, string $message): void {
    try {
        $db = getDB();
        $db->prepare("INSERT INTO log_api (api_key_id, accion, parametros, resultado, mensaje, ip) VALUES (?,?,?,?,?,?)")
           ->execute([$keyId, $action, $params, $result, $message, $_SERVER['REMOTE_ADDR'] ?? '']);
    } catch (Exception $e) {
        // Silenciar errores de logging
    }
}
