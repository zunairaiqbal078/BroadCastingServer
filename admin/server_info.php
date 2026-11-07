<?php
/**
 * CastPanel v2.4 - Admin: Información y Estado del Servidor
 * Vista completa del servidor: hardware, software, servicios
 * NOTA: Usa métodos PHP nativos — NO shell_exec (bloqueado por SELinux/disable_functions)
 */
$pageTitle = 'Información del Servidor';
$pageIcon = 'server';
$isAdmin = true;
require_once __DIR__ . '/../includes/layout_header.php';
require_once __DIR__ . '/../includes/provisioning.php';
requireRole('admin');

$db = getDB();

// === System Info (PHP-native) ===
$hostname = @gethostname() ?: 'localhost';

$osRelease = '';
if (is_readable('/etc/os-release')) {
    $osData = @parse_ini_file('/etc/os-release');
    $osRelease = $osData['PRETTY_NAME'] ?? '';
}
if (!$osRelease) $osRelease = php_uname('s') . ' ' . php_uname('r');

$kernelVersion = php_uname('r');
$arch = php_uname('m');

// Uptime from /proc/uptime
$uptime = 'N/A';
$uptimeRaw = @file_get_contents('/proc/uptime') ?: '';
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
$serverTime = date('Y-m-d H:i:s T');

// === CPU Info from /proc/cpuinfo ===
$cpuModel = 'N/A';
$cpuCores = 0;
$cpuInfo = @file_get_contents('/proc/cpuinfo');
if ($cpuInfo) {
    if (preg_match('/model name\s*:\s*(.+)/i', $cpuInfo, $m)) {
        $cpuModel = trim($m[1]);
    }
    $cpuCores = substr_count($cpuInfo, 'processor');
    if ($cpuCores < 1) $cpuCores = 1;
}

// CPU usage from /proc/stat (2-sample method)
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

// === Memory from /proc/meminfo ===
$memTotal = 0; $memFree = 0; $memAvailable = 0; $memBuffers = 0; $memCached = 0;
$swapTotal = 0; $swapFree = 0;
$meminfo = @file_get_contents('/proc/meminfo');
if ($meminfo) {
    preg_match('/MemTotal:\s+(\d+)/', $meminfo, $m) && $memTotal = (int)$m[1] * 1024;
    preg_match('/MemFree:\s+(\d+)/', $meminfo, $m) && $memFree = (int)$m[1] * 1024;
    preg_match('/MemAvailable:\s+(\d+)/', $meminfo, $m) && $memAvailable = (int)$m[1] * 1024;
    preg_match('/Buffers:\s+(\d+)/', $meminfo, $m) && $memBuffers = (int)$m[1] * 1024;
    preg_match('/Cached:\s+(\d+)/', $meminfo, $m) && $memCached = (int)$m[1] * 1024;
    preg_match('/SwapTotal:\s+(\d+)/', $meminfo, $m) && $swapTotal = (int)$m[1] * 1024;
    preg_match('/SwapFree:\s+(\d+)/', $meminfo, $m) && $swapFree = (int)$m[1] * 1024;
}
$memUsed = $memTotal - $memAvailable;
$memPct = $memTotal > 0 ? round($memUsed / $memTotal * 100, 1) : 0;
$swapUsed = $swapTotal - $swapFree;
$swapPct = $swapTotal > 0 ? round($swapUsed / $swapTotal * 100, 1) : 0;

// === Disk ===
$diskTotal = @disk_total_space('/') ?: 0;
$diskFree = @disk_free_space('/') ?: 0;
$diskUsed = $diskTotal - $diskFree;
$diskPct = $diskTotal > 0 ? round($diskUsed / $diskTotal * 100, 1) : 0;

// === Network: Server IP from PHP globals ===
$serverIP = $_SERVER['SERVER_ADDR'] ?? '';
if (!$serverIP) {
    // Try reading from /proc/net/fib_trie or hostname resolution
    $serverIP = @gethostbyname($hostname) ?: 'N/A';
}

