<?php
ob_start();
/**
 * CastPanel v2.3 - Biblioteca de Música (Estilo SonicPanel)
 */
$pageTitle = 'Biblioteca de Música';
$pageIcon = 'library';
require_once __DIR__ . '/../includes/layout_header.php';

if (!$estacion) { echo '<div class="alert alert-warning">Sin estaci&oacute;n asignada.</div>'; require_once __DIR__ . '/../includes/layout_footer.php'; exit; }

$db = getDB();
require_once __DIR__ . '/../includes/autodj_schedule.php';
castpanelEnsureRotationRuleColumns();
$cpWeightLabels = [1 => 'Muy baja', 2 => 'Baja', 3 => 'Normal', 4 => 'Alta', 5 => 'Muy alta'];

// Procesar acciones
if ($_SERVER['REQUEST_METHOD'] === 'POST' && verifyCsrf()) {
    $action = $_POST['action'] ?? '';

    // Re-read release year and BPM from the files' tags (selected songs)
    if ($action === 'read_tags') {
        $ids = array_map('intval', (array)($_POST['selected_ids'] ?? []));
        $found = 0;
        if ($ids) {
            $in = implode(',', array_fill(0, count($ids), '?'));
            $st = $db->prepare("SELECT id, ruta_archivo FROM canciones WHERE estacion_id = ? AND id IN ($in)");
            $st->execute(array_merge([$estacion['id']], $ids));
            $up = $db->prepare('UPDATE canciones SET anio = COALESCE(?, anio), bpm = COALESCE(?, bpm) WHERE id = ?');
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $row) {
                if (!is_file($row['ruta_archivo'])) continue;
                $tags = getMP3Info($row['ruta_archivo']);
                if ($tags['anio'] || $tags['bpm']) { $up->execute([$tags['anio'], $tags['bpm'], $row['id']]); $found++; }
            }
        }
        $cpSchedStation = (int)$estacion['id'];
        register_shutdown_function(static function () use ($cpSchedStation) { castpanelApplyStationScheduleById($cpSchedStation); });
        setFlash('success', "Etiquetas leídas: año/BPM encontrados en $found canciones.");
        $back = preg_replace('/[^A-Za-z0-9_=&%.+-]/', '', (string)($_POST['return_qs'] ?? ''));
        header('Location: biblioteca.php' . ($back !== '' ? '?' . $back : '')); exit;
    }

    // Song weight (rotation "random by weight"): one song via fetch, or the selected ones
    if ($action === 'set_weight' || $action === 'weight_selected') {
        $peso = max(1, min(5, (int)($_POST['peso'] ?? 3)));
        $ids = $action === 'set_weight' ? [(int)($_POST['cancion_id'] ?? 0)] : array_map('intval', (array)($_POST['selected_ids'] ?? []));
        $n = 0;
        if ($ids) {
            $in = implode(',', array_fill(0, count($ids), '?'));
            $st = $db->prepare("UPDATE canciones SET peso = ? WHERE estacion_id = ? AND id IN ($in)");
            $st->execute(array_merge([$peso, $estacion['id']], $ids));
            $n = $st->rowCount();
        }
        $cpSchedStation = (int)$estacion['id'];
        register_shutdown_function(static function () use ($cpSchedStation) { castpanelApplyStationScheduleById($cpSchedStation); });
        if ($action === 'set_weight') {
            while (ob_get_level() > 0) ob_end_clean(); // drop the page/translator buffers
            header('Content-Type: application/json');
            echo json_encode(['success' => true, 'peso' => $peso]);
            exit;
        }
        setFlash('success', "Peso actualizado en $n canciones.");
        $back = preg_replace('/[^A-Za-z0-9_=&%.+-]/', '', (string)($_POST['return_qs'] ?? ''));
        header('Location: biblioteca.php' . ($back !== '' ? '?' . $back : '')); exit;
    }

    try {
        if ($action === 'rename') {
            $id = (int)$_POST['cancion_id'];
            $titulo = trim($_POST['titulo']);
            $artista = trim($_POST['artista']);
            $anio = castpanelParseYear($_POST['anio'] ?? null);
            $bpm = castpanelParseBpm($_POST['bpm'] ?? null);
            $db->prepare("UPDATE canciones SET titulo = ?, artista = ?, anio = ?, bpm = ? WHERE id = ? AND estacion_id = ?")->execute([$titulo, $artista, $anio, $bpm, $id, $estacion['id']]);
            $cpSchedStation = (int)$estacion['id'];
            register_shutdown_function(static function () use ($cpSchedStation) { castpanelApplyStationScheduleById($cpSchedStation); });
            setFlash('success', 'Canci&oacute;n actualizada.');
            header('Location: biblioteca.php'); exit;
        }

        if ($action === 'delete') {
            $id = (int)$_POST['cancion_id'];
            $stmt = $db->prepare("SELECT * FROM canciones WHERE id = ? AND estacion_id = ?");
            $stmt->execute([$id, $estacion['id']]);
            $cancion = $stmt->fetch();
            if ($cancion) {
                @unlink($cancion['ruta_archivo']);
                if ($cancion['caratula']) @unlink(BASE_PATH . '/assets/covers/' . basename($cancion['caratula']));
                $db->prepare("DELETE FROM canciones WHERE id = ?")->execute([$id]);
                if ($cancion['playlist_id']) {
                    $db->prepare("UPDATE playlists SET total_canciones = total_canciones - 1 WHERE id = ? AND total_canciones > 0")->execute([$cancion['playlist_id']]);
                }
                setFlash('success', 'Canci&oacute;n eliminada.');
            }
            header('Location: biblioteca.php'); exit;
        }

        if ($action === 'move') {
            $id = (int)$_POST['cancion_id'];
            $playlistId = $_POST['playlist_id'] ?: null;
            $stmt = $db->prepare("SELECT playlist_id FROM canciones WHERE id = ? AND estacion_id = ?");
            $stmt->execute([$id, $estacion['id']]);
            $old = $stmt->fetch();
            if ($old) {
                if ($old['playlist_id']) {
                    $db->prepare("UPDATE playlists SET total_canciones = total_canciones - 1 WHERE id = ? AND total_canciones > 0")->execute([$old['playlist_id']]);
                }
                $db->prepare("UPDATE canciones SET playlist_id = ? WHERE id = ? AND estacion_id = ?")->execute([$playlistId, $id, $estacion['id']]);
                if ($playlistId) {
                    $db->prepare("UPDATE playlists SET total_canciones = total_canciones + 1 WHERE id = ?")->execute([$playlistId]);
                }
                setFlash('success', 'Canci&oacute;n movida.');
                require_once __DIR__ . '/../includes/provisioning.php';
                $estacionFresh = loadStationWithPackage((int)$estacion['id']);
                if ($estacionFresh && stationHasAutoDJ($estacionFresh)) {
                    $active = (int)($estacionFresh['autodj_playlist_activa'] ?? 0);
                    if ($active > 0 && ($active === (int)$playlistId || $active === (int)$old['playlist_id'])) {
                        ensureAutoDJPlaying($estacionFresh);
                        clearStreamStatusCache((int)$estacion['id']);
                    }
                }
            }
            header('Location: biblioteca.php'); exit;
        }

        if ($action === 'delete_selected') {
            $ids = $_POST['selected_ids'] ?? [];
            if (!empty($ids)) {
                $deleted = 0;
                foreach ($ids as $id) {
                    $id = (int)$id;
                    $stmt = $db->prepare("SELECT * FROM canciones WHERE id = ? AND estacion_id = ?");
                    $stmt->execute([$id, $estacion['id']]);
                    $cancion = $stmt->fetch();
                    if ($cancion) {
                        @unlink($cancion['ruta_archivo']);
                        if ($cancion['caratula']) @unlink(BASE_PATH . '/assets/covers/' . basename($cancion['caratula']));
                        $db->prepare("DELETE FROM canciones WHERE id = ?")->execute([$id]);
                        if ($cancion['playlist_id']) {
                            $db->prepare("UPDATE playlists SET total_canciones = total_canciones - 1 WHERE id = ? AND total_canciones > 0")->execute([$cancion['playlist_id']]);
                        }
                        $deleted++;
                    }
                }
                setFlash('success', "$deleted canciones eliminadas.");
            }
            header('Location: biblioteca.php'); exit;
        }
    } catch (PDOException $e) {
        setFlash('error', 'Error en la operaci&oacute;n: ' . $e->getMessage());
        header('Location: biblioteca.php'); exit;
    }
}

