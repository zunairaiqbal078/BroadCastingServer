<?php
/**
 * CastPanel DJ Portal — API: Actualizar cuenta DJ
 * Acciones: change_password, social
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

// Load DJ
$stmt = $db->prepare("SELECT * FROM cuentas_dj WHERE id = ? AND activo = 1");
$stmt->execute([$_SESSION['dj_id']]);
$dj = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$dj) {
    echo json_encode(['success' => false, 'message' => 'Cuenta DJ no encontrada']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
$action = $input['action'] ?? '';

switch ($action) {
    case 'change_password':
        if (!($dj['perm_cambiar_pass'] ?? true)) {
            echo json_encode(['success' => false, 'message' => 'Sin permiso para cambiar contraseña']);
            exit;
        }

        $current = $input['current_password'] ?? '';
        $newPass = $input['new_password'] ?? '';

        if (empty($current) || empty($newPass)) {
            echo json_encode(['success' => false, 'message' => 'Todos los campos son requeridos']);
            exit;
        }

        if (strlen($newPass) < 6) {
            echo json_encode(['success' => false, 'message' => 'La contraseña debe tener al menos 6 caracteres']);
            exit;
        }

        // Verify current password
        if (!password_verify($current, $dj['password'])) {
            echo json_encode(['success' => false, 'message' => 'La contraseña actual es incorrecta']);
            exit;
        }

        $hash = password_hash($newPass, PASSWORD_BCRYPT);
        $upd = $db->prepare("UPDATE cuentas_dj SET password = ?, password_plain = ? WHERE id = ?");
        $upd->execute([$hash, $newPass, $dj['id']]);

        echo json_encode(['success' => true, 'message' => 'Contraseña actualizada correctamente']);
        break;

    case 'social':
        $email = trim($input['email'] ?? '');
        $instagram = trim($input['instagram'] ?? '');
        $facebook = trim($input['facebook'] ?? '');

        // Basic email validation
        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            echo json_encode(['success' => false, 'message' => 'Email no válido']);
            exit;
        }

        // Check if columns exist, add them if not (graceful)
        try {
            $upd = $db->prepare("UPDATE cuentas_dj SET email = ?, instagram = ?, facebook = ? WHERE id = ?");
            $upd->execute([$email, $instagram, $facebook, $dj['id']]);
            echo json_encode(['success' => true, 'message' => 'Información social actualizada']);
        } catch (PDOException $e) {
            // Columns might not exist yet (before migration)
            // Try just email
            try {
                $upd = $db->prepare("UPDATE cuentas_dj SET email = ? WHERE id = ?");
                $upd->execute([$email, $dj['id']]);
                echo json_encode(['success' => true, 'message' => 'Email actualizado (ejecuta install.sh para activar Instagram/Facebook)']);
            } catch (PDOException $e2) {
                echo json_encode(['success' => false, 'message' => 'Error al actualizar: ' . $e2->getMessage()]);
            }
        }
        break;

    default:
        echo json_encode(['success' => false, 'message' => 'Acción no válida']);
}
