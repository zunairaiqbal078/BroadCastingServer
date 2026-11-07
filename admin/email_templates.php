<?php
/**
 * CastPanel v2.4 - Admin: Plantillas de Correo
 * Gestionar plantillas HTML para notificaciones por email
 */
$pageTitle = 'Plantillas de Correo';
$pageIcon = 'note';
$isAdmin = true;
require_once __DIR__ . '/../includes/layout_header.php';
requireRole('admin');

$db = getDB();
$msg = '';
$msgType = '';

// Check table
$tableExists = false;
try {
    $db->query("SELECT 1 FROM email_templates LIMIT 1");
    $tableExists = true;
} catch (PDOException $e) {}

// Default templates
$defaultTemplates = [
    ['slug' => 'welcome', 'nombre' => 'Bienvenida', 'asunto' => 'Bienvenido a {{panel_name}}', 'descripcion' => 'Se envía al crear una nueva cuenta'],
    ['slug' => 'station_created', 'nombre' => 'Estación Creada', 'asunto' => 'Tu estación {{station_name}} está lista', 'descripcion' => 'Se envía al provisionar una nueva estación'],
    ['slug' => 'password_reset', 'nombre' => 'Restablecer Contraseña', 'asunto' => 'Restablecer tu contraseña', 'descripcion' => 'Se envía al solicitar restablecimiento de contraseña'],
    ['slug' => 'suspension', 'nombre' => 'Cuenta Suspendida', 'asunto' => 'Tu cuenta ha sido suspendida', 'descripcion' => 'Se envía al suspender una cuenta de usuario'],
    ['slug' => 'reactivation', 'nombre' => 'Cuenta Reactivada', 'asunto' => 'Tu cuenta ha sido reactivada', 'descripcion' => 'Se envía al reactivar una cuenta de usuario'],
];

// POST
if ($_SERVER['REQUEST_METHOD'] === 'POST' && verifyCsrf() && $tableExists) {
    $action = $_POST['action'] ?? '';

    try {
        if ($action === 'save_template') {
            $id = (int)($_POST['template_id'] ?? 0);
            $asunto = trim($_POST['asunto'] ?? '');
            $cuerpo = $_POST['cuerpo'] ?? '';

            if ($id > 0) {
                $db->prepare("UPDATE email_templates SET asunto = ?, cuerpo = ?, updated_at = NOW() WHERE id = ?")->execute([$asunto, $cuerpo, $id]);
                $msg = 'Plantilla actualizada exitosamente';
                $msgType = 'success';
            }
        } elseif ($action === 'init_defaults') {
            foreach ($defaultTemplates as $dt) {
                $check = $db->prepare("SELECT id FROM email_templates WHERE slug = ?");
                $check->execute([$dt['slug']]);
                if (!$check->fetch()) {
                    $defaultBody = "<h2>{{panel_name}}</h2>\n<p>Estimado/a {{username}},</p>\n<p>" . htmlspecialchars($dt['descripcion']) . "</p>\n<p>Atentamente,<br>El equipo de {{panel_name}}</p>";
                    $db->prepare("INSERT INTO email_templates (slug, nombre, asunto, cuerpo, descripcion, created_at) VALUES (?,?,?,?,?,NOW())")
                       ->execute([$dt['slug'], $dt['nombre'], $dt['asunto'], $defaultBody, $dt['descripcion']]);
                }
            }
            $msg = 'Plantillas predeterminadas inicializadas';
            $msgType = 'success';
        }
    } catch (PDOException $e) {
        $msg = 'Error: ' . $e->getMessage();
        $msgType = 'error';
    }
}

// Load templates
$templates = [];
if ($tableExists) {
    try {
        $templates = $db->query("SELECT * FROM email_templates ORDER BY nombre ASC")->fetchAll();
    } catch (PDOException $e) {}
}

// Edit mode
$editTemplate = null;
if (isset($_GET['edit']) && $tableExists) {
    $editId = (int)$_GET['edit'];
    $stEdit = $db->prepare("SELECT * FROM email_templates WHERE id = ?");
    $stEdit->execute([$editId]);
    $editTemplate = $stEdit->fetch();
}
?>

<?php if ($msg): ?>
<div class="alert alert-<?= $msgType === 'error' ? 'danger' : 'success' ?>">
    <?= htmlspecialchars($msg) ?>
</div>
<?php endif; ?>

