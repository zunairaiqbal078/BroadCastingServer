<?php
/**
 * CastPanel v2.4 - Admin: Configuración de Puerto SSL
 * Gestionar los puertos SSL para streaming
 */
$pageTitle = 'Configuración Puerto SSL';
$pageIcon = 'key';
$isAdmin = true;
require_once __DIR__ . '/../includes/layout_header.php';
requireRole('admin');

$db = getDB();
$msg = '';
$msgType = '';

// POST
if ($_SERVER['REQUEST_METHOD'] === 'POST' && verifyCsrf()) {
    $action = $_POST['action'] ?? '';

    try {
        $fields = [
            'ssl_port' => trim($_POST['ssl_port'] ?? '443'),
            'stream_ssl_port' => trim($_POST['stream_ssl_port'] ?? '8443'),
            'admin_ssl_port' => trim($_POST['admin_ssl_port'] ?? '2087'),
        ];

        foreach ($fields as $key => $value) {
            $stmtCheck = $db->prepare("SELECT 1 FROM configuracion WHERE clave = ?");
            $stmtCheck->execute([$key]);
            if ($stmtCheck->fetch()) {
                $db->prepare("UPDATE configuracion SET valor = ? WHERE clave = ?")->execute([$value, $key]);
            } else {
                $db->prepare("INSERT INTO configuracion (clave, valor) VALUES (?, ?)")->execute([$key, $value]);
            }
        }

        $msg = 'Configuración de puertos SSL guardada. Reinicie Apache para aplicar los cambios.';
        $msgType = 'success';
    } catch (PDOException $e) {
        $msg = 'Error: ' . $e->getMessage();
        $msgType = 'error';
    }
}

// Load current values
$sslPort = '443';
$streamSslPort = '8443';
$adminSslPort = '2087';
try {
    $rows = $db->query("SELECT clave, valor FROM configuracion WHERE clave IN ('ssl_port','stream_ssl_port','admin_ssl_port')")->fetchAll();
    foreach ($rows as $r) {
        if ($r['clave'] === 'ssl_port') $sslPort = $r['valor'];
        if ($r['clave'] === 'stream_ssl_port') $streamSslPort = $r['valor'];
        if ($r['clave'] === 'admin_ssl_port') $adminSslPort = $r['valor'];
    }
} catch (PDOException $e) {}

// Check listening ports
$listeningPorts = [];
$output = @shell_exec('ss -tlnp 2>/dev/null') ?: '';
if (preg_match_all('/:(\d+)\s/', $output, $matches)) {
    $listeningPorts = array_unique($matches[1]);
}
?>

<?php if ($msg): ?>
<div class="alert alert-<?= $msgType === 'error' ? 'danger' : 'success' ?>">
    <?= htmlspecialchars($msg) ?>
</div>
<?php endif; ?>

<div class="card" style="margin-bottom:1.5rem;">
    <div class="card-header">
        <h3><?= cpIcon('key', 18) ?> Puertos SSL</h3>
    </div>
    <div class="card-body">
        <form method="post">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="save">

            <div class="form-grid-3">
                <div class="form-group">
                    <label>Puerto SSL del Panel (HTTPS)</label>
                    <input type="number" name="ssl_port" class="form-control" min="1" max="65535"
                           value="<?= htmlspecialchars($sslPort) ?>">
                    <small style="color:#666;">Puerto estándar HTTPS (default: 443)</small>
                    <?php if (in_array($sslPort, $listeningPorts)): ?>
                        <div style="color:#28a745;font-size:.8rem;margin-top:.3rem;"><?= cpIcon('check', 12) ?> Puerto activo</div>
                    <?php endif; ?>
                </div>
                <div class="form-group">
                    <label>Puerto SSL de Streaming</label>
                    <input type="number" name="stream_ssl_port" class="form-control" min="1" max="65535"
                           value="<?= htmlspecialchars($streamSslPort) ?>">
                    <small style="color:#666;">Puerto para streams seguros (default: 8443)</small>
                    <?php if (in_array($streamSslPort, $listeningPorts)): ?>
                        <div style="color:#28a745;font-size:.8rem;margin-top:.3rem;"><?= cpIcon('check', 12) ?> Puerto activo</div>
                    <?php endif; ?>
                </div>
                <div class="form-group">
                    <label>Puerto SSL Admin</label>
                    <input type="number" name="admin_ssl_port" class="form-control" min="1" max="65535"
                           value="<?= htmlspecialchars($adminSslPort) ?>">
                    <small style="color:#666;">Puerto de administración (default: 2087)</small>
                    <?php if (in_array($adminSslPort, $listeningPorts)): ?>
                        <div style="color:#28a745;font-size:.8rem;margin-top:.3rem;"><?= cpIcon('check', 12) ?> Puerto activo</div>
                    <?php endif; ?>
                </div>
            </div>

            <button type="submit" class="btn btn-primary" style="margin-top:1rem;">
                <?= cpIcon('save', 14) ?> Guardar Configuración
            </button>
        </form>
    </div>
</div>

<div class="card">
    <div class="card-body">
        <h4><?= cpIcon('terminal', 16) ?> Configurar SSL en Apache (SSH)</h4>
        <pre style="background:#f4f5f7;padding:1rem;border-radius:8px;font-size:.85rem;overflow-x:auto;">
# Habilitar módulo SSL
sudo yum install mod_ssl -y

# Editar configuración Apache SSL
sudo nano /etc/httpd/conf.d/ssl.conf

# Reiniciar Apache
sudo systemctl restart httpd

# Verificar puertos escuchando
sudo ss -tlnp | grep -E '(443|8443|2087)'</pre>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/layout_footer.php'; ?>
