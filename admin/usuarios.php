<?php
/**
 * CastPanel v2.3 - Admin: Gestión de Usuarios
 */
$pageTitle = 'Gestionar Usuarios';
$pageIcon = 'user';
$isAdmin = true;
require_once __DIR__ . '/../includes/layout_header.php';
requireRole('admin');

$db = getDB();
$msg = '';
$msgType = '';
$_tableError = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && verifyCsrf()) {
    $action = $_POST['action'] ?? '';

    try {
        if ($action === 'create') {
            $username = trim($_POST['username'] ?? '');
            $email = trim($_POST['email'] ?? '');
            $nombre = trim($_POST['nombre'] ?? '');
            $password = $_POST['password'] ?? '';
            $rol = $_POST['rol'] ?? 'cliente';

            if (!$username || !$password) {
                $msg = 'Usuario y contraseña son obligatorios';
                $msgType = 'error';
            } else {
                $check = $db->prepare("SELECT id FROM usuarios WHERE username = ?");
                $check->execute([$username]);
                if ($check->fetch()) {
                    $msg = 'El nombre de usuario ya existe';
                    $msgType = 'error';
                } else {
                    $maxClientes = ($rol === 'reseller') ? (int)($_POST['max_clientes'] ?? 0) : 0;
                    $maxEstaciones = ($rol === 'reseller') ? (int)($_POST['max_estaciones'] ?? 0) : 0;
                    $stmt = $db->prepare("INSERT INTO usuarios (username, email, nombre, password, rol, max_clientes, max_estaciones) VALUES (?,?,?,?,?,?,?)");
                    $stmt->execute([$username, $email, $nombre, password_hash($password, PASSWORD_DEFAULT), $rol, $maxClientes, $maxEstaciones]);
                    $msg = "Usuario '$username' creado exitosamente";
                    $msgType = 'success';
                }
            }
        } elseif ($action === 'update') {
            $id = (int)$_POST['id'];
            $email = trim($_POST['email'] ?? '');
            $nombre = trim($_POST['nombre'] ?? '');
            $rol = $_POST['rol'] ?? 'cliente';
            $activo = isset($_POST['activo']) ? 1 : 0;

            $maxClientes = ($rol === 'reseller') ? (int)($_POST['max_clientes'] ?? 0) : 0;
            $maxEstaciones = ($rol === 'reseller') ? (int)($_POST['max_estaciones'] ?? 0) : 0;
            $stmt = $db->prepare("UPDATE usuarios SET email=?, nombre=?, rol=?, activo=?, max_clientes=?, max_estaciones=? WHERE id=?");
            $stmt->execute([$email, $nombre, $rol, $activo, $maxClientes, $maxEstaciones, $id]);

            $newPass = $_POST['new_password'] ?? '';
            if ($newPass) {
                $db->prepare("UPDATE usuarios SET password = ? WHERE id = ?")->execute([password_hash($newPass, PASSWORD_DEFAULT), $id]);
            }

            $msg = 'Usuario actualizado';
            $msgType = 'success';
        } elseif ($action === 'delete') {
            $id = (int)$_POST['id'];
            if ($id == $_SESSION['user_id']) {
                $msg = 'No puedes eliminar tu propia cuenta';
                $msgType = 'error';
            } else {
                $db->prepare("DELETE FROM usuarios WHERE id = ?")->execute([$id]);
                $msg = 'Usuario eliminado';
                $msgType = 'success';
            }
        } elseif ($action === 'toggle') {
            $id = (int)$_POST['id'];
            if ($id == $_SESSION['user_id']) {
                $msg = 'No puedes desactivar tu propia cuenta';
                $msgType = 'error';
            } else {
                $db->prepare("UPDATE usuarios SET activo = NOT activo WHERE id = ?")->execute([$id]);
                $msg = 'Estado actualizado';
                $msgType = 'success';
            }
        }
    } catch (PDOException $e) {
        $msg = 'Error en la operación: ' . $e->getMessage();
        $msgType = 'error';
    }
}

