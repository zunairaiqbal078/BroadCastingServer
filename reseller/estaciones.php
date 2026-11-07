<?php
/**
 * CastPanel v2.0 - Reseller: Gestión de Estaciones
 */
$pageTitle = 'Estaciones de Clientes';
$pageIcon = 'radio';
$isReseller = true;
require_once __DIR__ . '/../includes/layout_header.php';
requireRole('reseller');

$db = getDB();
$resellerId = $_SESSION['user_id'];
$msg = '';
$msgType = '';

// Filtro por cliente específico
$filtroCliente = isset($_GET['cliente']) ? (int)$_GET['cliente'] : 0;

// Obtener clientes del reseller (para selector)
$stmt = $db->prepare("SELECT id, username, nombre FROM usuarios WHERE reseller_id = ? AND rol = 'cliente' ORDER BY username");
$stmt->execute([$resellerId]);
$misClientes = $stmt->fetchAll();

// Verificar que el filtro de cliente pertenezca a este reseller
if ($filtroCliente) {
    $valid = false;
    foreach ($misClientes as $c) {
        if ($c['id'] == $filtroCliente) { $valid = true; break; }
    }
    if (!$valid) $filtroCliente = 0;
}

// Paquetes disponibles
$paquetes = $db->query("SELECT * FROM paquetes WHERE activo = 1 ORDER BY precio ASC")->fetchAll();

// Límites del reseller
$stmt = $db->prepare("SELECT max_estaciones FROM usuarios WHERE id = ?");
$stmt->execute([$resellerId]);
$maxEstaciones = (int)$stmt->fetchColumn();

