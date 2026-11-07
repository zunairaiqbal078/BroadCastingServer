<?php
/**
 * CastPanel v2.0 - Instalador
 *
 * Uso por CLI:
 *   php install.php
 *
 * Crea la base de datos, importa el schema y configura los directorios.
 */

echo "===========================================\n";
echo "  CastPanel v2.0 - Instalador\n";
echo "===========================================\n\n";

// Verificar que estamos en CLI
if (php_sapi_name() !== 'cli') {
    die("Este instalador solo funciona desde la línea de comandos.\n");
}

// Leer configuración
$configFile = __DIR__ . '/../config/database.php';
if (!file_exists($configFile)) {
    die("Error: No se encontró config/database.php\nCopie config/database.php.example y configure los datos de conexión.\n");
}

require_once $configFile;

echo "Conectando a MySQL...\n";

try {
    $db = getDB();
    echo "  ✓ Conexión exitosa\n\n";
} catch (Exception $e) {
    die("  ✗ Error de conexión: " . $e->getMessage() . "\n");
}

// Importar schema
echo "Importando schema...\n";
$schemaFile = __DIR__ . '/schema.sql';
if (!file_exists($schemaFile)) {
    die("  ✗ No se encontró install/schema.sql\n");
}

$sql = file_get_contents($schemaFile);
$statements = array_filter(
    array_map('trim', explode(';', $sql)),
    function($s) { return !empty($s) && $s !== ''; }
);

$executed = 0;
$errors = 0;
foreach ($statements as $statement) {
    try {
        $db->exec($statement);
        $executed++;
    } catch (PDOException $e) {
        // Ignorar errores de "tabla ya existe"
        if (strpos($e->getMessage(), 'already exists') === false) {
            echo "  ! Warning: " . $e->getMessage() . "\n";
            $errors++;
        }
    }
}
echo "  ✓ $executed sentencias ejecutadas" . ($errors ? " ($errors warnings)" : '') . "\n\n";

// Crear directorios
echo "Creando directorios...\n";
$dirs = [
    MEDIA_PATH,
    ANUNCIOS_PATH,
    BASE_PATH . '/assets/covers',
    BASE_PATH . '/assets/img',
];

foreach ($dirs as $dir) {
    if (!is_dir($dir)) {
        if (mkdir($dir, 0755, true)) {
            echo "  ✓ $dir\n";
        } else {
            echo "  ✗ No se pudo crear: $dir\n";
        }
    } else {
        echo "  - $dir (ya existe)\n";
    }
}

echo "\n";

// Verificar dependencias
echo "Verificando dependencias...\n";

// PHP version
echo "  PHP: " . PHP_VERSION;
echo version_compare(PHP_VERSION, '7.4.0', '>=') ? " ✓\n" : " ✗ (requiere 7.4+)\n";

// Extensiones
$exts = ['pdo', 'pdo_mysql', 'json', 'mbstring', 'curl'];
foreach ($exts as $ext) {
    echo "  ext-$ext: " . (extension_loaded($ext) ? "✓" : "✗ FALTA") . "\n";
}

// ffprobe/ffmpeg
$ffprobe = trim(shell_exec('which ffprobe 2>/dev/null') ?? '');
$ffmpeg = trim(shell_exec('which ffmpeg 2>/dev/null') ?? '');
echo "  ffprobe: " . ($ffprobe ? "✓ ($ffprobe)" : "✗ No encontrado (instale ffmpeg)") . "\n";
echo "  ffmpeg:  " . ($ffmpeg ? "✓ ($ffmpeg)" : "✗ No encontrado") . "\n";

echo "\n===========================================\n";
echo "  Instalación completada\n";
echo "===========================================\n\n";
echo "Datos de acceso por defecto:\n";
echo "  Usuario: admin\n";
echo "  Contraseña: password\n\n";
echo "¡IMPORTANTE! Cambie la contraseña del admin después de iniciar sesión.\n\n";
echo "Configuración:\n";
echo "  1. Edite config/database.php con sus datos de MySQL\n";
echo "  2. Configure su servidor web (Apache/Nginx) para apuntar al directorio del panel\n";
echo "  3. Configure Icecast y Liquidsoap\n";
echo "  4. Acceda al panel desde su navegador\n\n";
