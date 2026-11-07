<?php
/**
 * CastPanel v2.4 - Panel de Administración
 * Dashboard estilo SonicPanel con accesos directos
 */
$pageTitle = 'Panel de Administración';
$pageIcon = 'gear';
$isAdmin = true;
require_once __DIR__ . '/../includes/layout_header.php';
requireRole('admin');

$db = getDB();

// Stats globales
$totalUsuarios = 0;
$totalEstaciones = 0;
$estacionesOnline = 0;
$totalCanciones = 0;
$totalAnuncios = 0;
$totalDJ = 0;
$oyentesTotales = 0;
$totalStorage = 0;
$allListeners = [];
$_dbErrors = [];

try { $totalUsuarios = (int)$db->query("SELECT COUNT(*) FROM usuarios")->fetchColumn(); } catch (PDOException $e) { $_dbErrors[] = 'usuarios'; }
try { $totalEstaciones = (int)$db->query("SELECT COUNT(*) FROM estaciones")->fetchColumn(); } catch (PDOException $e) { $_dbErrors[] = 'estaciones'; }
try { $estacionesOnline = (int)$db->query("SELECT COUNT(*) FROM estaciones WHERE autodj_estado = 'online'")->fetchColumn(); } catch (PDOException $e) {}
try { $totalCanciones = (int)$db->query("SELECT COUNT(*) FROM canciones")->fetchColumn(); } catch (PDOException $e) { $_dbErrors[] = 'canciones'; }
try { $totalAnuncios = (int)$db->query("SELECT COUNT(*) FROM anuncios")->fetchColumn(); } catch (PDOException $e) { $_dbErrors[] = 'anuncios'; }
try { $totalDJ = (int)$db->query("SELECT COUNT(*) FROM cuentas_dj")->fetchColumn(); } catch (PDOException $e) { $_dbErrors[] = 'cuentas_dj'; }

try {
    $allListeners = getIcecastListeners();
    foreach ($allListeners as $m) $oyentesTotales += $m['oyentes'];
} catch (Exception $e) {}

try { $totalStorage = (int)$db->query("SELECT COALESCE(SUM(tamano_bytes),0) FROM canciones")->fetchColumn(); } catch (PDOException $e) {}
try { $totalStorage += (int)$db->query("SELECT COALESCE(SUM(tamano_bytes),0) FROM anuncios")->fetchColumn(); } catch (PDOException $e) {}

// Info del servidor
$serverLoad = @sys_getloadavg();
$loadStr = $serverLoad ? number_format($serverLoad[0], 2) . ' - ' . number_format($serverLoad[1], 2) . ' - ' . number_format($serverLoad[2], 2) : 'N/A';
$hostname = gethostname() ?: 'N/A';
$sslDomain = defined('SSL_DOMAIN') ? SSL_DOMAIN : $hostname;
?>

