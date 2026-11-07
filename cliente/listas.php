<?php
ob_start();
/**
 * CastPanel v2.3 - Listas de Reproducción (Estilo SonicPanel)
 */
$pageTitle = 'Listas de Reproducción';
$pageIcon = 'music';
require_once __DIR__ . '/../includes/layout_header.php';

if (!$estacion) { echo '<div class="alert alert-warning">Sin estaci&oacute;n asignada.</div>'; require_once __DIR__ . '/../includes/layout_footer.php'; exit; }

$db = getDB();

// Procesar acciones
if ($_SERVER['REQUEST_METHOD'] === 'POST' && verifyCsrf()) {
    $action = $_POST['action'] ?? '';

    try {
        if ($action === 'create') {
            $nombre = trim($_POST['nombre']);
            $tipo = $_POST['tipo'] ?? 'musica';
            $slug = slugify($nombre);
            if (!$nombre) { setFlash('error', 'El nombre es obligatorio.'); header('Location: listas.php'); exit; }
            $check = $db->prepare("SELECT id FROM playlists WHERE estacion_id = ? AND slug = ?");
            $check->execute([$estacion['id'], $slug]);
            if ($check->fetch()) { $slug .= '-' . substr(uniqid(), -4); }
            $db->prepare("INSERT INTO playlists (estacion_id, nombre, slug) VALUES (?,?,?)")->execute([$estacion['id'], $nombre, $slug]);
            setFlash('success', "Lista '$nombre' creada.");
            header('Location: listas.php'); exit;
        }

        if ($action === 'rename') {
            $id = (int)$_POST['playlist_id'];
            $nombre = trim($_POST['nombre']);
            $db->prepare("UPDATE playlists SET nombre = ? WHERE id = ? AND estacion_id = ?")->execute([$nombre, $id, $estacion['id']]);
            setFlash('success', 'Lista renombrada.');
            header('Location: listas.php'); exit;
        }

        if ($action === 'toggle') {
            $id = (int)$_POST['playlist_id'];
            $db->prepare("UPDATE playlists SET activa = NOT activa WHERE id = ? AND estacion_id = ?")->execute([$id, $estacion['id']]);
            header('Location: listas.php'); exit;
        }

        if ($action === 'delete') {
            $id = (int)$_POST['playlist_id'];
            $db->prepare("UPDATE canciones SET playlist_id = NULL WHERE playlist_id = ?")->execute([$id]);
            $db->prepare("DELETE FROM playlists WHERE id = ? AND estacion_id = ?")->execute([$id, $estacion['id']]);
            setFlash('success', 'Lista eliminada. Las canciones quedaron sin lista asignada.');
            header('Location: listas.php'); exit;
        }

        if ($action === 'sort_prefix') {
            $id = (int)$_POST['playlist_id'];
            $db->prepare("UPDATE canciones SET orden = titulo WHERE playlist_id = ? AND estacion_id = ?")->execute([$id, $estacion['id']]);
            setFlash('success', 'Canciones ordenadas por prefijo.');
            header('Location: listas.php?pl=' . $id); exit;
        }

        if ($action === 'sort_number') {
            $id = (int)$_POST['playlist_id'];
            // Ordenar numéricamente por nombre
            $songs = $db->prepare("SELECT id, nombre_original FROM canciones WHERE playlist_id = ? AND estacion_id = ? ORDER BY CAST(nombre_original AS UNSIGNED), nombre_original");
            $songs->execute([$id, $estacion['id']]);
            $i = 1;
            foreach ($songs->fetchAll() as $s) {
                $db->prepare("UPDATE canciones SET orden = ? WHERE id = ?")->execute([$i++, $s['id']]);
            }
            setFlash('success', 'Canciones ordenadas por n&uacute;meros.');
            header('Location: listas.php?pl=' . $id); exit;
        }
    } catch (PDOException $e) {
        setFlash('error', 'Error: ' . $e->getMessage());
        header('Location: listas.php'); exit;
    }
}

// Obtener playlists
$playlists = [];
try {
    $stmt = $db->prepare("SELECT p.*, (SELECT COUNT(*) FROM canciones c WHERE c.playlist_id = p.id) AS total_real FROM playlists p WHERE p.estacion_id = ? ORDER BY p.nombre");
    $stmt->execute([$estacion['id']]);
    $playlists = $stmt->fetchAll();
} catch (PDOException $e) {}

// Playlist seleccionada
$selectedPl = $_GET['pl'] ?? '';
$selectedPlaylist = null;
$playlistSongs = [];