// Filtros
$filtroPlaylist = $_GET['playlist'] ?? '';
$filtroSearch = $_GET['q'] ?? '';
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 50;
$offset = ($page - 1) * $perPage;

// Contar total
$totalCanciones = 0;
$canciones = [];
try {
    $sqlBase = "FROM canciones c LEFT JOIN playlists p ON p.id = c.playlist_id WHERE c.estacion_id = ?";
    $params = [$estacion['id']];

    if ($filtroPlaylist !== '') {
        if ($filtroPlaylist === '0') {
            $sqlBase .= " AND c.playlist_id IS NULL";
        } else {
            $sqlBase .= " AND c.playlist_id = ?";
            $params[] = (int)$filtroPlaylist;
        }
    }

    if ($filtroSearch) {
        $sqlBase .= " AND (c.titulo LIKE ? OR c.artista LIKE ? OR c.nombre_original LIKE ?)";
        $like = '%' . $filtroSearch . '%';
        $params[] = $like; $params[] = $like; $params[] = $like;
    }

    $stmtCount = $db->prepare("SELECT COUNT(*) $sqlBase");
    $stmtCount->execute($params);
    $totalCanciones = (int)$stmtCount->fetchColumn();

    $stmt = $db->prepare("SELECT c.*, p.nombre AS playlist_nombre $sqlBase ORDER BY c.created_at DESC LIMIT $perPage OFFSET $offset");
    $stmt->execute($params);
    $canciones = $stmt->fetchAll();
} catch (PDOException $e) { /* tabla no existe aún */ }

