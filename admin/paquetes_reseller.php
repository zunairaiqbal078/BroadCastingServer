<?php
/**
 * CastPanel v2.4 - Admin: Paquetes de Revendedor
 * Crear, editar y eliminar paquetes para revendedores
 */
$_isNewAction = ($_GET['action'] ?? '') === 'new' && !isset($_GET['edit']);
$pageTitle = $_isNewAction ? 'Agregar Paquete de Revendedor' : 'Editar Paquetes de Revendedor';
$pageIcon = 'package';
$isAdmin = true;
require_once __DIR__ . '/../includes/layout_header.php';
requireRole('admin');

$db = getDB();
$msg = '';
$msgType = '';

// Check if table exists
$tableExists = false;
try {
    $db->query("SELECT 1 FROM paquetes_reseller LIMIT 1");
    $tableExists = true;
} catch (PDOException $e) {}

// POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && verifyCsrf() && $tableExists) {
    $action = $_POST['action'] ?? '';

    try {
        if ($action === 'create' || $action === 'update') {
            $nombre = trim($_POST['nombre'] ?? '');
            $maxCuentas = (int)($_POST['max_cuentas'] ?? 10);
            $maxEstaciones = (int)($_POST['max_estaciones'] ?? 10);
            $maxOyentes = (int)($_POST['max_oyentes'] ?? 100);
            $maxAlmacenamiento = (int)($_POST['max_almacenamiento'] ?? 5000);
            $maxBitrate = (int)($_POST['max_bitrate'] ?? 320);
            $autodj = isset($_POST['autodj_enabled']) ? 1 : 0;
            $customBranding = isset($_POST['custom_branding']) ? 1 : 0;
            $precio = (float)($_POST['precio'] ?? 0);

            if (!$nombre) {
                $msg = 'El nombre del paquete es obligatorio';
                $msgType = 'error';
            } else {
                if ($action === 'create') {
                    $stmt = $db->prepare("INSERT INTO paquetes_reseller (nombre, max_cuentas, max_estaciones, max_oyentes, max_almacenamiento, max_bitrate, autodj_enabled, custom_branding, precio) VALUES (?,?,?,?,?,?,?,?,?)");
                    $stmt->execute([$nombre, $maxCuentas, $maxEstaciones, $maxOyentes, $maxAlmacenamiento, $maxBitrate, $autodj, $customBranding, $precio]);
                    $msg = "Paquete '$nombre' creado exitosamente";
                } else {
                    $id = (int)($_POST['pkg_id'] ?? 0);
                    $stmt = $db->prepare("UPDATE paquetes_reseller SET nombre=?, max_cuentas=?, max_estaciones=?, max_oyentes=?, max_almacenamiento=?, max_bitrate=?, autodj_enabled=?, custom_branding=?, precio=? WHERE id=?");
                    $stmt->execute([$nombre, $maxCuentas, $maxEstaciones, $maxOyentes, $maxAlmacenamiento, $maxBitrate, $autodj, $customBranding, $precio, $id]);
                    $msg = "Paquete actualizado";
                }
                $msgType = 'success';
            }
        } elseif ($action === 'delete') {
            $id = (int)($_POST['pkg_id'] ?? 0);
            if ($id > 0) {
                $inUse = $db->prepare("SELECT COUNT(*) FROM usuarios WHERE paquete_reseller_id = ?");
                $inUse->execute([$id]);
                if ($inUse->fetchColumn() > 0) {
                    $msg = 'No se puede eliminar: hay revendedores usando este paquete';
                    $msgType = 'error';
                } else {
                    $db->prepare("DELETE FROM paquetes_reseller WHERE id = ?")->execute([$id]);
                    $msg = 'Paquete eliminado';
                    $msgType = 'success';
                }
            }
        }
    } catch (PDOException $e) {
        $msg = 'Error: ' . $e->getMessage();
        $msgType = 'error';
    }
}

