#!/usr/bin/env php
<?php
/**
 * CastPanel - Parche para auto-inicio de estaciones
 *
 * Este script modifica provisioning.php en el servidor para que
 * provisionStation() inicie automáticamente el servicio después
 * de crear la configuración.
 *
 * USO: sudo php /tmp/patch_autostart.php
 *
 * Es IDEMPOTENTE: si ya fue aplicado, no hace nada.
 * Crea backup antes de modificar.
 */

$file = '/var/www/castpanel/includes/provisioning.php';

if (!file_exists($file)) {
    fwrite(STDERR, "ERROR: No se encuentra $file\n");
    exit(1);
}

$content = file_get_contents($file);
$original = $content;

echo "=== CastPanel - Parche Auto-Inicio ===\n\n";

// ============================================================
// VERIFICACIÓN 1: ¿provisionStation ya llama a startStation?
// ============================================================

// Encontrar la función provisionStation
if (!preg_match('/function\s+provisionStation\s*\(\s*int\s+\$estacionId\s*\)\s*:\s*array\s*\{/', $content, $match, PREG_OFFSET_CAPTURE)) {
    fwrite(STDERR, "ERROR: No se encontró función provisionStation()\n");
    exit(1);
}

$funcStart = $match[0][1];
echo "✓ provisionStation() encontrada en posición $funcStart\n";

// Encontrar el cierre de la función (seguir llaves)
$braceCount = 0;
$inFunc = false;
$funcEnd = 0;
for ($i = $funcStart; $i < strlen($content); $i++) {
    if ($content[$i] === '{') {
        $braceCount++;
        $inFunc = true;
    } elseif ($content[$i] === '}') {
        $braceCount--;
        if ($inFunc && $braceCount === 0) {
            $funcEnd = $i;
            break;
        }
    }
}

if ($funcEnd === 0) {
    fwrite(STDERR, "ERROR: No se pudo encontrar el cierre de provisionStation()\n");
    exit(1);
}

$funcBody = substr($content, $funcStart, $funcEnd - $funcStart + 1);
echo "✓ Función tiene " . strlen($funcBody) . " bytes (líneas " . substr_count(substr($content, 0, $funcStart), "\n") . " a " . substr_count(substr($content, 0, $funcEnd), "\n") . ")\n";

// Verificar si ya tiene auto-start
if (strpos($funcBody, 'startStation') !== false ||
    strpos($funcBody, 'startLiquidsoapService') !== false ||
    strpos($funcBody, 'startIcecastService') !== false ||
    strpos($funcBody, 'startShoutcastService') !== false) {
    echo "\n✓ provisionStation() YA tiene llamada de inicio. No se necesita parche.\n";

    // Verificar icecast_port
    if (strpos($funcBody, 'ICECAST_PORT') !== false || strpos($funcBody, 'icecast_port') !== false) {
        echo "✓ icecast_port ya está configurado en provisionStation()\n";
    }

    echo "\nNo se realizaron cambios.\n";
    exit(0);
}

echo "✗ provisionStation() NO llama a función de inicio → aplicando parche\n";

// ============================================================
// PARCHE 1: Agregar icecast_port = 8000 si falta
// ============================================================

$icecastPortPatch = '';
if (strpos($funcBody, 'icecast_port') === false && strpos($funcBody, 'ICECAST_PORT') === false) {
    echo "\n[PARCHE 1] Agregando icecast_port = 8000...\n";

    // Buscar donde se asigna puerto_stream para insertar después
    $puertoStreamPattern = '/(\$estacion\[.puerto_stream.\]\s*=\s*\$puertoStream\s*;)/';
    if (preg_match($puertoStreamPattern, $funcBody, $psMatch, PREG_OFFSET_CAPTURE)) {
        $insertPos = $funcStart + $psMatch[0][1] + strlen($psMatch[0][0]);

        $icecastFix = "\n\n        // Fijar icecast_port al puerto del servidor Icecast (siempre 8000)\n" .
                      "        \$icecastPort = (int)(defined('ICECAST_PORT') ? ICECAST_PORT : 8000);\n" .
                      "        \$db->prepare(\"UPDATE estaciones SET icecast_port = ? WHERE id = ?\")\n" .
                      "           ->execute([\$icecastPort, \$estacionId]);\n" .
                      "        \$estacion['icecast_port'] = \$icecastPort;";

        $content = substr($content, 0, $insertPos) . $icecastFix . substr($content, $insertPos);

        // Recalcular funcEnd después de la inserción
        $funcEnd += strlen($icecastFix);

        echo "  ✓ icecast_port fijado a ICECAST_PORT (8000)\n";
    } else {
        echo "  ⚠ No se encontró asignación de puerto_stream, insertando al inicio del try\n";

        // Buscar el try { dentro de provisionStation
        $tryPos = strpos($funcBody, 'try {');
        if ($tryPos !== false) {
            $insertPos = $funcStart + $tryPos + 5; // después de "try {"

            $icecastFix = "\n        // Fijar icecast_port al puerto del servidor Icecast (siempre 8000)\n" .
                          "        \$icecastPort = (int)(defined('ICECAST_PORT') ? ICECAST_PORT : 8000);\n";

            $content = substr($content, 0, $insertPos) . $icecastFix . substr($content, $insertPos);
            $funcEnd += strlen($icecastFix);

            echo "  ✓ icecast_port fijado después del try\n";
        }
    }
}

