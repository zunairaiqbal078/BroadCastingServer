<?php
/**
 * CastPanel v2.3 - API JSON (Estilo SonicPanel/NexoAir)
 */
$pageTitle = 'API JSON';
$pageIcon = 'code';
require_once __DIR__ . '/../includes/layout_header.php';

if (!$estacion) { echo '<div class="alert alert-warning">Sin estaci&oacute;n asignada.</div>'; require_once __DIR__ . '/../includes/layout_footer.php'; exit; }

$baseApi = (isset($_SERVER['HTTPS']) ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'panel.tudominio.com') . BASE_URL;
$streamUrl = 'https://' . SSL_DOMAIN . '/live/' . $estacion['slug'];
$nowplayingUrl = $baseApi . '/api/nowplaying.php?slug=' . $estacion['slug'];
?>

<!-- Endpoint principal -->
<div class="card" style="margin-bottom:20px;overflow:hidden">
    <div style="padding:20px;background:linear-gradient(135deg,#2563eb,#1d4ed8)">
        <div style="display:flex;align-items:center;gap:10px;margin-bottom:12px">
            <span style="color:#fff"><?= cpIcon('globe',24) ?></span>
            <div>
                <h3 style="color:#fff;font-size:18px;margin:0">API Now Playing</h3>
                <p style="color:rgba(255,255,255,.7);font-size:12px;margin:0">Endpoint p&uacute;blico &middot; No requiere autenticaci&oacute;n &middot; JSON &middot; CORS habilitado</p>
            </div>
        </div>
        <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap">
            <span style="background:rgba(255,255,255,.2);color:#fff;padding:4px 10px;border-radius:4px;font-size:12px;font-weight:700">GET</span>
            <div style="flex:1;min-width:200px;position:relative">
                <input type="text" id="api-main" class="form-control" readonly value="<?= htmlspecialchars($nowplayingUrl) ?>" style="background:rgba(255,255,255,.15);border:1px solid rgba(255,255,255,.25);color:#fff;font-family:monospace;font-size:13px;padding-right:90px">
            </div>
            <button class="btn btn-sm" style="background:rgba(255,255,255,.2);color:#fff;border:1px solid rgba(255,255,255,.3)" onclick="copyField('api-main',this)"><?= cpIcon('copy',14) ?> Copiar</button>
            <a href="<?= htmlspecialchars($nowplayingUrl) ?>" target="_blank" class="btn btn-sm" style="background:rgba(255,255,255,.2);color:#fff;border:1px solid rgba(255,255,255,.3)"><?= cpIcon('link',14) ?> Abrir</a>
        </div>
    </div>
</div>

<!-- Feature cards -->
<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:12px;margin-bottom:20px">
    <div class="card" style="padding:16px;text-align:center">
        <div style="margin-bottom:6px"><?= cpIcon('zap',28) ?></div>
        <h4 style="font-size:14px;margin-bottom:4px">Tiempo Real</h4>
        <p style="font-size:12px;color:var(--text-secondary)">Datos actualizados en cada solicitud con informaci&oacute;n en vivo</p>
    </div>
    <div class="card" style="padding:16px;text-align:center">
        <div style="margin-bottom:6px"><?= cpIcon('lock',28) ?></div>
        <h4 style="font-size:14px;margin-bottom:4px">Sin Autenticaci&oacute;n</h4>
        <p style="font-size:12px;color:var(--text-secondary)">P&uacute;blico y libre. No necesitas API Key ni tokens</p>
    </div>
    <div class="card" style="padding:16px;text-align:center">
        <div style="margin-bottom:6px"><?= cpIcon('globe',28) ?></div>
        <h4 style="font-size:14px;margin-bottom:4px">CORS Habilitado</h4>
        <p style="font-size:12px;color:var(--text-secondary)">Consulta desde cualquier dominio o app m&oacute;vil</p>
    </div>
    <div class="card" style="padding:16px;text-align:center">
        <div style="margin-bottom:6px"><?= cpIcon('package',28) ?></div>
        <h4 style="font-size:14px;margin-bottom:4px">JSON Est&aacute;ndar</h4>
        <p style="font-size:12px;color:var(--text-secondary)">Respuesta JSON estructurada y documentada</p>
    </div>
