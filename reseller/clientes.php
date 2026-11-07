<?php
/**
 * CastPanel v2.0 - Reseller: Gestión de Clientes
 */
$pageTitle = 'Mis Clientes';
$pageIcon = 'users';
$isReseller = true;
require_once __DIR__ . '/../includes/layout_header.php';
requireRole('reseller');

$db = getDB();
$resellerId = $_SESSION['user_id'];
$msg = '';
$msgType = '';

// Límites del reseller
$stmt = $db->prepare("SELECT max_clientes, max_estaciones FROM usuarios WHERE id = ?");
$stmt->execute([$resellerId]);
$limites = $stmt->fetch();
$maxClientes = (int)($limites['max_clientes'] ?? 0);

// Contar clientes actuales
$stmt = $db->prepare("SELECT COUNT(*) FROM usuarios WHERE reseller_id = ? AND rol = 'cliente'");
$stmt->execute([$resellerId]);
$clientesActuales = (int)$stmt->fetchColumn();

// Paquetes disponibles
$paquetes = $db->query("SELECT * FROM paquetes WHERE activo = 1 ORDER BY precio ASC")->fetchAll();

// Procesar acciones POST
if ($_SERVER['REQUEST_METHOD'] === 'POST' && verifyCsrf()) {
    $action = $_POST['action'] ?? '';

    if ($action === 'create') {
        $username = trim($_POST['username'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $nombre = trim($_POST['nombre'] ?? '');
        $password = $_POST['password'] ?? '';

        if (!$username || !$password) {
            $msg = 'Usuario y contraseña son obligatorios';
            $msgType = 'error';
        } elseif ($maxClientes > 0 && $clientesActuales >= $maxClientes) {
            $msg = "Has alcanzado el límite de clientes ($maxClientes). Contacta al administrador para ampliar tu plan.";
            $msgType = 'error';
        } else {
            $check = $db->prepare("SELECT id FROM usuarios WHERE username = ?");
            $check->execute([$username]);
            if ($check->fetch()) {
                $msg = 'El nombre de usuario ya existe';
                $msgType = 'error';
            } else {
                $stmt = $db->prepare("INSERT INTO usuarios (username, email, nombre, password, rol, reseller_id) VALUES (?,?,?,?,?,?)");
                $stmt->execute([
                    $username, $email, $nombre,
                    password_hash($password, PASSWORD_DEFAULT),
                    'cliente', $resellerId
                ]);
                $clientesActuales++;
                $msg = "Cliente '$username' creado exitosamente";
                $msgType = 'success';
            }
        }
    } elseif ($action === 'update') {
        $id = (int)$_POST['id'];

        // Verificar que el cliente pertenece a este reseller
        $check = $db->prepare("SELECT id FROM usuarios WHERE id = ? AND reseller_id = ? AND rol = 'cliente'");
        $check->execute([$id, $resellerId]);
        if (!$check->fetch()) {
            $msg = 'Cliente no encontrado';
            $msgType = 'error';
        } else {
            $email = trim($_POST['email'] ?? '');
            $nombre = trim($_POST['nombre'] ?? '');
            $activo = isset($_POST['activo']) ? 1 : 0;

            $stmt = $db->prepare("UPDATE usuarios SET email=?, nombre=?, activo=? WHERE id=? AND reseller_id=?");
            $stmt->execute([$email, $nombre, $activo, $id, $resellerId]);

            // Cambiar contraseña si se proporcionó
            $newPass = $_POST['new_password'] ?? '';
            if ($newPass) {
                $db->prepare("UPDATE usuarios SET password = ? WHERE id = ? AND reseller_id = ?")
                    ->execute([password_hash($newPass, PASSWORD_DEFAULT), $id, $resellerId]);
            }

            $msg = 'Cliente actualizado';
            $msgType = 'success';
        }
    } elseif ($action === 'toggle') {
        $id = (int)$_POST['id'];
        $check = $db->prepare("SELECT id FROM usuarios WHERE id = ? AND reseller_id = ?");
        $check->execute([$id, $resellerId]);
        if ($check->fetch()) {
            $db->prepare("UPDATE usuarios SET activo = NOT activo WHERE id = ? AND reseller_id = ?")
                ->execute([$id, $resellerId]);
            $msg = 'Estado actualizado';
            $msgType = 'success';
        }
    } elseif ($action === 'delete') {
        $id = (int)$_POST['id'];
        $check = $db->prepare("SELECT id FROM usuarios WHERE id = ? AND reseller_id = ? AND rol = 'cliente'");
        $check->execute([$id, $resellerId]);
        if ($check->fetch()) {
            $db->prepare("DELETE FROM usuarios WHERE id = ? AND reseller_id = ?")->execute([$id, $resellerId]);
            $clientesActuales--;
            $msg = 'Cliente eliminado (y todas sus estaciones)';
            $msgType = 'success';
        }
    }
}

// Obtener clientes
$stmt = $db->prepare("
    SELECT u.*,
        (SELECT COUNT(*) FROM estaciones WHERE usuario_id = u.id) AS total_estaciones,
        (SELECT MAX(ultimo_login) FROM usuarios WHERE id = u.id) AS last_login
    FROM usuarios u
    WHERE u.reseller_id = ? AND u.rol = 'cliente'
    ORDER BY u.created_at DESC
");
$stmt->execute([$resellerId]);
$clientes = $stmt->fetchAll();

// Editar
$editClient = null;
if (isset($_GET['edit'])) {
    $stmt = $db->prepare("SELECT * FROM usuarios WHERE id = ? AND reseller_id = ? AND rol = 'cliente'");
    $stmt->execute([(int)$_GET['edit'], $resellerId]);
    $editClient = $stmt->fetch();
}

$showForm = $editClient || isset($_GET['nuevo']);
?>

<?php if ($msg): ?>
    <div class="alert alert-<?= $msgType ?>"><?= htmlspecialchars($msg) ?></div>
<?php endif; ?>

<!-- Límites del reseller -->
<?php if ($maxClientes > 0): ?>
<div class="card" style="margin-bottom:20px">
    <div class="card-body" style="padding:15px 20px">
        <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:10px">
            <div>
                <strong>L&iacute;mite de clientes:</strong> <?= $clientesActuales ?> / <?= $maxClientes ?> utilizados
            </div>
            <div style="flex:1;max-width:300px">
                <div style="background:var(--bg-tertiary);border-radius:10px;height:10px;overflow:hidden">
                    <?php $pct = $maxClientes > 0 ? round(($clientesActuales / $maxClientes) * 100) : 0; ?>
                    <div style="height:100%;width:<?= $pct ?>%;background:<?= $pct >= 90 ? 'var(--danger)' : 'var(--primary)' ?>;border-radius:10px;transition:width .3s"></div>
                </div>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- Formulario -->
<?php if ($showForm): ?>
<div class="card">
    <div class="card-header">
        <h3><?= $editClient ? cpIcon('pencil',14) . ' Editar Cliente' : cpIcon('plus',14) . ' Nuevo Cliente' ?></h3>
        <a href="<?= BASE_URL ?>/reseller/clientes.php" class="btn btn-outline btn-sm">Cancelar</a>
    </div>
    <div class="card-body">
        <form method="POST">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="<?= $editClient ? 'update' : 'create' ?>">
            <?php if ($editClient): ?>
                <input type="hidden" name="id" value="<?= $editClient['id'] ?>">
            <?php endif; ?>

            <div class="form-grid-3">
                <?php if (!$editClient): ?>
                    <div class="form-group">
                        <label>Nombre de Usuario *</label>
                        <input type="text" name="username" class="form-control" required placeholder="Ej: cliente1">
                    </div>
                <?php else: ?>
                    <div class="form-group">
                        <label>Usuario</label>
                        <input type="text" class="form-control" value="<?= htmlspecialchars($editClient['username']) ?>" disabled>
                    </div>
                <?php endif; ?>
                <div class="form-group">
                    <label>Nombre Completo</label>
                    <input type="text" name="nombre" class="form-control" value="<?= htmlspecialchars($editClient['nombre'] ?? '') ?>">
                </div>
                <div class="form-group">
                    <label>Email</label>
                    <input type="email" name="email" class="form-control" value="<?= htmlspecialchars($editClient['email'] ?? '') ?>">
                </div>
            </div>

            <div class="form-grid-3">
                <div class="form-group">
                    <label><?= $editClient ? 'Nueva Contraseña (dejar vacío para mantener)' : 'Contraseña *' ?></label>
                    <input type="password" name="<?= $editClient ? 'new_password' : 'password' ?>" class="form-control" <?= $editClient ? '' : 'required' ?>>
                </div>
                <?php if ($editClient): ?>
                    <div class="form-group" style="display:flex;align-items:end">
                        <label><input type="checkbox" name="activo" <?= $editClient['activo'] ? 'checked' : '' ?>> Cuenta Activa</label>
                    </div>
                <?php endif; ?>
            </div>

            <button type="submit" class="btn btn-primary"><?= $editClient ? 'Guardar Cambios' : 'Crear Cliente' ?></button>
        </form>
    </div>
</div>
<?php endif; ?>

<!-- Lista de clientes -->
<div class="card">
    <div class="card-header">
        <h3><?= cpIcon('users',18) ?> Mis Clientes (<?= count($clientes) ?>)</h3>
        <div style="display:flex;gap:10px;align-items:center">
            <input type="text" placeholder="Buscar..." class="form-control" style="width:180px" oninput="searchTable(this.value,'clients-table')">
            <?php if (!$showForm): ?>
                <a href="?nuevo=1" class="btn btn-primary btn-sm"><?= cpIcon('plus',14) ?> Nuevo</a>
            <?php endif; ?>
        </div>
    </div>
    <div class="card-body no-padding">
        <div class="table-responsive">
            <table class="table" id="clients-table">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Usuario</th>
                        <th>Email</th>
                        <th>Estaciones</th>
                        <th>Estado</th>
                        <th>&Uacute;ltimo Login</th>
                        <th>Registro</th>
                        <th>Acciones</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($clientes)): ?>
                        <tr><td colspan="8" style="text-align:center;color:var(--text-secondary);padding:30px">
                            No tienes clientes a&uacute;n. <?php if (!$showForm): ?><a href="?nuevo=1">Crear el primero</a><?php endif; ?>
                        </td></tr>
                    <?php else: ?>
                        <?php foreach ($clientes as $c): ?>
                            <tr>
                                <td><?= $c['id'] ?></td>
                                <td>
                                    <strong><?= htmlspecialchars($c['username']) ?></strong>
                                    <?php if ($c['nombre']): ?>
                                        <div style="font-size:11px;color:var(--text-secondary)"><?= htmlspecialchars($c['nombre']) ?></div>
                                    <?php endif; ?>
                                </td>
                                <td style="font-size:12px"><?= htmlspecialchars($c['email'] ?: '-') ?></td>
                                <td>
                                    <a href="<?= BASE_URL ?>/reseller/estaciones.php?cliente=<?= $c['id'] ?>" style="color:var(--primary)">
                                        <?= $c['total_estaciones'] ?> <?= cpIcon('external',12) ?>
                                    </a>
                                </td>
                                <td>
                                    <?= $c['activo']
                                        ? '<span class="badge badge-success">Activo</span>'
                                        : '<span class="badge badge-danger">Inactivo</span>' ?>
                                </td>
                                <td style="font-size:12px">
                                    <?= $c['ultimo_login'] ? date('d/m/Y H:i', strtotime($c['ultimo_login'])) : '<span style="color:var(--text-secondary)">Nunca</span>' ?>
                                </td>
                                <td style="font-size:12px"><?= date('d/m/Y', strtotime($c['created_at'])) ?></td>
                                <td>
                                    <div style="display:flex;gap:5px;flex-wrap:wrap">
                                        <a href="?edit=<?= $c['id'] ?>" class="btn btn-sm btn-outline"><?= cpIcon('pencil',14) ?></a>
                                        <form method="POST" style="display:inline">
                                            <?= csrfField() ?>
                                            <input type="hidden" name="action" value="toggle">
                                            <input type="hidden" name="id" value="<?= $c['id'] ?>">
                                            <button type="submit" class="btn btn-sm <?= $c['activo'] ? 'btn-warning' : 'btn-success' ?>">
                                                <?= $c['activo'] ? 'Suspender' : 'Activar' ?>
                                            </button>
                                        </form>
                                        <form method="POST" style="display:inline" onsubmit="return confirmDelete('este cliente y todas sus estaciones')">
                                            <?= csrfField() ?>
                                            <input type="hidden" name="action" value="delete">
                                            <input type="hidden" name="id" value="<?= $c['id'] ?>">
                                            <button type="submit" class="btn btn-sm btn-danger"><?= cpIcon('trash',14) ?></button>
                                        </form>
                                    </div>
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
