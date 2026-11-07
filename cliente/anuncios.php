<?php
ob_start();
/**
 * CastPanel v2.0 - Gestión de Anuncios
 */
$pageTitle = 'Gestión de Anuncios';
$pageIcon = 'megaphone';
require_once __DIR__ . '/../includes/layout_header.php';

if (!$estacion) { echo '<div class="alert alert-warning">Sin estación asignada.</div>'; require_once __DIR__ . '/../includes/layout_footer.php'; exit; }

$db = getDB();
$listas = [];
$anuncios = [];
$_tableError = false;

try {
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && verifyCsrf()) {
        $action = $_POST['action'] ?? '';

        if ($action === 'create_list') {
            $nombre = trim($_POST['nombre']);
            $slug = slugify($nombre);
            if (!$nombre) { setFlash('error', 'Nombre requerido.'); header('Location: anuncios.php'); exit; }
            $check = $db->prepare("SELECT id FROM listas_anuncios WHERE estacion_id = ? AND slug = ?");
            $check->execute([$estacion['id'], $slug]);
            if ($check->fetch()) $slug .= '-' . substr(uniqid(), -4);
            $db->prepare("INSERT INTO listas_anuncios (estacion_id, nombre, slug) VALUES (?,?,?)")->execute([$estacion['id'], $nombre, $slug]);
            setFlash('success', "Lista de anuncios '$nombre' creada.");
            header('Location: anuncios.php'); exit;
        }

        if ($action === 'delete_list') {
            $id = (int)$_POST['lista_id'];
            $stmt = $db->prepare("SELECT ruta_archivo FROM anuncios WHERE lista_id = ?");
            $stmt->execute([$id]);
            foreach ($stmt->fetchAll() as $a) { @unlink($a['ruta_archivo']); }
            $db->prepare("DELETE FROM listas_anuncios WHERE id = ? AND estacion_id = ?")->execute([$id, $estacion['id']]);
            setFlash('success', 'Lista de anuncios eliminada.');
            header('Location: anuncios.php'); exit;
        }

        if ($action === 'delete_spot') {
            $id = (int)$_POST['anuncio_id'];
            $stmt = $db->prepare("SELECT * FROM anuncios WHERE id = ? AND estacion_id = ?");
            $stmt->execute([$id, $estacion['id']]);
            $anuncio = $stmt->fetch();
            if ($anuncio) {
                @unlink($anuncio['ruta_archivo']);
                $db->prepare("DELETE FROM anuncios WHERE id = ?")->execute([$id]);
                $db->prepare("UPDATE listas_anuncios SET total_anuncios = total_anuncios - 1 WHERE id = ? AND total_anuncios > 0")->execute([$anuncio['lista_id']]);
                setFlash('success', 'Anuncio eliminado.');
            }
            header('Location: anuncios.php'); exit;
        }
    }

    // Listas de anuncios
    $stmt = $db->prepare("SELECT la.*, (SELECT COUNT(*) FROM anuncios a WHERE a.lista_id = la.id) AS total_real FROM listas_anuncios la WHERE la.estacion_id = ? ORDER BY la.nombre");
    $stmt->execute([$estacion['id']]);
    $listas = $stmt->fetchAll();

    // Todos los anuncios
    $stmt = $db->prepare("SELECT a.*, la.nombre AS lista_nombre FROM anuncios a JOIN listas_anuncios la ON la.id = a.lista_id WHERE a.estacion_id = ? ORDER BY la.nombre, a.orden");
    $stmt->execute([$estacion['id']]);
    $anuncios = $stmt->fetchAll();
} catch (PDOException $e) {
    $_tableError = true;
}
?>

<?php if ($_tableError): ?>
<div class="alert alert-warning"><?= cpIcon('warning',14) ?> Las tablas de anuncios no están disponibles. Ejecute la migración SQL: <code>mysql -u root -p castpanel &lt; /var/www/castpanel/install/schema_update_v2.3.sql</code></div>
<?php endif; ?>

