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

// Editable permissions (same as cuenta_dj.php); AutoDJ ones only when the package has AutoDJ
$djPermisos = [
    'perm_transmitir'   => 'DJ Puede Transmitir Música',
    'perm_cambiar_pass' => 'DJ Puede Cambiar Propia Contraseña',
    'perm_subir_foto'   => 'DJ Puede Subir Foto de Perfil',
    'perm_login_panel'  => 'DJ Puede Iniciar Sesión en Panel DJ',
    'perm_start_stop'   => 'DJ Puede Iniciar/Detener Radio',
    'perm_autodj'       => 'DJ Puede Iniciar/Detener AutoDJ',
    'perm_jingles'      => 'DJ Puede Reproducir Jingles/IDs (Al Aire)',
    'perm_voice_pro'    => 'DJ Puede usar Voice Pro (Al Aire)',
    'perm_saltar'       => 'DJ Puede Saltar Canción (AutoDJ)',
];
if (empty($estacion['autodj_enabled'])) {
    unset($djPermisos['perm_autodj'], $djPermisos['perm_saltar']);
}

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
        setFlash('success', "Nueva contraseña: $newPass");
        header('Location: listar_dj.php'); exit;
    }

    if ($action === 'update') {
        $id = (int)$_POST['dj_id'];
        $email = trim((string)($_POST['email'] ?? ''));
        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            setFlash('error', 'Email no válido.');
            header('Location: listar_dj.php?edit=' . $id); exit;
        }
        $sets = ['nombre = ?', 'email = ?'];
        $vals = [mb_substr(trim((string)($_POST['nombre'] ?? '')), 0, 128), $email !== '' ? $email : null];
        foreach (array_keys($djPermisos) as $perm) {
            $sets[] = "$perm = ?";
            $vals[] = !empty($_POST[$perm]) ? 1 : 0;
        }
        $vals[] = $id;
        $vals[] = $estacion['id'];
        $db->prepare('UPDATE cuentas_dj SET ' . implode(', ', $sets) . ' WHERE id = ? AND estacion_id = ?')->execute($vals);
        setFlash('success', 'Cuenta DJ actualizada.');
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

<?php
$editDj = null;
foreach ($djAccounts as $d) {
    if ((int)$d['id'] === (int)($_GET['edit'] ?? 0)) { $editDj = $d; break; }
}
if ($editDj): ?>
<div class="card" style="margin-bottom:20px">
    <div class="card-header">
        <h3><?= cpIcon('pencil', 18) ?> Editar DJ: <?= htmlspecialchars($editDj['username']) ?></h3>
        <a href="listar_dj.php" class="btn btn-outline btn-sm">Cancelar</a>
    </div>
    <div class="card-body">
        <form method="POST" action="listar_dj.php">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="update">
            <input type="hidden" name="dj_id" value="<?= (int)$editDj['id'] ?>">
            <div class="form-row">
                <div class="form-group">
                    <label>Nombre</label>
                    <input type="text" name="nombre" class="form-control" maxlength="128" value="<?= htmlspecialchars($editDj['nombre'] ?? '') ?>">
                </div>
                <div class="form-group">
                    <label>Email</label>
                    <input type="email" name="email" class="form-control" value="<?= htmlspecialchars($editDj['email'] ?? '') ?>">
                </div>
            </div>
            <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(260px,1fr));gap:10px;margin:6px 0 16px">
                <?php foreach ($djPermisos as $perm => $label): ?>
                <label style="display:flex;align-items:center;gap:10px;padding:10px 12px;border:1px solid var(--border);border-radius:10px;cursor:pointer;font-size:13px">
                    <input type="checkbox" name="<?= $perm ?>" value="1" <?= !empty($editDj[$perm]) ? 'checked' : '' ?>>
                    <?= htmlspecialchars($label) ?>
                </label>
                <?php endforeach; ?>
            </div>
            <button type="submit" class="btn btn-primary"><?= cpIcon('save', 14) ?> Guardar cambios</button>
        </form>
    </div>
</div>
<?php endif; ?>

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
                                        <a href="listar_dj.php?edit=<?= (int)$dj['id'] ?>" class="btn btn-primary btn-sm"><?= cpIcon('pencil',14) ?> Editar</a>
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