// ============================================================
// PARCHE 2: Agregar firewall port opening si falta
// ============================================================

// Re-extraer funcBody después de posibles cambios
$funcBody = substr($content, $funcStart, $funcEnd - $funcStart + 1);

if (strpos($funcBody, 'firewall-cmd') === false) {
    echo "\n[PARCHE 2] Agregando apertura de puerto en firewall...\n";
    // Se insertará junto con el auto-start
} else {
    echo "\n✓ Firewall ya configurado en provisionStation()\n";
}

// ============================================================
// PARCHE 3: Agregar auto-start antes del return success
// ============================================================

echo "\n[PARCHE 3] Agregando auto-inicio del servicio...\n";

// Re-extraer funcBody
$funcBody = substr($content, $funcStart, $funcEnd - $funcStart + 1);

// Buscar "return ['success' => true" dentro de la función
$returnPattern = "return ['success' => true";
$returnPos = strpos($funcBody, $returnPattern);

if ($returnPos === false) {
    // Probar variante con comillas dobles
    $returnPattern = 'return ["success" => true';
    $returnPos = strpos($funcBody, $returnPattern);
}

if ($returnPos === false) {
    // Probar con array()
    $returnPattern = "return array('success' => true";
    $returnPos = strpos($funcBody, $returnPattern);
}

if ($returnPos === false) {
    fwrite(STDERR, "ERROR: No se encontró 'return success' en provisionStation()\n");
    fwrite(STDERR, "Últimas 500 chars de la función:\n");
    fwrite(STDERR, substr($funcBody, -500) . "\n");
    exit(1);
}

$absoluteReturnPos = $funcStart + $returnPos;

// Construir el código a insertar
$autoStartCode = '';

// Agregar firewall si no existe
if (strpos($funcBody, 'firewall-cmd') === false) {
    $autoStartCode .= "        // Abrir puerto harbor en firewall\n" .
                       "        @exec(\"sudo firewall-cmd --permanent --add-port={\$puertoStream}/tcp 2>/dev/null\");\n" .
                       "        @exec(\"sudo firewall-cmd --reload 2>/dev/null\");\n" .
                       "        \$log[] = \"Puerto harbor \$puertoStream abierto en firewall\";\n\n";
}

// Agregar auto-start
$autoStartCode .= "        // Auto-inicio: Iniciar servicio de streaming automáticamente\n" .
                   "        \$startResult = startStation(\$estacion);\n" .
                   "        if (\$startResult['success']) {\n" .
                   "            \$log[] = \"Servicio iniciado automáticamente (\" . (\$estacion['server_type'] ?? 'default') . \")\";\n" .
                   "        } else {\n" .
                   "            \$log[] = \"AVISO: No se pudo iniciar automáticamente: \" . (\$startResult['error'] ?? 'Error desconocido');\n" .
                   "        }\n\n";

$content = substr($content, 0, $absoluteReturnPos) . $autoStartCode . substr($content, $absoluteReturnPos);

echo "  ✓ Llamada a startStation() insertada antes del return\n";

// ============================================================
// VERIFICAR que startStation() existe en el archivo
// ============================================================

if (strpos($content, 'function startStation') === false) {
    echo "\n⚠ ADVERTENCIA: No se encontró función startStation() en el archivo.\n";
    echo "  Buscando alternativas...\n";

    if (strpos($content, 'function startLiquidsoapService') !== false) {
        echo "  → startLiquidsoapService() encontrada. Cambiando llamada...\n";
        $content = str_replace('startStation($estacion)', 'startLiquidsoapService($estacion)', $content);
        echo "  ✓ Cambiado a startLiquidsoapService()\n";
    } else {
        echo "  ⚠ No hay función de inicio disponible. El parche podría no funcionar.\n";
    }
}

// ============================================================
// GUARDAR
// ============================================================

if ($content === $original) {
    echo "\nNo se realizaron cambios.\n";
    exit(0);
}

// Crear backup
$backup = $file . '.bak.' . date('YmdHis');
if (!copy($file, $backup)) {
    fwrite(STDERR, "ERROR: No se pudo crear backup en $backup\n");
    exit(1);
}
echo "\n✓ Backup creado: $backup\n";

// Escribir archivo parchado
if (file_put_contents($file, $content) === false) {
    fwrite(STDERR, "ERROR: No se pudo escribir $file\n");
    // Restaurar backup
    copy($backup, $file);
    exit(1);
}

echo "✓ Archivo parchado exitosamente\n";

// Verificar sintaxis PHP
$syntaxOutput = [];
exec("php -l $file 2>&1", $syntaxOutput, $syntaxRet);
if ($syntaxRet !== 0) {
    fwrite(STDERR, "\n⚠ ERROR DE SINTAXIS detectado. Restaurando backup...\n");
    fwrite(STDERR, implode("\n", $syntaxOutput) . "\n");
    copy($backup, $file);
    unlink($backup);
    fwrite(STDERR, "Archivo restaurado desde backup.\n");
    exit(1);
}

echo "✓ Sintaxis PHP verificada: OK\n";

echo "\n=== PARCHE APLICADO EXITOSAMENTE ===\n";
echo "\nAhora cuando se cree una estación nueva (Icecast, SHOUTcast v1 o v2),\n";
echo "se iniciará automáticamente después de generar la configuración.\n";
echo "\nPara probar: crea una estación nueva desde el panel.\n";
