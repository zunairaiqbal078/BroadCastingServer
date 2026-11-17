<?php
ob_start();
/**
 * CastPanel v2.3 - Dashboard Cliente (Estilo SonicPanel)
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
if (isset($_GET['login_as'])) {
    if (castpanelAdminLoginAsCliente((int)$_GET['login_as'])) {
        header('Location: ' . BASE_URL . '/cliente/index.php');
        exit;
    }
    setFlash('error', 'Could not open the client panel (user has no active station).');
    header('Location: ' . BASE_URL . '/admin/estaciones.php');
    exit;
}

// Auto-sync music on dashboard open (existing audios play without manual restart).
if ($_SERVER['REQUEST_METHOD'] === 'GET'
    && !(defined('CASTPANEL_STREAMING_DISABLED') && CASTPANEL_STREAMING_DISABLED)) {
    requireLogin();
    $estacionBootstrap = getCurrentStation();
    if ($estacionBootstrap) {
        require_once __DIR__ . '/../includes/provisioning.php';
        $estFull = loadStationWithPackage((int)$estacionBootstrap['id']);
        if ($estFull && stationHasAutoDJ($estFull)) {
            ensureAutoDJPlaying($estFull);
            clearStreamStatusCache((int)$estFull['id']);
        }
    }
}

$pageTitle = 'Panel de Control';
$pageIcon = 'radio';
require_once __DIR__ . '/../includes/layout_header.php';

if (!$estacion) {
    echo '<div class="alert alert-warning">No tienes una estaci&oacute;n asignada. Contacta al administrador.</div>';
    require_once __DIR__ . '/../includes/layout_footer.php';
    exit;
}

$db = getDB();

// Obtener stats (defensivo)
$iceStatus = getStreamStatus($estacion);
$oyentesAhora = $iceStatus['oyentes'];
$picoOyentes = getPeakListeners($estacion['id'], 24);
$storageUsedBytes = getStorageUsed($estacion['id']);
$storageUsedMB = round($storageUsedBytes / (1024 * 1024), 1);
// Storage contracted in the plan (Add Radio Package); 0 = unlimited
$maxStorageMB = (int)($estacion['max_storage_mb'] ?? 0);
$storagePercent = $maxStorageMB > 0 ? min(round(($storageUsedMB / $maxStorageMB) * 100, 1), 100) : 0;
$storageCapLabel = $maxStorageMB > 0 ? formatMB($maxStorageMB) : 'Ilimitado';
$maxOyentes = $estacion['max_oyentes'] ?? 500;
$oyentesPercent = $maxOyentes > 0 ? min(round(($oyentesAhora / $maxOyentes) * 100, 1), 100) : 0;
$maxBitrate = $estacion['max_bitrate'] ?? 320;
$bitratePercent = $maxBitrate > 0 ? min(round(($estacion['bitrate'] / $maxBitrate) * 100, 1), 100) : 0;

// Total canciones y anuncios (defensivo)
$totalCanciones = 0;
$totalAnuncios = 0;
try {
    $stmt = $db->prepare("SELECT COUNT(*) FROM canciones WHERE estacion_id = ?");
    $stmt->execute([$estacion['id']]);
    $totalCanciones = (int)$stmt->fetchColumn();
} catch (PDOException $e) {}
try {
    $stmt = $db->prepare("SELECT COUNT(*) FROM anuncios WHERE estacion_id = ?");
    $stmt->execute([$estacion['id']]);
    $totalAnuncios = (int)$stmt->fetchColumn();
} catch (PDOException $e) {}

// Log de emisión (defensivo)
$logEmision = getEmisionLog($estacion['id'], 15);

// Playlist activa (defensivo)
$playlistActiva = null;
try {
    if ($estacion['autodj_playlist_activa']) {
        $stmt = $db->prepare("SELECT * FROM playlists WHERE id = ?");
        $stmt->execute([$estacion['autodj_playlist_activa']]);
        $playlistActiva = $stmt->fetch();
    }
} catch (PDOException $e) {}

$activePlaylistSongCount = 0;
$otherPlaylistSongHint = null;
try {
    if (!empty($estacion['autodj_playlist_activa'])) {
        $stmt = $db->prepare('SELECT COUNT(*) FROM canciones WHERE estacion_id = ? AND playlist_id = ? AND activa = 1');
        $stmt->execute([$estacion['id'], $estacion['autodj_playlist_activa']]);
        $activePlaylistSongCount = (int)$stmt->fetchColumn();
    }
    if ($activePlaylistSongCount === 0 && $totalCanciones > 0) {
        $stmt = $db->prepare(
            'SELECT p.nombre, COUNT(c.id) AS cnt FROM canciones c
             JOIN playlists p ON p.id = c.playlist_id
             WHERE c.estacion_id = ? AND c.activa = 1 AND c.playlist_id IS NOT NULL
             GROUP BY p.id ORDER BY cnt DESC LIMIT 1'
        );
        $stmt->execute([$estacion['id']]);
        $otherPlaylistSongHint = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }
} catch (PDOException $e) {}

$dashboardPlaylists = [];
$dashboardPlaylistCounts = [];
try {
    $stmt = $db->prepare('SELECT * FROM playlists WHERE estacion_id = ? ORDER BY nombre');
    $stmt->execute([$estacion['id']]);
    $dashboardPlaylists = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $stmt = $db->prepare(
        'SELECT playlist_id, COUNT(*) AS cnt FROM canciones WHERE estacion_id = ? AND activa = 1 AND playlist_id IS NOT NULL GROUP BY playlist_id'
    );
    $stmt->execute([$estacion['id']]);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $dashboardPlaylistCounts[(int)$row['playlist_id']] = (int)$row['cnt'];
    }
} catch (PDOException $e) {}

// Cuentas DJ
$totalDJ = 0;
$maxDJ = $estacion['max_dj'] ?? 5;
try {
    $stmt = $db->prepare("SELECT COUNT(*) FROM cuentas_dj WHERE estacion_id = ?");
    $stmt->execute([$estacion['id']]);
    $totalDJ = (int)$stmt->fetchColumn();
} catch (PDOException $e) {}

// URL del stream — mismo origen que el panel (Apache proxy /shoutcast/ o /live/)
$streamUrl = castpanelStreamPlaybackUrl($estacion);
// Links for the "SSL/Play links" box. SHOUTcast listens on puerto_stream;
// icecast_port defaults to 8000 for every station, so it must not be used here.
$cpIsShout = isShoutcastServerType($estacion['server_type'] ?? '');
$cpStationPort = $cpIsShout ? ((int)($estacion['puerto_stream'] ?? 0) ?: 8000) : ((int)($estacion['icecast_port'] ?? 0) ?: 8000);
$cpHasSsl = defined('SSL_DOMAIN') && SSL_DOMAIN !== '' && SSL_DOMAIN !== '__DOMAIN__';
$cpSecureUrl = $cpHasSsl ? 'https://' . SSL_DOMAIN . ($cpIsShout ? '/shoutcast/' : '/live/') . rawurlencode($estacion['slug']) : $streamUrl;
$cpDirectUrl = 'http://' . castpanelPublicHost() . ':' . $cpStationPort . ($cpIsShout ? '/stream' : ($estacion['icecast_mount'] ?? '/stream'));
$cpWidgetUrl = castpanelWidgetPlayerUrls($estacion)['full'];

// Obtener configuración del player (para effect_type en URL del reproductor)
$playerEffect = 'rain';
try {
    $stmtPc = $db->prepare("SELECT effect_type FROM player_config WHERE estacion_id = ? LIMIT 1");
    $stmtPc->execute([$estacion['id']]);
    $pcRow = $stmtPc->fetch();
    if ($pcRow && !empty($pcRow['effect_type'])) {
        $playerEffect = $pcRow['effect_type'];
    }
} catch (PDOException $e) {}

// Now playing (SHOUTcast, telnet, or emission log)
$cancionActual = $iceStatus['titulo'] ?? '';
if ($cancionActual === '' && !empty($iceStatus['online'])) {
    $cancionActual = castpanelLiquidsoapNowPlaying($estacion);
}
$quickPlayerAutodjFirst = !empty($estacion['autodj_enabled']);
$npEmptyLabel = 'No track info';
$npBarPrefix = 'Now playing: ';
$streamOnline = !empty($iceStatus['online']);
$autodjOnline = ($estacion['autodj_estado'] ?? 'offline') === 'online';
$cpServerLabel = match ($estacion['server_type'] ?? '') {
    'shoutcast_v2' => 'SHOUTcast v2',
    'shoutcast_v25' => 'SHOUTcast v2.5',
    'shoutcast_v1', 'shoutcast' => 'SHOUTcast',
    'icecast' => 'Icecast',
    default => ucfirst((string)($estacion['server_type'] ?? 'stream')),
};
?>

<style>
/* ===== Client dashboard (redesign) ===== */
.cp2-hero{position:relative;overflow:hidden;border-radius:18px;padding:24px 26px;margin-bottom:18px;color:#fff;
  background:linear-gradient(135deg,var(--primary) 0%,#0f5f66 58%,#0b3d6e 100%);box-shadow:0 14px 34px rgba(15,40,80,.20)}
.cp2-hero::before,.cp2-hero::after{content:'';position:absolute;border-radius:50%;filter:blur(40px);pointer-events:none}
.cp2-hero::before{width:280px;height:280px;right:-90px;top:-120px;background:rgba(255,255,255,.18)}
.cp2-hero::after{width:220px;height:220px;left:28%;bottom:-140px;background:rgba(255,255,255,.10)}
.cp2-hero>*{position:relative;z-index:1}
.cp2-top{display:flex;justify-content:space-between;align-items:flex-start;gap:16px;flex-wrap:wrap}
.cp2-kicker{font-size:11px;letter-spacing:1.4px;text-transform:uppercase;opacity:.75;font-weight:700}
.cp2-hero h2{font-size:26px;font-weight:800;margin:4px 0 8px;color:#fff}
.cp2-chips{display:flex;gap:8px;flex-wrap:wrap}
.cp2-chip{display:inline-flex;align-items:center;gap:7px;padding:5px 12px;border-radius:30px;font-size:12px;font-weight:600;background:rgba(255,255,255,.14);border:1px solid rgba(255,255,255,.22)}
.cp2-chip .dot{width:8px;height:8px;border-radius:50%;background:#fca5a5}
.cp2-chip.on .dot{background:#34d399;animation:cp2Pulse 1.8s infinite}
@keyframes cp2Pulse{0%{box-shadow:0 0 0 0 rgba(52,211,153,.6)}70%{box-shadow:0 0 0 8px rgba(52,211,153,0)}100%{box-shadow:0 0 0 0 rgba(52,211,153,0)}}
.cp2-actions{display:flex;gap:8px;flex-wrap:wrap}
.cp2-btn{display:inline-flex;align-items:center;gap:6px;padding:9px 15px;border-radius:10px;font-size:13px;font-weight:600;border:1px solid rgba(255,255,255,.3);background:rgba(255,255,255,.12);color:#fff;text-decoration:none;transition:background .2s,transform .2s}
.cp2-btn:hover{background:rgba(255,255,255,.24);color:#fff;transform:translateY(-1px)}
.cp2-btn.solid{background:#fff;color:var(--primary);border-color:#fff}
.cp2-btn.solid:hover{background:rgba(255,255,255,.88);color:var(--primary)}
.cp2-np{margin-top:18px;display:flex;align-items:center;gap:14px;padding:13px 16px;border-radius:14px;background:rgba(255,255,255,.10);border:1px solid rgba(255,255,255,.16)}
.cp2-eq{display:flex;align-items:flex-end;gap:3px;height:24px;width:26px;flex-shrink:0}
.cp2-eq span{display:block;width:4px;border-radius:2px;background:#fff;opacity:.85;height:30%}
.cp2-hero.is-on .cp2-eq span{animation:cp2Eq 1s ease-in-out infinite}
.cp2-hero.is-on .cp2-eq span:nth-child(2){animation-delay:.2s}.cp2-hero.is-on .cp2-eq span:nth-child(3){animation-delay:.4s}.cp2-hero.is-on .cp2-eq span:nth-child(4){animation-delay:.1s}
@keyframes cp2Eq{0%,100%{height:25%}50%{height:100%}}
.cp2-np-main{flex:1;min-width:0}
.cp2-np small{display:block;font-size:11px;opacity:.7;text-transform:uppercase;letter-spacing:1px;font-weight:700}
.cp2-np strong{display:block;font-size:16px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.cp2-np a{color:#fff;font-size:12.5px;font-weight:600;white-space:nowrap;opacity:.9}
.cp2-metrics{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:10px;margin-top:14px}
@media(max-width:900px){.cp2-metrics{grid-template-columns:repeat(2,minmax(0,1fr))}}
.cp2-metric{padding:12px 14px;border-radius:12px;background:rgba(255,255,255,.08);text-decoration:none;color:#fff;transition:background .2s}
a.cp2-metric:hover{background:rgba(255,255,255,.16);color:#fff}
.cp2-metric small{display:block;font-size:11px;opacity:.7;text-transform:uppercase;letter-spacing:.6px}
.cp2-metric strong{display:block;font-size:22px;font-weight:800;margin-top:2px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.cp2-metric em{display:block;font-style:normal;font-size:12px;opacity:.75;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.cp2-bar{height:5px;border-radius:5px;background:rgba(255,255,255,.2);margin-top:7px;overflow:hidden}
.cp2-bar span{display:block;height:100%;background:#fff;border-radius:5px}

.cp2-shortcuts{display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:10px;margin-bottom:20px}
.cp2-sc{display:flex;align-items:center;gap:10px;padding:12px 14px;background:var(--card-bg);border:1px solid var(--border);border-radius:14px;color:var(--text-primary);font-size:13px;font-weight:600;text-decoration:none;transition:var(--transition)}
.cp2-sc:hover{border-color:var(--primary);color:var(--primary);transform:translateY(-2px);box-shadow:var(--shadow-md)}
.cp2-sc .ic{width:34px;height:34px;border-radius:10px;display:flex;align-items:center;justify-content:center;background:var(--primary-bg);color:var(--primary);flex-shrink:0}
/* the cards below get the same rounded look */
.sp-card{border-radius:16px!important;overflow:hidden}
</style>

<div class="cp2-hero <?= $streamOnline ? 'is-on' : '' ?>" id="cp2Hero">
    <div class="cp2-top">
        <div>
            <div class="cp2-kicker">Welcome, <?= htmlspecialchars($_SESSION['user_username'] ?? '') ?></div>
            <h2><?= htmlspecialchars($estacion['nombre']) ?></h2>
            <div class="cp2-chips">
                <span class="cp2-chip <?= $streamOnline ? 'on' : '' ?>" id="cp2Pill"><span class="dot"></span><span id="cp2PillText"><?= $streamOnline ? 'On air' : 'Offline' ?></span></span>
                <span class="cp2-chip"><?= cpIcon('radio', 13) ?> <?= htmlspecialchars($cpServerLabel) ?> · <?= (int)$cpStationPort ?></span>
                <?php if (!empty($estacion['autodj_enabled'])): ?>
                <span class="cp2-chip"><?= cpIcon('gear', 13) ?> AutoDJ <?= $autodjOnline ? 'on' : 'off' ?></span>
                <?php endif; ?>
            </div>
        </div>
        <div class="cp2-actions">
            <?php if (!empty($estacion['autodj_enabled'])): ?>
            <a href="<?= BASE_URL ?>/cliente/autodj.php" class="cp2-btn"><?= cpIcon('gear', 15) ?> Manage AutoDJ</a>
            <?php endif; ?>
            <a href="<?= BASE_URL ?>/cliente/widgets.php" class="cp2-btn"><?= cpIcon('link', 15) ?> Widgets</a>
            <a href="<?= BASE_URL ?>/cliente/subir_musica.php" class="cp2-btn solid"><?= cpIcon('upload', 15) ?> Upload music</a>
        </div>
    </div>

    <div class="cp2-np">
        <div class="cp2-eq"><span></span><span></span><span></span><span></span></div>
        <div class="cp2-np-main">
            <small>Now playing</small>
            <strong id="cp2NpTitle"><?= htmlspecialchars($cancionActual ?: $npEmptyLabel) ?></strong>
        </div>
        <a href="#ctrl-estado" onclick="var b=[...document.querySelectorAll('.sp-tab')].find(t=>t.textContent.trim()==='Radio status'); if(b){b.click(); b.scrollIntoView({behavior:'smooth',block:'center'});} return false;">▶ Listen</a>
    </div>

    <div class="cp2-metrics">
        <a class="cp2-metric" href="<?= BASE_URL ?>/cliente/estadisticas.php"><small>Listeners now</small><strong><?= (int)$oyentesAhora ?></strong><em>Peak 24h: <?= (int)$picoOyentes ?></em></a>
        <a class="cp2-metric" href="<?= BASE_URL ?>/cliente/biblioteca.php"><small>Songs</small><strong><?= (int)$totalCanciones ?></strong><em><?= (int)$totalAnuncios ?> ads in library</em></a>
        <a class="cp2-metric" href="<?= BASE_URL ?>/cliente/autodj.php"><small>Folder on air</small><strong style="font-size:17px;margin-top:6px"><?= htmlspecialchars($playlistActiva['nombre'] ?? 'None') ?></strong><em><?= (int)$activePlaylistSongCount ?> songs</em></a>
        <div class="cp2-metric"><small>Storage</small><strong style="font-size:17px;margin-top:6px"><?= number_format($storageUsedMB, 1) ?> MB</strong><em><?= $maxStorageMB > 0 ? 'de ' . htmlspecialchars($storageCapLabel) : 'Ilimitado' ?></em><?php if ($maxStorageMB > 0): ?><div class="cp2-bar"><span style="width:<?= $storagePercent ?>%;<?= $storagePercent > 90 ? 'background:#fca5a5' : '' ?>"></span></div><?php endif; ?></div>
    </div>
</div>

<div class="cp2-shortcuts">
    <a class="cp2-sc" href="<?= BASE_URL ?>/cliente/cuenta_dj.php"><span class="ic"><?= cpIcon('microphone', 16) ?></span> DJ accounts</a>
    <a class="cp2-sc" href="<?= BASE_URL ?>/cliente/player_config.php"><span class="ic"><?= cpIcon('palette', 16) ?></span> Customize player</a>
    <a class="cp2-sc" href="<?= BASE_URL ?>/cliente/enlaces.php"><span class="ic"><?= cpIcon('lock', 16) ?></span> SSL links</a>
    <a class="cp2-sc" href="<?= BASE_URL ?>/cliente/anuncios.php"><span class="ic"><?= cpIcon('megaphone', 16) ?></span> Ads</a>
    <a class="cp2-sc" href="<?= BASE_URL ?>/cliente/estadisticas.php"><span class="ic"><?= cpIcon('chart', 16) ?></span> Statistics</a>
    <a class="cp2-sc" href="<?= BASE_URL ?>/cliente/api_json.php"><span class="ic"><?= cpIcon('code', 16) ?></span> API JSON</a>
</div>

<?php if (!empty($estacion['autodj_enabled']) && $activePlaylistSongCount === 0 && $totalCanciones > 0 && $otherPlaylistSongHint): ?>
<div class="alert alert-warning" style="margin-bottom:16px">
    <strong>AutoDJ playlist is empty.</strong>
    You have <?= (int)$totalCanciones ?> song(s) in the library, but the active playlist
    <strong><?= htmlspecialchars($playlistActiva['nombre'] ?? '—') ?></strong> has no tracks.
    Most of your music is in <strong><?= htmlspecialchars($otherPlaylistSongHint['nombre']) ?></strong>
    (<?= (int)$otherPlaylistSongHint['cnt'] ?> tracks).
    <a href="<?= BASE_URL ?>/cliente/autodj.php?tab=config" class="btn btn-sm btn-primary" style="margin-left:8px">Set active playlist</a>
    or upload into the active playlist on <a href="<?= BASE_URL ?>/cliente/subir_musica.php">Upload music</a>.
</div>
<?php endif; ?>

<style>
.cp-dot{display:inline-block;width:8px;height:8px;border-radius:50%;margin-right:4px;vertical-align:middle}
.cp-dot.on{background:var(--success);box-shadow:0 0 0 3px rgba(16,185,129,.2)}
.cp-dot.off{background:#9ca3af}
.cp-client-stats{display:grid!important;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:16px;margin-bottom:22px;width:100%}
.cp-client-stats .stat-card,.cp-client-stats a.stat-card{display:block!important;min-height:112px;color:#fff!important;text-decoration:none}
</style>

<!-- ===== DASHBOARD SONICPANEL STYLE ===== -->
<div class="sp-dashboard">

    <!-- ROW 1: Info de Radio + Control Rápido -->
    <div class="sp-row">

        <!-- CARD: Info de Radio -->
        <div class="sp-card">
            <div class="sp-card-header">
                <h3><?= cpIcon('radio', 18) ?> Info de Radio</h3>
            </div>
            <div class="sp-card-tabs">
                <button class="sp-tab active" onclick="spTab(this,'info-cuenta')">Cuenta</button>
                <button class="sp-tab" onclick="spTab(this,'info-limites')">L&iacute;mites de Radio</button>
                <button class="sp-tab" onclick="spTab(this,'info-passwords')">Todas las Contrase&ntilde;as</button>
                <button class="sp-tab" onclick="document.getElementById('modal-enlaces').style.display='flex'">Enlaces SSL/Reproducir</button>
            </div>

            <!-- Tab: Cuenta -->
            <div class="sp-tab-content active" id="info-cuenta">
                <div class="sp-info-grid">
                    <div class="sp-info-item">
                        <span class="sp-info-label">Usuario</span>
                        <span class="sp-info-value"><?= htmlspecialchars($_SESSION['user_username'] ?? '') ?></span>
                    </div>
                    <div class="sp-info-item">
                        <span class="sp-info-label">Sistema de Radio</span>
                        <span class="sp-info-value"><?= ucfirst($estacion['server_type'] ?? 'icecast') ?></span>
                    </div>
                    <div class="sp-info-item">
                        <span class="sp-info-label">IP de Radio</span>
                        <span class="sp-info-value"><?= htmlspecialchars(SSL_DOMAIN) ?></span>
                    </div>
                    <div class="sp-info-item">
                        <span class="sp-info-label">Puerto de Radio</span>
                        <span class="sp-info-value"><?= (int)$cpStationPort ?></span>
                    </div>
                    <div class="sp-info-item">
                        <span class="sp-info-label">Punto de Montaje</span>
                        <span class="sp-info-value"><?= in_array($estacion['server_type'] ?? '', ['shoutcast_v1','shoutcast_v2','shoutcast_v25']) ? '/shoutcast/' : '/live/' ?><?= htmlspecialchars($estacion['slug']) ?></span>
                    </div>
                    <div class="sp-info-item">
                        <span class="sp-info-label">Paquete</span>
                        <span class="sp-info-value"><?= htmlspecialchars($estacion['paquete_nombre'] ?? 'Sin paquete') ?></span>
                    </div>
                    <div class="sp-info-item">
                        <span class="sp-info-label">Nombre de Estaci&oacute;n</span>
                        <span class="sp-info-value"><?= htmlspecialchars($estacion['nombre']) ?></span>
                    </div>
                    <div class="sp-info-item">
                        <span class="sp-info-label">Codec</span>
                        <span class="sp-info-value"><?= strtoupper($estacion['codec'] ?? 'MP3') ?></span>
                    </div>
                </div>
            </div>

            <!-- Tab: Límites -->
            <div class="sp-tab-content" id="info-limites">
                <div class="sp-info-grid">
                    <div class="sp-info-item">
                        <span class="sp-info-label">Almacenamiento M&aacute;ximo</span>
                        <span class="sp-info-value"><?= htmlspecialchars($storageCapLabel) ?></span>
                    </div>
                    <div class="sp-info-item">
                        <span class="sp-info-label">Bitrate M&aacute;ximo</span>
                        <span class="sp-info-value"><?= $maxBitrate ?> kbps</span>
                    </div>
                    <div class="sp-info-item">
                        <span class="sp-info-label">Oyentes M&aacute;ximo</span>
                        <span class="sp-info-value"><?= $maxOyentes ?></span>
                    </div>
                    <div class="sp-info-item">
                        <span class="sp-info-label">Cuentas DJ M&aacute;ximo</span>
                        <span class="sp-info-value"><?= $maxDJ ?></span>
                    </div>
                    <div class="sp-info-item">
                        <span class="sp-info-label">AutoDJ</span>
                        <span class="sp-info-value"><?= ($estacion['autodj_enabled'] ?? 1) ? cpIcon('check',14) . ' Habilitado' : cpIcon('x',14) . ' Deshabilitado' ?></span>
                    </div>
                    <div class="sp-info-item">
                        <span class="sp-info-label">Anuncios</span>
                        <span class="sp-info-value"><?= ($estacion['anuncios_enabled'] ?? 1) ? cpIcon('check',14) . ' Habilitado' : cpIcon('x',14) . ' Deshabilitado' ?></span>
                    </div>
                    <div class="sp-info-item">
                        <span class="sp-info-label">SSL</span>
                        <span class="sp-info-value"><?= ($estacion['ssl_enabled'] ?? 1) ? cpIcon('check',14) . ' Habilitado' : cpIcon('x',14) . ' Deshabilitado' ?></span>
                    </div>
                    <div class="sp-info-item">
                        <span class="sp-info-label">Frecuencia de Muestreo</span>
                        <span class="sp-info-value"><?= number_format(($estacion['samplerate'] ?? 44100) / 1000, 1) ?> kHz</span>
                    </div>
                </div>
            </div>

            <!-- Tab: Contraseñas -->
            <div class="sp-tab-content" id="info-passwords">
                <div class="sp-info-grid">
                    <div class="sp-info-item full">
                        <span class="sp-info-label">Servidor / Host</span>
                        <div class="sp-copy-field">
                            <input type="text" value="<?= htmlspecialchars(SSL_DOMAIN) ?>" readonly id="cpHost">
                            <button onclick="cpCopy('cpHost',this)"><?= cpIcon('copy', 14) ?> Copiar</button>
                        </div>
                    </div>
                    <div class="sp-info-item full">
                        <span class="sp-info-label">Puerto de Emisi&oacute;n</span>
                        <div class="sp-copy-field">
                            <?php
                            // v7.4: Puerto correcto según tipo de estación
                            if (!empty($estacion['autodj_enabled']) && in_array($estacion['server_type'] ?? '', ['shoutcast_v1','shoutcast_v2','shoutcast_v25'])) {
                                // Con AutoDJ + SHOUTcast: DJ conecta al harbor (puerto+5)
                                $djPort = $estacion['puerto_stream'] + 5;
                            } else {
                                // Sin AutoDJ: DJ conecta directo al portbase
                                $djPort = $estacion['puerto_stream'];
                            }
                            ?>
                            <input type="text" value="<?= $djPort ?>" readonly id="cpPort">
                            <button onclick="cpCopy('cpPort',this)"><?= cpIcon('copy', 14) ?> Copiar</button>
                        </div>
                    </div>
                    <div class="sp-info-item full">
                        <span class="sp-info-label">Punto de Montaje (Mount)</span>
                        <div class="sp-copy-field">
                            <?php
                            // v7.5: Mount point correcto según tipo de estación
                            if (in_array($estacion['server_type'] ?? '', ['shoutcast_v1','shoutcast_v2','shoutcast_v25'])) {
                                // SHOUTcast (con o sin AutoDJ): no requiere mount point
                                $mountInfo = 'No requiere (protocolo SHOUTcast)';
                            } else {
                                $mountInfo = '/live/' . htmlspecialchars($estacion['slug']);
                            }
                            ?>
                            <input type="text" value="<?= $mountInfo ?>" readonly id="cpMount">
                            <button onclick="cpCopy('cpMount',this)"><?= cpIcon('copy', 14) ?> Copiar</button>
                        </div>
                    </div>
                    <div class="sp-info-item full">
                        <?php if (!empty($estacion['autodj_enabled'])): ?>
                        <span class="sp-info-label">Contrase&ntilde;a DJ</span>
                        <div class="sp-copy-field" style="flex-direction:column;align-items:flex-start;gap:4px">
                            <span style="font-size:13px;color:#666">Use las credenciales de su <a href="<?= BASE_URL ?>/cliente/cuenta_dj.php" style="color:#1976d2">cuenta DJ</a> (usuario y contrase&ntilde;a)</span>
                        </div>
                        <?php else: ?>
                        <span class="sp-info-label">Contrase&ntilde;a Source / DJ</span>
                        <div class="sp-copy-field">
                            <input type="password" value="<?= htmlspecialchars($estacion['icecast_password']) ?>" readonly id="cpPass">
                            <button onclick="let i=document.getElementById('cpPass');i.type=i.type==='password'?'text':'password'"><?= cpIcon('eye', 14) ?> Ver</button>
                            <button onclick="cpCopy('cpPass',this)"><?= cpIcon('copy', 14) ?> Copiar</button>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

        </div>

        <!-- CARD: Control Rápido -->
        <div class="sp-card">
            <div class="sp-card-header">
                <h3><?= cpIcon('zap', 18) ?> Control R&aacute;pido</h3>
            </div>
            <div class="sp-card-tabs">
                <button class="sp-tab <?= $quickPlayerAutodjFirst ? '' : 'active' ?>" onclick="spTab(this,'ctrl-estado')">Radio status</button>
                <?php if (!empty($estacion['autodj_enabled'])): ?>
                <button class="sp-tab <?= $quickPlayerAutodjFirst ? 'active' : '' ?>" onclick="spTab(this,'ctrl-autodj')">Manage AutoDJ</button>
                <?php endif; ?>
                <button class="sp-tab" onclick="spTab(this,'ctrl-historial')">Song history</button>
            </div>

            <!-- Tab: Radio status (listeners + listen only) -->
            <div class="sp-tab-content <?= $quickPlayerAutodjFirst ? '' : 'active' ?>" id="ctrl-estado">
                <p class="cp-tab-hint"><strong>Radio status</strong> — stream online state and listeners. Start/stop the whole station here (not individual songs).</p>
                <div class="cp-control-toolbar">
                    <div class="cp-control-actions">
                        <button type="button" class="btn btn-sm btn-success" onclick="cpQuickStation('iniciar')"><?= cpIcon('play', 13) ?> Iniciar</button>
                        <button type="button" class="btn btn-sm btn-outline" onclick="cpQuickStation('detener')"><?= cpIcon('circle-stop', 13) ?> Detener</button>
                        <button type="button" class="btn btn-sm btn-success" onclick="cpQuickStation('reiniciar')"><?= cpIcon('refresh-cw', 13) ?> Reiniciar</button>
                    </div>
                    <span class="cp-control-status">Estado:
                        <strong class="<?= $streamOnline ? 'is-on' : 'is-off' ?>" id="cpStreamStatusText"><?= $streamOnline ? 'Online' : 'Desconectada' ?></strong>
                    </span>
                </div>

                <!-- Radio status player (the page's one stream) -->
                <div class="cp3-player" id="stPlayerCard">
                    <div class="cp3-art"><div class="cp3-vinyl"><span></span></div></div>
                    <div class="cp3-body">
                        <div class="cp3-top">
                            <span class="cp3-live"><span class="dot"></span> LIVE</span>
                            <span class="cp3-time" id="stAudioTime">00:00</span>
                        </div>
                        <div class="cp3-label">Now playing</div>
                        <div class="cp3-title" id="stNowPlayingBar"><?= htmlspecialchars($cancionActual ?: $npEmptyLabel) ?></div>
                        <span id="stCoverTitle" hidden></span>
                        <input type="range" class="cp3-progress" id="stSeekBar" min="0" max="100" value="0" disabled aria-label="Progress">
                        <div class="cp3-ctrl">
                            <button type="button" class="cp3-play" onclick="stAudioPlay()" title="Reproducir" aria-label="Play">
                                <svg id="stPlayIcon" width="22" height="22" viewBox="0 0 24 24" fill="currentColor"><polygon points="5,3 19,12 5,21"/></svg>
                            </button>
                            <button type="button" class="cp3-stop" onclick="stAudioStop()" title="Detener" aria-label="Stop">
                                <svg width="14" height="14" viewBox="0 0 24 24" fill="currentColor"><rect x="5" y="5" width="14" height="14" rx="2"/></svg>
                            </button>
                            <div class="cp3-eq" aria-hidden="true"><span></span><span></span><span></span><span></span><span></span><span></span><span></span></div>
                            <div class="cp3-vol">
                                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polygon points="11 5 6 9 2 9 2 15 6 15 11 19"/><path d="M15.5 8.5a5 5 0 0 1 0 7"/></svg>
                                <input type="range" id="stVolume" min="0" max="100" value="80" oninput="stSetVolume(this.value)" aria-label="Volume">
                            </div>
                        </div>
                    </div>
                </div>
                <style>
                .cp3-player{display:flex;gap:18px;align-items:center;padding:18px;border-radius:18px;color:#fff;
                  background:linear-gradient(135deg,var(--primary) 0%,#0f5f66 60%,#0b3d6e 100%);box-shadow:0 12px 30px rgba(15,40,80,.22);position:relative;overflow:hidden}
                .cp3-player::before{content:'';position:absolute;width:220px;height:220px;right:-70px;top:-90px;border-radius:50%;background:rgba(255,255,255,.14);filter:blur(30px);pointer-events:none}
                .cp3-art{flex-shrink:0;position:relative;z-index:1}
                .cp3-vinyl{width:96px;height:96px;border-radius:50%;background:radial-gradient(circle,#fff 0 9%,var(--primary) 9.5% 22%,#111 22.5% 24%,transparent 24.5%),repeating-radial-gradient(circle,#111 0 2px,#222 2px 4px);box-shadow:0 8px 20px rgba(0,0,0,.4)}
                .cp3-player.is-playing .cp3-vinyl{animation:cp3spin 4s linear infinite}
                @keyframes cp3spin{to{transform:rotate(360deg)}}
                .cp3-body{flex:1;min-width:0;position:relative;z-index:1}
                .cp3-top{display:flex;justify-content:space-between;align-items:center}
                .cp3-live{display:inline-flex;align-items:center;gap:6px;font-size:10.5px;font-weight:800;letter-spacing:1px;padding:3px 9px;border-radius:20px;background:rgba(255,255,255,.15)}
                .cp3-live .dot{width:7px;height:7px;border-radius:50%;background:#cbd5e1}
                .cp3-player.is-playing .cp3-live .dot{background:#ef4444;animation:cp3pulse 1.6s infinite}
                @keyframes cp3pulse{0%{box-shadow:0 0 0 0 rgba(239,68,68,.7)}70%{box-shadow:0 0 0 7px rgba(239,68,68,0)}100%{box-shadow:0 0 0 0 rgba(239,68,68,0)}}
                .cp3-time{font-size:12px;opacity:.85;font-variant-numeric:tabular-nums}
                .cp3-label{margin-top:8px;font-size:10.5px;text-transform:uppercase;letter-spacing:1px;opacity:.7;font-weight:700}
                .cp3-title{font-size:16px;font-weight:800;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
                .cp3-progress{width:100%;margin:10px 0 8px;-webkit-appearance:none;appearance:none;height:4px;border-radius:4px;background:rgba(255,255,255,.25);accent-color:#fff;pointer-events:none}
                .cp3-progress::-webkit-slider-thumb{-webkit-appearance:none;width:10px;height:10px;border-radius:50%;background:#fff}
                .cp3-ctrl{display:flex;align-items:center;gap:10px}
                .cp3-play{width:48px;height:48px;border-radius:50%;border:none;background:#fff;color:var(--primary);display:flex;align-items:center;justify-content:center;cursor:pointer;box-shadow:0 6px 16px rgba(0,0,0,.25);transition:transform .2s}
                .cp3-play:hover{transform:scale(1.07)}
                .cp3-stop{width:34px;height:34px;border-radius:50%;border:1px solid rgba(255,255,255,.35);background:rgba(255,255,255,.1);color:#fff;display:flex;align-items:center;justify-content:center;cursor:pointer}
                .cp3-stop:hover{background:rgba(255,255,255,.22)}
                .cp3-eq{display:flex;align-items:flex-end;gap:3px;height:22px;margin-left:4px}
                .cp3-eq span{width:3px;height:20%;border-radius:2px;background:#fff;opacity:.55}
                .cp3-player.is-playing .cp3-eq span{opacity:1;animation:cp3eq 1s ease-in-out infinite}
                .cp3-player.is-playing .cp3-eq span:nth-child(2n){animation-duration:.75s}.cp3-player.is-playing .cp3-eq span:nth-child(3n){animation-duration:1.2s}
                @keyframes cp3eq{0%,100%{height:20%}50%{height:100%}}
                .cp3-vol{margin-left:auto;display:flex;align-items:center;gap:6px;opacity:.9}
                .cp3-vol input{width:90px;accent-color:#fff}
                @media(max-width:560px){.cp3-player{flex-direction:column;text-align:center}.cp3-top,.cp3-ctrl{justify-content:center}.cp3-vol{margin-left:0}}
                </style>

                <audio id="stAudioPlayer" preload="none" playsinline></audio>
                <p id="cpAudioErr" class="cp-audio-err"></p>
            </div>

            <!-- Tab: Administrar AutoDJ (Estilo SonicPanel) -->
            <?php if (!empty($estacion['autodj_enabled'])): ?>
            <div class="sp-tab-content <?= $quickPlayerAutodjFirst ? 'active' : '' ?>" id="ctrl-autodj">
                <p class="cp-tab-hint"><strong>Manage AutoDJ</strong> — start/stop the robot and see now playing. Playlist runs automatically. Use <strong>Radio status</strong> for listeners only.</p>
                <div class="cp-control-toolbar" style="flex-wrap:wrap;gap:8px">
                    <div class="cp-control-actions" style="display:flex;flex-wrap:wrap;gap:6px;align-items:center">
                        <button type="button" class="btn btn-sm btn-success" id="cpBtnAutodjStart" onclick="cpQuickAutodj('iniciar')"><?= cpIcon('play', 13) ?> Start AutoDJ</button>
                        <button type="button" class="btn btn-sm btn-outline" id="cpBtnAutodjStop" onclick="cpQuickAutodj('detener')"><?= cpIcon('circle-stop', 13) ?> Stop</button>
                        <button type="button" class="btn btn-sm btn-primary" id="cpBtnAutodjRestart" onclick="cpQuickAutodj('reiniciar')"><?= cpIcon('refresh-cw', 13) ?> Restart</button>
                        <a href="<?= BASE_URL ?>/cliente/autodj.php?tab=config" class="btn btn-sm btn-outline"><?= cpIcon('gear', 13) ?> Edit details</a>
                    </div>
                    <span class="cp-control-status">AutoDJ:
                        <strong class="<?= $autodjOnline ? 'is-on' : 'is-off' ?>" id="cpAutodjStatusText"><?= $autodjOnline ? 'Online' : 'Offline' ?></strong>
                        <span id="cpQuickBusy" style="display:none;font-size:11px;margin-left:8px;color:var(--text-secondary)">Working…</span>
                    </span>
                </div>

                <div class="cp-quick-playlist" style="margin:12px 0 4px;padding:12px 14px;background:var(--hover-bg,#f8f9fc);border-radius:8px;border:1px solid var(--border,#e5e7eb)">
                    <label style="font-size:12px;font-weight:600;display:block;margin-bottom:6px">On-air playlist</label>
                    <div style="display:flex;flex-wrap:wrap;gap:8px;align-items:center">
                        <select id="cpQuickPlaylist" class="form-control" style="max-width:280px;min-width:160px">
                            <?php foreach ($dashboardPlaylists as $pl):
                                $cnt = $dashboardPlaylistCounts[(int)$pl['id']] ?? 0;
                                $sel = (int)($estacion['autodj_playlist_activa'] ?? 0) === (int)$pl['id'];
                            ?>
                            <option value="<?= (int)$pl['id'] ?>" <?= $sel ? 'selected' : '' ?>>
                                <?= htmlspecialchars($pl['nombre']) ?> (<?= $cnt ?>)
                            </option>
                            <?php endforeach; ?>
                        </select>
                        <button type="button" class="btn btn-sm btn-primary" onclick="cpQuickSetPlaylist()"><?= cpIcon('check', 13) ?> Play this list</button>
                        <span id="cpQuickPlaylistMsg" style="font-size:12px;color:var(--text-secondary)"></span>
                    </div>
                    <p style="font-size:11px;color:var(--text-secondary);margin:8px 0 0">Switch folder here — only tracks in the selected list play on air.</p>
                </div>

                <!-- Now playing (display only). Listening is done by the single
                     player in the "Radio status" tab, so switching tabs never
                     stops playback or opens a second stream. -->
                <div class="cp-embed-player">
                    <div class="cp-embed-player-main">
                        <div class="cp-embed-cover">
                            <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><circle cx="12" cy="12" r="3"/></svg>
                            <span id="cpCoverTitle"><?= htmlspecialchars(mb_substr($cancionActual, 0, 30)) ?: $npEmptyLabel ?></span>
                        </div>
                        <div class="cp-embed-controls">
                            <div class="cp-embed-row">
                                <button type="button" class="cp-embed-btn" id="cpListenBtn" onclick="cpListenFromAutodj()" title="Listen (Radio status player)">
                                    <svg id="cpPlayIcon" width="16" height="16" viewBox="0 0 24 24" fill="#555"><polygon points="5,3 19,12 5,21"/></svg>
                                </button>
                                <input type="range" class="cp-embed-seek" id="cpSeekBar" min="0" max="100" value="0" readonly tabindex="-1" style="pointer-events:none">
                                <span class="cp-embed-time" style="min-width:72px" id="cpAudioTime">00:00 / 00:00</span>
                            </div>
                            <div class="cp-embed-row" style="font-size:12px;color:var(--text-secondary)">
                                <span id="cpListenState">Not listening</span>
                            </div>
                        </div>
                    </div>
                    <div class="cp-embed-np">
                        <span><?= cpIcon('music', 14) ?> <span id="cpNowPlayingBar"><?= $npBarPrefix ?><?= htmlspecialchars($cancionActual ?: $npEmptyLabel) ?></span></span>
                        <span id="cpPlaylistPos" style="font-size:11px;opacity:.9;font-weight:500"></span>
                    </div>
                </div>
                <p class="cp-embed-footnote">▶ plays/pauses the <strong>Radio status</strong> player — one stream for the whole page.</p>
            </div>
            <?php endif; /* autodj_enabled */ ?>

            <!-- Tab: Song history -->
            <div class="sp-tab-content" id="ctrl-historial">
                <p class="cp-tab-hint">Recent songs played on your station (updates automatically).</p>
                <div class="sp-history-list" id="ctrl-historial-list">
                    <?php if (empty($logEmision)): ?>
                        <div class="sp-empty" id="ctrl-historial-empty">No songs logged yet — start AutoDJ and play music.</div>
                    <?php else: ?>
                        <?php foreach ($logEmision as $log): ?>
                            <div class="sp-history-item">
                                <span class="sp-hist-time"><?= date('H:i:s', strtotime($log['emitido_at'])) ?></span>
                                <span class="sp-hist-badge song"><?= cpIcon('music', 14) ?></span>
                                <span class="sp-hist-title"><?= htmlspecialchars($log['titulo'] ?: 'Unknown') ?></span>
                                <?php if ($log['estado'] === 'al_aire'): ?>
                                    <span class="sp-hist-live"><?= cpIcon('circle-dot', 10) ?> Live</span>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- ROW 2: Gráfico Oyentes + Resumen Cuenta -->
    <div class="sp-row">

        <!-- CARD: Quick Actions (extra) -->
        <div class="sp-card">
            <div class="sp-card-header">
                <h3><?= cpIcon('chart', 18) ?> Oyentes de Radio Gr&aacute;fico (En Vivo)</h3>
            </div>
            <div class="sp-card-body" style="padding:20px">
                <!-- Simple stats bar chart -->
                <div class="sp-chart-container">
                    <div class="sp-chart-label">Oyentes actuales</div>
                    <div class="sp-chart-bars" id="spChartBars">
                        <?php
                        // Generate last 12 hours of placeholder data
                        // In production, this would come from estadisticas_oyentes
                        $horasLabels = [];
                        $horasData = [];
                        for ($i = 11; $i >= 0; $i--) {
                            $h = date('H:i', strtotime("-$i hours"));
                            $horasLabels[] = $h;
                            $horasData[] = 0;
                        }
                        // Try to get real data
                        try {
                            $stmt = $db->prepare("SELECT HOUR(registrado_at) as hora, MAX(oyentes) as max_oy FROM estadisticas_oyentes WHERE estacion_id = ? AND registrado_at >= DATE_SUB(NOW(), INTERVAL 12 HOUR) GROUP BY HOUR(registrado_at) ORDER BY hora");
                            $stmt->execute([$estacion['id']]);
                            $statsData = $stmt->fetchAll();
                            foreach ($statsData as $sd) {
                                $idx = 11 - ((int)date('H') - $sd['hora'] + 24) % 24;
                                if ($idx >= 0 && $idx < 12) {
                                    $horasData[$idx] = $sd['max_oy'];
                                }
                            }
                        } catch (PDOException $e) {}
                        // Set current hour to live value
                        $horasData[11] = $oyentesAhora;
                        $maxBar = max(1, max($horasData));
                        ?>
                        <?php foreach ($horasLabels as $idx => $label): ?>
                            <div class="sp-bar-col">
                                <div class="sp-bar" style="height:<?= round(($horasData[$idx] / $maxBar) * 100) ?>%"
                                     title="<?= $horasData[$idx] ?> oyentes">
                                    <?php if ($horasData[$idx] > 0): ?>
                                        <span class="sp-bar-val"><?= $horasData[$idx] ?></span>
                                    <?php endif; ?>
                                </div>
                                <span class="sp-bar-label"><?= $label ?></span>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>

                <!-- Quick Actions -->
                <div class="sp-quick-actions">
                    <a href="<?= BASE_URL ?>/cliente/estadisticas.php" class="sp-qa"><?= cpIcon('chart', 14) ?> Estad&iacute;sticas completas</a>
                    <a href="<?= BASE_URL ?>/cliente/widgets.php" class="sp-qa"><?= cpIcon('link', 14) ?> Widgets &amp; Players</a>
                    <?php if (!empty($estacion['anuncios_enabled'])): ?>
                    <a href="<?= BASE_URL ?>/cliente/anuncios.php" class="sp-qa"><?= cpIcon('megaphone', 14) ?> Anuncios</a>
                    <?php endif; ?>
                    <a href="<?= BASE_URL ?>/cliente/cuenta_dj.php" class="sp-qa"><?= cpIcon('microphone', 14) ?> Cuentas DJ</a>
                </div>
            </div>
        </div>

        <!-- CARD: Resumen de Cuenta -->
        <div class="sp-card">
            <div class="sp-card-header">
                <h3><?= cpIcon('note', 18) ?> Resumen de Cuenta (En Vivo)</h3>
            </div>
            <div class="sp-card-body" style="padding:20px">
                <div class="sp-gauges">
                    <!-- Oyentes -->
                    <?php
                    $gauges = [
                        ['label' => 'Oyentes', 'value' => $oyentesAhora, 'max' => $maxOyentes, 'pct' => $oyentesPercent, 'color' => '#00897b', 'unit' => ''],
                        ['label' => 'Uso de Disco', 'value' => $storageUsedMB, 'max' => $maxStorageMB, 'pct' => $storagePercent, 'pctText' => $maxStorageMB > 0 ? null : '∞', 'color' => '#1e88e5', 'unit' => ' MB'],
                        ['label' => 'Pico 24h', 'value' => $picoOyentes, 'max' => $maxOyentes, 'pct' => $maxOyentes > 0 ? min(round(($picoOyentes / $maxOyentes) * 100, 1), 100) : 0, 'color' => '#f4511e', 'unit' => ''],
                        ['label' => 'Bitrate', 'value' => $estacion['bitrate'], 'max' => $maxBitrate, 'pct' => $bitratePercent, 'color' => '#8e24aa', 'unit' => ' kbps'],
                        ['label' => 'Canciones', 'value' => $totalCanciones, 'max' => 999, 'pct' => min(round(($totalCanciones / max(1, 999)) * 100, 1), 100), 'color' => '#fb8c00', 'unit' => ''],
                        ['label' => 'Cuentas DJ', 'value' => $totalDJ, 'max' => $maxDJ, 'pct' => $maxDJ > 0 ? min(round(($totalDJ / $maxDJ) * 100, 1), 100) : 0, 'color' => '#43a047', 'unit' => ''],
                    ];
                    foreach ($gauges as $g):
                        $circumference = 2 * M_PI * 36;
                        $offset = $circumference * (1 - $g['pct'] / 100);
                    ?>
                    <div class="sp-gauge">
                        <svg viewBox="0 0 100 100" class="sp-gauge-svg">
                            <circle cx="50" cy="50" r="36" fill="none" stroke="#e8eaed" stroke-width="7"/>
                            <circle cx="50" cy="50" r="36" fill="none" stroke="<?= $g['color'] ?>" stroke-width="7"
                                    stroke-dasharray="<?= round($circumference, 2) ?>"
                                    stroke-dashoffset="<?= round($offset, 2) ?>"
                                    stroke-linecap="round" transform="rotate(-90 50 50)"
                                    class="sp-gauge-fill"/>
                            <text x="50" y="45" text-anchor="middle" font-size="15" font-weight="700" fill="#333"><?= $g['value'] ?></text>
                            <text x="50" y="60" text-anchor="middle" font-size="7" fill="#999"><?= $g['pctText'] ?? (round($g['pct']) . '%') ?></text>
                        </svg>
                        <div class="sp-gauge-label"><?= $g['label'] ?></div>
                        <div class="sp-gauge-detail"><?= $g['value'] . $g['unit'] ?> / <?= $g['max'] . $g['unit'] ?></div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- MODAL: Enlaces SSL/Reproducir (estilo SonicPanel) -->
<div id="modal-enlaces" style="display:none;position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,0.45);z-index:9999;align-items:center;justify-content:center" onclick="if(event.target===this)this.style.display='none'">
    <div style="background:#fff;border-radius:10px;max-width:680px;width:94%;box-shadow:0 8px 40px rgba(0,0,0,0.25);position:relative;max-height:90vh;overflow-y:auto">
        <!-- Header -->
        <div style="display:flex;align-items:center;justify-content:space-between;padding:18px 24px;border-bottom:1px solid #e8e8e8">
            <h3 style="margin:0;font-size:17px;font-weight:700;color:#333;display:flex;align-items:center;gap:8px"><?= cpIcon('lock', 18) ?> Enlaces SSL/Reproducir</h3>
            <button onclick="document.getElementById('modal-enlaces').style.display='none'" style="background:none;border:none;font-size:24px;cursor:pointer;color:#999;line-height:1;padding:0 4px">&times;</button>
        </div>
        <div style="padding:20px 24px">

            <!-- Sección 1: URL SSL directa -->
            <h4 style="font-size:14px;font-weight:700;color:#333;margin:0 0 10px">URL de Reproducci&oacute;n SSL Directa</h4>
            <div style="background:#e3f2fd;border-radius:8px;padding:12px 16px;display:flex;gap:10px;align-items:flex-start;margin-bottom:12px">
                <span style="color:#1565c0;flex-shrink:0;margin-top:2px"><?= cpIcon('info', 16) ?></span>
                <p style="margin:0;font-size:12px;color:#1565c0;line-height:1.5">URL SSL directa para reproductores HTML5, apps m&oacute;viles, plugins WordPress y players personalizados. Funciona con HTTPS en el puerto de la estaci&oacute;n.</p>
            </div>
            <div style="display:flex;align-items:center;gap:8px;background:#f8f9fa;border:1px solid #e0e0e0;border-radius:8px;padding:10px 14px;margin-bottom:22px">
                <span style="color:#00897b;flex-shrink:0"><?= cpIcon('circle-dot', 14) ?></span>
                <input type="text" value="<?= htmlspecialchars($cpSecureUrl) ?>" readonly id="cpStream" style="flex:1;border:none;background:transparent;font-size:13px;color:#1565c0;font-weight:600;outline:none;font-family:monospace">
                <button onclick="cpCopy('cpStream',this)" style="background:#00897b;color:#fff;border:none;padding:6px 14px;border-radius:6px;cursor:pointer;font-size:12px;font-weight:600;white-space:nowrap;display:flex;align-items:center;gap:4px"><?= cpIcon('copy', 12) ?> Copiar</button>
            </div>

            <!-- Sección 2: URL HTTP sin SSL -->
            <h4 style="font-size:14px;font-weight:700;color:#333;margin:0 0 10px">URL HTTP Directa (Sin SSL)</h4>
            <div style="background:#e3f2fd;border-radius:8px;padding:12px 16px;display:flex;gap:10px;align-items:flex-start;margin-bottom:12px">
                <span style="color:#1565c0;flex-shrink:0;margin-top:2px"><?= cpIcon('info', 16) ?></span>
                <p style="margin:0;font-size:12px;color:#1565c0;line-height:1.5">URL directa sin SSL para software de radio como SAM Broadcaster, BUTT, Mixxx, RadioBOSS y otros encoders. Use esta URL con el puerto indicado para transmisi&oacute;n directa.</p>
            </div>
            <div style="display:flex;align-items:center;gap:8px;background:#f8f9fa;border:1px solid #e0e0e0;border-radius:8px;padding:10px 14px;margin-bottom:22px">
                <span style="color:#1565c0;flex-shrink:0"><?= cpIcon('circle-dot', 14) ?></span>
                <input type="text" value="<?= htmlspecialchars($cpDirectUrl) ?>" readonly id="cpHttp" style="flex:1;border:none;background:transparent;font-size:13px;color:#1565c0;font-weight:600;outline:none;font-family:monospace">
                <button onclick="cpCopy('cpHttp',this)" style="background:#00897b;color:#fff;border:none;padding:6px 14px;border-radius:6px;cursor:pointer;font-size:12px;font-weight:600;white-space:nowrap;display:flex;align-items:center;gap:4px"><?= cpIcon('copy', 12) ?> Copiar</button>
            </div>

            <!-- Sección 3: Reproductor Público -->
            <h4 style="font-size:14px;font-weight:700;color:#333;margin:0 0 10px">Reproductor P&uacute;blico</h4>
            <div style="background:#e3f2fd;border-radius:8px;padding:12px 16px;display:flex;gap:10px;align-items:flex-start;margin-bottom:12px">
                <span style="color:#1565c0;flex-shrink:0;margin-top:2px"><?= cpIcon('info', 16) ?></span>
                <p style="margin:0;font-size:12px;color:#1565c0;line-height:1.5">Comparta este enlace con sus oyentes. Es la p&aacute;gina del reproductor p&uacute;blico con car&aacute;tulas, historial de canciones y efectos visuales personalizables.</p>
            </div>
            <div style="display:flex;align-items:center;gap:8px;background:#f8f9fa;border:1px solid #e0e0e0;border-radius:8px;padding:10px 14px;margin-bottom:22px">
                <span style="color:#8e24aa;flex-shrink:0"><?= cpIcon('circle-dot', 14) ?></span>
                <input type="text" value="<?= htmlspecialchars($cpWidgetUrl . '&type=' . $playerEffect) ?>" readonly id="cpPlayer" style="flex:1;border:none;background:transparent;font-size:13px;color:#1565c0;font-weight:600;outline:none;font-family:monospace">
                <button onclick="cpCopy('cpPlayer',this)" style="background:#00897b;color:#fff;border:none;padding:6px 14px;border-radius:6px;cursor:pointer;font-size:12px;font-weight:600;white-space:nowrap;display:flex;align-items:center;gap:4px"><?= cpIcon('copy', 12) ?> Copiar</button>
            </div>

            <!-- Sección 4: API JSON -->
            <h4 style="font-size:14px;font-weight:700;color:#333;margin:0 0 10px">Enlace API JSON</h4>
            <div style="background:#e3f2fd;border-radius:8px;padding:12px 16px;display:flex;gap:10px;align-items:flex-start;margin-bottom:12px">
                <span style="color:#1565c0;flex-shrink:0;margin-top:2px"><?= cpIcon('info', 16) ?></span>
                <p style="margin:0;font-size:12px;color:#1565c0;line-height:1.5">Endpoint JSON para desarrolladores. Retorna metadatos en vivo: canci&oacute;n actual, oyentes, estado del servidor. &Uacute;til para integraciones y apps personalizadas.</p>
            </div>
            <div style="display:flex;align-items:center;gap:8px;background:#f8f9fa;border:1px solid #e0e0e0;border-radius:8px;padding:10px 14px">
                <span style="color:#fb8c00;flex-shrink:0"><?= cpIcon('circle-dot', 14) ?></span>
                <input type="text" value="<?= BASE_URL ?>/api/stats.php?estacion=<?= $estacion['id'] ?>" readonly id="cpApi" style="flex:1;border:none;background:transparent;font-size:13px;color:#1565c0;font-weight:600;outline:none;font-family:monospace">
                <button onclick="cpCopy('cpApi',this)" style="background:#00897b;color:#fff;border:none;padding:6px 14px;border-radius:6px;cursor:pointer;font-size:12px;font-weight:600;white-space:nowrap;display:flex;align-items:center;gap:4px"><?= cpIcon('copy', 12) ?> Copiar</button>
            </div>

        </div>
    </div>
</div>

<script>
// Tab switching
function spTab(btn, panelId) {
    var card = btn.closest('.sp-card');
    card.querySelectorAll('.sp-tab').forEach(function(t) { t.classList.remove('active'); });
    card.querySelectorAll('.sp-tab-content').forEach(function(p) { p.classList.remove('active'); });
    btn.classList.add('active');
    document.getElementById(panelId).classList.add('active');
    if (panelId === 'ctrl-historial') cpRefreshHistory();
}

// Copy to clipboard (app.js castpanelCopyText when available)
function cpCopy(inputId, btn) {
    var el = document.getElementById(inputId);
    if (!el) return;
    var val = el.value !== undefined ? el.value : (el.textContent || '');
    if (typeof castpanelCopyText === 'function') {
        castpanelCopyText(val, btn);
        return;
    }
    if (el.type === 'password') {
        var tmp = document.createElement('input');
        tmp.value = val;
        document.body.appendChild(tmp);
        tmp.select();
        document.execCommand('copy');
        document.body.removeChild(tmp);
    } else {
        el.select();
        document.execCommand('copy');
    }
    var orig = btn.innerHTML;
    btn.innerHTML = '&#x2705; Copiado';
    setTimeout(function() { btn.innerHTML = orig; }, 1500);
}

// Single stream player. It lives in the "Radio status" tab; the AutoDJ tab
// only shows now-playing info and its ▶ button controls this same player.
var stAudioEl = document.getElementById('stAudioPlayer');
var stIsPlaying = false;

var PLAY_SVG = '<polygon points="5,3 19,12 5,21"/>';
var PAUSE_SVG = '<polygon points="6,4 10,4 10,20 6,20"/><polygon points="14,4 18,4 18,20 14,20"/>';

function cpSyncPlayButtons() {
    ['stPlayIcon', 'cpPlayIcon'].forEach(function(id) {
        var el = document.getElementById(id);
        if (el) el.innerHTML = stIsPlaying ? PAUSE_SVG : PLAY_SVG;
    });
    var st = document.getElementById('cpListenState');
    if (st) st.textContent = stIsPlaying ? '● Listening (Radio status player)' : 'Not listening';
    var card = document.getElementById('stPlayerCard');
    if (card) card.classList.toggle('is-playing', stIsPlaying);
}

function cpSetStreamSrc(el, bustCache) {
    if (!el || !cpStreamUrl) return;
    var url = cpStreamUrl;
    if (bustCache) {
        url += (url.indexOf('?') >= 0 ? '&' : '?') + '_=' + Date.now();
    }
    el.src = url;
    el.load();
}

var cpAudioRestartHint = ' If you cannot hear audio, click Restart AutoDJ, wait a few seconds, then press Play again.';

function cpShowAudioError(msg) {
    var err = document.getElementById('cpAudioErr');
    if (err) {
        err.style.display = msg ? 'block' : 'none';
        err.textContent = msg || '';
    }
}

function cpTryPlayAudio(el, onFail) {
    if (!el) return;
    el.volume = (document.getElementById('stVolume')?.value || 80) / 100;
    el.play().catch(function() {
        cpSetStreamSrc(el, true);
        el.play().catch(onFail);
    });
}

function stAudioPlay() {
    if (!stAudioEl) return;
    if (stIsPlaying && !stAudioEl.paused) {
        stAudioEl.pause();
        stIsPlaying = false;
        cpSyncPlayButtons();
        return;
    }
    cpShowAudioError('');
    // A live stream that was paused is behind real time: reconnect
    cpSetStreamSrc(stAudioEl, true);
    cpTryPlayAudio(stAudioEl, function() {
        stIsPlaying = false;
        cpSyncPlayButtons();
        cpShowAudioError('Could not start playback — click Play again or open the stream link below.' + cpAudioRestartHint);
    });
    stIsPlaying = true;
    cpSyncPlayButtons();
}

function stAudioStop() {
    if (!stAudioEl) return;
    stAudioEl.pause();
    stAudioEl.removeAttribute('src');
    stAudioEl.load();
    stIsPlaying = false;
    cpSyncPlayButtons();
}

function stSetVolume(val) {
    if (stAudioEl) stAudioEl.volume = val / 100;
}

// After a start/restart the stream source changes: reconnect only if the
// listener was already playing (never start audio on its own).
function cpReconnectIfPlaying() {
    if (!stIsPlaying || !stAudioEl) return;
    setTimeout(function() {
        cpSetStreamSrc(stAudioEl, true);
        cpTryPlayAudio(stAudioEl, function() {});
    }, 1500);
}

// AutoDJ tab ▶: same player, no second stream
function cpListenFromAutodj() { stAudioPlay(); }
// Old names kept for any markup that still calls them
function cpAudioPlay() { stAudioPlay(); }
function cpAudioStop() { stAudioStop(); }
function cpSetVolume(v) { stSetVolume(v); }

var cpStreamUrl = <?= json_encode($streamUrl) ?>;
var cpQuickApi = <?= json_encode((BASE_URL ?: '') . '/cliente/quick_control_api.php') ?>;
var cpCsrfToken = <?= json_encode(csrfToken()) ?>;
var cpPlayerApi = <?= json_encode((BASE_URL ?: '') . '/api/autodj_player.php?estacion=' . (int)$estacion['id']) ?>;

function cpQuickPost(action, extra, onDone) {
    var busy = document.getElementById('cpQuickBusy');
    if (busy) busy.style.display = 'inline';
    var body = new URLSearchParams(Object.assign({ action: action, csrf_token: cpCsrfToken, ajax: '1' }, extra || {}));
    fetch(cpQuickApi, { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: body })
        .then(function(r) { return r.json(); })
        .then(function(data) {
            if (busy) busy.style.display = 'none';
            if (typeof onDone === 'function') onDone(data);
        })
        .catch(function() {
            if (busy) busy.style.display = 'none';
            alert('Network error — try again.');
        });
}

function cpQuickAutodj(cmd) {
    ['cpBtnAutodjStart', 'cpBtnAutodjStop', 'cpBtnAutodjRestart'].forEach(function(id) {
        var b = document.getElementById(id);
        if (b) b.disabled = true;
    });
    cpQuickPost('autodj_control', { cmd: cmd }, function(data) {
        ['cpBtnAutodjStart', 'cpBtnAutodjStop', 'cpBtnAutodjRestart'].forEach(function(id) {
            var b = document.getElementById(id);
            if (b) b.disabled = false;
        });
        if (!data.success) {
            alert(data.error || 'AutoDJ command failed');
            return;
        }
        var st = document.getElementById('cpAutodjStatusText');
        if (st) {
            var on = !!data.autodj_online;
            st.textContent = on ? 'Online' : 'Offline';
            st.classList.toggle('is-on', on);
            st.classList.toggle('is-off', !on);
        }
        if (cmd === 'reiniciar' || cmd === 'iniciar') {
            cpReconnectIfPlaying();
        }
        setTimeout(cpRefreshNowPlaying, 800);
    });
}

function cpQuickStation(cmd) {
    cpQuickPost('station_control', { cmd: cmd }, function(data) {
        if (!data.success) {
            alert(data.error || 'Station command failed');
            return;
        }
        var st = document.getElementById('cpStreamStatusText');
        if (st && typeof data.stream_online !== 'undefined') {
            var on = !!data.stream_online;
            st.textContent = on ? 'Online' : 'Desconectada';
            st.classList.toggle('is-on', on);
            st.classList.toggle('is-off', !on);
        }
        if (cmd === 'reiniciar' || cmd === 'iniciar') {
            cpReconnectIfPlaying();
        } else if (cmd === 'detener') {
            stAudioStop();
        }
        setTimeout(cpRefreshNowPlaying, 800);
    });
}

function cpQuickSetPlaylist() {
    var sel = document.getElementById('cpQuickPlaylist');
    var msg = document.getElementById('cpQuickPlaylistMsg');
    if (!sel) return;
    if (msg) msg.textContent = 'Applying…';
    cpQuickPost('set_active_playlist', { playlist_id: sel.value }, function(data) {
        if (!data.success) {
            if (msg) msg.textContent = '';
            alert(data.error || 'Could not switch playlist');
            return;
        }
        var n = data.tracks_on_air || 0;
        if (msg) msg.textContent = (data.playlist_name || 'Playlist') + ' on air (' + n + ' track' + (n === 1 ? '' : 's') + ')';
        setTimeout(cpRefreshNowPlaying, 600);
    });
}
var cpNpPrefix = <?= json_encode($npBarPrefix) ?>;
var cpNpEmpty = <?= json_encode($npEmptyLabel) ?>;
var cpLastTitle = '';
var cpPlayerState = { elapsed: 0, duration: 0, progress: 0, title: '' };

function cpFormatTime(sec) {
    sec = Math.max(0, Math.floor(sec || 0));
    var m = Math.floor(sec / 60);
    var s = sec % 60;
    return (m < 10 ? '0' : '') + m + ':' + (s < 10 ? '0' : '') + s;
}

function cpApplyPlayer(p) {
    if (!p) return;
    var title = p.title || cpNpEmpty;
    if (!title && p.online) title = 'Streaming…';
    var short = title.length > 30 ? title.substring(0, 30) + '…' : title;
    cpLastTitle = title;
    cpPlayerState = {
        elapsed: p.elapsed || 0,
        duration: Math.max(1, p.duration || 0),
        progress: p.progress || 0,
        title: title
    };
    var heroT = document.getElementById('cp2NpTitle');
    if (heroT) heroT.textContent = title;
    if (typeof p.online !== 'undefined') {
        var hero = document.getElementById('cp2Hero'), pill = document.getElementById('cp2Pill');
        if (hero) hero.classList.toggle('is-on', !!p.online);
        if (pill) { pill.classList.toggle('on', !!p.online); document.getElementById('cp2PillText').textContent = p.online ? 'On air' : 'Offline'; }
    }
    ['stCoverTitle', 'cpCoverTitle'].forEach(function(id) {
        var el = document.getElementById(id);
        if (el) el.textContent = short;
    });
    var barTitle = cpNpPrefix + title;
    var stBar = document.getElementById('stNowPlayingBar');
    var cpBar = document.getElementById('cpNowPlayingBar');
    if (stBar) stBar.textContent = title; // "Now playing" label is above the title
    if (cpBar) cpBar.textContent = barTitle;
    var pos = document.getElementById('cpPlaylistPos');
    if (pos && p.playlist_total > 0 && typeof p.playlist_index === 'number' && p.playlist_index >= 0) {
        pos.textContent = 'Track ' + (p.playlist_index + 1) + ' of ' + p.playlist_total;
    } else if (pos && p.playlist_total > 0) {
        pos.textContent = p.playlist_total + ' tracks in playlist';
    } else if (pos) {
        pos.textContent = '';
    }
    cpUpdateProgressUI();
}

function cpUpdateProgressUI() {
    var elBar = Math.min(100, Math.max(0, cpPlayerState.progress || 0));
    ['stSeekBar', 'cpSeekBar'].forEach(function(id) {
        var el = document.getElementById(id);
        if (el) el.value = elBar;
    });
    var timeStr = cpFormatTime(cpPlayerState.elapsed) + ' / ' + cpFormatTime(cpPlayerState.duration);
    var stTime = document.getElementById('stAudioTime');
    var cpTime = document.getElementById('cpAudioTime');
    if (stTime) stTime.textContent = timeStr;
    if (cpTime) cpTime.textContent = timeStr;
}

function cpRefreshNowPlaying() {
    fetch(cpPlayerApi + '&action=status', { credentials: 'same-origin' })
        .then(function(r) { return r.json(); })
        .then(function(data) {
            if (data.success && data.player) cpApplyPlayer(data.player);
        })
        .catch(function() {});
}

function cpEsc(t) {
    return String(t).replace(/[&<>"']/g, function(c) {
        return {'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'}[c];
    });
}

function cpRefreshHistory() {
    var list = document.getElementById('ctrl-historial-list');
    if (!list) return;
    fetch(cpPlayerApi + '&action=history', { credentials: 'same-origin' })
        .then(function(r) { return r.json(); })
        .then(function(data) {
            if (!data.success || !data.history) return;
            if (!data.history.length) {
                list.innerHTML = '<div class="sp-empty">No songs logged yet — start AutoDJ and play music.</div>';
                return;
            }
            list.innerHTML = data.history.map(function(h) {
                var t = (h.emitido_at || '').substr(11, 8) || '';
                var live = h.estado === 'al_aire' ? ' <span class="sp-hist-live">● Live</span>' : '';
                return '<div class="sp-history-item"><span class="sp-hist-time">' + t + '</span>' +
                    '<span class="sp-hist-title">' + cpEsc(h.titulo || 'Unknown') + '</span>' + live + '</div>';
            }).join('');
        })
        .catch(function() {});
}

if (stAudioEl) {
    stAudioEl.addEventListener('error', function() {
        if (!stAudioEl.getAttribute('src')) return; // stopped on purpose
        stIsPlaying = false;
        cpSyncPlayButtons();
        cpShowAudioError('Stream error — check AutoDJ is Online, then click Play again.' + cpAudioRestartHint);
    });
    stAudioEl.addEventListener('playing', function() { stIsPlaying = true; cpSyncPlayButtons(); });
    stAudioEl.addEventListener('pause', function() { stIsPlaying = false; cpSyncPlayButtons(); });
}

setInterval(cpRefreshNowPlaying, 3000);
setInterval(function() {
    if (cpPlayerState.duration > 0 && cpPlayerState.elapsed < cpPlayerState.duration) {
        cpPlayerState.elapsed++;
        cpPlayerState.progress = Math.min(100, (cpPlayerState.elapsed / cpPlayerState.duration) * 100);
        cpUpdateProgressUI();
    }
}, 1000);
cpRefreshNowPlaying();
</script>

<?php require_once __DIR__ . '/../includes/layout_footer.php'; ?>
