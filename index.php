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
            background: linear-gradient(135deg, <?= htmlspecialchars($sidebarColor) ?> 0%, #00695c 50%, <?= htmlspecialchars($accentColor) ?> 100%);
        }
        .login-card {
            background: #fff;
            border-radius: 12px;
            padding: 40px 36px;
            width: 90%;
            max-width: 400px;
            box-shadow: 0 20px 60px rgba(0,0,0,.2);
        }
        .login-logo {
            text-align: center;
            margin-bottom: 30px;
        }
        .login-logo .logo-icon {
            width: 52px; height: 52px;
            background: <?= htmlspecialchars($accentColor) ?>;
            border-radius: 12px;
            display: inline-flex;
            align-items: center; justify-content: center;
            font-size: 22px; font-weight: 800;
            color: #fff;
            margin-bottom: 14px;
        }
        .login-logo h1 {
            font-size: 22px; font-weight: 700;
            color: #1a1a1a;
            letter-spacing: -0.3px;
        }
        .login-logo p {
            font-size: 13px;
            color: #888;
            margin-top: 4px;
        }
        .form-group {
            margin-bottom: 18px;
        }
        .form-group label {
            display: block;
            font-size: 13px;
            font-weight: 600;
            color: #444;
            margin-bottom: 6px;
        }
        .form-group input {
            width: 100%;
            padding: 11px 14px;
            border: 1.5px solid #e0e0e0;
            border-radius: 8px;
            font-size: 14px;
            font-family: inherit;
            color: #333;
            background: #fafafa;
            transition: border-color .2s, box-shadow .2s;
            outline: none;
        }
        .form-group input:focus {
            border-color: <?= htmlspecialchars($accentColor) ?>;
            box-shadow: 0 0 0 3px <?= htmlspecialchars($accentColor) ?>22;
            background: #fff;
        }
        .btn-login {
            width: 100%;
            padding: 12px;
            background: <?= htmlspecialchars($accentColor) ?>;
            color: #fff;
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
            background: <?= htmlspecialchars($sidebarColor) ?>;
            box-shadow: 0 4px 12px rgba(0,0,0,.15);
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
            color: #bbb;
        }
    </style>
</head>
<body>

<div class="login-card">
    <div class="login-logo">
        <?php if (!empty($brand['logo_login'])): ?>
            <img src="<?= htmlspecialchars($brand['logo_login']) ?>" alt="<?= htmlspecialchars($brand['panel_title'] ?? 'CastPanel') ?>"
                 style="max-height:60px;max-width:220px;object-fit:contain;margin-bottom:14px">
        <?php else: ?>
            <div class="logo-icon"><?= strtoupper(substr($brand['panel_title'] ?? 'C', 0, 1)) ?></div>
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
