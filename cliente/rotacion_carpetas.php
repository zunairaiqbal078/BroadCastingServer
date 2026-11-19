<?php
ob_start();
/**
 * CastPanel v2.3 - Rotación de Carpetas (Estilo NexoAir)
 * Permite programar secuencias de playlists con cantidades, horarios y prioridad.
 */
$pageTitle = 'Rotación de Carpetas';
$pageIcon = 'refresh';
require_once __DIR__ . '/../includes/layout_header.php';

// Apply schedules/rotation/relay/ads right after a change (runs after the redirect)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($estacion['id'])) {
    require_once __DIR__ . '/../includes/autodj_schedule.php';
    $cpSchedStation = (int)$estacion['id'];
    register_shutdown_function(static function () use ($cpSchedStation) { castpanelApplyStationScheduleById($cpSchedStation); });
}

if (!$estacion) { echo '<div class="alert alert-warning">Sin estaci&oacute;n asignada.</div>'; require_once __DIR__ . '/../includes/layout_footer.php'; exit; }

$db = getDB();
require_once __DIR__ . '/../includes/autodj_schedule.php';
castpanelEnsureRotationRuleColumns();

// Mixing rules sent by the form (separation in minutes, selection mode)
$cpRotRules = static function (): array {
    $sel = $_POST['seleccion'] ?? 'auto';
    if (!in_array($sel, ['auto', 'orden', 'aleatorio', 'peso'], true)) $sel = 'auto';
    return [
        max(0, min(1440, (int)($_POST['sep_artista_min'] ?? 0))),
        max(0, min(10080, (int)($_POST['sep_cancion_min'] ?? 0))),
        $sel,
    ];
};

// Year/BPM filter of sequence row $i (empty = no filter)
$cpStepFilter = static function (int $i): array {
    $v = static function (string $name, int $min, int $max) use ($i): ?int {
        $x = (int)(($_POST[$name] ?? [])[$i] ?? 0);
        return ($x >= $min && $x <= $max) ? $x : null;
    };
    return [$v('secuencia_anio_min', 1900, 2100), $v('secuencia_anio_max', 1900, 2100), $v('secuencia_bpm_min', 30, 300), $v('secuencia_bpm_max', 30, 300)];
};

// Obtener playlists disponibles
$playlists = [];
try {
    $stmt = $db->prepare("SELECT id, nombre FROM playlists WHERE estacion_id = ? ORDER BY nombre");
    $stmt->execute([$estacion['id']]);
    $playlists = $stmt->fetchAll();
} catch (PDOException $e) {}
$cpOwnPlaylists = array_flip(array_map('intval', array_column($playlists, 'id')));