// === Software Versions (PHP-native detection) ===
$phpVersion = phpversion();

// Apache: use apache_get_version() or SERVER_SOFTWARE
$apacheVersion = 'N/A';
if (function_exists('apache_get_version')) {
    $apacheVersion = apache_get_version();
} elseif (!empty($_SERVER['SERVER_SOFTWARE'])) {
    $apacheVersion = $_SERVER['SERVER_SOFTWARE'];
}

// MariaDB: via PDO query
$mariadbVersion = 'N/A';
try {
    $row = $db->query("SELECT VERSION() as v")->fetch();
    $mariadbVersion = $row['v'] ?? 'N/A';
} catch (PDOException $e) {}

// Docker: check socket + version file
$dockerVersion = 'No instalado';
if (file_exists('/var/run/docker.sock') || file_exists('/run/docker.sock')) {
    $dockerVersion = 'Instalado (activo)';
    // Try reading version from docker info via castpanelCtl or config
    if (file_exists('/usr/bin/docker') || file_exists('/usr/local/bin/docker')) {
        $dockerVersion = 'Docker Engine (activo)';
    }
}

// Icecast: read version from config or binary path
$icecastVersion = 'N/A';
$icecastConfPaths = ['/etc/icecast.xml', '/etc/icecast2/icecast.xml', '/usr/local/etc/icecast.xml'];
foreach ($icecastConfPaths as $icConf) {
    if (file_exists($icConf)) {
        $icecastVersion = 'Icecast (instalado)';
        // Try to extract version from config
        $icXml = @file_get_contents($icConf) ?: '';
        if (preg_match('/<server-id>([^<]*)<\/server-id>/', $icXml, $icm)) {
            $icecastVersion = trim($icm[1]) ?: $icecastVersion;
        }
        break;
    }
}
// Fallback: check if binary exists
if ($icecastVersion === 'N/A' && (file_exists('/usr/bin/icecast') || file_exists('/usr/local/bin/icecast'))) {
    $icecastVersion = 'Icecast (instalado)';
}
// Also check TCP port 8000
if ($icecastVersion === 'N/A') {
    $fp = @fsockopen('127.0.0.1', 8000, $errno, $errstr, 1);
    if ($fp) {
        fclose($fp);
        $icecastVersion = 'Icecast (activo en puerto 8000)';
    }
}

// Node.js: check binary existence
$nodeVersion = 'No instalado';
foreach (['/usr/bin/node', '/usr/local/bin/node', '/usr/bin/nodejs'] as $nodePath) {
    if (file_exists($nodePath)) {
        $nodeVersion = 'Instalado';
        break;
    }
}

// === Services Status (PHP-native 4-layer detection) ===
/**
 * Detectar estado de servicio usando métodos PHP nativos.
 * 4 capas: castpanelCtl IPC → TCP port → PID files + /proc → heurísticas
 */
