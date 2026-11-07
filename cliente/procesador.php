<?php
ob_start();
/**
 * CastPanel v2.3 - Procesador de Audio
 * Interfaz cliente para configurar AGC, Compresor, EQ, Limitador
 * UPDATE: Todos los iconos actualizados a SVG profesionales
 */
$pageTitle = 'Procesador de Audio';
$pageIcon = 'speaker';
require_once __DIR__ . '/../includes/layout_header.php';
require_once __DIR__ . '/../includes/audio_processor.php';

if (!$estacion) {
    echo '<div class="alert alert-warning">Sin estaci&oacute;n asignada.</div>';
    require_once __DIR__ . '/../includes/layout_footer.php';
    exit;
}

$db = getDB();
$proc = [];
$presets = getProcessorPresets();
$_tableError = false;

try {

$proc = getProcessorConfig($estacion['id']);

// Procesar formulario
if ($_SERVER['REQUEST_METHOD'] === 'POST' && verifyCsrf()) {
    $action = $_POST['action'] ?? '';

    if ($action === 'apply_preset') {
        $presetKey = $_POST['preset'] ?? 'standard';
        if (applyProcessorPreset($estacion['id'], $presetKey)) {
            // v7.4: Solo regenerar Liquidsoap si la estación tiene AutoDJ
            if (!empty($estacion['autodj_enabled'])) {
                require_once __DIR__ . '/../includes/provisioning.php';
                $stData = $db->prepare("SELECT * FROM estaciones WHERE id = ?");
                $stData->execute([$estacion['id']]);
                $stRow = $stData->fetch();
                if ($stRow) {
                    generateLiquidsoapConfig($stRow);
                    restartLiquidsoapService($stRow);
                }
                setFlash('success', 'Preset "' . htmlspecialchars($presets[$presetKey]['nombre'] ?? $presetKey) . '" aplicado correctamente. La estaci&oacute;n se reiniciar&aacute; para aplicar los cambios.');
            } else {
                setFlash('success', 'Preset "' . htmlspecialchars($presets[$presetKey]['nombre'] ?? $presetKey) . '" guardado correctamente. Se aplicar&aacute; cuando se active AutoDJ.');
            }
        } else {
            setFlash('error', 'Error al aplicar el preset.');
        }
        header('Location: procesador.php');
        exit;
    }

    if ($action === 'save_custom') {
        $data = [
            'enabled'    => isset($_POST['enabled']) ? 1 : 0,
            'preset'     => 'custom',
            // AGC
            'agc_enabled'  => isset($_POST['agc_enabled']) ? 1 : 0,
            'agc_target'   => max(-30, min(0, (float)($_POST['agc_target'] ?? -14))),
            'agc_gain_min' => max(-20, min(0, (float)($_POST['agc_gain_min'] ?? -6))),
            'agc_gain_max' => max(0, min(20, (float)($_POST['agc_gain_max'] ?? 6))),
            // Compresor
            'comp_enabled'   => isset($_POST['comp_enabled']) ? 1 : 0,
            'comp_threshold' => max(-60, min(0, (float)($_POST['comp_threshold'] ?? -15))),
            'comp_ratio'     => max(1, min(20, (float)($_POST['comp_ratio'] ?? 3))),
            'comp_attack'    => max(0.01, min(100, (float)($_POST['comp_attack'] ?? 10))),
            'comp_release'   => max(1, min(1000, (float)($_POST['comp_release'] ?? 100))),
            'comp_gain'      => max(0, min(20, (float)($_POST['comp_gain'] ?? 3))),
            'comp_knee'      => max(0, min(20, (float)($_POST['comp_knee'] ?? 3))),
            // EQ
            'eq_enabled'   => isset($_POST['eq_enabled']) ? 1 : 0,
            'eq_low_freq'  => max(60, min(500, (int)($_POST['eq_low_freq'] ?? 200))),
            'eq_low_gain'  => max(-12, min(12, (float)($_POST['eq_low_gain'] ?? 0))),
            'eq_mid_freq'  => max(500, min(5000, (int)($_POST['eq_mid_freq'] ?? 2500))),
            'eq_mid_gain'  => max(-12, min(12, (float)($_POST['eq_mid_gain'] ?? 0))),
            'eq_mid_q'     => max(0.1, min(10, (float)($_POST['eq_mid_q'] ?? 1))),
            'eq_high_freq' => max(5000, min(16000, (int)($_POST['eq_high_freq'] ?? 8000))),
            'eq_high_gain' => max(-12, min(12, (float)($_POST['eq_high_gain'] ?? 0))),
            // Limitador
            'limiter_enabled'   => isset($_POST['limiter_enabled']) ? 1 : 0,
            'limiter_threshold' => max(-10, min(0, (float)($_POST['limiter_threshold'] ?? -1))),
            'limiter_attack'    => max(0.01, min(10, (float)($_POST['limiter_attack'] ?? 0.1))),
            'limiter_release'   => max(1, min(100, (float)($_POST['limiter_release'] ?? 10))),
            // Salida
            'output_gain'     => max(-12, min(12, (float)($_POST['output_gain'] ?? 0))),
            'stereo_enhance'  => isset($_POST['stereo_enhance']) ? 1 : 0,
        ];

        if (saveProcessorConfig($estacion['id'], $data)) {
            // v7.4: Solo regenerar Liquidsoap si la estación tiene AutoDJ
            if (!empty($estacion['autodj_enabled'])) {
                require_once __DIR__ . '/../includes/provisioning.php';
                $stData = $db->prepare("SELECT * FROM estaciones WHERE id = ?");
                $stData->execute([$estacion['id']]);
                $stRow = $stData->fetch();
                if ($stRow) {
                    generateLiquidsoapConfig($stRow);
                    restartLiquidsoapService($stRow);
                }
                setFlash('success', 'Configuraci&oacute;n personalizada guardada. La estaci&oacute;n se reiniciar&aacute; para aplicar los cambios.');
            } else {
                setFlash('success', 'Configuraci&oacute;n personalizada guardada. Se aplicar&aacute; cuando se active AutoDJ.');
            }
        } else {
            setFlash('error', 'Error al guardar la configuraci&oacute;n.');
        }
        header('Location: procesador.php');
        exit;
    }
}

// Recargar config actual
$proc = getProcessorConfig($estacion['id']);
} catch (PDOException $e) {
    $_tableError = true;
}
$currentPreset = $proc['preset'] ?? 'standard';

