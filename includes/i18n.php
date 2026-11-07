<?php
/**
 * CastPanel v2.4 - Sistema de Internacionalización (i18n)
 *
 * Carga traducciones basadas en el idioma configurado.
 * Uso: __('key') retorna la traducción, o la key si no existe.
 */

// Almacena las traducciones cargadas
$_TRANSLATIONS = [];
$_CURRENT_LANG = 'es';

/**
 * Cargar traducciones del idioma especificado
 */
function loadTranslations(?string $lang = null): void {
    global $_TRANSLATIONS, $_CURRENT_LANG;

    if ($lang === null) {
        // Obtener idioma de la BD
        try {
            $db = getDB();
            $row = $db->query("SELECT valor FROM configuracion WHERE clave = 'default_language'")->fetch();
            $lang = $row ? $row['valor'] : 'es';
        } catch (\Throwable $e) {
            $lang = 'es';
        }
    }

    $_CURRENT_LANG = $lang;
    $langFile = __DIR__ . '/../lang/' . basename($lang) . '.php';

    if (file_exists($langFile)) {
        $_TRANSLATIONS = require $langFile;
    } else {
        // Fallback a español
        $esFile = __DIR__ . '/../lang/es.php';
        $_TRANSLATIONS = file_exists($esFile) ? require $esFile : [];
        $_CURRENT_LANG = 'es';
    }
}

/**
 * Obtener traducción de una clave
 * @param string $key Clave de traducción
 * @param array $params Parámetros para reemplazo (ej: [':name' => 'valor'])
 * @return string Texto traducido
 */
function __($key, array $params = []): string {
    global $_TRANSLATIONS;

    static $fallback = null;
    if (!isset($_TRANSLATIONS[$key])) {
        // Older language files miss some keys: fall back to Spanish (the
        // source language), which the page translator then translates
        if ($fallback === null) {
            $fallback = (array)@include __DIR__ . '/../lang/es.php';
        }
        $text = $fallback[$key] ?? $key;
    } else {
        $text = $_TRANSLATIONS[$key];
    }

    // Reemplazar parámetros
    if (!empty($params)) {
        foreach ($params as $param => $value) {
            $text = str_replace($param, $value, $text);
        }
    }

    return $text;
}

/**
 * Imprimir traducción directamente (echo)
 */
function _e($key, array $params = []): void {
    echo __($key, $params);
}

/**
 * Obtener el idioma actual
 */
function getCurrentLang(): string {
    global $_CURRENT_LANG;
    return $_CURRENT_LANG;
}

/**
 * Obtener idiomas disponibles con información
 */
function getAvailableLanguages(): array {
    $langDir = __DIR__ . '/../lang/';
    $languages = [
        'es' => ['name' => 'Español', 'flag' => '🇪🇸', 'native' => 'Español'],
        'en' => ['name' => 'English', 'flag' => '🇺🇸', 'native' => 'English'],
        'pt' => ['name' => 'Português', 'flag' => '🇧🇷', 'native' => 'Português'],
        'fr' => ['name' => 'Français', 'flag' => '🇫🇷', 'native' => 'Français'],
        'de' => ['name' => 'Deutsch', 'flag' => '🇩🇪', 'native' => 'Deutsch'],
        'it' => ['name' => 'Italiano', 'flag' => '🇮🇹', 'native' => 'Italiano'],
        'nl' => ['name' => 'Nederlands', 'flag' => '🇳🇱', 'native' => 'Nederlands'],
        'tr' => ['name' => 'Türkçe', 'flag' => '🇹🇷', 'native' => 'Türkçe'],
        'pl' => ['name' => 'Polski', 'flag' => '🇵🇱', 'native' => 'Polski'],
        'ro' => ['name' => 'Română', 'flag' => '🇷🇴', 'native' => 'Română'],
        'cs' => ['name' => 'Čeština', 'flag' => '🇨🇿', 'native' => 'Čeština'],
        'sr' => ['name' => 'Srpski', 'flag' => '🇷🇸', 'native' => 'Srpski'],
        'el' => ['name' => 'Ελληνικά', 'flag' => '🇬🇷', 'native' => 'Ελληνικά'],
        'af' => ['name' => 'Afrikaans', 'flag' => '🇿🇦', 'native' => 'Afrikaans'],
        'fa' => ['name' => 'فارسی', 'flag' => '🇮🇷', 'native' => 'فارسی'],
    ];

    // Solo retornar idiomas que tienen archivo de traducción
    $available = [];
    foreach ($languages as $code => $info) {
        $info['has_file'] = file_exists($langDir . $code . '.php');
        if ($info['has_file']) {
            // Contar claves traducidas
            $info['keys_count'] = cpPhraseCount($code);
        } else {
            $info['keys_count'] = 0;
        }
        $available[$code] = $info;
    }

    return $available;
}

