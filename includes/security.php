<?php
/**
 * CastPanel — Brute Force Protection & request filters (like SonicPanel)
 *
 * - Login (panel + DJ portal) and API: after N failed attempts from one IP
 *   within the window (default 5 in 60 min) that IP is banned (default 60 min).
 * - Login without a browser (no User-Agent) and known attack scanners
 *   (sqlmap, nikto, wpscan, ...) are refused.
 * Settings live in `configuracion` (sec_*). Admin page: admin/seguridad.php.
 */

const CP_SEC_DEFAULTS = [
    'sec_bf_enabled'      => '1',
    'sec_bf_max_attempts' => '5',
    'sec_bf_window_min'   => '60',
    'sec_bf_ban_min'      => '60',
    'sec_block_no_ua'     => '1',
    'sec_block_scanners'  => '1',
];
const CP_SEC_SCANNERS = ['sqlmap', 'nikto', 'wpscan', 'acunetix', 'nessus', 'netsparker', 'masscan', 'nmap', 'zgrab', 'dirbuster', 'gobuster', 'havij', 'w3af', 'openvas', 'jorgee', 'fimap'];

function cpSecEnsureTables(): void {
    static $done = false;
    if ($done) return;
    $done = true;
    try {
        $db = getDB();
        $db->exec("CREATE TABLE IF NOT EXISTS `seguridad_intentos` (
            `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
            `ip` varchar(45) NOT NULL,
            `usuario` varchar(128) DEFAULT NULL,
            `area` varchar(16) NOT NULL DEFAULT 'panel',
            `created_at` datetime NOT NULL DEFAULT current_timestamp(),
            PRIMARY KEY (`id`),
            KEY `idx_ip_time` (`ip`, `created_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $db->exec("CREATE TABLE IF NOT EXISTS `seguridad_bloqueos` (
            `ip` varchar(45) NOT NULL,
            `motivo` varchar(255) DEFAULT NULL,
            `intentos` int(10) unsigned NOT NULL DEFAULT 0,
            `bloqueado_hasta` datetime NOT NULL,
            `created_at` datetime NOT NULL DEFAULT current_timestamp(),
            PRIMARY KEY (`ip`),
            KEY `idx_hasta` (`bloqueado_hasta`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    } catch (PDOException $e) {
        error_log('CastPanel security tables: ' . $e->getMessage());
    }
}

/** All sec_* settings (defaults merged). */
function cpSecSettings(): array {
    static $cache = null;
    if ($cache !== null) return $cache;
    $cache = CP_SEC_DEFAULTS;
    try {
        $st = getDB()->query("SELECT clave, valor FROM configuracion WHERE clave LIKE 'sec\\_%'");
        foreach ($st->fetchAll(PDO::FETCH_KEY_PAIR) as $k => $v) {
            if (array_key_exists($k, $cache)) $cache[$k] = (string)$v;
        }
    } catch (PDOException $e) {}
    return $cache;
}

function cpSecSave(array $values): void {
    $st = getDB()->prepare('INSERT INTO configuracion (clave, valor) VALUES (?, ?) ON DUPLICATE KEY UPDATE valor = VALUES(valor)');
    foreach ($values as $k => $v) {
        if (array_key_exists($k, CP_SEC_DEFAULTS)) $st->execute([$k, (string)$v]);
    }
}

/** Panel-clock timestamp (PHP timezone), offset in seconds. The DB clock may use another timezone. */
function cpSecNow(int $offset = 0): string {
    return date('Y-m-d H:i:s', time() + $offset);
}

/** Ban an IP for $minutes. */
function cpSecBan(string $ip, string $reason, int $minutes, int $attempts = 0): void {
    cpSecEnsureTables();
    getDB()->prepare('INSERT INTO seguridad_bloqueos (ip, motivo, intentos, bloqueado_hasta, created_at) VALUES (?, ?, ?, ?, ?)
                      ON DUPLICATE KEY UPDATE motivo = VALUES(motivo), intentos = VALUES(intentos), bloqueado_hasta = VALUES(bloqueado_hasta), created_at = VALUES(created_at)')
        ->execute([$ip, $reason, $attempts, cpSecNow($minutes * 60), cpSecNow()]);
}

/** Client IP (the server's view; proxy headers are not trusted, they can be forged). */
function cpSecIp(): string {
    $ip = (string)($_SERVER['REMOTE_ADDR'] ?? '');
    return filter_var($ip, FILTER_VALIDATE_IP) ? $ip : '0.0.0.0';
}

function cpSecIsLocal(string $ip): bool {
    return $ip === '127.0.0.1' || $ip === '::1';
}

/** Is this IP banned right now? Returns the ban row or null. */
function cpSecBlocked(?string $ip = null): ?array {
    $ip = $ip ?? cpSecIp();
    if (cpSecIsLocal($ip) || cpSecSettings()['sec_bf_enabled'] !== '1') return null;
    cpSecEnsureTables();
    try {
        $st = getDB()->prepare('SELECT * FROM seguridad_bloqueos WHERE ip = ? AND bloqueado_hasta > ?');
        $st->execute([$ip, cpSecNow()]);
        return $st->fetch(PDO::FETCH_ASSOC) ?: null;
    } catch (PDOException $e) {
        return null;
    }
}

/** Minutes left on a ban (for the message). */
function cpSecMinutesLeft(array $ban): int {
    return max(1, (int)ceil((strtotime((string)$ban['bloqueado_hasta']) - time()) / 60));
}

/**
 * Record a failed login/API attempt. Bans the IP when the limit is reached.
 * @return int attempts left before the ban (0 = banned now)
 */
function cpSecFail(string $area, string $user = ''): int {
    $ip = cpSecIp();
    $cfg = cpSecSettings();
    if ($cfg['sec_bf_enabled'] !== '1' || cpSecIsLocal($ip)) return PHP_INT_MAX;
    cpSecEnsureTables();
    $max = max(1, (int)$cfg['sec_bf_max_attempts']);
    $window = max(1, (int)$cfg['sec_bf_window_min']);
    $ban = max(1, (int)$cfg['sec_bf_ban_min']);
    try {
        $db = getDB();
        $db->prepare('INSERT INTO seguridad_intentos (ip, usuario, area, created_at) VALUES (?, ?, ?, ?)')
           ->execute([$ip, mb_substr($user, 0, 128), $area, cpSecNow()]);
        $st = $db->prepare('SELECT COUNT(*) FROM seguridad_intentos WHERE ip = ? AND created_at > ?');
        $st->execute([$ip, cpSecNow(-$window * 60)]);
        $count = (int)$st->fetchColumn();
        if ($count >= $max) {
            cpSecBan($ip, "Brute force: $count intentos fallidos ($area)", $ban, $count);
            return 0;
        }
        // keep the table small
        if (mt_rand(1, 50) === 1) $db->prepare('DELETE FROM seguridad_intentos WHERE created_at < ?')->execute([cpSecNow(-7 * 86400)]);
        return $max - $count;
    } catch (PDOException $e) {
        error_log('CastPanel security: ' . $e->getMessage());
        return PHP_INT_MAX;
    }
}

/** Successful login: forget this IP's failed attempts. */
function cpSecSuccess(): void {
    if (cpSecSettings()['sec_bf_enabled'] !== '1') return;
    cpSecEnsureTables();
    try {
        getDB()->prepare('DELETE FROM seguridad_intentos WHERE ip = ?')->execute([cpSecIp()]);
    } catch (PDOException $e) {}
}

/**
 * Request filters for login and API endpoints. Returns an error message
 * (request must be refused) or '' when it may continue.
 */
function cpSecCheckRequest(): string {
    $cfg = cpSecSettings();
    $ua = trim((string)($_SERVER['HTTP_USER_AGENT'] ?? ''));
    if ($cfg['sec_block_scanners'] === '1' && $ua !== '') {
        $l = strtolower($ua);
        foreach (CP_SEC_SCANNERS as $s) {
            if (str_contains($l, $s)) return 'Acceso bloqueado (escáner de seguridad detectado)';
        }
    }
    if ($cfg['sec_block_no_ua'] === '1' && $ua === '' && !cpSecIsLocal(cpSecIp())) {
        return 'Acceso bloqueado (inicio de sesión sin navegador)';
    }
    $ban = cpSecBlocked();
    if ($ban) {
        return 'Demasiados intentos fallidos. Tu IP está bloqueada durante ' . cpSecMinutesLeft($ban) . ' minutos.';
    }
    return '';
}

/** Error text after a failed login, including the attempts left / ban notice. */
function cpSecFailMessage(string $base, int $left): string {
    if ($left === 0) {
        return 'Demasiados intentos fallidos. Tu IP está bloqueada durante ' . max(1, (int)cpSecSettings()['sec_bf_ban_min']) . ' minutos.';
    }
    if ($left <= 2) return $base . ' (' . $left . ' intento(s) restante(s) antes del bloqueo)';
    return $base;
}
