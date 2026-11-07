<?php
/**
 * CastPanel v2.1 - Branding de Reseller
 *
 * Los resellers pueden personalizar logos, títulos y favicons
 * que verán sus propios clientes. Sobreescribe el branding global.
 */

$pageTitle = 'Mi Branding';
$pageIcon = 'palette';
$isReseller = true;

require_once __DIR__ . '/../includes/layout_header.php';
require_once __DIR__ . '/../includes/branding.php';
requireRole('reseller');

$db = getDB();
$resellerId = (int)$_SESSION['user_id'];
$success = '';
$error = '';

// ── Obtener o crear registro de branding del reseller ──
$stmt = $db->prepare("SELECT * FROM branding WHERE owner_type = 'reseller' AND owner_id = ? LIMIT 1");
$stmt->execute([$resellerId]);
$brand = $stmt->fetch();

if (!$brand) {
    $stmt = $db->prepare("INSERT INTO branding (owner_type, owner_id) VALUES ('reseller', ?)");
    $stmt->execute([$resellerId]);
    $stmt = $db->prepare("SELECT * FROM branding WHERE owner_type = 'reseller' AND owner_id = ? LIMIT 1");
    $stmt->execute([$resellerId]);
    $brand = $stmt->fetch();
}

// Cargar branding global como referencia
$globalBrand = getBranding(null);

// ── Procesar formulario ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && verifyCsrf()) {

    // Eliminar logo
    if (isset($_POST['delete_logo'])) {
        $type = $_POST['delete_logo'];
        $allowed = ['logo_login', 'logo_sidebar', 'logo_favicon', 'logo_player'];
        if (in_array($type, $allowed)) {
            deleteBrandingFile($type, 'reseller', $resellerId);
            $success = 'Logo eliminado. Se usará el logo por defecto del panel.';
        }
    }

    // Guardar textos
    if (isset($_POST['save_branding'])) {
        $panelTitle    = trim($_POST['panel_title'] ?? '');
        $panelSubtitle = trim($_POST['panel_subtitle'] ?? '');
        $loginTitle    = trim($_POST['login_title'] ?? '');
        $footerText    = trim($_POST['footer_text'] ?? '');

        $stmt = $db->prepare("
            UPDATE branding SET
                panel_title = ?, panel_subtitle = ?, login_title = ?,
                footer_text = ?, updated_at = NOW()
            WHERE owner_type = 'reseller' AND owner_id = ?
        ");
        $stmt->execute([
            $panelTitle ?: null,
            $panelSubtitle ?: null,
            $loginTitle ?: null,
            $footerText ?: null,
            $resellerId,
        ]);

        $success = 'Branding actualizado. Sus clientes verán los cambios al recargar la página.';
    }

    // Subir logos
    $logoTypes = ['logo_login', 'logo_sidebar', 'logo_favicon', 'logo_player'];
    foreach ($logoTypes as $logoType) {
        if (isset($_FILES[$logoType]) && $_FILES[$logoType]['error'] === UPLOAD_ERR_OK) {
            $result = uploadBrandingFile($_FILES[$logoType], $logoType, 'reseller', $resellerId);
            if ($result['success']) {
                $stmt = $db->prepare("UPDATE branding SET `{$logoType}` = ?, updated_at = NOW() WHERE owner_type = 'reseller' AND owner_id = ?");
                $stmt->execute([$result['path'], $resellerId]);
                $success = 'Logo subido correctamente.';
            } else {
                $error = $result['error'];
            }
        }
    }

    // Recargar
    $stmt = $db->prepare("SELECT * FROM branding WHERE owner_type = 'reseller' AND owner_id = ? LIMIT 1");
    $stmt->execute([$resellerId]);
    $brand = $stmt->fetch();
}

$logoLabels = [
    'logo_login'   => ['titulo' => 'Logo de Login', 'desc' => 'Página de inicio de sesión de sus clientes. 200×60px PNG.'],
    'logo_sidebar'  => ['titulo' => 'Logo del Sidebar', 'desc' => 'Barra lateral del panel de sus clientes. 140×40px PNG.'],
    'logo_favicon'  => ['titulo' => 'Favicon', 'desc' => 'Icono del navegador para sus clientes. 32×32px ICO/PNG.'],
    'logo_player'   => ['titulo' => 'Logo del Reproductor', 'desc' => 'Reproductor público de las radios de sus clientes. 200×200px PNG.'],
];
?>

<?php if ($success): ?>
    <div class="alert alert-success"><?= htmlspecialchars($success) ?></div>
<?php endif; ?>
<?php if ($error): ?>
    <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
<?php endif; ?>

