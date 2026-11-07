<?php
/**
 * CastPanel v2.4 - Admin: Stream / Source List
 * Lista todas las fuentes de streaming activas (sources/mounts)
 */
$pageTitle = 'Stream / Source List';
$pageIcon = 'signal';
$isAdmin = true;
require_once __DIR__ . '/../includes/layout_header.php';
requireRole('admin');

$db = getDB();

// Collect all active streams/sources
$streams = [];
try {
    $rows = $db->query("
        SELECT e.id, e.nombre, e.slug, e.server_type, e.puerto_stream, e.activa,
               e.icecast_mount, e.icecast_port, e.icecast_password,
               e.codec, e.bitrate, e.samplerate,
               u.username
        FROM estaciones e
        LEFT JOIN usuarios u ON e.usuario_id = u.id
        ORDER BY e.activa DESC, e.nombre ASC
    ")->fetchAll();

    foreach ($rows as $r) {
        $streamInfo = [
            'station' => $r,
            'status' => null,
            'source_ip' => 'N/A',
            'uptime' => 'N/A',
            'listeners' => 0,
            'bitrate_live' => $r['bitrate'] ?? 128,
        ];
        try {
            $status = getStreamStatus($r);
            $streamInfo['status'] = $status;
            $streamInfo['listeners'] = $status['listeners'] ?? 0;
            $streamInfo['source_ip'] = $status['source_ip'] ?? 'N/A';
            $streamInfo['uptime'] = $status['uptime'] ?? 'N/A';
            $streamInfo['bitrate_live'] = $status['bitrate'] ?? $r['bitrate'];
        } catch (\Throwable $e) {}
        $streams[] = $streamInfo;
    }
} catch (PDOException $e) {
    $streams = [];
}

$activeCount = 0;
foreach ($streams as $s) {
    if ($s['station']['activa']) $activeCount++;
}
?>

<div class="stats-grid" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:1rem;margin-bottom:1.5rem;">
    <div class="card" style="text-align:center;padding:1.2rem;">
        <div style="font-size:1.8rem;font-weight:700;color:var(--primary);"><?= count($streams) ?></div>
        <div style="color:#666;font-size:.85rem;">Total Streams</div>
    </div>
    <div class="card" style="text-align:center;padding:1.2rem;">
        <div style="font-size:1.8rem;font-weight:700;color:#28a745;"><?= $activeCount ?></div>
        <div style="color:#666;font-size:.85rem;">En Línea</div>
    </div>
    <div class="card" style="text-align:center;padding:1.2rem;">
        <div style="font-size:1.8rem;font-weight:700;color:#dc3545;"><?= count($streams) - $activeCount ?></div>
        <div style="color:#666;font-size:.85rem;">Offline</div>
    </div>
</div>

<div class="card">
    <div class="card-header">
        <h3><?= cpIcon('signal', 18) ?> Todas las Fuentes de Streaming</h3>
    </div>
    <div class="card-body" style="padding:0;">
        <div style="overflow-x:auto;">
        <table class="table" style="margin:0;">
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Estación</th>
                    <th>Usuario</th>
                    <th>Tipo Servidor</th>
                    <th>Puerto</th>
                    <th>Mount / SID</th>
                    <th>Codec</th>
                    <th>Bitrate</th>
                    <th>Oyentes</th>
                    <th>Source IP</th>
                    <th>Estado</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($streams)): ?>
                <tr><td colspan="11" style="text-align:center;padding:2rem;color:#999;">No hay streams registrados</td></tr>
                <?php else: ?>
                <?php foreach ($streams as $s):
                    $e = $s['station'];
                    $isShoutcast = function_exists('isShoutcastType') ? isShoutcastType($e['server_type'] ?? '') : false;
                ?>
                <tr>
                    <td><?= $e['id'] ?></td>
                    <td><strong><?= htmlspecialchars($e['nombre']) ?></strong></td>
                    <td><?= htmlspecialchars($e['username'] ?? 'N/A') ?></td>
                    <td><span class="badge badge-info"><?= htmlspecialchars($e['server_type'] ?? 'icecast') ?></span></td>
                    <td><?= $e['puerto_stream'] ?? '-' ?></td>
                    <td><?= $isShoutcast ? 'SID 1' : htmlspecialchars($e['icecast_mount'] ?? '/live') ?></td>
                    <td><?= strtoupper($e['codec'] ?? 'MP3') ?></td>
                    <td><?= $s['bitrate_live'] ?> kbps</td>
                    <td><strong><?= $s['listeners'] ?></strong></td>
                    <td><code style="font-size:.8rem;"><?= htmlspecialchars($s['source_ip']) ?></code></td>
                    <td>
                        <?php if ($e['activa']): ?>
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
