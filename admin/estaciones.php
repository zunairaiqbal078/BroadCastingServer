<?php
/**
 * CastPanel v2.6 - Admin: Gestion de Estaciones
 * Modal estilo SonicPanel con tabs: Cuenta, Administrar Radio, Playlists, AutoDJ
 */
$pageTitle = 'Gestionar Estaciones';
$pageIcon = 'radio';
$isAdmin = true;
require_once __DIR__ . '/../includes/layout_header.php';
require_once __DIR__ . '/../includes/provisioning.php';
requireRole('admin');

$db = getDB();
$msg = '';
$msgType = '';
$_tableError = false;

// Verificar si paquetes tiene server_type
$pkgHasServerType = false;
try {
    $cols = $db->query("SHOW COLUMNS FROM paquetes LIKE 'server_type'")->fetchAll();
    $pkgHasServerType = count($cols) > 0;
} catch (PDOException $e) {}

// Acciones POST
if ($_SERVER['REQUEST_METHOD'] === 'POST' && verifyCsrf()) {
    $action = $_POST['action'] ?? '';

    try {
        if ($action === 'create') {
            $nombre = trim($_POST['nombre'] ?? '');
            $paqueteId = (int)$_POST['paquete_id'];
            $icecastPassword = trim($_POST['icecast_password'] ?? 'hackme');
            $codec = $_POST['codec'] ?? 'mp3';
            $bitrate = (int)($_POST['bitrate'] ?? 128);
            $samplerate = (int)($_POST['samplerate'] ?? 44100);

            $loginUsername = trim($_POST['login_username'] ?? '');
            $loginPassword = trim($_POST['login_password'] ?? '');
            $clientEmail = trim($_POST['client_email'] ?? '');
            $sendEmail = isset($_POST['send_email']) ? true : false;

            $serverType = $_POST['server_type'] ?? 'shoutcast_v2';
            if ($pkgHasServerType && $paqueteId) {
                $stmtPkg = $db->prepare("SELECT server_type FROM paquetes WHERE id = ?");
                $stmtPkg->execute([$paqueteId]);
                $pkgRow = $stmtPkg->fetch();
                if ($pkgRow && !empty($pkgRow['server_type'])) {
                    $serverType = $pkgRow['server_type'];
                }
            }

            if (!$nombre || !$loginUsername || !$paqueteId) {
                $msg = 'Todos los campos obligatorios deben ser completados (nombre radio, usuario, paquete)';
                $msgType = 'error';
            } else {
                $result = createAccountWithStation([
                    'username'     => $loginUsername,
                    'password'     => $loginPassword,
                    'email'        => $clientEmail,
                    'nombre'       => $loginUsername,
                    'paquete_id'   => $paqueteId,
                    'station_name' => $nombre,
                    'server_type'  => $serverType,
                    'codec'        => $codec,
                    'bitrate'      => $bitrate,
                    'samplerate'   => $samplerate,
                    'source_password' => $icecastPassword,
                ]);

                if ($result['success']) {
                    $msg = "Radio creada exitosamente &mdash; Usuario: {$result['username']}, Puerto: {$result['puerto']}, Mount: {$result['mount']}";
                    $msgType = 'success';

                    if ($sendEmail && $clientEmail && function_exists('sendAccountEmail')) {
                        sendAccountEmail($clientEmail, $loginUsername, $loginPassword, $nombre, $result['puerto']);
                    }
                } else {
                    $msg = 'Error: ' . ($result['error'] ?? 'Error desconocido al crear la radio');
                    $msgType = 'error';
                }
            }
        } elseif ($action === 'toggle') {
            $id = (int)$_POST['id'];
            $db->prepare("UPDATE estaciones SET activa = NOT activa WHERE id = ?")->execute([$id]);
            $msg = 'Estado actualizado';
            $msgType = 'success';
        } elseif ($action === 'sync_proxy') {
            if (updateShoutcastProxyConfig()) {
                $msg = 'Reglas proxy de Apache sincronizadas correctamente';
                $msgType = 'success';
            } else {
                $msg = 'Error al sincronizar reglas proxy. Verificar permisos del archivo /etc/httpd/shoutcast-proxy-rules.inc';
                $msgType = 'error';
            }
        }
    } catch (PDOException $e) {
        $msg = 'Error en la operacion: ' . $e->getMessage();
        $msgType = 'error';
    }
}

// Obtener datos
$estaciones = [];
$paquetes = [];
$allListeners = [];

try {
    $estaciones = $db->query("SELECT e.*, u.username, u.nombre AS user_nombre, p.nombre AS paquete_nombre FROM estaciones e JOIN usuarios u ON u.id = e.usuario_id LEFT JOIN paquetes p ON p.id = e.paquete_id ORDER BY e.created_at DESC")->fetchAll();
} catch (PDOException $e) { $_tableError = true; }

try {
    $paquetes = $db->query("SELECT * FROM paquetes WHERE activo = 1 ORDER BY nombre")->fetchAll();
} catch (PDOException $e) {}

try {
    $allListeners = getIcecastListeners();
} catch (Exception $e) {}

// IP del servidor
$serverIp = $_SERVER['SERVER_ADDR'] ?? '0.0.0.0';
if ($serverIp === '0.0.0.0' || $serverIp === '::1') {
    $serverIp = gethostbyname(gethostname());
}

