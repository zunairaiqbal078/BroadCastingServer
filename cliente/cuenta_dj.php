<?php
ob_start();
/**
 * CastPanel v2.7.3 - Gestión de Cuentas DJ (Estilo SonicPanel)
 * - Formulario con datos de conexión integrados
 * - Permisos como dropdowns Sí/No
 * - Info de conexión condicional según autodj_enabled
 */
$pageTitle = 'Cuentas DJ';
$pageIcon = 'microphone';
require_once __DIR__ . '/../includes/layout_header.php';

if (!$estacion) { echo '<div class="alert alert-warning">Sin estaci&oacute;n asignada.</div>'; require_once __DIR__ . '/../includes/layout_footer.php'; exit; }

$db = getDB();

// Límite de cuentas DJ
$maxDJ = 5;
try {
    $stmt = $db->prepare("SELECT p.max_dj FROM paquetes p JOIN estaciones e ON e.paquete_id = p.id WHERE e.id = ?");
    $stmt->execute([$estacion['id']]);
    $pkg = $stmt->fetch();
    if ($pkg) $maxDJ = (int)$pkg['max_dj'];
} catch (PDOException $e) {}

$totalDJ = 0;
try {
    $stmt = $db->prepare("SELECT COUNT(*) FROM cuentas_dj WHERE estacion_id = ?");
    $stmt->execute([$estacion['id']]);
    $totalDJ = (int)$stmt->fetchColumn();
} catch (PDOException $e) {}

// Detectar si AutoDJ está habilitado
$autoDJEnabled = !empty($estacion['autodj_enabled']);

// Encoder / RadioCaster host — IP or domain only (no :8080 panel port)
$connServer = castpanelPublicHost();
$serverIP   = defined('SERVER_IP') ? SERVER_IP : $connServer;
$puertoStream = (int)($estacion['puerto_stream'] ?: ($estacion['icecast_port'] ?? 8000));

if ($autoDJEnabled) {
    // Con AutoDJ: conectar a Liquidsoap harbor (SHOUTcast v1 / ICY protocol)
    $connProtocol  = 'SHOUTcast v1';
    $connPort      = $puertoStream + 5; // Harbor port
    $connMount     = ''; // No se necesita mount point con SHOUTcast v1
    $connPassword  = ''; // Se muestra por DJ individual (password_plain)
    $connUser      = ''; // Se muestra por DJ individual (username)
    $connNote      = 'Protocolo <strong>SHOUTcast v1</strong> — Conexi&oacute;n a trav&eacute;s de Liquidsoap Harbor (AutoDJ activo). Cada DJ usa sus propias credenciales.';
    $radiocastNote = 'RadioCaster: Protocol <em>SHOUTcast v1</em>. Server: <strong>' . htmlspecialchars($connServer) . '</strong>, '
        . 'Port: <strong>' . $connPort . '</strong> (harbor — not the listener port ' . $puertoStream . '). '
        . 'Password: this DJ account password. Username: optional; if required use DJ username. '
        . 'When you connect, AutoDJ is overridden automatically (no extra permission needed). '
        . 'Enable <strong>DJ can transmit</strong> below.';
} else {
    // Sin AutoDJ: conectar directamente al servidor SHOUTcast/Icecast
    $serverType = $estacion['server_type'] ?? 'shoutcast_v2';
    if (stripos($serverType, 'icecast') !== false) {
        $connProtocol  = 'Icecast (Source)';
        $connPort      = (int)($estacion['icecast_port'] ?: $puertoStream);
        $connMount     = '/' . ($estacion['icecast_mount'] ?? ($estacion['slug'] ?? 'stream'));
        $connPassword  = $estacion['icecast_password'] ?? 'hackme';
        $connUser      = 'source';
        $connNote      = 'Protocolo <strong>Icecast</strong> — Conexi&oacute;n directa al servidor Icecast.';
        $radiocastNote = 'RadioCaster: Protocolo <em>Icecast</em>, Servidor: ' . htmlspecialchars($connServer) . ', Puerto: ' . $connPort . ', Mount: ' . htmlspecialchars($connMount);
    } else {
        // SHOUTcast v1/v2
        $connProtocol  = 'SHOUTcast';
        $connPort      = $puertoStream;
        $connMount     = '/' . ($estacion['slug'] ?? 'stream');
        $connPassword  = $estacion['icecast_password'] ?? ($estacion['password'] ?? '');
        $connUser      = '';
        $connNote      = 'Protocolo <strong>SHOUTcast v1</strong> — Conexi&oacute;n directa al servidor SHOUTcast (sin AutoDJ). En RadioCaster usar siempre protocolo SHOUTcast v1.';
        $radiocastNote = 'RadioCaster: Protocolo <em>SHOUTcast v1</em>, Servidor: ' . htmlspecialchars($connServer) . ', Puerto: ' . $connPort . ', Contrase&ntilde;a: (la del DJ)';
    }
}