function checkServiceInfoNative(string $name): array {
    $active = false;
    $enabled = false;

    // Método 1: castpanelCtl IPC (corre como root)
    $result = castpanelCtl("service_status $name");
    if (!empty($result['success']) && isset($result['status'])) {
        $isActive = ($result['status'] === 'active' || $result['status'] === 'running');
        return [
            'active'  => $isActive,
            'enabled' => (bool)($result['enabled'] ?? false),
        ];
    }

    // Método 2: TCP port probe
    $portMap = [
        'httpd' => [80], 'mariadb' => [3306], 'sshd' => [22],
        'icecast' => [8000], 'postfix' => [25],
    ];
    foreach ($portMap[$name] ?? [] as $port) {
        $fp = @fsockopen('127.0.0.1', $port, $errno, $errstr, 1);
        if ($fp) { fclose($fp); $active = true; break; }
    }

    // Método 3: PID files + /proc
    if (!$active) {
        $pidFiles = [
            'httpd'     => ['/var/run/httpd/httpd.pid', '/run/httpd/httpd.pid'],
            'mariadb'   => ['/var/run/mariadb/mariadb.pid'],
            'sshd'      => ['/var/run/sshd.pid', '/run/sshd.pid'],
            'crond'     => ['/var/run/crond.pid', '/run/crond/crond.pid'],
            'postfix'   => ['/var/spool/postfix/pid/master.pid'],
            'firewalld' => ['/var/run/firewalld.pid', '/run/firewalld/firewalld.pid'],
            'docker'    => ['/var/run/docker.pid', '/run/docker.pid'],
            'icecast'   => ['/var/run/icecast/icecast.pid'],
            'fail2ban'  => ['/var/run/fail2ban/fail2ban.pid', '/run/fail2ban/fail2ban.pid'],
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

    // Método 4: Detección especial
    if (!$active) {
        if ($name === 'docker' && file_exists('/var/run/docker.sock')) $active = true;
        elseif ($name === 'mariadb' && file_exists('/var/lib/mysql/mysql.sock')) $active = true;
        elseif ($name === 'httpd') $active = true; // If we're serving this page, Apache is running
    }

    // Auto-inicio: systemd symlinks
    $unitFile = "{$name}.service";
    $enabled = file_exists("/etc/systemd/system/multi-user.target.wants/$unitFile")
            || file_exists("/etc/systemd/system/default.target.wants/$unitFile")
            || file_exists("/usr/lib/systemd/system/$unitFile");

    return ['active' => $active, 'enabled' => $enabled];
}

$services = [
    'httpd' => 'Apache HTTP',
    'mariadb' => 'MariaDB',
    'docker' => 'Docker',
    'icecast' => 'Icecast',
    'firewalld' => 'Firewalld',
    'sshd' => 'SSH',
    'crond' => 'Cron',
    'postfix' => 'Postfix (Mail)',
    'fail2ban' => 'Fail2ban',
];
$serviceStatus = [];
foreach ($services as $svc => $label) {
    $status = checkServiceInfoNative($svc);
    $serviceStatus[$svc] = ['label' => $label, 'active' => $status['active'], 'enabled' => $status['enabled']];
}

// === CastPanel Info ===
$panelVersion = '2.4';
$sslDomain = '';
try {
    $row = $db->query("SELECT valor FROM configuracion WHERE clave = 'ssl_domain'")->fetch();
    if ($row) $sslDomain = $row['valor'];
} catch (PDOException $e) {}
$stationCount = 0;
try { $stationCount = (int)$db->query("SELECT COUNT(*) FROM estaciones")->fetchColumn(); } catch (PDOException $e) {}
$userCount = 0;
try { $userCount = (int)$db->query("SELECT COUNT(*) FROM usuarios")->fetchColumn(); } catch (PDOException $e) {}
?>

<!-- Resource Meters -->
<div class="stats-grid" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(250px,1fr));gap:1rem;margin-bottom:1.5rem;">
    <!-- CPU -->
    <div class="card" style="padding:1.5rem;">
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:.6rem;">
            <strong><?= cpIcon('cpu', 16) ?> CPU</strong>
            <span style="font-weight:700;color:<?= $cpuUsage > 90 ? '#dc3545' : ($cpuUsage > 70 ? '#fd7e14' : '#28a745') ?>;"><?= $cpuUsage ?>%</span>
        </div>
        <div style="background:#e9ecef;border-radius:4px;height:10px;margin-bottom:.5rem;">
            <div style="width:<?= min($cpuUsage, 100) ?>%;height:100%;background:<?= $cpuUsage > 90 ? '#dc3545' : ($cpuUsage > 70 ? '#fd7e14' : '#28a745') ?>;border-radius:4px;transition:width .3s;"></div>
        </div>
        <div style="font-size:.8rem;color:#666;"><?= $cpuCores ?> cores · Load: <?= implode(', ', array_map(fn($l) => round($l, 2), $loadAvg)) ?></div>
    </div>
    <!-- Memory -->
    <div class="card" style="padding:1.5rem;">
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:.6rem;">
            <strong><?= cpIcon('hard-drive', 16) ?> RAM</strong>
            <span style="font-weight:700;color:<?= $memPct > 90 ? '#dc3545' : ($memPct > 70 ? '#fd7e14' : '#28a745') ?>;"><?= $memPct ?>%</span>
        </div>
        <div style="background:#e9ecef;border-radius:4px;height:10px;margin-bottom:.5rem;">
            <div style="width:<?= min($memPct, 100) ?>%;height:100%;background:<?= $memPct > 90 ? '#dc3545' : ($memPct > 70 ? '#fd7e14' : '#28a745') ?>;border-radius:4px;transition:width .3s;"></div>
        </div>
        <div style="font-size:.8rem;color:#666;"><?= formatBytes($memUsed) ?> / <?= formatBytes($memTotal) ?></div>
    </div>
    <!-- Disk -->
    <div class="card" style="padding:1.5rem;">
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:.6rem;">
            <strong><?= cpIcon('hard-drive', 16) ?> Disco</strong>
            <span style="font-weight:700;color:<?= $diskPct > 90 ? '#dc3545' : ($diskPct > 70 ? '#fd7e14' : '#28a745') ?>;"><?= $diskPct ?>%</span>
        </div>
        <div style="background:#e9ecef;border-radius:4px;height:10px;margin-bottom:.5rem;">
            <div style="width:<?= min($diskPct, 100) ?>%;height:100%;background:<?= $diskPct > 90 ? '#dc3545' : ($diskPct > 70 ? '#fd7e14' : '#28a745') ?>;border-radius:4px;transition:width .3s;"></div>
        </div>
        <div style="font-size:.8rem;color:#666;"><?= formatBytes($diskUsed) ?> / <?= formatBytes($diskTotal) ?></div>
    </div>
</div>

<!-- Server Details -->
<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(400px,1fr));gap:1.5rem;margin-bottom:1.5rem;">

    <!-- System Info -->
    <div class="card">
        <div class="card-header">
            <h3><?= cpIcon('server', 18) ?> Sistema</h3>
        </div>
        <div class="card-body" style="padding:0;">
            <table class="table" style="margin:0;">
                <tr><td style="width:40%;font-weight:600;">Hostname</td><td><?= htmlspecialchars($hostname) ?></td></tr>
                <tr><td style="font-weight:600;">IP del Servidor</td><td><code><?= htmlspecialchars($serverIP) ?></code></td></tr>
                <tr><td style="font-weight:600;">Sistema Operativo</td><td><?= htmlspecialchars($osRelease) ?></td></tr>
                <tr><td style="font-weight:600;">Kernel</td><td><?= htmlspecialchars($kernelVersion) ?></td></tr>
                <tr><td style="font-weight:600;">Arquitectura</td><td><?= htmlspecialchars($arch) ?></td></tr>
                <tr><td style="font-weight:600;">Uptime</td><td><?= htmlspecialchars($uptime) ?></td></tr>
                <tr><td style="font-weight:600;">Hora del Servidor</td><td><?= $serverTime ?></td></tr>
                <tr><td style="font-weight:600;">CPU</td><td><?= htmlspecialchars($cpuModel) ?></td></tr>
                <tr><td style="font-weight:600;">Cores</td><td><?= $cpuCores ?></td></tr>
                <tr><td style="font-weight:600;">Swap</td><td><?= formatBytes($swapUsed) ?> / <?= formatBytes($swapTotal) ?> (<?= $swapPct ?>%)</td></tr>
            </table>
        </div>
    </div>

    <!-- Software Versions -->
    <div class="card">
        <div class="card-header">
            <h3><?= cpIcon('layers', 18) ?> Software</h3>
        </div>
        <div class="card-body" style="padding:0;">
            <table class="table" style="margin:0;">
                <tr><td style="width:40%;font-weight:600;">CastPanel</td><td>v<?= $panelVersion ?></td></tr>
                <tr><td style="font-weight:600;">Dominio SSL</td><td><?= htmlspecialchars($sslDomain ?: 'No configurado') ?></td></tr>
                <tr><td style="font-weight:600;">PHP</td><td><?= htmlspecialchars($phpVersion) ?></td></tr>
                <tr><td style="font-weight:600;">Apache</td><td style="font-size:.85rem;"><?= htmlspecialchars($apacheVersion) ?></td></tr>
                <tr><td style="font-weight:600;">MariaDB</td><td><?= htmlspecialchars($mariadbVersion) ?></td></tr>
                <tr><td style="font-weight:600;">Docker</td><td style="font-size:.85rem;"><?= htmlspecialchars($dockerVersion) ?></td></tr>
                <tr><td style="font-weight:600;">Icecast</td><td><?= htmlspecialchars($icecastVersion) ?></td></tr>
                <tr><td style="font-weight:600;">Node.js</td><td><?= htmlspecialchars($nodeVersion) ?></td></tr>
                <tr><td style="font-weight:600;">Estaciones</td><td><?= $stationCount ?></td></tr>
                <tr><td style="font-weight:600;">Usuarios</td><td><?= $userCount ?></td></tr>
            </table>
        </div>
    </div>