// --- Iconos SVG profesionales ---
function procSvg($name, $size = 18) {
    $s = (int)$size;
    $icons = [
        // Procesador / Speaker - ondas de audio
        'processor' => '<svg width="'.$s.'" height="'.$s.'" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="4" y="2" width="16" height="20" rx="2"/><circle cx="12" cy="14" r="4"/><line x1="12" y1="6" x2="12.01" y2="6"/></svg>',
        // AGC - volumen automatico
        'agc' => '<svg width="'.$s.'" height="'.$s.'" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polygon points="11,5 6,9 2,9 2,15 6,15 11,19"/><path d="M15.54 8.46a5 5 0 010 7.07"/><path d="M19.07 4.93a10 10 0 010 14.14"/></svg>',
        // Compresor - onda comprimida
        'compressor' => '<svg width="'.$s.'" height="'.$s.'" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M2 12h4l3-9 4 18 3-9h6"/></svg>',
        // EQ - barras de ecualizador
        'eq' => '<svg width="'.$s.'" height="'.$s.'" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="4" y1="21" x2="4" y2="14"/><line x1="4" y1="10" x2="4" y2="3"/><line x1="12" y1="21" x2="12" y2="12"/><line x1="12" y1="8" x2="12" y2="3"/><line x1="20" y1="21" x2="20" y2="16"/><line x1="20" y1="12" x2="20" y2="3"/><line x1="1" y1="14" x2="7" y2="14"/><line x1="9" y1="8" x2="15" y2="8"/><line x1="17" y1="16" x2="23" y2="16"/></svg>',
        // Limitador - escudo/barrera
        'limiter' => '<svg width="'.$s.'" height="'.$s.'" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>',
        // Ganancia de salida - volumen alto
        'output' => '<svg width="'.$s.'" height="'.$s.'" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polygon points="11,5 6,9 2,9 2,15 6,15 11,19"/><path d="M19.07 4.93a10 10 0 010 14.14"/><path d="M15.54 8.46a5 5 0 010 7.07"/></svg>',
        // Presets - rejilla/cuadricula
        'presets' => '<svg width="'.$s.'" height="'.$s.'" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/></svg>',
        // Ajustes - engranaje
        'settings' => '<svg width="'.$s.'" height="'.$s.'" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 00.33 1.82l.06.06a2 2 0 010 2.83 2 2 0 01-2.83 0l-.06-.06a1.65 1.65 0 00-1.82-.33 1.65 1.65 0 00-1 1.51V21a2 2 0 01-4 0v-.09A1.65 1.65 0 009 19.4a1.65 1.65 0 00-1.82.33l-.06.06a2 2 0 01-2.83-2.83l.06-.06A1.65 1.65 0 004.68 15a1.65 1.65 0 00-1.51-1H3a2 2 0 010-4h.09A1.65 1.65 0 004.6 9a1.65 1.65 0 00-.33-1.82l-.06-.06a2 2 0 112.83-2.83l.06.06A1.65 1.65 0 009 4.68a1.65 1.65 0 001-1.51V3a2 2 0 014 0v.09a1.65 1.65 0 001 1.51 1.65 1.65 0 001.82-.33l.06-.06a2 2 0 012.83 2.83l-.06.06a1.65 1.65 0 00-.33 1.82V9c.26.604.852.997 1.51 1H21a2 2 0 010 4h-.09a1.65 1.65 0 00-1.51 1z"/></svg>',
        // Flecha derecha - cadena
        'arrow' => '<svg width="'.$s.'" height="'.$s.'" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="9,18 15,12 9,6"/></svg>',
        // Guardar
        'save' => '<svg width="'.$s.'" height="'.$s.'" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M19 21H5a2 2 0 01-2-2V5a2 2 0 012-2h11l5 5v11a2 2 0 01-2 2z"/><polyline points="17,21 17,13 7,13 7,21"/><polyline points="7,3 7,8 15,8"/></svg>',
        // Info
        'info' => '<svg width="'.$s.'" height="'.$s.'" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="12" y1="16" x2="12" y2="12"/><line x1="12" y1="8" x2="12.01" y2="8"/></svg>',
        // Warning
        'warning' => '<svg width="'.$s.'" height="'.$s.'" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>',
    ];
    return $icons[$name] ?? '';
}

