<?php
/**
 * CastPanel — Scheduled events (Programming > Events)
 *
 * An event plays a file, a folder (N songs) or a time announcement at an
 * exact minute: once on a date, every day, on chosen weekdays or every hour.
 * cron/scheduler.php calls castpanelRunStationEvents() every minute; due
 * events are pushed to the station's Liquidsoap queues:
 *   evwait_<slug>  starts when the current song ends ("wait")
 *   evnow_<slug>   cuts the current song right away ("cut")
 * After the event the AutoDJ continues on its own.
 */
require_once __DIR__ . '/autodj_schedule.php';
require_once __DIR__ . '/autodj_player.php';
require_once __DIR__ . '/liquidsoap_playlist.php';

const CP_EVENT_TYPES = ['archivo', 'carpeta', 'hora'];
const CP_EVENT_REPEATS = ['una_vez', 'diario', 'semanal', 'cada_hora'];

function castpanelEnsureEventsTable(): void {
    static $done = false;
    if ($done) return;
    $done = true;
    try {
        getDB()->exec("CREATE TABLE IF NOT EXISTS `eventos_programados` (
            `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
            `estacion_id` int(10) unsigned NOT NULL,
            `nombre` varchar(128) NOT NULL,
            `tipo` varchar(16) NOT NULL DEFAULT 'archivo',
            `cancion_id` int(10) unsigned DEFAULT NULL,
            `playlist_id` int(10) unsigned DEFAULT NULL,
            `cantidad` int(10) unsigned NOT NULL DEFAULT 0,
            `aleatorio` tinyint(1) NOT NULL DEFAULT 0,
            `repeticion` varchar(16) NOT NULL DEFAULT 'semanal',
            `fecha` date DEFAULT NULL,
            `dias` varchar(128) NOT NULL DEFAULT '',
            `hora` time NOT NULL DEFAULT '08:00:00',
            `modo` varchar(8) NOT NULL DEFAULT 'esperar',
            `activo` tinyint(1) NOT NULL DEFAULT 1,
            `ultima_ejecucion` datetime DEFAULT NULL,
            `ultimo_resultado` varchar(255) DEFAULT NULL,
            `created_at` datetime NOT NULL DEFAULT current_timestamp(),
            PRIMARY KEY (`id`),
            KEY `idx_estacion` (`estacion_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    } catch (PDOException $e) {
        error_log('CastPanel: eventos_programados: ' . $e->getMessage());
    }
}

/**
 * The scheduled time of this event that falls in (now - 3 min, now], or null.
 * The small window covers a cron run that started a little late.
 */
function castpanelEventDueAt(array $ev, DateTimeImmutable $now): ?DateTimeImmutable {
    [$h, $m] = array_map('intval', explode(':', (string)$ev['hora']) + [0, 0]);
    $cands = [];
    if ($ev['repeticion'] === 'cada_hora') {
        $c = $now->setTime((int)$now->format('H'), $m);
        $cands = [$c, $c->modify('-1 hour')];
    } else {
        $today = $now->setTime($h, $m);
        $cands = [$today, $today->modify('-1 day')]; // just after midnight
    }
    foreach ($cands as $c) {
        if ($c > $now || $now->getTimestamp() - $c->getTimestamp() >= 180) continue;
        switch ($ev['repeticion']) {
            case 'una_vez':
                if ((string)$ev['fecha'] !== $c->format('Y-m-d')) continue 2;
                break;
            case 'semanal':
                if (!cpSchedDaysMatch((string)$ev['dias'], $c)) continue 2;
                break;
        }
        $last = $ev['ultima_ejecucion'] ? strtotime((string)$ev['ultima_ejecucion']) : 0;
        if ($last >= $c->getTimestamp()) return null; // already fired for this time
        return $c;
    }
    return null;
}

/** Next time the event will run (for the list), or null. */
function castpanelEventNextRun(array $ev, ?DateTimeImmutable $now = null): ?DateTimeImmutable {
    if (empty($ev['activo'])) return null;
    $now = $now ?? cpSchedNow();
    [$h, $m] = array_map('intval', explode(':', (string)$ev['hora']) + [0, 0]);
    if ($ev['repeticion'] === 'cada_hora') {
        $c = $now->setTime((int)$now->format('H'), $m);
        return $c <= $now ? $c->modify('+1 hour') : $c;
    }
    if ($ev['repeticion'] === 'una_vez') {
        if (!$ev['fecha']) return null;
        $c = new DateTimeImmutable($ev['fecha'] . sprintf(' %02d:%02d:00', $h, $m), $now->getTimezone());
        return $c > $now ? $c : null;
    }
    for ($d = 0; $d <= 7; $d++) {
        $c = $now->modify("+$d day")->setTime($h, $m);
        if ($c <= $now) continue;
        if ($ev['repeticion'] === 'semanal' && !cpSchedDaysMatch((string)$ev['dias'], $c)) continue;
        return $c;
    }
    return null;
}

/** Songs to play for an event (rows from canciones). */
function castpanelEventTracks(array $ev, int $estacionId, ?DateTimeImmutable $at = null): array {
    $db = getDB();
    if ($ev['tipo'] === 'archivo') {
        $st = $db->prepare('SELECT ruta_archivo, titulo, artista, nombre_original FROM canciones WHERE id = ? AND estacion_id = ?');
        $st->execute([(int)$ev['cancion_id'], $estacionId]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        return $row ? [$row] : [];
    }
    $st = $db->prepare('SELECT ruta_archivo, titulo, artista, nombre_original FROM canciones
                        WHERE estacion_id = ? AND playlist_id = ? AND activa = 1 ORDER BY orden ASC, id ASC');
    $st->execute([$estacionId, (int)$ev['playlist_id']]);
    $songs = $st->fetchAll(PDO::FETCH_ASSOC);
    if (!$songs) return [];

    if ($ev['tipo'] === 'hora') {
        // Time announcement: the clip whose name has the hour (24h "08"/"8", or 12h), else any clip
        $hour = (int)($at ?? cpSchedNow())->format('G');
        $h12 = $hour % 12 ?: 12;
        foreach ([$hour, $h12] as $want) {
            foreach ($songs as $s) {
                $name = pathinfo((string)($s['nombre_original'] ?: $s['ruta_archivo']), PATHINFO_FILENAME) . ' ' . $s['titulo'];
                if (preg_match('/(^|\D)0?' . $want . '(\D|$)/', $name)) return [$s];
            }
        }
        return [$songs[array_rand($songs)]];
    }

    if (!empty($ev['aleatorio'])) shuffle($songs);
    $n = (int)$ev['cantidad'];
    return $n > 0 ? array_slice($songs, 0, $n) : $songs;
}

/**
 * Push an event to Liquidsoap now. Returns [ok, message].
 * $force = true for "Run now" from the panel (also runs while a DJ is live).
 */
function castpanelFireEvent(array $estacion, array $ev, ?DateTimeImmutable $at = null, bool $force = false): array {
    $slug = (string)$estacion['slug'];
    $port = castpanelLiquidsoapTelnetPort($estacion);

    if (!$force) {
        // A live DJ or a relay is on air: the event would only play when they end, too late
        $live = (string)liquidsoapCommand("live_{$slug}.status", $port);
        if ($live !== '' && stripos($live, 'no source') === false && stripos($live, 'connected') !== false) {
            return [false, 'Omitido: DJ en vivo al aire'];
        }
        $relayFile = rtrim(defined('MEDIA_PATH') ? MEDIA_PATH : '/var/castpanel/media', '/') . "/$slug/relay.url";
        if (is_file($relayFile) && trim((string)@file_get_contents($relayFile)) !== '') {
            return [false, 'Omitido: relay al aire'];
        }
    }

    $tracks = castpanelEventTracks($ev, (int)$estacion['id'], $at);
    if (!$tracks) return [false, 'Sin archivos para reproducir'];

    $queue = ($ev['modo'] === 'cortar' ? 'evnow_' : 'evwait_') . $slug;
    $pushed = 0;
    foreach ($tracks as $t) {
        $title = trim((string)($t['titulo'] ?: pathinfo((string)$t['ruta_archivo'], PATHINFO_FILENAME)));
        $artist = trim((string)$t['artista']);
        $display = str_replace(['"', '\\', "\n", "\r"], ["'", '', ' ', ' '], $artist !== '' ? "$artist - $title" : $title);
        $uri = 'annotate:title="' . $display . '":' . castpanelContainerMediaPath((string)$t['ruta_archivo'], $slug);
        $r = liquidsoapCommand("$queue.push $uri", $port);
        if ($r === null) return [false, 'AutoDJ apagado (sin conexión)'];
        if (stripos($r, 'ERROR') !== false || stripos($r, 'unknown') !== false) {
            return [false, 'Reinicie el AutoDJ una vez para activar los eventos'];
        }
        $pushed++;
    }
    // Cut: the interrupted song does not resume after the event
    if ($ev['modo'] === 'cortar') liquidsoapCommand("music_{$slug}.skip", $port);

    return [true, $pushed === 1 ? '1 archivo enviado' : "$pushed archivos enviados"];
}

function castpanelEventSaveResult(int $eventId, bool $ok, string $msg, ?DateTimeImmutable $at = null): void {
    getDB()->prepare('UPDATE eventos_programados SET ultima_ejecucion = ?, ultimo_resultado = ? WHERE id = ?')
        ->execute([($at ?? cpSchedNow())->format('Y-m-d H:i:s'), ($ok ? 'OK: ' : '') . $msg, $eventId]);
}

/** Fire every due event of one station. @return string[] log lines */
function castpanelRunStationEvents(array $estacion, ?DateTimeInterface $now = null): array {
    castpanelEnsureEventsTable();
    $now = cpSchedNow($now);
    $st = getDB()->prepare('SELECT * FROM eventos_programados WHERE estacion_id = ? AND activo = 1 ORDER BY hora, id');
    $st->execute([(int)$estacion['id']]);
    $log = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $ev) {
        $at = castpanelEventDueAt($ev, $now);
        if (!$at) continue;
        // Claim this run first: the cron and the Liquidsoap tick may both get here
        $claim = getDB()->prepare('UPDATE eventos_programados SET ultima_ejecucion = ? WHERE id = ? AND (ultima_ejecucion IS NULL OR ultima_ejecucion < ?)');
        $claim->execute([$at->format('Y-m-d H:i:s'), (int)$ev['id'], $at->format('Y-m-d H:i:s')]);
        if ($claim->rowCount() !== 1) continue;
        [$ok, $msg] = castpanelFireEvent($estacion, $ev, $at);
        castpanelEventSaveResult((int)$ev['id'], $ok, $msg, $at);
        if ($ev['repeticion'] === 'una_vez') {
            getDB()->prepare('UPDATE eventos_programados SET activo = 0 WHERE id = ?')->execute([(int)$ev['id']]);
        }
        $log[] = "event '{$ev['nombre']}': " . ($ok ? 'OK ' : '') . $msg;
    }
    return $log;
}
