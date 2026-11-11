<?php
/**
 * CastPanel v2.1 - Sistema de Aprovisionamiento Automático
 *
 * Maneja: asignación de puertos, generación de configs Icecast/Liquidsoap,
 * arranque/parada de servicios, creación de directorios.
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/functions.php'; // liquidsoapCommand()
require_once __DIR__ . '/audio_processor.php';

/**
 * Rutas de binarios SHOUTcast por versión
 * Si existe un binario específico para la versión, se usa ese; si no, el default.
 */
define('SC_SERV_BINARIES', [
    'shoutcast_v2'  => '/usr/local/bin/sc_serv2',      // SHOUTcast DNAS v2.6
    'shoutcast_v25' => '/usr/local/bin/sc_serv2_v25',   // SHOUTcast DNAS v2.5
    'shoutcast_v1'  => '/usr/local/bin/sc_serv',        // SHOUTcast DNAS v1
]);
define('SC_SERV_DEFAULT', '/usr/local/bin/sc_serv2');

/**
 * Verificar si un server_type es de tipo SHOUTcast (cualquier versión)
 */
function isShoutcastType(string $serverType): bool {
    return in_array($serverType, ['shoutcast_v1', 'shoutcast_v2', 'shoutcast_v25']);
}

/**
 * Load a station together with its package flags (autodj_enabled, etc.).
 * Several callers used "SELECT * FROM estaciones" without the package join,
 * which left autodj_enabled empty and made startStation() skip Liquidsoap.
 */
