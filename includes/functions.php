<?php
/**
 * CastPanel v2.0 - Funciones generales
 */

/**
 * Formatear bytes a legible
 */
function formatBytes(int $bytes, int $decimals = 2): string {
    if ($bytes === 0) return '0 B';
    $k = 1024;
    $sizes = ['B', 'KB', 'MB', 'GB', 'TB'];
    $i = floor(log($bytes) / log($k));
    return round($bytes / pow($k, $i), $decimals) . ' ' . $sizes[$i];
}

/**
 * Formatear MB
 */
function formatMB(float $mb, int $decimals = 1): string {
    if ($mb >= 1024) return round($mb / 1024, $decimals) . ' GB';
    return round($mb, $decimals) . ' MB';
}

/**
 * Formatear duración en segundos a mm:ss
 */
function formatDuration(int $seconds): string {
    $m = floor($seconds / 60);
    $s = $seconds % 60;
    return sprintf('%d:%02d', $m, $s);
}

/**
 * Generar slug
 */
function slugify(string $text): string {
    $text = preg_replace('/[^a-z0-9\-]/', '', strtolower(trim(preg_replace('/[\s_]+/', '-', $text))));
    return preg_replace('/-+/', '-', trim($text, '-'));
}

/**
 * Sanitizar nombre de archivo
 */
function sanitizeFilename(string $filename): string {
    $info = pathinfo($filename);
    $name = preg_replace('/[^a-zA-Z0-9._\-]/', '_', $info['filename']);
    return $name . '.' . strtolower($info['extension'] ?? 'mp3');
}

/**
 * Obtener metadatos de archivo MP3 usando ffprobe
 */
function getMP3Info(string $filepath): array {
    $info = ['duracion' => 0, 'titulo' => null, 'artista' => null, 'album' => null];

    $cmd = sprintf(
        'ffprobe -v quiet -print_format json -show_format %s 2>/dev/null',
        escapeshellarg($filepath)
    );
    $output = shell_exec($cmd);
    if ($output) {
        $data = json_decode($output, true);
        if (isset($data['format'])) {
            $fmt = $data['format'];
            $info['duracion'] = (int)($fmt['duration'] ?? 0);
            $tags = $fmt['tags'] ?? [];
            $info['titulo'] = $tags['title'] ?? $tags['TITLE'] ?? null;
            $info['artista'] = $tags['artist'] ?? $tags['ARTIST'] ?? null;
            $info['album'] = $tags['album'] ?? $tags['ALBUM'] ?? null;
        }
    }

    return $info;
}

/**
 * Obtener carátula embebida de MP3
 */
function extractCover(string $filepath, string $outputPath): bool {
    $cmd = sprintf(
        'ffmpeg -y -i %s -an -vcodec mjpeg -frames:v 1 %s 2>/dev/null',
        escapeshellarg($filepath),
        escapeshellarg($outputPath)
    );
    exec($cmd, $out, $ret);
    return $ret === 0 && file_exists($outputPath) && filesize($outputPath) > 0;
}

/**
 * Comunicación con Icecast (obtener estado del mount)
 */
function getIcecastStatus(string $mount): array {
    $url = sprintf(
        'http://%s:%d/admin/stats?mount=%s',
        ICECAST_HOST, ICECAST_PORT, urlencode($mount)
    );

    $ctx = stream_context_create([
        'http' => [
            'header' => 'Authorization: Basic ' . base64_encode(ICECAST_ADMIN_USER . ':' . ICECAST_ADMIN_PASS),
            'timeout' => 5,
        ]
    ]);

    $result = ['online' => false, 'oyentes' => 0, 'titulo' => '', 'bitrate' => 0];

    $xml = @file_get_contents($url, false, $ctx);
    if ($xml) {
        $parsed = @simplexml_load_string($xml);
        if ($parsed && isset($parsed->source)) {
            $src = $parsed->source;
            $result['online'] = true;
            $result['oyentes'] = (int)($src->listeners ?? 0);
            $result['titulo'] = (string)($src->title ?? '');
            $result['bitrate'] = (int)($src->bitrate ?? 0);
        }
    }

    return $result;
}

/**
 * Obtener oyentes totales de todos los mounts
 */
