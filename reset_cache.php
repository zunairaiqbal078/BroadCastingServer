<?php
/**
 * CastPanel - Reset OPcache & Verificar Actualización
 * ELIMINAR ESTE ARCHIVO DESPUÉS DE USAR
 */

// 1. Limpiar OPcache
$opcache = false;
if (function_exists('opcache_reset')) {
    $opcache = opcache_reset();
}

// 2. Limpiar APC cache si existe
if (function_exists('apc_clear_cache')) {
    apc_clear_cache();
    apc_clear_cache('user');
}

// 3. Verificar archivos clave
$checks = [
    'includes/icons.php' => file_exists(__DIR__ . '/includes/icons.php'),
    'includes/branding.php (try-catch)' => file_exists(__DIR__ . '/includes/branding.php') && strpos(file_get_contents(__DIR__ . '/includes/branding.php'), 'try {') !== false,
    'admin/configuracion.php (try-catch)' => file_exists(__DIR__ . '/admin/configuracion.php') && strpos(file_get_contents(__DIR__ . '/admin/configuracion.php'), 'try {') !== false,
    'admin/branding.php (isAdmin)' => file_exists(__DIR__ . '/admin/branding.php') && strpos(file_get_contents(__DIR__ . '/admin/branding.php'), '$isAdmin = true') !== false,
    'includes/layout_header.php (icons)' => file_exists(__DIR__ . '/includes/layout_header.php') && strpos(file_get_contents(__DIR__ . '/includes/layout_header.php'), "icons.php") !== false,
];
?>
<!DOCTYPE html>
<html>
<head><title>CastPanel - Reset Cache</title>
<style>body{font-family:Arial,sans-serif;max-width:600px;margin:40px auto;padding:20px}
.ok{color:green;font-weight:bold}.fail{color:red;font-weight:bold}
h1{color:#333}table{width:100%;border-collapse:collapse}td{padding:8px;border-bottom:1px solid #ddd}</style>
</head>
<body>
<h1>CastPanel - Reset Cache</h1>

<h2>OPcache</h2>
<p><?= $opcache ? '<span class="ok">✓ OPcache limpiado exitosamente</span>' : (function_exists('opcache_reset') ? '<span class="fail">✗ No se pudo limpiar</span>' : '<span class="ok">OPcache no está activo</span>') ?></p>

<h2>Verificación de Archivos</h2>
<table>
<?php foreach ($checks as $file => $ok): ?>
<tr>
    <td><?= $file ?></td>
    <td><?= $ok ? '<span class="ok">✓ OK</span>' : '<span class="fail">✗ NO ACTUALIZADO</span>' ?></td>
</tr>
<?php endforeach; ?>
</table>

<h2>Versiones de Archivos</h2>
<table>
<?php
$files = ['includes/icons.php', 'includes/branding.php', 'includes/layout_header.php', 'admin/branding.php', 'admin/configuracion.php'];
foreach ($files as $f) {
    $path = __DIR__ . '/' . $f;
    echo '<tr><td>' . $f . '</td><td>' . (file_exists($path) ? filesize($path) . ' bytes — ' . date('Y-m-d H:i:s', filemtime($path)) : '<span class="fail">NO EXISTE</span>') . '</td></tr>';
}
?>
</table>

<br>
<p style="background:#fff3cd;padding:12px;border-radius:6px"><strong>⚠️ IMPORTANTE:</strong> Elimina este archivo después de verificar. Recarga las páginas del panel (Ctrl+F5).</p>
</body>
</html>
