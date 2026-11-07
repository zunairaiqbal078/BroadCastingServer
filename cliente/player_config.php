<?php
/**
 * CastPanel v2.3 - Personalización del Reproductor Público
 * Permite al cliente configurar: imagen de fondo, portada, efecto visual, colores, redes sociales
 */
$pageTitle = 'Personalizar Reproductor';
$pageIcon = 'palette';
require_once __DIR__ . '/../includes/layout_header.php';

if (!$estacion) {
    echo '<div class="alert alert-warning">Sin estación asignada.</div>';
    require_once __DIR__ . '/../includes/layout_footer.php';
    exit;
}

$db = getDB();
$stId = $estacion['id'];
$uploadDir = __DIR__ . '/../uploads/player/' . $stId . '/';
$uploadUrl = BASE_URL . '/uploads/player/' . $stId . '/';
$_tableError = false;

// Crear directorio si no existe
if (!is_dir($uploadDir)) {
    mkdir($uploadDir, 0755, true);
}

// Crear tabla player_config si no existe (autocreación defensiva)
try {
    $db->exec("CREATE TABLE IF NOT EXISTS player_config (
        id INT AUTO_INCREMENT PRIMARY KEY,
        estacion_id INT NOT NULL,
        bg_image VARCHAR(500) DEFAULT NULL,
        bg_overlay_opacity DECIMAL(3,2) DEFAULT 0.60,
        bg_blur INT DEFAULT 0,
        cover_image VARCHAR(500) DEFAULT NULL,
        effect_type ENUM('rain','stars','wave','equalizer','snow','none') DEFAULT 'rain',
        accent_color VARCHAR(7) DEFAULT '#6c3ce0',
        text_color VARCHAR(7) DEFAULT '#ffffff',
        social_facebook VARCHAR(255) DEFAULT NULL,
        social_instagram VARCHAR(255) DEFAULT NULL,
        social_twitter VARCHAR(255) DEFAULT NULL,
        social_youtube VARCHAR(255) DEFAULT NULL,
        social_website VARCHAR(255) DEFAULT NULL,
        show_history TINYINT(1) DEFAULT 1,
        show_listeners TINYINT(1) DEFAULT 1,
        show_eq_bars TINYINT(1) DEFAULT 1,
        custom_css TEXT DEFAULT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uq_estacion (estacion_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
} catch (PDOException $e) {
    // Tabla ya existe o error menor, continuar
}

// Obtener config actual
$config = null;
try {
    $stmt = $db->prepare("SELECT * FROM player_config WHERE estacion_id = ?");
    $stmt->execute([$stId]);
    $config = $stmt->fetch();

    if (!$config) {
        // Crear registro por defecto
        $db->prepare("INSERT INTO player_config (estacion_id) VALUES (?)")->execute([$stId]);
        $stmt->execute([$stId]);
        $config = $stmt->fetch();
    }
} catch (PDOException $e) {
    $_tableError = true;
    $config = [
        'bg_image' => null, 'bg_overlay_opacity' => 0.6, 'bg_blur' => 0,
        'cover_image' => null, 'effect_type' => 'rain',
        'accent_color' => '#6c3ce0', 'text_color' => '#ffffff',
        'social_facebook' => '', 'social_instagram' => '', 'social_twitter' => '',
        'social_youtube' => '', 'social_website' => '',
        'show_history' => 1, 'show_listeners' => 1, 'show_eq_bars' => 1
    ];
}

$msg = '';
$msgType = 'success';

// Procesar formulario
if (!$_tableError && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['csrf_token'])) {
    if (!hash_equals($_SESSION['csrf_token'] ?? '', $_POST['csrf_token'])) {
        $msg = 'Token de seguridad inválido';
        $msgType = 'danger';
    } else {
        try {
            $action = $_POST['action'] ?? 'save';

            // Subir imagen de fondo
            if (!empty($_FILES['bg_image']['name']) && $_FILES['bg_image']['error'] === UPLOAD_ERR_OK) {
                $allowed = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];
                $finfo = finfo_open(FILEINFO_MIME_TYPE);
                $mime = finfo_file($finfo, $_FILES['bg_image']['tmp_name']);
                finfo_close($finfo);

                if (in_array($mime, $allowed)) {
                    $ext = pathinfo($_FILES['bg_image']['name'], PATHINFO_EXTENSION);
                    $bgFile = 'background_' . time() . '.' . strtolower($ext);
                    if ($config['bg_image'] && file_exists($uploadDir . basename($config['bg_image']))) {
                        unlink($uploadDir . basename($config['bg_image']));
                    }
                    move_uploaded_file($_FILES['bg_image']['tmp_name'], $uploadDir . $bgFile);
                    $db->prepare("UPDATE player_config SET bg_image = ? WHERE estacion_id = ?")->execute([$uploadUrl . $bgFile, $stId]);
                } else {
                    $msg = 'Formato de imagen de fondo no válido. Use JPG, PNG, WebP o GIF.';
                    $msgType = 'danger';
                }
            }

            // Subir portada
            if (!empty($_FILES['cover_image']['name']) && $_FILES['cover_image']['error'] === UPLOAD_ERR_OK) {
                $allowed = ['image/jpeg', 'image/png', 'image/webp'];
                $finfo = finfo_open(FILEINFO_MIME_TYPE);
                $mime = finfo_file($finfo, $_FILES['cover_image']['tmp_name']);
                finfo_close($finfo);

                if (in_array($mime, $allowed)) {
                    $ext = pathinfo($_FILES['cover_image']['name'], PATHINFO_EXTENSION);
                    $coverFile = 'cover_' . time() . '.' . strtolower($ext);
                    if ($config['cover_image'] && file_exists($uploadDir . basename($config['cover_image']))) {
                        unlink($uploadDir . basename($config['cover_image']));
                    }
                    move_uploaded_file($_FILES['cover_image']['tmp_name'], $uploadDir . $coverFile);
                    $db->prepare("UPDATE player_config SET cover_image = ? WHERE estacion_id = ?")->execute([$uploadUrl . $coverFile, $stId]);
                } else {
                    $msg = 'Formato de portada no válido. Use JPG, PNG o WebP.';
                    $msgType = 'danger';
                }
            }

            // Guardar configuración general
            if ($action === 'save' && empty($msg)) {
                $effect = $_POST['effect_type'] ?? 'rain';
                $validEffects = ['rain', 'stars', 'wave', 'equalizer', 'snow', 'none'];
                if (!in_array($effect, $validEffects)) $effect = 'rain';

                $accent = preg_match('/^#[0-9a-fA-F]{6}$/', $_POST['accent_color'] ?? '') ? $_POST['accent_color'] : '#6c3ce0';
                $textColor = preg_match('/^#[0-9a-fA-F]{6}$/', $_POST['text_color'] ?? '') ? $_POST['text_color'] : '#ffffff';
                $overlayOp = max(0, min(1, floatval($_POST['bg_overlay_opacity'] ?? 0.6)));
                $bgBlur = max(0, min(20, intval($_POST['bg_blur'] ?? 0)));

                $showHistory = isset($_POST['show_history']) ? 1 : 0;
                $showListeners = isset($_POST['show_listeners']) ? 1 : 0;
                $showEq = isset($_POST['show_eq_bars']) ? 1 : 0;

                $socialFb = trim($_POST['social_facebook'] ?? '');
                $socialIg = trim($_POST['social_instagram'] ?? '');
                $socialTw = trim($_POST['social_twitter'] ?? '');
                $socialYt = trim($_POST['social_youtube'] ?? '');
                $socialWeb = trim($_POST['social_website'] ?? '');

                $db->prepare("UPDATE player_config SET
                    effect_type = ?, accent_color = ?, text_color = ?,
                    bg_overlay_opacity = ?, bg_blur = ?,
                    show_history = ?, show_listeners = ?, show_eq_bars = ?,
                    social_facebook = ?, social_instagram = ?, social_twitter = ?,
                    social_youtube = ?, social_website = ?
                    WHERE estacion_id = ?
                ")->execute([
                    $effect, $accent, $textColor,
                    $overlayOp, $bgBlur,
                    $showHistory, $showListeners, $showEq,
                    $socialFb ?: null, $socialIg ?: null, $socialTw ?: null,
                    $socialYt ?: null, $socialWeb ?: null,
                    $stId
                ]);

                $msg = 'Configuración del reproductor guardada correctamente.';
            }

            // Eliminar fondo
            if ($action === 'delete_bg') {
                if ($config['bg_image'] && file_exists($uploadDir . basename($config['bg_image']))) {
                    unlink($uploadDir . basename($config['bg_image']));
                }
                $db->prepare("UPDATE player_config SET bg_image = NULL WHERE estacion_id = ?")->execute([$stId]);
                $msg = 'Imagen de fondo eliminada.';
            }

            // Eliminar portada
            if ($action === 'delete_cover') {
                if ($config['cover_image'] && file_exists($uploadDir . basename($config['cover_image']))) {
                    unlink($uploadDir . basename($config['cover_image']));
                }
                $db->prepare("UPDATE player_config SET cover_image = NULL WHERE estacion_id = ?")->execute([$stId]);
                $msg = 'Portada eliminada.';
            }

            // Refrescar config
            $stmt->execute([$stId]);
            $config = $stmt->fetch();
        } catch (PDOException $e) {
            $msg = 'Error de base de datos: ' . $e->getMessage();
            $msgType = 'danger';
        }
    }
}

