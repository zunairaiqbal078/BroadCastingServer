<?php
/**
 * CastPanel v2.5.1 - Centro de Control DJ
 * Gestion de cuentas DJ, shows grabados, mensaje personalizado
 * Accesible para todos los roles (admin, revendedor, cliente)
 * v7.1: Corregido acceso multi-rol y filtrado de estaciones
 */
$pageTitle = 'Centro de Control DJ';
$pageIcon = 'headphones';
require_once __DIR__ . '/../includes/layout_header.php';

// No se requiere rol admin — todos los usuarios autenticados pueden acceder
// $isAdmin y $isReseller son establecidos por layout_header.php desde la sesion

$db = getDB();
$msg = '';
$msgType = '';

// ====== Auto-migracion: columna mensaje_dj en estaciones ======
try {
    $chk = $db->query("SHOW COLUMNS FROM estaciones LIKE 'mensaje_dj'")->fetchAll();
    if (count($chk) === 0) {
        $db->exec("ALTER TABLE estaciones ADD COLUMN `mensaje_dj` TEXT DEFAULT NULL");
    }
} catch (PDOException $e) { /* ya existe */ }

// ====== Obtener estaciones segun rol ======
if (!empty($isAdmin)) {
    // Admin ve todas las estaciones
    $estaciones = $db->query("SELECT id, nombre, slug FROM estaciones ORDER BY nombre")->fetchAll(PDO::FETCH_ASSOC);
} else {
    // Cliente/revendedor: solo sus estaciones
    $userId = $_SESSION[SESSION_NAME]['user_id']
           ?? $_SESSION[SESSION_NAME]['id']
           ?? $_SESSION[SESSION_NAME]['usuario_id']
           ?? 0;
    $estaciones = [];
    if ($userId > 0) {
        // Intentar con diferentes nombres de columna (compatibilidad)
        foreach (['usuario_id', 'user_id', 'owner_id', 'client_id'] as $col) {
            try {
                $stmt = $db->prepare("SELECT id, nombre, slug FROM estaciones WHERE `$col` = ? ORDER BY nombre");
                $stmt->execute([$userId]);
                $estaciones = $stmt->fetchAll(PDO::FETCH_ASSOC);
                break; // Columna encontrada
            } catch (PDOException $e) {
                continue; // Columna no existe, probar siguiente
            }
        }
    }
    if (empty($estaciones) && !empty($isReseller)) {
        // Revendedor: intentar con reseller_id
        foreach (['reseller_id', 'revendedor_id'] as $col) {
            try {
                $stmt = $db->prepare("SELECT id, nombre, slug FROM estaciones WHERE `$col` = ? ORDER BY nombre");
                $stmt->execute([$userId]);
                $estaciones = $stmt->fetchAll(PDO::FETCH_ASSOC);
                if (!empty($estaciones)) break;
            } catch (PDOException $e) {
                continue;
            }
        }
    }
}

// IDs de estaciones permitidas (para verificacion de seguridad)
$allowedStationIds = array_column($estaciones, 'id');

// ====== Contexto de estacion ======
$stationId = (int)($_GET['station'] ?? $_POST['station_id'] ?? 0);
$estacion = null;
if ($stationId > 0) {
    // Seguridad: verificar que el usuario tiene acceso a esta estacion
    if (!empty($isAdmin) || in_array($stationId, $allowedStationIds)) {
        $stmtE = $db->prepare("SELECT * FROM estaciones WHERE id = ?");
        $stmtE->execute([$stationId]);
        $estacion = $stmtE->fetch(PDO::FETCH_ASSOC);
    } else {
        // Usuario no tiene acceso a esta estacion
        $stationId = 0;
    }
}

// ====== Helper: generar password ======
function generarPasswordDJ($len = 10) {
    $chars = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    $pass = '';
    for ($i = 0; $i < $len; $i++) {
        $pass .= $chars[random_int(0, strlen($chars) - 1)];
    }
    return $pass;
}

