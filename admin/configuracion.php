<?php
/**
 * CastPanel v2.3 - Admin: Configuración Global
 */
$pageTitle = 'Configuración Global';
$pageIcon = 'gear';
$isAdmin = true;
require_once __DIR__ . '/../includes/layout_header.php';
requireRole('admin');

$db = getDB();
$msg = '';
$msgType = '';
$_tableError = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && verifyCsrf()) {
    try {
        $configs = $_POST['config'] ?? [];
        foreach ($configs as $clave => $valor) {
            $stmt = $db->prepare("INSERT INTO configuracion (clave, valor) VALUES (?, ?) ON DUPLICATE KEY UPDATE valor = VALUES(valor)");
            $stmt->execute([$clave, trim($valor)]);
        }
        $msg = 'Configuración guardada exitosamente';
        $msgType = 'success';
    } catch (PDOException $e) {
        $msg = 'Error al guardar la configuración: ' . $e->getMessage();
        $msgType = 'error';
    }
}

// Cargar configuraciones
$configs = [];
try {
    $stmt = $db->query("SELECT clave, valor FROM configuracion ORDER BY clave");
    foreach ($stmt->fetchAll() as $row) {
        $configs[$row['clave']] = $row['valor'];
    }
} catch (PDOException $e) {
    $_tableError = true;
}

// Valores por defecto — uso seguro de constantes
$liqHost = defined('LIQUIDSOAP_HOST') ? LIQUIDSOAP_HOST : (defined('LIQUIDSOAP_TELNET_HOST') ? LIQUIDSOAP_TELNET_HOST : '127.0.0.1');
$liqPort = defined('LIQUIDSOAP_PORT') ? LIQUIDSOAP_PORT : (defined('LIQUIDSOAP_TELNET_PORT') ? LIQUIDSOAP_TELNET_PORT : 1234);

$defaults = [
    'site_name' => 'CastPanel',
    'site_url' => '',
    'admin_email' => '',
    'icecast_host' => ICECAST_HOST,
    'icecast_port' => ICECAST_PORT,
    'icecast_admin_user' => 'admin',
    'icecast_admin_pass' => '',
    'liquidsoap_host' => $liqHost,
    'liquidsoap_port' => $liqPort,
    'ssl_domain' => SSL_DOMAIN,
    'ssl_enabled' => '1',
    'max_upload_size_mb' => '50',
    'allowed_extensions' => 'mp3,ogg,aac,m4a,wav,flac',
    'default_codec' => 'mp3',
    'default_bitrate' => '128',
    'default_samplerate' => '44100',
    'registro_habilitado' => '0',
    'mantenimiento' => '0',
];

foreach ($defaults as $k => $v) {
    if (!isset($configs[$k])) $configs[$k] = $v;
}
?>

<?php if ($_tableError): ?>
<div class="alert alert-warning"><?= cpIcon('warning', 16) ?> La tabla <strong>configuracion</strong> no existe. Ejecute la migración SQL v2.3 para crearla.</div>
<?php endif; ?>

<?php if ($msg): ?>
    <div class="alert alert-<?= $msgType ?>"><?= $msg ?></div>
<?php endif; ?>

