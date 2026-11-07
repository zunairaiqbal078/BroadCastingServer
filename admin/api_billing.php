<?php
/**
 * CastPanel v2.4 - Admin: Facturación y Automatización (API)
 * Configurar integración con WHMCS/Blesta y API keys
 */
$pageTitle = 'Facturación y API';
$pageIcon = 'dollar';
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
        if ($action === 'save_api') {
            $fields = [
                'api_enabled' => isset($_POST['api_enabled']) ? '1' : '0',
                'api_key' => trim($_POST['api_key'] ?? ''),
                'whmcs_url' => trim($_POST['whmcs_url'] ?? ''),
                'whmcs_api_id' => trim($_POST['whmcs_api_id'] ?? ''),
                'whmcs_api_secret' => trim($_POST['whmcs_api_secret'] ?? ''),
                'billing_system' => $_POST['billing_system'] ?? 'none',
                'auto_provision' => isset($_POST['auto_provision']) ? '1' : '0',
                'auto_suspend' => isset($_POST['auto_suspend']) ? '1' : '0',
                'auto_terminate' => isset($_POST['auto_terminate']) ? '1' : '0',
            ];

            foreach ($fields as $key => $val) {
                $stmtCheck = $db->prepare("SELECT 1 FROM configuracion WHERE clave = ?");
                $stmtCheck->execute([$key]);
                if ($stmtCheck->fetch()) {
                    $db->prepare("UPDATE configuracion SET valor = ? WHERE clave = ?")->execute([$val, $key]);
                } else {
                    $db->prepare("INSERT INTO configuracion (clave, valor) VALUES (?, ?)")->execute([$key, $val]);
                }
            }
            $msg = 'Configuración de API/Facturación guardada';
            $msgType = 'success';
        } elseif ($action === 'generate_key') {
            $newKey = bin2hex(random_bytes(32));
            $stmtCheck = $db->query("SELECT 1 FROM configuracion WHERE clave = 'api_key'");
            if ($stmtCheck->fetch()) {
                $db->prepare("UPDATE configuracion SET valor = ? WHERE clave = 'api_key'")->execute([$newKey]);
            } else {
                $db->prepare("INSERT INTO configuracion (clave, valor) VALUES ('api_key', ?)")->execute([$newKey]);
            }
            $msg = 'Nueva API Key generada: ' . $newKey;
            $msgType = 'success';
        }
    } catch (PDOException $e) {
        $msg = 'Error: ' . $e->getMessage();
        $msgType = 'error';
    }
}

// Load config
$config = [];
try {
    $rows = $db->query("SELECT clave, valor FROM configuracion WHERE clave IN ('api_enabled','api_key','whmcs_url','whmcs_api_id','whmcs_api_secret','billing_system','auto_provision','auto_suspend','auto_terminate')")->fetchAll();
    foreach ($rows as $r) $config[$r['clave']] = $r['valor'];
} catch (PDOException $e) {}
?>

<?php if ($msg): ?>
<div class="alert alert-<?= $msgType === 'error' ? 'danger' : 'success' ?>">
    <?= htmlspecialchars($msg) ?>
</div>
<?php endif; ?>

