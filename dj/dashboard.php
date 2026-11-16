<?php
/**
 * CastPanel DJ Portal — Dashboard (estilo SonicPanel)
 * DJ Information + Quick Control
 */
$pageTitle = 'Inicio';
require_once __DIR__ . '/includes/header.php';

// Player config (effect type)
$pcStmt = $db->prepare("SELECT effect_type FROM player_config WHERE estacion_id = ? LIMIT 1");
$pcStmt->execute([$dj['estacion_id']]);
$pc = $pcStmt->fetch(PDO::FETCH_ASSOC);
$effectType = $pc['effect_type'] ?? 'rain';

// Audio processor config
$_procConfig = [];
$_procPresets = [];
$_procCurrentPreset = 'standard';
try {
    require_once __DIR__ . '/../includes/audio_processor.php';
    $_procConfig = getProcessorConfig($dj['estacion_id']);
    $_procPresets = getProcessorPresets();
    $_procCurrentPreset = $_procConfig['preset'] ?? 'standard';
} catch (Exception $e) {
    // audio_processor table may not exist yet - graceful fallback
}

require_once __DIR__ . '/../includes/functions.php';

// Connection info (harbor IP = public host, not :2080 DJ portal host)
$connIP = castpanelPublicHost();
$autodj = !empty($dj['autodj_enabled']);
$connPort = $autodj ? ((int)($dj['puerto_stream'] ?? 8000) + 5) : ($dj['puerto_stream'] ?? 8000);
$djPassword = $autodj ? ($dj['password_plain'] ?? $dj['icecast_password'] ?? '') : ($dj['icecast_password'] ?? '');
$djConnUser = $dj['username'] ?? '';

$estacionForUrl = [
    'slug' => $dj['slug'] ?? '',
    'server_type' => $dj['server_type'] ?? 'shoutcast_v2',
];
$pageOrigin = '';
try {
    $stmt = $db->prepare("SELECT valor FROM configuracion WHERE clave = 'site_url'");
    $stmt->execute();
    $site = trim((string)$stmt->fetchColumn());
    if ($site !== '') {
        $pageOrigin = rtrim($site, '/');
    }
} catch (Throwable $e) {
    // ignore
}
if ($pageOrigin === '') {
    // This page is served on the DJ port (2080), which has no /api or
    // /shoutcast: use the main panel address (same one the CSS comes from)
    $pageOrigin = $djAssetsBase ?: castpanelPublicPageOrigin();
}
$slugEnc = rawurlencode($dj['slug'] ?? '');
$streamUrl = isShoutcastServerType($dj['server_type'] ?? '')
    ? ($pageOrigin . '/shoutcast/' . $slugEnc)
    : ($pageOrigin . '/live/' . $slugEnc);
$npUrl = $pageOrigin . '/api/nowplaying.php?estacion=' . (int)$dj['estacion_id'];

$estRow = $db->prepare('SELECT * FROM estaciones WHERE id = ?');
$estRow->execute([(int)$dj['estacion_id']]);
$estacionDj = $estRow->fetch(PDO::FETCH_ASSOC) ?: [];
$streamSt = $estacionDj ? getStreamStatus($estacionDj) : ['online' => false, 'oyentes' => 0];
$streamOnline = !empty($streamSt['online']);
?>

