<?php
ob_start();
/**
 * CastPanel — Eventos Programados (Programming module)
 * Plays a file, a folder/program or a time announcement at an exact minute:
 * once on a date, every day, on chosen weekdays or every hour. Each event can
 * wait for the current song to end or cut it right away.
 */
$pageTitle = 'Eventos Programados';
$pageIcon = 'clock';
require_once __DIR__ . '/../includes/layout_header.php';

if (!$estacion) { echo '<div class="alert alert-warning">Sin estaci&oacute;n asignada.</div>'; require_once __DIR__ . '/../includes/layout_footer.php'; exit; }

require_once __DIR__ . '/../includes/provisioning.php';
require_once __DIR__ . '/../includes/autodj_events.php';
castpanelEnsureEventsTable();
$db = getDB();
$stId = (int)$estacion['id'];

$playlists = [];
$songs = [];
try {
    $st = $db->prepare('SELECT id, nombre FROM playlists WHERE estacion_id = ? ORDER BY nombre');
    $st->execute([$stId]);
    $playlists = $st->fetchAll(PDO::FETCH_ASSOC);
    $st = $db->prepare('SELECT c.id, c.titulo, c.artista, c.nombre_original, c.playlist_id FROM canciones c
                        WHERE c.estacion_id = ? AND c.activa = 1 ORDER BY c.playlist_id, c.titulo, c.nombre_original');
    $st->execute([$stId]);
    $songs = $st->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {}
$ownPl = array_flip(array_map('intval', array_column($playlists, 'id')));
$ownSong = array_flip(array_map('intval', array_column($songs, 'id')));
$plName = array_column($playlists, 'nombre', 'id');
$songName = [];
foreach ($songs as $s) {
    $t = $s['titulo'] ?: pathinfo((string)$s['nombre_original'], PATHINFO_FILENAME);
    $songName[(int)$s['id']] = ($s['artista'] ? $s['artista'] . ' - ' : '') . $t;
}
$diasSemana = ['lunes' => 'Lun', 'martes' => 'Mar', 'miercoles' => 'Mié', 'jueves' => 'Jue', 'viernes' => 'Vie', 'sabado' => 'Sáb', 'domingo' => 'Dom'];

$findEvent = static function (int $id) use ($db, $stId): ?array {
    $st = $db->prepare('SELECT * FROM eventos_programados WHERE id = ? AND estacion_id = ?');
    $st->execute([$id, $stId]);
    return $st->fetch(PDO::FETCH_ASSOC) ?: null;
};

if ($_SERVER['REQUEST_METHOD'] === 'POST' && verifyCsrf()) {
    $action = $_POST['action'] ?? '';
    try {
        if ($action === 'create' || $action === 'update') {
            $nombre = trim((string)($_POST['nombre'] ?? ''));
            $tipo = in_array($_POST['tipo'] ?? '', CP_EVENT_TYPES, true) ? $_POST['tipo'] : 'archivo';
            $rep = in_array($_POST['repeticion'] ?? '', CP_EVENT_REPEATS, true) ? $_POST['repeticion'] : 'semanal';
            $modo = ($_POST['modo'] ?? '') === 'cortar' ? 'cortar' : 'esperar';
            $cancion = (int)($_POST['cancion_id'] ?? 0);
            $pl = (int)($_POST['playlist_id'] ?? 0);
            $cantidad = max(0, min(500, (int)($_POST['cantidad'] ?? 0)));
            $aleatorio = !empty($_POST['aleatorio']) ? 1 : 0;
            $fecha = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($_POST['fecha'] ?? '')) ? $_POST['fecha'] : null;
            $dias = implode(',', array_values(array_intersect(array_keys($diasSemana), (array)($_POST['dias'] ?? []))));
            if ($rep === 'cada_hora') {
                $hora = sprintf('00:%02d:00', max(0, min(59, (int)($_POST['minuto'] ?? 0))));
            } else {
                $hora = preg_match('/^\d{2}:\d{2}$/', (string)($_POST['hora'] ?? '')) ? $_POST['hora'] . ':00' : '08:00:00';
            }

            $err = '';
            if ($nombre === '') $err = 'El nombre es obligatorio.';
            elseif ($tipo === 'archivo' && !isset($ownSong[$cancion])) $err = 'Elige el archivo a reproducir.';
            elseif ($tipo !== 'archivo' && !isset($ownPl[$pl])) $err = 'Elige la carpeta.';
            elseif ($rep === 'una_vez' && !$fecha) $err = 'Elige la fecha del evento.';
            elseif ($rep === 'semanal' && $dias === '') $err = 'Elige al menos un día.';
            if ($err) {
                setFlash('error', $err);
                header('Location: eventos.php' . ($action === 'update' ? '?edit=' . (int)$_POST['evento_id'] : '')); exit;
            }
            $vals = [$nombre, $tipo, $tipo === 'archivo' ? $cancion : null, $tipo === 'archivo' ? null : $pl, $cantidad, $aleatorio,
                     $rep, $rep === 'una_vez' ? $fecha : null, $rep === 'semanal' ? $dias : '', $hora, $modo];
            if ($action === 'create') {
                $db->prepare('INSERT INTO eventos_programados (nombre, tipo, cancion_id, playlist_id, cantidad, aleatorio, repeticion, fecha, dias, hora, modo, estacion_id)
                              VALUES (?,?,?,?,?,?,?,?,?,?,?,?)')->execute(array_merge($vals, [$stId]));
                setFlash('success', 'Evento creado.');
            } else {
                // a changed time/date fires again (ultima_ejecucion reset), and a past one-off event is re-armed
                $db->prepare('UPDATE eventos_programados SET nombre=?, tipo=?, cancion_id=?, playlist_id=?, cantidad=?, aleatorio=?, repeticion=?, fecha=?, dias=?, hora=?, modo=?,
                              activo = 1, ultima_ejecucion = NULL WHERE id = ? AND estacion_id = ?')
                   ->execute(array_merge($vals, [(int)$_POST['evento_id'], $stId]));
                setFlash('success', 'Evento actualizado.');
            }
            header('Location: eventos.php'); exit;
        }

        $ev = $findEvent((int)($_POST['evento_id'] ?? 0));
        if ($ev && $action === 'toggle') {
            $db->prepare('UPDATE eventos_programados SET activo = NOT activo WHERE id = ? AND estacion_id = ?')->execute([$ev['id'], $stId]);
        }
        if ($ev && $action === 'delete') {
            $db->prepare('DELETE FROM eventos_programados WHERE id = ? AND estacion_id = ?')->execute([$ev['id'], $stId]);
            setFlash('success', 'Evento eliminado.');
        }
        if ($ev && $action === 'run_now') {
            $est = loadStationWithPackage($stId) ?: $estacion;
            [$ok, $msg] = castpanelFireEvent($est, $ev, cpSchedNow(), true);
            $db->prepare('UPDATE eventos_programados SET ultimo_resultado = ? WHERE id = ?')->execute([($ok ? 'OK: ' : '') . $msg, $ev['id']]);
            setFlash($ok ? 'success' : 'error', $ok ? "Evento enviado al aire: $msg." : $msg);
        }
        header('Location: eventos.php'); exit;
    } catch (PDOException $e) {
        setFlash('error', 'Error: ' . $e->getMessage());
        header('Location: eventos.php'); exit;
    }
}

$st = $db->prepare('SELECT * FROM eventos_programados WHERE estacion_id = ? ORDER BY activo DESC, hora, nombre');
$st->execute([$stId]);
$eventos = $st->fetchAll(PDO::FETCH_ASSOC);
$editEv = isset($_GET['edit']) ? $findEvent((int)$_GET['edit']) : null;
$f = $editEv ?? ['nombre' => '', 'tipo' => 'carpeta', 'cancion_id' => null, 'playlist_id' => null, 'cantidad' => 0, 'aleatorio' => 0,
                 'repeticion' => 'semanal', 'fecha' => date('Y-m-d'), 'dias' => 'lunes,martes,miercoles,jueves,viernes', 'hora' => '08:00:00', 'modo' => 'esperar'];
$fDias = explode(',', (string)$f['dias']);
$fHora = substr((string)$f['hora'], 0, 5);
$fMin = (int)substr((string)$f['hora'], 3, 2);

$typeLabels = ['archivo' => 'Archivo', 'carpeta' => 'Carpeta / programa', 'hora' => 'Anuncio de la hora'];
$typeIcons = ['archivo' => 'music', 'carpeta' => 'folder', 'hora' => 'clock'];
$now = cpSchedNow();
?>
<style>
.ev-types{display:grid;grid-template-columns:repeat(3,1fr);gap:8px}
.form-group .ev-type{border:2px solid var(--border);border-radius:10px;padding:10px 8px;text-align:center;cursor:pointer;font-size:12.5px;font-weight:600;display:flex;flex-direction:column;align-items:center;gap:5px;transition:all .15s}
.form-group .ev-type input{display:none}
.ev-type:has(input:checked){border-color:var(--primary);background:var(--primary-bg,#ede7fb);color:var(--primary)}
.ev-modes{display:grid;grid-template-columns:1fr 1fr;gap:8px}
.form-group .ev-mode{border:1px solid var(--border);border-radius:10px;padding:10px 12px;cursor:pointer;font-size:12.5px;display:flex;gap:8px;align-items:flex-start}
.ev-mode:has(input:checked){border-color:var(--primary);background:var(--primary-bg,#ede7fb)}
.ev-mode strong{display:block;font-size:13px;margin-bottom:2px}
.ev-mode small{color:var(--text-secondary);line-height:1.4}
.ev-days{display:flex;gap:4px;flex-wrap:wrap}
.form-group .ev-days label{display:flex;align-items:center;gap:4px;padding:6px 10px;border:1px solid var(--border);border-radius:6px;cursor:pointer;font-size:13px}
.ev-item{padding:14px 16px;border-bottom:1px solid var(--border)}
.ev-item:last-child{border-bottom:none}
.ev-item.off{opacity:.55}
.ev-top{display:flex;justify-content:space-between;gap:10px;align-items:flex-start}
.ev-title{display:flex;gap:10px;align-items:flex-start;min-width:0}
.ev-ico{width:34px;height:34px;border-radius:9px;background:var(--primary-bg,#ede7fb);color:var(--primary);display:flex;align-items:center;justify-content:center;flex-shrink:0}
.ev-name{font-weight:700;font-size:14px}
.ev-sub{font-size:12px;color:var(--text-secondary);margin-top:2px}
.ev-tags{display:flex;gap:4px;flex-wrap:wrap;margin-top:7px}
.ev-tags span{font-size:10.5px;padding:2px 7px;border-radius:4px;background:var(--hover-bg,#f1f5f9);border:1px solid var(--border);font-weight:600}
.ev-tags .cut{background:#fee2e2;border-color:#fecaca;color:#b91c1c}
.ev-tags .next{background:#dcfce7;border-color:#bbf7d0;color:#166534}
.ev-actions{display:flex;gap:4px;flex-shrink:0;flex-wrap:wrap;justify-content:flex-end}
.ev-result{font-size:11.5px;color:var(--text-secondary);margin-top:6px}
.ev-result.err{color:#b91c1c}
.ev-hide{display:none}
@media (max-width:560px){.ev-types,.ev-modes{grid-template-columns:1fr}.ev-top{flex-direction:column}}
</style>

<div class="tabs">
    <a href="listas.php" class="tab-btn"><?= cpIcon('music',14) ?> Listas de Reproducci&oacute;n</a>
    <a href="autodj.php?tab=programacion" class="tab-btn"><?= cpIcon('clock',14) ?> Programaci&oacute;n</a>
    <a href="eventos.php" class="tab-btn active"><?= cpIcon('zap',14) ?> Eventos</a>
    <a href="rotacion_carpetas.php" class="tab-btn"><?= cpIcon('refresh',14) ?> Rotaci&oacute;n de Carpetas</a>
    <a href="relay.php" class="tab-btn"><?= cpIcon('satellite',14) ?> Relay</a>
</div>

<div class="alert alert-info" style="margin-bottom:16px">
    <?= cpIcon('info',14) ?> <span>Los eventos lanzan un archivo, una carpeta (programa grabado, noticias, bloque comercial) o el anuncio de la hora en un minuto exacto. Al terminar, el AutoDJ contin&uacute;a solo. Si hay un DJ en vivo o un relay al aire, el evento se omite.</span>
</div>

<div class="two-cols-equal">
    <div>
        <div class="card">
            <div class="card-header">
                <h3><?= $editEv ? cpIcon('pencil',14) . ' Editar evento' : cpIcon('plus',14) . ' Nuevo evento' ?></h3>
                <?php if ($editEv): ?><a href="eventos.php" class="btn btn-outline btn-sm">Cancelar</a><?php endif; ?>
            </div>
            <div class="card-body">
                <form method="POST" id="evForm">
                    <?= csrfField() ?>
                    <input type="hidden" name="action" value="<?= $editEv ? 'update' : 'create' ?>">
                    <?php if ($editEv): ?><input type="hidden" name="evento_id" value="<?= (int)$editEv['id'] ?>"><?php endif; ?>

                    <div class="form-group">
                        <label>Nombre del evento *</label>
                        <input type="text" name="nombre" class="form-control" required maxlength="128" placeholder="Ej: Noticias de la ma&ntilde;ana" value="<?= htmlspecialchars($f['nombre']) ?>">
                    </div>

                    <div class="form-group">
                        <label>&iquest;Qu&eacute; se reproduce?</label>
                        <div class="ev-types">
                            <?php foreach ($typeLabels as $k => $l): ?>
                            <label class="ev-type"><input type="radio" name="tipo" value="<?= $k ?>" <?= $f['tipo'] === $k ? 'checked' : '' ?> onchange="evSync()"><?= cpIcon($typeIcons[$k], 20) ?><?= $l ?></label>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <div class="form-group" data-show="archivo">
                        <label>Archivo</label>
                        <select name="cancion_id" class="form-control">
                            <option value="">-- Seleccionar --</option>
                            <?php $grp = false; foreach ($songs as $s):
                                $g = (int)$s['playlist_id'];
                                if ($grp !== $g) { if ($grp !== false) echo '</optgroup>'; echo '<optgroup label="' . htmlspecialchars($plName[$g] ?? 'Sin lista') . '">'; $grp = $g; } ?>
                                <option value="<?= (int)$s['id'] ?>" <?= (int)$f['cancion_id'] === (int)$s['id'] ? 'selected' : '' ?>><?= htmlspecialchars($songName[(int)$s['id']]) ?></option>
                            <?php endforeach; if ($grp !== false) echo '</optgroup>'; ?>
                        </select>
                    </div>

                    <div class="form-group" data-show="carpeta hora">
                        <label>Carpeta</label>
                        <select name="playlist_id" class="form-control">
                            <option value="">-- Seleccionar --</option>
                            <?php foreach ($playlists as $pl): ?>
                                <option value="<?= (int)$pl['id'] ?>" <?= (int)$f['playlist_id'] === (int)$pl['id'] ? 'selected' : '' ?>><?= htmlspecialchars($pl['nombre']) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <small data-show="hora" style="color:var(--text-secondary)">Sube a esta carpeta un audio por cada hora con la hora en el nombre (ej: 08.mp3, hora 8.mp3, 20.mp3). Se elige el de la hora actual.</small>
                    </div>

                    <div data-show="carpeta" style="display:grid;grid-template-columns:1fr 1fr;gap:12px">
                        <div class="form-group">
                            <label>Canciones a reproducir</label>
                            <input type="number" name="cantidad" class="form-control" min="0" max="500" value="<?= (int)$f['cantidad'] ?>">
                            <small style="color:var(--text-secondary)">0 = toda la carpeta</small>
                        </div>
                        <div class="form-group">
                            <label>Orden</label>
                            <select name="aleatorio" class="form-control">
                                <option value="0" <?= empty($f['aleatorio']) ? 'selected' : '' ?>>En orden de la carpeta</option>
                                <option value="1" <?= !empty($f['aleatorio']) ? 'selected' : '' ?>>Aleatoria</option>
                            </select>
                        </div>
                    </div>

                    <div class="form-group">
                        <label>Repetici&oacute;n</label>
                        <select name="repeticion" class="form-control" onchange="evSync()">
                            <option value="una_vez" <?= $f['repeticion'] === 'una_vez' ? 'selected' : '' ?>>Una sola vez (fecha)</option>
                            <option value="diario" <?= $f['repeticion'] === 'diario' ? 'selected' : '' ?>>Todos los d&iacute;as</option>
                            <option value="semanal" <?= $f['repeticion'] === 'semanal' ? 'selected' : '' ?>>D&iacute;as de la semana</option>
                            <option value="cada_hora" <?= $f['repeticion'] === 'cada_hora' ? 'selected' : '' ?>>Cada hora</option>
                        </select>
                    </div>

                    <div class="form-group" data-rep="una_vez">
                        <label>Fecha</label>
                        <input type="date" name="fecha" class="form-control" value="<?= htmlspecialchars((string)($f['fecha'] ?: date('Y-m-d'))) ?>">
                    </div>
                    <div class="form-group" data-rep="semanal">
                        <label>D&iacute;as</label>
                        <div class="ev-days">
                            <?php foreach ($diasSemana as $dk => $dl): ?>
                            <label><input type="checkbox" name="dias[]" value="<?= $dk ?>" <?= in_array($dk, $fDias, true) ? 'checked' : '' ?>> <?= $dl ?></label>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <div class="form-group" data-rep="una_vez diario semanal">
                        <label>Hora exacta</label>
                        <input type="time" name="hora" class="form-control" value="<?= htmlspecialchars($fHora) ?>">
                    </div>
                    <div class="form-group" data-rep="cada_hora">
                        <label>Minuto de cada hora</label>
                        <input type="number" name="minuto" class="form-control" min="0" max="59" value="<?= $fMin ?>">
                        <small style="color:var(--text-secondary)">0 = en punto (8:00, 9:00, 10:00...)</small>
                    </div>

                    <div class="form-group">
                        <label>Al llegar la hora</label>
                        <div class="ev-modes">
                            <label class="ev-mode"><input type="radio" name="modo" value="esperar" <?= $f['modo'] !== 'cortar' ? 'checked' : '' ?>>
                                <span><strong>Esperar</strong><small>Empieza cuando termina la canci&oacute;n que suena.</small></span></label>
                            <label class="ev-mode"><input type="radio" name="modo" value="cortar" <?= $f['modo'] === 'cortar' ? 'checked' : '' ?>>
                                <span><strong>Cortar</strong><small>Corta la canci&oacute;n y empieza en el minuto exacto.</small></span></label>
                        </div>
                    </div>

                    <button type="submit" class="btn btn-success btn-block"><?= $editEv ? cpIcon('save',14) . ' Guardar cambios' : cpIcon('plus',14) . ' Crear evento' ?></button>
                </form>
            </div>
        </div>
    </div>

    <div>
        <div class="card">
            <div class="card-header">
                <h3><?= cpIcon('zap',18) ?> Eventos configurados</h3>
                <span class="badge"><?= count($eventos) ?></span>
            </div>
            <div class="card-body no-padding">
                <?php if (!$eventos): ?>
                    <div class="empty-state">
                        <p style="margin-bottom:8px"><?= cpIcon('clock',40) ?></p>
                        <p>No hay eventos programados.</p>
                        <p style="font-size:12px;color:var(--text-secondary)">Crea uno con el formulario.</p>
                    </div>
                <?php endif; ?>
                <?php foreach ($eventos as $ev):
                    $what = $ev['tipo'] === 'archivo' ? ($songName[(int)$ev['cancion_id']] ?? '?') : ($plName[(int)$ev['playlist_id']] ?? '?');
                    $whatExtra = $ev['tipo'] === 'carpeta' ? ((int)$ev['cantidad'] > 0 ? (int)$ev['cantidad'] . ' canciones' : 'toda la carpeta') : '';
                    $hh = substr((string)$ev['hora'], 0, 5);
                    // [label to translate, value kept as is]
                    if ($ev['repeticion'] === 'cada_hora') $when = ['Cada hora, minuto', sprintf(':%02d', (int)substr((string)$ev['hora'], 3, 2))];
                    elseif ($ev['repeticion'] === 'una_vez') $when = ['', date('d/m/Y', strtotime((string)$ev['fecha'])) . ' ' . $hh];
                    elseif ($ev['repeticion'] === 'diario') $when = ['Todos los días', $hh];
                    else $when = [array_map(fn($d) => $diasSemana[$d] ?? $d, array_filter(explode(',', (string)$ev['dias']))), $hh];
                    $next = castpanelEventNextRun($ev, $now);
                    $res = (string)($ev['ultimo_resultado'] ?? '');
                ?>
                <div class="ev-item <?= $ev['activo'] ? '' : 'off' ?>">
                    <div class="ev-top">
                        <div class="ev-title">
                            <span class="ev-ico"><?= cpIcon($typeIcons[$ev['tipo']] ?? 'clock', 17) ?></span>
                            <div style="min-width:0">
                                <div class="ev-name"><?= htmlspecialchars($ev['nombre']) ?></div>
                                <div class="ev-sub"><span translate="no"><?= htmlspecialchars($what) ?></span><?php if ($whatExtra): ?> &middot; <span><?= $whatExtra ?></span><?php endif; ?></div>
                            </div>
                        </div>
                        <div class="ev-actions">
                            <form method="POST" style="display:inline" onsubmit="return confirm('¿Enviar este evento al aire ahora?')">
                                <?= csrfField() ?><input type="hidden" name="action" value="run_now"><input type="hidden" name="evento_id" value="<?= (int)$ev['id'] ?>">
                                <button class="btn btn-outline btn-sm" title="Ejecutar ahora"><?= cpIcon('play',14) ?></button>
                            </form>
                            <a href="eventos.php?edit=<?= (int)$ev['id'] ?>" class="btn btn-outline btn-sm" title="Editar"><?= cpIcon('pencil',14) ?></a>
                            <form method="POST" style="display:inline">
                                <?= csrfField() ?><input type="hidden" name="action" value="toggle"><input type="hidden" name="evento_id" value="<?= (int)$ev['id'] ?>">
                                <button class="btn btn-outline btn-sm" title="<?= $ev['activo'] ? 'Desactivar' : 'Activar' ?>"><?= $ev['activo'] ? cpIcon('pause',14) : cpIcon('check',14) ?></button>
                            </form>
                            <form method="POST" style="display:inline" onsubmit="return confirm('¿Eliminar este evento?')">
                                <?= csrfField() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="evento_id" value="<?= (int)$ev['id'] ?>">
                                <button class="btn btn-danger btn-sm" title="Eliminar"><?= cpIcon('trash',14) ?></button>
                            </form>
                        </div>
                    </div>
                    <div class="ev-tags">
                        <span><?php foreach ((array)$when[0] as $wi => $wl): if ($wl === '') continue; ?><?= $wi ? ', ' : '' ?><span><?= htmlspecialchars($wl) ?></span><?php endforeach; ?> <?= htmlspecialchars($when[1]) ?></span>
                        <span class="<?= $ev['modo'] === 'cortar' ? 'cut' : '' ?>"><?= $ev['modo'] === 'cortar' ? 'Corta la canción' : 'Espera la canción' ?></span>
                        <?php if (!$ev['activo']): ?><span>Inactivo</span><?php elseif ($next): ?><span class="next"><span>Próximo</span> <?= $next->format('d/m H:i') ?></span><?php endif; ?>
                    </div>
                    <?php if ($res !== ''): ?>
                        <?php $resOk = str_starts_with($res, 'OK: '); ?>
                        <div class="ev-result <?= $resOk ? '' : 'err' ?>">
                            <?= cpIcon($resOk ? 'check' : 'warning', 12) ?> <span>&Uacute;ltima ejecuci&oacute;n</span><?= $ev['ultima_ejecucion'] ? ' ' . date('d/m H:i', strtotime((string)$ev['ultima_ejecucion'])) : '' ?> &middot; <span><?= htmlspecialchars($resOk ? substr($res, 4) : $res) ?></span>
                        </div>
                    <?php endif; ?>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
</div>

<script>
function evSync() {
    var f = document.getElementById('evForm');
    var tipo = (f.querySelector('input[name=tipo]:checked') || {}).value;
    var rep = f.repeticion.value;
    f.querySelectorAll('[data-show]').forEach(function (el) { el.classList.toggle('ev-hide', el.dataset.show.split(' ').indexOf(tipo) === -1); });
    f.querySelectorAll('[data-rep]').forEach(function (el) { el.classList.toggle('ev-hide', el.dataset.rep.split(' ').indexOf(rep) === -1); });
}
evSync();
</script>

<?php require_once __DIR__ . '/../includes/layout_footer.php'; ?>
