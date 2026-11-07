<?php
/**
 * CastPanel v2.4 - Admin: Cambiar Nombre de Host
 * Configurar el hostname del servidor
 */
$pageTitle = 'Cambiar Nombre de Host';
$pageIcon = 'globe';
$isAdmin = true;
require_once __DIR__ . '/../includes/layout_header.php';
require_once __DIR__ . '/../includes/provisioning.php';
requireRole('admin');

$db = getDB();
$msg = '';
$msgType = '';

// Get current hostname
$currentHostname = @gethostname() ?: 'localhost';
$currentDomain = defined('SSL_DOMAIN') ? SSL_DOMAIN : '';

// POST
if ($_SERVER['REQUEST_METHOD'] === 'POST' && verifyCsrf()) {
    $newHostname = trim($_POST['hostname'] ?? '');
    $newDomain = trim($_POST['panel_domain'] ?? '');

    try {
        // Actualizar dominio del panel en BD
        if ($newDomain) {
            $stmtCheck = $db->query("SELECT 1 FROM configuracion WHERE clave = 'ssl_domain'");
            if ($stmtCheck->fetch()) {
                $db->prepare("UPDATE configuracion SET valor = ? WHERE clave = 'ssl_domain'")->execute([$newDomain]);
            } else {
                $db->prepare("INSERT INTO configuracion (clave, valor) VALUES ('ssl_domain', ?)")->execute([$newDomain]);
            }
        }

        $msg = "Dominio del panel actualizado a '$newDomain'.";
        $currentDomain = $newDomain;

        // Cambiar hostname del servidor via castpanelCtl
        if ($newHostname && $newHostname !== $currentHostname) {
            // Validar formato del hostname
            if (preg_match('/^[a-zA-Z0-9]([a-zA-Z0-9\-\.]{0,253}[a-zA-Z0-9])?$/', $newHostname)) {
                $result = castpanelCtl("set_hostname $newHostname");
                if (!empty($result['success'])) {
                    $msg = "Hostname cambiado a '$newHostname' y dominio actualizado a '$newDomain'.";
                    $currentHostname = $newHostname;
                } else {
                    $msg .= " Hostname no pudo ser cambiado: " . ($result['error'] ?? 'error desconocido') . ".";
                    $msgType = 'warning';
                }
            } else {
                $msg .= " Hostname inválido: solo letras, números, guiones y puntos.";
                $msgType = 'warning';
            }
        }

        if (!$msgType) $msgType = 'success';
    } catch (PDOException $e) {
        $msg = 'Error: ' . $e->getMessage();
        $msgType = 'error';
    }
}

// Server info
$serverIp = $_SERVER['SERVER_ADDR'] ?? '0.0.0.0';
$phpVersion = phpversion();
$osInfo = php_uname('s') . ' ' . php_uname('r');
?>

<?php if ($msg): ?>
<div class="alert alert-<?= $msgType === 'error' ? 'danger' : ($msgType === 'warning' ? 'warning' : 'success') ?>">
    <?= htmlspecialchars($msg) ?>
</div>
<?php endif; ?>

<div class="card" style="margin-bottom:1.5rem;">
    <div class="card-header">
        <h3><?= cpIcon('globe', 18) ?> Información del Servidor</h3>
    </div>
    <div class="card-body">
        <div class="form-grid-3">
            <div style="background:#f8f9fa;padding:1rem;border-radius:8px;text-align:center;">
                <div style="font-size:.8rem;color:#666;margin-bottom:.3rem;">Hostname Actual</div>
                <div style="font-weight:600;font-size:1.1rem;"><?= htmlspecialchars($currentHostname) ?></div>
            </div>
            <div style="background:#f8f9fa;padding:1rem;border-radius:8px;text-align:center;">
                <div style="font-size:.8rem;color:#666;margin-bottom:.3rem;">IP del Servidor</div>
                <div style="font-weight:600;font-size:1.1rem;"><?= htmlspecialchars($serverIp) ?></div>
            </div>
            <div style="background:#f8f9fa;padding:1rem;border-radius:8px;text-align:center;">
                <div style="font-size:.8rem;color:#666;margin-bottom:.3rem;">Sistema Operativo</div>
                <div style="font-weight:600;font-size:1.1rem;"><?= htmlspecialchars($osInfo) ?></div>
            </div>
        </div>
    </div>
</div>

<div class="card">
    <div class="card-header">
        <h3><?= cpIcon('edit', 18) ?> Configurar Hostname y Dominio</h3>
    </div>
    <div class="card-body">
        <form method="post">
            <?= csrfField() ?>
            <div class="form-grid-2">
                <div class="form-group">
                    <label>Hostname del Servidor</label>
                    <input type="text" name="hostname" class="form-control"
                           value="<?= htmlspecialchars($currentHostname) ?>" placeholder="server1.example.com">
                    <small style="color:#999;">Se aplica automáticamente en el servidor vía castpanel-ctl</small>
                </div>
                <div class="form-group">
                    <label>Dominio del Panel (SSL_DOMAIN)</label>
                    <input type="text" name="panel_domain" class="form-control"
                           value="<?= htmlspecialchars($currentDomain) ?>" placeholder="panel.example.com">
                    <small style="color:#999;">Dominio usado para URLs del panel y streams</small>
                </div>
            </div>
            <button type="submit" class="btn btn-primary" style="margin-top:1rem;">
                <?= cpIcon('save', 14) ?> Guardar Cambios
            </button>
        </form>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/layout_footer.php'; ?>