// Proximo puerto disponible
$nextPort = '---';
try {
    $nextPort = getNextAvailablePort();
} catch (Exception $e) {
    $nextPort = 'N/D';
}

// Contrasenas aleatorias
$autoPassword = substr(str_shuffle('ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789'), 0, 14);
$autoUserPassword = substr(str_shuffle('ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789'), 0, 14);

// Mapa de server_type por paquete para JavaScript
$pkgServerTypes = [];
foreach ($paquetes as $p) {
    $pkgServerTypes[$p['id']] = $p['server_type'] ?? 'shoutcast_v2';
}

$sslDomain = defined('SSL_DOMAIN') ? SSL_DOMAIN : ($_SERVER['SERVER_NAME'] ?? 'localhost');
?>

<?php if ($_tableError): ?>
<div class="alert alert-warning"><?= cpIcon('warning', 16) ?> Algunas tablas no existen o tienen columnas faltantes. Ejecute la migracion SQL v2.4.</div>
<?php endif; ?>

<?php if ($msg): ?>
    <div class="alert alert-<?= $msgType ?>"><?= $msg ?></div>
<?php endif; ?>

<?php $showForm = ($_GET['action'] ?? '') === 'new'; ?>
<?php if ($showForm): ?>
<!-- Formulario Crear Radio -->
<div class="card">
    <div class="card-header">
        <h3><?= cpIcon('plus', 18) ?> Crear Radio</h3>
    </div>
    <div class="card-body">
        <div style="background:#e8f4fd;border:1px solid #bee5f7;border-radius:8px;padding:14px 18px;margin-bottom:22px;color:#1a6fa5;font-size:13px">
            <strong><?= cpIcon('info', 14) ?> </strong>
            Complete los datos para crear una nueva radio. El puerto y URL SSL se asignan automaticamente.
        </div>

        <form method="POST" id="stationForm">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="create">

            <!-- Fila 1: Nombre + Paquete -->
            <div class="form-grid-2">
                <div class="form-group">
                    <label>Nombre de la Radio: *</label>
                    <input type="text" name="nombre" class="form-control" required placeholder="Ej: Radio Palmeras FM">
                </div>
                <div class="form-group">
                    <label>Paquete: *</label>
                    <select name="paquete_id" class="form-control" required id="pkgSelect" onchange="onPkgChange()">
                        <option value="">Seleccionar paquete...</option>
                        <?php foreach ($paquetes as $p): ?>
                            <option value="<?= $p['id'] ?>"><?= htmlspecialchars($p['nombre']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <!-- Fila 2: Usuario + Contrasena -->
            <div class="form-grid-2">
                <div class="form-group">
                    <label>Usuario de Inicio de Sesion CP: *</label>
                    <input type="text" name="login_username" class="form-control" required placeholder="Ej: radiofm" pattern="[a-zA-Z0-9_]{3,64}" title="Solo letras, numeros y guion bajo (3-64 caracteres)">
                </div>
                <div class="form-group">
                    <label>Contrasena de Inicio de Sesion CP: <span style="color:#10b981;font-weight:600">Auto-generada</span></label>
                    <input type="text" name="login_password" class="form-control" value="<?= $autoUserPassword ?>" style="font-family:monospace;font-weight:600;letter-spacing:1px">
                </div>
            </div>

            <!-- Fila 2b: Email + Enviar Correo -->
            <div class="form-grid-2">
                <div class="form-group">
                    <label>Correo Electronico del Cliente:</label>
                    <input type="email" name="client_email" class="form-control" placeholder="cliente@ejemplo.com">
                </div>
                <div class="form-group" style="display:flex;align-items:flex-end;padding-bottom:4px">
                    <label style="display:flex;align-items:center;gap:6px;cursor:pointer">
                        <input type="checkbox" name="send_email" value="1"> Enviar Correo de la Cuenta (Opcional)
                    </label>
                </div>
            </div>

            <!-- Fila 2c: Sistema de Radio -->
            <div class="form-grid-2">
                <div class="form-group">
                    <label>Sistema de Radio:</label>
                    <select name="server_type" class="form-control" id="serverTypeCreate" onchange="onSystemChange()">
                        <option value="shoutcast_v2">ShoutCast v2.6</option>
                        <option value="shoutcast_v25">ShoutCast v2.5</option>
                        <option value="shoutcast_v1">ShoutCast v1</option>
                        <option value="icecast">IceCast v2</option>
                    </select>
                    <small style="color:var(--text-secondary)" id="serverTypeNote">Se hereda del paquete seleccionado</small>
                </div>
                <div class="form-group">
                    <label>Timezone: <span style="color:#10b981;font-weight:600">Automatico</span></label>
                    <input type="text" class="form-control" value="<?= date_default_timezone_get() ?>" readonly style="background:#f0f0f0;color:#666;font-weight:500">
                </div>
            </div>

            <!-- Fila 3: Puerto + Contrasena -->
            <div class="form-grid-2">
                <div class="form-group">
                    <label>Puerto de Radio: <span style="color:#10b981;font-weight:600">Automatico</span></label>
                    <input type="text" class="form-control" id="portDisplay" value="<?= htmlspecialchars($nextPort) ?>" readonly style="background:#f0f0f0;color:#666;font-weight:600;font-size:15px">
                    <small style="color:var(--text-secondary)" id="portInfo">Puerto asignado automaticamente (admin: <?= is_numeric($nextPort) ? $nextPort + 1 : '---' ?>, harbor: <?= is_numeric($nextPort) ? $nextPort + 5 : '---' ?>)</small>
                </div>
                <div class="form-group">
                    <label>Contrasena de Radio: <span style="color:#10b981;font-weight:600">Auto-generada</span></label>
                    <input type="text" name="icecast_password" class="form-control" value="<?= $autoPassword ?>" style="font-family:monospace;font-weight:600">
                </div>
            </div>

            <!-- Fila 4: URL SSL + IP -->
            <div class="form-grid-2">
                <div class="form-group">
                    <label>URL SSL: <span style="color:#10b981;font-weight:600">Automatico</span></label>
                    <input type="text" class="form-control" id="sslUrlDisplay" value="https://<?= htmlspecialchars($sslDomain) ?>:<?= htmlspecialchars($nextPort) ?>/stream" readonly style="background:#f0f0f0;color:#666;font-weight:500">
                </div>
                <div class="form-group">
                    <label>IP del Servidor:</label>
                    <input type="text" class="form-control" value="<?= htmlspecialchars($serverIp) ?>" readonly style="background:#f0f0f0;color:#666">
                </div>
            </div>

            <!-- Fila 5: Codec + Bitrate + Sample Rate -->
            <div class="form-grid-3">
                <div class="form-group">
                    <label>Codec:</label>
                    <select name="codec" class="form-control">
                        <option value="mp3">MP3</option>
                        <option value="aac">AAC+</option>
                        <option value="ogg">OGG Vorbis</option>
                    </select>
                </div>
                <div class="form-group">
                    <label>Bitrate (kbps):</label>
                    <select name="bitrate" class="form-control">
                        <?php foreach ([64,96,128,192,256,320] as $br): ?>
                            <option value="<?= $br ?>" <?= $br == 128 ? 'selected' : '' ?>><?= $br ?> kbps</option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label>Sample Rate (Hz):</label>
                    <select name="samplerate" class="form-control">
                        <option value="22050">22050</option>
                        <option value="44100" selected>44100</option>
                        <option value="48000">48000</option>
                    </select>
                </div>
            </div>

            <script>
            var pkgServerTypes = <?= json_encode($pkgServerTypes) ?>;
            var scPort = <?= json_encode($nextPort) ?>;
            var icePort = <?= json_encode(defined('ICECAST_PORT') ? ICECAST_PORT : 8000) ?>;
            var sslDomain = <?= json_encode($sslDomain) ?>;

            function onPkgChange() {
                var sel = document.getElementById('pkgSelect');
                var pkgId = sel.value;
                var stSel = document.getElementById('serverTypeCreate');
                if (pkgId && pkgServerTypes[pkgId]) {
                    stSel.value = pkgServerTypes[pkgId];
                }
                onSystemChange();
            }

            function onSystemChange() {
                var type = document.getElementById('serverTypeCreate').value;
                var portEl = document.getElementById('portDisplay');
                var infoEl = document.getElementById('portInfo');
                var sslEl = document.getElementById('sslUrlDisplay');

                if (type === 'icecast') {
                    portEl.value = icePort;
                    infoEl.innerHTML = 'Puerto del servidor Icecast central (mount: /<em>slug</em>)';
                    sslEl.value = 'https://' + sslDomain + ':' + icePort + '/<slug>';
                } else {
                    portEl.value = scPort;
                    infoEl.innerHTML = 'Puerto asignado automaticamente (admin: ' + (parseInt(scPort)+1) + ', harbor: ' + (parseInt(scPort)+5) + ')';
                    sslEl.value = 'https://' + sslDomain + ':' + scPort + '/stream';
                }
            }
            </script>

            <div style="display:flex;gap:10px;margin-top:8px">
                <button type="submit" class="btn btn-primary">Crear Radio</button>
                <a href="<?= BASE_URL ?>/admin/estaciones.php" class="btn btn-outline">Cancelar</a>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

<?php if (!$showForm): ?>
<!-- Lista de estaciones -->
<div class="card">
    <div class="card-header">
        <h3><?= cpIcon('radio', 18) ?> Listar/Editar Radios (<?= count($estaciones) ?>)</h3>
        <div style="display:flex;gap:8px;align-items:center">
            <input type="text" placeholder="Buscar..." class="form-control" style="width:200px" id="stSearchInput">
            <form method="POST" style="display:inline">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="sync_proxy">
                <button type="submit" class="btn btn-sm btn-outline" title="Regenerar reglas proxy Apache"><?= cpIcon('refresh', 14) ?> Sync Proxy</button>
            </form>
        </div>
    </div>
    <div class="card-body no-padding">
        <div class="table-responsive">
            <table class="table" id="stations-table">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Estacion</th>
                        <th>Usuario</th>
                        <th>Paquete</th>
                        <th>Sistema</th>
                        <th>Puerto</th>
                        <th>Codec</th>
                        <th>Estado</th>
                        <th>Oyentes</th>
                        <th>Acciones</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($estaciones as $e): ?>
                        <tr>
                            <td><?= $e['id'] ?></td>
                            <td>
                                <strong><?= htmlspecialchars($e['nombre']) ?></strong>
                                <div style="font-size:11px;color:var(--text-secondary)"><?= htmlspecialchars($e['slug']) ?></div>
                            </td>
                            <td><?= htmlspecialchars($e['username']) ?></td>
                            <td><span class="badge badge-info"><?= htmlspecialchars($e['paquete_nombre'] ?? 'Sin paquete') ?></span></td>
                            <td>
                                <?php
                                    $stLabel = ['icecast' => 'IceCast v2', 'shoutcast_v1' => 'SC v1', 'shoutcast_v25' => 'SC v2.5', 'shoutcast_v2' => 'SC v2.6'];
                                    $stBadge = $e['server_type'] === 'icecast' ? 'info' : 'warning';
                                ?>
                                <span class="badge badge-<?= $stBadge ?>" style="font-size:10px"><?= $stLabel[$e['server_type']] ?? $e['server_type'] ?></span>
                            </td>
                            <td style="font-family:monospace;font-size:12px"><?= $e['icecast_port'] ?? $e['puerto_stream'] ?? '---' ?></td>
                            <td><?= strtoupper($e['codec']) ?> <?= $e['bitrate'] ?>k</td>
                            <td>
                                <?php if ($e['activa']): ?>
                                    <?php $adjEstado = $e['autodj_estado'] ?? 'offline'; ?>
                                    <?= $adjEstado === 'online'
                                        ? '<span class="badge badge-success">Online</span>'
                                        : '<span class="badge badge-warning">Offline</span>' ?>
                                <?php else: ?>
                                    <span class="badge badge-danger">Inactiva</span>
                                <?php endif; ?>
                            </td>
                            <td><?php
                                if ($e['server_type'] === 'icecast') {
                                    echo $allListeners[$e['icecast_mount']]['oyentes'] ?? 0;
                                } else {
                                    try {
                                        $sc = getStreamStatus($e);
                                        echo $sc['oyentes'];
                                    } catch (Exception $ex) { echo 0; }
                                }
                            ?></td>
                            <td>
                                <div style="display:flex;gap:5px;flex-wrap:wrap">
                                    <button type="button" class="btn btn-sm btn-outline" onclick="openStationModal(<?= $e['id'] ?>)"><?= cpIcon('pencil', 14) ?> Editar</button>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- ============================================================
     MODAL SONICPANEL-STYLE: Editar/Actualizar Radios
     ============================================================ -->
<div class="modal" id="stationModal">
    <div class="modal-content" style="max-width:800px;width:95%">
        <div class="modal-header" style="background:var(--sidebar-bg);color:#fff;border-radius:var(--radius) var(--radius) 0 0">
            <h3 id="modalTitle" style="color:#fff;font-size:15px"><?= cpIcon('radio', 16) ?> Editar/Actualizar Radios</h3>
            <button class="modal-close" onclick="closeStationModal()" style="color:#fff;font-size:22px">&times;</button>
        </div>

        <!-- Tabs -->
        <div class="sp-tabs">
            <button class="sp-tab active" data-tab="tab-cuenta" onclick="switchTab(this)">Cuenta</button>
            <button class="sp-tab" data-tab="tab-radio" onclick="switchTab(this)">Administrar Radio</button>
            <button class="sp-tab" data-tab="tab-playlists" onclick="switchTab(this)">Listas de Reproduccion</button>
            <button class="sp-tab" data-tab="tab-autodj" onclick="switchTab(this)">Administrar AutoDJ</button>
        </div>

        <div class="modal-body" id="modalBody" style="padding:0">
            <!-- Loading spinner -->
            <div id="modalLoading" style="display:flex;align-items:center;justify-content:center;padding:60px;gap:10px">
                <div class="sp-spinner"></div>
                <span style="color:var(--text-secondary)">Cargando datos...</span>
            </div>

            <!-- Tab: Cuenta -->
            <div class="sp-tab-content active" id="tab-cuenta" style="display:none">
                <div style="padding:20px">
                    <div class="form-grid-2">
                        <div class="form-group">
                            <label>Nombre de Usuario</label>
                            <input type="text" class="form-control" id="md_username" readonly style="background:#f0f0f0">
                        </div>
                        <div class="form-group">
                            <label>Contrasena</label>
                            <input type="text" class="form-control" id="md_user_password" value="********" readonly style="background:#f0f0f0">
                        </div>
                    </div>
                    <div class="form-grid-2">
                        <div class="form-group">
                            <label>Email</label>
                            <input type="text" class="form-control" id="md_user_email" readonly style="background:#f0f0f0">
                        </div>
                        <div class="form-group">
                            <label>Propietario</label>
                            <input type="text" class="form-control" id="md_user_nombre" readonly style="background:#f0f0f0">
                        </div>
                    </div>
                    <div class="form-grid-2">
                        <div class="form-group">
                            <label>Dominio</label>
                            <input type="text" class="form-control" id="md_domain" value="<?= htmlspecialchars($sslDomain) ?>" readonly style="background:#f0f0f0">
                        </div>
                        <div class="form-group">
                            <label>Timezone</label>
                            <input type="text" class="form-control" value="<?= date_default_timezone_get() ?>" readonly style="background:#f0f0f0">
                        </div>
                    </div>
                    <div style="margin-top:10px">
                        <a href="#" id="md_resend_email" onclick="resendEmail();return false" style="color:var(--primary);font-size:13px;text-decoration:none"><?= cpIcon('mail', 14) ?> Reenviar Correo de Bienvenida</a>
                    </div>
                </div>
            </div>

            <!-- Tab: Administrar Radio -->
            <div class="sp-tab-content" id="tab-radio" style="display:none">
                <div style="padding:20px">
                    <div class="form-grid-2">
                        <div class="form-group">
                            <label>Sistema de Radio</label>
                            <select class="form-control" id="md_server_type">
                                <option value="shoutcast_v2">ShoutCast v2.6</option>
                                <option value="shoutcast_v25">ShoutCast v2.5</option>
                                <option value="shoutcast_v1">ShoutCast v1</option>
                                <option value="icecast">IceCast v2</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label>Paquete</label>
                            <select class="form-control" id="md_paquete_id">
                                <?php foreach ($paquetes as $p): ?>
                                    <option value="<?= $p['id'] ?>"><?= htmlspecialchars($p['nombre']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    <div class="form-grid-2">
                        <div class="form-group">
                            <label>Contrasena de Radio</label>
                            <input type="text" class="form-control" id="md_icecast_password">
                        </div>
                        <div class="form-group">
                            <label>Puerto</label>
                            <input type="text" class="form-control" id="md_puerto" readonly style="background:#f0f0f0">
                        </div>
                    </div>
                    <div class="form-grid-2">
                        <div class="form-group">
                            <label>Limite de Oyentes</label>
                            <input type="text" class="form-control" id="md_max_oyentes" readonly style="background:#f0f0f0">
                        </div>
                        <div class="form-group">
                            <label>Max Bitrate</label>
                            <input type="text" class="form-control" id="md_max_bitrate" readonly style="background:#f0f0f0">
                        </div>
                    </div>
                    <div class="form-grid-2">
                        <div class="form-group">
                            <label>Almacenamiento</label>
                            <input type="text" class="form-control" id="md_max_storage" readonly style="background:#f0f0f0">
                        </div>
                        <div class="form-group">
                            <label>IP del Servidor</label>
                            <input type="text" class="form-control" id="md_server_ip" readonly style="background:#f0f0f0">
                        </div>
                    </div>
                    <div class="form-grid-2">
                        <div class="form-group">
                            <label>Contrasena de Admin</label>
                            <input type="text" class="form-control" id="md_admin_password" readonly style="background:#f0f0f0" value="(mismo que contrasena de radio)">
                        </div>
                        <div class="form-group">
                            <label>URL SSL</label>
                            <input type="text" class="form-control" id="md_ssl_url" readonly style="background:#f0f0f0">
                        </div>
                    </div>
                    <div class="form-grid-2">
                        <div class="form-group">
                            <label>Puerto SSL</label>
                            <input type="text" class="form-control" value="443" readonly style="background:#f0f0f0">
                        </div>
                        <div class="form-group">
                            <label>Codec / Bitrate / Sample Rate</label>
                            <div style="display:flex;gap:6px">
                                <select class="form-control" id="md_codec" style="flex:1">
                                    <option value="mp3">MP3</option>
                                    <option value="aac">AAC+</option>
                                    <option value="ogg">OGG</option>
                                </select>
                                <select class="form-control" id="md_bitrate" style="flex:1">
                                    <option value="64">64k</option>
                                    <option value="96">96k</option>
                                    <option value="128">128k</option>
                                    <option value="192">192k</option>
                                    <option value="256">256k</option>
                                    <option value="320">320k</option>
                                </select>
                                <select class="form-control" id="md_samplerate" style="flex:1">
                                    <option value="22050">22050</option>
                                    <option value="44100">44100</option>
                                    <option value="48000">48000</option>
                                </select>
                            </div>
                        </div>
                    </div>
                    <div style="margin-top:12px;text-align:right">
                        <button class="btn btn-primary btn-sm" onclick="saveRadioConfig()"><?= cpIcon('check', 14) ?> Guardar Cambios</button>
                    </div>
                </div>
            </div>

            <!-- Tab: Listas de Reproduccion -->
            <div class="sp-tab-content" id="tab-playlists" style="display:none">
                <div style="padding:20px">
                    <div class="alert alert-info" style="margin-bottom:16px">
                        <?= cpIcon('info', 14) ?> Las listas de reproduccion se administran desde el panel del usuario. Use "Iniciar Sesion en Usuario" para acceder.
                    </div>
                    <div id="md_playlists_list"></div>
                    <div style="margin-top:12px">
                        <button class="btn btn-sm btn-info" onclick="loginAsUser()"><?= cpIcon('log-in', 14) ?> Iniciar Sesion como Usuario</button>
                    </div>
                </div>
            </div>

            <!-- Tab: Administrar AutoDJ -->
            <div class="sp-tab-content" id="tab-autodj" style="display:none">
                <div style="padding:20px">
                    <!-- Botones Start/Stop/Restart -->
                    <div style="display:flex;gap:8px;margin-bottom:18px;flex-wrap:wrap">
                        <button class="btn btn-sm btn-success" onclick="stationAction('start')"><?= cpIcon('play', 14) ?> Iniciar</button>
                        <button class="btn btn-sm btn-danger" onclick="stationAction('stop')"><?= cpIcon('square', 14) ?> Detener</button>
                        <button class="btn btn-sm btn-warning" onclick="stationAction('restart')"><?= cpIcon('refresh', 14) ?> Reiniciar</button>
                        <span class="badge" id="md_autodj_badge" style="display:inline-flex;align-items:center;padding:6px 14px;font-size:12px">--</span>
                    </div>

                    <div class="form-grid-2">
                        <div class="form-group">
                            <label>Playlist Activa</label>
                            <select class="form-control" id="md_autodj_playlist"></select>
                        </div>
                        <div class="form-group">
                            <label>Encoder</label>
                            <input type="text" class="form-control" id="md_autodj_encoder" readonly style="background:#f0f0f0">
                        </div>
                    </div>
                    <div class="form-grid-2">
                        <div class="form-group">
                            <label>Titulo de Radio</label>
                            <input type="text" class="form-control" id="md_titulo_radio">
                        </div>
                        <div class="form-group">
                            <label>URL de la Radio</label>
                            <input type="text" class="form-control" id="md_url_web">
                        </div>
                    </div>
                    <div class="form-grid-2">
                        <div class="form-group">
                            <label>Modo de Reproduccion</label>
                            <select class="form-control" id="md_modo_reproduccion">
                                <option value="normal">Normal (Secuencial)</option>
                                <option value="random">Aleatorio</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label>Crossfade (segundos)</label>
                            <input type="number" class="form-control" id="md_crossfade" min="0" max="15">
                        </div>
                    </div>
                    <div class="form-grid-2">
                        <div class="form-group">
                            <label>Bitrate</label>
                            <input type="text" class="form-control" id="md_autodj_bitrate" readonly style="background:#f0f0f0">
                        </div>
                        <div class="form-group">
                            <label>Genero</label>
                            <input type="text" class="form-control" id="md_genero">
                        </div>
                    </div>
                    <div class="form-grid-2">
                        <div class="form-group">
                            <label>Puerto DJ</label>
                            <input type="text" class="form-control" id="md_harbor_port" readonly style="background:#f0f0f0">
                        </div>
                        <div class="form-group">
                            <label>Buffer DJ Delay (seg)</label>
                            <input type="number" class="form-control" id="md_buffer_delay" min="0" max="30">
                        </div>
                    </div>
                    <div class="form-grid-2">
                        <div class="form-group">
                            <label>
                                <input type="checkbox" id="md_mostrar_titulos"> Mostrar Titulos
                            </label>
                        </div>
                        <div class="form-group">
                            <label>
                                <input type="checkbox" id="md_grabar_dj"> Grabar DJ
                            </label>
                        </div>
                    </div>
                    <div class="form-grid-2">
                        <div class="form-group">
                            <label>Calidad de Grabacion</label>
                            <select class="form-control" id="md_calidad_grabacion">
                                <option value="alta">Alta</option>
                                <option value="media">Media</option>
                                <option value="baja">Baja</option>
                            </select>
                        </div>
                        <div class="form-group"></div>
                    </div>

                    <div style="margin-top:12px;text-align:right">
                        <button class="btn btn-primary btn-sm" onclick="saveAutoDJConfig()"><?= cpIcon('check', 14) ?> Guardar Cambios AutoDJ</button>
                    </div>
                </div>
            </div>
        </div>

        <!-- Botones de accion (estilo SonicPanel) -->
        <div class="modal-footer" style="flex-wrap:wrap;justify-content:center;gap:6px;padding:14px 20px;background:#f8f9fa;border-radius:0 0 var(--radius) var(--radius)">
            <button class="btn btn-sm btn-warning" onclick="stationAction('suspend')" id="md_btn_suspend"><?= cpIcon('pause', 14) ?> Suspender</button>
            <button class="btn btn-sm btn-success" onclick="stationAction('reactivate')" id="md_btn_reactivate"><?= cpIcon('play', 14) ?> Reactivar</button>
            <button class="btn btn-sm btn-info" onclick="openRadioPage()"><?= cpIcon('external-link', 14) ?> Pagina de Radio</button>
            <button class="btn btn-sm btn-info" onclick="loginAsUser()"><?= cpIcon('log-in', 14) ?> Iniciar Sesion en Usuario</button>
            <button class="btn btn-sm btn-danger" onclick="deleteStation()"><?= cpIcon('trash', 14) ?> Terminar</button>
        </div>
    </div>
</div>

<script>
// ============================================================
// CastPanel v2.6 - Modal SonicPanel-style JavaScript
// ============================================================
var currentStationId = 0;
var currentStationData = null;
var csrfToken = <?= json_encode(csrfToken()) ?>;
var baseUrl = <?= json_encode(BASE_URL) ?>;

// --- Busqueda en tabla ---
(function(){
    var inp = document.getElementById('stSearchInput');
    if (!inp) return;
    inp.addEventListener('input', function(){
        var filter = this.value.toLowerCase();
        var rows = document.querySelectorAll('#stations-table tbody tr');
        rows.forEach(function(row){
            row.style.display = row.textContent.toLowerCase().indexOf(filter) >= 0 ? '' : 'none';
        });
    });
})();

// --- Tabs ---
function switchTab(btn) {
    document.querySelectorAll('.sp-tab').forEach(function(t){ t.classList.remove('active'); });
    document.querySelectorAll('.sp-tab-content').forEach(function(c){ c.style.display = 'none'; });
    btn.classList.add('active');
    var target = btn.getAttribute('data-tab');
    document.getElementById(target).style.display = 'block';
}

// --- Modal open/close ---
function openStationModal(id) {
    currentStationId = id;
    var modal = document.getElementById('stationModal');
    var loading = document.getElementById('modalLoading');

    // Reset tabs
    document.querySelectorAll('.sp-tab').forEach(function(t, i){ t.classList.toggle('active', i === 0); });
    document.querySelectorAll('.sp-tab-content').forEach(function(c){ c.style.display = 'none'; });
    loading.style.display = 'flex';

    modal.classList.add('show');

    // Fetch data
    fetch(baseUrl + '/admin/ajax/station_data.php?id=' + id)
        .then(function(r){ return r.json(); })
        .then(function(data){
            if (!data.success) {
                loading.innerHTML = '<span style="color:var(--danger)">Error: ' + (data.error || 'Desconocido') + '</span>';
                return;
            }
            currentStationData = data;
            populateModal(data);
            loading.style.display = 'none';
            document.getElementById('tab-cuenta').style.display = 'block';
        })
        .catch(function(err){
            loading.innerHTML = '<span style="color:var(--danger)">Error de conexion</span>';
        });
}

function closeStationModal() {
    document.getElementById('stationModal').classList.remove('show');
    currentStationId = 0;
    currentStationData = null;
}

// Click outside to close
document.getElementById('stationModal').addEventListener('click', function(e){
    if (e.target === this) closeStationModal();
});

// --- Populate modal fields ---
function populateModal(data) {
    var s = data.station;
    var u = data.user;
    var p = data.paquete;

    // Title
    document.getElementById('modalTitle').innerHTML = '&#x1F4FB; Editar/Actualizar Radios -- ' + u.username;

    // Tab Cuenta
    document.getElementById('md_username').value = u.username;
    document.getElementById('md_user_email').value = u.email || '(sin email)';
    document.getElementById('md_user_nombre').value = u.nombre || u.username;

    // Tab Radio
    document.getElementById('md_server_type').value = s.server_type;
    document.getElementById('md_paquete_id').value = s.paquete_id;
    document.getElementById('md_icecast_password').value = s.icecast_password;
    document.getElementById('md_puerto').value = s.puerto_stream || s.icecast_port;
    document.getElementById('md_max_oyentes').value = p.max_oyentes + ' oyentes';
    document.getElementById('md_max_bitrate').value = p.max_bitrate + ' kbps';
    document.getElementById('md_max_storage').value = p.max_storage_mb + ' MB';
    document.getElementById('md_ssl_url').value = data.ssl_url;
    document.getElementById('md_server_ip').value = data.server_ip;
    document.getElementById('md_codec').value = s.codec;
    document.getElementById('md_bitrate').value = s.bitrate;
    document.getElementById('md_samplerate').value = s.samplerate;

    // Tab AutoDJ
    var statusBadge = document.getElementById('md_autodj_badge');
    if (s.autodj_estado === 'online' || s.servicio_estado === 'running') {
        statusBadge.className = 'badge badge-success';
        statusBadge.textContent = 'ONLINE';
    } else {
        statusBadge.className = 'badge badge-danger';
        statusBadge.textContent = 'OFFLINE';
    }

    // Playlists dropdown
    var plSel = document.getElementById('md_autodj_playlist');
    plSel.innerHTML = '<option value="">(Sin playlist)</option>';
    (data.playlists || []).forEach(function(pl){
        var opt = document.createElement('option');
        opt.value = pl.slug || pl.nombre;
        opt.textContent = pl.nombre + ' (' + pl.total_canciones + ' canciones)';
        if (s.autodj_playlist_activa === (pl.slug || pl.nombre)) opt.selected = true;
        plSel.appendChild(opt);
    });

    // Playlists list in tab
    var plList = document.getElementById('md_playlists_list');
    if (data.playlists && data.playlists.length > 0) {
        var html = '<table class="table" style="font-size:13px"><thead><tr><th>Nombre</th><th>Canciones</th><th>Estado</th></tr></thead><tbody>';
        data.playlists.forEach(function(pl){
            html += '<tr><td>' + pl.nombre + '</td><td>' + pl.total_canciones + '</td><td>' +
                (pl.activa == 1 ? '<span class="badge badge-success">Activa</span>' : '<span class="badge badge-danger">Inactiva</span>') +
                '</td></tr>';
        });
        html += '</tbody></table>';
        plList.innerHTML = html;
    } else {
        plList.innerHTML = '<p style="color:var(--text-secondary);font-size:13px">No hay listas de reproduccion creadas.</p>';
    }

    document.getElementById('md_autodj_encoder').value = s.codec.toUpperCase() + ' ' + s.bitrate + 'kbps';
    document.getElementById('md_titulo_radio').value = s.titulo_radio || s.nombre;
    document.getElementById('md_url_web').value = s.url_web || '';
    document.getElementById('md_modo_reproduccion').value = s.modo_reproduccion || 'normal';
    document.getElementById('md_crossfade').value = s.crossfade_seconds;
    document.getElementById('md_autodj_bitrate').value = s.bitrate + ' kbps';
    document.getElementById('md_genero').value = s.genero || '';
    document.getElementById('md_harbor_port').value = data.harbor_port;
    document.getElementById('md_buffer_delay').value = s.buffer_dj_delay;
    document.getElementById('md_mostrar_titulos').checked = s.mostrar_titulos == 1;
    document.getElementById('md_grabar_dj').checked = s.grabar_dj == 1;
    document.getElementById('md_calidad_grabacion').value = s.calidad_grabacion || 'alta';

    // Suspend/Reactivate button visibility
    document.getElementById('md_btn_suspend').style.display = s.activa ? '' : 'none';
    document.getElementById('md_btn_reactivate').style.display = s.activa ? 'none' : '';
}

// --- Actions ---
function stationAction(action) {
    if (action === 'suspend' && !confirm('Seguro que desea SUSPENDER esta estacion?')) return;
    if (action === 'delete' && !confirm('PELIGRO: Esto eliminara la estacion y todos sus datos. Continuar?')) return;

    var body = JSON.stringify({
        action: action,
        station_id: currentStationId,
        csrf_token: csrfToken
    });

    fetch(baseUrl + '/admin/ajax/station_action.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: body
    })
    .then(function(r){ return r.json(); })
    .then(function(data){
        if (data.success) {
            showToast(data.message || 'Accion completada', 'success');
            if (action === 'delete') {
                closeStationModal();
                setTimeout(function(){ location.reload(); }, 800);
            } else {
                // Refresh modal data
                openStationModal(currentStationId);
                if (action === 'suspend' || action === 'reactivate') {
                    setTimeout(function(){ location.reload(); }, 1000);
                }
            }
        } else {
            showToast(data.error || 'Error en la accion', 'error');
        }
    })
    .catch(function(){
        showToast('Error de conexion', 'error');
    });
}

function saveRadioConfig() {
    var body = JSON.stringify({
        action: 'save',
        station_id: currentStationId,
        csrf_token: csrfToken,
        server_type: document.getElementById('md_server_type').value,
        paquete_id: document.getElementById('md_paquete_id').value,
        icecast_password: document.getElementById('md_icecast_password').value,
        codec: document.getElementById('md_codec').value,
        bitrate: document.getElementById('md_bitrate').value,
        samplerate: document.getElementById('md_samplerate').value
    });

    fetch(baseUrl + '/admin/ajax/station_action.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: body
    })
    .then(function(r){ return r.json(); })
    .then(function(data){
        showToast(data.success ? (data.message || 'Guardado') : (data.error || 'Error'), data.success ? 'success' : 'error');
    })
    .catch(function(){
        showToast('Error de conexion', 'error');
    });
}

