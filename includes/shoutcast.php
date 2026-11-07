<?php
/**
 * CastPanel v2.4 - SHOUTcast DNAS Management
 *
 * Funciones para generar configs, iniciar/detener y verificar
 * servidores SHOUTcast DNAS v2 por estacion.
 *
 * Arquitectura:
 *   sc_serv2 (escucha en puerto_stream) <- Liquidsoap (output.shoutcast) <- AutoDJ/DJ
 *   Oyentes se conectan via proxy: /shoutcast/{slug} -> :puerto/stream/1/
 *
 * NOTA: startShoutcastServer() y stopShoutcastServer() usan castpanelCtl()
 *       (IPC por archivo) para ejecutar sc_serv2 como root a traves del daemon
 *       castpanel-ctl.sh, evitando problemas con exec("sudo ...") desde PHP-FPM.
 *
 * El proxy Apache se actualiza automaticamente al iniciar/detener estaciones
 * mediante updateShoutcastProxyConfig() que genera /etc/httpd/shoutcast-proxy-rules.inc
 */

if (!defined('SHOUTCAST_BINARY')) {
    define('SHOUTCAST_BINARY', '/opt/shoutcast/sc_serv2');
}
if (!defined('SHOUTCAST_CONFIG_DIR')) {
    define('SHOUTCAST_CONFIG_DIR', '/opt/shoutcast/configs');
}
if (!defined('SHOUTCAST_LOG_DIR')) {
    define('SHOUTCAST_LOG_DIR', '/opt/shoutcast/logs');
}
if (!defined('SHOUTCAST_PID_DIR')) {
    define('SHOUTCAST_PID_DIR', '/opt/shoutcast/pids');
}

/**
 * Ruta del config SHOUTcast para una estacion
 */
function getShoutcastConfigPath(string $slug): string {
    return SHOUTCAST_CONFIG_DIR . '/' . $slug . '.conf';
}

/**
 * Ruta del PID file SHOUTcast para una estacion
 */
function getShoutcastPidPath(string $slug): string {
    return SHOUTCAST_PID_DIR . '/' . $slug . '.pid';
}

/**
 * Generar archivo de configuracion SHOUTcast DNAS v2 para una estacion
 *
 * @param array $estacion Datos de la estacion (de la tabla estaciones + paquetes)
 * @return array ['success' => bool, 'file' => string, 'error' => string]
 */
function generateShoutcastConfig(array $estacion): array {
    $slug = $estacion['slug'];
    $port = (int)($estacion['puerto_stream'] ?: 8100);
    $password = $estacion['icecast_password'] ?: 'hackme';
    $sid = (int)($estacion['shoutcast_sid'] ?: 1);
    $maxListeners = 100;

    // Obtener limite de oyentes del paquete
    if (!empty($estacion['paquete_id'])) {
        $db = getDB();
        $stmt = $db->prepare("SELECT max_oyentes FROM paquetes WHERE id = ?");
        $stmt->execute([$estacion['paquete_id']]);
        $ml = $stmt->fetchColumn();
        if ($ml) $maxListeners = (int)$ml;
    }

    $adminPass = 'CastPanel_Admin_2024!';
    $logFile = SHOUTCAST_LOG_DIR . '/' . $slug . '.log';
    $pidFile = getShoutcastPidPath($slug);

    // Generar configuracion sc_serv2
    $config  = "; ==================================================\n";
    $config .= "; CastPanel - SHOUTcast DNAS v2 Configuration\n";
    $config .= "; Estacion: {$slug}\n";
    $config .= "; Generado: " . date('Y-m-d H:i:s') . "\n";
    $config .= "; ==================================================\n\n";

    // Network
    $config .= "; --- Red ---\n";
    $config .= "portbase={$port}\n";
    $config .= "srcip=127.0.0.1\n";
    $config .= "destip=0.0.0.0\n\n";

    // Authentication
    $config .= "; --- Autenticacion ---\n";
    $config .= "; Password para source (Liquidsoap usa esta)\n";
    $config .= "password={$password}\n";
    $config .= "adminpassword={$adminPass}\n\n";

    // Limits
    $config .= "; --- Limites ---\n";
    $config .= "maxuser={$maxListeners}\n\n";

    // Logging
    $config .= "; --- Logs ---\n";
    $config .= "logfile={$logFile}\n";
    $config .= "w3clog=" . SHOUTCAST_LOG_DIR . "/{$slug}_w3c.log\n";
    $config .= "realtime=1\n";
    $config .= "screenlog=0\n\n";

    // PID
    $config .= "; --- Proceso ---\n";
    $config .= "pidfile={$pidFile}\n\n";

    // Stream config
    $config .= "; --- Stream ---\n";
    $config .= "streamid_1={$sid}\n";
    $config .= "streampath_1=/\n\n";

    // Public
    $config .= "; --- Directorio ---\n";
    $config .= "publicserver=never\n";

    // Crear directorio si no existe
    if (!is_dir(SHOUTCAST_CONFIG_DIR)) {
        @mkdir(SHOUTCAST_CONFIG_DIR, 0755, true);
    }

    $configPath = getShoutcastConfigPath($slug);
    $written = file_put_contents($configPath, $config);

    if ($written === false) {
        return ['success' => false, 'error' => "No se pudo escribir $configPath"];
    }

    @chmod($configPath, 0644);
    return ['success' => true, 'file' => $configPath];
}

