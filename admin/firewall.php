<?php
/**
 * CastPanel v2.4 - Admin: Seguridad y Firewall
 * Gestión de reglas de firewall y seguridad del servidor
 */
$pageTitle = 'Seguridad y Firewall';
$pageIcon = 'shield';
$isAdmin = true;
require_once __DIR__ . '/../includes/layout_header.php';
require_once __DIR__ . '/../includes/provisioning.php';
requireRole('admin');

$msg = '';
$msgType = '';

// POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && verifyCsrf()) {
    $action = $_POST['action'] ?? '';

    if ($action === 'open_port') {
        $port = (int)($_POST['port'] ?? 0);
        $protocol = $_POST['protocol'] ?? 'tcp';
        if ($port > 0 && $port <= 65535) {
            $result = castpanelCtl("firewall_open $port/$protocol");
            $msg = $result['success']
                ? "Puerto $port/$protocol abierto exitosamente"
                : "Error: " . ($result['error'] ?? 'desconocido');
            $msgType = $result['success'] ? 'success' : 'error';
        }
    } elseif ($action === 'close_port') {
        $port = (int)($_POST['port'] ?? 0);
        $protocol = $_POST['protocol'] ?? 'tcp';
        if ($port > 0) {
            $result = castpanelCtl("firewall_close $port/$protocol");
            $msg = $result['success']
                ? "Puerto $port/$protocol cerrado"
                : "Error: " . ($result['error'] ?? 'desconocido');
            $msgType = $result['success'] ? 'success' : 'error';
        }
    } elseif ($action === 'reload_firewall') {
        $result = castpanelCtl("firewall_reload");
        $msg = $result['success'] ? 'Firewall recargado' : 'Error: ' . ($result['error'] ?? 'desconocido');
        $msgType = $result['success'] ? 'success' : 'error';
    }
}

// =====================================================
// Detección de estado usando métodos PHP nativos
// (shell_exec puede estar bloqueado por SELinux/PHP)
// =====================================================

// --- SELinux: leer config directamente ---
$selinuxStatus = 'Disabled';
if (file_exists('/etc/selinux/config')) {
    $selinuxConf = @file_get_contents('/etc/selinux/config') ?: '';
    if (preg_match('/^\s*SELINUX\s*=\s*(\S+)/m', $selinuxConf, $m)) {
        $selinuxMode = strtolower(trim($m[1]));
        $selinuxStatus = match($selinuxMode) {
            'enforcing'  => 'Enforcing',
            'permissive' => 'Permissive',
            'disabled'   => 'Disabled',
            default      => ucfirst($selinuxMode),
        };
    }
    // Verificar estado runtime via /sys (puede diferir del config)
    if (file_exists('/sys/fs/selinux/enforce')) {
        $enforceVal = trim(@file_get_contents('/sys/fs/selinux/enforce') ?: '');
        if ($enforceVal === '1') {
            $selinuxStatus = 'Enforcing';
        } elseif ($enforceVal === '0' && $selinuxStatus !== 'Disabled') {
            $selinuxStatus = 'Permissive';
        }
    }
} elseif (file_exists('/sys/fs/selinux/enforce')) {
    $enforceVal = trim(@file_get_contents('/sys/fs/selinux/enforce') ?: '');
    $selinuxStatus = $enforceVal === '1' ? 'Enforcing' : 'Permissive';
}

// --- Firewalld: detectar vía castpanelCtl / PID / config ---
$firewallActive = false;
$defaultZone = 'N/A';
$openPorts = [];
$allowedServices = [];