<form method="POST">
    <?= csrfField() ?>

    <!-- Configuración del Sitio -->
    <div class="card">
        <div class="card-header"><h3><?= cpIcon('home', 18) ?> Configuración del Sitio</h3></div>
        <div class="card-body">
            <div class="form-grid-3">
                <div class="form-group">
                    <label>Nombre del Sitio</label>
                    <input type="text" name="config[site_name]" class="form-control" value="<?= htmlspecialchars($configs['site_name']) ?>">
                </div>
                <div class="form-group">
                    <label>URL del Sitio</label>
                    <input type="url" name="config[site_url]" class="form-control" value="<?= htmlspecialchars($configs['site_url']) ?>" placeholder="https://panel.tudominio.com">
                </div>
                <div class="form-group">
                    <label>Email Administrador</label>
                    <input type="email" name="config[admin_email]" class="form-control" value="<?= htmlspecialchars($configs['admin_email']) ?>">
                </div>
            </div>
            <div class="form-group" style="display:flex;gap:20px;padding:10px 0">
                <label><input type="checkbox" name="config[registro_habilitado]" value="1" <?= $configs['registro_habilitado'] ? 'checked' : '' ?>> Registro Público Habilitado</label>
                <label><input type="checkbox" name="config[mantenimiento]" value="1" <?= $configs['mantenimiento'] ? 'checked' : '' ?>> Modo Mantenimiento</label>
            </div>
        </div>
    </div>

    <!-- Configuración Icecast -->
    <div class="card">
        <div class="card-header"><h3><?= cpIcon('radio', 18) ?> Configuración Icecast</h3></div>
        <div class="card-body">
            <div class="form-grid-3">
                <div class="form-group">
                    <label>Host Icecast</label>
                    <input type="text" name="config[icecast_host]" class="form-control" value="<?= htmlspecialchars($configs['icecast_host']) ?>">
                </div>
                <div class="form-group">
                    <label>Puerto Icecast</label>
                    <input type="number" name="config[icecast_port]" class="form-control" value="<?= $configs['icecast_port'] ?>">
                </div>
                <div class="form-group">
                    <label>Usuario Admin</label>
                    <input type="text" name="config[icecast_admin_user]" class="form-control" value="<?= htmlspecialchars($configs['icecast_admin_user']) ?>">
                </div>
            </div>
            <div class="form-grid-3">
                <div class="form-group">
                    <label>Contraseña Admin</label>
                    <input type="password" name="config[icecast_admin_pass]" class="form-control" value="<?= htmlspecialchars($configs['icecast_admin_pass']) ?>">
                </div>
                <div class="form-group">
                    <label>Dominio SSL</label>
                    <input type="text" name="config[ssl_domain]" class="form-control" value="<?= htmlspecialchars($configs['ssl_domain']) ?>">
                </div>
                <div class="form-group" style="display:flex;align-items:end">
                    <label><input type="checkbox" name="config[ssl_enabled]" value="1" <?= $configs['ssl_enabled'] ? 'checked' : '' ?>> SSL Habilitado</label>
                </div>
            </div>
        </div>
    </div>

    <!-- Configuración Liquidsoap -->
    <div class="card">
        <div class="card-header"><h3><?= cpIcon('music', 18) ?> Configuración Liquidsoap</h3></div>
        <div class="card-body">
            <div class="form-grid-3">
                <div class="form-group">
                    <label>Host Liquidsoap</label>
                    <input type="text" name="config[liquidsoap_host]" class="form-control" value="<?= htmlspecialchars($configs['liquidsoap_host']) ?>">
                </div>
                <div class="form-group">
                    <label>Puerto Telnet</label>
                    <input type="number" name="config[liquidsoap_port]" class="form-control" value="<?= $configs['liquidsoap_port'] ?>">
                </div>
            </div>
        </div>
    </div>

    <!-- Configuración de Archivos -->
    <div class="card">
        <div class="card-header"><h3><?= cpIcon('folder', 18) ?> Configuración de Archivos</h3></div>
        <div class="card-body">
            <div class="form-grid-3">
                <div class="form-group">
                    <label>Tamaño Máx. Upload (MB)</label>
                    <input type="number" name="config[max_upload_size_mb]" class="form-control" value="<?= $configs['max_upload_size_mb'] ?>">
                </div>
                <div class="form-group">
                    <label>Extensiones Permitidas</label>
                    <input type="text" name="config[allowed_extensions]" class="form-control" value="<?= htmlspecialchars($configs['allowed_extensions']) ?>">
                    <small style="color:var(--text-secondary)">Separadas por coma</small>
                </div>
            </div>
            <div class="form-grid-3">
                <div class="form-group">
                    <label>Codec por Defecto</label>
                    <select name="config[default_codec]" class="form-control">
                        <option value="mp3" <?= $configs['default_codec'] === 'mp3' ? 'selected' : '' ?>>MP3</option>
                        <option value="aac" <?= $configs['default_codec'] === 'aac' ? 'selected' : '' ?>>AAC+</option>
                        <option value="ogg" <?= $configs['default_codec'] === 'ogg' ? 'selected' : '' ?>>OGG</option>
                    </select>
                </div>
                <div class="form-group">
                    <label>Bitrate por Defecto</label>
                    <select name="config[default_bitrate]" class="form-control">
                        <?php foreach ([64,96,128,192,256,320] as $br): ?>
                            <option value="<?= $br ?>" <?= $configs['default_bitrate'] == $br ? 'selected' : '' ?>><?= $br ?> kbps</option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label>Sample Rate por Defecto</label>
                    <select name="config[default_samplerate]" class="form-control">
                        <option value="22050" <?= $configs['default_samplerate'] == 22050 ? 'selected' : '' ?>>22050 Hz</option>
                        <option value="44100" <?= $configs['default_samplerate'] == 44100 ? 'selected' : '' ?>>44100 Hz</option>
                        <option value="48000" <?= $configs['default_samplerate'] == 48000 ? 'selected' : '' ?>>48000 Hz</option>
                    </select>
                </div>
            </div>
        </div>
    </div>

    <button type="submit" class="btn btn-primary btn-lg" style="width:100%"><?= cpIcon('save', 18) ?> Guardar Toda la Configuración</button>
</form>

<?php require_once __DIR__ . '/../includes/layout_footer.php'; ?>
