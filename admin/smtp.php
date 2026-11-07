<?php
/**
 * CastPanel v2.4 - Admin: Configuración SMTP de Correo
 * Configurar servidor de correo saliente para notificaciones
 */
$pageTitle = 'Configuración SMTP';
$pageIcon = 'mail';
$isAdmin = true;
require_once __DIR__ . '/../includes/layout_header.php';
requireRole('admin');

$db = getDB();
$msg = '';
$msgType = '';

// SMTP config keys
$smtpKeys = ['smtp_host', 'smtp_port', 'smtp_user', 'smtp_pass', 'smtp_from', 'smtp_from_name', 'smtp_encryption', 'smtp_enabled'];

// POST
if ($_SERVER['REQUEST_METHOD'] === 'POST' && verifyCsrf()) {
    $action = $_POST['action'] ?? 'save';

    try {
        if ($action === 'save') {
            $values = [
                'smtp_host' => trim($_POST['smtp_host'] ?? ''),
                'smtp_port' => trim($_POST['smtp_port'] ?? '587'),
                'smtp_user' => trim($_POST['smtp_user'] ?? ''),
                'smtp_pass' => $_POST['smtp_pass'] ?? '',
                'smtp_from' => trim($_POST['smtp_from'] ?? ''),
                'smtp_from_name' => trim($_POST['smtp_from_name'] ?? 'CastPanel'),
                'smtp_encryption' => $_POST['smtp_encryption'] ?? 'tls',
                'smtp_enabled' => isset($_POST['smtp_enabled']) ? '1' : '0',
            ];

            foreach ($values as $key => $val) {
                $stmtCheck = $db->prepare("SELECT 1 FROM configuracion WHERE clave = ?");
                $stmtCheck->execute([$key]);
                if ($stmtCheck->fetch()) {
                    $db->prepare("UPDATE configuracion SET valor = ? WHERE clave = ?")->execute([$val, $key]);
                } else {
                    $db->prepare("INSERT INTO configuracion (clave, valor) VALUES (?, ?)")->execute([$key, $val]);
                }
            }
            $msg = 'Configuración SMTP guardada exitosamente';
            $msgType = 'success';
        } elseif ($action === 'test') {
            // Send test email
            $testTo = trim($_POST['test_email'] ?? '');
            if ($testTo) {
                // Load current SMTP config from DB
                $smtpConfig = [];
                $rows = $db->query("SELECT clave, valor FROM configuracion WHERE clave LIKE 'smtp_%'")->fetchAll();
                foreach ($rows as $r) $smtpConfig[$r['clave']] = $r['valor'];

                $from = $smtpConfig['smtp_from'] ?? 'noreply@example.com';
                $fromName = $smtpConfig['smtp_from_name'] ?? 'CastPanel';
                $headers = "From: $fromName <$from>\r\nContent-Type: text/html; charset=UTF-8\r\n";
                $subject = 'CastPanel - Prueba SMTP';
                $body = '<h2>Prueba de Correo CastPanel</h2><p>Si recibes este correo, la configuración SMTP está funcionando correctamente.</p>';

                $sent = @mail($testTo, $subject, $body, $headers);
                if ($sent) {
                    $msg = "Correo de prueba enviado a $testTo";
                    $msgType = 'success';
                } else {
                    $msg = "Error al enviar correo de prueba. Verifique la configuración SMTP.";
                    $msgType = 'error';
                }
            }
        }
    } catch (PDOException $e) {
        $msg = 'Error: ' . $e->getMessage();
        $msgType = 'error';
    }
}

// Load current config
$config = [];
try {
    $rows = $db->query("SELECT clave, valor FROM configuracion WHERE clave LIKE 'smtp_%'")->fetchAll();
    foreach ($rows as $r) $config[$r['clave']] = $r['valor'];
} catch (PDOException $e) {}
?>

