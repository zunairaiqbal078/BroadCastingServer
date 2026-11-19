<?php
/**
 * CastPanel — AutoDJ scheduling
 *
 * Decides what a station plays right now and writes the small files the
 * station's Liquidsoap watches:
 *   <media>/<slug>/active.m3u        music (default folder, AutoDJ schedule or folder rotation)
 *   <ads>/<slug>/active_ads.m3u      ads allowed right now (Schedule Ads)
 *   <ads>/<slug>/ads.conf            "<songs between blocks> <ads per block>"
 *   <media>/<slug>/relay.url         URL to relay right now, empty = no relay (Relay Redirect)
 *
 * Liquidsoap reloads a playlist when its file changes and checks relay.url
 * every few seconds, so nothing has to be restarted. Files are only
 * rewritten when their content changes.
 *
 * Called by cron/scheduler.php every minute and right after a schedule,
 * rotation, relay or ad is saved.
 */

const CP_DAY_KEYS = [1 => 'lunes', 2 => 'martes', 3 => 'miercoles', 4 => 'jueves', 5 => 'viernes', 6 => 'sabado', 7 => 'domingo'];

function cpSchedNow(?DateTimeInterface $now = null): DateTimeImmutable {
    return $now ? DateTimeImmutable::createFromInterface($now) : new DateTimeImmutable('now');
}

function cpSchedDayKey(DateTimeInterface $now): string {
    return CP_DAY_KEYS[(int)$now->format('N')];
}

/**
 * Is $now inside [start, end)? The end minute belongs to the next block, so
 * 10:00–11:00 then 11:00–12:00 switch exactly at 11:00. An end of 23:59 means
 * "until midnight" (whole minute included). Windows that cross midnight
 * (22:00–02:00) count too; start = end means all day.
 */
function cpSchedTimeIn(DateTimeInterface $now, string $start, string $end): bool {
    $t = $now->format('H:i');
    $start = substr($start, 0, 5);
    $end = substr($end, 0, 5);
    if ($start === $end) return true;
    $beforeEnd = $end === '23:59' ? $t <= $end : $t < $end;
    if ($start < $end) {
        return $t >= $start && $beforeEnd;
    }
    return $t >= $start || $beforeEnd;
}

/** "lunes,martes" / "todos" / "" → does it include today? */
function cpSchedDaysMatch(string $dias, DateTimeInterface $now, bool $emptyMeansAll = false): bool {
    $list = array_filter(array_map('trim', explode(',', strtolower($dias))));
    if (!$list) return $emptyMeansAll;
    return in_array('todos', $list, true) || in_array(cpSchedDayKey($now), $list, true);
}

/**
 * What music should play now.
 * @return array{type:string,id:?int,name:?string}  type = rotation | schedule | default
 */
