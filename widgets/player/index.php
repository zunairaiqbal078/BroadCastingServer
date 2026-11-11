<?php
/**
 * CastPanel v2.3 - Reproductor Público Full-Page (Estilo SonicPanel)
 * jPlayer flatie skin light, fondos rotativos, album art animado
 * URL: /widgets/player/?slug=mi-radio
 *      /widgets/player/?p=8002
 *      /widgets/player/?p=8002&type=rain  (type: rain|snow|stars|wave|equalizer|none)
 */
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/functions.php';

$db = getDB();

$slug = $_GET['slug'] ?? $_GET['station'] ?? '';
$port = (int)($_GET['p'] ?? 0);
$typeOverride = $_GET['type'] ?? null;

if ($slug) {
    $stmt = $db->prepare("SELECT * FROM estaciones WHERE slug = ? AND activa = 1");
    $stmt->execute([$slug]);
} elseif ($port) {
    $stmt = $db->prepare("SELECT * FROM estaciones WHERE (puerto_stream = ? OR icecast_port = ?) AND activa = 1");
    $stmt->execute([$port, $port]);
} else {
    die('Parámetro slug o p requerido');
}
$estacion = $stmt->fetch();
if (!$estacion) die('Estación no encontrada');

// Obtener configuración del player
$pcStmt = $db->prepare("SELECT * FROM player_config WHERE estacion_id = ?");
$pcStmt->execute([$estacion['id']]);
$pc = $pcStmt->fetch();

// Defaults
$bgImage = $pc['bg_image'] ?? null;
$coverImage = $pc['cover_image'] ?? null;
$bgOverlay = isset($pc['bg_overlay_opacity']) ? floatval($pc['bg_overlay_opacity']) : 0.60;
$bgBlur = isset($pc['bg_blur']) ? intval($pc['bg_blur']) : 0;
$effectType = $typeOverride ?? ($pc['effect_type'] ?? 'rain');
$showHistory = ($pc['show_history'] ?? 1) ? true : false;
$showListeners = ($pc['show_listeners'] ?? 1) ? true : false;

// Redes sociales
$socials = [];
if (!empty($pc['social_facebook'])) $socials[] = ['url' => $pc['social_facebook'], 'icon' => 'fb', 'label' => 'Facebook'];
if (!empty($pc['social_instagram'])) $socials[] = ['url' => $pc['social_instagram'], 'icon' => 'ig', 'label' => 'Instagram'];
if (!empty($pc['social_twitter'])) $socials[] = ['url' => $pc['social_twitter'], 'icon' => 'tw', 'label' => 'Twitter/X'];
if (!empty($pc['social_youtube'])) $socials[] = ['url' => $pc['social_youtube'], 'icon' => 'yt', 'label' => 'YouTube'];
if (!empty($pc['social_website'])) $socials[] = ['url' => $pc['social_website'], 'icon' => 'web', 'label' => 'Website'];

$streamUrl = castpanelStreamPlaybackUrl($estacion);
$fallbackUrl = isShoutcastServerType($estacion['server_type'] ?? '')
    ? preg_replace('#/shoutcast/#', '/live/', $streamUrl)
    : '';