</div>

<!-- Documentación de campos -->
<div class="card" style="margin-bottom:20px">
    <div class="card-header">
        <h3><?= cpIcon('code',18) ?> Documentaci&oacute;n de Campos</h3>
        <span class="badge badge-success">Respuesta JSON</span>
    </div>
    <div class="card-body no-padding">
        <div class="table-responsive">
            <table class="table">
                <thead>
                    <tr>
                        <th>Campo</th>
                        <th>Tipo</th>
                        <th>Descripci&oacute;n</th>
                        <th>Ejemplo</th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td><code style="background:var(--hover-bg);padding:2px 6px;border-radius:3px;font-size:12px">station.name</code></td>
                        <td><span style="background:#dbeafe;color:#1d4ed8;padding:2px 8px;border-radius:10px;font-size:11px;font-weight:600">string</span></td>
                        <td style="font-size:13px">Nombre de la estaci&oacute;n</td>
                        <td style="font-size:12px;color:var(--text-secondary)">"<?= htmlspecialchars($estacion['nombre']) ?>"</td>
                    </tr>
                    <tr>
                        <td><code style="background:var(--hover-bg);padding:2px 6px;border-radius:3px;font-size:12px">station.slug</code></td>
                        <td><span style="background:#dbeafe;color:#1d4ed8;padding:2px 8px;border-radius:10px;font-size:11px;font-weight:600">string</span></td>
                        <td style="font-size:13px">Identificador &uacute;nico de la estaci&oacute;n</td>
                        <td style="font-size:12px;color:var(--text-secondary)">"<?= $estacion['slug'] ?>"</td>
                    </tr>
                    <tr>
                        <td><code style="background:var(--hover-bg);padding:2px 6px;border-radius:3px;font-size:12px">station.stream_url</code></td>
                        <td><span style="background:#fef3c7;color:#92400e;padding:2px 8px;border-radius:10px;font-size:11px;font-weight:600">url</span></td>
                        <td style="font-size:13px">URL de streaming HTTPS</td>
                        <td style="font-size:12px;color:var(--text-secondary)">"<?= $streamUrl ?>"</td>
                    </tr>
                    <tr>
                        <td><code style="background:var(--hover-bg);padding:2px 6px;border-radius:3px;font-size:12px">station.bitrate</code></td>
                        <td><span style="background:#dcfce7;color:#166534;padding:2px 8px;border-radius:10px;font-size:11px;font-weight:600">integer</span></td>
                        <td style="font-size:13px">Bitrate de la transmisi&oacute;n en kbps</td>
                        <td style="font-size:12px;color:var(--text-secondary)"><?= $estacion['bitrate'] ?></td>
                    </tr>
                    <tr>
                        <td><code style="background:var(--hover-bg);padding:2px 6px;border-radius:3px;font-size:12px">live.online</code></td>
                        <td><span style="background:#ede9fe;color:#5b21b6;padding:2px 8px;border-radius:10px;font-size:11px;font-weight:600">boolean</span></td>
                        <td style="font-size:13px">Si la estaci&oacute;n est&aacute; transmitiendo</td>
                        <td style="font-size:12px;color:var(--text-secondary)">true</td>
                    </tr>
                    <tr>
                        <td><code style="background:var(--hover-bg);padding:2px 6px;border-radius:3px;font-size:12px">live.listeners</code></td>
                        <td><span style="background:#dcfce7;color:#166534;padding:2px 8px;border-radius:10px;font-size:11px;font-weight:600">integer</span></td>
                        <td style="font-size:13px">Oyentes conectados actualmente</td>
                        <td style="font-size:12px;color:var(--text-secondary)">15</td>
                    </tr>
                    <tr>
                        <td><code style="background:var(--hover-bg);padding:2px 6px;border-radius:3px;font-size:12px">now_playing.title</code></td>
                        <td><span style="background:#dbeafe;color:#1d4ed8;padding:2px 8px;border-radius:10px;font-size:11px;font-weight:600">string</span></td>
                        <td style="font-size:13px">T&iacute;tulo de la canci&oacute;n actual</td>
                        <td style="font-size:12px;color:var(--text-secondary)">"Mi Canci&oacute;n"</td>
                    </tr>
                    <tr>
                        <td><code style="background:var(--hover-bg);padding:2px 6px;border-radius:3px;font-size:12px">now_playing.artist</code></td>
                        <td><span style="background:#dbeafe;color:#1d4ed8;padding:2px 8px;border-radius:10px;font-size:11px;font-weight:600">string</span></td>
                        <td style="font-size:13px">Artista de la canci&oacute;n actual</td>
                        <td style="font-size:12px;color:var(--text-secondary)">"Mi Artista"</td>
                    </tr>
                    <tr>
                        <td><code style="background:var(--hover-bg);padding:2px 6px;border-radius:3px;font-size:12px">now_playing.cover</code></td>
                        <td><span style="background:#fef3c7;color:#92400e;padding:2px 8px;border-radius:10px;font-size:11px;font-weight:600">url</span></td>
                        <td style="font-size:13px">URL de la car&aacute;tula del &aacute;lbum</td>
                        <td style="font-size:12px;color:var(--text-secondary)">"/assets/covers/..."</td>
                    </tr>
                    <tr>
                        <td><code style="background:var(--hover-bg);padding:2px 6px;border-radius:3px;font-size:12px">history[]</code></td>
                        <td><span style="background:#fee2e2;color:#991b1b;padding:2px 8px;border-radius:10px;font-size:11px;font-weight:600">array</span></td>
                        <td style="font-size:13px">Historial de canciones recientes</td>
                        <td style="font-size:12px;color:var(--text-secondary)">[{title, artist, played_at}]</td>
                    </tr>
                    <tr>
                        <td><code style="background:var(--hover-bg);padding:2px 6px;border-radius:3px;font-size:12px">timestamp</code></td>
                        <td><span style="background:#dbeafe;color:#1d4ed8;padding:2px 8px;border-radius:10px;font-size:11px;font-weight:600">string</span></td>
                        <td style="font-size:13px">Fecha/hora de la consulta ISO 8601</td>
                        <td style="font-size:12px;color:var(--text-secondary)">"2024-01-15T14:30:00-05:00"</td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Vista previa JSON en tiempo real -->