function castpanelResolveMusicSource(array $estacion, ?DateTimeInterface $now = null): array {
    $now = cpSchedNow($now);
    $db = getDB();
    $id = (int)$estacion['id'];
    $best = null; // [prioridad, rank, result]
    // A block whose folders have no songs is skipped (it used to put the station on silence)
    $hasSongs = static function (string $type, int $srcId) use ($db, $id): bool {
        try {
            if ($type === 'schedule') {
                $st = $db->prepare('SELECT 1 FROM canciones WHERE estacion_id = ? AND playlist_id = ? AND activa = 1 LIMIT 1');
                $st->execute([$id, $srcId]);
                return (bool)$st->fetchColumn();
            }
            $st = $db->prepare('SELECT * FROM rotacion_carpetas_secuencia WHERE rotacion_id = ?');
            $st->execute([$srcId]);
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $row) {
                if (cpSchedFolderSongs($id, (int)$row['playlist_id'], $row)) return true;
            }
            return false;
        } catch (PDOException $e) {
            return true;
        }
    };

    try {
        $st = $db->prepare('SELECT * FROM rotacion_carpetas WHERE estacion_id = ? AND activa = 1');
        $st->execute([$id]);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            if (!cpSchedDaysMatch((string)$r['dias'], $now, true)) continue;
            if (!cpSchedTimeIn($now, (string)$r['hora_inicio'], (string)$r['hora_fin'])) continue;
            $cand = [(int)$r['prioridad'], 2, ['type' => 'rotation', 'id' => (int)$r['id'], 'name' => $r['nombre']]];
            if (($best === null || [$cand[0], $cand[1]] > [$best[0], $best[1]]) && !$hasSongs('rotation', (int)$r['id'])) continue;
            if ($best === null || [$cand[0], $cand[1]] > [$best[0], $best[1]]) $best = $cand;
        }
    } catch (PDOException $e) {}

    try {
        $st = $db->prepare("SELECT pp.*, p.nombre FROM programacion_playlists pp JOIN playlists p ON p.id = pp.playlist_id
                            WHERE pp.estacion_id = ? AND pp.estado = 'activa' AND pp.dia_semana = ?");
        $st->execute([$id, cpSchedDayKey($now)]);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            if (!cpSchedTimeIn($now, (string)$r['hora_inicio'], (string)$r['hora_fin'])) continue;
            $cand = [(int)$r['prioridad'], 1, ['type' => 'schedule', 'id' => (int)$r['playlist_id'], 'name' => $r['nombre']]];
            if (($best === null || [$cand[0], $cand[1]] > [$best[0], $best[1]]) && !$hasSongs('schedule', (int)$r['playlist_id'])) continue;
            if ($best === null || [$cand[0], $cand[1]] > [$best[0], $best[1]]) $best = $cand;
        }
    } catch (PDOException $e) {}

    if ($best) return $best[2];
    $pl = (int)($estacion['autodj_playlist_activa'] ?? 0);
    return ['type' => 'default', 'id' => $pl ?: null, 'name' => null];
}

/**
 * Rotation rules: artist/track separation, song weight, release year and
 * BPM (song columns + per-step filters).
 * Adds the columns on first use so existing installs need no manual migration.
 */
function castpanelEnsureRotationRuleColumns(): void {
    static $done = false;
    if ($done) return;
    $done = true;
    $db = getDB();
    $cols = [
        ['rotacion_carpetas', 'sep_artista_min', 'INT UNSIGNED NOT NULL DEFAULT 0'],
        ['rotacion_carpetas', 'sep_cancion_min', 'INT UNSIGNED NOT NULL DEFAULT 0'],
        ['rotacion_carpetas', 'seleccion', "VARCHAR(16) NOT NULL DEFAULT 'auto'"],
        ['canciones', 'peso', 'TINYINT UNSIGNED NOT NULL DEFAULT 3'],
        // Phase 3: release year and BPM per song, filters per rotation step
        ['canciones', 'anio', 'SMALLINT UNSIGNED DEFAULT NULL'],
        ['canciones', 'bpm', 'SMALLINT UNSIGNED DEFAULT NULL'],
        ['rotacion_carpetas_secuencia', 'anio_min', 'SMALLINT UNSIGNED DEFAULT NULL'],
        ['rotacion_carpetas_secuencia', 'anio_max', 'SMALLINT UNSIGNED DEFAULT NULL'],
        ['rotacion_carpetas_secuencia', 'bpm_min', 'SMALLINT UNSIGNED DEFAULT NULL'],
        ['rotacion_carpetas_secuencia', 'bpm_max', 'SMALLINT UNSIGNED DEFAULT NULL'],
    ];
    foreach ($cols as [$table, $col, $ddl]) {
        try {
            $st = $db->prepare('SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?');
            $st->execute([$table, $col]);
            if (!$st->fetchColumn()) $db->exec("ALTER TABLE `$table` ADD COLUMN `$col` $ddl");
        } catch (PDOException $e) {
            error_log("CastPanel: could not add $table.$col: " . $e->getMessage());
        }
    }
}

