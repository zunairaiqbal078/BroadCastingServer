<?php
/**
 * CastPanel v2.4 - Admin: Reiniciar Servicios del Servidor
 * Reiniciar MySQL, SSH, Correo, Reset completo
 * NOTA: Usa métodos PHP nativos — NO shell_exec (bloqueado por SELinux/disable_functions)
 */
$pageTitle = 'Reiniciar Servicios';
$pageIcon = 'refresh';
$isAdmin = true;
require_once __DIR__ . '/../includes/layout_header.php';
require_once __DIR__ . '/../includes/provisioning.php';
requireRole('admin');

$msg = '';
$msgType = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && verifyCsrf()) {
    $action = $_POST['action'] ?? '';

    $serviceMap = [
        'restart_mysql' => ['service' => 'mariadb', 'label' => 'MySQL/MariaDB'],
        'restart_ssh' => ['service' => 'sshd', 'label' => 'SSH'],
        'restart_mail' => ['service' => 'postfix', 'label' => 'Servidor de Correo'],
        'restart_apache' => ['service' => 'httpd', 'label' => 'Apache HTTP'],
        'restart_docker' => ['service' => 'docker', 'label' => 'Docker'],
        'restart_icecast' => ['service' => 'icecast', 'label' => 'Icecast'],
        'restart_firewall' => ['service' => 'fail2ban', 'label' => 'Firewall'],
        'restart_cron' => ['service' => 'crond', 'label' => 'Cron'],
    ];

    if (isset($serviceMap[$action])) {
        $svc = $serviceMap[$action];
        // Firewall safe-restart: abrir puertos esenciales después de reiniciar
        if ($svc['service'] === 'firewalld') {
            $result = castpanelCtl("service_restart {$svc['service']}");
            if ($result['success']) {
                sleep(1);
                $portsOpened = [];
                foreach (['http', 'https', 'ssh'] as $p) {
                    $r = castpanelCtl("firewall_open $p");
                    if ($r['success']) $portsOpened[] = $p;
                }
                $r = castpanelCtl("firewall_open 8000-8200/tcp");
                if ($r['success']) $portsOpened[] = '8000-8200/tcp';
                castpanelCtl("firewall_reload");
                $msg = "Firewall reiniciado. Puertos abiertos: " . implode(', ', $portsOpened);
                $msgType = 'success';
            } else {
                $msg = "Error reiniciando Firewall: " . ($result['error'] ?? 'desconocido');
                $msgType = 'error';
            }
        } else {
            $result = castpanelCtl("service_restart {$svc['service']}");
            if ($result['success']) {
                $msg = "{$svc['label']} reiniciado exitosamente";
                $msgType = 'success';
            } else {
                $msg = "Error reiniciando {$svc['label']}: " . ($result['error'] ?? 'desconocido');
                $msgType = 'error';
            }
        }
    } elseif ($action === 'full_reset_ssh') {
        $r1 = castpanelCtl("service_restart sshd");
        $r2 = castpanelCtl("service_restart fail2ban");
        if ($r1['success']) {
            $msg = 'SSH completamente reiniciado';
            if ($r2['success']) $msg .= ' + Fail2ban reiniciado';
            $msgType = 'success';
        } else {
            $msg = "Error: " . ($r1['error'] ?? 'desconocido');
            $msgType = 'error';
        }
    }
}

/**
 * Detectar estado de servicio — PHP nativo (no shell_exec).
 * 4 capas: castpanelCtl → TCP port → PID + /proc → heurísticas
 */
