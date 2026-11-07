<?php
/**
 * CastPanel v2.4 - Admin: Dominios / Certificados SSL
 * Gestión de certificados SSL y dominios del panel
 */
$pageTitle = 'Dominios / Certificados SSL';
$pageIcon = 'lock';
$isAdmin = true;
require_once __DIR__ . '/../includes/layout_header.php';
requireRole('admin');

$db = getDB();
$msg = '';
$msgType = '';

// Check if ssl_certificates table exists
$tableExists = false;
try {
    $db->query("SELECT 1 FROM ssl_certificates LIMIT 1");
    $tableExists = true;
} catch (PDOException $e) {}

// POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && verifyCsrf()) {
    $action = $_POST['action'] ?? '';

    try {
        if ($action === 'save_domain') {
            $domain = trim($_POST['ssl_domain'] ?? '');
            $sslEnabled = isset($_POST['ssl_enabled']) ? 1 : 0;

            // Update config table
            $stmtCheck = $db->query("SELECT 1 FROM configuracion WHERE clave = 'ssl_domain'");
            if ($stmtCheck->fetch()) {
                $db->prepare("UPDATE configuracion SET valor = ? WHERE clave = 'ssl_domain'")->execute([$domain]);
            } else {
                $db->prepare("INSERT INTO configuracion (clave, valor) VALUES ('ssl_domain', ?)")->execute([$domain]);
            }

            $stmtCheck2 = $db->query("SELECT 1 FROM configuracion WHERE clave = 'ssl_enabled'");
            if ($stmtCheck2->fetch()) {
                $db->prepare("UPDATE configuracion SET valor = ? WHERE clave = 'ssl_enabled'")->execute([$sslEnabled]);
            } else {
                $db->prepare("INSERT INTO configuracion (clave, valor) VALUES ('ssl_enabled', ?)")->execute([$sslEnabled]);
            }

            $msg = 'Configuración de dominio SSL guardada';
            $msgType = 'success';
        } elseif ($action === 'add_cert' && $tableExists) {
            $domain = trim($_POST['cert_domain'] ?? '');
            $certType = $_POST['cert_type'] ?? 'letsencrypt';

            if ($domain) {
                $stmt = $db->prepare("INSERT INTO ssl_certificates (domain, cert_type, status, created_at) VALUES (?, ?, 'pending', NOW())");
                $stmt->execute([$domain, $certType]);
                $msg = "Certificado para '$domain' registrado. Ejecute certbot en el servidor para activarlo.";
                $msgType = 'success';
            }
        } elseif ($action === 'delete_cert' && $tableExists) {
            $id = (int)($_POST['cert_id'] ?? 0);
            if ($id > 0) {
                $db->prepare("DELETE FROM ssl_certificates WHERE id = ?")->execute([$id]);
                $msg = 'Certificado eliminado del registro';
                $msgType = 'success';
            }
        }
    } catch (PDOException $e) {
        $msg = 'Error: ' . $e->getMessage();
        $msgType = 'error';
    }
}

// Current config
$currentDomain = defined('SSL_DOMAIN') ? SSL_DOMAIN : '';
$sslEnabled = true;
try {
    $row = $db->query("SELECT valor FROM configuracion WHERE clave = 'ssl_domain'")->fetch();
    if ($row) $currentDomain = $row['valor'];
    $row2 = $db->query("SELECT valor FROM configuracion WHERE clave = 'ssl_enabled'")->fetch();
    if ($row2) $sslEnabled = (bool)$row2['valor'];
} catch (PDOException $e) {}

// Certificates list
$certificates = [];
if ($tableExists) {
    try {
        $certificates = $db->query("SELECT * FROM ssl_certificates ORDER BY created_at DESC")->fetchAll();
    } catch (PDOException $e) {}
}

// Check actual SSL cert on disk
$certInfo = [];
$certPath = '/etc/letsencrypt/live/' . $currentDomain . '/fullchain.pem';
if ($currentDomain && is_file($certPath)) {
    $certData = @openssl_x509_parse(@file_get_contents($certPath));
    if ($certData) {
        $certInfo['issuer'] = $certData['issuer']['O'] ?? 'Unknown';
        $certInfo['valid_from'] = date('Y-m-d', $certData['validFrom_time_t'] ?? 0);
        $certInfo['valid_to'] = date('Y-m-d', $certData['validTo_time_t'] ?? 0);
        $certInfo['expired'] = time() > ($certData['validTo_time_t'] ?? 0);
    }
}
?>

<?php if ($msg): ?>
<div class="alert alert-<?= $msgType === 'error' ? 'danger' : 'success' ?>">
    <?= htmlspecialchars($msg) ?>
</div>
<?php endif; ?>

