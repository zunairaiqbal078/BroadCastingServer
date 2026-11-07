<?php
/**
 * CastPanel v2.4 - Admin: Estadísticas de Radio Básicas
 * Resumen estadístico de todas las estaciones
 */
$pageTitle = 'Estadísticas de Radio';
$pageIcon = 'bar-chart';
$isAdmin = true;
require_once __DIR__ . '/../includes/layout_header.php';
requireRole('admin');

$db = getDB();

// Station counts
$totalStations = 0;
$activeStations = 0;
$suspendedStations = 0;
$stationsByType = [];
try {
    $totalStations = (int)$db->query("SELECT COUNT(*) FROM estaciones")->fetchColumn();
    $activeStations = (int)$db->query("SELECT COUNT(*) FROM estaciones WHERE activa = 1")->fetchColumn();
    $suspendedStations = (int)$db->query("SELECT COUNT(*) FROM estaciones WHERE activa = 0")->fetchColumn();

    $typeRows = $db->query("SELECT server_type, COUNT(*) as cnt FROM estaciones GROUP BY server_type")->fetchAll();
    foreach ($typeRows as $tr) {
        $stationsByType[$tr['server_type']] = (int)$tr['cnt'];
    }
} catch (PDOException $e) {}

// User counts
$totalUsers = 0;
$totalResellers = 0;
try {
    $totalUsers = (int)$db->query("SELECT COUNT(*) FROM usuarios WHERE rol = 'cliente'")->fetchColumn();
    $totalResellers = (int)$db->query("SELECT COUNT(*) FROM usuarios WHERE rol = 'reseller'")->fetchColumn();
} catch (PDOException $e) {}

// Package counts
$totalPackages = 0;
try {
    $totalPackages = (int)$db->query("SELECT COUNT(*) FROM paquetes")->fetchColumn();
} catch (PDOException $e) {}

// Live stats
$onlineCount = 0;
$totalListeners = 0;
$peakListeners = 0;
$stationDetails = [];

try {
    $stations = $db->query("
        SELECT e.id, e.nombre, e.slug, e.server_type, e.puerto_stream, e.bitrate, e.activa,
               e.icecast_port, e.icecast_password, e.shoutcast_sid, e.icecast_mount,
               u.username,
               p.max_oyentes
        FROM estaciones e
        LEFT JOIN usuarios u ON e.usuario_id = u.id
        LEFT JOIN paquetes p ON e.paquete_id = p.id
        WHERE e.activa = 1
        ORDER BY e.nombre
    ")->fetchAll();

    foreach ($stations as $st) {
        $status = getStreamStatus($st);
        $isOnline = ($status['status'] ?? '') === 'online';
        $listeners = $status['listeners'] ?? 0;
        $peak = getPeakListeners($st['id']);

        if ($isOnline) $onlineCount++;
        $totalListeners += $listeners;
        $peakListeners += $peak;

        $stationDetails[] = [
            'id' => $st['id'],
            'nombre' => $st['nombre'],
            'username' => $st['username'] ?? 'N/A',
            'server_type' => $st['server_type'],
            'bitrate' => $st['bitrate'] ?: ($status['bitrate'] ?? 0),
            'listeners' => $listeners,
            'max_listeners' => $st['max_oyentes'] ?? 0,
            'peak' => $peak,
            'online' => $isOnline,
            'title' => $status['title'] ?? '',
        ];
    }
} catch (\Throwable $e) {}

// Sort by listeners desc
usort($stationDetails, fn($a, $b) => $b['listeners'] - $a['listeners']);

$typeLabels = [
    'shoutcast_v2' => 'SHOUTcast v2',
    'shoutcast_v25' => 'SHOUTcast v2.5',
    'shoutcast_v1' => 'SHOUTcast v1',
    'icecast' => 'Icecast',
];
?>

<!-- Overview Cards -->
<div class="stats-grid" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:1rem;margin-bottom:1.5rem;">
    <div class="card" style="text-align:center;padding:1.2rem;">
        <div style="font-size:1.8rem;font-weight:700;color:var(--primary);"><?= $totalStations ?></div>
        <div style="color:#666;font-size:.85rem;">Estaciones Total</div>
    </div>
    <div class="card" style="text-align:center;padding:1.2rem;">
        <div style="font-size:1.8rem;font-weight:700;color:#28a745;"><?= $onlineCount ?></div>
        <div style="color:#666;font-size:.85rem;">Online Ahora</div>
    </div>
    <div class="card" style="text-align:center;padding:1.2rem;">
        <div style="font-size:1.8rem;font-weight:700;color:#fd7e14;"><?= $totalListeners ?></div>
        <div style="color:#666;font-size:.85rem;">Oyentes Totales</div>
    </div>
    <div class="card" style="text-align:center;padding:1.2rem;">
        <div style="font-size:1.8rem;font-weight:700;color:#dc3545;"><?= $peakListeners ?></div>
        <div style="color:#666;font-size:.85rem;">Peak Total</div>
    </div>
    <div class="card" style="text-align:center;padding:1.2rem;">
        <div style="font-size:1.8rem;font-weight:700;color:#6366f1;"><?= $totalUsers ?></div>
        <div style="color:#666;font-size:.85rem;">Clientes</div>
    </div>
    <div class="card" style="text-align:center;padding:1.2rem;">
        <div style="font-size:1.8rem;font-weight:700;color:#0ea5e9;"><?= $totalResellers ?></div>
        <div style="color:#666;font-size:.85rem;">Revendedores</div>
    </div>
</div>

<!-- Station Types -->
<div class="card" style="margin-bottom:1.5rem;">
    <div class="card-header">
        <h3><?= cpIcon('layers', 18) ?> Distribución por Tipo de Servidor</h3>
    </div>
    <div class="card-body">
        <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(200px,1fr));gap:1rem;">
            <?php foreach ($stationsByType as $type => $count):
                $pct = $totalStations > 0 ? round($count / $totalStations * 100, 1) : 0;
                $label = $typeLabels[$type] ?? $type;
            ?>
            <div style="padding:1rem;background:#f8f9fa;border-radius:8px;">
                <div style="display:flex;justify-content:space-between;margin-bottom:.5rem;">
                    <strong><?= htmlspecialchars($label) ?></strong>
                    <span><?= $count ?> (<?= $pct ?>%)</span>
                </div>
                <div style="background:#e9ecef;border-radius:3px;height:8px;">
                    <div style="width:<?= $pct ?>%;height:100%;background:var(--primary);border-radius:3px;"></div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>

        <?php if (empty($stationsByType)): ?>
        <p style="text-align:center;color:#999;padding:1rem;">Sin datos de tipos</p>
        <?php endif; ?>
    </div>