<div class="two-cols-equal">
    <!-- Nueva lista de anuncios -->
    <div class="card">
        <div class="card-header">
            <h3><?= cpIcon('plus',14) ?> Nueva Lista de Anuncios</h3>
        </div>
        <div class="card-body">
            <form method="POST">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="create_list">
                <div class="form-group">
                    <label>Nombre de la Lista</label>
                    <input type="text" name="nombre" class="form-control" placeholder="Ej: Spots Comerciales" required>
                </div>
                <button type="submit" class="btn btn-success btn-block">+ Crear Lista</button>
            </form>
        </div>
    </div>

    <!-- Listas existentes -->
    <div class="card">
        <div class="card-header">
            <h3><?= cpIcon('megaphone',18) ?> Listas de Anuncios (<?= count($listas) ?>)</h3>
        </div>
        <div class="card-body no-padding">
            <?php if (empty($listas)): ?>
                <div class="empty-state"><p>No hay listas de anuncios.</p></div>
            <?php else: ?>
                <?php foreach ($listas as $la): ?>
                    <div class="playlist-card">
                        <div class="playlist-info">
                            <div class="playlist-icon" style="background:var(--warning)"><?= cpIcon('megaphone',16) ?></div>
                            <div>
                                <div class="playlist-name"><?= htmlspecialchars($la['nombre']) ?></div>
                                <div class="playlist-meta"><?= $la['total_real'] ?> spot(s)</div>
                            </div>
                        </div>
                        <div style="display:flex;gap:4px">
                            <a href="programar_anuncios.php?lista=<?= $la['id'] ?>" class="btn btn-outline btn-sm"><?= cpIcon('clock',14) ?> Programar</a>
                            <form method="POST" style="display:inline" onsubmit="return confirmDelete('¿Eliminar esta lista y todos sus spots?')">
                                <?= csrfField() ?>
                                <input type="hidden" name="action" value="delete_list">
                                <input type="hidden" name="lista_id" value="<?= $la['id'] ?>">
                                <button class="btn btn-danger btn-sm"><?= cpIcon('trash',14) ?></button>
                            </form>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Subir anuncios -->
<?php if (!empty($listas)): ?>
<div class="card" style="margin-top:20px">
    <div class="card-header">
        <h3><?= cpIcon('upload',18) ?> Subir Spots / Anuncios</h3>
    </div>
    <div class="card-body">
        <form id="adUploadForm">
            <?= csrfField() ?>
            <div class="form-group">
                <label>Lista de Anuncios Destino</label>
                <select id="adListId" class="form-control" required>
                    <?php foreach ($listas as $la): ?>
                        <option value="<?= $la['id'] ?>"><?= htmlspecialchars($la['nombre']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="upload-area" id="adDropZone">
                <div class="upload-icon"><?= cpIcon('megaphone',24) ?></div>
                <div class="upload-title">Arrastra tus spots aquí</div>
                <div class="upload-hint">o haz clic para seleccionar</div>
                <div class="upload-formats">Formatos: MP3, OGG, AAC — Máx. 20 MB por archivo</div>
                <input type="file" id="adFileInput" accept=".mp3,.ogg,.aac,.m4a" multiple style="display:none">
            </div>
        </form>
        <div id="adProgress" style="display:none;margin-top:12px">
            <div class="progress-bar"><div class="progress-fill" id="adProgressFill" style="width:0"></div></div>
            <span id="adProgressText" style="font-size:12px"></span>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', () => {
    initDropZone(document.getElementById('adDropZone'), document.getElementById('adFileInput'), (files) => {
        const lista = document.getElementById('adListId').value;
        const prog = document.getElementById('adProgress');
        prog.style.display = '';
        uploadFiles(
            '<?= BASE_URL ?>/api/upload.php?estacion=<?= $estacion['id'] ?>&tipo=anuncio',
            files, 'lista_id', lista,
            (pct) => {
                document.getElementById('adProgressFill').style.width = pct + '%';
                document.getElementById('adProgressText').textContent = pct + '%';
            },
            (resp) => {
                if (resp.success) { showToast(resp.uploaded + ' spot(s) subido(s)'); setTimeout(() => location.reload(), 1000); }
                else showToast(resp.error || 'Error', 'error');
            }
        );
    });
});
</script>
<?php endif; ?>

<!-- Tabla de anuncios -->
<?php if (!empty($anuncios)): ?>
<div class="card" style="margin-top:20px">
    <div class="card-header">
        <h3><?= cpIcon('headphones',18) ?> Todos los Spots (<?= count($anuncios) ?>)</h3>
    </div>
    <div class="card-body no-padding">
        <div class="table-responsive">
            <table class="table">
                <thead>
                    <tr><th>Archivo</th><th>Lista</th><th>Duración</th><th>Tamaño</th><th>Acciones</th></tr>
                </thead>
                <tbody>
                    <?php foreach ($anuncios as $a): ?>
                        <tr>
                            <td><?= htmlspecialchars($a['nombre_original']) ?></td>
                            <td><span class="badge badge-orange"><?= htmlspecialchars($a['lista_nombre']) ?></span></td>
                            <td><?= $a['duracion_segundos'] ? formatDuration($a['duracion_segundos']) : '—' ?></td>
                            <td><?= formatBytes($a['tamano_bytes']) ?></td>
                            <td>
                                <form method="POST" style="display:inline" onsubmit="return confirmDelete('¿Eliminar este spot?')">
                                    <?= csrfField() ?>
                                    <input type="hidden" name="action" value="delete_spot">
                                    <input type="hidden" name="anuncio_id" value="<?= $a['id'] ?>">
                                    <button class="btn btn-danger btn-sm"><?= cpIcon('trash',14) ?> Eliminar</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<?php endif; ?>

<?php require_once __DIR__ . '/../includes/layout_footer.php'; ?>