<div class="dj2-hero" id="djHero">
    <div class="dj2-hero-top">
        <div class="dj2-id">
            <img src="<?= $djPhoto ?>" alt="" class="dj2-avatar">
            <div>
                <div class="dj2-kicker">DJ Control Center</div>
                <h2><?= htmlspecialchars($dj['nombre'] ?: $dj['username']) ?></h2>
                <div class="dj2-sub"><?= $stationName ?></div>
            </div>
        </div>
        <div class="dj2-actions">
            <?php if ($dj['perm_start_stop']): ?>
            <button type="button" class="dj2-btn" onclick="radioControl('start')" id="btnStart">
                <svg viewBox="0 0 24 24"><polygon points="5 3 19 12 5 21 5 3"/></svg> Start radio</button>
            <button type="button" class="dj2-btn" onclick="radioControl('stop')" id="btnStop">
                <svg viewBox="0 0 24 24"><rect x="5" y="5" width="14" height="14" rx="2"/></svg> Stop</button>
            <?php endif; ?>
            <a href="widget.php" class="dj2-btn solid">
                <svg viewBox="0 0 24 24"><polyline points="15 3 21 3 21 9"/><line x1="10" y1="14" x2="21" y2="3"/><path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"/></svg> Live widget</a>
        </div>
    </div>

    <div class="dj2-np">
        <button type="button" class="dj2-listen" id="djListenBtn" onclick="djListen()" title="Listen to the station" aria-label="Listen to the station">
            <svg viewBox="0 0 24 24" id="djListenIcon"><polygon points="7 4 20 12 7 20 7 4"/></svg>
        </button>
        <div class="dj2-np-main">
            <div class="dj2-np-label">Now playing</div>
            <div class="dj2-np-title" id="npTitle">—</div>
        </div>
        <div class="dj2-np-side">
            <span class="dj2-pill <?= $streamOnline ? 'on' : '' ?>" id="radioStatus"><span class="dot"></span><span class="txt"><?= $streamOnline ? 'On air' : 'Offline' ?></span></span>
            <span class="dj2-listeners"><svg viewBox="0 0 24 24"><path d="M3 18v-6a9 9 0 0 1 18 0v6"/><path d="M21 19a2 2 0 0 1-2 2h-1v-6h3zM3 19a2 2 0 0 0 2 2h1v-6H3z"/></svg>
                <strong id="djStatListeners"><?= (int)($streamSt['oyentes'] ?? 0) ?></strong> listening</span>
        </div>
        <audio id="audioPlayer" preload="none" playsinline></audio>
    </div>
</div>

<?php if (trim((string)($estacionDj['mensaje_dj'] ?? '')) !== ''): ?>
<!-- Message from the station owner (Panel cliente > DJ Panel Message) -->
<div class="dj2-msg">
    <svg viewBox="0 0 24 24"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>
    <div><strong>Message from the station</strong><p><?= nl2br(htmlspecialchars(trim($estacionDj['mensaje_dj']))) ?></p></div>
</div>
<?php endif; ?>

<div class="dj2-grid">
    <!-- Connection details -->
    <div class="section-card dj2-conn">
        <div class="section-header">
            <svg viewBox="0 0 24 24"><path d="M12 1a3 3 0 0 0-3 3v8a3 3 0 0 0 6 0V4a3 3 0 0 0-3-3z"/><path d="M19 10v2a7 7 0 0 1-14 0v-2"/><line x1="12" y1="19" x2="12" y2="23"/></svg>
            Go live — connection details
        </div>
        <div class="section-body">
            <div class="dj2-fields">
                <div class="dj2-field"><small>Server</small><code><?= htmlspecialchars($connIP) ?></code>
                    <button type="button" onclick="djCopy(this, <?= htmlspecialchars(json_encode($connIP), ENT_QUOTES) ?>)">Copy</button></div>
                <div class="dj2-field"><small>Port</small><code><?= (int)$connPort ?></code>
                    <button type="button" onclick="djCopy(this, '<?= (int)$connPort ?>')">Copy</button></div>
                <div class="dj2-field"><small>Username</small><code><?= htmlspecialchars($djConnUser) ?></code>
                    <button type="button" onclick="djCopy(this, <?= htmlspecialchars(json_encode($djConnUser), ENT_QUOTES) ?>)">Copy</button></div>
                <div class="dj2-field"><small>Password <a href="account.php">change</a></small>
                    <code class="dj2-secret" data-secret="<?= htmlspecialchars($djPassword) ?>">••••••••</code>
                    <span class="dj2-two">
                        <button type="button" onclick="djReveal(this)">Show</button>
                        <button type="button" onclick="djCopy(this, this.closest('.dj2-field').querySelector('.dj2-secret').dataset.secret)">Copy</button>
                    </span></div>
                <div class="dj2-field"><small>Protocol</small><code>SHOUTcast v1</code><span></span></div>
                <div class="dj2-field"><small>Mobile apps password</small>
                    <code class="dj2-secret" data-secret="<?= htmlspecialchars($djConnUser . ':' . $djPassword) ?>">user:••••••</code>
                    <span class="dj2-two">
                        <button type="button" onclick="djReveal(this)">Show</button>
                        <button type="button" onclick="djCopy(this, this.closest('.dj2-field').querySelector('.dj2-secret').dataset.secret)">Copy</button>
                    </span></div>
            </div>
        </div>
    </div>

    <!-- How to broadcast -->
    <div class="section-card">
        <div class="section-header">
            <svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><line x1="12" y1="16" x2="12" y2="12"/><line x1="12" y1="8" x2="12.01" y2="8"/></svg>
            How to broadcast
        </div>
        <div class="section-body">
            <ol class="dj2-steps">
                <li><strong>Open your software</strong> — RadioCaster, BUTT, SAM, Mixxx or a mobile app.</li>
                <li><strong>Choose SHOUTcast v1</strong> as the server type (even if the station uses SHOUTcast v2 or Icecast).</li>
                <li><strong>Enter the server, port and password</strong> from the card on the left.</li>
                <li><strong>Connect</strong> — your live show replaces <?= $autodj ? 'AutoDJ' : 'the stream' ?> right away<?= $autodj ? ', and AutoDJ comes back when you disconnect' : '' ?>.</li>
            </ol>
            <p class="dj2-note">Mobile apps often have no username field: use the <em>mobile apps password</em> (<code>user:password</code>).</p>
        </div>
    </div>
