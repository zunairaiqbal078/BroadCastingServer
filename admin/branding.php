<?php
/**
 * CastPanel v2.3 - Branding Global (Admin)
 * FIX: Formularios anidados corregidos - cada logo tiene su propio form
 */
$pageTitle = 'Branding / White-Label';
$pageIcon = 'palette';
$isAdmin = true;

require_once __DIR__ . '/../includes/layout_header.php';
require_once __DIR__ . '/../includes/branding.php';
requireRole('admin');

$db = getDB();
$success = '';
$error = '';
$_tableError = false;

// Obtener o crear registro de branding global
$brand = null;
try {
    $stmt = $db->prepare("SELECT * FROM branding WHERE owner_type = 'global' AND owner_id IS NULL LIMIT 1");
    $stmt->execute();
    $brand = $stmt->fetch();

    if (!$brand) {
        $db->exec("INSERT INTO branding (owner_type, owner_id, panel_title, panel_subtitle, login_title) VALUES ('global', NULL, 'CastPanel', 'Audio Streaming Platform', 'Iniciar Sesi\xC3\xB3n')");
        $stmt->execute();
        $brand = $stmt->fetch();
    }
} catch (PDOException $e) {
    $_tableError = true;
    $brand = [
        'panel_title' => 'CastPanel',
        'panel_subtitle' => 'Audio Streaming Platform',
        'login_title' => "Iniciar Sesi\xC3\xB3n",
        'footer_text' => '',
        'primary_color' => '#6c3ce0',
        'sidebar_color' => '#1e1b2e',
        'logo_login' => '',
        'logo_sidebar' => '',
        'logo_favicon' => '',
        'logo_player' => '',
    ];
}

