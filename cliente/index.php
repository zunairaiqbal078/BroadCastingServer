<?php
ob_start();
/**
 * CastPanel v2.3 - Dashboard Cliente (Estilo SonicPanel)
 */
$pageTitle = 'Panel de Control';
$pageIcon = 'radio';
require_once __DIR__ . '/../includes/layout_header.php';

if (!$estacion) {
    echo '<div class="alert alert-warning">No tienes una estaci&oacute;n asignada. Contacta al administrador.</div>';
    require_once __DIR__ . '/../includes/layout_footer.php';
    exit;
}

$db = getDB();

// Procesar control de estación (Iniciar/Detener completo)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && verifyCsrf()) {
    $action = $_POST['action'] ?? '';
    if ($action === 'station_control') {
        require_once __DIR__ . '/../includes/provisioning.php';
        $cmd = $_POST['cmd'] ?? '';
        $resultado = ['success' => false, 'error' => 'Comando no reconocido'];

        if ($cmd === 'iniciar') {
            generateLiquidsoapConfig($estacion);
            $resultado = startStation($estacion);
        } elseif ($cmd === 'detener') {
            $resultado = stopStation($estacion);
        } elseif ($cmd === 'reiniciar') {
            generateLiquidsoapConfig($estacion);
            $resultado = restartStation($estacion);
        }

        if ($resultado['success']) {
            setFlash('success', "Estaci&oacute;n: $cmd ejecutado correctamente.");
        } else {
            setFlash('error', "Estaci&oacute;n: Error al $cmd - " . ($resultado['error'] ?? 'desconocido'));
        }
        header('Location: ' . BASE_URL . '/cliente/index.php');
        exit;
    }
}

// Obtener stats (defensivo)
$iceStatus = getStreamStatus($estacion);
$oyentesAhora = $iceStatus['oyentes'];
$picoOyentes = getPeakListeners($estacion['id'], 24);
$storageUsedBytes = getStorageUsed($estacion['id']);
$storageUsedMB = round($storageUsedBytes / (1024 * 1024), 1);
$maxStorageMB = $estacion['max_storage_mb'] ?? 5500;
$storagePercent = $maxStorageMB > 0 ? min(round(($storageUsedMB / $maxStorageMB) * 100, 1), 100) : 0;
$maxOyentes = $estacion['max_oyentes'] ?? 500;
$oyentesPercent = $maxOyentes > 0 ? min(round(($oyentesAhora / $maxOyentes) * 100, 1), 100) : 0;
$maxBitrate = $estacion['max_bitrate'] ?? 320;
$bitratePercent = $maxBitrate > 0 ? min(round(($estacion['bitrate'] / $maxBitrate) * 100, 1), 100) : 0;

// Total canciones y anuncios (defensivo)
$totalCanciones = 0;
$totalAnuncios = 0;
try {
    $stmt = $db->prepare("SELECT COUNT(*) FROM canciones WHERE estacion_id = ?");
    $stmt->execute([$estacion['id']]);
    $totalCanciones = (int)$stmt->fetchColumn();
} catch (PDOException $e) {}
try {
    $stmt = $db->prepare("SELECT COUNT(*) FROM anuncios WHERE estacion_id = ?");
    $stmt->execute([$estacion['id']]);
    $totalAnuncios = (int)$stmt->fetchColumn();
} catch (PDOException $e) {}

// Log de emisión (defensivo)
$logEmision = getEmisionLog($estacion['id'], 15);

// Playlist activa (defensivo)
$playlistActiva = null;
try {
    if ($estacion['autodj_playlist_activa']) {
        $stmt = $db->prepare("SELECT * FROM playlists WHERE id = ?");
        $stmt->execute([$estacion['autodj_playlist_activa']]);
        $playlistActiva = $stmt->fetch();
    }
} catch (PDOException $e) {}

// Cuentas DJ
$totalDJ = 0;
$maxDJ = $estacion['max_dj'] ?? 5;
try {
    $stmt = $db->prepare("SELECT COUNT(*) FROM cuentas_dj WHERE estacion_id = ?");
    $stmt->execute([$estacion['id']]);
    $totalDJ = (int)$stmt->fetchColumn();
} catch (PDOException $e) {}

// URL del stream — SHOUTcast usa proxy /shoutcast/, Icecast usa /live/
if (in_array($estacion['server_type'] ?? '', ['shoutcast_v1', 'shoutcast_v2', 'shoutcast_v25'])) {
    $streamUrl = 'https://' . SSL_DOMAIN . '/shoutcast/' . $estacion['slug'];
} else {
    $streamUrl = 'https://' . SSL_DOMAIN . '/live/' . $estacion['slug'];
}

// Obtener configuración del player (para effect_type en URL del reproductor)
$playerEffect = 'rain';
try {
    $stmtPc = $db->prepare("SELECT effect_type FROM player_config WHERE estacion_id = ? LIMIT 1");
    $stmtPc->execute([$estacion['id']]);
    $pcRow = $stmtPc->fetch();
    if ($pcRow && !empty($pcRow['effect_type'])) {
        $playerEffect = $pcRow['effect_type'];
    }
} catch (PDOException $e) {}

// Canción actual (del status del stream)
$cancionActual = $iceStatus['titulo'] ?? '';
?>