</div>

<!-- ═══ QUICK CONTROL ═══ -->
<div class="section-card">
    <div class="section-header">
        <svg viewBox="0 0 24 24"><polygon points="13 2 3 14 12 14 11 22 21 10 12 10 13 2"/></svg>
        Station tools
    </div>
    <div class="tabs" data-tab-group="control">
        <?php if ($dj['perm_autodj']): ?>
        <button class="tab-btn" data-tab="tab-autodj">
            <svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><polygon points="10 8 16 12 10 16 10 8"/></svg>
            AutoDJ
        </button>
        <?php endif; ?>
        <button class="tab-btn active" data-tab="tab-history">
            <svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
            Historial
        </button>
        <button class="tab-btn" data-tab="tab-processor">
            <svg viewBox="0 0 24 24"><rect x="4" y="2" width="16" height="20" rx="2"/><circle cx="12" cy="14" r="4"/><line x1="12" y1="6" x2="12.01" y2="6"/></svg>
            Procesador
        </button>
        <button class="tab-btn" data-tab="tab-social">
            <svg viewBox="0 0 24 24"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><polyline points="22,6 12,13 2,6"/></svg>
            Social
        </button>
    </div>

    <!-- Tab: AutoDJ -->
    <?php if ($dj['perm_autodj']): ?>
    <div class="tab-panel" id="tab-autodj">
        <div class="alert alert-info">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="12" y1="16" x2="12" y2="12"/><line x1="12" y1="8" x2="12.01" y2="8"/></svg>
            <div>El AutoDJ reproduce música automáticamente cuando no hay DJ conectado. Puedes iniciar o detener el AutoDJ desde aquí.</div>
        </div>
        <div style="display:flex;gap:12px;">
            <button class="btn btn-success" onclick="autodjControl('start')">
                <svg viewBox="0 0 24 24"><polygon points="5 3 19 12 5 21 5 3"/></svg>
                Iniciar AutoDJ
            </button>
            <button class="btn btn-dark" onclick="autodjControl('stop')">
                <svg viewBox="0 0 24 24"><rect x="4" y="4" width="16" height="16" rx="2"/></svg>
                Detener AutoDJ
            </button>
        </div>
    </div>
    <?php endif; ?>

    <!-- Tab: Historial -->
    <div class="tab-panel active" id="tab-history">
        <div id="songHistory" style="min-height:60px;">
            <p style="color:var(--text-secondary);font-size:13px;">Cargando historial...</p>
        </div>
    </div>

    <!-- Tab: Social -->
    <div class="tab-panel" id="tab-social">
        <form id="socialForm" onsubmit="saveSocial(event)">
            <div class="form-grid">
                <div class="form-group">
                    <label>Email:</label>
                    <input type="email" name="email" class="form-control" value="<?= htmlspecialchars($dj['email'] ?? '') ?>" placeholder="tu@email.com">
                </div>
                <div class="form-group">
                    <label>Instagram:</label>
                    <input type="text" name="instagram" class="form-control" value="<?= htmlspecialchars($dj['instagram'] ?? '') ?>" placeholder="@usuario">
                </div>
                <div class="form-group">
                    <label>Facebook:</label>
                    <input type="text" name="facebook" class="form-control" value="<?= htmlspecialchars($dj['facebook'] ?? '') ?>" placeholder="https://facebook.com/...">
                </div>
            </div>
            <button type="submit" class="btn btn-primary btn-sm">
                <svg viewBox="0 0 24 24"><path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"/><polyline points="17 21 17 13 7 13 7 21"/></svg>
                Guardar Cambios
            </button>
        </form>
    </div>

    <!-- Tab: Procesador de Audio -->
    <div class="tab-panel" id="tab-processor">
        <div class="alert alert-info">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="12" y1="16" x2="12" y2="12"/><line x1="12" y1="8" x2="12.01" y2="8"/></svg>
            <div>
                El procesador de audio mejora la calidad de tu transmision con compresion, ecualizacion y limitador profesional.
                Selecciona un preset que se adapte a tu estilo de contenido.
                <?php if (empty($dj['autodj_enabled'])): ?>
                <br><strong>Nota:</strong> Los ajustes se guardan y se aplicaran cuando Liquidsoap este activo en la estacion.
                <?php endif; ?>
            </div>
        </div>

        <!-- Estado actual del procesador -->
        <div style="display:flex;align-items:center;gap:12px;margin-bottom:20px;padding:14px 18px;border-radius:10px;<?= !empty($_procConfig['enabled']) ? 'background:rgba(16,185,129,.08);border:1px solid rgba(16,185,129,.25)' : 'background:#f9fafb;border:1px solid var(--border)' ?>">
            <div style="width:40px;height:40px;border-radius:10px;display:flex;align-items:center;justify-content:center;<?= !empty($_procConfig['enabled']) ? 'background:rgba(16,185,129,.15);color:#16a34a' : 'background:#e5e7eb;color:#9ca3af' ?>">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="4" y="2" width="16" height="20" rx="2"/><circle cx="12" cy="14" r="4"/><line x1="12" y1="6" x2="12.01" y2="6"/></svg>
            </div>
            <div>
                <div style="font-size:14px;font-weight:700;"><?= !empty($_procConfig['enabled']) ? 'Procesador Activo' : 'Procesador Desactivado' ?></div>
                <div style="font-size:12px;color:var(--text-secondary);">
                    <?php if (!empty($_procConfig['enabled'])): ?>
                        Preset actual: <strong><?= htmlspecialchars($_procPresets[$_procCurrentPreset]['nombre'] ?? 'Desconocido') ?></strong>
                    <?php else: ?>
                        Selecciona un preset para activar el procesamiento de audio
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Grid de presets -->
        <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(160px,1fr));gap:12px;">
            <?php
            $presetIcons = [
                'off'      => ['icon' => '<svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="#94a3b8" stroke-width="1.5"><circle cx="12" cy="12" r="10"/><line x1="4.93" y1="4.93" x2="19.07" y2="19.07"/></svg>', 'color' => '#94a3b8'],
                'soft'     => ['icon' => '<svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="#6366f1" stroke-width="1.5"><path d="M9 18V5l12-2v13"/><circle cx="6" cy="18" r="3"/><circle cx="18" cy="16" r="3"/></svg>', 'color' => '#6366f1'],
                'standard' => ['icon' => '<svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="#0ea5e9" stroke-width="1.5"><polygon points="11,5 6,9 2,9 2,15 6,15 11,19"/><path d="M15.54 8.46a5 5 0 010 7.07"/></svg>', 'color' => '#0ea5e9'],
                'powerful' => ['icon' => '<svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="#ef4444" stroke-width="1.5"><polygon points="11,5 6,9 2,9 2,15 6,15 11,19"/><path d="M15.54 8.46a5 5 0 010 7.07"/><path d="M19.07 4.93a10 10 0 010 14.14"/></svg>', 'color' => '#ef4444'],
                'rock'     => ['icon' => '<svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="#f59e0b" stroke-width="1.5"><path d="M9 18V5l12-2v13"/><circle cx="6" cy="18" r="3"/><circle cx="18" cy="16" r="3"/></svg>', 'color' => '#f59e0b'],
                'talk'     => ['icon' => '<svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="#10b981" stroke-width="1.5"><path d="M12 1a3 3 0 00-3 3v8a3 3 0 006 0V4a3 3 0 00-3-3z"/><path d="M19 10v2a7 7 0 01-14 0v-2"/><line x1="12" y1="19" x2="12" y2="23"/><line x1="8" y1="23" x2="16" y2="23"/></svg>', 'color' => '#10b981'],
                'custom'   => ['icon' => '<svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="#8b5cf6" stroke-width="1.5"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 00.33 1.82l.06.06a2 2 0 010 2.83 2 2 0 01-2.83 0l-.06-.06a1.65 1.65 0 00-1.82-.33 1.65 1.65 0 00-1 1.51V21a2 2 0 01-4 0v-.09A1.65 1.65 0 009 19.4a1.65 1.65 0 00-1.82.33l-.06.06a2 2 0 01-2.83-2.83l.06-.06A1.65 1.65 0 004.68 15a1.65 1.65 0 00-1.51-1H3a2 2 0 010-4h.09A1.65 1.65 0 004.6 9"/></svg>', 'color' => '#8b5cf6'],
            ];
            foreach ($_procPresets as $key => $preset):
                $isActive = ($_procCurrentPreset === $key);
                $pi = $presetIcons[$key] ?? $presetIcons['standard'];
            ?>
            <button type="button" onclick="applyPreset('<?= $key ?>')"
                    class="proc-preset-card <?= $isActive ? 'active' : '' ?>"
                    id="preset-<?= $key ?>"
                    style="background:var(--card-bg);border:2px solid <?= $isActive ? 'var(--primary)' : 'var(--border)' ?>;border-radius:10px;padding:16px;cursor:pointer;text-align:center;transition:all .2s;font-family:inherit;<?= $isActive ? 'box-shadow:0 0 0 3px rgba(39,145,126,.15);' : '' ?>">
                <div style="display:flex;justify-content:center;margin-bottom:8px;"><?= $pi['icon'] ?></div>
                <div style="font-size:13px;font-weight:700;margin-bottom:4px;color:var(--text-dark);"><?= htmlspecialchars($preset['nombre']) ?></div>
                <div style="font-size:11px;color:var(--text-secondary);line-height:1.4;"><?= htmlspecialchars($preset['desc']) ?></div>
                <?php if ($isActive): ?>
                <div style="margin-top:8px;font-size:10px;font-weight:700;color:var(--primary);text-transform:uppercase;">Activo</div>
                <?php endif; ?>
            </button>
            <?php endforeach; ?>
        </div>

        <!-- Cadena de procesamiento visual -->
        <?php if (!empty($_procConfig['enabled'])): ?>
        <div style="display:flex;align-items:center;gap:6px;flex-wrap:wrap;padding:14px;background:#f9fafb;border-radius:8px;margin-top:16px;border:1px solid var(--border);">
            <span style="font-size:11px;font-weight:700;margin-right:4px;color:var(--text-secondary);">Cadena:</span>
            <?php
            $chainSteps = [
                ['name' => 'AGC', 'on' => !empty($_procConfig['agc_enabled'])],
                ['name' => 'Compresor', 'on' => !empty($_procConfig['comp_enabled'])],
                ['name' => 'EQ', 'on' => !empty($_procConfig['eq_enabled'])],
                ['name' => 'Limitador', 'on' => !empty($_procConfig['limiter_enabled'])],
                ['name' => 'Ganancia', 'on' => ($_procConfig['output_gain'] ?? 0) != 0],
            ];
            foreach ($chainSteps as $i => $step):
                if ($i > 0): ?>
                    <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="var(--text-secondary)" stroke-width="2"><polyline points="9,18 15,12 9,6"/></svg>
                <?php endif; ?>
                <span style="padding:4px 10px;border-radius:16px;font-size:10px;font-weight:600;white-space:nowrap;<?= $step['on'] ? 'background:rgba(16,185,129,.12);color:#16a34a;' : 'background:#fff;color:var(--text-secondary);border:1px solid var(--border);' ?>">
                    <?= $step['name'] ?>
                </span>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <p style="font-size:11px;color:var(--text-secondary);margin-top:16px;line-height:1.5;">
            Para ajustes avanzados (AGC, Compresor, EQ, Limitador) contacta al administrador de la estacion.
        </p>
    </div>
