<?php
/**
 * CastPanel v2.1 - Widget Embebible (iframe)
 *
 * Widget compacto para insertar en sitios externos
 * Uso: <iframe src="/public/embed.php?slug=mi-radio" width="320" height="160"></iframe>
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';

$db = getDB();

$slug = $_GET['slug'] ?? '';
if (!$slug) { http_response_code(400); exit; }

$stmt = $db->prepare("
    SELECT e.*, u.activo AS usuario_activo
    FROM estaciones e
    JOIN usuarios u ON u.id = e.usuario_id
    WHERE e.slug = ? AND e.activa = 1
");
$stmt->execute([$slug]);
$estacion = $stmt->fetch();

if (!$estacion || !$estacion['usuario_activo']) {
    http_response_code(404);
    exit;
}

// Stream URL
$stmt = $db->prepare("SELECT valor FROM configuracion WHERE clave = 'ssl_domain'");
$stmt->execute();
$sslDomain = $stmt->fetchColumn() ?: 'localhost';
$port = $estacion['puerto_stream'] ?: $estacion['icecast_port'];

if ($estacion['server_type'] === 'icecast') {
    $streamUrl = "https://{$sslDomain}:{$port}{$estacion['icecast_mount']}";
} elseif ($estacion['server_type'] === 'shoutcast_v1') {
    $streamUrl = "https://{$sslDomain}:{$port}/;";
} else {
    $streamUrl = "https://{$sslDomain}:{$port}/stream/{$estacion['shoutcast_sid']}";
}

$baseUrl = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost');
$metadataUrl = $baseUrl . dirname($_SERVER['SCRIPT_NAME']) . '/api_metadata.php?slug=' . urlencode($slug);

$radioName = htmlspecialchars($estacion['titulo_radio'] ?: $estacion['nombre']);
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $radioName ?></title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, sans-serif;
            background: #1a1930;
            color: #fff;
            height: 100vh;
            overflow: hidden;
        }
        .widget {
            display: flex;
            align-items: center;
            gap: 14px;
            padding: 16px;
            height: 100%;
        }
        .cover {
            width: 80px;
            height: 80px;
            border-radius: 12px;
            background: linear-gradient(135deg, #6c3ce0, #5a2dc5);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 32px;
            flex-shrink: 0;
            overflow: hidden;
        }
        .cover img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }
        .info {
            flex: 1;
            min-width: 0;
            display: flex;
            flex-direction: column;
            gap: 4px;
        }
        .name {
            font-size: 14px;
            font-weight: 700;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        .np {
            font-size: 12px;
            color: #8b5cf6;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        .np-artist {
            font-size: 11px;
            color: #9f9caf;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        .bottom-row {
            display: flex;
            align-items: center;
            gap: 10px;
            margin-top: 6px;
        }
        .play-btn {
            width: 36px;
            height: 36px;
            border-radius: 50%;
            background: #6c3ce0;
            border: none;
            color: white;
            font-size: 14px;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
            transition: transform .1s;
        }
        .play-btn:hover { transform: scale(1.1); }
        .listeners {
            font-size: 10px;
            color: #9f9caf;
            display: flex;
            align-items: center;
            gap: 4px;
        }
        .live-dot {
            width: 5px;
            height: 5px;
            border-radius: 50%;
            background: #ef4444;
            animation: blink 1s infinite;
            display: none;
        }
        .live-dot.on { display: block; }
        @keyframes blink { 50% { opacity: .3; } }
    </style>
</head>
<body>
<div class="widget">
    <div class="cover" id="cover">&#x1F3B5;</div>
    <div class="info">
        <div class="name"><?= $radioName ?></div>
        <div class="np" id="npTitle">Cargando...</div>
        <div class="np-artist" id="npArtist"></div>
        <div class="bottom-row">
            <button class="play-btn" id="playBtn" onclick="togglePlay()">&#9654;</button>
            <span class="live-dot" id="liveDot"></span>
            <span class="listeners"><span id="lCount">0</span> oyentes</span>
        </div>
    </div>
</div>

<audio id="audio" preload="none">
    <source src="<?= htmlspecialchars($streamUrl) ?>" type="audio/mpeg">
</audio>

<script>
const audio = document.getElementById('audio');
const btn = document.getElementById('playBtn');
let playing = false;

function togglePlay() {
    if (playing) {
        audio.pause();
        audio.src = '';
        btn.innerHTML = '&#9654;';
        playing = false;
    } else {
        audio.src = <?= json_encode($streamUrl) ?>;
        audio.load();
        audio.play().catch(() => {});
        btn.innerHTML = '&#9646;&#9646;';
        playing = true;
    }
}

function fetchMeta() {
    fetch(<?= json_encode($metadataUrl) ?>)
        .then(r => r.json())
        .then(d => {
            if (d.success) {
                document.getElementById('npTitle').textContent = d.title || 'Sin info';
                document.getElementById('npArtist').textContent = d.artist || '';
                document.getElementById('lCount').textContent = d.listeners || '0';
                document.getElementById('liveDot').className = 'live-dot' + (d.online ? ' on' : '');
                if (d.cover) {
                    document.getElementById('cover').innerHTML = '<img src="' + d.cover + '" alt="">';
                }
            }
        })
        .catch(() => {});
}

audio.volume = 0.8;
fetchMeta();
setInterval(fetchMeta, 10000);
</script>
</body>
</html>