// Método 1: castpanelCtl para obtener toda la info de firewall
$fwResult = castpanelCtl("firewall_info");
if (!empty($fwResult['success'])) {
    $firewallActive = !empty($fwResult['active']);
    $defaultZone = $fwResult['zone'] ?? 'public';
    if (!empty($fwResult['ports'])) {
        $openPorts = is_array($fwResult['ports']) ? $fwResult['ports'] : array_filter(explode(' ', $fwResult['ports']));
    }
    if (!empty($fwResult['services'])) {
        $allowedServices = is_array($fwResult['services']) ? $fwResult['services'] : array_filter(explode(' ', $fwResult['services']));
    }
} else {
    // Método 2: PID file + /proc para detectar si firewalld corre
    foreach (['/var/run/firewalld.pid', '/run/firewalld/firewalld.pid'] as $pidFile) {
        if (file_exists($pidFile)) {
            $pidVal = trim(@file_get_contents($pidFile) ?: '');
            if ($pidVal && is_numeric($pidVal) && is_dir("/proc/$pidVal")) {
                $firewallActive = true;
                break;
            }
        }
    }
    // Fallback: verificar si el socket de dbus de firewalld existe
    if (!$firewallActive && file_exists('/run/dbus/system_bus_socket')) {
        // Verificar systemd symlink como indicador
        if (file_exists('/etc/systemd/system/multi-user.target.wants/firewalld.service')
            || file_exists('/etc/systemd/system/dbus-org.fedoraproject.FirewallD1.service')) {
            // El servicio está habilitado, probablemente activo
            // Verificar via puerto de gestión o presencia del proceso
            foreach (glob('/proc/*/comm') as $commFile) {
                $comm = @file_get_contents($commFile);
                if ($comm !== false && trim($comm) === 'firewalld') {
                    $firewallActive = true;
                    break;
                }
            }
        }
    }

    // Leer zona y puertos desde archivos de config
    if (file_exists('/etc/firewalld/firewalld.conf')) {
        $fwConf = @file_get_contents('/etc/firewalld/firewalld.conf') ?: '';
        if (preg_match('/^\s*DefaultZone\s*=\s*(\S+)/m', $fwConf, $m)) {
            $defaultZone = $m[1];
        }
    }
    // Leer puertos/servicios de la zona XML
    $zoneFile = "/etc/firewalld/zones/{$defaultZone}.xml";
    if (!file_exists($zoneFile)) {
        $zoneFile = "/usr/lib/firewalld/zones/{$defaultZone}.xml";
    }
    if (file_exists($zoneFile)) {
        $zoneXml = @file_get_contents($zoneFile) ?: '';
        if (preg_match_all('/<port\s+protocol="([^"]+)"\s+port="([^"]+)"/', $zoneXml, $pm, PREG_SET_ORDER)) {
            foreach ($pm as $match) {
                $openPorts[] = $match[2] . '/' . $match[1];
            }
        }
        if (preg_match_all('/<service\s+name="([^"]+)"/', $zoneXml, $sm)) {
            $allowedServices = $sm[1];
        }
    }
}

// --- Fail2ban: detectar vía PID + config ---
$fail2banActive = false;
$fail2banJails = [];
$fail2banInstalled = file_exists('/usr/bin/fail2ban-server') || file_exists('/usr/bin/fail2ban-client');
foreach (['/var/run/fail2ban/fail2ban.pid', '/run/fail2ban/fail2ban.pid'] as $pidFile) {
    if (file_exists($pidFile)) {
        $pidVal = trim(@file_get_contents($pidFile) ?: '');
        if ($pidVal && is_numeric($pidVal) && is_dir("/proc/$pidVal")) {
            $fail2banActive = true;
            break;
        }
        // Archivo PID existe pero no se puede leer (permisos/SELinux)
        $fail2banActive = true;
        break;
    }
}
// Fallback: verificar via systemctl si no hay archivo PID
if (!$fail2banActive && $fail2banInstalled) {
    $sysOut = @shell_exec('systemctl is-active fail2ban 2>/dev/null');
    if ($sysOut !== null && trim($sysOut) === 'active') {
        $fail2banActive = true;
    }
}
if ($fail2banActive) {
    // Leer jails de la configuración
    $jailConf = '/etc/fail2ban/jail.local';
    if (!file_exists($jailConf)) $jailConf = '/etc/fail2ban/jail.conf';
    if (file_exists($jailConf)) {
        $jailContent = @file_get_contents($jailConf) ?: '';
        if (preg_match_all('/^\[([^\]]+)\]/m', $jailContent, $sections)) {
            foreach ($sections[1] as $section) {
                if (in_array($section, ['DEFAULT', 'INCLUDES', 'Definition'])) continue;
                // Buscar enabled = true en esta sección
                $pattern = '/\[' . preg_quote($section, '/') . '\][^\[]*?enabled\s*=\s*true/si';
                if (preg_match($pattern, $jailContent)) {
                    $fail2banJails[] = $section;
                }
            }
        }
    }
    if (empty($fail2banJails)) {
        $fail2banJails = ['sshd'];
    }
}