/**
 * Songs of one folder (or the whole library when $playlistId is null).
 * $filter (rotation step): anio_min/anio_max/bpm_min/bpm_max; a song without
 * the value is left out when that filter is set.
 */
function cpSchedFolderSongs(int $estacionId, ?int $playlistId, array $filter = []): array {
    $db = getDB();
    castpanelEnsureRotationRuleColumns();
    $sql = 'SELECT ruta_archivo, titulo, artista, duracion_segundos, peso, anio, bpm FROM canciones WHERE estacion_id = ? AND activa = 1';
    $args = [$estacionId];
    if ($playlistId) { $sql .= ' AND playlist_id = ?'; $args[] = $playlistId; }
    foreach (['anio_min' => 'anio >= ?', 'anio_max' => 'anio <= ?', 'bpm_min' => 'bpm >= ?', 'bpm_max' => 'bpm <= ?'] as $k => $cond) {
        if (isset($filter[$k]) && $filter[$k] !== null && $filter[$k] !== '' && (int)$filter[$k] > 0) {
            $sql .= ' AND ' . $cond;
            $args[] = (int)$filter[$k];
        }
    }
    $st = $db->prepare($sql . ' ORDER BY orden ASC, id ASC');
    $st->execute($args);
    return $st->fetchAll(PDO::FETCH_ASSOC);
}

/** Does a rotation step have a year/BPM filter? */
function cpSchedStepHasFilter(array $row): bool {
    foreach (['anio_min', 'anio_max', 'bpm_min', 'bpm_max'] as $k) if ((int)($row[$k] ?? 0) > 0) return true;
    return false;
}

/** Shuffle mode: a new random order every hour (stable within the hour). */
function cpSchedShuffle(array $songs, string $seedKey): array {
    mt_srand(crc32($seedKey . date('Y-m-d H')));
    for ($i = count($songs) - 1; $i > 0; $i--) {
        $j = mt_rand(0, $i);
        [$songs[$i], $songs[$j]] = [$songs[$j], $songs[$i]];
    }
    mt_srand();
    return $songs;
}

/**
 * Ordered song list for the source. A rotation plays N songs from folder A,
 * M from folder B, … and repeats; the list is long enough that every folder
 * comes round at least once, then Liquidsoap loops it.
 */
function castpanelBuildMusicTracks(array $estacion, array $source): array {
    $id = (int)$estacion['id'];
    $shuffle = ($estacion['modo_reproduccion'] ?? 'normal') === 'aleatorio';

    if ($source['type'] !== 'rotation') {
        $songs = cpSchedFolderSongs($id, $source['id']);
        return $shuffle ? cpSchedShuffle($songs, $estacion['slug'] . ':' . $source['id']) : $songs;
    }

    // Rotation rules: separation (minutes) and selection mode
    $rules = ['sep_artista_min' => 0, 'sep_cancion_min' => 0, 'seleccion' => 'auto'];
    try {
        $rs = getDB()->prepare('SELECT sep_artista_min, sep_cancion_min, seleccion FROM rotacion_carpetas WHERE id = ?');
        $rs->execute([$source['id']]);
        $rules = array_merge($rules, $rs->fetch(PDO::FETCH_ASSOC) ?: []);
    } catch (PDOException $e) {}
    $mode = (string)$rules['seleccion'];
    if (!in_array($mode, ['orden', 'aleatorio', 'peso'], true)) $mode = $shuffle ? 'aleatorio' : 'orden';
    if ((int)$rules['sep_artista_min'] > 0 || (int)$rules['sep_cancion_min'] > 0 || $mode === 'peso'
        || ($mode === 'orden' && $shuffle) || ($mode === 'aleatorio' && !$shuffle)) {
        return cpSchedBuildRotationWithRules($estacion, (int)$source['id'], $mode,
            (int)$rules['sep_artista_min'] * 60, (int)$rules['sep_cancion_min'] * 60);
    }

    $st = getDB()->prepare('SELECT * FROM rotacion_carpetas_secuencia WHERE rotacion_id = ? ORDER BY orden ASC, id ASC');
    $st->execute([$source['id']]);
    $steps = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $songs = cpSchedFolderSongs($id, (int)$row['playlist_id'], $row);
        if (!$songs) continue; // empty folder (or nothing matches its year/BPM filter): skip its turn
        if ($shuffle) $songs = cpSchedShuffle($songs, $estacion['slug'] . ':' . $row['playlist_id']);
        $steps[] = ['songs' => $songs, 'n' => max(1, (int)$row['cantidad']), 'pos' => 0, 'wrapped' => false];
    }
    if (!$steps) return [];

    $out = [];
    $limit = 3000;
    while (count($out) < $limit) {
        foreach ($steps as &$s) {
            for ($k = 0; $k < $s['n']; $k++) {
                $out[] = $s['songs'][$s['pos']];
                $s['pos']++;
                if ($s['pos'] >= count($s['songs'])) { $s['pos'] = 0; $s['wrapped'] = true; }
            }
        }
        unset($s);
        if (!array_filter($steps, fn($s) => !$s['wrapped'])) break; // every folder came round
    }
    return $out;
}

