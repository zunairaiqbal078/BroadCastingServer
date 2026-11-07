<?php
/**
 * CastPanel v2.0 - Panel Reseller: Dashboard
 */
$pageTitle = 'Panel Reseller';
$pageIcon = 'chart';
$isReseller = true;
require_once __DIR__ . '/../includes/layout_header.php';
requireRole('reseller');

$db = getDB();
$resellerId = $_SESSION['user_id'];

// Stats del reseller
$stmt = $db->prepare("SELECT COUNT(*) FROM usuarios WHERE reseller_id = ? AND rol = 'cliente'");
$stmt->execute([$resellerId]);
$totalClientes = (int)$stmt->fetchColumn();

$stmt = $db->prepare("SELECT COUNT(*) FROM usuarios WHERE reseller_id = ? AND rol = 'cliente' AND activo = 1");
$stmt->execute([$resellerId]);
$clientesActivos = (int)$stmt->fetchColumn();

// Estaciones de mis clientes
$stmt = $db->prepare("
    SELECT COUNT(*) FROM estaciones e
    JOIN usuarios u ON u.id = e.usuario_id
    WHERE u.reseller_id = ?
");
$stmt->execute([$resellerId]);
$totalEstaciones = (int)$stmt->fetchColumn();

// Online = stream server has a live source (live DJ or AutoDJ), not only AutoDJ
$stmt = $db->prepare("
    SELECT e.* FROM estaciones e
    JOIN usuarios u ON u.id = e.usuario_id
    WHERE u.reseller_id = ? AND e.activa = 1
");
$stmt->execute([$resellerId]);
$estacionesOnline = countOnlineStations($stmt->fetchAll());

// Oyentes totales de mis estaciones
$stmt = $db->prepare("
    SELECT e.* FROM estaciones e
    JOIN usuarios u ON u.id = e.usuario_id
    WHERE u.reseller_id = ?
");
$stmt->execute([$resellerId]);
$misEstaciones = $stmt->fetchAll();

$oyentesTotales = 0;
$allListeners = getIcecastListeners();
foreach ($misEstaciones as $e) {
    if ($e['server_type'] === 'icecast') {
        $oyentesTotales += $allListeners[$e['icecast_mount']]['oyentes'] ?? 0;
    } else {
        $sc = getStreamStatusCached($e);
        $oyentesTotales += $sc['oyentes'];
    }
}

// Almacenamiento total
$stmt = $db->prepare("
    SELECT COALESCE(SUM(c.tamano_bytes), 0) FROM canciones c
    JOIN estaciones e ON e.id = c.estacion_id
    JOIN usuarios u ON u.id = e.usuario_id
    WHERE u.reseller_id = ?
");
$stmt->execute([$resellerId]);
$totalStorage = (int)$stmt->fetchColumn();

$stmt = $db->prepare("
    SELECT COALESCE(SUM(a.tamano_bytes), 0) FROM anuncios a
    JOIN estaciones e ON e.id = a.estacion_id
    JOIN usuarios u ON u.id = e.usuario_id
    WHERE u.reseller_id = ?
");
$stmt->execute([$resellerId]);
$totalStorage += (int)$stmt->fetchColumn();

// Límites del reseller
$stmt = $db->prepare("SELECT max_estaciones, max_clientes FROM usuarios WHERE id = ?");
$stmt->execute([$resellerId]);
$limites = $stmt->fetch();
$maxEstaciones = (int)($limites['max_estaciones'] ?? 0);
$maxClientes = (int)($limites['max_clientes'] ?? 0);

// Clientes recientes
$stmt = $db->prepare("
    SELECT u.*, (SELECT COUNT(*) FROM estaciones WHERE usuario_id = u.id) AS total_estaciones
    FROM usuarios u
    WHERE u.reseller_id = ? AND u.rol = 'cliente'
    ORDER BY u.created_at DESC LIMIT 10
");
$stmt->execute([$resellerId]);
$clientesRecientes = $stmt->fetchAll();

// Estaciones recientes
$stmt = $db->prepare("
    SELECT e.*, u.username, u.nombre AS user_nombre
    FROM estaciones e
    JOIN usuarios u ON u.id = e.usuario_id
    WHERE u.reseller_id = ?
    ORDER BY e.created_at DESC LIMIT 10
");
$stmt->execute([$resellerId]);
$estacionesRecientes = $stmt->fetchAll();
?>

<!-- Stats Cards -->
<div class="stats-grid">
    <div class="stat-card green">
        <div class="stat-label">Oyentes Totales</div>
        <div class="stat-value"><?= $oyentesTotales ?></div>
        <div class="stat-sub">En tus estaciones</div>
        <span class="stat-badge"><?= cpIcon('headphones',12) ?> LIVE</span>
    </div>
    <div class="stat-card blue">
        <div class="stat-label">Estaciones</div>
        <div class="stat-value"><?= $totalEstaciones ?><?= $maxEstaciones ? "/{$maxEstaciones}" : '' ?></div>
        <div class="stat-sub"><?= $estacionesOnline ?> online ahora</div>
        <span class="stat-badge"><?= cpIcon('radio',12) ?> RADIO</span>
    </div>
    <div class="stat-card purple">
        <div class="stat-label">Clientes</div>
        <div class="stat-value"><?= $totalClientes ?><?= $maxClientes ? "/{$maxClientes}" : '' ?></div>
        <div class="stat-sub"><?= $clientesActivos ?> activos</div>
        <span class="stat-badge"><?= cpIcon('users',12) ?> USERS</span>
    </div>
    <div class="stat-card red">
        <div class="stat-label">Almacenamiento</div>
        <div class="stat-value"><?= formatBytes($totalStorage) ?></div>
        <div class="stat-sub">Usado por tus clientes</div>
        <span class="stat-badge"><?= cpIcon('save',12) ?> DISCO</span>
    </div>
</div>

<!-- Quick Actions -->
<div class="quick-actions">
    <a href="<?= BASE_URL ?>/reseller/clientes.php" class="quick-action">
        <span class="qa-icon"><?= cpIcon('users',16) ?></span> Gestionar Clientes <?= cpIcon('external',12) ?>
    </a>
    <a href="<?= BASE_URL ?>/reseller/estaciones.php" class="quick-action">
        <span class="qa-icon"><?= cpIcon('radio',16) ?></span> Gestionar Estaciones <?= cpIcon('external',12) ?>
    </a>
    <a href="<?= BASE_URL ?>/reseller/clientes.php?nuevo=1" class="quick-action">
        <span class="qa-icon"><?= cpIcon('plus',16) ?></span> Nuevo Cliente <?= cpIcon('external',12) ?>
    </a>
</div>

<div class="two-cols-equal">
    <!-- Clientes recientes -->
    <div class="card">
        <div class="card-header">
            <h3><?= cpIcon('users',18) ?> Clientes Recientes</h3>
            <a href="<?= BASE_URL ?>/reseller/clientes.php" class="btn btn-outline btn-sm">Ver todos</a>
        </div>
        <div class="card-body no-padding">
            <div class="table-responsive">
                <table class="table">
                    <thead><tr><th>Usuario</th><th>Estaciones</th><th>Estado</th><th>Registro</th></tr></thead>
                    <tbody>
                        <?php if (empty($clientesRecientes)): ?>
                            <tr><td colspan="4" style="text-align:center;color:var(--text-secondary);padding:30px">
                                A&uacute;n no tienes clientes. <a href="<?= BASE_URL ?>/reseller/clientes.php?nuevo=1">Crear uno</a>
                            </td></tr>
                        <?php else: ?>
                            <?php foreach ($clientesRecientes as $c): ?>
                                <tr>
                                    <td>
                                        <strong><?= htmlspecialchars($c['username']) ?></strong>
                                        <?php if ($c['nombre']): ?>
                                            <div style="font-size:11px;color:var(--text-secondary)"><?= htmlspecialchars($c['nombre']) ?></div>
                                        <?php endif; ?>
                                    </td>
                                    <td><?= $c['total_estaciones'] ?></td>
                                    <td>
                                        <?= $c['activo']
                                            ? '<span class="badge badge-success">Activo</span>'
                                            : '<span class="badge badge-danger">Inactivo</span>' ?>
                                    </td>
                                    <td style="font-size:12px"><?= date('d/m/Y', strtotime($c['created_at'])) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- Estaciones recientes -->
    <div class="card">
        <div class="card-header">
            <h3><?= cpIcon('radio',18) ?> Estaciones Recientes</h3>
            <a href="<?= BASE_URL ?>/reseller/estaciones.php" class="btn btn-outline btn-sm">Ver todas</a>
        </div>
        <div class="card-body no-padding">
            <div class="table-responsive">
                <table class="table">
                    <thead><tr><th>Estaci&oacute;n</th><th>Cliente</th><th>Estado</th><th>Oyentes</th></tr></thead>
                    <tbody>
                        <?php if (empty($estacionesRecientes)): ?>
                            <tr><td colspan="4" style="text-align:center;color:var(--text-secondary);padding:30px">
                                Sin estaciones a&uacute;n
                            </td></tr>
                        <?php else: ?>
                            <?php foreach ($estacionesRecientes as $e): ?>
                                <tr>
                                    <td>
                                        <strong><?= htmlspecialchars($e['nombre']) ?></strong>
                                        <div style="font-size:11px;color:var(--text-secondary)"><?= htmlspecialchars($e['slug']) ?></div>
                                    </td>
                                    <td><?= htmlspecialchars($e['username']) ?></td>
                                    <td>
                                        <?= isStationOnline($e)
                                            ? '<span class="badge badge-success">Online</span>'
                                            : '<span class="badge">Offline</span>' ?>
                                    </td>
                                    <td><?php
                                        if ($e['server_type'] === 'icecast') {
                                            echo $allListeners[$e['icecast_mount']]['oyentes'] ?? 0;
                                        } else {
                                            $sc = getStreamStatusCached($e);
                                            echo $sc['oyentes'];
                                        }
                                    ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/layout_footer.php'; ?>