/**
 * Iniciar servidor SHOUTcast DNAS para una estacion
 * Usa castpanelCtl() (IPC por archivo) para ejecutar como root
 *
 * @param array $estacion Datos de la estacion
 * @return array ['success' => bool, 'pid' => int, 'error' => string]
 */
function startShoutcastServer(array $estacion): array {
    $slug = $estacion['slug'];
    $configPath = getShoutcastConfigPath($slug);
    $pidPath = getShoutcastPidPath($slug);

    // Generar config si no existe
    if (!file_exists($configPath)) {
        $gen = generateShoutcastConfig($estacion);
        if (!$gen['success']) {
            return $gen;
        }
    }

    // Verificar binario
    if (!file_exists(SHOUTCAST_BINARY)) {
        return [
            'success' => false,
            'error'   => 'SHOUTcast binary no encontrado en ' . SHOUTCAST_BINARY
                       . '. Ejecute: sudo bash install_shoutcast.sh'
        ];
    }

    // Ya esta corriendo?
    if (file_exists($pidPath)) {
        $pid = (int)trim(file_get_contents($pidPath));
        if ($pid > 0 && file_exists("/proc/$pid")) {
            // Actualizar proxy por si acaso
            updateShoutcastProxyConfig();
            return ['success' => true, 'pid' => $pid, 'message' => 'Ya estaba corriendo'];
        }
        @unlink($pidPath);
    }

    // Asegurar directorio de logs y pids
    @mkdir(SHOUTCAST_LOG_DIR, 0755, true);
    @mkdir(SHOUTCAST_PID_DIR, 0755, true);

    // Iniciar sc_serv2 via castpanelCtl (daemon corre como root)
    $r = castpanelCtl("start-shoutcast-{$slug}", 15);

    if ($r['success']) {
        $pid = (int)trim($r['output'] ?? '0');
        // Actualizar proxy Apache automaticamente
        updateShoutcastProxyConfig();
        return ['success' => true, 'pid' => $pid];
    }

    // Revisar log para diagnosticar
    $logFile = SHOUTCAST_LOG_DIR . '/' . $slug . '.log';
    $logTail = '';
    if (file_exists($logFile)) {
        $lines = file($logFile);
        $logTail = implode('', array_slice($lines, -5));
    }

    return [
        'success' => false,
        'error'   => "SHOUTcast no arranco. Log: $logTail. CTL: " . ($r['output'] ?? 'sin respuesta')
    ];
}

/**
 * Detener servidor SHOUTcast DNAS para una estacion
 * Usa castpanelCtl() (IPC por archivo) para ejecutar como root
 */
function stopShoutcastServer(array $estacion): array {
    $slug = $estacion['slug'];

    // Detener SHOUTcast via castpanelCtl (daemon corre como root)
    $r = castpanelCtl("stop-shoutcast-{$slug}", 10);

    return ['success' => true, 'message' => $r['output'] ?? 'shoutcast stopped'];
}

/**
 * Verificar si el servidor SHOUTcast esta corriendo para una estacion
 */
function isShoutcastRunning(string $slug): bool {
    $pidPath = getShoutcastPidPath($slug);
    if (!file_exists($pidPath)) {
        return false;
    }
    $pid = (int)trim(file_get_contents($pidPath));
    return $pid > 0 && file_exists("/proc/$pid");
}

/**
 * Obtener PID del proceso SHOUTcast de una estacion
 */
function getShoutcastPid(string $slug): int {
    $pidPath = getShoutcastPidPath($slug);
    if (!file_exists($pidPath)) {
        return 0;
    }
    $pid = (int)trim(file_get_contents($pidPath));
    return ($pid > 0 && file_exists("/proc/$pid")) ? $pid : 0;
}

/**
 * Eliminar config y PID de SHOUTcast para una estacion (deprovisioning)
 */
function removeShoutcastConfig(string $slug): bool {
    $configPath = getShoutcastConfigPath($slug);
    if (file_exists($configPath)) {
        @unlink($configPath);
    }
    $pidPath = getShoutcastPidPath($slug);
    if (file_exists($pidPath)) {
        @unlink($pidPath);
    }
    // Limpiar logs
    $logFile = SHOUTCAST_LOG_DIR . '/' . $slug . '.log';
    if (file_exists($logFile)) {
        @unlink($logFile);
    }
    $w3cLog = SHOUTCAST_LOG_DIR . '/' . $slug . '_w3c.log';
    if (file_exists($w3cLog)) {
        @unlink($w3cLog);
    }
    // Actualizar proxy (remover esta estacion)
    updateShoutcastProxyConfig();
    return true;
}