$panelDJUrl = castpanelDjPortalUrl();

// Permisos DJ disponibles
$permisos = [
    'perm_transmitir'   => ['label' => 'DJ Puede Transmitir M&uacute;sica',       'icon' => cpIcon('music',16)],
    'perm_cambiar_pass' => ['label' => 'DJ Puede Cambiar Propia Contrase&ntilde;a','icon' => cpIcon('lock',16)],
    'perm_subir_foto'   => ['label' => 'DJ Puede Subir Foto de Perfil',            'icon' => cpIcon('image',16)],
    'perm_login_panel'  => ['label' => 'DJ Puede Iniciar Sesi&oacute;n en Panel DJ','icon' => cpIcon('user',16)],
    'perm_start_stop'   => ['label' => 'DJ Puede Iniciar/Detener Radio',           'icon' => cpIcon('play',16)],
    'perm_autodj'       => ['label' => 'DJ Puede Iniciar/Detener AutoDJ',          'icon' => cpIcon('gear',16)],
    'perm_jingles'      => ['label' => 'DJ Puede Reproducir Jingles/IDs (Al Aire)','icon' => cpIcon('speaker',16)],
    'perm_voice_pro'    => ['label' => 'DJ Puede usar Voice Pro (Al Aire)',         'icon' => cpIcon('microphone',16)],
    'perm_saltar'       => ['label' => 'DJ Puede Saltar Canci&oacute;n (AutoDJ)',  'icon' => cpIcon('zap',16)],
];

// Permisos que dependen de AutoDJ — ocultar si autodj_enabled=0
$permisosAutoDJ = ['perm_autodj', 'perm_saltar'];

// Procesar acciones
if ($_SERVER['REQUEST_METHOD'] === 'POST' && verifyCsrf()) {
    $action = $_POST['action'] ?? '';

    try {
        if ($action === 'create') {
            if ($totalDJ >= $maxDJ) {
                setFlash('error', "L&iacute;mite de $maxDJ cuentas DJ alcanzado.");
                header('Location: cuenta_dj.php'); exit;
            }

            $username = trim($_POST['username']);
            $password = $_POST['password'];
            $nombre   = trim($_POST['nombre']);
            $email    = trim($_POST['email']);

            if (!$username || !$password) {
                setFlash('error', 'Usuario y contrase&ntilde;a son obligatorios.');
                header('Location: cuenta_dj.php'); exit;
            }

            $check = $db->prepare("SELECT id FROM cuentas_dj WHERE estacion_id = ? AND username = ?");
            $check->execute([$estacion['id'], $username]);
            if ($check->fetch()) {
                setFlash('error', 'Ya existe un DJ con ese nombre de usuario.');
                header('Location: cuenta_dj.php'); exit;
            }

            $hash = password_hash($password, PASSWORD_DEFAULT);

            // Recoger permisos (dropdown: Sí=1, No=0)
            $permValues = [];
            foreach (array_keys($permisos) as $key) {
                if (!$autoDJEnabled && in_array($key, $permisosAutoDJ)) {
                    $permValues[$key] = 0;
                } else {
                    $permValues[$key] = ($_POST[$key] ?? '0') === '1' ? 1 : 0;
                }
            }

            $cols = 'estacion_id, username, password, password_plain, nombre, email';
            $vals = '?,?,?,?,?,?';
            $params = [$estacion['id'], $username, $hash, $password, $nombre, $email];

            foreach ($permValues as $col => $val) {
                $cols .= ", $col";
                $vals .= ',?';
                $params[] = $val;
            }

            $db->prepare("INSERT INTO cuentas_dj ($cols) VALUES ($vals)")->execute($params);
            setFlash('success', "Cuenta DJ '$username' creada exitosamente.");
            header('Location: cuenta_dj.php'); exit;
        }

        if ($action === 'edit') {
            $djId     = (int)$_POST['dj_id'];
            $nombre   = trim($_POST['nombre']);
            $email    = trim($_POST['email']);
            $password = $_POST['password'] ?? '';

            $sets = ['nombre = ?', 'email = ?'];
            $params = [$nombre, $email];

            if ($password) {
                $sets[] = 'password = ?';
                $params[] = password_hash($password, PASSWORD_DEFAULT);
                $sets[] = 'password_plain = ?';
                $params[] = $password;
            }

            // Permisos
            foreach (array_keys($permisos) as $key) {
                if (!$autoDJEnabled && in_array($key, $permisosAutoDJ)) {
                    $sets[] = "$key = 0";
                } else {
                    $sets[] = "$key = ?";
                    $params[] = ($_POST[$key] ?? '0') === '1' ? 1 : 0;
                }
            }

            $params[] = $djId;
            $params[] = $estacion['id'];
            $db->prepare("UPDATE cuentas_dj SET " . implode(', ', $sets) . " WHERE id = ? AND estacion_id = ?")->execute($params);
            setFlash('success', 'Cuenta DJ actualizada.');
            header('Location: cuenta_dj.php'); exit;
        }

        if ($action === 'toggle') {
            $djId = (int)$_POST['dj_id'];
            $db->prepare("UPDATE cuentas_dj SET activo = NOT activo WHERE id = ? AND estacion_id = ?")->execute([$djId, $estacion['id']]);
            header('Location: cuenta_dj.php'); exit;
        }

        if ($action === 'delete') {
            $djId = (int)$_POST['dj_id'];
            $db->prepare("DELETE FROM cuentas_dj WHERE id = ? AND estacion_id = ?")->execute([$djId, $estacion['id']]);
            setFlash('success', 'Cuenta DJ eliminada.');
            header('Location: cuenta_dj.php'); exit;
        }
    } catch (PDOException $e) {
        setFlash('error', 'Error: ' . $e->getMessage());
        header('Location: cuenta_dj.php'); exit;
    }
}

