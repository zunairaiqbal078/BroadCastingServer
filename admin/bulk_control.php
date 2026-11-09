<?php
/**
 * CastPanel v2.4 - Admin: Control Múltiple
 * Reiniciar/detener todas las radios y AutoDJs en lote
 */
$pageTitle = 'Control Múltiple';
$pageIcon = 'layers';
$isAdmin = true;
require_once __DIR__ . '/../includes/layout_header.php';
require_once __DIR__ . '/../includes/provisioning.php';
requireRole('admin');

$db = getDB();
$msg = '';
$msgType = '';
$results = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && verifyCsrf()) {
    $action = $_POST['action'] ?? '';
    // One daemon call per station can take a few seconds each
    @set_time_limit(0);

    // Same provisioning functions the admin/client panels use for a single
    // station, so bulk actions send the daemon the commands it understands.
    // (Before: unknown "restart_radio <id>" commands, and the AutoDJ actions
    // passed an id where a station row is required, which crashed the page.)
    $actions = [
        'restart_all_radios'  => ['Reiniciar Radio',  false, fn($e) => restartStation($e),
                                  'Reinicio de todas las radios ejecutado'],
        'start_all_radios'    => ['Iniciar Radio',    false, fn($e) => startStation($e),
                                  'Inicio de todas las radios ejecutado'],
        'stop_all_radios'     => ['Detener Radio',    false, fn($e) => stopStation($e),
                                  'Todas las radios detenidas'],
        'restart_all_autodjs' => ['Reiniciar AutoDJ', true,  fn($e) => restartStation($e),
                                  'Reinicio de todos los AutoDJs ejecutado'],
        'stop_all_autodjs'    => ['Detener AutoDJ',   true,  fn($e) => stopLiquidsoapService($e),
                                  'Todos los AutoDJs detenidos'],
    ];

    if (isset($actions[$action])) {
        [$label, $autoDJOnly, $run, $doneMsg] = $actions[$action];
        try {
            $ids = $db->query("SELECT id FROM estaciones WHERE activa = 1 ORDER BY nombre")->fetchAll(PDO::FETCH_COLUMN);
            $failed = 0;
            foreach ($ids as $id) {
                $st = loadStationWithPackage((int)$id);
                if (!$st) continue;
                if ($autoDJOnly && !stationHasAutoDJ($st)) {
                    continue; // stations without AutoDJ have no Liquidsoap to restart/stop
                }
                try {
                    $r = $run($st);
                } catch (\Throwable $ex) {
                    $r = ['success' => false, 'error' => $ex->getMessage()];
                }
                clearStreamStatusCache((int)$id);
                $ok = !empty($r['success']);
                if (!$ok) $failed++;
                $results[] = [
                    'station' => $st['nombre'],
                    'action'  => $label,
                    'success' => $ok,
                    'msg'     => $ok ? 'OK' : ($r['error'] ?? 'Error desconocido'),
                ];
            }
            $msg = $doneMsg;
            $msgType = $failed ? 'error' : 'success';
        } catch (PDOException $e) {
            $msg = 'Error: ' . $e->getMessage();
            $msgType = 'error';
        }
    }
}

// Current counts
$totalActive = 0;
$totalOnline = 0;
$totalAutoDJ = 0;
try {
    $stations = $db->query("SELECT * FROM estaciones WHERE activa = 1")->fetchAll();
    $totalActive = count($stations);
    $totalOnline = countOnlineStations($stations);
    // AutoDJ count
    $totalAutoDJ = (int)$db->query("SELECT COUNT(*) FROM estaciones WHERE activa = 1 AND autodj_estado = 'online'")->fetchColumn();
} catch (PDOException $e) {}
?>

<?php if ($msg): ?>
<div class="alert alert-<?= $msgType === 'error' ? 'danger' : 'success' ?>">
    <?= htmlspecialchars($msg) ?>
</div>
<?php endif; ?>

<!-- Current Status -->
<div class="stats-grid" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:1rem;margin-bottom:1.5rem;">
    <div class="card" style="text-align:center;padding:1.5rem;">
        <div style="font-size:2rem;font-weight:700;color:var(--primary);"><?= $totalActive ?></div>
        <div style="color:#666;font-size:.85rem;">Estaciones Activas</div>
    </div>
    <div class="card" style="text-align:center;padding:1.5rem;">
        <div style="font-size:2rem;font-weight:700;color:#28a745;"><?= $totalOnline ?></div>
        <div style="color:#666;font-size:.85rem;">Transmitiendo</div>
    </div>
    <div class="card" style="text-align:center;padding:1.5rem;">
        <div style="font-size:2rem;font-weight:700;color:#6366f1;"><?= $totalAutoDJ ?></div>
        <div style="color:#666;font-size:.85rem;">Con AutoDJ</div>
    </div>