$totalPages = max(1, ceil($totalCanciones / $perPage));

// Playlists para filtro
$playlists = [];
try {
    $stmt = $db->prepare("SELECT * FROM playlists WHERE estacion_id = ? ORDER BY nombre");
    $stmt->execute([$estacion['id']]);
    $playlists = $stmt->fetchAll();
} catch (PDOException $e) { /* tabla no existe aún */ }

// Stats
$totalSize = 0;
$totalDuration = 0;
try {
    $stmt = $db->prepare("SELECT COALESCE(SUM(tamano_bytes),0), COALESCE(SUM(duracion_segundos),0) FROM canciones WHERE estacion_id = ?");
    $stmt->execute([$estacion['id']]);
    $row = $stmt->fetch(PDO::FETCH_NUM);
    $totalSize = (int)$row[0];
    $totalDuration = (int)$row[1];
} catch (PDOException $e) {}
?>

<!-- Stats Bar -->
<div style="display:flex;gap:12px;margin-bottom:16px;flex-wrap:wrap">
    <div style="flex:1;min-width:120px;padding:12px 16px;background:linear-gradient(135deg,#3b82f6,#2563eb);border-radius:var(--radius-sm);color:#fff">
        <div style="font-size:11px;opacity:.8">Total Archivos</div>
        <div style="font-size:20px;font-weight:700"><?= $totalCanciones ?></div>
    </div>
    <div style="flex:1;min-width:120px;padding:12px 16px;background:linear-gradient(135deg,#10b981,#059669);border-radius:var(--radius-sm);color:#fff">
        <div style="font-size:11px;opacity:.8">Almacenamiento</div>
        <div style="font-size:20px;font-weight:700"><?= formatBytes($totalSize) ?></div>
    </div>
    <div style="flex:1;min-width:120px;padding:12px 16px;background:linear-gradient(135deg,#f59e0b,#d97706);border-radius:var(--radius-sm);color:#fff">
        <div style="font-size:11px;opacity:.8">Duraci&oacute;n Total</div>
        <div style="font-size:20px;font-weight:700"><?= floor($totalDuration/3600) ?>h <?= floor(($totalDuration%3600)/60) ?>m</div>
    </div>
    <div style="flex:1;min-width:120px;padding:12px 16px;background:linear-gradient(135deg,#8b5cf6,#7c3aed);border-radius:var(--radius-sm);color:#fff">
        <div style="font-size:11px;opacity:.8">Listas Activas</div>
        <div style="font-size:20px;font-weight:700"><?= count($playlists) ?></div>
    </div>
