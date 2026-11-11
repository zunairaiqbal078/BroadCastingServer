<?php
/**
 * CastPanel v2.0 - Autenticación y Sesiones
 */

require_once __DIR__ . '/../config/database.php';

session_name(SESSION_NAME);
session_start();

/**
 * Verificar si el usuario está autenticado
 */
function isLoggedIn(): bool {
    return isset($_SESSION['user_id']) && $_SESSION['user_id'] > 0;
}

/**
 * Requerir autenticación (redirige al login si no)
 */
function requireLogin(): void {
    if (!isLoggedIn()) {
        header('Location: ' . BASE_URL . '/index.php');
        exit;
    }
}

/**
 * Requerir rol específico
 */
function requireRole(string ...$roles): void {
    requireLogin();
    if (!in_array($_SESSION['user_rol'], $roles)) {
        header('HTTP/1.1 403 Forbidden');
        exit('Acceso denegado');
    }
}

/**
 * Login de usuario
 */
function loginUser(string $username, string $password): array {
    $db = getDB();
    $stmt = $db->prepare("SELECT * FROM usuarios WHERE username = ? AND activo = 1");
    $stmt->execute([$username]);
    $user = $stmt->fetch();

    if (!$user || !password_verify($password, $user['password'])) {
        return ['success' => false, 'error' => 'Usuario o contraseña incorrectos'];
    }

    // Actualizar último login
    $db->prepare("UPDATE usuarios SET ultimo_login = NOW() WHERE id = ?")->execute([$user['id']]);

    // Establecer sesión
    $_SESSION['user_id'] = $user['id'];
    $_SESSION['user_username'] = $user['username'];
    $_SESSION['user_nombre'] = $user['nombre'];
    $_SESSION['user_rol'] = $user['rol'];
    $_SESSION['user_email'] = $user['email'];

    // Cargar estación si es cliente
    if ($user['rol'] === 'cliente') {
        $stmt = $db->prepare("SELECT * FROM estaciones WHERE usuario_id = ? LIMIT 1");
        $stmt->execute([$user['id']]);
        $estacion = $stmt->fetch();
        if ($estacion) {
            $_SESSION['estacion_id'] = $estacion['id'];
            $_SESSION['estacion_nombre'] = $estacion['nombre'];
            $_SESSION['estacion_slug'] = $estacion['slug'];
            require_once __DIR__ . '/provisioning.php';
            castpanelClientAutoStartOnLogin($estacion);
        }
    }

    // Determinar URL de redirección según el rol
    $redirect = BASE_URL . '/cliente/index.php';
    if ($user['rol'] === 'admin') {
        $redirect = BASE_URL . '/admin/index.php';
    } elseif ($user['rol'] === 'reseller') {
        $redirect = BASE_URL . '/reseller/index.php';
    }

    return ['success' => true, 'user' => $user, 'redirect' => $redirect];
}

/**
 * Login de DJ
 */
