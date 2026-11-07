<?php
ob_start();
/**
 * CastPanel v2.7.2 - Mensaje Panel DJ (Cliente)
 * Permite al cliente configurar un mensaje personalizado que los DJs ven al iniciar sesion
 */
$pageTitle = 'Mensaje Panel DJ';
$pageIcon = 'message-square';
require_once __DIR__ . '/../includes/layout_header.php';

if (!$estacion) {
    echo '<div class="alert alert-warning">Sin estaci&oacute;n asignada.</div>';
    require_once __DIR__ . '/../includes/layout_footer.php';
    exit;
}

$db = getDB();
$msg = '';
$successMsg = '';
$errorMsg = '';

// Auto-migrar columna mensaje_dj si no existe
try {
    $db->query("SELECT mensaje_dj FROM estaciones LIMIT 0");
} catch (PDOException $e) {
    $db->exec("ALTER TABLE estaciones ADD COLUMN mensaje_dj TEXT DEFAULT NULL");
}

// Guardar mensaje
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save_message') {
    verifyCsrf();
    $nuevoMsg = trim($_POST['mensaje_dj'] ?? '');
    try {
        $stmt = $db->prepare("UPDATE estaciones SET mensaje_dj = ? WHERE id = ?");
        $stmt->execute([$nuevoMsg ?: null, $estacion['id']]);
        $successMsg = 'Mensaje actualizado correctamente.';
        // Refrescar
        $stmt = $db->prepare("SELECT mensaje_dj FROM estaciones WHERE id = ?");
        $stmt->execute([$estacion['id']]);
        $row = $stmt->fetch();
        $msg = $row['mensaje_dj'] ?? '';
    } catch (PDOException $e) {
        $errorMsg = 'Error al guardar: ' . $e->getMessage();
    }
} else {
    // Cargar mensaje actual
    $stmt = $db->prepare("SELECT mensaje_dj FROM estaciones WHERE id = ?");
    $stmt->execute([$estacion['id']]);
    $row = $stmt->fetch();
    $msg = $row['mensaje_dj'] ?? '';
}
?>

<div class="section-card">
    <h3 style="margin:0 0 12px;display:flex;align-items:center;gap:8px">
        <?= cpIcon('message-square', 20) ?>
        Mensaje para el Panel DJ
    </h3>

    <div style="background:var(--info-bg, #e8f4fd);border:1px solid var(--info-border, #b6d4e8);border-radius:8px;padding:14px 18px;margin-bottom:20px;font-size:14px;line-height:1.7">
        <?= cpIcon('info', 16) ?>
        Este mensaje se muestra a los DJs cuando inician sesi&oacute;n en su panel.
        Puede incluir instrucciones, horarios, reglas o cualquier informaci&oacute;n importante.
        Se admite HTML b&aacute;sico para formato.
    </div>

    <?php if ($successMsg): ?>
    <div class="alert alert-success" style="margin-bottom:16px"><?= cpIcon('check-circle', 16) ?> <?= htmlspecialchars($successMsg) ?></div>
    <?php endif; ?>
    <?php if ($errorMsg): ?>
    <div class="alert alert-danger" style="margin-bottom:16px"><?= cpIcon('alert-circle', 16) ?> <?= htmlspecialchars($errorMsg) ?></div>
    <?php endif; ?>

    <form method="POST">
        <input type="hidden" name="action" value="save_message">
        <?= csrfField() ?>

        <div style="margin-bottom:16px">
            <label for="mensaje_dj" style="display:block;font-weight:600;margin-bottom:6px">
                <?= cpIcon('edit-3', 14) ?> Contenido del mensaje:
            </label>
            <textarea id="mensaje_dj" name="mensaje_dj" rows="8"
                      class="form-control"
                      style="width:100%;font-family:monospace;font-size:14px;padding:12px;border:1px solid #ddd;border-radius:8px;resize:vertical"
                      placeholder="Escriba aqu&iacute; el mensaje que ver&aacute;n los DJs al entrar al panel..."><?= htmlspecialchars($msg) ?></textarea>
        </div>

        <div style="display:flex;gap:10px;align-items:center">
            <button type="submit" class="btn btn-primary">
                <?= cpIcon('save', 16) ?> Guardar Mensaje
            </button>
            <button type="button" class="btn btn-outline" onclick="document.getElementById('mensaje_dj').value='';this.form.submit();">
                <?= cpIcon('trash-2', 16) ?> Borrar Mensaje
            </button>
        </div>
    </form>
</div>

<?php if (trim($msg)): ?>
<div class="section-card" style="margin-top:20px">
    <h4 style="margin:0 0 12px;display:flex;align-items:center;gap:8px">
        <?= cpIcon('eye', 18) ?> Vista Previa
    </h4>
    <div style="background:#f8f9fa;border:1px solid #e9ecef;border-radius:8px;padding:16px;line-height:1.7">
        <?= nl2br(htmlspecialchars($msg)) ?>
    </div>
</div>
<?php endif; ?>

<?php require_once __DIR__ . '/../includes/layout_footer.php'; ?>