// Procesar acciones
if ($_SERVER['REQUEST_METHOD'] === 'POST' && verifyCsrf()) {
    $action = $_POST['action'] ?? '';

    try {
        if ($action === 'create') {
            $nombre = trim($_POST['nombre']);
            if (!$nombre) { setFlash('error', 'El nombre es obligatorio.'); header('Location: rotacion_carpetas.php'); exit; }

            $dias = implode(',', $_POST['dias'] ?? ['lunes','martes','miercoles','jueves','viernes','sabado','domingo']);
            $hora_inicio = $_POST['hora_inicio'] ?? '00:00';
            $hora_fin    = $_POST['hora_fin'] ?? '23:59';
            $prioridad   = (int)($_POST['prioridad'] ?? 0);

            [$sepArt, $sepSong, $sel] = $cpRotRules();
            $db->prepare("INSERT INTO rotacion_carpetas (estacion_id, nombre, dias, hora_inicio, hora_fin, prioridad, sep_artista_min, sep_cancion_min, seleccion) VALUES (?,?,?,?,?,?,?,?,?)")
                ->execute([$estacion['id'], $nombre, $dias, $hora_inicio, $hora_fin, $prioridad, $sepArt, $sepSong, $sel]);

            $rotacionId = $db->lastInsertId();

            // Guardar secuencia de playlists
            $plIds = $_POST['secuencia_playlist'] ?? [];
            $cantidades = $_POST['secuencia_cantidad'] ?? [];
            $orden = 1;
            foreach ($plIds as $i => $plId) {
                if (!$plId || !isset($cpOwnPlaylists[(int)$plId])) continue;
                $cant = max(1, (int)($cantidades[$i] ?? 1));
                $db->prepare("INSERT INTO rotacion_carpetas_secuencia (rotacion_id, playlist_id, cantidad, orden, anio_min, anio_max, bpm_min, bpm_max) VALUES (?,?,?,?,?,?,?,?)")
                    ->execute(array_merge([$rotacionId, $plId, $cant, $orden++], $cpStepFilter($i)));
            }

            setFlash('success', "Rotaci&oacute;n '$nombre' creada.");
            header('Location: rotacion_carpetas.php'); exit;
        }

        if ($action === 'toggle') {
            $id = (int)$_POST['rotacion_id'];
            $db->prepare("UPDATE rotacion_carpetas SET activa = NOT activa WHERE id = ? AND estacion_id = ?")->execute([$id, $estacion['id']]);
            header('Location: rotacion_carpetas.php'); exit;
        }

        if ($action === 'delete') {
            $id = (int)$_POST['rotacion_id'];
            $db->prepare("DELETE s FROM rotacion_carpetas_secuencia s JOIN rotacion_carpetas r ON r.id = s.rotacion_id WHERE s.rotacion_id = ? AND r.estacion_id = ?")->execute([$id, $estacion['id']]);
            $db->prepare("DELETE FROM rotacion_carpetas WHERE id = ? AND estacion_id = ?")->execute([$id, $estacion['id']]);
            setFlash('success', 'Rotaci&oacute;n eliminada.');
            header('Location: rotacion_carpetas.php'); exit;
        }

        if ($action === 'update') {
            $id = (int)$_POST['rotacion_id'];
            $nombre = trim($_POST['nombre']);
            $dias = implode(',', $_POST['dias'] ?? []);
            $hora_inicio = $_POST['hora_inicio'] ?? '00:00';
            $hora_fin    = $_POST['hora_fin'] ?? '23:59';
            $prioridad   = (int)($_POST['prioridad'] ?? 0);

            [$sepArt, $sepSong, $sel] = $cpRotRules();
            $own = $db->prepare("SELECT 1 FROM rotacion_carpetas WHERE id = ? AND estacion_id = ?");
            $own->execute([$id, $estacion['id']]);
            if (!$own->fetchColumn()) { header('Location: rotacion_carpetas.php'); exit; }
            $db->prepare("UPDATE rotacion_carpetas SET nombre=?, dias=?, hora_inicio=?, hora_fin=?, prioridad=?, sep_artista_min=?, sep_cancion_min=?, seleccion=? WHERE id=? AND estacion_id=?")
                ->execute([$nombre, $dias, $hora_inicio, $hora_fin, $prioridad, $sepArt, $sepSong, $sel, $id, $estacion['id']]);

            // Recrear secuencia
            $db->prepare("DELETE FROM rotacion_carpetas_secuencia WHERE rotacion_id = ?")->execute([$id]);
            $plIds = $_POST['secuencia_playlist'] ?? [];
            $cantidades = $_POST['secuencia_cantidad'] ?? [];
            $orden = 1;
            foreach ($plIds as $i => $plId) {
                if (!$plId || !isset($cpOwnPlaylists[(int)$plId])) continue;
                $cant = max(1, (int)($cantidades[$i] ?? 1));
                $db->prepare("INSERT INTO rotacion_carpetas_secuencia (rotacion_id, playlist_id, cantidad, orden, anio_min, anio_max, bpm_min, bpm_max) VALUES (?,?,?,?,?,?,?,?)")
                    ->execute(array_merge([$id, $plId, $cant, $orden++], $cpStepFilter($i)));
            }

            setFlash('success', 'Rotaci&oacute;n actualizada.');
            header('Location: rotacion_carpetas.php'); exit;
        }
    } catch (PDOException $e) {
        setFlash('error', 'Error: ' . $e->getMessage());
        header('Location: rotacion_carpetas.php'); exit;
    }
}