function loginDJ(string $username, string $password): array {
    $db = getDB();
    $stmt = $db->prepare("
        SELECT d.*, e.nombre AS estacion_nombre, e.slug AS estacion_slug, e.id AS estacion_id
        FROM cuentas_dj d
        JOIN estaciones e ON e.id = d.estacion_id
        WHERE d.username = ? AND d.activo = 1
    ");
    $stmt->execute([$username]);
    $dj = $stmt->fetch();

    if (!$dj || !password_verify($password, $dj['password'])) {
        return ['success' => false, 'error' => 'Credenciales DJ inválidas'];
    }

    $db->prepare("UPDATE cuentas_dj SET ultimo_login = NOW() WHERE id = ?")->execute([$dj['id']]);

    $_SESSION['user_id'] = $dj['id'];
    $_SESSION['user_username'] = $dj['username'];
    $_SESSION['user_nombre'] = $dj['nombre'] ?: $dj['username'];
    $_SESSION['user_rol'] = 'dj';
    $_SESSION['estacion_id'] = $dj['estacion_id'];
    $_SESSION['estacion_nombre'] = $dj['estacion_nombre'];
    $_SESSION['estacion_slug'] = $dj['estacion_slug'];

    return ['success' => true];
}

/**
 * Cerrar sesión
 */
function logout(): void {
    session_destroy();
    header('Location: ' . BASE_URL . '/index.php');
    exit;
}

/**
 * Admin/reseller: open client panel as that station owner (Login as user).
 */
function castpanelAdminLoginAsCliente(int $clientUserId): bool {
    if (!isLoggedIn() || $clientUserId <= 0) {
        return false;
    }
    $rol = $_SESSION['user_rol'] ?? '';
    if (!in_array($rol, ['admin', 'reseller'], true)) {
        return false;
    }

    $db = getDB();
    $stmt = $db->prepare("SELECT * FROM usuarios WHERE id = ? AND rol = 'cliente' AND activo = 1");
    $stmt->execute([$clientUserId]);
    $user = $stmt->fetch();
    if (!$user) {
        return false;
    }
    if ($rol === 'reseller' && (int)($user['reseller_id'] ?? 0) !== (int)$_SESSION['user_id']) {
        return false;
    }

    if (!isset($_SESSION['admin_impersonator_id'])) {
        $_SESSION['admin_impersonator_id'] = (int)$_SESSION['user_id'];
        $_SESSION['admin_impersonator_rol'] = $rol;
    }

    $_SESSION['user_id'] = (int)$user['id'];
    $_SESSION['user_username'] = $user['username'];
    $_SESSION['user_nombre'] = $user['nombre'] ?: $user['username'];
    $_SESSION['user_rol'] = 'cliente';
    $_SESSION['user_email'] = $user['email'] ?? '';

    $stmt = $db->prepare(
        "SELECT id, nombre, slug FROM estaciones WHERE usuario_id = ? AND activa = 1 ORDER BY id ASC LIMIT 1"
    );
    $stmt->execute([$clientUserId]);
    $est = $stmt->fetch();
    if (!$est) {
        unset($_SESSION['estacion_id'], $_SESSION['estacion_nombre'], $_SESSION['estacion_slug']);
        return false;
    }

    $_SESSION['estacion_id'] = (int)$est['id'];
    $_SESSION['estacion_nombre'] = $est['nombre'];
    $_SESSION['estacion_slug'] = $est['slug'];
    require_once __DIR__ . '/provisioning.php';
    castpanelClientAutoStartOnLogin(['id' => (int)$est['id']]);
    return true;
}

/**
 * Obtener la estación actual del usuario logueado
 */
function getCurrentStation(): ?array {
    if (!isset($_SESSION['estacion_id'])) return null;
    $db = getDB();
    $stmt = $db->prepare("SELECT e.*, p.max_storage_mb, p.max_bitrate, p.max_oyentes, p.nombre AS paquete_nombre,
                                 p.autodj_enabled, p.anuncios_enabled, p.social_stream_enabled,
                                 p.max_dj, p.ssl_enabled AS paquete_ssl_enabled
                          FROM estaciones e
                          LEFT JOIN paquetes p ON p.id = e.paquete_id
                          WHERE e.id = ?");
    $stmt->execute([$_SESSION['estacion_id']]);
    return $stmt->fetch() ?: null;
}

/**
 * Obtener uso de almacenamiento de una estación
 */
function getStorageUsed(int $estacionId): int {
    try {
        $db = getDB();
        $stmt = $db->prepare("SELECT COALESCE(SUM(tamano_bytes), 0) AS total FROM canciones WHERE estacion_id = ?");
        $stmt->execute([$estacionId]);
        $musica = (int)$stmt->fetchColumn();

        $stmt = $db->prepare("SELECT COALESCE(SUM(tamano_bytes), 0) AS total FROM anuncios WHERE estacion_id = ?");
        $stmt->execute([$estacionId]);
        $anuncios = (int)$stmt->fetchColumn();

        return $musica + $anuncios;
    } catch (PDOException $e) { return 0; }
}