function getIcecastListeners(): array {
    $url = sprintf('http://%s:%d/admin/stats', ICECAST_HOST, ICECAST_PORT);

    $ctx = stream_context_create([
        'http' => [
            'header' => 'Authorization: Basic ' . base64_encode(ICECAST_ADMIN_USER . ':' . ICECAST_ADMIN_PASS),
            'timeout' => 5,
        ]
    ]);

    $result = [];
    $xml = @file_get_contents($url, false, $ctx);
    if ($xml) {
        $parsed = @simplexml_load_string($xml);
        if ($parsed) {
            foreach ($parsed->source as $src) {
                $mount = (string)$src['mount'];
                $result[$mount] = [
                    'oyentes' => (int)($src->listeners ?? 0),
                    'titulo' => (string)($src->title ?? ''),
                ];
            }
        }
    }

    return $result;
}

/**
 * Comunicación con SHOUTcast v1 (obtener estado)
 * SHOUTcast v1: puerto admin = puerto + 1, /admin.cgi?pass=PASSWORD&mode=viewxml
 */
function getShoutcastV1Status(string $host, int $port, string $password): array {
    $result = ['online' => false, 'oyentes' => 0, 'titulo' => '', 'bitrate' => 0, 'pico_oyentes' => 0];

    $ctx = stream_context_create(['http' => ['timeout' => 3, 'user_agent' => 'CastPanel/2.4']]);

    // Método 1: /stats público (mismo puerto)
    $url = sprintf('http://%s:%d/stats', $host, $port);
    $xml = @file_get_contents($url, false, $ctx);

    // Método 2: admin.cgi en puerto+1
    if (!$xml && $password) {
        $adminPort = $port + 1;
        $url = sprintf('http://%s:%d/admin.cgi?pass=%s&mode=viewxml', $host, $adminPort, urlencode($password));
        $xml = @file_get_contents($url, false, $ctx);
    }

    if ($xml) {
        $parsed = @simplexml_load_string($xml);
        if ($parsed) {
            $result['online'] = ((int)($parsed->STREAMSTATUS ?? 0)) === 1;
            $result['oyentes'] = (int)($parsed->CURRENTLISTENERS ?? 0);
            $result['pico_oyentes'] = (int)($parsed->PEAKLISTENERS ?? 0);
            $result['titulo'] = (string)($parsed->SONGTITLE ?? '');
            $result['bitrate'] = (int)($parsed->BITRATE ?? 0);
        }
    }

    // Método 3: port check como último recurso
    if (!$result['online'] && !$xml) {
        $fp = @fsockopen($host, $port, $errno, $errstr, 2);
        if ($fp) {
            fclose($fp);
            $result['online'] = true;
        }
    }

    return $result;
}

/**
 * Comunicación con SHOUTcast v2 (obtener estado por SID)
 * SHOUTcast v2: mismo puerto, /admin.cgi?sid=SID&mode=viewxml
 */
function getShoutcastV2Status(string $host, int $port, string $password, int $sid = 1): array {
    $result = ['online' => false, 'oyentes' => 0, 'titulo' => '', 'bitrate' => 0, 'pico_oyentes' => 0];

    // Método 1: /stats público (no necesita password)
    $url = sprintf('http://%s:%d/stats?sid=%d', $host, $port, $sid);
    $ctx = stream_context_create(['http' => ['timeout' => 3, 'user_agent' => 'CastPanel/2.4']]);
    $xml = @file_get_contents($url, false, $ctx);

    // Método 2: admin.cgi con password si /stats falla
    if (!$xml && $password) {
        $url = sprintf('http://%s:%d/admin.cgi?sid=%d&mode=viewxml&pass=%s', $host, $port, $sid, urlencode($password));
        $xml = @file_get_contents($url, false, $ctx);
    }

    if ($xml) {
        $parsed = @simplexml_load_string($xml);
        if ($parsed) {
            $result['online'] = ((int)($parsed->STREAMSTATUS ?? 0)) === 1;
            $result['oyentes'] = (int)($parsed->CURRENTLISTENERS ?? 0);
            $result['pico_oyentes'] = (int)($parsed->PEAKLISTENERS ?? 0);
            $result['titulo'] = (string)($parsed->SONGTITLE ?? '');
            $result['bitrate'] = (int)($parsed->BITRATE ?? 0);
        }
    }

    // Método 3: si el puerto responde pero XML falla, al menos detectar online
    if (!$result['online'] && !$xml) {
        $fp = @fsockopen($host, $port, $errno, $errstr, 2);
        if ($fp) {
            fclose($fp);
            $result['online'] = true;
        }
    }

    return $result;
}

/**
 * Función unificada: obtener estado del stream según tipo de servidor
 * Detecta automáticamente Icecast / SHOUTcast v1 / SHOUTcast v2
 */