/**
 * Rotation with mixing rules. Builds about one day of audio (the list is
 * rebuilt every day, Liquidsoap loops it):
 *  - the cycle (N songs from folder A, M from folder B, ...) repeats in order;
 *  - a song is not repeated within $sepSong seconds and an artist within
 *    $sepArtist seconds (time measured with each song's duration);
 *  - $mode: orden = folder order, aleatorio = random, peso = random weighted
 *    by each song's weight (1-5).
 * When a small folder (jingles, promos) cannot meet a rule, the song that
 * played longest ago is used, so the cycle never stops.
 */
function cpSchedBuildRotationWithRules(array $estacion, int $rotationId, string $mode, int $sepArtist, int $sepSong): array {
    $id = (int)$estacion['id'];
    $st = getDB()->prepare('SELECT * FROM rotacion_carpetas_secuencia WHERE rotacion_id = ? ORDER BY orden ASC, id ASC');
    $st->execute([$rotationId]);
    $steps = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $songs = cpSchedFolderSongs($id, (int)$row['playlist_id'], $row);
        if ($songs) $steps[] = ['songs' => $songs, 'n' => max(1, (int)$row['cantidad']), 'ptr' => 0];
    }
    if (!$steps) return [];

    mt_srand(crc32($estacion['slug'] . ':rot' . $rotationId . ':' . date('Y-m-d')));
    $lastSong = [];   // ruta => time it started (seconds from list start)
    $lastArtist = []; // normalised artist => time
    $t = 0;
    $out = [];
    $targetSeconds = 26 * 3600;
    $artistKey = static fn(array $s): string => mb_strtolower(trim((string)($s['artista'] ?? '')));

    while ($t < $targetSeconds && count($out) < 3000) {
        foreach ($steps as &$step) {
            for ($k = 0; $k < $step['n']; $k++) {
                $songs = $step['songs'];
                $ok = static function (array $s, bool $checkArtist) use ($t, $sepSong, $sepArtist, $lastSong, $lastArtist, $artistKey): bool {
                    $r = $s['ruta_archivo'];
                    if ($sepSong > 0 && isset($lastSong[$r]) && $t - $lastSong[$r] < $sepSong) return false;
                    if ($checkArtist && $sepArtist > 0) {
                        $a = $artistKey($s);
                        if ($a !== '' && isset($lastArtist[$a]) && $t - $lastArtist[$a] < $sepArtist) return false;
                    }
                    return true;
                };
                // Indexes that meet both rules; relax the artist rule, then fall back to the least recently played
                $eligible = array_keys(array_filter($songs, fn($s) => $ok($s, true)));
                if (!$eligible) $eligible = array_keys(array_filter($songs, fn($s) => $ok($s, false)));
                if (!$eligible) {
                    $best = 0; $bestT = PHP_INT_MAX;
                    foreach ($songs as $i => $s) {
                        $lt = $lastSong[$s['ruta_archivo']] ?? -1;
                        if ($lt < $bestT) { $bestT = $lt; $best = $i; }
                    }
                    $eligible = [$best];
                }

                if ($mode === 'orden') {
                    // first eligible song at or after the folder pointer
                    $cnt = count($songs);
                    $pick = $eligible[0];
                    $set = array_flip($eligible);
                    for ($j = 0; $j < $cnt; $j++) {
                        $idx = ($step['ptr'] + $j) % $cnt;
                        if (isset($set[$idx])) { $pick = $idx; break; }
                    }
                    $step['ptr'] = ($pick + 1) % $cnt;
                } elseif ($mode === 'peso') {
                    $total = 0;
                    foreach ($eligible as $i) $total += max(1, min(5, (int)($songs[$i]['peso'] ?? 3)));
                    $roll = mt_rand(1, $total);
                    $pick = $eligible[0];
                    foreach ($eligible as $i) {
                        $roll -= max(1, min(5, (int)($songs[$i]['peso'] ?? 3)));
                        if ($roll <= 0) { $pick = $i; break; }
                    }
                } else {
                    $pick = $eligible[mt_rand(0, count($eligible) - 1)];
                }

                $song = $songs[$pick];
                $out[] = $song;
                $lastSong[$song['ruta_archivo']] = $t;
                $a = $artistKey($song);
                if ($a !== '') $lastArtist[$a] = $t;
                $dur = (int)($song['duracion_segundos'] ?? 0);
                $t += $dur > 0 ? $dur : 210;
            }
        }
        unset($step);
    }
    mt_srand();
    return $out;
}