<!-- ===== DASHBOARD SONICPANEL STYLE ===== -->
<div class="sp-dashboard">

    <!-- ROW 1: Info de Radio + Control Rápido -->
    <div class="sp-row">

        <!-- CARD: Info de Radio -->
        <div class="sp-card">
            <div class="sp-card-header">
                <h3><?= cpIcon('radio', 18) ?> Info de Radio</h3>
            </div>
            <div class="sp-card-tabs">
                <button class="sp-tab active" onclick="spTab(this,'info-cuenta')">Cuenta</button>
                <button class="sp-tab" onclick="spTab(this,'info-limites')">L&iacute;mites de Radio</button>
                <button class="sp-tab" onclick="spTab(this,'info-passwords')">Todas las Contrase&ntilde;as</button>
                <button class="sp-tab" onclick="document.getElementById('modal-enlaces').style.display='flex'">Enlaces SSL/Reproducir</button>
            </div>

            <!-- Tab: Cuenta -->
            <div class="sp-tab-content active" id="info-cuenta">
                <div class="sp-info-grid">
                    <div class="sp-info-item">
                        <span class="sp-info-label">Usuario</span>
                        <span class="sp-info-value"><?= htmlspecialchars($_SESSION['user_username'] ?? '') ?></span>
                    </div>
                    <div class="sp-info-item">
                        <span class="sp-info-label">Sistema de Radio</span>
                        <span class="sp-info-value"><?= ucfirst($estacion['server_type'] ?? 'icecast') ?></span>
                    </div>
                    <div class="sp-info-item">
                        <span class="sp-info-label">IP de Radio</span>
                        <span class="sp-info-value"><?= htmlspecialchars(SSL_DOMAIN) ?></span>
                    </div>
                    <div class="sp-info-item">
                        <span class="sp-info-label">Puerto de Radio</span>
                        <span class="sp-info-value"><?= $estacion['icecast_port'] ?? '8000' ?></span>
                    </div>
                    <div class="sp-info-item">
                        <span class="sp-info-label">Punto de Montaje</span>
                        <span class="sp-info-value"><?= in_array($estacion['server_type'] ?? '', ['shoutcast_v1','shoutcast_v2','shoutcast_v25']) ? '/shoutcast/' : '/live/' ?><?= htmlspecialchars($estacion['slug']) ?></span>
                    </div>
                    <div class="sp-info-item">
                        <span class="sp-info-label">Paquete</span>
                        <span class="sp-info-value"><?= htmlspecialchars($estacion['paquete_nombre'] ?? 'Sin paquete') ?></span>
                    </div>
                    <div class="sp-info-item">
                        <span class="sp-info-label">Nombre de Estaci&oacute;n</span>
                        <span class="sp-info-value"><?= htmlspecialchars($estacion['nombre']) ?></span>
                    </div>
                    <div class="sp-info-item">
                        <span class="sp-info-label">Codec</span>
                        <span class="sp-info-value"><?= strtoupper($estacion['codec'] ?? 'MP3') ?></span>
                    </div>
                </div>
            </div>

            <!-- Tab: Límites -->
            <div class="sp-tab-content" id="info-limites">
                <div class="sp-info-grid">
                    <div class="sp-info-item">
                        <span class="sp-info-label">Almacenamiento M&aacute;ximo</span>
                        <span class="sp-info-value"><?= number_format($maxStorageMB) ?> MB</span>
                    </div>
                    <div class="sp-info-item">
                        <span class="sp-info-label">Bitrate M&aacute;ximo</span>
                        <span class="sp-info-value"><?= $maxBitrate ?> kbps</span>
                    </div>
                    <div class="sp-info-item">
                        <span class="sp-info-label">Oyentes M&aacute;ximo</span>
                        <span class="sp-info-value"><?= $maxOyentes ?></span>
                    </div>
                    <div class="sp-info-item">
                        <span class="sp-info-label">Cuentas DJ M&aacute;ximo</span>
                        <span class="sp-info-value"><?= $maxDJ ?></span>
                    </div>
                    <div class="sp-info-item">
                        <span class="sp-info-label">AutoDJ</span>
                        <span class="sp-info-value"><?= ($estacion['autodj_enabled'] ?? 1) ? cpIcon('check',14) . ' Habilitado' : cpIcon('x',14) . ' Deshabilitado' ?></span>
                    </div>
                    <div class="sp-info-item">
                        <span class="sp-info-label">Anuncios</span>
                        <span class="sp-info-value"><?= ($estacion['anuncios_enabled'] ?? 1) ? cpIcon('check',14) . ' Habilitado' : cpIcon('x',14) . ' Deshabilitado' ?></span>
                    </div>
                    <div class="sp-info-item">
                        <span class="sp-info-label">SSL</span>
                        <span class="sp-info-value"><?= ($estacion['ssl_enabled'] ?? 1) ? cpIcon('check',14) . ' Habilitado' : cpIcon('x',14) . ' Deshabilitado' ?></span>
                    </div>
                    <div class="sp-info-item">
                        <span class="sp-info-label">Frecuencia de Muestreo</span>
                        <span class="sp-info-value"><?= number_format(($estacion['samplerate'] ?? 44100) / 1000, 1) ?> kHz</span>
                    </div>
                </div>
            </div>

            <!-- Tab: Contraseñas -->
            <div class="sp-tab-content" id="info-passwords">
                <div class="sp-info-grid">
                    <div class="sp-info-item full">
                        <span class="sp-info-label">Servidor / Host</span>
                        <div class="sp-copy-field">
                            <input type="text" value="<?= htmlspecialchars(SSL_DOMAIN) ?>" readonly id="cpHost">
                            <button onclick="cpCopy('cpHost',this)"><?= cpIcon('copy', 14) ?> Copiar</button>
                        </div>
                    </div>
                    <div class="sp-info-item full">
                        <span class="sp-info-label">Puerto de Emisi&oacute;n</span>
                        <div class="sp-copy-field">
                            <?php
                            // v7.4: Puerto correcto según tipo de estación
                            if (!empty($estacion['autodj_enabled']) && in_array($estacion['server_type'] ?? '', ['shoutcast_v1','shoutcast_v2','shoutcast_v25'])) {
                                // Con AutoDJ + SHOUTcast: DJ conecta al harbor (puerto+5)
                                $djPort = $estacion['puerto_stream'] + 5;
                            } else {
                                // Sin AutoDJ: DJ conecta directo al portbase
                                $djPort = $estacion['puerto_stream'];
                            }
                            ?>
                            <input type="text" value="<?= $djPort ?>" readonly id="cpPort">
                            <button onclick="cpCopy('cpPort',this)"><?= cpIcon('copy', 14) ?> Copiar</button>
                        </div>
                    </div>
                    <div class="sp-info-item full">
                        <span class="sp-info-label">Punto de Montaje (Mount)</span>
                        <div class="sp-copy-field">
                            <?php
                            // v7.5: Mount point correcto según tipo de estación
                            if (in_array($estacion['server_type'] ?? '', ['shoutcast_v1','shoutcast_v2','shoutcast_v25'])) {
                                // SHOUTcast (con o sin AutoDJ): no requiere mount point
                                $mountInfo = 'No requiere (protocolo SHOUTcast)';
                            } else {
                                $mountInfo = '/live/' . htmlspecialchars($estacion['slug']);
                            }
                            ?>
                            <input type="text" value="<?= $mountInfo ?>" readonly id="cpMount">
                            <button onclick="cpCopy('cpMount',this)"><?= cpIcon('copy', 14) ?> Copiar</button>
                        </div>
                    </div>
                    <div class="sp-info-item full">
                        <?php if (!empty($estacion['autodj_enabled'])): ?>
                        <span class="sp-info-label">Contrase&ntilde;a DJ</span>
                        <div class="sp-copy-field" style="flex-direction:column;align-items:flex-start;gap:4px">
                            <span style="font-size:13px;color:#666">Use las credenciales de su <a href="<?= BASE_URL ?>/cliente/cuenta_dj.php" style="color:#1976d2">cuenta DJ</a> (usuario y contrase&ntilde;a)</span>
                        </div>
                        <?php else: ?>
                        <span class="sp-info-label">Contrase&ntilde;a Source / DJ</span>
                        <div class="sp-copy-field">
                            <input type="password" value="<?= htmlspecialchars($estacion['icecast_password']) ?>" readonly id="cpPass">
                            <button onclick="let i=document.getElementById('cpPass');i.type=i.type==='password'?'text':'password'"><?= cpIcon('eye', 14) ?> Ver</button>
                            <button onclick="cpCopy('cpPass',this)"><?= cpIcon('copy', 14) ?> Copiar</button>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

        </div>

        <!-- CARD: Control Rápido -->
        <div class="sp-card">
            <div class="sp-card-header">
                <h3><?= cpIcon('zap', 18) ?> Control R&aacute;pido</h3>
            </div>
            <div class="sp-card-tabs">
                <button class="sp-tab active" onclick="spTab(this,'ctrl-estado')">Estado de la Radio</button>
                <?php if (!empty($estacion['autodj_enabled'])): ?>
                <button class="sp-tab" onclick="spTab(this,'ctrl-autodj')">Administrar AutoDJ</button>
                <?php endif; ?>
                <button class="sp-tab" onclick="spTab(this,'ctrl-historial')">Historial de Canciones</button>
            </div>

            <!-- Tab: Estado de la Radio -->
            <div class="sp-tab-content active" id="ctrl-estado">
                <!-- Control Buttons + Estado en misma línea -->
                <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;margin-bottom:14px">
                    <form method="POST" action="<?= BASE_URL ?>/cliente/index.php" style="display:inline">
                        <?= csrfField() ?>
                        <input type="hidden" name="action" value="station_control">
                        <input type="hidden" name="cmd" value="iniciar">
                        <button type="submit" style="padding:8px 18px;background:#4caf50;color:#fff;border:none;border-radius:4px;font-size:13px;font-weight:600;cursor:pointer;display:inline-flex;align-items:center;gap:5px"><?= cpIcon('play', 13) ?> Iniciar</button>
                    </form>
                    <form method="POST" action="<?= BASE_URL ?>/cliente/index.php" style="display:inline">
                        <?= csrfField() ?>
                        <input type="hidden" name="action" value="station_control">
                        <input type="hidden" name="cmd" value="detener">
                        <button type="submit" style="padding:8px 18px;background:#757575;color:#fff;border:none;border-radius:4px;font-size:13px;font-weight:600;cursor:pointer;display:inline-flex;align-items:center;gap:5px"><?= cpIcon('circle-stop', 13) ?> Detener</button>
                    </form>
                    <form method="POST" action="<?= BASE_URL ?>/cliente/index.php" style="display:inline">
                        <?= csrfField() ?>
                        <input type="hidden" name="action" value="station_control">
                        <input type="hidden" name="cmd" value="reiniciar">
                        <button type="submit" style="padding:8px 18px;background:#4caf50;color:#fff;border:none;border-radius:4px;font-size:13px;font-weight:600;cursor:pointer;display:inline-flex;align-items:center;gap:5px"><?= cpIcon('refresh-cw', 13) ?> Reiniciar</button>
                    </form>
                    <span style="font-size:14px;color:#555;font-weight:600;margin-left:6px">Estado: <strong style="color:<?= $iceStatus['online'] ? '#4caf50' : '#e53935' ?>"><?= $iceStatus['online'] ? 'Online' : 'Desconectada' ?></strong></span>
                </div>

                <!-- Reproductor embebido estilo SonicPanel -->
                <div style="border:2px solid #80cbc4;border-radius:6px;overflow:hidden;margin-bottom:14px">
                    <div style="display:flex;align-items:center;background:#f5f5f5;padding:0">
                        <!-- Carátula / Cover -->
                        <div style="width:90px;min-height:70px;background:linear-gradient(135deg,#00897b,#004d40);display:flex;flex-direction:column;align-items:center;justify-content:center;flex-shrink:0;padding:8px">
                            <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><circle cx="12" cy="12" r="3"/></svg>
                            <span style="color:#fff;font-size:9px;margin-top:4px;text-align:center;line-height:1.2;max-width:80px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"><?= htmlspecialchars(mb_substr($cancionActual, 0, 30)) ?: 'Sin datos' ?></span>
                        </div>
                        <!-- Controles de audio -->
                        <div style="flex:1;padding:10px 14px;display:flex;flex-direction:column;gap:6px">
                            <div style="display:flex;align-items:center;gap:10px">
                                <button onclick="stAudioPlay()" style="width:34px;height:34px;background:#e0e0e0;border:none;border-radius:50%;cursor:pointer;display:flex;align-items:center;justify-content:center" title="Reproducir">
                                    <svg id="stPlayIcon" width="16" height="16" viewBox="0 0 24 24" fill="#555"><polygon points="5,3 19,12 5,21"/></svg>
                                </button>
                                <button onclick="stAudioStop()" style="width:34px;height:34px;background:#e0e0e0;border:none;border-radius:50%;cursor:pointer;display:flex;align-items:center;justify-content:center" title="Detener">
                                    <svg width="14" height="14" viewBox="0 0 24 24" fill="#555"><rect x="4" y="4" width="16" height="16" rx="2"/></svg>
                                </button>
                                <input type="range" id="stSeekBar" min="0" max="100" value="0" disabled style="flex:1;height:4px;accent-color:#00897b;cursor:default">
                                <span style="font-size:12px;color:#888;min-width:40px;text-align:right" id="stAudioTime">00:00</span>
                            </div>
                            <div style="display:flex;align-items:center;gap:6px">
                                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="#888" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polygon points="11 5 6 9 2 9 2 15 6 15 11 19"/></svg>
                                <input type="range" id="stVolume" min="0" max="100" value="80" style="width:100px;height:4px;accent-color:#00897b" oninput="stSetVolume(this.value)">
                                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="#888" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polygon points="11 5 6 9 2 9 2 15 6 15 11 19"/><path d="M19.07 4.93a10 10 0 0 1 0 14.14M15.54 8.46a5 5 0 0 1 0 7.08"/></svg>
                            </div>
                        </div>
                    </div>
                    <!-- Barra "Reproduciendo Ahora" -->
                    <div style="background:linear-gradient(135deg,#00897b,#00796b);color:#fff;padding:8px 14px;font-size:13px;font-weight:600;display:flex;align-items:center;gap:6px">
                        <?= cpIcon('music', 14) ?> Reproduciendo Ahora: <?= htmlspecialchars($cancionActual ?: 'Sin informaci&oacute;n') ?>
                    </div>
                </div>

                <!-- Audio element oculto para Estado de Radio -->
                <audio id="stAudioPlayer" preload="none"></audio>
            </div>

            <!-- Tab: Administrar AutoDJ (Estilo SonicPanel) -->
            <?php if (!empty($estacion['autodj_enabled'])): ?>
            <div class="sp-tab-content" id="ctrl-autodj">
                <!-- Fila: Botones + Estado en misma línea -->
                <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;margin-bottom:14px">
                    <form method="POST" action="<?= BASE_URL ?>/cliente/autodj.php" style="display:inline">
                        <?= csrfField() ?>
                        <input type="hidden" name="action" value="autodj_control">
                        <input type="hidden" name="cmd" value="iniciar">
                        <button type="submit" style="padding:8px 18px;background:#4caf50;color:#fff;border:none;border-radius:4px;font-size:13px;font-weight:600;cursor:pointer;display:inline-flex;align-items:center;gap:5px"><?= cpIcon('play', 13) ?> Iniciar</button>
                    </form>
                    <form method="POST" action="<?= BASE_URL ?>/cliente/autodj.php" style="display:inline">
                        <?= csrfField() ?>
                        <input type="hidden" name="action" value="autodj_control">
                        <input type="hidden" name="cmd" value="detener">
                        <button type="submit" style="padding:8px 18px;background:#757575;color:#fff;border:none;border-radius:4px;font-size:13px;font-weight:600;cursor:pointer;display:inline-flex;align-items:center;gap:5px"><?= cpIcon('circle-stop', 13) ?> Detener</button>
                    </form>
                    <form method="POST" action="<?= BASE_URL ?>/cliente/autodj.php" style="display:inline">
                        <?= csrfField() ?>
                        <input type="hidden" name="action" value="autodj_control">
                        <input type="hidden" name="cmd" value="reiniciar">
                        <button type="submit" style="padding:8px 18px;background:#4caf50;color:#fff;border:none;border-radius:4px;font-size:13px;font-weight:600;cursor:pointer;display:inline-flex;align-items:center;gap:5px"><?= cpIcon('refresh-cw', 13) ?> Reiniciar</button>
                    </form>
                    <span style="font-size:14px;color:#555;font-weight:600;margin-left:6px">AutoDJ Estado: <strong style="color:<?= $estacion['autodj_estado'] === 'online' ? '#4caf50' : '#e53935' ?>"><?= $estacion['autodj_estado'] === 'online' ? 'Online' : ucfirst($estacion['autodj_estado']) ?></strong></span>
                </div>

                <!-- Reproductor embebido estilo SonicPanel -->
                <div style="border:2px solid #80cbc4;border-radius:6px;overflow:hidden;margin-bottom:14px">
                    <div style="display:flex;align-items:center;background:#f5f5f5;padding:0">
                        <!-- Carátula / Cover -->
                        <div style="width:90px;min-height:70px;background:linear-gradient(135deg,#00897b,#004d40);display:flex;flex-direction:column;align-items:center;justify-content:center;flex-shrink:0;padding:8px">
                            <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><circle cx="12" cy="12" r="3"/></svg>
                            <span style="color:#fff;font-size:9px;margin-top:4px;text-align:center;line-height:1.2;max-width:80px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"><?= htmlspecialchars(mb_substr($cancionActual, 0, 30)) ?: 'Sin datos' ?></span>
                        </div>
                        <!-- Controles de audio -->
                        <div style="flex:1;padding:10px 14px;display:flex;flex-direction:column;gap:6px">
                            <div style="display:flex;align-items:center;gap:10px">
                                <button onclick="cpAudioPlay()" style="width:34px;height:34px;background:#e0e0e0;border:none;border-radius:50%;cursor:pointer;display:flex;align-items:center;justify-content:center" title="Reproducir">
                                    <svg id="cpPlayIcon" width="16" height="16" viewBox="0 0 24 24" fill="#555"><polygon points="5,3 19,12 5,21"/></svg>
                                </button>
                                <button onclick="cpAudioStop()" style="width:34px;height:34px;background:#e0e0e0;border:none;border-radius:50%;cursor:pointer;display:flex;align-items:center;justify-content:center" title="Detener">
                                    <svg width="14" height="14" viewBox="0 0 24 24" fill="#555"><rect x="4" y="4" width="16" height="16" rx="2"/></svg>
                                </button>
                                <input type="range" id="cpSeekBar" min="0" max="100" value="0" disabled style="flex:1;height:4px;accent-color:#00897b;cursor:default">
                                <span style="font-size:12px;color:#888;min-width:40px;text-align:right" id="cpAudioTime">00:00</span>
                            </div>
                            <div style="display:flex;align-items:center;gap:6px">
                                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="#888" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polygon points="11 5 6 9 2 9 2 15 6 15 11 19"/></svg>
                                <input type="range" id="cpVolume" min="0" max="100" value="80" style="width:100px;height:4px;accent-color:#00897b" oninput="cpSetVolume(this.value)">
                                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="#888" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polygon points="11 5 6 9 2 9 2 15 6 15 11 19"/><path d="M19.07 4.93a10 10 0 0 1 0 14.14M15.54 8.46a5 5 0 0 1 0 7.08"/></svg>
                            </div>
                        </div>
                    </div>
                    <!-- Barra "Reproduciendo Ahora" -->
                    <div style="background:linear-gradient(135deg,#00897b,#00796b);color:#fff;padding:8px 14px;font-size:13px;font-weight:600;display:flex;align-items:center;gap:6px">
                        <?= cpIcon('music', 14) ?> Reproduciendo Ahora: <?= htmlspecialchars($cancionActual ?: 'Sin informaci&oacute;n') ?>
                    </div>
                </div>

                <!-- Audio element oculto -->
                <audio id="cpAudioPlayer" preload="none"></audio>

                <!-- (info grid y links removidos por solicitud del usuario) -->
            </div>
            <?php endif; /* autodj_enabled */ ?>

            <!-- Tab: Historial de Canciones -->
            <div class="sp-tab-content" id="ctrl-historial">
                <?php if (empty($logEmision)): ?>
                    <div class="sp-empty">No hay registros de emisi&oacute;n a&uacute;n.</div>
                <?php else: ?>
                    <div class="sp-history-list">
                        <?php foreach ($logEmision as $log): ?>
                            <div class="sp-history-item">
                                <span class="sp-hist-time"><?= date('H:i:s', strtotime($log['emitido_at'])) ?></span>
                                <?php if ($log['tipo'] === 'cancion'): ?>
                                    <span class="sp-hist-badge song"><?= cpIcon('music', 14) ?></span>
                                <?php else: ?>
                                    <span class="sp-hist-badge ad"><?= cpIcon('megaphone', 14) ?></span>
                                <?php endif; ?>
                                <span class="sp-hist-title"><?= htmlspecialchars($log['titulo'] ?: 'Sin t&iacute;tulo') ?></span>
                                <?php if ($log['estado'] === 'al_aire'): ?>
                                    <span class="sp-hist-live"><?= cpIcon('circle-dot', 10) ?> Al aire</span>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- ROW 2: Gráfico Oyentes + Resumen Cuenta -->
    <div class="sp-row">

        <!-- CARD: Quick Actions (extra) -->
        <div class="sp-card">
            <div class="sp-card-header">
                <h3><?= cpIcon('chart', 18) ?> Oyentes de Radio Gr&aacute;fico (En Vivo)</h3>
            </div>
            <div class="sp-card-body" style="padding:20px">
                <!-- Simple stats bar chart -->
                <div class="sp-chart-container">
                    <div class="sp-chart-label">Oyentes actuales</div>
                    <div class="sp-chart-bars" id="spChartBars">
                        <?php
                        // Generate last 12 hours of placeholder data
                        // In production, this would come from estadisticas_oyentes
                        $horasLabels = [];
                        $horasData = [];
                        for ($i = 11; $i >= 0; $i--) {
                            $h = date('H:i', strtotime("-$i hours"));
                            $horasLabels[] = $h;
                            $horasData[] = 0;
                        }
                        // Try to get real data
                        try {
                            $stmt = $db->prepare("SELECT HOUR(registrado_at) as hora, MAX(oyentes) as max_oy FROM estadisticas_oyentes WHERE estacion_id = ? AND registrado_at >= DATE_SUB(NOW(), INTERVAL 12 HOUR) GROUP BY HOUR(registrado_at) ORDER BY hora");
                            $stmt->execute([$estacion['id']]);
                            $statsData = $stmt->fetchAll();
                            foreach ($statsData as $sd) {
                                $idx = 11 - ((int)date('H') - $sd['hora'] + 24) % 24;
                                if ($idx >= 0 && $idx < 12) {
                                    $horasData[$idx] = $sd['max_oy'];
                                }
                            }
                        } catch (PDOException $e) {}
                        // Set current hour to live value
                        $horasData[11] = $oyentesAhora;
                        $maxBar = max(1, max($horasData));
                        ?>
                        <?php foreach ($horasLabels as $idx => $label): ?>
                            <div class="sp-bar-col">
                                <div class="sp-bar" style="height:<?= round(($horasData[$idx] / $maxBar) * 100) ?>%"
                                     title="<?= $horasData[$idx] ?> oyentes">
                                    <?php if ($horasData[$idx] > 0): ?>
                                        <span class="sp-bar-val"><?= $horasData[$idx] ?></span>
                                    <?php endif; ?>
                                </div>
                                <span class="sp-bar-label"><?= $label ?></span>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>

                <!-- Quick Actions -->
                <div class="sp-quick-actions">
                    <a href="<?= BASE_URL ?>/cliente/estadisticas.php" class="sp-qa"><?= cpIcon('chart', 14) ?> Estad&iacute;sticas completas</a>
                    <a href="<?= BASE_URL ?>/cliente/widgets.php" class="sp-qa"><?= cpIcon('link', 14) ?> Widgets &amp; Players</a>
                    <?php if (!empty($estacion['anuncios_enabled'])): ?>
                    <a href="<?= BASE_URL ?>/cliente/anuncios.php" class="sp-qa"><?= cpIcon('megaphone', 14) ?> Anuncios</a>
                    <?php endif; ?>
                    <a href="<?= BASE_URL ?>/cliente/cuenta_dj.php" class="sp-qa"><?= cpIcon('microphone', 14) ?> Cuentas DJ</a>
                </div>
            </div>
        </div>

        <!-- CARD: Resumen de Cuenta -->
        <div class="sp-card">
            <div class="sp-card-header">
                <h3><?= cpIcon('note', 18) ?> Resumen de Cuenta (En Vivo)</h3>
            </div>
            <div class="sp-card-body" style="padding:20px">
                <div class="sp-gauges">
                    <!-- Oyentes -->
                    <?php
                    $gauges = [
                        ['label' => 'Oyentes', 'value' => $oyentesAhora, 'max' => $maxOyentes, 'pct' => $oyentesPercent, 'color' => '#00897b', 'unit' => ''],
                        ['label' => 'Uso de Disco', 'value' => $storageUsedMB, 'max' => $maxStorageMB, 'pct' => $storagePercent, 'color' => '#1e88e5', 'unit' => ' MB'],
                        ['label' => 'Pico 24h', 'value' => $picoOyentes, 'max' => $maxOyentes, 'pct' => $maxOyentes > 0 ? min(round(($picoOyentes / $maxOyentes) * 100, 1), 100) : 0, 'color' => '#f4511e', 'unit' => ''],
                        ['label' => 'Bitrate', 'value' => $estacion['bitrate'], 'max' => $maxBitrate, 'pct' => $bitratePercent, 'color' => '#8e24aa', 'unit' => ' kbps'],
                        ['label' => 'Canciones', 'value' => $totalCanciones, 'max' => 999, 'pct' => min(round(($totalCanciones / max(1, 999)) * 100, 1), 100), 'color' => '#fb8c00', 'unit' => ''],
                        ['label' => 'Cuentas DJ', 'value' => $totalDJ, 'max' => $maxDJ, 'pct' => $maxDJ > 0 ? min(round(($totalDJ / $maxDJ) * 100, 1), 100) : 0, 'color' => '#43a047', 'unit' => ''],
                    ];
                    foreach ($gauges as $g):
                        $circumference = 2 * M_PI * 36;
                        $offset = $circumference * (1 - $g['pct'] / 100);
                    ?>
                    <div class="sp-gauge">
                        <svg viewBox="0 0 100 100" class="sp-gauge-svg">
                            <circle cx="50" cy="50" r="36" fill="none" stroke="#e8eaed" stroke-width="7"/>
                            <circle cx="50" cy="50" r="36" fill="none" stroke="<?= $g['color'] ?>" stroke-width="7"
                                    stroke-dasharray="<?= round($circumference, 2) ?>"
                                    stroke-dashoffset="<?= round($offset, 2) ?>"
                                    stroke-linecap="round" transform="rotate(-90 50 50)"
                                    class="sp-gauge-fill"/>
                            <text x="50" y="45" text-anchor="middle" font-size="15" font-weight="700" fill="#333"><?= $g['value'] ?></text>
                            <text x="50" y="60" text-anchor="middle" font-size="7" fill="#999"><?= round($g['pct']) ?>%</text>
                        </svg>
                        <div class="sp-gauge-label"><?= $g['label'] ?></div>
                        <div class="sp-gauge-detail"><?= $g['value'] . $g['unit'] ?> / <?= $g['max'] . $g['unit'] ?></div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- MODAL: Enlaces SSL/Reproducir (estilo SonicPanel) -->