// ====== Helper: info de conexion ======
function getDJConnectionInfo($estacion) {
    $ip = '';
    if (!empty($estacion['default_hostname'])) {
        $ip = $estacion['default_hostname'];
    } else {
        $ip = $_SERVER['SERVER_NAME'] ?? $_SERVER['HTTP_HOST'] ?? '127.0.0.1';
        $ip = preg_replace('/:\d+$/', '', $ip);
    }
    $port = $estacion['puerto_stream'] ?? 8000;
    $panelUrl = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http')
              . '://' . $ip . ':2080';
    return ['ip' => $ip, 'port' => $port, 'panel_url' => $panelUrl];
}

// ====== POST: Acciones ======
if ($_SERVER['REQUEST_METHOD'] === 'POST' && verifyCsrf()) {
    $postAction = $_POST['action'] ?? '';
    $stationId = (int)($_POST['station_id'] ?? 0);

    // Seguridad POST: verificar acceso a la estacion
    if ($stationId > 0 && (empty($isAdmin) && !in_array($stationId, $allowedStationIds))) {
        $msg = 'No tiene permiso para esta estacion';
        $msgType = 'error';
    } else {
        // Re-cargar estacion para POST
        if ($stationId > 0 && !$estacion) {
            $stmtE = $db->prepare("SELECT * FROM estaciones WHERE id = ?");
            $stmtE->execute([$stationId]);
            $estacion = $stmtE->fetch(PDO::FETCH_ASSOC);
        }

        try {
            if ($postAction === 'create_dj' && $estacion) {
                $username = trim($_POST['username'] ?? '');
                $password = trim($_POST['password'] ?? '');
                $nombre   = trim($_POST['nombre'] ?? '');

                if (!$username || !$password) {
                    $msg = 'Usuario y contrasena son obligatorios';
                    $msgType = 'error';
                } else {
                    // Verificar unicidad por estacion
                    $chkStmt = $db->prepare("SELECT id FROM cuentas_dj WHERE estacion_id = ? AND username = ?");
                    $chkStmt->execute([$stationId, $username]);
                    if ($chkStmt->fetch()) {
                        $msg = "Ya existe un DJ con usuario '$username' en esta estacion";
                        $msgType = 'error';
                    } else {
                        // Verificar limite de DJs del paquete
                        $maxDj = 99;
                        $paqStmt = $db->prepare("SELECT p.max_dj FROM paquetes p JOIN estaciones e ON e.paquete_id = p.id WHERE e.id = ?");
                        $paqStmt->execute([$stationId]);
                        $paqRow = $paqStmt->fetch(PDO::FETCH_ASSOC);
                        if ($paqRow) $maxDj = (int)$paqRow['max_dj'];

                        $countStmt = $db->prepare("SELECT COUNT(*) FROM cuentas_dj WHERE estacion_id = ?");
                        $countStmt->execute([$stationId]);
                        $currentCount = (int)$countStmt->fetchColumn();

                        if ($currentCount >= $maxDj) {
                            $msg = "Limite de cuentas DJ alcanzado ($maxDj). Actualice el paquete.";
                            $msgType = 'error';
                        } else {
                            $stmt = $db->prepare("INSERT INTO cuentas_dj
                                (estacion_id, username, password, nombre, activo,
                                 perm_transmitir, perm_cambiar_pass, perm_subir_foto, perm_login_panel,
                                 perm_start_stop, perm_autodj, perm_jingles, perm_voice_pro, perm_saltar)
                                VALUES (?,?,?,?,1,?,?,?,?,?,?,?,?,?)");
                            $stmt->execute([
                                $stationId, $username, $password, $nombre,
                                isset($_POST['perm_transmitir']) ? 1 : 0,
                                isset($_POST['perm_cambiar_pass']) ? 1 : 0,
                                isset($_POST['perm_subir_foto']) ? 1 : 0,
                                isset($_POST['perm_login_panel']) ? 1 : 0,
                                isset($_POST['perm_start_stop']) ? 1 : 0,
                                isset($_POST['perm_autodj']) ? 1 : 0,
                                isset($_POST['perm_jingles']) ? 1 : 0,
                                isset($_POST['perm_voice_pro']) ? 1 : 0,
                                isset($_POST['perm_saltar']) ? 1 : 0,
                            ]);
                            $msg = "Cuenta DJ '$username' creada exitosamente";
                            $msgType = 'success';
                        }
                    }
                }

            } elseif ($postAction === 'update_dj' && $estacion) {
                $djId     = (int)($_POST['dj_id'] ?? 0);
                $username = trim($_POST['username'] ?? '');
                $password = trim($_POST['password'] ?? '');
                $nombre   = trim($_POST['nombre'] ?? '');
                $activo   = isset($_POST['activo']) ? 1 : 0;

                if (!$username || !$password) {
                    $msg = 'Usuario y contrasena son obligatorios';
                    $msgType = 'error';
                } else {
                    // Verificar unicidad (excluir el mismo DJ)
                    $chkStmt = $db->prepare("SELECT id FROM cuentas_dj WHERE estacion_id = ? AND username = ? AND id != ?");
                    $chkStmt->execute([$stationId, $username, $djId]);
                    if ($chkStmt->fetch()) {
                        $msg = "Ya existe otro DJ con usuario '$username'";
                        $msgType = 'error';
                    } else {
                        $stmt = $db->prepare("UPDATE cuentas_dj SET
                            username=?, password=?, nombre=?, activo=?,
                            perm_transmitir=?, perm_cambiar_pass=?, perm_subir_foto=?, perm_login_panel=?,
                            perm_start_stop=?, perm_autodj=?, perm_jingles=?, perm_voice_pro=?, perm_saltar=?
                            WHERE id=? AND estacion_id=?");
                        $stmt->execute([
                            $username, $password, $nombre, $activo,
                            isset($_POST['perm_transmitir']) ? 1 : 0,
                            isset($_POST['perm_cambiar_pass']) ? 1 : 0,
                            isset($_POST['perm_subir_foto']) ? 1 : 0,
                            isset($_POST['perm_login_panel']) ? 1 : 0,
                            isset($_POST['perm_start_stop']) ? 1 : 0,
                            isset($_POST['perm_autodj']) ? 1 : 0,
                            isset($_POST['perm_jingles']) ? 1 : 0,
                            isset($_POST['perm_voice_pro']) ? 1 : 0,
                            isset($_POST['perm_saltar']) ? 1 : 0,
                            $djId, $stationId,
                        ]);
                        $msg = "Cuenta DJ '$username' actualizada";
                        $msgType = 'success';
                    }
                }

            } elseif ($postAction === 'delete_dj' && $estacion) {
                $djId = (int)($_POST['dj_id'] ?? 0);
                $stmt = $db->prepare("DELETE FROM cuentas_dj WHERE id = ? AND estacion_id = ?");
                $stmt->execute([$djId, $stationId]);
                $msg = 'Cuenta DJ eliminada';
                $msgType = 'success';

            } elseif ($postAction === 'save_message' && $estacion) {
                $mensaje = trim($_POST['mensaje_dj'] ?? '');
                $stmt = $db->prepare("UPDATE estaciones SET mensaje_dj = ? WHERE id = ?");
                $stmt->execute([$mensaje, $stationId]);
                $msg = 'Mensaje del Panel DJ actualizado';
                $msgType = 'success';
                // Refrescar estacion
                $stmtE = $db->prepare("SELECT * FROM estaciones WHERE id = ?");
                $stmtE->execute([$stationId]);
                $estacion = $stmtE->fetch(PDO::FETCH_ASSOC);
            }
        } catch (PDOException $e) {
            $msg = 'Error: ' . $e->getMessage();
            $msgType = 'error';
        }
    }
}

$action = $_GET['action'] ?? '';
$editDjId = (int)($_GET['edit'] ?? 0);
$editDj = null;
if ($editDjId > 0 && $estacion) {
    $stmtDj = $db->prepare("SELECT * FROM cuentas_dj WHERE id = ? AND estacion_id = ?");
    $stmtDj->execute([$editDjId, $stationId]);
    $editDj = $stmtDj->fetch(PDO::FETCH_ASSOC);
}
?>

<?php if ($msg): ?>
<div class="alert alert-<?= $msgType === 'error' ? 'danger' : 'success' ?>" style="margin-bottom:20px">
    <?= cpIcon($msgType === 'error' ? 'alert-triangle' : 'check-circle', 16) ?>
    <?= htmlspecialchars($msg) ?>
</div>
<?php endif; ?>

<!-- Selector de Estacion -->
<div class="section-card" style="margin-bottom:20px">
    <div style="display:flex;align-items:center;gap:12px;flex-wrap:wrap">
        <label style="font-weight:600;white-space:nowrap"><?= cpIcon('radio', 16) ?> Estacion:</label>
        <select id="stationSelect" class="form-control" style="max-width:350px" onchange="changeStation(this.value)">
            <option value="">-- Seleccionar Estacion --</option>
            <?php foreach ($estaciones as $e): ?>
            <option value="<?= $e['id'] ?>" <?= $stationId == $e['id'] ? 'selected' : '' ?>>
                <?= htmlspecialchars($e['nombre']) ?> (<?= htmlspecialchars($e['slug']) ?>)
            </option>
            <?php endforeach; ?>
        </select>
        <?php if ($estacion): ?>
        <span class="badge badge-success" style="font-size:13px">
            <?= htmlspecialchars($estacion['nombre']) ?>
        </span>
        <?php endif; ?>
    </div>
</div>

<script>
function changeStation(id) {
    if (id) window.location.href = '?station=' + id;
}
</script>

<?php if (!$estacion): ?>
<!-- Sin estacion seleccionada -->
<div class="section-card">
    <div style="text-align:center;padding:40px 20px;color:#666">
        <?= cpIcon('headphones', 48) ?>
        <h3 style="margin:16px 0 8px">Centro de Control DJ</h3>
        <p>Selecciona una estacion para administrar sus cuentas DJ.</p>
    </div>
</div>

<?php elseif ($action === 'create' || $editDj): ?>
<!-- ====== FORMULARIO CREAR / EDITAR DJ ====== -->
<?php
    $isEdit = (bool)$editDj;
    $dj = $editDj ?: [
        'username' => '', 'password' => generarPasswordDJ(), 'nombre' => '', 'activo' => 1,
        'perm_transmitir' => 1, 'perm_cambiar_pass' => 1, 'perm_subir_foto' => 1,
        'perm_login_panel' => 1, 'perm_start_stop' => 0, 'perm_autodj' => 0,
        'perm_jingles' => 0, 'perm_voice_pro' => 0, 'perm_saltar' => 0,
    ];
    $conn = getDJConnectionInfo($estacion);
?>
<div class="section-card">
    <h3 style="margin:0 0 8px;display:flex;align-items:center;gap:8px">
        <?= cpIcon($isEdit ? 'pencil' : 'user-plus', 20) ?>
        <?= $isEdit ? 'Editar Cuenta DJ' : 'Crear una Cuenta DJ' ?>
    </h3>

    <!-- Info de conexion -->
    <div style="background:var(--info-bg, #e8f4fd);border:1px solid var(--info-border, #b6d4e8);border-radius:8px;padding:14px 18px;margin-bottom:20px;font-size:14px;line-height:1.7">
        <?= cpIcon('info', 16) ?>
        Las cuentas DJ pueden iniciar sesion en el Panel DJ con su nombre de usuario y contrasena.
        Los DJs deben elegir siempre el protocolo <strong>shoutcast v1</strong> o la opcion servidor shoutcast en su programa de transmision.
        Puede usar <strong>djusername:djpassword</strong> como contrasena para programas moviles.
    </div>

    <form method="POST">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="<?= $isEdit ? 'update_dj' : 'create_dj' ?>">
        <input type="hidden" name="station_id" value="<?= $stationId ?>">
        <?php if ($isEdit): ?>
        <input type="hidden" name="dj_id" value="<?= $editDj['id'] ?>">
        <?php endif; ?>

        <div style="border:1px solid var(--border);border-radius:8px;padding:18px 20px;margin-bottom:20px">
            <h4 style="margin:0 0 14px;font-size:15px;color:#555"><?= cpIcon('user', 16) ?> Informacion de la Cuenta</h4>
            <div class="form-grid-2">
                <div class="form-group">
                    <label>Usuario DJ: *</label>
                    <input type="text" name="username" class="form-control" required placeholder="Ingresar Nombre de Usuario"
                           value="<?= htmlspecialchars($dj['username']) ?>">
                </div>
                <div class="form-group">
                    <label>Contrasena DJ: *</label>
                    <input type="text" name="password" class="form-control" required
                           value="<?= htmlspecialchars($dj['password']) ?>">
                </div>
            </div>
            <div class="form-group">
                <label>Nombre Completo:</label>
                <input type="text" name="nombre" class="form-control" placeholder="Nombre del DJ (opcional)"
                       value="<?= htmlspecialchars($dj['nombre'] ?? '') ?>">
            </div>

            <!-- Info conexion (solo lectura) -->
            <div class="form-grid-2" style="margin-top:12px">
                <div class="form-group">
                    <label>IP de Conexion de Stream:</label>
                    <input type="text" class="form-control" value="<?= htmlspecialchars($conn['ip']) ?>" readonly style="background:#f3f4f6">
                </div>
                <div class="form-group">
                    <label>Puerto de Conexion DJ:</label>
                    <input type="text" class="form-control" value="<?= htmlspecialchars($conn['port']) ?>" readonly style="background:#f3f4f6">
                </div>
            </div>
            <div class="form-group">
                <label>URL de Inicio de Sesion Panel DJ:</label>
                <input type="text" class="form-control" value="<?= htmlspecialchars($conn['panel_url']) ?>" readonly style="background:#f3f4f6">
            </div>
        </div>

        <!-- Permisos -->
        <div style="border:1px solid var(--border);border-radius:8px;padding:18px 20px;margin-bottom:20px">
            <h4 style="margin:0 0 14px;font-size:15px;color:#555"><?= cpIcon('shield', 16) ?> Permisos DJ (Tenga Cuidado)</h4>

            <?php if ($isEdit): ?>
            <div class="form-group" style="margin-bottom:14px">
                <label style="display:flex;align-items:center;gap:8px">
                    <input type="checkbox" name="activo" value="1" <?= $dj['activo'] ? 'checked' : '' ?>>
                    <strong>Cuenta Activa</strong>
                </label>
            </div>
            <?php endif; ?>

            <div class="form-grid-2">
                <div class="form-group">
                    <label>DJ Puede Transmitir Musica:</label>
                    <select name="perm_transmitir" class="form-control">
                        <option value="1" <?= ($dj['perm_transmitir'] ?? 1) ? 'selected' : '' ?>>Si</option>
                        <option value="0" <?= !($dj['perm_transmitir'] ?? 1) ? 'selected' : '' ?>>No</option>
                    </select>
                </div>
                <div class="form-group">
                    <label>DJ Puede Cambiar Propia Contrasena:</label>
                    <select name="perm_cambiar_pass" class="form-control">
                        <option value="1" <?= ($dj['perm_cambiar_pass'] ?? 1) ? 'selected' : '' ?>>Si</option>
                        <option value="0" <?= !($dj['perm_cambiar_pass'] ?? 1) ? 'selected' : '' ?>>No</option>
                    </select>
                </div>
            </div>
            <div class="form-grid-2">
                <div class="form-group">
                    <label>DJ Puede Subir Foto de Perfil:</label>
                    <select name="perm_subir_foto" class="form-control">
                        <option value="1" <?= ($dj['perm_subir_foto'] ?? 0) ? 'selected' : '' ?>>Si</option>
                        <option value="0" <?= !($dj['perm_subir_foto'] ?? 0) ? 'selected' : '' ?>>No</option>
                    </select>
                </div>
                <div class="form-group">
                    <label>DJ Puede Iniciar Sesion en Panel DJ:</label>
                    <select name="perm_login_panel" class="form-control">
                        <option value="1" <?= ($dj['perm_login_panel'] ?? 1) ? 'selected' : '' ?>>Si</option>
                        <option value="0" <?= !($dj['perm_login_panel'] ?? 1) ? 'selected' : '' ?>>No</option>
                    </select>
                </div>
            </div>
            <div class="form-grid-2">
                <div class="form-group">
                    <label>DJ Puede Iniciar/Detener Radio:</label>
                    <select name="perm_start_stop" class="form-control">
                        <option value="1" <?= ($dj['perm_start_stop'] ?? 0) ? 'selected' : '' ?>>Si</option>
                        <option value="0" <?= !($dj['perm_start_stop'] ?? 0) ? 'selected' : '' ?>>No</option>
                    </select>
                </div>
                <div class="form-group">
                    <label>DJ Puede Iniciar/Detener AutoDJ:</label>
                    <select name="perm_autodj" class="form-control">
                        <option value="1" <?= ($dj['perm_autodj'] ?? 0) ? 'selected' : '' ?>>Si</option>
                        <option value="0" <?= !($dj['perm_autodj'] ?? 0) ? 'selected' : '' ?>>No</option>
                    </select>
                </div>
            </div>
            <div class="form-grid-2">
                <div class="form-group">
                    <label>DJ Puede Reproducir Jingles/IDs (Al Aire):</label>
                    <select name="perm_jingles" class="form-control">
                        <option value="1" <?= ($dj['perm_jingles'] ?? 0) ? 'selected' : '' ?>>Si</option>
                        <option value="0" <?= !($dj['perm_jingles'] ?? 0) ? 'selected' : '' ?>>No</option>
                    </select>
                </div>
                <div class="form-group">
                    <label>DJ Puede usar Voice Pro (Al Aire):</label>
                    <select name="perm_voice_pro" class="form-control">
                        <option value="1" <?= ($dj['perm_voice_pro'] ?? 0) ? 'selected' : '' ?>>Si</option>
                        <option value="0" <?= !($dj['perm_voice_pro'] ?? 0) ? 'selected' : '' ?>>No</option>
                    </select>
                </div>
            </div>
            <div class="form-grid-2">
                <div class="form-group">
                    <label>DJ Puede usar Saltar Cancion (AutoDJ):</label>
                    <select name="perm_saltar" class="form-control">
                        <option value="1" <?= ($dj['perm_saltar'] ?? 0) ? 'selected' : '' ?>>Si</option>
                        <option value="0" <?= !($dj['perm_saltar'] ?? 0) ? 'selected' : '' ?>>No</option>
                    </select>
                </div>
                <div class="form-group"></div>
            </div>
        </div>

        <div style="display:flex;gap:10px">
            <button type="submit" class="btn btn-primary">
                <?= cpIcon($isEdit ? 'check' : 'plus', 16) ?>
                <?= $isEdit ? 'Guardar Cambios' : 'Crear Cuenta DJ' ?>
            </button>
            <a href="?station=<?= $stationId ?>" class="btn btn-outline">Cancelar</a>
            <?php if ($isEdit): ?>
            <form method="POST" style="margin-left:auto" onsubmit="return confirm('Eliminar esta cuenta DJ?')">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="delete_dj">
                <input type="hidden" name="station_id" value="<?= $stationId ?>">
                <input type="hidden" name="dj_id" value="<?= $editDj['id'] ?>">
                <button type="submit" class="btn btn-danger">
                    <?= cpIcon('trash', 16) ?> Eliminar Cuenta DJ
                </button>
            </form>
            <?php endif; ?>
        </div>
    </form>
</div>

<?php elseif ($action === 'shows'): ?>
<!-- ====== SHOWS DJ GRABADOS ====== -->
<?php
    $showsDir = '/var/www/castpanel/storage/' . ($estacion['slug'] ?? 'unknown') . '/recordings';
    $shows = [];
    if (is_dir($showsDir)) {
        $files = scandir($showsDir, SCANDIR_SORT_DESCENDING);
        foreach ($files as $f) {
            if ($f === '.' || $f === '..') continue;
            $fullPath = $showsDir . '/' . $f;
            if (is_file($fullPath)) {
                $shows[] = [
                    'file' => $f,
                    'size' => filesize($fullPath),
                    'date' => date('Y-m-d', filemtime($fullPath)),
                    'time' => date('H:i:s', filemtime($fullPath)),
                ];
            }
        }
    }
?>
<div class="section-card">
    <h3 style="margin:0 0 12px;display:flex;align-items:center;gap:8px">
        <?= cpIcon('download', 20) ?>
        Listar/Descargar Shows DJ
    </h3>

    <div style="background:var(--info-bg, #e8f4fd);border:1px solid var(--info-border, #b6d4e8);border-radius:8px;padding:14px 18px;margin-bottom:20px;font-size:14px;line-height:1.7">
        <?= cpIcon('info', 16) ?>
        La grabacion es automatica si esta habilitada. Para habilitar/deshabilitar la grabacion de shows DJ en vivo,
        use la configuracion de la estacion. Los archivos usan su espacio de alojamiento disponible.
    </div>

    <?php if (empty($shows)): ?>
    <div style="text-align:center;padding:30px;color:#888">
        <?= cpIcon('music', 32) ?>
        <p style="margin:10px 0 0">No hay shows grabados para esta estacion.</p>
        <p style="font-size:13px;color:#aaa">Grabacion DJ: <strong><?= ($estacion['grabar_dj'] ?? 0) ? 'Habilitada' : 'Deshabilitada' ?></strong></p>
    </div>
    <?php else: ?>
    <div class="table-responsive">
        <table class="table">
            <thead>
                <tr>
                    <th><?= cpIcon('user', 14) ?> Archivo</th>
                    <th><?= cpIcon('calendar', 14) ?> Fecha</th>
                    <th><?= cpIcon('clock', 14) ?> Hora</th>
                    <th><?= cpIcon('hard-drive', 14) ?> Tamano</th>
                    <th><?= cpIcon('download', 14) ?> Descargar</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($shows as $show): ?>
                <tr>
                    <td><?= htmlspecialchars($show['file']) ?></td>
                    <td><?= $show['date'] ?></td>
                    <td><?= $show['time'] ?></td>
                    <td><?= formatMB(round($show['size'] / 1048576, 1)) ?></td>
                    <td>
                        <a href="<?= BASE_URL ?>/storage/<?= htmlspecialchars($estacion['slug']) ?>/recordings/<?= rawurlencode($show['file']) ?>"
                           class="btn btn-sm btn-outline" download>
                            <?= cpIcon('download', 14) ?> Descargar
                        </a>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</div>

<?php elseif ($action === 'message'): ?>
<!-- ====== MENSAJE PERSONALIZADO ====== -->
<div class="section-card">
    <h3 style="margin:0 0 12px;display:flex;align-items:center;gap:8px">
        <?= cpIcon('message-square', 20) ?>
        Mensaje Personalizado del Panel DJ
    </h3>

    <div style="background:var(--info-bg, #e8f4fd);border:1px solid var(--info-border, #b6d4e8);border-radius:8px;padding:14px 18px;margin-bottom:20px;font-size:14px;line-height:1.7">
        <?= cpIcon('info', 16) ?>
        Cualquier mensaje o anuncio que escriba aqui aparecera en la pagina principal del Panel DJ (puerto 2080).
        Puede especificar sus reglas de radio o cualquier cosa que quiera que su DJ lea.
        Se aceptan codigos HTML, enlaces y textos en <strong>negrita</strong> o listas <strong>ul</strong>.
    </div>

    <div style="border:1px solid var(--border);border-radius:8px;padding:18px 20px;margin-bottom:16px">
        <h4 style="margin:0 0 10px;font-size:14px;color:#666">Esto es lo que veran sus DJs en su Panel DJ:</h4>
        <div style="background:#fff;border:1px solid #e2e8f0;border-radius:6px;padding:14px;min-height:40px;color:#333">
            <?= $estacion['mensaje_dj'] ?? '' ?>
        </div>
    </div>

    <form method="POST">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="save_message">
        <input type="hidden" name="station_id" value="<?= $stationId ?>">

        <div class="form-group">
            <label>Mensaje Personalizado del Panel DJ:</label>
            <textarea name="mensaje_dj" class="form-control" rows="8" style="font-family:monospace;font-size:13px"
                      placeholder="Escriba su mensaje aqui..."><?= htmlspecialchars($estacion['mensaje_dj'] ?? '') ?></textarea>
        </div>

        <button type="submit" class="btn btn-primary">
            <?= cpIcon('check', 16) ?> Guardar Cambios
        </button>
    </form>
</div>

<?php else: ?>
<!-- ====== LISTAR CUENTAS DJ ====== -->
<?php
    $djList = $db->prepare("SELECT * FROM cuentas_dj WHERE estacion_id = ? ORDER BY username");
    $djList->execute([$stationId]);
    $djs = $djList->fetchAll(PDO::FETCH_ASSOC);

    $conn = getDJConnectionInfo($estacion);

    // Limite del paquete
    $maxDj = 99;
    $paqStmt = $db->prepare("SELECT p.max_dj FROM paquetes p JOIN estaciones e ON e.paquete_id = p.id WHERE e.id = ?");
    $paqStmt->execute([$stationId]);
    $paqRow = $paqStmt->fetch(PDO::FETCH_ASSOC);
    if ($paqRow) $maxDj = (int)$paqRow['max_dj'];
?>

<div class="section-card">
    <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:10px;margin-bottom:16px">
        <h3 style="margin:0;display:flex;align-items:center;gap:8px">
            <?= cpIcon('users', 20) ?>
            Cuentas DJ
            <span class="badge badge-<?= count($djs) >= $maxDj ? 'danger' : 'info' ?>"><?= count($djs) ?>/<?= $maxDj ?></span>
        </h3>
        <?php if (count($djs) < $maxDj): ?>
        <a href="?station=<?= $stationId ?>&action=create" class="btn btn-primary btn-sm">
            <?= cpIcon('plus', 14) ?> Crear Cuenta DJ
        </a>
        <?php endif; ?>
    </div>

    <!-- Info panel DJ -->
    <div style="background:#f8f9fa;border:1px solid var(--border);border-radius:8px;padding:12px 16px;margin-bottom:16px;display:flex;gap:20px;flex-wrap:wrap;font-size:13px">
        <div><strong>IP Stream:</strong> <?= htmlspecialchars($conn['ip']) ?></div>
        <div><strong>Puerto DJ:</strong> <?= htmlspecialchars($conn['port']) ?></div>
        <div><strong>Panel DJ:</strong> <a href="<?= htmlspecialchars($conn['panel_url']) ?>" target="_blank"><?= htmlspecialchars($conn['panel_url']) ?></a></div>
    </div>

    <?php if (empty($djs)): ?>
    <div style="text-align:center;padding:30px;color:#888">
        <?= cpIcon('user-plus', 32) ?>
        <p style="margin:10px 0 0">No hay cuentas DJ en esta estacion.</p>
        <a href="?station=<?= $stationId ?>&action=create" class="btn btn-primary" style="margin-top:12px">
            <?= cpIcon('plus', 14) ?> Crear primera cuenta DJ
        </a>
    </div>
    <?php else: ?>
    <div class="table-responsive">
        <table class="table">
            <thead>
                <tr>
                    <th>Usuario</th>
                    <th>Nombre</th>
                    <th>Contrasena</th>
                    <th>Estado</th>
                    <th>Ultimo Login</th>
                    <th>Permisos</th>
                    <th>Acciones</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($djs as $djRow): ?>
                <tr>
                    <td><strong><?= htmlspecialchars($djRow['username']) ?></strong></td>
                    <td><?= htmlspecialchars($djRow['nombre'] ?? '-') ?></td>
                    <td><code style="font-size:12px"><?= htmlspecialchars($djRow['password']) ?></code></td>
                    <td>
                        <?= $djRow['activo']
                            ? '<span class="badge badge-success">Activo</span>'
                            : '<span class="badge badge-danger">Inactivo</span>' ?>
                    </td>
                    <td style="font-size:13px;color:#666">
                        <?= $djRow['ultimo_login'] ? date('d/m/Y H:i', strtotime($djRow['ultimo_login'])) : 'Nunca' ?>
                    </td>
                    <td style="font-size:12px">
                        <?php
                        $perms = [];
                        if ($djRow['perm_transmitir']) $perms[] = 'Transmitir';
                        if ($djRow['perm_autodj']) $perms[] = 'AutoDJ';
                        if ($djRow['perm_start_stop']) $perms[] = 'Start/Stop';
                        echo $perms ? implode(', ', $perms) : '<span style="color:#aaa">Basico</span>';
                        ?>
                    </td>
                    <td>
                        <div style="display:flex;gap:5px">
                            <a href="?station=<?= $stationId ?>&edit=<?= $djRow['id'] ?>" class="btn btn-sm btn-outline">
                                <?= cpIcon('pencil', 14) ?>
                            </a>
                            <form method="POST" style="display:inline" onsubmit="return confirm('Eliminar cuenta DJ: <?= htmlspecialchars($djRow['username']) ?>?')">
                                <?= csrfField() ?>
                                <input type="hidden" name="action" value="delete_dj">
                                <input type="hidden" name="station_id" value="<?= $stationId ?>">
                                <input type="hidden" name="dj_id" value="<?= $djRow['id'] ?>">
                                <button type="submit" class="btn btn-sm btn-danger"><?= cpIcon('trash', 14) ?></button>
                            </form>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</div>
<?php endif; ?>

<?php require_once __DIR__ . '/../includes/layout_footer.php'; ?>
