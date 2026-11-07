<?php
/**
 * CastPanel v2.5.1 - Admin: Gestion de Paquetes de Radio
 * Diseno estilo SonicPanel - Agregar Paquete de Radio
 */
$_isNewAction = ($_GET['action'] ?? '') === 'new' && !isset($_GET['edit']);
$pageTitle = $_isNewAction ? 'Agregar Paquete de Radio' : 'Editar/Listar Paquetes';
$pageIcon = 'package';
$isAdmin = true;
require_once __DIR__ . '/../includes/layout_header.php';
requireRole('admin');

$db = getDB();
$msg = '';
$msgType = '';
$_tableError = false;

// ====== Auto-migracion de columnas ======
$migrationCols = [
    'server_type'           => "ENUM('shoutcast_v2','shoutcast_v25','shoutcast_v1','icecast') NOT NULL DEFAULT 'shoutcast_v2' AFTER `nombre`",
    'allow_system_change'   => "TINYINT(1) NOT NULL DEFAULT 1 AFTER `server_type`",
    'default_hostname'      => "VARCHAR(255) NOT NULL DEFAULT '' AFTER `allow_system_change`",
    'social_stream_enabled' => "TINYINT(1) NOT NULL DEFAULT 0 AFTER `default_hostname`",
    'mount_points_enabled'  => "TINYINT(1) NOT NULL DEFAULT 1 AFTER `social_stream_enabled`",
];

foreach ($migrationCols as $colName => $colDef) {
    try {
        $chk = $db->query("SHOW COLUMNS FROM paquetes LIKE '$colName'")->fetchAll();
        if (count($chk) === 0) {
            $db->exec("ALTER TABLE paquetes ADD COLUMN `$colName` $colDef");
        }
    } catch (PDOException $e) {
        // columna ya existe o error de migracion
    }
}