<div class="card" style="margin-bottom:20px">
    <div class="card-header">
        <h3><?= cpIcon('satellite',18) ?> Vista Previa en Tiempo Real</h3>
        <button class="btn btn-outline btn-sm" onclick="loadPreview()"><?= cpIcon('refresh',14) ?> Actualizar</button>
    </div>
    <div class="card-body">
        <pre id="jsonPreview" style="background:#1e293b;color:#e2e8f0;padding:16px;border-radius:8px;overflow-x:auto;font-size:13px;font-family:'Fira Code',monospace;max-height:400px;overflow-y:auto">Cargando...</pre>
    </div>
</div>

<!-- Endpoints adicionales -->
<div class="card" style="margin-bottom:20px">
    <div class="card-header">
        <h3><?= cpIcon('link',18) ?> Todos los Endpoints</h3>
    </div>
    <div class="card-body no-padding">
        <div class="table-responsive">
            <table class="table">
                <thead>
                    <tr>
                        <th>M&eacute;todo</th>
                        <th>Endpoint</th>
                        <th>Descripci&oacute;n</th>
                        <th style="width:120px">Acciones</th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td><span class="badge badge-success" style="font-size:11px">GET</span></td>
                        <td>
                            <code style="font-size:12px">/api/nowplaying.php?slug=<?= $estacion['slug'] ?></code>
                        </td>
                        <td style="font-size:13px">Informaci&oacute;n completa de la estaci&oacute;n y canci&oacute;n actual</td>
                        <td>
                            <div style="display:flex;gap:3px">
                                <button class="btn btn-outline btn-sm" onclick="copyField('api-main',this)">Copiar</button>
                                <a href="<?= $baseApi ?>/api/nowplaying.php?slug=<?= $estacion['slug'] ?>" target="_blank" class="btn btn-outline btn-sm">Abrir</a>
                            </div>
                        </td>
                    </tr>
                    <tr>
                        <td><span class="badge badge-success" style="font-size:11px">GET</span></td>
                        <td>
                            <code style="font-size:12px">/api/nowplaying.php?estacion=<?= $estacion['id'] ?></code>
                            <input type="hidden" id="api-by-id" value="<?= $baseApi ?>/api/nowplaying.php?estacion=<?= $estacion['id'] ?>">
                        </td>
                        <td style="font-size:13px">Now Playing por ID de estaci&oacute;n</td>
                        <td>
                            <div style="display:flex;gap:3px">
                                <button class="btn btn-outline btn-sm" onclick="copyField('api-by-id',this)">Copiar</button>
                                <a href="<?= $baseApi ?>/api/nowplaying.php?estacion=<?= $estacion['id'] ?>" target="_blank" class="btn btn-outline btn-sm">Abrir</a>
                            </div>
                        </td>
                    </tr>
                    <tr>
                        <td><span class="badge badge-success" style="font-size:11px">GET</span></td>
                        <td>
                            <code style="font-size:12px">/api/stats.php?estacion=<?= $estacion['id'] ?></code>
                            <input type="hidden" id="api-stats" value="<?= $baseApi ?>/api/stats.php?estacion=<?= $estacion['id'] ?>">
                        </td>
                        <td style="font-size:13px">Estad&iacute;sticas r&aacute;pidas (oyentes, estado, t&iacute;tulo)</td>
                        <td>
                            <div style="display:flex;gap:3px">
                                <button class="btn btn-outline btn-sm" onclick="copyField('api-stats',this)">Copiar</button>
                                <a href="<?= $baseApi ?>/api/stats.php?estacion=<?= $estacion['id'] ?>" target="_blank" class="btn btn-outline btn-sm">Abrir</a>
                            </div>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Archivos Stream + URL directa -->