</div>

<!-- Barra de herramientas -->
<div class="card" style="margin-bottom:16px">
    <div style="padding:12px 16px;display:flex;gap:8px;flex-wrap:wrap;align-items:center">
        <form method="GET" style="display:flex;gap:8px;flex:1;min-width:200px;align-items:center">
            <input type="text" name="q" placeholder="Buscar por t&iacute;tulo, artista..." value="<?= htmlspecialchars($filtroSearch) ?>" class="form-control" style="flex:1">
            <select name="playlist" class="form-control" style="width:auto;min-width:140px" onchange="this.form.submit()">
                <option value="">Todas las listas</option>
                <option value="0" <?= $filtroPlaylist === '0' ? 'selected' : '' ?>>Sin lista asignada</option>
                <?php foreach ($playlists as $pl): ?>
                    <option value="<?= $pl['id'] ?>" <?= $filtroPlaylist == $pl['id'] ? 'selected' : '' ?>><?= htmlspecialchars($pl['nombre']) ?> (<?= $pl['total_canciones'] ?? 0 ?>)</option>
                <?php endforeach; ?>
            </select>
            <button type="submit" class="btn btn-primary btn-sm">Buscar</button>
            <?php if ($filtroSearch || $filtroPlaylist): ?>
                <a href="biblioteca.php" class="btn btn-outline btn-sm">Limpiar</a>
            <?php endif; ?>
        </form>
        <a href="<?= BASE_URL ?>/cliente/subir_musica.php" class="btn btn-success btn-sm"><?= cpIcon('upload',14) ?> Subir M&uacute;sica</a>
        <a href="<?= BASE_URL ?>/cliente/listas.php" class="btn btn-outline btn-sm"><?= cpIcon('list',14) ?> Listas</a>
    </div>
</div>

