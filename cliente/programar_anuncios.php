<?php
ob_start();
/**
 * CastPanel v2.0 - Programar Anuncios
 */
$pageTitle = 'Programar Anuncios';
$pageIcon = 'megaphone';
require_once __DIR__ . '/../includes/layout_header.php';

if (!$estacion) { echo '<div class="alert alert-warning">Sin estación asignada.</div>'; require_once __DIR__ . '/../includes/layout_footer.php'; exit; }

$db = getDB();
$listas = [];
$programaciones = [];
$_tableError = false;

try {
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && verifyCsrf()) {
        $action = $_POST['action'] ?? '';

        if ($action === 'create') {
            $listaId = (int)$_POST['lista_id'];
            $dias = implode(',', $_POST['dias'] ?? ['todos']);
            $horaInicio = $_POST['hora_inicio'];
            $horaFin = $_POST['hora_fin'];
            $cadaCanciones = (int)$_POST['cada_x_canciones'];
            $porBloque = (int)$_POST['anuncios_por_bloque'];
            $prioridad = (int)$_POST['prioridad'];

            $db->prepare("INSERT INTO programacion_anuncios (estacion_id, lista_anuncios_id, dias, hora_inicio, hora_fin, cada_x_canciones, anuncios_por_bloque, prioridad) VALUES (?,?,?,?,?,?,?,?)")
                ->execute([$estacion['id'], $listaId, $dias, $horaInicio, $horaFin, $cadaCanciones, $porBloque, $prioridad]);
            setFlash('success', 'Programación de anuncios creada.');
            header('Location: programar_anuncios.php'); exit;
        }

        if ($action === 'delete') {
            $db->prepare("DELETE FROM programacion_anuncios WHERE id = ? AND estacion_id = ?")->execute([(int)$_POST['prog_id'], $estacion['id']]);
            setFlash('success', 'Programación eliminada.');
            header('Location: programar_anuncios.php'); exit;
        }

        if ($action === 'toggle') {
            $newState = $_POST['new_state'];
            $db->prepare("UPDATE programacion_anuncios SET estado = ? WHERE id = ? AND estacion_id = ?")->execute([$newState, (int)$_POST['prog_id'], $estacion['id']]);
            header('Location: programar_anuncios.php'); exit;
        }
    }

    // Listas de anuncios
    $stmt = $db->prepare("SELECT * FROM listas_anuncios WHERE estacion_id = ? ORDER BY nombre");
    $stmt->execute([$estacion['id']]);
    $listas = $stmt->fetchAll();

    // Programaciones
    $stmt = $db->prepare("SELECT pa.*, la.nombre AS lista_nombre FROM programacion_anuncios pa JOIN listas_anuncios la ON la.id = pa.lista_anuncios_id WHERE pa.estacion_id = ? ORDER BY pa.prioridad DESC");
    $stmt->execute([$estacion['id']]);
    $programaciones = $stmt->fetchAll();
} catch (PDOException $e) {
    $_tableError = true;
}
?>

<?php if ($_tableError): ?>
<div class="alert alert-warning"><?= cpIcon('warning',14) ?> Las tablas de programación no están disponibles. Ejecute la migración SQL: <code>mysql -u root -p castpanel &lt; /var/www/castpanel/install/schema_update_v2.3.sql</code></div>
<?php endif; ?>

<div class="alert alert-info">
    <?= cpIcon('info',14) ?> Define cada cuántas canciones se insertan bloques de anuncios. Puedes crear varias reglas con diferente frecuencia y horario.
</div>

