<?php
/**
 * CastPanel — DJ Portal Login
 * Puerto 2080 — Solo para cuentas DJ
 * Estilo igual al login principal + soporte logo branding
 */
session_start();
if (isset($_SESSION['dj_id'])) {
    header('Location: dashboard.php');
    exit;
}

require_once '/var/www/castpanel/config/database.php';

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';

    if ($username === '' || $password === '') {
        $error = 'Ingrese usuario y contrase&ntilde;a';
    } else {
        $db = getDB();
        $stmt = $db->prepare("
            SELECT d.*, e.nombre AS estacion_nombre, e.slug, e.server_type
            FROM cuentas_dj d
            JOIN estaciones e ON e.id = d.estacion_id
            WHERE d.username = ? AND d.activo = 1
            LIMIT 1
        ");
        $stmt->execute([$username]);
        $dj = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($dj && password_verify($password, $dj['password'])) {
            if (!$dj['perm_login_panel']) {
                $error = 'Tu cuenta no tiene permiso para iniciar sesi&oacute;n';
            } else {
                $_SESSION['dj_id'] = $dj['id'];
                $_SESSION['dj_username'] = $dj['username'];
                $_SESSION['dj_nombre'] = $dj['nombre'];
                $_SESSION['dj_estacion_id'] = $dj['estacion_id'];

                $upd = $db->prepare("UPDATE cuentas_dj SET ultimo_login = NOW() WHERE id = ?");
                $upd->execute([$dj['id']]);

                header('Location: dashboard.php');
                exit;
            }
        } else {
            $error = 'Usuario o contrase&ntilde;a incorrectos';
        }
    }
}

// Obtener branding (mismos colores que el login principal)
try {
    $db = getDB();
    $brand = $db->query("SELECT * FROM branding LIMIT 1")->fetch(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $brand = [];
}
$panelName = $brand['panel_title'] ?? 'CastPanel';
$bgColor = htmlspecialchars($brand['sidebar_color'] ?? '#3b4a5e');
$btnColor = htmlspecialchars($brand['primary_color'] ?? '#1a2332');

// Construir URL del logo (el DJ portal corre en puerto 2080, los uploads están en el dominio principal)
$logoSrc = '';
if (!empty($brand['logo_login'])) {
    $logoSrc = $brand['logo_login'];
    // Si no es URL absoluta, construir desde el dominio principal (sin puerto)
    if (!preg_match('#^https?://#i', $logoSrc)) {
        $mainHost = preg_replace('/:\d+$/', '', $_SERVER['HTTP_HOST'] ?? 'localhost');
        $logoSrc = 'https://' . $mainHost . '/' . ltrim($logoSrc, '/');
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= htmlspecialchars($panelName) ?> — DJ Login</title>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<style>
    *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

    body {
        min-height: 100vh;
        display: flex;
        align-items: center;
        justify-content: center;
        background: <?= $bgColor ?>;
        font-family: 'Inter', -apple-system, BlinkMacSystemFont, sans-serif;
    }

    .login-wrapper {
        width: 90%; max-width: 400px;
    }

    .login-card {
        background: #fff;
        border-radius: 12px;
        padding: 40px 36px;
        box-shadow: 0 4px 24px rgba(0,0,0,.15);
    }

    .login-logo {
        text-align: center;
        margin-bottom: 8px;
    }
    .login-logo .logo-icon {
        width: 56px; height: 56px;
        background: <?= $btnColor ?>;
        border-radius: 14px;
        display: inline-flex;
        align-items: center; justify-content: center;
        font-size: 24px; font-weight: 800;
        color: #fff;
    }
    .login-logo .logo-img {
        max-height: 64px;
        max-width: 200px;
        object-fit: contain;
    }

    .login-title {
        text-align: center;
        font-size: 18px;
        font-weight: 700;
        color: #1f2937;
        margin-bottom: 4px;
    }
    .login-subtitle {
        text-align: center;
        font-size: 12px;
        color: #9ca3af;
        margin-bottom: 24px;
    }

    .input-group {
        display: flex;
        margin-bottom: 16px;
        border: 1px solid #e5e7eb;
        border-radius: 8px;
        overflow: hidden;
        transition: border-color .2s;
    }
    .input-group:focus-within {
        border-color: <?= $btnColor ?>;
    }
    .input-icon {
        width: 46px;
        min-width: 46px;
        display: flex;
        align-items: center;
        justify-content: center;
        background: <?= $btnColor ?>;
        color: #fff;
    }
    .input-icon svg {
        width: 18px; height: 18px;
        fill: none;
        stroke: currentColor;
        stroke-width: 2;
        stroke-linecap: round;
        stroke-linejoin: round;
    }
    .input-group input {
        flex: 1;
        padding: 12px 14px;
        border: none;
        outline: none;
        font-size: 14px;
        font-family: inherit;
        background: #f9fafb;
        color: #1f2937;
    }
    .input-group input::placeholder {
        color: #9ca3af;
    }

    .btn-login {
        width: 100%;
        padding: 13px;
        background: <?= $btnColor ?>;
        color: #fff;
        border: none;
        border-radius: 8px;
        font-size: 15px;
        font-weight: 600;
        font-family: inherit;
        cursor: pointer;
        transition: opacity .2s;
        margin-top: 4px;
    }
    .btn-login:hover {
        opacity: .9;
    }

    .error-msg {
        background: #fef2f2;
        color: #991b1b;
        border: 1px solid #fecaca;
        padding: 10px 14px;
        border-radius: 8px;
        font-size: 13px;
        margin-bottom: 16px;
        text-align: center;
    }

    .footer-text {
        text-align: center;
        font-size: 11px;
        color: rgba(255,255,255,0.4);
        margin-top: 20px;
    }
</style>
</head>
<body>
<div class="login-wrapper">
    <div class="login-card">
        <div class="login-logo">
            <?php if ($logoSrc): ?>
                <img src="<?= htmlspecialchars($logoSrc) ?>" alt="<?= htmlspecialchars($panelName) ?>" class="logo-img">
            <?php else: ?>
                <div class="logo-icon"><?= strtoupper(substr($panelName, 0, 1)) ?></div>
            <?php endif; ?>
        </div>
        <div class="login-title"><?= htmlspecialchars($panelName) ?></div>
        <div class="login-subtitle">Portal DJ</div>

        <?php if ($error): ?>
        <div class="error-msg"><?= $error ?></div>
        <?php endif; ?>

        <form method="POST" autocomplete="off">
            <div class="input-group">
                <div class="input-icon">
                    <svg viewBox="0 0 24 24"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
                </div>
                <input type="text" name="username" placeholder="Usuario DJ" required autofocus
                       value="<?= htmlspecialchars($_POST['username'] ?? '') ?>">
            </div>

            <div class="input-group">
                <div class="input-icon">
                    <svg viewBox="0 0 24 24"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
                </div>
                <input type="password" name="password" placeholder="Contrase&ntilde;a" required>
            </div>

            <button type="submit" class="btn-login">Iniciar Sesi&oacute;n</button>
        </form>
    </div>
    <div class="footer-text"><?= htmlspecialchars($panelName) ?> &copy; <?= date('Y') ?></div>
</div>
</body>
</html>