<!-- Tabla de canciones -->
<div class="card">
    <div class="card-header">
        <h3><?= cpIcon('music',18) ?> Archivos de M&uacute;sica</h3>
        <span style="font-size:13px;color:var(--text-secondary)">
            P&aacute;gina <?= $page ?> de <?= $totalPages ?> &middot; <?= $totalCanciones ?> archivos
        </span>
    </div>
    <div class="card-body no-padding">
        <?php if (empty($canciones)): ?>
            <div class="empty-state">
                <div style="margin-bottom:8px"><?= cpIcon('music',40) ?></div>
                <p>No hay canciones en la biblioteca.</p>
                <a href="<?= BASE_URL ?>/cliente/subir_musica.php" class="btn btn-primary" style="margin-top:12px">Subir M&uacute;sica</a>
            </div>
        <?php else: ?>
            <form method="POST" id="formBulk">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="delete_selected">
                <div class="table-responsive">
                    <table class="table" id="tablaBiblioteca">
                        <thead>
                            <tr>
                                <th style="width:32px"><input type="checkbox" id="checkAll" onclick="document.querySelectorAll('.song-check').forEach(c=>c.checked=this.checked)"></th>
                                <th style="width:36px"></th>
                                <th>T&iacute;tulo</th>
                                <th>Artista</th>
                                <th>Lista</th>
                                <th>Duraci&oacute;n</th>
                                <th>A&ntilde;o</th>
                                <th>BPM</th>
                                <th title="Prioridad de reproducci&oacute;n en rotaciones &laquo;Aleatoria por peso&raquo;">Peso</th>
                                <th>Tama&ntilde;o</th>
                                <th style="width:100px">Acciones</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($canciones as $c): ?>
                                <tr>
                                    <td><input type="checkbox" name="selected_ids[]" value="<?= $c['id'] ?>" class="song-check"></td>
                                    <td>
                                        <?php if ($c['caratula']): ?>
                                            <img src="<?= htmlspecialchars($c['caratula']) ?>" style="width:32px;height:32px;border-radius:4px;object-fit:cover">
                                        <?php else: ?>
                                            <div style="width:32px;height:32px;border-radius:4px;background:var(--border);display:flex;align-items:center;justify-content:center"><?= cpIcon('music',14) ?></div>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <strong style="font-size:13px"><?= htmlspecialchars($c['titulo'] ?: $c['nombre_original']) ?></strong>
                                        <?php if ($c['titulo'] && $c['titulo'] !== $c['nombre_original']): ?>
                                            <div style="font-size:11px;color:var(--text-secondary)"><?= htmlspecialchars($c['nombre_original']) ?></div>
                                        <?php endif; ?>
                                    </td>
                                    <td style="font-size:13px"><?= htmlspecialchars($c['artista'] ?: '—') ?></td>
                                    <td>
                                        <?php if ($c['playlist_nombre']): ?>
                                            <span class="badge badge-success" style="font-size:11px"><?= htmlspecialchars($c['playlist_nombre']) ?></span>
                                        <?php else: ?>
                                            <span class="badge" style="font-size:11px">Sin lista</span>
                                        <?php endif; ?>
                                    </td>
                                    <td style="font-size:13px"><?= $c['duracion_segundos'] ? formatDuration($c['duracion_segundos']) : '—' ?></td>
                                    <td style="font-size:13px" translate="no"><?= !empty($c['anio']) ? (int)$c['anio'] : '—' ?></td>
                                    <td style="font-size:13px" translate="no"><?= !empty($c['bpm']) ? (int)$c['bpm'] : '—' ?></td>
                                    <td>
                                        <?php $pw = max(1, min(5, (int)($c['peso'] ?? 3))); ?>
                                        <select class="cp-weight w<?= $pw ?>" data-id="<?= (int)$c['id'] ?>" onchange="cpSetWeight(this)" aria-label="Peso">
                                            <?php foreach ($cpWeightLabels as $wv => $wl): ?>
                                                <option value="<?= $wv ?>" <?= $wv === $pw ? 'selected' : '' ?>><?= $wl ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </td>
                                    <td style="font-size:13px"><?= formatBytes($c['tamano_bytes']) ?></td>
                                    <td>
                                        <div style="display:flex;gap:3px">
                                            <button type="button" class="btn btn-outline btn-sm" onclick="openModal('editModal-<?= $c['id'] ?>')" title="Editar"><?= cpIcon('pencil',14) ?></button>
                                            <button type="button" class="btn btn-outline btn-sm" onclick="openModal('moveModal-<?= $c['id'] ?>')" title="Mover"><?= cpIcon('arrow-right',14) ?></button>
                                            <button type="button" class="btn btn-danger btn-sm" onclick="if(confirm('¿Eliminar esta canción?')){document.getElementById('delForm-<?= $c['id'] ?>').submit()}" title="Eliminar"><?= cpIcon('trash',14) ?></button>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <!-- Bulk actions bar -->
                <div style="padding:10px 16px;border-top:1px solid var(--border);display:flex;align-items:center;justify-content:space-between;gap:8px;flex-wrap:wrap">
                    <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap">
                        <button type="submit" class="btn btn-danger btn-sm" onclick="this.form.action.value='delete_selected';return confirm('¿Eliminar las canciones seleccionadas?')"><?= cpIcon('trash',14) ?> Eliminar Seleccionadas</button>
                        <input type="hidden" name="return_qs" value="<?= htmlspecialchars($_SERVER['QUERY_STRING'] ?? '') ?>">
                        <select name="peso" class="form-control" style="width:auto;height:31px;padding:2px 8px;font-size:12.5px" aria-label="Peso">
                            <?php foreach ($cpWeightLabels as $wv => $wl): ?>
                                <option value="<?= $wv ?>" <?= $wv === 3 ? 'selected' : '' ?>><?= $wl ?></option>
                            <?php endforeach; ?>
                        </select>
                        <button type="submit" class="btn btn-outline btn-sm" onclick="if(!document.querySelector('.song-check:checked')){alert('Selecciona canciones primero');return false;}this.form.action.value='weight_selected'"><?= cpIcon('star',14) ?> Asignar peso</button>
                        <button type="submit" class="btn btn-outline btn-sm" title="A&ntilde;o y BPM desde las etiquetas ID3 del archivo" onclick="if(!document.querySelector('.song-check:checked')){alert('Selecciona canciones primero');return false;}this.form.action.value='read_tags'"><?= cpIcon('refresh',14) ?> Leer a&ntilde;o/BPM</button>
                    </div>
                    <div style="font-size:12px;color:var(--text-secondary)">
                        Selecciona canciones con las casillas para acciones masivas
                    </div>
                </div>
            </form>

            <!-- Paginación -->
            <?php if ($totalPages > 1): ?>
                <div style="padding:12px 16px;border-top:1px solid var(--border);display:flex;justify-content:center;gap:4px;flex-wrap:wrap">
                    <?php if ($page > 1): ?>
                        <a href="?page=<?= $page-1 ?>&playlist=<?= $filtroPlaylist ?>&q=<?= urlencode($filtroSearch) ?>" class="btn btn-outline btn-sm">&laquo; Anterior</a>
                    <?php endif; ?>
                    <?php for ($i = max(1,$page-2); $i <= min($totalPages,$page+2); $i++): ?>
                        <a href="?page=<?= $i ?>&playlist=<?= $filtroPlaylist ?>&q=<?= urlencode($filtroSearch) ?>" class="btn <?= $i===$page ? 'btn-primary' : 'btn-outline' ?> btn-sm"><?= $i ?></a>
                    <?php endfor; ?>
                    <?php if ($page < $totalPages): ?>
                        <a href="?page=<?= $page+1 ?>&playlist=<?= $filtroPlaylist ?>&q=<?= urlencode($filtroSearch) ?>" class="btn btn-outline btn-sm">Siguiente &raquo;</a>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</div>

