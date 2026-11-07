<?php
/**
 * CastPanel v2.4 - Admin: Oyentes de Radio (Google Maps)
 * Mapa de oyentes conectados con geolocalización IP
 */
$pageTitle = 'Oyentes en Mapa';
$pageIcon = 'globe';
$isAdmin = true;
require_once __DIR__ . '/../includes/layout_header.php';
requireRole('admin');

$db = getDB();

// Get Google Maps API key from config
$gmapsKey = '';
try {
    $row = $db->query("SELECT valor FROM configuracion WHERE clave = 'google_maps_key'")->fetch();
    if ($row) $gmapsKey = $row['valor'];
} catch (PDOException $e) {}

// POST: save API key
$msg = '';
$msgType = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && verifyCsrf()) {
    $action = $_POST['action'] ?? '';
    if ($action === 'save_key') {
        $newKey = trim($_POST['google_maps_key'] ?? '');
        try {
            $stmtCheck = $db->query("SELECT 1 FROM configuracion WHERE clave = 'google_maps_key'");
            if ($stmtCheck->fetch()) {
                $db->prepare("UPDATE configuracion SET valor = ? WHERE clave = 'google_maps_key'")->execute([$newKey]);
            } else {
                $db->prepare("INSERT INTO configuracion (clave, valor) VALUES ('google_maps_key', ?)")->execute([$newKey]);
            }
            $gmapsKey = $newKey;
            $msg = 'API Key de Google Maps guardada';
            $msgType = 'success';
        } catch (PDOException $e) {
            $msg = 'Error: ' . $e->getMessage();
            $msgType = 'error';
        }
    }
}

