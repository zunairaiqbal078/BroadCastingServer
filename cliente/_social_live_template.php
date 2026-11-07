<?php
/**
 * CastPanel - Social Streaming Template (Estilo SonicPanel)
 * Compartido por youtube_live.php, facebook_live.php, tiktok_live.php, instagram_live.php
 * Variables requeridas: $platform, $config, $status, $message, $msgType, $imageUrl, $estacion
 */

// Datos de cada plataforma
$_platforms = [
    'youtube' => [
        'color' => '#FF0000',
        'name'  => 'YouTube',
        'info'  => 'La funci&oacute;n transmisi&oacute;n en vivo Youtube transmite su m&uacute;sica de radio a Youtube live. Hay un <strong>l&iacute;mite</strong> diario de transmisi&oacute;n en vivo en youtube, no reinicie la transmisi&oacute;n en vivo m&aacute;s de <strong>7 veces</strong> al d&iacute;a. Vaya a <a href="https://www.youtube.com/livestreaming/" target="_blank" style="color:#1565c0">https://www.youtube.com/livestreaming/</a> y active funciones de transmisi&oacute;n en vivo. Mantenga la p&aacute;gina de transmisi&oacute;n en vivo Youtube abierta antes de iniciar la transmisi&oacute;n en vivo. Youtube tarda 24 horas para activaci&oacute;n. Copie su clave de transmisi&oacute;n en vivo e ingr&eacute;sela en esta p&aacute;gina, suba una imagen de tama&ntilde;o 1280x720. La transmisi&oacute;n en vivo comenzar&aacute; despu&eacute;s de 30-40 segundos en Youtube.',
        'key_hint' => 'Enter Youtube Key',
        'warnings' => [
            'Youtube has copyright strikes for live stream music, your entire youtube channel can be <strong>banned</strong> or limited if your radio plays copyrighted music.',
            'Your hosting company and the control panel software company are not responsible in such cases.',
        ],
    ],
    'facebook' => [
        'color' => '#1877F2',
        'name'  => 'Facebook',
        'info'  => 'La funci&oacute;n transmisi&oacute;n en vivo Facebook transmite su m&uacute;sica de radio a Facebook Live. Vaya a <a href="https://www.facebook.com/live/producer/" target="_blank" style="color:#1565c0">Facebook Live Producer</a>, cree un video en vivo y copie la &quot;Clave de transmisi&oacute;n&quot;. Pegue la clave en el campo de abajo. Suba una imagen de tama&ntilde;o 1280x720 que se mostrar&aacute; durante la transmisi&oacute;n. Facebook usa <strong>RTMPS</strong> (cifrado).',
        'key_hint' => 'Enter Facebook Key',
        'warnings' => [
            'Facebook can <strong>restrict</strong> your account or page if your radio plays copyrighted music during live stream.',
            'Your hosting company and the control panel software company are not responsible in such cases.',
        ],
    ],
    'tiktok' => [
        'color' => '#000000',
        'name'  => 'TikTok',
        'info'  => 'La funci&oacute;n transmisi&oacute;n en vivo TikTok transmite su m&uacute;sica de radio a TikTok Live. Se requieren al menos <strong>1,000 seguidores</strong> para transmitir en vivo. Obtenga su clave de transmisi&oacute;n desde <a href="https://www.tiktok.com/studio" target="_blank" style="color:#1565c0">TikTok Live Studio</a> o la aplicaci&oacute;n m&oacute;vil. Suba una imagen de tama&ntilde;o 1280x720 que se mostrar&aacute; durante la transmisi&oacute;n.',
        'key_hint' => 'Enter TikTok Key',
        'warnings' => [
            'TikTok can <strong>suspend</strong> your account if your radio plays copyrighted music during live stream.',
            'Your hosting company and the control panel software company are not responsible in such cases.',
        ],
    ],
    'instagram' => [
        'color' => '#E4405F',
        'name'  => 'Instagram',
        'info'  => 'La funci&oacute;n transmisi&oacute;n en vivo Instagram transmite su m&uacute;sica de radio a Instagram Live. Se requiere una cuenta de tipo <strong>Business o Creator</strong>. Obtenga su clave de transmisi&oacute;n desde la configuraci&oacute;n de Instagram Live. Suba una imagen de tama&ntilde;o 1280x720 que se mostrar&aacute; durante la transmisi&oacute;n. Instagram usa <strong>RTMPS</strong> (cifrado).',
        'key_hint' => 'Enter Instagram Key',
        'warnings' => [
            'Instagram can <strong>restrict</strong> your account if your radio plays copyrighted music during live stream.',
            'Your hosting company and the control panel software company are not responsible in such cases.',
        ],
    ],
];