// Iconos SVG para presets
function presetSvgIcon($key) {
    $icons = [
        'disabled' => '<svg width="36" height="36" viewBox="0 0 24 24" fill="none" stroke="#94a3b8" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="4.93" y1="4.93" x2="19.07" y2="19.07"/></svg>',
        'standard' => '<svg width="36" height="36" viewBox="0 0 24 24" fill="none" stroke="#6c3ce0" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><polygon points="11,5 6,9 2,9 2,15 6,15 11,19"/><path d="M15.54 8.46a5 5 0 010 7.07"/></svg>',
        'voice' => '<svg width="36" height="36" viewBox="0 0 24 24" fill="none" stroke="#0ea5e9" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M12 1a3 3 0 00-3 3v8a3 3 0 006 0V4a3 3 0 00-3-3z"/><path d="M19 10v2a7 7 0 01-14 0v-2"/><line x1="12" y1="19" x2="12" y2="23"/><line x1="8" y1="23" x2="16" y2="23"/></svg>',
        'loud' => '<svg width="36" height="36" viewBox="0 0 24 24" fill="none" stroke="#ef4444" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><polygon points="11,5 6,9 2,9 2,15 6,15 11,19"/><path d="M15.54 8.46a5 5 0 010 7.07"/><path d="M19.07 4.93a10 10 0 010 14.14"/></svg>',
        'music' => '<svg width="36" height="36" viewBox="0 0 24 24" fill="none" stroke="#f59e0b" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M9 18V5l12-2v13"/><circle cx="6" cy="18" r="3"/><circle cx="18" cy="16" r="3"/></svg>',
        'bass_boost' => '<svg width="36" height="36" viewBox="0 0 24 24" fill="none" stroke="#8b5cf6" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><line x1="4" y1="21" x2="4" y2="8"/><line x1="8" y1="21" x2="8" y2="14"/><line x1="12" y1="21" x2="12" y2="11"/><line x1="16" y1="21" x2="16" y2="16"/><line x1="20" y1="21" x2="20" y2="18"/></svg>',
        'custom' => '<svg width="36" height="36" viewBox="0 0 24 24" fill="none" stroke="#10b981" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 00.33 1.82l.06.06a2 2 0 010 2.83 2 2 0 01-2.83 0l-.06-.06a1.65 1.65 0 00-1.82-.33 1.65 1.65 0 00-1 1.51V21a2 2 0 01-4 0v-.09A1.65 1.65 0 009 19.4a1.65 1.65 0 00-1.82.33l-.06.06a2 2 0 01-2.83-2.83l.06-.06A1.65 1.65 0 004.68 15a1.65 1.65 0 00-1.51-1H3a2 2 0 010-4h.09A1.65 1.65 0 004.6 9a1.65 1.65 0 00-.33-1.82l-.06-.06a2 2 0 112.83-2.83l.06.06A1.65 1.65 0 009 4.68a1.65 1.65 0 001-1.51V3a2 2 0 014 0v.09a1.65 1.65 0 001 1.51 1.65 1.65 0 001.82-.33l.06-.06a2 2 0 012.83 2.83l-.06.06a1.65 1.65 0 00-.33 1.82V9c.26.604.852.997 1.51 1H21a2 2 0 010 4h-.09a1.65 1.65 0 00-1.51 1z"/></svg>',
    ];
    return $icons[$key] ?? $icons['standard'];
}
?>

<?php if ($_tableError): ?>
<div class="alert alert-warning"><?= procSvg('warning',14) ?> La tabla del procesador de audio no est&aacute; disponible. Ejecute la migraci&oacute;n SQL: <code>mysql -u root -p castpanel &lt; /var/www/castpanel/install/schema_update_v2.3.sql</code></div>
<?php endif; ?>

<style>
/* Procesador de Audio */
.proc-status {
    display: flex;
    align-items: center;
    gap: 16px;
    padding: 20px;
    border-radius: var(--radius-md);
    margin-bottom: 24px;
}
.proc-status.active {
    background: linear-gradient(135deg, rgba(34,197,94,.12), rgba(16,185,129,.08));
    border: 1px solid rgba(34,197,94,.25);
}
.proc-status.inactive {
    background: var(--card-bg);
    border: 1px solid var(--border);
}
.proc-status-icon {
    width: 48px; height: 48px;
    border-radius: 12px;
    display: flex; align-items: center; justify-content: center;
}
.proc-status.active .proc-status-icon { background: rgba(34,197,94,.15); color: #16a34a; }
.proc-status.inactive .proc-status-icon { background: var(--bg-secondary); color: var(--text-secondary); }
.proc-status-info h3 { font-size: 15px; font-weight: 700; margin: 0 0 2px 0; }
.proc-status-info p { font-size: 12px; color: var(--text-secondary); margin: 0; }

/* Presets Grid */
.presets-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(180px, 1fr));
    gap: 12px;
    margin-bottom: 32px;
}
.preset-card {
    background: var(--card-bg);
    border: 2px solid var(--border);
    border-radius: var(--radius-md);
    padding: 16px;
    cursor: pointer;
    transition: var(--transition);
    text-align: center;
}
.preset-card:hover { border-color: var(--primary); transform: translateY(-2px); }
.preset-card.active {
    border-color: var(--primary);
    background: rgba(108,60,224,.06);
    box-shadow: 0 0 0 3px rgba(108,60,224,.12);
}
.preset-card .preset-icon { margin-bottom: 8px; display: flex; justify-content: center; }
.preset-card .preset-name { font-size: 14px; font-weight: 700; margin-bottom: 4px; }
.preset-card .preset-desc { font-size: 11px; color: var(--text-secondary); line-height: 1.4; }

