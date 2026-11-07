#!/usr/bin/env php
<?php
/**
 * CastPanel v2.1 - Cron: Enforce Limits
 *
 * Monitorea estaciones activas y corta streams que excedan:
 *   - Máximo de oyentes del paquete
 *   - Máximo de bitrate del paquete
 *   - Cuentas suspendidas que siguen emitiendo
 *
 * Uso:
 *   php enforce_limits.php           (ejecución normal)
 *   php enforce_limits.php --verbose  (salida detallada)
 *   php enforce_limits.php --dry-run  (solo reportar, no actuar)
 *
 * Crontab recomendado:
 *   * * * * * php /ruta/castpanel/cron/enforce_limits.php >> /var/log/castpanel/enforce.log 2>&1
 */

// Evitar ejecución web
if (php_sapi_name() !== 'cli') {
    die('Solo ejecución CLI');
}

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/provisioning.php';

$verbose = in_array('--verbose', $argv ?? []);
$dryRun  = in_array('--dry-run', $argv ?? []);

$db = getDB();

// Verificar si enforcement está habilitado
$stmt = $db->prepare("SELECT valor FROM configuracion WHERE clave = 'enforce_limits'");
$stmt->execute();
if ($stmt->fetchColumn() !== '1') {
    if ($verbose) echo "[INFO] Enforcement deshabilitado en configuración\n";
    exit(0);
}

$startTime = microtime(true);
$stats = ['revisadas' => 0, 'violaciones' => 0, 'acciones' => 0];

if ($verbose) {
    echo "=== CastPanel Enforce Limits ===\n";
    echo "Hora: " . date('Y-m-d H:i:s') . "\n";
    if ($dryRun) echo "[DRY-RUN] Solo reportando, sin acciones\n";
    echo "---\n";
}