// --- Últimos logins SSH: /var/log/wtmp vía lastlog, o castpanelCtl ---
$recentLogins = 'No disponible';

// Método 1: Leer /var/log/secure o /var/log/auth.log directamente
$logFiles = ['/var/log/secure', '/var/log/auth.log'];
$found = false;
foreach ($logFiles as $logFile) {
    if (file_exists($logFile) && is_readable($logFile)) {
        $secureLog = @file_get_contents($logFile) ?: '';
        if ($secureLog) {
            $lines = explode("\n", trim($secureLog));
            $sshLines = array_filter($lines, function($l) {
                return stripos($l, 'sshd') !== false
                    && (stripos($l, 'Accepted') !== false || stripos($l, 'session opened') !== false);
            });
            if (!empty($sshLines)) {
                $recentLogins = implode("\n", array_slice(array_values($sshLines), -10));
                $found = true;
            }
        }
        break;
    }
}

// Método 2: Leer /var/log/lastlog o wtmp como fallback
if (!$found) {
    // Intentar castpanelCtl con comando "last" (más compatible)
    $loginResult = castpanelCtl("last_logins 10");
    if (!empty($loginResult['success']) && !empty($loginResult['output'])) {
        $recentLogins = $loginResult['output'];
        $found = true;
    }
}

// Método 3: Fallback mensaje útil
if (!$found) {
    $recentLogins = 'Logs no accesibles desde el panel web. Para ver los últimos accesos SSH ejecute en terminal: last -n 10';
}
?>

<?php if ($msg): ?>
<div class="alert alert-<?= $msgType === 'error' ? 'danger' : 'success' ?>">
    <?= htmlspecialchars($msg) ?>
</div>
<?php endif; ?>

<!-- Security Overview -->
<div class="stats-grid" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:1rem;margin-bottom:1.5rem;">
    <div class="card" style="text-align:center;padding:1.2rem;">
        <div style="font-weight:600;margin-bottom:.5rem;"><?= cpIcon('shield', 16) ?> SELinux</div>
        <span class="badge badge-<?= $selinuxStatus === 'Enforcing' ? 'success' : ($selinuxStatus === 'Permissive' ? 'warning' : 'danger') ?>">
            <?= htmlspecialchars($selinuxStatus) ?>
        </span>
    </div>
    <div class="card" style="text-align:center;padding:1.2rem;">
        <div style="font-weight:600;margin-bottom:.5rem;"><?= cpIcon('fire', 16) ?> Firewalld</div>
        <span class="badge badge-<?= $firewallActive ? 'success' : 'danger' ?>">
            <?= $firewallActive ? 'Activo' : 'Inactivo' ?>
        </span>
    </div>
    <div class="card" style="text-align:center;padding:1.2rem;">
        <div style="font-weight:600;margin-bottom:.5rem;"><?= cpIcon('lock', 16) ?> Fail2ban</div>
        <span class="badge badge-<?= $fail2banActive ? 'success' : ($fail2banInstalled ? 'warning' : 'danger') ?>">
            <?= $fail2banActive ? 'Activo (' . count($fail2banJails) . ' jails)' : ($fail2banInstalled ? 'Inactivo' : 'No instalado') ?>
        </span>
    </div>
    <div class="card" style="text-align:center;padding:1.2rem;">
        <div style="font-weight:600;margin-bottom:.5rem;"><?= cpIcon('globe', 16) ?> Zona Default</div>
        <span class="badge badge-info"><?= htmlspecialchars($defaultZone) ?></span>
    </div>