/** Write a file only when its content changed. @return bool changed */
function cpSchedWriteIfChanged(string $path, string $content): bool {
    if (is_file($path) && @file_get_contents($path) === $content) return false;
    $dir = dirname($path);
    if (!is_dir($dir)) @mkdir($dir, 0775, true);
    $tmp = $path . '.tmp' . getmypid();
    if (@file_put_contents($tmp, $content) === false) return false;
    @chmod($tmp, 0664);
    return @rename($tmp, $path);
}

/** @return array{changed:bool,count:int,path:string,ok:bool} */
function castpanelWriteMusicM3u(array $estacion, ?array $source = null): array {
    require_once __DIR__ . '/liquidsoap_playlist.php';
    $slug = $estacion['slug'];
    $source = $source ?? castpanelResolveMusicSource($estacion);
    $songs = castpanelBuildMusicTracks($estacion, $source);

    $lines = ['#EXTM3U', '# CastPanel — ' . $source['type'] . ($source['name'] ? ': ' . $source['name'] : '')];
    $count = 0;
    foreach ($songs as $row) {
        $hostPath = (string)($row['ruta_archivo'] ?? '');
        if ($hostPath === '') continue;
        $artist = trim((string)($row['artista'] ?? ''));
        $title = trim((string)($row['titulo'] ?? '')) ?: pathinfo($hostPath, PATHINFO_FILENAME);
        $display = $artist !== '' ? $artist . ' - ' . $title : $title;
        $lines[] = '#EXTINF:' . max(1, (int)($row['duracion_segundos'] ?? 0)) . ',' . str_replace(["\r", "\n"], ' ', $display);
        $lines[] = castpanelContainerMediaPath($hostPath, $slug);
        $count++;
    }
    $mediaRoot = defined('MEDIA_PATH') ? MEDIA_PATH : '/var/castpanel/media';
    $path = rtrim($mediaRoot, '/') . '/' . $slug . '/active.m3u';
    $prevCount = 0; // songs in the list Liquidsoap had before this write
    if (is_file($path)) {
        foreach ((array)@file($path, FILE_IGNORE_NEW_LINES) as $l) {
            if ($l !== '' && $l[0] !== '#') $prevCount++;
        }
    }
    $changed = cpSchedWriteIfChanged($path, implode("\n", $lines) . "\n");
    $ok = is_file($path);
    return ['changed' => $changed, 'count' => $count, 'prev_count' => $prevCount, 'path' => $path, 'ok' => $ok, 'source' => $source];
}