<style>
.cp-dash-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(280px,1fr));gap:16px;margin-bottom:32px}
.cp-dash-card{display:flex;align-items:flex-start;gap:16px;padding:20px;background:var(--card-bg);border-radius:10px;border:1px solid var(--border-color);transition:box-shadow .2s,border-color .2s;cursor:pointer;text-decoration:none;color:inherit}
.cp-dash-card:hover{box-shadow:0 4px 12px rgba(0,0,0,.1)}
.cp-dash-icon{width:48px;height:48px;border-radius:10px;display:flex;align-items:center;justify-content:center;flex-shrink:0;color:#fff}
.cp-dash-title{font-weight:600;font-size:15px;margin-bottom:4px}
.cp-dash-desc{font-size:12px;color:var(--text-secondary);line-height:1.4}
.cp-section-title{font-size:18px;font-weight:600;margin-bottom:16px;color:var(--text-primary)}
.cp-section-divider{border-top:1px solid var(--border-color);padding-top:24px}
</style>

<?php if (!empty($_dbErrors)): ?>
<div class="alert alert-warning"><?= cpIcon('warning', 16) ?> Algunas tablas no existen aún: <strong><?= implode(', ', $_dbErrors) ?></strong>. Ejecute la migración SQL.</div>
<?php endif; ?>

<!-- Header Info Bar -->
<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:24px;padding:12px 18px;background:var(--card-bg);border-radius:8px;border:1px solid var(--border-color);font-size:13px;color:var(--text-secondary);flex-wrap:wrap;gap:8px">
    <span><?= cpIcon('server', 14) ?> <strong><?= htmlspecialchars($sslDomain) ?></strong></span>
    <span>CastPanel v<?= trim(file_get_contents(BASE_PATH . '/VERSION')) ?></span>
    <span>Promedios de Carga: <?= $loadStr ?></span>
</div>

<!-- ═══════════════════════════════════════════ -->
<!-- Radios y Cuentas -->
<!-- ═══════════════════════════════════════════ -->
<h2 class="cp-section-title"><?= cpIcon('radio', 20) ?> Radios y Cuentas</h2>
<div class="cp-dash-grid">

    <!-- Crear Radio -->
    <a href="<?= BASE_URL ?>/admin/estaciones.php?action=new" class="cp-dash-card" onmouseover="this.style.borderColor='#6366f1'" onmouseout="this.style.borderColor=''">
        <div class="cp-dash-icon" style="background:linear-gradient(135deg,#6366f1,#818cf8)">
            <?= cpIcon('plus', 24) ?>
        </div>
        <div>
            <div class="cp-dash-title" style="color:#6366f1">Crear Radio</div>
            <div class="cp-dash-desc">Crear una nueva radio y cuenta de cliente. Los clientes pueden administrar sus radios desde el panel del cliente.</div>
        </div>
    </a>

    <!-- Listar / Editar Radios -->
    <a href="<?= BASE_URL ?>/admin/estaciones.php" class="cp-dash-card" onmouseover="this.style.borderColor='#0ea5e9'" onmouseout="this.style.borderColor=''">
        <div class="cp-dash-icon" style="background:linear-gradient(135deg,#0ea5e9,#38bdf8)">
            <?= cpIcon('list', 24) ?>
        </div>
        <div>
            <div class="cp-dash-title" style="color:#0ea5e9">Listar / Editar Radios</div>
            <div class="cp-dash-desc">Vea, edite y administre todas las radios y cuentas existentes en el servidor.</div>
        </div>
    </a>

    <!-- Agregar Paquete de Radio -->
    <a href="<?= BASE_URL ?>/admin/paquetes.php" class="cp-dash-card" onmouseover="this.style.borderColor='#10b981'" onmouseout="this.style.borderColor=''">
        <div class="cp-dash-icon" style="background:linear-gradient(135deg,#10b981,#34d399)">
            <?= cpIcon('package', 24) ?>
        </div>
        <div>
            <div class="cp-dash-title" style="color:#10b981">Agregar Paquete de Radio</div>
            <div class="cp-dash-desc">Cree un paquete con los límites de cuentas y radios para sus clientes.</div>
        </div>
    </a>

    <!-- Estado del Servidor en Vivo -->
    <a href="<?= BASE_URL ?>/admin/server_status.php" class="cp-dash-card" onmouseover="this.style.borderColor='#f59e0b'" onmouseout="this.style.borderColor=''">
        <div class="cp-dash-icon" style="background:linear-gradient(135deg,#f59e0b,#fbbf24)">
            <?= cpIcon('signal', 24) ?>
        </div>
        <div>
            <div class="cp-dash-title" style="color:#f59e0b">Estado del Servidor en Vivo</div>
            <div class="cp-dash-desc">Verifique el uso de memoria, CPU, carga y disco del servidor en tiempo real.</div>
        </div>
    </a>

    <!-- Facturación y Automatización (API) -->
    <a href="<?= BASE_URL ?>/admin/api_billing.php" class="cp-dash-card" onmouseover="this.style.borderColor='#8b5cf6'" onmouseout="this.style.borderColor=''">
        <div class="cp-dash-icon" style="background:linear-gradient(135deg,#8b5cf6,#a78bfa)">
            <?= cpIcon('code', 24) ?>
        </div>
        <div>
            <div class="cp-dash-title" style="color:#8b5cf6">Facturación y Automatización (API)</div>
            <div class="cp-dash-desc">Cree y administre claves API, descargue módulos para software de facturación.</div>
        </div>
    </a>

</div>

<!-- ═══════════════════════════════════════════ -->
<!-- Administrar Servidor y Servicios -->
<!-- ═══════════════════════════════════════════ -->
<h2 class="cp-section-title cp-section-divider"><?= cpIcon('server', 20) ?> Administrar Servidor y Servicios</h2>
<div class="cp-dash-grid">

    <!-- Configuración Global -->
    <a href="<?= BASE_URL ?>/admin/configuracion.php" class="cp-dash-card" onmouseover="this.style.borderColor='#0ea5e9'" onmouseout="this.style.borderColor=''">
        <div class="cp-dash-icon" style="background:linear-gradient(135deg,#0ea5e9,#38bdf8)">
            <?= cpIcon('gear', 24) ?>
        </div>
        <div>
            <div class="cp-dash-title" style="color:#0ea5e9">Configuración Global</div>
            <div class="cp-dash-desc">Configure los ajustes globales del panel y servicios de streaming.</div>
        </div>
    </a>

    <!-- SSL / Dominios -->
    <a href="<?= BASE_URL ?>/admin/ssl.php" class="cp-dash-card" onmouseover="this.style.borderColor='#10b981'" onmouseout="this.style.borderColor=''">
        <div class="cp-dash-icon" style="background:linear-gradient(135deg,#10b981,#34d399)">
            <?= cpIcon('lock', 24) ?>
        </div>
        <div>
            <div class="cp-dash-title" style="color:#10b981">Dominios / Certificados SSL</div>
            <div class="cp-dash-desc">Administre certificados SSL y dominios del servidor.</div>
        </div>
    </a>

    <!-- Cambiar Idioma -->
    <a href="<?= BASE_URL ?>/admin/idioma.php" class="cp-dash-card" onmouseover="this.style.borderColor='#6366f1'" onmouseout="this.style.borderColor=''">
        <div class="cp-dash-icon" style="background:linear-gradient(135deg,#6366f1,#818cf8)">
            <?= cpIcon('globe', 24) ?>
        </div>
        <div>
            <div class="cp-dash-title" style="color:#6366f1">Cambiar Idioma</div>
            <div class="cp-dash-desc">Cambie el idioma de su CastPanel.</div>
        </div>
    </a>

    <!-- Reiniciar Servicios -->
    <a href="<?= BASE_URL ?>/admin/restart_services.php" class="cp-dash-card" onmouseover="this.style.borderColor='#ef4444'" onmouseout="this.style.borderColor=''">
        <div class="cp-dash-icon" style="background:linear-gradient(135deg,#ef4444,#f87171)">
            <?= cpIcon('refresh', 24) ?>
        </div>
        <div>
            <div class="cp-dash-title" style="color:#ef4444">Reiniciar Servicios</div>
            <div class="cp-dash-desc">Reinicie los servicios de streaming del servidor.</div>
        </div>
    </a>

    <!-- Branding / White Label -->
    <a href="<?= BASE_URL ?>/admin/branding.php" class="cp-dash-card" onmouseover="this.style.borderColor='#f59e0b'" onmouseout="this.style.borderColor=''">
        <div class="cp-dash-icon" style="background:linear-gradient(135deg,#f59e0b,#fbbf24)">
            <?= cpIcon('palette', 24) ?>
        </div>
        <div>
            <div class="cp-dash-title" style="color:#f59e0b">Branding / White Label</div>
            <div class="cp-dash-desc">Personalice el logo, nombre y colores de su panel.</div>
        </div>
    </a>

    <!-- SMTP / Email -->
    <a href="<?= BASE_URL ?>/admin/smtp.php" class="cp-dash-card" onmouseover="this.style.borderColor='#8b5cf6'" onmouseout="this.style.borderColor=''">
        <div class="cp-dash-icon" style="background:linear-gradient(135deg,#8b5cf6,#a78bfa)">
            <?= cpIcon('mail', 24) ?>
        </div>
        <div>
            <div class="cp-dash-title" style="color:#8b5cf6">Configurar SMTP / Email</div>
            <div class="cp-dash-desc">Configure el servidor de correo para notificaciones.</div>
        </div>
    </a>

</div>

<!-- ═══════════════════════════════════════════ -->
<!-- Resumen del Servidor -->
<!-- ═══════════════════════════════════════════ -->
<h2 class="cp-section-title cp-section-divider"><?= cpIcon('chart', 20) ?> Resumen del Servidor</h2>
<div class="stats-grid">
    <div class="stat-card green">
        <div class="stat-label">Oyentes Totales</div>
        <div class="stat-value"><?= $oyentesTotales ?></div>
        <div class="stat-sub">En todas las estaciones</div>
        <span class="stat-badge"><?= cpIcon('headphones', 14) ?> LIVE</span>
    </div>
    <div class="stat-card blue">
        <div class="stat-label">Estaciones</div>
        <div class="stat-value"><?= $totalEstaciones ?></div>
        <div class="stat-sub"><?= $estacionesOnline ?> online ahora</div>
        <span class="stat-badge"><?= cpIcon('radio', 14) ?> RADIO</span>
    </div>
    <div class="stat-card purple">
        <div class="stat-label">Usuarios</div>
        <div class="stat-value"><?= $totalUsuarios ?></div>
        <div class="stat-sub"><?= $totalDJ ?> cuentas DJ</div>
        <span class="stat-badge"><?= cpIcon('user', 14) ?> USERS</span>
    </div>
    <div class="stat-card red">
        <div class="stat-label">Almacenamiento</div>
        <div class="stat-value"><?= formatBytes($totalStorage) ?></div>
        <div class="stat-sub"><?= $totalCanciones ?> canciones, <?= $totalAnuncios ?> anuncios</div>
        <span class="stat-badge"><?= cpIcon('save', 14) ?> DISCO</span>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/layout_footer.php'; ?>
