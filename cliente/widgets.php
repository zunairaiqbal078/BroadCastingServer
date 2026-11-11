<?php
/**
 * CastPanel v2.2 - Widgets de Reproductor
 * Incluye: Player Full Page (efectos visuales), Player Compacto iframe, HTML widget, enlaces
 */
$pageTitle = 'Widget de Reproductor';
$pageIcon = 'link';
require_once __DIR__ . '/../includes/layout_header.php';

if (!$estacion) { echo '<div class="alert alert-warning">Sin estación asignada.</div>'; require_once __DIR__ . '/../includes/layout_footer.php'; exit; }

$_baseUrl = defined('BASE_URL') ? BASE_URL : '';
$streamUrl = castpanelStreamPlaybackUrl($estacion);
$baseHost = castpanelPublicPageOrigin() . $_baseUrl;
$apiUrl = $baseHost . '/api/nowplaying.php?estacion=' . (int)$estacion['id'];
$radioName = htmlspecialchars(($estacion['titulo_radio'] ?? '') ?: ($estacion['nombre'] ?? 'Radio'));
$slugEnc = rawurlencode($estacion['slug'] ?? '');
$playerFullUrl = $baseHost . '/widgets/player/?slug=' . $slugEnc;
$playerSingleUrl = $baseHost . '/widgets/player/single/?slug=' . $slugEnc;
?>

