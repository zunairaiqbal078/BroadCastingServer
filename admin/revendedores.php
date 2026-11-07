<?php
/**
 * CastPanel v2.4 - Admin: Gestión de Revendedores
 * Crear, listar, editar y eliminar cuentas de revendedores
 */
$_isNewAction = ($_GET['action'] ?? '') === 'new' && !isset($_GET['edit']);
$pageTitle = $_isNewAction ? 'Crear un Nuevo Revendedor' : 'Listar/Editar Revendedores';
$pageIcon = 'users';
$isAdmin = true;
require_once __DIR__ . '/../includes/layout_header.php';
requireRole('admin');

$db = getDB();
$msg = '';
$msgType = '';

// POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && verifyCsrf()) {
    $action = $_POST['action'] ?? '';

    try {
        if ($action === 'create') {
            $username = trim($_POST['username'] ?? '');
            $email = trim($_POST['email'] ?? '');
            $password = $_POST['password'] ?? '';
            $paqueteId = (int)($_POST['paquete_reseller_id'] ?? 0);
            $nombre = trim($_POST['nombre'] ?? '');
            $empresa = trim($_POST['empresa'] ?? '');

            if (!$username || !$email || !$password) {
                $msg = 'Username, email y contraseña son obligatorios';
                $msgType = 'error';
            } else {
                $check = $db->prepare("SELECT id FROM usuarios WHERE username = ? OR email = ?");
                $check->execute([$username, $email]);
                if ($check->fetch()) {
                    $msg = 'El username o email ya existe';
                    $msgType = 'error';
                } else {
                    $stmt = $db->prepare("INSERT INTO usuarios (username, email, password, rol, nombre, empresa, paquete_reseller_id, activo, created_at) VALUES (?,?,?,?,?,?,?,?,NOW())");
                    $stmt->execute([
                        $username, $email, password_hash($password, PASSWORD_DEFAULT),
                        'reseller', $nombre, $empresa,
                        $paqueteId > 0 ? $paqueteId : null,
                        1
                    ]);
                    $msg = "Revendedor '$username' creado exitosamente";
                    $msgType = 'success';
                }
            }
        } elseif ($action === 'update') {
            $id = (int)($_POST['user_id'] ?? 0);
            $email = trim($_POST['email'] ?? '');
            $nombre = trim($_POST['nombre'] ?? '');
            $empresa = trim($_POST['empresa'] ?? '');
            $activo = (int)($_POST['activo'] ?? 1);
            $paqueteId = (int)($_POST['paquete_reseller_id'] ?? 0);
            $password = $_POST['password'] ?? '';

            if ($id > 0) {
                $updates = "email = ?, nombre = ?, empresa = ?, activo = ?, paquete_reseller_id = ?";
                $params = [$email, $nombre, $empresa, $activo, $paqueteId > 0 ? $paqueteId : null];

                if (!empty($password)) {
                    $updates .= ", password = ?";
                    $params[] = password_hash($password, PASSWORD_DEFAULT);
                }
                $params[] = $id;

                $db->prepare("UPDATE usuarios SET $updates WHERE id = ? AND rol = 'reseller'")->execute($params);
                $msg = 'Revendedor actualizado';
                $msgType = 'success';
            }
        } elseif ($action === 'delete') {
            $id = (int)($_POST['user_id'] ?? 0);
            if ($id > 0) {
                // Check if reseller has stations assigned
                $stCount = $db->prepare("SELECT COUNT(*) FROM estaciones WHERE usuario_id IN (SELECT id FROM usuarios WHERE reseller_id = ?)");
                $stCount->execute([$id]);
                $hasStations = $stCount->fetchColumn() > 0;

                if ($hasStations) {
                    $msg = 'No se puede eliminar: el revendedor tiene clientes con estaciones activas';
                    $msgType = 'error';
                } else {
                    $db->prepare("DELETE FROM usuarios WHERE id = ? AND rol = 'reseller'")->execute([$id]);
                    $msg = 'Revendedor eliminado';
                    $msgType = 'success';
                }
            }
        } elseif ($action === 'toggle') {
            $id = (int)($_POST['user_id'] ?? 0);
            if ($id > 0) {
                $cur = $db->prepare("SELECT activo FROM usuarios WHERE id = ? AND rol = 'reseller'");
                $cur->execute([$id]);
                $row = $cur->fetch();
                if ($row) {
                    $newState = $row['activo'] ? 0 : 1;
                    $db->prepare("UPDATE usuarios SET activo = ? WHERE id = ?")->execute([$newState, $id]);
                    $msg = "Revendedor " . ($newState ? 'activado' : 'suspendido');
                    $msgType = 'success';
                }
            }
        }
    } catch (PDOException $e) {
        $msg = 'Error: ' . $e->getMessage();
        $msgType = 'error';
    }
}