/**
 * Ads allowed right now. No ad schedules at all → every active ad, 1 ad
 * every 5 songs (the old behaviour). Schedules exist but none matches now
 * → no ads.
 */
function castpanelWriteAdsFiles(array $estacion, ?DateTimeInterface $now = null): array {
    $now = cpSchedNow($now);
    $db = getDB();
    $id = (int)$estacion['id'];
    $slug = $estacion['slug'];
    $every = 5;
    $perBlock = 1;
    $listIds = null; // null = all lists

    try {
        $st = $db->prepare("SELECT * FROM programacion_anuncios WHERE estacion_id = ? AND estado = 'activa' ORDER BY prioridad DESC, id ASC");
        $st->execute([$id]);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
        if ($rows) {
            $listIds = [];
            $first = true;
            foreach ($rows as $r) {
                if (!cpSchedDaysMatch((string)$r['dias'], $now, true)) continue;
                if (!cpSchedTimeIn($now, (string)$r['hora_inicio'], (string)$r['hora_fin'])) continue;
                $listIds[] = (int)$r['lista_anuncios_id'];
                if ($first) { // highest priority decides the frequency
                    $every = max(1, (int)$r['cada_x_canciones']);
                    $perBlock = max(1, (int)$r['anuncios_por_bloque']);
                    $first = false;
                }
            }
        }
    } catch (PDOException $e) {}

    $ads = [];
    if ($listIds === null || $listIds) {
        $sql = 'SELECT ruta_archivo, nombre_original FROM anuncios WHERE estacion_id = ? AND activo = 1';
        $params = [$id];
        if ($listIds) {
            $sql .= ' AND lista_id IN (' . implode(',', array_fill(0, count($listIds), '?')) . ')';
            $params = array_merge($params, $listIds);
        }
        $st = $db->prepare($sql . ' ORDER BY orden ASC, id ASC');
        $st->execute($params);
        $ads = $st->fetchAll(PDO::FETCH_ASSOC);
    }

    $adsRoot = rtrim(defined('ANUNCIOS_PATH') ? ANUNCIOS_PATH : '/var/castpanel/anuncios', '/') . '/' . $slug;
    $lines = ['#EXTM3U'];
    foreach ($ads as $a) {
        $host = (string)$a['ruta_archivo'];
        if ($host === '') continue;
        $rel = str_starts_with($host, $adsRoot . '/') ? substr($host, strlen($adsRoot) + 1) : basename($host);
        $lines[] = '/anuncios/' . $rel;
    }
    $c1 = cpSchedWriteIfChanged($adsRoot . '/active_ads.m3u', implode("\n", $lines) . "\n");
    $c2 = cpSchedWriteIfChanged($adsRoot . '/ads.conf', "$every $perBlock\n");
    return ['changed' => $c1 || $c2, 'count' => count($lines) - 1, 'every' => $every, 'per_block' => $perBlock];
}

