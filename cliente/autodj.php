<?php
ob_start();
/**
 * CastPanel v2.4 - Administrar AutoDJ
 * Rediseno estilo SonicPanel - modulo unico plano
 * Incluye modulo de Listas de Reproduccion integrado
 */
$pageTitle = 'Administrar AutoDJ';
$pageIcon = 'gear';
require_once __DIR__ . '/../includes/layout_header.php';

if (!$estacion) { echo '<div class="alert alert-warning">Sin estaci' . chr(243) . 'n asignada.</div>'; require_once __DIR__ . '/../includes/layout_footer.php'; exit; }

$db = getDB();

// Obtener playlists (defensivo)
$playlists = [];
try {
    $stmt = $db->prepare("SELECT * FROM playlists WHERE estacion_id = ? ORDER BY nombre");
    $stmt->execute([$estacion['id']]);
    $playlists = $stmt->fetchAll();
} catch (PDOException $e) { /* tabla no existe aun */ }

// Obtener playlists activas para el selector
$playlistsActivas = [];
try {
    $stmt = $db->prepare("SELECT * FROM playlists WHERE estacion_id = ? AND activa = 1 ORDER BY nombre");
    $stmt->execute([$estacion['id']]);
    $playlistsActivas = $stmt->fetchAll();
} catch (PDOException $e) { /* tabla no existe aun */ }

// Obtener programaciones (defensivo)
$programaciones = [];
try {
    $stmt = $db->prepare("SELECT pp.*, p.nombre AS playlist_nombre FROM programacion_playlists pp JOIN playlists p ON p.id = pp.playlist_id WHERE pp.estacion_id = ? ORDER BY FIELD(pp.dia_semana,'lunes','martes','miercoles','jueves','viernes','sabado','domingo'), pp.hora_inicio");
    $stmt->execute([$estacion['id']]);
    $programaciones = $stmt->fetchAll();
} catch (PDOException $e) { /* tabla no existe aun */ }

