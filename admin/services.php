<?php
/**
 * CastPanel v2.4 - Admin: Administrador de Servicios
 * Iniciar, detener y reiniciar servicios del sistema
 */
$pageTitle = 'Administrador de Servicios';
$pageIcon = 'server';
$isAdmin = true;
require_once __DIR__ . '/../includes/layout_header.php';
require_once __DIR__ . '/../includes/provisioning.php';
requireRole('admin');

$db = getDB();
$msg = '';
$msgType = '';

// Available services
$managedServices = [
    'httpd' => ['label' => 'Apache Web Server', 'icon' => 'globe', 'critical' => true],
    'mariadb' => ['label' => 'MariaDB Database', 'icon' => 'database', 'critical' => true],
    'docker' => ['label' => 'Docker Engine', 'icon' => 'layers', 'critical' => true],
    'icecast' => ['label' => 'Icecast2 Streaming', 'icon' => 'radio', 'critical' => false],
    'firewalld' => ['label' => 'Firewall (firewalld)', 'icon' => 'shield', 'critical' => false],
    'sshd' => ['label' => 'SSH Server', 'icon' => 'terminal', 'critical' => true],
    'crond' => ['label' => 'Cron Scheduler', 'icon' => 'clock', 'critical' => false],
    'postfix' => ['label' => 'Postfix Mail Server', 'icon' => 'mail', 'critical' => false],
];

// POST: restart service via castpanelCtl
if ($_SERVER['REQUEST_METHOD'] === 'POST' && verifyCsrf()) {
    $action = $_POST['action'] ?? '';
    $service = $_POST['service'] ?? '';

    if (array_key_exists($service, $managedServices)) {
        $validActions = ['start', 'stop', 'restart'];
        if (in_array($action, $validActions)) {
            // Firewall safe-start: abrir puertos esenciales ANTES de iniciar
            if ($service === 'firewalld' && ($action === 'start' || $action === 'restart')) {
                $result = castpanelCtl("service_$action $service");
                if ($result['success']) {
                    // Abrir puertos esenciales inmediatamente
                    sleep(1); // Esperar a que firewalld esté listo
                    $portsOpened = [];
                    $portsFailed = [];
                    $essentialPorts = ['http', 'https', 'ssh'];
                    foreach ($essentialPorts as $p) {
                        $r = castpanelCtl("firewall_open $p");
                        if ($r['success']) $portsOpened[] = $p;
                        else $portsFailed[] = $p;
                    }
                    // Abrir rango de puertos de radio (8000-8200)
                    $r = castpanelCtl("firewall_open 8000-8200/tcp");
                    if ($r['success']) $portsOpened[] = '8000-8200/tcp';
                    else $portsFailed[] = '8000-8200/tcp';
                    // Reload firewall para aplicar
                    castpanelCtl("firewall_reload");

                    $msg = ucfirst($action) . " de Firewall completado. Puertos abiertos: " . implode(', ', $portsOpened);
                    if ($portsFailed) {
                        $msg .= ". Error abriendo: " . implode(', ', $portsFailed);
                        $msgType = 'warning';
                    } else {
                        $msgType = 'success';
                    }
                } else {
                    $msg = "Error al $action Firewall: " . ($result['error'] ?? 'desconocido');
                    $msgType = 'error';
                }
            } else {
                $result = castpanelCtl("service_$action $service");
                if ($result['success']) {
                    $msg = ucfirst($action) . " de {$managedServices[$service]['label']} completado";
                    $msgType = 'success';
                } else {
                    $msg = "Error al $action {$managedServices[$service]['label']}: " . ($result['error'] ?? 'desconocido');
                    $msgType = 'error';
                }
            }
        }
    }
}

/**
 * Detectar estado de un servicio usando métodos PHP nativos.
 * Evita shell_exec que puede estar bloqueado por SELinux o disable_functions.
 * Usa: castpanelCtl IPC → puertos TCP → archivos PID + /proc → socket files.
 */
