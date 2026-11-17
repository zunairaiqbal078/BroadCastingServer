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

// Accent colour from "Customize Player" (was ignored: blue was hard-coded)
$cpAccent = '#2196F3';
try {
    $acStmt = $db->prepare("SELECT accent_color FROM player_config WHERE estacion_id = ?");
    $acStmt->execute([$estacion['id']]);
    $acVal = trim((string)$acStmt->fetchColumn());
    if (preg_match('/^#[0-9a-fA-F]{6}$/', $acVal)) $cpAccent = $acVal;
} catch (Throwable $e) {}
$cpAccentRgb = implode(',', array_map('hexdec', str_split(substr($cpAccent, 1), 2)));

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
    /* ===== Full-page player (redesign) ===== */
    :root { --ac: <?= $cpAccent ?>; --ac-rgb: <?= $cpAccentRgb ?>; }
    * { margin:0; padding:0; box-sizing:border-box; }
    html, body { height:100%; }
    body {
        font-family:'Open Sans', -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
        color:#fff; background:#0b1020; overflow-x:hidden;
        display:flex; min-height:100vh;
    }
    .bg-layer {
        position:fixed; inset:-40px; z-index:-2;
        background-size:cover; background-position:center;
        filter: blur(<?= max(18, $bgBlur) ?>px) saturate(1.2);
        transform:scale(1.08); transition:background .8s ease;
    }
    .bg-overlay {
        position:fixed; inset:0; z-index:-1;
        background:
            radial-gradient(circle at 20% 15%, rgba(var(--ac-rgb),.35), transparent 45%),
            radial-gradient(circle at 85% 90%, rgba(var(--ac-rgb),.25), transparent 50%),
            rgba(5,8,20,<?= $bgOverlay ?>);
    }
    /* preloader */
    .se-pre-con { position:fixed; inset:0; z-index:99; background:#0b1020; display:flex; align-items:center; justify-content:center; transition:opacity .5s; }
    .se-pre-con.hidden { opacity:0; pointer-events:none; }
    .loader { width:44px; height:44px; border-radius:50%; border:3px solid rgba(255,255,255,.15); border-top-color:var(--ac); animation:spin 1s linear infinite; }
    @keyframes spin { to { transform:rotate(360deg); } }

    .main-container { position:relative; z-index:1; width:100%; max-width:440px; padding:28px 18px; margin:auto; /* centred, never cut off on short screens */ }
    .glass {
        background:linear-gradient(160deg, rgba(255,255,255,.14), rgba(255,255,255,.05));
        border:1px solid rgba(255,255,255,.16);
        -webkit-backdrop-filter:blur(22px); backdrop-filter:blur(22px);
        border-radius:28px; box-shadow:0 30px 80px rgba(0,0,0,.45);
        padding:26px 24px 22px;
    }
    /* top line */
    .pl-top { display:flex; align-items:center; justify-content:space-between; gap:10px; margin-bottom:20px; }
    .pl-brand { font-size:12px; letter-spacing:2px; text-transform:uppercase; opacity:.7; font-weight:700; }
    .live-pill { display:inline-flex; align-items:center; gap:7px; padding:5px 12px; border-radius:30px; font-size:11px; font-weight:800; letter-spacing:1px; background:rgba(255,255,255,.12); border:1px solid rgba(255,255,255,.18); }
    .live-dot { width:8px; height:8px; border-radius:50%; background:#94a3b8; display:inline-block; }
    .live-dot.on { background:#ef4444; box-shadow:0 0 0 0 rgba(239,68,68,.7); animation:pulse 1.6s infinite; }
    @keyframes pulse { 70% { box-shadow:0 0 0 9px rgba(239,68,68,0); } 100% { box-shadow:0 0 0 0 rgba(239,68,68,0); } }

    /* cover */
    .art-container { position:relative; width:100%; aspect-ratio:1/1; max-width:300px; margin:0 auto 22px; }
    .art-container::after { content:''; position:absolute; inset:12% 8% -6%; z-index:-1; border-radius:30px; background:var(--ac); filter:blur(40px); opacity:.45; }
    .art-container img { width:100%; height:100%; object-fit:cover; border-radius:24px; display:block; box-shadow:0 20px 50px rgba(0,0,0,.5); }
    .default-art {
        width:100%; height:100%; border-radius:50%;
        background:
            radial-gradient(circle, var(--ac) 0 13%, #0b1020 13.5% 15%, transparent 15.5%),
            repeating-radial-gradient(circle, #111827 0 2px, #1f2937 2px 4px);
        box-shadow:0 20px 50px rgba(0,0,0,.55), inset 0 0 0 6px rgba(255,255,255,.04);
    }
    .default-art.spinning { animation:spin 6s linear infinite; }

    /* titles */
    .pl-titles { text-align:center; margin-bottom:16px; }
    .player-station { font-size:22px; font-weight:800; line-height:1.2; }
    .nowplay-text { margin-top:6px; font-size:14px; opacity:.85; min-height:20px; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
    .np-label { opacity:.6; }
    .player-meta { margin-top:8px; display:flex; justify-content:center; flex-wrap:wrap; gap:8px; font-size:12px; opacity:.7; }

    /* equalizer */
    .player-eq-bar { display:flex; align-items:flex-end; justify-content:center; gap:4px; height:28px; margin:4px 0 16px; }
    .player-eq-bar span { width:4px; height:15%; border-radius:3px; background:linear-gradient(to top, var(--ac), #fff); opacity:.5; transition:height .3s; }
    .player-eq-bar.active span { opacity:1; animation:eq 1s ease-in-out infinite; }
    .player-eq-bar.active span:nth-child(2n) { animation-duration:.8s; }
    .player-eq-bar.active span:nth-child(3n) { animation-duration:1.25s; }
    .player-eq-bar.active span:nth-child(4n) { animation-duration:.65s; }
    @keyframes eq { 0%,100% { height:15%; } 50% { height:100%; } }

    /* controls */
    .player-controls { display:flex; align-items:center; justify-content:center; gap:22px; }
    .btn-play {
        width:78px; height:78px; border-radius:50%; border:none; cursor:pointer; color:#fff;
        background:linear-gradient(145deg, var(--ac), rgba(var(--ac-rgb),.7));
        box-shadow:0 12px 30px rgba(var(--ac-rgb),.55), inset 0 1px 0 rgba(255,255,255,.35);
        display:flex; align-items:center; justify-content:center; position:relative; transition:transform .2s;
    }
    .btn-play:hover { transform:scale(1.06); }
    .btn-play svg { width:30px; height:30px; }
    .btn-play::before { content:''; position:absolute; inset:-8px; border-radius:50%; border:2px solid rgba(var(--ac-rgb),.5); opacity:0; }
    .btn-play.playing::before { animation:ring 1.8s ease-out infinite; }
    @keyframes ring { 0% { transform:scale(.9); opacity:.8; } 100% { transform:scale(1.35); opacity:0; } }
    .player-volume { display:flex; align-items:center; gap:10px; margin-top:18px; padding:10px 14px; border-radius:16px; background:rgba(255,255,255,.08); }
    .vol-btn { background:none; border:none; color:#fff; cursor:pointer; opacity:.8; display:flex; }
    .vol-btn:hover { opacity:1; color:var(--ac); }
    .vol-range { flex:1; -webkit-appearance:none; appearance:none; height:5px; border-radius:5px; background:rgba(255,255,255,.25); outline:none; accent-color:var(--ac); }
    .vol-range::-webkit-slider-thumb { -webkit-appearance:none; width:16px; height:16px; border-radius:50%; background:#fff; box-shadow:0 0 0 4px rgba(var(--ac-rgb),.5); cursor:pointer; }
    .vol-range::-moz-range-thumb { width:16px; height:16px; border:none; border-radius:50%; background:#fff; cursor:pointer; }

    /* extras */
    .listeners-info { text-align:center; font-size:12px; opacity:.7; margin-top:12px; }
    .social-links { display:flex; justify-content:center; gap:10px; margin-top:16px; }
    .social-link { width:38px; height:38px; border-radius:12px; display:flex; align-items:center; justify-content:center; color:#fff; background:rgba(255,255,255,.1); border:1px solid rgba(255,255,255,.14); transition:all .2s; }
    .social-link:hover { background:var(--ac); border-color:var(--ac); transform:translateY(-2px); }
    .social-link svg { width:17px; height:17px; }
    .history-section { margin-top:16px; border-radius:16px; background:rgba(0,0,0,.18); overflow:hidden; }
    .history-btn { width:100%; padding:11px 14px; border:none; background:none; color:rgba(255,255,255,.75); font-family:inherit; font-size:12.5px; font-weight:700; cursor:pointer; display:flex; align-items:center; justify-content:center; gap:6px; }
    .history-btn:hover { color:#fff; }
    .history-list { display:none; padding:0 14px 12px; max-height:220px; overflow:auto; }
    .history-list.open { display:block; }
    .h-item { display:flex; align-items:center; gap:10px; padding:8px 0; border-bottom:1px solid rgba(255,255,255,.06); font-size:12.5px; }
    .h-item:last-child { border-bottom:none; }
    .h-num { width:24px; height:24px; border-radius:8px; background:rgba(var(--ac-rgb),.25); display:flex; align-items:center; justify-content:center; font-size:11px; font-weight:800; flex-shrink:0; }
    .h-info { flex:1; min-width:0; }
    .h-title { font-weight:600; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
    .h-artist { opacity:.55; font-size:11px; }
    .powered-by { text-align:center; margin-top:14px; font-size:10.5px; opacity:.45; letter-spacing:.5px; }
    .powered-by a { color:#fff; text-decoration:none; }
    @media (max-width:420px) { .glass { padding:22px 16px 18px; border-radius:24px; } .player-station { font-size:19px; } .btn-play { width:68px; height:68px; } }
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

<!-- Main Container -->
<div class="main-container">
  <div class="glass">
    <div class="pl-top">
        <span class="pl-brand"><?= $radioName ?></span>
        <span class="live-pill"><span class="live-dot off" id="liveDot"></span><span id="liveStatus">Conectando...</span></span>
    </div>

    <!-- Cover -->
    <div class="art-container" id="artContainer">
        <?php if ($coverImage): ?>
        <img src="<?= htmlspecialchars($coverImage) ?>" alt="<?= $radioName ?>" id="artImg" class="animate__animated">
        <?php else: ?>
        <div class="default-art" id="defaultArt"></div>
        <?php endif; ?>
    </div>

    <div class="pl-titles">
        <div class="player-station" id="playerStation"><?= $radioName ?></div>
        <div class="nowplay-text"><span class="np-song" id="nowPlayText">Cargando...</span></div>
        <div class="player-meta">
            <?php if ($showListeners): ?><span id="listenersInfo">-</span><span>&bull;</span><?php endif; ?>
            <span><?= strtoupper($estacion['codec'] ?: 'MP3') ?> <?= (int)$estacion['bitrate'] ?> kbps</span>
            <?php if ($genre !== ''): ?><span>&bull;</span><span id="radioGenre"><?= $genre ?></span><?php endif; ?>
        </div>
    </div>

    <div class="player-eq-bar" id="eqBar"><?= str_repeat('<span></span>', 18) ?></div>

    <div class="player-controls">
        <button class="btn-play" id="btnPlay" onclick="togglePlay()" aria-label="Play">
            <span id="playIcon"><svg width="22" height="22" viewBox="0 0 24 24" fill="currentColor"><polygon points="5 3 19 12 5 21 5 3"/></svg></span>
        </button>
    </div>

    <div class="player-volume">
        <button class="vol-btn" id="volBtn" onclick="toggleMute()" aria-label="Mute">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polygon points="11 5 6 9 2 9 2 15 6 15 11 19 11 5"/><path d="M19.07 4.93a10 10 0 0 1 0 14.14M15.54 8.46a5 5 0 0 1 0 7.07"/></svg>
        </button>
        <input type="range" class="vol-range" id="volRange" min="0" max="100" value="80" oninput="setVol(this.value)" aria-label="Volume">
    </div>

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

    <?php if ($showHistory): ?>
    <div class="history-section">
        <button class="history-btn" onclick="toggleHistory()" id="histBtn">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
            Historial <span id="histArrow"><svg width="10" height="10" viewBox="0 0 24 24" fill="currentColor"><path d="M7 10l5 5 5-5z"/></svg></span>
        </button>
        <div class="history-list" id="histList"></div>
    </div>
    <?php endif; ?>
  </div>
  <div class="powered-by">Powered by <a href="#">CastPanel</a></div>
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
    // hide the loader as soon as the page is usable (window "load" waits for web fonts)
    function hidePre(){ var p = document.getElementById('preloader'); if (p) p.classList.add('hidden'); }
    if (document.readyState !== 'loading') setTimeout(hidePre, 300);
    else document.addEventListener('DOMContentLoaded', function(){ setTimeout(hidePre, 300); });
    setTimeout(hidePre, 1500);

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
        if (window._coverBg) return; // background follows the song cover
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
                if(coverSrc && songText !== lastSong && !<?= $bgImage ? 'true' : 'false' ?>){
                    document.getElementById('bgLayer').style.background = 'center/cover no-repeat url("' + coverSrc.replace(/"/g, '') + '")';
                    window._coverBg = true;
                }
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

        if (ss) {
            ss.innerHTML = on
                ? '<svg width="8" height="8" viewBox="0 0 8 8" fill="#4CAF50" style="vertical-align:middle;margin-right:4px"><circle cx="4" cy="4" r="4"/></svg>'
                : '';
            ss.style.color = on ? '#4CAF50' : 'rgba(255,255,255,0.5)';
        }
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
                    ctx.fillStyle = 'rgba(<?= $cpAccentRgb ?>,'+p.opacity+')';
                    ctx.beginPath();
                    ctx.arc(p.x, p.baseY + yOff, p.r, 0, Math.PI*2);
                    ctx.fill();
                    break;

                case 'equalizer':
                    var h = (Math.sin(t*3 + p.offset) * 0.5 + 0.5) * p.maxH;
                    ctx.fillStyle = 'rgba(<?= $cpAccentRgb ?>,'+p.opacity+')';
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