// Procesar acciones
if ($_SERVER['REQUEST_METHOD'] === 'POST' && verifyCsrf()) {
    $action = $_POST['action'] ?? '';

    try {
        if ($action === 'update_config') {
            $stmt = $db->prepare("UPDATE estaciones SET
                codec = ?, bitrate = ?, modo_reproduccion = ?, crossfade_seconds = ?,
                titulo_radio = ?, url_web = ?, mostrar_titulos = ?,
                grabar_dj = ?, calidad_grabacion = ?, buffer_dj_delay = ?,
                autodj_playlist_activa = ?
                WHERE id = ?");
            $stmt->execute([
                $_POST['codec'], (int)$_POST['bitrate'],
                $_POST['modo_reproduccion'], (int)$_POST['crossfade'],
                $_POST['titulo_radio'], $_POST['url_web'],
                isset($_POST['mostrar_titulos']) ? 1 : 0,
                isset($_POST['grabar_dj']) ? 1 : 0,
                (int)$_POST['calidad_grabacion'], (int)$_POST['buffer_dj_delay'],
                $_POST['playlist_activa'] ?: null,
                $estacion['id']
            ]);

            // Regenerar config y reiniciar Liquidsoap automaticamente
            require_once __DIR__ . '/../includes/provisioning.php';
            $estacionActualizada = loadStationWithPackage((int)$estacion['id']);
            if ($estacionActualizada && stationHasAutoDJ($estacionActualizada)) {
                restartStation($estacionActualizada);
            }

            setFlash('success', 'Configuraci' . chr(243) . 'n del AutoDJ actualizada y servicio reiniciado.');
            header('Location: autodj.php?tab=config');
            exit;
        }

        if ($action === 'autodj_control') {
            require_once __DIR__ . '/../includes/provisioning.php';
            $cmd = $_POST['cmd'] ?? '';
            $resultado = ['success' => false, 'error' => 'Comando no reconocido'];

            // Start/restart the whole station: SHOUTcast must be running
            // before Liquidsoap, otherwise AutoDJ has nowhere to send audio
            // and the station stays Offline.
            if ($cmd === 'iniciar') {
                $resultado = startStation($estacion);
            } elseif ($cmd === 'detener') {
                $resultado = stopLiquidsoapService($estacion);
            } elseif ($cmd === 'reiniciar') {
                $resultado = restartStation($estacion);
            }

            if ($resultado['success']) {
                setFlash('success', "AutoDJ: $cmd ejecutado correctamente.");
            } else {
                setFlash('error', "AutoDJ: Error al $cmd - " . ($resultado['error'] ?? 'desconocido'));
            }
            header('Location: autodj.php?tab=config');
            exit;
        }

        if ($action === 'save_schedule') {
            $playlistId = (int)$_POST['playlist_id'];
            $dias = $_POST['dias'] ?? [];
            $horaInicio = $_POST['hora_inicio'];
            $horaFin = $_POST['hora_fin'];
            $prioridad = (int)$_POST['prioridad'];
            $estado = $_POST['estado'] ?? 'activa';

            $stmtIns = $db->prepare("INSERT INTO programacion_playlists (estacion_id, playlist_id, dia_semana, hora_inicio, hora_fin, prioridad, estado) VALUES (?,?,?,?,?,?,?)");
            foreach ($dias as $dia) {
                $stmtIns->execute([$estacion['id'], $playlistId, $dia, $horaInicio, $horaFin, $prioridad, $estado]);
            }
            setFlash('success', 'Programaci' . chr(243) . 'n guardada.');
            header('Location: autodj.php?tab=programacion');
            exit;
        }

        if ($action === 'delete_schedule') {
            $db->prepare("DELETE FROM programacion_playlists WHERE id = ? AND estacion_id = ?")->execute([(int)$_POST['schedule_id'], $estacion['id']]);
            setFlash('success', 'Programaci' . chr(243) . 'n eliminada.');
            header('Location: autodj.php?tab=programacion');
            exit;
        }

        if ($action === 'toggle_schedule') {
            $newState = $_POST['new_state'];
            $db->prepare("UPDATE programacion_playlists SET estado = ? WHERE id = ? AND estacion_id = ?")->execute([$newState, (int)$_POST['schedule_id'], $estacion['id']]);
            header('Location: autodj.php?tab=programacion');
            exit;
        }

        // ---- Acciones de Listas de Reproduccion ----
        if ($action === 'create_playlist') {
            $nombre = trim($_POST['nombre'] ?? '');
            if ($nombre !== '') {
                $slug = slugify($nombre);
                $db->prepare("INSERT INTO playlists (estacion_id, nombre, slug, activa) VALUES (?, ?, ?, 1)")->execute([$estacion['id'], $nombre, $slug]);
                setFlash('success', 'Lista de reproducci' . chr(243) . 'n creada: ' . htmlspecialchars($nombre));
            }
            header('Location: autodj.php?tab=listas');
            exit;
        }

        if ($action === 'delete_playlist') {
            $plId = (int)$_POST['playlist_id'];
            // Borrar canciones de la playlist
            $db->prepare("DELETE FROM canciones WHERE playlist_id = ? AND estacion_id = ?")->execute([$plId, $estacion['id']]);
            // Borrar la playlist
            $db->prepare("DELETE FROM playlists WHERE id = ? AND estacion_id = ?")->execute([$plId, $estacion['id']]);
            setFlash('success', 'Lista de reproducci' . chr(243) . 'n eliminada.');
            header('Location: autodj.php?tab=listas');
            exit;
        }

        if ($action === 'delete_song') {
            $songId = (int)$_POST['song_id'];
            $plId = (int)$_POST['playlist_id'];
            // Obtener info de la cancion para borrar archivo
            $stmtSong = $db->prepare("SELECT nombre_original FROM canciones WHERE id = ? AND estacion_id = ?");
            $stmtSong->execute([$songId, $estacion['id']]);
            $cancion = $stmtSong->fetch();
            if ($cancion) {
                $musicDir = '/var/www/castpanel/storage/music/' . $estacion['slug'] . '/';
                $filePath = $musicDir . $cancion['nombre_original'];
                if (file_exists($filePath)) { @unlink($filePath); }
                $db->prepare("DELETE FROM canciones WHERE id = ? AND estacion_id = ?")->execute([$songId, $estacion['id']]);
                setFlash('success', 'Canci' . chr(243) . 'n eliminada.');
            }
            header('Location: autodj.php?tab=listas&pl=' . $plId);
            exit;
        }

        if ($action === 'sort_by_prefix') {
            $plId = (int)$_POST['playlist_id'];
            $canciones = $db->prepare("SELECT id, titulo FROM canciones WHERE playlist_id = ? AND estacion_id = ? ORDER BY titulo ASC");
            $canciones->execute([$plId, $estacion['id']]);
            $orden = 0;
            $stmtOrd = $db->prepare("UPDATE canciones SET orden = ? WHERE id = ?");
            foreach ($canciones as $c) { $stmtOrd->execute([$orden++, $c['id']]); }
            setFlash('success', 'Canciones ordenadas por prefijo.');
            header('Location: autodj.php?tab=listas&pl=' . $plId);
            exit;
        }

        if ($action === 'sort_by_number') {
            $plId = (int)$_POST['playlist_id'];
            $canciones = $db->prepare("SELECT id, titulo FROM canciones WHERE playlist_id = ? AND estacion_id = ?");
            $canciones->execute([$plId, $estacion['id']]);
            $items = $canciones->fetchAll();
            usort($items, function($a, $b) {
                preg_match('/^(\d+)/', $a['titulo'], $ma);
                preg_match('/^(\d+)/', $b['titulo'], $mb);
                $na = isset($ma[1]) ? (int)$ma[1] : PHP_INT_MAX;
                $nb = isset($mb[1]) ? (int)$mb[1] : PHP_INT_MAX;
                return $na - $nb;
            });
            $stmtOrd = $db->prepare("UPDATE canciones SET orden = ? WHERE id = ?");
            foreach ($items as $i => $c) { $stmtOrd->execute([$i, $c['id']]); }
            setFlash('success', 'Canciones ordenadas por n' . chr(250) . 'meros.');
            header('Location: autodj.php?tab=listas&pl=' . $plId);
            exit;
        }

    } catch (PDOException $e) {
        setFlash('error', 'Error: ' . $e->getMessage());
        header('Location: autodj.php');
        exit;
    }
}

// Refrescar estacion y playlists
$estacion = getCurrentStation();
$playlists = [];
try {
    $stmt = $db->prepare("SELECT * FROM playlists WHERE estacion_id = ? ORDER BY nombre");
    $stmt->execute([$estacion['id']]);
    $playlists = $stmt->fetchAll();
} catch (PDOException $e) {}

// Subtabs
$subtab = $_GET['tab'] ?? 'listas';
$selectedPl = isset($_GET['pl']) ? (int)$_GET['pl'] : 0;

// Cargar canciones si hay playlist seleccionada
$canciones = [];
$playlistSeleccionada = null;
if ($subtab === 'listas' && $selectedPl > 0) {
    try {
        $stmtPl = $db->prepare("SELECT * FROM playlists WHERE id = ? AND estacion_id = ?");
        $stmtPl->execute([$selectedPl, $estacion['id']]);
        $playlistSeleccionada = $stmtPl->fetch();

        if ($playlistSeleccionada) {
            $stmtC = $db->prepare("SELECT * FROM canciones WHERE playlist_id = ? AND estacion_id = ? ORDER BY orden ASC, titulo ASC");
            $stmtC->execute([$selectedPl, $estacion['id']]);
            $canciones = $stmtC->fetchAll();
        }
    } catch (PDOException $e) {}
}
?>

<style>
/* SonicPanel-style AutoDJ */
.sp-tabs { display:flex; gap:0; border-bottom:2px solid #e5e7eb; margin-bottom:20px; flex-wrap:wrap; }
.sp-tab { padding:10px 18px; font-size:13px; color:#6b7280; text-decoration:none; border-bottom:2px solid transparent; margin-bottom:-2px; display:flex; align-items:center; gap:6px; white-space:nowrap; }
.sp-tab:hover { color:#1e40af; background:#f0f4ff; }
.sp-tab.active { color:#1e40af; border-bottom-color:#1e40af; font-weight:600; }

.sp-controls { display:flex; align-items:center; gap:8px; margin-bottom:24px; flex-wrap:wrap; }
.sp-btn-start { background:#10b981; color:#fff; border:none; padding:8px 18px; border-radius:6px; font-size:13px; font-weight:600; cursor:pointer; display:flex; align-items:center; gap:6px; }
.sp-btn-stop { background:#6b7280; color:#fff; border:none; padding:8px 18px; border-radius:6px; font-size:13px; font-weight:600; cursor:pointer; display:flex; align-items:center; gap:6px; }
.sp-btn-restart { background:#0ea5e9; color:#fff; border:none; padding:8px 18px; border-radius:6px; font-size:13px; font-weight:600; cursor:pointer; display:flex; align-items:center; gap:6px; }
.sp-btn-delete { background:#ef4444; color:#fff; border:none; padding:8px 18px; border-radius:6px; font-size:13px; font-weight:600; cursor:pointer; display:flex; align-items:center; gap:6px; margin-left:auto; }
.sp-btn-start:hover { background:#059669; }
.sp-btn-stop:hover { background:#4b5563; }
.sp-btn-restart:hover { background:#0284c7; }
.sp-btn-delete:hover { background:#dc2626; }

.sp-status { font-size:13px; margin-left:8px; color:#6b7280; }
.sp-status-val { font-weight:700; }
.sp-status-val.online { color:#10b981; }
.sp-status-val.offline { color:#ef4444; }

.sp-form-card { background:#fff; border:1px solid #e5e7eb; border-radius:8px; padding:24px; }
.sp-form-grid { display:grid; grid-template-columns:1fr 1fr; gap:20px; }
@media(max-width:768px) { .sp-form-grid { grid-template-columns:1fr; } }
.sp-field label { display:block; font-size:13px; font-weight:600; color:#374151; margin-bottom:6px; }
.sp-field label .sp-help { display:inline-flex; align-items:center; justify-content:center; width:16px; height:16px; border-radius:50%; background:#0ea5e9; color:#fff; font-size:10px; font-weight:700; margin-left:4px; cursor:help; }
.sp-field select, .sp-field input[type="text"], .sp-field input[type="url"], .sp-field input[type="number"] {
    width:100%; padding:8px 12px; border:1px solid #d1d5db; border-radius:6px; font-size:14px; color:#111827; background:#fff;
    box-sizing:border-box;
}
.sp-field select:focus, .sp-field input:focus { outline:none; border-color:#3b82f6; box-shadow:0 0 0 3px rgba(59,130,246,0.1); }
.sp-field input:disabled { background:#f9fafb; color:#6b7280; }

.sp-save-bar { display:flex; align-items:center; justify-content:space-between; margin-top:20px; padding:14px 18px; background:#fffbeb; border:1px solid #fde68a; border-radius:8px; }
.sp-save-bar .sp-warn { font-size:13px; color:#92400e; display:flex; align-items:center; gap:6px; }
.sp-save-btn { background:#3b82f6; color:#fff; border:none; padding:10px 24px; border-radius:6px; font-size:14px; font-weight:600; cursor:pointer; }
.sp-save-btn:hover { background:#2563eb; }

/* Playlist module - SonicPanel style */
.sp-pl-header { display:flex; gap:12px; align-items:center; margin-bottom:20px; flex-wrap:wrap; }
.sp-pl-header select { flex:1; min-width:200px; padding:9px 14px; border:1px solid #d1d5db; border-radius:8px; font-size:14px; color:#111827; background:#fff; }
.sp-pl-header select:focus { outline:none; border-color:#14b8a6; box-shadow:0 0 0 3px rgba(20,184,166,0.15); }
.sp-pl-header input[type="text"] { flex:0 1 200px; padding:9px 14px; border:1px solid #d1d5db; border-radius:8px; font-size:14px; color:#111827; background:#fff; }
.sp-pl-header input[type="text"]:focus { outline:none; border-color:#14b8a6; box-shadow:0 0 0 3px rgba(20,184,166,0.15); }
.sp-pl-create-btn { background:#14b8a6; color:#fff; border:none; padding:9px 20px; border-radius:8px; font-size:14px; font-weight:600; cursor:pointer; display:flex; align-items:center; gap:6px; white-space:nowrap; }
.sp-pl-create-btn:hover { background:#0d9488; }

.sp-pl-actions { display:flex; gap:8px; margin-bottom:16px; flex-wrap:wrap; }
.sp-pl-action-btn { background:#0ea5e9; color:#fff; border:none; padding:8px 16px; border-radius:6px; font-size:13px; font-weight:500; cursor:pointer; display:flex; align-items:center; gap:5px; text-decoration:none; }
.sp-pl-action-btn:hover { background:#0284c7; }
.sp-pl-action-btn.btn-red { background:#ef4444; }
.sp-pl-action-btn.btn-red:hover { background:#dc2626; }

.sp-pl-info { font-size:13px; color:#6b7280; margin-bottom:12px; display:flex; align-items:center; gap:6px; }
.sp-pl-info i { color:#0ea5e9; }

.sp-pl-search { width:100%; padding:10px 14px 10px 38px; border:1px solid #d1d5db; border-radius:8px; font-size:14px; color:#111827; background:#fff; margin-bottom:16px; box-sizing:border-box; }
.sp-pl-search:focus { outline:none; border-color:#14b8a6; box-shadow:0 0 0 3px rgba(20,184,166,0.15); }
.sp-pl-search-wrap { position:relative; margin-bottom:16px; }
.sp-pl-search-wrap .search-icon { position:absolute; left:12px; top:50%; transform:translateY(-50%); color:#9ca3af; }

.sp-song-list { border:1px solid #e5e7eb; border-radius:8px; overflow:hidden; }
.sp-song-item { display:flex; align-items:center; padding:12px 16px; border-bottom:1px solid #e5e7eb; cursor:pointer; transition:background 0.15s; }
.sp-song-item:last-child { border-bottom:none; }
.sp-song-item:hover { background:#f0fdfa; }
.sp-song-expand { color:#14b8a6; margin-right:10px; font-size:16px; transition:transform 0.2s; }
.sp-song-title { flex:1; font-size:14px; color:#14b8a6; font-weight:500; }
.sp-song-actions { display:flex; gap:8px; }
.sp-song-action { background:none; border:none; cursor:pointer; font-size:16px; padding:4px; }
.sp-song-action.edit-btn { color:#3b82f6; }
.sp-song-action.edit-btn:hover { color:#1d4ed8; }
.sp-song-action.del-btn { color:#ef4444; }
.sp-song-action.del-btn:hover { color:#dc2626; }

.sp-song-details { display:none; padding:8px 16px 16px 42px; background:#f9fafb; border-bottom:1px solid #e5e7eb; font-size:13px; color:#6b7280; }
.sp-song-details.open { display:block; }
.sp-song-details table { width:100%; }
.sp-song-details table td { padding:3px 8px; }
.sp-song-details table td:first-child { font-weight:600; color:#374151; width:120px; }

.sp-pl-empty { text-align:center; padding:40px; color:#9ca3af; font-size:14px; }
.sp-pl-count { font-size:13px; color:#6b7280; margin-bottom:12px; }
</style>

<!-- Tabs estilo SonicPanel -->
<div class="sp-tabs">
    <a href="autodj.php?tab=listas" class="sp-tab <?= $subtab === 'listas' ? 'active' : '' ?>"><?= cpIcon('music',14) ?> Listas de Reproducci&oacute;n</a>
    <a href="autodj.php?tab=programacion" class="sp-tab <?= $subtab === 'programacion' ? 'active' : '' ?>"><?= cpIcon('clock',14) ?> Lista Programada</a>
    <a href="<?= BASE_URL ?>/cliente/rotacion_carpetas.php" class="sp-tab"><?= cpIcon('refresh',14) ?> Playlist Intervals</a>
    <a href="autodj.php?tab=config" class="sp-tab <?= $subtab === 'config' ? 'active' : '' ?>"><?= cpIcon('gear',14) ?> Administrar AutoDJ</a>
</div>

<?php if ($subtab === 'listas'): ?>

<!-- ======= TAB: Listas de Reproduccion - Estilo SonicPanel ======= -->

<!-- Selector de playlist + crear nueva -->
<form method="POST" id="formCreatePl" style="display:inline">
    <?= csrfField() ?>
    <input type="hidden" name="action" value="create_playlist">
    <div class="sp-pl-header">
        <select id="plSelector" onchange="if(this.value) window.location='autodj.php?tab=listas&pl='+this.value">
            <option value="">Seleccionar una lista de reproducci&oacute;n</option>
            <?php foreach ($playlists as $pl): ?>
                <option value="<?= $pl['id'] ?>" <?= $selectedPl == $pl['id'] ? 'selected' : '' ?>>
                    <?= htmlspecialchars($pl['nombre']) ?><?= $pl['activa'] ? ' (Active)' : '' ?>
                </option>
            <?php endforeach; ?>
        </select>
        <input type="text" name="nombre" placeholder="Ingresar Nombre" required id="newPlName">
        <button type="submit" class="sp-pl-create-btn">+ Crear Nueva Lista de Reproducci&oacute;n</button>
    </div>
</form>

<?php if ($playlistSeleccionada): ?>
    <!-- Botones de accion de la playlist -->
    <div class="sp-pl-actions">
        <a href="<?= BASE_URL ?>/cliente/subir_musica.php?playlist=<?= $playlistSeleccionada['id'] ?>" class="sp-pl-action-btn">
            <?= cpIcon('upload',14) ?> Subir Archivos MP3
        </a>
        <a href="autodj.php?tab=programacion" class="sp-pl-action-btn">
            <?= cpIcon('clock',14) ?> Horario
        </a>
        <a href="autodj.php?tab=listas&pl=<?= $playlistSeleccionada['id'] ?>" class="sp-pl-action-btn">
            <?= cpIcon('refresh',14) ?> Actualizar
        </a>
        <form method="POST" style="display:inline">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="sort_by_prefix">
            <input type="hidden" name="playlist_id" value="<?= $playlistSeleccionada['id'] ?>">
            <button type="submit" class="sp-pl-action-btn"><?= cpIcon('list',14) ?> Ordenar Por Prefijo</button>
        </form>
        <form method="POST" style="display:inline">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="sort_by_number">
            <input type="hidden" name="playlist_id" value="<?= $playlistSeleccionada['id'] ?>">
            <button type="submit" class="sp-pl-action-btn"><?= cpIcon('hash',14) ?> Ordenar Por N&uacute;meros</button>
        </form>
        <a href="<?= BASE_URL ?>/cliente/listas.php" class="sp-pl-action-btn">
            <?= cpIcon('music',14) ?> Biblioteca de M&uacute;sica
        </a>
        <form method="POST" style="display:inline" onsubmit="return confirm('&iquest;Eliminar la lista &quot;<?= htmlspecialchars($playlistSeleccionada['nombre'], ENT_QUOTES) ?>&quot; y todas sus canciones?')">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="delete_playlist">
            <input type="hidden" name="playlist_id" value="<?= $playlistSeleccionada['id'] ?>">
            <button type="submit" class="sp-pl-action-btn btn-red"><?= cpIcon('trash',14) ?> Eliminar</button>
        </form>
    </div>

    <!-- Info -->
    <div class="sp-pl-info">
        <?= cpIcon('info',14) ?> Arrastre y Suelte pistas para cambiar el orden de reproducci&oacute;n (AutoDJ comenzar&aacute; a reproducir desde el inicio de la lista despu&eacute;s de que termine la pista actual.)
    </div>
    <div class="sp-pl-info">
        <?= cpIcon('info',14) ?> Haga clic en pistas para editar/previsualizar.
    </div>

    <!-- Buscador -->
    <div class="sp-pl-search-wrap">
        <span class="search-icon"><?= cpIcon('search',16) ?></span>
        <input type="text" class="sp-pl-search" placeholder="Buscar, artistas, nombres de canciones..." id="songSearch" onkeyup="filterSongs()">
    </div>

    <!-- Contador -->
    <div class="sp-pl-count"><?= count($canciones) ?> cancion<?= count($canciones) !== 1 ? 'es' : '' ?> en esta lista</div>

    <!-- Lista de canciones -->
    <?php if (empty($canciones)): ?>
        <div class="sp-pl-empty">
            <p>No hay canciones en esta lista.</p>
            <a href="<?= BASE_URL ?>/cliente/subir_musica.php?playlist=<?= $playlistSeleccionada['id'] ?>" class="sp-pl-action-btn" style="display:inline-flex;margin-top:12px">
                <?= cpIcon('upload',14) ?> Subir Archivos MP3
            </a>
        </div>
    <?php else: ?>
        <div class="sp-song-list" id="songList">
            <?php foreach ($canciones as $idx => $cancion): ?>
                <div class="sp-song-item" data-title="<?= htmlspecialchars(strtolower($cancion['titulo'] . ' ' . ($cancion['artista'] ?? ''))) ?>" onclick="toggleSongDetails(<?= $idx ?>)">
                    <span class="sp-song-expand" id="arrow-<?= $idx ?>">&#9654;</span>
                    <span class="sp-song-title"><?= htmlspecialchars(($cancion['orden'] ?? $idx) . ' - ' . ($cancion['titulo'] ?: $cancion['nombre_original'])) ?></span>
                    <span class="sp-song-actions" onclick="event.stopPropagation()">
                        <button class="sp-song-action edit-btn" onclick="toggleSongDetails(<?= $idx ?>)" title="Editar/Ver">&#9998;</button>
                        <form method="POST" style="display:inline" onsubmit="return confirm('&iquest;Eliminar esta canci&oacute;n?')">
                            <?= csrfField() ?>
                            <input type="hidden" name="action" value="delete_song">
                            <input type="hidden" name="song_id" value="<?= $cancion['id'] ?>">
                            <input type="hidden" name="playlist_id" value="<?= $playlistSeleccionada['id'] ?>">
                            <button type="submit" class="sp-song-action del-btn" title="Eliminar">&times;</button>
                        </form>
                    </span>
                </div>
                <div class="sp-song-details" id="details-<?= $idx ?>">
                    <table>
                        <tr><td>T&iacute;tulo:</td><td><?= htmlspecialchars($cancion['titulo'] ?: '-') ?></td></tr>
                        <tr><td>Artista:</td><td><?= htmlspecialchars($cancion['artista'] ?? '-') ?></td></tr>
                        <tr><td>Archivo:</td><td><?= htmlspecialchars($cancion['nombre_original'] ?? '-') ?></td></tr>
                        <tr><td>Duraci&oacute;n:</td><td><?= isset($cancion['duracion_segundos']) ? formatDuration($cancion['duracion_segundos']) : '-' ?></td></tr>
                        <tr><td>Tama&ntilde;o:</td><td><?= isset($cancion['tamano_bytes']) ? formatBytes($cancion['tamano_bytes']) : '-' ?></td></tr>
                    </table>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

<?php else: ?>
    <!-- Sin playlist seleccionada -->
    <div class="sp-pl-empty">
        <p>Seleccione una lista de reproducci&oacute;n del men&uacute; desplegable para ver y administrar sus canciones.</p>
    </div>
<?php endif; ?>

<script>
function toggleSongDetails(idx) {
    var details = document.getElementById('details-' + idx);
    var arrow = document.getElementById('arrow-' + idx);
    if (details.classList.contains('open')) {
        details.classList.remove('open');
        arrow.innerHTML = '&#9654;';
    } else {
        details.classList.add('open');
        arrow.innerHTML = '&#9660;';
    }
}

function filterSongs() {
    var query = document.getElementById('songSearch').value.toLowerCase();
    var items = document.querySelectorAll('.sp-song-item');
    var details = document.querySelectorAll('.sp-song-details');
    items.forEach(function(item, i) {
        var title = item.getAttribute('data-title') || '';
        var show = title.indexOf(query) !== -1;
        item.style.display = show ? '' : 'none';
        if (details[i]) details[i].style.display = show && details[i].classList.contains('open') ? 'block' : 'none';
    });
}
</script>

<?php elseif ($subtab === 'config'): ?>

<!-- Controles AutoDJ - estilo SonicPanel -->
<div class="sp-controls">
    <form method="POST" style="display:inline">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="autodj_control">
        <input type="hidden" name="cmd" value="iniciar">
        <button type="submit" class="sp-btn-start"><?= cpIcon('play',14) ?> Iniciar</button>
    </form>
    <form method="POST" style="display:inline">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="autodj_control">
        <input type="hidden" name="cmd" value="detener">
        <button type="submit" class="sp-btn-stop"><?= cpIcon('x',14) ?> Detener</button>
    </form>
    <form method="POST" style="display:inline">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="autodj_control">
        <input type="hidden" name="cmd" value="reiniciar">
        <button type="submit" class="sp-btn-restart"><?= cpIcon('refresh',14) ?> Reiniciar</button>
    </form>
    <span class="sp-status">AutoDJ Estado:
        <span class="sp-status-val <?= ($estacion['autodj_estado'] ?? 'offline') === 'online' ? 'online' : 'offline' ?>">
            <?= ucfirst($estacion['autodj_estado'] ?? 'Offline') ?>
        </span>
    </span>
    <form method="POST" style="display:inline;margin-left:auto" onsubmit="return confirm('&iquest;Est&aacute;s seguro de eliminar el AutoDJ? Esta acci&oacute;n detendr&aacute; el servicio.')">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="autodj_control">
        <input type="hidden" name="cmd" value="detener">
        <button type="submit" class="sp-btn-delete"><?= cpIcon('trash',14) ?> Eliminar</button>
    </form>
</div>

<!-- Formulario unico plano estilo SonicPanel -->
<div class="sp-form-card">
    <form method="POST">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="update_config">

        <div class="sp-form-grid">

            <div class="sp-field">
                <label>Activo Lista de Reproducci&oacute;n:</label>
                <select name="playlist_activa">
                    <option value="">-- Ninguna --</option>
                    <?php foreach ($playlists as $pl): ?>
                        <option value="<?= $pl['id'] ?>" <?= ($estacion['autodj_playlist_activa'] ?? '') == $pl['id'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($pl['nombre']) ?><?= $pl['activa'] ? ' (Activo)' : '' ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="sp-field">
                <label>Codificador: <span class="sp-help" title="Formato de codificaci&oacute;n de audio para la transmisi&oacute;n">?</span></label>
                <select name="codec">
                    <option value="mp3" <?= ($estacion['codec'] ?? '') === 'mp3' ? 'selected' : '' ?>>MP3</option>
                    <option value="aac" <?= ($estacion['codec'] ?? '') === 'aac' ? 'selected' : '' ?>>AAC</option>
                    <option value="ogg" <?= ($estacion['codec'] ?? '') === 'ogg' ? 'selected' : '' ?>>OGG Vorbis</option>
                </select>
            </div>

            <div class="sp-field">
                <label>T&iacute;tulo de la Radio:</label>
                <input type="text" name="titulo_radio" value="<?= htmlspecialchars($estacion['titulo_radio'] ?? '') ?>">
            </div>

            <div class="sp-field">
                <label>URL de la Radio:</label>
                <input type="text" name="url_web" value="<?= htmlspecialchars($estacion['url_web'] ?? '') ?>" placeholder="https://tuweb.com">
            </div>

            <div class="sp-field">
                <label>Modo de Reproducci&oacute;n:</label>
                <select name="modo_reproduccion">
                    <option value="normal" <?= ($estacion['modo_reproduccion'] ?? '') === 'normal' ? 'selected' : '' ?>>Reproducir en Modo Normal</option>
                    <option value="aleatorio" <?= ($estacion['modo_reproduccion'] ?? '') === 'aleatorio' ? 'selected' : '' ?>>Modo Aleatorio (Shuffle)</option>
                    <option value="secuencial" <?= ($estacion['modo_reproduccion'] ?? '') === 'secuencial' ? 'selected' : '' ?>>Secuencial Estricto</option>
                </select>
            </div>

            <div class="sp-field">
                <label>Crossfade (AutoMix):</label>
                <select name="crossfade">
                    <option value="0" <?= ($estacion['crossfade_seconds'] ?? 0) == 0 ? 'selected' : '' ?>>Desactivado</option>
                    <option value="2" <?= ($estacion['crossfade_seconds'] ?? 0) == 2 ? 'selected' : '' ?>>2 Seconds</option>
                    <option value="3" <?= ($estacion['crossfade_seconds'] ?? 0) == 3 ? 'selected' : '' ?>>3 Seconds</option>
                    <option value="4" <?= ($estacion['crossfade_seconds'] ?? 0) == 4 ? 'selected' : '' ?>>4 Seconds</option>
                    <option value="5" <?= ($estacion['crossfade_seconds'] ?? 0) == 5 ? 'selected' : '' ?>>5 Seconds</option>
                    <option value="8" <?= ($estacion['crossfade_seconds'] ?? 0) == 8 ? 'selected' : '' ?>>8 Seconds</option>
                    <option value="10" <?= ($estacion['crossfade_seconds'] ?? 0) == 10 ? 'selected' : '' ?>>10 Seconds</option>
                </select>
            </div>

            <div class="sp-field">
                <label>L&iacute;mite M&aacute;ximo de Tasa de Bits:</label>
                <select name="bitrate">
                    <?php foreach ([64,96,128,192,256,320] as $br): ?>
                        <option value="<?= $br ?>" <?= ($estacion['bitrate'] ?? 128) == $br ? 'selected' : '' ?>><?= $br ?> KBPS</option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="sp-field">
                <label>Puerto DJ (Auto): <span class="sp-help" title="Puerto harbor para conexi&oacute;n DJ en vivo (puerto stream + 5)">?</span></label>
                <input type="text" value="<?= (int)($estacion['puerto_stream'] ?: ($estacion['icecast_port'] ?? 8000)) + 5 ?>" disabled>
            </div>

            <div class="sp-field">
                <label>Grabar Shows DJ En Vivo: <span class="sp-help" title="Graba autom&aacute;ticamente las transmisiones de DJs en vivo">?</span></label>
                <select name="grabar_dj">
                    <option value="0" <?= !($estacion['grabar_dj'] ?? 0) ? 'selected' : '' ?>>No</option>
                    <option value="1" <?= ($estacion['grabar_dj'] ?? 0) ? 'selected' : '' ?>>S&iacute;</option>
                </select>
            </div>

            <div class="sp-field">
                <label>Calidad MP3 de Grabaci&oacute;n En Vivo:</label>
                <select name="calidad_grabacion">
                    <?php foreach ([128,192,256,320] as $br): ?>
                        <option value="<?= $br ?>" <?= ($estacion['calidad_grabacion'] ?? 320) == $br ? 'selected' : '' ?>><?= $br ?> KBPS</option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="sp-field">
                <label>Configuraciones de T&iacute;tulo de Canci&oacute;n:</label>
                <select name="mostrar_titulos">
                    <option value="1" <?= ($estacion['mostrar_titulos'] ?? 1) ? 'selected' : '' ?>>Mostrar T&iacute;tulos de Canciones (Predeterminado)</option>
                    <option value="0" <?= !($estacion['mostrar_titulos'] ?? 1) ? 'selected' : '' ?>>Ocultar T&iacute;tulos</option>
                </select>
            </div>

            <div class="sp-field">
                <label>G&eacute;nero:</label>
                <input type="text" value="<?= htmlspecialchars($estacion['genero'] ?? 'various') ?>" disabled>
            </div>

        </div>

        <div class="sp-save-bar">
            <span class="sp-warn"><?= cpIcon('warning',14) ?> Los cambios se aplican de forma inmediata al guardar.</span>
            <button type="submit" class="sp-save-btn"><?= cpIcon('save',14) ?> Guardar Cambios</button>
        </div>
    </form>
</div>

<?php elseif ($subtab === 'programacion'): ?>
<!-- ======= TAB: Programacion ======= -->

<div class="alert alert-info">
    <?= cpIcon('info',14) ?> Programa qu&eacute; playlist debe utilizar el AutoDJ durante cada horario. Puedes crear varios horarios para la misma radio y repetirlos semanalmente.
</div>

<div class="two-cols-equal">
    <!-- Nueva programacion -->
    <div class="card">
        <div class="card-header">
            <h3><?= cpIcon('plus',14) ?> Nueva programaci&oacute;n</h3>
        </div>
        <div class="card-body">
            <p style="font-size:12px;color:var(--text-secondary);margin-bottom:16px">Define los d&iacute;as, horario y playlist.</p>
            <form method="POST">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="save_schedule">

                <div class="form-group">
                    <label>Playlist</label>
                    <select name="playlist_id" class="form-control" required>
                        <option value="">-- Selecciona una lista --</option>
                        <?php foreach ($playlistsActivas as $pl): ?>
                            <option value="<?= $pl['id'] ?>"><?= htmlspecialchars($pl['nombre']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-group">
                    <label>D&iacute;as de Transmisi&oacute;n</label>
                    <div class="check-grid">
                        <?php foreach (['lunes'=>'Lun','martes'=>'Mar','miercoles'=>'Mi&eacute;','jueves'=>'Jue','viernes'=>'Vie','sabado'=>'S&aacute;b','domingo'=>'Dom'] as $k => $v): ?>
                            <label class="check-item"><input type="checkbox" name="dias[]" value="<?= $k ?>"><span><?= $v ?></span></label>
                        <?php endforeach; ?>
                        <label class="check-item"><input type="checkbox" onclick="document.querySelectorAll('input[name=\'dias[]\']').forEach(c=>c.checked=this.checked)"><span>Todos</span></label>
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label>Hora inicio</label>
                        <input type="time" name="hora_inicio" class="form-control" value="08:00" required>
                    </div>
                    <div class="form-group">
                        <label>Hora fin</label>
                        <input type="time" name="hora_fin" class="form-control" value="12:00" required>
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label>Prioridad</label>
                        <input type="number" name="prioridad" class="form-control" value="0" min="0">
                    </div>
                    <div class="form-group">
                        <label>Estado</label>
                        <select name="estado" class="form-control">
                            <option value="activa">Activa</option>
                            <option value="inactiva">Inactiva</option>
                        </select>
                    </div>
                </div>

                <button type="submit" class="btn btn-success btn-block">+ Guardar Programaci&oacute;n</button>
            </form>
        </div>
    </div>

    <!-- Programaciones activas -->
    <div class="card">
        <div class="card-header">
            <h3><?= cpIcon('clock',18) ?> Programaciones activas</h3>
            <span style="font-size:12px;color:var(--text-secondary)">Estaci&oacute;n: <strong><?= htmlspecialchars($estacion['slug']) ?></strong></span>
        </div>
        <div class="card-body no-padding">
            <?php if (empty($programaciones)): ?>
                <div class="empty-state"><p>No hay programaciones configuradas.</p></div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>D&iacute;a</th>
                                <th>Horario</th>
                                <th>Playlist</th>
                                <th>Prioridad</th>
                                <th>Estado</th>
                                <th>Acciones</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($programaciones as $prog): ?>
                                <tr>
                                    <td style="text-transform:capitalize"><?= $prog['dia_semana'] ?></td>
                                    <td><?= substr($prog['hora_inicio'],0,5) ?> - <?= substr($prog['hora_fin'],0,5) ?></td>
                                    <td><?= htmlspecialchars($prog['playlist_nombre']) ?></td>
                                    <td><?= $prog['prioridad'] ?></td>
                                    <td>
                                        <?php if ($prog['estado'] === 'activa'): ?>
                                            <span class="badge badge-success">Activa</span>
                                        <?php else: ?>
                                            <span class="badge badge-danger">Inactiva</span>
                                        <?php endif; ?>
                                    </td>
                                    <td style="display:flex;gap:4px">
                                        <form method="POST" style="display:inline">
                                            <?= csrfField() ?>
                                            <input type="hidden" name="action" value="toggle_schedule">
                                            <input type="hidden" name="schedule_id" value="<?= $prog['id'] ?>">
                                            <input type="hidden" name="new_state" value="<?= $prog['estado'] === 'activa' ? 'inactiva' : 'activa' ?>">
                                            <button class="btn btn-outline btn-sm"><?= $prog['estado'] === 'activa' ? 'Desactivar' : 'Activar' ?></button>
                                        </form>
                                        <form method="POST" style="display:inline" onsubmit="return confirmDelete('&iquest;Eliminar esta programaci&oacute;n?')">
                                            <?= csrfField() ?>
                                            <input type="hidden" name="action" value="delete_schedule">
                                            <input type="hidden" name="schedule_id" value="<?= $prog['id'] ?>">
                                            <button class="btn btn-danger btn-sm"><?= cpIcon('trash',14) ?> Eliminar</button>
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

<?php endif; ?>

<?php require_once __DIR__ . '/../includes/layout_footer.php'; ?>