/* Secciones del procesador */
.proc-section {
    background: var(--card-bg);
    border: 1px solid var(--border);
    border-radius: var(--radius-md);
    margin-bottom: 16px;
    overflow: hidden;
}
.proc-section-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 14px 18px;
    cursor: pointer;
    user-select: none;
    background: var(--bg-secondary);
    border-bottom: 1px solid var(--border);
}
.proc-section-header:hover { background: rgba(108,60,224,.04); }
.proc-section-title {
    display: flex; align-items: center; gap: 10px;
    font-weight: 700; font-size: 14px;
}
.proc-section-title .badge {
    font-size: 10px;
    padding: 2px 8px;
    border-radius: 10px;
    font-weight: 600;
}
.badge-on { background: rgba(34,197,94,.15); color: #16a34a; }
.badge-off { background: var(--bg-secondary); color: var(--text-secondary); border: 1px solid var(--border); }
.proc-section-toggle { font-size: 12px; color: var(--text-secondary); transition: transform .2s; }
.proc-section-body { padding: 18px; display: none; }
.proc-section.open .proc-section-body { display: block; }
.proc-section.open .proc-section-toggle { transform: rotate(180deg); }

/* Controls Grid */
.controls-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(260px, 1fr));
    gap: 16px;
}
.control-item {
    background: var(--bg-secondary);
    border-radius: var(--radius-sm);
    padding: 14px;
}
.control-item label {
    display: flex; justify-content: space-between; align-items: baseline;
    font-size: 12px; font-weight: 600; margin-bottom: 8px;
    color: var(--text-secondary);
}
.control-item label .val {
    font-weight: 700; color: var(--primary); font-size: 13px;
    min-width: 50px; text-align: right;
}
.control-item input[type="range"] {
    width: 100%;
    -webkit-appearance: none;
    height: 6px;
    border-radius: 3px;
    background: var(--border);
    outline: none;
}
.control-item input[type="range"]::-webkit-slider-thumb {
    -webkit-appearance: none;
    width: 18px; height: 18px;
    border-radius: 50%;
    background: var(--primary);
    cursor: pointer;
    box-shadow: 0 1px 4px rgba(108,60,224,.3);
}
.control-item .hint {
    font-size: 10px; color: var(--text-secondary); margin-top: 4px;
}