</div>

<!-- Action Buttons -->
<div class="card" style="margin-bottom:1.5rem;">
    <div class="card-header">
        <h3><?= cpIcon('radio', 18) ?> Control de Radios</h3>
    </div>
    <div class="card-body">
        <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(250px,1fr));gap:1rem;">
            <form method="post">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="restart_all_radios">
                <button type="submit" class="btn btn-warning" style="width:100%;padding:1rem;"
                        onclick="return confirm('¿Reiniciar TODAS las radios activas? Los oyentes serán desconectados temporalmente.')">
                    <?= cpIcon('refresh', 16) ?> Reiniciar Todas las Radios
                </button>
                <small style="color:#666;display:block;margin-top:.5rem;">Reinicia el servicio de streaming de cada estación activa</small>
            </form>

            <form method="post">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="start_all_radios">
                <button type="submit" class="btn btn-success" style="width:100%;padding:1rem;">
                    <?= cpIcon('play', 16) ?> Iniciar Todas las Radios
                </button>
                <small style="color:#666;display:block;margin-top:.5rem;">Inicia las radios que estén detenidas</small>
            </form>

            <form method="post">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="stop_all_radios">
                <button type="submit" class="btn btn-danger" style="width:100%;padding:1rem;"
                        onclick="return confirm('¿DETENER TODAS las radios? Esto desconectará a TODOS los oyentes.')">
                    <?= cpIcon('stop', 16) ?> Detener Todas las Radios
                </button>
                <small style="color:#666;display:block;margin-top:.5rem;">⚠️ Detiene TODAS las transmisiones</small>
            </form>
        </div>
    </div>
</div>

<div class="card" style="margin-bottom:1.5rem;">
    <div class="card-header">
        <h3><?= cpIcon('music', 18) ?> Control de AutoDJs</h3>
    </div>
    <div class="card-body">
        <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(250px,1fr));gap:1rem;">
            <form method="post">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="restart_all_autodjs">
                <button type="submit" class="btn btn-warning" style="width:100%;padding:1rem;"
                        onclick="return confirm('¿Reiniciar TODOS los AutoDJs (Liquidsoap)?')">
                    <?= cpIcon('refresh', 16) ?> Reiniciar Todos los AutoDJs
                </button>
                <small style="color:#666;display:block;margin-top:.5rem;">Reinicia todos los contenedores Liquidsoap</small>
            </form>

            <form method="post">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="stop_all_autodjs">
                <button type="submit" class="btn btn-danger" style="width:100%;padding:1rem;"
                        onclick="return confirm('¿Detener TODOS los AutoDJs?')">
                    <?= cpIcon('stop', 16) ?> Detener Todos los AutoDJs
                </button>
                <small style="color:#666;display:block;margin-top:.5rem;">Detiene todos los contenedores Liquidsoap</small>
            </form>
        </div>
    </div>
</div>

<!-- Results -->
<?php if (!empty($results)): ?>
<div class="card">
    <div class="card-header">
        <h3><?= cpIcon('list', 18) ?> Resultados de la Operación</h3>
    </div>
    <div class="card-body" style="padding:0;">
        <table class="table" style="margin:0;">
            <thead>
                <tr><th>Estación</th><th>Acción</th><th>Resultado</th><th>Detalle</th></tr>
            </thead>
            <tbody>
                <?php
                $successCount = 0;
                $failCount = 0;
                foreach ($results as $r):
                    if ($r['success']) $successCount++; else $failCount++;
                ?>
                <tr>
                    <td><strong><?= htmlspecialchars($r['station']) ?></strong></td>
                    <td><?= htmlspecialchars($r['action']) ?></td>
                    <td>
                        <span class="badge badge-<?= $r['success'] ? 'success' : 'danger' ?>">
                            <?= $r['success'] ? '✓ OK' : '✗ Error' ?>
                        </span>
                    </td>
                    <td style="font-size:.85rem;"><?= htmlspecialchars($r['msg']) ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <div style="padding:1rem;background:#f8f9fa;border-top:1px solid #eee;">
            <strong>Resumen:</strong>
            <span class="badge badge-success"><?= $successCount ?> exitosos</span>
            <?php if ($failCount > 0): ?>
            <span class="badge badge-danger"><?= $failCount ?> fallidos</span>
            <?php endif; ?>
        </div>
    </div>
</div>
<?php endif; ?>

<?php require_once __DIR__ . '/../includes/layout_footer.php'; ?>