$pInfo = $_platforms[$platform] ?? $_platforms['youtube'];
$currentKey = $config['stream_key'] ?? '';
$statusLabel = ($status === 'active') ? 'Active' : 'Disabled';
?>

<?php if ($message): ?>
<div style="padding:12px 16px;border-radius:6px;margin-bottom:16px;font-size:13px;
    <?php if ($msgType === 'success'): ?>background:#e8f5e9;color:#2e7d32;border:1px solid #a5d6a7;
    <?php elseif ($msgType === 'error'): ?>background:#ffebee;color:#c62828;border:1px solid #ef9a9a;
    <?php else: ?>background:#e3f2fd;color:#1565c0;border:1px solid #90caf9;<?php endif; ?>">
    <?= $message ?>
</div>
<?php endif; ?>

<h4 style="font-size:18px;font-weight:700;margin:0 0 20px 0;display:flex;align-items:center;gap:10px">
    <?= socialPlatformIcon($platform, 24) ?>
    Transmisi&oacute;n en Vivo <?= $pInfo['name'] ?>
</h4>

<!-- Info Box Azul (estilo SonicPanel) -->
<div style="background:#e3f2fd;border-left:5px solid #2196f3;padding:16px 20px;border-radius:0 6px 6px 0;margin-bottom:24px">
    <div style="display:flex;gap:14px;align-items:flex-start">
        <svg width="24" height="24" viewBox="0 0 24 24" fill="#2196f3" style="flex-shrink:0;margin-top:2px"><circle cx="12" cy="12" r="10" fill="none" stroke="#2196f3" stroke-width="2"/><line x1="12" y1="16" x2="12" y2="12" stroke="#2196f3" stroke-width="2" stroke-linecap="round"/><circle cx="12" cy="8" r="1" fill="#2196f3"/></svg>
        <p style="margin:0;font-size:13px;line-height:1.7;color:#1a237e"><?= $pInfo['info'] ?></p>
    </div>
</div>

<!-- Clave + Botones + Status (estilo SonicPanel) -->
<form method="POST" id="streamForm" style="margin-bottom:24px">
    <?= csrfField() ?>
    <div style="display:flex;align-items:center;gap:12px;flex-wrap:wrap">
        <input type="text" name="stream_key" value="<?= htmlspecialchars($currentKey) ?>"
               placeholder="<?= $pInfo['key_hint'] ?>"
               style="flex:1;min-width:200px;padding:10px 14px;border:1px solid #ddd;border-radius:6px;font-size:14px;outline:none;transition:border-color .2s"
               onfocus="this.style.borderColor='#2196f3'" onblur="this.style.borderColor='#ddd'">

        <button type="submit" name="action" value="start_stream"
                style="padding:10px 24px;background:#4caf50;color:#fff;border:none;border-radius:6px;font-size:14px;font-weight:600;cursor:pointer;transition:background .2s"
                onmouseover="this.style.background='#43a047'" onmouseout="this.style.background='#4caf50'">
            Iniciar
        </button>

        <button type="submit" name="action" value="stop_stream"
                style="padding:10px 24px;background:#607d8b;color:#fff;border:none;border-radius:6px;font-size:14px;font-weight:600;cursor:pointer;transition:background .2s"
                onmouseover="this.style.background='#546e7a'" onmouseout="this.style.background='#607d8b'">
            Detener
        </button>

        <span style="font-size:14px;color:#555">
            Status: <strong style="color:<?= $status === 'active' ? '#4caf50' : '#9e9e9e' ?>"><?= $statusLabel ?></strong>
        </span>
    </div>
</form>