/* EQ Visual */
.eq-visual {
    display: flex;
    align-items: flex-end;
    justify-content: center;
    gap: 4px;
    height: 80px;
    background: var(--bg-secondary);
    border-radius: var(--radius-sm);
    padding: 12px;
    margin-bottom: 16px;
}
.eq-bar {
    width: 28px;
    border-radius: 4px 4px 0 0;
    transition: height .3s, background .3s;
    min-height: 4px;
}
.eq-bar.low { background: #ef4444; }
.eq-bar.mid { background: #f59e0b; }
.eq-bar.high { background: #3b82f6; }
.eq-labels {
    display: flex; justify-content: center; gap: 4px; margin-bottom: 8px;
}
.eq-labels span {
    width: 28px; text-align: center;
    font-size: 9px; color: var(--text-secondary); font-weight: 600;
}

/* Processing chain */
.proc-chain {
    display: flex;
    align-items: center;
    gap: 4px;
    flex-wrap: wrap;
    padding: 16px;
    background: var(--bg-secondary);
    border-radius: var(--radius-sm);
    margin-bottom: 24px;
}
.proc-chain-step {
    display: flex; align-items: center; gap: 6px;
    padding: 6px 12px;
    border-radius: 20px;
    font-size: 11px; font-weight: 600;
    white-space: nowrap;
}
.proc-chain-step.on { background: rgba(34,197,94,.12); color: #16a34a; }
.proc-chain-step.off { background: var(--card-bg); color: var(--text-secondary); border: 1px solid var(--border); }
.proc-chain-arrow { color: var(--text-secondary); font-size: 14px; display: flex; align-items: center; }

/* Toggle switch */
.toggle-wrap {
    display: flex; align-items: center; gap: 10px;
    margin-bottom: 12px;
}
.toggle-switch {
    position: relative;
    width: 44px; height: 24px;
}
.toggle-switch input { opacity: 0; width: 0; height: 0; }
.toggle-slider {
    position: absolute; inset: 0;
    background: var(--border);
    border-radius: 24px;
    cursor: pointer;
    transition: .2s;
}
.toggle-slider:before {
    content: '';
    position: absolute;
    width: 18px; height: 18px;
    left: 3px; bottom: 3px;
    background: #fff;
    border-radius: 50%;
    transition: .2s;
}
.toggle-switch input:checked + .toggle-slider { background: var(--primary); }
.toggle-switch input:checked + .toggle-slider:before { transform: translateX(20px); }
</style>

<!-- Estado del procesador -->
<div class="proc-status <?= $proc['enabled'] ? 'active' : 'inactive' ?>">
    <div class="proc-status-icon"><?= procSvg('processor', 24) ?></div>
    <div class="proc-status-info">
        <h3><?= $proc['enabled'] ? 'Procesador Activo' : 'Procesador Desactivado' ?></h3>
        <p>
            <?php if ($proc['enabled']): ?>
                Preset: <strong><?= htmlspecialchars($presets[$currentPreset]['nombre'] ?? 'Desconocido') ?></strong>
                &mdash; Funciona en AutoDJ y DJ en vivo
            <?php else: ?>
                Activa el procesador para que tu transmisi&oacute;n suene con m&aacute;s fuerza y calidad profesional
            <?php endif; ?>
        </p>
    </div>
</div>

<!-- Cadena de procesamiento visual -->
<?php if ($proc['enabled']): ?>
<div class="proc-chain">
    <span style="font-size:12px;font-weight:700;margin-right:8px">Cadena:</span>
    <div class="proc-chain-step <?= $proc['agc_enabled'] ? 'on' : 'off' ?>">
        <?= procSvg('agc', 14) ?> AGC
    </div>
    <span class="proc-chain-arrow"><?= procSvg('arrow', 12) ?></span>
    <div class="proc-chain-step <?= $proc['comp_enabled'] ? 'on' : 'off' ?>">
        <?= procSvg('compressor', 14) ?> Compresor
    </div>
    <span class="proc-chain-arrow"><?= procSvg('arrow', 12) ?></span>
    <div class="proc-chain-step <?= $proc['eq_enabled'] ? 'on' : 'off' ?>">
        <?= procSvg('eq', 14) ?> EQ 3 Bandas
    </div>
    <span class="proc-chain-arrow"><?= procSvg('arrow', 12) ?></span>
    <div class="proc-chain-step <?= $proc['limiter_enabled'] ? 'on' : 'off' ?>">
        <?= procSvg('limiter', 14) ?> Limitador
    </div>
    <span class="proc-chain-arrow"><?= procSvg('arrow', 12) ?></span>
    <div class="proc-chain-step <?= ($proc['output_gain'] ?? 0) != 0 ? 'on' : 'off' ?>">
        <?= procSvg('output', 14) ?> Ganancia
    </div>
</div>
<?php endif; ?>

<!-- Presets -->
<h3 style="font-size:16px;font-weight:700;margin-bottom:16px;display:flex;align-items:center;gap:8px"><?= procSvg('presets', 18) ?> Presets R&aacute;pidos</h3>
<div class="presets-grid">
    <?php foreach ($presets as $key => $preset): ?>
    <form method="POST" style="margin:0">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="apply_preset">
        <input type="hidden" name="preset" value="<?= $key ?>">
        <button type="submit" class="preset-card <?= $currentPreset === $key ? 'active' : '' ?>"
                style="width:100%;border:2px solid <?= $currentPreset === $key ? 'var(--primary)' : 'var(--border)' ?>">
            <div class="preset-icon"><?= presetSvgIcon($key) ?></div>
            <div class="preset-name"><?= htmlspecialchars($preset['nombre']) ?></div>
            <div class="preset-desc"><?= htmlspecialchars($preset['desc']) ?></div>
        </button>
    </form>
    <?php endforeach; ?>
</div>

<!-- Controles personalizados -->
<h3 style="font-size:16px;font-weight:700;margin:32px 0 16px;display:flex;align-items:center;gap:8px"><?= procSvg('settings', 18) ?> Ajustes Personalizados</h3>

<form method="POST" id="customForm">
    <?= csrfField() ?>
    <input type="hidden" name="action" value="save_custom">

    <!-- Toggle Master -->
    <div class="toggle-wrap" style="margin-bottom:20px">
        <label class="toggle-switch">
            <input type="checkbox" name="enabled" <?= $proc['enabled'] ? 'checked' : '' ?> onchange="toggleMaster(this)">
            <span class="toggle-slider"></span>
        </label>
        <span style="font-weight:700;font-size:14px">Procesador Activado</span>
    </div>

    <div id="procControls" style="<?= $proc['enabled'] ? '' : 'opacity:.5;pointer-events:none' ?>">

    <!-- AGC -->
    <div class="proc-section open">
        <div class="proc-section-header" onclick="toggleSection(this)">
            <div class="proc-section-title">
                <?= procSvg('agc', 16) ?> Control Autom&aacute;tico de Ganancia (AGC)
                <span class="badge <?= $proc['agc_enabled'] ? 'badge-on' : 'badge-off' ?>" id="badge_agc">
                    <?= $proc['agc_enabled'] ? 'ON' : 'OFF' ?>
                </span>
            </div>
            <span class="proc-section-toggle">&#x25BC;</span>
        </div>
        <div class="proc-section-body">
            <div class="toggle-wrap">
                <label class="toggle-switch">
                    <input type="checkbox" name="agc_enabled" <?= $proc['agc_enabled'] ? 'checked' : '' ?>
                           onchange="updateBadge('agc', this.checked)">
                    <span class="toggle-slider"></span>
                </label>
                <span style="font-size:13px">Activar AGC</span>
            </div>
            <div class="controls-grid">
                <div class="control-item">
                    <label>Nivel Objetivo <span class="val" id="v_agc_target"><?= $proc['agc_target'] ?> dBFS</span></label>
                    <input type="range" name="agc_target" min="-30" max="0" step="0.5"
                           value="<?= $proc['agc_target'] ?>" oninput="showVal('agc_target', this.value + ' dBFS')">
                    <div class="hint">M&aacute;s cercano a 0 = m&aacute;s fuerte</div>
                </div>
                <div class="control-item">
                    <label>Ganancia M&iacute;n <span class="val" id="v_agc_gain_min"><?= $proc['agc_gain_min'] ?> dB</span></label>
                    <input type="range" name="agc_gain_min" min="-20" max="0" step="0.5"
                           value="<?= $proc['agc_gain_min'] ?>" oninput="showVal('agc_gain_min', this.value + ' dB')">
                </div>
                <div class="control-item">
                    <label>Ganancia M&aacute;x <span class="val" id="v_agc_gain_max"><?= $proc['agc_gain_max'] ?> dB</span></label>
                    <input type="range" name="agc_gain_max" min="0" max="20" step="0.5"
                           value="<?= $proc['agc_gain_max'] ?>" oninput="showVal('agc_gain_max', this.value + ' dB')">
                </div>
            </div>
        </div>
    </div>

    <!-- Compresor -->
    <div class="proc-section">
        <div class="proc-section-header" onclick="toggleSection(this)">
            <div class="proc-section-title">
                <?= procSvg('compressor', 16) ?> Compresor de Din&aacute;mica
                <span class="badge <?= $proc['comp_enabled'] ? 'badge-on' : 'badge-off' ?>" id="badge_comp">
                    <?= $proc['comp_enabled'] ? 'ON' : 'OFF' ?>
                </span>
            </div>
            <span class="proc-section-toggle">&#x25BC;</span>
        </div>
        <div class="proc-section-body">
            <div class="toggle-wrap">
                <label class="toggle-switch">
                    <input type="checkbox" name="comp_enabled" <?= $proc['comp_enabled'] ? 'checked' : '' ?>
                           onchange="updateBadge('comp', this.checked)">
                    <span class="toggle-slider"></span>
                </label>
                <span style="font-size:13px">Activar Compresor</span>
            </div>
            <div class="controls-grid">
                <div class="control-item">
                    <label>Umbral (Threshold) <span class="val" id="v_comp_threshold"><?= $proc['comp_threshold'] ?> dB</span></label>
                    <input type="range" name="comp_threshold" min="-60" max="0" step="0.5"
                           value="<?= $proc['comp_threshold'] ?>" oninput="showVal('comp_threshold', this.value + ' dB')">
                    <div class="hint">Nivel donde inicia la compresi&oacute;n</div>
                </div>
                <div class="control-item">
                    <label>Ratio <span class="val" id="v_comp_ratio"><?= $proc['comp_ratio'] ?>:1</span></label>
                    <input type="range" name="comp_ratio" min="1" max="20" step="0.5"
                           value="<?= $proc['comp_ratio'] ?>" oninput="showVal('comp_ratio', this.value + ':1')">
                    <div class="hint">Mayor ratio = m&aacute;s compresi&oacute;n</div>
                </div>
                <div class="control-item">
                    <label>Ataque <span class="val" id="v_comp_attack"><?= $proc['comp_attack'] ?> ms</span></label>
                    <input type="range" name="comp_attack" min="0.1" max="100" step="0.1"
                           value="<?= $proc['comp_attack'] ?>" oninput="showVal('comp_attack', this.value + ' ms')">
                </div>
                <div class="control-item">
                    <label>Release <span class="val" id="v_comp_release"><?= $proc['comp_release'] ?> ms</span></label>
                    <input type="range" name="comp_release" min="1" max="1000" step="1"
                           value="<?= $proc['comp_release'] ?>" oninput="showVal('comp_release', this.value + ' ms')">
                </div>
                <div class="control-item">
                    <label>Makeup Gain <span class="val" id="v_comp_gain"><?= $proc['comp_gain'] ?> dB</span></label>
                    <input type="range" name="comp_gain" min="0" max="20" step="0.5"
                           value="<?= $proc['comp_gain'] ?>" oninput="showVal('comp_gain', this.value + ' dB')">
                    <div class="hint">Ganancia de compensaci&oacute;n</div>
                </div>
                <div class="control-item">
                    <label>Knee <span class="val" id="v_comp_knee"><?= $proc['comp_knee'] ?> dB</span></label>
                    <input type="range" name="comp_knee" min="0" max="20" step="0.5"
                           value="<?= $proc['comp_knee'] ?>" oninput="showVal('comp_knee', this.value + ' dB')">
                    <div class="hint">Transici&oacute;n suave (mayor = m&aacute;s suave)</div>
                </div>
            </div>
        </div>
    </div>

    <!-- Ecualizador -->
    <div class="proc-section">
        <div class="proc-section-header" onclick="toggleSection(this)">
            <div class="proc-section-title">
                <?= procSvg('eq', 16) ?> Ecualizador 3 Bandas
                <span class="badge <?= $proc['eq_enabled'] ? 'badge-on' : 'badge-off' ?>" id="badge_eq">
                    <?= $proc['eq_enabled'] ? 'ON' : 'OFF' ?>
                </span>
            </div>
            <span class="proc-section-toggle">&#x25BC;</span>
        </div>
        <div class="proc-section-body">
            <div class="toggle-wrap">
                <label class="toggle-switch">
                    <input type="checkbox" name="eq_enabled" <?= $proc['eq_enabled'] ? 'checked' : '' ?>
                           onchange="updateBadge('eq', this.checked)">
                    <span class="toggle-slider"></span>
                </label>
                <span style="font-size:13px">Activar Ecualizador</span>
            </div>

            <!-- Visualizacion EQ -->
            <div class="eq-visual" id="eqVisual">
                <div class="eq-bar low" id="eqBarLow" style="height:<?= 40 + ($proc['eq_low_gain'] * 3) ?>px"></div>
                <div class="eq-bar mid" id="eqBarMid" style="height:<?= 40 + ($proc['eq_mid_gain'] * 3) ?>px"></div>
                <div class="eq-bar high" id="eqBarHigh" style="height:<?= 40 + ($proc['eq_high_gain'] * 3) ?>px"></div>
            </div>
            <div class="eq-labels">
                <span>LOW</span>
                <span>MID</span>
                <span>HIGH</span>
            </div>

            <div class="controls-grid">
                <div class="control-item">
                    <label>Bajos - Frecuencia <span class="val" id="v_eq_low_freq"><?= $proc['eq_low_freq'] ?> Hz</span></label>
                    <input type="range" name="eq_low_freq" min="60" max="500" step="10"
                           value="<?= $proc['eq_low_freq'] ?>" oninput="showVal('eq_low_freq', this.value + ' Hz')">
                </div>
                <div class="control-item">
                    <label>Bajos - Ganancia <span class="val" id="v_eq_low_gain"><?= $proc['eq_low_gain'] ?> dB</span></label>
                    <input type="range" name="eq_low_gain" min="-12" max="12" step="0.5"
                           value="<?= $proc['eq_low_gain'] ?>"
                           oninput="showVal('eq_low_gain', this.value + ' dB'); updateEqBar('Low', this.value)">
                </div>
                <div class="control-item">
                    <label>Medios - Frecuencia <span class="val" id="v_eq_mid_freq"><?= $proc['eq_mid_freq'] ?> Hz</span></label>
                    <input type="range" name="eq_mid_freq" min="500" max="5000" step="50"
                           value="<?= $proc['eq_mid_freq'] ?>" oninput="showVal('eq_mid_freq', this.value + ' Hz')">
                </div>
                <div class="control-item">
                    <label>Medios - Ganancia <span class="val" id="v_eq_mid_gain"><?= $proc['eq_mid_gain'] ?> dB</span></label>
                    <input type="range" name="eq_mid_gain" min="-12" max="12" step="0.5"
                           value="<?= $proc['eq_mid_gain'] ?>"
                           oninput="showVal('eq_mid_gain', this.value + ' dB'); updateEqBar('Mid', this.value)">
                </div>
                <div class="control-item">
                    <label>Medios - Q Factor <span class="val" id="v_eq_mid_q"><?= $proc['eq_mid_q'] ?></span></label>
                    <input type="range" name="eq_mid_q" min="0.1" max="10" step="0.1"
                           value="<?= $proc['eq_mid_q'] ?>" oninput="showVal('eq_mid_q', this.value)">
                    <div class="hint">Mayor Q = banda m&aacute;s angosta</div>
                </div>
                <div class="control-item">
                    <label>Altos - Frecuencia <span class="val" id="v_eq_high_freq"><?= $proc['eq_high_freq'] ?> Hz</span></label>
                    <input type="range" name="eq_high_freq" min="5000" max="16000" step="100"
                           value="<?= $proc['eq_high_freq'] ?>" oninput="showVal('eq_high_freq', this.value + ' Hz')">
                </div>
                <div class="control-item">
                    <label>Altos - Ganancia <span class="val" id="v_eq_high_gain"><?= $proc['eq_high_gain'] ?> dB</span></label>
                    <input type="range" name="eq_high_gain" min="-12" max="12" step="0.5"
                           value="<?= $proc['eq_high_gain'] ?>"
                           oninput="showVal('eq_high_gain', this.value + ' dB'); updateEqBar('High', this.value)">
                </div>
            </div>
        </div>
    </div>

    <!-- Limitador -->
    <div class="proc-section">
        <div class="proc-section-header" onclick="toggleSection(this)">
            <div class="proc-section-title">
                <?= procSvg('limiter', 16) ?> Limitador de Picos
                <span class="badge <?= $proc['limiter_enabled'] ? 'badge-on' : 'badge-off' ?>" id="badge_limiter">
                    <?= $proc['limiter_enabled'] ? 'ON' : 'OFF' ?>
                </span>
            </div>
            <span class="proc-section-toggle">&#x25BC;</span>
        </div>
        <div class="proc-section-body">
            <div class="toggle-wrap">
                <label class="toggle-switch">
                    <input type="checkbox" name="limiter_enabled" <?= $proc['limiter_enabled'] ? 'checked' : '' ?>
                           onchange="updateBadge('limiter', this.checked)">
                    <span class="toggle-slider"></span>
                </label>
                <span style="font-size:13px">Activar Limitador</span>
            </div>
            <div class="controls-grid">
                <div class="control-item">
                    <label>Umbral <span class="val" id="v_limiter_threshold"><?= $proc['limiter_threshold'] ?> dBFS</span></label>
                    <input type="range" name="limiter_threshold" min="-10" max="0" step="0.1"
                           value="<?= $proc['limiter_threshold'] ?>" oninput="showVal('limiter_threshold', this.value + ' dBFS')">
                    <div class="hint">Nivel m&aacute;ximo permitido antes de limitar</div>
                </div>
                <div class="control-item">
                    <label>Ataque <span class="val" id="v_limiter_attack"><?= $proc['limiter_attack'] ?> ms</span></label>
                    <input type="range" name="limiter_attack" min="0.01" max="10" step="0.01"
                           value="<?= $proc['limiter_attack'] ?>" oninput="showVal('limiter_attack', this.value + ' ms')">
                </div>
                <div class="control-item">
                    <label>Release <span class="val" id="v_limiter_release"><?= $proc['limiter_release'] ?> ms</span></label>
                    <input type="range" name="limiter_release" min="1" max="100" step="0.5"
                           value="<?= $proc['limiter_release'] ?>" oninput="showVal('limiter_release', this.value + ' ms')">
                </div>
            </div>
        </div>
    </div>

    <!-- Ganancia de Salida -->
    <div class="proc-section">
        <div class="proc-section-header" onclick="toggleSection(this)">
            <div class="proc-section-title">
                <?= procSvg('output', 16) ?> Ganancia de Salida y Extras
            </div>
            <span class="proc-section-toggle">&#x25BC;</span>
        </div>
        <div class="proc-section-body">
            <div class="controls-grid">
                <div class="control-item">
                    <label>Ganancia Final <span class="val" id="v_output_gain"><?= $proc['output_gain'] ?> dB</span></label>
                    <input type="range" name="output_gain" min="-12" max="12" step="0.5"
                           value="<?= $proc['output_gain'] ?>" oninput="showVal('output_gain', this.value + ' dB')">
                    <div class="hint">Ajuste final de volumen de transmisi&oacute;n</div>
                </div>
                <div class="control-item">
                    <label style="margin-bottom:0">Ensanchamiento Est&eacute;reo</label>
                    <div class="toggle-wrap" style="margin:10px 0 0 0">
                        <label class="toggle-switch">
                            <input type="checkbox" name="stereo_enhance" <?= $proc['stereo_enhance'] ? 'checked' : '' ?>>
                            <span class="toggle-slider"></span>
                        </label>
                        <span style="font-size:12px;color:var(--text-secondary)">Aumenta la imagen est&eacute;reo</span>
                    </div>
                </div>
            </div>
        </div>
    </div>

    </div><!-- /procControls -->

    <div style="display:flex;gap:12px;margin-top:24px">
        <button type="submit" class="btn btn-primary btn-lg">
            <?= procSvg('save', 16) ?> Guardar Configuraci&oacute;n Personalizada
        </button>
    </div>
</form>

<!-- Info -->
<div style="background:var(--bg-secondary);border-radius:var(--radius-sm);padding:16px;margin-top:24px">
    <p style="font-size:12px;color:var(--text-secondary);margin:0;line-height:1.6">
        <strong><?= procSvg('info', 14) ?> Informaci&oacute;n:</strong>
        <?php if (!empty($estacion['autodj_enabled'])): ?>
            El procesador de audio funciona tanto en modo AutoDJ como cuando un DJ est&aacute; en vivo.
            Al guardar cambios, la estaci&oacute;n se reiniciar&aacute; brevemente para aplicar la nueva configuraci&oacute;n.
        <?php else: ?>
            Los ajustes del procesador se guardan en la base de datos. Para aplicar el procesamiento en tiempo real,
            active AutoDJ en el paquete de esta estaci&oacute;n. Una vez activado, Liquidsoap aplicar&aacute; la cadena
            de procesamiento autom&aacute;ticamente.
        <?php endif; ?>
        El procesador usa una cadena profesional: AGC &rarr; Compresor &rarr; Ecualizador &rarr; Limitador &rarr; Ganancia de Salida.
    </p>
</div>

<script>
function toggleSection(header) {
    header.closest('.proc-section').classList.toggle('open');
}

function showVal(id, text) {
    document.getElementById('v_' + id).textContent = text;
}

function updateBadge(name, on) {
    var b = document.getElementById('badge_' + name);
    if (b) {
        b.textContent = on ? 'ON' : 'OFF';
        b.className = 'badge ' + (on ? 'badge-on' : 'badge-off');
    }
}

function updateEqBar(band, gain) {
    var bar = document.getElementById('eqBar' + band);
    if (bar) {
        bar.style.height = Math.max(4, 40 + (parseFloat(gain) * 3)) + 'px';
    }
}

function toggleMaster(cb) {
    var c = document.getElementById('procControls');
    if (cb.checked) {
        c.style.opacity = '1';
        c.style.pointerEvents = 'auto';
    } else {
        c.style.opacity = '.5';
        c.style.pointerEvents = 'none';
    }
}
</script>

<?php require_once __DIR__ . '/../includes/layout_footer.php'; ?>
