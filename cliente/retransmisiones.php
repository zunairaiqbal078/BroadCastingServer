<?php
/**
 * CastPanel — Transmision en Vivo (una plataforma por pagina)
 * Estilo SonicPanel: retransmisiones.php?plat=facebook|youtube|tiktok|instagram
 */
$validPlats = ['facebook','youtube','tiktok','instagram','custom'];
$plat = $_GET['plat'] ?? 'facebook';
if (!in_array($plat, $validPlats)) $plat = 'facebook';

$platTitles = [
    'facebook'  => 'Transmision en Vivo Facebook',
    'youtube'   => 'Transmision en Vivo Youtube',
    'tiktok'    => 'Transmision en Vivo TikTok',
    'instagram' => 'Transmision en Vivo Instagram',
    'custom'    => 'Transmision Personalizada',
];
$platIcons = [
    'facebook'  => '<svg width="24" height="24" viewBox="0 0 24 24" fill="#1877F2"><path d="M24 12.073c0-6.627-5.373-12-12-12s-12 5.373-12 12c0 5.99 4.388 10.954 10.125 11.854v-8.385H7.078v-3.47h3.047V9.43c0-3.007 1.792-4.669 4.533-4.669 1.312 0 2.686.235 2.686.235v2.953H15.83c-1.491 0-1.956.925-1.956 1.874v2.25h3.328l-.532 3.47h-2.796v8.385C19.612 23.027 24 18.062 24 12.073z"/></svg>',
    'youtube'   => '<svg width="24" height="24" viewBox="0 0 24 24" fill="#FF0000"><path d="M23.498 6.186a3.016 3.016 0 0 0-2.122-2.136C19.505 3.545 12 3.545 12 3.545s-7.505 0-9.377.505A3.017 3.017 0 0 0 .502 6.186C0 8.07 0 12 0 12s0 3.93.502 5.814a3.016 3.016 0 0 0 2.122 2.136c1.871.505 9.376.505 9.376.505s7.505 0 9.377-.505a3.015 3.015 0 0 0 2.122-2.136C24 15.93 24 12 24 12s0-3.93-.502-5.814zM9.545 15.568V8.432L15.818 12l-6.273 3.568z"/></svg>',
    'tiktok'    => '<svg width="24" height="24" viewBox="0 0 24 24" fill="#000000"><path d="M12.525.02c1.31-.02 2.61-.01 3.91-.02.08 1.53.63 3.09 1.75 4.17 1.12 1.11 2.7 1.62 4.24 1.79v4.03c-1.44-.05-2.89-.35-4.2-.97-.57-.26-1.1-.59-1.62-.93-.01 2.92.01 5.84-.02 8.75-.08 1.4-.54 2.79-1.35 3.94-1.31 1.92-3.58 3.17-5.91 3.21-1.43.08-2.86-.31-4.08-1.03-2.02-1.19-3.44-3.37-3.65-5.71-.02-.5-.03-1-.01-1.49.18-1.9 1.12-3.72 2.58-4.96 1.66-1.44 3.98-2.13 6.15-1.72.02 1.48-.04 2.96-.04 4.44-.99-.32-2.15-.23-3.02.37-.63.41-1.11 1.04-1.36 1.75-.21.51-.15 1.07-.14 1.61.24 1.64 1.82 3.02 3.5 2.87 1.12-.01 2.19-.66 2.77-1.61.19-.33.4-.67.41-1.06.1-1.79.06-3.57.07-5.36.01-4.03-.01-8.05.02-12.07z"/></svg>',
    'instagram' => '<svg width="24" height="24" viewBox="0 0 24 24" fill="#E4405F"><path d="M12 0C8.74 0 8.333.015 7.053.072 5.775.132 4.905.333 4.14.63c-.789.306-1.459.717-2.126 1.384S.935 3.35.63 4.14C.333 4.905.131 5.775.072 7.053.012 8.333 0 8.74 0 12s.015 3.667.072 4.947c.06 1.277.261 2.148.558 2.913.306.788.717 1.459 1.384 2.126.667.666 1.336 1.079 2.126 1.384.766.296 1.636.499 2.913.558C8.333 23.988 8.74 24 12 24s3.667-.015 4.947-.072c1.277-.06 2.148-.262 2.913-.558.788-.306 1.459-.718 2.126-1.384.666-.667 1.079-1.335 1.384-2.126.296-.765.499-1.636.558-2.913.06-1.28.072-1.687.072-4.947s-.015-3.667-.072-4.947c-.06-1.277-.262-2.149-.558-2.913-.306-.789-.718-1.459-1.384-2.126C21.319 1.347 20.651.935 19.86.63c-.765-.297-1.636-.499-2.913-.558C15.667.012 15.26 0 12 0zm0 2.16c3.203 0 3.585.016 4.85.071 1.17.055 1.805.249 2.227.415.562.217.96.477 1.382.896.419.42.679.819.896 1.381.164.422.36 1.057.413 2.227.057 1.266.07 1.646.07 4.85s-.015 3.585-.074 4.85c-.061 1.17-.256 1.805-.421 2.227-.224.562-.479.96-.899 1.382-.419.419-.824.679-1.38.896-.42.164-1.065.36-2.235.413-1.274.057-1.649.07-4.859.07-3.211 0-3.586-.015-4.859-.074-1.171-.061-1.816-.256-2.236-.421-.569-.224-.96-.479-1.379-.899-.421-.419-.69-.824-.9-1.38-.165-.42-.359-1.065-.42-2.235-.045-1.26-.061-1.649-.061-4.844 0-3.196.016-3.586.061-4.861.061-1.17.255-1.814.42-2.234.21-.57.479-.96.9-1.381.419-.419.81-.689 1.379-.898.42-.166 1.051-.361 2.221-.421 1.275-.045 1.65-.06 4.859-.06l.045.03zm0 3.678a6.162 6.162 0 1 0 0 12.324 6.162 6.162 0 1 0 0-12.324zM12 16c-2.21 0-4-1.79-4-4s1.79-4 4-4 4 1.79 4 4-1.79 4-4 4zm7.846-10.405a1.441 1.441 0 1 1-2.882 0 1.441 1.441 0 0 1 2.882 0z"/></svg>',
    'custom'    => '<svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="#555" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83-2.83l.06-.06A1.65 1.65 0 0 0 4.68 15a1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 2.83-2.83l.06.06A1.65 1.65 0 0 0 9 4.68a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 2.83l-.06.06A1.65 1.65 0 0 0 19.4 9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z"/></svg>',
];

