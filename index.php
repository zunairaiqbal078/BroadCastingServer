<?php
/**
 * CastPanel v2.3 - Login Unificado (Estilo SonicPanel)
 * Un solo formulario para admin, reseller, cliente y DJ
 */
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/branding.php';
require_once __DIR__ . '/includes/i18n.php';
cpI18nStart();

$brand = getLoginBranding();

// Si ya está logueado, redirigir según rol
if (isLoggedIn()) {
    $rol = $_SESSION['user_rol'] ?? 'cliente';
    if ($rol === 'admin') {
        header('Location: ' . BASE_URL . '/admin/index.php');
    } elseif ($rol === 'reseller') {
        header('Location: ' . BASE_URL . '/reseller/index.php');
    } else {
        header('Location: ' . BASE_URL . '/cliente/index.php');
    }
    exit;
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';

    // Login unificado: primero intenta como usuario (admin/reseller/cliente)
    $result = loginUser($username, $password);

    // Si falla como usuario, intentar como DJ
    if (!$result['success']) {
        $resultDJ = loginDJ($username, $password);
        if ($resultDJ['success']) {
            $result = $resultDJ;
        }
    }

    if ($result['success']) {
        $redirect = $result['redirect'] ?? BASE_URL . '/cliente/index.php';
        header('Location: ' . $redirect);
        exit;
    } else {
        $error = $result['error'];
    }
}

$accentColor = $brand['primary_color'] ?? '#00897b';
$sidebarColor = $brand['sidebar_color'] ?? '#004d40';
// Card gradient adds alpha to these colours, so keep them as #rrggbb
$cpHex6 = function ($c, $fallback) {
    $c = trim((string)$c);
    if (preg_match('/^#([0-9a-f])([0-9a-f])([0-9a-f])$/i', $c, $m)) return "#$m[1]$m[1]$m[2]$m[2]$m[3]$m[3]";
    return preg_match('/^#[0-9a-f]{6}$/i', $c) ? $c : $fallback;
};
$accentColor = $cpHex6($accentColor, '#00897b');
$sidebarColor = $cpHex6($sidebarColor, '#004d40');
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($brand['login_title'] ?? 'Iniciar Sesión') ?> - <?= htmlspecialchars($brand['panel_title'] ?? 'CastPanel') ?></title>
    <?= brandingFavicon($brand) ?>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, sans-serif;
            background: #ffffff;
        }
        .login-card {
            position: relative;
            overflow: hidden;
            background: linear-gradient(135deg, <?= htmlspecialchars($sidebarColor) ?>e6 0%, #00695ccc 50%, <?= htmlspecialchars($accentColor) ?>e6 100%);
            -webkit-backdrop-filter: blur(18px);
            backdrop-filter: blur(18px);
            border: 1px solid rgba(255,255,255,.25);
            border-radius: 18px;
            padding: 44px 36px 36px;
            width: 90%;
            max-width: 400px;
            color: #fff;
            box-shadow: 0 24px 60px <?= htmlspecialchars($sidebarColor) ?>40, 0 8px 20px rgba(0,0,0,.08);
        }
        /* soft blurry light shapes inside the card */
        .login-card::before, .login-card::after {
            content: '';
            position: absolute;
            border-radius: 50%;
            filter: blur(40px);
            pointer-events: none;
        }
        .login-card::before {
            width: 220px; height: 220px;
            top: -80px; right: -60px;
            background: rgba(255,255,255,.28);
        }
        .login-card::after {
            width: 200px; height: 200px;
            bottom: -90px; left: -70px;
            background: rgba(255,255,255,.18);
        }
        .login-card > * { position: relative; z-index: 1; }
        .login-logo {
            text-align: center;
            margin-bottom: 30px;
        }
        .login-logo h1 {
            font-size: 28px; font-weight: 800;
            color: #fff;
            letter-spacing: -0.5px;
        }
        .login-logo p {
            font-size: 13px;
            color: rgba(255,255,255,.75);
            margin-top: 4px;
        }
        .form-group {
            margin-bottom: 18px;
        }
        .form-group label {
            display: block;
            font-size: 13px;
            font-weight: 600;
            color: rgba(255,255,255,.9);
            margin-bottom: 6px;
        }
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
            color: <?= htmlspecialchars($sidebarColor) ?>;
            border: none;
            border-radius: 8px;
            font-size: 14px;
            font-weight: 600;
            font-family: inherit;
            cursor: pointer;
            transition: background .2s, box-shadow .2s;
            margin-top: 4px;
        }
        .btn-login:hover {
            background: rgba(255,255,255,.88);
            box-shadow: 0 6px 16px rgba(0,0,0,.18);
        }
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
        .login-footer {
            text-align: center;
            margin-top: 20px;
            font-size: 11px;
            color: rgba(255,255,255,.6);
        }
    </style>
</head>
<body>

<div class="login-card">
    <div class="login-logo">
        <?php if (!empty($brand['logo_login'])): ?>
            <img src="<?= htmlspecialchars($brand['logo_login']) ?>" alt="<?= htmlspecialchars($brand['panel_title'] ?? 'CastPanel') ?>"
                 style="max-height:60px;max-width:220px;object-fit:contain;margin-bottom:14px">
        <?php endif; ?>
        <h1><?= htmlspecialchars($brand['panel_title'] ?? 'CastPanel') ?></h1>
        <p><?= htmlspecialchars($brand['panel_subtitle'] ?? 'Audio Streaming Platform') ?></p>
    </div>

    <?php if ($error): ?>
        <div class="error-msg"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <form method="POST">
        <div class="form-group">
            <label>Usuario</label>
            <input type="text" name="username" required autofocus placeholder="Ingrese su usuario"
                   value="<?= htmlspecialchars($_POST['username'] ?? '') ?>">
        </div>

        <div class="form-group">
            <label>Contrase&ntilde;a</label>
            <input type="password" name="password" required placeholder="Ingrese su contrase&ntilde;a">
        </div>

        <button type="submit" class="btn-login">Iniciar Sesi&oacute;n</button>
    </form>

    <div class="login-footer"><?= htmlspecialchars($brand['footer_text'] ?? 'CastPanel - Audio Streaming Platform') ?></div>
</div>

</body>
</html>