// Obtener cuentas DJ existentes
$djAccounts = [];
try {
    $stmt = $db->prepare("SELECT * FROM cuentas_dj WHERE estacion_id = ? ORDER BY username");
    $stmt->execute([$estacion['id']]);
    $djAccounts = $stmt->fetchAll();
} catch (PDOException $e) {}

// DJ para edición
$editDJ = null;
if (isset($_GET['edit'])) {
    $editId = (int)$_GET['edit'];
    foreach ($djAccounts as $dj) {
        if ($dj['id'] == $editId) { $editDJ = $dj; break; }
    }
}
?>

<style>
.cp-info-note {
    background: #e3f2fd; border: 1px solid #90caf9; border-radius: 8px;
    padding: 12px 16px; font-size: 13px; line-height: 1.6; margin-bottom: 16px;
    color: #1565c0;
}
.cp-info-note.cp-warn {
    background: #fff3e0; border-color: #ffb74d; color: #e65100;
}
.cp-conn-field {
    margin-bottom: 12px;
}
.cp-conn-field label {
    display: block; font-size: 12px; font-weight: 600; color: var(--text-secondary, #666);
    text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 4px;
}
.cp-conn-input {
    display: flex; align-items: center; gap: 0;
}
.cp-conn-input input {
    flex: 1; padding: 8px 12px; border: 1px solid var(--border, #ddd); border-radius: 6px 0 0 6px;
    font-size: 14px; background: #f8f9fa; color: var(--text-primary, #333);
}
.cp-conn-input .cp-copy-btn {
    padding: 8px 14px; border: 1px solid var(--border, #ddd); border-left: 0;
    border-radius: 0 6px 6px 0; background: #fff; cursor: pointer;
    font-size: 12px; font-weight: 600; color: var(--primary, #2563eb);
    transition: background .15s;
    display: flex; align-items: center; gap: 4px;
}
.cp-conn-input .cp-copy-btn:hover { background: #e3f2fd; }
.cp-perm-row {
    display: flex; align-items: center; gap: 10px; padding: 10px 12px;
    border: 1px solid var(--border, #ddd); border-radius: 8px; margin-bottom: 6px;
}
.cp-perm-row .cp-perm-icon { flex: 0 0 28px; text-align: center; }
.cp-perm-row .cp-perm-label { flex: 1; font-size: 13px; font-weight: 500; }
.cp-perm-row select {
    width: 70px; padding: 5px 8px; border: 1px solid var(--border, #ddd);
    border-radius: 6px; font-size: 13px; font-weight: 600; cursor: pointer;
    background: #fff;
}
.cp-perm-row select.cp-yes { color: #059669; }
.cp-perm-row select.cp-no { color: #dc2626; }
</style>

<!-- Barra de estado -->
<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:12px;margin-bottom:16px">
    <div class="card" style="padding:14px 16px;text-align:center;background:linear-gradient(135deg,#059669,#10b981)">
        <div style="font-size:22px;font-weight:700;color:#fff"><?= $totalDJ ?></div>
        <div style="font-size:12px;color:rgba(255,255,255,.85)">Cuentas DJ</div>
    </div>
    <div class="card" style="padding:14px 16px;text-align:center;background:linear-gradient(135deg,#2563eb,#3b82f6)">
        <div style="font-size:22px;font-weight:700;color:#fff"><?= $maxDJ ?></div>
        <div style="font-size:12px;color:rgba(255,255,255,.85)">M&aacute;ximo Permitido</div>
    </div>
    <div class="card" style="padding:14px 16px;text-align:center;background:linear-gradient(135deg,#d97706,#f59e0b)">
        <div style="font-size:22px;font-weight:700;color:#fff"><?= max(0, $maxDJ - $totalDJ) ?></div>
        <div style="font-size:12px;color:rgba(255,255,255,.85)">Disponibles</div>
    </div>
    <div class="card" style="padding:14px 16px;text-align:center;background:linear-gradient(135deg,<?= $totalDJ > 0 ? '#059669,#10b981' : '#6b7280,#9ca3af' ?>)">
        <div style="font-size:22px;font-weight:700;color:#fff"><?= count(array_filter($djAccounts, fn($d) => $d['activo'])) ?></div>
        <div style="font-size:12px;color:rgba(255,255,255,.85)">DJs Activos</div>
    </div>
</div>

<!-- Nota informativa -->
<div class="cp-info-note" style="margin-bottom:16px">
    <?= cpIcon('info', 16) ?>
    Los DJs deben elegir siempre el protocolo <strong>SHOUTcast v1</strong> en su programa de transmisi&oacute;n.
    <?php if (!$autoDJEnabled): ?>
    Esta estaci&oacute;n opera <strong>sin AutoDJ</strong> &mdash; los DJs se conectan directamente al servidor de streaming.
    <?php else: ?>
    Los DJs se conectan al puerto Harbor (<strong><?= $connPort ?></strong>) y pueden usar <code>usuario:contrase&ntilde;a</code> en apps m&oacute;viles.
    <?php endif; ?>
</div>

<div class="two-cols-equal">
    <!-- Formulario Crear / Editar -->
    <div>
        <div class="card">
            <div class="card-header">
                <h3><?= $editDJ ? cpIcon('pencil',14) . ' Editar DJ: ' . htmlspecialchars($editDJ['username']) : cpIcon('plus',14) . ' Nueva Cuenta DJ' ?></h3>
                <?php if ($editDJ): ?>
                    <a href="cuenta_dj.php" class="btn btn-outline btn-sm">Cancelar</a>
                <?php endif; ?>
            </div>
            <div class="card-body">
                <?php if (!$editDJ && $totalDJ >= $maxDJ): ?>
                    <div class="alert alert-warning">
                        Has alcanzado el l&iacute;mite de <?= $maxDJ ?> cuentas DJ. Contacta al administrador para ampliar tu plan.
                    </div>
                <?php else: ?>
                    <form method="POST">
                        <?= csrfField() ?>
                        <input type="hidden" name="action" value="<?= $editDJ ? 'edit' : 'create' ?>">
                        <?php if ($editDJ): ?>
                            <input type="hidden" name="dj_id" value="<?= $editDJ['id'] ?>">
                        <?php endif; ?>

                        <!-- ===== Información de la Cuenta ===== -->
                        <h4 style="font-size:15px;font-weight:700;margin:0 0 14px;padding-bottom:8px;border-bottom:2px solid var(--border, #ddd)">
                            Informaci&oacute;n de la Cuenta
                        </h4>

                        <!-- Usuario DJ -->
                        <div class="cp-conn-field">
                            <label>Usuario DJ:</label>
                            <?php if ($editDJ): ?>
                                <input type="text" class="form-control" value="<?= htmlspecialchars($editDJ['username']) ?>" disabled style="background:#f0f0f0">
                            <?php else: ?>
                                <input type="text" name="username" class="form-control" placeholder="Ingresar Nombre de Usuario" required pattern="[a-zA-Z0-9_\-]+" title="Solo letras, n&uacute;meros, guiones y guiones bajos">
                            <?php endif; ?>
                        </div>

                        <!-- Contraseña DJ -->
                        <div class="cp-conn-field">
                            <label><?= $editDJ ? 'Nueva Contrase&ntilde;a DJ: (vac&iacute;o = sin cambio)' : 'Contrase&ntilde;a DJ:' ?></label>
                            <input type="text" name="password" class="form-control"
                                   <?= $editDJ ? 'placeholder="Dejar vac&iacute;o para no cambiar"' : 'value="' . strtoupper(bin2hex(random_bytes(5))) . '" required' ?>
                                   style="font-family:monospace;letter-spacing:1px">
                            <?php if (!$editDJ): ?>
                                <small style="color:var(--text-secondary);font-size:11px">Se genera autom&aacute;ticamente. Puedes cambiarla.</small>
                            <?php endif; ?>
                        </div>

                        <!-- Nombre Completo -->
                        <div class="cp-conn-field">
                            <label>Nombre Completo:</label>
                            <input type="text" name="nombre" class="form-control" placeholder="Juan P&eacute;rez" value="<?= htmlspecialchars($editDJ['nombre'] ?? '') ?>">
                        </div>

                        <!-- Email -->
                        <div class="cp-conn-field">
                            <label>Email:</label>
                            <input type="email" name="email" class="form-control" placeholder="dj@ejemplo.com" value="<?= htmlspecialchars($editDJ['email'] ?? '') ?>">
                        </div>

                        <!-- Datos de Conexión (solo lectura) -->
                        <div class="cp-conn-field">
                            <label>IP de Conexi&oacute;n de Stream:</label>
                            <div class="cp-conn-input">
                                <input type="text" value="<?= htmlspecialchars($connServer) ?>" readonly id="cp-conn-ip">
                                <button type="button" class="cp-copy-btn" onclick="cpCopy('cp-conn-ip')"><?= cpIcon('clipboard',14) ?> Copiar</button>
                            </div>
                        </div>

                        <div class="cp-conn-field">
                            <label>Puerto de Conexi&oacute;n DJ:</label>
                            <div class="cp-conn-input">
                                <input type="text" value="<?= $connPort ?>" readonly id="cp-conn-port">
                                <button type="button" class="cp-copy-btn" onclick="cpCopy('cp-conn-port')"><?= cpIcon('clipboard',14) ?> Copiar</button>
                            </div>
                        </div>

                        <?php if ($connMount): ?>
                        <div class="cp-conn-field">
                            <label>Punto de Montaje (Mount):</label>
                            <div class="cp-conn-input">
                                <input type="text" value="<?= htmlspecialchars($connMount) ?>" readonly id="cp-conn-mount">
                                <button type="button" class="cp-copy-btn" onclick="cpCopy('cp-conn-mount')"><?= cpIcon('clipboard',14) ?> Copiar</button>
                            </div>
                        </div>
                        <?php endif; ?>

                        <div class="cp-conn-field">
                            <?php if ($autoDJEnabled): ?>
                            <label>Contrase&ntilde;a DJ:</label>
                            <div style="font-size:13px;color:#666;padding:8px 12px;background:#f8f9fa;border:1px solid var(--border, #ddd);border-radius:6px;">
                                Cada DJ usa su propia contrase&ntilde;a (la que se asigna al crear la cuenta). En m&oacute;viles usar formato <code>usuario:contrase&ntilde;a</code>.
                            </div>
                            <?php else: ?>
                            <label>Contrase&ntilde;a Source / DJ:</label>
                            <div class="cp-conn-input">
                                <input type="password" value="<?= htmlspecialchars($connPassword) ?>" readonly id="cp-conn-pass">
                                <button type="button" class="cp-copy-btn" onclick="var i=document.getElementById('cp-conn-pass');i.type=i.type==='password'?'text':'password'"><?= cpIcon('eye',14) ?> Ver</button>
                                <button type="button" class="cp-copy-btn" onclick="cpCopy('cp-conn-pass')" style="border-left:0"><?= cpIcon('clipboard',14) ?> Copiar</button>
                            </div>
                            <?php endif; ?>
                        </div>

                        <div class="cp-conn-field">
                            <label>URL de Inicio de Sesi&oacute;n Panel DJ:</label>
                            <div class="cp-conn-input">
                                <input type="text" value="<?= htmlspecialchars($panelDJUrl) ?>" readonly id="cp-conn-url">
                                <button type="button" class="cp-copy-btn" onclick="cpCopy('cp-conn-url')"><?= cpIcon('clipboard',14) ?> Copiar</button>
                            </div>
                            <small style="color:var(--text-secondary);font-size:11px;display:block;margin-top:4px">
                                DJ web login uses port <?= (int)(defined('DJ_PORT') ? DJ_PORT : 2080) ?> (not the cliente panel port). Live audio uses the harbor port above.
                            </small>
                        </div>

                        <!-- ===== Permisos DJ ===== -->
                        <h4 style="font-size:15px;font-weight:700;margin:20px 0 6px;padding-bottom:8px;border-bottom:2px solid var(--border, #ddd)">
                            <?= cpIcon('lock',16) ?> Permisos DJ (Tenga Cuidado)
                        </h4>

                        <div class="cp-info-note cp-warn" style="margin-bottom:12px;font-size:12px">
                            <?= cpIcon('alert-triangle', 14) ?>
                            No cree una cuenta de DJ con todos los permisos permitidos a menos que conf&iacute;e completamente en esa persona.
                        </div>

                        <?php foreach ($permisos as $key => $perm):
                            // Ocultar permisos de AutoDJ si no está habilitado
                            if (!$autoDJEnabled && in_array($key, $permisosAutoDJ)) continue;

                            // Valor actual
                            if ($editDJ) {
                                $val = !empty($editDJ[$key]) ? '1' : '0';
                            } else {
                                // Defaults para nuevo DJ
                                $val = in_array($key, ['perm_transmitir', 'perm_login_panel', 'perm_cambiar_pass']) ? '1' : '0';
                            }
                        ?>
                        <div class="cp-perm-row">
                            <span class="cp-perm-icon"><?= $perm['icon'] ?></span>
                            <span class="cp-perm-label"><?= $perm['label'] ?>:</span>
                            <select name="<?= $key ?>" class="<?= $val === '1' ? 'cp-yes' : 'cp-no' ?>"
                                    onchange="this.className=this.value==='1'?'cp-yes':'cp-no'">
                                <option value="1" <?= $val === '1' ? 'selected' : '' ?>>S&iacute;</option>
                                <option value="0" <?= $val === '0' ? 'selected' : '' ?>>No</option>
                            </select>
                        </div>
                        <?php endforeach; ?>

                        <!-- Botón Submit -->
                        <div style="margin-top:20px">
                            <button type="submit" class="btn btn-success" style="width:100%;padding:12px;font-size:15px;font-weight:600">
                                <?= $editDJ ? cpIcon('save',16) . ' Guardar Cambios' : cpIcon('plus',16) . ' Crear Cuenta DJ' ?>
                            </button>
                        </div>
                    </form>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Lista de DJs existentes -->
    <div>
        <div class="card">
            <div class="card-header">
                <h3><?= cpIcon('users',18) ?> Cuentas DJ Registradas</h3>
                <span class="badge"><?= $totalDJ ?> de <?= $maxDJ ?></span>
            </div>
            <div class="card-body no-padding">
                <?php if (empty($djAccounts)): ?>
                    <div class="empty-state">
                        <p style="margin-bottom:8px"><?= cpIcon('microphone',40) ?></p>
                        <p>No hay cuentas DJ creadas.</p>
                        <p style="font-size:12px;color:var(--text-secondary)">Crea una con el formulario de la izquierda.</p>
                    </div>
                <?php else: ?>
                    <?php foreach ($djAccounts as $dj): ?>
                        <div style="padding:12px 16px;border-bottom:1px solid var(--border);display:flex;align-items:center;gap:12px">
                            <!-- Avatar -->
                            <div style="width:42px;height:42px;border-radius:50%;background:<?= $dj['activo'] ? 'linear-gradient(135deg,#059669,#10b981)' : 'var(--border)' ?>;display:flex;align-items:center;justify-content:center;flex-shrink:0">
                                <span style="color:#fff"><?= cpIcon('headphones',18) ?></span>
                            </div>
                            <!-- Info -->
                            <div style="flex:1;min-width:0">
                                <div style="font-weight:600;font-size:14px"><?= htmlspecialchars($dj['username']) ?></div>
                                <div style="font-size:12px;color:var(--text-secondary)">
                                    <?= htmlspecialchars($dj['nombre'] ?: 'Sin nombre') ?>
                                    <?php if ($dj['email']): ?> &middot; <?= htmlspecialchars($dj['email']) ?><?php endif; ?>
                                </div>
                                <!-- Permisos badge -->
                                <div style="display:flex;gap:3px;margin-top:4px;flex-wrap:wrap">
                                    <?php
                                    $activePerms = 0;
                                    foreach (array_keys($permisos) as $key) {
                                        if (!$autoDJEnabled && in_array($key, $permisosAutoDJ)) continue;
                                        if (!empty($dj[$key])) $activePerms++;
                                    }
                                    $totalPermsVisible = count($permisos) - ($autoDJEnabled ? 0 : count($permisosAutoDJ));
                                    ?>
                                    <span style="font-size:10px;padding:2px 6px;border-radius:4px;background:var(--primary, #2563eb);color:#fff">
                                        <?= $activePerms ?>/<?= $totalPermsVisible ?> permisos
                                    </span>
                                    <span class="badge <?= $dj['activo'] ? 'badge-success' : 'badge-danger' ?>" style="font-size:10px">
                                        <?= $dj['activo'] ? 'Activo' : 'Inactivo' ?>
                                    </span>
                                </div>
                            </div>
                            <!-- Actions -->
                            <div style="display:flex;gap:4px;flex-shrink:0">
                                <a href="cuenta_dj.php?edit=<?= $dj['id'] ?>" class="btn btn-outline btn-sm" title="Editar"><?= cpIcon('pencil',14) ?></a>
                                <form method="POST" style="display:inline">
                                    <?= csrfField() ?>
                                    <input type="hidden" name="action" value="toggle">
                                    <input type="hidden" name="dj_id" value="<?= $dj['id'] ?>">
                                    <button class="btn btn-outline btn-sm" title="<?= $dj['activo'] ? 'Desactivar' : 'Activar' ?>">
                                        <?= $dj['activo'] ? cpIcon('pause',14) : cpIcon('play',14) ?>
                                    </button>
                                </form>
                                <form method="POST" style="display:inline" onsubmit="return confirm('&iquest;Eliminar la cuenta DJ &laquo;<?= htmlspecialchars($dj['username']) ?>&raquo;?')">
                                    <?= csrfField() ?>
                                    <input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="dj_id" value="<?= $dj['id'] ?>">
                                    <button class="btn btn-danger btn-sm" title="Eliminar"><?= cpIcon('trash',14) ?></button>
                                </form>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>

        <!-- Nota RadioCaster -->
        <div class="card" style="margin-top:16px">
            <div class="card-header">
                <h3><?= cpIcon('info',18) ?> Configuraci&oacute;n RadioCaster</h3>
            </div>
            <div class="card-body">
                <div style="background:#e3f2fd;border:1px solid #90caf9;border-radius:8px;padding:12px 16px;font-size:13px;line-height:1.7">
                    <strong>RadioCaster:</strong> <?= $radiocastNote ?>
                </div>
                <div style="margin-top:10px;padding:10px;border-radius:6px;background:var(--hover-bg, #f5f5f5);font-size:12px;line-height:1.6">
                    <?= $connNote ?>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
function cpCopy(id) {
    var input = document.getElementById(id);
    var origType = input.type;
    input.type = 'text';
    input.select();
    document.execCommand('copy');
    input.type = origType;
    // Visual feedback
    var btn = input.parentElement.querySelector('.cp-copy-btn:last-child');
    if (btn) {
        var orig = btn.innerHTML;
        btn.innerHTML = '<?= cpIcon("check",14) ?> Copiado';
        btn.style.color = '#059669';
        setTimeout(function() { btn.innerHTML = orig; btn.style.color = ''; }, 1500);
    }
}
</script>

<?php require_once __DIR__ . '/../includes/layout_footer.php'; ?>