function loadStationWithPackage(int $estacionId): ?array {
    $stmt = getDB()->prepare("
        SELECT e.*, p.max_storage_mb, p.max_bitrate, p.max_oyentes, p.nombre AS paquete_nombre,
               p.autodj_enabled, p.anuncios_enabled
        FROM estaciones e
        LEFT JOIN paquetes p ON p.id = e.paquete_id
        WHERE e.id = ?
    ");
    $stmt->execute([$estacionId]);
    return $stmt->fetch() ?: null;
}

/**
 * Does this station use AutoDJ (Liquidsoap)? Uses the package flag when the
 * station row already has it, otherwise looks it up from the package.
 */
function stationHasAutoDJ(array $estacion): bool {
    if (array_key_exists('autodj_enabled', $estacion)) {
        return !empty($estacion['autodj_enabled']);
    }
    if (empty($estacion['paquete_id'])) {
        return false;
    }
    $stmt = getDB()->prepare("SELECT autodj_enabled FROM paquetes WHERE id = ?");
    $stmt->execute([$estacion['paquete_id']]);
    return (bool)$stmt->fetchColumn();
}

/**
 * Obtener la ruta del binario SHOUTcast según el tipo de servidor
 */
function getShoutcastBinary(string $serverType): string {
    $binary = SC_SERV_BINARIES[$serverType] ?? SC_SERV_DEFAULT;
    // Si el binario específico no existe, usar el default
    if (!file_exists($binary)) {
        $binary = SC_SERV_DEFAULT;
    }
    return $binary;
}

/**
 * Obtener el siguiente puerto disponible para streaming
 * Para SHOUTcast: reserva portbase, portbase+1 (admin), portbase+5 (harbor)
 */
function getNextAvailablePort(): int {
    $db = getDB();

    // Leer rango configurado
    $stmt = $db->prepare("SELECT clave, valor FROM configuracion WHERE clave IN ('puerto_rango_inicio', 'puerto_rango_fin')");
    $stmt->execute();
    $config = [];
    while ($row = $stmt->fetch()) {
        $config[$row['clave']] = (int)$row['valor'];
    }

    $inicio = $config['puerto_rango_inicio'] ?? 8100;
    $fin = $config['puerto_rango_fin'] ?? 9000;

    // Construir mapa completo de puertos ocupados (incluyendo reservados SHOUTcast)
    $rows = $db->query("SELECT puerto_stream, server_type FROM estaciones WHERE puerto_stream IS NOT NULL")->fetchAll();
    $puertosOcupados = [];
    foreach ($rows as $row) {
        $p = (int)$row['puerto_stream'];
        $puertosOcupados[$p] = true;
        if (isShoutcastType($row['server_type'])) {
            $puertosOcupados[$p + 1] = true; // admin port
            $puertosOcupados[$p + 5] = true; // harbor port
        }
    }

    // Buscar primer puerto donde portbase, +1 y +5 estén todos libres
    for ($puerto = $inicio; $puerto <= $fin; $puerto++) {
        if (isset($puertosOcupados[$puerto]) ||
            isset($puertosOcupados[$puerto + 1]) ||
            isset($puertosOcupados[$puerto + 5])) {
            continue;
        }
        // Verificar que los 3 puertos no estén en uso en el sistema
        if (!isPortInUse($puerto) && !isPortInUse($puerto + 1) && !isPortInUse($puerto + 5)) {
            return $puerto;
        }
    }

    throw new RuntimeException("No hay puertos disponibles en el rango $inicio-$fin");
}

/**
 * Obtener el siguiente puerto telnet disponible para Liquidsoap
 */
function getNextTelnetPort(): int {
    $db = getDB();

    $stmt = $db->prepare("SELECT valor FROM configuracion WHERE clave = 'telnet_rango_inicio'");
    $stmt->execute();
    $inicio = (int)($stmt->fetchColumn() ?: 10000);

    $puertosUsados = $db->query("SELECT puerto_telnet FROM estaciones WHERE puerto_telnet IS NOT NULL ORDER BY puerto_telnet")->fetchAll(PDO::FETCH_COLUMN);

    for ($puerto = $inicio; $puerto <= $inicio + 1000; $puerto++) {
        if (!in_array($puerto, $puertosUsados)) {
            if (!isPortInUse($puerto)) {
                return $puerto;
            }
        }
    }

    throw new RuntimeException("No hay puertos telnet disponibles");
}

/**
 * Verificar si un puerto está en uso en el sistema
 */
function isPortInUse(int $port): bool {
    $result = @fsockopen('127.0.0.1', $port, $errno, $errstr, 1);
    if ($result) {
        fclose($result);
        return true;
    }
    return false;
}

/**
 * Aprovisionar una estación completamente
 * Se llama al crear una estación nueva
 */
function provisionStation(int $estacionId): array {
    $db = getDB();
    $log = [];

    // Obtener datos de la estación
    $stmt = $db->prepare("
        SELECT e.*, p.max_storage_mb, p.max_bitrate, p.max_oyentes, p.autodj_enabled, p.anuncios_enabled, p.nombre AS paquete_nombre
        FROM estaciones e
        LEFT JOIN paquetes p ON p.id = e.paquete_id
        WHERE e.id = ?
    ");
    $stmt->execute([$estacionId]);
    $estacion = $stmt->fetch();

    if (!$estacion) {
        return ['success' => false, 'error' => 'Estación no encontrada'];
    }

    try {
        // 1. Asignar puerto de streaming
        $puertoStream = getNextAvailablePort();
        $puertoTelnet = getNextTelnetPort();
        $serverType = $estacion['server_type'] ?? 'icecast';

        // Para SHOUTcast, icecast_port = portbase (sc_serv2); para Icecast, el servidor central
        if (isShoutcastType($serverType)) {
            $icecastPort = $puertoStream;
        } else {
            $icecastPort = (int)(defined('ICECAST_PORT') ? ICECAST_PORT : 8000);
        }
        $db->prepare("UPDATE estaciones SET puerto_stream = ?, puerto_telnet = ?, icecast_port = ? WHERE id = ?")
           ->execute([$puertoStream, $puertoTelnet, $icecastPort, $estacionId]);

        $estacion['puerto_stream'] = $puertoStream;
        $estacion['puerto_telnet'] = $puertoTelnet;
        $estacion['icecast_port'] = $icecastPort;

        $log[] = "Puerto streaming asignado: $puertoStream";
        $log[] = "Puerto telnet asignado: $puertoTelnet";

        // 2. Crear directorios de media
        $mediaDir = MEDIA_PATH . '/' . $estacion['slug'];
        $anunciosDir = ANUNCIOS_PATH . '/' . $estacion['slug'];

        if (!is_dir($mediaDir)) {
            @mkdir($mediaDir, 0755, true);
            $log[] = "Directorio media creado: $mediaDir";
        }
        if (!is_dir($anunciosDir)) {
            @mkdir($anunciosDir, 0755, true);
            $log[] = "Directorio anuncios creado: $anunciosDir";
        }

        // 3. Generar mount en Icecast
        if ($estacion['server_type'] === 'icecast') {
            $mountResult = addIcecastMount($estacion);
            if ($mountResult) {
                $log[] = "Mount Icecast configurado: " . $estacion['icecast_mount'];
            }
        }

        // 3b. SHOUTcast config + proxy (process start happens in step 7 via startStation)
        if (isShoutcastType($serverType)) {
            require_once __DIR__ . '/shoutcast.php';
            $gen = generateShoutcastConfig($estacion);
            if ($gen['success'] ?? false) {
                $log[] = 'SHOUTcast config written: ' . ($gen['file'] ?? $estacion['slug']);
            } else {
                $log[] = 'AVISO: SHOUTcast config: ' . ($gen['error'] ?? 'unknown');
            }
            addShoutcastProxyRule($estacion['slug'], $puertoStream);
            $log[] = "Proxy Apache configurado: /shoutcast/{$estacion['slug']}";
        }

        // 4. Playlist por defecto (antes de Liquidsoap — active.m3u la necesita)
        $stmt = $db->prepare("INSERT INTO playlists (estacion_id, nombre, slug) VALUES (?, 'General', 'general')");
        $stmt->execute([$estacionId]);
        $defaultPlaylistId = (int)$db->lastInsertId();
        $db->prepare("UPDATE estaciones SET autodj_playlist_activa = ? WHERE id = ?")
           ->execute([$defaultPlaylistId, $estacionId]);
        $estacion['autodj_playlist_activa'] = $defaultPlaylistId;
        $log[] = "Playlist 'General' creada y marcada como activa";

        // 5. Generar configuración Liquidsoap (solo si AutoDJ habilitado)
        if (!empty($estacion['autodj_enabled'])) {
            $liqResult = generateLiquidsoapConfig($estacion);
            if ($liqResult['success']) {
                $log[] = "Config Liquidsoap generado: " . $liqResult['file'];
            } else {
                $log[] = "AVISO: Config Liquidsoap: " . ($liqResult['error'] ?? 'error');
            }
        } else {
            $log[] = "Liquidsoap omitido (AutoDJ deshabilitado)";
        }

        // 6. Abrir puertos en firewall: streaming + harbor (DJ externo)
        $harborPort = $puertoStream + 5;
        castpanelCtl("firewall_open {$puertoStream}/tcp");
        castpanelCtl("firewall_open {$harborPort}/tcp");
        castpanelCtl("firewall_reload");
        $log[] = "Puertos abiertos en firewall: streaming=$puertoStream, harbor=$harborPort";

        // 7. Start the whole station (SHOUTcast + Liquidsoap when AutoDJ) so new clients see Online
        $estacion = loadStationWithPackage($estacionId) ?: $estacion;
        if (stationHasAutoDJ($estacion) || isShoutcastType($serverType) || $estacion['server_type'] === 'icecast') {
            if (!castpanelStreamingAvailable()) {
                $log[] = 'AVISO: Streaming daemon not available — station created but not started (local dev or castpanel-ctl stopped).';
            } else {
                $startResult = startStation($estacion);
                if ($startResult['success'] ?? false) {
                    $log[] = 'Estación iniciada (stream + AutoDJ si aplica)';
                } else {
                    $log[] = 'AVISO: No se pudo iniciar la estación: ' . ($startResult['error'] ?? 'error');
                }
            }
        }

        return ['success' => true, 'log' => $log, 'puerto' => $puertoStream, 'telnet' => $puertoTelnet];

    } catch (Exception $e) {
        return ['success' => false, 'error' => $e->getMessage(), 'log' => $log];
    }
}

/**
 * Des-aprovisionar una estación (al eliminar)
 */
function deprovisionStation(array $estacion): array {
    $log = [];
    $serverType = $estacion['server_type'] ?? 'icecast';

    // 1. Detener Liquidsoap
    stopLiquidsoapService($estacion);
    $log[] = "Servicio Liquidsoap detenido";

    // 2. Detener SHOUTcast y remover proxy si aplica
    if (isShoutcastType($serverType)) {
        stopShoutcastService($estacion);
        $log[] = "Servicio SHOUTcast detenido";
        removeShoutcastProxyRule($estacion['slug']);
        $log[] = "Proxy Apache removido: /shoutcast/{$estacion['slug']}";
    }

    // 3. Eliminar config Liquidsoap
    $configPath = getLiquidsoapConfigPath($estacion['slug']);
    if (file_exists($configPath)) {
        @unlink($configPath);
        $log[] = "Config Liquidsoap eliminado: $configPath";
    }

    // 4. Eliminar mount de Icecast
    if ($serverType === 'icecast') {
        removeIcecastMount($estacion);
        $log[] = "Mount Icecast removido";
    }

    // 5. Eliminar directorios (opcional, los archivos quedan)
    // No eliminamos media por seguridad, el admin puede hacerlo manual

    return ['success' => true, 'log' => $log];
}

/**
 * Eliminar estación del panel + des-aprovisionar + usuario cliente huérfano
 */
function deleteStationFully(array $estacion): array {
    $db = getDB();
    $stationId = (int)$estacion['id'];
    $usuarioId = (int)($estacion['usuario_id'] ?? 0);

    deprovisionStation($estacion);

    $db->prepare("DELETE FROM estaciones WHERE id = ?")->execute([$stationId]);

    $mediaDir = MEDIA_PATH . '/' . $estacion['slug'];
    $adsDir = ANUNCIOS_PATH . '/' . $estacion['slug'];
    if (is_dir($mediaDir)) {
        foreach (glob("$mediaDir/*") ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($mediaDir);
    }
    if (is_dir($adsDir)) {
        foreach (glob("$adsDir/*") ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($adsDir);
    }

    if ($usuarioId > 0) {
        $stmt = $db->prepare("SELECT COUNT(*) FROM estaciones WHERE usuario_id = ?");
        $stmt->execute([$usuarioId]);
        if ((int)$stmt->fetchColumn() === 0) {
            $db->prepare("DELETE FROM usuarios WHERE id = ? AND rol = 'cliente'")->execute([$usuarioId]);
        }
    }

    return ['success' => true];
}

/**
 * Agregar mount point a la configuración de Icecast
 */
function addIcecastMount(array $estacion): bool {
    $db = getDB();
    $stmt = $db->prepare("SELECT valor FROM configuracion WHERE clave = 'icecast_config_path'");
    $stmt->execute();
    $configPath = $stmt->fetchColumn() ?: '/etc/icecast2/icecast.xml';

    if (!file_exists($configPath)) {
        return false;
    }

    $xml = file_get_contents($configPath);
    $mount = $estacion['icecast_mount'] ?: ('/' . $estacion['slug']);
    $password = $estacion['icecast_password'] ?: 'hackme';
    $maxOyentes = 0;

    // Obtener límite de oyentes del paquete
    if ($estacion['paquete_id']) {
        $stmt = $db->prepare("SELECT max_oyentes FROM paquetes WHERE id = ?");
        $stmt->execute([$estacion['paquete_id']]);
        $maxOyentes = (int)$stmt->fetchColumn();
    }

    // Bloque de mount point
    $mountBlock = "\n    <!-- Mount auto-generado: {$estacion['slug']} -->\n";
    $mountBlock .= "    <mount type=\"normal\">\n";
    $mountBlock .= "        <mount-name>{$mount}</mount-name>\n";
    $mountBlock .= "        <password>{$password}</password>\n";
    if ($maxOyentes > 0) {
        $mountBlock .= "        <max-listeners>{$maxOyentes}</max-listeners>\n";
    }
    $mountBlock .= "        <fallback-override>1</fallback-override>\n";
    $mountBlock .= "        <hidden>0</hidden>\n";
    $mountBlock .= "        <public>0</public>\n";
    $mountBlock .= "    </mount>\n";

    // Insertar antes del cierre de </icecast>
    if (strpos($xml, "<!-- Mount auto-generado: {$estacion['slug']} -->") !== false) {
        // Ya existe, no duplicar
        return true;
    }

    $xml = str_replace('</icecast>', $mountBlock . '</icecast>', $xml);
    file_put_contents($configPath, $xml);

    // Recargar Icecast via daemon (stop + start)
    castpanelCtl("stop-icecast");
    castpanelCtl("start-icecast");

    return true;
}

/**
 * Eliminar mount point de Icecast
 */
function removeIcecastMount(array $estacion): bool {
    $db = getDB();
    $stmt = $db->prepare("SELECT valor FROM configuracion WHERE clave = 'icecast_config_path'");
    $stmt->execute();
    $configPath = $stmt->fetchColumn() ?: '/etc/icecast2/icecast.xml';

    if (!file_exists($configPath)) {
        return false;
    }

    $xml = file_get_contents($configPath);

    // Remover el bloque del mount
    $pattern = '/\n\s*<!-- Mount auto-generado: ' . preg_quote($estacion['slug'], '/') . ' -->.*?<\/mount>\n/s';
    $xml = preg_replace($pattern, "\n", $xml);
    file_put_contents($configPath, $xml);

    // Recargar Icecast via daemon
    castpanelCtl("stop-icecast");
    castpanelCtl("start-icecast");

    return true;
}

/**
 * Directorio donde PHP escribe los .liq (debe ser escribible por el usuario del
 * servidor web; /etc suele ser de solo lectura con Apache + ProtectSystem=full).
 */
function getLiquidsoapConfigDir(): string {
    if (defined('LIQUIDSOAP_CONFIG_DIR') && LIQUIDSOAP_CONFIG_DIR !== '') {
        return rtrim(LIQUIDSOAP_CONFIG_DIR, '/');
    }
    try {
        $stmt = getDB()->prepare("SELECT valor FROM configuracion WHERE clave = 'liquidsoap_config_path'");
        $stmt->execute();
        $dir = $stmt->fetchColumn();
        if (is_string($dir) && $dir !== '') {
            // Rutas legacy bajo /etc no funcionan con mod_php en muchos servidores
            if (str_starts_with($dir, '/etc/')) {
                return '/var/castpanel/liquidsoap/stations';
            }
            return rtrim($dir, '/');
        }
    } catch (Exception $e) {
        // ignore
    }
    return '/var/castpanel/liquidsoap/stations';
}

/**
 * Obtener ruta del config Liquidsoap para una estación
 */
function getLiquidsoapConfigPath(string $slug): string {
    return getLiquidsoapConfigDir() . '/' . $slug . '.liq';
}

/**
 * Generar configuración Liquidsoap para una estación
 */
function generateLiquidsoapConfig(array $estacion): array {
    $db = getDB();
    require_once __DIR__ . '/liquidsoap_playlist.php';

    $slug = $estacion['slug'];
    $serverType = $estacion['server_type'] ?? 'icecast';

    // Puerto de output: SHOUTcast usa icecast_port (=portbase sc_serv2), Icecast usa el servidor central
    if (isShoutcastType($serverType)) {
        $icecastPort = (int)($estacion['icecast_port'] ?? $estacion['puerto_stream'] ?? 8000);
    } else {
        $icecastPort = (int)(defined('ICECAST_PORT') ? ICECAST_PORT : 8000);
    }

    // Puerto harbor: SHOUTcast = portbase+5, Icecast = puerto_stream
    if (isShoutcastType($serverType)) {
        $harborPort = (int)($estacion['puerto_stream']) + 5;
    } else {
        $harborPort = (int)($estacion['puerto_stream'] ?: ($icecastPort + 100 + (int)$estacion['id']));
    }

    $telnetPort = $estacion['puerto_telnet'] ?: (LIQUIDSOAP_TELNET_PORT + $estacion['id']);
    $mount = $estacion['icecast_mount'] ?: ('/' . $slug);
    $password = $estacion['icecast_password'] ?: 'hackme';
    $bitrate = $estacion['bitrate'] ?: 128;
    $samplerate = $estacion['samplerate'] ?: 44100;
    $codec = $estacion['codec'] ?: 'mp3';
    $crossfade = $estacion['crossfade_seconds'] ?? 0;

    // Rutas dentro del contenedor Docker (los volúmenes montan host→contenedor):
    //   /var/castpanel/media/{slug}    → /media
    //   /var/castpanel/anuncios/{slug} → /anuncios
    // Liquidsoap corre DENTRO del contenedor, así que debe usar las rutas del contenedor
    $anunciosDir = '/anuncios';

    $m3uResult = castpanelWriteActivePlaylistM3u($estacion);
    if (!$m3uResult['success']) {
        return ['success' => false, 'error' => $m3uResult['error'] ?? 'No se pudo generar active.m3u'];
    }
    $playlistUri = $m3uResult['liquidsoap_uri'];
    $modoRep = $estacion['modo_reproduccion'] ?? 'normal';
    $playMode = ($modoRep === 'aleatorio') ? 'randomize' : 'normal';

    // Construir bloque de encoding según codec
    $encodingBlock = match($codec) {
        'aac' => "%fdkaac(channels=2, samplerate=$samplerate, bitrate=$bitrate, afterburner=true, aot=\"mpeg4_he_aac_v2\")",
        'ogg' => "%vorbis(channels=2, samplerate=$samplerate, quality=0.6)",
        default => "%mp3(bitrate=$bitrate, samplerate=$samplerate, stereo=true)",
    };

    // Construir bloque de output según tipo de servidor
    if (isShoutcastType($serverType)) {
        $outputBlock = <<<LIQ
output.shoutcast(
    $encodingBlock,
    id="output_{$slug}",
    host="127.0.0.1",
    port=$icecastPort,
    password="$password",
    name="$slug",
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
        $outputBlock = <<<LIQ
output.icecast(
    $encodingBlock,
    id="output_{$slug}",
    host="127.0.0.1",
    port=$icecastPort,
    password="$password",
    mount="$mount",
    name="$slug",
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

    $panelUrl = castpanelInternalPanelUrl();

    // Config completo
    $config = <<<LIQ
#!/usr/bin/liquidsoap
# ==============================================
# CastPanel - Configuración automática
# Estación: $slug
# Generado: {date('Y-m-d H:i:s')}
# ==============================================

settings.server.telnet.set(true)
settings.server.telnet.bind_addr.set("127.0.0.1")
settings.server.telnet.port.set($telnetPort)

# Logging
settings.log.file.path.set("/var/log/liquidsoap/{$slug}.log")
settings.log.level.set(3)

# === FUENTES DE AUDIO ===

# Playlist principal (solo canciones de la playlist activa — active.m3u)
music_src = playlist(
    id="music_{$slug}",
    mode="{$playMode}",
    reload=10,
    reload_mode="watch",
    "{$playlistUri}"
)

# Prefer M3U #EXTINF titles over embedded MP3 ID3 (fixes stuck “tiki” on SHOUTcast/panel).
def m3u_stream_meta(m) =
    s = list.assoc(default="", "song", m)
    t = if s != "" then s else list.assoc(default="", "title", m) end
    [("title", t), ("artist", "")]
end
music_src = map_metadata(m3u_stream_meta, music_src)

# Anuncios (spots publicitarios)
ads_src = playlist(
    id="ads_{$slug}",
    mode="randomize",
    reload=30,
    reload_mode="rounds",
    "$anunciosDir"
)

# Silencio como fallback final (tono a -50dB para indicar stream activo)
silence = blank(id="silence_{$slug}")

# === PROCESAMIENTO ===

# Envolver fuentes fallibles con fallback a silencio
# Esto evita que un directorio vacio detenga toda la cadena
music = fallback(id="music_safe_{$slug}", track_sensitive=false, [music_src, silence])
ads = fallback(id="ads_safe_{$slug}", track_sensitive=false, [ads_src, silence])

# Insertar anuncios cada 5 canciones
radio = rotate(
    id="radio_{$slug}",
    weights=[5, 1],
    [music, ads]
)

LIQ;

    // Agregar crossfade si está configurado
    if ($crossfade > 0) {
        $config .= <<<LIQ

# Crossfade entre pistas
radio = crossfade(
    duration=$crossfade.0,
    radio
)

LIQ;
    }

    // URL del panel para DJ auth webhook (Liquidsoap llama HTTP desde Docker)
    // Liquidsoap corre en Docker con host networking — usar HTTP local directo
    // (HTTPS no funciona porque el contenedor no tiene certificados CA)
    $djAuthUrl = castpanelInternalPanelUrl() . '/api/dj_auth.php';

    // Construir bloque de autenticación harbor
    if ($djAuthUrl) {
        // Auth via HTTP webhook - valida cuentas DJ desde la base de datos
        $harborAuthBlock = <<<LIQ
    auth=fun(args) -> begin
        let auth_url = "$djAuthUrl" ^ "?username=" ^ url.encode(args.user) ^ "&password=" ^ url.encode(args.password) ^ "&station=$slug"
        log("DJ Auth: verificando '#{args.user}' para '$slug'")
        let result = string.trim(http.get(auth_url))
        if result == "true" then
            log("DJ Auth: '#{args.user}' autenticado OK")
            true
        else
            log("DJ Auth: '#{args.user}' rechazado")
            false
        end
    end
LIQ;
    } else {
        // Fallback: password estática de la estación
        $harborAuthBlock = "    password=\"$password\"";
    }

    $config .= <<<LIQ

# Entrada DJ en vivo (input.harbor)
# icy=true: acepta conexiones SHOUTcast/ICY (RadioCaster, BUTT, etc.)
# mount="/": sin mount point - cada estación tiene su propio puerto harbor
live_dj = input.harbor(
    id="live_{$slug}",
    "/",
    port=$harborPort,
    icy=true,
    buffer=1.0,
    max=5.0,
$harborAuthBlock
)

# DJ en vivo tiene prioridad sobre AutoDJ
radio = fallback(
    id="fallback_{$slug}",
    track_sensitive=false,
    transitions=[fun(a,b) -> b, fun(a,b) -> b],
    [live_dj, radio, silence]
)

LIQ;

    // Procesador de audio (AGC → Compresor → EQ → Limitador → Ganancia)
    $procConfig = getProcessorConfig($estacion['id']);
    $config .= generateProcessorLiquidsoap($procConfig);

    $config .= <<<LIQ

# Webhook de metadata al panel (loopback — contenedor Docker)
def on_metadata(m) =
    title = m["title"]
    artist = m["artist"]
    ignore(
        http.post(
            headers=[("Content-Type", "application/x-www-form-urlencoded")],
            data="station=$slug&title=#{title}&artist=#{artist}",
            "$panelUrl/api/webhook.php"
        )
    )
end
radio.on_metadata(on_metadata)

LIQ;

    $config .= <<<LIQ

# === SALIDA ===
$outputBlock

LIQ;

    // Guardar archivo
    $configDir = getLiquidsoapConfigDir();

    if (!is_dir($configDir)) {
        @mkdir($configDir, 0775, true);
    }
    if (!is_dir($configDir) || !is_writable($configDir)) {
        $hint = 'En el servidor (root): sudo bash ' . (defined('BASE_PATH') ? BASE_PATH : '/var/www/castpanel') . '/install/setup_castpanel_ctl.sh';
        return ['success' => false, 'error' => "No se pudo escribir $configDir ($hint)"];
    }

    $configFile = $configDir . '/' . $slug . '.liq';
    $written = file_put_contents($configFile, $config);

    if ($written === false) {
        return ['success' => false, 'error' => "No se pudo escribir $configFile"];
    }

    @chmod($configFile, 0644);

    return ['success' => true, 'file' => $configFile];
}

/**
 * Iniciar servicio Liquidsoap para una estación (Docker)
 */
function startLiquidsoapService(array $estacion): array {
    $slug = $estacion['slug'];
    $configFile = getLiquidsoapConfigPath($slug);
    $containerName = "liquidsoap-$slug";

    if (!file_exists($configFile)) {
        return ['success' => false, 'error' => "Config no encontrado: $configFile"];
    }

    // Enviar comando nombrado al daemon castpanel-ctl
    // El daemon maneja internamente: docker rm, docker run con mounts correctos
    $result = castpanelCtl("start-liquidsoap-$slug");

    $db = getDB();
    $markOffline = static function () use ($db, $estacion): void {
        $db->prepare("UPDATE estaciones SET servicio_estado = 'stopped', autodj_estado = 'offline' WHERE id = ?")
           ->execute([$estacion['id']]);
    };

    if (!$result['success'] && !liquidsoapContainerIsRunning($slug)) {
        $markOffline();
        $why = $result['error'] ?? $result['output'] ?? $result['message'] ?? '';
        $why = trim((string)$why) !== '' ? trim((string)$why) : 'error desconocido';
        return ['success' => false, 'error' => 'No se pudo iniciar Liquidsoap: ' . $why];
    }

    // Daemon started the container (or it was already up) — confirm Docker is really running.
    usleep(500000);
    if (!liquidsoapContainerIsRunning($slug)) {
        $markOffline();
        $why = $result['error'] ?? $result['output'] ?? '';
        $why = trim((string)$why) !== '' ? trim((string)$why) : 'el contenedor se detuvo al iniciar (revisar log Liquidsoap)';
        return ['success' => false, 'error' => 'Liquidsoap no está en ejecución: ' . $why];
    }

    $db->prepare("UPDATE estaciones SET servicio_estado = 'running', autodj_estado = 'online' WHERE id = ?")
       ->execute([$estacion['id']]);

    $message = ($result['success'] ?? false) ? null : 'already running';
    return array_filter([
        'success' => true,
        'container' => $containerName,
        'message' => $message,
    ]);
}

/**
 * Detener servicio Liquidsoap para una estación (Docker)
 */
function stopLiquidsoapService(array $estacion): array {
    $slug = $estacion['slug'];
    $containerName = "liquidsoap-$slug";

    // Enviar comando nombrado al daemon castpanel-ctl
    // El daemon maneja internamente: docker stop + docker rm
    castpanelCtl("stop-liquidsoap-$slug");

    // Actualizar BD
    $db = getDB();
    $db->prepare("UPDATE estaciones SET servicio_estado = 'stopped', pid_liquidsoap = NULL, autodj_estado = 'offline' WHERE id = ?")
       ->execute([$estacion['id']]);

    return ['success' => true];
}

/**
 * Reiniciar servicio Liquidsoap (Docker)
 */
function restartLiquidsoapService(array $estacion): array {
    stopLiquidsoapService($estacion);
    sleep(2);
    return startLiquidsoapService($estacion);
}

/**
 * Verificar si el contenedor Docker de Liquidsoap está corriendo (Docker, no solo BD).
 */
function liquidsoapContainerIsRunning(string $slug): bool {
    $r = castpanelCtl('liquidsoap-status-' . $slug);
    return !empty($r['success']) && !empty($r['running']);
}

/**
 * @deprecated Prefer liquidsoapContainerIsRunning(); kept for callers that only need a quick hint.
 */
function isLiquidsoapRunning(string $slug): bool {
    if (liquidsoapContainerIsRunning($slug)) {
        return true;
    }
    $db = getDB();
    $stmt = $db->prepare("SELECT servicio_estado FROM estaciones WHERE slug = ?");
    $stmt->execute([$slug]);
    return $stmt->fetchColumn() === 'running';
}

/**
 * Obtener PID del proceso Liquidsoap de una estación (legacy + Docker)
 */
function getLiquidsoapPid(string $slug): int {
    // Verificar estado via BD
    if (isLiquidsoapRunning($slug)) {
        return 1; // Corriendo (PID real no accesible desde PHP sin sudo)
    }
    return 0;
}

/**
 * Obtener resumen del estado de aprovisionamiento
 */
function getProvisioningStatus(int $estacionId): array {
    $db = getDB();
    $stmt = $db->prepare("SELECT e.*, p.nombre AS paquete_nombre, p.max_oyentes, p.max_bitrate, p.max_storage_mb
        FROM estaciones e LEFT JOIN paquetes p ON p.id = e.paquete_id WHERE e.id = ?");
    $stmt->execute([$estacionId]);
    $estacion = $stmt->fetch();

    if (!$estacion) {
        return ['provisioned' => false];
    }

    $slug = $estacion['slug'];
    $configFile = getLiquidsoapConfigPath($slug);

    return [
        'provisioned' => $estacion['puerto_stream'] !== null,
        'puerto_stream' => $estacion['puerto_stream'],
        'puerto_telnet' => $estacion['puerto_telnet'],
        'config_exists' => file_exists($configFile),
        'service_running' => isLiquidsoapRunning($slug),
        'media_dir_exists' => is_dir(MEDIA_PATH . '/' . $slug),
        'estacion' => $estacion,
    ];
}

/**
 * Crear cuenta de cliente con estación aprovisionada (todo-en-uno)
 * Usado por la API externa y WHMCS
 */
function createAccountWithStation(array $params): array {
    $db = getDB();

    $username = $params['username'] ?? '';
    $password = $params['password'] ?? '';
    $email = $params['email'] ?? '';
    $nombre = $params['nombre'] ?? '';
    $paqueteId = (int)($params['paquete_id'] ?? 0);
    $stationName = $params['station_name'] ?? $username;
    $resellerId = (int)($params['reseller_id'] ?? 0);

    if (!$username || !$password) {
        return ['success' => false, 'error' => 'username y password son obligatorios'];
    }

    // Verificar username único
    $check = $db->prepare("SELECT id FROM usuarios WHERE username = ?");
    $check->execute([$username]);
    if ($check->fetch()) {
        return ['success' => false, 'error' => 'El usuario ya existe'];
    }

    // Verificar paquete
    if ($paqueteId) {
        $stmt = $db->prepare("SELECT id FROM paquetes WHERE id = ? AND activo = 1");
        $stmt->execute([$paqueteId]);
        if (!$stmt->fetch()) {
            return ['success' => false, 'error' => 'Paquete no encontrado o inactivo'];
        }
    }

    $db->beginTransaction();
    try {
        // Crear usuario
        $stmt = $db->prepare("INSERT INTO usuarios (username, password, email, nombre, rol, reseller_id) VALUES (?,?,?,?,?,?)");
        $stmt->execute([
            $username,
            password_hash($password, PASSWORD_DEFAULT),
            $email,
            $nombre,
            'cliente',
            $resellerId ?: null
        ]);
        $userId = (int)$db->lastInsertId();

        // Crear estación
        $slug = slugify($stationName);
        // Asegurar slug único
        $originalSlug = $slug;
        $counter = 1;
        while (true) {
            $check = $db->prepare("SELECT id FROM estaciones WHERE slug = ?");
            $check->execute([$slug]);
            if (!$check->fetch()) break;
            $slug = $originalSlug . '-' . $counter++;
        }

        $mount = '/' . $slug;
        $sourcePass = !empty($params['source_password']) ? $params['source_password'] : bin2hex(random_bytes(8));

        $stmt = $db->prepare("INSERT INTO estaciones (usuario_id, paquete_id, nombre, slug, icecast_mount, icecast_password, server_type, codec, bitrate, samplerate) VALUES (?,?,?,?,?,?,?,?,?,?)");
        $stmt->execute([
            $userId,
            $paqueteId ?: null,
            $stationName,
            $slug,
            $mount,
            $sourcePass,
            $params['server_type'] ?? 'icecast',
            $params['codec'] ?? 'mp3',
            (int)($params['bitrate'] ?? 128),
            (int)($params['samplerate'] ?? 44100)
        ]);
        $stationId = (int)$db->lastInsertId();

        $db->commit();

        // Aprovisionar estación
        $provision = provisionStation($stationId);

        return [
            'success' => true,
            'user_id' => $userId,
            'station_id' => $stationId,
            'username' => $username,
            'slug' => $slug,
            'mount' => $mount,
            'source_password' => $sourcePass,
            'puerto' => $provision['puerto'] ?? null,
            'provision_log' => $provision['log'] ?? [],
        ];

    } catch (Exception $e) {
        $db->rollBack();
        return ['success' => false, 'error' => $e->getMessage()];
    }
}

// ============================================================
// SHOUTcast v2 Management Functions
// ============================================================

/** Whether privileged streaming (castpanel-ctl) is available on this host. */
function castpanelStreamingAvailable(): bool {
    if (defined('CASTPANEL_STREAMING_DISABLED') && CASTPANEL_STREAMING_DISABLED) {
        return false;
    }
    $ipcDir = '/var/run/castpanel';

    return is_dir($ipcDir)
        && is_writable($ipcDir)
        && (is_executable('/usr/local/bin/castpanel-ctl') || file_exists('/etc/systemd/system/castpanel-ctl.service'));
}

/**
 * Enviar comando al daemon castpanel-ctl (root) via IPC
 * El daemon corre como root y ejecuta comandos privilegiados
 */
function castpanelCtl(string $command): array {
    $command = trim($command);
    if ($command === '') {
        return ['success' => false, 'error' => 'Comando IPC vacío'];
    }
    if (!castpanelStreamingAvailable()) {
        $msg = (defined('CASTPANEL_STREAMING_DISABLED') && CASTPANEL_STREAMING_DISABLED)
            ? 'AutoDJ is not available in local Docker dev — use a Linux server with install.sh (or the test VPS).'
            : 'castpanel-ctl service is not running. On the server run: sudo systemctl enable --now castpanel-ctl';

        return ['success' => false, 'error' => $msg];
    }
    $ipcDir = '/var/run/castpanel';
    $cmdFile = "$ipcDir/command";
    $resFile = "$ipcDir/result";
    $lockFile = "$ipcDir/lock";

    // Crear directorio IPC si no existe (puede perderse tras reboot en tmpfs)
    if (!is_dir($ipcDir)) {
        @mkdir($ipcDir, 0755, true);
    }

    // Esperar si hay otro comando en curso (a lock older than 60 s is left
    // over from a crashed request and is ignored)
    $maxWait = 10;
    while (file_exists($lockFile) && (time() - (int)@filemtime($lockFile)) < 60 && $maxWait-- > 0) {
        usleep(500000); // 0.5s
        clearstatcache(true, $lockFile);
    }

    // Crear lock
    @file_put_contents($lockFile, getmypid());

    // Limpiar resultado anterior
    @unlink($resFile);

    // Enviar comando
    if (@file_put_contents($cmdFile, $command) === false) {
        @unlink($lockFile);
        return ['success' => false, 'error' => 'No se pudo escribir comando IPC'];
    }

    // Esperar resultado (max 30 segundos)
    $timeout = 30;
    $start = time();
    while (!file_exists($resFile) && (time() - $start) < $timeout) {
        usleep(200000); // 0.2s
        clearstatcache();
        // The daemon removes the command file as soon as it reads it. If it is
        // still there after 5 s, the daemon is not running: fail now instead
        // of making the page wait the full 30 s for every command.
        if ((time() - $start) >= 5 && file_exists($cmdFile) && !file_exists($resFile)) {
            @unlink($cmdFile);
            @unlink($lockFile);
            return ['success' => false, 'error' => 'castpanel-ctl service is not running (sudo systemctl enable --now castpanel-ctl)'];
        }
    }

    if (!file_exists($resFile)) {
        @unlink($lockFile);
        return ['success' => false, 'error' => 'Timeout esperando respuesta del daemon'];
    }

    $result = @file_get_contents($resFile);
    @unlink($resFile);
    @unlink($lockFile);

    $data = @json_decode($result, true);
    if ($data) {
        return $data;
    }
    return ['success' => true, 'output' => $result];
}

/**
 * Iniciar servicio SHOUTcast sc_serv2 para una estación
 */
function startShoutcastService(array $estacion): array {
    $slug = $estacion['slug'];
    $serverType = $estacion['server_type'] ?? 'shoutcast_v2';
    $portbase = (int)($estacion['icecast_port'] ?? $estacion['puerto_stream'] ?? 0);

    if ($portbase <= 0) {
        return ['success' => false, 'error' => 'Puerto no asignado'];
    }

    // Determinar binario SHOUTcast según versión
    $scBinary = getShoutcastBinary($serverType);

    // Generar/actualizar configuración SHOUTcast
    $configDir = '/opt/shoutcast/configs';
    $configFile = "$configDir/$slug.conf";

    // Siempre regenerar config para asegurar binario correcto
    $password = $estacion['icecast_password'] ?? '2525';
    $adminPass = defined('SHOUTCAST_ADMIN_PASS') ? SHOUTCAST_ADMIN_PASS : 'CastPanel_Admin_2024!';
    $maxListeners = 32;

    // Obtener limite de oyentes del paquete
    if (!empty($estacion['paquete_id'])) {
        $db = getDB();
        $stmt = $db->prepare("SELECT max_oyentes FROM paquetes WHERE id = ?");
        $stmt->execute([$estacion['paquete_id']]);
        $maxListeners = (int)($stmt->fetchColumn() ?: 32);
    }

    $scConfig = "portbase=$portbase\npassword=$password\nadminpassword=$adminPass\nmaxuser=$maxListeners\n";
    // CastPanel v7.4: srcip condicional segun autodj_enabled
    // AutoDJ=1 -> srcip=127.0.0.1 (solo Liquidsoap local conecta como source)
    // AutoDJ=0 -> srcip=0.0.0.0 (DJ externo conecta directo desde internet)
    $autoDJ = stationHasAutoDJ($estacion);
    $srcIp = $autoDJ ? '127.0.0.1' : '0.0.0.0';
    $scConfig .= "logfile=/var/log/shoutcast/$slug.log\nsrcip=$srcIp\ndestip=0.0.0.0\npublicserver=never\n";
    // Guardar ruta del binario y versión en la config para que el daemon sepa cuál usar
    $scConfig .= "; CastPanel metadata\n";
    $scConfig .= "; sc_binary=$scBinary\n";
    $scConfig .= "; server_type=$serverType\n";

    // Escribir config via daemon (write-shoutcast-config no existe, intentar directamente)
    // Si el directorio no es escribible por Apache, el daemon start-shoutcast lo manejará
    @mkdir($configDir, 0755, true);
    @file_put_contents($configFile, $scConfig);

    // Crear/actualizar symlink del binario para esta estación
    // Esto permite que el daemon use el binario correcto sin modificar castpanel-ctl.sh
    $binLinkDir = '/opt/shoutcast/bin';
    @mkdir($binLinkDir, 0755, true);
    $binLink = "$binLinkDir/$slug";
    // Escribir un pequeño wrapper script que llama al binario correcto
    $wrapperScript = "#!/bin/bash\nexec $scBinary \"\$@\"\n";
    @file_put_contents($binLink, $wrapperScript);
    @chmod($binLink, 0755);

    // Enviar comando nombrado al daemon castpanel-ctl
    // El daemon maneja internamente el inicio de sc_serv2
    $result = castpanelCtl("start-shoutcast-$slug");

    // Fallback: si el IPC falla, iniciar sc_serv2 directamente
    if (!($result['success'] ?? false)) {
        // Verificar que no esté ya corriendo
        $checkCmd = "pgrep -f 'sc_serv2.*{$slug}.conf' 2>/dev/null";
        $running = trim(shell_exec($checkCmd) ?? '');
        if (empty($running)) {
            // Crear directorio de logs si no existe
            @mkdir('/var/log/shoutcast', 0755, true);
            // Iniciar sc_serv2 directamente como daemon
            $startCmd = "$scBinary daemon $configFile >/dev/null 2>&1 &";
            exec($startCmd);
            // Esperar a que arranque
            usleep(1500000); // 1.5s
            $running = trim(shell_exec($checkCmd) ?? '');
        }
        if (!empty($running)) {
            $result = ['success' => true, 'output' => "SHOUTcast iniciado directamente (PID: $running)"];
        }
    }

    if ($result['success'] ?? false) {
        // Actualizar BD
        $db = getDB();
        $db->prepare("UPDATE estaciones SET servicio_estado = 'running' WHERE id = ?")
           ->execute([$estacion['id']]);
    }

    return $result;
}

/**
 * Detener servicio SHOUTcast sc_serv2 para una estación
 */
function stopShoutcastService(array $estacion): array {
    $slug = $estacion['slug'];

    // Enviar comando nombrado al daemon castpanel-ctl
    // El daemon maneja internamente: kill/stop de sc_serv2
    castpanelCtl("stop-shoutcast-$slug");

    return ['success' => true];
}

/**
 * Regenerar reglas ProxyPass SHOUTcast e instalar vía castpanel-ctl (root escribe en /etc/apache2).
 */
function updateShoutcastProxyConfig(): bool {
    $db = getDB();

    $stmt = $db->query(
        "SELECT slug, puerto_stream FROM estaciones
         WHERE server_type IN ('shoutcast_v1', 'shoutcast_v2', 'shoutcast_v25')
         AND activa = 1
         ORDER BY id ASC"
    );
    $estaciones = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $content = "# Auto-generado por updateShoutcastProxyConfig()\n";
    $content .= "# CastPanel - NO EDITAR MANUALMENTE\n";
    $content .= "# Ultima actualizacion: " . date('Y-m-d H:i:s') . "\n";
    $content .= "# Total estaciones: " . count($estaciones) . "\n\n";

    foreach ($estaciones as $est) {
        $slug = preg_replace('/[^a-z0-9\-]/', '', strtolower($est['slug']));
        $port = (int)$est['puerto_stream'];
        if ($port <= 0 || $slug === '') {
            continue;
        }
        $content .= "# Estacion: $slug (puerto $port)\n";
        $content .= "ProxyPass /shoutcast/$slug http://127.0.0.1:$port/stream/1/ retry=1 flushpackets=on\n";
        $content .= "ProxyPassReverse /shoutcast/$slug http://127.0.0.1:$port/stream/1/\n\n";
    }

    $tmpFile = '/tmp/castpanel-shoutcast-proxy.conf';
    if (@file_put_contents($tmpFile, $content) === false) {
        error_log("CastPanel: No se pudo escribir $tmpFile");
        return false;
    }
    @chmod($tmpFile, 0644);

    $r = castpanelCtl('update-shoutcast-proxy');
    if (empty($r['success'])) {
        error_log('CastPanel: update-shoutcast-proxy fallo: ' . ($r['error'] ?? $r['output'] ?? ''));
        return false;
    }
    return true;
}

/**
 * Recargar Apache de forma segura: valida la config primero.
 * Si la validación falla, intenta auto-reparar deshabilitando configs problemáticos.
 * Protege configs esenciales (castpanel-le-ssl.conf, icecast-proxy.conf, ssl.conf).
 *
 * @return bool true si Apache se recargó exitosamente
 */
function reloadApacheSafe(): bool {
    // Configs que NUNCA se deben deshabilitar automáticamente
    $protectedConfs = [
        'castpanel-le-ssl.conf',
        'icecast-proxy.conf',
        'ssl.conf',
        'php.conf',
        'autoindex.conf',
        'welcome.conf',
    ];

    // 1. Validar configuración de Apache
    $testOutput = [];
    exec('httpd -t 2>&1', $testOutput, $testRetcode);

    if ($testRetcode === 0) {
        // Config OK — recargar directamente
        exec('sudo /usr/bin/systemctl reload httpd 2>&1', $reloadOutput, $reloadRetcode);
        if ($reloadRetcode !== 0) {
            error_log("CastPanel: systemctl reload httpd falló (retcode=$reloadRetcode): " . implode(' ', $reloadOutput));
            return false;
        }
        return true;
    }

    // 2. Config tiene errores — intentar identificar y deshabilitar el conf problemático
    $testMsg = implode("\n", $testOutput);
    error_log("CastPanel: httpd -t falló, intentando auto-reparar: $testMsg");

    // Buscar archivo .conf mencionado en el error
    // Patrones típicos de error de Apache:
    // "AH00526: Syntax error on line 5 of /etc/httpd/conf.d/castpanel-dj-portal.conf:"
    // "SSLCertificateFile: file '/etc/letsencrypt/...' does not exist or is empty"
    $disabledFiles = [];
    $maxAttempts = 3; // Evitar loops infinitos

    for ($attempt = 0; $attempt < $maxAttempts; $attempt++) {
        if (preg_match('#/etc/httpd/conf\.d/([^\s:]+\.conf)#', $testMsg, $confMatch)) {
            $badConf = $confMatch[1];

            // No tocar configs esenciales
            if (in_array($badConf, $protectedConfs)) {
                error_log("CastPanel: Config esencial con error: $badConf — no se puede auto-reparar");
                break;
            }

            // No deshabilitar el mismo archivo dos veces
            if (in_array($badConf, $disabledFiles)) {
                break;
            }

            $badPath = '/etc/httpd/conf.d/' . $badConf;
            $disPath = $badPath . '.disabled';

            if (!file_exists($badPath)) {
                break;
            }

            // Intentar renombrar: primero con PHP, luego con sudo
            $renamed = @rename($badPath, $disPath);
            if (!$renamed) {
                exec("sudo /bin/mv " . escapeshellarg($badPath) . " " . escapeshellarg($disPath) . " 2>&1", $mvOut, $mvRet);
                $renamed = ($mvRet === 0);
            }

            if ($renamed) {
                $disabledFiles[] = $badConf;
                error_log("CastPanel: Auto-deshabilitado config problemático: $badConf → $badConf.disabled");

                // Re-validar
                $testOutput = [];
                exec('httpd -t 2>&1', $testOutput, $testRetcode);

                if ($testRetcode === 0) {
                    // Reparado — ahora sí recargar
                    exec('sudo /usr/bin/systemctl reload httpd 2>&1', $reloadOutput, $reloadRetcode);
                    if ($reloadRetcode !== 0) {
                        error_log("CastPanel: Reload falló después de auto-reparar: " . implode(' ', $reloadOutput));
                        return false;
                    }
                    return true;
                }

                // Aún hay errores, intentar otro archivo
                $testMsg = implode("\n", $testOutput);
                continue;
            } else {
                error_log("CastPanel: No se pudo deshabilitar $badConf — verificar permisos");
                break;
            }
        } else {
            // No se pudo identificar el archivo problemático
            error_log("CastPanel: No se pudo identificar el archivo con error en Apache config");
            break;
        }
    }

    // Si llegamos aquí, no se pudo reparar
    if (!empty($disabledFiles)) {
        error_log("CastPanel: Se deshabilitaron " . implode(', ', $disabledFiles) . " pero Apache aún tiene errores");
    }

    return false;
}

/**
 * Agregar regla ProxyPass en Apache para SHOUTcast
 * Regenera el archivo completo desde la BD para mantener sincronizado
 */
function addShoutcastProxyRule(string $slug, int $portbase): bool {
    return updateShoutcastProxyConfig();
}

/**
 * Remover regla ProxyPass de Apache para SHOUTcast
 * Regenera el archivo completo desde la BD (sin la estación eliminada/desactivada)
 */
function removeShoutcastProxyRule(string $slug): bool {
    return updateShoutcastProxyConfig();
}

// ============================================================
// Funciones wrapper: iniciar/detener/reiniciar estación completa
// Maneja tanto Liquidsoap como SHOUTcast según server_type
// ============================================================

/**
 * Iniciar estación completa (Liquidsoap + SHOUTcast si aplica)
 */
function startStation(array $estacion): array {
    $type = $estacion['server_type'] ?? 'icecast';
    $autoDJ = stationHasAutoDJ($estacion);

    // 1. Stream server first, so Liquidsoap (AutoDJ) has something to connect to.
    //    Without AutoDJ this is all a live station needs: RadioCaster/BUTT
    //    connect straight to sc_serv2 (srcip=0.0.0.0), no Liquidsoap/Docker.
    $scResult = ['success' => true];
    if (isShoutcastType($type)) {
        $scResult = startShoutcastService($estacion);
        if (!($scResult['success'] ?? false)) {
            return ['success' => false, 'error' => 'SHOUTcast: ' . ($scResult['error'] ?? 'no se pudo iniciar')];
        }
    }

    if (!$autoDJ) {
        getDB()->prepare("UPDATE estaciones SET servicio_estado = 'running' WHERE id = ?")
               ->execute([$estacion['id']]);
        return ['success' => true, 'message' => 'Estacion en vivo iniciada (sin AutoDJ)'];
    }

    // 2. AutoDJ: always regenerate the config so it matches current settings,
    //    then start Liquidsoap. Its harbor (portbase+5) is where DJs connect.
    $estacion = loadStationWithPackage((int)$estacion['id']) ?: $estacion;
    $cfg = generateLiquidsoapConfig($estacion);
    if (!$cfg['success']) {
        return ['success' => false, 'error' => 'Liquidsoap: ' . ($cfg['error'] ?? 'config')];
    }
    return startLiquidsoapService($estacion);
}

/**
 * Detener estación completa (Liquidsoap + SHOUTcast si aplica)
 */
function stopStation(array $estacion): array {
    $type = $estacion['server_type'] ?? 'icecast';

    // Liquidsoap only exists for AutoDJ stations
    if (stationHasAutoDJ($estacion)) {
        stopLiquidsoapService($estacion);
    }

    // Si es SHOUTcast, también detener sc_serv2
    if (isShoutcastType($type)) {
        stopShoutcastService($estacion);
    }

    getDB()->prepare("UPDATE estaciones SET servicio_estado = 'stopped', autodj_estado = 'offline' WHERE id = ?")
           ->execute([$estacion['id']]);

    return ['success' => true];
}

/**
 * After music is uploaded: make sure an AutoDJ station is actually playing.
 * Not running yet -> start the whole station (SHOUTcast + Liquidsoap).
 * Already running  -> ask Liquidsoap to reload its playlist (no restart).
 */
/** When a client logs in, start AutoDJ if the stream is down (real server with castpanel-ctl only). */
function castpanelClientAutoStartOnLogin(array $estacionRow): void {
    if (!castpanelStreamingAvailable()) {
        return;
    }
    $full = loadStationWithPackage((int)$estacionRow['id']);
    if (!$full || !stationHasAutoDJ($full)) {
        return;
    }
    if (isStationOnline($full)) {
        return;
    }
    ensureAutoDJPlaying($full);
    clearStreamStatusCache((int)$full['id']);
}

function ensureAutoDJPlaying(array $estacion): array {
    if (!stationHasAutoDJ($estacion)) {
        return ['success' => true, 'message' => 'Sin AutoDJ'];
    }

    $fresh = loadStationWithPackage((int)$estacion['id']);
    if ($fresh) {
        $estacion = $fresh;
    }

    $running = liquidsoapContainerIsRunning($estacion['slug'])
            || (($estacion['servicio_estado'] ?? '') === 'running'
                && ($estacion['autodj_estado'] ?? '') === 'online');
    if (!$running) {
        return startStation($estacion);
    }

    $port = (int)($estacion['puerto_telnet'] ?: (LIQUIDSOAP_TELNET_PORT + $estacion['id']));
    require_once __DIR__ . '/liquidsoap_playlist.php';
    $m3u = castpanelWriteActivePlaylistM3u($estacion);
    if (!$m3u['success']) {
        generateLiquidsoapConfig($estacion);
        return restartLiquidsoapService($estacion);
    }

    $reply = liquidsoapCommand('music_' . $estacion['slug'] . '.reload', $port);
    if ($reply !== null) {
        return ['success' => true, 'message' => 'Playlist recargada'];
    }

    // Telnet not reachable: Liquidsoap is not really running -> restart it
    generateLiquidsoapConfig($estacion);
    return restartLiquidsoapService($estacion);
}

/**
 * Reiniciar estación completa
 */
function restartStation(array $estacion): array {
    stopStation($estacion);
    usleep(500000); // 0.5s
    return startStation($estacion);
}