</div>

<!-- Open Port -->
<div class="card" style="margin-bottom:1.5rem;">
    <div class="card-header">
        <h3><?= cpIcon('plus', 18) ?> Abrir Puerto en Firewall</h3>
    </div>
    <div class="card-body">
        <form method="post">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="open_port">
            <div class="form-grid-3">
                <div class="form-group">
                    <label>Puerto</label>
                    <input type="number" name="port" class="form-control" required min="1" max="65535" placeholder="8000">
                </div>
                <div class="form-group">
                    <label>Protocolo</label>
                    <select name="protocol" class="form-control">
                        <option value="tcp">TCP</option>
                        <option value="udp">UDP</option>
                    </select>
                </div>
                <div class="form-group" style="display:flex;align-items:flex-end;">
                    <button type="submit" class="btn btn-success"><?= cpIcon('plus', 14) ?> Abrir Puerto</button>
                </div>
            </div>
        </form>
    </div>
</div>

<!-- Current Rules -->
<div class="card" style="margin-bottom:1.5rem;">
    <div class="card-header" style="display:flex;justify-content:space-between;align-items:center;">
        <h3><?= cpIcon('shield', 18) ?> Puertos Abiertos</h3>
        <form method="post" style="display:inline;">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="reload_firewall">
            <button type="submit" class="btn btn-sm btn-warning"><?= cpIcon('refresh', 14) ?> Recargar Firewall</button>
        </form>
    </div>
    <div class="card-body">
        <?php if (empty($openPorts)): ?>
            <p style="color:#999;">No hay puertos adicionales abiertos (solo servicios predeterminados)</p>
        <?php else: ?>
            <div style="display:flex;flex-wrap:wrap;gap:.5rem;">
                <?php foreach ($openPorts as $port): ?>
                <div style="display:inline-flex;align-items:center;gap:.5rem;background:#f0f9ff;border:1px solid #bfdbfe;border-radius:6px;padding:.4rem .8rem;">
                    <strong><?= htmlspecialchars($port) ?></strong>
                    <form method="post" style="display:inline;">
                        <?= csrfField() ?>
                        <input type="hidden" name="action" value="close_port">
                        <?php
                        $parts = explode('/', $port);
                        ?>
                        <input type="hidden" name="port" value="<?= (int)$parts[0] ?>">
                        <input type="hidden" name="protocol" value="<?= htmlspecialchars($parts[1] ?? 'tcp') ?>">
                        <button type="submit" style="background:none;border:none;color:#dc3545;cursor:pointer;padding:0;" title="Cerrar">&times;</button>
                    </form>
                </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <?php if (!empty($allowedServices)): ?>
        <div style="margin-top:1rem;">
            <strong>Servicios permitidos:</strong>
            <div style="display:flex;flex-wrap:wrap;gap:.5rem;margin-top:.5rem;">
                <?php foreach ($allowedServices as $svc): ?>
                <span class="badge badge-info"><?= htmlspecialchars($svc) ?></span>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>
    </div>
</div>

<!-- Recent Logins -->
<div class="card">
    <div class="card-header">
        <h3><?= cpIcon('user', 18) ?> Últimos Inicios de Sesión SSH</h3>
    </div>
    <div class="card-body">
        <pre style="background:#f4f5f7;padding:1rem;border-radius:8px;font-size:.8rem;overflow-x:auto;max-height:300px;"><?= htmlspecialchars($recentLogins) ?></pre>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/layout_footer.php'; ?>