$apiUrl = (isset($_SERVER['HTTPS']) ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? SSL_DOMAIN) . str_replace('/widgets/player', '', dirname($_SERVER['SCRIPT_NAME'])) . '/api/nowplaying.php?slug=' . urlencode($estacion['slug']);
$radioName = htmlspecialchars($estacion['titulo_radio'] ?: $estacion['nombre']);
$genre = htmlspecialchars($estacion['genero'] ?? '');
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $radioName ?> - Radio en Vivo</title>
    <meta name="description" content="Escucha <?= $radioName ?> en vivo<?= $genre ? ' - ' . $genre : '' ?>">
    <meta property="og:title" content="<?= $radioName ?> - Radio en Vivo">
    <meta property="og:type" content="music.radio_station">
    <?php if ($coverImage): ?>
    <meta property="og:image" content="<?= htmlspecialchars($coverImage) ?>">
    <?php endif; ?>
    <link rel="shortcut icon" href="../../assets/favicon.ico">
    <link href="https://fonts.googleapis.com/css2?family=Open+Sans:wght@300;400;600;700;800&display=swap" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/animate.css/4.1.1/animate.min.css" rel="stylesheet">
    <style>
    /* === RESET === */
    * { margin:0; padding:0; box-sizing:border-box; }
    html, body {
        height:100%; overflow:hidden;
        font-family:'Open Sans', Arial, sans-serif;
        color:#333;
    }

    /* === BODY BACKGROUND === */
    body {
        background: #1a1a2e;
        display:flex;
        align-items:center;
        justify-content:center;
        position:relative;
    }
    /* Imagen de fondo real */
    .bg-layer {
        position:fixed; inset:0; z-index:0;
        background-size:cover;
        background-position:center center;
        background-repeat:no-repeat;
        transition: opacity 2s ease-in-out;
        <?php if ($bgBlur > 0): ?>
        filter: blur(<?= $bgBlur ?>px);
        transform: scale(1.05); /* evitar bordes blancos del blur */
        <?php endif; ?>
    }
    /* Overlay oscuro sobre el fondo */
    .bg-overlay {
        position:fixed; inset:0; z-index:0;
        background: rgba(0,0,0,<?= $bgOverlay ?>);
    }

    /* === PRELOADER === */
    .se-pre-con {
        position:fixed; inset:0; z-index:9999;
        background:#fff;
        display:flex; align-items:center; justify-content:center;
        transition: opacity 0.6s;
    }
    .se-pre-con.hidden { opacity:0; pointer-events:none; }
    .loader {
        width:40px; height:40px;
        border:3px solid #e0e0e0;
        border-top:3px solid #2196F3;
        border-radius:50%;
        animation: spin 0.8s linear infinite;
    }
    @keyframes spin { 0%{transform:rotate(0)} 100%{transform:rotate(360deg)} }

    /* === MAIN CONTAINER === */
    .main-container {
        position:relative; z-index:1;
        display:block;
        margin:0 auto;
        max-width:600px;
        width:100%;
        padding:20px;
    }

    /* === ALBUM ART === */
    .art-container {
        text-align:center;
        margin-bottom:12px;
    }
    .art-container img {
        max-width:280px;
        width:100%;
        border-radius:12px;
        box-shadow: 0 8px 30px rgba(0,0,0,0.3);
        transition: transform 0.3s ease;
    }
    /* Default art (animated disc) */
    .default-art {
        width:280px; height:280px;
        margin:0 auto;
        border-radius:50%;
        background: radial-gradient(circle at 50% 50%, #555 0%, #333 30%, #222 50%, #111 70%, #000 100%);
        box-shadow: 0 8px 30px rgba(0,0,0,0.4);
        display:flex; align-items:center; justify-content:center;
        position:relative;
        overflow:hidden;
    }
    .default-art::before {
        content:'';
        position:absolute;
        width:80px; height:80px;
        border-radius:50%;
        background:linear-gradient(135deg, #2196F3, #03A9F4);
        box-shadow: 0 0 0 8px rgba(255,255,255,0.1), 0 0 0 16px rgba(255,255,255,0.05);
    }
    .default-art::after {
        content:'';
        position:absolute; inset:10px;
        border-radius:50%;
        border:1px solid rgba(255,255,255,0.06);
        background: repeating-radial-gradient(
            circle at center,
            transparent 0px,
            transparent 8px,
            rgba(255,255,255,0.03) 8px,
            rgba(255,255,255,0.03) 9px
        );
    }
    .default-art.spinning {
        animation: vinylSpin 4s linear infinite;
    }
    @keyframes vinylSpin { 0%{transform:rotate(0)} 100%{transform:rotate(360deg)} }

    /* === NOW PLAYING BAR === */
    .nowplay-bar {
        background: rgba(0,0,0,0.55);
        backdrop-filter: blur(10px);
        -webkit-backdrop-filter: blur(10px);
        border-radius:10px;
        padding:10px 16px;
        margin-bottom:8px;
        display:flex;
        align-items:center;
        gap:10px;
        min-height:48px;
    }
    .nowplay-icon {
        flex-shrink:0;
    }
    .nowplay-icon img,
    .nowplay-icon svg {
        vertical-align:middle;
    }
    .nowplay-text {
        flex:1;
        min-width:0;
        color:#fff;
        font-size:13px;
        overflow:hidden;
        text-overflow:ellipsis;
        white-space:nowrap;
    }
    .nowplay-text b {
        color:#4FC3F7;
        font-weight:700;
    }
    .nowplay-text .np-song {
        font-weight:400;
        color:#e0e0e0;
    }

    /* === FLATIE JPLAYER SKIN (Light) - Recreado en CSS puro === */
    .audio-player {
        background: #f5f5f5;
        border-radius:10px;
        padding:0;
        overflow:hidden;
        box-shadow: 0 4px 20px rgba(0,0,0,0.15);
        border:1px solid #e0e0e0;
    }

    /* Player top row */
    .player-controls {
        display:flex;
        align-items:center;
        gap:0;
        padding:8px 12px;
        background:#fff;
    }

    /* Play button */
    .btn-play {
        width:48px; height:48px;
        border-radius:50%;
        border:2px solid #2196F3;
        background:#fff;
        color:#2196F3;
        font-size:20px;
        cursor:pointer;
        display:flex;
        align-items:center;
        justify-content:center;
        flex-shrink:0;
        transition:all 0.2s;
        margin-right:12px;
    }
    .btn-play:hover {
        background:#2196F3;
        color:#fff;
    }
    .btn-play:active {
        transform:scale(0.92);
    }
    .btn-play.playing {
        background:#2196F3;
        color:#fff;
    }

    /* Station info in player */
    .player-info {
        flex:1;
        min-width:0;
    }
    .player-station {
        font-size:14px;
        font-weight:700;
        color:#333;
        overflow:hidden;
        text-overflow:ellipsis;
        white-space:nowrap;
        line-height:1.3;
    }
    .player-meta {
        font-size:11px;
        color:#999;
        display:flex;
        align-items:center;
        gap:8px;
    }
    .player-meta .live-dot {
        width:6px; height:6px;
        border-radius:50%;
        display:inline-block;
    }
    .player-meta .live-dot.on { background:#4CAF50; animation:blink 1.5s infinite; }
    .player-meta .live-dot.off { background:#ccc; }
    @keyframes blink{0%,100%{opacity:1}50%{opacity:.3}}

    /* Volume section */
    .player-volume {
        display:flex;
        align-items:center;
        gap:6px;
        flex-shrink:0;
        margin-left:8px;
    }
    .vol-btn {
        background:none; border:none;
        color:#888;
        cursor:pointer;
        padding:4px;
        transition:color 0.2s;
        display:flex; align-items:center; justify-content:center;
        line-height:0;
    }
    .vol-btn:hover { color:#2196F3; }
    .vol-btn svg { display:block; }
    .vol-range {
        width:70px; height:4px;
        -webkit-appearance:none; appearance:none;
        background:#e0e0e0;
        border-radius:4px;
        outline:none;
    }
    .vol-range::-webkit-slider-thumb {
        -webkit-appearance:none;
        width:14px; height:14px;
        border-radius:50%;
        background:#2196F3;
        cursor:pointer;
        box-shadow:0 1px 4px rgba(0,0,0,.2);
    }
    .vol-range::-moz-range-thumb {
        width:14px; height:14px;
        border-radius:50%;
        background:#2196F3;
        border:none;
        cursor:pointer;
    }

    /* Progress/EQ bar at bottom of player */
    .player-eq-bar {
        height:4px;
        background: linear-gradient(90deg, #2196F3, #03A9F4, #00BCD4, #2196F3);
        background-size:300% 100%;
        animation: eqSlide 3s linear infinite;
        opacity:0;
        transition:opacity 0.3s;
    }
    .player-eq-bar.active { opacity:1; }
    @keyframes eqSlide {
        0%{background-position:0% 50%}
        100%{background-position:300% 50%}
    }

    /* === STATUS BAR === */
    .status-bar {
        display:flex;
        align-items:center;
        justify-content:space-between;
        padding:8px 4px;
        font-size:11px;
        color:rgba(255,255,255,0.5);
    }
    .status-bar .listeners-info {
        display:flex; align-items:center; gap:4px;
    }
    .status-bar .codec-info {
        text-transform:uppercase;
        letter-spacing:0.5px;
    }

    /* === SOCIAL LINKS === */
    .social-links {
        display:flex;
        justify-content:center;
        gap:8px;
        margin-top:10px;
    }
    .social-link {
        width:36px; height:36px;
        border-radius:50%;
        background:rgba(255,255,255,0.1);
        border:1px solid rgba(255,255,255,0.15);
        display:flex; align-items:center; justify-content:center;
        color:rgba(255,255,255,0.6);
        text-decoration:none;
        font-size:12px; font-weight:700;
        transition:all 0.2s;
    }
    .social-link:hover {
        background:#2196F3;
        border-color:#2196F3;
        color:#fff;
        transform:translateY(-2px);
        box-shadow: 0 4px 12px rgba(33,150,243,0.3);
    }
    .social-link svg { width:14px; height:14px; }

    /* === HISTORY === */
    .history-section {
        margin-top:10px;
        background:rgba(0,0,0,0.4);
        backdrop-filter:blur(10px);
        -webkit-backdrop-filter:blur(10px);
        border-radius:10px;
        overflow:hidden;
    }
    .history-btn {
        width:100%; padding:10px;
        background:none; border:none;
        color:rgba(255,255,255,0.5);
        font-size:12px; font-weight:600;
        cursor:pointer;
        font-family:'Open Sans',sans-serif;
        transition:color 0.2s;
    }
    .history-btn:hover { color:rgba(255,255,255,0.8); }
    .history-list { display:none; padding:0 14px 14px; }
    .history-list.open { display:block; }
    .h-item {
        display:flex; align-items:center; gap:10px;
        padding:7px 0; border-bottom:1px solid rgba(255,255,255,0.05);
        font-size:12px;
    }
    .h-item:last-child { border-bottom:none; }
    .h-num {
        width:22px; height:22px; border-radius:50%;
        background:rgba(33,150,243,0.15);
        display:flex; align-items:center; justify-content:center;
        font-size:10px; font-weight:700; color:#4FC3F7; flex-shrink:0;
    }
    .h-info { flex:1; min-width:0; }
    .h-title { color:rgba(255,255,255,0.7); font-weight:600; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
    .h-artist { color:rgba(255,255,255,0.35); font-size:11px; }

    /* === POWERED BY === */
    .powered-by {
        text-align:center;
        margin-top:12px;
        font-size:10px;
        color:rgba(255,255,255,0.3);
        letter-spacing:0.5px;
    }
    .powered-by a { color:rgba(255,255,255,0.4); text-decoration:none; }
    .powered-by a:hover { color:rgba(255,255,255,0.7); }

    /* === ANIMATIONS === */
    @keyframes fadeInCover {
        from { opacity:0; transform:scale(0.95); }
        to { opacity:1; transform:scale(1); }
    }

    /* === RESPONSIVE === */
    @media(max-width:480px){
        .main-container { padding:15px; }
        .art-container img { max-width:220px; }
        .default-art { width:220px; height:220px; }
        .default-art::before { width:60px; height:60px; }
        .nowplay-text { font-size:12px; }
        .player-station { font-size:13px; }
        .vol-range { width:50px; }
    }
    @media(max-width:360px){
        .art-container img { max-width:180px; }
        .default-art { width:180px; height:180px; }
        .default-art::before { width:50px; height:50px; }
    }
    </style>
</head>
<body>

<!-- Background layer -->
<?php if ($bgImage): ?>
<div class="bg-layer" id="bgLayer" style="background-image:url('<?= htmlspecialchars($bgImage) ?>')"></div>
<?php else: ?>
<div class="bg-layer" id="bgLayer" style="background:linear-gradient(135deg, #0c1220 0%, #1a2040 50%, #0d1a2d 100%)"></div>
<?php endif; ?>
<div class="bg-overlay"></div>

<?php if ($effectType !== 'none'): ?>
<canvas id="fxCanvas" style="position:fixed;inset:0;z-index:0;pointer-events:none"></canvas>
<?php endif; ?>

<!-- Preloader (como SonicPanel) -->
<div class="se-pre-con" id="preloader">
    <div class="loader"></div>
</div>

<!-- Main Container (estilo SonicPanel: centrado, max 600px) -->
<div class="main-container">

    <!-- Album Art -->
    <div class="art-container" id="artContainer">
        <?php if ($coverImage): ?>
        <img src="<?= htmlspecialchars($coverImage) ?>" alt="<?= $radioName ?>" id="artImg" class="animate__animated">
        <?php else: ?>
        <div class="default-art" id="defaultArt">
        </div>
        <?php endif; ?>
    </div>

    <!-- Now Playing bar (como SonicPanel) -->
    <div class="nowplay-bar">
        <div class="nowplay-icon">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none">
                <path d="M9 18V5l12-2v13" stroke="#4FC3F7" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                <circle cx="6" cy="18" r="3" fill="#4FC3F7"/>
                <circle cx="18" cy="16" r="3" fill="#4FC3F7"/>
            </svg>
        </div>
        <div class="nowplay-text">
            <b>Now Playing:</b> <span class="np-song" id="nowPlayText">Cargando...</span>
        </div>
    </div>

    <!-- Audio Player (Flatie Light Style) -->
    <div class="audio-player">
        <div class="player-controls">
            <!-- Play Button -->
            <button class="btn-play" id="btnPlay" onclick="togglePlay()">
                <span id="playIcon"><svg width="22" height="22" viewBox="0 0 24 24" fill="currentColor"><polygon points="5 3 19 12 5 21 5 3"/></svg></span>
            </button>

            <!-- Station Info -->
            <div class="player-info">
                <div class="player-station" id="playerStation"><?= $radioName ?></div>
                <div class="player-meta">
                    <span class="live-dot off" id="liveDot"></span>
                    <span id="liveStatus">Conectando...</span>
                    <?php if ($showListeners): ?>
                    <span>&bull;</span>
                    <span id="listenersInfo">-</span>
                    <?php endif; ?>
                    <span>&bull;</span>
                    <span><?= strtoupper($estacion['codec'] ?: 'MP3') ?> <?= $estacion['bitrate'] ?>kbps</span>
                </div>
            </div>

            <!-- Volume -->
            <div class="player-volume">
                <button class="vol-btn" id="volBtn" onclick="toggleMute()">
                    <svg id="volIcon" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polygon points="11 5 6 9 2 9 2 15 6 15 11 19 11 5"/><path d="M19.07 4.93a10 10 0 0 1 0 14.14M15.54 8.46a5 5 0 0 1 0 7.07"/></svg>
                </button>
                <input type="range" class="vol-range" id="volRange" min="0" max="100" value="80" oninput="setVol(this.value)">
            </div>
        </div>

        <!-- EQ progress bar -->
        <div class="player-eq-bar" id="eqBar"></div>
    </div>

    <!-- Status bar -->
    <div class="status-bar">
        <div class="listeners-info">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="opacity:.6;vertical-align:middle;margin-right:4px"><path d="M4.9 19.1C1 15.2 1 8.8 4.9 4.9"/><path d="M7.8 16.2c-2.3-2.3-2.3-6.1 0-8.5"/><circle cx="12" cy="12" r="2" fill="currentColor"/><path d="M16.2 7.8c2.3 2.3 2.3 6.1 0 8.5"/><path d="M19.1 4.9C23 8.8 23 15.2 19.1 19.1"/></svg>
            <span id="radioGenre"><?= $genre ?: 'Radio Online' ?></span>
        </div>
        <div class="codec-info">
            <span id="streamStatus" class="status-ready"><svg width="8" height="8" viewBox="0 0 8 8" fill="currentColor" style="vertical-align:middle;margin-right:4px"><circle cx="4" cy="4" r="4"/></svg>REPRODUCIENDO</span>
        </div>
    </div>

    <!-- Social links -->
    <?php if (!empty($socials)): ?>
    <div class="social-links">
        <?php foreach ($socials as $s): ?>
        <a href="<?= htmlspecialchars($s['url']) ?>" target="_blank" rel="noopener" class="social-link" title="<?= $s['label'] ?>">
            <?php
            switch($s['icon']){
                case 'fb': echo '<svg viewBox="0 0 24 24" fill="currentColor"><path d="M18 2h-3a5 5 0 00-5 5v3H7v4h3v8h4v-8h3l1-4h-4V7a1 1 0 011-1h3z"/></svg>'; break;
                case 'ig': echo '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="2" y="2" width="20" height="20" rx="5"/><circle cx="12" cy="12" r="5"/></svg>'; break;
                case 'tw': echo '<svg viewBox="0 0 24 24" fill="currentColor"><path d="M18.244 2.25h3.308l-7.227 8.26 8.502 11.24H16.17l-5.214-6.817L4.99 21.75H1.68l7.73-8.835L1.254 2.25H8.08l4.713 6.231zm-1.161 17.52h1.833L7.084 4.126H5.117z"/></svg>'; break;
                case 'yt': echo '<svg viewBox="0 0 24 24" fill="currentColor"><path d="M23.5 6.19a3 3 0 00-2.11-2.12C19.5 3.5 12 3.5 12 3.5s-7.5 0-9.39.57A3 3 0 00.5 6.19 31.25 31.25 0 000 12a31.25 31.25 0 00.5 5.81 3 3 0 002.11 2.12C4.5 20.5 12 20.5 12 20.5s7.5 0 9.39-.57a3 3 0 002.11-2.12A31.25 31.25 0 0024 12a31.25 31.25 0 00-.5-5.81zM9.75 15.02V8.98L15.5 12l-5.75 3.02z"/></svg>'; break;
                case 'web': echo '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="2" y1="12" x2="22" y2="12"/><path d="M12 2a15.3 15.3 0 014 10 15.3 15.3 0 01-4 10 15.3 15.3 0 01-4-10 15.3 15.3 0 014-10z"/></svg>'; break;
            }
            ?>
        </a>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <!-- History -->
    <?php if ($showHistory): ?>
    <div class="history-section">
        <button class="history-btn" onclick="toggleHistory()" id="histBtn">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="vertical-align:middle;margin-right:4px"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
            Historial <span id="histArrow"><svg width="10" height="10" viewBox="0 0 24 24" fill="currentColor" style="vertical-align:middle"><path d="M7 10l5 5 5-5z"/></svg></span>
        </button>
        <div class="history-list" id="histList"></div>
    </div>
    <?php endif; ?>

    <div class="powered-by">
        Powered by <a href="#">CastPanel</a>
    </div>
</div>

<script>
(function(){
    // === CONFIG ===
    var streamUrl = "<?= $streamUrl ?>";
    var fallbackUrl = "<?= $fallbackUrl ?>";
    var apiUrl = "<?= $apiUrl ?>";
    var defaultCover = <?= $coverImage ? '"'.htmlspecialchars($coverImage).'"' : 'null' ?>;
    var audio = null, playing = false, vol = 80, retrying = false;
    var lastSong = '';

    // === PRELOADER (como SonicPanel) ===
    window.addEventListener('load', function(){
        setTimeout(function(){
            document.getElementById('preloader').classList.add('hidden');
        }, 800);
    });

    // === BACKGROUND ===
    <?php if (!$bgImage): ?>
    // Sin imagen configurada: rotar gradientes como fallback
    var backgrounds = [
        'linear-gradient(135deg, #0c1220 0%, #1a2040 50%, #0d1a2d 100%)',
        'linear-gradient(135deg, #1a1030 0%, #2d1b69 50%, #0c1220 100%)',
        'linear-gradient(135deg, #0d2137 0%, #0a1628 50%, #162447 100%)',
        'linear-gradient(135deg, #1b0a2e 0%, #2a1a5e 50%, #0e1538 100%)',
        'linear-gradient(135deg, #0a192f 0%, #203a43 50%, #2c5364 100%)'
    ];
    var currentBg = 0;
    function nextBg(){
        currentBg = (currentBg + 1) % backgrounds.length;
        document.getElementById('bgLayer').style.background = backgrounds[currentBg];
        setTimeout(nextBg, 20000);
    }
    setTimeout(nextBg, 20000);
    <?php endif; ?>

    // === FETCH DATA ===
    function fetchData(){
        fetch(apiUrl).then(function(r){return r.json()}).then(function(d){
            if(d.error) return;

            // Station name
            document.getElementById('playerStation').textContent = d.station.name;

            // Live status
            var dot = document.getElementById('liveDot');
            var statusText = document.getElementById('liveStatus');
            if(d.live.online){
                dot.className = 'live-dot on';
                statusText.textContent = 'EN VIVO';
            } else {
                dot.className = 'live-dot off';
                statusText.textContent = 'OFFLINE';
            }

            // Now Playing
            var songText = '';
            if(d.now_playing && d.now_playing.title){
                songText = d.now_playing.title;
                if(d.now_playing.artist) songText = d.now_playing.artist + ' - ' + d.now_playing.title;

                // Album art update con animación (como SonicPanel)
                var coverSrc = d.now_playing.cover || defaultCover;
                if(coverSrc && songText !== lastSong){
                    var ac = document.getElementById('artContainer');
                    ac.innerHTML = '<img src="'+esc(coverSrc)+'" alt="cover" id="artImg" class="animate__animated animate__jello">';
                    // Quitar clase animación después
                    setTimeout(function(){
                        var img = document.getElementById('artImg');
                        if(img) img.classList.remove('animate__jello');
                    }, 1000);
                }

                // Now playing text con animación (como SonicPanel - rubberBand)
                if(songText !== lastSong){
                    var npEl = document.getElementById('nowPlayText');
                    npEl.textContent = songText;
                    npEl.className = 'np-song animate__animated animate__rubberBand';
                    setTimeout(function(){
                        npEl.className = 'np-song';
                    }, 1000);
                    lastSong = songText;
                }
            } else if(d.live.title){
                songText = d.live.title;
                document.getElementById('nowPlayText').textContent = songText;
            }

            // Listeners
            var lis = document.getElementById('listenersInfo');
            if(lis) lis.textContent = (d.live.listeners||0)+' oyente'+((d.live.listeners||0)!==1?'s':'');

            // Title
            document.title = (songText || d.station.name)+' - Radio en Vivo';

            // History
            var hl = document.getElementById('histList');
            if(hl && d.history && d.history.length){
                var html='';
                d.history.forEach(function(h,i){
                    html+='<div class="h-item"><div class="h-num">'+(i+1)+'</div><div class="h-info"><div class="h-title">'+esc(h.title||'-')+'</div><div class="h-artist">'+esc(h.artist||'')+'</div></div></div>';
                });
                hl.innerHTML=html;
            }
        }).catch(function(){});
    }

    function esc(s){var d=document.createElement('div');d.textContent=s;return d.innerHTML;}

    // === PLAYER CONTROLS ===
    function setPlayingUI(on){
        playing = on;
        var btn = document.getElementById('btnPlay');
        var icon = document.getElementById('playIcon');
        var eq = document.getElementById('eqBar');
        var da = document.getElementById('defaultArt');
        var ss = document.getElementById('streamStatus');

        icon.innerHTML = on
            ? '<svg width="22" height="22" viewBox="0 0 24 24" fill="currentColor"><rect x="6" y="4" width="4" height="16" rx="1"/><rect x="14" y="4" width="4" height="16" rx="1"/></svg>'
            : '<svg width="22" height="22" viewBox="0 0 24 24" fill="currentColor"><polygon points="5 3 19 12 5 21 5 3"/></svg>';
        btn.className = on ? 'btn-play playing' : 'btn-play';
        eq.className = on ? 'player-eq-bar active' : 'player-eq-bar';

        if(da){
            if(on) da.classList.add('spinning');
            else da.classList.remove('spinning');
        }

        ss.innerHTML = on
            ? '<svg width="8" height="8" viewBox="0 0 8 8" fill="#4CAF50" style="vertical-align:middle;margin-right:4px"><circle cx="4" cy="4" r="4"/></svg>REPRODUCIENDO'
            : '<svg width="8" height="8" viewBox="0 0 8 8" fill="currentColor" style="vertical-align:middle;margin-right:4px"><circle cx="4" cy="4" r="4"/></svg>Listo';
        ss.style.color = on ? '#4CAF50' : 'rgba(255,255,255,0.5)';
    }

    function tryStream(url, canFallback){
        if(audio){ audio.pause(); audio.src=''; audio=null; }
        audio = new Audio(url);
        audio.volume = vol/100;
        audio.crossOrigin = 'anonymous';

        audio.onplaying = function(){
            retrying = false;
            setPlayingUI(true);
        };

        audio.onerror = function(){
            console.log('Stream error: ' + url);
            if(canFallback && fallbackUrl && !retrying){
                retrying = true;
                console.log('Trying fallback: ' + fallbackUrl);
                tryStream(fallbackUrl, false);
                return;
            }
            retrying = false;
            setPlayingUI(false);
            audio = null;
        };

        setPlayingUI(true);
        audio.play().catch(function(e){
            console.log('Play error: ' + e.message);
            if(canFallback && fallbackUrl && !retrying){
                retrying = true;
                tryStream(fallbackUrl, false);
                return;
            }
            retrying = false;
            setPlayingUI(false);
            audio = null;
        });
    }

    window.togglePlay = function(){
        if(playing){
            if(audio){ audio.pause(); audio.src=''; audio=null; }
            setPlayingUI(false);
        } else {
            tryStream(streamUrl, true);
        }
    };

    window.setVol = function(v){
        vol=parseInt(v);
        if(audio) audio.volume=vol/100;
        var volSvg = vol===0
            ? '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polygon points="11 5 6 9 2 9 2 15 6 15 11 19 11 5"/><line x1="23" y1="9" x2="17" y2="15"/><line x1="17" y1="9" x2="23" y2="15"/></svg>'
            : '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polygon points="11 5 6 9 2 9 2 15 6 15 11 19 11 5"/><path d="M19.07 4.93a10 10 0 0 1 0 14.14"/><path d="M15.54 8.46a5 5 0 0 1 0 7.07"/></svg>';
        document.getElementById('volBtn').innerHTML = volSvg;
    };

    window.toggleMute = function(){
        var s=document.getElementById('volRange');
        if(vol>0){window._pv=vol;setVol(0);s.value=0;}
        else{setVol(window._pv||80);s.value=vol;}
    };

    window.toggleHistory = function(){
        var l=document.getElementById('histList');
        if(!l)return;
        var open=l.classList.toggle('open');
        document.getElementById('histArrow').innerHTML=open
            ?'<svg width="10" height="10" viewBox="0 0 24 24" fill="currentColor" style="vertical-align:middle"><path d="M7 14l5-5 5 5z"/></svg>'
            :'<svg width="10" height="10" viewBox="0 0 24 24" fill="currentColor" style="vertical-align:middle"><path d="M7 10l5 5 5-5z"/></svg>';
    };

    // === INIT ===
    fetchData();
    setInterval(fetchData, 12000);
})();
</script>

<?php if ($effectType !== 'none'): ?>
<script>
// === VISUAL EFFECTS ===
(function(){
    var canvas = document.getElementById('fxCanvas');
    if(!canvas) return;
    var ctx = canvas.getContext('2d');
    var W, H, particles = [];
    var effect = "<?= $effectType ?>";

    function resize(){
        W = canvas.width = window.innerWidth;
        H = canvas.height = window.innerHeight;
    }
    resize();
    window.addEventListener('resize', resize);

    function initParticles(){
        particles = [];
        var count;
        switch(effect){
            case 'rain': count = 120; break;
            case 'snow': count = 80; break;
            case 'stars': count = 100; break;
            case 'wave': count = 60; break;
            case 'equalizer': count = 32; break;
            default: count = 80;
        }
        for(var i=0;i<count;i++){
            var p = {};
            switch(effect){
                case 'rain':
                    p.x = Math.random()*W;
                    p.y = Math.random()*H;
                    p.len = 10+Math.random()*20;
                    p.speed = 4+Math.random()*6;
                    p.opacity = 0.1+Math.random()*0.25;
                    break;
                case 'snow':
                    p.x = Math.random()*W;
                    p.y = Math.random()*H;
                    p.r = 1+Math.random()*3;
                    p.speed = 0.5+Math.random()*1.5;
                    p.wind = -0.3+Math.random()*0.6;
                    p.opacity = 0.3+Math.random()*0.5;
                    break;
                case 'stars':
                    p.x = Math.random()*W;
                    p.y = Math.random()*H;
                    p.r = 0.5+Math.random()*2;
                    p.opacity = Math.random();
                    p.twinkleSpeed = 0.005+Math.random()*0.02;
                    p.dir = Math.random()>0.5?1:-1;
                    break;
                case 'wave':
                    p.x = i*(W/count);
                    p.baseY = H*0.7;
                    p.amplitude = 20+Math.random()*40;
                    p.speed = 0.01+Math.random()*0.02;
                    p.offset = Math.random()*Math.PI*2;
                    p.r = 2+Math.random()*3;
                    p.opacity = 0.15+Math.random()*0.2;
                    break;
                case 'equalizer':
                    p.x = i*(W/count);
                    p.barW = (W/count)-2;
                    p.maxH = 30+Math.random()*100;
                    p.speed = 0.03+Math.random()*0.05;
                    p.offset = Math.random()*Math.PI*2;
                    p.opacity = 0.1+Math.random()*0.15;
                    break;
            }
            particles.push(p);
        }
    }

    var t = 0;
    function draw(){
        ctx.clearRect(0,0,W,H);
        t += 0.016;

        for(var i=0;i<particles.length;i++){
            var p = particles[i];
            switch(effect){
                case 'rain':
                    ctx.strokeStyle = 'rgba(174,194,224,'+p.opacity+')';
                    ctx.lineWidth = 1;
                    ctx.beginPath();
                    ctx.moveTo(p.x, p.y);
                    ctx.lineTo(p.x+1, p.y+p.len);
                    ctx.stroke();
                    p.y += p.speed;
                    if(p.y > H){ p.y = -p.len; p.x = Math.random()*W; }
                    break;

                case 'snow':
                    ctx.fillStyle = 'rgba(255,255,255,'+p.opacity+')';
                    ctx.beginPath();
                    ctx.arc(p.x, p.y, p.r, 0, Math.PI*2);
                    ctx.fill();
                    p.y += p.speed;
                    p.x += p.wind + Math.sin(t+i)*0.3;
                    if(p.y > H+5){ p.y = -5; p.x = Math.random()*W; }
                    if(p.x > W) p.x = 0;
                    if(p.x < 0) p.x = W;
                    break;

                case 'stars':
                    p.opacity += p.twinkleSpeed * p.dir;
                    if(p.opacity >= 1){ p.opacity = 1; p.dir = -1; }
                    if(p.opacity <= 0.1){ p.opacity = 0.1; p.dir = 1; }
                    ctx.fillStyle = 'rgba(255,255,255,'+p.opacity+')';
                    ctx.beginPath();
                    ctx.arc(p.x, p.y, p.r, 0, Math.PI*2);
                    ctx.fill();
                    break;

                case 'wave':
                    var yOff = Math.sin(t*2 + p.offset + p.x*0.005) * p.amplitude;
                    ctx.fillStyle = 'rgba(33,150,243,'+p.opacity+')';
                    ctx.beginPath();
                    ctx.arc(p.x, p.baseY + yOff, p.r, 0, Math.PI*2);
                    ctx.fill();
                    break;

                case 'equalizer':
                    var h = (Math.sin(t*3 + p.offset) * 0.5 + 0.5) * p.maxH;
                    ctx.fillStyle = 'rgba(33,150,243,'+p.opacity+')';
                    ctx.fillRect(p.x, H - h, p.barW > 1 ? p.barW : 2, h);
                    break;
            }
        }
        requestAnimationFrame(draw);
    }

    initParticles();
    draw();
    window.addEventListener('resize', initParticles);
})();
</script>
<?php endif; ?>

</body>
</html>
