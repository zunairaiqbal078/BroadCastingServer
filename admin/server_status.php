<?php
/**
 * CastPanel v2.4 - Admin: Estado del Servidor en Vivo
 * Monitor en tiempo real de recursos del servidor
 */
$pageTitle = 'Estado del Servidor';
$pageIcon = 'activity';
$isAdmin = true;
require_once __DIR__ . '/../includes/layout_header.php';
requireRole('admin');

// Gather system info
// Uptime from /proc/uptime (no shell_exec)
$uptimeRaw = @file_get_contents('/proc/uptime') ?: '';
$uptime = 'N/A';
if ($uptimeRaw && preg_match('/^([\d.]+)/', $uptimeRaw, $um)) {
    $secs = (int)$um[1];
    $days = (int)($secs / 86400); $secs %= 86400;
    $hours = (int)($secs / 3600); $secs %= 3600;
    $mins = (int)($secs / 60);
    $parts = [];
    if ($days > 0) $parts[] = "$days días";
    if ($hours > 0) $parts[] = "$hours horas";
    if ($mins > 0) $parts[] = "$mins minutos";
    $uptime = 'up ' . (implode(', ', $parts) ?: '< 1 minuto');
}
$loadAvg = sys_getloadavg() ?: [0, 0, 0];
$hostname = @gethostname() ?: 'localhost';

// CPU info - PHP-native (no shell_exec)
$cpuCores = 1;
$cpuModel = 'N/A';
if (file_exists('/proc/cpuinfo')) {
    $cpuInfoContent = @file_get_contents('/proc/cpuinfo') ?: '';
    $cpuCores = substr_count($cpuInfoContent, 'processor');
    if ($cpuCores < 1) $cpuCores = 1;
    if (preg_match('/model name\s*:\s*(.+)/i', $cpuInfoContent, $cm)) {
        $cpuModel = trim($cm[1]);
    }
}
// CPU usage from /proc/stat
$cpuUsage = 0;
if (file_exists('/proc/stat')) {
    $stat1 = @file_get_contents('/proc/stat') ?: '';
    if (preg_match('/^cpu\s+(\d+)\s+(\d+)\s+(\d+)\s+(\d+)\s+(\d+)\s+(\d+)\s+(\d+)/m', $stat1, $s1)) {
        $idle1 = (int)$s1[4];
        $total1 = array_sum(array_slice(array_map('intval', $s1), 1));
        usleep(250000); // 250ms sample
        $stat2 = @file_get_contents('/proc/stat') ?: '';
        if (preg_match('/^cpu\s+(\d+)\s+(\d+)\s+(\d+)\s+(\d+)\s+(\d+)\s+(\d+)\s+(\d+)/m', $stat2, $s2)) {
            $idle2 = (int)$s2[4];
            $total2 = array_sum(array_slice(array_map('intval', $s2), 1));
            $diffIdle = $idle2 - $idle1;
            $diffTotal = $total2 - $total1;
            if ($diffTotal > 0) {
                $cpuUsage = round((1 - $diffIdle / $diffTotal) * 100, 1);
            }
        }
    }
}

// Memory
$memInfo = @file_get_contents('/proc/meminfo');
$memTotal = $memUsed = $memFree = 0;
if ($memInfo) {
    preg_match('/MemTotal:\s+(\d+)/', $memInfo, $mt);
    preg_match('/MemAvailable:\s+(\d+)/', $memInfo, $ma);
    $memTotal = isset($mt[1]) ? (int)$mt[1] * 1024 : 0;
    $memFree = isset($ma[1]) ? (int)$ma[1] * 1024 : 0;
    $memUsed = $memTotal - $memFree;
}
$memPct = $memTotal > 0 ? round($memUsed / $memTotal * 100, 1) : 0;

// Disk
$diskTotal = @disk_total_space('/') ?: 0;
$diskFree = @disk_free_space('/') ?: 0;
$diskUsed = $diskTotal - $diskFree;
$diskPct = $diskTotal > 0 ? round($diskUsed / $diskTotal * 100, 1) : 0;

// Network - read /proc/net/dev directly (no shell_exec)
$netRx = $netTx = 'N/A';
$netDev = @file_get_contents('/proc/net/dev') ?: '';
if ($netDev) {
    foreach (explode("\n", $netDev) as $line) {
        if (preg_match('/^\s*(eth|ens|enp)\S+:\s*(\d+)(?:\s+\d+){7}\s+(\d+)/', $line, $nm)) {
            $netRx = formatBytes((int)$nm[2]);
            $netTx = formatBytes((int)$nm[3]);
            break;
        }
    }
}

