<?php
/**
 * CastPanel - Transmision en Vivo Instagram
 */
$platform  = 'instagram';
$pageTitle = 'Transmisi' . chr(0xC3) . chr(0xB3) . 'n en Vivo Instagram';
$pageIcon  = 'instagram';
require_once __DIR__ . '/../includes/layout_header.php';
require_once __DIR__ . '/../includes/social_streaming.php';

if (!$estacion) {
    echo '<div class="alert alert-warning">Sin estaci&oacute;n asignada.</div>';
    require_once __DIR__ . '/../includes/layout_footer.php';
    exit;
}

ensureSocialStreamTable();
$config  = getSocialStreamConfig($estacion['id'], $platform);
$status  = checkSocialStreamStatus($estacion['id'], $platform);
$message = '';
$msgType = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && verifyCsrf()) {
    $action = $_POST['action'] ?? '';

    if ($action === 'start_stream') {
        $key = trim($_POST['stream_key'] ?? '');
        if ($key) {
            saveSocialStreamConfig($estacion['id'], $platform, [
                'stream_key' => $key,
                'rtmp_url'   => getDefaultRtmpUrl($platform),
            ]);
            $result = startSocialStream($estacion['id'], $platform);
            if ($result['ok']) {
                $message = 'Transmisi&oacute;n iniciada correctamente.';
                $msgType = 'success';
            } else {
                $message = 'Error: ' . htmlspecialchars($result['error'] ?? 'Desconocido');
                $msgType = 'error';
            }
        } else {
            $message = 'Ingrese la clave de transmisi&oacute;n primero.';
            $msgType = 'error';
        }
    } elseif ($action === 'stop_stream') {
        stopSocialStream($estacion['id'], $platform);
        $message = 'Transmisi&oacute;n detenida.';
        $msgType = 'success';
    } elseif ($action === 'upload_image') {
        if (!empty($_FILES['stream_image']['name'])) {
            if (uploadStreamImage($estacion['id'], $platform, $_FILES['stream_image'])) {
                $message = 'Imagen subida correctamente.';
                $msgType = 'success';
            } else {
                $message = 'Error al subir imagen. Solo .jpg/.png, m&aacute;x 3MB.';
                $msgType = 'error';
            }
        }
    } elseif ($action === 'delete_image') {
        deleteStreamImage($estacion['id'], $platform);
        $message = 'Imagen eliminada.';
        $msgType = 'success';
    }

    $config = getSocialStreamConfig($estacion['id'], $platform);
    $status = checkSocialStreamStatus($estacion['id'], $platform);
}

$imageUrl = getStreamImageUrl($estacion['id'], $platform);
require __DIR__ . '/_social_live_template.php';