<form method="post">
    <?= csrfField() ?>
    <input type="hidden" name="action" value="save_api">

    <!-- API Config -->
    <div class="card" style="margin-bottom:1.5rem;">
        <div class="card-header">
            <h3><?= cpIcon('key', 18) ?> API de CastPanel</h3>
        </div>
        <div class="card-body">
            <div class="form-group" style="margin-bottom:1rem;">
                <label style="display:flex;align-items:center;gap:.5rem;cursor:pointer;">
                    <input type="checkbox" name="api_enabled" <?= ($config['api_enabled'] ?? '0') === '1' ? 'checked' : '' ?>>
                    <strong>Habilitar API REST</strong>
                </label>
            </div>
            <div class="form-grid-2">
                <div class="form-group">
                    <label>API Key</label>
                    <div style="display:flex;gap:.5rem;">
                        <input type="text" name="api_key" class="form-control" style="font-family:monospace;"
                               value="<?= htmlspecialchars($config['api_key'] ?? '') ?>" readonly>
                    </div>
                </div>
                <div class="form-group" style="display:flex;align-items:flex-end;">
                    <button type="submit" name="action" value="generate_key" class="btn btn-warning"><?= cpIcon('refresh', 14) ?> Generar Nueva Key</button>
                </div>
            </div>
            <p style="font-size:.85rem;color:#666;margin-top:.5rem;">
                Endpoint API: <code><?= htmlspecialchars((defined('SSL_DOMAIN') ? 'https://' . SSL_DOMAIN : BASE_URL)) ?>/api/</code>
            </p>
        </div>
    </div>

    <!-- Billing System -->
    <div class="card" style="margin-bottom:1.5rem;">
        <div class="card-header">
            <h3><?= cpIcon('dollar', 18) ?> Sistema de Facturación</h3>
        </div>
        <div class="card-body">
            <div class="form-grid-2">
                <div class="form-group">
                    <label>Sistema de Facturación</label>
                    <select name="billing_system" class="form-control">
                        <option value="none" <?= ($config['billing_system'] ?? 'none') === 'none' ? 'selected' : '' ?>>Ninguno</option>
                        <option value="whmcs" <?= ($config['billing_system'] ?? '') === 'whmcs' ? 'selected' : '' ?>>WHMCS</option>
                        <option value="blesta" <?= ($config['billing_system'] ?? '') === 'blesta' ? 'selected' : '' ?>>Blesta</option>
                        <option value="clientexec" <?= ($config['billing_system'] ?? '') === 'clientexec' ? 'selected' : '' ?>>ClientExec</option>
                    </select>
                </div>
                <div class="form-group">
                    <label>URL del Sistema</label>
                    <input type="url" name="whmcs_url" class="form-control"
                           value="<?= htmlspecialchars($config['whmcs_url'] ?? '') ?>" placeholder="https://billing.example.com">
                </div>
            </div>
            <div class="form-grid-2">
                <div class="form-group">
                    <label>API Identifier</label>
                    <input type="text" name="whmcs_api_id" class="form-control"
                           value="<?= htmlspecialchars($config['whmcs_api_id'] ?? '') ?>">
                </div>
                <div class="form-group">
                    <label>API Secret</label>
                    <input type="password" name="whmcs_api_secret" class="form-control"
                           value="<?= htmlspecialchars($config['whmcs_api_secret'] ?? '') ?>">
                </div>
            </div>
        </div>
    </div>

    <!-- Automation -->
    <div class="card" style="margin-bottom:1.5rem;">
        <div class="card-header">
            <h3><?= cpIcon('gear', 18) ?> Automatización</h3>
        </div>
        <div class="card-body">
            <div class="form-grid-3">
                <div class="form-group">
                    <label style="display:flex;align-items:center;gap:.5rem;cursor:pointer;">
                        <input type="checkbox" name="auto_provision" <?= ($config['auto_provision'] ?? '0') === '1' ? 'checked' : '' ?>>
                        Auto-Provisionar cuentas nuevas
                    </label>
                    <small style="color:#999;">Crear y activar estación automáticamente al pagar</small>
                </div>
                <div class="form-group">
                    <label style="display:flex;align-items:center;gap:.5rem;cursor:pointer;">
                        <input type="checkbox" name="auto_suspend" <?= ($config['auto_suspend'] ?? '0') === '1' ? 'checked' : '' ?>>
                        Auto-Suspender por impago
                    </label>
                    <small style="color:#999;">Suspender estación automáticamente si el pago falla</small>
                </div>
                <div class="form-group">
                    <label style="display:flex;align-items:center;gap:.5rem;cursor:pointer;">
                        <input type="checkbox" name="auto_terminate" <?= ($config['auto_terminate'] ?? '0') === '1' ? 'checked' : '' ?>>
                        Auto-Terminar cuentas canceladas
                    </label>
                    <small style="color:#999;">Eliminar estación al cancelar el servicio</small>
                </div>
            </div>
        </div>
    </div>

    <button type="submit" class="btn btn-primary" style="margin-bottom:2rem;">
        <?= cpIcon('save', 14) ?> Guardar Toda la Configuración
    </button>
</form>

<?php require_once __DIR__ . '/../includes/layout_footer.php'; ?>
