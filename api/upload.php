<?php
/**
 * CastPanel v2.0 - API: Upload de archivos (canciones y anuncios)
 */
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(['error' => 'Método no permitido'], 405);
}

// Verificar sesión
if (!isLoggedIn()) {
    jsonResponse(['error' => 'No autenticado'], 401);
}

$estacionId = (int)($_GET['estacion'] ?? 0);
$tipo = $_GET['tipo'] ?? 'musica'; // musica o anuncio

$db = getDB();
$stmt = $db->prepare("SELECT e.*, p.max_storage_mb, p.autodj_enabled FROM estaciones e LEFT JOIN paquetes p ON p.id = e.paquete_id WHERE e.id = ? AND e.activa = 1");
$stmt->execute([$estacionId]);
$estacion = $stmt->fetch();

if (!$estacion) {
    jsonResponse(['error' => 'Estación no encontrada'], 404);
}

// Verificar permisos
$userRol = $_SESSION['user_rol'] ?? '';
if ($userRol === 'cliente' && $estacion['usuario_id'] != $_SESSION['user_id']) {
    jsonResponse(['error' => 'Sin permisos'], 403);
}

if (empty($_FILES['archivos'])) {
    jsonResponse(['error' => 'No se recibieron archivos'], 400);
}

// Verificar almacenamiento
$storageUsed = getStorageUsed($estacionId);
$maxStorage = ($estacion['max_storage_mb'] ?? 5500) * 1024 * 1024;

$results = [];
$uploaded = 0;
$files = $_FILES['archivos'];

for ($i = 0; $i < count($files['name']); $i++) {
    $originalName = $files['name'][$i];
    $tmpFile = $files['tmp_name'][$i];
    $fileSize = $files['size'][$i];
    $error = $files['error'][$i];

    if ($error !== UPLOAD_ERR_OK) {
        $results[] = ['file' => $originalName, 'ok' => false, 'error' => 'Error de subida: ' . $error];
        continue;
    }

    // Validar extensión
    if (!isValidAudio($originalName)) {
        $results[] = ['file' => $originalName, 'ok' => false, 'error' => 'Formato no válido'];
        continue;
    }

    // Verificar espacio
    $storageUsed += $fileSize;
    if ($storageUsed > $maxStorage) {
        $results[] = ['file' => $originalName, 'ok' => false, 'error' => 'Sin espacio de almacenamiento'];
        continue;
    }

    // Nombre seguro
    $safeName = sanitizeFilename($originalName);
    $uniqueName = time() . '_' . $i . '_' . $safeName;

    if ($tipo === 'anuncio') {
        $destDir = ANUNCIOS_PATH . '/' . $estacion['slug'];
        $listaId = (int)($_POST['lista_id'] ?? 0);
        if (!$listaId) {
            $results[] = ['file' => $originalName, 'ok' => false, 'error' => 'Lista de anuncios no especificada'];
            continue;
        }
    } else {
        $destDir = MEDIA_PATH . '/' . $estacion['slug'];
    }

    // Crear directorio si no existe
    if (!is_dir($destDir)) {
        mkdir($destDir, 0755, true);
    }

    $destFile = $destDir . '/' . $uniqueName;

    if (!move_uploaded_file($tmpFile, $destFile)) {
        $results[] = ['file' => $originalName, 'ok' => false, 'error' => 'Error al mover archivo'];
        continue;
    }

    if ($tipo === 'anuncio') {
        // Obtener duración
        $info = getMP3Info($destFile);

        $db->prepare("INSERT INTO anuncios (estacion_id, lista_id, nombre_archivo, nombre_original, tamano_bytes, duracion_segundos, ruta_archivo) VALUES (?,?,?,?,?,?,?)")
            ->execute([$estacionId, $listaId, $uniqueName, $originalName, $fileSize, $info['duracion'], $destFile]);

        $db->prepare("UPDATE listas_anuncios SET total_anuncios = total_anuncios + 1 WHERE id = ?")->execute([$listaId]);
    } else {
        // Extraer metadatos
        $info = getMP3Info($destFile);

        // Intentar extraer carátula
        $coverUrl = null;
        $coverDir = BASE_PATH . '/assets/covers';
        if (!is_dir($coverDir)) mkdir($coverDir, 0755, true);
        $coverFile = $coverDir . '/' . pathinfo($uniqueName, PATHINFO_FILENAME) . '.jpg';
        if (extractCover($destFile, $coverFile)) {
            $coverUrl = BASE_URL . '/assets/covers/' . basename($coverFile);
        }

        $playlistId = ($_POST['playlist_id'] ?? null) ?: null;

        // Obtener orden
        $orden = 0;
        if ($playlistId) {
            $stmt = $db->prepare("SELECT COALESCE(MAX(orden),0)+1 FROM canciones WHERE playlist_id = ?");
            $stmt->execute([$playlistId]);
            $orden = (int)$stmt->fetchColumn();
        }

        $db->prepare("INSERT INTO canciones (estacion_id, playlist_id, nombre_archivo, nombre_original, titulo, artista, album, duracion_segundos, tamano_bytes, ruta_archivo, caratula, orden) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)")
            ->execute([
                $estacionId, $playlistId, $uniqueName, $originalName,
                $info['titulo'] ?: pathinfo($originalName, PATHINFO_FILENAME),
                $info['artista'], $info['album'], $info['duracion'],
                $fileSize, $destFile, $coverUrl, $orden
            ]);

        if ($playlistId) {
            $db->prepare("UPDATE playlists SET total_canciones = total_canciones + 1 WHERE id = ?")->execute([$playlistId]);
        }
    }

    $uploaded++;
    $results[] = ['file' => $originalName, 'ok' => true];
}

// Si se subieron archivos exitosamente, regenerar config y reiniciar Liquidsoap
if ($uploaded > 0) {
    try {
        require_once __DIR__ . '/../includes/provisioning.php';

        // AutoDJ stations: start the station if it is not running yet,
        // otherwise just reload the playlist (no restart per uploaded file)
        ensureAutoDJPlaying($estacion);
    } catch (Exception $e) {
        // No bloquear la respuesta si falla el reinicio
        // El usuario puede reiniciar manualmente desde AutoDJ
    }
}

jsonResponse([
    'success' => true,
    'uploaded' => $uploaded,
    'total' => count($files['name']),
    'results' => $results,
]);
