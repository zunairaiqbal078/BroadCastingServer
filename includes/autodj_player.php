<?php
/**
 * AutoDJ Quick Control — Liquidsoap transport + track timing for the panel player.
 */

require_once __DIR__ . '/liquidsoap_playlist.php';

function castpanelLiquidsoapMetadata(array $estacion): array {
    $port = (int)($estacion['puerto_telnet'] ?? 0);
    if ($port <= 0) {
        return ['title' => '', 'artist' => ''];
    }
    $slug = $estacion['slug'] ?? '';
    $resp = liquidsoapCommand("output_{$slug}.metadata", $port);
    if (!$resp) {
        return ['title' => '', 'artist' => ''];
    }
    $title = '';
    $artist = '';
    if (preg_match('/title="([^"]*)"/', $resp, $m)) {
        $title = trim($m[1]);
    }
    if (preg_match('/artist="([^"]*)"/', $resp, $m)) {
        $artist = trim($m[1]);
    }
    return ['title' => $title, 'artist' => $artist];
}

/** @return list<array{display:string,title:string,uri:string,duration:int}> */
function castpanelActivePlaylistTracks(array $estacion): array {
    $db = getDB();
    $estacionId = (int)$estacion['id'];
    $slug = $estacion['slug'];
    $activePlaylistId = $estacion['autodj_playlist_activa'] ?? null;

    if ($activePlaylistId) {
        $stmt = $db->prepare(
            'SELECT ruta_archivo, titulo, artista, duracion_segundos FROM canciones
             WHERE estacion_id = ? AND playlist_id = ? AND activa = 1 ORDER BY orden ASC'
        );
        $stmt->execute([$estacionId, $activePlaylistId]);
    } else {
        $stmt = $db->prepare(
            'SELECT ruta_archivo, titulo, artista, duracion_segundos FROM canciones
             WHERE estacion_id = ? AND activa = 1 ORDER BY orden ASC'
        );
        $stmt->execute([$estacionId]);
    }
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $tracks = [];
    foreach ($rows as $row) {
        $hostPath = (string)($row['ruta_archivo'] ?? '');
        if ($hostPath === '') {
            continue;
        }
        $title = trim((string)($row['titulo'] ?? '')) ?: pathinfo($hostPath, PATHINFO_FILENAME);
        $artist = trim((string)($row['artista'] ?? ''));
        $display = $artist !== '' ? ($artist . ' - ' . $title) : $title;
        $tracks[] = [
            'display' => $display,
            'title' => $title,
            'uri' => castpanelContainerMediaPath($hostPath, $slug),
            'duration' => max(1, (int)($row['duracion_segundos'] ?? 0)),
        ];
    }
    return $tracks;
}

function castpanelTrackByUri(array $tracks, string $uri): ?int {
    $uri = trim($uri);
    if ($uri === '') {
        return null;
    }
    $base = basename($uri);
    foreach ($tracks as $i => $t) {
        $u = (string)($t['uri'] ?? '');
        if ($u === $uri || ($u !== '' && basename($u) === $base)) {
            return $i;
        }
    }

    return null;
}

function castpanelFindTrackIndex(array $tracks, string $needle): ?int {
    $needle = strtolower(trim($needle));
    if ($needle === '') {
        return null;
    }
    foreach ($tracks as $i => $t) {
        if (strtolower($t['display']) === $needle || strtolower($t['title']) === $needle) {
            return $i;
        }
    }
    foreach ($tracks as $i => $t) {
        $hay = strtolower($t['display'] . ' ' . $t['title']);
        if (str_contains($hay, $needle) || str_contains($needle, strtolower($t['title']))) {
            return $i;
        }
    }

    return null;
}

function castpanelCurrentTrackFromLiquidsoap(array $estacion): ?array {
    $slug = $estacion['slug'] ?? '';
    if ($slug === '') {
        return null;
    }
    $tracks = castpanelActivePlaylistTracks($estacion);
    $uri = castpanelLiquidsoapLastPreparedUri($slug);
    if ($uri === null) {
        return null;
    }
    $idx = castpanelTrackByUri($tracks, $uri);
    if ($idx === null) {
        return null;
    }

    return ['index' => $idx, 'track' => $tracks[$idx]];
}

