<?php
/**
 * CastPanel DJ Portal — Layout Header (estilo SonicPanel)
 * Sidebar + top bar + apertura de content area
 */
if (session_status() === PHP_SESSION_NONE) session_start();
if (!isset($_SESSION['dj_id'])) {
    header('Location: index.php');
    exit;
}

require_once '/var/www/castpanel/config/database.php';
require_once __DIR__ . '/../../includes/i18n.php';
cpI18nStart(); // translate the DJ portal into the panel language

$db = getDB();

// DJ + estación
$_djStmt = $db->prepare("
    SELECT d.*, e.nombre AS estacion_nombre, e.slug, e.server_type,
           e.puerto_stream, e.icecast_port, e.icecast_password,
           e.titulo_radio, e.activa, COALESCE(p.autodj_enabled, 0) AS autodj_enabled
    FROM cuentas_dj d
    JOIN estaciones e ON e.id = d.estacion_id
    LEFT JOIN paquetes p ON p.id = e.paquete_id
    WHERE d.id = ? AND d.activo = 1
");
$_djStmt->execute([$_SESSION['dj_id']]);
$dj = $_djStmt->fetch(PDO::FETCH_ASSOC);

if (!$dj) {
    session_destroy();
    header('Location: index.php');
    exit;
}

// Branding
try {
    $brand = $db->query("SELECT * FROM branding LIMIT 1")->fetch(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $brand = [];
}

$panelName   = htmlspecialchars($brand['nombre_panel'] ?? 'CastPanel');
$sidebarColor = htmlspecialchars($brand['sidebar_color'] ?? '#1a2332');
$primaryColor = htmlspecialchars($brand['primary_color'] ?? '#27917e');
$_page        = basename($_SERVER['PHP_SELF'], '.php');

// DJ photo
$djPhoto = 'data:image/svg+xml,' . rawurlencode('<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 80 80"><rect fill="#ddd" width="80" height="80"/><text x="40" y="48" text-anchor="middle" font-size="32" fill="#999" font-family="sans-serif">' . strtoupper(substr($dj['nombre'] ?? 'D', 0, 1)) . '</text></svg>');
if (!empty($dj['foto']) && file_exists('/var/www/castpanel/dj/uploads/' . $dj['foto'])) {
    $djPhoto = 'uploads/' . htmlspecialchars($dj['foto']);
}

$djName = htmlspecialchars($dj['nombre'] ?? $dj['username'] ?? 'DJ');
$stationName = htmlspecialchars($dj['estacion_nombre'] ?? $dj['titulo_radio'] ?? 'Radio');
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= $pageTitle ?? 'Dashboard' ?> — <?= $panelName ?> DJ</title>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<style>
:root {
    --sidebar-bg: <?= $sidebarColor ?>;
    --primary: <?= $primaryColor ?>;
    --primary-hover: <?= $primaryColor ?>dd;
    --content-bg: #f0f2f5;
    --card-bg: #fff;
    --text-dark: #1f2937;
    --text-secondary: #6b7280;
    --text-light: rgba(255,255,255,.85);
    --text-muted: rgba(255,255,255,.5);
    --border: #e5e7eb;
    --success: #10b981;
    --danger: #ef4444;
    --info-bg: #dbeafe;
    --info-text: #1e40af;
    --info-border: #93c5fd;
    --sidebar-w: 230px;
    --topbar-h: 56px;
}

*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

body {
    font-family: 'Inter', -apple-system, BlinkMacSystemFont, sans-serif;
    background: var(--content-bg);
    color: var(--text-dark);
    min-height: 100vh;
}

/* ═══ SIDEBAR ═══ */
.dj-sidebar {
    position: fixed;
    top: 0; left: 0;
    width: var(--sidebar-w);
    height: 100vh;
    background: var(--sidebar-bg);
    display: flex;
    flex-direction: column;
    z-index: 100;
    transition: transform .3s;
    overflow-y: auto;
}

.sidebar-logo {
    padding: 20px 18px;
    display: flex;
    align-items: center;
    gap: 10px;
    border-bottom: 1px solid rgba(255,255,255,.08);
}
.sidebar-logo .logo-icon {
    width: 40px; height: 40px;
    background: var(--primary);
    border-radius: 10px;
    display: flex;
    align-items: center;
    justify-content: center;
}
.sidebar-logo .logo-icon svg { width: 22px; height: 22px; fill: #fff; }
.sidebar-logo span {
    color: #fff;
    font-weight: 700;
    font-size: 15px;
}

.sidebar-menu { padding: 12px 0; flex: 1; }
.sidebar-menu a {
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 10px 20px;
    color: var(--text-light);
    text-decoration: none;
    font-size: 13.5px;
    font-weight: 500;
    transition: all .15s;
    border-left: 3px solid transparent;
}
.sidebar-menu a:hover {
    background: rgba(255,255,255,.06);
    color: #fff;
}
.sidebar-menu a.active {
    background: rgba(255,255,255,.1);
    color: #fff;
    border-left-color: var(--primary);
}
.sidebar-menu a svg {
    width: 18px; height: 18px;
    fill: none; stroke: currentColor;
    stroke-width: 2;
    stroke-linecap: round;
    stroke-linejoin: round;
    flex-shrink: 0;
}

.sidebar-section {
    padding: 16px 20px 6px;
    font-size: 10px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 1.2px;
    color: var(--text-muted);
}

.sidebar-station {
    padding: 14px 18px;
    border-top: 1px solid rgba(255,255,255,.08);
    display: flex;
    align-items: center;
    gap: 8px;
}
.sidebar-station .dot {
    width: 8px; height: 8px;
    border-radius: 50%;
    background: var(--success);
    flex-shrink: 0;
}
.sidebar-station span {
    color: var(--text-light);
    font-size: 12px;
    font-weight: 500;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}

/* ═══ TOP BAR ═══ */
.dj-topbar {
    position: fixed;
    top: 0;
    left: var(--sidebar-w);
    right: 0;
    height: var(--topbar-h);
    background: var(--card-bg);
    border-bottom: 1px solid var(--border);
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 0 24px;
    z-index: 90;
}

.topbar-left {
    display: flex;
    align-items: center;
    gap: 12px;
}
.topbar-left .breadcrumb {
    display: flex;
    align-items: center;
    gap: 6px;
    font-size: 14px;
    color: var(--text-secondary);
}
.topbar-left .breadcrumb a {
    color: var(--primary);
    text-decoration: none;
    font-weight: 500;
}
.topbar-left .breadcrumb svg { width: 14px; height: 14px; }
.hamburger {
    display: none;
    background: none; border: none;
    cursor: pointer; padding: 4px;
}
.hamburger svg { width: 24px; height: 24px; stroke: var(--text-dark); }

.topbar-right {
    display: flex;
    align-items: center;
    gap: 14px;
}
.topbar-user {
    display: flex;
    align-items: center;
    gap: 8px;
}
.topbar-user img {
    width: 34px; height: 34px;
    border-radius: 50%;
    object-fit: cover;
    border: 2px solid var(--border);
}
.topbar-user .name { font-size: 13px; font-weight: 600; }
.btn-logout {
    background: none;
    border: 1px solid var(--border);
    color: var(--text-secondary);
    padding: 6px 14px;
    border-radius: 6px;
    font-size: 12px;
    font-weight: 600;
    cursor: pointer;
    text-decoration: none;
    transition: all .15s;
}
.btn-logout:hover { border-color: var(--danger); color: var(--danger); }

/* ═══ MAIN CONTENT ═══ */
.dj-main {
    margin-left: var(--sidebar-w);
    padding-top: var(--topbar-h);
    min-height: 100vh;
}
.dj-content {
    padding: 24px;
    max-width: 1200px;
    margin: 0 auto;
}

/* ═══ CARDS & SECTIONS ═══ */
.section-card {
    background: var(--card-bg);
    border-radius: 10px;
    overflow: hidden;
    margin-bottom: 24px;
    box-shadow: 0 1px 3px rgba(0,0,0,.06);
}
.section-header {
    background: var(--primary);
    color: #fff;
    padding: 12px 20px;
    font-size: 15px;
    font-weight: 700;
    display: flex;
    align-items: center;
    gap: 8px;
}
.section-header svg {
    width: 18px; height: 18px;
    fill: none; stroke: currentColor;
    stroke-width: 2;
    stroke-linecap: round;
    stroke-linejoin: round;
}
.section-body { padding: 20px; }

/* ═══ TABS ═══ */
.tabs {
    display: flex;
    border-bottom: 2px solid var(--border);
    padding: 0 20px;
    background: var(--card-bg);
}
.tab-btn {
    padding: 12px 16px;
    background: none;
    border: none;
    border-bottom: 2px solid transparent;
    margin-bottom: -2px;
    font-size: 13.5px;
    font-weight: 600;
    color: var(--text-secondary);
    cursor: pointer;
    transition: all .15s;
    display: flex;
    align-items: center;
    gap: 6px;
    font-family: inherit;
    white-space: nowrap;
}
.tab-btn:hover { color: var(--primary); }
.tab-btn.active {
    color: var(--primary);
    border-bottom-color: var(--primary);
}
.tab-btn svg {
    width: 15px; height: 15px;
    fill: none; stroke: currentColor;
    stroke-width: 2;
}
.tab-panel { display: none; padding: 20px; }
.tab-panel.active { display: block; }

/* ═══ FORM ELEMENTS ═══ */
.form-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 16px;
}
.form-group { margin-bottom: 16px; }
.form-group label {
    display: block;
    font-size: 13px;
    font-weight: 600;
    color: var(--text-secondary);
    margin-bottom: 6px;
}
.form-control {
    width: 100%;
    padding: 10px 14px;
    border: 1px solid var(--border);
    border-radius: 8px;
    font-size: 14px;
    font-family: inherit;
    background: #f9fafb;
    color: var(--text-dark);
    transition: border-color .15s;
}
.form-control:focus {
    outline: none;
    border-color: var(--primary);
    background: #fff;
}
.form-control[readonly] {
    background: #f3f4f6;
    color: var(--text-secondary);
    cursor: default;
}
.input-with-icon {
    position: relative;
}
.input-with-icon .icon {
    position: absolute;
    left: 12px; top: 50%;
    transform: translateY(-50%);
    width: 16px; height: 16px;
    color: var(--text-secondary);
}
.input-with-icon .form-control {
    padding-left: 36px;
}

/* ═══ BUTTONS ═══ */
.btn {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 10px 20px;
    border: none;
    border-radius: 8px;
    font-size: 14px;
    font-weight: 600;
    font-family: inherit;
    cursor: pointer;
    transition: all .15s;
    text-decoration: none;
}
.btn svg {
    width: 16px; height: 16px;
    fill: none; stroke: currentColor;
    stroke-width: 2;
}
.btn-primary { background: var(--primary); color: #fff; }
.btn-primary:hover { opacity: .9; }
.btn-success { background: var(--success); color: #fff; }
.btn-success:hover { opacity: .9; }
.btn-danger { background: var(--danger); color: #fff; }
.btn-danger:hover { opacity: .9; }
.btn-dark { background: #374151; color: #fff; }
.btn-dark:hover { background: #1f2937; }
.btn-outline {
    background: none;
    border: 1px solid var(--border);
    color: var(--text-secondary);
}
.btn-outline:hover { border-color: var(--primary); color: var(--primary); }
.btn-sm { padding: 7px 14px; font-size: 13px; }

/* ═══ ALERTS ═══ */
.alert {
    padding: 12px 16px;
    border-radius: 8px;
    font-size: 13px;
    margin-bottom: 16px;
    display: flex;
    align-items: flex-start;
    gap: 8px;
    line-height: 1.5;
}
.alert svg { width: 18px; height: 18px; flex-shrink: 0; margin-top: 1px; }
.alert-info {
    background: var(--info-bg);
    color: var(--info-text);
    border: 1px solid var(--info-border);
}
.alert-success { background: #d1fae5; color: #065f46; border: 1px solid #6ee7b7; }
.alert-danger { background: #fef2f2; color: #991b1b; border: 1px solid #fecaca; }
.alert-warning { background: #fffbeb; color: #92400e; border: 1px solid #fde68a; }

/* ═══ STATUS BADGE ═══ */
.status-badge {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 4px 12px;
    border-radius: 20px;
    font-size: 13px;
    font-weight: 600;
}
.status-online { background: #d1fae5; color: #065f46; }
.status-offline { background: #fef2f2; color: #991b1b; }

/* ═══ PLAYER MINI ═══ */
.mini-player {
    background: #f9fafb;
    border: 1px solid var(--border);
    border-radius: 10px;
    padding: 14px;
    display: flex;
    align-items: center;
    gap: 14px;
    margin-top: 16px;
}
.mini-player .album-art {
    width: 60px; height: 60px;
    border-radius: 8px;
    background: #e5e7eb;
    display: flex;
    align-items: center;
    justify-content: center;
    overflow: hidden;
    flex-shrink: 0;
}
.mini-player .album-art img { width: 100%; height: 100%; object-fit: cover; }
.mini-player .player-controls {
    flex: 1;
}
.mini-player .now-playing {
    font-size: 13px;
    font-weight: 600;
    margin-bottom: 6px;
}
.mini-player .np-label {
    color: var(--text-secondary);
    font-weight: 400;
}
.mini-player audio { width: 100%; height: 32px; }

/* ═══ MODAL ═══ */
.modal-overlay {
    display: none;
    position: fixed;
    inset: 0;
    background: rgba(0,0,0,.4);
    z-index: 200;
    align-items: center;
    justify-content: center;
}
.modal-overlay.show { display: flex; }
.modal {
    background: var(--card-bg);
    border-radius: 12px;
    width: 90%;
    max-width: 520px;
    max-height: 90vh;
    overflow-y: auto;
    box-shadow: 0 12px 40px rgba(0,0,0,.2);
}
.modal-header {
    padding: 18px 24px;
    border-bottom: 1px solid var(--border);
    display: flex;
    align-items: center;
    justify-content: space-between;
}
.modal-header h3 { font-size: 16px; font-weight: 700; }
.modal-close {
    background: none; border: none;
    font-size: 20px; cursor: pointer;
    color: var(--text-secondary);
    padding: 4px;
}
.modal-body { padding: 24px; }
.modal-footer {
    padding: 16px 24px;
    border-top: 1px solid var(--border);
    text-align: right;
}

/* ═══ RESPONSIVE ═══ */
@media (max-width: 768px) {
    .dj-sidebar { transform: translateX(-100%); }
    .dj-sidebar.open { transform: translateX(0); }
    .dj-main { margin-left: 0; }
    .dj-topbar { left: 0; }
    .hamburger { display: block; }
    .form-grid { grid-template-columns: 1fr; }
    .dj-content { padding: 16px; }
    .tabs { overflow-x: auto; }
    .sidebar-overlay {
        display: none;
        position: fixed; inset: 0;
        background: rgba(0,0,0,.3);
        z-index: 99;
    }
    .sidebar-overlay.show { display: block; }
}
</style>
</head>
<body>

<!-- Overlay móvil -->
<div class="sidebar-overlay" id="sidebarOverlay" onclick="toggleSidebar()"></div>

<!-- SIDEBAR -->
<nav class="dj-sidebar" id="sidebar">
    <div class="sidebar-logo">
        <div class="logo-icon">
            <svg viewBox="0 0 24 24"><path d="M9 18V5l12-2v13"/><circle cx="6" cy="18" r="3"/><circle cx="18" cy="16" r="3"/></svg>
        </div>
        <span>DJ Panel</span>
    </div>

    <div class="sidebar-menu">
        <a href="dashboard.php" class="<?= $_page === 'dashboard' ? 'active' : '' ?>">
            <svg viewBox="0 0 24 24"><path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/></svg>
            Inicio
        </a>

        <div class="sidebar-section">Cuenta</div>
        <a href="account.php" class="<?= $_page === 'account' ? 'active' : '' ?>">
            <svg viewBox="0 0 24 24"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
            Administrar Cuenta
        </a>
        <a href="profile.php" class="<?= $_page === 'profile' ? 'active' : '' ?>">
            <svg viewBox="0 0 24 24"><rect x="3" y="3" width="18" height="18" rx="2" ry="2"/><circle cx="8.5" cy="8.5" r="1.5"/><polyline points="21 15 16 10 5 21"/></svg>
            Foto de Perfil
        </a>
        <a href="widget.php" class="<?= $_page === 'widget' ? 'active' : '' ?>">
            <svg viewBox="0 0 24 24"><polyline points="15 3 21 3 21 9"/><polyline points="9 21 3 21 3 15"/><line x1="21" y1="3" x2="14" y2="10"/><line x1="3" y1="21" x2="10" y2="14"/></svg>
            Widget en Vivo
        </a>
    </div>

    <div class="sidebar-station">
        <span class="dot"></span>
        <span><?= $stationName ?></span>
    </div>
</nav>

<!-- TOP BAR -->
<header class="dj-topbar">
    <div class="topbar-left">
        <button class="hamburger" onclick="toggleSidebar()">
            <svg viewBox="0 0 24 24" fill="none" stroke-width="2" stroke-linecap="round"><line x1="3" y1="6" x2="21" y2="6"/><line x1="3" y1="12" x2="21" y2="12"/><line x1="3" y1="18" x2="21" y2="18"/></svg>
        </button>
        <div class="breadcrumb">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/></svg>
            <a href="dashboard.php">Inicio</a>
            <?php if (isset($pageTitle) && $_page !== 'dashboard'): ?>
                <span>›</span>
                <span><?= htmlspecialchars($pageTitle) ?></span>
            <?php endif; ?>
        </div>
    </div>
    <div class="topbar-right">
        <div class="topbar-user">
            <img src="<?= $djPhoto ?>" alt="DJ">
            <span class="name"><?= $djName ?></span>
        </div>
        <a href="logout.php" class="btn-logout">Cerrar Sesión</a>
    </div>
</header>

<!-- MAIN -->
<main class="dj-main">
<div class="dj-content">
