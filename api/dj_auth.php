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

// RadioCaster / BUTT in SHOUTcast (ICY) mode send only a password, no
// username; Liquidsoap then reports user "source" (or empty). Accept:
//   1. password typed as "djuser:djpass"  -> normal DJ account check
//   2. the station's source password      -> same login as a non-AutoDJ station
//   3. the password of any active DJ of this station
$icyClient = ($username === '' || $username === 'source');
if ($icyClient && strpos($password, ':') !== false) {
    [$username, $password] = explode(':', $password, 2);
    $icyClient = false;
}

if ($icyClient && $password !== '' && $slug !== '') {
    try {
        $db = getDB();
        $stmt = $db->prepare("SELECT id, icecast_password FROM estaciones WHERE slug = ?");
        $stmt->execute([$slug]);
        $st = $stmt->fetch();
        $ok = false;
        if ($st) {
            if ($st['icecast_password'] !== '' && hash_equals((string)$st['icecast_password'], $password)) {
                $ok = true;
            } else {
                $stmt = $db->prepare(
                    "SELECT password FROM cuentas_dj WHERE estacion_id = ? AND activo = 1 AND perm_transmitir = 1"
                );
                $stmt->execute([$st['id']]);
                foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $hash) {
                    if (password_verify($password, $hash)) { $ok = true; break; }
                }
            }
        }
        if ($ok) {
            error_log("CastPanel DJ Auth: ICY source authenticated for station '$slug'");
        }
        header('Content-Type: text/plain');
        echo $ok ? 'true' : 'false';
        exit;
    } catch (Exception $e) {
        error_log("CastPanel DJ Auth Error: " . $e->getMessage());
        header('Content-Type: text/plain');
        echo 'false';
        exit;
    }
}

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
        "SELECT id, password, activo, perm_transmitir FROM cuentas_dj
         WHERE estacion_id = ? AND username = ? LIMIT 1"
    );
    $stmt->execute([$estacion['id'], $username]);
    $dj = $stmt->fetch();

    if (!$dj || empty($dj['activo']) || empty($dj['perm_transmitir'])) {
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
