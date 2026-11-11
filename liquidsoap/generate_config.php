<?php
/**
 * CastPanel v2.0 - Generador de configuracion Liquidsoap
 *
 * Uso: php generate_config.php <estacion_id>
 *
 * Lee la plantilla station_template.liq y genera un archivo .liq
 * personalizado para la estacion especificada.
 * Tambien genera el archivo M3U de playlist activa.
 */
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/liquidsoap_playlist.php';

if (php_sapi_name() !== 'cli') {
    die('Este script solo se ejecuta desde CLI');
}

$estacionId = (int)($argv[1] ?? 0);
if (!$estacionId) {
    echo "Uso: php generate_config.php <estacion_id>\n";
    exit(1);
}

$db = getDB();
$stmt = $db->prepare("SELECT e.*, p.* FROM estaciones e LEFT JOIN paquetes p ON p.id = e.paquete_id WHERE e.id = ?");
$stmt->execute([$estacionId]);
$estacion = $stmt->fetch();

if (!$estacion) {
    echo "Error: Estacion #$estacionId no encontrada\n";
    exit(1);
}

// Leer plantilla
$template = file_get_contents(__DIR__ . '/station_template.liq');
if (!$template) {
    echo "Error: No se pudo leer station_template.liq\n";
    exit(1);
}

// Calcular puerto telnet unico para esta estacion (base + id)
$telnetPort = LIQUIDSOAP_TELNET_PORT + $estacion['id'];

// Obtener la URL del panel
$panelUrl = '';
$stmt = $db->prepare("SELECT valor FROM configuracion WHERE clave = 'site_url'");
$stmt->execute();
$row = $stmt->fetch();
if ($row) $panelUrl = $row['valor'];

// Obtener la contrasena de Icecast
$icecastPass = '';
$stmt = $db->prepare("SELECT valor FROM configuracion WHERE clave = 'icecast_admin_pass'");
$stmt->execute();
$row = $stmt->fetch();
if ($row) $icecastPass = $row['valor'];

// Generar bloque de salida segun tipo de servidor
$serverType = $estacion['server_type'] ?? 'icecast';
$stationPass = $estacion['icecast_password'] ?: ($icecastPass ?: 'hackme');
$bitrate = $estacion['bitrate'] ?: 128;
$samplerate = $estacion['samplerate'] ?: 44100;
$port = $estacion['icecast_port'] ?: ICECAST_PORT;

// Puerto harbor = puerto de streaming + 5 (evita conflicto)
$harborPort = $port + 5;

// Host siempre es 127.0.0.1 (Liquidsoap y SHOUTcast/Icecast corren en el mismo servidor)
$icecastHost = defined('ICECAST_HOST') ? ICECAST_HOST : '127.0.0.1';

// Modo de reproduccion
$modoReproduccion = $estacion['modo_reproduccion'] ?? 'normal';
if ($modoReproduccion === 'aleatorio') {
    $playMode = 'randomize';
} else {
    $playMode = 'normal';
}

// Crossfade
$crossfade = (int)($estacion['crossfade_seconds'] ?? 0);
if ($crossfade < 1) $crossfade = 3;

$m3uResult = castpanelWriteActivePlaylistM3u($estacion);
if (!$m3uResult['success']) {
    echo "Error: " . ($m3uResult['error'] ?? 'M3U') . "\n";
    exit(1);
}
$playlistFile = $m3uResult['liquidsoap_uri'];
echo "M3U generado: {$m3uResult['path']} ({$m3uResult['count']} canciones)\n";
echo "Liquidsoap URI: $playlistFile\n";

// ---- Bloque de salida ----
if ($serverType === 'shoutcast_v1' || $serverType === 'shoutcast_v2' || $serverType === 'shoutcast_v25') {
    // SHOUTcast v1/v2: usa output.shoutcast
    $outputBlock = <<<LIQ
# Salida a SHOUTcast ($serverType)
output.shoutcast(
    %mp3(
        bitrate=$bitrate,
        samplerate=$samplerate,
        stereo=true
    ),
    id="output_{$estacion['slug']}",
    host="$icecastHost",
    port=$port,
    password="$stationPass",
    name="{$estacion['slug']}",
    genre="Variada",
    public=false,
    fallible=true,
    on_error=fun(msg) -> begin
        log("Error SHOUTcast: #{msg}")
        3.0
    end,
    radio
)
LIQ;
} else {
    // Icecast
    $mount = $estacion['icecast_mount'] ?: ('/' . $estacion['slug']);
    $outputBlock = <<<LIQ
# Salida a Icecast
output.icecast(
    %mp3(
        bitrate=$bitrate,
        samplerate=$samplerate,
        stereo=true
    ),
    id="output_{$estacion['slug']}",
    host="$icecastHost",
    port=$port,
    password="$stationPass",
    mount="$mount",
    name="{$estacion['slug']}",
    genre="Variada",
    public=false,
    fallible=true,
    on_error=fun(msg) -> begin
        log("Error Icecast: #{msg}")
        3.0
    end,
    radio
)
LIQ;
}

// Reemplazos
$replacements = [
    '{{STATION_SLUG}}'     => $slug,
    '{{SERVER_TYPE}}'      => $serverType,
    '{{ICECAST_HOST}}'     => $icecastHost,
    '{{ICECAST_PORT}}'     => $port,
    '{{ICECAST_MOUNT}}'    => $estacion['icecast_mount'] ?: ('/' . $slug),
    '{{ICECAST_PASSWORD}}' => $stationPass,
    '{{SHOUTCAST_SID}}'    => $estacion['shoutcast_sid'] ?: 1,
    '{{CODEC}}'            => $estacion['codec'] ?: 'mp3',
    '{{BITRATE}}'          => $bitrate,
    '{{SAMPLERATE}}'       => $samplerate,
    '{{MEDIA_PATH}}'       => $mediaPath,
    '{{ANUNCIOS_PATH}}'    => defined('ANUNCIOS_PATH') ? ANUNCIOS_PATH : '/var/castpanel/anuncios',
    '{{PANEL_API_URL}}'    => $panelUrl,
    '{{TELNET_PORT}}'      => $telnetPort,
    '{{HARBOR_PORT}}'      => $harborPort,
    '{{PLAY_MODE}}'        => $playMode,
    '{{PLAYLIST_FILE}}'    => $playlistFile,
    '{{CROSSFADE}}'        => $crossfade,
    '# {{OUTPUT_BLOCK}}'   => $outputBlock,
];

$config = str_replace(array_keys($replacements), array_values($replacements), $template);

// Directorio de salida
$outputDir = defined('LIQUIDSOAP_CONFIG_DIR') ? LIQUIDSOAP_CONFIG_DIR : '/var/castpanel/liquidsoap/stations';
if (!is_dir($outputDir)) {
    @mkdir($outputDir, 0755, true);
}

$outputFile = $outputDir . '/' . $slug . '.liq';
file_put_contents($outputFile, $config);

echo "Configuracion generada: $outputFile\n";
echo "Tipo servidor: $serverType\n";
echo "Puerto streaming: $port\n";
echo "Puerto harbor (DJ): $harborPort\n";
echo "Puerto telnet: $telnetPort\n";
echo "Host: $icecastHost\n";
echo "Modo reproduccion: $modoReproduccion ($playMode)\n";
echo "Crossfade: {$crossfade}s\n";
echo "\nPara iniciar: liquidsoap $outputFile\n";
