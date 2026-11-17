<?php
/**
 * Safe incremental migration for existing CastPanel DB (no data wipe).
 * CLI: php install/migrate_safe.php
 */
if (php_sapi_name() !== 'cli') {
    die("CLI only\n");
}

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';

$db = getDB();
$log = [];

$columnExists = static function (string $table, string $column) use ($db): bool {
    $st = $db->prepare(
        'SELECT 1 FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ? LIMIT 1'
    );
    $st->execute([$table, $column]);
    return (bool)$st->fetchColumn();
};

$addColumn = static function (string $table, string $ddl) use ($db, &$log, $columnExists): void {
    if (!preg_match('/ADD COLUMN `?([a-zA-Z0-9_]+)`?/i', $ddl, $m)) {
        $log[] = "SKIP parse: $ddl";
        return;
    }
    $col = $m[1];
    if ($columnExists($table, $col)) {
        $log[] = "OK exists: $table.$col";
        return;
    }
    try {
        $db->exec("ALTER TABLE `$table` $ddl");
        $log[] = "ADDED: $table.$col";
    } catch (PDOException $e) {
        $log[] = "ERR $table.$col: " . $e->getMessage();
    }
};

// --- Columns (match install/schema.sql) ---
$addColumn('usuarios', "ADD COLUMN `idioma` VARCHAR(8) DEFAULT NULL");
$addColumn('cuentas_dj', "ADD COLUMN `panel_lang` VARCHAR(8) DEFAULT NULL");
$addColumn('estaciones', "ADD COLUMN `mensaje_dj` TEXT DEFAULT NULL");
$addColumn('paquetes', "ADD COLUMN `default_hostname` VARCHAR(255) NOT NULL DEFAULT ''");
$addColumn('paquetes', "ADD COLUMN `social_stream_enabled` TINYINT(1) NOT NULL DEFAULT 0");
$addColumn('paquetes', "ADD COLUMN `mount_points_enabled` TINYINT(1) NOT NULL DEFAULT 1");

// DJ UI language: keep panel_lang in sync with legacy idioma where panel_lang is empty
if ($columnExists('cuentas_dj', 'panel_lang') && $columnExists('cuentas_dj', 'idioma')) {
  try {
      $n = $db->exec("UPDATE cuentas_dj SET panel_lang = idioma WHERE panel_lang IS NULL AND idioma IS NOT NULL AND idioma != ''");
    $log[] = "Synced cuentas_dj.panel_lang from idioma ($n rows)";
  } catch (PDOException $e) {
      $log[] = 'Sync panel_lang: ' . $e->getMessage();
  }
}

// --- Missing configuracion keys (insert only) ---
$configDefaults = [
    'default_language' => 'es',
    'nombre_plataforma' => 'CastPanel',
    'media_path' => '/var/castpanel/media',
    'anuncios_path' => '/var/castpanel/anuncios',
    'liquidsoap_config_path' => '/var/castpanel/liquidsoap/stations',
    'enforce_limits' => '1',
    'enforce_interval' => '60',
    'auto_provision' => '1',
    'api_enabled' => '1',
];
foreach ($configDefaults as $clave => $valor) {
    $st = $db->prepare('SELECT 1 FROM configuracion WHERE clave = ? LIMIT 1');
    $st->execute([$clave]);
    if ($st->fetch()) {
        $log[] = "config ok: $clave";
        continue;
    }
    try {
        $db->prepare('INSERT INTO configuracion (clave, valor, descripcion) VALUES (?, ?, NULL)')->execute([$clave, $valor]);
        $log[] = "config added: $clave";
    } catch (PDOException $e) {
        $log[] = "config err $clave: " . $e->getMessage();
    }
}

foreach ($log as $line) {
    echo $line . "\n";
}
echo "MIGRATE_DONE\n";