/**
 * Obtener estado del servidor SHOUTcast (para la API de status)
 * Conecta al admin del sc_serv2 para obtener info de listeners
 */
function getShoutcastServerInfo(array $estacion): array {
    $port = (int)($estacion['puerto_stream'] ?: 8100);
    $adminPass = 'CastPanel_Admin_2024!';
    $sid = (int)($estacion['shoutcast_sid'] ?: 1);

    // SHOUTcast v2 API: /statistics?json=1&sid=$sid
    $url = "http://127.0.0.1:{$port}/statistics?json=1&sid={$sid}";

    $ctx = stream_context_create([
        'http' => [
            'timeout' => 3,
            'header'  => "Authorization: Basic " . base64_encode("admin:{$adminPass}")
        ]
    ]);

    $json = @file_get_contents($url, false, $ctx);
    if ($json === false) {
        return [
            'online'    => false,
            'listeners' => 0,
            'title'     => '',
            'error'     => 'No se pudo conectar al servidor SHOUTcast'
        ];
    }

    $data = json_decode($json, true);
    if (!$data) {
        return [
            'online'    => false,
            'listeners' => 0,
            'title'     => '',
            'error'     => 'Respuesta JSON invalida'
        ];
    }

    return [
        'online'     => true,
        'listeners'  => (int)($data['currentlisteners'] ?? 0),
        'max'        => (int)($data['maxlisteners'] ?? 0),
        'title'      => $data['songtitle'] ?? '',
        'bitrate'    => (int)($data['bitrate'] ?? 0),
        'content'    => $data['content'] ?? '',
        'peak'       => (int)($data['peaklisteners'] ?? 0),
    ];
}

/**
 * Actualizar configuracion de proxy Apache para estaciones SHOUTcast
 *
 * Genera /tmp/castpanel-shoutcast-proxy.conf con las reglas ProxyPass
 * para TODAS las estaciones SHOUTcast en la BD, luego usa castpanelCtl()
 * para instalar el archivo y recargar Apache.
 *
 * Se llama automaticamente al iniciar/detener/eliminar estaciones SHOUTcast.
 * El archivo generado es incluido por Apache via IncludeOptional.
 *
 * Patron de URL: /shoutcast/{slug} -> http://127.0.0.1:{puerto}/stream/1/
 *
 * @return bool true si se actualizo correctamente
 */
function updateShoutcastProxyConfig(): bool {
    try {
        $db = getDB();
        $stmt = $db->query("
            SELECT slug, puerto_stream
            FROM estaciones
            WHERE server_type IN ('shoutcast_v1', 'shoutcast_v2')
              AND puerto_stream > 0
        ");
        $stations = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        error_log("CastPanel: updateShoutcastProxyConfig() DB error: " . $e->getMessage());
        return false;
    }

    $config  = "# CastPanel - SHOUTcast Proxy Rules\n";
    $config .= "# Auto-generado por updateShoutcastProxyConfig()\n";
    $config .= "# NO EDITAR MANUALMENTE - se regenera al iniciar/detener estaciones\n";
    $config .= "# Fecha: " . date('Y-m-d H:i:s') . "\n";
    $config .= "# Estaciones: " . count($stations) . "\n\n";

    foreach ($stations as $st) {
        // Sanitizar slug: solo letras, numeros, guiones
        $slug = preg_replace('/[^a-z0-9\-]/', '', strtolower($st['slug']));
        $port = (int)$st['puerto_stream'];

        if ($port > 0 && $slug !== '') {
            $config .= "# Estacion: {$slug} (puerto {$port})\n";
            $config .= "ProxyPass /shoutcast/{$slug} http://127.0.0.1:{$port}/stream/1/ retry=1\n";
            $config .= "ProxyPassReverse /shoutcast/{$slug} http://127.0.0.1:{$port}/stream/1/\n\n";
        }
    }

    // Si no hay estaciones, escribir archivo vacio (con comentario)
    if (empty($stations)) {
        $config .= "# No hay estaciones SHOUTcast configuradas\n";
    }

    $tmpFile = '/tmp/castpanel-shoutcast-proxy.conf';
    $written = @file_put_contents($tmpFile, $config);
    if ($written === false) {
        error_log("CastPanel: No se pudo escribir $tmpFile");
        return false;
    }
    @chmod($tmpFile, 0644);

    // Instalar y recargar Apache via castpanelCtl
    $r = castpanelCtl("update-shoutcast-proxy", 10);

    if (!$r['success']) {
        error_log("CastPanel: update-shoutcast-proxy fallo: " . ($r['output'] ?? 'sin respuesta'));
    }

    return $r['success'];
}
