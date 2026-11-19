<?php
ob_start();
/**
 * CastPanel v2.4 - Administrar AutoDJ
 * Rediseno estilo SonicPanel - modulo unico plano
 * Incluye modulo de Listas de Reproduccion integrado
 */
$pageTitle = 'Administrar AutoDJ';
$pageIcon = 'gear';

// Before layout: sync playlist when opening AutoDJ (stream may be “online” but playing silence).
if ($_SERVER['REQUEST_METHOD'] === 'GET'
    && !(defined('CASTPANEL_STREAMING_DISABLED') && CASTPANEL_STREAMING_DISABLED)) {
    require_once __DIR__ . '/../includes/auth.php';
    requireLogin();
    $estPre = getCurrentStation();
    if ($estPre) {
        require_once __DIR__ . '/../includes/provisioning.php';
        $estFull = loadStationWithPackage((int)$estPre['id']);
        if ($estFull && stationHasAutoDJ($estFull)) {
            ensureAutoDJPlaying($estFull);
            clearStreamStatusCache((int)$estPre['id']);
        }
    }
}

require_once __DIR__ . '/../includes/layout_header.php';

// Apply schedules/rotation/relay/ads right after a change (runs after the redirect)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($estacion['id'])) {
    require_once __DIR__ . '/../includes/autodj_schedule.php';
    $cpSchedStation = (int)$estacion['id'];
    register_shutdown_function(static function () use ($cpSchedStation) { castpanelApplyStationScheduleById($cpSchedStation); });
}
require_once __DIR__ . '/../includes/provisioning.php';

if (!$estacion) { echo '<div class="alert alert-warning">Sin estación asignada.</div>'; require_once __DIR__ . '/../includes/layout_footer.php'; exit; }

if (defined('CASTPANEL_STREAMING_DISABLED') && CASTPANEL_STREAMING_DISABLED) {
    echo '<div class="alert alert-warning" style="margin-bottom:16px">'
        . '<strong>Local dev mode:</strong> AutoDJ cannot start here (no SHOUTcast/Liquidsoap). '
        . 'Create and test stations on your Linux VPS with <code>install.sh</code>.</div>';
}

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

/** Remove a song's audio file (only inside this station's media folder) and its cover. */
function cpAutodjDeleteSongFiles(array $cancion, array $estacion): void {
    $mediaDir = rtrim(defined('MEDIA_PATH') ? MEDIA_PATH : '/var/castpanel/media', '/') . '/' . $estacion['slug'] . '/';
    $file = (string)($cancion['ruta_archivo'] ?? '');
    $real = $file !== '' ? realpath($file) : false;
    $base = realpath($mediaDir);
    if ($real && $base && str_starts_with($real, $base . '/')) @unlink($real);
    if (!empty($cancion['caratula'])) @unlink(BASE_PATH . '/assets/covers/' . basename($cancion['caratula']));
}

/**
 * Create a music folder (playlist) for the station and return its id.
 * Reuses a folder with the same name; slugs are made unique.
 */
function cpAutodjCreateFolder(PDO $db, int $estacionId, string $nombre): int {
    $nombre = mb_substr(trim($nombre), 0, 128);
    $q = $db->prepare('SELECT id FROM playlists WHERE estacion_id = ? AND nombre = ? LIMIT 1');
    $q->execute([$estacionId, $nombre]);
    if ($id = (int)$q->fetchColumn()) return $id;
    $base = substr(slugify($nombre), 0, 56) ?: 'folder';
    $slug = $base;
    $chk = $db->prepare('SELECT 1 FROM playlists WHERE estacion_id = ? AND slug = ?');
    for ($i = 2; $chk->execute([$estacionId, $slug]) && $chk->fetchColumn(); $i++) {
        $slug = $base . '-' . $i;
    }
    $db->prepare('INSERT INTO playlists (estacion_id, nombre, slug, activa) VALUES (?, ?, ?, 1)')
       ->execute([$estacionId, $nombre, $slug]);
    return (int)$db->lastInsertId();
}