// Obtener rotaciones existentes
$rotaciones = [];
try {
    $stmt = $db->prepare("SELECT * FROM rotacion_carpetas WHERE estacion_id = ? ORDER BY prioridad DESC, nombre");
    $stmt->execute([$estacion['id']]);
    $rotaciones = $stmt->fetchAll();

    // Obtener secuencias para cada rotación
    foreach ($rotaciones as &$rot) {
        $stmt2 = $db->prepare("SELECT s.*, p.nombre AS playlist_nombre FROM rotacion_carpetas_secuencia s LEFT JOIN playlists p ON s.playlist_id = p.id WHERE s.rotacion_id = ? ORDER BY s.orden");
        $stmt2->execute([$rot['id']]);
        $rot['secuencia'] = $stmt2->fetchAll();
    }
    unset($rot);
} catch (PDOException $e) {}

// Editando?
$editRot = null;
if (isset($_GET['edit'])) {
    $editId = (int)$_GET['edit'];
    foreach ($rotaciones as $r) {
        if ($r['id'] == $editId) { $editRot = $r; break; }
    }
}

$diasSemana = ['lunes'=>'Lun','martes'=>'Mar','miercoles'=>'Mi&eacute;','jueves'=>'Jue','viernes'=>'Vie','sabado'=>'S&aacute;b','domingo'=>'Dom'];

// One sequence row: folder, quantity and an optional year/BPM filter
$cpSeqRow = static function (array $seq) use ($playlists): void {
    $fv = static fn(string $k) => !empty($seq[$k]) ? (int)$seq[$k] : '';
    $hasF = function_exists('cpSchedStepHasFilter') && cpSchedStepHasFilter($seq);
    ?>
    <div class="secuencia-row <?= $hasF ? 'show-f' : '' ?>">
        <div class="seq-main">
            <span style="cursor:grab;color:var(--text-secondary)"><?= cpIcon('menu',16) ?></span>
            <select name="secuencia_playlist[]" class="form-control" style="flex:1;min-width:0">
                <option value="">-- Seleccionar --</option>
                <?php foreach ($playlists as $pl): ?>
                    <option value="<?= $pl['id'] ?>" <?= (int)($seq['playlist_id'] ?? 0) === (int)$pl['id'] ? 'selected' : '' ?>><?= htmlspecialchars($pl['nombre']) ?></option>
                <?php endforeach; ?>
            </select>
            <input type="number" name="secuencia_cantidad[]" class="form-control" value="<?= (int)($seq['cantidad'] ?? 1) ?: 1 ?>" min="1" max="100" style="width:70px" title="Cantidad">
            <button type="button" class="btn btn-outline btn-sm seq-ftoggle <?= $hasF ? 'on' : '' ?>" onclick="this.closest('.secuencia-row').classList.toggle('show-f')" title="Filtro por a&ntilde;o / BPM"><?= cpIcon('search',14) ?></button>
            <button type="button" class="btn btn-danger btn-sm" onclick="this.closest('.secuencia-row').remove()"><?= cpIcon('x',14) ?></button>
        </div>
        <div class="seq-filter">
            <div class="seq-fg"><span>A&ntilde;o</span>
                <input type="number" name="secuencia_anio_min[]" class="form-control" min="1900" max="2100" placeholder="desde" value="<?= $fv('anio_min') ?>">
                <input type="number" name="secuencia_anio_max[]" class="form-control" min="1900" max="2100" placeholder="hasta" value="<?= $fv('anio_max') ?>"></div>
            <div class="seq-fg"><span>BPM</span>
                <input type="number" name="secuencia_bpm_min[]" class="form-control" min="30" max="300" placeholder="desde" value="<?= $fv('bpm_min') ?>">
                <input type="number" name="secuencia_bpm_max[]" class="form-control" min="30" max="300" placeholder="hasta" value="<?= $fv('bpm_max') ?>"></div>
        </div>
    </div>
    <?php
};
?>