<div id="modal-enlaces" style="display:none;position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,0.45);z-index:9999;align-items:center;justify-content:center" onclick="if(event.target===this)this.style.display='none'">
    <div style="background:#fff;border-radius:10px;max-width:680px;width:94%;box-shadow:0 8px 40px rgba(0,0,0,0.25);position:relative;max-height:90vh;overflow-y:auto">
        <!-- Header -->
        <div style="display:flex;align-items:center;justify-content:space-between;padding:18px 24px;border-bottom:1px solid #e8e8e8">
            <h3 style="margin:0;font-size:17px;font-weight:700;color:#333;display:flex;align-items:center;gap:8px"><?= cpIcon('lock', 18) ?> Enlaces SSL/Reproducir</h3>
            <button onclick="document.getElementById('modal-enlaces').style.display='none'" style="background:none;border:none;font-size:24px;cursor:pointer;color:#999;line-height:1;padding:0 4px">&times;</button>
        </div>
        <div style="padding:20px 24px">

            <!-- Sección 1: URL SSL directa -->
            <h4 style="font-size:14px;font-weight:700;color:#333;margin:0 0 10px">URL de Reproducci&oacute;n SSL Directa</h4>
            <div style="background:#e3f2fd;border-radius:8px;padding:12px 16px;display:flex;gap:10px;align-items:flex-start;margin-bottom:12px">
                <span style="color:#1565c0;flex-shrink:0;margin-top:2px"><?= cpIcon('info', 16) ?></span>
                <p style="margin:0;font-size:12px;color:#1565c0;line-height:1.5">URL SSL directa para reproductores HTML5, apps m&oacute;viles, plugins WordPress y players personalizados. Funciona con HTTPS en el puerto de la estaci&oacute;n.</p>
            </div>
            <div style="display:flex;align-items:center;gap:8px;background:#f8f9fa;border:1px solid #e0e0e0;border-radius:8px;padding:10px 14px;margin-bottom:22px">
                <span style="color:#00897b;flex-shrink:0"><?= cpIcon('circle-dot', 14) ?></span>
                <input type="text" value="https://<?= htmlspecialchars(SSL_DOMAIN) ?>:<?= $estacion['icecast_port'] ?>/stream" readonly id="cpStream" style="flex:1;border:none;background:transparent;font-size:13px;color:#1565c0;font-weight:600;outline:none;font-family:monospace">
                <button onclick="cpCopy('cpStream',this)" style="background:#00897b;color:#fff;border:none;padding:6px 14px;border-radius:6px;cursor:pointer;font-size:12px;font-weight:600;white-space:nowrap;display:flex;align-items:center;gap:4px"><?= cpIcon('copy', 12) ?> Copiar</button>
            </div>

            <!-- Sección 2: URL HTTP sin SSL -->
            <h4 style="font-size:14px;font-weight:700;color:#333;margin:0 0 10px">URL HTTP Directa (Sin SSL)</h4>
            <div style="background:#e3f2fd;border-radius:8px;padding:12px 16px;display:flex;gap:10px;align-items:flex-start;margin-bottom:12px">
                <span style="color:#1565c0;flex-shrink:0;margin-top:2px"><?= cpIcon('info', 16) ?></span>
                <p style="margin:0;font-size:12px;color:#1565c0;line-height:1.5">URL directa sin SSL para software de radio como SAM Broadcaster, BUTT, Mixxx, RadioBOSS y otros encoders. Use esta URL con el puerto indicado para transmisi&oacute;n directa.</p>
            </div>
            <div style="display:flex;align-items:center;gap:8px;background:#f8f9fa;border:1px solid #e0e0e0;border-radius:8px;padding:10px 14px;margin-bottom:22px">
                <span style="color:#1565c0;flex-shrink:0"><?= cpIcon('circle-dot', 14) ?></span>
                <input type="text" value="http://<?= htmlspecialchars(SSL_DOMAIN) ?>:<?= $estacion['icecast_port'] ?>/stream" readonly id="cpHttp" style="flex:1;border:none;background:transparent;font-size:13px;color:#1565c0;font-weight:600;outline:none;font-family:monospace">
                <button onclick="cpCopy('cpHttp',this)" style="background:#00897b;color:#fff;border:none;padding:6px 14px;border-radius:6px;cursor:pointer;font-size:12px;font-weight:600;white-space:nowrap;display:flex;align-items:center;gap:4px"><?= cpIcon('copy', 12) ?> Copiar</button>
            </div>

            <!-- Sección 3: Reproductor Público -->
            <h4 style="font-size:14px;font-weight:700;color:#333;margin:0 0 10px">Reproductor P&uacute;blico</h4>
            <div style="background:#e3f2fd;border-radius:8px;padding:12px 16px;display:flex;gap:10px;align-items:flex-start;margin-bottom:12px">
                <span style="color:#1565c0;flex-shrink:0;margin-top:2px"><?= cpIcon('info', 16) ?></span>
                <p style="margin:0;font-size:12px;color:#1565c0;line-height:1.5">Comparta este enlace con sus oyentes. Es la p&aacute;gina del reproductor p&uacute;blico con car&aacute;tulas, historial de canciones y efectos visuales personalizables.</p>
            </div>
            <div style="display:flex;align-items:center;gap:8px;background:#f8f9fa;border:1px solid #e0e0e0;border-radius:8px;padding:10px 14px;margin-bottom:22px">
                <span style="color:#8e24aa;flex-shrink:0"><?= cpIcon('circle-dot', 14) ?></span>
                <input type="text" value="https://<?= htmlspecialchars(SSL_DOMAIN) ?>/widgets/player/?p=<?= $estacion['icecast_port'] ?>&amp;type=<?= htmlspecialchars($playerEffect) ?>" readonly id="cpPlayer" style="flex:1;border:none;background:transparent;font-size:13px;color:#1565c0;font-weight:600;outline:none;font-family:monospace">
                <button onclick="cpCopy('cpPlayer',this)" style="background:#00897b;color:#fff;border:none;padding:6px 14px;border-radius:6px;cursor:pointer;font-size:12px;font-weight:600;white-space:nowrap;display:flex;align-items:center;gap:4px"><?= cpIcon('copy', 12) ?> Copiar</button>
            </div>

            <!-- Sección 4: API JSON -->
            <h4 style="font-size:14px;font-weight:700;color:#333;margin:0 0 10px">Enlace API JSON</h4>
            <div style="background:#e3f2fd;border-radius:8px;padding:12px 16px;display:flex;gap:10px;align-items:flex-start;margin-bottom:12px">
                <span style="color:#1565c0;flex-shrink:0;margin-top:2px"><?= cpIcon('info', 16) ?></span>
                <p style="margin:0;font-size:12px;color:#1565c0;line-height:1.5">Endpoint JSON para desarrolladores. Retorna metadatos en vivo: canci&oacute;n actual, oyentes, estado del servidor. &Uacute;til para integraciones y apps personalizadas.</p>
            </div>
            <div style="display:flex;align-items:center;gap:8px;background:#f8f9fa;border:1px solid #e0e0e0;border-radius:8px;padding:10px 14px">
                <span style="color:#fb8c00;flex-shrink:0"><?= cpIcon('circle-dot', 14) ?></span>
                <input type="text" value="<?= BASE_URL ?>/api/stats.php?estacion=<?= $estacion['id'] ?>" readonly id="cpApi" style="flex:1;border:none;background:transparent;font-size:13px;color:#1565c0;font-weight:600;outline:none;font-family:monospace">
                <button onclick="cpCopy('cpApi',this)" style="background:#00897b;color:#fff;border:none;padding:6px 14px;border-radius:6px;cursor:pointer;font-size:12px;font-weight:600;white-space:nowrap;display:flex;align-items:center;gap:4px"><?= cpIcon('copy', 12) ?> Copiar</button>
            </div>

        </div>
    </div>
