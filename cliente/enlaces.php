<?php
/**
 * CastPanel v2.0 - Enlaces SSL de Streaming
 */
$pageTitle = 'Enlaces de Streaming SSL';
$pageIcon = 'link';
require_once __DIR__ . '/../includes/layout_header.php';

if (!$estacion) { echo '<div class="alert alert-warning">Sin estación asignada.</div>'; require_once __DIR__ . '/../includes/layout_footer.php'; exit; }

$domain = defined('SSL_DOMAIN') ? SSL_DOMAIN : ($_SERVER['HTTP_HOST'] ?? 'localhost');
$slug = $estacion['slug'] ?? '';
$port = $estacion['icecast_port'] ?? 8000;
$baseStream = "https://$domain/live/$slug";
$baseStreamHttp = "http://$domain:$port" . ($estacion['icecast_mount'] ?? '/stream');
$appUrl = (isset($_SERVER['HTTPS']) ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? $domain) . (defined('BASE_URL') ? BASE_URL : '');
?>

<div class="alert alert-info">
    <?= cpIcon('info',14) ?> Todos los enlaces de streaming están disponibles con SSL (HTTPS) para máxima compatibilidad con navegadores y aplicaciones móviles.
</div>

<!-- URLs Principales -->
<div class="card" style="margin-bottom:20px">
    <div class="card-header">
        <h3><?= cpIcon('lock',18) ?> URLs de Streaming SSL</h3>
    </div>
    <div class="card-body">
        <div class="form-group">
            <label>Stream HTTPS Principal</label>
            <div class="copy-field">
                <input type="text" value="<?= $baseStream ?>" readonly id="url1">
                <button class="btn-copy" onclick="copyToClipboard(document.getElementById('url1').value, this)"><?= cpIcon('copy',14) ?> Copiar</button>
            </div>
        </div>

        <div class="form-group">
            <label>Stream HTTP (sin SSL)</label>
            <div class="copy-field">
                <input type="text" value="<?= $baseStreamHttp ?>" readonly id="url2">
                <button class="btn-copy" onclick="copyToClipboard(document.getElementById('url2').value, this)"><?= cpIcon('copy',14) ?> Copiar</button>
            </div>
        </div>

        <div class="form-group">
            <label>Enlace M3U (para reproductores)</label>
            <div class="copy-field">
                <input type="text" value="<?= $appUrl ?>/api/stream.php?format=m3u&station=<?= $slug ?>" readonly id="url3">
                <button class="btn-copy" onclick="copyToClipboard(document.getElementById('url3').value, this)"><?= cpIcon('copy',14) ?> Copiar</button>
            </div>
        </div>

        <div class="form-group">
            <label>Enlace PLS (para WinAmp, VLC, etc)</label>
            <div class="copy-field">
                <input type="text" value="<?= $appUrl ?>/api/stream.php?format=pls&station=<?= $slug ?>" readonly id="url4">
                <button class="btn-copy" onclick="copyToClipboard(document.getElementById('url4').value, this)"><?= cpIcon('copy',14) ?> Copiar</button>
            </div>
        </div>

        <div class="form-group">
            <label>Enlace XSPF</label>
            <div class="copy-field">
                <input type="text" value="<?= $appUrl ?>/api/stream.php?format=xspf&station=<?= $slug ?>" readonly id="url5">
                <button class="btn-copy" onclick="copyToClipboard(document.getElementById('url5').value, this)"><?= cpIcon('copy',14) ?> Copiar</button>
            </div>
        </div>
    </div>
</div>

<!-- Información técnica -->
<div class="two-cols-equal">
    <div class="card">
        <div class="card-header">
            <h3><?= cpIcon('satellite',18) ?> Información Técnica</h3>
        </div>
        <div class="card-body">
            <table class="table">
                <tr><td style="font-weight:600">Servidor</td><td><?= htmlspecialchars($domain) ?></td></tr>
                <tr><td style="font-weight:600">Puerto HTTP</td><td><?= $port ?></td></tr>
                <tr><td style="font-weight:600">Puerto HTTPS</td><td>443 (proxy SSL)</td></tr>
                <tr><td style="font-weight:600">Mount Point</td><td><?= htmlspecialchars($estacion['icecast_mount'] ?? '/stream') ?></td></tr>
                <tr><td style="font-weight:600">Codec</td><td><?= strtoupper($estacion['codec'] ?? 'mp3') ?></td></tr>
                <tr><td style="font-weight:600">Bitrate</td><td><?= $estacion['bitrate'] ?? 128 ?> kbps</td></tr>
                <tr><td style="font-weight:600">Sample Rate</td><td><?= number_format(($estacion['samplerate'] ?? 44100) / 1000, 1) ?> kHz</td></tr>
                <tr><td style="font-weight:600">Canales</td><td><?= ($estacion['channels'] ?? 2) == 2 ? 'Estéreo' : 'Mono' ?></td></tr>
            </table>
        </div>
    </div>

    <div class="card">
        <div class="card-header">
            <h3><?= cpIcon('globe',18) ?> Aplicaciones Compatibles</h3>
        </div>
        <div class="card-body">
            <p style="font-size:13px;color:var(--text-secondary);margin-bottom:12px">
                Tu stream es compatible con cualquier reproductor que soporte HTTP streaming:
            </p>
            <ul style="font-size:13px;color:var(--text-secondary);padding-left:20px;line-height:2">
                <li><strong>VLC Media Player</strong> — Abre URL de red</li>
                <li><strong>WinAmp / AIMP</strong> — Abrir archivo .pls o .m3u</li>
                <li><strong>foobar2000</strong> — Abrir URL</li>
                <li><strong>iTunes / Apple Music</strong> — Abrir URL del stream</li>
                <li><strong>Navegadores Web</strong> — Enlace directo HTTPS</li>
                <li><strong>TuneIn / Radio.net</strong> — Usar enlace M3U/PLS</li>
                <li><strong>Apps Android/iOS</strong> — URL HTTPS directa</li>
            </ul>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/layout_footer.php'; ?>
