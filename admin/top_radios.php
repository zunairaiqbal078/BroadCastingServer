<?php
/**
 * CastPanel v2.4 - Admin: Top 50 Radios
 * Ranking de estaciones por oyentes pico y oyentes actuales
 */
$pageTitle = 'Top 50 Radios';
$pageIcon = 'trending-up';
$isAdmin = true;
require_once __DIR__ . '/../includes/layout_header.php';
requireRole('admin');

$db = getDB();

// Fetch all stations with live listener counts
$stations = [];
try {
    $rows = $db->query("
        SELECT e.id, e.nombre, e.slug, e.server_type, e.puerto_stream, e.activa,
               e.icecast_mount, e.icecast_port,
               u.username,
               p.nombre AS paquete_nombre, p.max_oyentes
        FROM estaciones e
        LEFT JOIN usuarios u ON e.usuario_id = u.id
        LEFT JOIN paquetes p ON e.paquete_id = p.id
        ORDER BY e.nombre ASC
    ")->fetchAll();

    foreach ($rows as $r) {
        $listeners = 0;
        $peakListeners = 0;
        $currentSong = 'N/A';
        try {
            $status = getStreamStatus($r);
            $listeners = $status['listeners'] ?? 0;
            $peakListeners = $status['peak_listeners'] ?? $listeners;
            $currentSong = $status['current_song'] ?? 'N/A';
        } catch (\Throwable $e) {}

        // Also check peak from log
        try {
            $peak = getPeakListeners($r['id']);
            if ($peak > $peakListeners) $peakListeners = $peak;
        } catch (\Throwable $e) {}

        $r['listeners'] = $listeners;
        $r['peak_listeners'] = $peakListeners;
        $r['current_song'] = $currentSong;
        $stations[] = $r;
    }

    // Sort by current listeners DESC, then peak DESC
    usort($stations, function($a, $b) {
        if ($b['listeners'] !== $a['listeners']) return $b['listeners'] - $a['listeners'];
        return $b['peak_listeners'] - $a['peak_listeners'];
    });

    // Top 50
    $stations = array_slice($stations, 0, 50);
} catch (PDOException $e) {
    $stations = [];
}

$totalListeners = array_sum(array_column($stations, 'listeners'));
?>

<div class="stats-grid" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:1rem;margin-bottom:1.5rem;">
    <div class="card" style="text-align:center;padding:1.5rem;">
        <div style="font-size:2rem;font-weight:700;color:var(--primary);"><?= count($stations) ?></div>
        <div style="color:#666;font-size:.85rem;">Estaciones</div>
    </div>
    <div class="card" style="text-align:center;padding:1.5rem;">
        <div style="font-size:2rem;font-weight:700;color:#28a745;"><?= $totalListeners ?></div>
        <div style="color:#666;font-size:.85rem;">Oyentes Activos</div>
    </div>
    <div class="card" style="text-align:center;padding:1.5rem;">
        <div style="font-size:2rem;font-weight:700;color:#fd7e14;"><?= !empty($stations) ? $stations[0]['listeners'] : 0 ?></div>
        <div style="color:#666;font-size:.85rem;">Máx. en una Radio</div>
    </div>
</div>

<div class="card">
    <div class="card-header">
        <h3><?= cpIcon('trending-up', 18) ?> Ranking — Top 50 Radios por Oyentes</h3>
    </div>
    <div class="card-body" style="padding:0;">
        <div style="overflow-x:auto;">
        <table class="table" style="margin:0;">
            <thead>
                <tr>
                    <th>#</th>
                    <th>Estación</th>
                    <th>Usuario</th>
                    <th>Tipo</th>
                    <th>Oyentes</th>
                    <th>Pico</th>
                    <th>Máx. Permitidos</th>
                    <th>Canción Actual</th>
                    <th>Estado</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($stations)): ?>
                <tr><td colspan="9" style="text-align:center;padding:2rem;color:#999;">No hay datos disponibles</td></tr>
                <?php else: ?>
                <?php foreach ($stations as $i => $s): ?>
                <tr>
                    <td><strong><?= $i + 1 ?></strong></td>
                    <td><?= htmlspecialchars($s['nombre']) ?></td>
                    <td><?= htmlspecialchars($s['username'] ?? 'N/A') ?></td>
                    <td><span class="badge badge-info"><?= htmlspecialchars($s['server_type'] ?? 'icecast') ?></span></td>
                    <td><strong style="color:#28a745;"><?= $s['listeners'] ?></strong></td>
                    <td><?= $s['peak_listeners'] ?></td>
                    <td><?= $s['max_oyentes'] ?? '∞' ?></td>
                    <td style="max-width:200px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" title="<?= htmlspecialchars($s['current_song']) ?>">
                        <?= htmlspecialchars($s['current_song']) ?>
                    </td>
                    <td>
                        <?php if ($s['activa']): ?>
                            <span class="badge badge-success">Activa</span>
                        <?php else: ?>
                            <span class="badge badge-danger">Inactiva</span>
                        <?php endif; ?>
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
