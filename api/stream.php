<?php
/**
 * CastPanel v2.0 - API: Generador de archivos M3U / PLS / XSPF
 */
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';

$format = $_GET['format'] ?? 'm3u';
$station = $_GET['station'] ?? '';

if (!$station) {
    http_response_code(400);
    echo 'station parameter required';
    exit;
}

$db = getDB();
$stmt = $db->prepare("SELECT * FROM estaciones WHERE slug = ? AND activa = 1");
$stmt->execute([$station]);
$estacion = $stmt->fetch();

if (!$estacion) {
    http_response_code(404);
    echo 'Station not found';
    exit;
}

// URL del stream segun tipo de servidor
if (in_array($estacion['server_type'] ?? '', ['shoutcast_v1', 'shoutcast_v2', 'shoutcast_v25'])) {
    $streamUrl = 'https://' . SSL_DOMAIN . '/shoutcast/' . $estacion['slug'];
} else {
    $streamUrl = 'https://' . SSL_DOMAIN . '/live/' . $estacion['slug'];
}
$radioName = $estacion['titulo_radio'] ?: $estacion['nombre'];

switch ($format) {
    case 'pls':
        header('Content-Type: audio/x-scpls');
        header('Content-Disposition: inline; filename="' . $estacion['slug'] . '.pls"');
        echo "[playlist]\n";
        echo "NumberOfEntries=1\n";
        echo "File1=$streamUrl\n";
        echo "Title1=$radioName\n";
        echo "Length1=-1\n";
        echo "Version=2\n";
        break;

    case 'xspf':
        header('Content-Type: application/xspf+xml');
        header('Content-Disposition: inline; filename="' . $estacion['slug'] . '.xspf"');
        echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
        echo '<playlist version="1" xmlns="http://xspf.org/ns/0/">' . "\n";
        echo "  <title>" . htmlspecialchars($radioName) . "</title>\n";
        echo "  <trackList>\n";
        echo "    <track>\n";
        echo "      <location>$streamUrl</location>\n";
        echo "      <title>" . htmlspecialchars($radioName) . "</title>\n";
        echo "    </track>\n";
        echo "  </trackList>\n";
        echo "</playlist>\n";
        break;

    case 'm3u':
    default:
        header('Content-Type: audio/x-mpegurl');
        header('Content-Disposition: inline; filename="' . $estacion['slug'] . '.m3u"');
        echo "#EXTM3U\n";
        echo "#EXTINF:-1,$radioName\n";
        echo "$streamUrl\n";
        break;
}
