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

    $text = $_TRANSLATIONS[$key] ?? $key;

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
    ];

    // Solo retornar idiomas que tienen archivo de traducción
    $available = [];
    foreach ($languages as $code => $info) {
        $info['has_file'] = file_exists($langDir . $code . '.php');
        if ($info['has_file']) {
            // Contar claves traducidas
            $translations = require $langDir . $code . '.php';
            $info['keys_count'] = count($translations);
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
    $langDir = __DIR__ . '/../lang/';
    $baseFile = $langDir . 'es.php';
    $targetFile = $langDir . $langCode . '.php';

    if (!file_exists($baseFile) || !file_exists($targetFile)) return 0;

    $base = require $baseFile;
    $target = require $targetFile;

    if (empty($base)) return 0;

    $translated = 0;
    foreach (array_keys($base) as $key) {
        if (isset($target[$key]) && $target[$key] !== '') {
            $translated++;
        }
    }

    return (int)round(($translated / count($base)) * 100);
}