// Contar estaciones actuales
$stmt = $db->prepare("
    SELECT COUNT(*) FROM estaciones e
    JOIN usuarios u ON u.id = e.usuario_id
    WHERE u.reseller_id = ?
");
$stmt->execute([$resellerId]);
$estacionesActuales = (int)$stmt->fetchColumn();

// Procesar acciones POST
if ($_SERVER['REQUEST_METHOD'] === 'POST' && verifyCsrf()) {
    $action = $_POST['action'] ?? '';

    if ($action === 'create') {
        $clienteId = (int)$_POST['usuario_id'];

        // Verificar que el cliente pertenezca a este reseller
        $check = $db->prepare("SELECT id FROM usuarios WHERE id = ? AND reseller_id = ? AND rol = 'cliente'");
        $check->execute([$clienteId, $resellerId]);
        if (!$check->fetch()) {
            $msg = 'Cliente no válido';
            $msgType = 'error';
        } elseif ($maxEstaciones > 0 && $estacionesActuales >= $maxEstaciones) {
            $msg = "Has alcanzado el límite de estaciones ($maxEstaciones). Contacta al administrador.";
            $msgType = 'error';
        } else {
            $nombre = trim($_POST['nombre'] ?? '');
            $slug = slugify($_POST['slug'] ?? $nombre);
            $genero = trim($_POST['genero'] ?? '');
            $paqueteId = (int)$_POST['paquete_id'] ?: null;
            $icecastMount = '/' . ltrim(trim($_POST['icecast_mount'] ?? "/$slug"), '/');
            $icecastPort = (int)($_POST['icecast_port'] ?? ICECAST_PORT);
            $icecastPassword = trim($_POST['icecast_password'] ?? 'hackme');
            $serverType = $_POST['server_type'] ?? 'icecast';
            $shoutcastSid = (int)($_POST['shoutcast_sid'] ?? 1);
            $codec = $_POST['codec'] ?? 'mp3';
            $bitrate = (int)($_POST['bitrate'] ?? 128);

            if (!$nombre || !$slug) {
                $msg = 'Nombre y slug son obligatorios';
                $msgType = 'error';
            } else {
                // Verificar slug único
                $check = $db->prepare("SELECT id FROM estaciones WHERE slug = ?");
                $check->execute([$slug]);
                if ($check->fetch()) {
                    $msg = "El slug '$slug' ya está en uso";
                    $msgType = 'error';
                } else {
                    $stmt = $db->prepare("
                        INSERT INTO estaciones (usuario_id, paquete_id, nombre, slug, genero, server_type, icecast_mount, icecast_port, icecast_password, shoutcast_sid, codec, bitrate)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                    ");
                    $stmt->execute([
                        $clienteId, $paqueteId, $nombre, $slug, $genero,
                        $serverType, $icecastMount, $icecastPort, $icecastPassword,
                        $shoutcastSid, $codec, $bitrate
                    ]);
                    $estacionesActuales++;
                    $msg = "Estación '$nombre' creada para el cliente";
                    $msgType = 'success';
                }
            }
        }
    } elseif ($action === 'update') {
        $id = (int)$_POST['id'];

        // Verificar que la estación pertenece a un cliente de este reseller
        $check = $db->prepare("
            SELECT e.id FROM estaciones e
            JOIN usuarios u ON u.id = e.usuario_id
            WHERE e.id = ? AND u.reseller_id = ?
        ");
        $check->execute([$id, $resellerId]);
        if (!$check->fetch()) {
            $msg = 'Estación no encontrada';
            $msgType = 'error';
        } else {
            $nombre = trim($_POST['nombre'] ?? '');
            $genero = trim($_POST['genero'] ?? '');
            $paqueteId = (int)$_POST['paquete_id'] ?: null;
            $serverType = $_POST['server_type'] ?? 'icecast';
            $icecastPort = (int)($_POST['icecast_port'] ?? ICECAST_PORT);
            $icecastPassword = trim($_POST['icecast_password'] ?? '');
            $shoutcastSid = (int)($_POST['shoutcast_sid'] ?? 1);
            $codec = $_POST['codec'] ?? 'mp3';
            $bitrate = (int)($_POST['bitrate'] ?? 128);
            $activa = isset($_POST['activa']) ? 1 : 0;

            $stmt = $db->prepare("
                UPDATE estaciones SET nombre=?, genero=?, paquete_id=?, server_type=?, icecast_port=?,
                icecast_password=?, shoutcast_sid=?, codec=?, bitrate=?, activa=?
                WHERE id=?
            ");
            $stmt->execute([
                $nombre, $genero, $paqueteId, $serverType, $icecastPort,
                $icecastPassword, $shoutcastSid, $codec, $bitrate, $activa, $id
            ]);

            $msg = 'Estación actualizada';
            $msgType = 'success';
        }
    } elseif ($action === 'delete') {
        $id = (int)$_POST['id'];
        $check = $db->prepare("
            SELECT e.id FROM estaciones e
            JOIN usuarios u ON u.id = e.usuario_id
            WHERE e.id = ? AND u.reseller_id = ?
        ");
        $check->execute([$id, $resellerId]);
        if ($check->fetch()) {
            $db->prepare("DELETE FROM estaciones WHERE id = ?")->execute([$id]);
            $estacionesActuales--;
            $msg = 'Estación eliminada';
            $msgType = 'success';
        }
    }
}

// Obtener estaciones
$sql = "
    SELECT e.*, u.username, u.nombre AS user_nombre, p.nombre AS paquete_nombre
    FROM estaciones e
    JOIN usuarios u ON u.id = e.usuario_id
    LEFT JOIN paquetes p ON p.id = e.paquete_id
    WHERE u.reseller_id = ?
";
$params = [$resellerId];

if ($filtroCliente) {
    $sql .= " AND e.usuario_id = ?";
    $params[] = $filtroCliente;
}

$sql .= " ORDER BY e.created_at DESC";
$stmt = $db->prepare($sql);
$stmt->execute($params);
$estaciones = $stmt->fetchAll();

$allListeners = getIcecastListeners();

// Editar
$editEst = null;
if (isset($_GET['edit'])) {
    $stmt = $db->prepare("
        SELECT e.* FROM estaciones e
        JOIN usuarios u ON u.id = e.usuario_id
        WHERE e.id = ? AND u.reseller_id = ?
    ");
    $stmt->execute([(int)$_GET['edit'], $resellerId]);
    $editEst = $stmt->fetch();
}

$showForm = $editEst || isset($_GET['nueva']);
?>

<?php if ($msg): ?>
    <div class="alert alert-<?= $msgType ?>"><?= htmlspecialchars($msg) ?></div>
<?php endif; ?>

<!-- Límites -->
<?php if ($maxEstaciones > 0): ?>
<div class="card" style="margin-bottom:20px">
    <div class="card-body" style="padding:15px 20px">
        <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:10px">
            <div>
                <strong>L&iacute;mite de estaciones:</strong> <?= $estacionesActuales ?> / <?= $maxEstaciones ?> utilizadas
            </div>
            <div style="flex:1;max-width:300px">
                <?php $pct = $maxEstaciones > 0 ? round(($estacionesActuales / $maxEstaciones) * 100) : 0; ?>
                <div style="background:var(--bg-tertiary);border-radius:10px;height:10px;overflow:hidden">
                    <div style="height:100%;width:<?= $pct ?>%;background:<?= $pct >= 90 ? 'var(--danger)' : 'var(--primary)' ?>;border-radius:10px;transition:width .3s"></div>
                </div>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- Formulario -->
<?php if ($showForm): ?>
<div class="card">
    <div class="card-header">
        <h3><?= $editEst ? cpIcon('pencil',14) . ' Editar Estación' : cpIcon('plus',14) . ' Nueva Estación' ?></h3>
        <a href="<?= BASE_URL ?>/reseller/estaciones.php" class="btn btn-outline btn-sm">Cancelar</a>
    </div>
    <div class="card-body">
        <form method="POST">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="<?= $editEst ? 'update' : 'create' ?>">
            <?php if ($editEst): ?>
                <input type="hidden" name="id" value="<?= $editEst['id'] ?>">
            <?php endif; ?>

            <div class="form-grid-3">
                <?php if (!$editEst): ?>
                    <div class="form-group">
                        <label>Cliente *</label>
                        <select name="usuario_id" class="form-control" required>
                            <option value="">Seleccionar cliente...</option>
                            <?php foreach ($misClientes as $c): ?>
                                <option value="<?= $c['id'] ?>" <?= ($filtroCliente == $c['id']) ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($c['username']) ?> <?= $c['nombre'] ? '(' . htmlspecialchars($c['nombre']) . ')' : '' ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                <?php endif; ?>
                <div class="form-group">
                    <label>Nombre de la Estaci&oacute;n *</label>
                    <input type="text" name="nombre" class="form-control" value="<?= htmlspecialchars($editEst['nombre'] ?? '') ?>" required>
                </div>
                <?php if (!$editEst): ?>
                    <div class="form-group">
                        <label>Slug (URL) *</label>
                        <input type="text" name="slug" class="form-control" placeholder="mi-radio" required>
                    </div>
                <?php endif; ?>
                <div class="form-group">
                    <label>G&eacute;nero</label>
                    <input type="text" name="genero" class="form-control" value="<?= htmlspecialchars($editEst['genero'] ?? '') ?>">
                </div>
            </div>

            <div class="form-grid-3">
                <div class="form-group">
                    <label>Paquete</label>
                    <select name="paquete_id" class="form-control">
                        <option value="">Sin paquete</option>
                        <?php foreach ($paquetes as $p): ?>
                            <option value="<?= $p['id'] ?>" <?= ($editEst['paquete_id'] ?? '') == $p['id'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($p['nombre']) ?> ($<?= $p['precio'] ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label>Tipo de Servidor</label>
                    <select name="server_type" class="form-control" id="serverType" onchange="toggleServerFields()">
                        <option value="icecast" <?= ($editEst['server_type'] ?? 'icecast') === 'icecast' ? 'selected' : '' ?>>Icecast</option>
                        <option value="shoutcast_v1" <?= ($editEst['server_type'] ?? '') === 'shoutcast_v1' ? 'selected' : '' ?>>SHOUTcast v1</option>
                        <option value="shoutcast_v2" <?= ($editEst['server_type'] ?? '') === 'shoutcast_v2' ? 'selected' : '' ?>>SHOUTcast v2</option>
                    </select>
                </div>
                <div class="form-group">
                    <label>Puerto</label>
                    <input type="number" name="icecast_port" class="form-control" value="<?= $editEst['icecast_port'] ?? ICECAST_PORT ?>">
                </div>
            </div>

            <div class="form-grid-3">
                <?php if (!$editEst): ?>
                    <div class="form-group" id="mountGroup">
                        <label>Mount Point</label>
                        <input type="text" name="icecast_mount" class="form-control" placeholder="/mi-radio">
                    </div>
                <?php endif; ?>
                <div class="form-group">
                    <label>Contrase&ntilde;a Servidor</label>
                    <input type="text" name="icecast_password" class="form-control" value="<?= htmlspecialchars($editEst['icecast_password'] ?? 'hackme') ?>">
                </div>
                <div class="form-group" id="sidGroup" style="display:none">
                    <label>Stream ID (SID)</label>
                    <input type="number" name="shoutcast_sid" class="form-control" value="<?= $editEst['shoutcast_sid'] ?? 1 ?>" min="1">
                </div>
            </div>

            <div class="form-grid-3">
                <div class="form-group">
                    <label>Codec</label>
                    <select name="codec" class="form-control">
                        <option value="mp3" <?= ($editEst['codec'] ?? 'mp3') === 'mp3' ? 'selected' : '' ?>>MP3</option>
                        <option value="aac" <?= ($editEst['codec'] ?? '') === 'aac' ? 'selected' : '' ?>>AAC</option>
                        <option value="ogg" <?= ($editEst['codec'] ?? '') === 'ogg' ? 'selected' : '' ?>>OGG</option>
                    </select>
                </div>
                <div class="form-group">
                    <label>Bitrate (kbps)</label>
                    <select name="bitrate" class="form-control">
                        <?php foreach ([64,96,128,192,256,320] as $br): ?>
                            <option value="<?= $br ?>" <?= ($editEst['bitrate'] ?? 128) == $br ? 'selected' : '' ?>><?= $br ?> kbps</option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php if ($editEst): ?>
                    <div class="form-group" style="display:flex;align-items:end">
                        <label><input type="checkbox" name="activa" <?= $editEst['activa'] ? 'checked' : '' ?>> Estaci&oacute;n Activa</label>
                    </div>
                <?php endif; ?>
            </div>

            <button type="submit" class="btn btn-primary"><?= $editEst ? 'Guardar Cambios' : 'Crear Estación' ?></button>
        </form>
    </div>
</div>

<script>
function toggleServerFields() {
    var type = document.getElementById('serverType').value;
    var sidGroup = document.getElementById('sidGroup');
    var mountGroup = document.getElementById('mountGroup');
    if (sidGroup) sidGroup.style.display = type === 'shoutcast_v2' ? '' : 'none';
    if (mountGroup) mountGroup.style.display = type === 'icecast' ? '' : 'none';
}
toggleServerFields();
</script>
<?php endif; ?>

<!-- Filtro por cliente -->
<div class="card">
    <div class="card-header">
        <h3><?= cpIcon('radio',18) ?> Estaciones (<?= count($estaciones) ?>)</h3>
        <div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap">
            <select class="form-control" style="width:180px" onchange="location.href='?'+(this.value?'cliente='+this.value:'')">
                <option value="">Todos los clientes</option>
                <?php foreach ($misClientes as $c): ?>
                    <option value="<?= $c['id'] ?>" <?= $filtroCliente == $c['id'] ? 'selected' : '' ?>>
                        <?= htmlspecialchars($c['username']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <input type="text" placeholder="Buscar..." class="form-control" style="width:150px" oninput="searchTable(this.value,'stations-table')">
            <?php if (!$showForm && !empty($misClientes)): ?>
                <a href="?nueva=1<?= $filtroCliente ? "&cliente=$filtroCliente" : '' ?>" class="btn btn-primary btn-sm"><?= cpIcon('plus',14) ?> Nueva</a>
            <?php endif; ?>
        </div>
    </div>
    <div class="card-body no-padding">
        <div class="table-responsive">
            <table class="table" id="stations-table">
                <thead>
                    <tr>
                        <th>Estaci&oacute;n</th>
                        <th>Cliente</th>
                        <th>Servidor</th>
                        <th>Paquete</th>
                        <th>Estado</th>
                        <th>Oyentes</th>
                        <th>Acciones</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($estaciones)): ?>
                        <tr><td colspan="7" style="text-align:center;color:var(--text-secondary);padding:30px">
                            <?= empty($misClientes)
                                ? 'Primero crea un <a href="' . BASE_URL . '/reseller/clientes.php?nuevo=1">cliente</a>'
                                : 'Sin estaciones.' . (!$showForm ? ' <a href="?nueva=1">Crear una</a>' : '') ?>
                        </td></tr>
                    <?php else: ?>
                        <?php foreach ($estaciones as $e): ?>
                            <tr>
                                <td>
                                    <strong><?= htmlspecialchars($e['nombre']) ?></strong>
                                    <div style="font-size:11px;color:var(--text-secondary)">
                                        <?= htmlspecialchars($e['slug']) ?>
                                        <?php if ($e['server_type'] === 'icecast'): ?>
                                            &middot; <?= htmlspecialchars($e['icecast_mount']) ?>
                                        <?php elseif ($e['server_type'] === 'shoutcast_v2'): ?>
                                            &middot; SID:<?= $e['shoutcast_sid'] ?>
                                        <?php endif; ?>
                                    </div>
                                </td>
                                <td>
                                    <a href="?cliente=<?= $e['usuario_id'] ?>" style="color:var(--primary)"><?= htmlspecialchars($e['username']) ?></a>
                                </td>
                                <td>
                                    <span class="badge badge-<?= $e['server_type'] === 'icecast' ? 'info' : 'warning' ?>">
                                        <?= $e['server_type'] === 'icecast' ? 'Icecast' : ($e['server_type'] === 'shoutcast_v1' ? 'SC v1' : 'SC v2') ?>
                                    </span>
                                    <div style="font-size:11px;color:var(--text-secondary)"><?= strtoupper($e['codec']) ?> <?= $e['bitrate'] ?>kbps</div>
                                </td>
                                <td><?= htmlspecialchars($e['paquete_nombre'] ?? 'Sin paquete') ?></td>
                                <td>
                                    <?= $e['activa']
                                        ? ($e['autodj_estado'] === 'online'
                                            ? '<span class="badge badge-success">Online</span>'
                                            : '<span class="badge">Offline</span>')
                                        : '<span class="badge badge-danger">Inactiva</span>' ?>
                                </td>
                                <td><?php
                                    if ($e['server_type'] === 'icecast') {
                                        echo $allListeners[$e['icecast_mount']]['oyentes'] ?? 0;
                                    } else {
                                        $sc = getStreamStatus($e);
                                        echo $sc['oyentes'];
                                    }
                                ?></td>
                                <td>
                                    <div style="display:flex;gap:5px;flex-wrap:wrap">
                                        <a href="?edit=<?= $e['id'] ?>" class="btn btn-sm btn-outline"><?= cpIcon('pencil',14) ?></a>
                                        <form method="POST" style="display:inline" onsubmit="return confirmDelete('esta estación y todo su contenido')">
                                            <?= csrfField() ?>
                                            <input type="hidden" name="action" value="delete">
                                            <input type="hidden" name="id" value="<?= $e['id'] ?>">
                                            <button type="submit" class="btn btn-sm btn-danger"><?= cpIcon('trash',14) ?></button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/layout_footer.php'; ?>