</div>

<script>
const STATION_ID = <?= (int)$dj['estacion_id'] ?>;
const NP_URL = '<?= $npUrl ?>';
const STREAM_URL = '<?= htmlspecialchars($streamUrl) ?>';

function djEsc(t) {
    return String(t == null ? '' : t).replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
}
function djSetLive(on) {
    const pill = document.getElementById('radioStatus');
    pill.classList.toggle('on', !!on);
    pill.querySelector('.txt').textContent = on ? 'On air' : 'Offline';
    document.getElementById('djHero').classList.toggle('is-on', !!on);
}
// Fetch now playing
function fetchNowPlaying() {
    fetch(NP_URL)
        .then(r => r.json())
        .then(data => {
            const np = data.now_playing || {};
            document.getElementById('npTitle').textContent = (typeof np === 'object' ? np.title : np) || '—';
            const live = data.live || {};
            const ls = document.getElementById('djStatListeners');
            if (ls && live.listeners !== undefined) ls.textContent = live.listeners;
            djSetLive(live.online);
        })
        .catch(() => djSetLive(false));
}
fetchNowPlaying();
setInterval(fetchNowPlaying, 15000);

// Listen to the station (one stream)
const djAudio = document.getElementById('audioPlayer');
function djListenIcon(playing) {
    document.getElementById('djListenIcon').innerHTML = playing
        ? '<rect x="6" y="4" width="4" height="16"/><rect x="14" y="4" width="4" height="16"/>'
        : '<polygon points="7 4 20 12 7 20 7 4"/>';
}
function djListen() {
    if (!djAudio.paused) { djAudio.pause(); djAudio.removeAttribute('src'); djAudio.load(); djListenIcon(false); return; }
    djAudio.src = STREAM_URL + (STREAM_URL.indexOf('?') >= 0 ? '&' : '?') + '_=' + Date.now();
    djAudio.play().then(() => djListenIcon(true)).catch(() => { djListenIcon(false); showToast('Could not play the stream — is the radio on air?', 'error'); });
}
djAudio.addEventListener('pause', () => djListenIcon(false));
djAudio.addEventListener('playing', () => djListenIcon(true));