function castpanelLiquidsoapTelnetPort(array $estacion): int {
    $port = (int)($estacion['puerto_telnet'] ?? 0);
    if ($port <= 0) {
        $port = (int)(defined('LIQUIDSOAP_TELNET_PORT') ? LIQUIDSOAP_TELNET_PORT : 1234)
            + (int)($estacion['id'] ?? 0);
    }
    return $port;
}

function castpanelResetTrackTiming(int $estacionId): void {
    @unlink(castpanelTrackTimingPath($estacionId));
}

function castpanelLiquidsoapTelnetOk(?string $response): bool {
    if ($response === null || trim($response) === '') {
        return false;
    }

    return stripos($response, 'ERROR') === false;
}

function castpanelLiquidsoapSkipNext(array $estacion): bool {
    $slug = $estacion['slug'] ?? '';
    $port = castpanelLiquidsoapTelnetPort($estacion);
    $before = castpanelLiquidsoapLastPreparedUri($slug);
    $metaBefore = trim(castpanelLiquidsoapMetadata($estacion)['title'] ?? '');
    castpanelResetTrackTiming((int)$estacion['id']);
    // Advance playlist (music) and on-air chain (output). Either alone can return OK without a new song.
    $outMusic = liquidsoapCommand("music_{$slug}.skip", $port);
    $outOutput = liquidsoapCommand("output_{$slug}.skip", $port);
    $ok = castpanelLiquidsoapTelnetOk($outMusic) || castpanelLiquidsoapTelnetOk($outOutput);
    if (!$ok) {
        return false;
    }
    $afterUri = castpanelLiquidsoapWaitPreparedUri($slug, $before, 4.0);
    $metaAfter = trim(castpanelLiquidsoapMetadata($estacion)['title'] ?? '');
    if ($afterUri !== null && $afterUri !== $before) {
        return true;
    }
    if ($metaBefore !== '' && $metaAfter !== '' && strcasecmp($metaBefore, $metaAfter) !== 0) {
        return true;
    }
    if ($before === null && $afterUri !== null) {
        return true;
    }

    return false;
}

function castpanelLiquidsoapPlayUri(array $estacion, string $uri): bool {
    $slug = $estacion['slug'] ?? '';
    $port = castpanelLiquidsoapTelnetPort($estacion);
    $uri = trim($uri);
    if ($uri === '') {
        return false;
    }
    liquidsoapCommand("music_{$slug}.uri {$uri}", $port);
    $out = liquidsoapCommand("music_{$slug}.skip", $port);
    liquidsoapCommand("output_{$slug}.skip", $port);
    return $out !== null && stripos($out, 'ERROR') === false;
}

function castpanelLiquidsoapSkipPrevious(array $estacion): bool {
    $tracks = castpanelActivePlaylistTracks($estacion);
    if (count($tracks) < 2) {
        return false;
    }
    $current = castpanelCurrentTrackFromLiquidsoap($estacion);
    $idx = $current['index'] ?? null;
    if ($idx === null) {
        $meta = castpanelLiquidsoapMetadata($estacion);
        $needle = $meta['title'] ?: ($meta['artist'] ?? '');
        $idx = castpanelFindTrackIndex($tracks, $needle) ?? 0;
    }
    $prev = $idx <= 0 ? count($tracks) - 1 : $idx - 1;
    $before = castpanelLiquidsoapLastPreparedUri($estacion['slug'] ?? '');
    castpanelResetTrackTiming((int)$estacion['id']);
    $ok = castpanelLiquidsoapPlayUri($estacion, $tracks[$prev]['uri']);
    if ($ok) {
        castpanelLiquidsoapWaitPreparedUri($estacion['slug'] ?? '', $before, 2.5);
    }

    return $ok;
}

function castpanelTrackTimingPath(int $estacionId): string {
    return sys_get_temp_dir() . '/castpanel_track_' . $estacionId . '.json';
}

/** Progress for the current song (elapsed + duration from DB + local timing). */
function castpanelLogTrackOnAir(int $estacionId, string $title, string $artist): void {
    $title = trim($title);
    if ($title === '') {
        return;
    }
    try {
        $db = getDB();
        $db->prepare("UPDATE log_emision SET estado = 'emitido' WHERE estacion_id = ? AND estado = 'al_aire'")
           ->execute([$estacionId]);
        $db->prepare(
            "INSERT INTO log_emision (estacion_id, tipo, titulo, artista, estado) VALUES (?,?,?,?, 'al_aire')"
        )->execute([$estacionId, 'cancion', $title, $artist]);
    } catch (Throwable $e) {
        // log table optional
    }
}

