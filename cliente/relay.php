<?php
ob_start();
/**
 * CastPanel v2.3 - Relay Redireccionamiento (Estilo NexoAir)
 * Permite redirigir la emisión a fuentes externas con programación.
 */
$pageTitle = 'Relay Redireccionamiento';
$pageIcon = 'satellite';
require_once __DIR__ . '/../includes/layout_header.php';

if (!$estacion) { echo '<div class="alert alert-warning">Sin estaci&oacute;n asignada.</div>'; require_once __DIR__ . '/../includes/layout_footer.php'; exit; }

$db = getDB();

// Procesar acciones
if ($_SERVER['REQUEST_METHOD'] === 'POST' && verifyCsrf()) {
    $action = $_POST['action'] ?? '';

    try {
        if ($action === 'create') {
            $nombre = trim($_POST['nombre']);
            $url_fuente = trim($_POST['url_fuente']);
            if (!$nombre || !$url_fuente) { setFlash('error', 'Nombre y URL son obligatorios.'); header('Location: relay.php'); exit; }

            $tipo_horario = $_POST['tipo_horario'] ?? '24x7';
            $dias = ($tipo_horario === 'semanal') ? implode(',', $_POST['dias'] ?? []) : '';
            $fecha_unica = ($tipo_horario === 'fecha_unica') ? ($_POST['fecha_unica'] ?? '') : '';
            $hora_inicio = $_POST['hora_inicio'] ?? '00:00';
            $hora_fin    = $_POST['hora_fin'] ?? '23:59';
            $prioridad   = (int)($_POST['prioridad'] ?? 0);

            $db->prepare("INSERT INTO relay_redireccionamiento (estacion_id, nombre, url_fuente, tipo_horario, dias, fecha_unica, hora_inicio, hora_fin, prioridad) VALUES (?,?,?,?,?,?,?,?,?)")
                ->execute([$estacion['id'], $nombre, $url_fuente, $tipo_horario, $dias, $fecha_unica, $hora_inicio, $hora_fin, $prioridad]);

            setFlash('success', "Relay '$nombre' creado.");
            header('Location: relay.php'); exit;
        }

        if ($action === 'toggle') {
            $id = (int)$_POST['relay_id'];
            $db->prepare("UPDATE relay_redireccionamiento SET activo = NOT activo WHERE id = ? AND estacion_id = ?")->execute([$id, $estacion['id']]);
            header('Location: relay.php'); exit;
        }

        if ($action === 'delete') {
            $id = (int)$_POST['relay_id'];
            $db->prepare("DELETE FROM relay_redireccionamiento WHERE id = ? AND estacion_id = ?")->execute([$id, $estacion['id']]);
            setFlash('success', 'Relay eliminado.');
            header('Location: relay.php'); exit;
        }

        if ($action === 'update') {
            $id = (int)$_POST['relay_id'];
            $nombre = trim($_POST['nombre']);
            $url_fuente = trim($_POST['url_fuente']);
            $tipo_horario = $_POST['tipo_horario'] ?? '24x7';
            $dias = ($tipo_horario === 'semanal') ? implode(',', $_POST['dias'] ?? []) : '';
            $fecha_unica = ($tipo_horario === 'fecha_unica') ? ($_POST['fecha_unica'] ?? '') : '';
            $hora_inicio = $_POST['hora_inicio'] ?? '00:00';
            $hora_fin    = $_POST['hora_fin'] ?? '23:59';
            $prioridad   = (int)($_POST['prioridad'] ?? 0);

            $db->prepare("UPDATE relay_redireccionamiento SET nombre=?, url_fuente=?, tipo_horario=?, dias=?, fecha_unica=?, hora_inicio=?, hora_fin=?, prioridad=? WHERE id=? AND estacion_id=?")
                ->execute([$nombre, $url_fuente, $tipo_horario, $dias, $fecha_unica, $hora_inicio, $hora_fin, $prioridad, $id, $estacion['id']]);

            setFlash('success', 'Relay actualizado.');
            header('Location: relay.php'); exit;
        }

        if ($action === 'test_signal') {
            $url = trim($_POST['test_url']);
            // Simple test: check if URL is reachable via HTTP headers
            $headers = @get_headers($url);
            if ($headers && strpos($headers[0], '200') !== false) {
                setFlash('success', "&#x2705; Se&ntilde;al OK: La fuente responde correctamente.");
            } elseif ($headers) {
                setFlash('warning', "&#x26A0; La fuente responde pero con estado: " . htmlspecialchars($headers[0]));
            } else {
                setFlash('error', "&#x274C; No se pudo conectar a la fuente. Verifique la URL.");
            }
            header('Location: relay.php'); exit;
        }
    } catch (PDOException $e) {
        setFlash('error', 'Error: ' . $e->getMessage());
        header('Location: relay.php'); exit;
    }
}