function djCopy(btn, text) {
    const done = () => { const t = btn.textContent; btn.textContent = 'Copied'; setTimeout(() => btn.textContent = t, 1200); };
    if (navigator.clipboard && window.isSecureContext) { navigator.clipboard.writeText(text).then(done); return; }
    const ta = document.createElement('textarea'); ta.value = text; document.body.appendChild(ta); ta.select();
    try { document.execCommand('copy'); done(); } finally { ta.remove(); }
}
function djReveal(btn) {
    const code = btn.closest('.dj2-field').querySelector('.dj2-secret');
    const shown = btn.textContent === 'Hide';
    if (!code.dataset.mask) code.dataset.mask = code.textContent;
    code.textContent = shown ? code.dataset.mask : code.dataset.secret;
    btn.textContent = shown ? 'Show' : 'Hide';
}

// Radio control
function radioControl(action) {
    const btn = document.getElementById(action === 'start' ? 'btnStart' : 'btnStop');
    btn.disabled = true;
    btn.style.opacity = '.6';
    fetch('api/control.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({ action: action, estacion_id: STATION_ID })
    })
    .then(r => r.json())
    .then(data => {
        showToast(data.message || (action === 'start' ? 'Radio iniciada' : 'Radio detenida'), data.success ? 'success' : 'error');
        setTimeout(fetchNowPlaying, 3000);
    })
    .catch(() => showToast('Error de conexión', 'error'))
    .finally(() => { btn.disabled = false; btn.style.opacity = '1'; });
}