// Generar CSRF
if (empty($_SESSION['csrf_token'])) $_SESSION['csrf_token'] = bin2hex(random_bytes(32));

$playerUrl = (isset($_SERVER['HTTPS']) ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? SSL_DOMAIN) . BASE_URL . '/widgets/player/?p=' . (int)$estacion['puerto_stream'] . '&type=' . urlencode($config['effect_type'] ?? 'rain');
?>

<?php if ($_tableError): ?>
<div class="alert alert-warning"><?= cpIcon('warning',14) ?> La tabla <code>player_config</code> no se pudo crear. Verifique los permisos de la base de datos.</div>
<?php endif; ?>

<?php if ($msg): ?>
<div class="alert alert-<?= $msgType ?>"><?= htmlspecialchars($msg) ?></div>
<?php endif; ?>

<style>
.config-grid{display:grid;grid-template-columns:1fr 1fr;gap:20px}
@media(max-width:768px){.config-grid{grid-template-columns:1fr}}
.img-upload-zone{
    border:2px dashed rgba(108,60,224,.3);
    border-radius:16px;padding:24px;text-align:center;
    background:rgba(108,60,224,.04);transition:all .2s;cursor:pointer;
    position:relative;overflow:hidden
}
.img-upload-zone:hover{border-color:var(--primary);background:rgba(108,60,224,.08)}
.img-upload-zone input[type=file]{
    position:absolute;inset:0;opacity:0;cursor:pointer
}
.img-upload-zone .upload-icon{font-size:40px;margin-bottom:8px;opacity:.5}
.img-upload-zone .upload-text{font-size:13px;color:var(--text-secondary)}
.img-upload-zone .upload-hint{font-size:11px;color:var(--text-secondary);opacity:.6;margin-top:4px}
.img-preview{
    width:100%;max-height:200px;object-fit:cover;border-radius:12px;
    margin-top:12px;border:1px solid rgba(255,255,255,.06)
}
.effect-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(130px,1fr));gap:10px}
.effect-card{
    background:var(--card-bg);border:2px solid var(--border-color);
    border-radius:12px;padding:12px;cursor:pointer;transition:all .2s;text-align:center
}
.effect-card:hover{border-color:var(--primary);transform:translateY(-2px)}
.effect-card.active{border-color:var(--primary);box-shadow:0 0 12px rgba(108,60,224,.3);background:rgba(108,60,224,.08)}
.effect-card .ef-icon{font-size:28px;margin-bottom:4px}
.effect-card .ef-name{font-weight:700;font-size:12px}
.color-row{display:flex;align-items:center;gap:10px;margin-bottom:12px}
.color-row label{width:140px;font-size:13px;font-weight:600}
.color-row input[type=color]{
    width:40px;height:40px;border:none;border-radius:8px;cursor:pointer;
    background:transparent;padding:0
}
.color-row .color-hex{font-size:12px;color:var(--text-secondary);font-family:monospace}
.preview-btn{
    display:inline-flex;align-items:center;gap:6px;
    padding:10px 20px;background:var(--primary);color:#fff;
    border:none;border-radius:10px;font-weight:700;cursor:pointer;transition:all .2s;
    text-decoration:none;font-size:13px
}
.preview-btn:hover{filter:brightness(1.1);transform:translateY(-1px)}
.social-row{display:flex;align-items:center;gap:10px;margin-bottom:10px}
.social-row .s-icon{width:30px;text-align:center;font-size:18px}
.social-row input{flex:1}
.toggle-row{display:flex;align-items:center;justify-content:space-between;padding:10px 0;border-bottom:1px solid var(--border-color)}
.toggle-row:last-child{border-bottom:none}
.toggle-row label{font-size:13px;font-weight:600}
.slider-row{display:flex;align-items:center;gap:12px;margin-bottom:12px}
.slider-row label{width:160px;font-size:13px;font-weight:600}
.slider-row input[type=range]{flex:1}
.slider-row .slider-val{width:40px;text-align:right;font-size:12px;font-family:monospace;color:var(--text-secondary)}
.delete-btn{
    padding:6px 14px;background:rgba(239,68,68,.1);color:#ef4444;
    border:1px solid rgba(239,68,68,.2);border-radius:8px;font-size:12px;
    font-weight:600;cursor:pointer;transition:all .2s
}
.delete-btn:hover{background:rgba(239,68,68,.2)}
</style>