<div class="info-box" style="margin-bottom:24px;padding:16px;background:rgba(108,60,224,.08);border-radius:12px;border:1px solid rgba(108,60,224,.2)">
    <strong><?= cpIcon('palette',14) ?> Branding para sus Clientes</strong><br>
    <span style="font-size:13px;color:var(--text-secondary)">
        Personalice los logos, títulos y favicons que verán sus clientes.
        Las imágenes subidas reemplazarán los valores por defecto del panel.
        Al eliminar una imagen se restaurará el logo por defecto.
    </span>
</div>

<!-- ════ TÍTULOS ════ -->
<div class="card" style="margin-bottom:24px">
    <div class="card-header">
        <h3 class="card-title"><?= cpIcon('gear',18) ?> Título del Panel</h3>
    </div>
    <div class="card-body">
        <form method="POST">
            <?= csrfField() ?>
            <input type="hidden" name="save_branding" value="1">

            <div class="form-grid" style="display:grid;grid-template-columns:1fr 1fr;gap:16px">
                <div class="form-group">
                    <label>Título del Panel</label>
                    <input type="text" name="panel_title" class="form-control"
                           value="<?= htmlspecialchars($brand['panel_title'] ?? '') ?>"
                           placeholder="<?= htmlspecialchars($globalBrand['panel_title']) ?> (por defecto)">
                    <small style="color:var(--text-secondary)">Deje vacío para usar el valor global</small>
                </div>

                <div class="form-group">
                    <label>Subtítulo</label>
                    <input type="text" name="panel_subtitle" class="form-control"
                           value="<?= htmlspecialchars($brand['panel_subtitle'] ?? '') ?>"
                           placeholder="<?= htmlspecialchars($globalBrand['panel_subtitle']) ?> (por defecto)">
                </div>

                <div class="form-group">
                    <label>Título de Login</label>
                    <input type="text" name="login_title" class="form-control"
                           value="<?= htmlspecialchars($brand['login_title'] ?? '') ?>"
                           placeholder="<?= htmlspecialchars($globalBrand['login_title']) ?> (por defecto)">
                </div>

                <div class="form-group">
                    <label>Texto del Footer</label>
                    <input type="text" name="footer_text" class="form-control"
                           value="<?= htmlspecialchars($brand['footer_text'] ?? '') ?>"
                           placeholder="Pie de página personalizado">
                </div>
            </div>

            <div style="margin-top:16px">
                <button type="submit" class="btn btn-primary"><?= cpIcon('save',14) ?> Guardar</button>
            </div>
        </form>
    </div>
</div>

<!-- ════ LOGOS ════ -->
<div class="card">
    <div class="card-header">
        <h3 class="card-title"><?= cpIcon('image',18) ?> Logos y Favicon</h3>
    </div>
    <div class="card-body">
        <form method="POST" enctype="multipart/form-data">
            <?= csrfField() ?>

            <div class="form-grid" style="display:grid;grid-template-columns:1fr 1fr;gap:24px">
                <?php foreach ($logoLabels as $field => $meta): ?>
                <div style="background:var(--bg-secondary);border-radius:12px;padding:20px;border:1px solid var(--border)">
                    <h4 style="margin-bottom:4px;font-size:14px"><?= $meta['titulo'] ?></h4>
                    <p style="font-size:12px;color:var(--text-secondary);margin-bottom:12px"><?= $meta['desc'] ?></p>

                    <?php if (!empty($brand[$field])): ?>
                        <div style="margin-bottom:12px;padding:12px;background:#000;border-radius:8px;text-align:center">
                            <img src="<?= htmlspecialchars($brand[$field]) ?>" alt="<?= $meta['titulo'] ?>"
                                 style="max-height:80px;max-width:100%;object-fit:contain">
                        </div>
                        <form method="POST" style="display:inline">
                            <?= csrfField() ?>
                            <input type="hidden" name="delete_logo" value="<?= $field ?>">
                            <button type="submit" class="btn btn-sm"
                                    style="background:#ef4444;color:#fff;border:none;font-size:12px"
                                    onclick="return confirm('¿Eliminar este logo?')">
                                <?= cpIcon('trash',14) ?> Eliminar
                            </button>
                        </form>
                    <?php else: ?>
                        <div style="margin-bottom:12px;padding:20px;background:rgba(108,60,224,.05);border-radius:8px;text-align:center;border:2px dashed rgba(108,60,224,.2)">
                            <span style="font-size:24px;opacity:.4"><?= cpIcon('image',24) ?></span>
                            <p style="font-size:12px;color:var(--text-secondary);margin-top:4px">Usando logo por defecto</p>
                        </div>
                    <?php endif; ?>

                    <div style="margin-top:8px">
                        <input type="file" name="<?= $field ?>" accept="image/*,.ico"
                               style="font-size:12px;width:100%"
                               onchange="this.form.submit()">
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </form>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/layout_footer.php'; ?>
