<?php
/**
 * CastPanel v2.0 - Estadísticas
 */
$pageTitle = 'Estadísticas';
$pageIcon = 'chart';
require_once __DIR__ . '/../includes/layout_header.php';

if (!$estacion) { echo '<div class="alert alert-warning">Sin estación asignada.</div>'; require_once __DIR__ . '/../includes/layout_footer.php'; exit; }

$db = getDB();

// Estadísticas actuales
$iceStatus = getIcecastStatus($estacion['icecast_mount']);
$oyentesAhora = $iceStatus['oyentes'];
$pico24h = getPeakListeners($estacion['id'], 24);
$pico7d = getPeakListeners($estacion['id'], 168);
$pico30d = getPeakListeners($estacion['id'], 720);

// Estadísticas de biblioteca
$musicStats = ['total' => 0, 'bytes' => 0, 'duracion' => 0];
$adStats = ['total' => 0];
$playlistStats = ['total' => 0];
$djStats = ['total' => 0];
$historial = [];
$logStats = [];
$_tableError = false;

try {
    $stmt = $db->prepare("SELECT COUNT(*) AS total, COALESCE(SUM(tamano_bytes),0) AS bytes, COALESCE(SUM(duracion_segundos),0) AS duracion FROM canciones WHERE estacion_id = ?");
    $stmt->execute([$estacion['id']]);
    $musicStats = $stmt->fetch();

    $stmt = $db->prepare("SELECT COUNT(*) AS total FROM anuncios WHERE estacion_id = ?");
    $stmt->execute([$estacion['id']]);
    $adStats = $stmt->fetch();

    $stmt = $db->prepare("SELECT COUNT(*) AS total FROM playlists WHERE estacion_id = ?");
    $stmt->execute([$estacion['id']]);
    $playlistStats = $stmt->fetch();

    $stmt = $db->prepare("SELECT COUNT(*) AS total FROM cuentas_dj WHERE estacion_id = ?");
    $stmt->execute([$estacion['id']]);
    $djStats = $stmt->fetch();

    // Historial de oyentes últimas 24h (para gráfico)
    $stmt = $db->prepare("SELECT DATE_FORMAT(registrado_at, '%H:%i') AS hora, oyentes, pico_oyentes FROM estadisticas_oyentes WHERE estacion_id = ? AND registrado_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR) ORDER BY registrado_at");
    $stmt->execute([$estacion['id']]);
    $historial = $stmt->fetchAll();

    // Log reciente
    $stmt = $db->prepare("SELECT tipo, COUNT(*) AS total FROM log_emision WHERE estacion_id = ? AND emitido_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR) GROUP BY tipo");
    $stmt->execute([$estacion['id']]);
    foreach ($stmt->fetchAll() as $r) $logStats[$r['tipo']] = $r['total'];
} catch (PDOException $e) {
    $_tableError = true;
}

$horas = ($musicStats['duracion'] ?? 0) > 0 ? round($musicStats['duracion'] / 3600, 1) : 0;
?>

<?php if ($_tableError): ?>
<div class="alert alert-warning"><?= cpIcon('warning',14) ?> Algunas tablas de estad&iacute;sticas no est&aacute;n disponibles. Ejecute la migraci&oacute;n SQL: <code>mysql -u root -p castpanel &lt; /var/www/castpanel/install/schema_update_v2.3.sql</code></div>
<?php endif; ?>

<!-- Stats Cards -->
<div class="stats-grid">
    <div class="stat-card green">
        <div class="stat-label">Oyentes Ahora</div>
        <div class="stat-value"><?= $oyentesAhora ?></div>
        <div class="stat-sub">Conexiones en tiempo real</div>
        <span class="stat-badge"><?= cpIcon('headphones',12) ?> LIVE</span>
    </div>
    <div class="stat-card blue">
        <div class="stat-label">Pico 24 Horas</div>
        <div class="stat-value"><?= $pico24h ?></div>
        <div class="stat-sub">Máximo en las últimas 24h</div>
        <span class="stat-badge">24H</span>
    </div>
    <div class="stat-card purple">
        <div class="stat-label">Pico 7 Días</div>
        <div class="stat-value"><?= $pico7d ?></div>
        <div class="stat-sub">Máximo semanal</div>
        <span class="stat-badge">7D</span>
    </div>
    <div class="stat-card orange">
        <div class="stat-label">Pico 30 Días</div>
        <div class="stat-value"><?= $pico30d ?></div>
        <div class="stat-sub">Máximo mensual</div>
        <span class="stat-badge">30D</span>
    </div>