// Fetch resellers
$resellers = [];
try {
    $resellers = $db->query("
        SELECT u.*,
               (SELECT COUNT(*) FROM usuarios c WHERE c.reseller_id = u.id) AS total_clients,
               (SELECT COUNT(*) FROM estaciones e WHERE e.usuario_id IN (SELECT c2.id FROM usuarios c2 WHERE c2.reseller_id = u.id)) AS total_stations
        FROM usuarios u
        WHERE u.rol = 'reseller'
        ORDER BY u.username ASC
    ")->fetchAll();
} catch (PDOException $e) {
    $resellers = [];
}

// Fetch reseller packages
$resellerPkgs = [];
try {
    $resellerPkgs = $db->query("SELECT id, nombre FROM paquetes_reseller ORDER BY nombre ASC")->fetchAll();
} catch (PDOException $e) {}

$editReseller = null;
if (isset($_GET['edit'])) {
    $editId = (int)$_GET['edit'];
    foreach ($resellers as $r) {
        if ($r['id'] === $editId) { $editReseller = $r; break; }
    }
}
?>

<?php if ($msg): ?>
<div class="alert alert-<?= $msgType === 'error' ? 'danger' : 'success' ?>">
    <?= htmlspecialchars($msg) ?>
</div>
<?php endif; ?>

<?php $showForm = $editReseller || ($_GET['action'] ?? '') === 'new'; ?>
<?php if ($showForm): ?>
<!-- Create / Edit Form -->
<div class="card" style="margin-bottom:1.5rem;">
    <div class="card-header">
        <h3><?= cpIcon($editReseller ? 'edit' : 'plus', 18) ?> <?= $editReseller ? 'Editar Revendedor' : 'Crear Nuevo Revendedor' ?></h3>
    </div>
    <div class="card-body">
        <form method="post">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="<?= $editReseller ? 'update' : 'create' ?>">
            <?php if ($editReseller): ?>
            <input type="hidden" name="user_id" value="<?= $editReseller['id'] ?>">
            <?php endif; ?>

            <div class="form-grid-3">
                <div class="form-group">
                    <label>Username <?= !$editReseller ? '*' : '' ?></label>
                    <?php if ($editReseller): ?>
                        <input type="text" class="form-control" value="<?= htmlspecialchars($editReseller['username']) ?>" disabled>
                    <?php else: ?>
                        <input type="text" name="username" class="form-control" required placeholder="reseller01">
                    <?php endif; ?>
                </div>
                <div class="form-group">
                    <label>Email *</label>
                    <input type="email" name="email" class="form-control" required
                           value="<?= htmlspecialchars($editReseller['email'] ?? '') ?>" placeholder="reseller@example.com">
                </div>
                <div class="form-group">
                    <label>Contraseña <?= !$editReseller ? '*' : '(dejar vacío para no cambiar)' ?></label>
                    <input type="password" name="password" class="form-control" <?= !$editReseller ? 'required' : '' ?>>
                </div>
            </div>
            <div class="form-grid-3">
                <div class="form-group">
                    <label>Nombre Completo</label>
                    <input type="text" name="nombre" class="form-control"
                           value="<?= htmlspecialchars($editReseller['nombre'] ?? '') ?>">
                </div>
                <div class="form-group">
                    <label>Empresa</label>
                    <input type="text" name="empresa" class="form-control"
                           value="<?= htmlspecialchars($editReseller['empresa'] ?? '') ?>">
                </div>
                <div class="form-group">
                    <label>Paquete Revendedor</label>
                    <select name="paquete_reseller_id" class="form-control">
                        <option value="0">— Sin paquete —</option>
                        <?php foreach ($resellerPkgs as $rp): ?>
                        <option value="<?= $rp['id'] ?>" <?= ($editReseller && ($editReseller['paquete_reseller_id'] ?? 0) == $rp['id']) ? 'selected' : '' ?>>
                            <?= htmlspecialchars($rp['nombre']) ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <?php if ($editReseller): ?>
            <div class="form-group">
                <label>Estado</label>
                <select name="activo" class="form-control" style="max-width:200px;">
                    <option value="1" <?= ($editReseller['activo'] ?? 1) ? 'selected' : '' ?>>Activo</option>
                    <option value="0" <?= !($editReseller['activo'] ?? 1) ? 'selected' : '' ?>>Suspendido</option>
                </select>
            </div>
            <?php endif; ?>
            <div style="margin-top:1rem;">
                <button type="submit" class="btn btn-primary">
                    <?= cpIcon($editReseller ? 'save' : 'plus', 14) ?> <?= $editReseller ? 'Guardar Cambios' : 'Crear Revendedor' ?>
                </button>
                <?php if ($editReseller): ?>
                <a href="<?= BASE_URL ?>/admin/revendedores.php" class="btn btn-secondary">Cancelar</a>
                <?php endif; ?>
            </div>
        </form>
    </div>
</div>

<?php endif; /* $showForm */ ?>

<?php if (!$showForm || $editReseller): ?>
<!-- Reseller List -->
<div class="card">
    <div class="card-header">
        <h3><?= cpIcon('users', 18) ?> Revendedores Registrados (<?= count($resellers) ?>)</h3>
    </div>
    <div class="card-body" style="padding:0;">
        <div style="overflow-x:auto;">
        <table class="table" style="margin:0;">
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Username</th>
                    <th>Email</th>
                    <th>Nombre</th>
                    <th>Empresa</th>
                    <th>Clientes</th>
                    <th>Estaciones</th>
                    <th>Estado</th>
                    <th>Acciones</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($resellers)): ?>
                <tr><td colspan="9" style="text-align:center;padding:2rem;color:#999;">No hay revendedores registrados</td></tr>
                <?php else: ?>
                <?php foreach ($resellers as $r): ?>
                <tr>
                    <td><?= $r['id'] ?></td>
                    <td><strong><?= htmlspecialchars($r['username']) ?></strong></td>
                    <td><?= htmlspecialchars($r['email']) ?></td>
                    <td><?= htmlspecialchars($r['nombre'] ?? '') ?></td>
                    <td><?= htmlspecialchars($r['empresa'] ?? '') ?></td>
                    <td><?= $r['total_clients'] ?></td>
                    <td><?= $r['total_stations'] ?></td>
                    <td>
                        <?php if ($r['activo'] ?? 1): ?>
                            <span class="badge badge-success">Activo</span>
                        <?php else: ?>
                            <span class="badge badge-danger">Suspendido</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <a href="?edit=<?= $r['id'] ?>" class="btn btn-sm btn-primary" title="Editar"><?= cpIcon('edit', 14) ?></a>
                        <form method="post" style="display:inline;">
                            <?= csrfField() ?>
                            <input type="hidden" name="action" value="toggle">
                            <input type="hidden" name="user_id" value="<?= $r['id'] ?>">
                            <button type="submit" class="btn btn-sm btn-warning" title="<?= ($r['activo'] ?? 1) ? 'Suspender' : 'Activar' ?>">
                                <?= cpIcon('power', 14) ?>
                            </button>
                        </form>
                        <form method="post" style="display:inline;" onsubmit="return confirm('¿Eliminar este revendedor?')">
                            <?= csrfField() ?>
                            <input type="hidden" name="action" value="delete">
                            <input type="hidden" name="user_id" value="<?= $r['id'] ?>">
                            <button type="submit" class="btn btn-sm btn-danger" title="Eliminar"><?= cpIcon('trash', 14) ?></button>
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

<?php endif; /* !$showForm || $editReseller */ ?>

<?php require_once __DIR__ . '/../includes/layout_footer.php'; ?>