<!-- Hidden delete forms -->
<?php foreach ($canciones as $c): ?>
<form method="POST" id="delForm-<?= $c['id'] ?>" style="display:none">
    <?= csrfField() ?>
    <input type="hidden" name="action" value="delete">
    <input type="hidden" name="cancion_id" value="<?= $c['id'] ?>">
</form>
<?php endforeach; ?>

<!-- MODALS (hidden by default via CSS) -->
<?php foreach ($canciones as $c): ?>
<!-- Modal editar -->
<div class="modal" id="editModal-<?= $c['id'] ?>">
    <div class="modal-content">
        <div class="modal-header">
            <h3><?= cpIcon('pencil',14) ?> Editar Canci&oacute;n</h3>
            <button class="modal-close" onclick="closeModal('editModal-<?= $c['id'] ?>')">&times;</button>
        </div>
        <form method="POST">
            <div class="modal-body">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="rename">
                <input type="hidden" name="cancion_id" value="<?= $c['id'] ?>">
                <div class="form-group">
                    <label>T&iacute;tulo</label>
                    <input type="text" name="titulo" class="form-control" value="<?= htmlspecialchars($c['titulo'] ?? '') ?>">
                </div>
                <div class="form-group">
                    <label>Artista</label>
                    <input type="text" name="artista" class="form-control" value="<?= htmlspecialchars($c['artista'] ?? '') ?>">
                </div>
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px">
                    <div class="form-group">
                        <label>A&ntilde;o de lanzamiento</label>
                        <input type="number" name="anio" class="form-control" min="1900" max="<?= (int)date('Y') + 1 ?>" placeholder="2024" value="<?= !empty($c['anio']) ? (int)$c['anio'] : '' ?>">
                    </div>
                    <div class="form-group">
                        <label>BPM</label>
                        <input type="number" name="bpm" class="form-control" min="30" max="300" placeholder="120" value="<?= !empty($c['bpm']) ? (int)$c['bpm'] : '' ?>">
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline" onclick="closeModal('editModal-<?= $c['id'] ?>')">Cancelar</button>
                <button type="submit" class="btn btn-primary">Guardar</button>
            </div>
        </form>
    </div>