// Fetch packages
$packages = [];
if ($tableExists) {
    try {
        $packages = $db->query("
            SELECT pr.*,
                   (SELECT COUNT(*) FROM usuarios u WHERE u.paquete_reseller_id = pr.id) AS total_resellers
            FROM paquetes_reseller pr
            ORDER BY pr.nombre ASC
        ")->fetchAll();
    } catch (PDOException $e) {}
}

$editPkg = null;
if (isset($_GET['edit']) && $tableExists) {
    $editId = (int)$_GET['edit'];
    $stEdit = $db->prepare("SELECT * FROM paquetes_reseller WHERE id = ?");
    $stEdit->execute([$editId]);
    $editPkg = $stEdit->fetch();
}
?>

<?php if ($msg): ?>
<div class="alert alert-<?= $msgType === 'error' ? 'danger' : 'success' ?>">
    <?= htmlspecialchars($msg) ?>
</div>
<?php endif; ?>

<?php if (!$tableExists): ?>
<div class="alert alert-warning">
    <?= cpIcon('alert', 16) ?> La tabla <code>paquetes_reseller</code> no existe aún. Ejecute la migración SQL para habilitar esta funcionalidad.
</div>
<?php endif; ?>

<?php $showForm = $editPkg || ($_GET['action'] ?? '') === 'new'; ?>
<?php if ($showForm): ?>
<!-- Create / Edit Form -->
<div class="card" style="margin-bottom:1.5rem;">
    <div class="card-header">
        <h3><?= cpIcon($editPkg ? 'edit' : 'plus', 18) ?> <?= $editPkg ? 'Editar Paquete' : 'Crear Paquete de Revendedor' ?></h3>
    </div>
    <div class="card-body">
        <form method="post">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="<?= $editPkg ? 'update' : 'create' ?>">
            <?php if ($editPkg): ?>
            <input type="hidden" name="pkg_id" value="<?= $editPkg['id'] ?>">
            <?php endif; ?>

            <div class="form-grid-3">
                <div class="form-group">
                    <label>Nombre del Paquete *</label>
                    <input type="text" name="nombre" class="form-control" required
                           value="<?= htmlspecialchars($editPkg['nombre'] ?? '') ?>" placeholder="Revendedor Básico">
                </div>
                <div class="form-group">
                    <label>Máx. Cuentas de Cliente</label>
                    <input type="number" name="max_cuentas" class="form-control" min="1"
                           value="<?= $editPkg['max_cuentas'] ?? 10 ?>">
                </div>
                <div class="form-group">
                    <label>Máx. Estaciones</label>
                    <input type="number" name="max_estaciones" class="form-control" min="1"
                           value="<?= $editPkg['max_estaciones'] ?? 10 ?>">
                </div>
            </div>
            <div class="form-grid-3">
                <div class="form-group">
                    <label>Máx. Oyentes por Estación</label>
                    <input type="number" name="max_oyentes" class="form-control" min="1"
                           value="<?= $editPkg['max_oyentes'] ?? 100 ?>">
                </div>
                <div class="form-group">
                    <label>Almacenamiento (MB)</label>
                    <input type="number" name="max_almacenamiento" class="form-control" min="100"
                           value="<?= $editPkg['max_almacenamiento'] ?? 5000 ?>">
                </div>
                <div class="form-group">
                    <label>Máx. Bitrate (kbps)</label>
                    <select name="max_bitrate" class="form-control">
                        <?php foreach ([64,96,128,192,256,320] as $br): ?>
                        <option value="<?= $br ?>" <?= ($editPkg['max_bitrate'] ?? 320) == $br ? 'selected' : '' ?>><?= $br ?> kbps</option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <input type="hidden" name="precio" value="0">
            <div class="form-grid-2">
                <div class="form-group" style="display:flex;align-items:center;gap:1rem;padding-top:1.5rem;">
                    <label style="display:flex;align-items:center;gap:.5rem;cursor:pointer;">
                        <input type="checkbox" name="autodj_enabled" <?= ($editPkg['autodj_enabled'] ?? 1) ? 'checked' : '' ?>>
                        AutoDJ Habilitado
                    </label>
                </div>
                <div class="form-group" style="display:flex;align-items:center;gap:1rem;padding-top:1.5rem;">
                    <label style="display:flex;align-items:center;gap:.5rem;cursor:pointer;">
                        <input type="checkbox" name="custom_branding" <?= ($editPkg['custom_branding'] ?? 0) ? 'checked' : '' ?>>
                        Branding Personalizado
                    </label>
                </div>
            </div>

            <div style="margin-top:1rem;">
                <button type="submit" class="btn btn-primary" <?= !$tableExists ? 'disabled' : '' ?>>
                    <?= cpIcon($editPkg ? 'save' : 'plus', 14) ?> <?= $editPkg ? 'Guardar' : 'Crear Paquete' ?>
                </button>
                <?php if ($editPkg): ?>
                <a href="<?= BASE_URL ?>/admin/paquetes_reseller.php" class="btn btn-secondary">Cancelar</a>
                <?php endif; ?>
            </div>
        </form>
    </div>
</div>

<?php endif; /* $showForm */ ?>

<?php if (!$showForm || $editPkg): ?>
<!-- Package List -->
<div class="card">
    <div class="card-header">
        <h3><?= cpIcon('package', 18) ?> Paquetes de Revendedor (<?= count($packages) ?>)</h3>
    </div>
    <div class="card-body" style="padding:0;">
        <table class="table" style="margin:0;">
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Nombre</th>
                    <th>Cuentas</th>
                    <th>Estaciones</th>
                    <th>Oyentes</th>
                    <th>Almacenamiento</th>
                    <th>Bitrate</th>
                    <th>AutoDJ</th>
                    <th>Revendedores</th>
                    <th>Acciones</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($packages)): ?>
                <tr><td colspan="10" style="text-align:center;padding:2rem;color:#999;">No hay paquetes creados</td></tr>
                <?php else: ?>
                <?php foreach ($packages as $p): ?>
                <tr>
                    <td><?= $p['id'] ?></td>
                    <td><strong><?= htmlspecialchars($p['nombre']) ?></strong></td>
                    <td><?= $p['max_cuentas'] ?></td>
                    <td><?= $p['max_estaciones'] ?></td>
                    <td><?= $p['max_oyentes'] ?></td>
                    <td><?= formatMB($p['max_almacenamiento']) ?></td>
                    <td><?= $p['max_bitrate'] ?> kbps</td>
                    <td><?= $p['autodj_enabled'] ? '✓' : '✗' ?></td>
                    <td><?= $p['total_resellers'] ?></td>
                    <td>
                        <a href="?edit=<?= $p['id'] ?>" class="btn btn-sm btn-primary"><?= cpIcon('edit', 14) ?></a>
                        <form method="post" style="display:inline;" onsubmit="return confirm('¿Eliminar este paquete?')">
                            <?= csrfField() ?>
                            <input type="hidden" name="action" value="delete">
                            <input type="hidden" name="pkg_id" value="<?= $p['id'] ?>">
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

<?php endif; /* !$showForm || $editPkg */ ?>

<?php require_once __DIR__ . '/../includes/layout_footer.php'; ?>