// Obtener relays existentes
$relays = [];
try {
    $stmt = $db->prepare("SELECT * FROM relay_redireccionamiento WHERE estacion_id = ? ORDER BY prioridad DESC, nombre");
    $stmt->execute([$estacion['id']]);
    $relays = $stmt->fetchAll();
} catch (PDOException $e) {}

// Editando?
$editRelay = null;
if (isset($_GET['edit'])) {
    $editId = (int)$_GET['edit'];
    foreach ($relays as $r) {
        if ($r['id'] == $editId) { $editRelay = $r; break; }
    }
}

$diasSemana = ['lunes'=>'Lun','martes'=>'Mar','miercoles'=>'Mi&eacute;','jueves'=>'Jue','viernes'=>'Vie','sabado'=>'S&aacute;b','domingo'=>'Dom'];
?>

<!-- Tabs -->
<div class="tabs">
    <a href="listas.php" class="tab-btn"><?= cpIcon('music',14) ?> Listas de Reproducci&oacute;n</a>
    <a href="autodj.php?tab=programacion" class="tab-btn"><?= cpIcon('clock',14) ?> Programaci&oacute;n</a>
    <a href="rotacion_carpetas.php" class="tab-btn"><?= cpIcon('refresh',14) ?> Rotaci&oacute;n de Carpetas</a>
    <a href="relay.php" class="tab-btn active"><?= cpIcon('satellite',14) ?> Relay</a>
    <a href="autodj.php?tab=config" class="tab-btn"><?= cpIcon('gear',14) ?> Administrar AutoDJ</a>
</div>

<!-- Info -->
<div class="alert alert-info" style="margin-bottom:16px">
    <?= cpIcon('info',14) ?> El <strong>Relay Redireccionamiento</strong> permite retransmitir una fuente de audio externa. Cuando el relay est&aacute; activo y dentro del horario programado, la emisora retransmitir&aacute; la se&ntilde;al de la URL configurada en lugar del AutoDJ.
</div>