$usuarios = [];
try {
    $usuarios = $db->query("SELECT u.*, (SELECT COUNT(*) FROM estaciones WHERE usuario_id = u.id) AS total_estaciones FROM usuarios u ORDER BY u.created_at DESC")->fetchAll();
} catch (PDOException $e) { $_tableError = true; }

$editUser = null;
if (isset($_GET['edit'])) {
    try {
        $stmt = $db->prepare("SELECT * FROM usuarios WHERE id = ?");
        $stmt->execute([(int)$_GET['edit']]);
        $editUser = $stmt->fetch();
    } catch (PDOException $e) {}
}
?>

<?php if ($_tableError): ?>
<div class="alert alert-warning"><?= cpIcon('warning', 16) ?> La tabla <strong>usuarios</strong> tiene un problema. Verifique la migración SQL.</div>
<?php endif; ?>

<?php if ($msg): ?>
    <div class="alert alert-<?= $msgType ?>"><?= $msg ?></div>
<?php endif; ?>

<!-- Formulario -->
<div class="card">
    <div class="card-header">
        <h3><?= $editUser ? cpIcon('pencil', 18) . ' Editar Usuario' : cpIcon('plus', 18) . ' Nuevo Usuario' ?></h3>
    </div>
    <div class="card-body">
        <form method="POST">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="<?= $editUser ? 'update' : 'create' ?>">
            <?php if ($editUser): ?>
                <input type="hidden" name="id" value="<?= $editUser['id'] ?>">
            <?php endif; ?>

            <div class="form-grid-3">
                <?php if (!$editUser): ?>
                    <div class="form-group">
                        <label>Nombre de Usuario *</label>
                        <input type="text" name="username" class="form-control" required>
                    </div>
                <?php else: ?>
                    <div class="form-group">
                        <label>Usuario</label>
                        <input type="text" class="form-control" value="<?= htmlspecialchars($editUser['username']) ?>" disabled>
                    </div>
                <?php endif; ?>
                <div class="form-group">
                    <label>Nombre Completo</label>
                    <input type="text" name="nombre" class="form-control" value="<?= htmlspecialchars($editUser['nombre'] ?? '') ?>">
                </div>
                <div class="form-group">
                    <label>Email</label>
                    <input type="email" name="email" class="form-control" value="<?= htmlspecialchars($editUser['email'] ?? '') ?>">
                </div>
            </div>

            <div class="form-grid-3">
                <div class="form-group">
                    <label><?= $editUser ? 'Nueva Contraseña (dejar vacío para mantener)' : 'Contraseña *' ?></label>
                    <input type="password" name="<?= $editUser ? 'new_password' : 'password' ?>" class="form-control" <?= $editUser ? '' : 'required' ?>>
                </div>
                <div class="form-group">
                    <label>Rol</label>
                    <select name="rol" class="form-control" id="rolSelect" onchange="toggleResellerFields()">
                        <option value="cliente" <?= ($editUser['rol'] ?? 'cliente') === 'cliente' ? 'selected' : '' ?>>Cliente</option>
                        <option value="reseller" <?= ($editUser['rol'] ?? '') === 'reseller' ? 'selected' : '' ?>>Reseller</option>
                        <option value="admin" <?= ($editUser['rol'] ?? '') === 'admin' ? 'selected' : '' ?>>Administrador</option>
                    </select>
                </div>
                <?php if ($editUser): ?>
                    <div class="form-group" style="display:flex;align-items:end">
                        <label><input type="checkbox" name="activo" <?= $editUser['activo'] ? 'checked' : '' ?>> Cuenta Activa</label>
                    </div>
                <?php endif; ?>
            </div>

            <div class="form-grid-3" id="resellerFields" style="display:none">
                <div class="form-group">
                    <label>M&aacute;x. Clientes (0 = ilimitado)</label>
                    <input type="number" name="max_clientes" class="form-control" value="<?= $editUser['max_clientes'] ?? 0 ?>" min="0">
                </div>
                <div class="form-group">
                    <label>M&aacute;x. Estaciones (0 = ilimitado)</label>
                    <input type="number" name="max_estaciones" class="form-control" value="<?= $editUser['max_estaciones'] ?? 0 ?>" min="0">
                </div>
            </div>

            <div style="display:flex;gap:10px">
                <button type="submit" class="btn btn-primary"><?= $editUser ? 'Guardar Cambios' : 'Crear Usuario' ?></button>
                <?php if ($editUser): ?>
                    <a href="<?= BASE_URL ?>/admin/usuarios.php" class="btn btn-outline">Cancelar</a>
                <?php endif; ?>
            </div>
        </form>
    </div>