</div>

<script>
// Tab switching
function spTab(btn, panelId) {
    var card = btn.closest('.sp-card');
    card.querySelectorAll('.sp-tab').forEach(function(t) { t.classList.remove('active'); });
    card.querySelectorAll('.sp-tab-content').forEach(function(p) { p.classList.remove('active'); });
    btn.classList.add('active');
    document.getElementById(panelId).classList.add('active');
}

// Copy to clipboard
function cpCopy(inputId, btn) {
    var el = document.getElementById(inputId);
    var val = el.value;
    if (el.type === 'password') {
        var tmp = document.createElement('input');
        tmp.value = val;
        document.body.appendChild(tmp);
        tmp.select();
        document.execCommand('copy');
        document.body.removeChild(tmp);
    } else {
        el.select();
        document.execCommand('copy');
    }
    var orig = btn.innerHTML;
    btn.innerHTML = '&#x2705; Copiado';
    setTimeout(function() { btn.innerHTML = orig; }, 1500);
}

// Estado de Radio Audio Player (reproductor embebido en tab Estado)
var stAudioEl = document.getElementById('stAudioPlayer');
var stIsPlaying = false;
var stTimerInterval = null;

function stAudioPlay() {
    if (!stAudioEl) return;
    if (stIsPlaying) return;
    stAudioEl.src = '<?= $streamUrl ?>';
    stAudioEl.volume = (document.getElementById('stVolume').value || 80) / 100;
    stAudioEl.play().catch(function(){});
    stIsPlaying = true;
    document.getElementById('stPlayIcon').innerHTML = '<polygon points="6,4 10,4 10,20 6,20"/><polygon points="14,4 18,4 18,20 14,20"/>';
    stStartTimer();
}