</div>

<!-- Services Status -->
<div class="card" style="margin-bottom:1.5rem;">
    <div class="card-header">
        <h3><?= cpIcon('gear', 18) ?> Estado de Servicios</h3>
    </div>
    <div class="card-body">
        <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(200px,1fr));gap:.8rem;">
            <?php foreach ($serviceStatus as $svc => $info): ?>
            <div style="display:flex;align-items:center;gap:.8rem;padding:.8rem 1rem;background:<?= $info['active'] ? '#f0fdf4' : '#fef2f2' ?>;border:1px solid <?= $info['active'] ? '#bbf7d0' : '#fecaca' ?>;border-radius:8px;">
                <div style="width:10px;height:10px;border-radius:50%;background:<?= $info['active'] ? '#22c55e' : '#ef4444' ?>;"></div>
                <div>
                    <div style="font-weight:600;font-size:.9rem;"><?= htmlspecialchars($info['label']) ?></div>
                    <div style="font-size:.75rem;color:#666;">
                        <?= $info['active'] ? 'Activo' : 'Inactivo' ?>
                        · <?= $info['enabled'] ? 'Habilitado' : 'Deshabilitado' ?>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
</div>

<!-- PHP Info Summary -->
<div class="card">
    <div class="card-header">
        <h3><?= cpIcon('code', 18) ?> PHP Configuration</h3>
    </div>
    <div class="card-body" style="padding:0;">
        <table class="table" style="margin:0;">
            <tr><td style="width:40%;font-weight:600;">Versión PHP</td><td><?= phpversion() ?></td></tr>
            <tr><td style="font-weight:600;">SAPI</td><td><?= php_sapi_name() ?></td></tr>
            <tr><td style="font-weight:600;">memory_limit</td><td><?= ini_get('memory_limit') ?></td></tr>
            <tr><td style="font-weight:600;">max_execution_time</td><td><?= ini_get('max_execution_time') ?>s</td></tr>
            <tr><td style="font-weight:600;">upload_max_filesize</td><td><?= ini_get('upload_max_filesize') ?></td></tr>
            <tr><td style="font-weight:600;">post_max_size</td><td><?= ini_get('post_max_size') ?></td></tr>
            <tr><td style="font-weight:600;">disable_functions</td><td style="font-size:.8rem;word-break:break-all;"><?= ini_get('disable_functions') ?: 'Ninguna' ?></td></tr>
            <tr><td style="font-weight:600;">Extensiones cargadas</td><td style="font-size:.8rem;"><?= implode(', ', get_loaded_extensions()) ?></td></tr>
        </table>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/layout_footer.php'; ?>
