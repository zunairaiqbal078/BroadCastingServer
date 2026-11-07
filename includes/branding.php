<?php
/**
 * CastPanel v2.1 - Sistema de Branding / White-Label
 *
 * Funciones para cargar y gestionar logos, títulos y favicons
 * personalizados por admin (global) y reseller.
 */

require_once __DIR__ . '/../config/database.php';

/**
 * Obtener la configuración de branding aplicable al usuario actual.
 *
 * Jerarquía:
 *   1. Branding del reseller (si el usuario pertenece a un reseller)
 *   2. Branding global (admin)
 *   3. Valores por defecto hardcodeados
 *
 * @param int|null $resellerId  ID del reseller (para clientes del reseller)
 * @return array  Configuración de branding completa
 */
function getBranding(?int $resellerId = null): array {
    $db = getDB();

    $defaults = [
        'panel_title'    => 'CastPanel',
        'panel_subtitle' => 'Audio Streaming Platform',
        'login_title'    => 'Iniciar Sesión',
        'logo_login'     => null,
        'logo_sidebar'   => null,
        'logo_favicon'   => null,
        'logo_player'    => null,
        'primary_color'  => null,
        'sidebar_color'  => null,
        'custom_css'     => null,
        'footer_text'    => null,
    ];

    $result = $defaults;

    // 1) Cargar branding global (try-catch por si la tabla no existe)
    try {
        $stmt = $db->prepare("SELECT * FROM branding WHERE owner_type = 'global' AND owner_id IS NULL LIMIT 1");
        $stmt->execute();
        $global = $stmt->fetch();

        if ($global) {
            foreach ($defaults as $key => $defVal) {
                if (!empty($global[$key])) {
                    $result[$key] = $global[$key];
                }
            }
            $result['_global_id'] = $global['id'];
        }

        // 2) Si hay reseller, intentar sobreescribir con su branding
        if ($resellerId) {
            $stmt = $db->prepare("SELECT * FROM branding WHERE owner_type = 'reseller' AND owner_id = ? LIMIT 1");
            $stmt->execute([$resellerId]);
            $resellerBrand = $stmt->fetch();

            if ($resellerBrand) {
                foreach ($defaults as $key => $defVal) {
                    if (!empty($resellerBrand[$key])) {
                        $result[$key] = $resellerBrand[$key];
                    }
                }
                $result['_reseller_id'] = $resellerBrand['id'];
            }
        }
    } catch (PDOException $e) {
        // Tabla branding no existe aún — devolver defaults
    }

    return $result;
}

/**
 * Obtener el branding para el usuario de la sesión actual.
 * Detecta automáticamente el reseller_id si el usuario es cliente de un reseller.
 *
 * @return array
 */
function getCurrentBranding(): array {
    $resellerId = null;

    if (isset($_SESSION['user_id']) && isset($_SESSION['user_rol'])) {
        $db = getDB();

        if ($_SESSION['user_rol'] === 'cliente' || $_SESSION['user_rol'] === 'dj') {
            // Buscar si este usuario pertenece a un reseller
            $stmt = $db->prepare("SELECT reseller_id FROM usuarios WHERE id = ?");
            $stmt->execute([$_SESSION['user_id']]);
            $row = $stmt->fetch();
            $resellerId = !empty($row['reseller_id']) ? (int)$row['reseller_id'] : null;
        }
    }

    return getBranding($resellerId);
}

/**
 * Obtener el branding para la página de login (sin sesión activa).
 * Se usa en index.php antes de que haya sesión.
 *
 * @return array  Branding global solamente (no hay reseller sin sesión)
 */
function getLoginBranding(): array {
    return getBranding(null);
}

/**
 * Obtener el directorio base para uploads de branding.
 *
 * @return string
 */
function getBrandingUploadPath(): string {
    try {
        $db = getDB();
        $stmt = $db->prepare("SELECT valor FROM configuracion WHERE clave = 'branding_upload_path'");
        $stmt->execute();
        $path = $stmt->fetchColumn();
        return $path ?: '/var/castpanel/branding';
    } catch (PDOException $e) {
        return '/var/castpanel/branding';
    }
}

/**
 * Obtener la URL base para archivos de branding subidos.
 *
 * @param string $filename  Nombre del archivo
 * @param string $ownerType  'global' o 'reseller'
 * @param int|null $ownerId  ID del reseller (null para global)
 * @return string  URL relativa al archivo
 */
function getBrandingUrl(string $filename, string $ownerType = 'global', ?int $ownerId = null): string {
    $subdir = ($ownerType === 'reseller' && $ownerId) ? "reseller_{$ownerId}" : 'global';
    return BASE_URL . '/uploads/branding/' . $subdir . '/' . $filename;
}