function getStreamStatus(array $estacion): array {
    $serverType = $estacion['server_type'] ?? 'icecast';

    // SHOUTcast listens on the station's own port (puerto_stream). icecast_port
    // has a DB default of 8000 (the Icecast port), so it must not win here,
    // otherwise SHOUTcast stations are checked on the wrong port.
    if (in_array($serverType, ['shoutcast_v1', 'shoutcast_v2', 'shoutcast_v25'], true)) {
        $port = (int)($estacion['puerto_stream'] ?? 0) ?: (int)($estacion['icecast_port'] ?? 0);
    } else {
        $port = (int)($estacion['icecast_port'] ?? $estacion['puerto_stream'] ?? ICECAST_PORT);
    }
    if ($port <= 0) $port = ICECAST_PORT;

    $host = defined('ICECAST_HOST') ? ICECAST_HOST : '127.0.0.1';
    $pass = $estacion['icecast_password'] ?? '';
    $raw = [];

    switch ($serverType) {
        case 'shoutcast_v1':
            $raw = getShoutcastV1Status($host, $port, $pass);
            break;

        case 'shoutcast_v2':
        case 'shoutcast_v25':
            $sid = (int)($estacion['shoutcast_sid'] ?? 1);
            if ($sid <= 0) $sid = 1;
            $raw = getShoutcastV2Status($host, $port, $pass, $sid);
            break;

        case 'icecast':
        default:
            $raw = getIcecastStatus($estacion['icecast_mount'] ?? '');
            break;
    }

    $titulo = trim((string)($raw['titulo'] ?? ''));
    if ($titulo === '' && !empty($raw['online'])) {
        $titulo = castpanelLiquidsoapNowPlaying($estacion);
    }
    if ($titulo === '' && !empty($estacion['id'])) {
        try {
            $stmt = getDB()->prepare(
                "SELECT titulo, artista FROM log_emision
                 WHERE estacion_id = ? AND tipo = 'cancion'
                 ORDER BY emitido_at DESC LIMIT 1"
            );
            $stmt->execute([(int)$estacion['id']]);
            $row = $stmt->fetch();
            if ($row && trim((string)($row['titulo'] ?? '')) !== '') {
                $titulo = trim((string)$row['titulo']);
                if (!empty($row['artista'])) {
                    $titulo = trim($row['artista'] . ' - ' . $titulo, ' -');
                }
            }
        } catch (Throwable $e) {
            // ignore
        }
    }
    // Normalizar claves para que los callers funcionen con ambos formatos
    return [
        // Claves que usan live_stats.php, radio_stats.php, etc.
        'status'    => !empty($raw['online']) ? 'online' : 'offline',
        'listeners' => (int)($raw['oyentes'] ?? 0),
        'title'     => $titulo,
        'bitrate'   => (int)($raw['bitrate'] ?? 0),
        'peak'      => (int)($raw['pico_oyentes'] ?? 0),
        'genre'     => $raw['genero'] ?? '',
        'source'    => $raw['source'] ?? '',
        // Claves originales para backward compat
        'online'    => !empty($raw['online']),
        'oyentes'   => (int)($raw['oyentes'] ?? 0),
        'titulo'    => $titulo,
        'pico_oyentes' => (int)($raw['pico_oyentes'] ?? 0),
    ];
}

/**
 * Is the station really on air? Checks the stream server (SHOUTcast/Icecast)
 * instead of autodj_estado, which only Liquidsoap updates and so is always
 * 'offline' for live-only stations (no AutoDJ) even when a DJ is broadcasting.
 * Results are cached for $ttl seconds so list pages and dashboards with many
 * stations stay fast.
 */
function getStreamStatusCached(array $estacion, int $ttl = 30): array {
    static $mem = [];
    $id = (int)($estacion['id'] ?? 0);
    if (isset($mem[$id])) return $mem[$id];

    $file = sys_get_temp_dir() . '/castpanel_stream_status.json';
    $cache = @json_decode((string)@file_get_contents($file), true) ?: [];
    if ($id && isset($cache[$id]) && (time() - ($cache[$id]['t'] ?? 0)) < $ttl) {
        return $mem[$id] = $cache[$id]['s'];
    }

    $status = getStreamStatus($estacion);
    if ($id) {
        $cache[$id] = ['t' => time(), 's' => $status];
        @file_put_contents($file, json_encode($cache), LOCK_EX);
    }
    return $mem[$id] = $status;
}