/** Relay that should run now (highest priority), or null. */
function castpanelResolveRelay(array $estacion, ?DateTimeInterface $now = null): ?array {
    $now = cpSchedNow($now);
    try {
        $st = getDB()->prepare('SELECT * FROM relay_redireccionamiento WHERE estacion_id = ? AND activo = 1 ORDER BY prioridad DESC, id ASC');
        $st->execute([(int)$estacion['id']]);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            if (!preg_match('#^https?://[^\s"\']+$#i', (string)$r['url_fuente'])) continue;
            $inTime = cpSchedTimeIn($now, (string)$r['hora_inicio'], (string)$r['hora_fin']);
            $match = match ($r['tipo_horario']) {
                '24x7' => true,
                'semanal' => cpSchedDaysMatch((string)$r['dias'], $now) && $inTime,
                'fecha_unica' => ($r['fecha_unica'] ?? '') === $now->format('Y-m-d') && $inTime,
                default => false,
            };
            if ($match) return $r;
        }
    } catch (PDOException $e) {}
    return null;
}

function castpanelWriteRelayFile(array $estacion, ?DateTimeInterface $now = null): array {
    $relay = castpanelResolveRelay($estacion, $now);
    $mediaRoot = defined('MEDIA_PATH') ? MEDIA_PATH : '/var/castpanel/media';
    $path = rtrim($mediaRoot, '/') . '/' . $estacion['slug'] . '/relay.url';
    $changed = cpSchedWriteIfChanged($path, $relay ? trim($relay['url_fuente']) . "\n" : "\n");
    return ['changed' => $changed, 'relay' => $relay ? ['id' => (int)$relay['id'], 'name' => $relay['nombre'], 'url' => $relay['url_fuente']] : null];
}

/**
 * Apply every schedule for one station (writes only what changed).
 * $estacion needs the station row plus `autodj_enabled` (loadStationWithPackage()).
 */
function castpanelApplyStationSchedule(array $estacion, ?DateTimeInterface $now = null): array {
    if (empty($estacion['slug'])) return ['success' => false, 'error' => 'no slug'];
    $music = castpanelWriteMusicM3u($estacion, castpanelResolveMusicSource($estacion, $now));
    $ads = castpanelWriteAdsFiles($estacion, $now);
    $relay = castpanelWriteRelayFile($estacion, $now);
    // Tell a running Liquidsoap about changed playlists (only then: a reload
    // restarts the list from its first song). relay.url / ads.conf are re-read
    // by Liquidsoap on its own.
    // kick (skip to the first song) only when the list was empty, i.e. on silence
    if ($music['changed']) cpSchedLiquidsoapReload($estacion, 'music', $music['prev_count'] === 0 ? $music['count'] : 0);
    if ($ads['changed']) cpSchedLiquidsoapReload($estacion, 'ads', 0);
    return ['success' => true, 'music' => $music, 'ads' => $ads, 'relay' => $relay];
}

function cpSchedLiquidsoapReload(array $estacion, string $source, int $songCount): void {
    if (defined('CASTPANEL_STREAMING_DISABLED') && CASTPANEL_STREAMING_DISABLED) return;
    if (!function_exists('liquidsoapCommand')) return;
    $port = (int)(($estacion['puerto_telnet'] ?? 0) ?: (LIQUIDSOAP_TELNET_PORT + (int)$estacion['id']));
    $reply = liquidsoapCommand($source . '_' . $estacion['slug'] . '.reload', $port);
    // A list that was empty is on silence: move on to its first song now
    if ($reply !== null && $source === 'music' && $songCount > 0 && function_exists('castpanelKickAutoDJIfNeeded')) {
        usleep(300000);
        castpanelKickAutoDJIfNeeded($estacion, $songCount);
    }
}

/** Convenience for panel pages: apply right after a save (never breaks the page). */
function castpanelApplyStationScheduleById(int $estacionId): void {
    try {
        require_once __DIR__ . '/provisioning.php';
        $est = loadStationWithPackage($estacionId);
        if ($est && !empty($est['autodj_enabled'])) castpanelApplyStationSchedule($est);
    } catch (Throwable $e) {
        error_log('CastPanel schedule: ' . $e->getMessage());
    }
}
