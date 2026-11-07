<?php
/**
 * CastPanel v2.4 - Admin: Uso de Ancho de Banda
 * Monitoreo de tráfico de red por estación y global
 */
$pageTitle = 'Uso de Ancho de Banda';
$pageIcon = 'activity';
$isAdmin = true;
require_once __DIR__ . '/../includes/layout_header.php';
requireRole('admin');

$db = getDB();

// Network interface stats from /proc/net/dev
$netStats = [];
$netData = @file_get_contents('/proc/net/dev');
if ($netData) {
    $lines = explode("\n", $netData);
    foreach ($lines as $line) {
        if (preg_match('/^\s*(\w+):\s*(\d+)\s+\d+\s+\d+\s+\d+\s+\d+\s+\d+\s+\d+\s+\d+\s+(\d+)/', $line, $m)) {
            $iface = $m[1];
            if ($iface === 'lo') continue;
            $netStats[] = [
                'interface' => $iface,
                'rx_bytes' => (int)$m[2],
                'tx_bytes' => (int)$m[3],
            ];
        }
    }
}

$totalRx = array_sum(array_column($netStats, 'rx_bytes'));
$totalTx = array_sum(array_column($netStats, 'tx_bytes'));
$totalBw = $totalRx + $totalTx;

// Per-station estimated bandwidth (listeners * bitrate)
$stationBw = [];
try {
    $stations = $db->query("
        SELECT e.id, e.nombre, e.slug, e.server_type, e.puerto_stream, e.bitrate, u.username
        FROM estaciones e
        LEFT JOIN usuarios u ON e.usuario_id = u.id
        ORDER BY e.nombre
    ")->fetchAll();

    foreach ($stations as $st) {
        $status = getStreamStatus($st);
        $listeners = $status['listeners'] ?? 0;
        $bitrate = $st['bitrate'] ?: ($status['bitrate'] ?? 128);
        // Estimated bandwidth: listeners * bitrate (kbps) converted to bytes/sec
        $bwEstimate = $listeners * ($bitrate * 1000 / 8);
        // Estimated daily transfer
        $dailyEstimate = $bwEstimate * 3600 * 24;

        $stationBw[] = [
            'id' => $st['id'],
            'nombre' => $st['nombre'],
            'username' => $st['username'] ?? 'N/A',
            'listeners' => $listeners,
            'bitrate' => $bitrate,
            'bw_per_sec' => $bwEstimate,
            'daily_estimate' => $dailyEstimate,
            'online' => ($status['status'] ?? '') === 'online',
        ];
    }
    // Sort by current bandwidth descending
    usort($stationBw, fn($a, $b) => $b['bw_per_sec'] - $a['bw_per_sec']);
} catch (PDOException $e) {}

$totalListeners = array_sum(array_column($stationBw, 'listeners'));
$totalStreamBw = array_sum(array_column($stationBw, 'bw_per_sec'));
?>

<!-- Overview -->
<div class="stats-grid" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:1rem;margin-bottom:1.5rem;">
    <div class="card" style="text-align:center;padding:1.5rem;">
        <div style="font-size:1.6rem;font-weight:700;color:var(--primary);"><?= formatBytes($totalRx) ?></div>
        <div style="color:#666;font-size:.85rem;">Total Recibido (RX)</div>
    </div>
    <div class="card" style="text-align:center;padding:1.5rem;">
        <div style="font-size:1.6rem;font-weight:700;color:#28a745;"><?= formatBytes($totalTx) ?></div>
        <div style="color:#666;font-size:.85rem;">Total Enviado (TX)</div>
    </div>
    <div class="card" style="text-align:center;padding:1.5rem;">
        <div style="font-size:1.6rem;font-weight:700;color:#fd7e14;"><?= formatBytes($totalStreamBw) ?>/s</div>
        <div style="color:#666;font-size:.85rem;">Ancho de Banda Streaming</div>
    </div>
    <div class="card" style="text-align:center;padding:1.5rem;">
        <div style="font-size:1.6rem;font-weight:700;color:#6366f1;"><?= $totalListeners ?></div>
        <div style="color:#666;font-size:.85rem;">Oyentes Totales Ahora</div>
    </div>
</div>

<!-- Network Interfaces -->
<div class="card" style="margin-bottom:1.5rem;">
    <div class="card-header">
        <h3><?= cpIcon('activity', 18) ?> Interfaces de Red</h3>
    </div>
    <div class="card-body" style="padding:0;">
        <table class="table" style="margin:0;">
            <thead>
                <tr><th>Interfaz</th><th>Recibido (RX)</th><th>Enviado (TX)</th><th>Total</th></tr>
            </thead>
            <tbody>
                <?php if (empty($netStats)): ?>
                <tr><td colspan="4" style="text-align:center;padding:2rem;color:#999;">No se pudo leer estadísticas de red</td></tr>
                <?php else: ?>
                <?php foreach ($netStats as $ns): ?>
                <tr>
                    <td><strong><code><?= htmlspecialchars($ns['interface']) ?></code></strong></td>
                    <td><?= formatBytes($ns['rx_bytes']) ?></td>
                    <td><?= formatBytes($ns['tx_bytes']) ?></td>
                    <td><?= formatBytes($ns['rx_bytes'] + $ns['tx_bytes']) ?></td>
                </tr>
                <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Per-Station Bandwidth -->
<div class="card">
    <div class="card-header">
        <h3><?= cpIcon('radio', 18) ?> Ancho de Banda por Estación</h3>
    </div>
    <div class="card-body" style="padding:0;">
        <table class="table" style="margin:0;">
            <thead>
                <tr>
                    <th>Estación</th>
                    <th>Usuario</th>
                    <th>Estado</th>
                    <th>Oyentes</th>
                    <th>Bitrate</th>
                    <th>BW Actual</th>
                    <th>Est. Diario</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($stationBw)): ?>
                <tr><td colspan="7" style="text-align:center;padding:2rem;color:#999;">Sin estaciones</td></tr>
                <?php else: ?>
                <?php foreach ($stationBw as $sb): ?>
                <tr>
                    <td><strong><?= htmlspecialchars($sb['nombre']) ?></strong></td>
                    <td><?= htmlspecialchars($sb['username']) ?></td>
                    <td>
                        <span class="badge badge-<?= $sb['online'] ? 'success' : 'secondary' ?>">
                            <?= $sb['online'] ? 'Online' : 'Offline' ?>
                        </span>
                    </td>
                    <td><?= $sb['listeners'] ?></td>
                    <td><?= $sb['bitrate'] ?> kbps</td>
                    <td><?= $sb['bw_per_sec'] > 0 ? formatBytes($sb['bw_per_sec']) . '/s' : '-' ?></td>
                    <td><?= $sb['daily_estimate'] > 0 ? formatBytes($sb['daily_estimate']) : '-' ?></td>
                </tr>
                <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<div style="margin-top:1rem;padding:1rem;background:#f0f9ff;border:1px solid #bae6fd;border-radius:8px;">
    <p style="margin:0;font-size:.85rem;color:#0369a1;">
        <?= cpIcon('info', 14) ?> <strong>Nota:</strong> El ancho de banda por estación es una estimación basada en oyentes actuales × bitrate. Los datos de interfaces de red son acumulados desde el último reinicio del servidor.
    </p>
</div>

<?php require_once __DIR__ . '/../includes/layout_footer.php'; ?>
