<?php
/**
 * CastPanel v2.3 - Reproductor Embebible (Legacy)
 * URL: /reproductor.php?s=mi-radio
 *      /reproductor.php?s=mi-radio&json  (API JSON para metadata)
 */
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/functions.php';

$db = getDB();

$slug = $_GET['s'] ?? '';
$port = (int)($_GET['p'] ?? 0);

if ($slug) {
    $stmt = $db->prepare("SELECT * FROM estaciones WHERE slug = ? AND activa = 1");
    $stmt->execute([$slug]);
} elseif ($port) {
    $stmt = $db->prepare("SELECT * FROM estaciones WHERE (puerto_stream = ? OR icecast_port = ?) AND activa = 1");
    $stmt->execute([$port, $port]);
} else {
    die('Uso: reproductor.php?s=nombre-estacion');
}
$estacion = $stmt->fetch();
if (!$estacion) {
    http_response_code(404);
    exit('Estacion no encontrada');
}

$nombre    = htmlspecialchars($estacion['nombre'] ?? 'Radio');
$slug      = $estacion['slug'];
$bitrate   = (int)($estacion['bitrate'] ?? 128);
$formato   = strtoupper($estacion['formato'] ?? 'MP3');
$serverType = $estacion['server_type'] ?? 'icecast';

$streamUrl = castpanelStreamPlaybackUrl($estacion);

// Logo
$logoFile = $estacion['logo'] ?? '';
$logoUrl  = '';
if ($logoFile && file_exists(__DIR__ . '/uploads/logos/' . $logoFile)) {
    $logoUrl = '/uploads/logos/' . htmlspecialchars($logoFile);
}
// Cover fallback
$coverUrl = '';
$stationId = (int)($estacion['id'] ?? 0);
if ($stationId > 0) {
    $coverDir = __DIR__ . '/uploads/player/' . $stationId . '/';
    if (is_dir($coverDir)) {
        $coverFiles = glob($coverDir . 'cover_*');
        if (!empty($coverFiles)) {
            $coverUrl = '/uploads/player/' . $stationId . '/' . basename($coverFiles[0]);
        }
    }
}
if (!$coverUrl && $logoUrl) $coverUrl = $logoUrl;