<?php if ($msg): ?>
<div class="alert alert-<?= $msgType === 'error' ? 'danger' : 'success' ?>">
    <?= htmlspecialchars($msg) ?>
</div>
<?php endif; ?>

<div class="card" style="margin-bottom:1.5rem;">
    <div class="card-header">
        <h3><?= cpIcon('mail', 18) ?> Servidor SMTP</h3>
    </div>
    <div class="card-body">
        <form method="post">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="save">

            <div class="form-group" style="margin-bottom:1rem;">
                <label style="display:flex;align-items:center;gap:.5rem;cursor:pointer;">
                    <input type="checkbox" name="smtp_enabled" <?= ($config['smtp_enabled'] ?? '0') === '1' ? 'checked' : '' ?>>
                    <strong>Habilitar envío de correos SMTP</strong>
                </label>
            </div>

            <div class="form-grid-3">
                <div class="form-group">
                    <label>Servidor SMTP</label>
                    <input type="text" name="smtp_host" class="form-control"
                           value="<?= htmlspecialchars($config['smtp_host'] ?? '') ?>" placeholder="smtp.gmail.com">
                </div>
                <div class="form-group">
                    <label>Puerto</label>
                    <input type="number" name="smtp_port" class="form-control"
                           value="<?= htmlspecialchars($config['smtp_port'] ?? '587') ?>" placeholder="587">
                </div>
                <div class="form-group">
                    <label>Encriptación</label>
                    <select name="smtp_encryption" class="form-control">
                        <option value="tls" <?= ($config['smtp_encryption'] ?? 'tls') === 'tls' ? 'selected' : '' ?>>TLS</option>
                        <option value="ssl" <?= ($config['smtp_encryption'] ?? '') === 'ssl' ? 'selected' : '' ?>>SSL</option>
                        <option value="none" <?= ($config['smtp_encryption'] ?? '') === 'none' ? 'selected' : '' ?>>Ninguna</option>
                    </select>
                </div>
            </div>
            <div class="form-grid-2">
                <div class="form-group">
                    <label>Usuario SMTP</label>
                    <input type="text" name="smtp_user" class="form-control"
                           value="<?= htmlspecialchars($config['smtp_user'] ?? '') ?>" placeholder="user@gmail.com">
                </div>
                <div class="form-group">
                    <label>Contraseña SMTP</label>
                    <input type="password" name="smtp_pass" class="form-control"
                           value="<?= htmlspecialchars($config['smtp_pass'] ?? '') ?>" placeholder="••••••••">
                </div>
            </div>
            <div class="form-grid-2">
                <div class="form-group">
                    <label>Email Remitente (From)</label>
                    <input type="email" name="smtp_from" class="form-control"
                           value="<?= htmlspecialchars($config['smtp_from'] ?? '') ?>" placeholder="noreply@example.com">
                </div>
                <div class="form-group">
                    <label>Nombre del Remitente</label>
                    <input type="text" name="smtp_from_name" class="form-control"
                           value="<?= htmlspecialchars($config['smtp_from_name'] ?? 'CastPanel') ?>" placeholder="CastPanel">
                </div>
            </div>

            <button type="submit" class="btn btn-primary" style="margin-top:1rem;">
                <?= cpIcon('save', 14) ?> Guardar Configuración
            </button>
        </form>
    </div>
</div>

<!-- Test Email -->
<div class="card">
    <div class="card-header">
        <h3><?= cpIcon('mail', 18) ?> Enviar Correo de Prueba</h3>
    </div>
    <div class="card-body">
        <form method="post">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="test">
            <div class="form-grid-2">
                <div class="form-group">
                    <label>Email de Destino</label>
                    <input type="email" name="test_email" class="form-control" required placeholder="test@example.com">
                </div>
                <div class="form-group" style="display:flex;align-items:flex-end;">
                    <button type="submit" class="btn btn-success"><?= cpIcon('mail', 14) ?> Enviar Prueba</button>
                </div>
            </div>
        </form>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/layout_footer.php'; ?>
