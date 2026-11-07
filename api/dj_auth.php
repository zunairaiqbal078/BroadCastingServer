<?php
/**
 * CastPanel v2.7.5 - DJ Authentication Webhook
 *
 * Liquidsoap calls this endpoint to authenticate DJ connections.
 * The harbor sends username + password via HTTP GET/POST,
 * and this script validates against the cuentas_dj table.
 *
 * Returns: "true" (plain text) if authenticated, "false" otherwise.
 * Liquidsoap expects a plain text response for HTTP auth callbacks.
 */
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';

// Accept both GET and POST (Liquidsoap may use either)
$username = $_REQUEST['username'] ?? '';
$password = $_REQUEST['password'] ?? '';
$slug     = $_REQUEST['station']  ?? '';

// All fields required
if (!$username || !$password || !$slug) {
    header('Content-Type: text/plain');
    echo 'false';
    exit;
}

try {
    $db = getDB();

    // Find the station by slug
    $stmt = $db->prepare("SELECT id FROM estaciones WHERE slug = ?");
    $stmt->execute([$slug]);
    $estacion = $stmt->fetch();

    if (!$estacion) {
        header('Content-Type: text/plain');
        echo 'false';
        exit;
    }

    // Find DJ account for this station
    $stmt = $db->prepare(
        "SELECT id, password, activo FROM cuentas_dj WHERE estacion_id = ? AND username = ? LIMIT 1"
    );
    $stmt->execute([$estacion['id'], $username]);
    $dj = $stmt->fetch();

    if (!$dj || empty($dj['activo'])) {
        header('Content-Type: text/plain');
        echo 'false';
        exit;
    }

    // Verify password (stored as bcrypt hash via password_hash)
    if (password_verify($password, $dj['password'])) {
        // Log successful DJ connection
        error_log("CastPanel DJ Auth: DJ '$username' authenticated for station '$slug'");
        header('Content-Type: text/plain');
        echo 'true';
        exit;
    }

    // Password mismatch
    header('Content-Type: text/plain');
    echo 'false';
    exit;

} catch (Exception $e) {
    error_log("CastPanel DJ Auth Error: " . $e->getMessage());
    header('Content-Type: text/plain');
    echo 'false';
    exit;
}