function castpanelTrackProgress(array $estacion, string $title, int $durationSec, string $artist = ''): array {
    $estacionId = (int)$estacion['id'];
    $file = castpanelTrackTimingPath($estacionId);
    $state = @json_decode((string)@file_get_contents($file), true) ?: [];
    $title = trim($title);
    if ($title !== '' && ($state['title'] ?? '') !== $title) {
        castpanelLogTrackOnAir($estacionId, $title, $artist);
        $state = [
            'title' => $title,
            'started_at' => time(),
            'duration' => max(1, $durationSec),
        ];
        @file_put_contents($file, json_encode($state), LOCK_EX);
    } elseif ($durationSec > 0) {
        $state['duration'] = max((int)($state['duration'] ?? 1), $durationSec);
        @file_put_contents($file, json_encode($state), LOCK_EX);
    }
    if ($title === '' || empty($state['started_at'])) {
        return ['elapsed' => 0, 'duration' => max(1, $durationSec), 'progress' => 0];
    }
    $dur = max(1, (int)($state['duration'] ?? $durationSec ?: 1));
    $elapsed = time() - (int)$state['started_at'];
    $elapsed = max(0, min($elapsed, $dur));
    return [
        'elapsed' => $elapsed,
        'duration' => $dur,
        'progress' => (float)min(100, round($elapsed / $dur * 100, 1)),
    ];
}

function castpanelResolveSongDuration(array $estacion, string $title, string $artist = ''): int {
    if ($title === '') {
        return 0;
    }
    $tracks = castpanelActivePlaylistTracks($estacion);
    $idx = castpanelFindTrackIndex($tracks, $title) ?? castpanelFindTrackIndex($tracks, $artist);
    return ($idx !== null) ? (int)$tracks[$idx]['duration'] : 0;
}

function castpanelQuickPlayerPayload(array $estacion): array {
    require_once __DIR__ . '/provisioning.php';
    $slug = $estacion['slug'] ?? '';
    if ($slug !== '') {
        castpanelEnsureLiquidsoapLogReadable($slug);
    }
    $ice = getStreamStatus($estacion);
    $tracks = castpanelActivePlaylistTracks($estacion);
    $current = castpanelCurrentTrackFromLiquidsoap($estacion);
    $title = '';
    $artist = '';
    $display = '';
    $idx = null;
    if ($current !== null) {
        $idx = $current['index'];
        $title = $current['track']['title'];
        $display = $current['track']['display'];
    }
    if ($display === '') {
        $meta = castpanelLiquidsoapMetadata($estacion);
        $title = trim($meta['title'] ?? '');
        $artist = trim($meta['artist'] ?? '');
        if ($title === '' && !empty($ice['online'])) {
            $title = castpanelLiquidsoapNowPlaying($estacion);
        }
        $display = $title;
        if ($display !== '' && $artist !== '' && stripos($display, $artist) === false) {
            $display = $artist . ' - ' . $title;
        }
        $idx = castpanelFindTrackIndex($tracks, $display) ?? castpanelFindTrackIndex($tracks, $title);
    }
    $label = $display ?: $title;
    $duration = $current !== null
        ? (int)$current['track']['duration']
        : castpanelResolveSongDuration($estacion, $label, $artist);
    if ($duration <= 1) {
        $duration = castpanelResolveSongDuration($estacion, $title, $artist);
    }
    $timing = castpanelTrackProgress($estacion, $label, $duration, $artist);
    if ($idx === null) {
        $idx = castpanelFindTrackIndex($tracks, $label) ?? castpanelFindTrackIndex($tracks, $title);
    }

    return [
        'online' => !empty($ice['online']),
        'title' => $display ?: $title,
        'artist' => $artist,
        'duration' => $timing['duration'],
        'elapsed' => $timing['elapsed'],
        'progress' => $timing['progress'],
        'can_skip' => stationHasAutoDJ($estacion) && count($tracks) > 0,
        'can_previous' => count($tracks) > 1,
        'playlist_index' => $idx,
        'playlist_total' => count($tracks),
    ];
}