// AutoDJ control
function autodjControl(action) {
    fetch('api/control.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({ action: 'autodj_' + action, estacion_id: STATION_ID })
    })
    .then(r => r.json())
    .then(data => showToast(data.message || 'OK', data.success ? 'success' : 'error'))
    .catch(() => showToast('Error de conexión', 'error'));
}

// Social form
function saveSocial(e) {
    e.preventDefault();
    const form = new FormData(e.target);
    fetch('api/update.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({
            action: 'social',
            email: form.get('email'),
            instagram: form.get('instagram'),
            facebook: form.get('facebook')
        })
    })
    .then(r => r.json())
    .then(data => showToast(data.message || 'Guardado', data.success ? 'success' : 'error'))
    .catch(() => showToast('Error de conexión', 'error'));
}

// Song history
function loadHistory() {
    fetch(NP_URL)
        .then(r => r.json())
        .then(data => {
            const hist = data.history || data.hist || [];
            const el = document.getElementById('songHistory');
            if (hist.length === 0) {
                el.innerHTML = '<p style="color:var(--text-secondary);font-size:13px;">No hay historial disponible.</p>';
                return;
            }
            let html = '<table style="width:100%;border-collapse:collapse;font-size:13px;">';
            html += '<thead><tr style="border-bottom:2px solid var(--border);"><th style="text-align:left;padding:8px;">Canción</th><th style="text-align:right;padding:8px;">Hora</th></tr></thead><tbody>';
            hist.forEach(h => {
                const hTime = (h.played_at || h.time || '').replace(/^\d{4}-\d{2}-\d{2}\s/, '');
                html += '<tr style="border-bottom:1px solid var(--border);"><td style="padding:8px;">' + djEsc(h.title || h.song || '—') + (h.artist ? ' — ' + djEsc(h.artist) : '') + '</td><td style="text-align:right;padding:8px;color:var(--text-secondary);">' + djEsc(hTime) + '</td></tr>';
            });
            html += '</tbody></table>';
            el.innerHTML = html;
        })
        .catch(() => {
            document.getElementById('songHistory').innerHTML = '<p style="color:var(--text-secondary);font-size:13px;">No se pudo cargar el historial.</p>';
        });
}
loadHistory();