<form method="POST" enctype="multipart/form-data">
<input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
<input type="hidden" name="action" value="save">

<!-- Vista previa del link -->
<div class="card" style="margin-bottom:20px">
    <div class="card-body" style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px">
        <div>
            <div style="font-weight:700;font-size:14px;margin-bottom:2px"><?= cpIcon('play',14) ?> Tu Reproductor Público</div>
            <div style="font-size:12px;color:var(--text-secondary);word-break:break-all"><?= htmlspecialchars($playerUrl) ?></div>
        </div>
        <a href="<?= htmlspecialchars($playerUrl) ?>" target="_blank" class="preview-btn"><?= cpIcon('eye',14) ?> Ver Reproductor</a>
    </div>
</div>

<div class="config-grid">
    <!-- === IMAGEN DE FONDO === -->
    <div class="card">
        <div class="card-header">
            <h3><?= cpIcon('image',18) ?> Imagen de Fondo</h3>
        </div>
        <div class="card-body">
            <p style="font-size:12px;color:var(--text-secondary);margin-bottom:14px">
                Imagen que se muestra de fondo en todo el reproductor. Recomendado: 1920x1080px o mayor.
            </p>

            <?php if ($config['bg_image']): ?>
                <img src="<?= htmlspecialchars($config['bg_image']) ?>" alt="Fondo actual" class="img-preview">
                <div style="margin-top:10px;display:flex;gap:8px">
                    <span style="font-size:12px;color:var(--text-secondary);flex:1;display:flex;align-items:center"><?= cpIcon('check',14) ?> Fondo configurado</span>
                    <button type="submit" name="action" value="delete_bg" class="delete-btn" onclick="return confirm('¿Eliminar imagen de fondo?')"><?= cpIcon('trash',14) ?> Eliminar</button>
                </div>
            <?php endif; ?>

            <div class="img-upload-zone" style="margin-top:12px">
                <input type="file" name="bg_image" accept="image/jpeg,image/png,image/webp,image/gif">
                <div class="upload-icon"><?= cpIcon('image',24) ?></div>
                <div class="upload-text"><?= $config['bg_image'] ? 'Cambiar imagen de fondo' : 'Subir imagen de fondo' ?></div>
                <div class="upload-hint">JPG, PNG, WebP o GIF animado</div>
            </div>

            <!-- Overlay opacity -->
            <div style="margin-top:16px">
                <div class="slider-row">
                    <label>Oscuridad del overlay:</label>
                    <input type="range" name="bg_overlay_opacity" min="0" max="100" value="<?= intval(($config['bg_overlay_opacity'] ?? 0.6) * 100) ?>" oninput="this.nextElementSibling.textContent=this.value+'%'">
                    <span class="slider-val"><?= intval(($config['bg_overlay_opacity'] ?? 0.6) * 100) ?>%</span>
                </div>
                <div class="slider-row">
                    <label>Desenfoque (blur):</label>
                    <input type="range" name="bg_blur" min="0" max="20" value="<?= intval($config['bg_blur'] ?? 0) ?>" oninput="this.nextElementSibling.textContent=this.value+'px'">
                    <span class="slider-val"><?= intval($config['bg_blur'] ?? 0) ?>px</span>
                </div>
            </div>
        </div>
    </div>

    <!-- === PORTADA POR DEFECTO === -->
    <div class="card">
        <div class="card-header">
            <h3><?= cpIcon('image',18) ?> Portada por Defecto</h3>
        </div>
        <div class="card-body">
            <p style="font-size:12px;color:var(--text-secondary);margin-bottom:14px">
                Imagen que se muestra como carátula cuando la canción no tiene portada propia. Recomendado: 500x500px, cuadrada.
            </p>

            <?php if ($config['cover_image']): ?>
                <img src="<?= htmlspecialchars($config['cover_image']) ?>" alt="Portada actual" class="img-preview" style="max-height:160px;max-width:160px;margin:0 auto;display:block">
                <div style="margin-top:10px;display:flex;gap:8px;justify-content:center">
                    <span style="font-size:12px;color:var(--text-secondary);display:flex;align-items:center"><?= cpIcon('check',14) ?> Portada configurada</span>
                    <button type="submit" name="action" value="delete_cover" class="delete-btn" onclick="return confirm('¿Eliminar portada?')"><?= cpIcon('trash',14) ?> Eliminar</button>
                </div>
            <?php endif; ?>

            <div class="img-upload-zone" style="margin-top:12px">
                <input type="file" name="cover_image" accept="image/jpeg,image/png,image/webp">
                <div class="upload-icon"><?= cpIcon('image',24) ?></div>
                <div class="upload-text"><?= $config['cover_image'] ? 'Cambiar portada' : 'Subir portada' ?></div>
                <div class="upload-hint">JPG, PNG o WebP - Cuadrada 500x500</div>
            </div>
        </div>
    </div>