</div>

<script>
function toggleResellerFields() {
    var sel = document.getElementById('rolSelect');
    var fields = document.getElementById('resellerFields');
    if (fields) fields.style.display = sel.value === 'reseller' ? '' : 'none';
}
toggleResellerFields();
</script>

<!-- Lista de usuarios -->
<div class="card">
    <div class="card-header">
        <h3><?= cpIcon('user', 18) ?> Todos los Usuarios (<?= count($usuarios) ?>)</h3>
        <input type="text" placeholder="Buscar..." class="form-control" style="width:200px" oninput="searchTable(this.value,'users-table')">
    </div>
    <div class="card-body no-padding">
        <div class="table-responsive">
            <table class="table" id="users-table">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Usuario</th>
                        <th>Email</th>
                        <th>Rol</th>
                        <th>Estaciones</th>
                        <th>Estado</th>
                        <th>Registro</th>
                        <th>Acciones</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($usuarios as $u): ?>
                        <tr>
                            <td><?= $u['id'] ?></td>
                            <td>
                                <strong><?= htmlspecialchars($u['username']) ?></strong>
                                <?php if ($u['nombre']): ?>
                                    <div style="font-size:11px;color:var(--text-secondary)"><?= htmlspecialchars($u['nombre']) ?></div>
                                <?php endif; ?>
                            </td>
                            <td style="font-size:12px"><?= htmlspecialchars($u['email'] ?: '-') ?></td>
                            <td>
                                <span class="badge badge-<?= $u['rol'] === 'admin' ? 'danger' : ($u['rol'] === 'reseller' ? 'warning' : 'info') ?>">
                                    <?= ucfirst($u['rol']) ?>
                                </span>
                            </td>
                            <td><?= $u['total_estaciones'] ?></td>
                            <td>
                                <?= $u['activo']
                                    ? '<span class="badge badge-success">Activo</span>'
                                    : '<span class="badge badge-danger">Inactivo</span>' ?>
                            </td>
                            <td style="font-size:12px"><?= date('d/m/Y', strtotime($u['created_at'])) ?></td>
                            <td>
                                <div style="display:flex;gap:5px;flex-wrap:wrap">
                                    <a href="?edit=<?= $u['id'] ?>" class="btn btn-sm btn-outline"><?= cpIcon('pencil', 14) ?></a>
                                    <?php if ($u['id'] != $_SESSION['user_id']): ?>
                                        <form method="POST" style="display:inline">
                                            <?= csrfField() ?>
                                            <input type="hidden" name="action" value="toggle">
                                            <input type="hidden" name="id" value="<?= $u['id'] ?>">
                                            <button type="submit" class="btn btn-sm <?= $u['activo'] ? 'btn-warning' : 'btn-success' ?>"><?= $u['activo'] ? 'Desactivar' : 'Activar' ?></button>
                                        </form>
                                        <form method="POST" style="display:inline" onsubmit="return confirmDelete('este usuario y todas sus estaciones')">
                                            <?= csrfField() ?>
                                            <input type="hidden" name="action" value="delete">
                                            <input type="hidden" name="id" value="<?= $u['id'] ?>">
                                            <button type="submit" class="btn btn-sm btn-danger"><?= cpIcon('trash', 14) ?></button>
                                        </form>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/layout_footer.php'; ?>
