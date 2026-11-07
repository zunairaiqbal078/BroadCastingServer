<?php
/**
 * CastPanel v2.4 - Admin: phpMyAdmin
 * Acceso rápido a phpMyAdmin y gestión de base de datos
 */
$pageTitle = 'phpMyAdmin';
$pageIcon = 'database';
$isAdmin = true;
require_once __DIR__ . '/../includes/layout_header.php';
requireRole('admin');

$db = getDB();

// Database info
$dbSize = 0;
$tables = [];
try {
    $tablesInfo = $db->query("SHOW TABLE STATUS")->fetchAll();
    foreach ($tablesInfo as $t) {
        $size = ($t['Data_length'] ?? 0) + ($t['Index_length'] ?? 0);
        $tables[] = [
            'name' => $t['Name'],
            'rows' => $t['Rows'] ?? 0,
            'size' => $size,
            'engine' => $t['Engine'] ?? 'InnoDB',
            'collation' => $t['Collation'] ?? 'utf8mb4_general_ci',
        ];
        $dbSize += $size;
    }
} catch (PDOException $e) {
    $tables = [];
}

// MariaDB version
$dbVersion = 'N/A';
try {
    $row = $db->query("SELECT VERSION() as v")->fetch();
    $dbVersion = $row['v'] ?? 'N/A';
} catch (PDOException $e) {}

// phpMyAdmin detection - multiple methods for AlmaLinux/RHEL/CentOS
$pmaUrl = '';
$pmaDetected = false;

// Método 1: Buscar directorio de instalación
$pmaDirs = [
    '/usr/share/phpMyAdmin'       => '/phpMyAdmin',     // RHEL/AlmaLinux (yum/dnf)
    '/usr/share/phpmyadmin'       => '/phpmyadmin',     // Debian/Ubuntu
    '/var/www/html/phpMyAdmin'    => '/phpMyAdmin',
    '/var/www/html/phpmyadmin'    => '/phpmyadmin',
    '/var/www/html/pma'           => '/pma',
    '/var/www/phpMyAdmin'         => '/phpMyAdmin',
    '/var/www/phpmyadmin'         => '/phpmyadmin',
    '/opt/phpmyadmin'             => '/phpmyadmin',
    '/opt/phpMyAdmin'             => '/phpMyAdmin',
];
foreach ($pmaDirs as $dir => $urlPath) {
    if (is_dir($dir)) {
        $pmaUrl = $urlPath;
        $pmaDetected = true;
        break;
    }
}

// Método 2: Buscar en config de Apache (alias phpMyAdmin)
if (!$pmaDetected) {
    $apacheConfFiles = [
        '/etc/httpd/conf.d/phpMyAdmin.conf',
        '/etc/httpd/conf.d/phpmyadmin.conf',
        '/etc/apache2/conf-enabled/phpmyadmin.conf',
        '/etc/apache2/conf.d/phpmyadmin.conf',
    ];
    foreach ($apacheConfFiles as $confFile) {
        if (file_exists($confFile)) {
            $confContent = @file_get_contents($confFile) ?: '';
            // Buscar Alias /phpMyAdmin o similar
            if (preg_match('/^\s*Alias\s+(\S+)\s+/mi', $confContent, $aliasMatch)) {
                $pmaUrl = $aliasMatch[1];
                $pmaDetected = true;
                break;
            }
            // Si existe el archivo de config, phpMyAdmin está instalado
            $pmaUrl = '/phpMyAdmin';
            $pmaDetected = true;
            break;
        }
    }
}

// Método 3: Verificar si el paquete RPM está instalado (via archivos conocidos)
if (!$pmaDetected) {
    $rpmIndicators = [
        '/usr/share/phpMyAdmin/index.php',
        '/usr/share/phpMyAdmin/config.inc.php',
        '/usr/share/phpmyadmin/index.php',
    ];
    foreach ($rpmIndicators as $indicator) {
        if (file_exists($indicator)) {
            $pmaUrl = strpos($indicator, 'phpMyAdmin') !== false ? '/phpMyAdmin' : '/phpmyadmin';
            $pmaDetected = true;
            break;
        }
    }
}
?>