</div>

<!-- === EFECTO VISUAL === -->
<div class="card" style="margin-top:20px">
    <div class="card-header">
        <h3><?= cpIcon('star',18) ?> Efecto Visual del Fondo</h3>
    </div>
    <div class="card-body">
        <p style="font-size:12px;color:var(--text-secondary);margin-bottom:14px">
            Animación que se muestra sobre la imagen de fondo del reproductor.
        </p>
        <div class="effect-grid">
            <?php
            $effects = [
                'rain' => ['icon' => cpIcon('signal',28), 'name' => 'Lluvia'],
                'snow' => ['icon' => cpIcon('star',28), 'name' => 'Nieve'],
                'stars' => ['icon' => cpIcon('star',28), 'name' => 'Estrellas'],
                'wave' => ['icon' => cpIcon('signal',28), 'name' => 'Ondas'],
                'equalizer' => ['icon' => cpIcon('music',28), 'name' => 'Ecualizador'],
                'none' => ['icon' => cpIcon('x',28), 'name' => 'Sin efecto'],
            ];
            foreach ($effects as $key => $ef): ?>
            <label class="effect-card <?= ($config['effect_type'] ?? 'rain') === $key ? 'active' : '' ?>">
                <input type="radio" name="effect_type" value="<?= $key ?>" <?= ($config['effect_type'] ?? 'rain') === $key ? 'checked' : '' ?> style="display:none" onchange="document.querySelectorAll('.effect-card').forEach(c=>c.classList.remove('active'));this.closest('.effect-card').classList.add('active')">
                <div class="ef-icon"><?= $ef['icon'] ?></div>
                <div class="ef-name"><?= $ef['name'] ?></div>
            </label>
            <?php endforeach; ?>
        </div>
    </div>
