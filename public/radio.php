<?php
/**
 * CastPanel v2.1 - Reproductor Público de Radio
 *
 * Página pública para cada estación: /public/radio.php?slug=mi-radio
 * Muestra reproductor con metadata en vivo, carátula y código de embed
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';

// Verificar que la funcionalidad está habilitada
$db = getDB();
$stmt = $db->prepare("SELECT valor FROM configuracion WHERE clave = 'public_player'");
$stmt->execute();
if ($stmt->fetchColumn() !== '1') {
    http_response_code(404);
    echo 'Reproductor público deshabilitado';
    exit;
}

// Obtener estación por slug
$slug = $_GET['slug'] ?? '';
if (!$slug) {
    http_response_code(400);
    echo 'Estación no especificada';
    exit;
}

$stmt = $db->prepare("
    SELECT e.*, p.nombre AS paquete_nombre, p.max_oyentes,
           u.nombre AS propietario
    FROM estaciones e
    LEFT JOIN paquetes p ON p.id = e.paquete_id
    LEFT JOIN usuarios u ON u.id = e.usuario_id
    WHERE e.slug = ? AND e.activa = 1
");
$stmt->execute([$slug]);
$estacion = $stmt->fetch();

if (!$estacion) {
    http_response_code(404);
    echo 'Estación no encontrada';
    exit;
}

// Verificar que el usuario está activo
$stmt = $db->prepare("SELECT activo FROM usuarios WHERE id = ?");
$stmt->execute([$estacion['usuario_id']]);
$usuario = $stmt->fetch();
if (!$usuario || !$usuario['activo']) {
    http_response_code(404);
    echo 'Estación no disponible';
    exit;
}

// Construir URL del stream
$sslDomain = '';
$stmt = $db->prepare("SELECT valor FROM configuracion WHERE clave = 'ssl_domain'");
$stmt->execute();
$sslDomain = $stmt->fetchColumn() ?: 'localhost';

// Construir URL del stream via reverse proxy (HTTPS)
if (in_array($estacion['server_type'] ?? '', ['shoutcast_v1', 'shoutcast_v2', 'shoutcast_v25'])) {
    // SHOUTcast: usar proxy Apache /shoutcast/{slug}
    $streamUrl = "https://{$sslDomain}/shoutcast/{$estacion['slug']}";
    $streamUrlHttp = "https://{$sslDomain}/shoutcast/{$estacion['slug']}";
} else {
    // Icecast: usar proxy Apache /live/{mount}
    $streamUrl = "https://{$sslDomain}/live/{$estacion['slug']}";
    $streamUrlHttp = "https://{$sslDomain}/live/{$estacion['slug']}";
}

// URL del embed
$baseUrl = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost');
$embedUrl = $baseUrl . dirname($_SERVER['SCRIPT_NAME']) . '/embed.php?slug=' . urlencode($slug);

// Metadata API (para AJAX polling)
$metadataUrl = $baseUrl . dirname($_SERVER['SCRIPT_NAME']) . '/api_metadata.php?slug=' . urlencode($slug);

$radioName = htmlspecialchars($estacion['titulo_radio'] ?: $estacion['nombre']);
$radioGenre = htmlspecialchars($estacion['genero'] ?? 'Variado');
$radioDesc = htmlspecialchars($estacion['descripcion'] ?? '');
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $radioName ?> - Escuchar en vivo</title>
    <meta name="description" content="<?= $radioDesc ?: "Escucha $radioName en vivo" ?>">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        :root {
            --primary: #6c3ce0;
            --primary-dark: #5a2dc5;
            --primary-light: #8b5cf6;
            --bg-dark: #0f0e17;
            --bg-card: #1a1930;
            --bg-card-light: #232042;
            --text-white: #ffffff;
            --text-muted: #9f9caf;
            --border: rgba(108,60,224,.25);
            --radius: 16px;
            --glow: 0 0 30px rgba(108,60,224,.3);
        }

        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

        body {
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, sans-serif;
            background: var(--bg-dark);
            color: var(--text-white);
            min-height: 100vh;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }

        .player-container {
            width: 100%;
            max-width: 480px;
            background: var(--bg-card);
            border-radius: var(--radius);
            overflow: hidden;
            box-shadow: var(--glow);
            border: 1px solid var(--border);
        }

        /* Cover art */
        .cover-area {
            position: relative;
            width: 100%;
            aspect-ratio: 1;
            background: linear-gradient(135deg, #1a1930 0%, #2d1b69 50%, #1a1930 100%);
            display: flex;
            align-items: center;
            justify-content: center;
            overflow: hidden;
        }

        .cover-area img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }

        .cover-placeholder {
            width: 120px;
            height: 120px;
            border-radius: 50%;
            background: linear-gradient(135deg, var(--primary), var(--primary-dark));
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 48px;
            animation: pulse-glow 2s ease-in-out infinite;
        }

        @keyframes pulse-glow {
            0%, 100% { box-shadow: 0 0 20px rgba(108,60,224,.4); }
            50% { box-shadow: 0 0 40px rgba(108,60,224,.7); }
        }

        .live-badge {
            position: absolute;
            top: 16px;
            right: 16px;
            background: #ef4444;
            color: white;
            font-size: 11px;
            font-weight: 700;
            padding: 4px 12px;
            border-radius: 20px;
            letter-spacing: 1px;
            text-transform: uppercase;
            display: none;
        }

        .live-badge.visible { display: flex; align-items: center; gap: 6px; }
        .live-dot { width: 6px; height: 6px; border-radius: 50%; background: white; animation: blink 1s ease-in-out infinite; }
        @keyframes blink { 0%, 100% { opacity: 1; } 50% { opacity: .3; } }

        /* Info area */
        .info-area {
            padding: 24px;
        }

        .station-name {
            font-size: 22px;
            font-weight: 700;
            margin-bottom: 4px;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .station-genre {
            color: var(--primary-light);
            font-size: 13px;
            font-weight: 500;
            margin-bottom: 16px;
        }

        .now-playing {
            background: var(--bg-card-light);
            border-radius: 12px;
            padding: 14px 16px;
            margin-bottom: 20px;
        }

        .now-playing-label {
            font-size: 10px;
            font-weight: 600;
            color: var(--text-muted);
            text-transform: uppercase;
            letter-spacing: 1px;
            margin-bottom: 6px;
        }

        .now-playing-title {
            font-size: 15px;
            font-weight: 600;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .now-playing-artist {
            font-size: 13px;
            color: var(--text-muted);
            margin-top: 2px;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        /* Controls */
        .controls {
            display: flex;
            align-items: center;
            gap: 16px;
        }

        .play-btn {
            width: 56px;
            height: 56px;
            border-radius: 50%;
            background: linear-gradient(135deg, var(--primary), var(--primary-dark));
            border: none;
            color: white;
            font-size: 22px;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: transform .15s, box-shadow .15s;
            flex-shrink: 0;
        }

        .play-btn:hover { transform: scale(1.08); box-shadow: 0 0 20px rgba(108,60,224,.5); }
        .play-btn:active { transform: scale(.95); }

        .volume-control {
            flex: 1;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .volume-icon { font-size: 18px; cursor: pointer; flex-shrink: 0; }

        .volume-slider {
            flex: 1;
            -webkit-appearance: none;
            appearance: none;
            height: 4px;
            background: var(--bg-card-light);
            border-radius: 2px;
            outline: none;
        }

        .volume-slider::-webkit-slider-thumb {
            -webkit-appearance: none;
            width: 14px;
            height: 14px;
            border-radius: 50%;
            background: var(--primary-light);
            cursor: pointer;
        }

        .volume-slider::-moz-range-thumb {
            width: 14px;
            height: 14px;
            border-radius: 50%;
            background: var(--primary-light);
            cursor: pointer;
            border: none;
        }

        /* Stats */
        .stats-bar {
            display: flex;
            justify-content: space-between;
            padding: 16px 24px;
            border-top: 1px solid rgba(255,255,255,.06);
            font-size: 12px;
            color: var(--text-muted);
        }

        .stat { display: flex; align-items: center; gap: 5px; }
        .stat-icon { font-size: 14px; }

        /* Embed section */
        .embed-section {
            padding: 0 24px 24px;
        }

        .embed-toggle {
            background: none;
            border: 1px solid var(--border);
            color: var(--text-muted);
            font-size: 12px;
            padding: 8px 16px;
            border-radius: 8px;
            cursor: pointer;
            width: 100%;
            font-family: inherit;
            transition: all .2s;
        }

        .embed-toggle:hover { color: var(--text-white); border-color: var(--primary); }

        .embed-code {
            display: none;
            margin-top: 12px;
            background: var(--bg-card-light);
            border-radius: 8px;
            padding: 12px;
        }

        .embed-code.visible { display: block; }

        .embed-code textarea {
            width: 100%;
            background: transparent;
            border: none;
            color: var(--primary-light);
            font-family: 'JetBrains Mono', 'Fira Code', monospace;
            font-size: 11px;
            resize: none;
            outline: none;
            height: 60px;
        }

        .embed-copy {
            margin-top: 8px;
            background: var(--primary);
            border: none;
            color: white;
            font-size: 12px;
            font-weight: 600;
            padding: 6px 14px;
            border-radius: 6px;
            cursor: pointer;
            font-family: inherit;
        }

        .embed-copy:hover { background: var(--primary-dark); }

        /* Footer */
        .powered-by {
            margin-top: 24px;
            font-size: 11px;
            color: var(--text-muted);
            text-align: center;
        }

        .powered-by a { color: var(--primary-light); }

        /* Responsive */
        @media (max-width: 520px) {
            body { padding: 12px; }
            .player-container { border-radius: 12px; }
            .info-area { padding: 18px; }
            .station-name { font-size: 18px; }
        }
    </style>
</head>
<body>

<div class="player-container">
    <!-- Cover Art -->
    <div class="cover-area">
        <div class="cover-placeholder" id="coverPlaceholder">&#x1F3B5;</div>
        <img id="coverImage" style="display:none" alt="Cover">
        <div class="live-badge" id="liveBadge">
            <span class="live-dot"></span> EN VIVO
        </div>
    </div>

    <!-- Info -->
    <div class="info-area">
        <div class="station-name"><?= $radioName ?></div>
        <div class="station-genre"><?= $radioGenre ?></div>

        <div class="now-playing">
            <div class="now-playing-label">Reproduciendo ahora</div>
            <div class="now-playing-title" id="npTitle">Cargando...</div>
            <div class="now-playing-artist" id="npArtist"></div>
        </div>

        <!-- Controls -->
        <div class="controls">
            <button class="play-btn" id="playBtn" onclick="togglePlay()">&#9654;</button>
            <div class="volume-control">
                <span class="volume-icon" id="volIcon" onclick="toggleMute()">&#x1F50A;</span>
                <input type="range" class="volume-slider" id="volumeSlider" min="0" max="100" value="80" oninput="changeVolume(this.value)">
            </div>
        </div>
    </div>

    <!-- Stats -->
    <div class="stats-bar">
        <div class="stat">
            <span class="stat-icon">&#x1F464;</span>
            <span id="listeners">0</span> oyentes
        </div>
        <div class="stat">
            <span class="stat-icon">&#x1F4FB;</span>
            <?= $estacion['bitrate'] ?> kbps <?= strtoupper($estacion['codec']) ?>
        </div>
        <div class="stat">
            <span class="stat-icon">&#x2699;</span>
            <?= ucfirst($estacion['server_type'] === 'shoutcast_v1' ? 'SHOUTcast' : ($estacion['server_type'] === 'shoutcast_v2' ? 'SHOUTcast v2' : 'Icecast')) ?>
        </div>
    </div>

    <!-- Embed -->
    <div class="embed-section">
        <button class="embed-toggle" onclick="toggleEmbed()">&#x1F517; Obtener c&oacute;digo de inserci&oacute;n</button>
        <div class="embed-code" id="embedCode">
            <textarea readonly id="embedTextarea">&lt;iframe src="<?= htmlspecialchars($embedUrl) ?>" width="320" height="160" frameborder="0" allow="autoplay" style="border-radius:12px"&gt;&lt;/iframe&gt;</textarea>
            <button class="embed-copy" onclick="copyEmbed()">Copiar c&oacute;digo</button>
        </div>
    </div>
</div>

<div class="powered-by">
    Powered by <a href="#">CastPanel</a>
</div>

<audio id="audioPlayer" preload="none">
    <source src="<?= htmlspecialchars($streamUrl) ?>" type="audio/mpeg">
    <source src="<?= htmlspecialchars($streamUrlHttp) ?>" type="audio/mpeg">
</audio>

<script>
const audio = document.getElementById('audioPlayer');
const playBtn = document.getElementById('playBtn');
const liveBadge = document.getElementById('liveBadge');
let isPlaying = false;
let lastVolume = 80;

function togglePlay() {
    if (isPlaying) {
        audio.pause();
        audio.src = '';
        playBtn.innerHTML = '&#9654;';
        isPlaying = false;
    } else {
        // Recargar para obtener stream fresco
        audio.src = <?= json_encode($streamUrl) ?>;
        audio.load();
        audio.play().catch(function() {
            // Intentar con HTTP si HTTPS falla
            audio.src = <?= json_encode($streamUrlHttp) ?>;
            audio.load();
            audio.play();
        });
        playBtn.innerHTML = '&#9646;&#9646;';
        isPlaying = true;
    }
}

function changeVolume(val) {
    audio.volume = val / 100;
    lastVolume = val;
    updateVolIcon(val);
}

function toggleMute() {
    const slider = document.getElementById('volumeSlider');
    if (audio.volume > 0) {
        lastVolume = slider.value;
        audio.volume = 0;
        slider.value = 0;
        updateVolIcon(0);
    } else {
        audio.volume = lastVolume / 100;
        slider.value = lastVolume;
        updateVolIcon(lastVolume);
    }
}

function updateVolIcon(val) {
    const icon = document.getElementById('volIcon');
    if (val == 0) icon.innerHTML = '&#x1F507;';
    else if (val < 50) icon.innerHTML = '&#x1F509;';
    else icon.innerHTML = '&#x1F50A;';
}

function toggleEmbed() {
    document.getElementById('embedCode').classList.toggle('visible');
}

function copyEmbed() {
    const textarea = document.getElementById('embedTextarea');
    textarea.select();
    document.execCommand('copy');
    const btn = event.target;
    btn.textContent = '¡Copiado!';
    setTimeout(() => btn.textContent = 'Copiar código', 2000);
}

// Polling de metadata
function fetchMetadata() {
    fetch(<?= json_encode($metadataUrl) ?>)
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                document.getElementById('npTitle').textContent = data.title || 'Sin información';
                document.getElementById('npArtist').textContent = data.artist || '';
                document.getElementById('listeners').textContent = data.listeners || '0';

                if (data.cover) {
                    document.getElementById('coverImage').src = data.cover;
                    document.getElementById('coverImage').style.display = 'block';
                    document.getElementById('coverPlaceholder').style.display = 'none';
                }

                if (data.online) {
                    liveBadge.classList.add('visible');
                } else {
                    liveBadge.classList.remove('visible');
                }
            }
        })
        .catch(() => {});
}

// Init
audio.volume = 0.8;
fetchMetadata();
setInterval(fetchMetadata, 10000);
</script>

</body>
</html>