// Procesar acciones
if ($_SERVER['REQUEST_METHOD'] === 'POST' && verifyCsrf()) {
    $action = $_POST['action'] ?? '';

    try {
        if ($action === 'update_config') {
            // SonicPanel flow: pick (or create) the music folder, pick the
            // encoder, Save -> AutoDJ plays that folder right away.
            $isShout = isShoutcastType($estacion['server_type'] ?? '');
            $codec = $_POST['codec'] ?? 'mp3';
            $allowedCodecs = $isShout ? ['mp3', 'aac'] : ['mp3', 'aac', 'ogg']; // SHOUTcast cannot carry Ogg
            if (!in_array($codec, $allowedCodecs, true)) $codec = 'mp3';
            $bitrate = (int)($_POST['bitrate'] ?? 128);
            if (!in_array($bitrate, [64, 96, 128, 192, 256, 320], true)) $bitrate = 128;
            $maxBr = (int)($estacion['max_bitrate'] ?? 0);
            if ($maxBr > 0 && $bitrate > $maxBr) $bitrate = $maxBr;
            $modo = in_array($_POST['modo_reproduccion'] ?? '', ['normal', 'aleatorio', 'secuencial'], true) ? $_POST['modo_reproduccion'] : 'normal';

            // Folder: an existing one, or a new one typed in the form
            $playlistActiva = (int)($_POST['playlist_activa'] ?? 0) ?: null;
            $newFolder = trim((string)($_POST['new_folder'] ?? ''));
            if ($newFolder !== '') {
                $playlistActiva = cpAutodjCreateFolder($db, (int)$estacion['id'], $newFolder);
            } elseif ($playlistActiva) {
                $chk = $db->prepare('SELECT id FROM playlists WHERE id = ? AND estacion_id = ?');
                $chk->execute([$playlistActiva, $estacion['id']]);
                if (!$chk->fetchColumn()) $playlistActiva = null;
            }

            $stmt = $db->prepare("UPDATE estaciones SET
                codec = ?, bitrate = ?, modo_reproduccion = ?, crossfade_seconds = ?,
                titulo_radio = ?, url_web = ?, mostrar_titulos = ?,
                grabar_dj = ?, calidad_grabacion = ?, buffer_dj_delay = ?,
                autodj_playlist_activa = ?
                WHERE id = ?");
            $stmt->execute([
                $codec, $bitrate, $modo, max(0, min(10, (int)($_POST['crossfade'] ?? 0))),
                trim((string)($_POST['titulo_radio'] ?? '')), trim((string)($_POST['url_web'] ?? '')),
                // these selects send "1"/"0"; isset() made "No" mean Yes
                !empty($_POST['mostrar_titulos']) ? 1 : 0,
                !empty($_POST['grabar_dj']) ? 1 : 0,
                (int)($_POST['calidad_grabacion'] ?? 320), (int)($_POST['buffer_dj_delay'] ?? ($estacion['buffer_dj_delay'] ?? 0)),
                $playlistActiva,
                $estacion['id']
            ]);

            require_once __DIR__ . '/../includes/provisioning.php';
            require_once __DIR__ . '/../includes/liquidsoap_playlist.php';
            $estacionActualizada = loadStationWithPackage((int)$estacion['id']);
            if ($estacionActualizada && stationHasAutoDJ($estacionActualizada)) {
                // Encoder, bitrate, crossfade and play mode live in the .liq
                // file: when it changed, Liquidsoap must restart to use it.
                // A folder change alone is applied by reloading the playlist.
                $cfgPath = getLiquidsoapConfigPath($estacionActualizada['slug']);
                $before = is_readable($cfgPath) ? md5_file($cfgPath) : null;
                $gen = generateLiquidsoapConfig($estacionActualizada);
                $after = is_readable($cfgPath) ? md5_file($cfgPath) : null;
                if (!($gen['success'] ?? true)) {
                    $playResult = ['success' => false, 'error' => $gen['error'] ?? 'config'];
                } elseif ($before !== null && $before !== $after && liquidsoapContainerIsRunning($estacionActualizada['slug'])) {
                    $playResult = restartLiquidsoapService($estacionActualizada);
                    castpanelWriteActivePlaylistM3u($estacionActualizada);
                } else {
                    // Not running -> starts SHOUTcast + Liquidsoap; running -> reloads the folder
                    $playResult = ensureAutoDJPlaying($estacionActualizada);
                }
                clearStreamStatusCache((int)$estacion['id']);
                if (!($playResult['success'] ?? false)) {
                    setFlash('error', 'Settings saved but AutoDJ could not start: ' . ($playResult['error'] ?? 'unknown'));
                    header('Location: autodj.php?tab=config');
                    exit;
                }
                $cnt = 0;
                if ($playlistActiva) {
                    $c = $db->prepare('SELECT COUNT(*) FROM canciones WHERE playlist_id = ? AND estacion_id = ? AND activa = 1');
                    $c->execute([$playlistActiva, $estacion['id']]);
                    $cnt = (int)$c->fetchColumn();
                }
                if (!$playlistActiva) {
                    setFlash('warning', 'Settings saved. Pick a music folder so AutoDJ has something to play.');
                } elseif ($cnt === 0) {
                    setFlash('warning', 'Settings saved and AutoDJ is running, but this folder has no songs yet — upload music into it and it will start playing.');
                } else {
                    setFlash('success', 'Saved. AutoDJ is now playing this folder (' . $cnt . ' song' . ($cnt === 1 ? '' : 's') . ').');
                }
            } else {
                setFlash('success', 'Settings saved.');
            }
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
                $resultado = ensureAutoDJPlaying($estacion);
            } elseif ($cmd === 'detener') {
                $resultado = stopLiquidsoapService($estacion);
            } elseif ($cmd === 'reiniciar') {
                $resultado = restartStation($estacion);
            }

            clearStreamStatusCache((int)$estacion['id']);
            $cmdEn = match ($cmd) {
                'iniciar' => 'started',
                'detener' => 'stopped',
                'reiniciar' => 'restarted',
                default => $cmd,
            };
            if ($resultado['success']) {
                setFlash('success', 'AutoDJ ' . $cmdEn . ' successfully.');
            } else {
                setFlash('error', 'AutoDJ could not ' . ($cmd === 'iniciar' ? 'start' : ($cmd === 'detener' ? 'stop' : 'restart'))
                    . ': ' . ($resultado['error'] ?? 'unknown error'));
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
            setFlash('success', 'Programación guardada.');
            header('Location: autodj.php?tab=programacion');
            exit;
        }

        if ($action === 'delete_schedule') {
            $db->prepare("DELETE FROM programacion_playlists WHERE id = ? AND estacion_id = ?")->execute([(int)$_POST['schedule_id'], $estacion['id']]);
            setFlash('success', 'Programación eliminada.');
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
                $newId = cpAutodjCreateFolder($db, (int)$estacion['id'], $nombre);
                setFlash('success', 'Lista de reproducción creada: ' . htmlspecialchars($nombre));
                header('Location: autodj.php?tab=listas&pl=' . $newId);
                exit;
            }
            header('Location: autodj.php?tab=listas');
            exit;
        }

        if ($action === 'delete_playlist') {
            $plId = (int)$_POST['playlist_id'];
            // Borrar canciones de la playlist (files too: they count against the plan's storage)
            $st = $db->prepare("SELECT ruta_archivo, caratula FROM canciones WHERE playlist_id = ? AND estacion_id = ?");
            $st->execute([$plId, $estacion['id']]);
            foreach ($st->fetchAll() as $c) cpAutodjDeleteSongFiles($c, $estacion);
            $db->prepare("DELETE FROM canciones WHERE playlist_id = ? AND estacion_id = ?")->execute([$plId, $estacion['id']]);
            // Borrar la playlist
            $db->prepare("DELETE FROM playlists WHERE id = ? AND estacion_id = ?")->execute([$plId, $estacion['id']]);
            setFlash('success', 'Lista de reproducción eliminada.');
            header('Location: autodj.php?tab=listas');
            exit;
        }

        if ($action === 'delete_song') {
            $songId = (int)$_POST['song_id'];
            $plId = (int)$_POST['playlist_id'];
            // Obtener info de la cancion para borrar archivo
            $stmtSong = $db->prepare("SELECT ruta_archivo, caratula, playlist_id FROM canciones WHERE id = ? AND estacion_id = ?");
            $stmtSong->execute([$songId, $estacion['id']]);
            $cancion = $stmtSong->fetch();
            if ($cancion) {
                // the old code looked in storage/music/ and never removed the real file
                cpAutodjDeleteSongFiles($cancion, $estacion);
                $db->prepare("DELETE FROM canciones WHERE id = ? AND estacion_id = ?")->execute([$songId, $estacion['id']]);
                if ($cancion['playlist_id']) {
                    $db->prepare("UPDATE playlists SET total_canciones = total_canciones - 1 WHERE id = ? AND total_canciones > 0")->execute([$cancion['playlist_id']]);
                }
                setFlash('success', 'Canción eliminada.');
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
            setFlash('success', 'Canciones ordenadas por números.');
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

$playlistTrackCounts = [];
try {
    $stmt = $db->prepare(
        'SELECT playlist_id, COUNT(*) AS cnt FROM canciones WHERE estacion_id = ? AND activa = 1 AND playlist_id IS NOT NULL GROUP BY playlist_id'
    );
    $stmt->execute([$estacion['id']]);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $playlistTrackCounts[(int)$row['playlist_id']] = (int)$row['cnt'];
    }
} catch (PDOException $e) {}

// Subtabs
$subtab = $_GET['tab'] ?? 'listas';
if (in_array($subtab, ['manage', 'admin', 'administrar'], true)) {
    $subtab = 'config';
}
$selectedPl = isset($_GET['pl']) ? (int)$_GET['pl'] : 0;
if ($subtab === 'listas' && $selectedPl <= 0 && $playlists) {
    $selectedPl = (int)($estacion['autodj_playlist_activa'] ?? 0) ?: (int)$playlists[0]['id'];
}

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

$totalCancionesAd = 0;
try {
    $stmt = $db->prepare("SELECT COUNT(*) FROM canciones WHERE estacion_id = ?");
    $stmt->execute([$estacion['id']]);
    $totalCancionesAd = (int)$stmt->fetchColumn();
} catch (PDOException $e) {}
$autodjOnline = ($estacion['autodj_estado'] ?? 'offline') === 'online';
$playlistCount = count($playlists);
$activePlName = null;
foreach ($playlists as $pl) {
    if (!empty($pl['activa'])) {
        $activePlName = $pl['nombre'];
        break;
    }
}
if ($activePlName === null && !empty($estacion['autodj_playlist_activa'])) {
    foreach ($playlists as $pl) {
        if ((int)$pl['id'] === (int)$estacion['autodj_playlist_activa']) {
            $activePlName = $pl['nombre'];
            break;
        }
    }
}
?>

<?php
$onAirId = (int)($estacion['autodj_playlist_activa'] ?? 0);
$onAirName = null;
foreach ($playlists as $pl) {
    if ((int)$pl['id'] === $onAirId) { $onAirName = $pl['nombre']; break; }
}
$onAirCount = $onAirId ? ($playlistTrackCounts[$onAirId] ?? 0) : 0;
// What actually plays right now (a schedule or rotation overrides the default folder)
require_once __DIR__ . '/../includes/autodj_schedule.php';
$nowSource = castpanelResolveMusicSource($estacion);
$nowRelay = castpanelResolveRelay($estacion);
$nowLabel = $nowRelay ? 'Relay: ' . $nowRelay['nombre']
    : ($nowSource['type'] === 'rotation' ? 'Rotation: ' . $nowSource['name']
    : ($nowSource['type'] === 'schedule' ? 'Schedule: ' . $nowSource['name'] : ($onAirName ?? 'None selected')));
$codecLabel = strtoupper($estacion['codec'] ?: 'mp3');
$modeLabels = ['normal' => 'Normal', 'aleatorio' => 'Shuffle', 'secuencial' => 'Sequential'];
$modeLabel = $modeLabels[$estacion['modo_reproduccion'] ?? 'normal'] ?? 'Normal';
$harborPort = (int)($estacion['puerto_stream'] ?: ($estacion['icecast_port'] ?? 8000)) + 5;
$isShoutStation = isShoutcastType($estacion['server_type'] ?? '');
$curCodec = $estacion['codec'] ?: 'mp3';
?>
<style>
/* ===== AutoDJ (redesign) ===== */
.adj-hero{position:relative;overflow:hidden;border-radius:18px;padding:24px 26px;margin-bottom:18px;color:#fff;
  background:linear-gradient(135deg,var(--primary) 0%,#0f5f66 58%,#0b3d6e 100%);box-shadow:0 14px 34px rgba(15,40,80,.20)}
.adj-hero::before,.adj-hero::after{content:'';position:absolute;border-radius:50%;filter:blur(40px);pointer-events:none}
.adj-hero::before{width:260px;height:260px;right:-80px;top:-110px;background:rgba(255,255,255,.18)}
.adj-hero::after{width:200px;height:200px;left:30%;bottom:-130px;background:rgba(255,255,255,.10)}
.adj-hero>*{position:relative;z-index:1}
.adj-hero-top{display:flex;justify-content:space-between;align-items:flex-start;gap:16px;flex-wrap:wrap}
.adj-kicker{font-size:11px;letter-spacing:1.4px;text-transform:uppercase;opacity:.75;font-weight:700}
.adj-hero h2{font-size:24px;font-weight:800;margin:4px 0 2px;color:#fff}
.adj-hero .adj-sub{font-size:13px;opacity:.8}
.adj-pill{display:inline-flex;align-items:center;gap:7px;padding:5px 12px;border-radius:30px;font-size:12px;font-weight:700;background:rgba(255,255,255,.16);border:1px solid rgba(255,255,255,.25)}
.adj-pill .dot{width:8px;height:8px;border-radius:50%;background:#9ca3af}
.adj-pill.on .dot{background:#34d399;box-shadow:0 0 0 0 rgba(52,211,153,.7);animation:adjPulse 1.8s infinite}
@keyframes adjPulse{0%{box-shadow:0 0 0 0 rgba(52,211,153,.6)}70%{box-shadow:0 0 0 8px rgba(52,211,153,0)}100%{box-shadow:0 0 0 0 rgba(52,211,153,0)}}
.adj-actions{display:flex;gap:8px;flex-wrap:wrap}
.adj-actions form{display:inline}
.adj-btn{display:inline-flex;align-items:center;gap:6px;padding:9px 15px;border-radius:10px;font-size:13px;font-weight:600;border:1px solid rgba(255,255,255,.3);background:rgba(255,255,255,.12);color:#fff;cursor:pointer;text-decoration:none;transition:background .2s,transform .2s}
.adj-btn:hover{background:rgba(255,255,255,.24);color:#fff;transform:translateY(-1px)}
.adj-btn.solid{background:#fff;color:var(--primary);border-color:#fff}
.adj-btn.solid:hover{background:rgba(255,255,255,.88);color:var(--primary)}
.adj-np{margin-top:20px;display:flex;align-items:center;gap:16px;padding:14px 16px;border-radius:14px;background:rgba(255,255,255,.10);border:1px solid rgba(255,255,255,.16)}
.adj-eq{display:flex;align-items:flex-end;gap:3px;height:26px;width:28px;flex-shrink:0}
.adj-eq span{display:block;width:4px;border-radius:2px;background:#fff;opacity:.85;height:30%}
.adj-hero.is-on .adj-eq span{animation:adjEq 1s ease-in-out infinite}
.adj-hero.is-on .adj-eq span:nth-child(2){animation-delay:.2s}.adj-hero.is-on .adj-eq span:nth-child(3){animation-delay:.4s}.adj-hero.is-on .adj-eq span:nth-child(4){animation-delay:.1s}
@keyframes adjEq{0%,100%{height:25%}50%{height:100%}}
.adj-np-main{flex:1;min-width:0}
.adj-np-label{font-size:11px;opacity:.7;text-transform:uppercase;letter-spacing:1px;font-weight:700}
.adj-np-title{font-size:16px;font-weight:700;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.adj-np-bar{height:4px;border-radius:4px;background:rgba(255,255,255,.2);margin-top:8px;overflow:hidden}
.adj-np-bar span{display:block;height:100%;width:0;background:#fff;border-radius:4px;transition:width 1s linear}
.adj-np-time{font-size:12px;opacity:.8;font-variant-numeric:tabular-nums;white-space:nowrap}
.adj-meta{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:10px;margin-top:14px}
@media(max-width:800px){.adj-meta{grid-template-columns:repeat(2,minmax(0,1fr))}}
.adj-meta div{padding:10px 12px;border-radius:12px;background:rgba(255,255,255,.08)}
.adj-meta small{display:block;font-size:11px;opacity:.7;text-transform:uppercase;letter-spacing:.6px}
.adj-meta strong{display:block;font-size:14px;margin-top:2px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}

.adj-tabs{display:inline-flex;gap:4px;padding:4px;border-radius:12px;background:var(--card-bg);border:1px solid var(--border);margin-bottom:16px;flex-wrap:wrap;box-shadow:var(--shadow)}
.adj-tabs a{display:inline-flex;align-items:center;gap:6px;padding:8px 14px;border-radius:9px;font-size:13px;font-weight:600;color:var(--text-secondary);text-decoration:none;transition:var(--transition)}
.adj-tabs a:hover{color:var(--primary);background:var(--primary-bg)}
.adj-tabs a.active{background:var(--primary);color:#fff;box-shadow:0 4px 10px rgba(108,60,224,.25)}

.adj-card{background:var(--card-bg);border:1px solid var(--border);border-radius:16px;box-shadow:var(--shadow)}
.adj-card-h{display:flex;align-items:center;justify-content:space-between;gap:10px;padding:16px 18px;border-bottom:1px solid var(--border);flex-wrap:wrap}
.adj-card-h h3{font-size:15px;font-weight:700;display:flex;align-items:center;gap:8px;margin:0}
.adj-card-h h3 .ic{width:30px;height:30px;border-radius:9px;display:inline-flex;align-items:center;justify-content:center;background:var(--primary-bg);color:var(--primary)}
.adj-card-b{padding:18px}

/* Folders */
.adj-lib{display:grid;grid-template-columns:300px minmax(0,1fr);gap:16px;align-items:start}
@media(max-width:900px){.adj-lib{grid-template-columns:1fr}}
.adj-newfolder{display:flex;gap:6px;padding:14px;border-bottom:1px solid var(--border)}
.adj-newfolder input{flex:1;min-width:0;padding:9px 12px;border:1px solid var(--border);border-radius:9px;font-size:13px;background:var(--card-bg);color:var(--text-primary)}
.adj-newfolder input:focus{outline:none;border-color:var(--primary);box-shadow:0 0 0 3px rgba(108,60,224,.12)}
.adj-newfolder button{border:none;border-radius:9px;background:var(--primary);color:#fff;padding:0 12px;cursor:pointer;display:inline-flex;align-items:center}
.adj-folder-list{padding:8px;max-height:520px;overflow:auto}
.adj-folder{display:flex;align-items:center;gap:10px;padding:10px 12px;border-radius:11px;color:var(--text-primary);text-decoration:none;transition:var(--transition);margin-bottom:2px}
.adj-folder:hover{background:var(--hover);color:var(--text-primary)}
.adj-folder.sel{background:var(--primary-bg)}
.adj-folder .fi{width:36px;height:36px;border-radius:10px;display:flex;align-items:center;justify-content:center;background:var(--hover);color:var(--text-secondary);flex-shrink:0}
.adj-folder.sel .fi{background:var(--primary);color:#fff}
.adj-folder .fn{flex:1;min-width:0}
.adj-folder .fn strong{display:block;font-size:13.5px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.adj-folder .fn small{font-size:12px;color:var(--text-secondary)}
.adj-onair{font-size:10px;font-weight:800;letter-spacing:.6px;padding:3px 7px;border-radius:6px;background:#d1fae5;color:#065f46;white-space:nowrap}
.adj-empty{padding:40px 20px;text-align:center;color:var(--text-secondary);font-size:14px}
.adj-empty .big{width:56px;height:56px;border-radius:16px;margin:0 auto 12px;display:flex;align-items:center;justify-content:center;background:var(--primary-bg);color:var(--primary)}
.adj-toolbar{display:flex;gap:6px;flex-wrap:wrap}
.adj-toolbar form{display:inline}
.adj-tbtn{display:inline-flex;align-items:center;gap:5px;padding:7px 11px;border-radius:9px;font-size:12.5px;font-weight:600;border:1px solid var(--border);background:var(--card-bg);color:var(--text-primary);cursor:pointer;text-decoration:none;transition:var(--transition)}
.adj-tbtn:hover{border-color:var(--primary);color:var(--primary)}
.adj-tbtn.primary{background:var(--primary);border-color:var(--primary);color:#fff}
.adj-tbtn.primary:hover{background:var(--primary-dark);color:#fff}
.adj-tbtn.danger:hover{border-color:var(--danger);color:var(--danger)}
.adj-tbtn[disabled]{opacity:.55;cursor:default}
.adj-search{position:relative;margin-bottom:12px}
.adj-search input{width:100%;padding:10px 12px 10px 36px;border:1px solid var(--border);border-radius:10px;font-size:13.5px;background:var(--card-bg);color:var(--text-primary);box-sizing:border-box}
.adj-search input:focus{outline:none;border-color:var(--primary);box-shadow:0 0 0 3px rgba(108,60,224,.12)}
.adj-search .si{position:absolute;left:12px;top:50%;transform:translateY(-50%);color:var(--text-secondary);display:flex}
.adj-songs{border:1px solid var(--border);border-radius:12px;overflow:hidden}
.adj-song{display:grid;grid-template-columns:34px minmax(0,1fr) 70px 34px;align-items:center;gap:10px;padding:10px 14px;border-bottom:1px solid var(--border);font-size:13.5px;transition:background .15s}
.adj-song:last-child{border-bottom:none}
.adj-song:hover{background:var(--hover)}
.adj-song .n{color:var(--text-secondary);font-variant-numeric:tabular-nums;font-size:12px}
.adj-song .t strong{display:block;font-weight:600;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.adj-song .t small{display:block;font-size:12px;color:var(--text-secondary);white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.adj-song .d{color:var(--text-secondary);font-size:12px;text-align:right;font-variant-numeric:tabular-nums}
.adj-song .x{border:none;background:none;color:var(--text-secondary);cursor:pointer;border-radius:8px;width:30px;height:30px;display:flex;align-items:center;justify-content:center}
.adj-song .x:hover{background:#fee2e2;color:var(--danger)}
.adj-hint{font-size:12px;color:var(--text-secondary);margin:10px 2px 0;display:flex;gap:6px;align-items:center}

/* Settings */
.adj-settings{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:16px}
@media(max-width:900px){.adj-settings{grid-template-columns:1fr}}
.adj-settings .full{grid-column:1/-1}
.adj-field{margin-bottom:14px}
.adj-field:last-child{margin-bottom:0}
.adj-field label{display:block;font-size:12.5px;font-weight:600;color:var(--text-primary);margin-bottom:6px}
.adj-field select,.adj-field input[type=text]{width:100%;padding:9px 12px;border:1px solid var(--border);border-radius:9px;font-size:13.5px;background:var(--card-bg);color:var(--text-primary);box-sizing:border-box}
.adj-field select:focus,.adj-field input:focus{outline:none;border-color:var(--primary);box-shadow:0 0 0 3px rgba(108,60,224,.12)}
.adj-field input:disabled{background:var(--hover);color:var(--text-secondary)}
.adj-field .help{font-size:12px;color:var(--text-secondary);margin-top:6px}
.adj-row2{display:grid;grid-template-columns:1fr 1fr;gap:12px}
@media(max-width:560px){.adj-row2{grid-template-columns:1fr}}
.adj-codecs{display:grid;grid-template-columns:repeat(auto-fit,minmax(130px,1fr));gap:10px}
.adj-codec{position:relative;display:block;cursor:pointer}
.adj-codec input{position:absolute;opacity:0;pointer-events:none}
.adj-codec span{display:block;padding:12px 14px;border:1.5px solid var(--border);border-radius:12px;transition:var(--transition)}
.adj-codec strong{display:block;font-size:15px}
.adj-codec small{display:block;font-size:11.5px;color:var(--text-secondary);margin-top:2px}
.adj-codec input:checked+span{border-color:var(--primary);background:var(--primary-bg);box-shadow:0 0 0 3px rgba(108,60,224,.10)}
.adj-codec input:focus-visible+span{outline:2px solid var(--primary);outline-offset:2px}
.adj-savebar{position:sticky;bottom:12px;z-index:5;margin-top:16px;display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap;padding:12px 16px;border-radius:14px;background:var(--card-bg);border:1px solid var(--border);box-shadow:0 10px 30px rgba(0,0,0,.10)}
.adj-savebar span{font-size:13px;color:var(--text-secondary);display:flex;gap:6px;align-items:center}
.adj-save{border:none;border-radius:10px;background:var(--primary);color:#fff;padding:11px 22px;font-size:14px;font-weight:700;cursor:pointer;display:inline-flex;gap:8px;align-items:center}
.adj-save:hover{background:var(--primary-dark)}
/* schedule tab uses the panel's cards: round them to match */
.adj-wrap .two-cols-equal .card{border-radius:16px}
</style>

<div class="adj-wrap">

<!-- ===== Hero: status, now playing, controls ===== -->
<div class="adj-hero <?= $autodjOnline ? 'is-on' : '' ?>" id="adjHero">
    <div class="adj-hero-top">
        <div>
            <div class="adj-kicker">AutoDJ</div>
            <h2><?= htmlspecialchars($estacion['nombre']) ?></h2>
            <span class="adj-pill <?= $autodjOnline ? 'on' : '' ?>" id="adjPill"><span class="dot"></span><span id="adjPillText"><?= $autodjOnline ? 'On air' : 'Off air' ?></span></span>
        </div>
        <div class="adj-actions">
            <form method="POST"><?= csrfField() ?><input type="hidden" name="action" value="autodj_control"><input type="hidden" name="cmd" value="iniciar">
                <button type="submit" class="adj-btn"><?= cpIcon('play', 14) ?> Start</button></form>
            <form method="POST"><?= csrfField() ?><input type="hidden" name="action" value="autodj_control"><input type="hidden" name="cmd" value="detener">
                <button type="submit" class="adj-btn"><?= cpIcon('stop', 14) ?> Stop</button></form>
            <form method="POST"><?= csrfField() ?><input type="hidden" name="action" value="autodj_control"><input type="hidden" name="cmd" value="reiniciar">
                <button type="submit" class="adj-btn"><?= cpIcon('refresh', 14) ?> Restart</button></form>
            <a href="<?= BASE_URL ?>/cliente/subir_musica.php<?= $onAirId ? '?playlist=' . $onAirId : '' ?>" class="adj-btn solid"><?= cpIcon('upload', 14) ?> Upload music</a>
        </div>
    </div>

    <div class="adj-np">
        <div class="adj-eq"><span></span><span></span><span></span><span></span></div>
        <div class="adj-np-main">
            <div class="adj-np-label">Now playing</div>
            <div class="adj-np-title" id="adjNpTitle"><?= $autodjOnline ? '…' : 'AutoDJ is off' ?></div>
            <div class="adj-np-bar"><span id="adjNpBar"></span></div>
        </div>
        <div class="adj-np-time" id="adjNpTime">--:-- / --:--</div>
    </div>

    <div class="adj-meta">
        <div><small><?= $nowSource['type'] === 'default' && !$nowRelay ? 'Folder on air' : 'Playing now' ?></small><strong title="<?= htmlspecialchars($nowLabel) ?>"><?= htmlspecialchars($nowLabel) ?></strong></div>
        <div><small>Songs</small><strong><?= $onAirCount ?> in folder · <?= $totalCancionesAd ?> total</strong></div>
        <div><small>Encoder</small><strong><?= $codecLabel ?> · <?= (int)($estacion['bitrate'] ?: 128) ?> kbps</strong></div>
        <div><small>Play mode</small><strong><?= $modeLabel ?><?= ($estacion['crossfade_seconds'] ?? 0) > 0 ? ' · ' . (int)$estacion['crossfade_seconds'] . 's fade' : '' ?></strong></div>
    </div>
</div>

<!-- ===== Tabs ===== -->
<nav class="adj-tabs">
    <a href="autodj.php?tab=listas" class="<?= $subtab === 'listas' ? 'active' : '' ?>"><?= cpIcon('folder', 15) ?> Music folders</a>
    <a href="autodj.php?tab=config" class="<?= $subtab === 'config' ? 'active' : '' ?>"><?= cpIcon('gear', 15) ?> AutoDJ settings</a>
    <a href="autodj.php?tab=programacion" class="<?= $subtab === 'programacion' ? 'active' : '' ?>"><?= cpIcon('clock', 15) ?> Schedule</a>
    <a href="<?= BASE_URL ?>/cliente/rotacion_carpetas.php"><?= cpIcon('refresh', 15) ?> Folder rotation</a>
    <a href="<?= BASE_URL ?>/cliente/eventos.php"><?= cpIcon('zap', 15) ?> Scheduled events</a>
</nav>

<?php if ($subtab === 'listas'): ?>
<!-- ===== Music folders ===== -->
<div class="adj-lib">
    <aside class="adj-card">
        <form method="POST" action="autodj.php?tab=listas" class="adj-newfolder">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="create_playlist">
            <input type="text" name="nombre" maxlength="128" placeholder="New folder name" required aria-label="New folder name">
            <button type="submit" title="Create folder" aria-label="Create folder"><?= cpIcon('plus', 16) ?></button>
        </form>
        <div class="adj-folder-list">
            <?php if (!$playlists): ?>
                <div class="adj-empty" style="padding:24px 10px">No folders yet — create one above.</div>
            <?php endif; ?>
            <?php foreach ($playlists as $pl):
                $cnt = $playlistTrackCounts[(int)$pl['id']] ?? 0;
                $isSel = (int)$pl['id'] === (int)$selectedPl;
            ?>
            <a class="adj-folder <?= $isSel ? 'sel' : '' ?>" href="autodj.php?tab=listas&pl=<?= (int)$pl['id'] ?>">
                <span class="fi"><?= cpIcon('folder', 17) ?></span>
                <span class="fn"><strong><?= htmlspecialchars($pl['nombre']) ?></strong><small><?= $cnt ?> song<?= $cnt === 1 ? '' : 's' ?></small></span>
                <?php if ((int)$pl['id'] === $onAirId): ?><span class="adj-onair">ON AIR</span><?php endif; ?>
            </a>
            <?php endforeach; ?>
        </div>
    </aside>

    <section class="adj-card">
    <?php if ($playlistSeleccionada): $plId = (int)$playlistSeleccionada['id']; ?>
        <div class="adj-card-h">
            <h3><span class="ic"><?= cpIcon('folder', 16) ?></span> <?= htmlspecialchars($playlistSeleccionada['nombre']) ?>
                <small style="font-weight:500;color:var(--text-secondary)">· <?= count($canciones) ?> song<?= count($canciones) === 1 ? '' : 's' ?></small></h3>
            <div class="adj-toolbar">
                <?php if ($plId === $onAirId): ?>
                    <button type="button" class="adj-tbtn" disabled><?= cpIcon('check', 14) ?> On air</button>
                <?php else: ?>
                    <button type="button" class="adj-tbtn primary" onclick="adjPlayFolder(<?= $plId ?>, this)"><?= cpIcon('play', 14) ?> Play on air</button>
                <?php endif; ?>
                <a href="<?= BASE_URL ?>/cliente/subir_musica.php?playlist=<?= $plId ?>" class="adj-tbtn"><?= cpIcon('upload', 14) ?> Upload</a>
                <form method="POST"><?= csrfField() ?><input type="hidden" name="action" value="sort_by_prefix"><input type="hidden" name="playlist_id" value="<?= $plId ?>">
                    <button type="submit" class="adj-tbtn" title="Sort by name prefix"><?= cpIcon('list', 14) ?> A–Z</button></form>
                <form method="POST"><?= csrfField() ?><input type="hidden" name="action" value="sort_by_number"><input type="hidden" name="playlist_id" value="<?= $plId ?>">
                    <button type="submit" class="adj-tbtn" title="Sort by number"><?= cpIcon('list', 14) ?> 1–9</button></form>
                <form method="POST" onsubmit="return confirm('Delete the folder &quot;<?= htmlspecialchars($playlistSeleccionada['nombre'], ENT_QUOTES) ?>&quot; and all its songs?')">
                    <?= csrfField() ?><input type="hidden" name="action" value="delete_playlist"><input type="hidden" name="playlist_id" value="<?= $plId ?>">
                    <button type="submit" class="adj-tbtn danger"><?= cpIcon('trash', 14) ?> Delete</button></form>
            </div>
        </div>
        <div class="adj-card-b">
            <?php if (empty($canciones)): ?>
                <div class="adj-empty">
                    <div class="big"><?= cpIcon('music', 24) ?></div>
                    <p>This folder is empty.</p>
                    <a href="<?= BASE_URL ?>/cliente/subir_musica.php?playlist=<?= $plId ?>" class="adj-tbtn primary" style="margin-top:12px"><?= cpIcon('upload', 14) ?> Upload music</a>
                </div>
            <?php else: ?>
                <div class="adj-search">
                    <span class="si"><?= cpIcon('search', 15) ?></span>
                    <input type="text" placeholder="Search songs or artists…" id="songSearch" oninput="filterSongs()">
                </div>
                <div class="adj-songs" id="songList">
                    <?php foreach ($canciones as $idx => $cancion): ?>
                    <div class="adj-song" data-title="<?= htmlspecialchars(mb_strtolower(($cancion['titulo'] ?: $cancion['nombre_original']) . ' ' . ($cancion['artista'] ?? ''))) ?>">
                        <span class="n"><?= $idx + 1 ?></span>
                        <span class="t"><strong><?= htmlspecialchars($cancion['titulo'] ?: $cancion['nombre_original']) ?></strong>
                            <small><?= htmlspecialchars($cancion['artista'] ?: $cancion['nombre_original']) ?> · <?= isset($cancion['tamano_bytes']) ? formatBytes((int)$cancion['tamano_bytes'], 1) : '' ?></small></span>
                        <span class="d"><?= !empty($cancion['duracion_segundos']) ? formatDuration((int)$cancion['duracion_segundos']) : '--:--' ?></span>
                        <form method="POST" onsubmit="return confirm('Delete this song?')">
                            <?= csrfField() ?><input type="hidden" name="action" value="delete_song">
                            <input type="hidden" name="song_id" value="<?= (int)$cancion['id'] ?>"><input type="hidden" name="playlist_id" value="<?= $plId ?>">
                            <button type="submit" class="x" title="Delete song" aria-label="Delete song"><?= cpIcon('trash', 14) ?></button>
                        </form>
                    </div>
                    <?php endforeach; ?>
                </div>
                <p class="adj-hint"><?= cpIcon('info', 13) ?> When the last song ends, AutoDJ starts this folder again from the top.</p>
            <?php endif; ?>
        </div>
    <?php else: ?>
        <div class="adj-empty">
            <div class="big"><?= cpIcon('folder', 24) ?></div>
            <p>Create a folder on the left, then upload music into it.</p>
        </div>
    <?php endif; ?>
    </section>
</div>

<script>
function filterSongs() {
    var q = document.getElementById('songSearch').value.toLowerCase();
    document.querySelectorAll('#songList .adj-song').forEach(function (row) {
        row.style.display = (row.getAttribute('data-title') || '').indexOf(q) !== -1 ? '' : 'none';
    });
}
function adjPlayFolder(id, btn) {
    btn.disabled = true;
    var body = new URLSearchParams({ action: 'set_active_playlist', playlist_id: id, ajax: '1',
        csrf_token: <?= json_encode(csrfToken()) ?> });
    fetch(<?= json_encode((BASE_URL ?: '') . '/cliente/quick_control_api.php') ?>, { method: 'POST', credentials: 'same-origin',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: body })
        .then(function (r) { return r.json(); })
        .then(function (d) {
            if (!d.success) { btn.disabled = false; alert(d.error || 'Could not switch folder'); return; }
            location.reload();
        })
        .catch(function () { btn.disabled = false; alert('Network error — try again.'); });
}
</script>

<?php elseif ($subtab === 'config'): ?>
<!-- ===== AutoDJ settings ===== -->
<form method="POST">
    <?= csrfField() ?>
    <input type="hidden" name="action" value="update_config">

    <div class="adj-settings">
        <div class="adj-card full">
            <div class="adj-card-h"><h3><span class="ic"><?= cpIcon('folder', 16) ?></span> Music source</h3></div>
            <div class="adj-card-b">
                <div class="adj-row2">
                    <div class="adj-field">
                        <label for="adjFolder">Folder on air</label>
                        <select name="playlist_activa" id="adjFolder">
                            <option value="">— None (silence) —</option>
                            <?php foreach ($playlists as $pl):
                                $cnt = $playlistTrackCounts[(int)$pl['id']] ?? 0; ?>
                                <option value="<?= (int)$pl['id'] ?>" <?= (int)$pl['id'] === $onAirId ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($pl['nombre']) ?> — <?= $cnt ?> song<?= $cnt === 1 ? '' : 's' ?><?= $cnt === 0 ? ' (empty)' : '' ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="adj-field">
                        <label for="cpNewFolder">…or create a new folder</label>
                        <input type="text" name="new_folder" id="cpNewFolder" maxlength="128" placeholder="Folder name">
                    </div>
                </div>
                <p class="help" style="font-size:12px;color:var(--text-secondary);margin:4px 0 0">
                    Pick a folder, choose the encoder and press <strong>Save changes</strong> — AutoDJ plays it right away and loops it, no station restart needed.
                    <a href="subir_musica.php">Upload music</a>
                </p>
            </div>
        </div>

        <div class="adj-card">
            <div class="adj-card-h"><h3><span class="ic"><?= cpIcon('signal', 16) ?></span> Stream encoding</h3></div>
            <div class="adj-card-b">
                <div class="adj-field">
                    <label>Encoder</label>
                    <div class="adj-codecs">
                        <label class="adj-codec"><input type="radio" name="codec" value="mp3" <?= $curCodec === 'mp3' ? 'checked' : '' ?>><span><strong>MP3</strong><small>Plays everywhere</small></span></label>
                        <label class="adj-codec"><input type="radio" name="codec" value="aac" <?= $curCodec === 'aac' ? 'checked' : '' ?>><span><strong>AAC</strong><small>Better sound at low bitrate</small></span></label>
                        <?php if (!$isShoutStation): /* SHOUTcast only carries MP3/AAC */ ?>
                        <label class="adj-codec"><input type="radio" name="codec" value="ogg" <?= $curCodec === 'ogg' ? 'checked' : '' ?>><span><strong>OGG</strong><small>Vorbis (Icecast)</small></span></label>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="adj-field">
                    <label for="adjBitrate">Bitrate</label>
                    <select name="bitrate" id="adjBitrate">
                        <?php foreach ([64, 96, 128, 192, 256, 320] as $br): ?>
                            <option value="<?= $br ?>" <?= (int)($estacion['bitrate'] ?? 128) === $br ? 'selected' : '' ?>><?= $br ?> kbps</option>
                        <?php endforeach; ?>
                    </select>
                    <?php if (!empty($estacion['max_bitrate'])): ?><div class="help">Your package allows up to <?= (int)$estacion['max_bitrate'] ?> kbps.</div><?php endif; ?>
                </div>
            </div>
        </div>

        <div class="adj-card">
            <div class="adj-card-h"><h3><span class="ic"><?= cpIcon('music', 16) ?></span> Playback</h3></div>
            <div class="adj-card-b">
                <div class="adj-field">
                    <label for="adjMode">Play mode</label>
                    <select name="modo_reproduccion" id="adjMode">
                        <option value="normal" <?= ($estacion['modo_reproduccion'] ?? '') === 'normal' ? 'selected' : '' ?>>Normal (folder order)</option>
                        <option value="aleatorio" <?= ($estacion['modo_reproduccion'] ?? '') === 'aleatorio' ? 'selected' : '' ?>>Shuffle</option>
                        <option value="secuencial" <?= ($estacion['modo_reproduccion'] ?? '') === 'secuencial' ? 'selected' : '' ?>>Strict sequential</option>
                    </select>
                </div>
                <div class="adj-row2">
                    <div class="adj-field">
                        <label for="adjFade">Crossfade</label>
                        <select name="crossfade" id="adjFade">
                            <?php foreach ([0, 2, 3, 4, 5, 8, 10] as $cf): ?>
                                <option value="<?= $cf ?>" <?= (int)($estacion['crossfade_seconds'] ?? 0) === $cf ? 'selected' : '' ?>><?= $cf ? $cf . ' seconds' : 'Off' ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="adj-field">
                        <label for="adjTitles">Song titles</label>
                        <select name="mostrar_titulos" id="adjTitles">
                            <option value="1" <?= ($estacion['mostrar_titulos'] ?? 1) ? 'selected' : '' ?>>Show</option>
                            <option value="0" <?= !($estacion['mostrar_titulos'] ?? 1) ? 'selected' : '' ?>>Hide</option>
                        </select>
                    </div>
                </div>
            </div>
        </div>

        <div class="adj-card">
            <div class="adj-card-h"><h3><span class="ic"><?= cpIcon('radio', 16) ?></span> Station info</h3></div>
            <div class="adj-card-b">
                <div class="adj-field">
                    <label for="adjTitle">Radio title</label>
                    <input type="text" name="titulo_radio" id="adjTitle" value="<?= htmlspecialchars($estacion['titulo_radio'] ?? '') ?>">
                </div>
                <div class="adj-row2">
                    <div class="adj-field">
                        <label for="adjUrl">Website</label>
                        <input type="text" name="url_web" id="adjUrl" value="<?= htmlspecialchars($estacion['url_web'] ?? '') ?>" placeholder="https://yourradio.com">
                    </div>
                    <div class="adj-field">
                        <label>Genre</label>
                        <input type="text" value="<?= htmlspecialchars($estacion['genero'] ?? 'various') ?>" disabled>
                    </div>
                </div>
            </div>
        </div>

        <div class="adj-card">
            <div class="adj-card-h"><h3><span class="ic"><?= cpIcon('microphone', 16) ?></span> Live DJ</h3></div>
            <div class="adj-card-b">
                <div class="adj-field">
                    <label>DJ port (automatic)</label>
                    <input type="text" value="<?= $harborPort ?>" disabled>
                    <div class="help">DJs connect here; live audio replaces AutoDJ until they disconnect.</div>
                </div>
                <div class="adj-row2">
                    <div class="adj-field">
                        <label for="adjRec">Record live shows</label>
                        <select name="grabar_dj" id="adjRec">
                            <option value="0" <?= !($estacion['grabar_dj'] ?? 0) ? 'selected' : '' ?>>No</option>
                            <option value="1" <?= ($estacion['grabar_dj'] ?? 0) ? 'selected' : '' ?>>Yes</option>
                        </select>
                    </div>
                    <div class="adj-field">
                        <label for="adjRecQ">Recording quality</label>
                        <select name="calidad_grabacion" id="adjRecQ">
                            <?php foreach ([128, 192, 256, 320] as $br): ?>
                                <option value="<?= $br ?>" <?= (int)($estacion['calidad_grabacion'] ?? 320) === $br ? 'selected' : '' ?>><?= $br ?> kbps</option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="adj-savebar">
        <span><?= cpIcon('info', 14) ?> Changes go live as soon as you save.</span>
        <button type="submit" class="adj-save"><?= cpIcon('save', 15) ?> Save changes</button>
    </div>
</form>

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

</div><!-- .adj-wrap -->

<script>
// Live "now playing" in the AutoDJ header
(function () {
    var api = <?= json_encode((BASE_URL ?: '') . '/api/autodj_player.php?estacion=' . (int)$estacion['id'] . '&action=status') ?>;
    var st = { elapsed: 0, duration: 0, on: false };
    function fmt(s) { s = Math.max(0, Math.floor(s || 0)); return String(Math.floor(s / 60)).padStart(2, '0') + ':' + String(s % 60).padStart(2, '0'); }
    function paint() {
        var bar = document.getElementById('adjNpBar'), tm = document.getElementById('adjNpTime');
        if (!bar) return;
        if (st.on && st.duration > 1) {
            bar.style.width = Math.min(100, st.elapsed / st.duration * 100) + '%';
            tm.textContent = fmt(st.elapsed) + ' / ' + fmt(st.duration);
        } else { bar.style.width = '0'; tm.textContent = '--:-- / --:--'; }
    }
    function poll() {
        fetch(api, { credentials: 'same-origin' }).then(function (r) { return r.json(); }).then(function (d) {
            if (!d || !d.success || !d.player) return;
            var p = d.player, on = !!p.online;
            st = { elapsed: p.elapsed || 0, duration: p.duration || 0, on: on };
            document.getElementById('adjNpTitle').textContent = p.title || (on ? 'Streaming…' : 'AutoDJ is off');
            document.getElementById('adjHero').classList.toggle('is-on', on);
            document.getElementById('adjPill').classList.toggle('on', on);
            document.getElementById('adjPillText').textContent = on ? 'On air' : 'Off air';
            paint();
        }).catch(function () {});
    }
    poll();
    setInterval(poll, 5000);
    setInterval(function () { if (st.on && st.duration > 0 && st.elapsed < st.duration) { st.elapsed++; paint(); } }, 1000);
})();
</script>

<?php require_once __DIR__ . '/../includes/layout_footer.php'; ?>