<div class="two-cols-equal">
    <!-- Nueva programación -->
    <div class="card">
        <div class="card-header">
            <h3><?= cpIcon('plus',14) ?> Nueva Regla de Anuncios</h3>
        </div>
        <div class="card-body">
            <?php if (empty($listas)): ?>
                <div class="empty-state">
                    <p>Primero crea una <a href="anuncios.php">lista de anuncios</a> y sube spots.</p>
                </div>
            <?php else: ?>
                <form method="POST">
                    <?= csrfField() ?>
                    <input type="hidden" name="action" value="create">

                    <div class="form-group">
                        <label>Lista de Anuncios</label>
                        <select name="lista_id" class="form-control" required>
                            <?php foreach ($listas as $la): ?>
                                <option value="<?= $la['id'] ?>"><?= htmlspecialchars($la['nombre']) ?> (<?= $la['total_anuncios'] ?> spots)</option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-group">
                        <label>Días de Emisión</label>
                        <div class="check-grid">
                            <label class="check-item"><input type="checkbox" name="dias[]" value="todos" checked><span>Todos</span></label>
                            <?php foreach (['lunes'=>'Lun','martes'=>'Mar','miercoles'=>'Mié','jueves'=>'Jue','viernes'=>'Vie','sabado'=>'Sáb','domingo'=>'Dom'] as $k => $v): ?>
                                <label class="check-item"><input type="checkbox" name="dias[]" value="<?= $k ?>"><span><?= $v ?></span></label>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label>Hora inicio</label>
                            <input type="time" name="hora_inicio" class="form-control" value="00:00">
                        </div>
                        <div class="form-group">
                            <label>Hora fin</label>
                            <input type="time" name="hora_fin" class="form-control" value="23:59">
                        </div>
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label>Cada X Canciones</label>
                            <select name="cada_x_canciones" class="form-control">
                                <?php foreach ([3,4,5,6,7,8,10,12,15] as $n): ?>
                                    <option value="<?= $n ?>" <?= $n === 5 ? 'selected' : '' ?>>Cada <?= $n ?> canciones</option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <label>Spots por Bloque</label>
                            <select name="anuncios_por_bloque" class="form-control">
                                <?php for ($i = 1; $i <= 5; $i++): ?>
                                    <option value="<?= $i ?>" <?= $i === 1 ? 'selected' : '' ?>><?= $i ?> spot(s)</option>
                                <?php endfor; ?>
                            </select>
                        </div>
                    </div>

                    <div class="form-group">
                        <label>Prioridad</label>
                        <input type="number" name="prioridad" class="form-control" value="0" min="0">
                        <small style="color:var(--text-secondary)">Mayor número = mayor prioridad cuando coinciden horarios.</small>
                    </div>

                    <button type="submit" class="btn btn-success btn-block">+ Guardar Regla</button>
                </form>
            <?php endif; ?>
        </div>
    </div>

    <!-- Programaciones activas -->
    <div class="card">
        <div class="card-header">
            <h3><?= cpIcon('clock',18) ?> Reglas Activas (<?= count($programaciones) ?>)</h3>
        </div>
        <div class="card-body no-padding">
            <?php if (empty($programaciones)): ?>
                <div class="empty-state"><p>No hay reglas de anuncios configuradas.</p></div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table">
                        <thead>
                            <tr><th>Lista</th><th>Días</th><th>Horario</th><th>Frecuencia</th><th>Estado</th><th>Acciones</th></tr>
                        </thead>
                        <tbody>
                            <?php foreach ($programaciones as $p): ?>
                                <tr>
                                    <td><span class="badge badge-orange"><?= htmlspecialchars($p['lista_nombre']) ?></span></td>
                                    <td style="font-size:12px"><?= htmlspecialchars($p['dias']) ?></td>
                                    <td><?= substr($p['hora_inicio'],0,5) ?> - <?= substr($p['hora_fin'],0,5) ?></td>
                                    <td>c/<?= $p['cada_x_canciones'] ?> canc. &times;<?= $p['anuncios_por_bloque'] ?></td>
                                    <td>
                                        <?= $p['estado'] === 'activa'
                                            ? '<span class="badge badge-success">Activa</span>'
                                            : '<span class="badge badge-danger">Inactiva</span>' ?>
                                    </td>
                                    <td style="display:flex;gap:4px">
                                        <form method="POST" style="display:inline">
                                            <?= csrfField() ?>
                                            <input type="hidden" name="action" value="toggle">
                                            <input type="hidden" name="prog_id" value="<?= $p['id'] ?>">
                                            <input type="hidden" name="new_state" value="<?= $p['estado'] === 'activa' ? 'inactiva' : 'activa' ?>">
                                            <button class="btn btn-outline btn-sm"><?= $p['estado'] === 'activa' ? 'Pausar' : 'Activar' ?></button>
                                        </form>
                                        <form method="POST" style="display:inline" onsubmit="return confirmDelete('¿Eliminar esta regla?')">
                                            <?= csrfField() ?>
                                            <input type="hidden" name="action" value="delete">
                                            <input type="hidden" name="prog_id" value="<?= $p['id'] ?>">
                                            <button class="btn btn-danger btn-sm"><?= cpIcon('trash',14) ?></button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/layout_footer.php'; ?>