<div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;margin-bottom:20px">
    <div class="card">
        <div class="card-header">
            <h3><?= cpIcon('folder',18) ?> Archivos de Stream</h3>
        </div>
        <div class="card-body">
            <?php
            $formats = [
                'm3u'  => ['label' => 'M3U Playlist',  'icon' => cpIcon('music',16)],
                'pls'  => ['label' => 'PLS Playlist',  'icon' => cpIcon('list',16)],
                'xspf' => ['label' => 'XSPF Playlist', 'icon' => cpIcon('note',16)],
            ];
            foreach ($formats as $fmt => $info):
                $url = $baseApi . '/api/stream.php?station=' . $estacion['slug'] . '&format=' . $fmt;
            ?>
            <div style="display:flex;align-items:center;gap:8px;margin-bottom:8px">
                <span><?= $info['icon'] ?></span>
                <input type="text" id="api-<?= $fmt ?>" class="form-control" readonly value="<?= htmlspecialchars($url) ?>" style="flex:1;font-size:12px;font-family:monospace">
                <button class="btn btn-outline btn-sm" onclick="copyField('api-<?= $fmt ?>',this)">Copiar</button>
            </div>
            <?php endforeach; ?>
        </div>
    </div>

    <div class="card">
        <div class="card-header">
            <h3><?= cpIcon('link',18) ?> URLs de Stream</h3>
        </div>
        <div class="card-body">
            <div style="display:flex;align-items:center;gap:8px;margin-bottom:8px">
                <span style="background:#059669;color:#fff;font-size:10px;padding:2px 6px;border-radius:3px;font-weight:700">HTTPS</span>
                <input type="text" id="api-stream-ssl" class="form-control" readonly value="<?= $streamUrl ?>" style="flex:1;font-size:12px;font-family:monospace">
                <button class="btn btn-outline btn-sm" onclick="copyField('api-stream-ssl',this)">Copiar</button>
            </div>
            <div style="display:flex;align-items:center;gap:8px">
                <span style="background:#d97706;color:#fff;font-size:10px;padding:2px 6px;border-radius:3px;font-weight:700">HTTP</span>
                <input type="text" id="api-stream-http" class="form-control" readonly value="http://<?= ICECAST_HOST ?>:<?= $estacion['icecast_port'] ?><?= $estacion['icecast_mount'] ?>" style="flex:1;font-size:12px;font-family:monospace">
                <button class="btn btn-outline btn-sm" onclick="copyField('api-stream-http',this)">Copiar</button>
            </div>
        </div>
    </div>