<style>
.widget-section{margin-bottom:24px}
.widget-section .card-header h3{display:flex;align-items:center;gap:8px}
.theme-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(160px,1fr));gap:12px;margin-bottom:16px}
.theme-card{
    background:var(--card-bg);border:2px solid var(--border-color);
    border-radius:12px;padding:14px;cursor:pointer;transition:all .2s;text-align:center
}
.theme-card:hover{border-color:var(--primary);transform:translateY(-2px)}
.theme-card.active{border-color:var(--primary);box-shadow:0 0 12px rgba(108,60,224,.3)}
.theme-icon{font-size:32px;margin-bottom:6px}
.theme-name{font-weight:700;font-size:13px;margin-bottom:2px}
.theme-desc{font-size:11px;color:var(--text-secondary)}
.preview-frame{
    background:#000;border-radius:12px;overflow:hidden;margin-bottom:16px;
    position:relative;border:1px solid rgba(255,255,255,.06)
}
.preview-frame iframe{display:block;width:100%;border:none}
.copy-field{display:flex;gap:8px;align-items:flex-start}
.copy-field .form-control{flex:1}
.btn-copy{
    padding:8px 14px;background:var(--primary);color:#fff;
    border:none;border-radius:var(--radius);font-weight:600;font-size:12px;
    cursor:pointer;white-space:nowrap;transition:all .2s;flex-shrink:0
}
.btn-copy:hover{filter:brightness(1.1)}
.btn-copy.copied{background:#22c55e}
.tab-btns{display:flex;gap:6px;margin-bottom:16px;flex-wrap:wrap}
.tab-btn{
    padding:6px 16px;border-radius:20px;border:1px solid var(--border-color);
    background:transparent;color:var(--text-secondary);font-size:12px;font-weight:600;
    cursor:pointer;transition:all .2s
}
.tab-btn.active{background:var(--primary);color:#fff;border-color:var(--primary)}
</style>

<div class="alert alert-info" style="margin-bottom:20px">
    <?= cpIcon('info',14) ?> Copia el código del widget que prefieras y pégalo en tu página web para que tus visitantes puedan escuchar <strong><?= $radioName ?></strong> en vivo.
</div>

<!-- ============================================ -->
<!-- Widget 1: Reproductor Full Page con Efectos  -->
<!-- ============================================ -->
<div class="card widget-section">
    <div class="card-header">
        <h3><?= cpIcon('play',18) ?> Reproductor Página Completa (con Efectos Visuales)</h3>
        <span class="badge badge-purple"><?= cpIcon('star',12) ?> Nuevo</span>
    </div>
    <div class="card-body">
        <p style="font-size:13px;color:var(--text-secondary);margin-bottom:16px">
            Reproductor profesional a página completa con animaciones de fondo, carátula, historial de canciones y controles avanzados.
            Ideal como <strong>página pública de tu radio</strong> o para abrir en ventana popup.
        </p>

        <!-- Selector de tema -->
        <label style="font-weight:700;margin-bottom:8px;display:block">Selecciona el efecto visual:</label>
        <div class="theme-grid">
            <div class="theme-card active" onclick="selectTheme('rain',this)">
                <div class="theme-icon"><?= cpIcon('signal',28) ?></div>
                <div class="theme-name">Lluvia</div>
                <div class="theme-desc">Gotas de lluvia animadas</div>
            </div>
            <div class="theme-card" onclick="selectTheme('stars',this)">
                <div class="theme-icon"><?= cpIcon('star',28) ?></div>
                <div class="theme-name">Estrellas</div>
                <div class="theme-desc">Cielo estrellado</div>
            </div>
            <div class="theme-card" onclick="selectTheme('wave',this)">
                <div class="theme-icon"><?= cpIcon('signal',28) ?></div>
                <div class="theme-name">Ondas</div>
                <div class="theme-desc">Olas del océano</div>
            </div>
            <div class="theme-card" onclick="selectTheme('equalizer',this)">
                <div class="theme-icon"><?= cpIcon('music',28) ?></div>
                <div class="theme-name">Ecualizador</div>
                <div class="theme-desc">Barras de audio</div>
            </div>
        </div>

        <!-- Preview -->
        <label style="font-weight:700;margin-bottom:8px;display:block">Vista previa:</label>
        <div class="preview-frame">
            <iframe id="previewFull" src="<?= $playerFullUrl ?>&type=rain" height="420" loading="lazy"></iframe>
        </div>

        <!-- Código -->
        <div class="form-group">
            <label>Enlace directo (para compartir en redes, web, etc.)</label>
            <div class="copy-field">
                <input type="text" id="fullPlayerUrl" class="form-control" readonly value="<?= $playerFullUrl ?>&type=rain">
                <button class="btn-copy" onclick="copyToClipboard(document.getElementById('fullPlayerUrl').value, this)"><?= cpIcon('copy',14) ?> Copiar</button>
            </div>
        </div>

        <div class="form-group">
            <label>Código iframe (para embeber en tu web)</label>
            <div class="copy-field" style="align-items:flex-start">
                <textarea id="fullPlayerIframe" class="form-control" rows="3" readonly style="font-family:monospace;font-size:12px">&lt;iframe src="<?= $playerFullUrl ?>&amp;type=rain" width="100%" height="600" frameborder="0" allow="autoplay" style="border:none;border-radius:12px"&gt;&lt;/iframe&gt;</textarea>
                <button class="btn-copy" onclick="copyToClipboard(document.getElementById('fullPlayerIframe').value, this)"><?= cpIcon('copy',14) ?> Copiar</button>
            </div>
        </div>

        <div class="form-group">
            <label>Código Popup (botón que abre ventana)</label>
            <div class="copy-field" style="align-items:flex-start">
                <textarea id="fullPlayerPopup" class="form-control" rows="3" readonly style="font-family:monospace;font-size:12px">&lt;a href="javascript:window.open('<?= $playerFullUrl ?>&amp;type=rain','CastPlayer','width=500,height=750')" style="display:inline-block;background:#6c3ce0;color:#fff;padding:12px 28px;border-radius:10px;text-decoration:none;font-weight:700;font-size:15px"&gt;&#x1F3B5; Escuchar <?= $radioName ?>&lt;/a&gt;</textarea>
                <button class="btn-copy" onclick="copyToClipboard(document.getElementById('fullPlayerPopup').value, this)"><?= cpIcon('copy',14) ?> Copiar</button>
            </div>
        </div>
    </div>
</div>

<!-- ============================================ -->
<!-- Widget 2: Reproductor Compacto (iframe 110px) -->
<!-- ============================================ -->
<div class="card widget-section">
    <div class="card-header">
        <h3><?= cpIcon('radio',18) ?> Reproductor Compacto (iframe)</h3>
        <span class="badge badge-purple"><?= cpIcon('star',12) ?> Nuevo</span>
    </div>
    <div class="card-body">
        <p style="font-size:13px;color:var(--text-secondary);margin-bottom:16px">
            Reproductor compacto de <strong>110px de altura</strong> con carátula, título, artista, indicador de estado y controles.
            Perfecto para <strong>embeber en tu página web, blog o foro</strong>.
        </p>

        <!-- Preview -->
        <label style="font-weight:700;margin-bottom:8px;display:block">Vista previa:</label>
        <div class="preview-frame" style="max-width:580px">
            <iframe src="<?= $playerSingleUrl ?>" height="110" style="width:100%"></iframe>
        </div>

        <!-- Código -->
        <div class="form-group">
            <label>Código iframe</label>
            <div class="copy-field" style="align-items:flex-start">
                <textarea id="singleIframe" class="form-control" rows="2" readonly style="font-family:monospace;font-size:12px">&lt;iframe src="<?= $playerSingleUrl ?>" height="110" width="100%" scrolling="no" style="border:none;"&gt;&lt;/iframe&gt;</textarea>
                <button class="btn-copy" onclick="copyToClipboard(document.getElementById('singleIframe').value, this)"><?= cpIcon('copy',14) ?> Copiar</button>
            </div>
        </div>

        <div class="form-group">
            <label>Código con ancho personalizado (ej: 500px)</label>
            <div class="copy-field" style="align-items:flex-start">
                <textarea id="singleIframeFixed" class="form-control" rows="2" readonly style="font-family:monospace;font-size:12px">&lt;iframe src="<?= $playerSingleUrl ?>" height="110" width="500" scrolling="no" style="border:none;"&gt;&lt;/iframe&gt;</textarea>
                <button class="btn-copy" onclick="copyToClipboard(document.getElementById('singleIframeFixed').value, this)"><?= cpIcon('copy',14) ?> Copiar</button>
            </div>
        </div>
    </div>
</div>

<!-- ============================================ -->
<!-- Widget 3: Widget HTML Simple                 -->
<!-- ============================================ -->
<div class="card widget-section">
    <div class="card-header">
        <h3><?= cpIcon('music',18) ?> Widget HTML Compacto (sin iframe)</h3>
    </div>
    <div class="card-body">
        <p style="font-size:13px;color:var(--text-secondary);margin-bottom:12px">Reproductor autocontenido que no requiere iframe. Solo copia y pega el código en tu HTML.</p>

        <!-- Preview -->
        <div style="background:#1e1b2e;padding:20px;border-radius:var(--radius);margin-bottom:16px">
            <div style="background:#2d2945;border-radius:12px;padding:16px;max-width:400px;display:flex;align-items:center;gap:12px">
                <button style="width:48px;height:48px;border-radius:50%;background:var(--primary);border:none;color:#fff;font-size:20px;cursor:pointer;flex-shrink:0" onclick="var a=document.getElementById('previewAudio');a.paused?a.play():a.pause()">&#x25B6;</button>
                <div style="flex:1;min-width:0">
                    <div style="color:#fff;font-weight:700;font-size:14px"><?= $radioName ?></div>
                    <div style="color:#9ca3af;font-size:12px">En vivo</div>
                </div>
                <div style="color:#9ca3af;font-size:11px">&#x1F50A;</div>
            </div>
            <audio id="previewAudio" src="<?= $streamUrl ?>" preload="none"></audio>
        </div>

        <!-- Código -->
        <div class="form-group">
            <label>Código HTML</label>
            <div class="copy-field" style="align-items:flex-start">
                <textarea id="widgetCode1" class="form-control" rows="8" readonly style="font-family:monospace;font-size:12px">&lt;!-- CastPanel Player Widget --&gt;
&lt;div id="castpanel-player" style="font-family:Arial,sans-serif;background:#1e1b2e;border-radius:12px;padding:16px;max-width:400px;display:flex;align-items:center;gap:12px"&gt;
  &lt;button onclick="var a=document.getElementById('cp-audio');a.paused?a.play():a.pause();this.textContent=a.paused?'&#x25B6;':'&#x23F8;'" style="width:48px;height:48px;border-radius:50%;background:#6c3ce0;border:none;color:#fff;font-size:20px;cursor:pointer"&gt;&#x25B6;&lt;/button&gt;
  &lt;div style="flex:1"&gt;
    &lt;div style="color:#fff;font-weight:700;font-size:14px"&gt;<?= $radioName ?>&lt;/div&gt;
    &lt;div id="cp-title" style="color:#9ca3af;font-size:12px"&gt;En vivo&lt;/div&gt;
  &lt;/div&gt;
  &lt;input type="range" min="0" max="100" value="80" oninput="document.getElementById('cp-audio').volume=this.value/100" style="width:60px"&gt;
&lt;/div&gt;
&lt;audio id="cp-audio" src="<?= $streamUrl ?>" preload="none"&gt;&lt;/audio&gt;</textarea>
                <button class="btn-copy" onclick="copyToClipboard(document.getElementById('widgetCode1').value, this)"><?= cpIcon('copy',14) ?> Copiar</button>
            </div>
        </div>
    </div>
</div>

<!-- ============================================ -->
<!-- Widget 4: Enlaces Directos                   -->
<!-- ============================================ -->
<div class="card widget-section">
    <div class="card-header">
        <h3><?= cpIcon('link',18) ?> Enlaces Directos al Stream</h3>
    </div>
    <div class="card-body">
        <div class="form-group">
            <label>URL del Stream (HTTPS)</label>
            <div class="copy-field">
                <input type="text" id="streamDirectUrl" class="form-control" readonly value="<?= $streamUrl ?>">
                <button class="btn-copy" onclick="copyToClipboard(document.getElementById('streamDirectUrl').value, this)"><?= cpIcon('copy',14) ?> Copiar</button>
            </div>
        </div>

        <div class="form-group">
            <label>Archivo M3U (para reproductores de escritorio)</label>
            <div class="copy-field">
                <input type="text" id="m3uUrl" class="form-control" readonly value="<?= $baseHost ?>/api/stream.php?format=m3u&station=<?= $estacion['slug'] ?>">
                <button class="btn-copy" onclick="copyToClipboard(document.getElementById('m3uUrl').value, this)"><?= cpIcon('copy',14) ?> Copiar</button>
            </div>
        </div>

        <div class="form-group">
            <label>Archivo PLS (compatible con Winamp, VLC, etc.)</label>
            <div class="copy-field">
                <input type="text" id="plsUrl" class="form-control" readonly value="<?= $baseHost ?>/api/stream.php?format=pls&station=<?= $estacion['slug'] ?>">
                <button class="btn-copy" onclick="copyToClipboard(document.getElementById('plsUrl').value, this)"><?= cpIcon('copy',14) ?> Copiar</button>
            </div>
        </div>
    </div>
</div>

<script>
var selectedTheme = 'rain';
var playerBaseUrl = '<?= $playerFullUrl ?>';

function selectTheme(theme, el) {
    selectedTheme = theme;
    document.querySelectorAll('.theme-card').forEach(function(c){ c.classList.remove('active'); });
    el.classList.add('active');

    var fullUrl = playerBaseUrl + '&type=' + theme;
    document.getElementById('previewFull').src = fullUrl;
    document.getElementById('fullPlayerUrl').value = fullUrl;
    document.getElementById('fullPlayerIframe').value = '<iframe src="' + fullUrl.replace(/&/g,'&amp;') + '" width="100%" height="600" frameborder="0" allow="autoplay" style="border:none;border-radius:12px"></iframe>';
    document.getElementById('fullPlayerPopup').value = '<a href="javascript:window.open(\'' + fullUrl.replace(/&/g,'&amp;') + '\',\'CastPlayer\',\'width=500,height=750\')" style="display:inline-block;background:#6c3ce0;color:#fff;padding:12px 28px;border-radius:10px;text-decoration:none;font-weight:700;font-size:15px">\u{1F3B5} Escuchar <?= addslashes($radioName) ?></a>';
}

function copyToClipboard(text, btn) {
    navigator.clipboard.writeText(text).then(function(){
        var orig = btn.innerHTML;
        btn.innerHTML = '&#x2705; Copiado';
        btn.classList.add('copied');
        setTimeout(function(){
            btn.innerHTML = orig;
            btn.classList.remove('copied');
        }, 2000);
    }).catch(function(){
        // Fallback
        var ta = document.createElement('textarea');
        ta.value = text;
        document.body.appendChild(ta);
        ta.select();
        document.execCommand('copy');
        document.body.removeChild(ta);
        var orig = btn.innerHTML;
        btn.innerHTML = '&#x2705; Copiado';
        btn.classList.add('copied');
        setTimeout(function(){
            btn.innerHTML = orig;
            btn.classList.remove('copied');
        }, 2000);
    });
}
</script>

<?php require_once __DIR__ . '/../includes/layout_footer.php'; ?>
