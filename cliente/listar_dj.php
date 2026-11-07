<?php
ob_start();
/**
 * CastPanel v2.0 - Listar Cuentas DJ
 */
$pageTitle = 'Cuentas DJ';
$pageIcon = 'microphone';
require_once __DIR__ . '/../includes/layout_header.php';

if (!$estacion) { echo '<div class="alert alert-warning">Sin estación asignada.</div>'; require_once __DIR__ . '/../includes/layout_footer.php'; exit; }

$db = getDB();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && verifyCsrf()) {
    $action = $_POST['action'] ?? '';

    if ($action === 'toggle') {
        $id = (int)$_POST['dj_id'];
        $db->prepare("UPDATE cuentas_dj SET activo = NOT activo WHERE id = ? AND estacion_id = ?")->execute([$id, $estacion['id']]);
        header('Location: listar_dj.php'); exit;
    }

    if ($action === 'reset_password') {
        $id = (int)$_POST['dj_id'];
        $newPass = bin2hex(random_bytes(6));
        $hash = password_hash($newPass, PASSWORD_DEFAULT);
        $db->prepare("UPDATE cuentas_dj SET password = ?, password_plain = ? WHERE id = ? AND estacion_id = ?")->execute([$hash, $newPass, $id, $estacion['id']]);
        setFlash('success', "Nueva contraseña: <strong>$newPass</strong>");
        header('Location: listar_dj.php'); exit;
    }

    if ($action === 'delete') {
        $id = (int)$_POST['dj_id'];
        $db->prepare("DELETE FROM cuentas_dj WHERE id = ? AND estacion_id = ?")->execute([$id, $estacion['id']]);
        setFlash('success', 'Cuenta DJ eliminada.');
        header('Location: listar_dj.php'); exit;
    }
}

$stmt = $db->prepare("SELECT * FROM cuentas_dj WHERE estacion_id = ? ORDER BY username");
$stmt->execute([$estacion['id']]);
$djAccounts = $stmt->fetchAll();
?>

<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:20px">
    <span style="color:var(--text-secondary)"><?= count($djAccounts) ?> cuenta(s) DJ</span>
    <a href="cuenta_dj.php" class="btn btn-success btn-sm"><?= cpIcon('plus',14) ?> Crear Cuenta DJ</a>
</div>

<div class="card">
    <div class="card-body no-padding">
        <?php if (empty($djAccounts)): ?>
            <div class="empty-state">
                <p>No hay cuentas DJ creadas.</p>
                <a href="cuenta_dj.php" class="btn btn-primary">Crear Primera Cuenta</a>
            </div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table">
                    <thead>
                        <tr>
                            <th>Usuario</th>
                            <th>Nombre</th>
                            <th>Email</th>
                            <th>Estado</th>
                            <th>Último Login</th>
                            <th>Acciones</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($djAccounts as $dj): ?>
                            <tr>
                                <td><strong><?= htmlspecialchars($dj['username']) ?></strong></td>
                                <td><?= htmlspecialchars($dj['nombre'] ?: '—') ?></td>
                                <td><?= htmlspecialchars($dj['email'] ?: '—') ?></td>
                                <td>
                                    <?= $dj['activo']
                                        ? '<span class="badge badge-success">Activo</span>'
                                        : '<span class="badge badge-danger">Suspendido</span>' ?>
                                </td>
                                <td style="font-size:12px"><?= $dj['ultimo_login'] ? date('d/m/Y H:i', strtotime($dj['ultimo_login'])) : 'Nunca' ?></td>
                                <td>
                                    <div style="display:flex;gap:4px">
                                        <form method="POST" style="display:inline">
                                            <?= csrfField() ?>
                                            <input type="hidden" name="action" value="toggle">
                                            <input type="hidden" name="dj_id" value="<?= $dj['id'] ?>">
                                            <button class="btn btn-outline btn-sm"><?= $dj['activo'] ? cpIcon('pause',14) . ' Suspender' : cpIcon('play',14) . ' Activar' ?></button>
                                        </form>
                                        <form method="POST" style="display:inline" onsubmit="return confirm('¿Resetear contraseña?')">
                                            <?= csrfField() ?>
                                            <input type="hidden" name="action" value="reset_password">
                                            <input type="hidden" name="dj_id" value="<?= $dj['id'] ?>">
                                            <button class="btn btn-warning btn-sm"><?= cpIcon('lock',14) ?> Reset Pass</button>
                                        </form>
                                        <form method="POST" style="display:inline" onsubmit="return confirmDelete('¿Eliminar cuenta DJ?')">
                                            <?= csrfField() ?>
                                            <input type="hidden" name="action" value="delete">
                                            <input type="hidden" name="dj_id" value="<?= $dj['id'] ?>">
                                            <button class="btn btn-danger btn-sm"><?= cpIcon('trash',14) ?></button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/layout_footer.php'; ?>
