<?php
/**
 * Regenerate SHOUTcast proxy + Liquidsoap configs (after DJ auth / player fixes).
 * sudo -u www-data php /var/www/castpanel/dev/vps_regenerate_streaming.php
 */
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/provisioning.php';

$db = getDB();
$db->exec("INSERT INTO configuracion (clave, valor) VALUES ('site_url', 'http://148.113.240.183:8080')
    ON DUPLICATE KEY UPDATE valor = VALUES(valor)");

updateShoutcastProxyConfig();

$rows = $db->query(
    "SELECT e.id FROM estaciones e
     JOIN paquetes p ON p.id = e.paquete_id
     WHERE e.activa = 1 AND p.autodj_enabled = 1"
)->fetchAll(PDO::FETCH_COLUMN);

foreach ($rows as $id) {
    $est = loadStationWithPackage((int)$id);
    if (!$est) {
        continue;
    }
    echo "Liquidsoap config: {$est['slug']}\n";
    generateLiquidsoapConfig($est);
    restartStation($est);
}
echo "Done.\n";