if ($selectedPl) {
    foreach ($playlists as $pl) {
        if ($pl['id'] == $selectedPl) { $selectedPlaylist = $pl; break; }
    }
    if ($selectedPlaylist) {
        try {
            $stmt = $db->prepare("SELECT * FROM canciones WHERE playlist_id = ? AND estacion_id = ? ORDER BY COALESCE(orden,9999), titulo, nombre_original");
            $stmt->execute([$selectedPl, $estacion['id']]);
            $playlistSongs = $stmt->fetchAll();
        } catch (PDOException $e) {}
    }
}

// Total canciones sin playlist
$sinLista = 0;
try {
    $stmt = $db->prepare("SELECT COUNT(*) FROM canciones WHERE estacion_id = ? AND playlist_id IS NULL");
    $stmt->execute([$estacion['id']]);
    $sinLista = (int)$stmt->fetchColumn();
} catch (PDOException $e) {}
?>

<!-- Tabs -->
<div class="tabs">
    <a href="listas.php" class="tab-btn active"><?= cpIcon('music',14) ?> Listas de Reproducci&oacute;n</a>
    <a href="autodj.php?tab=programacion" class="tab-btn"><?= cpIcon('clock',14) ?> Programaci&oacute;n</a>
    <a href="rotacion_carpetas.php" class="tab-btn"><?= cpIcon('refresh',14) ?> Rotaci&oacute;n de Carpetas</a>
    <a href="relay.php" class="tab-btn"><?= cpIcon('satellite',14) ?> Relay</a>
    <a href="autodj.php?tab=config" class="tab-btn"><?= cpIcon('gear',14) ?> Administrar AutoDJ</a>
</div>

<!-- Crear nueva lista (barra superior) -->
<div class="card" style="margin-bottom:16px">
    <div style="padding:12px 16px">
        <form method="POST" style="display:flex;gap:8px;align-items:end;flex-wrap:wrap">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="create">
            <div style="flex:0 0 140px">
                <label style="font-size:12px;font-weight:600;color:var(--text-secondary);display:block;margin-bottom:4px">Tipo de Lista</label>
                <select name="tipo" class="form-control">
                    <option value="musica">M&uacute;sica</option>
                    <option value="jingles">Jingles</option>
                    <option value="promos">Promos</option>
                </select>
            </div>
            <div style="flex:1;min-width:200px">
                <label style="font-size:12px;font-weight:600;color:var(--text-secondary);display:block;margin-bottom:4px">Nombre de la Lista</label>
                <input type="text" name="nombre" class="form-control" placeholder="Ej: &Eacute;xitos del momento" required>
            </div>
            <button type="submit" class="btn btn-success">+ Crear Nueva Lista de Reproducci&oacute;n</button>
        </form>
    </div>
</div>

<!-- Selector de playlist + Acciones -->
<?php if (!empty($playlists)): ?>
<div class="card" style="margin-bottom:16px">
    <div style="padding:12px 16px;display:flex;gap:8px;align-items:center;flex-wrap:wrap">
        <div style="flex:0 0 auto">
            <select class="form-control" onchange="if(this.value)location='listas.php?pl='+this.value;else location='listas.php'" style="min-width:200px;font-weight:600">
                <option value="">-- Seleccionar Lista --</option>
                <?php foreach ($playlists as $pl): ?>
                    <option value="<?= $pl['id'] ?>" <?= $selectedPl == $pl['id'] ? 'selected' : '' ?>>
                        <?= htmlspecialchars($pl['nombre']) ?> (<?= $pl['total_real'] ?> canciones)
                        <?= $pl['activa'] ? '' : ' [Inactiva]' ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <?php if ($selectedPlaylist): ?>
            <a href="subir_musica.php?playlist=<?= $selectedPlaylist['id'] ?>" class="btn btn-success btn-sm"><?= cpIcon('upload',14) ?> Subir Archivos MP3</a>
            <a href="autodj.php?tab=programacion" class="btn btn-outline btn-sm"><?= cpIcon('clock',14) ?> Horario</a>
            <a href="listas.php?pl=<?= $selectedPlaylist['id'] ?>" class="btn btn-outline btn-sm"><?= cpIcon('refresh',14) ?> Actualizar</a>
            <form method="POST" style="display:inline">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="sort_prefix">
                <input type="hidden" name="playlist_id" value="<?= $selectedPlaylist['id'] ?>">
                <button class="btn btn-outline btn-sm"><?= cpIcon('list',14) ?> Ordenar Por Prefijo</button>
            </form>
            <form method="POST" style="display:inline">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="sort_number">
                <input type="hidden" name="playlist_id" value="<?= $selectedPlaylist['id'] ?>">
                <button class="btn btn-outline btn-sm"><?= cpIcon('list',14) ?> Ordenar Por N&uacute;meros</button>
            </form>
            <a href="biblioteca.php?playlist=<?= $selectedPlaylist['id'] ?>" class="btn btn-outline btn-sm"><?= cpIcon('library',14) ?> Biblioteca de M&uacute;sica</a>
            <form method="POST" style="display:inline" onsubmit="return confirm('¿Eliminar la lista «<?= htmlspecialchars($selectedPlaylist['nombre']) ?>»? Las canciones no se borrarán.')">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="playlist_id" value="<?= $selectedPlaylist['id'] ?>">
                <button class="btn btn-danger btn-sm"><?= cpIcon('trash',14) ?> Eliminar</button>
            </form>
        <?php endif; ?>
    </div>
