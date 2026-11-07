<?php
ob_start();
/**
 * CastPanel v2.7.2 - Shows DJ Grabados (Cliente)
 * Lista y descarga de shows DJ grabados de la estacion del cliente
 */
$pageTitle = 'Shows DJ';
$pageIcon = 'download';
require_once __DIR__ . '/../includes/layout_header.php';

if (!$estacion) {
    echo '<div class="alert alert-warning">Sin estaci&oacute;n asignada.</div>';
    require_once __DIR__ . '/../includes/layout_footer.php';
    exit;
}

// Buscar grabaciones
$showsDir = '/var/www/castpanel/storage/' . ($estacion['slug'] ?? 'unknown') . '/recordings';
$shows = [];
if (is_dir($showsDir)) {
    $files = scandir($showsDir, SCANDIR_SORT_DESCENDING);
    foreach ($files as $f) {
        if ($f === '.' || $f === '..') continue;
        $fullPath = $showsDir . '/' . $f;
        if (is_file($fullPath)) {
            $shows[] = [
                'file' => $f,
                'size' => filesize($fullPath),
                'date' => date('Y-m-d', filemtime($fullPath)),
                'time' => date('H:i:s', filemtime($fullPath)),
            ];
        }
    }
}
?>

<div class="section-card">
    <h3 style="margin:0 0 12px;display:flex;align-items:center;gap:8px">
        <?= cpIcon('download', 20) ?>
        Listar/Descargar Shows DJ
    </h3>

    <div style="background:var(--info-bg, #e8f4fd);border:1px solid var(--info-border, #b6d4e8);border-radius:8px;padding:14px 18px;margin-bottom:20px;font-size:14px;line-height:1.7">
        <?= cpIcon('info', 16) ?>
        La grabaci&oacute;n es autom&aacute;tica si est&aacute; habilitada. Para habilitar/deshabilitar la grabaci&oacute;n de shows DJ en vivo,
        use la configuraci&oacute;n de la estaci&oacute;n. Los archivos usan su espacio de alojamiento disponible.
    </div>

    <?php if (empty($shows)): ?>
    <div style="text-align:center;padding:30px;color:#888">
        <?= cpIcon('music', 32) ?>
        <p style="margin:10px 0 0">No hay shows grabados para esta estaci&oacute;n.</p>
        <p style="font-size:13px;color:#aaa">Grabaci&oacute;n DJ: <strong><?= ($estacion['grabar_dj'] ?? 0) ? 'Habilitada' : 'Deshabilitada' ?></strong></p>
    </div>
    <?php else: ?>
    <div class="table-responsive">
        <table class="table">
            <thead>
                <tr>
                    <th><?= cpIcon('user', 14) ?> Archivo</th>
                    <th><?= cpIcon('calendar', 14) ?> Fecha</th>
                    <th><?= cpIcon('clock', 14) ?> Hora</th>
                    <th><?= cpIcon('hard-drive', 14) ?> Tama&ntilde;o</th>
                    <th><?= cpIcon('download', 14) ?> Descargar</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($shows as $show): ?>
                <tr>
                    <td><?= htmlspecialchars($show['file']) ?></td>
                    <td><?= $show['date'] ?></td>
                    <td><?= $show['time'] ?></td>
                    <td><?= round($show['size'] / 1048576, 1) ?> MB</td>
                    <td>
                        <a href="<?= BASE_URL ?>/storage/<?= htmlspecialchars($estacion['slug']) ?>/recordings/<?= rawurlencode($show['file']) ?>"
                           class="btn btn-sm btn-outline" download>
                            <?= cpIcon('download', 14) ?> Descargar
                        </a>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <p style="font-size:13px;color:#888;margin-top:12px">Total: <?= count($shows) ?> grabaci&oacute;n(es)</p>
    <?php endif; ?>
</div>

<?php require_once __DIR__ . '/../includes/layout_footer.php'; ?>
