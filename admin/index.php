<?php
/**
 * CastPanel - Panel de Administración
 * Dashboard: resumen arriba, estaciones recientes, recursos del servidor y accesos rápidos
 */
$pageTitle = 'Panel de Administración';
$pageIcon = 'gear';
$isAdmin = true;
require_once __DIR__ . '/../includes/layout_header.php';
requireRole('admin');

$db = getDB();

$totalUsuarios = 0;
$totalEstaciones = 0;
$estacionesOnline = 0;
$totalCanciones = 0;
$totalAnuncios = 0;
$totalDJ = 0;
$oyentesTotales = 0;
$totalStorage = 0;
$recientes = [];
$_dbErrors = [];

try { $totalUsuarios = (int)$db->query("SELECT COUNT(*) FROM usuarios")->fetchColumn(); } catch (PDOException $e) { $_dbErrors[] = 'usuarios'; }
try { $totalCanciones = (int)$db->query("SELECT COUNT(*) FROM canciones")->fetchColumn(); } catch (PDOException $e) { $_dbErrors[] = 'canciones'; }
try { $totalAnuncios = (int)$db->query("SELECT COUNT(*) FROM anuncios")->fetchColumn(); } catch (PDOException $e) { $_dbErrors[] = 'anuncios'; }
try { $totalDJ = (int)$db->query("SELECT COUNT(*) FROM cuentas_dj")->fetchColumn(); } catch (PDOException $e) { $_dbErrors[] = 'cuentas_dj'; }
try { $totalStorage = (int)$db->query("SELECT COALESCE(SUM(tamano_bytes),0) FROM canciones")->fetchColumn(); } catch (PDOException $e) {}
try { $totalStorage += (int)$db->query("SELECT COALESCE(SUM(tamano_bytes),0) FROM anuncios")->fetchColumn(); } catch (PDOException $e) {}

// Stations: on-air status and listeners come from the stream server (30 s cache)
try {
    $estaciones = $db->query("SELECT e.*, u.username AS cliente FROM estaciones e LEFT JOIN usuarios u ON u.id = e.usuario_id ORDER BY e.id DESC")->fetchAll();
    $totalEstaciones = count($estaciones);
    foreach ($estaciones as $i => $e) {
        $st = !empty($e['activa']) ? getStreamStatusCached($e) : ['online' => false, 'listeners' => 0];
        $estaciones[$i]['_online'] = !empty($e['activa']) && !empty($st['online']);
        $estaciones[$i]['_oyentes'] = (int)($st['listeners'] ?? 0);
        if ($estaciones[$i]['_online']) $estacionesOnline++;
        $oyentesTotales += $estaciones[$i]['_oyentes'];
    }
    $recientes = array_slice($estaciones, 0, 6);
} catch (PDOException $e) { $_dbErrors[] = 'estaciones'; }

// Icecast mounts not tied to a station row still count as listeners
if ($oyentesTotales === 0) {
    try { foreach (getIcecastListeners() as $m) $oyentesTotales += (int)$m['oyentes']; } catch (Exception $e) {}
}

// Server resources
$cores = 1;
if (is_readable('/proc/cpuinfo')) {
    $cores = max(1, preg_match_all('/^processor\s*:/m', (string)@file_get_contents('/proc/cpuinfo')));
}
$load = @sys_getloadavg();
$cpuPct = $load ? min(100, round($load[0] / $cores * 100)) : 0;

$memTotal = $memUsed = 0;
if (is_readable('/proc/meminfo')) {
    $mi = (string)@file_get_contents('/proc/meminfo');
    if (preg_match('/^MemTotal:\s+(\d+)/m', $mi, $a) && preg_match('/^MemAvailable:\s+(\d+)/m', $mi, $b)) {
        $memTotal = (int)$a[1] * 1024;
        $memUsed = $memTotal - (int)$b[1] * 1024;
    }
}
$memPct = $memTotal ? round($memUsed / $memTotal * 100) : 0;

$diskTotal = (int)@disk_total_space('/');
$diskUsed = $diskTotal ? $diskTotal - (int)@disk_free_space('/') : 0;
$diskPct = $diskTotal ? round($diskUsed / $diskTotal * 100) : 0;

$onlinePct = $totalEstaciones ? round($estacionesOnline / $totalEstaciones * 100) : 0;
$hostname = gethostname() ?: 'N/A';
$sslDomain = defined('SSL_DOMAIN') ? SSL_DOMAIN : $hostname;
$version = trim((string)@file_get_contents(BASE_PATH . '/VERSION'));