function saveAutoDJConfig() {
    var body = JSON.stringify({
        action: 'save_autodj',
        station_id: currentStationId,
        csrf_token: csrfToken,
        modo_reproduccion: document.getElementById('md_modo_reproduccion').value,
        crossfade_seconds: document.getElementById('md_crossfade').value,
        mostrar_titulos: document.getElementById('md_mostrar_titulos').checked ? 1 : 0,
        grabar_dj: document.getElementById('md_grabar_dj').checked ? 1 : 0,
        calidad_grabacion: document.getElementById('md_calidad_grabacion').value,
        buffer_dj_delay: document.getElementById('md_buffer_delay').value,
        titulo_radio: document.getElementById('md_titulo_radio').value,
        genero: document.getElementById('md_genero').value,
        url_web: document.getElementById('md_url_web').value,
        autodj_playlist_activa: document.getElementById('md_autodj_playlist').value
    });

    fetch(baseUrl + '/admin/ajax/station_action.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: body
    })
    .then(function(r){ return r.json(); })
    .then(function(data){
        showToast(data.success ? (data.message || 'Guardado') : (data.error || 'Error'), data.success ? 'success' : 'error');
    })
    .catch(function(){
        showToast('Error de conexion', 'error');
    });
}

function deleteStation() {
    if (!confirm('PELIGRO: Esto eliminara permanentemente la estacion, todos sus archivos y configuracion. Esta accion NO se puede deshacer.\n\nEscribe ELIMINAR para confirmar:')) return;
    stationAction('delete');
}

function openRadioPage() {
    if (currentStationData) {
        var s = currentStationData.station;
        var url = baseUrl + '/widgets/player/?p=' + (s.puerto_stream || s.icecast_port);
        window.open(url, '_blank');
    }
}

function loginAsUser() {
    if (currentStationData) {
        // Login as user functionality - redirect to client panel
        var userId = currentStationData.station.usuario_id;
        showToast('Redirigiendo al panel del usuario...', 'success');
        // For now, open user panel in new tab
        window.open(baseUrl + '/cliente/?login_as=' + userId, '_blank');
    }
}

function resendEmail() {
    showToast('Funcionalidad de reenvio de correo pendiente de implementar', 'info');
}
</script>

<?php require_once __DIR__ . '/../includes/layout_footer.php'; ?>