/** Forget cached on-air status (after start/stop) so lists update at once */
function clearStreamStatusCache(?int $estacionId = null): void {
    $file = sys_get_temp_dir() . '/castpanel_stream_status.json';
    if ($estacionId === null) { @unlink($file); return; }
    $cache = @json_decode((string)@file_get_contents($file), true) ?: [];
    unset($cache[$estacionId]);
    @file_put_contents($file, json_encode($cache), LOCK_EX);
}

/** true when the station is active and its stream server reports a live source */
function isStationOnline(array $estacion): bool {
    if (isset($estacion['activa']) && !$estacion['activa']) return false;
    return !empty(getStreamStatusCached($estacion)['online']);
}

/** Number of stations on air among the given rows (each row needs the station columns) */
function countOnlineStations(array $estaciones): int {
    $n = 0;
    foreach ($estaciones as $e) {
        if (isStationOnline($e)) $n++;
    }
    return $n;
}

/** Host path to Liquidsoap log for a station slug (readable by the web user when mode 644). */
function castpanelLiquidsoapLogFile(string $slug): string {
    $dir = defined('LIQUIDSOAP_LOG_DIR') ? (string)LIQUIDSOAP_LOG_DIR : '/var/log/liquidsoap';

    return rtrim($dir, '/') . '/' . $slug . '.log';
}

function castpanelEnsureLiquidsoapLogReadable(string $slug): void {
    $path = castpanelLiquidsoapLogFile($slug);
    if ($path !== '' && is_readable($path)) {
        return;
    }
    if (function_exists('castpanelCtl')) {
        castpanelCtl('liquidsoap-log-chmod-' . $slug);
    }
}

/** Read tail of Liquidsoap log (www-data must be able to read — castpanel-ctl fixes mode 644). */
function castpanelLiquidsoapLogTail(string $slug, int $maxBytes = 65536): ?string {
    castpanelEnsureLiquidsoapLogReadable($slug);
    $path = castpanelLiquidsoapLogFile($slug);
    if (!is_readable($path)) {
        return null;
    }
    $size = @filesize($path);
    if ($size === false || $size < 1) {
        return null;
    }
    $read = (int)min($size, $maxBytes);
    $fh = @fopen($path, 'rb');
    if (!$fh) {
        return null;
    }
    if ($size > $read) {
        fseek($fh, $size - $read);
    }
    $chunk = fread($fh, $read);
    fclose($fh);

    return is_string($chunk) && $chunk !== '' ? $chunk : null;
}

/** Last decoded file URI from Liquidsoap log (“Prepared …”) — not affected by bad ID3 tags. */
function castpanelLiquidsoapLastPreparedUri(string $slug): ?string {
    $chunk = castpanelLiquidsoapLogTail($slug);
    if ($chunk === null) {
        return null;
    }
    if (!preg_match_all('/Prepared "(\/media\/[^"]+)"/', $chunk, $m)) {
        return null;
    }
    $uri = end($m[1]);

    return is_string($uri) && $uri !== '' ? $uri : null;
}

/**
 * After skip, wait until log shows a new Prepared line (or timeout).
 */
function castpanelLiquidsoapWaitPreparedUri(string $slug, ?string $previousUri = null, float $timeoutSec = 2.5): ?string {
    $deadline = microtime(true) + max(0.3, $timeoutSec);
    $last = null;
    do {
        $uri = castpanelLiquidsoapLastPreparedUri($slug);
        if ($uri !== null && ($previousUri === null || $uri !== $previousUri)) {
            return $uri;
        }
        $last = $uri;
        usleep(200000);
    } while (microtime(true) < $deadline);

    return $last;
}

/**
 * Current track title from Liquidsoap telnet (when SHOUTcast SONGTITLE is empty).
 */