<div class="two-cols-equal">
    <!-- Formulario -->
    <div>
        <div class="card">
            <div class="card-header">
                <h3><?= $editRelay ? cpIcon('pencil',14) . ' Editar Relay' : cpIcon('plus',14) . ' Nuevo Relay' ?></h3>
                <?php if ($editRelay): ?>
                    <a href="relay.php" class="btn btn-outline btn-sm">Cancelar</a>
                <?php endif; ?>
            </div>
            <div class="card-body">
                <form method="POST">
                    <?= csrfField() ?>
                    <input type="hidden" name="action" value="<?= $editRelay ? 'update' : 'create' ?>">
                    <?php if ($editRelay): ?>
                        <input type="hidden" name="relay_id" value="<?= $editRelay['id'] ?>">
                    <?php endif; ?>

                    <div class="form-group">
                        <label>Nombre del Relay *</label>
                        <input type="text" name="nombre" class="form-control" placeholder="Ej: Radio Nacional FM" required value="<?= htmlspecialchars($editRelay['nombre'] ?? '') ?>">
                    </div>

                    <div class="form-group">
                        <label>URL de la Fuente Externa *</label>
                        <input type="url" name="url_fuente" class="form-control" placeholder="https://stream.ejemplo.com:8000/live" required value="<?= htmlspecialchars($editRelay['url_fuente'] ?? '') ?>">
                        <small style="color:var(--text-secondary)">URL del stream de audio externo (Icecast, SHOUTcast, HTTP stream).</small>
                    </div>

                    <!-- Tipo de horario -->
                    <div class="form-group">
                        <label>Tipo de Horario</label>
                        <?php $tipoH = $editRelay['tipo_horario'] ?? '24x7'; ?>
                        <div style="display:flex;gap:6px;flex-wrap:wrap">
                            <label style="display:flex;align-items:center;gap:4px;padding:8px 12px;border:1px solid var(--border);border-radius:6px;cursor:pointer">
                                <input type="radio" name="tipo_horario" value="24x7" <?= $tipoH === '24x7' ? 'checked' : '' ?> onchange="updateHorarioUI()"> 24/7
                            </label>
                            <label style="display:flex;align-items:center;gap:4px;padding:8px 12px;border:1px solid var(--border);border-radius:6px;cursor:pointer">
                                <input type="radio" name="tipo_horario" value="semanal" <?= $tipoH === 'semanal' ? 'checked' : '' ?> onchange="updateHorarioUI()"> D&iacute;as Semanales
                            </label>
                            <label style="display:flex;align-items:center;gap:4px;padding:8px 12px;border:1px solid var(--border);border-radius:6px;cursor:pointer">
                                <input type="radio" name="tipo_horario" value="fecha_unica" <?= $tipoH === 'fecha_unica' ? 'checked' : '' ?> onchange="updateHorarioUI()"> Fecha &Uacute;nica
                            </label>
                        </div>
                    </div>

                    <!-- Días (semanal) -->
                    <div id="diasPanel" style="<?= $tipoH === 'semanal' ? '' : 'display:none' ?>">
                        <div class="form-group">
                            <label>D&iacute;as Activos</label>
                            <div style="display:flex;gap:4px;flex-wrap:wrap">
                                <?php
                                $relayDias = $editRelay ? explode(',', $editRelay['dias']) : [];
                                foreach ($diasSemana as $dKey => $dLabel): ?>
                                    <label style="display:flex;align-items:center;gap:4px;padding:6px 10px;border:1px solid var(--border);border-radius:6px;cursor:pointer;font-size:13px">
                                        <input type="checkbox" name="dias[]" value="<?= $dKey ?>" <?= in_array($dKey, $relayDias) ? 'checked' : '' ?>>
                                        <?= $dLabel ?>
                                    </label>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>

                    <!-- Fecha única -->
                    <div id="fechaPanel" style="<?= $tipoH === 'fecha_unica' ? '' : 'display:none' ?>">
                        <div class="form-group">
                            <label>Fecha</label>
                            <input type="date" name="fecha_unica" class="form-control" value="<?= $editRelay['fecha_unica'] ?? '' ?>">
                        </div>
                    </div>

                    <!-- Horarios -->
                    <div id="horariosPanel" style="<?= $tipoH === '24x7' ? 'display:none' : '' ?>">
                        <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px">
                            <div class="form-group">
                                <label>Hora Inicio</label>
                                <input type="time" name="hora_inicio" class="form-control" value="<?= $editRelay['hora_inicio'] ?? '00:00' ?>">
                            </div>
                            <div class="form-group">
                                <label>Hora Fin</label>
                                <input type="time" name="hora_fin" class="form-control" value="<?= $editRelay['hora_fin'] ?? '23:59' ?>">
                            </div>
                        </div>
                    </div>

                    <div class="form-group">
                        <label>Prioridad</label>
                        <input type="number" name="prioridad" class="form-control" value="<?= $editRelay['prioridad'] ?? 0 ?>" min="0" max="100">
                        <small style="color:var(--text-secondary)">Mayor n&uacute;mero = mayor prioridad sobre otros relays activos.</small>
                    </div>

                    <button type="submit" class="btn btn-success btn-block"><?= $editRelay ? cpIcon('save',14) . ' Guardar Cambios' : cpIcon('plus',14) . ' Crear Relay' ?></button>
                </form>
            </div>
        </div>

        <!-- Test de señal -->
        <div class="card" style="margin-top:16px">
            <div class="card-header">
                <h3><?= cpIcon('search',18) ?> Probar Se&ntilde;al</h3>
            </div>
            <div class="card-body">
                <form method="POST" style="display:flex;gap:8px">
                    <?= csrfField() ?>
                    <input type="hidden" name="action" value="test_signal">
                    <input type="url" name="test_url" class="form-control" placeholder="https://stream.ejemplo.com:8000/live" required style="flex:1">
                    <button type="submit" class="btn btn-primary"><?= cpIcon('satellite',14) ?> Probar</button>
                </form>
            </div>
        </div>
    </div>

    <!-- Relays existentes -->
    <div>
        <div class="card">
            <div class="card-header">
                <h3><?= cpIcon('satellite',18) ?> Relays Configurados</h3>
                <span class="badge"><?= count($relays) ?></span>
            </div>
            <div class="card-body no-padding">
                <?php if (empty($relays)): ?>
                    <div class="empty-state">
                        <p style="margin-bottom:8px"><?= cpIcon('satellite',40) ?></p>
                        <p>No hay relays configurados.</p>
                        <p style="font-size:12px;color:var(--text-secondary)">Crea un relay con el formulario de la izquierda.</p>
                    </div>
                <?php else: ?>
                    <?php foreach ($relays as $relay): ?>
                        <div style="padding:14px 16px;border-bottom:1px solid var(--border)">
                            <div style="display:flex;justify-content:space-between;align-items:start;margin-bottom:8px">
                                <div style="display:flex;align-items:center;gap:10px">
                                    <div style="width:38px;height:38px;border-radius:50%;background:<?= $relay['activo'] ? 'linear-gradient(135deg,#059669,#10b981)' : 'var(--border)' ?>;display:flex;align-items:center;justify-content:center;flex-shrink:0">
                                        <span style="color:#fff"><?= cpIcon('satellite',16) ?></span>
                                    </div>
                                    <div>
                                        <strong style="font-size:14px"><?= htmlspecialchars($relay['nombre']) ?></strong>
                                        <div style="font-size:11px;color:var(--text-secondary);word-break:break-all;max-width:280px">
                                            <?= htmlspecialchars($relay['url_fuente']) ?>
                                        </div>
                                    </div>
                                </div>
                                <div style="display:flex;gap:4px;flex-shrink:0">
                                    <a href="relay.php?edit=<?= $relay['id'] ?>" class="btn btn-outline btn-sm"><?= cpIcon('pencil',14) ?></a>
                                    <form method="POST" style="display:inline">
                                        <?= csrfField() ?>
                                        <input type="hidden" name="action" value="toggle">
                                        <input type="hidden" name="relay_id" value="<?= $relay['id'] ?>">
                                        <button class="btn btn-outline btn-sm"><?= $relay['activo'] ? cpIcon('pause',14) : cpIcon('play',14) ?></button>
                                    </form>
                                    <form method="POST" style="display:inline" onsubmit="return confirm('¿Eliminar este relay?')">
                                        <?= csrfField() ?>
                                        <input type="hidden" name="action" value="delete">
                                        <input type="hidden" name="relay_id" value="<?= $relay['id'] ?>">
                                        <button class="btn btn-danger btn-sm"><?= cpIcon('trash',14) ?></button>
                                    </form>
                                </div>
                            </div>

                            <!-- Estado y horario -->
                            <div style="display:flex;gap:6px;flex-wrap:wrap;align-items:center">
                                <span class="badge <?= $relay['activo'] ? 'badge-success' : 'badge-danger' ?>"><?= $relay['activo'] ? 'Activo' : 'Inactivo' ?></span>
                                <span style="font-size:11px;padding:2px 8px;border-radius:10px;background:var(--hover-bg);border:1px solid var(--border)">
                                    <?php if ($relay['tipo_horario'] === '24x7'): ?>
                                        <?= cpIcon('clock',12) ?> 24/7
                                    <?php elseif ($relay['tipo_horario'] === 'semanal'): ?>
                                        <?= cpIcon('clock',12) ?> <?= $relay['hora_inicio'] ?> - <?= $relay['hora_fin'] ?>
                                    <?php else: ?>
                                        <?= cpIcon('clock',12) ?> <?= $relay['fecha_unica'] ?> <?= $relay['hora_inicio'] ?> - <?= $relay['hora_fin'] ?>
                                    <?php endif; ?>
                                </span>
                                <span style="font-size:11px;color:var(--text-secondary)">Prioridad: <?= $relay['prioridad'] ?></span>
                            </div>

                            <?php if ($relay['tipo_horario'] === 'semanal' && $relay['dias']): ?>
                                <div style="display:flex;gap:3px;margin-top:6px">
                                    <?php
                                    $relDias = explode(',', $relay['dias']);
                                    foreach ($diasSemana as $dKey => $dLabel): ?>
                                        <span style="font-size:10px;padding:2px 6px;border-radius:3px;<?= in_array($dKey, $relDias) ? 'background:var(--primary);color:#fff' : 'background:var(--border);color:var(--text-secondary)' ?>"><?= $dLabel ?></span>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<script>
function updateHorarioUI() {
    const tipo = document.querySelector('input[name="tipo_horario"]:checked')?.value || '24x7';
    document.getElementById('diasPanel').style.display    = tipo === 'semanal' ? '' : 'none';
    document.getElementById('fechaPanel').style.display   = tipo === 'fecha_unica' ? '' : 'none';
    document.getElementById('horariosPanel').style.display = tipo === '24x7' ? 'none' : '';
}
</script>

<?php require_once __DIR__ . '/../includes/layout_footer.php'; ?>