// PHP
$phpVersion = phpversion();
$phpMemLimit = ini_get('memory_limit');

// Services status - PHP-native detection (shell_exec blocked by SELinux)
require_once __DIR__ . '/../includes/provisioning.php';

function checkServiceNative(string $name): array {
    $active = false;

    // Método 1: castpanelCtl IPC (runs as root)
    $result = castpanelCtl("service_status $name");
    if (!empty($result['success']) && isset($result['status'])) {
        return ['name' => $name, 'active' => ($result['status'] === 'active' || $result['status'] === 'running')];
    }

    // Método 2: TCP port probe
    $portMap = ['httpd' => 80, 'mariadb' => 3306, 'sshd' => 22, 'icecast' => 8000, 'postfix' => 25];
    if (isset($portMap[$name])) {
        $fp = @fsockopen('127.0.0.1', $portMap[$name], $errno, $errstr, 1);
        if ($fp) { fclose($fp); $active = true; }
    }

    // Método 3: PID files + /proc
    if (!$active) {
        $pidFiles = [
            'httpd' => ['/var/run/httpd/httpd.pid', '/run/httpd/httpd.pid'],
            'mariadb' => ['/var/run/mariadb/mariadb.pid'],
            'sshd' => ['/var/run/sshd.pid', '/run/sshd.pid'],
            'docker' => ['/var/run/docker.pid', '/run/docker.pid'],
            'icecast' => ['/var/run/icecast/icecast.pid'],
            'firewalld' => ['/var/run/firewalld.pid', '/run/firewalld/firewalld.pid'],
        ];
        foreach ($pidFiles[$name] ?? [] as $pidFile) {
            if (file_exists($pidFile)) {
                $pidVal = trim(@file_get_contents($pidFile) ?: '');
                if ($pidVal && is_numeric($pidVal) && is_dir("/proc/$pidVal")) {
                    $active = true;
                    break;
                }
            }
        }
    }

    // Método 4: Special detection
    if (!$active) {
        if ($name === 'docker' && file_exists('/var/run/docker.sock')) $active = true;
        elseif ($name === 'mariadb' && file_exists('/var/lib/mysql/mysql.sock')) $active = true;
        elseif ($name === 'httpd') $active = true; // We're serving this page
    }

    return ['name' => $name, 'active' => $active];
}

$services = [
    checkServiceNative('httpd'),
    checkServiceNative('mariadb'),
    checkServiceNative('docker'),
    checkServiceNative('icecast'),
    checkServiceNative('firewalld'),
    checkServiceNative('sshd'),
];
?>

