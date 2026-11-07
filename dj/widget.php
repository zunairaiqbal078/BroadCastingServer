<?php
/**
 * CastPanel DJ Portal — Widget en Vivo
 * Código embed del reproductor para sitio web
 */
$pageTitle = 'Widget en Vivo';
require_once __DIR__ . '/includes/header.php';

$sslDomain = defined('SSL_DOMAIN') ? SSL_DOMAIN : ($_SERVER['HTTP_HOST'] ?? 'localhost');
$port = $dj['puerto_stream'] ?? 8000;

// Build widget URLs
$baseUrl = 'https://' . $sslDomain;
$playerFullUrl = $baseUrl . '/widgets/player/?p=' . $port;
$playerSingleUrl = $baseUrl . '/widgets/player/single/?p=' . $port;

// Available effects
$effects = ['rain','snow','stars','wave','equalizer','lightning','none'];
?>

<div class="section-card">
    <div class="section-header">
        <svg viewBox="0 0 24 24"><polyline points="15 3 21 3 21 9"/><polyline points="9 21 3 21 3 15"/><line x1="21" y1="3" x2="14" y2="10"/><line x1="3" y1="21" x2="10" y2="14"/></svg>
        Widget en Vivo — Código Embed
    </div>
    <div class="section-body">

        <div class="alert alert-info">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="12" y1="16" x2="12" y2="12"/><line x1="12" y1="8" x2="12.01" y2="8"/></svg>
            <div>Copia el código HTML de abajo y pégalo en tu sitio web para mostrar el reproductor de tu emisora en vivo.</div>
        </div>

        <!-- Effect selector -->
        <div class="form-group">
            <label>Efecto visual del reproductor:</label>
            <select class="form-control" id="effectSelect" onchange="updateEmbedCodes()" style="max-width:300px;">
                <?php foreach ($effects as $fx): ?>
                <option value="<?= $fx ?>"><?= ucfirst($fx) ?></option>
                <?php endforeach; ?>
            </select>
        </div>

        <!-- Full Player -->
        <h4 style="font-size:15px;font-weight:700;margin:24px 0 8px;">
            <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" style="vertical-align:middle;margin-right:4px;"><rect x="2" y="3" width="20" height="14" rx="2" ry="2"/><line x1="8" y1="21" x2="16" y2="21"/><line x1="12" y1="17" x2="12" y2="21"/></svg>
            Reproductor Completo
        </h4>
        <p style="font-size:13px;color:var(--text-secondary);margin-bottom:8px;">Tamaño recomendado: 400 x 550px</p>
        <div style="position:relative;">
            <textarea class="form-control" id="embedFull" rows="4" readonly style="font-family:monospace;font-size:12px;background:#f8fafc;resize:vertical;"></textarea>
            <button class="btn btn-sm btn-primary" onclick="copyCode('embedFull')" style="position:absolute;top:8px;right:8px;">
                <svg viewBox="0 0 24 24"><rect x="9" y="9" width="13" height="13" rx="2" ry="2"/><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/></svg>
                Copiar
            </button>
        </div>

        <!-- Single/Mini Player -->
        <h4 style="font-size:15px;font-weight:700;margin:24px 0 8px;">
            <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" style="vertical-align:middle;margin-right:4px;"><rect x="2" y="7" width="20" height="10" rx="2" ry="2"/></svg>
            Reproductor Mini (barra)
        </h4>
        <p style="font-size:13px;color:var(--text-secondary);margin-bottom:8px;">Tamaño recomendado: 400 x 120px</p>
        <div style="position:relative;">
            <textarea class="form-control" id="embedSingle" rows="4" readonly style="font-family:monospace;font-size:12px;background:#f8fafc;resize:vertical;"></textarea>
            <button class="btn btn-sm btn-primary" onclick="copyCode('embedSingle')" style="position:absolute;top:8px;right:8px;">
                <svg viewBox="0 0 24 24"><rect x="9" y="9" width="13" height="13" rx="2" ry="2"/><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/></svg>
                Copiar
            </button>
        </div>

        <!-- Direct link -->
        <h4 style="font-size:15px;font-weight:700;margin:24px 0 8px;">
            <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" style="vertical-align:middle;margin-right:4px;"><path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"/><path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"/></svg>
            Link Directo
        </h4>
        <div style="position:relative;">
            <input class="form-control" id="directLink" readonly style="font-family:monospace;font-size:13px;background:#f8fafc;padding-right:80px;">
            <button class="btn btn-sm btn-primary" onclick="copyCode('directLink')" style="position:absolute;top:6px;right:6px;">
                <svg viewBox="0 0 24 24"><rect x="9" y="9" width="13" height="13" rx="2" ry="2"/><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/></svg>
                Copiar
            </button>
        </div>

        <!-- Preview -->
        <h4 style="font-size:15px;font-weight:700;margin:24px 0 8px;">Vista Previa</h4>
        <div style="border:1px solid var(--border);border-radius:10px;overflow:hidden;max-width:420px;">
            <iframe id="previewFrame" width="100%" height="500" frameborder="0" style="display:block;"></iframe>
        </div>
    </div>
</div>

<script>
const BASE_FULL = <?= json_encode($playerFullUrl) ?>;
const BASE_SINGLE = <?= json_encode($playerSingleUrl) ?>;

function updateEmbedCodes() {
    const effect = document.getElementById('effectSelect').value;
    const suffix = effect !== 'none' ? '&type=' + effect : '';
    const fullUrl = BASE_FULL + suffix;
    const singleUrl = BASE_SINGLE + suffix;

    document.getElementById('embedFull').value =
        '<iframe src="' + fullUrl + '" width="400" height="550" frameborder="0" allow="autoplay" style="border:none;border-radius:12px;"></iframe>';

    document.getElementById('embedSingle').value =
        '<iframe src="' + singleUrl + '" width="400" height="120" frameborder="0" allow="autoplay" style="border:none;border-radius:12px;"></iframe>';

    document.getElementById('directLink').value = fullUrl;
    document.getElementById('previewFrame').src = fullUrl;
}

function copyCode(id) {
    const el = document.getElementById(id);
    const text = el.value || el.textContent;
    navigator.clipboard.writeText(text).then(() => {
        showToast('Código copiado al portapapeles', 'success');
    }).catch(() => {
        el.select();
        document.execCommand('copy');
        showToast('Código copiado', 'success');
    });
}

// Init
updateEmbedCodes();
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
