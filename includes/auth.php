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
    if (in_array($_SESSION['user_rol'] ?? '', $roles, true)) {
        return;
    }
    // Another tab of this browser may have switched the session to a
    // different account (e.g. admin "Login as user" opens the client panel).
    // If this browser is still signed in with a role this page accepts,
    // switch back to that identity instead of refusing access.
    foreach ($roles as $rol) {
        if (cpAuthSwitchTo($rol)) {
            return;
        }
    }
    $isAjax = ($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET'
        || strtolower($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'xmlhttprequest'
        || str_contains($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json');
    if (!$isAjax && !headers_sent()) {
        // Signed in, but not with a role for this page: go to your own panel
        header('Location: ' . cpAuthHomeUrl($_SESSION['user_rol'] ?? ''));
        exit;
    }
    header('HTTP/1.1 403 Forbidden');
    exit('Acceso denegado');
}

/* ----------------------------------------------------------------------
 * Several accounts in one browser
 * ----------------------------------------------------------------------
 * All panel tabs of a browser share one PHP session. Before this, signing
 * in as a client (or admin "Login as user") overwrote the admin identity,
 * so refreshing an admin tab showed "Acceso denegado". Each role that
 * signed in is now remembered in $_SESSION['cp_ctx'][role], and a page
 * switches the active identity to the role it needs.
 */
const CP_AUTH_KEYS = ['user_id', 'user_username', 'user_nombre', 'user_rol', 'user_email',
                      'estacion_id', 'estacion_nombre', 'estacion_slug'];

/** Remember the identity that is active right now under its role. */
function cpAuthRemember(): void {
    $rol = $_SESSION['user_rol'] ?? '';
    if ($rol === '' || empty($_SESSION['user_id'])) return;
    $ctx = [];
    foreach (CP_AUTH_KEYS as $k) {
        if (array_key_exists($k, $_SESSION)) $ctx[$k] = $_SESSION[$k];
    }
    $_SESSION['cp_ctx'][$rol] = $ctx;
}

/** Make a remembered identity the active one. */
function cpAuthSwitchTo(string $rol): bool {
    $ctx = $_SESSION['cp_ctx'][$rol] ?? null;
    if (!is_array($ctx) || empty($ctx['user_id'])) return false;
    if (($_SESSION['user_rol'] ?? '') === $rol && (int)($_SESSION['user_id'] ?? 0) === (int)$ctx['user_id']) {
        return true;
    }
    foreach (['user_id', 'user_username', 'user_nombre', 'user_rol', 'user_email'] as $k) {
        if (array_key_exists($k, $ctx)) $_SESSION[$k] = $ctx[$k]; else unset($_SESSION[$k]);
    }
    // A client identity carries its station; admin/reseller keep whatever
    // station the client tab uses, so that tab keeps working too.
    if (isset($ctx['estacion_id'])) {
        foreach (['estacion_id', 'estacion_nombre', 'estacion_slug'] as $k) {
            $_SESSION[$k] = $ctx[$k] ?? null;
        }
    }
    return true;
}

/** Forget one remembered identity. */
function cpAuthForget(string $rol): void {
    unset($_SESSION['cp_ctx'][$rol]);
}

function cpAuthHomeUrl(string $rol): string {
    return BASE_URL . match ($rol) {
        'admin'    => '/admin/index.php',
        'reseller' => '/reseller/index.php',
        'cliente'  => '/cliente/index.php',
        default    => '/index.php',
    };
}

/**
 * Pages under /admin/, /reseller/ and /cliente/ need that role. Switch to it
 * (when this browser is signed in with it) before the page reads $_SESSION.
 */
function cpAuthSelectForCurrentArea(): void {
    if (empty($_SESSION['cp_ctx'])) {
        // Sessions created before this change: remember the active identity
        if (!empty($_SESSION['user_id'])) cpAuthRemember();
        return;
    }
    $script = str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? '');
    // admin/cuentas_dj.php is shared by every role; it uses the active identity
    if (str_ends_with($script, '/admin/cuentas_dj.php')) return;
    foreach (['/admin/' => 'admin', '/reseller/' => 'reseller', '/cliente/' => 'cliente'] as $dir => $rol) {
        if (str_contains($script, $dir)) {
            if (($_SESSION['user_rol'] ?? '') !== $rol) cpAuthSwitchTo($rol);
            return;
        }
    }
}
cpAuthSelectForCurrentArea();

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
        unset($_SESSION['estacion_id'], $_SESSION['estacion_nombre'], $_SESSION['estacion_slug']);
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

    cpAuthRemember();

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
    cpAuthRemember();

    return ['success' => true];
}

/**
 * Cerrar sesión
 */
function logout(): void {
    // Signed in with more than one account in this browser (e.g. admin used
    // "Login as user"): log out only the active one and go back to the other.
    // The logout link says which account its tab was using
    $asked = (string)($_GET['rol'] ?? '');
    if ($asked !== '' && ($_SESSION['user_rol'] ?? '') !== $asked) {
        cpAuthSwitchTo($asked);
    }
    $rol = $_SESSION['user_rol'] ?? '';
    if ($rol !== '' && !empty($_SESSION['cp_ctx'])) {
        cpAuthForget($rol);
        if ($rol === 'cliente') {
            unset($_SESSION['estacion_id'], $_SESSION['estacion_nombre'], $_SESSION['estacion_slug']);
        }
        foreach (['admin', 'reseller', 'cliente', 'dj'] as $other) {
            if (cpAuthSwitchTo($other)) {
                unset($_SESSION['admin_impersonator_id'], $_SESSION['admin_impersonator_rol']);
                header('Location: ' . cpAuthHomeUrl($other));
                exit;
            }
        }
    }
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
    // /cliente/ pages switch the session to a remembered client identity;
    // "Login as user" is an admin/reseller action, so use that identity.
    if (!in_array($_SESSION['user_rol'] ?? '', ['admin', 'reseller'], true)) {
        cpAuthSwitchTo('admin') || cpAuthSwitchTo('reseller');
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

    cpAuthRemember(); // keep the admin/reseller identity for its own tabs
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
    cpAuthRemember();
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