<style>
.rot-rules{border:1px solid var(--border);border-radius:10px;padding:12px 14px 10px;margin-bottom:16px;background:var(--hover-bg, rgba(0,0,0,.015))}
.rot-rules-head{font-weight:700;font-size:13px;margin-bottom:10px;display:flex;align-items:center;gap:6px}
.rot-rule-tags{display:flex;gap:4px;flex-wrap:wrap;margin-top:4px}
.secuencia-row{display:flex;flex-direction:column;gap:6px}
.seq-main{display:flex;gap:6px;align-items:center}
.seq-filter{display:none;flex-direction:column;gap:6px;padding:8px 10px;margin-left:22px;border:1px dashed var(--border);border-radius:8px;font-size:12px;font-weight:600;color:var(--text-secondary)}
.secuencia-row.show-f .seq-filter{display:flex}
.seq-fg{display:grid;grid-template-columns:38px 1fr 1fr;gap:6px;align-items:center}
.seq-fg input{min-width:0;height:30px;padding:2px 8px;font-size:12.5px}
.seq-ftoggle.on{border-color:var(--primary);color:var(--primary);background:var(--primary-bg,#ede7fb)}
.rot-rule-tags span{font-size:10.5px;padding:2px 7px;border-radius:4px;background:var(--primary-bg, #ede7fb);color:var(--primary);font-weight:600}
</style>

<!-- Tabs -->
<div class="tabs">
    <a href="listas.php" class="tab-btn"><?= cpIcon('music',14) ?> Listas de Reproducci&oacute;n</a>
    <a href="autodj.php?tab=programacion" class="tab-btn"><?= cpIcon('clock',14) ?> Programaci&oacute;n</a>
    <a href="eventos.php" class="tab-btn"><?= cpIcon('zap',14) ?> Eventos</a>
    <a href="rotacion_carpetas.php" class="tab-btn active"><?= cpIcon('refresh',14) ?> Rotaci&oacute;n de Carpetas</a>
    <a href="relay.php" class="tab-btn"><?= cpIcon('satellite',14) ?> Relay</a>
    <a href="autodj.php?tab=config" class="tab-btn"><?= cpIcon('gear',14) ?> Administrar AutoDJ</a>
</div>

<!-- Info -->
<div class="alert alert-info" style="margin-bottom:16px">
    <?= cpIcon('info',14) ?> La <strong>Rotaci&oacute;n de Carpetas</strong> permite programar una secuencia de playlists que se reproducen autom&aacute;ticamente en el orden y cantidad especificados, dentro de los horarios configurados.
</div>

<div class="two-cols-equal">
    <!-- Formulario -->
    <div>
        <div class="card">
            <div class="card-header">
                <h3><?= $editRot ? cpIcon('pencil',14) . ' Editar Rotaci&oacute;n' : cpIcon('plus',14) . ' Nueva Rotaci&oacute;n' ?></h3>
                <?php if ($editRot): ?>
                    <a href="rotacion_carpetas.php" class="btn btn-outline btn-sm">Cancelar</a>
                <?php endif; ?>
            </div>
            <div class="card-body">
                <form method="POST" id="rotForm">
                    <?= csrfField() ?>
                    <input type="hidden" name="action" value="<?= $editRot ? 'update' : 'create' ?>">
                    <?php if ($editRot): ?>
                        <input type="hidden" name="rotacion_id" value="<?= $editRot['id'] ?>">
                    <?php endif; ?>

                    <div class="form-group">
                        <label>Nombre de la Rotaci&oacute;n *</label>
                        <input type="text" name="nombre" class="form-control" placeholder="Ej: M&uacute;sica de Ma&ntilde;ana" required value="<?= htmlspecialchars($editRot['nombre'] ?? '') ?>">
                    </div>

                    <!-- Secuencia de Playlists -->
                    <div class="form-group">
                        <label style="display:flex;justify-content:space-between;align-items:center">
                            Secuencia de Playlists
                            <button type="button" class="btn btn-outline btn-sm" onclick="addSecuencia()">+ Agregar</button>
                        </label>
                        <div id="secuenciaList" style="display:flex;flex-direction:column;gap:6px;margin-top:8px">
                            <?php foreach (($editRot && !empty($editRot['secuencia'])) ? $editRot['secuencia'] : [[]] as $seq) $cpSeqRow($seq); ?>
                        </div>
                        <template id="secuenciaTpl"><?php $cpSeqRow([]); ?></template>
                        <small style="color:var(--text-secondary)">Cantidad = n&uacute;mero de canciones a reproducir de cada carpeta/lista antes de pasar a la siguiente.</small>
                        <small style="color:var(--text-secondary);display:block;margin-top:4px">La lupa filtra la carpeta por a&ntilde;o de lanzamiento y BPM (ej: solo 2010–2026, o 100–130 BPM). Las canciones sin ese dato quedan fuera del filtro; se completan en la Biblioteca de M&uacute;sica.</small>
                    </div>

                    <!-- Días -->
                    <div class="form-group">
                        <label>D&iacute;as Activos</label>
                        <div style="display:flex;gap:4px;flex-wrap:wrap">
                            <?php
                            $editDias = $editRot ? explode(',', $editRot['dias']) : array_keys($diasSemana);
                            foreach ($diasSemana as $dKey => $dLabel): ?>
                                <label style="display:flex;align-items:center;gap:4px;padding:6px 10px;border:1px solid var(--border);border-radius:6px;cursor:pointer;font-size:13px">
                                    <input type="checkbox" name="dias[]" value="<?= $dKey ?>" <?= in_array($dKey, $editDias) ? 'checked' : '' ?>>
                                    <?= $dLabel ?>
                                </label>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <!-- Horarios -->
                    <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px">
                        <div class="form-group">
                            <label>Hora Inicio</label>
                            <input type="time" name="hora_inicio" class="form-control" value="<?= $editRot['hora_inicio'] ?? '00:00' ?>">
                        </div>
                        <div class="form-group">
                            <label>Hora Fin</label>
                            <input type="time" name="hora_fin" class="form-control" value="<?= $editRot['hora_fin'] ?? '23:59' ?>">
                        </div>
                    </div>

                    <!-- Mixing rules -->
                    <div class="rot-rules">
                        <div class="rot-rules-head"><?= cpIcon('shield',15) ?> Reglas de mezcla</div>
                        <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px">
                            <div class="form-group">
                                <label>No repetir artista en (min)</label>
                                <input type="number" name="sep_artista_min" class="form-control" min="0" max="1440" value="<?= (int)($editRot['sep_artista_min'] ?? 60) ?>">
                            </div>
                            <div class="form-group">
                                <label>No repetir canci&oacute;n en (min)</label>
                                <input type="number" name="sep_cancion_min" class="form-control" min="0" max="10080" value="<?= (int)($editRot['sep_cancion_min'] ?? 240) ?>">
                            </div>
                        </div>
                        <div class="form-group" style="margin-bottom:6px">
                            <label>Selecci&oacute;n de canciones</label>
                            <?php $selNow = $editRot['seleccion'] ?? 'auto'; ?>
                            <select name="seleccion" class="form-control">
                                <option value="auto" <?= $selNow === 'auto' ? 'selected' : '' ?>>Seg&uacute;n el modo del AutoDJ (normal / aleatorio)</option>
                                <option value="orden" <?= $selNow === 'orden' ? 'selected' : '' ?>>En orden de la carpeta</option>
                                <option value="aleatorio" <?= $selNow === 'aleatorio' ? 'selected' : '' ?>>Aleatoria</option>
                                <option value="peso" <?= $selNow === 'peso' ? 'selected' : '' ?>>Aleatoria por peso (prioridad de cada canci&oacute;n)</option>
                            </select>
                        </div>
                        <small style="color:var(--text-secondary)">0 = sin regla. Si una carpeta peque&ntilde;a (jingles, promos) no puede cumplir la regla, se usa la canci&oacute;n que hace m&aacute;s tiempo que no suena. El peso de cada canci&oacute;n (1 a 5) se cambia en la Biblioteca de M&uacute;sica.</small>
                    </div>

                    <div class="form-group">
                        <label>Prioridad</label>
                        <input type="number" name="prioridad" class="form-control" value="<?= $editRot['prioridad'] ?? 0 ?>" min="0" max="100">
                        <small style="color:var(--text-secondary)">Mayor n&uacute;mero = mayor prioridad. Las rotaciones con mayor prioridad se ejecutan primero.</small>
                    </div>

                    <button type="submit" class="btn btn-success btn-block"><?= $editRot ? cpIcon('save',14) . ' Guardar Cambios' : cpIcon('plus',14) . ' Crear Rotaci&oacute;n' ?></button>
                </form>
            </div>
        </div>
    </div>

    <!-- Rotaciones existentes -->
    <div>
        <div class="card">
            <div class="card-header">
                <h3><?= cpIcon('refresh',18) ?> Rotaciones Configuradas</h3>
                <span class="badge"><?= count($rotaciones) ?></span>
            </div>
            <div class="card-body no-padding">
                <?php if (empty($rotaciones)): ?>
                    <div class="empty-state">
                        <p style="margin-bottom:8px"><?= cpIcon('refresh',40) ?></p>
                        <p>No hay rotaciones configuradas.</p>
                        <p style="font-size:12px;color:var(--text-secondary)">Crea una rotaci&oacute;n con el formulario de la izquierda.</p>
                    </div>
                <?php else: ?>
                    <?php foreach ($rotaciones as $rot): ?>
                        <div style="padding:14px 16px;border-bottom:1px solid var(--border)">
                            <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:8px">
                                <div style="display:flex;align-items:center;gap:8px">
                                    <span style="color:<?= $rot['activa'] ? 'var(--success)' : 'var(--text-secondary)' ?>"><?= cpIcon('refresh',20) ?></span>
                                    <div>
                                        <strong style="font-size:14px"><?= htmlspecialchars($rot['nombre']) ?></strong>
                                        <div style="font-size:11px;color:var(--text-secondary)">
                                            Prioridad: <?= $rot['prioridad'] ?> &middot;
                                            <?= $rot['hora_inicio'] ?> - <?= $rot['hora_fin'] ?>
                                        </div>
                                        <?php
                                        $selLabels = ['orden' => 'En orden', 'aleatorio' => 'Aleatoria', 'peso' => 'Por peso'];
                                        $rArt = (int)($rot['sep_artista_min'] ?? 0); $rSong = (int)($rot['sep_cancion_min'] ?? 0); $rSel = $rot['seleccion'] ?? 'auto';
                                        if ($rArt || $rSong || isset($selLabels[$rSel])): ?>
                                        <div class="rot-rule-tags">
                                            <?php if ($rArt): ?><span>Artista: <?= $rArt ?> min</span><?php endif; ?>
                                            <?php if ($rSong): ?><span>Canci&oacute;n: <?= $rSong ?> min</span><?php endif; ?>
                                            <?php if (isset($selLabels[$rSel])): ?><span><?= $selLabels[$rSel] ?></span><?php endif; ?>
                                        </div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                <div style="display:flex;gap:4px;align-items:center">
                                    <span class="badge <?= $rot['activa'] ? 'badge-success' : 'badge-danger' ?>"><?= $rot['activa'] ? 'Activa' : 'Inactiva' ?></span>
                                    <a href="rotacion_carpetas.php?edit=<?= $rot['id'] ?>" class="btn btn-outline btn-sm"><?= cpIcon('pencil',14) ?></a>
                                    <form method="POST" style="display:inline">
                                        <?= csrfField() ?>
                                        <input type="hidden" name="action" value="toggle">
                                        <input type="hidden" name="rotacion_id" value="<?= $rot['id'] ?>">
                                        <button class="btn btn-outline btn-sm"><?= $rot['activa'] ? cpIcon('pause',14) : cpIcon('play',14) ?></button>
                                    </form>
                                    <form method="POST" style="display:inline" onsubmit="return confirm('¿Eliminar esta rotación?')">
                                        <?= csrfField() ?>
                                        <input type="hidden" name="action" value="delete">
                                        <input type="hidden" name="rotacion_id" value="<?= $rot['id'] ?>">
                                        <button class="btn btn-danger btn-sm"><?= cpIcon('trash',14) ?></button>
                                    </form>
                                </div>
                            </div>

                            <!-- Días -->
                            <div style="display:flex;gap:3px;margin-bottom:6px">
                                <?php
                                $rotDias = explode(',', $rot['dias']);
                                foreach ($diasSemana as $dKey => $dLabel): ?>
                                    <span style="font-size:10px;padding:2px 6px;border-radius:3px;<?= in_array($dKey, $rotDias) ? 'background:var(--primary);color:#fff' : 'background:var(--border);color:var(--text-secondary)' ?>"><?= $dLabel ?></span>
                                <?php endforeach; ?>
                            </div>

                            <!-- Secuencia -->
                            <?php if (!empty($rot['secuencia'])): ?>
                                <div style="display:flex;gap:4px;align-items:center;flex-wrap:wrap">
                                    <?php foreach ($rot['secuencia'] as $si => $seq): ?>
                                        <?php if ($si > 0): ?>
                                            <span style="color:var(--text-secondary)"><?= cpIcon('arrow-right',12) ?></span>
                                        <?php endif; ?>
                                        <span style="font-size:11px;padding:3px 8px;border-radius:4px;background:var(--hover-bg);border:1px solid var(--border)">
                                            <?= cpIcon('music',12) ?> <?= htmlspecialchars($seq['playlist_nombre'] ?? '?') ?> <strong>&times;<?= $seq['cantidad'] ?></strong>
                                            <?php
                                            $fl = [];
                                            if (!empty($seq['anio_min']) || !empty($seq['anio_max'])) $fl[] = (!empty($seq['anio_min']) ? (int)$seq['anio_min'] : '…') . '–' . (!empty($seq['anio_max']) ? (int)$seq['anio_max'] : '…');
                                            if (!empty($seq['bpm_min']) || !empty($seq['bpm_max'])) $fl[] = (!empty($seq['bpm_min']) ? (int)$seq['bpm_min'] : '…') . '–' . (!empty($seq['bpm_max']) ? (int)$seq['bpm_max'] : '…') . ' BPM';
                                            if ($fl): ?><em style="font-style:normal;color:var(--primary);font-weight:600" translate="no">(<?= implode(', ', $fl) ?>)</em><?php endif; ?>
                                        </span>
                                    <?php endforeach; ?>
                                </div>
                            <?php else: ?>
                                <span style="font-size:12px;color:var(--text-secondary)">Sin secuencia configurada</span>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<script>
function addSecuencia() {
    var tpl = document.getElementById('secuenciaTpl');
    document.getElementById('secuenciaList').appendChild(tpl.content.cloneNode(true));
}
</script>

<?php require_once __DIR__ . '/../includes/layout_footer.php'; ?>
