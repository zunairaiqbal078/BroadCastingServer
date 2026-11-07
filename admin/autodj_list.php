<?php
/**
 * CastPanel v2.4 - Admin: Listar AutoDJs
 * Muestra todos los servicios AutoDJ (Liquidsoap) activos/inactivos
 */
$pageTitle = 'Listar AutoDJs';
$pageIcon = 'music';
$isAdmin = true;
require_once __DIR__ . '/../includes/layout_header.php';
require_once __DIR__ . '/../includes/provisioning.php';
requireRole('admin');

$db = getDB();
$msg = '';
$msgType = '';

// POST actions: start/stop/restart AutoDJ
if ($_SERVER['REQUEST_METHOD'] === 'POST' && verifyCsrf()) {
    $action = $_POST['action'] ?? '';
    $stationId = (int)($_POST['station_id'] ?? 0);

    if ($stationId > 0) {
        try {
            $st = $db->prepare("SELECT e.*, u.username FROM estaciones e LEFT JOIN usuarios u ON e.usuario_id = u.id WHERE e.id = ?");
            $st->execute([$stationId]);
            $est = $st->fetch();
            if ($est) {
                $result = ['success' => false, 'error' => 'Acción no válida'];
                if ($action === 'start_autodj') {
                    $result = startLiquidsoapService($est);
                } elseif ($action === 'stop_autodj') {
                    $result = stopLiquidsoapService($est);
                } elseif ($action === 'restart_autodj') {
                    $result = restartLiquidsoapService($est);
                }
                $msg = $result['success']
                    ? "AutoDJ de '{$est['nombre']}' — acción '$action' completada"
                    : "Error: " . ($result['error'] ?? 'desconocido');
                $msgType = $result['success'] ? 'success' : 'error';
            }
        } catch (PDOException $e) {
            $msg = 'Error de base de datos: ' . $e->getMessage();
            $msgType = 'error';
        }
    }
}

// Fetch all stations with AutoDJ info
try {
    $stations = $db->query("
        SELECT e.id, e.nombre, e.slug, e.server_type, e.puerto_stream, e.activa,
               e.autodj_estado,
               u.username, u.email,
               p.nombre AS paquete_nombre
        FROM estaciones e
        LEFT JOIN usuarios u ON e.usuario_id = u.id
        LEFT JOIN paquetes p ON e.paquete_id = p.id
        ORDER BY e.nombre ASC
    ")->fetchAll();
} catch (PDOException $e) {
    $stations = [];
}
?>

<?php if ($msg): ?>
<div class="alert alert-<?= $msgType === 'error' ? 'danger' : 'success' ?>">
    <?= htmlspecialchars($msg) ?>
</div>
<?php endif; ?>

<div class="card">
    <div class="card-header">
        <h3><?= cpIcon('music', 18) ?> AutoDJs Registrados (<?= count($stations) ?>)</h3>
    </div>
    <div class="card-body" style="padding:0;">
        <div style="overflow-x:auto;">
        <table class="table" style="margin:0;">
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Estación</th>
                    <th>Usuario</th>
                    <th>Tipo</th>
                    <th>Puerto</th>
                    <th>AutoDJ</th>
                    <th>Fuente</th>
                    <th>Estado Radio</th>
                    <th>Acciones</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($stations)): ?>
                <tr><td colspan="9" style="text-align:center;padding:2rem;color:#999;">No hay estaciones registradas</td></tr>
                <?php else: ?>
                <?php foreach ($stations as $s): ?>
                <tr>
                    <td><?= $s['id'] ?></td>
                    <td><strong><?= htmlspecialchars($s['nombre']) ?></strong></td>
                    <td><?= htmlspecialchars($s['username'] ?? 'N/A') ?></td>
                    <td><span class="badge badge-info"><?= htmlspecialchars($s['server_type'] ?? 'icecast') ?></span></td>
                    <td><?= $s['puerto_stream'] ?? '-' ?></td>
                    <td>
                        <?php if ($s['autodj_estado'] === 'online'): ?>
                            <span class="badge badge-success">Online</span>
                        <?php elseif ($s['autodj_estado'] === 'starting'): ?>
                            <span class="badge badge-warning">Iniciando</span>
                        <?php elseif ($s['autodj_estado'] === 'stopping'): ?>
                            <span class="badge badge-warning">Deteniendo</span>
                        <?php else: ?>
                            <span class="badge badge-secondary">Offline</span>
                        <?php endif; ?>
                    </td>
                    <td>Playlist</td>
                    <td>
                        <?php if ($s['activa']): ?>
                            <span class="badge badge-success">Activa</span>
                        <?php else: ?>
                            <span class="badge badge-danger">Inactiva</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <form method="post" style="display:inline-flex;gap:4px;">
                            <?= csrfField() ?>
                            <input type="hidden" name="station_id" value="<?= $s['id'] ?>">
                            <button type="submit" name="action" value="start_autodj" class="btn btn-sm btn-success" title="Iniciar AutoDJ">
                                <?= cpIcon('play', 14) ?>
                            </button>
                            <button type="submit" name="action" value="stop_autodj" class="btn btn-sm btn-danger" title="Detener AutoDJ">
                                <?= cpIcon('stop', 14) ?>
                            </button>
                            <button type="submit" name="action" value="restart_autodj" class="btn btn-sm btn-warning" title="Reiniciar AutoDJ">
                                <?= cpIcon('refresh', 14) ?>
                            </button>
                        </form>
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
