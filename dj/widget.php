<?php
/**
 * CastPanel DJ Portal — Widget en Vivo
 * Código embed del reproductor para sitio web
 */
$pageTitle = 'Widget en Vivo';
require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/../includes/functions.php';

$estacionRow = [
    'slug' => $dj['slug'] ?? '',
    'server_type' => $dj['server_type'] ?? 'shoutcast_v2',
];
$widgetUrls = castpanelWidgetPlayerUrls($estacionRow);
$playerFullUrl = $widgetUrls['full'];
$playerSingleUrl = $widgetUrls['single'];
// Without a site_url the helper returns this page's origin, i.e. the DJ
// port (2080), where /widgets does not exist: use the main panel address.
if (!empty($djAssetsBase) && str_starts_with($playerFullUrl, castpanelPublicPageOrigin() . '/')) {
    $slugQ = rawurlencode($estacionRow['slug']);
    $playerFullUrl = $djAssetsBase . '/widgets/player/?slug=' . $slugQ;
    $playerSingleUrl = $djAssetsBase . '/widgets/player/single/?slug=' . $slugQ;
}

$effects = ['rain', 'snow', 'stars', 'wave', 'equalizer', 'lightning', 'none'];
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

        <div class="form-group">
            <label>Efecto visual del reproductor:</label>
            <select class="form-control" id="effectSelect" onchange="updateEmbedCodes()" style="max-width:300px;">
                <option value="" selected>Ajustes del reproductor</option>
                <?php foreach ($effects as $fx): ?>
                <option value="<?= $fx ?>"><?= ucfirst($fx) ?></option>
                <?php endforeach; ?>
            </select>
        </div>

        <h4 style="font-size:15px;font-weight:700;margin:24px 0 8px;">Reproductor Completo</h4>
        <p style="font-size:13px;color:var(--text-secondary);margin-bottom:8px;">Tamaño recomendado: 400 x 550px</p>
        <div style="position:relative;">
            <textarea class="form-control" id="embedFull" rows="4" readonly style="font-family:monospace;font-size:12px;background:#f8fafc;resize:vertical;padding-right:88px;"></textarea>
            <button type="button" class="btn btn-sm btn-primary" onclick="copyCode('embedFull', this)" style="position:absolute;top:8px;right:8px;">Copiar</button>
        </div>

        <h4 style="font-size:15px;font-weight:700;margin:24px 0 8px;">Reproductor Mini (barra)</h4>
        <p style="font-size:13px;color:var(--text-secondary);margin-bottom:8px;">Tamaño recomendado: 400 x 120px</p>
        <div style="position:relative;">
            <textarea class="form-control" id="embedSingle" rows="4" readonly style="font-family:monospace;font-size:12px;background:#f8fafc;resize:vertical;padding-right:88px;"></textarea>
            <button type="button" class="btn btn-sm btn-primary" onclick="copyCode('embedSingle', this)" style="position:absolute;top:8px;right:8px;">Copiar</button>
        </div>

        <h4 style="font-size:15px;font-weight:700;margin:24px 0 8px;">Link Directo</h4>
        <div style="position:relative;">
            <input class="form-control" id="directLink" readonly style="font-family:monospace;font-size:13px;background:#f8fafc;padding-right:80px;">
            <button type="button" class="btn btn-sm btn-primary" onclick="copyCode('directLink', this)" style="position:absolute;top:6px;right:6px;">Copiar</button>
        </div>

        <h4 style="font-size:15px;font-weight:700;margin:24px 0 8px;">Vista Previa</h4>
        <div style="border:1px solid var(--border);border-radius:12px;overflow:hidden;max-width:420px;background:#0a0a12;">
            <iframe id="previewFrame" width="100%" height="500" frameborder="0" style="display:block;" allow="autoplay"></iframe>
        </div>
    </div>
</div>

<script>
const BASE_FULL = <?= json_encode($playerFullUrl) ?>;
const BASE_SINGLE = <?= json_encode($playerSingleUrl) ?>;

function updateEmbedCodes() {
    const effect = document.getElementById('effectSelect').value;
    // '' = the effect saved in Customize Player (client panel)
    const suffix = effect ? '&type=' + encodeURIComponent(effect) : '';
    const fullUrl = BASE_FULL + suffix;
    const singleUrl = BASE_SINGLE + suffix;

    document.getElementById('embedFull').value =
        '<iframe src="' + fullUrl + '" width="400" height="550" frameborder="0" allow="autoplay" style="border:none;border-radius:12px;"></iframe>';

    document.getElementById('embedSingle').value =
        '<iframe src="' + singleUrl + '" width="400" height="120" frameborder="0" allow="autoplay" style="border:none;border-radius:12px;"></iframe>';

    document.getElementById('directLink').value = fullUrl;
    document.getElementById('previewFrame').src = fullUrl;
}

function copyCode(id, btn) {
    const el = document.getElementById(id);
    const text = el.value || el.textContent || '';
    if (typeof castpanelCopyText === 'function') {
        castpanelCopyText(text, btn);
    } else {
        el.select();
        document.execCommand('copy');
    }
    showToast('Código copiado al portapapeles', 'success');
}

updateEmbedCodes();
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
