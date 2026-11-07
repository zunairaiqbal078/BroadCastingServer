<?php
/**
 * CastPanel v2.4 - Admin: Estadísticas de Radio (En Vivo)
 * Dashboard en tiempo real de todas las estaciones
 */
$pageTitle = 'Estadísticas En Vivo';
$pageIcon = 'activity';
$isAdmin = true;
require_once __DIR__ . '/../includes/layout_header.php';
requireRole('admin');

$db = getDB();

// Gather live data
$stationsLive = [];
$totalListeners = 0;
$totalOnline = 0;
$totalOffline = 0;
$maxListenersStation = ['nombre' => '-', 'listeners' => 0];

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

        if ($isOnline) {
            $totalOnline++;
        } else {
            $totalOffline++;
        }
        $totalListeners += $listeners;

        if ($listeners > $maxListenersStation['listeners']) {
            $maxListenersStation = ['nombre' => $st['nombre'], 'listeners' => $listeners];
        }

        $stationsLive[] = [
            'id' => $st['id'],
            'nombre' => $st['nombre'],
            'slug' => $st['slug'],
            'username' => $st['username'] ?? 'N/A',
            'server_type' => $st['server_type'],
            'port' => $st['puerto_stream'],
            'online' => $isOnline,
            'listeners' => $listeners,
            'max_listeners' => $st['max_oyentes'] ?? 0,
            'peak' => $peak,
            'bitrate' => $st['bitrate'] ?: ($status['bitrate'] ?? 0),
            'title' => $status['title'] ?? '',
            'genre' => $status['genre'] ?? '',
            'source' => $status['source'] ?? '',
        ];
    }
} catch (\Throwable $e) {
    // Log error for debugging but don't crash the page
    error_log("CastPanel live_stats error: " . $e->getMessage());
}

// Sort: online first, then by listeners desc
usort($stationsLive, function($a, $b) {
    if ($a['online'] !== $b['online']) return $b['online'] - $a['online'];
    return $b['listeners'] - $a['listeners'];
});

$typeLabels = [
    'shoutcast_v2' => 'SC v2',
    'shoutcast_v25' => 'SC v2.5',
    'shoutcast_v1' => 'SC v1',
    'icecast' => 'Icecast',
];
?>

<!-- Auto-refresh -->
<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:1rem;">
    <div style="font-size:.85rem;color:#666;">
        <?= cpIcon('activity', 14) ?> Última actualización: <strong><?= date('H:i:s') ?></strong>
    </div>
    <div style="display:flex;gap:.5rem;align-items:center;">
        <label style="font-size:.85rem;color:#666;">Auto-refresh:</label>
        <select id="refreshInterval" onchange="setRefresh()" style="padding:.3rem .5rem;border-radius:4px;border:1px solid #ddd;">
            <option value="0">Desactivado</option>
            <option value="10" selected>10 seg</option>
            <option value="30">30 seg</option>
            <option value="60">60 seg</option>
        </select>
        <button onclick="location.reload()" class="btn btn-sm btn-primary"><?= cpIcon('refresh', 14) ?> Refrescar</button>
    </div>
</div>

<script>
let refreshTimer = null;
function setRefresh() {
    if (refreshTimer) clearInterval(refreshTimer);
    const val = parseInt(document.getElementById('refreshInterval').value);
    if (val > 0) {
        refreshTimer = setInterval(() => location.reload(), val * 1000);
    }
}
setRefresh();
</script>

<!-- Summary Cards -->
<div class="stats-grid" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:1rem;margin-bottom:1.5rem;">
    <div class="card" style="text-align:center;padding:1.5rem;">
        <div style="font-size:2rem;font-weight:700;color:var(--primary);"><?= $totalListeners ?></div>
        <div style="color:#666;font-size:.85rem;">Oyentes Ahora</div>
    </div>
    <div class="card" style="text-align:center;padding:1.5rem;">
        <div style="font-size:2rem;font-weight:700;color:#28a745;"><?= $totalOnline ?></div>
        <div style="color:#666;font-size:.85rem;">Estaciones Online</div>
    </div>
    <div class="card" style="text-align:center;padding:1.5rem;">
        <div style="font-size:2rem;font-weight:700;color:#dc3545;"><?= $totalOffline ?></div>
        <div style="color:#666;font-size:.85rem;">Estaciones Offline</div>
    </div>
    <div class="card" style="text-align:center;padding:1.5rem;">
        <div style="font-size:1.2rem;font-weight:700;color:#fd7e14;"><?= htmlspecialchars($maxListenersStation['nombre']) ?></div>
        <div style="color:#666;font-size:.85rem;">Más Popular (<?= $maxListenersStation['listeners'] ?> oyentes)</div>
    </div>
</div>

<!-- Live Station Cards -->
<div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(350px,1fr));gap:1rem;margin-bottom:1.5rem;">
    <?php foreach ($stationsLive as $sl): ?>
    <div class="card" style="border-left:4px solid <?= $sl['online'] ? '#28a745' : '#dc3545' ?>;">
        <div class="card-body" style="padding:1rem;">
            <div style="display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:.8rem;">
                <div>
                    <strong style="font-size:1.05rem;"><?= htmlspecialchars($sl['nombre']) ?></strong>
                    <div style="font-size:.8rem;color:#999;"><?= htmlspecialchars($sl['username']) ?> · <?= $typeLabels[$sl['server_type']] ?? $sl['server_type'] ?> · Puerto <?= $sl['port'] ?></div>
                </div>
                <span class="badge badge-<?= $sl['online'] ? 'success' : 'danger' ?>">
                    <?= $sl['online'] ? '● ONLINE' : '○ OFFLINE' ?>
                </span>
            </div>

            <?php if ($sl['online']): ?>
            <!-- Listener bar -->
            <?php
                $listPct = $sl['max_listeners'] > 0 ? round($sl['listeners'] / $sl['max_listeners'] * 100, 1) : 0;
                $barColor = $listPct > 90 ? '#dc3545' : ($listPct > 70 ? '#fd7e14' : '#28a745');
            ?>
            <div style="margin-bottom:.8rem;">
                <div style="display:flex;justify-content:space-between;font-size:.8rem;margin-bottom:.3rem;">
                    <span>Oyentes: <strong><?= $sl['listeners'] ?></strong><?php if ($sl['max_listeners']): ?> / <?= $sl['max_listeners'] ?><?php endif; ?></span>
                    <span>Peak: <?= $sl['peak'] ?></span>
                </div>
                <div style="background:#e9ecef;border-radius:4px;height:6px;">
                    <div style="width:<?= min($listPct, 100) ?>%;height:100%;background:<?= $barColor ?>;border-radius:4px;"></div>
                </div>
            </div>

            <div style="font-size:.8rem;color:#666;">
                <?php if ($sl['title']): ?>
                <div style="margin-bottom:.3rem;">🎵 <em><?= htmlspecialchars($sl['title']) ?></em></div>
                <?php endif; ?>
                <div>Bitrate: <?= $sl['bitrate'] ?> kbps</div>
            </div>
            <?php else: ?>
            <div style="text-align:center;padding:.5rem;color:#999;font-size:.85rem;">
                Estación no está transmitiendo
            </div>
            <?php endif; ?>
        </div>
    </div>
    <?php endforeach; ?>
</div>

<?php if (empty($stationsLive)): ?>
<div class="card">
    <div class="card-body" style="text-align:center;padding:3rem;color:#999;">
        <p>No hay estaciones activas</p>
    </div>
</div>
<?php endif; ?>

<?php require_once __DIR__ . '/../includes/layout_footer.php'; ?>