$pageTitle = $platTitles[$plat];
$pageIcon  = 'satellite';
require_once __DIR__ . '/../includes/layout_header.php';
require_once __DIR__ . '/../includes/restream_functions.php';

$platforms = getRestreamPlatforms();
$currentPlat = $platforms[$plat] ?? $platforms['custom'];
$icon = $platIcons[$plat] ?? '';
?>

<style>
/* ═══ SonicPanel Style — Single platform page ═══ */
.sp-title {
    font-size: 17px;
    font-weight: 600;
    color: #333;
    margin: 0 0 18px 0;
    display: flex;
    align-items: center;
    gap: 8px;
}
.sp-title-emoji {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 24px;
    height: 24px;
    flex-shrink: 0;
}
.sp-title-emoji svg {
    width: 24px;
    height: 24px;
}

/* Info box (blue) */
.sp-info-box {
    background: #d9edf7;
    border-left: 4px solid #31708f;
    padding: 14px 18px;
    margin-bottom: 22px;
    color: #31708f;
    font-size: 13px;
    line-height: 1.65;
    display: flex;
    gap: 12px;
    align-items: flex-start;
    border-radius: 0 3px 3px 0;
}
.sp-info-box a { color: #245269; text-decoration: underline; }
.sp-info-icon {
    width: 24px; height: 24px;
    background: #31708f;
    border-radius: 50%;
    display: flex; align-items: center; justify-content: center;
    color: #fff; font-weight: bold; font-size: 14px;
    flex-shrink: 0; margin-top: 1px;
}

/* Warning box (orange — YouTube) */
.sp-warning-box {
    background: #fcf8e3;
    border-left: 4px solid #f0ad4e;
    padding: 12px 18px;
    color: #8a6d3b;
    font-size: 13px;
    line-height: 1.5;
    margin-top: 22px;
    border-radius: 0 3px 3px 0;
    display: flex;
    gap: 12px;
    align-items: flex-start;
}
.sp-warning-icon {
    width: 24px; height: 24px;
    background: #f0ad4e;
    border-radius: 50%;
    display: flex; align-items: center; justify-content: center;
    color: #fff; font-weight: bold; font-size: 14px;
    flex-shrink: 0;
}
.sp-warning-box ul { margin: 5px 0 0 0; padding-left: 18px; }
.sp-warning-box li { margin-bottom: 3px; }

/* ── RTMP URL field (custom only) ── */
.sp-field { margin-bottom: 16px; }
.sp-field label {
    display: block; font-size: 13px; font-weight: 600;
    color: #333; margin-bottom: 5px;
}
.sp-field input {
    width: 100%; padding: 7px 12px;
    border: 1px solid #ccc; border-radius: 4px;
    font-size: 13px; font-family: inherit; color: #333;
    background: #fff; outline: none; box-sizing: border-box;
}
.sp-field input:focus {
    border-color: #66afe9;
    box-shadow: 0 0 5px rgba(102,175,233,.5);
}
.sp-field .sp-help { font-size: 12px; color: #999; margin-top: 4px; }

/* ── Control row: key + buttons + status ── */
.sp-control-row {
    display: flex;
    align-items: center;
    gap: 8px;
    margin-bottom: 22px;
    flex-wrap: wrap;
}
.sp-key-input {
    flex: 1; min-width: 200px;
    padding: 7px 12px;
    border: 1px solid #ccc; border-radius: 4px;
    font-size: 13px;
    font-family: 'Consolas','Monaco','Courier New',monospace;
    color: #555; background: #fff; outline: none;
}
.sp-key-input:focus {
    border-color: #66afe9;
    box-shadow: 0 0 5px rgba(102,175,233,.5);
}
.sp-key-input:read-only { background: #f5f5f5; color: #888; }

/* Buttons */
.sp-btn {
    padding: 7px 18px; border-radius: 4px;
    font-size: 13px; font-weight: 600;
    cursor: pointer; border: 1px solid;
    display: inline-flex; align-items: center; gap: 5px;
    transition: background .15s; font-family: inherit;
    white-space: nowrap;
}
.sp-btn:disabled { opacity: .5; cursor: not-allowed; }
.sp-btn-iniciar  { background: #5cb85c; color: #fff; border-color: #4cae4c; }
.sp-btn-iniciar:hover:not(:disabled) { background: #449d44; }
.sp-btn-detener  { background: #555; color: #fff; border-color: #444; }
.sp-btn-detener:hover:not(:disabled) { background: #333; }

.sp-status-text {
    font-size: 13px; color: #333; margin-left: 6px; white-space: nowrap;
}
.sp-status-disabled { color: #999; }
.sp-status-live     { color: #3c763d; font-weight: 700; }
.sp-status-error    { color: #a94442; font-weight: 700; }

/* Spinner */
.sp-spinner {
    display: inline-block; width: 14px; height: 14px;
    border: 2px solid rgba(255,255,255,.3);
    border-top-color: #fff; border-radius: 50%;
    animation: sp-spin .6s linear infinite;
}
@keyframes sp-spin { to { transform: rotate(360deg); } }

/* ── Image row ── */
.sp-image-row {
    display: flex; gap: 22px; flex-wrap: wrap;
}
.sp-image-preview {
    width: 380px; max-width: 100%;
    aspect-ratio: 16/9;
    background: #e8e8e8;
    border: 1px solid #ddd; border-radius: 3px;
    display: flex; align-items: center; justify-content: center;
    overflow: hidden;
    font-size: 22px; color: #aaa; font-weight: 300;
}
.sp-image-preview img {
    width: 100%; height: 100%; object-fit: cover;
}
.sp-upload-zone {
    flex: 1; min-width: 260px; min-height: 200px;
    border: 2px dashed #b8daff; border-radius: 3px;
    display: flex; flex-direction: column;
    align-items: center; justify-content: center;
    cursor: pointer; color: #999; font-size: 13px;
    text-align: center; padding: 22px;
    transition: border-color .2s, background .2s;
}
.sp-upload-zone:hover { border-color: #66b3ff; background: #f8fbff; }
.sp-upload-zone svg {
    width: 40px; height: 40px;
    stroke: #ccc; fill: none;
    stroke-width: 1.5; stroke-linecap: round; stroke-linejoin: round;
    margin-bottom: 10px;
}
.sp-upload-zone p { margin: 3px 0; }
.sp-upload-zone .sp-upload-note { font-size: 12px; color: #bbb; }

/* Error */
.sp-error-text {
    color: #a94442; font-size: 13px; margin-top: 14px;
    padding: 8px 14px; background: #f2dede; border-radius: 3px;
    border-left: 3px solid #d9534f;
}

/* Toast */
.sp-toast {
    position: fixed; bottom: 20px; right: 20px;
    padding: 10px 18px; border-radius: 3px;
    color: #fff; font-size: 13px; font-weight: 500;
    z-index: 2000; opacity: 0; transform: translateY(8px);
    transition: all .3s; max-width: 340px;
}
.sp-toast.show { opacity: 1; transform: translateY(0); }
.sp-toast-ok    { background: #5cb85c; }
.sp-toast-error { background: #d9534f; }

/* Responsive */
@media (max-width: 640px) {
    .sp-image-row { flex-direction: column; }
    .sp-image-preview { width: 100%; }
    .sp-control-row { flex-direction: column; align-items: stretch; }
    .sp-control-row .sp-status-text { text-align: center; }
}
</style>

<!-- ═══ Platform Title ═══ -->
<h2 class="sp-title">
    <span class="sp-title-emoji"><?= $icon ?></span>
    <?= htmlspecialchars($platTitles[$plat]) ?>
</h2>

<!-- ═══ Info Box (blue) ═══ -->
<div class="sp-info-box">
    <div class="sp-info-icon">i</div>
    <div id="spInfoText"></div>
</div>

<?php if ($plat === 'custom'): ?>
<!-- RTMP URL (custom only) -->
<div class="sp-field">
    <label>URL RTMP del servidor</label>
    <input type="text" id="spRtmpUrl" placeholder="rtmp://tu-servidor.com/live/">
    <div class="sp-help">Ingresa la URL RTMP de tu servidor personalizado</div>
</div>
<?php endif; ?>

<!-- ═══ Control Row: key + Iniciar + Detener + Status ═══ -->
<div class="sp-control-row">
    <input type="text" class="sp-key-input" id="spStreamKey"
           placeholder="Pega tu clave de transmision aqui...">
    <button class="sp-btn sp-btn-iniciar" id="btnIniciar" data-action="iniciar">Iniciar</button>
    <button class="sp-btn sp-btn-detener" id="btnDetener" data-action="detener" disabled>Detener</button>
    <span class="sp-status-text">Status: <strong id="spStatusLabel" class="sp-status-disabled">Disabled</strong></span>
</div>

<!-- ═══ Image Row: preview + upload ═══ -->
<div class="sp-image-row">
    <div class="sp-image-preview" id="spPreview">1280 x 720</div>
    <div class="sp-upload-zone" id="spUploadZone" data-action="upload">
        <svg viewBox="0 0 24 24" stroke-linecap="round" stroke-linejoin="round">
            <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/>
            <polyline points="17 8 12 3 7 8"/>
            <line x1="12" y1="3" x2="12" y2="15"/>
        </svg>
        <p>Drop the picture file here to upload, or click.</p>
        <p class="sp-upload-note">Only .jpg or .png allowed! Max 3MB.</p>
    </div>
</div>

<?php if ($plat === 'youtube'): ?>
<!-- ═══ YouTube Warning (orange) ═══ -->
<div class="sp-warning-box">
    <div class="sp-warning-icon">!</div>
    <div>
        <ul>
            <li>Youtube tiene sanciones por derechos de autor para transmisiones de musica en vivo, tu canal de Youtube completo puede ser <strong>baneado</strong> o limitado si tu radio reproduce musica con derechos de autor.</li>
            <li>Tu empresa de hosting y la empresa de software del panel de control no son responsables en tales casos.</li>
        </ul>
    </div>
</div>
<?php endif; ?>

<!-- Error display -->
<div class="sp-error-text" id="spError" style="display:none"></div>

<!-- Toast -->
<div class="sp-toast" id="spToast"></div>

<!-- Hidden file input -->
<input type="file" id="spCoverInput" accept="image/jpeg,image/png,image/webp" style="display:none">

<script>
(function() {
    'use strict';

    var API  = '<?= rtrim(BASE_URL, "/") ?>/api/restream.php';
    var PLAT = '<?= $plat ?>';
    var PLAT_NAME = <?= json_encode($platTitles[$plat]) ?>;
    var currentRecord = null;
    var refreshTimer  = null;

    /* Per-platform help texts (SonicPanel style) */
    var HELP = {
        facebook: 'La funcion transmision en vivo Facebook transmite su musica de radio a Facebook Live. Vaya a <a href="https://www.facebook.com/live/create" target="_blank">facebook.com/live/create</a> y active funciones de transmision en vivo. Mantenga la pagina de transmision en vivo Facebook abierta antes de iniciar la transmision en vivo. Copie su clave de transmision en vivo e ingresela en esta pagina, suba una imagen de tamano 1280x720. La transmision en vivo comenzara despues de 30-40 segundos en Facebook.',
        youtube: 'La funcion transmision en vivo Youtube transmite su musica de radio a Youtube Live. Hay un <strong>limite</strong> diario de transmision en vivo en youtube, no reinicie la transmision en vivo mas de <strong>7 veces</strong> al dia. Vaya a <a href="https://www.youtube.com/livestreaming/" target="_blank">youtube.com/livestreaming</a> y active funciones de transmision en vivo. Mantenga la pagina de transmision en vivo Youtube abierta antes de iniciar. Youtube tarda 24 horas para activacion. Copie su clave de transmision e ingresela en esta pagina, suba una imagen de tamano 1280x720. La transmision comenzara despues de 30-40 segundos.',
        tiktok: 'La funcion transmision en vivo TikTok transmite su musica de radio a TikTok Live. Vaya a TikTok, seleccione LIVE y obtenga la URL y clave del servidor. Copie la clave de transmision e ingresela en esta pagina, suba una imagen de tamano 1280x720. La transmision comenzara despues de 30-40 segundos.',
        instagram: 'La funcion transmision en vivo Instagram transmite su musica de radio a Instagram Live. Use la API de Instagram o herramientas de terceros para obtener la URL RTMP y la clave de transmision. Copie la clave e ingresela aqui, suba una imagen de tamano 1280x720. La transmision comenzara despues de 30-40 segundos.',
        custom: 'Configure su propia URL RTMP personalizada para transmitir a cualquier servidor compatible. Ingrese la URL del servidor RTMP y la clave de transmision. Suba una imagen de tamano 1280x720.'
    };

    /* ── Helpers ── */
    function api(action, data) {
        var fd = new FormData();
        fd.append('action', action);
        if (data) Object.keys(data).forEach(function(k) { fd.append(k, data[k]); });
        return fetch(API, { method: 'POST', body: fd }).then(function(r) { return r.json(); });
    }

    function esc(s) {
        if (!s) return '';
        var d = document.createElement('div');
        d.textContent = s;
        return d.innerHTML;
    }

    function toast(msg, isError) {
        var t = document.getElementById('spToast');
        t.textContent = msg;
        t.className = 'sp-toast show ' + (isError ? 'sp-toast-error' : 'sp-toast-ok');
        clearTimeout(t._timer);
        t._timer = setTimeout(function() { t.classList.remove('show'); }, 3500);
    }

    /* ── Load record for THIS platform ── */
    function loadRecord() {
        api('list').then(function(res) {
            if (!res.success) { toast(res.error, true); return; }
            var found = null;
            for (var i = 0; i < res.items.length; i++) {
                if (res.items[i].plataforma === PLAT) { found = res.items[i]; break; }
            }
            currentRecord = found;
            renderPage();
        }).catch(function() { toast('Error de conexion', true); });
    }

    /* ── Render page state ── */
    function renderPage() {
        var keyEl     = document.getElementById('spStreamKey');
        var btnStart  = document.getElementById('btnIniciar');
        var btnStop   = document.getElementById('btnDetener');
        var statusEl  = document.getElementById('spStatusLabel');
        var previewEl = document.getElementById('spPreview');
        var errorEl   = document.getElementById('spError');
        var rtmpEl    = document.getElementById('spRtmpUrl');

        if (currentRecord) {
            keyEl.value = currentRecord.stream_key || '';
            if (rtmpEl && currentRecord.rtmp_url) rtmpEl.value = currentRecord.rtmp_url;

            var isRunning = currentRecord.estado === 'running';
            var isError   = currentRecord.estado === 'error';

            keyEl.readOnly  = isRunning;
            btnStart.disabled = isRunning;
            btnStop.disabled  = !isRunning;
            if (rtmpEl) rtmpEl.readOnly = isRunning;

            if (isRunning) {
                statusEl.textContent = 'Live';
                statusEl.className = 'sp-status-live';
            } else if (isError) {
                statusEl.textContent = 'Error';
                statusEl.className = 'sp-status-error';
            } else {
                statusEl.textContent = 'Disabled';
                statusEl.className = 'sp-status-disabled';
            }

            if (currentRecord.cover_url) {
                previewEl.innerHTML = '<img src="' + esc(currentRecord.cover_url) + '" alt="Cover">';
            } else {
                previewEl.textContent = '1280 x 720';
            }

            if (isError && currentRecord.ultimo_error) {
                errorEl.textContent = 'Error: ' + currentRecord.ultimo_error;
                errorEl.style.display = '';
            } else {
                errorEl.style.display = 'none';
            }
        } else {
            keyEl.value = '';
            keyEl.readOnly = false;
            btnStart.disabled = false;
            btnStop.disabled = true;
            statusEl.textContent = 'Disabled';
            statusEl.className = 'sp-status-disabled';
            previewEl.textContent = '1280 x 720';
            errorEl.style.display = 'none';
        }
    }

    /* ── Iniciar: save + start ── */
    function iniciar(btn) {
        var key = document.getElementById('spStreamKey').value.trim();
        if (!key) { toast('Ingrese la clave de transmision', true); return; }

        var rtmpUrl = '';
        var rtmpEl = document.getElementById('spRtmpUrl');
        if (rtmpEl) rtmpUrl = rtmpEl.value.trim();

        btn.disabled = true;
        btn.innerHTML = '<span class="sp-spinner"></span> Iniciando...';

        var saveData = {
            plataforma: PLAT,
            nombre: PLAT_NAME,
            stream_key: key,
            rtmp_url: rtmpUrl
        };
        if (currentRecord) saveData.id = currentRecord.id;

        api('save', saveData).then(function(res) {
            if (!res.success) { toast(res.error, true); restore(btn, 'Iniciar'); return; }
            var id = res.id;
            return api('start', { id: id });
        }).then(function(res) {
            if (!res) return;
            if (res.success) {
                toast('Transmision iniciada');
            } else {
                toast(res.error, true);
            }
            restore(btn, 'Iniciar');
            loadRecord();
        }).catch(function() {
            toast('Error de conexion', true);
            restore(btn, 'Iniciar');
        });
    }

    /* ── Detener ── */
    function detener(btn) {
        if (!currentRecord) return;
        if (!confirm('Detener la transmision?')) return;

        btn.disabled = true;
        btn.innerHTML = '<span class="sp-spinner"></span> Deteniendo...';

        api('stop', { id: currentRecord.id }).then(function(res) {
            if (res.success) toast('Transmision detenida');
            else toast(res.error, true);
            restore(btn, 'Detener');
            loadRecord();
        }).catch(function() {
            toast('Error de conexion', true);
            restore(btn, 'Detener');
        });
    }

    function restore(btn, text) {
        btn.disabled = false;
        btn.textContent = text;
    }

    /* ── Upload cover ── */
    function triggerUpload() {
        if (!currentRecord) {
            /* Auto-create record first */
            var key = document.getElementById('spStreamKey').value.trim() || '(pendiente)';
            var rtmpUrl = '';
            var rtmpEl = document.getElementById('spRtmpUrl');
            if (rtmpEl) rtmpUrl = rtmpEl.value.trim();

            api('save', {
                plataforma: PLAT,
                nombre: PLAT_NAME,
                stream_key: key,
                rtmp_url: rtmpUrl
            }).then(function(res) {
                if (res.success) {
                    currentRecord = { id: res.id };
                    document.getElementById('spCoverInput').click();
                } else {
                    toast(res.error, true);
                }
            });
            return;
        }
        document.getElementById('spCoverInput').click();
    }

    document.getElementById('spCoverInput').addEventListener('change', function() {
        if (!this.files.length || !currentRecord) return;

        var fd = new FormData();
        fd.append('action', 'upload_cover');
        fd.append('id', currentRecord.id);
        fd.append('cover', this.files[0]);

        toast('Subiendo imagen...');

        fetch(API, { method: 'POST', body: fd })
            .then(function(r) { return r.json(); })
            .then(function(res) {
                if (res.success) { toast('Imagen actualizada'); loadRecord(); }
                else toast(res.error, true);
            })
            .catch(function() { toast('Error de conexion', true); });

        this.value = '';
    });

    /* ── Refresh status periodically ── */
    function refreshStatus() {
        if (!currentRecord) return;
        api('status').then(function(res) {
            if (!res.success) return;
            for (var i = 0; i < res.items.length; i++) {
                if (res.items[i].id == currentRecord.id) {
                    var oldState = currentRecord.estado;
                    currentRecord.estado = res.items[i].estado;
                    currentRecord.ultimo_error = res.items[i].ultimo_error;
                    if (oldState !== currentRecord.estado) renderPage();
                    break;
                }
            }
        });
    }

    /* ═══ Event Delegation ═══ */
    document.addEventListener('click', function(e) {
        var btn = e.target.closest('[data-action]');
        if (!btn) return;
        var action = btn.getAttribute('data-action');
        if (action === 'iniciar')  iniciar(btn);
        if (action === 'detener')  detener(btn);
        if (action === 'upload')   triggerUpload();
    });

    /* ── Init ── */
    document.addEventListener('DOMContentLoaded', function() {
        document.getElementById('spInfoText').innerHTML = HELP[PLAT] || HELP['custom'];
        loadRecord();
        refreshTimer = setInterval(refreshStatus, 8000);
    });

})();
</script>

<?php require_once __DIR__ . '/../includes/layout_footer.php'; ?>
