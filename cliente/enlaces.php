<?php
/**
 * CastPanel v2.0 - Enlaces SSL de Streaming
 */
$pageTitle = 'Enlaces de Streaming SSL';
$pageIcon = 'link';
require_once __DIR__ . '/../includes/layout_header.php';

if (!$estacion) { echo '<div class="alert alert-warning">Sin estación asignada.</div>'; require_once __DIR__ . '/../includes/layout_footer.php'; exit; }

$domain = castpanelPublicHost();
$slug = $estacion['slug'] ?? '';
$isShout = isShoutcastServerType($estacion['server_type'] ?? '');
// SHOUTcast listens on the station's own port; icecast_port defaults to 8000
$port = $isShout ? ((int)($estacion['puerto_stream'] ?? 0) ?: 8000) : ((int)($estacion['icecast_port'] ?? 0) ?: 8000);
// SonicPanel format: https://domain/PORT/stream (and /PORT/; for TuneIn and directories)
$baseStream = castpanelSslStreamUrl($estacion);
$tuneinStream = castpanelSslStreamUrl($estacion, ';');
$baseStreamHttp = "http://$domain:$port" . ($isShout ? '/stream' : ($estacion['icecast_mount'] ?? '/stream'));
$appUrl = (isset($_SERVER['HTTPS']) ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? $domain) . (defined('BASE_URL') ? BASE_URL : '');
?>

<?php
$playerUrl = castpanelWidgetPlayerUrls($estacion)['full'];
$listBase = $appUrl . '/api/stream.php?station=' . rawurlencode($slug) . '&format=';
// SonicPanel-style sections: title, blue info note, then the link(s) with a copy button
$sslSections = [
    ['title' => 'URL de Reproducción SSL Directa', 'color' => '#00897b',
     'note' => 'URL SSL directa para reproductores HTML5, apps móviles, plugins WordPress y players personalizados. Funciona con HTTPS en el puerto de la estación.',
     'links' => [[null, $baseStream]]],
    ['title' => 'Configuración SSL para TuneIn, Radio.es y directorios', 'color' => '#0277bd',
     'note' => 'Use este enlace al registrar su radio en TuneIn, Radio.es, Radio Garden y otros directorios, o en apps y reproductores que piden la URL del stream SHOUTcast. También funciona en reproductores HTML5.',
     'links' => [[null, $tuneinStream]]],
    ['title' => 'URL HTTP Directa (Sin SSL)', 'color' => '#1565c0',
     'note' => 'URL directa sin SSL para software de radio como SAM Broadcaster, BUTT, Mixxx, RadioBOSS y otros encoders. Use esta URL con el puerto indicado para transmisión directa.',
     'links' => [[null, $baseStreamHttp]]],
    ['title' => 'Reproductor Público', 'color' => '#8e24aa',
     'note' => 'Comparta este enlace con sus oyentes. Es la página del reproductor público con carátulas, historial de canciones y efectos visuales personalizables.',
     'links' => [[null, $playerUrl]]],
    ['title' => 'Listas de reproducción (M3U / PLS / XSPF)', 'color' => '#fb8c00',
     'note' => 'Archivos de lista para abrir la radio en VLC, WinAmp, AIMP, foobar2000, iTunes y directorios como TuneIn o Radio.net.',
     'links' => [['M3U', $listBase . 'm3u'], ['PLS', $listBase . 'pls'], ['XSPF', $listBase . 'xspf']]],
];
$linkN = 0;
?>
<style>
.sp-links-card .card-body{padding:22px 24px}
.sp-link-sec + .sp-link-sec{margin-top:24px}
.sp-link-sec h4{font-size:14.5px;font-weight:700;color:var(--text-primary);margin:0 0 10px}
.sp-link-note{display:flex;gap:10px;align-items:flex-start;background:#e3f2fd;color:#1565c0;border-radius:8px;padding:12px 16px;margin-bottom:12px;font-size:12.5px;line-height:1.55}
.sp-link-note svg{flex-shrink:0;margin-top:2px}
.sp-link-row{display:flex;align-items:center;gap:10px;background:var(--hover,#f8f9fa);border:1px solid var(--border,#e0e0e0);border-radius:8px;padding:9px 12px}
.sp-link-row + .sp-link-row{margin-top:8px}
.sp-link-row .sp-dot{flex-shrink:0;display:flex}
.sp-link-row .sp-tag{flex-shrink:0;font-size:11px;font-weight:800;padding:2px 7px;border-radius:5px;background:rgba(0,0,0,.06);color:var(--text-secondary)}
.sp-link-row input{flex:1;min-width:0;border:none;background:transparent;font-size:13px;color:#1565c0;font-weight:600;outline:none;font-family:monospace;text-overflow:ellipsis}
.sp-link-row a.sp-open{flex-shrink:0;display:flex;color:var(--text-secondary);padding:4px}
.sp-link-row a.sp-open:hover{color:var(--primary)}
.sp-link-row button{flex-shrink:0;background:#00897b;color:#fff;border:none;padding:6px 14px;border-radius:6px;cursor:pointer;font-size:12px;font-weight:600;display:flex;align-items:center;gap:5px;font-family:inherit}
.sp-link-row button:hover{filter:brightness(1.08)}
@media (max-width:560px){ .sp-link-row{flex-wrap:wrap} .sp-link-row input{flex-basis:100%;order:3} }
</style>

<div class="card sp-links-card" style="margin-bottom:20px">
    <div class="card-header">
        <h3><?= cpIcon('lock',18) ?> Enlaces SSL/Reproducir</h3>
    </div>
    <div class="card-body">
        <?php foreach ($sslSections as $sec): ?>
        <div class="sp-link-sec">
            <h4><?= htmlspecialchars($sec['title']) ?></h4>
            <div class="sp-link-note"><?= cpIcon('info', 16) ?><span><?= htmlspecialchars($sec['note']) ?></span></div>
            <?php foreach ($sec['links'] as [$tag, $url]): $linkN++; ?>
            <div class="sp-link-row">
                <span class="sp-dot" style="color:<?= $sec['color'] ?>"><?= cpIcon('link', 15) ?></span>
                <?php if ($tag): ?><span class="sp-tag" translate="no"><?= $tag ?></span><?php endif; ?>
                <input type="text" value="<?= htmlspecialchars($url) ?>" readonly id="spLink<?= $linkN ?>" onclick="this.select()" aria-label="<?= htmlspecialchars($sec['title']) ?>">
                <a class="sp-open" href="<?= htmlspecialchars($url) ?>" target="_blank" rel="noopener" title="Abrir"><?= cpIcon('external', 15) ?></a>
                <button type="button" onclick="copyToClipboard('spLink<?= $linkN ?>', this)"><?= cpIcon('copy', 12) ?> Copiar</button>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endforeach; ?>
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