<?php if (!$tableExists): ?>
<div class="alert alert-warning">
    <?= cpIcon('alert', 16) ?> La tabla <code>email_templates</code> no existe. Ejecute la migración SQL.
</div>
<?php endif; ?>

<?php if ($editTemplate): ?>
<!-- Edit Template -->
<div class="card" style="margin-bottom:1.5rem;">
    <div class="card-header">
        <h3><?= cpIcon('edit', 18) ?> Editar: <?= htmlspecialchars($editTemplate['nombre']) ?></h3>
    </div>
    <div class="card-body">
        <form method="post">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="save_template">
            <input type="hidden" name="template_id" value="<?= $editTemplate['id'] ?>">

            <div class="form-group">
                <label>Asunto del Correo</label>
                <input type="text" name="asunto" class="form-control" value="<?= htmlspecialchars($editTemplate['asunto']) ?>">
                <small style="color:#666;">Variables: {{panel_name}}, {{username}}, {{email}}, {{station_name}}, {{domain}}</small>
            </div>
            <div class="form-group">
                <label>Cuerpo del Correo (HTML)</label>
                <textarea name="cuerpo" class="form-control" rows="15" style="font-family:monospace;font-size:.9rem;"><?= htmlspecialchars($editTemplate['cuerpo']) ?></textarea>
                <small style="color:#666;">Puede usar HTML y las variables mencionadas arriba</small>
            </div>
            <div style="margin-top:1rem;">
                <button type="submit" class="btn btn-primary"><?= cpIcon('save', 14) ?> Guardar Plantilla</button>
                <a href="<?= BASE_URL ?>/admin/email_templates.php" class="btn btn-secondary">Cancelar</a>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

<!-- Templates List -->
<div class="card" style="margin-bottom:1.5rem;">
    <div class="card-header" style="display:flex;justify-content:space-between;align-items:center;">
        <h3><?= cpIcon('note', 18) ?> Plantillas de Correo (<?= count($templates) ?>)</h3>
        <?php if ($tableExists && empty($templates)): ?>
        <form method="post" style="display:inline;">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="init_defaults">
            <button type="submit" class="btn btn-sm btn-primary"><?= cpIcon('plus', 14) ?> Inicializar Plantillas</button>
        </form>
        <?php endif; ?>
    </div>
    <div class="card-body" style="padding:0;">
        <table class="table" style="margin:0;">
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Slug</th>
                    <th>Nombre</th>
                    <th>Asunto</th>
                    <th>Descripción</th>
                    <th>Última Edición</th>
                    <th>Acciones</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($templates)): ?>
                <tr><td colspan="7" style="text-align:center;padding:2rem;color:#999;">No hay plantillas. Use el botón "Inicializar Plantillas" para crear las predeterminadas.</td></tr>
                <?php else: ?>
                <?php foreach ($templates as $t): ?>
                <tr>
                    <td><?= $t['id'] ?></td>
                    <td><code><?= htmlspecialchars($t['slug']) ?></code></td>
                    <td><strong><?= htmlspecialchars($t['nombre']) ?></strong></td>
                    <td><?= htmlspecialchars($t['asunto']) ?></td>
                    <td style="font-size:.85rem;color:#666;"><?= htmlspecialchars($t['descripcion'] ?? '') ?></td>
                    <td><?= $t['updated_at'] ? date('d/m/Y H:i', strtotime($t['updated_at'])) : '-' ?></td>
                    <td>
                        <a href="?edit=<?= $t['id'] ?>" class="btn btn-sm btn-primary"><?= cpIcon('edit', 14) ?></a>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Variables Reference -->
<div class="card">
    <div class="card-body">
        <h4><?= cpIcon('info', 16) ?> Variables Disponibles</h4>
        <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(250px,1fr));gap:.5rem;margin-top:.5rem;">
            <div><code>{{panel_name}}</code> — Nombre del panel</div>
            <div><code>{{username}}</code> — Nombre de usuario</div>
            <div><code>{{email}}</code> — Email del usuario</div>
            <div><code>{{station_name}}</code> — Nombre de la estación</div>
            <div><code>{{domain}}</code> — Dominio del panel</div>
            <div><code>{{password}}</code> — Contraseña (solo en bienvenida)</div>
            <div><code>{{stream_url}}</code> — URL del stream</div>
            <div><code>{{reset_link}}</code> — Enlace de restablecimiento</div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/layout_footer.php'; ?>