function castpanelLiquidsoapNowPlaying(array $estacion): string {
    $slug = $estacion['slug'] ?? '';
    if ($slug !== '') {
        $uri = castpanelLiquidsoapLastPreparedUri($slug);
        if ($uri !== null) {
            require_once __DIR__ . '/autodj_player.php';
            $tracks = castpanelActivePlaylistTracks($estacion);
            $idx = castpanelTrackByUri($tracks, $uri);
            if ($idx !== null) {
                return $tracks[$idx]['display'];
            }
            $base = basename($uri);
            $base = preg_replace('/^[0-9]+_[0-9]+_/', '', $base);
            $base = preg_replace('/\.[^.]+$/', '', $base);

            return str_replace('_', ' ', $base);
        }
    }
    $port = (int)($estacion['puerto_telnet'] ?? 0);
    if ($port <= 0) {
        $port = (int)(defined('LIQUIDSOAP_TELNET_PORT') ? LIQUIDSOAP_TELNET_PORT : 1234) + (int)($estacion['id'] ?? 0);
    }
    if ($slug === '') {
        return '';
    }
    $cmds = ["output_{$slug}.metadata", "music_{$slug}.metadata"];
    foreach ($cmds as $cmd) {
        $resp = liquidsoapCommand($cmd, $port);
        if (!$resp) {
            continue;
        }
        $artist = '';
        if (preg_match('/artist="([^"]+)"/', $resp, $m)) {
            $artist = trim($m[1]);
        }
        if (preg_match('/title="([^"]+)"/', $resp, $m)) {
            $title = trim($m[1]);
            if ($title !== '' && $artist !== '' && stripos($title, $artist) === false) {
                return $artist . ' - ' . $title;
            }
            return $title;
        }
        if (preg_match('/filename="([^"]+)"/', $resp, $m)) {
            $base = basename($m[1]);
            $base = preg_replace('/^[0-9]+_[0-9]+_/', '', $base);
            $base = preg_replace('/\.[^.]+$/', '', $base);
            return str_replace('_', ' ', $base);
        }
        if (preg_match('#/media/[^\s"]+#', $resp, $m)) {
            $base = basename($m[0]);
            $base = preg_replace('/^[0-9]+_[0-9]+_/', '', $base);
            $base = preg_replace('/\.[^.]+$/', '', $base);
            return str_replace('_', ' ', $base);
        }
    }
    return '';
}

/**
 * Enviar comando a Liquidsoap via telnet
 */
function liquidsoapCommand(string $command, int $port = null): ?string {
    $port = $port ?: LIQUIDSOAP_TELNET_PORT;
    $fp = @fsockopen(LIQUIDSOAP_TELNET_HOST, $port, $errno, $errstr, 5);
    if (!$fp) return null;

    fwrite($fp, $command . "\n");
    stream_set_timeout($fp, 3);

    $response = '';
    while (!feof($fp)) {
        $line = fgets($fp, 4096);
        if ($line === false) {
            break;
        }
        $response .= $line;
        if (preg_match('/^END\s*$/m', $response)) {
            break;
        }
    }
    fwrite($fp, "quit\n");
    fclose($fp);

    return trim(preg_replace('/\r?\nEND\s*$/', '', $response));
}

/**
 * Respuesta JSON
 */
