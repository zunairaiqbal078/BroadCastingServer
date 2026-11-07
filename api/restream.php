<?php
/**
 * CastPanel — API Retransmision a Redes Sociales
 * Acciones: list, save, delete, start, stop, status, upload_cover
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/restream_functions.php';

requireLogin();

header('Content-Type: application/json; charset=utf-8');

$db = getDB();
$estacionId = $_SESSION['estacion_id'] ?? 0;
$action = $_REQUEST['action'] ?? '';

if (!$estacionId) {
    echo json_encode(['success' => false, 'error' => 'Sin estacion seleccionada']);
    exit;
}

switch ($action) {

    /* ── Listar retransmisiones ── */
    case 'list':
        syncRestreamStatuses($estacionId);

        $stmt = $db->prepare("SELECT * FROM retransmisiones WHERE estacion_id = ? ORDER BY created_at DESC");
        $stmt->execute([$estacionId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $platforms = getRestreamPlatforms();
        foreach ($rows as &$row) {
            $row['platform_info'] = $platforms[$row['plataforma']] ?? $platforms['custom'];
            if ($row['cover_image']) {
                $row['cover_url'] = '/uploads/restream/' . basename($row['cover_image']);
            } else {
                $row['cover_url'] = null;
            }
        }
        unset($row);

        echo json_encode(['success' => true, 'items' => $rows]);
        break;

    /* ── Guardar (crear o editar) ── */
    case 'save':
        $id         = intval($_POST['id'] ?? 0);
        $plataforma = $_POST['plataforma'] ?? '';
        $nombre     = trim($_POST['nombre'] ?? '');
        $rtmpUrl    = trim($_POST['rtmp_url'] ?? '');
        $streamKey  = trim($_POST['stream_key'] ?? '');

        $platforms = getRestreamPlatforms();
        if (!isset($platforms[$plataforma])) {
            echo json_encode(['success' => false, 'error' => 'Plataforma no valida']);
            exit;
        }
        if ($nombre === '') {
            echo json_encode(['success' => false, 'error' => 'Nombre requerido']);
            exit;
        }
        if ($streamKey === '') {
            echo json_encode(['success' => false, 'error' => 'Clave de transmision requerida']);
            exit;
        }

        // Si la plataforma tiene URL predefinida y no se envio una custom
        if ($plataforma !== 'custom' && $rtmpUrl === '') {
            $rtmpUrl = $platforms[$plataforma]['rtmp_url'];
        }
        if ($rtmpUrl === '') {
            echo json_encode(['success' => false, 'error' => 'URL RTMP requerida']);
            exit;
        }

        if ($id > 0) {
            // Verificar que pertenece a esta estacion y no esta corriendo
            $chk = $db->prepare("SELECT estado FROM retransmisiones WHERE id = ? AND estacion_id = ?");
            $chk->execute([$id, $estacionId]);
            $curr = $chk->fetch(PDO::FETCH_ASSOC);
            if (!$curr) {
                echo json_encode(['success' => false, 'error' => 'No encontrada']);
                exit;
            }
            if ($curr['estado'] === 'running') {
                echo json_encode(['success' => false, 'error' => 'Detener la retransmision antes de editar']);
                exit;
            }

            $db->prepare("
                UPDATE retransmisiones
                SET plataforma=?, nombre=?, rtmp_url=?, stream_key=?, updated_at=NOW()
                WHERE id=? AND estacion_id=?
            ")->execute([$plataforma, $nombre, $rtmpUrl, $streamKey, $id, $estacionId]);

            echo json_encode(['success' => true, 'id' => $id, 'msg' => 'Actualizada']);
        } else {
            // Limite de 10 por estacion
            $cnt = $db->prepare("SELECT COUNT(*) FROM retransmisiones WHERE estacion_id = ?");
            $cnt->execute([$estacionId]);
            if ($cnt->fetchColumn() >= 10) {
                echo json_encode(['success' => false, 'error' => 'Maximo 10 retransmisiones por estacion']);
                exit;
            }

            $db->prepare("
                INSERT INTO retransmisiones (estacion_id, plataforma, nombre, rtmp_url, stream_key, estado, created_at, updated_at)
                VALUES (?, ?, ?, ?, ?, 'stopped', NOW(), NOW())
            ")->execute([$estacionId, $plataforma, $nombre, $rtmpUrl, $streamKey]);

            $newId = $db->lastInsertId();
            echo json_encode(['success' => true, 'id' => $newId, 'msg' => 'Creada']);
        }
        break;

    /* ── Eliminar ── */
    case 'delete':
        $id = intval($_POST['id'] ?? 0);
        $chk = $db->prepare("SELECT * FROM retransmisiones WHERE id = ? AND estacion_id = ?");
        $chk->execute([$id, $estacionId]);
        $row = $chk->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
            echo json_encode(['success' => false, 'error' => 'No encontrada']);
            exit;
        }

        // Detener si esta corriendo
        if ($row['estado'] === 'running' && $row['pid']) {
            stopRestream($id, $estacionId);
        }

        // Borrar cover
        if ($row['cover_image']) {
            $coverPath = RESTREAM_UPLOAD_DIR . '/' . basename($row['cover_image']);
            if (file_exists($coverPath)) @unlink($coverPath);
        }

        $db->prepare("DELETE FROM retransmisiones WHERE id = ? AND estacion_id = ?")->execute([$id, $estacionId]);
        echo json_encode(['success' => true]);
        break;

    /* ── Iniciar retransmision ── */
    case 'start':
        $id = intval($_POST['id'] ?? 0);
        $result = startRestream($id, $estacionId);
        echo json_encode($result);
        break;

    /* ── Detener ── */
    case 'stop':
        $id = intval($_POST['id'] ?? 0);
        $result = stopRestream($id, $estacionId);
        echo json_encode($result);
        break;

    /* ── Estado actual ── */
    case 'status':
        syncRestreamStatuses($estacionId);
        $stmt = $db->prepare("SELECT id, estado, pid, ultimo_error FROM retransmisiones WHERE estacion_id = ?");
        $stmt->execute([$estacionId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode(['success' => true, 'items' => $rows]);
        break;

    /* ── Subir imagen de portada ── */
    case 'upload_cover':
        $id = intval($_POST['id'] ?? 0);
        if (!$id) {
            echo json_encode(['success' => false, 'error' => 'ID requerido']);
            exit;
        }

        // Verificar pertenencia
        $chk = $db->prepare("SELECT id FROM retransmisiones WHERE id = ? AND estacion_id = ?");
        $chk->execute([$id, $estacionId]);
        if (!$chk->fetch()) {
            echo json_encode(['success' => false, 'error' => 'No encontrada']);
            exit;
        }

        if (!isset($_FILES['cover'])) {
            echo json_encode(['success' => false, 'error' => 'No se recibio archivo']);
            exit;
        }

        $result = uploadRestreamCover($_FILES['cover'], $id);
        echo json_encode($result);
        break;

    default:
        echo json_encode(['success' => false, 'error' => 'Accion no valida']);
}