function cpDashBarColor(int $pct): string {
    return $pct >= 85 ? 'var(--danger)' : ($pct >= 65 ? 'var(--warning)' : 'var(--success)');
}
function cpDashServerLabel(string $t): string {
    return ['shoutcast_v2' => 'SHOUTcast v2', 'shoutcast_v25' => 'SHOUTcast v2.5', 'shoutcast' => 'SHOUTcast', 'icecast' => 'Icecast'][$t] ?? ucfirst($t);
}
?>

<style>
.cp-hero{display:flex;justify-content:space-between;align-items:center;gap:16px;flex-wrap:wrap;margin-bottom:22px}
.cp-hero h2{font-size:22px;font-weight:700;color:var(--text-primary);margin-bottom:4px}
.cp-hero p{font-size:13px;color:var(--text-secondary)}
.cp-chips{display:flex;gap:8px;flex-wrap:wrap;margin-top:10px}
.cp-chip{display:inline-flex;align-items:center;gap:6px;padding:4px 10px;border-radius:20px;background:var(--card-bg);border:1px solid var(--border);font-size:12px;color:var(--text-secondary)}
.cp-hero-actions{display:flex;gap:8px;flex-wrap:wrap}
.cp-stats{display:grid;grid-template-columns:repeat(auto-fit,minmax(210px,1fr));gap:16px;margin-bottom:22px}
.cp-stats .stat-card{box-shadow:var(--shadow-md);transition:transform .2s,box-shadow .2s;text-decoration:none;display:block}
.cp-stats a.stat-card:hover{transform:translateY(-3px);box-shadow:var(--shadow-lg);color:#fff}
.cp-mini-bar{height:6px;border-radius:6px;background:rgba(255,255,255,.25);margin-top:12px;overflow:hidden}
.cp-mini-bar span{display:block;height:100%;background:#fff;border-radius:6px}
.cp-row{display:grid;grid-template-columns:minmax(0,2fr) minmax(0,1fr);gap:16px;margin-bottom:22px}
@media (max-width:1000px){.cp-row{grid-template-columns:1fr}}
.cp-row .card{margin-bottom:0}
.cp-table{width:100%;border-collapse:collapse;font-size:13px}
.cp-table th{text-align:left;font-size:11px;text-transform:uppercase;letter-spacing:.5px;color:var(--text-secondary);font-weight:600;padding:10px 16px;border-bottom:1px solid var(--border)}
.cp-table td{padding:12px 16px;border-bottom:1px solid var(--border);vertical-align:middle}
.cp-table tr:last-child td{border-bottom:0}
.cp-table tr:hover td{background:var(--hover)}
.cp-st-name{font-weight:600;color:var(--text-primary)}
.cp-st-sub{font-size:12px;color:var(--text-secondary)}
.cp-dot{display:inline-block;width:8px;height:8px;border-radius:50%;margin-right:6px;vertical-align:middle}
.cp-dot.on{background:var(--success);box-shadow:0 0 0 3px rgba(16,185,129,.2)}
.cp-dot.off{background:#9ca3af}
.cp-res{padding:18px 20px}
.cp-res-item{margin-bottom:18px}
.cp-res-item:last-child{margin-bottom:0}
.cp-res-head{display:flex;justify-content:space-between;font-size:13px;margin-bottom:6px}
.cp-res-head strong{color:var(--text-primary)}
.cp-res-head span{color:var(--text-secondary);font-size:12px}
.cp-bar{height:8px;border-radius:8px;background:var(--hover);overflow:hidden}
.cp-bar span{display:block;height:100%;border-radius:8px;transition:width .6s}
.cp-quick{display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:12px}
.cp-quick a{display:flex;flex-direction:column;align-items:center;gap:10px;padding:18px 12px;background:var(--card-bg);border:1px solid var(--border);border-radius:var(--radius);color:var(--text-primary);font-size:13px;font-weight:600;text-align:center;transition:var(--transition)}
.cp-quick a:hover{border-color:var(--primary);box-shadow:var(--shadow-md);transform:translateY(-2px);color:var(--primary)}
.cp-quick .qi{width:42px;height:42px;border-radius:10px;display:flex;align-items:center;justify-content:center;color:#fff}
.cp-section-title{font-size:16px;font-weight:700;margin-bottom:12px;color:var(--text-primary);display:flex;align-items:center;gap:8px}
.cp-empty{padding:32px;text-align:center;color:var(--text-secondary)}
</style>

<?php if (!empty($_dbErrors)): ?>
<div class="alert alert-warning"><?= cpIcon('warning', 16) ?> Algunas tablas no existen aún: <strong><?= implode(', ', $_dbErrors) ?></strong>. Ejecute la migración SQL.</div>
<?php endif; ?>

<!-- Bienvenida -->
<div class="cp-hero">
    <div>
        <h2>Bienvenido, <?= htmlspecialchars($_SESSION['user_username'] ?? 'admin') ?></h2>
        <p>Resumen de su servidor de radio en tiempo real</p>
        <div class="cp-chips">
            <span class="cp-chip"><?= cpIcon('server', 13) ?> <?= htmlspecialchars($sslDomain) ?></span>
            <?php if ($version !== ''): ?><span class="cp-chip">CastPanel <?= htmlspecialchars(stripos($version, 'v') === 0 ? $version : 'v' . $version) ?></span><?php endif; ?>
            <?php if ($load): ?><span class="cp-chip"><?= cpIcon('chart', 13) ?> <?= number_format($load[0], 2) ?> · <?= number_format($load[1], 2) ?> · <?= number_format($load[2], 2) ?></span><?php endif; ?>
        </div>
    </div>
    <div class="cp-hero-actions">
        <a href="<?= BASE_URL ?>/admin/estaciones.php" class="btn btn-outline"><?= cpIcon('list', 16) ?> Listar / Editar Radios</a>
        <a href="<?= BASE_URL ?>/admin/estaciones.php?action=new" class="btn btn-primary"><?= cpIcon('plus', 16) ?> Crear Radio</a>
    </div>
</div>

<!-- Tarjetas de resumen (arriba) -->
<div class="cp-stats">
    <div class="stat-card green">
        <div class="stat-label">Oyentes Totales</div>
        <div class="stat-value"><?= $oyentesTotales ?></div>
        <div class="stat-sub">En todas las estaciones</div>
        <span class="stat-badge"><?= cpIcon('headphones', 14) ?> LIVE</span>
    </div>
    <a href="<?= BASE_URL ?>/admin/estaciones.php" class="stat-card blue">
        <div class="stat-label">Estaciones</div>
        <div class="stat-value"><?= $totalEstaciones ?></div>
        <div class="stat-sub"><?= $estacionesOnline ?> online ahora</div>
        <div class="cp-mini-bar"><span style="width:<?= $onlinePct ?>%"></span></div>
        <span class="stat-badge"><?= cpIcon('radio', 14) ?> RADIO</span>
    </a>
    <div class="stat-card purple">
        <div class="stat-label">Usuarios</div>
        <div class="stat-value"><?= $totalUsuarios ?></div>
        <div class="stat-sub"><?= $totalDJ ?> cuentas DJ</div>
        <span class="stat-badge"><?= cpIcon('user', 14) ?> USERS</span>
    </div>
    <a href="<?= BASE_URL ?>/admin/disk_usage.php" class="stat-card orange">
        <div class="stat-label">Almacenamiento</div>
        <div class="stat-value"><?= formatBytes($totalStorage) ?></div>
        <div class="stat-sub"><?= $totalCanciones ?> canciones, <?= $totalAnuncios ?> anuncios</div>
        <span class="stat-badge"><?= cpIcon('save', 14) ?> DISCO</span>
    </a>
</div>

<!-- Estaciones recientes + Recursos del servidor -->
<div class="cp-row">
    <div class="card">
        <div class="card-header">
            <h3><?= cpIcon('radio', 18, 'icon') ?> Estaciones Recientes</h3>
            <a href="<?= BASE_URL ?>/admin/estaciones.php" class="btn btn-sm btn-outline">Ver todas</a>
        </div>
        <div class="card-body no-padding table-responsive">
            <?php if (!$recientes): ?>
                <div class="cp-empty">No hay estaciones registradas</div>
            <?php else: ?>
            <table class="cp-table">
                <thead><tr><th>Nombre</th><th>Tipo</th><th>Puerto</th><th>Oyentes</th><th>Estado</th></tr></thead>
                <tbody>
                <?php foreach ($recientes as $e): ?>
                    <tr>
                        <td>
                            <div class="cp-st-name"><?= htmlspecialchars($e['nombre']) ?></div>
                            <div class="cp-st-sub"><?= cpIcon('user', 11) ?> <?= htmlspecialchars($e['cliente'] ?? '-') ?></div>
                        </td>
                        <td><span class="badge badge-purple"><?= htmlspecialchars(cpDashServerLabel((string)($e['server_type'] ?? ''))) ?></span></td>
                        <td><?= (int)($e['puerto_stream'] ?: $e['icecast_port']) ?></td>
                        <td><?= cpIcon('headphones', 13) ?> <?= $e['_oyentes'] ?></td>
                        <td>
                            <?php if (empty($e['activa'])): ?>
                                <span class="badge badge-warning">Inactiva</span>
                            <?php elseif ($e['_online']): ?>
                                <span class="badge badge-success"><span class="cp-dot on"></span>Online</span>
                            <?php else: ?>
                                <span class="badge badge-danger"><span class="cp-dot off"></span>Offline</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <?php endif; ?>
        </div>
    </div>

    <div class="card">
        <div class="card-header">
            <h3><?= cpIcon('server', 18, 'icon') ?> Recursos del Servidor</h3>
            <a href="<?= BASE_URL ?>/admin/server_status.php" class="btn btn-sm btn-outline">Ver detalles</a>
        </div>
        <div class="cp-res">
            <div class="cp-res-item">
                <div class="cp-res-head"><strong>Carga de CPU</strong><span><?= $cpuPct ?>% · <?= $cores ?> núcleos</span></div>
                <div class="cp-bar"><span style="width:<?= $cpuPct ?>%;background:<?= cpDashBarColor($cpuPct) ?>"></span></div>
            </div>
            <div class="cp-res-item">
                <div class="cp-res-head"><strong>Memoria</strong><span><?= $memTotal ? formatBytes($memUsed, 1) . ' usado de ' . formatBytes($memTotal, 1) : 'N/A' ?></span></div>
                <div class="cp-bar"><span style="width:<?= $memPct ?>%;background:<?= cpDashBarColor($memPct) ?>"></span></div>
            </div>
            <div class="cp-res-item">
                <div class="cp-res-head"><strong>Disco</strong><span><?= $diskTotal ? formatBytes($diskUsed, 1) . ' usado de ' . formatBytes($diskTotal, 1) : 'N/A' ?></span></div>
                <div class="cp-bar"><span style="width:<?= $diskPct ?>%;background:<?= cpDashBarColor($diskPct) ?>"></span></div>
            </div>
            <div class="cp-res-item">
                <div class="cp-res-head"><strong>Estaciones</strong><span><?= $estacionesOnline ?> online ahora</span></div>
                <div class="cp-bar"><span style="width:<?= $onlinePct ?>%;background:var(--info)"></span></div>
            </div>
        </div>
    </div>
</div>

<!-- Accesos rápidos -->
<h2 class="cp-section-title"><?= cpIcon('zap', 18) ?> Accesos Rápidos</h2>
<div class="cp-quick">
    <a href="<?= BASE_URL ?>/admin/estaciones.php?action=new"><span class="qi" style="background:linear-gradient(135deg,#6366f1,#818cf8)"><?= cpIcon('plus', 20) ?></span>Crear Radio</a>
    <a href="<?= BASE_URL ?>/admin/estaciones.php"><span class="qi" style="background:linear-gradient(135deg,#0ea5e9,#38bdf8)"><?= cpIcon('list', 20) ?></span>Listar / Editar Radios</a>
    <a href="<?= BASE_URL ?>/admin/paquetes.php"><span class="qi" style="background:linear-gradient(135deg,#10b981,#34d399)"><?= cpIcon('package', 20) ?></span>Paquetes</a>
    <a href="<?= BASE_URL ?>/admin/server_status.php"><span class="qi" style="background:linear-gradient(135deg,#f59e0b,#fbbf24)"><?= cpIcon('signal', 20) ?></span>Estado del Servidor en Vivo</a>
    <a href="<?= BASE_URL ?>/admin/restart_services.php"><span class="qi" style="background:linear-gradient(135deg,#ef4444,#f87171)"><?= cpIcon('refresh', 20) ?></span>Reiniciar Servicios</a>
    <a href="<?= BASE_URL ?>/admin/configuracion.php"><span class="qi" style="background:linear-gradient(135deg,#8b5cf6,#a78bfa)"><?= cpIcon('gear', 20) ?></span>Configuración Global</a>
</div>

<?php require_once __DIR__ . '/../includes/layout_footer.php'; ?>
