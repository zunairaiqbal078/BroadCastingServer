<?php
/**
 * Dynamic SHOUTcast listener URL — /shoutcast/{slug}
 *
 * Resolves the station port from the database and proxies audio from SHOUTcast.
 * New stations work immediately even if Apache ProxyPass rules were not synced yet.
 */
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';

$slug = isset($_GET['slug']) ? (string)$_GET['slug'] : '';
$slug = preg_replace('/[^a-z0-9\-]/', '', strtolower($slug));

if ($slug === '' && !empty($_SERVER['REQUEST_URI'])) {
    if (preg_match('#/shoutcast/([a-z0-9][a-z0-9\-]*)#', (string)$_SERVER['REQUEST_URI'], $m)) {
        $slug = $m[1];
    }
}

$byPort = (int)($_GET['port'] ?? 0);
if ($slug === '' && $byPort <= 0 && !empty($_SERVER['REQUEST_URI'])
    && preg_match('#/([0-9]{4,5})/(stream|;)#', (string)$_SERVER['REQUEST_URI'], $m)) {
    $byPort = (int)$m[1];
}

if ($slug === '' && $byPort <= 0) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=UTF-8');
    echo 'Station not found';
    exit;
}

$db = getDB();
if ($slug !== '') {
    $stmt = $db->prepare(
        "SELECT slug, puerto_stream, server_type, activa FROM estaciones WHERE slug = ? LIMIT 1"
    );
    $stmt->execute([$slug]);
} else {
    // Only ports that belong to a station are proxied
    $stmt = $db->prepare(
        "SELECT slug, puerto_stream, server_type, activa FROM estaciones WHERE puerto_stream = ? LIMIT 1"
    );
    $stmt->execute([$byPort]);
}
$estacion = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$estacion || empty($estacion['activa']) || !isShoutcastServerType($estacion['server_type'] ?? '')) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=UTF-8');
    echo 'Station not found';
    exit;
}

$port = (int)($estacion['puerto_stream'] ?? 0);
if ($port <= 0) {
    http_response_code(503);
    header('Content-Type: text/plain; charset=UTF-8');
    echo 'Stream port not configured';
    exit;
}

$upstream = "http://127.0.0.1:{$port}/stream/1/";

set_time_limit(0);
@ini_set('zlib.output_compression', '0');
if (function_exists('apache_setenv')) {
    @apache_setenv('no-gzip', '1');
}

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$ctx = stream_context_create([
    'http' => [
        'method' => $method,
        'timeout' => 15,
        'ignore_errors' => true,
        'header' => "Icy-MetaData: 0\r\n",
    ],
]);

$handle = @fopen($upstream, 'rb', false, $ctx);
if ($handle === false) {
    http_response_code(502);
    header('Content-Type: text/plain; charset=UTF-8');
    echo 'Could not reach stream source — start AutoDJ or the station service';
    exit;
}

$headers = $http_response_header ?? [];
$status = 502;
foreach ($headers as $line) {
    if (preg_match('/^HTTP\/\d\.\d\s+(\d+)/', $line, $m)) {
        $status = (int)$m[1];
        continue;
    }
    if (preg_match('/^(Content-Type|icy-|Cache-Control|Expires):/i', $line)) {
        header(trim($line), false);
    }
}

http_response_code($status >= 100 && $status < 600 ? $status : 502);
header('Cache-Control: no-cache, no-store');
header('X-CastPanel-Stream-Proxy: dynamic');

if ($method === 'HEAD') {
    fclose($handle);
    exit;
}

while (!feof($handle)) {
    $chunk = fread($handle, 8192);
    if ($chunk === false || $chunk === '') {
        break;
    }
    echo $chunk;
    if (function_exists('ob_flush')) {
        @ob_flush();
    }
    flush();
    if (connection_aborted()) {
        break;
    }
}
fclose($handle);