</div>

<div class="two-cols-equal">
    <!-- Historial gráfico -->
    <div class="card">
        <div class="card-header">
            <h3><?= cpIcon('chart',18) ?> Oyentes Últimas 24 Horas</h3>
        </div>
        <div class="card-body">
            <?php if (empty($historial)): ?>
                <div class="empty-state"><p>Aún no hay datos de oyentes registrados.</p></div>
            <?php else: ?>
                <div style="display:flex;align-items:flex-end;gap:2px;height:150px;padding:8px 0">
                    <?php
                    $maxVal = max(array_column($historial, 'pico_oyentes')) ?: 1;
                    foreach ($historial as $h):
                        $pct = ($h['pico_oyentes'] / $maxVal) * 100;
                    ?>
                        <div style="flex:1;background:linear-gradient(to top, var(--primary), var(--primary-light));border-radius:3px 3px 0 0;min-height:4px;height:<?= max(4, $pct) ?>%" title="<?= $h['hora'] ?> — <?= $h['pico_oyentes'] ?> oyentes"></div>
                    <?php endforeach; ?>
                </div>
                <div style="display:flex;justify-content:space-between;font-size:10px;color:var(--text-secondary);margin-top:4px">
                    <span><?= $historial[0]['hora'] ?? '' ?></span>
                    <span><?= end($historial)['hora'] ?? '' ?></span>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Resumen de contenido -->
    <div class="card">
        <div class="card-header">
            <h3><?= cpIcon('library',18) ?> Resumen de Contenido</h3>
        </div>
        <div class="card-body">
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px">
                <div style="text-align:center;padding:16px;background:var(--hover);border-radius:var(--radius-sm)">
                    <div style="font-size:28px;font-weight:800;color:var(--primary)"><?= $musicStats['total'] ?></div>
                    <div style="font-size:12px;color:var(--text-secondary)">Canciones</div>
                </div>
                <div style="text-align:center;padding:16px;background:var(--hover);border-radius:var(--radius-sm)">
                    <div style="font-size:28px;font-weight:800;color:var(--warning)"><?= $adStats['total'] ?></div>
                    <div style="font-size:12px;color:var(--text-secondary)">Anuncios</div>
                </div>
                <div style="text-align:center;padding:16px;background:var(--hover);border-radius:var(--radius-sm)">
                    <div style="font-size:28px;font-weight:800;color:var(--success)"><?= $playlistStats['total'] ?></div>
                    <div style="font-size:12px;color:var(--text-secondary)">Playlists</div>
                </div>
                <div style="text-align:center;padding:16px;background:var(--hover);border-radius:var(--radius-sm)">
                    <div style="font-size:28px;font-weight:800;color:var(--info)"><?= $djStats['total'] ?></div>
                    <div style="font-size:12px;color:var(--text-secondary)">Cuentas DJ</div>
                </div>
            </div>

            <div style="margin-top:16px;padding:12px;background:var(--hover);border-radius:var(--radius-sm)">
                <div style="font-size:13px;color:var(--text-secondary)">
                    <strong>Almacenamiento:</strong> <?= formatBytes($musicStats['bytes']) ?><br>
                    <strong>Duración total:</strong> <?= $horas ?> horas de contenido<br>
                    <strong>Emitidas hoy:</strong> <?= $logStats['cancion'] ?? 0 ?> canciones, <?= $logStats['anuncio'] ?? 0 ?> anuncios
                </div>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/layout_footer.php'; ?>