</div>

<!-- Ejemplo de uso -->
<div class="card">
    <div class="card-header">
        <h3><?= cpIcon('code',18) ?> Ejemplo de Integraci&oacute;n (JavaScript)</h3>
    </div>
    <div class="card-body">
        <pre style="background:#1e293b;color:#e2e8f0;padding:16px;border-radius:8px;overflow-x:auto;font-size:13px;font-family:'Fira Code',monospace"><span style="color:#93c5fd">// Obtener información de "Now Playing"</span>
<span style="color:#c084fc">fetch</span>(<span style="color:#86efac">'<?= $nowplayingUrl ?>'</span>)
  .<span style="color:#c084fc">then</span>(res =&gt; res.<span style="color:#c084fc">json</span>())
  .<span style="color:#c084fc">then</span>(data =&gt; {
    console.<span style="color:#c084fc">log</span>(<span style="color:#86efac">'Estación:'</span>, data.station.name);
    console.<span style="color:#c084fc">log</span>(<span style="color:#86efac">'En vivo:'</span>, data.live.online);
    console.<span style="color:#c084fc">log</span>(<span style="color:#86efac">'Oyentes:'</span>, data.live.listeners);
    console.<span style="color:#c084fc">log</span>(<span style="color:#86efac">'Sonando:'</span>, data.now_playing?.title);
  });

<span style="color:#93c5fd">// Actualizar estadísticas cada 30 segundos</span>
<span style="color:#c084fc">setInterval</span>(() =&gt; {
  <span style="color:#c084fc">fetch</span>(<span style="color:#86efac">'<?= $baseApi ?>/api/stats.php?estacion=<?= $estacion['id'] ?>'</span>)
    .<span style="color:#c084fc">then</span>(r =&gt; r.<span style="color:#c084fc">json</span>())
    .<span style="color:#c084fc">then</span>(d =&gt; {
      document.getElementById(<span style="color:#86efac">'listeners'</span>).textContent = d.oyentes;
      document.getElementById(<span style="color:#86efac">'title'</span>).textContent = d.titulo;
    });
}, <span style="color:#fbbf24">30000</span>);</pre>
    </div>
</div>

<script>
function loadPreview() {
    const pre = document.getElementById('jsonPreview');
    pre.textContent = 'Cargando...';
    fetch('<?= $nowplayingUrl ?>')
        .then(r => r.json())
        .then(data => {
            pre.textContent = JSON.stringify(data, null, 2);
        })
        .catch(() => {
            pre.textContent = '{\n  "error": "No se pudo conectar al API",\n  "nota": "Verifique que el servidor de streaming esté activo"\n}';
        });
}
loadPreview();
</script>

<?php require_once __DIR__ . '/../includes/layout_footer.php'; ?>
