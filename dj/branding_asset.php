<?php
/**
 * DJ portal (port 2080, document root = dj/): serves the panel's global
 * branding images (login logo, login background) from ../uploads/branding.
 * Only these two names, only image files.
 */
$type = (string)($_GET['f'] ?? '');
if (!in_array($type, ['logo_login', 'login_bg_image'], true)) {
    http_response_code(404);
    exit;
}
$dir = realpath(__DIR__ . '/../uploads/branding/global');
$files = $dir ? glob($dir . '/' . $type . '.*') : [];
$file = $files ? $files[0] : '';
$types = ['png' => 'image/png', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'gif' => 'image/gif', 'webp' => 'image/webp', 'svg' => 'image/svg+xml'];
$ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
if ($file === '' || !is_file($file) || !isset($types[$ext])) {
    http_response_code(404);
    exit;
}
header('Content-Type: ' . $types[$ext]);
header('Content-Length: ' . filesize($file));
header('Cache-Control: public, max-age=86400');
header('X-Content-Type-Options: nosniff');
readfile($file);