<!-- SSL Domain Config -->
<div class="card" style="margin-bottom:1.5rem;">
    <div class="card-header">
        <h3><?= cpIcon('globe', 18) ?> Dominio SSL Principal</h3>
    </div>
    <div class="card-body">
        <form method="post">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="save_domain">
            <div class="form-grid-2">
                <div class="form-group">
                    <label>Dominio del Panel</label>
                    <input type="text" name="ssl_domain" class="form-control"
                           value="<?= htmlspecialchars($currentDomain) ?>" placeholder="panel.example.com">
                    <small style="color:#666;">El dominio principal donde se accede al panel</small>
                </div>
                <div class="form-group" style="display:flex;align-items:center;padding-top:1rem;">
                    <label style="display:flex;align-items:center;gap:.5rem;cursor:pointer;">
                        <input type="checkbox" name="ssl_enabled" <?= $sslEnabled ? 'checked' : '' ?>>
                        SSL Habilitado (HTTPS)
                    </label>
                </div>
            </div>

            <?php if (!empty($certInfo)): ?>
            <div style="background:#f0f9ff;border:1px solid #bfdbfe;border-radius:8px;padding:1rem;margin:1rem 0;">
                <strong><?= cpIcon('lock', 14) ?> Certificado Activo:</strong>
                <span>Emisor: <?= htmlspecialchars($certInfo['issuer']) ?></span> |
                <span>Válido: <?= $certInfo['valid_from'] ?> → <?= $certInfo['valid_to'] ?></span>
                <?php if ($certInfo['expired']): ?>
                    <span class="badge badge-danger">EXPIRADO</span>
                <?php else: ?>
                    <span class="badge badge-success">Vigente</span>
                <?php endif; ?>
            </div>
            <?php endif; ?>

            <button type="submit" class="btn btn-primary"><?= cpIcon('save', 14) ?> Guardar</button>
        </form>
    </div>
</div>

<!-- Certificate Management -->
<div class="card" style="margin-bottom:1.5rem;">
    <div class="card-header">
        <h3><?= cpIcon('lock', 18) ?> Agregar Certificado SSL</h3>
    </div>
    <div class="card-body">
        <?php if (!$tableExists): ?>
        <div class="alert alert-warning"><?= cpIcon('alert', 16) ?> La tabla <code>ssl_certificates</code> no existe. Ejecute la migración SQL.</div>
        <?php else: ?>
        <form method="post">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="add_cert">
            <div class="form-grid-3">
                <div class="form-group">
                    <label>Dominio</label>
                    <input type="text" name="cert_domain" class="form-control" required placeholder="stream.example.com">
                </div>
                <div class="form-group">
                    <label>Tipo de Certificado</label>
                    <select name="cert_type" class="form-control">
                        <option value="letsencrypt">Let's Encrypt (Gratis)</option>
                        <option value="custom">Certificado Personalizado</option>
                        <option value="self_signed">Auto-firmado</option>
                    </select>
                </div>
                <div class="form-group" style="display:flex;align-items:flex-end;">
                    <button type="submit" class="btn btn-primary"><?= cpIcon('plus', 14) ?> Registrar Certificado</button>
                </div>
            </div>
        </form>
        <?php endif; ?>
    </div>
</div>

<!-- Certificates Table -->
<?php if ($tableExists && !empty($certificates)): ?>
<div class="card">
    <div class="card-header">
        <h3><?= cpIcon('lock', 18) ?> Certificados Registrados (<?= count($certificates) ?>)</h3>
    </div>
    <div class="card-body" style="padding:0;">
        <table class="table" style="margin:0;">
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Dominio</th>
                    <th>Tipo</th>
                    <th>Estado</th>
                    <th>Registrado</th>
                    <th>Acciones</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($certificates as $c): ?>
                <tr>
                    <td><?= $c['id'] ?></td>
                    <td><strong><?= htmlspecialchars($c['domain']) ?></strong></td>
                    <td><?= htmlspecialchars($c['cert_type']) ?></td>
                    <td>
                        <?php if ($c['status'] === 'active'): ?>
                            <span class="badge badge-success">Activo</span>
                        <?php elseif ($c['status'] === 'expired'): ?>
                            <span class="badge badge-danger">Expirado</span>
                        <?php else: ?>
                            <span class="badge badge-warning">Pendiente</span>
                        <?php endif; ?>
                    </td>
                    <td><?= date('d/m/Y', strtotime($c['created_at'])) ?></td>
                    <td>
                        <form method="post" style="display:inline;" onsubmit="return confirm('¿Eliminar este certificado?')">
                            <?= csrfField() ?>
                            <input type="hidden" name="action" value="delete_cert">
                            <input type="hidden" name="cert_id" value="<?= $c['id'] ?>">
                            <button type="submit" class="btn btn-sm btn-danger"><?= cpIcon('trash', 14) ?></button>
                        </form>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>

<div class="card" style="margin-top:1.5rem;">
    <div class="card-body">
        <h4><?= cpIcon('terminal', 16) ?> Comandos Útiles (SSH)</h4>
        <p style="color:#666;font-size:.9rem;">Ejecutar en el servidor vía SSH para gestionar certificados Let's Encrypt:</p>
        <pre style="background:#f4f5f7;padding:1rem;border-radius:8px;font-size:.85rem;overflow-x:auto;">
# Instalar certbot
sudo yum install certbot python3-certbot-apache -y

# Generar certificado
sudo certbot --apache -d <?= htmlspecialchars($currentDomain ?: 'tu-dominio.com') ?>

# Renovar todos los certificados
sudo certbot renew

# Verificar estado
sudo certbot certificates</pre>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/layout_footer.php'; ?>