// ====== POST: crear/editar/eliminar ======
if ($_SERVER['REQUEST_METHOD'] === 'POST' && verifyCsrf()) {
    $action = $_POST['action'] ?? '';

    try {
        if ($action === 'create' || $action === 'update') {
            $nombre             = trim($_POST['nombre'] ?? '');
            $serverType         = $_POST['server_type'] ?? 'shoutcast_v2';
            $allowChange        = (int)($_POST['allow_system_change'] ?? 1);
            $defaultHostname    = trim($_POST['default_hostname'] ?? '');
            $socialStream       = (int)($_POST['social_stream_enabled'] ?? 0);
            $mountPoints        = (int)($_POST['mount_points_enabled'] ?? 1);
            $maxStorageMb       = (int)($_POST['max_storage_mb'] ?? 5500);
            $maxBitrate         = (int)($_POST['max_bitrate'] ?? 128);
            $maxOyentes         = (int)($_POST['max_oyentes'] ?? 999);
            $maxDj              = (int)($_POST['max_dj'] ?? 3);
            $autodj             = (int)($_POST['autodj_enabled'] ?? 1);
            $anuncios           = isset($_POST['anuncios_enabled']) ? 1 : 0;
            $ssl                = isset($_POST['ssl_enabled']) ? 1 : 0;
            $activo             = isset($_POST['activo']) ? 1 : 0;
            $precio             = (float)($_POST['precio'] ?? 0);
            $descripcion        = trim($_POST['descripcion'] ?? '');

            if (!$nombre) {
                $msg = 'El nombre es obligatorio';
                $msgType = 'error';
            } elseif ($action === 'create') {
                $stmt = $db->prepare("INSERT INTO paquetes
                    (nombre, server_type, allow_system_change, default_hostname, social_stream_enabled, mount_points_enabled,
                     max_storage_mb, max_bitrate, max_oyentes, max_dj, autodj_enabled, anuncios_enabled, ssl_enabled, activo, precio, descripcion)
                    VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
                $stmt->execute([
                    $nombre, $serverType, $allowChange, $defaultHostname, $socialStream, $mountPoints,
                    $maxStorageMb, $maxBitrate, $maxOyentes, $maxDj, $autodj, $anuncios, $ssl, $activo, $precio, $descripcion
                ]);
                $msg = "Paquete '$nombre' creado exitosamente";
                $msgType = 'success';
            } else {
                $id = (int)$_POST['id'];
                $stmt = $db->prepare("UPDATE paquetes SET
                    nombre=?, server_type=?, allow_system_change=?, default_hostname=?, social_stream_enabled=?, mount_points_enabled=?,
                    max_storage_mb=?, max_bitrate=?, max_oyentes=?, max_dj=?, autodj_enabled=?, anuncios_enabled=?, ssl_enabled=?, activo=?, precio=?, descripcion=?
                    WHERE id=?");
                $stmt->execute([
                    $nombre, $serverType, $allowChange, $defaultHostname, $socialStream, $mountPoints,
                    $maxStorageMb, $maxBitrate, $maxOyentes, $maxDj, $autodj, $anuncios, $ssl, $activo, $precio, $descripcion, $id
                ]);
                $msg = 'Paquete actualizado';
                $msgType = 'success';
            }
        } elseif ($action === 'delete') {
            $id = (int)$_POST['id'];
            $check = $db->prepare("SELECT COUNT(*) FROM estaciones WHERE paquete_id = ?");
            $check->execute([$id]);
            if ((int)$check->fetchColumn() > 0) {
                $msg = 'No se puede eliminar: hay estaciones usando este paquete';
                $msgType = 'error';
            } else {
                $db->prepare("DELETE FROM paquetes WHERE id = ?")->execute([$id]);
                $msg = 'Paquete eliminado';
                $msgType = 'success';
            }
        }
    } catch (PDOException $e) {
        $msg = 'Error en la operacion: ' . $e->getMessage();
        $msgType = 'error';
    }
}

$paquetes = [];
try {
    $paquetes = $db->query("SELECT p.*, (SELECT COUNT(*) FROM estaciones WHERE paquete_id = p.id) AS total_estaciones FROM paquetes p ORDER BY p.nombre ASC")->fetchAll();
} catch (PDOException $e) { $_tableError = true; }

$editPkg = null;
if (isset($_GET['edit'])) {
    try {
        $stmt = $db->prepare("SELECT * FROM paquetes WHERE id = ?");
        $stmt->execute([(int)$_GET['edit']]);
        $editPkg = $stmt->fetch();
    } catch (PDOException $e) {}
}

// IP del servidor
$serverIp = $_SERVER['SERVER_ADDR'] ?? '0.0.0.0';
if ($serverIp === '0.0.0.0' || $serverIp === '::1') {
    $serverIp = gethostbyname(gethostname());
}
?>

<?php if ($_tableError): ?>
<div class="alert alert-warning"><?= cpIcon('warning', 16) ?> La tabla <strong>paquetes</strong> tiene un problema. Verifique la migracion SQL.</div>
<?php endif; ?>

<?php if ($msg): ?>
    <div class="alert alert-<?= $msgType ?>"><?= $msg ?></div>
<?php endif; ?>

<?php $showForm = $editPkg || ($_GET['action'] ?? '') === 'new'; ?>
<?php if ($showForm): ?>
<!-- Formulario Crear/Editar Paquete -->
<div class="card">
    <div class="card-header">
        <h3><?= $editPkg ? cpIcon('pencil', 18) . ' Editar Paquete de Radio' : cpIcon('plus', 18) . ' Agregar Paquete de Radio' ?></h3>
    </div>
    <div class="card-body">
        <!-- Info box estilo SonicPanel -->
        <div style="background:#e8f4fd;border:1px solid #bee5f7;border-radius:8px;padding:14px 18px;margin-bottom:22px;color:#1a6fa5;font-size:13px">
            <strong><?= cpIcon('info', 14) ?> </strong>
            Los paquetes son los limites de cuentas y radios. Puede usar sus paquetes para crear radios y cuentas facilmente.
            <ul style="margin:8px 0 0 18px;padding:0"><li>Limite 0 significa ilimitado.</li></ul>
        </div>

        <form method="POST">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="<?= $editPkg ? 'update' : 'create' ?>">
            <?php if ($editPkg): ?>
                <input type="hidden" name="id" value="<?= $editPkg['id'] ?>">
            <?php endif; ?>

            <!-- Fila 1: Nombre + Sistema de Radio -->
            <div class="form-grid-2">
                <div class="form-group">
                    <label>Ingrese un Nombre de Paquete: *</label>
                    <input type="text" name="nombre" class="form-control" required placeholder="Ingrese un Nombre" value="<?= htmlspecialchars($editPkg['nombre'] ?? '') ?>">
                </div>
                <div class="form-group">
                    <label>Sistema de Radio:</label>
                    <select name="server_type" class="form-control">
                        <option value="shoutcast_v2" <?= ($editPkg['server_type'] ?? 'shoutcast_v2') === 'shoutcast_v2' ? 'selected' : '' ?>>ShoutCast v2.6</option>
                        <option value="shoutcast_v25" <?= ($editPkg['server_type'] ?? '') === 'shoutcast_v25' ? 'selected' : '' ?>>ShoutCast v2.5</option>
                        <option value="shoutcast_v1" <?= ($editPkg['server_type'] ?? '') === 'shoutcast_v1' ? 'selected' : '' ?>>ShoutCast v1</option>
                        <option value="icecast" <?= ($editPkg['server_type'] ?? '') === 'icecast' ? 'selected' : '' ?>>IceCast v2</option>
                    </select>
                </div>
            </div>

            <!-- Fila 2: Limite de Oyentes + Bitrate -->
            <div class="form-grid-2">
                <div class="form-group">
                    <label>Limite de Oyentes:</label>
                    <input type="number" name="max_oyentes" class="form-control" min="0" value="<?= $editPkg['max_oyentes'] ?? 999 ?>">
                    <small style="color:var(--text-secondary)">0 = ilimitado</small>
                </div>
                <div class="form-group">
                    <label>Limite Maximo de Tasa de Bits:</label>
                    <select name="max_bitrate" class="form-control">
                        <?php foreach ([64,96,128,192,256,320] as $br): ?>
                            <option value="<?= $br ?>" <?= ($editPkg['max_bitrate'] ?? 128) == $br ? 'selected' : '' ?>><?= $br ?> KBPS</option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <!-- Fila 3: IP + Permitir Cambio de Sistema -->
            <div class="form-grid-2">
                <div class="form-group">
                    <label>IP de Radio: <span style="color:#10b981;font-weight:600">Automatico</span> <span title="La IP de radio se asigna automaticamente del servidor" style="cursor:help;color:#3b82f6;font-weight:bold">&#9432;</span></label>
                    <input type="text" class="form-control" value="<?= htmlspecialchars($serverIp) ?>" readonly style="background:#f0f0f0;color:#666">
                </div>
                <div class="form-group">
                    <label>Permitir Cambio de Sistema de Radio: <span title="Permite al reseller/cliente cambiar el tipo de sistema de radio" style="cursor:help;color:#3b82f6;font-weight:bold">&#9432;</span></label>
                    <select name="allow_system_change" class="form-control">
                        <option value="1" <?= ($editPkg['allow_system_change'] ?? 1) == 1 ? 'selected' : '' ?>>Permitido</option>
                        <option value="0" <?= ($editPkg['allow_system_change'] ?? 1) == 0 ? 'selected' : '' ?>>No Permitido</option>
                    </select>
                </div>
            </div>

            <!-- Fila 4: Domain/Hostname + Social Stream -->
            <div class="form-grid-2">
                <div class="form-group">
                    <label>Domain: <span style="color:var(--text-secondary);font-weight:400">Default Hostname</span> <span title="Hostname por defecto asignado a las estaciones de este paquete" style="cursor:help;color:#3b82f6;font-weight:bold">&#9432;</span></label>
                    <input type="text" name="default_hostname" class="form-control" placeholder="ej: radio.midominio.com" value="<?= htmlspecialchars($editPkg['default_hostname'] ?? '') ?>">
                </div>
                <div class="form-group">
                    <label>Social Stream: <span title="Permite a las estaciones usar Social Stream (transmision a redes sociales)" style="cursor:help;color:#3b82f6;font-weight:bold">&#9432;</span></label>
                    <select name="social_stream_enabled" class="form-control">
                        <option value="1" <?= ($editPkg['social_stream_enabled'] ?? 0) == 1 ? 'selected' : '' ?>>Permitido</option>
                        <option value="0" <?= ($editPkg['social_stream_enabled'] ?? 0) == 0 ? 'selected' : '' ?>>No Permitido</option>
                    </select>
                </div>
            </div>

            <!-- Fila 5: AutoDJ (select) + Mount Points -->
            <div class="form-grid-2">
                <div class="form-group">
                    <label>Permitir Creacion de AutoDJ: <span title="Permite a las estaciones crear y usar AutoDJ para reproduccion automatica" style="cursor:help;color:#3b82f6;font-weight:bold">&#9432;</span></label>
                    <select name="autodj_enabled" class="form-control">
                        <option value="1" <?= ($editPkg['autodj_enabled'] ?? 1) == 1 ? 'selected' : '' ?>>Permitido</option>
                        <option value="0" <?= ($editPkg['autodj_enabled'] ?? 1) == 0 ? 'selected' : '' ?>>No Permitido</option>
                    </select>
                </div>
                <div class="form-group">
                    <label>Mount Points: <span title="Permite a las estaciones crear mount points adicionales" style="cursor:help;color:#3b82f6;font-weight:bold">&#9432;</span></label>
                    <select name="mount_points_enabled" class="form-control">
                        <option value="1" <?= ($editPkg['mount_points_enabled'] ?? 1) == 1 ? 'selected' : '' ?>>Permitido</option>
                        <option value="0" <?= ($editPkg['mount_points_enabled'] ?? 1) == 0 ? 'selected' : '' ?>>No Permitido</option>
                    </select>
                </div>
            </div>

            <!-- Fila 6: Almacenamiento + Max DJs -->
            <div class="form-grid-2">
                <div class="form-group" id="storage-group">
                    <label>Almacenamiento (MB):</label>
                    <input type="number" name="max_storage_mb" class="form-control" min="0" value="<?= $editPkg['max_storage_mb'] ?? 5500 ?>">
                </div>
                <div id="maxdj-group" class="form-group">
                    <label>Max. Cuentas DJ:</label>
                    <input type="number" name="max_dj" class="form-control" min="1" value="<?= $editPkg['max_dj'] ?? 3 ?>">
                </div>
            </div>

            <!-- Fila 7: Descripcion -->
            <input type="hidden" name="precio" value="0">
            <div class="form-group">
                <label>Descripcion:</label>
                <input type="text" name="descripcion" class="form-control" placeholder="Descripcion del paquete" value="<?= htmlspecialchars($editPkg['descripcion'] ?? '') ?>">
            </div>

            <!-- Fila 8: Opciones adicionales -->
            <div style="background:#f8f9fa;border:1px solid var(--border);border-radius:8px;padding:16px 20px;margin:16px 0">
                <label style="font-weight:600;font-size:13px;color:var(--text-primary);display:block;margin-bottom:12px">Opciones Adicionales:</label>
                <div style="display:flex;gap:28px;flex-wrap:wrap">
                    <label style="display:flex;align-items:center;gap:6px;cursor:pointer;font-size:13px">
                        <input type="checkbox" name="anuncios_enabled" <?= ($editPkg['anuncios_enabled'] ?? 1) ? 'checked' : '' ?> style="width:16px;height:16px"> Anuncios Habilitados
                    </label>
                    <label style="display:flex;align-items:center;gap:6px;cursor:pointer;font-size:13px">
                        <input type="checkbox" name="ssl_enabled" <?= ($editPkg['ssl_enabled'] ?? 1) ? 'checked' : '' ?> style="width:16px;height:16px"> SSL / HTTPS
                    </label>
                    <label style="display:flex;align-items:center;gap:6px;cursor:pointer;font-size:13px">
                        <input type="checkbox" name="activo" <?= ($editPkg['activo'] ?? 1) ? 'checked' : '' ?> style="width:16px;height:16px"> Paquete Activo
                    </label>
                </div>
            </div>

            <div style="display:flex;gap:10px;margin-top:8px">
                <button type="submit" class="btn btn-primary"><?= $editPkg ? 'Guardar Cambios' : 'Crear Paquete' ?></button>
                <?php if ($editPkg): ?>
                    <a href="<?= BASE_URL ?>/admin/paquetes.php" class="btn btn-outline">Cancelar</a>
                <?php endif; ?>
            </div>
        </form>
    </div>
</div>

<?php endif; /* $showForm */ ?>

<?php if (!$showForm || $editPkg): ?>
<!-- Lista de paquetes -->
<div class="card">
    <div class="card-header">
        <h3><?= cpIcon('package', 18) ?> Editar/Listar Paquetes (<?= count($paquetes) ?>)</h3>
        <input type="text" placeholder="Buscar paquete..." class="form-control" style="width:200px" oninput="searchTable(this.value,'packages-table')">
    </div>
    <div class="card-body no-padding">
        <div class="table-responsive">
            <table class="table" id="packages-table">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Nombre</th>
                        <th>Sistema</th>
                        <th>Hostname</th>
                        <th>Almac.</th>
                        <th>Bitrate</th>
                        <th>Oyentes</th>
                        <th>DJs</th>
                        <th>Permisos</th>
                        <th>Estaciones</th>
                        <th>Estado</th>
                        <th>Acciones</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($paquetes as $p): ?>
                        <tr>
                            <td><?= $p['id'] ?></td>
                            <td><strong><?= htmlspecialchars($p['nombre']) ?></strong></td>
                            <td>
                                <?php
                                    $sType = $p['server_type'] ?? 'shoutcast_v2';
                                    $sLabels = ['shoutcast_v2' => 'SC v2.6', 'shoutcast_v25' => 'SC v2.5', 'shoutcast_v1' => 'SC v1', 'icecast' => 'IceCast v2'];
                                    $sBadge = $sType === 'icecast' ? 'info' : 'warning';
                                ?>
                                <span class="badge badge-<?= $sBadge ?>" style="font-size:10px"><?= $sLabels[$sType] ?? $sType ?></span>
                            </td>
                            <td style="font-size:11px"><?= htmlspecialchars($p['default_hostname'] ?? '') ?: '<span style="color:#999">—</span>' ?></td>
                            <td><?= formatMB($p['max_storage_mb']) ?></td>
                            <td><?= $p['max_bitrate'] ?> kbps</td>
                            <td><?= $p['max_oyentes'] ?: '&infin;' ?></td>
                            <td><?= $p['max_dj'] ?></td>
                            <td style="font-size:11px">
                                <?= ($p['autodj_enabled'] ?? 0) ? '<span class="badge badge-success">AutoDJ</span>' : '' ?>
                                <?= ($p['anuncios_enabled'] ?? 0) ? '<span class="badge badge-info">Ads</span>' : '' ?>
                                <?= ($p['ssl_enabled'] ?? 0) ? '<span class="badge badge-warning">SSL</span>' : '' ?>
                                <?= ($p['social_stream_enabled'] ?? 0) ? '<span class="badge badge-primary">Social</span>' : '' ?>
                                <?= ($p['mount_points_enabled'] ?? 0) ? '<span class="badge badge-secondary">Mounts</span>' : '' ?>
                            </td>
                            <td><?= $p['total_estaciones'] ?></td>
                            <td>
                                <?= $p['activo']
                                    ? '<span class="badge badge-success">Activo</span>'
                                    : '<span class="badge badge-danger">Inactivo</span>' ?>
                            </td>
                            <td>
                                <div style="display:flex;gap:5px">
                                    <a href="?edit=<?= $p['id'] ?>" class="btn btn-sm btn-outline"><?= cpIcon('pencil', 14) ?></a>
                                    <?php if ($p['total_estaciones'] == 0): ?>
                                        <form method="POST" style="display:inline" onsubmit="return confirmDelete('este paquete')">
                                            <?= csrfField() ?>
                                            <input type="hidden" name="action" value="delete">
                                            <input type="hidden" name="id" value="<?= $p['id'] ?>">
                                            <button type="submit" class="btn btn-sm btn-danger"><?= cpIcon('trash', 14) ?></button>
                                        </form>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php endif; /* !$showForm || $editPkg */ ?>

<script>
document.addEventListener('DOMContentLoaded', function(){
    var sel = document.querySelector('select[name="autodj_enabled"]');
    var storageGrp = document.getElementById('storage-group');
    var maxdjGrp = document.getElementById('maxdj-group');
    if(!sel) return;
    function toggle(){
        var off = sel.value==='0';
        if(storageGrp){
            storageGrp.style.opacity = off?'.5':'1';
            var inp1 = storageGrp.querySelector('input');
            if(inp1){ if(off){inp1.value='0';inp1.readOnly=true;}else{inp1.readOnly=false;} }
        }
        if(maxdjGrp){
            maxdjGrp.style.opacity = off?'.5':'1';
            var inp2 = maxdjGrp.querySelector('input');
            if(inp2){ if(off){inp2.value='0';inp2.readOnly=true;}else{inp2.readOnly=false;} }
        }
    }
    sel.addEventListener('change',toggle);
    toggle();
});
</script>

<?php require_once __DIR__ . '/../includes/layout_footer.php'; ?>