function getRestartServiceStatus(string $name): array {
    $active = false;
    $pid = '';

    // Método 1: castpanelCtl IPC
    $enabled = false;
    $result = castpanelCtl("service_status $name");
    if (!empty($result['success']) && isset($result['status'])) {
        $isActive = ($result['status'] === 'active' || $result['status'] === 'running');
        if ($isActive) {
            return [
                'active'  => true,
                'enabled' => (bool)($result['enabled'] ?? false),
                'pid'     => (string)($result['pid'] ?? ''),
                'installed' => true,
            ];
        }
        // Si dice inactivo, guardar enabled pero seguir verificando con otros métodos
        $enabled = (bool)($result['enabled'] ?? false);
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
            'crond'     => ['/var/run/crond.pid', '/run/crond/crond.pid', '/run/crond.pid'],
            'postfix'   => ['/var/spool/postfix/pid/master.pid'],
            'firewalld' => ['/var/run/firewalld.pid', '/run/firewalld/firewalld.pid'],
            'docker'    => ['/var/run/docker.pid', '/run/docker.pid'],
            'icecast'   => ['/var/run/icecast/icecast.pid'],
        ];
        foreach ($pidFiles[$name] ?? [] as $pidFile) {
            if (file_exists($pidFile)) {
                $pidVal = trim(@file_get_contents($pidFile) ?: '');
                if ($pidVal && is_numeric($pidVal) && is_dir("/proc/$pidVal")) {
                    $active = true;
                    $pid = $pidVal;
                    break;
                }
            }
        }
    }

    // Método 4: Detección especial
    if (!$active) {
        if ($name === 'docker' && file_exists('/var/run/docker.sock')) $active = true;
        elseif ($name === 'mariadb' && file_exists('/var/lib/mysql/mysql.sock')) $active = true;
        elseif ($name === 'httpd') $active = true;
        elseif ($name === 'crond' || $name === 'firewalld') {
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

    // Fallback: systemctl (SELinux puede bloquear /proc)
    if (!$active) {
        $sysOut = @shell_exec("systemctl is-active " . escapeshellarg($name) . " 2>/dev/null");
        if ($sysOut !== null && trim($sysOut) === 'active') {
            $active = true;
        }
    }

    // Detectar si el servicio está instalado
    $unitFile = "{$name}.service";
    $installed = file_exists("/usr/lib/systemd/system/$unitFile")
              || file_exists("/etc/systemd/system/$unitFile")
              || file_exists("/lib/systemd/system/$unitFile");

    // Auto-inicio: revisar symlinks de systemd
    $unitFile = "{$name}.service";
    $enabled = file_exists("/etc/systemd/system/multi-user.target.wants/$unitFile")
            || file_exists("/etc/systemd/system/default.target.wants/$unitFile");

    return ['active' => $active, 'enabled' => $enabled, 'pid' => $pid, 'installed' => $installed];
}

// Current service status
$services = [
    ['id' => 'mariadb', 'label' => 'MySQL / MariaDB', 'action' => 'restart_mysql', 'icon' => 'database',
     'desc' => 'Base de datos principal del panel. Reiniciar si hay problemas de conexión a la BD.', 'critical' => true],
    ['id' => 'sshd', 'label' => 'Servidor SSH', 'action' => 'restart_ssh', 'icon' => 'lock',
     'desc' => 'Servicio de acceso remoto SSH. Reiniciar si hay problemas de conexión remota.', 'critical' => true],
    ['id' => 'postfix', 'label' => 'Servidor de Correo', 'action' => 'restart_mail', 'icon' => 'mail',
     'desc' => 'Postfix mail server. Reiniciar si los correos no se envían.', 'critical' => false],
    ['id' => 'httpd', 'label' => 'Apache HTTP', 'action' => 'restart_apache', 'icon' => 'globe',
     'desc' => 'Servidor web del panel. Reiniciar si el panel no carga.', 'critical' => true],
    ['id' => 'docker', 'label' => 'Docker', 'action' => 'restart_docker', 'icon' => 'layers',
     'desc' => 'Motor de contenedores para AutoDJ (Liquidsoap). Reiniciar si los AutoDJs no funcionan.', 'critical' => true],
    ['id' => 'icecast', 'label' => 'Icecast', 'action' => 'restart_icecast', 'icon' => 'radio',
     'desc' => 'Servidor de streaming Icecast. Reiniciar si las radios Icecast no transmiten.', 'critical' => false],
    ['id' => 'fail2ban', 'label' => 'Firewall', 'action' => 'restart_firewall', 'icon' => 'shield',
     'desc' => 'Firewall del servidor. Reiniciar si hay problemas de acceso a puertos.', 'critical' => false],
    ['id' => 'crond', 'label' => 'Cron', 'action' => 'restart_cron', 'icon' => 'clock',
     'desc' => 'Tareas programadas. Reiniciar si los cron jobs no se ejecutan.', 'critical' => false],
];

foreach ($services as &$svc) {
    $status = getRestartServiceStatus($svc['id']);
    $svc['active'] = $status['active'];
    $svc['pid'] = $status['pid'];
    $svc['installed'] = $status['installed'] ?? true;
    $svc['uptime'] = '';
}
unset($svc);
?>

<?php if ($msg): ?>
<div class="alert alert-<?= $msgType === 'error' ? 'danger' : 'success' ?>">
    <?= htmlspecialchars($msg) ?>
</div>
<?php endif; ?>

<div style="margin-bottom:1.5rem;padding:1rem;background:#fef3c7;border:1px solid #fcd34d;border-radius:8px;">
    <p style="margin:0;color:#92400e;font-size:.9rem;">
        <?= cpIcon('info', 14) ?> <strong>Precaución:</strong> Reiniciar servicios críticos puede causar interrupciones temporales. Los servicios marcados como críticos afectarán al panel y/o streaming si se reinician.
    </p>
</div>

<!-- Service Cards -->
<div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(350px,1fr));gap:1rem;margin-bottom:1.5rem;">
    <?php foreach ($services as $svc): ?>
    <div class="card" style="border-left:4px solid <?= $svc['active'] ? '#28a745' : '#dc3545' ?>;">
        <div class="card-body" style="padding:1.2rem;">
            <div style="display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:.8rem;">
                <div>
                    <strong style="font-size:1.05rem;"><?= cpIcon($svc['icon'], 16) ?> <?= htmlspecialchars($svc['label']) ?></strong>
                    <?php if ($svc['critical']): ?>
                    <span class="badge badge-warning" style="font-size:.7rem;margin-left:.3rem;">CRÍTICO</span>
                    <?php endif; ?>
                </div>
                <?php if (!($svc['installed'] ?? true)): ?>
                <span class="badge" style="background:#6c757d;color:#fff;">No instalado</span>
                <?php else: ?>
                <span class="badge badge-<?= $svc['active'] ? 'success' : 'danger' ?>">
                    <?= $svc['active'] ? '● Activo' : '○ Inactivo' ?>
                </span>
                <?php endif; ?>
            </div>

            <p style="font-size:.85rem;color:#666;margin-bottom:.8rem;"><?= $svc['desc'] ?></p>

            <div style="display:flex;justify-content:space-between;align-items:center;">
                <div style="font-size:.75rem;color:#999;">
                    <?php if ($svc['pid'] && $svc['pid'] !== '0'): ?>
                    PID: <?= $svc['pid'] ?>
                    <?php endif; ?>
                </div>
                <?php if ($svc['installed']): ?>
                <form method="post" style="display:inline;">
                    <?= csrfField() ?>
                    <input type="hidden" name="action" value="<?= $svc['action'] ?>">
                    <button type="submit" class="btn btn-sm btn-warning"
                            onclick="return confirm('¿Reiniciar <?= htmlspecialchars($svc['label']) ?>?<?= $svc['critical'] ? ' Este es un servicio CRÍTICO.' : '' ?>')">
                        <?= cpIcon('refresh', 12) ?> Reiniciar
                    </button>
                </form>
                <?php else: ?>
                <span style="color:#999;font-size:.8rem;">—</span>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <?php endforeach; ?>