<div class="stats-grid" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:1rem;margin-bottom:1.5rem;">
    <!-- CPU -->
    <div class="card" style="padding:1.5rem;">
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:1rem;">
            <span style="font-weight:600;"><?= cpIcon('cpu', 16) ?> CPU</span>
            <span style="font-size:1.5rem;font-weight:700;color:<?= $cpuUsage > 80 ? '#dc3545' : ($cpuUsage > 50 ? '#fd7e14' : '#28a745') ?>;"><?= $cpuUsage ?>%</span>
        </div>
        <div style="background:#e9ecef;border-radius:4px;height:8px;overflow:hidden;">
            <div style="width:<?= min($cpuUsage, 100) ?>%;height:100%;background:<?= $cpuUsage > 80 ? '#dc3545' : ($cpuUsage > 50 ? '#fd7e14' : '#28a745') ?>;border-radius:4px;"></div>
        </div>
        <div style="font-size:.75rem;color:#999;margin-top:.5rem;"><?= $cpuCores ?> cores — <?= htmlspecialchars($cpuModel) ?></div>
    </div>

    <!-- Memory -->
    <div class="card" style="padding:1.5rem;">
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:1rem;">
            <span style="font-weight:600;"><?= cpIcon('layers', 16) ?> RAM</span>
            <span style="font-size:1.5rem;font-weight:700;color:<?= $memPct > 85 ? '#dc3545' : ($memPct > 60 ? '#fd7e14' : '#28a745') ?>;"><?= $memPct ?>%</span>
        </div>
        <div style="background:#e9ecef;border-radius:4px;height:8px;overflow:hidden;">
            <div style="width:<?= min($memPct, 100) ?>%;height:100%;background:<?= $memPct > 85 ? '#dc3545' : ($memPct > 60 ? '#fd7e14' : '#28a745') ?>;border-radius:4px;"></div>
        </div>
        <div style="font-size:.75rem;color:#999;margin-top:.5rem;"><?= formatBytes($memUsed) ?> / <?= formatBytes($memTotal) ?></div>
    </div>

    <!-- Disk -->
    <div class="card" style="padding:1.5rem;">
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:1rem;">
            <span style="font-weight:600;"><?= cpIcon('hard-drive', 16) ?> Disco</span>
            <span style="font-size:1.5rem;font-weight:700;color:<?= $diskPct > 90 ? '#dc3545' : ($diskPct > 70 ? '#fd7e14' : '#28a745') ?>;"><?= $diskPct ?>%</span>
        </div>
        <div style="background:#e9ecef;border-radius:4px;height:8px;overflow:hidden;">
            <div style="width:<?= min($diskPct, 100) ?>%;height:100%;background:<?= $diskPct > 90 ? '#dc3545' : ($diskPct > 70 ? '#fd7e14' : '#28a745') ?>;border-radius:4px;"></div>
        </div>
        <div style="font-size:.75rem;color:#999;margin-top:.5rem;"><?= formatBytes($diskUsed) ?> / <?= formatBytes($diskTotal) ?></div>
    </div>

    <!-- Network -->
    <div class="card" style="padding:1.5rem;">
        <div style="font-weight:600;margin-bottom:1rem;"><?= cpIcon('wifi', 16) ?> Red</div>
        <div style="font-size:.9rem;">
            <div>↓ RX: <strong><?= $netRx ?></strong></div>
            <div>↑ TX: <strong><?= $netTx ?></strong></div>
        </div>
        <div style="font-size:.75rem;color:#999;margin-top:.5rem;">Total desde inicio del servidor</div>
    </div>
</div>

<!-- Server Info -->
<div class="card" style="margin-bottom:1.5rem;">
    <div class="card-header">
        <h3><?= cpIcon('monitor', 18) ?> Información del Servidor</h3>
    </div>
    <div class="card-body">
        <div class="form-grid-3">
            <div>
                <div style="font-size:.8rem;color:#999;">Hostname</div>
                <div style="font-weight:600;"><?= htmlspecialchars($hostname) ?></div>
            </div>
            <div>
                <div style="font-size:.8rem;color:#999;">Uptime</div>
                <div style="font-weight:600;"><?= htmlspecialchars(trim($uptime)) ?></div>
            </div>
            <div>
                <div style="font-size:.8rem;color:#999;">Load Average</div>
                <div style="font-weight:600;"><?= implode(' / ', array_map(fn($v) => round($v, 2), $loadAvg)) ?></div>
            </div>
            <div>
                <div style="font-size:.8rem;color:#999;">PHP</div>
                <div style="font-weight:600;"><?= $phpVersion ?> (mem: <?= $phpMemLimit ?>)</div>
            </div>
            <div>
                <div style="font-size:.8rem;color:#999;">Sistema Operativo</div>
                <div style="font-weight:600;"><?= php_uname('s') . ' ' . php_uname('r') ?></div>
            </div>
            <div>
                <div style="font-size:.8rem;color:#999;">Arquitectura</div>
                <div style="font-weight:600;"><?= php_uname('m') ?></div>
            </div>
        </div>
    </div>
</div>

<!-- Services -->
<div class="card">
    <div class="card-header">
        <h3><?= cpIcon('server', 18) ?> Servicios del Sistema</h3>
    </div>
    <div class="card-body" style="padding:0;">
        <table class="table" style="margin:0;">
            <thead>
                <tr>
                    <th>Servicio</th>
                    <th>Estado</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($services as $svc): ?>
                <tr>
                    <td><strong><?= htmlspecialchars($svc['name']) ?></strong></td>
                    <td>
                        <?php if ($svc['active']): ?>
                            <span class="badge badge-success"><?= cpIcon('check', 12) ?> Activo</span>
                        <?php else: ?>
                            <span class="badge badge-danger"><?= cpIcon('x', 12) ?> Inactivo</span>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/layout_footer.php'; ?>