<div class="stats-grid" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:1rem;margin-bottom:1.5rem;">
    <div class="card" style="text-align:center;padding:1.2rem;">
        <div style="font-size:1.8rem;font-weight:700;color:var(--primary);"><?= count($tables) ?></div>
        <div style="color:#666;font-size:.85rem;">Tablas</div>
    </div>
    <div class="card" style="text-align:center;padding:1.2rem;">
        <div style="font-size:1.8rem;font-weight:700;color:#28a745;"><?= formatBytes($dbSize) ?></div>
        <div style="color:#666;font-size:.85rem;">Tamaño Total BD</div>
    </div>
    <div class="card" style="text-align:center;padding:1.2rem;">
        <div style="font-size:1.3rem;font-weight:700;color:#6366f1;"><?= htmlspecialchars($dbVersion) ?></div>
        <div style="color:#666;font-size:.85rem;">MariaDB Version</div>
    </div>
</div>

<!-- phpMyAdmin Access -->
<div class="card" style="margin-bottom:1.5rem;">
    <div class="card-header">
        <h3><?= cpIcon('database', 18) ?> Acceso a phpMyAdmin</h3>
    </div>
    <div class="card-body">
        <?php if ($pmaUrl): ?>
            <p style="margin-bottom:1rem;">phpMyAdmin está instalado y configurado con acceso directo.</p>
            <a href="<?= htmlspecialchars($pmaUrl) ?>/" target="_blank" class="btn btn-primary" style="font-size:1.05rem;padding:.7rem 1.5rem;">
                <?= cpIcon('database', 16) ?> Abrir phpMyAdmin
            </a>
            <div style="margin-top:1rem;padding:.8rem 1rem;background:#d1fae5;border:1px solid #6ee7b7;border-radius:8px;font-size:.85rem;color:#065f46;">
                <?= cpIcon('check', 14) ?> Acceso directo habilitado — no requiere usuario/contraseña
            </div>
        <?php else: ?>
            <div class="alert alert-warning" style="margin-bottom:1rem;">
                <?= cpIcon('alert', 14) ?> phpMyAdmin no fue detectado en este servidor.
            </div>
            <div style="padding:1rem;background:#f8f9fa;border-radius:8px;">
                <strong>Instalación manual (AlmaLinux 8):</strong>
                <pre style="background:#1e293b;color:#e2e8f0;padding:1rem;border-radius:8px;font-size:.82rem;margin-top:.5rem;overflow-x:auto;">cd /tmp
curl -sL https://files.phpmyadmin.net/phpMyAdmin/5.2.1/phpMyAdmin-5.2.1-all-languages.zip -o pma.zip
unzip -q pma.zip
sudo mv phpMyAdmin-5.2.1-all-languages /usr/share/phpmyadmin
sudo chown -R apache:apache /usr/share/phpmyadmin
sudo systemctl restart httpd</pre>
            </div>
        <?php endif; ?>

        <div style="margin-top:1rem;padding:1rem;background:#f8f9fa;border-radius:8px;">
            <strong>Base de Datos:</strong>
            <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(200px,1fr));gap:.5rem;margin-top:.5rem;">
                <div>Host: <code><?= defined('DB_HOST') ? DB_HOST : '127.0.0.1' ?></code></div>
                <div>Base de datos: <code><?= defined('DB_NAME') ? DB_NAME : 'castpanel' ?></code></div>
                <div>Motor: <code>MariaDB <?= htmlspecialchars($dbVersion) ?></code></div>
            </div>
        </div>
    </div>
</div>

<!-- Tables Overview -->
<div class="card">
    <div class="card-header">
        <h3><?= cpIcon('layers', 18) ?> Tablas de la Base de Datos (<?= count($tables) ?>)</h3>
    </div>
    <div class="card-body" style="padding:0;">
        <div style="overflow-x:auto;">
        <table class="table" style="margin:0;">
            <thead>
                <tr>
                    <th>Tabla</th>
                    <th>Filas</th>
                    <th>Tamaño</th>
                    <th>Engine</th>
                    <th>Collation</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($tables as $t): ?>
                <tr>
                    <td><code><?= htmlspecialchars($t['name']) ?></code></td>
                    <td><?= number_format($t['rows']) ?></td>
                    <td><?= formatBytes($t['size']) ?></td>
                    <td><?= htmlspecialchars($t['engine']) ?></td>
                    <td style="font-size:.8rem;"><?= htmlspecialchars($t['collation']) ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/layout_footer.php'; ?>
