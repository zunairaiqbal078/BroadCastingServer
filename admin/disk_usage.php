<?php
/**
 * CastPanel v2.4 - Admin: Uso de Disco / HDD
 * Monitorear el uso de disco por estación y directorio
 */
$pageTitle = 'Uso de Disco / HDD';
$pageIcon = 'hard-drive';
$isAdmin = true;
require_once __DIR__ . '/../includes/layout_header.php';
requireRole('admin');

$db = getDB();

// System disk usage
$diskTotal = @disk_total_space('/') ?: 0;
$diskFree = @disk_free_space('/') ?: 0;
$diskUsed = $diskTotal - $diskFree;
$diskPct = $diskTotal > 0 ? round($diskUsed / $diskTotal * 100, 1) : 0;

// Media directory usage
$mediaPath = defined('MEDIA_PATH') ? MEDIA_PATH : '/var/www/castpanel/media';
$mediaSize = 0;
$mediaOutput = @shell_exec("du -sb " . escapeshellarg($mediaPath) . " 2>/dev/null");
if ($mediaOutput && preg_match('/^(\d+)/', $mediaOutput, $m)) {
    $mediaSize = (int)$m[1];
}

// Per-station disk usage
$stationUsage = [];
try {
    $stations = $db->query("SELECT e.id, e.nombre, e.slug, u.username FROM estaciones e LEFT JOIN usuarios u ON e.usuario_id = u.id ORDER BY e.nombre")->fetchAll();
    foreach ($stations as $st) {
        $stDir = $mediaPath . '/' . $st['slug'];
        $size = 0;
        if (is_dir($stDir)) {
            $duOut = @shell_exec("du -sb " . escapeshellarg($stDir) . " 2>/dev/null");
            if ($duOut && preg_match('/^(\d+)/', $duOut, $m)) {
                $size = (int)$m[1];
            }
        }
        $stationUsage[] = [
            'id' => $st['id'],
            'nombre' => $st['nombre'],
            'slug' => $st['slug'],
            'username' => $st['username'] ?? 'N/A',
            'size' => $size,
        ];
    }
    // Sort by size descending
    usort($stationUsage, fn($a, $b) => $b['size'] - $a['size']);
} catch (PDOException $e) {}

// Key directories
$keyDirs = [
    ['path' => $mediaPath, 'label' => 'Media (Música)'],
    ['path' => defined('ANUNCIOS_PATH') ? ANUNCIOS_PATH : '/var/www/castpanel/anuncios', 'label' => 'Anuncios'],
    ['path' => '/var/log', 'label' => 'Logs del Sistema'],
    ['path' => '/tmp', 'label' => 'Archivos Temporales'],
];
foreach ($keyDirs as &$d) {
    $d['size'] = 0;
    $duOut = @shell_exec("du -sb " . escapeshellarg($d['path']) . " 2>/dev/null");
    if ($duOut && preg_match('/^(\d+)/', $duOut, $m)) {
        $d['size'] = (int)$m[1];
    }
}
unset($d);
?>

<!-- System Overview -->
<div class="stats-grid" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:1rem;margin-bottom:1.5rem;">
    <div class="card" style="padding:1.5rem;">
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:.8rem;">
            <span style="font-weight:600;"><?= cpIcon('hard-drive', 16) ?> Disco Total</span>
            <span style="font-weight:700;color:<?= $diskPct > 90 ? '#dc3545' : '#28a745' ?>;"><?= $diskPct ?>%</span>
        </div>
        <div style="background:#e9ecef;border-radius:4px;height:8px;overflow:hidden;margin-bottom:.5rem;">
            <div style="width:<?= min($diskPct, 100) ?>%;height:100%;background:<?= $diskPct > 90 ? '#dc3545' : ($diskPct > 70 ? '#fd7e14' : '#28a745') ?>;border-radius:4px;"></div>
        </div>
        <div style="font-size:.8rem;color:#666;"><?= formatBytes($diskUsed) ?> usado de <?= formatBytes($diskTotal) ?></div>
    </div>
    <div class="card" style="text-align:center;padding:1.5rem;">
        <div style="font-size:1.8rem;font-weight:700;color:var(--primary);"><?= formatBytes($mediaSize) ?></div>
        <div style="color:#666;font-size:.85rem;">Directorio Media</div>
    </div>
    <div class="card" style="text-align:center;padding:1.5rem;">
        <div style="font-size:1.8rem;font-weight:700;color:#28a745;"><?= formatBytes($diskFree) ?></div>
        <div style="color:#666;font-size:.85rem;">Espacio Libre</div>
    </div>
</div>

<!-- Key Directories -->
<div class="card" style="margin-bottom:1.5rem;">
    <div class="card-header">
        <h3><?= cpIcon('layers', 18) ?> Directorios Principales</h3>
    </div>
    <div class="card-body" style="padding:0;">
        <table class="table" style="margin:0;">
            <thead>
                <tr><th>Directorio</th><th>Ruta</th><th>Tamaño</th><th>% del Disco</th></tr>
            </thead>
            <tbody>
                <?php foreach ($keyDirs as $d):
                    $pct = $diskTotal > 0 ? round($d['size'] / $diskTotal * 100, 2) : 0;
                ?>
                <tr>
                    <td><strong><?= htmlspecialchars($d['label']) ?></strong></td>
                    <td><code style="font-size:.8rem;"><?= htmlspecialchars($d['path']) ?></code></td>
                    <td><?= formatBytes($d['size']) ?></td>
                    <td>
                        <div style="display:flex;align-items:center;gap:.5rem;">
                            <div style="flex:1;background:#e9ecef;border-radius:3px;height:6px;max-width:100px;">
                                <div style="width:<?= min($pct * 5, 100) ?>%;height:100%;background:var(--primary);border-radius:3px;"></div>
                            </div>
                            <?= $pct ?>%
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Per-Station Usage -->
<div class="card">
    <div class="card-header">
        <h3><?= cpIcon('radio', 18) ?> Uso por Estación (<?= count($stationUsage) ?>)</h3>
    </div>
    <div class="card-body" style="padding:0;">
        <table class="table" style="margin:0;">
            <thead>
                <tr><th>#</th><th>Estación</th><th>Usuario</th><th>Tamaño</th><th>Barra</th></tr>
            </thead>
            <tbody>
                <?php if (empty($stationUsage)): ?>
                <tr><td colspan="5" style="text-align:center;padding:2rem;color:#999;">Sin datos</td></tr>
                <?php else: ?>
                <?php // max(array, 1) returns the array in PHP, so take the largest size first
                      $maxSize = max(1, ...array_column($stationUsage, 'size')); ?>
                <?php foreach ($stationUsage as $i => $su): ?>
                <tr>
                    <td><?= $i + 1 ?></td>
                    <td><strong><?= htmlspecialchars($su['nombre']) ?></strong></td>
                    <td><?= htmlspecialchars($su['username']) ?></td>
                    <td><?= formatBytes($su['size']) ?></td>
                    <td style="width:30%;">
                        <div style="background:#e9ecef;border-radius:3px;height:8px;">
                            <div style="width:<?= round($su['size'] / $maxSize * 100, 1) ?>%;height:100%;background:var(--primary);border-radius:3px;"></div>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/layout_footer.php'; ?>