</div>

<!-- Full SSH Reset -->
<div class="card">
    <div class="card-header">
        <h3><?= cpIcon('lock', 18) ?> Restablecimiento Completo SSH</h3>
    </div>
    <div class="card-body">
        <p style="margin-bottom:1rem;">
            Realiza un reinicio completo del servicio SSH incluyendo Fail2ban. Útil cuando hay muchos intentos de acceso bloqueados o problemas persistentes de conexión.
        </p>
        <div style="display:flex;gap:1rem;flex-wrap:wrap;">
            <form method="post">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="full_reset_ssh">
                <button type="submit" class="btn btn-danger"
                        onclick="return confirm('¿Ejecutar restablecimiento completo de SSH + Fail2ban? Esto puede desconectar sesiones SSH activas.')">
                    <?= cpIcon('refresh', 14) ?> Restablecimiento Completo SSH
                </button>
            </form>
        </div>

        <div style="margin-top:1rem;padding:1rem;background:#f8f9fa;border-radius:8px;">
            <strong>Esto ejecutará:</strong>
            <ol style="margin:.5rem 0 0 1.5rem;font-size:.85rem;color:#666;">
                <li>Reiniciar servicio SSHD</li>
                <li>Reiniciar Fail2ban (limpia bans temporales)</li>
            </ol>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/layout_footer.php'; ?>
