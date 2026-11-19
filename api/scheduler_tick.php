<?php
/**
 * CastPanel — per-station scheduler tick, called by the station's own
 * Liquidsoap every minute (see generateLiquidsoapConfig). It applies Folder
 * Rotation / AutoDJ Schedule / Ads / Relay and fires due events, so they keep
 * switching on time even when the system cron (cron/scheduler.php) is missing.
 * Only callable from the server itself (Liquidsoap runs on this host).
 */
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';

$ip = (string)($_SERVER['REMOTE_ADDR'] ?? '');
$local = $ip === '127.0.0.1' || $ip === '::1'
    || (filter_var($ip, FILTER_VALIDATE_IP) && !filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE)
        && str_starts_with($ip, '172.')); // Docker bridge
if (!$local) {
    http_response_code(403);
    exit;
}

$slug = preg_replace('/[^a-z0-9\-]/', '', strtolower((string)($_GET['station'] ?? '')));
if ($slug === '') {
    http_response_code(400);
    exit;
}

// At most one run per station every 45 s (cron and tick may both call)
$stamp = sys_get_temp_dir() . '/castpanel_tick_' . $slug;
$fp = @fopen($stamp, 'c');
if (!$fp || !flock($fp, LOCK_EX | LOCK_NB)) exit('busy');
if (is_file($stamp) && time() - (int)@filemtime($stamp) < 45 && filesize($stamp) > 0) exit('recent');
ftruncate($fp, 0);
fwrite($fp, (string)time());
fflush($fp);
touch($stamp);

require_once __DIR__ . '/../includes/provisioning.php';
require_once __DIR__ . '/../includes/autodj_schedule.php';
require_once __DIR__ . '/../includes/autodj_events.php';

$id = getDB()->prepare('SELECT e.id FROM estaciones e LEFT JOIN paquetes p ON p.id = e.paquete_id
                        WHERE e.slug = ? AND e.activa = 1 AND COALESCE(p.autodj_enabled, 0) = 1');
$id->execute([$slug]);
$stationId = (int)$id->fetchColumn();
if (!$stationId) exit('no station');

try {
    $est = loadStationWithPackage($stationId);
    if ($est) {
        castpanelApplyStationSchedule($est);
        castpanelRunStationEvents($est);
    }
    echo 'ok';
} catch (Throwable $e) {
    error_log('CastPanel tick ' . $slug . ': ' . $e->getMessage());
    echo 'error';
}
flock($fp, LOCK_UN);
fclose($fp);