// Collect listener IPs from all active stations
$listeners = [];
try {
    $stations = $db->query("
        SELECT e.id, e.nombre, e.slug, e.server_type, e.puerto_stream
        FROM estaciones e WHERE e.activa = 1
    ")->fetchAll();

    foreach ($stations as $st) {
        $status = getStreamStatus($st);
        if (($status['status'] ?? '') !== 'online') continue;

        // Try to get listener list from Icecast/SHOUTcast admin
        if (function_exists('isShoutcastType') && isShoutcastType($st['server_type'])) {
            // SHOUTcast: try admin API
            $adminUrl = "http://127.0.0.1:{$st['puerto_stream']}/admin.cgi?sid=1&mode=viewjson&page=6";
            $ctx = stream_context_create(['http' => [
                'header' => "Authorization: Basic " . base64_encode("admin:CastPanel_Admin_2024!"),
                'timeout' => 3,
            ]]);
            $json = @file_get_contents($adminUrl, false, $ctx);
            if ($json) {
                $data = @json_decode($json, true);
                $listenerList = $data['listeners'] ?? [];
                foreach ($listenerList as $l) {
                    $listeners[] = [
                        'ip' => $l['hostname'] ?? $l['addr'] ?? '',
                        'station' => $st['nombre'],
                        'useragent' => $l['useragent'] ?? '',
                    ];
                }
            }
        } else {
            // Icecast: try admin stats
            $adminUrl = "http://127.0.0.1:8000/admin/listclients?mount=/live/{$st['slug']}";
            $ctx = stream_context_create(['http' => [
                'header' => "Authorization: Basic " . base64_encode("admin:CastPanel_Admin_2024!"),
                'timeout' => 3,
            ]]);
            $xml = @file_get_contents($adminUrl, false, $ctx);
            if ($xml) {
                libxml_use_internal_errors(true);
                $doc = @simplexml_load_string($xml);
                if ($doc && isset($doc->source->listener)) {
                    foreach ($doc->source->listener as $l) {
                        $listeners[] = [
                            'ip' => (string)($l->IP ?? ''),
                            'station' => $st['nombre'],
                            'useragent' => (string)($l->UserAgent ?? ''),
                        ];
                    }
                }
            }
        }
    }
} catch (PDOException $e) {}

// Geolocate IPs using ip-api.com (free, no key needed, batch of 100)
$geoListeners = [];
$uniqueIps = array_unique(array_column($listeners, 'ip'));
$ipGeo = [];

// Use local geoip lookup or basic batch
foreach ($uniqueIps as $ip) {
    if (empty($ip) || $ip === '127.0.0.1' || strpos($ip, '192.168.') === 0 || strpos($ip, '10.') === 0) continue;
    // Try ip-api.com (rate limited but works for small amounts)
    $geoJson = @file_get_contents("http://ip-api.com/json/" . urlencode($ip) . "?fields=status,lat,lon,city,country", false,
        stream_context_create(['http' => ['timeout' => 2]]));
    if ($geoJson) {
        $geo = @json_decode($geoJson, true);
        if ($geo && ($geo['status'] ?? '') === 'success') {
            $ipGeo[$ip] = $geo;
        }
    }
    usleep(150000); // Rate limit: ~6/sec
}

foreach ($listeners as $l) {
    $ip = $l['ip'];
    if (isset($ipGeo[$ip])) {
        $geoListeners[] = [
            'ip' => $ip,
            'station' => $l['station'],
            'lat' => $ipGeo[$ip]['lat'],
            'lng' => $ipGeo[$ip]['lon'],
            'city' => $ipGeo[$ip]['city'] ?? '',
            'country' => $ipGeo[$ip]['country'] ?? '',
        ];
    }
}
?>

<?php if ($msg): ?>
<div class="alert alert-<?= $msgType === 'error' ? 'danger' : 'success' ?>">
    <?= htmlspecialchars($msg) ?>
</div>
<?php endif; ?>

<!-- API Key Config -->
<div class="card" style="margin-bottom:1.5rem;">
    <div class="card-header">
        <h3><?= cpIcon('key', 18) ?> Configuración Google Maps</h3>
    </div>
    <div class="card-body">
        <form method="post">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="save_key">
            <div class="form-grid-2">
                <div class="form-group">
                    <label>Google Maps API Key</label>
                    <input type="text" name="google_maps_key" class="form-control"
                           value="<?= htmlspecialchars($gmapsKey) ?>"
                           placeholder="AIza...">
                </div>
                <div class="form-group" style="display:flex;align-items:flex-end;">
                    <button type="submit" class="btn btn-primary"><?= cpIcon('save', 14) ?> Guardar Key</button>
                </div>
            </div>
            <small style="color:#666;">Necesita una API Key de Google Maps con Maps JavaScript API habilitado. <a href="https://console.cloud.google.com/apis" target="_blank">Obtener Key</a></small>
        </form>
    </div>
</div>

<!-- Stats -->
<div class="stats-grid" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:1rem;margin-bottom:1.5rem;">
    <div class="card" style="text-align:center;padding:1.2rem;">
        <div style="font-size:1.8rem;font-weight:700;color:var(--primary);"><?= count($listeners) ?></div>
        <div style="color:#666;font-size:.85rem;">Oyentes Conectados</div>
    </div>
    <div class="card" style="text-align:center;padding:1.2rem;">
        <div style="font-size:1.8rem;font-weight:700;color:#28a745;"><?= count($geoListeners) ?></div>
        <div style="color:#666;font-size:.85rem;">Geolocalizados</div>
    </div>
    <div class="card" style="text-align:center;padding:1.2rem;">
        <div style="font-size:1.8rem;font-weight:700;color:#fd7e14;"><?= count($uniqueIps) ?></div>
        <div style="color:#666;font-size:.85rem;">IPs Únicas</div>
    </div>
</div>

<!-- Map -->
<div class="card" style="margin-bottom:1.5rem;">
    <div class="card-header">
        <h3><?= cpIcon('globe', 18) ?> Mapa de Oyentes</h3>
    </div>
    <div class="card-body">
        <?php if ($gmapsKey && !empty($geoListeners)): ?>
        <div id="listenersMap" style="width:100%;height:500px;border-radius:8px;"></div>

        <script src="https://maps.googleapis.com/maps/api/js?key=<?= htmlspecialchars($gmapsKey) ?>&callback=initMap" async defer></script>
        <script>
        function initMap() {
            const map = new google.maps.Map(document.getElementById('listenersMap'), {
                zoom: 3,
                center: {lat: 0, lng: -40},
                mapTypeId: 'roadmap',
            });

            const listeners = <?= json_encode($geoListeners) ?>;
            listeners.forEach(function(l) {
                new google.maps.Marker({
                    position: {lat: l.lat, lng: l.lng},
                    map: map,
                    title: l.station + ' - ' + l.city + ', ' + l.country + ' (' + l.ip + ')',
                });
            });

            // Fit bounds
            if (listeners.length > 0) {
                const bounds = new google.maps.LatLngBounds();
                listeners.forEach(l => bounds.extend({lat: l.lat, lng: l.lng}));
                map.fitBounds(bounds);
            }
        }
        </script>
        <?php elseif (!$gmapsKey): ?>
            <div style="text-align:center;padding:3rem;color:#999;">
                <p style="font-size:1.2rem;"><?= cpIcon('globe', 32) ?></p>
                <p>Configure una API Key de Google Maps arriba para ver el mapa de oyentes.</p>
            </div>
        <?php else: ?>
            <div style="text-align:center;padding:3rem;color:#999;">
                <p>No hay oyentes geolocalizados en este momento.</p>
            </div>
        <?php endif; ?>
    </div>
</div>

<!-- Listener Table -->
<?php if (!empty($geoListeners)): ?>
<div class="card">
    <div class="card-header">
        <h3><?= cpIcon('user', 18) ?> Lista de Oyentes Geolocalizados</h3>
    </div>
    <div class="card-body" style="padding:0;">
        <table class="table" style="margin:0;">
            <thead>
                <tr><th>IP</th><th>Estación</th><th>Ciudad</th><th>País</th></tr>
            </thead>
            <tbody>
                <?php foreach ($geoListeners as $gl): ?>
                <tr>
                    <td><code><?= htmlspecialchars($gl['ip']) ?></code></td>
                    <td><?= htmlspecialchars($gl['station']) ?></td>
                    <td><?= htmlspecialchars($gl['city']) ?></td>
                    <td><?= htmlspecialchars($gl['country']) ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>

<?php require_once __DIR__ . '/../includes/layout_footer.php'; ?>
