<?php
/**
 * CastPanel v2.4 - Admin: Listas de Reproducción Compartidas
 * Gestionar listas de reproducción globales compartidas entre estaciones
 */
$pageTitle = 'Listas de Reproducción Compartidas';
$pageIcon = 'share';
$isAdmin = true;
require_once __DIR__ . '/../includes/layout_header.php';
requireRole('admin');

$db = getDB();
$msg = '';
$msgType = '';

// Check if shared_playlists table exists
$tableExists = false;
try {
    $db->query("SELECT 1 FROM shared_playlists LIMIT 1");
    $tableExists = true;
} catch (PDOException $e) {}

// POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && verifyCsrf() && $tableExists) {
    $action = $_POST['action'] ?? '';

    try {
        if ($action === 'create') {
            $nombre = trim($_POST['nombre'] ?? '');
            $descripcion = trim($_POST['descripcion'] ?? '');
            if ($nombre) {
                $stmt = $db->prepare("INSERT INTO shared_playlists (nombre, descripcion, created_at) VALUES (?, ?, NOW())");
                $stmt->execute([$nombre, $descripcion]);
                $msg = "Lista compartida '$nombre' creada exitosamente";
                $msgType = 'success';
            }
        } elseif ($action === 'delete') {
            $id = (int)($_POST['playlist_id'] ?? 0);
            if ($id > 0) {
                $db->prepare("DELETE FROM shared_playlist_items WHERE shared_playlist_id = ?")->execute([$id]);
                $db->prepare("DELETE FROM shared_playlists WHERE id = ?")->execute([$id]);
                $msg = 'Lista compartida eliminada';
                $msgType = 'success';
            }
        } elseif ($action === 'assign') {
            $playlistId = (int)($_POST['playlist_id'] ?? 0);
            $stationIds = $_POST['station_ids'] ?? [];
            if ($playlistId > 0 && is_array($stationIds)) {
                $db->prepare("DELETE FROM shared_playlist_stations WHERE shared_playlist_id = ?")->execute([$playlistId]);
                $stmtIns = $db->prepare("INSERT INTO shared_playlist_stations (shared_playlist_id, estacion_id) VALUES (?, ?)");
                foreach ($stationIds as $sid) {
                    $stmtIns->execute([$playlistId, (int)$sid]);
                }
                $msg = 'Estaciones asignadas a la lista compartida';
                $msgType = 'success';
            }
        }
    } catch (PDOException $e) {
        $msg = 'Error: ' . $e->getMessage();
        $msgType = 'error';
    }
}

// Fetch playlists
$playlists = [];
if ($tableExists) {
    try {
        $playlists = $db->query("
            SELECT sp.*,
                   (SELECT COUNT(*) FROM shared_playlist_items spi WHERE spi.shared_playlist_id = sp.id) AS total_tracks,
                   (SELECT COUNT(*) FROM shared_playlist_stations sps WHERE sps.shared_playlist_id = sp.id) AS total_stations
            FROM shared_playlists sp
            ORDER BY sp.nombre ASC
        ")->fetchAll();
    } catch (PDOException $e) {
        $playlists = [];
    }
}

// Fetch stations for assignment
$allStations = [];
try {
    $allStations = $db->query("SELECT id, nombre FROM estaciones ORDER BY nombre ASC")->fetchAll();
} catch (PDOException $e) {}
?>

<?php if ($msg): ?>
<div class="alert alert-<?= $msgType === 'error' ? 'danger' : 'success' ?>">
    <?= htmlspecialchars($msg) ?>
</div>
<?php endif; ?>

<?php if (!$tableExists): ?>
<div class="alert alert-warning">
    <?= cpIcon('alert', 16) ?> La tabla <code>shared_playlists</code> no existe aún. Ejecute la migración SQL para habilitar esta funcionalidad.
</div>
<?php endif; ?>

<!-- Create new shared playlist -->
<div class="card" style="margin-bottom:1.5rem;">
    <div class="card-header">
        <h3><?= cpIcon('plus', 18) ?> Crear Lista Compartida</h3>
    </div>
    <div class="card-body">
        <form method="post">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="create">
            <div class="form-grid-2">
                <div class="form-group">
                    <label>Nombre de la Lista</label>
                    <input type="text" name="nombre" class="form-control" required placeholder="Ej: Música Navideña">
                </div>
                <div class="form-group">
                    <label>Descripción</label>
                    <input type="text" name="descripcion" class="form-control" placeholder="Descripción opcional">
                </div>
            </div>
            <button type="submit" class="btn btn-primary" <?= !$tableExists ? 'disabled' : '' ?>>
                <?= cpIcon('plus', 14) ?> Crear Lista
            </button>
        </form>
    </div>
</div>

<!-- Existing shared playlists -->
<div class="card">
    <div class="card-header">
        <h3><?= cpIcon('share', 18) ?> Listas Compartidas (<?= count($playlists) ?>)</h3>
    </div>
    <div class="card-body" style="padding:0;">
        <table class="table" style="margin:0;">
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Nombre</th>
                    <th>Descripción</th>
                    <th>Pistas</th>
                    <th>Estaciones Asignadas</th>
                    <th>Creada</th>
                    <th>Acciones</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($playlists)): ?>
                <tr><td colspan="7" style="text-align:center;padding:2rem;color:#999;">No hay listas compartidas</td></tr>
                <?php else: ?>
                <?php foreach ($playlists as $pl): ?>
                <tr>
                    <td><?= $pl['id'] ?></td>
                    <td><strong><?= htmlspecialchars($pl['nombre']) ?></strong></td>
                    <td><?= htmlspecialchars($pl['descripcion'] ?? '') ?></td>
                    <td><?= $pl['total_tracks'] ?></td>
                    <td><?= $pl['total_stations'] ?></td>
                    <td><?= date('d/m/Y', strtotime($pl['created_at'])) ?></td>
                    <td>
                        <form method="post" style="display:inline;" onsubmit="return confirm('¿Eliminar esta lista compartida?')">
                            <?= csrfField() ?>
                            <input type="hidden" name="action" value="delete">
                            <input type="hidden" name="playlist_id" value="<?= $pl['id'] ?>">
                            <button type="submit" class="btn btn-sm btn-danger"><?= cpIcon('trash', 14) ?></button>
                        </form>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/layout_footer.php'; ?>