// Audio Processor - apply preset
function applyPreset(presetKey) {
    // Disable all preset buttons
    document.querySelectorAll('.proc-preset-card').forEach(b => {
        b.disabled = true;
        b.style.opacity = '.6';
        b.style.pointerEvents = 'none';
    });
    fetch('api/processor.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({ action: 'apply_preset', preset: presetKey })
    })
    .then(r => r.json())
    .then(data => {
        showToast(data.message || 'Preset aplicado', data.success ? 'success' : 'error');
        if (data.success) {
            // Update active state visually
            document.querySelectorAll('.proc-preset-card').forEach(b => {
                const id = b.id.replace('preset-', '');
                if (id === presetKey) {
                    b.classList.add('active');
                    b.style.borderColor = 'var(--primary)';
                    b.style.boxShadow = '0 0 0 3px rgba(39,145,126,.15)';
                    // Add or update "Activo" badge
                    let badge = b.querySelector('.proc-active-badge');
                    if (!badge) {
                        badge = document.createElement('div');
                        badge.className = 'proc-active-badge';
                        badge.style.cssText = 'margin-top:8px;font-size:10px;font-weight:700;color:var(--primary);text-transform:uppercase;';
                        b.appendChild(badge);
                    }
                    badge.textContent = 'Activo';
                } else {
                    b.classList.remove('active');
                    b.style.borderColor = 'var(--border)';
                    b.style.boxShadow = 'none';
                    const badge = b.querySelector('.proc-active-badge');
                    if (badge) badge.remove();
                }
            });
        }
    })
    .catch(() => showToast('Error de conexion', 'error'))
    .finally(() => {
        document.querySelectorAll('.proc-preset-card').forEach(b => {
            b.disabled = false;
            b.style.opacity = '1';
            b.style.pointerEvents = 'auto';
        });
    });
}
</script>

<style>
.proc-preset-card:hover {
    border-color: var(--primary) !important;
    transform: translateY(-2px);
    box-shadow: 0 4px 12px rgba(0,0,0,.08);
}
.proc-preset-card:active {
    transform: translateY(0);
}
.proc-preset-card.active:hover {
    box-shadow: 0 0 0 3px rgba(39,145,126,.15), 0 4px 12px rgba(0,0,0,.08);
}
.alert-info {
    display: flex;
    align-items: flex-start;
    gap: 12px;
    padding: 14px 16px;
    border-radius: 10px;
    background: rgba(14,165,233,.06);
    border: 1px solid rgba(14,165,233,.2);
    color: var(--text-dark);
    font-size: 13px;
    line-height: 1.5;
    margin-bottom: 20px;
}
.alert-info svg {
    flex-shrink: 0;
    width: 18px;
    height: 18px;
    color: #0ea5e9;
    margin-top: 2px;
}
</style>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