</div>
<?php endif; ?>

<?php if ($selectedPlaylist): ?>
<!-- Contenido de la playlist seleccionada -->
<div class="card">
    <div class="card-header">
        <h3>
            <?php if ($selectedPlaylist['activa']): ?>
                <span style="color:var(--success)"><?= cpIcon('circle-dot',10) ?></span>
            <?php else: ?>
                <span style="color:var(--danger)"><?= cpIcon('circle-dot',10) ?></span>
            <?php endif; ?>
            <?= htmlspecialchars($selectedPlaylist['nombre']) ?>
        </h3>
        <div style="display:flex;gap:8px;align-items:center">
            <span class="badge <?= $selectedPlaylist['activa'] ? 'badge-success' : 'badge-danger' ?>">
                <?= $selectedPlaylist['activa'] ? 'Activa' : 'Inactiva' ?>
            </span>
            <form method="POST" style="display:inline">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="toggle">
                <input type="hidden" name="playlist_id" value="<?= $selectedPlaylist['id'] ?>">
                <button class="btn btn-outline btn-sm"><?= $selectedPlaylist['activa'] ? cpIcon('pause',14) . ' Desactivar' : cpIcon('play',14) . ' Activar' ?></button>
            </form>
            <button class="btn btn-outline btn-sm" onclick="openModal('renameModal')"><?= cpIcon('pencil',14) ?> Renombrar</button>
        </div>
    </div>
    <div class="card-body no-padding">
        <?php if (empty($playlistSongs)): ?>
            <div class="empty-state">
                <div style="margin-bottom:8px"><?= cpIcon('music',40) ?></div>
                <p>No hay canciones en esta lista.</p>
                <a href="subir_musica.php?playlist=<?= $selectedPlaylist['id'] ?>" class="btn btn-success" style="margin-top:12px">Subir M&uacute;sica</a>
            </div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table">
                    <thead>
                        <tr>
                            <th style="width:40px">#</th>
                            <th style="width:36px"></th>
                            <th>T&iacute;tulo</th>
                            <th>Artista</th>
                            <th>Duraci&oacute;n</th>
                            <th>Tama&ntilde;o</th>
                            <th style="width:80px">Acciones</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($playlistSongs as $i => $c): ?>
                            <tr>
                                <td style="color:var(--text-secondary);font-size:12px"><?= $i + 1 ?></td>
                                <td>
                                    <?php if ($c['caratula']): ?>
                                        <img src="<?= htmlspecialchars($c['caratula']) ?>" style="width:30px;height:30px;border-radius:4px;object-fit:cover">
                                    <?php else: ?>
                                        <div style="width:30px;height:30px;border-radius:4px;background:var(--border);display:flex;align-items:center;justify-content:center"><?= cpIcon('music',12) ?></div>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <strong style="font-size:13px"><?= htmlspecialchars($c['titulo'] ?: $c['nombre_original']) ?></strong>
                                </td>
                                <td style="font-size:13px;color:var(--text-secondary)"><?= htmlspecialchars($c['artista'] ?: '—') ?></td>
                                <td style="font-size:13px"><?= $c['duracion_segundos'] ? formatDuration($c['duracion_segundos']) : '—' ?></td>
                                <td style="font-size:13px"><?= formatBytes($c['tamano_bytes']) ?></td>
                                <td>
                                    <div style="display:flex;gap:3px">
                                        <a href="biblioteca.php?q=<?= urlencode($c['titulo'] ?: $c['nombre_original']) ?>" class="btn btn-outline btn-sm" title="Ver en biblioteca"><?= cpIcon('pencil',14) ?></a>
                                        <button type="button" class="btn btn-danger btn-sm" onclick="if(confirm('¿Quitar de la lista?')){document.getElementById('removeForm-<?= $c['id'] ?>').submit()}" title="Quitar"><?= cpIcon('x',14) ?></button>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <div style="padding:10px 16px;border-top:1px solid var(--border);font-size:12px;color:var(--text-secondary)">
                <?= count($playlistSongs) ?> canciones en la lista
            </div>
        <?php endif; ?>
    </div>