// Procesar formulario
if ($_SERVER['REQUEST_METHOD'] === 'POST' && verifyCsrf() && !$_tableError) {
    try {
        // Eliminar un logo
        if (isset($_POST['delete_logo'])) {
            $type = $_POST['delete_logo'];
            $allowed = ['logo_login', 'logo_sidebar', 'logo_favicon', 'logo_player'];
            if (in_array($type, $allowed)) {
                deleteBrandingFile($type, 'global', null);
                $success = "Logo eliminado. Se restaurar\xC3\xA1 el logo por defecto de CastPanel.";
            }
        }

        // Guardar configuracion de texto
        if (isset($_POST['save_branding'])) {
            $panelTitle    = trim($_POST['panel_title'] ?? '');
            $panelSubtitle = trim($_POST['panel_subtitle'] ?? '');
            $loginTitle    = trim($_POST['login_title'] ?? '');
            $footerText    = trim($_POST['footer_text'] ?? '');
            $primaryColor  = trim($_POST['primary_color'] ?? '');
            $sidebarColor  = trim($_POST['sidebar_color'] ?? '');

            if ($primaryColor && !preg_match('/^#[0-9a-fA-F]{6}$/', $primaryColor)) {
                $primaryColor = null;
            }
            if ($sidebarColor && !preg_match('/^#[0-9a-fA-F]{6}$/', $sidebarColor)) {
                $sidebarColor = null;
            }

            $stmt = $db->prepare("
                UPDATE branding SET
                    panel_title = ?, panel_subtitle = ?, login_title = ?,
                    footer_text = ?, primary_color = ?, sidebar_color = ?,
                    updated_at = NOW()
                WHERE owner_type = 'global' AND owner_id IS NULL
            ");
            $stmt->execute([
                $panelTitle ?: 'CastPanel',
                $panelSubtitle ?: null,
                $loginTitle ?: "Iniciar Sesi\xC3\xB3n",
                $footerText ?: null,
                $primaryColor ?: null,
                $sidebarColor ?: null,
            ]);

            $success = "Configuraci\xC3\xB3n de branding guardada. Recargue la p\xC3\xA1gina para ver los cambios.";
        }

        // Subir UN logo (cada logo tiene su propio form ahora)
        $logoTypes = ['logo_login', 'logo_sidebar', 'logo_favicon', 'logo_player'];
        foreach ($logoTypes as $logoType) {
            if (isset($_FILES[$logoType]) && $_FILES[$logoType]['error'] === UPLOAD_ERR_OK) {
                $result = uploadBrandingFile($_FILES[$logoType], $logoType, 'global', null);
                if ($result['success']) {
                    $stmt = $db->prepare("UPDATE branding SET `{$logoType}` = ?, updated_at = NOW() WHERE owner_type = 'global' AND owner_id IS NULL");
                    $stmt->execute([$result['path']]);
                    $success = 'Logo subido correctamente.';
                } else {
                    $error = $result['error'];
                }
            }
        }

        // Recargar datos
        $stmt = $db->prepare("SELECT * FROM branding WHERE owner_type = 'global' AND owner_id IS NULL LIMIT 1");
        $stmt->execute();
        $brand = $stmt->fetch();
    } catch (PDOException $e) {
        $error = "Error en la operaci\xC3\xB3n: " . $e->getMessage();
    }
}

// Labels para los tipos de logo
$logoLabels = [
    'logo_login'   => ['titulo' => 'Logo de Login', 'desc' => "Se muestra en la p\xC3\xA1gina de inicio de sesi\xC3\xB3n. Recomendado: 200\xC3\x9760px, PNG transparente."],
    'logo_sidebar'  => ['titulo' => 'Logo del Sidebar', 'desc' => "Logo peque\xC3\xB1o en la barra lateral del panel. Recomendado: 140\xC3\x9740px, PNG transparente."],
    'logo_favicon'  => ['titulo' => 'Favicon', 'desc' => "Icono de la pesta\xC3\xB1a del navegador. Recomendado: 32\xC3\x9732px o 64\xC3\x9764px, ICO o PNG."],
    'logo_player'   => ['titulo' => 'Logo del Reproductor', 'desc' => "Logo para el reproductor p\xC3\xBAblico de radio. Recomendado: 200\xC3\x97200px, PNG."],
];
?>

<?php if ($_tableError): ?>
<div class="alert alert-warning"><?= cpIcon('warning', 16) ?> La tabla <strong>branding</strong> no existe. Ejecute la migraci<?= "\xC3\xB3" ?>n SQL v2.3 para crearla.</div>
<?php endif; ?>

<?php if ($success): ?>
    <div class="alert alert-success"><?= htmlspecialchars($success) ?></div>
<?php endif; ?>
<?php if ($error): ?>
    <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
<?php endif; ?>

<div class="info-box" style="margin-bottom:24px;padding:16px;background:rgba(108,60,224,.08);border-radius:12px;border:1px solid rgba(108,60,224,.2)">
    <strong><?= cpIcon('palette', 18) ?> Branding White-Label</strong><br>
    <span style="font-size:13px;color:var(--text-secondary)">
        Personalice los logos, t<?= "\xC3\xAD" ?>tulos y favicons de su panel. Las im<?= "\xC3\xA1" ?>genes subidas reemplazar<?= "\xC3\xA1" ?>n los valores por defecto.
        Al eliminar una imagen se restaurar<?= "\xC3\xA1" ?> el logo por defecto de CastPanel.
        Los resellers pueden configurar su propio branding desde su panel para sus clientes.
    </span>
</div>

<!-- SECCION: TITULOS Y COLORES -->
<div class="card" style="margin-bottom:24px">
    <div class="card-header">
        <h3 class="card-title"><?= cpIcon('gear', 18) ?> T<?= "\xC3\xAD" ?>tulo y Colores del Panel</h3>
    </div>
    <div class="card-body">
        <form method="POST">
            <?= csrfField() ?>
            <input type="hidden" name="save_branding" value="1">

            <div class="form-grid" style="display:grid;grid-template-columns:1fr 1fr;gap:16px">
                <div class="form-group">
                    <label>T<?= "\xC3\xAD" ?>tulo del Panel</label>
                    <input type="text" name="panel_title" class="form-control"
                           value="<?= htmlspecialchars($brand['panel_title'] ?? 'CastPanel') ?>"
                           placeholder="CastPanel">
                    <small style="color:var(--text-secondary)">Nombre que aparece en el sidebar y t<?= "\xC3\xAD" ?>tulo del navegador</small>
                </div>

                <div class="form-group">
                    <label>Subt<?= "\xC3\xAD" ?>tulo</label>
                    <input type="text" name="panel_subtitle" class="form-control"
                           value="<?= htmlspecialchars($brand['panel_subtitle'] ?? '') ?>"
                           placeholder="Audio Streaming Platform">
                    <small style="color:var(--text-secondary)">Texto bajo el logo en el sidebar</small>
                </div>

                <div class="form-group">
                    <label>T<?= "\xC3\xAD" ?>tulo de Login</label>
                    <input type="text" name="login_title" class="form-control"
                           value="<?= htmlspecialchars($brand['login_title'] ?? "Iniciar Sesi\xC3\xB3n") ?>"
                           placeholder="Iniciar Sesi<?= "\xC3\xB3" ?>n">
                    <small style="color:var(--text-secondary)">Texto del bot<?= "\xC3\xB3" ?>n o encabezado en la p<?= "\xC3\xA1" ?>gina de login</small>
                </div>

                <div class="form-group">
                    <label>Texto del Footer</label>
                    <input type="text" name="footer_text" class="form-control"
                           value="<?= htmlspecialchars($brand['footer_text'] ?? '') ?>"
                           placeholder="<?= "\xC2\xA9" ?> 2024 Mi Empresa">
                    <small style="color:var(--text-secondary)">Pie de p<?= "\xC3\xA1" ?>gina personalizado</small>
                </div>

                <div class="form-group">
                    <label>Color Primario</label>
                    <div style="display:flex;gap:8px;align-items:center">
                        <input type="color" name="primary_color"
                               value="<?= htmlspecialchars($brand['primary_color'] ?? '#6c3ce0') ?>"
                               style="width:48px;height:38px;border:1px solid var(--border);border-radius:6px;cursor:pointer;padding:2px">
                        <input type="text" class="form-control" style="flex:1"
                               value="<?= htmlspecialchars($brand['primary_color'] ?? '#6c3ce0') ?>"
                               placeholder="#6c3ce0" readonly>
                    </div>
                    <small style="color:var(--text-secondary)">Color principal de botones y acentos</small>
                </div>

                <div class="form-group">
                    <label>Color del Sidebar</label>
                    <div style="display:flex;gap:8px;align-items:center">
                        <input type="color" name="sidebar_color"
                               value="<?= htmlspecialchars($brand['sidebar_color'] ?? '#1e1b2e') ?>"
                               style="width:48px;height:38px;border:1px solid var(--border);border-radius:6px;cursor:pointer;padding:2px">
                        <input type="text" class="form-control" style="flex:1"
                               value="<?= htmlspecialchars($brand['sidebar_color'] ?? '#1e1b2e') ?>"
                               placeholder="#1e1b2e" readonly>
                    </div>
                    <small style="color:var(--text-secondary)">Color de fondo de la barra lateral</small>
                </div>
            </div>

            <div style="margin-top:16px">
                <button type="submit" class="btn btn-primary"><?= cpIcon('save', 16) ?> Guardar Configuraci<?= "\xC3\xB3" ?>n</button>
            </div>
        </form>
    </div>
</div>

<!-- SECCION: LOGOS - CADA LOGO CON SU PROPIO FORM (FIX: sin formularios anidados) -->
<div class="card">
    <div class="card-header">
        <h3 class="card-title"><?= cpIcon('image', 18) ?> Logos y Favicon</h3>
    </div>
    <div class="card-body">
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
                    <!-- Form independiente para eliminar (NO anidado) -->
                    <form method="POST" style="display:inline;margin-bottom:8px">
                        <?= csrfField() ?>
                        <input type="hidden" name="delete_logo" value="<?= $field ?>">
                        <button type="submit" class="btn btn-sm"
                                style="background:#ef4444;color:#fff;border:none;font-size:12px"
                                onclick="return confirm('<?= "\xC2\xBF" ?>Eliminar este logo? Se restaurar<?= "\xC3\xA1" ?> el valor por defecto.')">
                            <?= cpIcon('trash', 14) ?> Eliminar
                        </button>
                    </form>
                <?php else: ?>
                    <div style="margin-bottom:12px;padding:20px;background:rgba(108,60,224,.05);border-radius:8px;text-align:center;border:2px dashed rgba(108,60,224,.2)">
                        <?= cpIcon('image', 24) ?>
                        <p style="font-size:12px;color:var(--text-secondary);margin-top:4px">Sin logo personalizado</p>
                    </div>
                <?php endif; ?>

                <!-- Form independiente para subir (NO anidado) -->
                <form method="POST" enctype="multipart/form-data" style="margin-top:8px">
                    <?= csrfField() ?>
                    <input type="file" name="<?= $field ?>" accept="image/*,.ico"
                           style="font-size:12px;width:100%"
                           onchange="this.form.submit()">
                </form>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
</div>

<!-- PREVISUALIZACION -->
<div class="card" style="margin-top:24px">
    <div class="card-header">
        <h3 class="card-title"><?= cpIcon('eye', 18) ?> Previsualizaci<?= "\xC3\xB3" ?>n</h3>
    </div>
    <div class="card-body">
        <div style="display:grid;grid-template-columns:240px 1fr;gap:24px;min-height:200px">
            <!-- Mini sidebar preview -->
            <div style="background:<?= htmlspecialchars($brand['sidebar_color'] ?? '#1e1b2e') ?>;border-radius:12px;padding:20px;color:#fff">
                <div style="display:flex;align-items:center;gap:10px;margin-bottom:16px">
                    <?php if (!empty($brand['logo_sidebar'])): ?>
                        <img src="<?= htmlspecialchars($brand['logo_sidebar']) ?>" alt="Logo"
                             style="max-height:32px;max-width:120px;object-fit:contain">
                    <?php else: ?>
                        <div style="width:36px;height:36px;background:<?= htmlspecialchars($brand['primary_color'] ?? '#6c3ce0') ?>;border-radius:10px;display:flex;align-items:center;justify-content:center;font-weight:800;font-size:16px">
                            <?= strtoupper(substr($brand['panel_title'] ?? 'C', 0, 1)) ?>
                        </div>
                    <?php endif; ?>
                    <div>
                        <div style="font-weight:700;font-size:14px"><?= htmlspecialchars($brand['panel_title'] ?? 'CastPanel') ?></div>
                        <div style="font-size:10px;opacity:.6"><?= htmlspecialchars($brand['panel_subtitle'] ?? 'Audio Streaming') ?></div>
                    </div>
                </div>
                <div style="font-size:12px;opacity:.5">
                    <div style="padding:6px 0;border-bottom:1px solid rgba(255,255,255,.06)"><?= cpIcon('home', 14) ?> Dashboard</div>
                    <div style="padding:6px 0;border-bottom:1px solid rgba(255,255,255,.06)"><?= cpIcon('radio', 14) ?> Estaciones</div>
                    <div style="padding:6px 0"><?= cpIcon('user', 14) ?> Usuarios</div>
                </div>
            </div>

            <!-- Mini login preview -->
            <div style="background:linear-gradient(135deg, <?= htmlspecialchars($brand['sidebar_color'] ?? '#1e1b2e') ?> 0%, <?= htmlspecialchars($brand['primary_color'] ?? '#6c3ce0') ?> 100%);border-radius:12px;display:flex;align-items:center;justify-content:center;padding:24px">
                <div style="background:var(--card-bg);border-radius:12px;padding:24px;width:240px;text-align:center">
                    <?php if (!empty($brand['logo_login'])): ?>
                        <img src="<?= htmlspecialchars($brand['logo_login']) ?>" alt="Logo"
                             style="max-height:48px;max-width:180px;object-fit:contain;margin-bottom:8px">
                    <?php else: ?>
                        <div style="width:40px;height:40px;background:<?= htmlspecialchars($brand['primary_color'] ?? '#6c3ce0') ?>;border-radius:10px;display:inline-flex;align-items:center;justify-content:center;font-weight:800;font-size:18px;color:#fff;margin-bottom:8px">
                            <?= strtoupper(substr($brand['panel_title'] ?? 'C', 0, 1)) ?>
                        </div>
                    <?php endif; ?>
                    <h4 style="font-size:16px;margin-bottom:2px"><?= htmlspecialchars($brand['panel_title'] ?? 'CastPanel') ?></h4>
                    <p style="font-size:11px;color:var(--text-secondary)"><?= htmlspecialchars($brand['panel_subtitle'] ?? 'Audio Streaming') ?></p>
                    <div style="margin-top:12px;padding:8px;background:<?= htmlspecialchars($brand['primary_color'] ?? '#6c3ce0') ?>;color:#fff;border-radius:6px;font-size:12px;font-weight:600">
                        <?= htmlspecialchars($brand['login_title'] ?? "Iniciar Sesi\xC3\xB3n") ?>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/layout_footer.php'; ?>