function jsonResponse(array $data, int $code = 200): void {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

/**
 * Validar extensión de archivo
 */
function isValidAudio(string $filename): bool {
    $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
    return in_array($ext, ['mp3', 'ogg', 'aac', 'm4a', 'wav', 'flac']);
}

/**
 * Obtener pico de oyentes en últimas X horas
 */
function getPeakListeners(int $estacionId, int $hours = 24): int {
    try {
        $db = getDB();
        $stmt = $db->prepare("
            SELECT COALESCE(MAX(pico_oyentes), 0)
            FROM estadisticas_oyentes
            WHERE estacion_id = ? AND registrado_at >= DATE_SUB(NOW(), INTERVAL ? HOUR)
        ");
        $stmt->execute([$estacionId, $hours]);
        return (int)$stmt->fetchColumn();
    } catch (PDOException $e) { return 0; }
}

/**
 * Obtener el log de emisión reciente
 */
function getEmisionLog(int $estacionId, int $limit = 50, ?string $tipo = null): array {
    try {
        $db = getDB();
        $sql = "SELECT * FROM log_emision WHERE estacion_id = ?";
        $params = [$estacionId];

        if ($tipo) {
            $sql .= " AND tipo = ?";
            $params[] = $tipo;
        }

        $sql .= " ORDER BY emitido_at DESC LIMIT ?";
        $params[] = $limit;

        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    } catch (PDOException $e) { return []; }
}

/**
 * SHOUTcast family server types
 */
function isShoutcastServerType(?string $serverType): bool {
    return in_array($serverType ?? '', ['shoutcast_v1', 'shoutcast_v2', 'shoutcast_v25'], true);
}

/**
 * Stream URL for in-panel HTML5 players (Quick Control, widgets preview).
 * Uses the same host:port as the panel (e.g. :8080) and HTTP when the page is HTTP,
 * so audio works on test VPS without SSL on :443.
 */
/**
 * Panel base URL reachable from Liquidsoap (host network / Docker on same machine).
 */
function castpanelInternalPanelUrl(): string {
    $port = 80;
    try {
        $stmt = getDB()->prepare("SELECT valor FROM configuracion WHERE clave = 'site_url'");
        $stmt->execute();
        $site = trim((string)$stmt->fetchColumn());
        if ($site !== '' && ($u = parse_url($site))) {
            $port = (int)($u['port'] ?? 0);
            if ($port <= 0) {
                $port = (($u['scheme'] ?? 'http') === 'https') ? 443 : 80;
            }
        }
    } catch (Throwable $e) {
        // ignore
    }
    if ($port === 443) {
        return 'http://127.0.0.1';
    }
    return $port === 80 ? 'http://127.0.0.1' : ('http://127.0.0.1:' . $port);
}

/** Scheme + host (+ port) for links shown to users (players, M3U, DJ help text). */
function castpanelPublicPageOrigin(): string {
    if (!empty($_SERVER['HTTP_HOST'])) {
        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';

        return $scheme . '://' . $_SERVER['HTTP_HOST'];
    }
    try {
        $stmt = getDB()->prepare("SELECT valor FROM configuracion WHERE clave = 'site_url'");
        $stmt->execute();
        $site = trim((string)$stmt->fetchColumn());
        if ($site !== '') {
            return rtrim($site, '/');
        }
    } catch (Throwable $e) {
        // ignore
    }
    $host = defined('SSL_DOMAIN') ? (string)SSL_DOMAIN : 'localhost';

    return 'http://' . $host;
}

function castpanelStreamPlaybackUrl(array $estacion): string {
    $origin = castpanelPublicPageOrigin();
    $slug = rawurlencode($estacion['slug'] ?? '');
    if (isShoutcastServerType($estacion['server_type'] ?? '')) {
        return "{$origin}/shoutcast/{$slug}";
    }

    return "{$origin}/live/{$slug}";
}

/** Public hostname for encoders (no panel port suffix like :8080). */
function castpanelPublicHost(): string {
    $host = '';
    if (defined('SSL_DOMAIN') && SSL_DOMAIN !== '' && SSL_DOMAIN !== '__DOMAIN__') {
        $host = (string)SSL_DOMAIN;
    }
    if ($host === '' && defined('SERVER_IP') && SERVER_IP !== '') {
        $host = (string)SERVER_IP;
    }
    if ($host === '' && !empty($_SERVER['HTTP_HOST'])) {
        $host = (string)$_SERVER['HTTP_HOST'];
    }
    $host = preg_replace('/:\d+$/', '', trim($host));

    return $host !== '' ? $host : 'localhost';
}

/** DJ Control Center login URL (Apache vhost on DJ_PORT, default 2080 — not the cliente panel port). */
function castpanelDjPortalUrl(): string {
    $djPort = defined('DJ_PORT') ? (int)DJ_PORT : 2080;
    $host = castpanelPublicHost();
    $scheme = 'http';
    if (defined('SSL_ENABLED') && SSL_ENABLED && $djPort === 443) {
        $scheme = 'https';
    }
    try {
        $stmt = getDB()->prepare("SELECT valor FROM configuracion WHERE clave = 'dj_portal_scheme'");
        $stmt->execute();
        $cfg = strtolower(trim((string)$stmt->fetchColumn()));
        if ($cfg === 'https' || $cfg === 'http') {
            $scheme = $cfg;
        }
    } catch (Throwable $e) {
        // optional
    }
    $base = $scheme . '://' . $host;
    if ($djPort !== 80 && $djPort !== 443) {
        $base .= ':' . $djPort;
    }

    // Apache DJ vhost uses DocumentRoot = PANEL_DIR/dj — login is at :2080/ not :2080/dj/
    return rtrim($base, '/') . '/';
}

/**
 * CSRF Token
 */
function csrfToken(): string {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function csrfField(): string {
    return '<input type="hidden" name="csrf_token" value="' . csrfToken() . '">';
}

function verifyCsrf(): bool {
    return isset($_POST['csrf_token']) && hash_equals($_SESSION['csrf_token'] ?? '', $_POST['csrf_token']);
}

/**
 * Flash messages
 */
function setFlash(string $type, string $message): void {
    $_SESSION['flash'] = ['type' => $type, 'message' => $message];
}

function getFlash(): ?array {
    if (isset($_SESSION['flash'])) {
        $flash = $_SESSION['flash'];
        unset($_SESSION['flash']);
        return $flash;
    }
    return null;
}