/**
 * Subir un archivo de branding (logo, favicon).
 *
 * @param array  $file        $_FILES['campo']
 * @param string $type        'logo_login', 'logo_sidebar', 'logo_favicon', 'logo_player'
 * @param string $ownerType   'global' o 'reseller'
 * @param int|null $ownerId   ID del reseller
 * @return array  ['success' => bool, 'path' => string|null, 'error' => string|null]
 */
function uploadBrandingFile(array $file, string $type, string $ownerType = 'global', ?int $ownerId = null): array {
    // Validar upload
    if ($file['error'] !== UPLOAD_ERR_OK) {
        return ['success' => false, 'error' => 'Error al subir archivo (código: ' . $file['error'] . ')'];
    }

    // Validar tamaño (max 2MB)
    if ($file['size'] > 2 * 1024 * 1024) {
        return ['success' => false, 'error' => 'El archivo no debe superar 2MB'];
    }

    // Validar tipo de imagen
    $allowedTypes = ['image/png', 'image/jpeg', 'image/gif', 'image/svg+xml', 'image/x-icon', 'image/vnd.microsoft.icon'];
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime = finfo_file($finfo, $file['tmp_name']);
    finfo_close($finfo);

    if (!in_array($mime, $allowedTypes)) {
        return ['success' => false, 'error' => 'Tipo de archivo no permitido. Use PNG, JPG, GIF, SVG o ICO'];
    }

    // Directorio de destino
    $subdir = ($ownerType === 'reseller' && $ownerId) ? "reseller_{$ownerId}" : 'global';
    $uploadDir = __DIR__ . '/../uploads/branding/' . $subdir;

    if (!is_dir($uploadDir)) {
        mkdir($uploadDir, 0755, true);
    }

    // Nombre de archivo basado en el tipo
    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if (!$ext || $ext === 'ico') {
        // Mapear MIME a extensión
        $extMap = ['image/png' => 'png', 'image/jpeg' => 'jpg', 'image/gif' => 'gif', 'image/svg+xml' => 'svg', 'image/x-icon' => 'ico', 'image/vnd.microsoft.icon' => 'ico'];
        $ext = $extMap[$mime] ?? 'png';
    }
    $filename = $type . '.' . $ext;
    $filepath = $uploadDir . '/' . $filename;

    // Borrar archivo anterior del mismo tipo (puede tener diferente extensión)
    foreach (glob($uploadDir . '/' . $type . '.*') as $oldFile) {
        unlink($oldFile);
    }

    if (!move_uploaded_file($file['tmp_name'], $filepath)) {
        return ['success' => false, 'error' => 'No se pudo guardar el archivo'];
    }

    // Retornar la URL relativa
    $url = getBrandingUrl($filename, $ownerType, $ownerId);
    return ['success' => true, 'path' => $url, 'filename' => $filename];
}

/**
 * Eliminar un archivo de branding y limpiar la BD.
 *
 * @param string $type        'logo_login', 'logo_sidebar', 'logo_favicon', 'logo_player'
 * @param string $ownerType   'global' o 'reseller'
 * @param int|null $ownerId
 * @return bool
 */
function deleteBrandingFile(string $type, string $ownerType = 'global', ?int $ownerId = null): bool {
    $subdir = ($ownerType === 'reseller' && $ownerId) ? "reseller_{$ownerId}" : 'global';
    $uploadDir = __DIR__ . '/../uploads/branding/' . $subdir;

    // Borrar todos los archivos de ese tipo (cualquier extensión)
    $deleted = false;
    foreach (glob($uploadDir . '/' . $type . '.*') as $file) {
        unlink($file);
        $deleted = true;
    }

    // Limpiar el campo en la BD
    $db = getDB();
    if ($ownerId) {
        $stmt = $db->prepare("UPDATE branding SET `{$type}` = NULL, updated_at = NOW() WHERE owner_type = ? AND owner_id = ?");
        $stmt->execute([$ownerType, $ownerId]);
    } else {
        $stmt = $db->prepare("UPDATE branding SET `{$type}` = NULL, updated_at = NOW() WHERE owner_type = 'global' AND owner_id IS NULL");
        $stmt->execute();
    }

    return $deleted;
}

/**
 * Generar la etiqueta <link> para el favicon personalizado.
 *
 * @param array $branding  Resultado de getBranding()
 * @return string  HTML del favicon o vacío
 */
function brandingFavicon(array $branding): string {
    if (!empty($branding['logo_favicon'])) {
        $url = htmlspecialchars($branding['logo_favicon']);
        $ext = strtolower(pathinfo($branding['logo_favicon'], PATHINFO_EXTENSION));
        $types = ['ico' => 'image/x-icon', 'png' => 'image/png', 'svg' => 'image/svg+xml', 'jpg' => 'image/jpeg', 'gif' => 'image/gif'];
        $type = $types[$ext] ?? 'image/x-icon';
        return '<link rel="icon" type="' . $type . '" href="' . $url . '">';
    }
    return '';
}