</div>

<!-- Status Summary -->
<div class="card" style="margin-bottom:1.5rem;">
    <div class="card-header">
        <h3><?= cpIcon('bar-chart', 18) ?> Estado de Cuentas</h3>
    </div>
    <div class="card-body">
        <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(180px,1fr));gap:1rem;">
            <div style="padding:1rem;background:#dcfce7;border-radius:8px;text-align:center;">
                <div style="font-size:1.5rem;font-weight:700;color:#16a34a;"><?= $activeStations ?></div>
                <div style="color:#166534;">Activas</div>
            </div>
            <div style="padding:1rem;background:#fef3c7;border-radius:8px;text-align:center;">
                <div style="font-size:1.5rem;font-weight:700;color:#d97706;"><?= $suspendedStations ?></div>
                <div style="color:#92400e;">Suspendidas</div>
            </div>
            <div style="padding:1rem;background:#e0f2fe;border-radius:8px;text-align:center;">
                <div style="font-size:1.5rem;font-weight:700;color:#0284c7;"><?= $totalPackages ?></div>
                <div style="color:#075985;">Paquetes</div>
            </div>
        </div>
    </div>
</div>

<!-- All Stations Detail -->
<div class="card">
    <div class="card-header">
        <h3><?= cpIcon('radio', 18) ?> Detalle de Estaciones (<?= count($stationDetails) ?>)</h3>
    </div>
    <div class="card-body" style="padding:0;">
        <div style="overflow-x:auto;">
        <table class="table" style="margin:0;">
            <thead>
                <tr>
                    <th>Estación</th>
                    <th>Usuario</th>
                    <th>Tipo</th>
                    <th>Estado</th>
                    <th>Oyentes</th>
                    <th>Peak</th>
                    <th>Bitrate</th>
                    <th>Canción Actual</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($stationDetails)): ?>
                <tr><td colspan="8" style="text-align:center;padding:2rem;color:#999;">Sin estaciones activas</td></tr>
                <?php else: ?>
                <?php foreach ($stationDetails as $sd): ?>
                <tr>
                    <td><strong><?= htmlspecialchars($sd['nombre']) ?></strong></td>
                    <td><?= htmlspecialchars($sd['username']) ?></td>
                    <td><span class="badge badge-info"><?= htmlspecialchars($typeLabels[$sd['server_type']] ?? $sd['server_type']) ?></span></td>
                    <td>
                        <span class="badge badge-<?= $sd['online'] ? 'success' : 'secondary' ?>">
                            <?= $sd['online'] ? 'Online' : 'Offline' ?>
                        </span>
                    </td>
                    <td>
                        <?= $sd['listeners'] ?><?php if ($sd['max_listeners']): ?><small>/<?= $sd['max_listeners'] ?></small><?php endif; ?>
                    </td>
                    <td><?= $sd['peak'] ?></td>
                    <td><?= $sd['bitrate'] ?> kbps</td>
                    <td style="max-width:200px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">
                        <?= htmlspecialchars($sd['title'] ?: '-') ?>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/layout_footer.php'; ?>