function getServiceStatus(string $name): array {
    $active = false;
    $enabled = false;
    $pid = '';

    // --- Método 1: Preguntar al daemon IPC (corre como root, acceso total) ---
    $result = castpanelCtl("service_status $name");
    if (!empty($result['success']) && isset($result['status'])) {
        $isActive = ($result['status'] === 'active' || $result['status'] === 'running');
        if ($isActive) {
            // Si el daemon dice activo, confiar y retornar
            return [
                'active'  => true,
                'enabled' => (bool)($result['enabled'] ?? false),
                'pid'     => (string)($result['pid'] ?? ''),
            ];
        }
        // Si dice inactivo, guardar enabled pero seguir verificando con otros métodos
        $enabled = (bool)($result['enabled'] ?? false);
    }

    // --- Método 2: Verificar puertos TCP conocidos via fsockopen ---
    $portMap = [
        'httpd'     => [80],
        'mariadb'   => [3306],
        'sshd'      => [22],
        'icecast'   => [8000],
        'postfix'   => [25],
        'firewalld' => [],
        'docker'    => [],
        'crond'     => [],
    ];
    foreach ($portMap[$name] ?? [] as $port) {
        $fp = @fsockopen('127.0.0.1', $port, $errno, $errstr, 1);
        if ($fp) {
            fclose($fp);
            $active = true;
            break;
        }
    }

    // --- Método 3: Archivos PID + verificar proceso en /proc ---
    $pidFiles = [
        'httpd'     => ['/var/run/httpd/httpd.pid', '/run/httpd/httpd.pid'],
        'mariadb'   => ['/var/run/mariadb/mariadb.pid', '/var/lib/mysql/*.pid'],
        'sshd'      => ['/var/run/sshd.pid', '/run/sshd.pid'],
        'crond'     => ['/var/run/crond.pid', '/run/crond/crond.pid', '/run/crond.pid'],
        'postfix'   => ['/var/spool/postfix/pid/master.pid'],
        'firewalld' => ['/var/run/firewalld.pid', '/run/firewalld/firewalld.pid'],
        'docker'    => ['/var/run/docker.pid', '/run/docker.pid'],
        'icecast'   => ['/var/run/icecast/icecast.pid', '/var/run/icecast2/icecast.pid'],
    ];
    foreach ($pidFiles[$name] ?? [] as $pattern) {
        // Handle glob patterns (e.g. /var/lib/mysql/*.pid)
        $files = (strpos($pattern, '*') !== false) ? glob($pattern) : [$pattern];
        foreach ($files ?: [] as $pidFile) {
            if (file_exists($pidFile)) {
                $pidVal = trim(@file_get_contents($pidFile) ?: '');
                if ($pidVal && is_numeric($pidVal)) {
                    if (is_dir("/proc/$pidVal")) {
                        $active = true;
                        $pid = $pidVal;
                    }
                    break 2;
                }
            }
        }
    }

    // --- Método 4: Detección especial por servicio ---
    if (!$active) {
        if ($name === 'docker' && file_exists('/var/run/docker.sock')) {
            $active = true;
        } elseif ($name === 'mariadb' && file_exists('/var/lib/mysql/mysql.sock')) {
            // Intentar una conexión PDO rápida
            try {
                $testDb = new PDO('mysql:host=127.0.0.1;port=3306', 'root', '', [
                    PDO::ATTR_TIMEOUT => 1,
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                ]);
                $active = true;
                $testDb = null;
            } catch (\Throwable $e) {
                // Socket exists → probablemente activo aunque no podemos conectar como root
                $active = file_exists('/var/lib/mysql/mysql.sock');
            }
        } elseif ($name === 'httpd') {
            // Si estamos sirviendo esta página, Apache está activo
            $active = true;
        } elseif ($name === 'crond' || $name === 'firewalld') {
            // Escanear /proc para procesos sin puerto ni PID file confiable
            $procDirs = @glob('/proc/[0-9]*', GLOB_ONLYDIR) ?: [];
            foreach ($procDirs as $procDir) {
                $pidNum = basename($procDir);
                // Método A: /proc/PID/comm
                $comm = @file_get_contents("$procDir/comm");
                if ($comm !== false && trim($comm) === $name) {
                    $active = true;
                    $pid = $pidNum;
                    break;
                }
                // Método B: /proc/PID/stat — segundo campo entre paréntesis
                $stat = @file_get_contents("$procDir/stat");
                if ($stat && preg_match('/^\d+\s+\(([^)]+)\)/', $stat, $m) && $m[1] === $name) {
                    $active = true;
                    $pid = $pidNum;
                    break;
                }
            }
        }
    }

    // --- Detectar si el servicio está instalado ---
    $unitFile = "{$name}.service";
    $installed = file_exists("/usr/lib/systemd/system/$unitFile")
              || file_exists("/etc/systemd/system/$unitFile")
              || file_exists("/lib/systemd/system/$unitFile");
    // Servicios que no usan systemd pero pueden existir
    if (!$installed && $name === 'docker') {
        $installed = file_exists('/usr/bin/dockerd') || file_exists('/usr/bin/docker');
    }

    // --- Auto-inicio: revisar symlinks de systemd ---
    $enabled = file_exists("/etc/systemd/system/multi-user.target.wants/$unitFile")
            || file_exists("/etc/systemd/system/default.target.wants/$unitFile");

    return ['active' => $active, 'enabled' => $enabled, 'pid' => $pid, 'installed' => $installed];
}
?>