function stAudioStop() {
    if (!stAudioEl) return;
    stAudioEl.pause();
    stAudioEl.src = '';
    stIsPlaying = false;
    document.getElementById('stPlayIcon').innerHTML = '<polygon points="5,3 19,12 5,21"/>';
    document.getElementById('stAudioTime').textContent = '00:00';
    if (stTimerInterval) { clearInterval(stTimerInterval); stTimerInterval = null; }
}

function stSetVolume(val) {
    if (stAudioEl) stAudioEl.volume = val / 100;
}

function stStartTimer() {
    var secs = 0;
    if (stTimerInterval) clearInterval(stTimerInterval);
    stTimerInterval = setInterval(function() {
        if (!stIsPlaying) return;
        secs++;
        var m = Math.floor(secs / 60);
        var s = secs % 60;
        document.getElementById('stAudioTime').textContent =
            (m < 10 ? '0' : '') + m + ':' + (s < 10 ? '0' : '') + s;
    }, 1000);
}

// AutoDJ Audio Player (reproductor embebido en tab AutoDJ)
var cpAudioEl = document.getElementById('cpAudioPlayer');
var cpIsPlaying = false;
var cpTimerInterval = null;

function cpAudioPlay() {
    if (!cpAudioEl) return;
    if (cpIsPlaying) return;
    cpAudioEl.src = '<?= $streamUrl ?>';
    cpAudioEl.volume = (document.getElementById('cpVolume').value || 80) / 100;
    cpAudioEl.play().catch(function(){});
    cpIsPlaying = true;
    document.getElementById('cpPlayIcon').innerHTML = '<polygon points="6,4 10,4 10,20 6,20"/><polygon points="14,4 18,4 18,20 14,20"/>';
    cpStartTimer();
}

function cpAudioStop() {
    if (!cpAudioEl) return;
    cpAudioEl.pause();
    cpAudioEl.src = '';
    cpIsPlaying = false;
    document.getElementById('cpPlayIcon').innerHTML = '<polygon points="5,3 19,12 5,21"/>';
    document.getElementById('cpAudioTime').textContent = '00:00';
    if (cpTimerInterval) { clearInterval(cpTimerInterval); cpTimerInterval = null; }
}

function cpSetVolume(val) {
    if (cpAudioEl) cpAudioEl.volume = val / 100;
}

function cpStartTimer() {
    var secs = 0;
    if (cpTimerInterval) clearInterval(cpTimerInterval);
    cpTimerInterval = setInterval(function() {
        if (!cpIsPlaying) return;
        secs++;
        var m = Math.floor(secs / 60);
        var s = secs % 60;
        document.getElementById('cpAudioTime').textContent =
            (m < 10 ? '0' : '') + m + ':' + (s < 10 ? '0' : '') + s;
    }, 1000);
}
</script>

<?php require_once __DIR__ . '/../includes/layout_footer.php'; ?>
