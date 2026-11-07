<?php
/**
 * CastPanel DJ Portal — API: Upload / Delete foto de perfil
 * POST multipart: campo "foto" (jpg/png, max 3MB)
 * POST JSON: {"action":"delete"} para eliminar
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

if (!($dj['perm_subir_foto'] ?? true)) {
    echo json_encode(['success' => false, 'message' => 'Sin permiso para subir foto']);
    exit;
}

$uploadDir = '/var/www/castpanel/dj/uploads/';
if (!is_dir($uploadDir)) {
    mkdir($uploadDir, 0755, true);
}

// Check if this is a JSON delete request
$contentType = $_SERVER['CONTENT_TYPE'] ?? '';
if (strpos($contentType, 'application/json') !== false) {
    $input = json_decode(file_get_contents('php://input'), true);
    if (($input['action'] ?? '') === 'delete') {
        // Delete current photo
        if (!empty($dj['foto'])) {
            $oldFile = $uploadDir . $dj['foto'];
            if (file_exists($oldFile)) {
                unlink($oldFile);
            }
            $upd = $db->prepare("UPDATE cuentas_dj SET foto = NULL WHERE id = ?");
            $upd->execute([$dj['id']]);
        }
        echo json_encode(['success' => true, 'message' => 'Foto eliminada']);
        exit;
    }
    echo json_encode(['success' => false, 'message' => 'Acción no válida']);
    exit;
}

// Handle file upload
if (!isset($_FILES['foto'])) {
    echo json_encode(['success' => false, 'message' => 'No se recibió archivo']);
    exit;
}

$file = $_FILES['foto'];

// Validate errors
if ($file['error'] !== UPLOAD_ERR_OK) {
    $errors = [
        UPLOAD_ERR_INI_SIZE   => 'Archivo demasiado grande (servidor)',
        UPLOAD_ERR_FORM_SIZE  => 'Archivo demasiado grande',
        UPLOAD_ERR_PARTIAL    => 'Archivo subido parcialmente',
        UPLOAD_ERR_NO_FILE    => 'No se recibió archivo',
    ];
    echo json_encode(['success' => false, 'message' => $errors[$file['error']] ?? 'Error de subida']);
    exit;
}

// Validate size (3MB)
if ($file['size'] > 3 * 1024 * 1024) {
    echo json_encode(['success' => false, 'message' => 'El archivo no puede pesar más de 3MB']);
    exit;
}

// Validate type
$finfo = new finfo(FILEINFO_MIME_TYPE);
$mimeType = $finfo->file($file['tmp_name']);
$allowedTypes = ['image/jpeg' => 'jpg', 'image/png' => 'png'];

if (!isset($allowedTypes[$mimeType])) {
    echo json_encode(['success' => false, 'message' => 'Solo se permiten archivos JPG o PNG']);
    exit;
}

$ext = $allowedTypes[$mimeType];

// Delete old photo
if (!empty($dj['foto'])) {
    $oldFile = $uploadDir . $dj['foto'];
    if (file_exists($oldFile)) {
        unlink($oldFile);
    }
}

// Save new photo
$filename = 'dj_' . $dj['id'] . '_' . time() . '.' . $ext;
$destination = $uploadDir . $filename;

if (!move_uploaded_file($file['tmp_name'], $destination)) {
    echo json_encode(['success' => false, 'message' => 'Error al guardar el archivo']);
    exit;
}

chmod($destination, 0644);

// Update DB
$upd = $db->prepare("UPDATE cuentas_dj SET foto = ? WHERE id = ?");
$upd->execute([$filename, $dj['id']]);

echo json_encode([
    'success' => true,
    'message' => 'Foto de perfil actualizada',
    'url'     => 'uploads/' . $filename
]);