</div>

<!-- === COLORES === -->
<div class="card" style="margin-top:20px">
    <div class="card-header">
        <h3><?= cpIcon('palette',18) ?> Colores</h3>
    </div>
    <div class="card-body">
        <div class="color-row">
            <label>Color de acento:</label>
            <input type="color" name="accent_color" value="<?= htmlspecialchars($config['accent_color'] ?? '#6c3ce0') ?>" onchange="this.nextElementSibling.textContent=this.value">
            <span class="color-hex"><?= htmlspecialchars($config['accent_color'] ?? '#6c3ce0') ?></span>
        </div>
        <div class="color-row">
            <label>Color de texto:</label>
            <input type="color" name="text_color" value="<?= htmlspecialchars($config['text_color'] ?? '#ffffff') ?>" onchange="this.nextElementSibling.textContent=this.value">
            <span class="color-hex"><?= htmlspecialchars($config['text_color'] ?? '#ffffff') ?></span>
        </div>
    </div>
</div>

<!-- === OPCIONES === -->
<div class="card" style="margin-top:20px">
    <div class="card-header">
        <h3><?= cpIcon('gear',18) ?> Opciones del Reproductor</h3>
    </div>
    <div class="card-body">
        <div class="toggle-row">
            <label>Mostrar historial de canciones</label>
            <label class="switch">
                <input type="checkbox" name="show_history" value="1" <?= ($config['show_history'] ?? 1) ? 'checked' : '' ?>>
                <span class="switch-slider"></span>
            </label>
        </div>
        <div class="toggle-row">
            <label>Mostrar conteo de oyentes</label>
            <label class="switch">
                <input type="checkbox" name="show_listeners" value="1" <?= ($config['show_listeners'] ?? 1) ? 'checked' : '' ?>>
                <span class="switch-slider"></span>
            </label>
        </div>
        <div class="toggle-row">
            <label>Mostrar barras de ecualizador</label>
            <label class="switch">
                <input type="checkbox" name="show_eq_bars" value="1" <?= ($config['show_eq_bars'] ?? 1) ? 'checked' : '' ?>>
                <span class="switch-slider"></span>
            </label>
        </div>
    </div>