</div>

<!-- Hidden remove forms -->
<?php foreach ($playlistSongs as $c): ?>
<form method="POST" id="removeForm-<?= $c['id'] ?>" style="display:none">
    <?= csrfField() ?>
    <input type="hidden" name="action" value="move">
    <input type="hidden" name="cancion_id" value="<?= $c['id'] ?>">
    <input type="hidden" name="playlist_id" value="">
</form>
<?php endforeach; ?>

<!-- Modal renombrar -->
<div class="modal" id="renameModal">
    <div class="modal-content">
        <div class="modal-header">
            <h3><?= cpIcon('pencil',14) ?> Renombrar Lista</h3>
            <button class="modal-close" onclick="closeModal('renameModal')">&times;</button>
        </div>
        <form method="POST">
            <div class="modal-body">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="rename">
                <input type="hidden" name="playlist_id" value="<?= $selectedPlaylist['id'] ?>">
                <div class="form-group">
                    <label>Nuevo Nombre</label>
                    <input type="text" name="nombre" class="form-control" value="<?= htmlspecialchars($selectedPlaylist['nombre']) ?>" required>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline" onclick="closeModal('renameModal')">Cancelar</button>
                <button type="submit" class="btn btn-primary">Guardar</button>
            </div>
        </form>
    </div>
</div>

<?php else: ?>
<!-- Vista de todas las listas -->
<div class="card">
    <div class="card-header">
        <h3><?= cpIcon('list',18) ?> Todas las Listas</h3>
        <span style="font-size:13px;color:var(--text-secondary)"><?= count($playlists) ?> lista(s) &middot; <?= $sinLista ?> canciones sin lista</span>
    </div>
    <div class="card-body no-padding">
        <?php if (empty($playlists)): ?>
            <div class="empty-state">
                <div style="margin-bottom:8px"><?= cpIcon('list',40) ?></div>
                <p>No hay listas creadas. Crea una con el formulario de arriba.</p>
            </div>
        <?php else: ?>
            <?php foreach ($playlists as $pl): ?>
                <div class="playlist-card">
                    <div class="playlist-info">
                        <div class="playlist-icon" style="background:<?= $pl['activa'] ? 'var(--success)' : 'var(--text-secondary)' ?>"><?= cpIcon('music',14) ?></div>
                        <div>
                            <div class="playlist-name"><?= htmlspecialchars($pl['nombre']) ?></div>
                            <div class="playlist-meta">
                                <?= $pl['total_real'] ?> canciones &middot;
                                <?= $pl['activa'] ? '<span style="color:var(--success)">Activa</span>' : '<span style="color:var(--danger)">Inactiva</span>' ?>
                                <?php if (($estacion['autodj_playlist_activa'] ?? '') == $pl['id']): ?>
                                    &middot; <span style="color:var(--warning)"><?= cpIcon('star',12) ?> Principal AutoDJ</span>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                    <div style="display:flex;gap:4px">
                        <a href="listas.php?pl=<?= $pl['id'] ?>" class="btn btn-primary btn-sm"><?= cpIcon('eye',14) ?> Abrir</a>
                        <form method="POST" style="display:inline">
                            <?= csrfField() ?>
                            <input type="hidden" name="action" value="toggle">
                            <input type="hidden" name="playlist_id" value="<?= $pl['id'] ?>">
                            <button class="btn btn-outline btn-sm"><?= $pl['activa'] ? cpIcon('pause',14) : cpIcon('play',14) ?></button>
                        </form>
                        <form method="POST" style="display:inline" onsubmit="return confirm('¿Eliminar lista «<?= htmlspecialchars($pl['nombre']) ?>»?')">
                            <?= csrfField() ?>
                            <input type="hidden" name="action" value="delete">
                            <input type="hidden" name="playlist_id" value="<?= $pl['id'] ?>">
                            <button class="btn btn-danger btn-sm"><?= cpIcon('trash',14) ?></button>
                        </form>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
</div>
<?php endif; ?>

<?php require_once __DIR__ . '/../includes/layout_footer.php'; ?>