/**
 * Obtener porcentaje de cobertura de traducción comparado con español (base)
 */
function getTranslationCoverage(string $langCode): int {
    $total = cpPhraseCount('es');
    if ($total === 0) return 0;
    return (int)min(100, round(cpPhraseCount($langCode) / $total * 100));
}

/**
 * Number of panel phrases translated for a language (lang/phrases/<code>.txt).
 * For 'es' (the source) it is the number of translatable phrases, i.e. the
 * phrases that have an English translation.
 */
function cpPhraseCount(string $code): int {
    $dir = __DIR__ . '/../lang/phrases/';
    $en = @file($dir . 'en.txt', FILE_IGNORE_NEW_LINES) ?: [];
    if ($code === 'es' || $code === 'en') {
        return count(array_filter($en, fn($l) => trim($l) !== ''));
    }
    $tr = @file($dir . basename($code) . '.txt', FILE_IGNORE_NEW_LINES);
    if (!$tr) return 0;
    // A phrase counts as covered when it is translated, or when it is a
    // name/technical term that stays the same in every language (those
    // lines are left empty on purpose and fall back to English).
    $n = 0;
    foreach ($en as $i => $e) {
        if (trim($e) === '') continue;
        if (trim($tr[$i] ?? '') !== '' || preg_match(CP_NEUTRAL_PHRASE, trim($e))) $n++;
    }
    return $n;
}

// =====================================================================
// Full-page translation
// ---------------------------------------------------------------------
// The panel templates are written in Spanish. Instead of rewriting every
// template, the rendered HTML is translated on output: every visible text
// node (and placeholder/title/alt attributes, button labels and plain JS
// string messages) that matches a phrase in lang/phrases/<lang>.php is
// replaced. Spanish is the source language, so 'es' needs no dictionary.
// =====================================================================

$_PHRASES = null;
// English phrases that are names or technical terms, identical in every language
define('CP_NEUTRAL_PHRASE', '/^(AAC\\+|AIza\\.\\.\\.|API (Identifier|JSON|Key|Secret|Now Playing)|API JSON - CastPanel|Apache.*|Blesta|ClientExec|Cron.*|Docker.*|Fail2ban|Firewalld|Favicon|Facebook Live.*|Instagram Live|TikTok Live.*|YouTube Live|InnoDB|Kernel|Knee|Node\\.js|PHP Configuration|PLS Playlist|M3U Playlist|XSPF Playlist|MP3 128k|Swap|Uptime|SELinux|SC v2\\.6|SID 1|IceCast v2|Icecast.*|SHOUTcast v[0-9.]+|ShoutCast.*|Shoutcast.*|Slug|Timezone|TuneIn \\/ Radio\\.net|VLC Media Player|WinAmp \\/ AIMP|iTunes \\/ Apple Music|phpMyAdmin.*|Release|Peak|Ratio|Load Average|Collation|Cores|Engine|Encoder|Endpoint|Hostname|Codec|Bitrate|Sample Rate|DJs|DJ Panel|AutoDJ|Admin|Reseller|Jingles|Promos|Premium|Rock \\/ Pop|Facebook:|Instagram:|RadioCaster:|Mount \\/ SID|Mounts|Relay|Ads|Media|Warning|Fatal error|HTTP\\/1\\.1 403 Forbidden|Sync Proxy|Reset Pass|Change Logos\\/Titles|Source IP|[A-Z]{2} · .+|English|Español|Deutsch|Français|Italiano|Português|\\| SHOUTcast:|↑ TX:|↓ RX:|● ONLINE|○ OFFLINE|now_playing\\..*|paquete_id is required|Facebook can|Instagram can|TikTok can|Firewall|Firewall \\(firewalld\\)|Online|Offline|Dashboard|Email|Email:|Host:|Status:|Station not found|Username|Branding|Social|Software|Total|Total Streams|Stream \\/ Source List.*|Widgets & Players)$/u');
$_PHRASE_PATTERNS = [];

/**
 * Pick the active language (global panel setting) and start translating
 * the page output. Call once, before any HTML is printed.
 */
function cpI18nStart(): void {
    static $started = false;
    if ($started) return;
    $started = true;
    loadTranslations();
    ob_start('cpTranslateOutput');
}