// ============================================================
// 1. Verificar cuentas suspendidas que siguen emitiendo
// ============================================================
$stmt = $db->query("
    SELECT e.*, u.activo AS usuario_activo, u.username
    FROM estaciones e
    JOIN usuarios u ON u.id = e.usuario_id
    WHERE (u.activo = 0 OR e.activa = 0)
      AND e.servicio_estado = 'running'
");

while ($est = $stmt->fetch()) {
    $stats['revisadas']++;
    $stats['violaciones']++;

    $razon = !$est['usuario_activo'] ? 'usuario_suspendido' : 'estacion_desactivada';
    if ($verbose) {
        echo "[VIOLACIÓN] Estación #{$est['id']} ({$est['slug']}) - $razon - Deteniendo servicio\n";
    }

    if (!$dryRun) {
        stopLiquidsoapService($est);
        $db->prepare("UPDATE estaciones SET servicio_estado = 'stopped', autodj_estado = 'offline' WHERE id = ?")
           ->execute([$est['id']]);
        logEnforcement($est['id'], 'suspension', "Servicio detenido: $razon", 0, 0, 'stop_service');
        $stats['acciones']++;
    }
}

// ============================================================
// 2. Verificar límites de oyentes y bitrate
// ============================================================
$stmt = $db->query("
    SELECT e.*, p.max_oyentes, p.max_bitrate, p.nombre AS paquete_nombre
    FROM estaciones e
    JOIN paquetes p ON p.id = e.paquete_id
    JOIN usuarios u ON u.id = e.usuario_id
    WHERE e.activa = 1
      AND u.activo = 1
      AND e.servicio_estado = 'running'
");

// Obtener listeners de Icecast de una sola vez
$icecastListeners = [];
try {
    $icecastListeners = getIcecastListeners();
} catch (Exception $e) {
    if ($verbose) echo "[WARN] No se pudo conectar a Icecast: {$e->getMessage()}\n";
}

while ($est = $stmt->fetch()) {
    $stats['revisadas']++;

    // --- Obtener estado actual del stream ---
    $status = null;
    try {
        if ($est['server_type'] === 'icecast') {
            $mount = $est['icecast_mount'];
            if (isset($icecastListeners[$mount])) {
                $status = [
                    'en_linea'  => true,
                    'oyentes'   => (int)($icecastListeners[$mount]['oyentes'] ?? 0),
                    'bitrate'   => (int)($icecastListeners[$mount]['bitrate'] ?? $est['bitrate']),
                ];
            } else {
                // Intentar obtener por mount individual
                $status = getIcecastStatus($mount);
            }
        } else {
            $status = getStreamStatus($est);
        }
    } catch (Exception $e) {
        if ($verbose) echo "[WARN] Error obteniendo status de estación #{$est['id']}: {$e->getMessage()}\n";
        continue;
    }

    if (!$status || !$status['en_linea']) {
        continue;
    }

    $currentListeners = (int)($status['oyentes'] ?? 0);
    $currentBitrate = (int)($status['bitrate'] ?? 0);
    $maxListeners = (int)$est['max_oyentes'];
    $maxBitrate = (int)$est['max_bitrate'];

    // --- Verificar máximo de oyentes ---
    if ($maxListeners > 0 && $currentListeners > $maxListeners) {
        $stats['violaciones']++;
        $exceso = $currentListeners - $maxListeners;

        if ($verbose) {
            echo "[VIOLACIÓN] Estación #{$est['id']} ({$est['slug']}) - Oyentes: $currentListeners / $maxListeners (exceso: $exceso)\n";
        }

        if (!$dryRun) {
            // Para Icecast: podemos usar fallback o desconectar oyentes excedentes
            // Para SHOUTcast: no hay forma fina, solo detener
            // Estrategia: si excede 150%, detener todo. Si no, solo advertir y loggear
            $porcentaje = ($currentListeners / $maxListeners) * 100;

            if ($porcentaje >= 150) {
                // Exceso grave: detener el servicio
                stopLiquidsoapService($est);
                $db->prepare("UPDATE estaciones SET servicio_estado = 'stopped', autodj_estado = 'offline' WHERE id = ?")
                   ->execute([$est['id']]);
                logEnforcement($est['id'], 'max_oyentes', "Servicio detenido: $currentListeners/$maxListeners oyentes (${porcentaje}%)", $currentListeners, $maxListeners, 'stop_service');
                $stats['acciones']++;

                if ($verbose) {
                    echo "  -> ACCIÓN: Servicio detenido (exceso grave: {$porcentaje}%)\n";
                }
            } else {
                // Exceso leve: solo loggear advertencia
                logEnforcement($est['id'], 'max_oyentes', "Advertencia: $currentListeners/$maxListeners oyentes", $currentListeners, $maxListeners, 'warning');

                if ($verbose) {
                    echo "  -> ACCIÓN: Advertencia registrada (exceso leve: {$porcentaje}%)\n";
                }
            }
        }
    }

    // --- Verificar máximo de bitrate ---
    if ($maxBitrate > 0 && $currentBitrate > $maxBitrate && $currentBitrate > 0) {
        $stats['violaciones']++;

        if ($verbose) {
            echo "[VIOLACIÓN] Estación #{$est['id']} ({$est['slug']}) - Bitrate: {$currentBitrate}kbps / {$maxBitrate}kbps\n";
        }

        if (!$dryRun) {
            // Bitrate excesivo: regenerar config con bitrate correcto y reiniciar
            $est['bitrate'] = $maxBitrate;
            $db->prepare("UPDATE estaciones SET bitrate = ? WHERE id = ?")->execute([$maxBitrate, $est['id']]);
            generateLiquidsoapConfig($est);
            restartLiquidsoapService($est);
            logEnforcement($est['id'], 'max_bitrate', "Bitrate forzado a {$maxBitrate}kbps (era {$currentBitrate}kbps)", $currentBitrate, $maxBitrate, 'force_bitrate');
            $stats['acciones']++;

            if ($verbose) {
                echo "  -> ACCIÓN: Bitrate forzado a {$maxBitrate}kbps, servicio reiniciado\n";
            }
        }
    }
}

// ============================================================
// 3. Verificar procesos huérfanos de Liquidsoap
// ============================================================
$stmt = $db->query("SELECT * FROM estaciones WHERE pid_liquidsoap IS NOT NULL AND servicio_estado = 'running'");
while ($est = $stmt->fetch()) {
    $pid = (int)$est['pid_liquidsoap'];
    if ($pid > 0 && !file_exists("/proc/$pid")) {
        if ($verbose) {
            echo "[WARN] Estación #{$est['id']} ({$est['slug']}) - PID $pid no existe, marcando como stopped\n";
        }
        if (!$dryRun) {
            $db->prepare("UPDATE estaciones SET servicio_estado = 'stopped', pid_liquidsoap = NULL, autodj_estado = 'offline' WHERE id = ?")
               ->execute([$est['id']]);
            logEnforcement($est['id'], 'activacion', "Proceso huérfano detectado (PID $pid), estado corregido", 0, 0, 'cleanup');
            $stats['acciones']++;
        }
    }
}

// ============================================================
// 4. Registrar estadísticas de oyentes (cada 5 minutos)
// ============================================================
$minuto = (int)date('i');
if ($minuto % 5 === 0) {
    $stmtAll = $db->query("
        SELECT e.id, e.server_type, e.icecast_mount, e.slug, e.puerto_stream, e.icecast_port, e.shoutcast_sid, e.icecast_password
        FROM estaciones e
        JOIN usuarios u ON u.id = e.usuario_id
        WHERE e.activa = 1 AND u.activo = 1 AND e.servicio_estado = 'running'
    ");

    while ($est = $stmtAll->fetch()) {
        try {
            $status = null;
            if ($est['server_type'] === 'icecast') {
                $mount = $est['icecast_mount'];
                if (isset($icecastListeners[$mount])) {
                    $status = $icecastListeners[$mount];
                }
            } else {
                $status = getStreamStatus($est);
            }

            $oyentes = (int)($status['oyentes'] ?? 0);
            $pico = (int)($status['pico_oyentes'] ?? $oyentes);

            $db->prepare("INSERT INTO estadisticas_oyentes (estacion_id, oyentes, pico_oyentes) VALUES (?, ?, ?)")
               ->execute([$est['id'], $oyentes, $pico]);
        } catch (Exception $e) {
            // Ignorar errores individuales
        }
    }

    if ($verbose) echo "[INFO] Estadísticas de oyentes registradas\n";
}

// ============================================================
// Resumen
// ============================================================
$elapsed = round((microtime(true) - $startTime) * 1000);

if ($verbose) {
    echo "---\n";
    echo "Estaciones revisadas: {$stats['revisadas']}\n";
    echo "Violaciones encontradas: {$stats['violaciones']}\n";
    echo "Acciones tomadas: {$stats['acciones']}\n";
    echo "Tiempo: {$elapsed}ms\n";
}

// Log resumen si hubo acciones
if ($stats['acciones'] > 0) {
    error_log("[CastPanel Enforce] {$stats['violaciones']} violaciones, {$stats['acciones']} acciones en {$elapsed}ms");
}

// ---- FUNCIONES ----

function logEnforcement(int $estacionId, string $tipo, string $detalle, int $valorActual, int $valorLimite, string $accion): void {
    try {
        $db = getDB();
        $db->prepare("INSERT INTO log_enforcement (estacion_id, tipo, detalle, valor_actual, valor_limite, accion_tomada) VALUES (?,?,?,?,?,?)")
           ->execute([$estacionId, $tipo, $detalle, $valorActual, $valorLimite, $accion]);
    } catch (Exception $e) {
        // Silenciar
    }
}