<!-- Area de Imagen (estilo SonicPanel) -->
<div style="display:flex;gap:24px;margin-bottom:24px;flex-wrap:wrap">
    <!-- Preview de imagen -->
    <div style="width:420px;max-width:100%;aspect-ratio:16/9;background:#e0e0e0;border-radius:6px;display:flex;align-items:center;justify-content:center;overflow:hidden;position:relative">
        <?php if ($imageUrl): ?>
            <img src="<?= htmlspecialchars($imageUrl) ?>" alt="Stream Image"
                 style="width:100%;height:100%;object-fit:cover">
            <form method="POST" style="position:absolute;top:8px;right:8px">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="delete_image">
                <button type="submit" title="Eliminar imagen"
                        style="width:28px;height:28px;border-radius:50%;background:rgba(0,0,0,.6);color:#fff;border:none;cursor:pointer;font-size:14px;display:flex;align-items:center;justify-content:center"
                        onclick="return confirm('Eliminar imagen?')">&#x2715;</button>
            </form>
        <?php else: ?>
            <span style="color:#999;font-size:24px;font-weight:700;letter-spacing:2px">1280 x 720</span>
        <?php endif; ?>
    </div>

    <!-- Upload drag-and-drop -->
    <form method="POST" enctype="multipart/form-data" id="uploadForm"
          style="flex:1;min-width:280px;min-height:180px">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="upload_image">
        <input type="file" name="stream_image" id="streamImageInput" accept="image/jpeg,image/png"
               style="display:none">
        <div id="dropZone"
             style="height:100%;min-height:180px;border:2px dashed #ccc;border-radius:6px;display:flex;flex-direction:column;align-items:center;justify-content:center;cursor:pointer;transition:all .2s;padding:20px;box-sizing:border-box">
            <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="#ccc" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" style="margin-bottom:12px">
                <path d="M21 15v4a2 2 0 01-2 2H5a2 2 0 01-2-2v-4"/><polyline points="17,8 12,3 7,8"/><line x1="12" y1="3" x2="12" y2="15"/>
            </svg>
            <p style="margin:0;font-size:14px;color:#999">Drop the picture file here to upload, or click.</p>
            <p style="margin:6px 0 0;font-size:12px;color:#bbb">Only .jpg or .png allowed! Max 3MB.</p>
        </div>
    </form>
</div>

<!-- Warning Box (estilo SonicPanel) -->
<div style="background:#fff3e0;border-left:5px solid #ff9800;padding:16px 20px;border-radius:0 6px 6px 0;margin-bottom:24px">
    <div style="display:flex;gap:14px;align-items:flex-start">
        <svg width="32" height="32" viewBox="0 0 24 24" fill="#ff9800" style="flex-shrink:0;margin-top:2px"><path d="M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z" fill="none" stroke="#ff9800" stroke-width="2"/><line x1="12" y1="9" x2="12" y2="13" stroke="#ff9800" stroke-width="2" stroke-linecap="round"/><circle cx="12" cy="17" r="1" fill="#ff9800"/></svg>
        <ul style="margin:0;padding:0 0 0 16px;font-size:13px;line-height:1.7;color:#e65100">
            <?php foreach ($pInfo['warnings'] as $w): ?>
            <li style="margin-bottom:4px"><?= $w ?></li>
            <?php endforeach; ?>
        </ul>
    </div>
</div>

<script>
(function(){
    var dropZone = document.getElementById('dropZone');
    var fileInput = document.getElementById('streamImageInput');
    var uploadForm = document.getElementById('uploadForm');

    if (!dropZone || !fileInput) return;

    dropZone.addEventListener('click', function() {
        fileInput.click();
    });

    dropZone.addEventListener('dragover', function(e) {
        e.preventDefault();
        e.stopPropagation();
        this.style.borderColor = '#2196f3';
        this.style.background = 'rgba(33,150,243,0.04)';
    });

    dropZone.addEventListener('dragleave', function(e) {
        e.preventDefault();
        e.stopPropagation();
        this.style.borderColor = '#ccc';
        this.style.background = 'transparent';
    });

    dropZone.addEventListener('drop', function(e) {
        e.preventDefault();
        e.stopPropagation();
        this.style.borderColor = '#ccc';
        this.style.background = 'transparent';
        if (e.dataTransfer.files.length) {
            fileInput.files = e.dataTransfer.files;
            uploadForm.submit();
        }
    });

    fileInput.addEventListener('change', function() {
        if (this.files.length) {
            uploadForm.submit();
        }
    });
})();
</script>

<?php require_once __DIR__ . '/../includes/layout_footer.php'; ?>