<?php if ($msg): ?>
<div class="alert alert-<?= $msgType === 'error' ? 'danger' : 'success' ?>">
    <?= htmlspecialchars($msg) ?>
</div>
<?php endif; ?>

<div class="card">
    <div class="card-header">
        <h3><?= cpIcon('server', 18) ?> Servicios del Sistema</h3>
    </div>
    <div class="card-body" style="padding:0;">
        <table class="table" style="margin:0;">
            <thead>
                <tr>
                    <th>Servicio</th>
                    <th>Descripción</th>
                    <th>Estado</th>
                    <th>Auto-inicio</th>
                    <th>PID</th>
                    <th>Acciones</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($managedServices as $svcName => $svcInfo):
                    $status = getServiceStatus($svcName);
                ?>
                <tr>
                    <td>
                        <strong><?= cpIcon($svcInfo['icon'], 14) ?> <?= htmlspecialchars($svcName) ?></strong>
                        <?php if ($svcInfo['critical']): ?>
                            <span class="badge badge-warning" style="font-size:.65rem;">Crítico</span>
                        <?php endif; ?>
                    </td>
                    <td><?= htmlspecialchars($svcInfo['label']) ?></td>
                    <td>
                        <?php if (!($status['installed'] ?? true)): ?>
                            <span class="badge" style="background:#6c757d;color:#fff;">No instalado</span>
                        <?php elseif ($status['active']): ?>
                            <span class="badge badge-success">Activo</span>
                        <?php else: ?>
                            <span class="badge badge-danger">Detenido</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if ($status['enabled']): ?>
                            <span style="color:#28a745;">Sí</span>
                        <?php else: ?>
                            <span style="color:#999;">No</span>
                        <?php endif; ?>
                    </td>
                    <td><code style="font-size:.8rem;"><?= $status['pid'] ?: '-' ?></code></td>
                    <td>
                        <?php if (!($status['installed'] ?? true)): ?>
                            <span style="color:#999;font-size:.8rem;">—</span>
                        <?php else: ?>
                        <form method="post" style="display:inline-flex;gap:4px;">
                            <?= csrfField() ?>
                            <input type="hidden" name="service" value="<?= htmlspecialchars($svcName) ?>">
                            <?php if (!$status['active']): ?>
                            <button type="submit" name="action" value="start" class="btn btn-sm btn-success" title="Iniciar"><?= cpIcon('play', 14) ?></button>
                            <?php else: ?>
                            <button type="submit" name="action" value="restart" class="btn btn-sm btn-warning" title="Reiniciar"><?= cpIcon('refresh', 14) ?></button>
                            <?php if (!$svcInfo['critical']): ?>
                            <button type="submit" name="action" value="stop" class="btn btn-sm btn-danger" title="Detener"
                                    onclick="return confirm('¿Detener <?= htmlspecialchars($svcInfo['label']) ?>?')"><?= cpIcon('stop', 14) ?></button>
                            <?php endif; ?>
                            <?php endif; ?>
                        </form>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<div class="card" style="margin-top:1.5rem;">
    <div class="card-body">
        <p style="color:#666;font-size:.85rem;">
            <?= cpIcon('info', 14) ?> Los servicios marcados como <span class="badge badge-warning" style="font-size:.65rem;">Crítico</span> no pueden ser detenidos desde el panel para evitar la pérdida de acceso.
            Las acciones de servicio se ejecutan a través del daemon <code>castpanel-ctl</code>.
        </p>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/layout_footer.php'; ?>