// ===== API JSON: obtener metadata (evita CORS) =====
if (isset($_GET['json'])) {
    header('Content-Type: application/json');
    header('Cache-Control: no-cache');
    header('Access-Control-Allow-Origin: *');

    // Usar getStreamStatus() que ya soporta Icecast y SHOUTcast
    $status = getStreamStatus($estacion);

    echo json_encode([
        'online'    => $status['online'] ?? false,
        'title'     => $status['titulo'] ?? '',
        'listeners' => (int)($status['oyentes'] ?? 0),
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$radioName = htmlspecialchars($estacion['titulo_radio'] ?: $estacion['nombre']);
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $radioName ?> - Reproductor</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: linear-gradient(135deg, #1a1a2e 0%, #16213e 50%, #0f3460 100%);
            color: #fff;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .player-container {
            background: rgba(255,255,255,0.08);
            backdrop-filter: blur(20px);
            border-radius: 20px;
            padding: 30px;
            width: 360px;
            text-align: center;
            box-shadow: 0 10px 40px rgba(0,0,0,0.3);
        }
        .cover-wrap {
            width: 200px;
            height: 200px;
            margin: 0 auto 20px;
            border-radius: 50%;
            overflow: hidden;
            box-shadow: 0 8px 30px rgba(0,0,0,0.4);
        }
        .cover-wrap img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }
        .cover-wrap.spinning { animation: spin 8s linear infinite; }
        @keyframes spin { from { transform: rotate(0deg); } to { transform: rotate(360deg); } }
        .station-name {
            font-size: 1.3em;
            font-weight: 700;
            margin-bottom: 5px;
        }
        .now-playing {
            font-size: 0.9em;
            color: rgba(255,255,255,0.7);
            margin-bottom: 5px;
            min-height: 1.4em;
        }
        .listeners-count {
            font-size: 0.8em;
            color: rgba(255,255,255,0.5);
            margin-bottom: 20px;
        }
        .listeners-count span { font-weight: bold; color: #4fc3f7; }
        .play-btn {
            width: 64px;
            height: 64px;
            border-radius: 50%;
            border: none;
            background: linear-gradient(135deg, #4fc3f7, #0288d1);
            color: #fff;
            font-size: 24px;
            cursor: pointer;
            margin: 0 auto 15px;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: transform 0.2s, box-shadow 0.2s;
        }
        .play-btn:hover { transform: scale(1.1); box-shadow: 0 4px 20px rgba(79,195,247,0.4); }
        .volume-bar {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 0 20px;
        }
        .volume-bar input[type="range"] {
            flex: 1;
            -webkit-appearance: none;
            height: 4px;
            border-radius: 2px;
            background: rgba(255,255,255,0.2);
            outline: none;
        }
        .volume-bar input[type="range"]::-webkit-slider-thumb {
            -webkit-appearance: none;
            width: 14px; height: 14px;
            border-radius: 50%;
            background: #4fc3f7;
            cursor: pointer;
        }
        .vol-icon { font-size: 16px; opacity: 0.6; }
        .badge-live {
            display: inline-block;
            background: #e53935;
            color: #fff;
            font-size: 0.7em;
            padding: 2px 8px;
            border-radius: 10px;
            margin-bottom: 10px;
            animation: pulse-live 1.5s infinite;
        }
        .badge-live.offline { background: #666; animation: none; }
        @keyframes pulse-live {
            0%, 100% { opacity: 1; }
            50% { opacity: 0.6; }
        }
    </style>
</head>
<body>
<div class="player-container">
    <div class="badge-live" id="badgeLive">EN VIVO</div>
    <div class="cover-wrap" id="coverBox">
        <?php if ($coverUrl): ?>
            <img src="<?= htmlspecialchars($coverUrl) ?>" alt="<?= $radioName ?>" id="coverImg">
        <?php else: ?>
            <img src="data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='200' height='200'%3E%3Crect fill='%23333' width='200' height='200'/%3E%3Ctext x='50%25' y='50%25' fill='%23888' font-size='48' text-anchor='middle' dy='.35em'%3E%F0%9F%8E%B5%3C/text%3E%3C/svg%3E" alt="<?= $radioName ?>" id="coverImg">
        <?php endif; ?>
    </div>
    <div class="station-name"><?= $radioName ?></div>
    <div class="now-playing" id="nowPlaying">Cargando...</div>
    <div class="listeners-count">
        <span id="listeners">0</span> oyentes conectados
    </div>
    <button class="play-btn" id="playBtn" onclick="togglePlay()">&#9654;</button>
    <div class="volume-bar">
        <span class="vol-icon">&#128264;</span>
        <input type="range" min="0" max="100" value="80" id="volSlider" oninput="setVol(this.value)">
        <span class="vol-icon">&#128266;</span>
    </div>
</div>

<script>
    var STREAM = <?= json_encode($streamUrl) ?>;
    var META_URL = '<?= htmlspecialchars($_SERVER['SCRIPT_NAME']) ?>?s=<?= urlencode($slug) ?>&json';
    var audio = null, playing = false, vol = 80;

    var titleEl = document.getElementById('nowPlaying');
    var listenEl = document.getElementById('listeners');
    var badgeEl = document.getElementById('badgeLive');
    var coverBox = document.getElementById('coverBox');

    function togglePlay() {
        if (playing) {
            if (audio) {
                audio.pause();
                audio.removeAttribute('src');
                audio.load();
                audio = null;
            }
            playing = false;
            document.getElementById('playBtn').innerHTML = '&#9654;';
            coverBox.classList.remove('spinning');
        } else {
            audio = new Audio(STREAM);
            audio.volume = vol / 100;
            audio.play().catch(function(){});
            playing = true;
            document.getElementById('playBtn').innerHTML = '&#9724;';
            coverBox.classList.add('spinning');

            audio.onerror = function(){
                playing = false;
                document.getElementById('playBtn').innerHTML = '&#9654;';
                coverBox.classList.remove('spinning');
            };
        }
    }

    function setVol(v) {
        vol = parseInt(v);
        if (audio) audio.volume = vol / 100;
    }

    function fetchMeta() {
        fetch(META_URL)
            .then(function(r) { return r.json(); })
            .then(function(d) {
                if (d.title) titleEl.textContent = d.title;
                else titleEl.textContent = 'Sin informacion';
                listenEl.textContent = d.listeners || 0;
                if (d.online) {
                    badgeEl.textContent = 'EN VIVO';
                    badgeEl.classList.remove('offline');
                } else {
                    badgeEl.textContent = 'OFFLINE';
                    badgeEl.classList.add('offline');
                }
            })
            .catch(function() {});
    }

    fetchMeta();
    setInterval(fetchMeta, 10000);
</script>
</body>
</html>
