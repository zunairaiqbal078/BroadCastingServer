<?php
/**
 * Local-only checks for Quick Control track detection (no Liquidsoap required).
 * Run: php dev/test_autodj_track_logic.php
 */
declare(strict_types=1);

require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/autodj_player.php';

$fail = 0;

function assert_true(bool $cond, string $label): void
{
    global $fail;
    if ($cond) {
        echo "OK   $label\n";
        return;
    }
    echo "FAIL $label\n";
    $fail++;
}

$tracks = [
    ['display' => 'ad', 'title' => 'ad', 'uri' => '/media/ad.mp3', 'duration' => 5],
    ['display' => 'tiki', 'title' => 'tiki', 'uri' => '/media/1700000_0_tiki.mp3', 'duration' => 9],
    ['display' => 'CastPanel Test - E2E Sample Beep', 'title' => 'E2E Sample Beep', 'uri' => '/media/sample-b.mp3', 'duration' => 12],
];

assert_true(castpanelTrackByUri($tracks, '/media/ad.mp3') === 0, 'trackByUri exact ad');
assert_true(castpanelTrackByUri($tracks, '/media/1700000_0_tiki.mp3') === 1, 'trackByUri tiki path');
assert_true(castpanelFindTrackIndex($tracks, 'ad') === 0, 'findIndex exact ad title');
// Stuck-title bug: needle "tiki" must not match every partial title.
assert_true(castpanelFindTrackIndex($tracks, 'ad') !== 1, 'findIndex ad is not tiki');

$logDir = sys_get_temp_dir() . '/castpanel-log-test';
@mkdir($logDir, 0777, true);
$slug = 'demo-autodj';
$logFile = $logDir . '/' . $slug . '.log';
$logBody = str_repeat("noise\n", 200)
    . 'Prepared "/media/ad.mp3"' . "\n"
    . "next line\n"
    . 'Prepared "/media/sample-b.mp3"' . "\n";

if (!defined('LIQUIDSOAP_LOG_DIR')) {
    define('LIQUIDSOAP_LOG_DIR', $logDir);
}

file_put_contents($logFile, $logBody);
chmod($logFile, 0644);

$uri = castpanelLiquidsoapLastPreparedUri($slug);
assert_true($uri === '/media/sample-b.mp3', 'log tail returns last Prepared URI');

@unlink($logFile);
@rmdir($logDir);

if ($fail > 0) {
    fwrite(STDERR, "\n$fail assertion(s) failed.\n");
    exit(1);
}

echo "\nAll local AutoDJ track logic checks passed.\n";
