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
require_once __DIR__ . '/../includes/i18n.php';
cpI18nStart();

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
// Same colours and card style as the main login page (index.php)
$cpHex6 = function ($c, $fallback) {
    $c = trim((string)$c);
    if (preg_match('/^#([0-9a-f])([0-9a-f])([0-9a-f])$/i', $c, $m)) return "#$m[1]$m[1]$m[2]$m[2]$m[3]$m[3]";
    return preg_match('/^#[0-9a-f]{6}$/i', $c) ? $c : $fallback;
};
$bgColor = $cpHex6($brand['sidebar_color'] ?? '', '#004d40');
$btnColor = $cpHex6($brand['primary_color'] ?? '', '#00897b');

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
        background: #ffffff;
        font-family: 'Inter', -apple-system, BlinkMacSystemFont, sans-serif;
    }
    .login-card {
        position: relative;
        overflow: hidden;
        background: linear-gradient(135deg, <?= $bgColor ?>e6 0%, #00695ccc 50%, <?= $btnColor ?>e6 100%);
        -webkit-backdrop-filter: blur(18px);
        backdrop-filter: blur(18px);
        border: 1px solid rgba(255,255,255,.25);
        border-radius: 18px;
        padding: 44px 36px 36px;
        width: 90%;
        max-width: 400px;
        color: #fff;
        box-shadow: 0 24px 60px <?= $bgColor ?>40, 0 8px 20px rgba(0,0,0,.08);
    }
    /* soft blurry light shapes inside the card */
    .login-card::before, .login-card::after {
        content: '';
        position: absolute;
        border-radius: 50%;
        filter: blur(40px);
        pointer-events: none;
    }
    .login-card::before { width: 220px; height: 220px; top: -80px; right: -60px; background: rgba(255,255,255,.28); }
    .login-card::after { width: 200px; height: 200px; bottom: -90px; left: -70px; background: rgba(255,255,255,.18); }
    .login-card > * { position: relative; z-index: 1; }
    .login-logo { text-align: center; margin-bottom: 30px; }
    .login-logo img { max-height: 60px; max-width: 220px; object-fit: contain; margin-bottom: 14px; }
    .login-logo h1 { font-size: 28px; font-weight: 800; color: #fff; letter-spacing: -0.5px; }
    .login-logo p { font-size: 13px; color: rgba(255,255,255,.75); margin-top: 4px; }
    .form-group { margin-bottom: 18px; }
    .form-group label { display: block; font-size: 13px; font-weight: 600; color: rgba(255,255,255,.9); margin-bottom: 6px; }
    .form-group input {
        width: 100%;
        padding: 11px 14px;
        border: 1.5px solid rgba(255,255,255,.3);
        border-radius: 8px;
        font-size: 14px;
        font-family: inherit;
        color: #fff;
        background: rgba(255,255,255,.12);
        transition: border-color .2s, box-shadow .2s;
        outline: none;
    }
    .form-group input::placeholder { color: rgba(255,255,255,.6); }
    .form-group input:focus {
        border-color: rgba(255,255,255,.8);
        box-shadow: 0 0 0 3px rgba(255,255,255,.18);
        background: rgba(255,255,255,.2);
    }
    .btn-login {
        width: 100%;
        padding: 12px;
        background: #fff;
        color: <?= $bgColor ?>;
        border: none;
        border-radius: 8px;
        font-size: 14px;
        font-weight: 600;
        font-family: inherit;
        cursor: pointer;
        transition: background .2s, box-shadow .2s;
        margin-top: 4px;
    }
    .btn-login:hover { background: rgba(255,255,255,.88); box-shadow: 0 6px 16px rgba(0,0,0,.18); }
    .error-msg {
        background: #fef2f2;
        color: #991b1b;
        border: 1px solid #fecaca;
        padding: 10px 14px;
        border-radius: 8px;
        font-size: 13px;
        margin-bottom: 18px;
        text-align: center;
    }
    .login-footer { text-align: center; margin-top: 20px; font-size: 11px; color: rgba(255,255,255,.6); }
</style>
</head>
<body>
<div class="login-card">
    <div class="login-logo">
        <?php if ($logoSrc): ?>
            <img src="<?= htmlspecialchars($logoSrc) ?>" alt="<?= htmlspecialchars($panelName) ?>">
        <?php endif; ?>
        <h1><?= htmlspecialchars($panelName) ?></h1>
        <p>Portal DJ</p>
    </div>

    <?php if ($error): ?>
    <div class="error-msg"><?= $error ?></div>
    <?php endif; ?>

    <form method="POST" autocomplete="off">
        <div class="form-group">
            <label>Usuario DJ</label>
            <input type="text" name="username" placeholder="Usuario DJ" required autofocus
                   value="<?= htmlspecialchars($_POST['username'] ?? '') ?>">
        </div>

        <div class="form-group">
            <label>Contrase&ntilde;a</label>
            <input type="password" name="password" placeholder="Contrase&ntilde;a" required>
        </div>

        <button type="submit" class="btn-login">Iniciar Sesi&oacute;n</button>
    </form>

    <div class="login-footer"><?= htmlspecialchars($panelName) ?> &copy; <?= date('Y') ?></div>
</div>
</body>
</html>