/** Normalise a phrase for lookup: decode entities, collapse whitespace. */
function cpPhraseKey(string $s): string {
    $s = html_entity_decode($s, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    return trim(preg_replace('/\s+/u', ' ', $s));
}

/** Spanish phrase => translation for the current language. */
function cpPhrases(): array {
    global $_PHRASES, $_PHRASE_PATTERNS, $_CURRENT_LANG;
    static $loadedFor = null;
    if ($_PHRASES !== null && $loadedFor === $_CURRENT_LANG) return $_PHRASES;
    $loadedFor = $_CURRENT_LANG;
    $_PHRASES = [];
    $_PHRASE_PATTERNS = [];
    if ($_CURRENT_LANG === 'es') return $_PHRASES;
    // lang/phrases/es.txt holds one Spanish phrase per line; <lang>.txt holds
    // the translation on the same line number (empty line = not translated).
    $dir = __DIR__ . '/../lang/phrases/';
    $src = @file($dir . 'es.txt', FILE_IGNORE_NEW_LINES);
    if (!$src) return $_PHRASES;
    // English is the fallback for phrases a language has not translated yet
    $chain = $_CURRENT_LANG === 'en' ? ['en'] : ['en', $_CURRENT_LANG];
    foreach ($chain as $code) {
        $tr = @file($dir . basename($code) . '.txt', FILE_IGNORE_NEW_LINES);
        if (!$tr) continue;
        foreach ($src as $i => $es) {
            $t = isset($tr[$i]) ? trim($tr[$i]) : '';
            if ($t === '' || $es === '') continue;
            if (strpos($es, '{n}') !== false || strpos($es, '{x}') !== false) {
                $re = preg_quote(cpPhraseKey($es), '/');
                $re = str_replace(['\\{n\\}', '\\{x\\}'], ['([\\d][\\d.,]*(?:\\s*[KMGT]?i?B)?)', '(.+?)'], $re);
                $_PHRASE_PATTERNS['/^' . $re . '$/u'] = $t;
            } else {
                $_PHRASES[cpPhraseKey($es)] = $t;
            }
        }
    }
    return $_PHRASES;
}

/** Look up a normalised phrase: exact match first, then {n}/{x} patterns. */
function cpLookup(string $k, array $p): ?string {
    global $_PHRASE_PATTERNS;
    if (isset($p[$k])) return $p[$k];
    foreach ($_PHRASE_PATTERNS as $re => $tr) {
        if (preg_match($re, $k, $m)) {
            array_shift($m);
            // translate captured text too (e.g. "Now Playing: {x}" with x = "Sin información")
            return preg_replace_callback('/\{[nx]\}/', function () use (&$m, $p) {
                $c = array_shift($m) ?? '';
                return $p[$c] ?? $c;
            }, $tr);
        }
    }
    return null;
}

/** Translate one phrase (plain text). Returns the input when unknown. */
function cpT(string $text): string {
    $p = cpPhrases();
    if (!$p) return $text;
    return cpLookup(cpPhraseKey($text), $p) ?? $text;
}

/** Translate a text segment of HTML, keeping its surrounding whitespace. */
function cpTranslateTextSegment(string $seg, array $p): string {
    if (trim($seg) === '') return $seg;
    $k = cpPhraseKey($seg);
    $tr = cpLookup($k, $p);
    if ($tr === null) {
        // "Label:" style text: translate the label, keep the colon
        if (preg_match('/^(.*?)(\s*[:：])$/u', $k, $m) && ($l = cpLookup($m[1], $p)) !== null) {
            $tr = $l . ':';
        } else {
            return $seg;
        }
    }
    preg_match('/^\s*/u', $seg, $lead);
    preg_match('/\s*$/u', $seg, $trail);
    return $lead[0] . htmlspecialchars($tr, ENT_NOQUOTES, 'UTF-8') . $trail[0];
}

/** Translate translatable attributes inside one HTML tag. */
function cpTranslateTag(string $tag, array $p): string {
    $isButton = (bool)preg_match('/^<input\b[^>]*\btype\s*=\s*["\']?(submit|button|reset)/i', $tag);
    return preg_replace_callback(
        '/\b(placeholder|title|alt|aria-label|data-confirm|value)(\s*=\s*)(["\'])(.*?)\3/is',
        function ($m) use ($p, $isButton) {
            if (strtolower($m[1]) === 'value' && !$isButton) return $m[0];
            $k = cpPhraseKey($m[4]);
            $tr = $k === '' ? null : cpLookup($k, $p);
            if ($tr === null) return $m[0];
            return $m[1] . $m[2] . $m[3] . htmlspecialchars($tr, ENT_QUOTES, 'UTF-8') . $m[3];
        },
        $tag
    );
}

/**
 * Translate user-facing string literals inside inline <script> blocks.
 * Only multi-word phrases are touched, so identifiers and status values
 * such as 'online' used in comparisons are left alone.
 */
function cpTranslateScript(string $js, array $p): string {
    return preg_replace_callback(
        '/`(?:[^`\\\\]|\\\\.)*`|\'(?:[^\'\\\\\n]|\\\\.)*\'|"(?:[^"\\\\\n]|\\\\.)*"/u',
        function ($m) use ($p) {
            $lit = $m[0];
            $q = $lit[0];
            $raw = substr($lit, 1, -1);
            if (strlen($raw) < 3) return $lit;
            $esc = function ($tr) use ($q) {
                return str_replace(['\\', $q, "\n"], ['\\\\', '\\' . $q, '\\n'], $tr);
            };
            // HTML snippet built in JS: translate the text between its tags
            if (strpos($raw, '<') !== false && strpos($raw, '>') !== false) {
                $parts = preg_split('/(<[^>]*>|\$\{[^}]*\})/', $raw, -1, PREG_SPLIT_DELIM_CAPTURE);
                foreach ($parts as $i => $part) {
                    if ($i % 2 === 1) {
                        if ($part[0] === '<') $parts[$i] = cpTranslateTag($part, $p);
                        continue;
                    }
                    if (trim($part) === '') continue;
                    $tr = cpLookup(cpPhraseKey($part), $p);
                    if ($tr !== null) {
                        preg_match('/^\s*/u', $part, $a); preg_match('/\s*$/u', $part, $b);
                        $parts[$i] = $a[0] . $esc($tr) . $b[0];
                    }
                }
                return $q . implode('', $parts) . $q;
            }
            if (strpos($raw, '${') !== false) return $lit;
            $k = cpPhraseKey(stripslashes($raw));
            // only multi-word phrases: single words may be status values or identifiers
            if (!preg_match('/\S\s+\S/u', $k) && !preg_match('/(\.\.\.|…|✓)$/u', $k)) return $lit;
            $tr = cpLookup($k, $p);
            return $tr === null ? $lit : $q . $esc($tr) . $q;
        },
        $js
    );
}

/** Output-buffer callback: translate a whole HTML document. */
function cpTranslateOutput(string $html, int $phase = 0): string {
    global $_CURRENT_LANG;
    if ($_CURRENT_LANG === 'es' || $html === '') return $html;
    // Leave JSON / non-HTML responses alone
    foreach (headers_list() as $h) {
        if (stripos($h, 'content-type:') === 0 && stripos($h, 'text/html') === false) return $html;
    }
    $p = cpPhrases();
    if (!$p) return $html;

    $html = preg_replace('/<html\b([^>]*)\blang="es"/i', '<html$1lang="' . htmlspecialchars($_CURRENT_LANG) . '"', $html, 1);
    // Right-to-left languages: the panel layout (fixed left sidebar) stays as
    // it is; only the text direction inside sidebar and content is flipped.
    if (in_array($_CURRENT_LANG, ['fa', 'ar', 'he', 'ur'], true)) {
        $html = preg_replace('#</head>#i', '<style>.sidebar-menu,.sidebar-section,.sidebar ul,.page-content,.main-content>header,.dj-main,.login-card{direction:rtl;text-align:right}.page-content input,.page-content code,.page-content pre{direction:ltr;text-align:left}</style></head>', $html, 1);
    }

    // Split into: comments | script/style blocks | tags | text
    $parts = preg_split(
        '/(<!--.*?-->|<script\b[^>]*>.*?<\/script>|<style\b[^>]*>.*?<\/style>|<textarea\b[^>]*>.*?<\/textarea>|<[^>]+>)/is',
        $html, -1, PREG_SPLIT_DELIM_CAPTURE
    );
    foreach ($parts as $i => $part) {
        if ($part === '') continue;
        if ($i % 2 === 0) {
            $parts[$i] = cpTranslateTextSegment($part, $p);
        } elseif (stripos($part, '<script') === 0) {
            if (preg_match('/^(<script\b[^>]*>)(.*)(<\/script>)$/is', $part, $m) && !preg_match('/\bsrc\s*=/i', $m[1])) {
                $parts[$i] = $m[1] . cpTranslateScript($m[2], $p) . $m[3];
            }
        } elseif (stripos($part, '<textarea') === 0) {
            $parts[$i] = preg_replace_callback('/^<textarea\b[^>]*>/i', fn($m) => cpTranslateTag($m[0], $p), $part);
        } elseif ($part[0] === '<' && $part[1] !== '!' && stripos($part, '<style') !== 0) {
            $parts[$i] = cpTranslateTag($part, $p);
        }
    }
    return implode('', $parts);
}