</div>

<!-- === REDES SOCIALES === -->
<div class="card" style="margin-top:20px">
    <div class="card-header">
        <h3><?= cpIcon('globe',18) ?> Redes Sociales</h3>
    </div>
    <div class="card-body">
        <p style="font-size:12px;color:var(--text-secondary);margin-bottom:14px">
            Los íconos se mostrarán en el reproductor público. Deja vacío para ocultar.
        </p>
        <div class="social-row">
            <span class="s-icon"><?= cpIcon('globe',18) ?></span>
            <input type="url" name="social_facebook" class="form-control" placeholder="https://facebook.com/tu-radio" value="<?= htmlspecialchars($config['social_facebook'] ?? '') ?>">
        </div>
        <div class="social-row">
            <span class="s-icon"><?= cpIcon('image',18) ?></span>
            <input type="url" name="social_instagram" class="form-control" placeholder="https://instagram.com/tu-radio" value="<?= htmlspecialchars($config['social_instagram'] ?? '') ?>">
        </div>
        <div class="social-row">
            <span class="s-icon"><?= cpIcon('globe',18) ?></span>
            <input type="url" name="social_twitter" class="form-control" placeholder="https://twitter.com/tu-radio" value="<?= htmlspecialchars($config['social_twitter'] ?? '') ?>">
        </div>
        <div class="social-row">
            <span class="s-icon"><?= cpIcon('play',18) ?></span>
            <input type="url" name="social_youtube" class="form-control" placeholder="https://youtube.com/tu-radio" value="<?= htmlspecialchars($config['social_youtube'] ?? '') ?>">
        </div>
        <div class="social-row">
            <span class="s-icon"><?= cpIcon('globe',18) ?></span>
            <input type="url" name="social_website" class="form-control" placeholder="https://tu-radio.com" value="<?= htmlspecialchars($config['social_website'] ?? '') ?>">
        </div>
    </div>
</div>

<!-- BOTÓN GUARDAR -->
<div style="margin-top:20px;display:flex;gap:12px;flex-wrap:wrap">
    <button type="submit" class="btn btn-primary" style="padding:12px 32px;font-size:15px">
        <?= cpIcon('save',14) ?> Guardar Configuración
    </button>
    <a href="<?= htmlspecialchars($playerUrl) ?>" target="_blank" class="preview-btn" style="background:rgba(255,255,255,.06);border:1px solid var(--border-color);color:var(--text-primary)">
        <?= cpIcon('eye',14) ?> Vista Previa
    </a>
</div>

</form>

<?php require_once __DIR__ . '/../includes/layout_footer.php'; ?>
