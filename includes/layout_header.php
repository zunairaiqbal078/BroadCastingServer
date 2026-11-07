<?php
/**
 * CastPanel v2.3 - Layout Header (sidebar + topbar)
 */
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/branding.php';
require_once __DIR__ . '/icons.php';
requireLogin();

$estacion = getCurrentStation();
$currentPage = basename($_SERVER['SCRIPT_NAME'], '.php');
$flash = getFlash();
$brand = getCurrentBranding();
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($pageTitle ?? 'Panel de Control') ?> - <?= htmlspecialchars($brand['panel_title'] ?? 'CastPanel') ?></title>
    <?= brandingFavicon($brand) ?>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;450;500;600;700;800&display=swap" rel="stylesheet">
    <link href="<?= BASE_URL ?>/assets/css/style.css" rel="stylesheet">
    <style>
        .nav-icon { font-style: normal; display:inline-flex; align-items:center; }
        <?php if (!empty($brand['primary_color'])): ?>
        :root { --primary: <?= htmlspecialchars($brand['primary_color']) ?>; }
        <?php endif; ?>
        <?php if (!empty($brand['sidebar_color'])): ?>
        .sidebar { background: <?= htmlspecialchars($brand['sidebar_color']) ?>; }
        <?php endif; ?>
    </style>
</head>
<body>