</div>

<!-- Modal mover -->
<div class="modal" id="moveModal-<?= $c['id'] ?>">
    <div class="modal-content">
        <div class="modal-header">
            <h3><?= cpIcon('arrow-right',14) ?> Mover a Lista</h3>
            <button class="modal-close" onclick="closeModal('moveModal-<?= $c['id'] ?>')">&times;</button>
        </div>
        <form method="POST">
            <div class="modal-body">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="move">
                <input type="hidden" name="cancion_id" value="<?= $c['id'] ?>">
                <div class="form-group">
                    <label>Lista destino</label>
                    <select name="playlist_id" class="form-control">
                        <option value="">Sin lista</option>
                        <?php foreach ($playlists as $pl): ?>
                            <option value="<?= $pl['id'] ?>" <?= $c['playlist_id'] == $pl['id'] ? 'selected' : '' ?>><?= htmlspecialchars($pl['nombre']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline" onclick="closeModal('moveModal-<?= $c['id'] ?>')">Cancelar</button>
                <button type="submit" class="btn btn-primary">Mover</button>
            </div>
        </form>
    </div>
</div>
<?php endforeach; ?>

<style>
.cp-weight{border:1px solid var(--border);border-radius:6px;padding:3px 6px;font-size:12px;font-family:inherit;background:var(--card-bg);color:var(--text-primary);cursor:pointer}
.cp-weight.w1,.cp-weight.w2{color:#64748b}
.cp-weight.w4{color:#d97706;font-weight:600}
.cp-weight.w5{color:#dc2626;font-weight:700}
.cp-weight.saved{border-color:#10b981;box-shadow:0 0 0 2px rgba(16,185,129,.2)}
</style>
<script>
function cpSetWeight(sel) {
    var body = new URLSearchParams({ action: 'set_weight', cancion_id: sel.dataset.id, peso: sel.value,
        csrf_token: <?= json_encode(csrfToken()) ?> });
    fetch('biblioteca.php', { method: 'POST', credentials: 'same-origin', body: body })
        .then(function (r) { return r.json(); })
        .then(function (d) {
            if (!d.success) throw new Error();
            sel.className = 'cp-weight w' + d.peso + ' saved';
            setTimeout(function () { sel.classList.remove('saved'); }, 1200);
        })
        .catch(function () { alert('No se pudo guardar el peso'); });
}
document.addEventListener('DOMContentLoaded', function() {
    // Search and folder filter run on the server (?q=&playlist=). The old
    // searchTable('searchInput', ...) call had no #searchInput element, so it
    // filtered by the word "searchInput" and hid every row.
});
</script>

<?php require_once __DIR__ . '/../includes/layout_footer.php'; ?>
