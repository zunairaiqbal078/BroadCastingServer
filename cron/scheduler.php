<?php
/**
 * CastPanel — Cron: AutoDJ scheduler (run every minute)
 *
 * Applies, for every active AutoDJ station: AutoDJ Schedule, Folder Rotation,
 * Schedule Ads and Relay Redirect (see includes/autodj_schedule.php). Only the
 * files whose content changed are rewritten; Liquidsoap picks them up itself.
 *
 *   * * * * * <web user> php /var/www/castpanel/cron/scheduler.php
 *
 * Options: --verbose
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit;
}

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/provisioning.php';
require_once __DIR__ . '/../includes/autodj_schedule.php';

$verbose = in_array('--verbose', $argv, true);

// One run at a time
$lock = fopen(sys_get_temp_dir() . '/castpanel_scheduler.lock', 'c');
if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) {
    if ($verbose) echo "Another scheduler run is still working\n";
    exit(0);
}

$db = getDB();
$ids = $db->query("SELECT e.id FROM estaciones e
                   JOIN usuarios u ON u.id = e.usuario_id
                   LEFT JOIN paquetes p ON p.id = e.paquete_id
                   WHERE e.activa = 1 AND u.activo = 1 AND COALESCE(p.autodj_enabled, 0) = 1")
          ->fetchAll(PDO::FETCH_COLUMN);

foreach ($ids as $id) {
    try {
        $est = loadStationWithPackage((int)$id);
        if (!$est) continue;
        $r = castpanelApplyStationSchedule($est);
        $changes = [];
        if ($r['music']['changed']) {
            $src = $r['music']['source'];
            $changes[] = 'music=' . $src['type'] . ($src['name'] ? " ({$src['name']})" : '') . ", {$r['music']['count']} songs";
        }
        if ($r['ads']['changed']) $changes[] = "ads={$r['ads']['count']} every {$r['ads']['every']} songs";
        if ($r['relay']['changed']) $changes[] = 'relay=' . ($r['relay']['relay']['name'] ?? 'off');
        if ($changes || $verbose) {
            echo date('Y-m-d H:i:s') . " {$est['slug']}: " . ($changes ? implode('; ', $changes) : 'no change') . "\n";
        }
    } catch (Throwable $e) {
        echo date('Y-m-d H:i:s') . " station #$id: ERROR " . $e->getMessage() . "\n";
    }
}