<div class="layout">
    <!-- Overlay móvil -->
    <div class="sidebar-overlay" id="sidebarOverlay" onclick="toggleSidebar()"></div>

    <!-- SIDEBAR -->
    <aside class="sidebar" id="sidebar">
        <div class="sidebar-logo">
            <?php if (!empty($brand['logo_sidebar'])): ?>
                <img src="<?= htmlspecialchars($brand['logo_sidebar']) ?>" alt="<?= htmlspecialchars($brand['panel_title'] ?? 'CastPanel') ?>"
                     style="max-height:44px;max-width:180px;object-fit:contain">
            <?php else: ?>
                <div class="logo-icon"><?= strtoupper(substr($brand['panel_title'] ?? 'C', 0, 1)) ?></div>
                <div class="logo-text">
                    <?= htmlspecialchars($brand['panel_title'] ?? 'CastPanel') ?>
                    <small><?= htmlspecialchars($brand['panel_subtitle'] ?? 'Audio Streaming') ?></small>
                </div>
            <?php endif; ?>
        </div>

        <?php if (!empty($isAdmin)): ?>
        <!-- ADMIN SIDEBAR — Estilo SonicPanel agrupado -->

        <div class="sidebar-section">Dashboard</div>
        <ul class="sidebar-nav">
            <li><a href="<?= BASE_URL ?>/admin/index.php" class="<?= $currentPage === 'index' ? 'active' : '' ?>">
                <span class="nav-icon"><?= cpIcon('home', 16) ?></span> Panel Principal
            </a></li>
        </ul>

        <div class="sidebar-section">Radios y Cuentas</div>
        <ul class="sidebar-nav">
            <li><a href="<?= BASE_URL ?>/admin/estaciones.php?action=new" class="<?= ($currentPage === 'estaciones' && ($_GET['action'] ?? '') === 'new') ? 'active' : '' ?>">
                <span class="nav-icon"><?= cpIcon('plus', 16) ?></span> Crear Radio
            </a></li>
            <li><a href="<?= BASE_URL ?>/admin/estaciones.php" class="<?= ($currentPage === 'estaciones' && ($_GET['action'] ?? '') !== 'new') ? 'active' : '' ?>">
                <span class="nav-icon"><?= cpIcon('radio', 16) ?></span> Listar / Editar Radios
            </a></li>
            <li><a href="<?= BASE_URL ?>/admin/paquetes.php?action=new" class="<?= ($currentPage === 'paquetes' && ($_GET['action'] ?? '') === 'new') ? 'active' : '' ?>">
                <span class="nav-icon"><?= cpIcon('plus', 16) ?></span> Agregar Paquete de Radio
            </a></li>
            <li><a href="<?= BASE_URL ?>/admin/paquetes.php" class="<?= ($currentPage === 'paquetes' && ($_GET['action'] ?? '') !== 'new') ? 'active' : '' ?>">
                <span class="nav-icon"><?= cpIcon('pencil', 16) ?></span> Editar/Listar Paquetes
            </a></li>
            <!-- Modulo Usuarios removido — los usuarios se crean junto con la estacion -->
            <li><a href="<?= BASE_URL ?>/admin/autodj_list.php" class="<?= $currentPage === 'autodj_list' ? 'active' : '' ?>">
                <span class="nav-icon"><?= cpIcon('music', 16) ?></span> Listar AutoDJs
            </a></li>
            <li><a href="<?= BASE_URL ?>/admin/top_radios.php" class="<?= $currentPage === 'top_radios' ? 'active' : '' ?>">
                <span class="nav-icon"><?= cpIcon('trending-up', 16) ?></span> Top 50 Radios
            </a></li>
            <li><a href="<?= BASE_URL ?>/admin/streams.php" class="<?= $currentPage === 'streams' ? 'active' : '' ?>">
                <span class="nav-icon"><?= cpIcon('signal', 16) ?></span> Stream / Source List
            </a></li>
            <li><a href="<?= BASE_URL ?>/admin/playlists_compartidas.php" class="<?= $currentPage === 'playlists_compartidas' ? 'active' : '' ?>">
                <span class="nav-icon"><?= cpIcon('share', 16) ?></span> Listas Compartidas
            </a></li>
        </ul>

        <div class="sidebar-section">Revendedores</div>
        <ul class="sidebar-nav">
            <li><a href="<?= BASE_URL ?>/admin/revendedores.php?action=new" class="<?= ($currentPage === 'revendedores' && ($_GET['action'] ?? '') === 'new') ? 'active' : '' ?>">
                <span class="nav-icon"><?= cpIcon('plus', 16) ?></span> Crear un Nuevo Revendedor
            </a></li>
            <li><a href="<?= BASE_URL ?>/admin/revendedores.php" class="<?= ($currentPage === 'revendedores' && ($_GET['action'] ?? '') !== 'new') ? 'active' : '' ?>">
                <span class="nav-icon"><?= cpIcon('list', 16) ?></span> Listar/Editar Revendedores
            </a></li>
            <li><a href="<?= BASE_URL ?>/admin/paquetes_reseller.php?action=new" class="<?= ($currentPage === 'paquetes_reseller' && ($_GET['action'] ?? '') === 'new') ? 'active' : '' ?>">
                <span class="nav-icon"><?= cpIcon('plus', 16) ?></span> Agregar Paquete de Revendedor
            </a></li>
            <li><a href="<?= BASE_URL ?>/admin/paquetes_reseller.php" class="<?= ($currentPage === 'paquetes_reseller' && ($_GET['action'] ?? '') !== 'new') ? 'active' : '' ?>">
                <span class="nav-icon"><?= cpIcon('pencil', 16) ?></span> Editar Paquetes de Revendedor
            </a></li>
        </ul>

        <div class="sidebar-section">SSL y Dominio</div>
        <ul class="sidebar-nav">
            <li><a href="<?= BASE_URL ?>/admin/ssl.php" class="<?= $currentPage === 'ssl' ? 'active' : '' ?>">
                <span class="nav-icon"><?= cpIcon('lock', 16) ?></span> Dominios / Certificados SSL
            </a></li>
            <li><a href="<?= BASE_URL ?>/admin/hostname.php" class="<?= $currentPage === 'hostname' ? 'active' : '' ?>">
                <span class="nav-icon"><?= cpIcon('globe', 16) ?></span> Cambiar Nombre de Host
            </a></li>
            <li><a href="<?= BASE_URL ?>/admin/ssl_port.php" class="<?= $currentPage === 'ssl_port' ? 'active' : '' ?>">
                <span class="nav-icon"><?= cpIcon('key', 16) ?></span> Configuraci&oacute;n Puerto SSL
            </a></li>
        </ul>

        <div class="sidebar-section">Servidor de Correo</div>
        <ul class="sidebar-nav">
            <li><a href="<?= BASE_URL ?>/admin/smtp.php" class="<?= $currentPage === 'smtp' ? 'active' : '' ?>">
                <span class="nav-icon"><?= cpIcon('mail', 16) ?></span> Configuraci&oacute;n SMTP
            </a></li>
            <li><a href="<?= BASE_URL ?>/admin/email_templates.php" class="<?= $currentPage === 'email_templates' ? 'active' : '' ?>">
                <span class="nav-icon"><?= cpIcon('note', 16) ?></span> Plantillas de Correo
            </a></li>
        </ul>

        <div class="sidebar-section">Branding</div>
        <ul class="sidebar-nav">
            <li><a href="<?= BASE_URL ?>/admin/branding.php" class="<?= $currentPage === 'branding' ? 'active' : '' ?>">
                <span class="nav-icon"><?= cpIcon('palette', 16) ?></span> Change Logos/Titles
            </a></li>
        </ul>

        <div class="sidebar-section">Configuraci&oacute;n del Servidor</div>
        <ul class="sidebar-nav">
            <li><a href="<?= BASE_URL ?>/admin/configuracion.php" class="<?= $currentPage === 'configuracion' ? 'active' : '' ?>">
                <span class="nav-icon"><?= cpIcon('gear', 16) ?></span> Configuraci&oacute;n CastPanel
            </a></li>
            <li><a href="<?= BASE_URL ?>/admin/server_status.php" class="<?= $currentPage === 'server_status' ? 'active' : '' ?>">
                <span class="nav-icon"><?= cpIcon('activity', 16) ?></span> Estado del Servidor
            </a></li>
            <li><a href="<?= BASE_URL ?>/admin/services.php" class="<?= $currentPage === 'services' ? 'active' : '' ?>">
                <span class="nav-icon"><?= cpIcon('server', 16) ?></span> Administrador de Servicios
            </a></li>
            <li><a href="<?= BASE_URL ?>/admin/firewall.php" class="<?= $currentPage === 'firewall' ? 'active' : '' ?>">
                <span class="nav-icon"><?= cpIcon('shield', 16) ?></span> Seguridad y Firewall
            </a></li>
            <li><a href="<?= BASE_URL ?>/admin/api_billing.php" class="<?= $currentPage === 'api_billing' ? 'active' : '' ?>">
                <span class="nav-icon"><?= cpIcon('dollar', 16) ?></span> Facturaci&oacute;n y API
            </a></li>
            <li><a href="<?= BASE_URL ?>/admin/idioma.php" class="<?= $currentPage === 'idioma' ? 'active' : '' ?>">
                <span class="nav-icon"><?= cpIcon('languages', 16) ?></span> Cambiar Idioma
            </a></li>
            <li><a href="<?= BASE_URL ?>/admin/phpmyadmin.php" class="<?= $currentPage === 'phpmyadmin' ? 'active' : '' ?>">
                <span class="nav-icon"><?= cpIcon('database', 16) ?></span> phpMyAdmin
            </a></li>
        </ul>

        <div class="sidebar-section">Estad&iacute;sticas</div>
        <ul class="sidebar-nav">
            <li><a href="<?= BASE_URL ?>/admin/disk_usage.php" class="<?= $currentPage === 'disk_usage' ? 'active' : '' ?>">
                <span class="nav-icon"><?= cpIcon('hard-drive', 16) ?></span> Uso de Disco / HDD
            </a></li>
            <li><a href="<?= BASE_URL ?>/admin/bandwidth.php" class="<?= $currentPage === 'bandwidth' ? 'active' : '' ?>">
                <span class="nav-icon"><?= cpIcon('wifi', 16) ?></span> Uso de Ancho de Banda
            </a></li>
            <li><a href="<?= BASE_URL ?>/admin/radio_stats.php" class="<?= $currentPage === 'radio_stats' ? 'active' : '' ?>">
                <span class="nav-icon"><?= cpIcon('bar-chart', 16) ?></span> Estad&iacute;sticas de Radio
            </a></li>
            <li><a href="<?= BASE_URL ?>/admin/listeners_map.php" class="<?= $currentPage === 'listeners_map' ? 'active' : '' ?>">
                <span class="nav-icon"><?= cpIcon('map-pin', 16) ?></span> Oyentes (Google Maps)
            </a></li>
            <li><a href="<?= BASE_URL ?>/admin/live_stats.php" class="<?= $currentPage === 'live_stats' ? 'active' : '' ?>">
                <span class="nav-icon"><?= cpIcon('activity', 16) ?></span> Estad&iacute;sticas en Vivo
            </a></li>
            <li><a href="<?= BASE_URL ?>/admin/server_info.php" class="<?= $currentPage === 'server_info' ? 'active' : '' ?>">
                <span class="nav-icon"><?= cpIcon('cpu', 16) ?></span> Info del Servidor
            </a></li>
        </ul>

        <div class="sidebar-section">Control M&uacute;ltiple</div>
        <ul class="sidebar-nav">
            <li><a href="<?= BASE_URL ?>/admin/bulk_control.php" class="<?= $currentPage === 'bulk_control' ? 'active' : '' ?>">
                <span class="nav-icon"><?= cpIcon('power', 16) ?></span> Control Masivo de Radios
            </a></li>
            <li><a href="<?= BASE_URL ?>/admin/restart_services.php" class="<?= $currentPage === 'restart_services' ? 'active' : '' ?>">
                <span class="nav-icon"><?= cpIcon('refresh', 16) ?></span> Reiniciar Servicios
            </a></li>
        </ul>

        <?php elseif (!empty($isReseller)): ?>
        <!-- RESELLER SIDEBAR -->
        <div class="sidebar-section">Panel Reseller</div>
        <ul class="sidebar-nav">
            <li><a href="<?= BASE_URL ?>/reseller/index.php" class="<?= $currentPage === 'index' ? 'active' : '' ?>">
                <span class="nav-icon"><?= cpIcon('home', 16) ?></span> Dashboard
            </a></li>
            <li><a href="<?= BASE_URL ?>/reseller/clientes.php" class="<?= $currentPage === 'clientes' ? 'active' : '' ?>">
                <span class="nav-icon"><?= cpIcon('users', 16) ?></span> Mis Clientes
            </a></li>
            <li><a href="<?= BASE_URL ?>/reseller/estaciones.php" class="<?= $currentPage === 'estaciones' ? 'active' : '' ?>">
                <span class="nav-icon"><?= cpIcon('radio', 16) ?></span> Estaciones
            </a></li>
            <li><a href="<?= BASE_URL ?>/reseller/branding.php" class="<?= $currentPage === 'branding' ? 'active' : '' ?>">
                <span class="nav-icon"><?= cpIcon('palette', 16) ?></span> Mi Branding
            </a></li>
        </ul>

        <?php else: ?>
        <!-- CLIENT SIDEBAR -->
        <div class="sidebar-section">Centro de Control</div>
        <ul class="sidebar-nav">
            <li><a href="<?= BASE_URL ?>/cliente/index.php" class="<?= $currentPage === 'index' ? 'active' : '' ?>">
                <span class="nav-icon"><?= cpIcon('home', 16) ?></span> Inicio
            </a></li>
        </ul>

        <?php if (!empty($estacion['autodj_enabled'])): ?>
        <div class="sidebar-section">Gesti&oacute;n de AutoDJ</div>
        <ul class="sidebar-nav">
            <li><a href="<?= BASE_URL ?>/cliente/autodj.php" class="<?= $currentPage === 'autodj' ? 'active' : '' ?>">
                <span class="nav-icon"><?= cpIcon('gear', 16) ?></span> Administrar AutoDJ
            </a></li>
            <li><a href="<?= BASE_URL ?>/cliente/subir_musica.php" class="<?= $currentPage === 'subir_musica' ? 'active' : '' ?>">
                <span class="nav-icon"><?= cpIcon('music', 16) ?></span> Subir M&uacute;sica MP3
            </a></li>
            <li><a href="<?= BASE_URL ?>/cliente/biblioteca.php" class="<?= $currentPage === 'biblioteca' ? 'active' : '' ?>">
                <span class="nav-icon"><?= cpIcon('library', 16) ?></span> Biblioteca de M&uacute;sica
            </a></li>
            <li><a href="<?= BASE_URL ?>/cliente/listas.php" class="<?= $currentPage === 'listas' ? 'active' : '' ?>">
                <span class="nav-icon"><?= cpIcon('list', 16) ?></span> Administrar Listas
            </a></li>
        </ul>
        <?php endif; ?>

        <div class="sidebar-section">Procesador de Audio</div>
        <ul class="sidebar-nav">
            <li><a href="<?= BASE_URL ?>/cliente/procesador.php" class="<?= $currentPage === 'procesador' ? 'active' : '' ?>">
                <span class="nav-icon"><?= cpIcon('speaker', 16) ?></span> Procesador de Audio
            </a></li>
        </ul>

        <?php if (!empty($estacion['autodj_enabled'])): ?>
        <?php if (!empty($estacion['anuncios_enabled'])): ?>
        <div class="sidebar-section">Gesti&oacute;n de Anuncios</div>
        <ul class="sidebar-nav">
            <li><a href="<?= BASE_URL ?>/cliente/anuncios.php" class="<?= $currentPage === 'anuncios' ? 'active' : '' ?>">
                <span class="nav-icon"><?= cpIcon('megaphone', 16) ?></span> Subir Anuncios
            </a></li>
            <li><a href="<?= BASE_URL ?>/cliente/programar_anuncios.php" class="<?= $currentPage === 'programar_anuncios' ? 'active' : '' ?>">
                <span class="nav-icon"><?= cpIcon('clock', 16) ?></span> Programar Anuncios
            </a></li>
        </ul>
        <?php endif; ?>

        <div class="sidebar-section">Automatizaci&oacute;n</div>
        <ul class="sidebar-nav">
            <li><a href="<?= BASE_URL ?>/cliente/rotacion_carpetas.php" class="<?= $currentPage === 'rotacion_carpetas' ? 'active' : '' ?>">
                <span class="nav-icon"><?= cpIcon('refresh', 16) ?></span> Rotaci&oacute;n de Carpetas
            </a></li>
            <li><a href="<?= BASE_URL ?>/cliente/relay.php" class="<?= $currentPage === 'relay' ? 'active' : '' ?>">
                <span class="nav-icon"><?= cpIcon('satellite', 16) ?></span> Relay Redireccionamiento
            </a></li>
        </ul>
        <?php endif; ?>

        <div class="sidebar-section">Centro de Control DJ</div>
        <ul class="sidebar-nav">
            <li><a href="<?= BASE_URL ?>/cliente/cuenta_dj.php" class="<?= $currentPage === 'cuenta_dj' ? 'active' : '' ?>">
                <span class="nav-icon"><?= cpIcon('user-plus', 16) ?></span> Crear Cuenta DJ
            </a></li>
            <li><a href="<?= BASE_URL ?>/cliente/listar_dj.php" class="<?= $currentPage === 'listar_dj' ? 'active' : '' ?>">
                <span class="nav-icon"><?= cpIcon('users', 16) ?></span> Listar/Editar Cuentas DJ
            </a></li>
            <li><a href="<?= BASE_URL ?>/cliente/shows_dj.php" class="<?= $currentPage === 'shows_dj' ? 'active' : '' ?>">
                <span class="nav-icon"><?= cpIcon('download', 16) ?></span> Shows DJ
            </a></li>
            <li><a href="<?= BASE_URL ?>/cliente/mensaje_dj.php" class="<?= $currentPage === 'mensaje_dj' ? 'active' : '' ?>">
                <span class="nav-icon"><?= cpIcon('message-square', 16) ?></span> Mensaje Panel DJ
            </a></li>
        </ul>

        <div class="sidebar-section">Reproductor P&uacute;blico</div>
        <ul class="sidebar-nav">
            <li><a href="<?= BASE_URL ?>/cliente/player_config.php" class="<?= $currentPage === 'player_config' ? 'active' : '' ?>">
                <span class="nav-icon"><?= cpIcon('palette', 16) ?></span> Personalizar Reproductor
            </a></li>
        </ul>

        <div class="sidebar-section">Integraciones y API</div>
        <ul class="sidebar-nav">
            <li><a href="<?= BASE_URL ?>/cliente/widgets.php" class="<?= $currentPage === 'widgets' ? 'active' : '' ?>">
                <span class="nav-icon"><?= cpIcon('play', 16) ?></span> Widgets de Players
            </a></li>
            <li><a href="<?= BASE_URL ?>/cliente/enlaces.php" class="<?= $currentPage === 'enlaces' ? 'active' : '' ?>">
                <span class="nav-icon"><?= cpIcon('lock', 16) ?></span> Enlaces SSL/Reproducir
            </a></li>
            <li><a href="<?= BASE_URL ?>/cliente/api_json.php" class="<?= $currentPage === 'api_json' ? 'active' : '' ?>">
                <span class="nav-icon"><?= cpIcon('code', 16) ?></span> API JSON
            </a></li>
        </ul>

        <div class="sidebar-section">Estad&iacute;sticas y Cuenta</div>
        <ul class="sidebar-nav">
            <li><a href="<?= BASE_URL ?>/cliente/estadisticas.php" class="<?= $currentPage === 'estadisticas' ? 'active' : '' ?>">
                <span class="nav-icon"><?= cpIcon('chart', 16) ?></span> Estad&iacute;sticas
            </a></li>
        </ul>
        <?php endif; ?>

        <div class="sidebar-footer">
            <div class="sidebar-user">
                <div class="user-avatar"><?= strtoupper(substr($_SESSION['user_username'] ?? 'U', 0, 1)) ?></div>
                <div class="user-info">
                    <div class="user-name"><?= htmlspecialchars($_SESSION['user_username'] ?? '') ?></div>
                    <div class="user-status"><?= cpIcon('circle-dot', 10) ?> Emisora Activa</div>
                </div>
            </div>
        </div>
    </aside>

    <!-- MAIN CONTENT -->
    <main class="main-content">
        <!-- TOP BAR -->
        <header class="topbar">
            <div style="display:flex;align-items:center;gap:12px">
                <button class="sidebar-toggle" onclick="toggleSidebar()"><?= cpIcon('menu', 20) ?></button>
                <div class="topbar-title">
                    <?php
                    // Renderizar icono de página — si es un nombre cpIcon válido, usar SVG; si no, es texto plano
                    $pi = $pageIcon ?? 'radio';
                    echo '<span class="title-icon">' . cpIcon($pi, 20) . '</span>';
                    ?>
                    <?= htmlspecialchars($pageTitle ?? 'Panel de Control') ?>
                    <?php if ($estacion): ?>
                        <span style="color:var(--text-secondary);font-weight:400;font-size:13px">: <?= htmlspecialchars($estacion['slug']) ?></span>
                    <?php endif; ?>
                </div>
            </div>
            <div class="topbar-actions">
                <a href="<?= BASE_URL ?>/cliente/index.php" class="btn btn-outline btn-sm" onclick="location.reload();return false;"><?= cpIcon('refresh', 14) ?> Actualizar</a>
                <a href="<?= BASE_URL ?>/logout.php" class="btn btn-outline btn-sm"><?= cpIcon('logout', 14) ?> Cerrar Sesi&oacute;n</a>
            </div>
        </header>

        <!-- PAGE CONTENT -->
        <div class="page-content">

<?php if ($flash): ?>
    <div class="alert alert-<?= $flash['type'] ?>">
        <?= htmlspecialchars($flash['message']) ?>
    </div>
<?php endif; ?>
