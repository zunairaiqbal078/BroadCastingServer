<?php
/**
 * CastPanel — Admin: Brute Force Protection (like SonicPanel)
 * Bans an IP after too many failed logins, lists/unblocks banned IPs and
 * shows the other request protections.
 */
ob_start();
$pageTitle = 'Protección Brute Force';
$pageIcon = 'shield';
$isAdmin = true;
require_once __DIR__ . '/../includes/layout_header.php';
require_once __DIR__ . '/../includes/security.php';
requireRole('admin');

cpSecEnsureTables();
$db = getDB();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && verifyCsrf()) {
    $action = $_POST['action'] ?? '';
    try {
        if ($action === 'toggle_bf') {
            cpSecSave(['sec_bf_enabled' => cpSecSettings()['sec_bf_enabled'] === '1' ? '0' : '1']);
            setFlash('success', 'Protección Brute Force actualizada.');
        } elseif ($action === 'save_settings') {
            cpSecSave([
                'sec_bf_max_attempts' => max(1, min(100, (int)($_POST['max_attempts'] ?? 5))),
                'sec_bf_window_min'   => max(1, min(1440, (int)($_POST['window_min'] ?? 60))),
                'sec_bf_ban_min'      => max(1, min(43200, (int)($_POST['ban_min'] ?? 60))),
            ]);
            setFlash('success', 'Configuración guardada.');
        } elseif ($action === 'toggle_filter') {
            $key = $_POST['key'] ?? '';
            if (in_array($key, ['sec_block_no_ua', 'sec_block_scanners'], true)) {
                cpSecSave([$key => cpSecSettings()[$key] === '1' ? '0' : '1']);
                setFlash('success', 'Protección actualizada.');
            }
        } elseif ($action === 'unblock') {
            $ip = (string)($_POST['ip'] ?? '');
            $db->prepare('DELETE FROM seguridad_bloqueos WHERE ip = ?')->execute([$ip]);
            $db->prepare('DELETE FROM seguridad_intentos WHERE ip = ?')->execute([$ip]);
            setFlash('success', 'IP desbloqueada.');
        } elseif ($action === 'unblock_all') {
            $db->exec('DELETE FROM seguridad_bloqueos');
            $db->exec('DELETE FROM seguridad_intentos');
            setFlash('success', 'Todas las IPs fueron desbloqueadas.');
        } elseif ($action === 'block') {
            $ip = trim((string)($_POST['ip'] ?? ''));
            $min = max(1, min(525600, (int)($_POST['minutes'] ?? 60)));
            if (!filter_var($ip, FILTER_VALIDATE_IP)) {
                setFlash('error', 'IP no válida.');
            } elseif ($ip === cpSecIp() || cpSecIsLocal($ip)) {
                setFlash('error', 'No puedes bloquear tu propia IP ni la del servidor.');
            } else {
                cpSecBan($ip, 'Bloqueo manual (administrador)', $min);
                setFlash('success', 'IP bloqueada.');
            }
        }
    } catch (PDOException $e) {
        setFlash('error', 'Error: ' . $e->getMessage());
    }
    header('Location: seguridad.php'); exit;
}

$cfg = cpSecSettings();
$bfOn = $cfg['sec_bf_enabled'] === '1';
$st = $db->prepare('SELECT * FROM seguridad_bloqueos WHERE bloqueado_hasta > ? ORDER BY created_at DESC');
$st->execute([cpSecNow()]);
$blocked = $st->fetchAll(PDO::FETCH_ASSOC);
$recent = $db->query('SELECT * FROM seguridad_intentos ORDER BY id DESC LIMIT 30')->fetchAll(PDO::FETCH_ASSOC);
$st = $db->prepare('SELECT COUNT(*) FROM seguridad_intentos WHERE created_at > ?');
$st->execute([cpSecNow(-86400)]);
$fails24 = (int)$st->fetchColumn();

// Database protections (read-only checks)
$dbHasPass = defined('DB_PASS') && DB_PASS !== '' && DB_PASS !== '__DB_PASS__';
$bind = '';
try { $bind = (string)($db->query("SHOW VARIABLES LIKE 'bind_address'")->fetch(PDO::FETCH_NUM)[1] ?? ''); } catch (PDOException $e) {}
$remoteOff = in_array($bind, ['127.0.0.1', 'localhost', '::1'], true);
$skipNet = '';
try { $skipNet = (string)($db->query("SHOW VARIABLES LIKE 'skip_networking'")->fetch(PDO::FETCH_NUM)[1] ?? ''); } catch (PDOException $e) {}
if (strtoupper($skipNet) === 'ON') $remoteOff = true;

$areaLabels = ['panel' => 'Panel', 'dj' => 'Portal DJ', 'api' => 'API'];
?>
<style>
.sec-hero{display:flex;gap:18px;align-items:center;justify-content:space-between;flex-wrap:wrap}
.sec-hero-l{display:flex;gap:16px;align-items:center}
.sec-shield{width:54px;height:54px;border-radius:14px;display:flex;align-items:center;justify-content:center;flex-shrink:0}
.sec-shield.on{background:#ecfdf5;color:#059669}
.sec-shield.off{background:#fef2f2;color:#dc2626}
.sec-hero h2{font-size:18px;font-weight:800;margin:0 0 4px;display:flex;align-items:center;gap:10px}
.sec-hero p{margin:0;color:var(--text-secondary);font-size:13px;max-width:720px;line-height:1.55}
.sec-pill{font-size:11px;font-weight:800;padding:3px 10px;border-radius:999px;letter-spacing:.4px}
.sec-pill.on{background:#dcfce7;color:#166534}
.sec-pill.off{background:#fee2e2;color:#991b1b}
.sec-stats{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:14px;margin:18px 0 0}
.sec-stat{border:1px solid var(--line, var(--border));border-radius:12px;padding:14px 16px;background:var(--muted-bg, #f8fafc)}
.sec-stat b{display:block;font-size:22px;font-weight:800}
.sec-stat span{font-size:12px;color:var(--text-secondary)}
.sec-prot{display:flex;align-items:center;justify-content:space-between;gap:12px;padding:13px 20px;border-bottom:1px solid var(--line-soft, var(--border))}
.sec-prot:last-child{border-bottom:0}
.sec-prot strong{font-size:13.5px;display:block}
.sec-prot small{color:var(--text-secondary);font-size:12px}
.sec-state{font-size:11.5px;font-weight:800;padding:4px 10px;border-radius:8px;white-space:nowrap}
.sec-state.good{background:#ecfdf5;color:#047857}
.sec-state.bad{background:#fef2f2;color:#b91c1c}
.sec-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:12px}
@media (max-width:720px){.sec-stats,.sec-grid{grid-template-columns:1fr}}
</style>

<div class="card" style="margin-bottom:22px">
    <div class="card-body">
        <div class="sec-hero">
            <div class="sec-hero-l">
                <div class="sec-shield <?= $bfOn ? 'on' : 'off' ?>"><?= cpIcon('shield', 28) ?></div>
                <div>
                    <h2>Protección Brute Force <span class="sec-pill <?= $bfOn ? 'on' : 'off' ?>"><?= $bfOn ? 'ACTIVA' : 'DESACTIVADA' ?></span></h2>
                    <p>Protege el inicio de sesión del panel, del portal DJ y de la API contra ataques de fuerza bruta. Una IP que supere <?= (int)$cfg['sec_bf_max_attempts'] ?> intentos fallidos en <?= (int)$cfg['sec_bf_window_min'] ?> minutos queda bloqueada durante <?= (int)$cfg['sec_bf_ban_min'] ?> minutos. Puedes desbloquearla antes desde esta página.</p>
                </div>
            </div>
            <form method="POST">
                <?= csrfField() ?><input type="hidden" name="action" value="toggle_bf">
                <button class="btn <?= $bfOn ? 'btn-outline' : 'btn-success' ?>"><?= cpIcon($bfOn ? 'pause' : 'play', 14) ?> <?= $bfOn ? 'Desactivar' : 'Activar' ?></button>
            </form>
        </div>
        <div class="sec-stats">
            <div class="sec-stat"><b><?= count($blocked) ?></b><span>IPs bloqueadas ahora</span></div>
            <div class="sec-stat"><b><?= $fails24 ?></b><span>Intentos fallidos (24 h)</span></div>
            <div class="sec-stat"><b translate="no"><?= htmlspecialchars(cpSecIp()) ?></b><span>Tu IP (nunca se bloquea manualmente)</span></div>
        </div>
    </div>
</div>

<div class="two-cols-equal">
    <div>
        <div class="card">
            <div class="card-header"><h3><?= cpIcon('gear', 18) ?> Configuración</h3></div>
            <div class="card-body">
                <form method="POST">
                    <?= csrfField() ?><input type="hidden" name="action" value="save_settings">
                    <div class="sec-grid">
                        <div class="form-group">
                            <label>Intentos fallidos</label>
                            <input type="number" name="max_attempts" class="form-control" min="1" max="100" value="<?= (int)$cfg['sec_bf_max_attempts'] ?>">
                        </div>
                        <div class="form-group">
                            <label>En (minutos)</label>
                            <input type="number" name="window_min" class="form-control" min="1" max="1440" value="<?= (int)$cfg['sec_bf_window_min'] ?>">
                        </div>
                        <div class="form-group">
                            <label>Bloqueo (minutos)</label>
                            <input type="number" name="ban_min" class="form-control" min="1" max="43200" value="<?= (int)$cfg['sec_bf_ban_min'] ?>">
                        </div>
                    </div>
                    <button class="btn btn-primary"><?= cpIcon('save', 14) ?> Guardar</button>
                </form>
            </div>
        </div>

        <div class="card">
            <div class="card-header"><h3><?= cpIcon('lock', 18) ?> Otras protecciones de seguridad</h3></div>
            <div class="card-body no-padding">
                <?php
                $prots = [
                    ['sec_block_no_ua', 'Inicio de sesión sin navegador', 'Rechaza logins y llamadas API sin User-Agent (scripts y bots).'],
                    ['sec_block_scanners', 'Escaneo con sqlmap y otros escáneres', 'Bloquea sqlmap, nikto, wpscan, nmap, acunetix y similares.'],
                ];
                foreach ($prots as [$key, $label, $desc]): $on = $cfg[$key] === '1'; ?>
                <div class="sec-prot">
                    <div><strong><?= $label ?></strong><small><?= $desc ?></small></div>
                    <div style="display:flex;gap:8px;align-items:center">
                        <span class="sec-state <?= $on ? 'good' : 'bad' ?>"><?= $on ? 'Bloqueado' : 'Permitido' ?></span>
                        <form method="POST"><?= csrfField() ?><input type="hidden" name="action" value="toggle_filter"><input type="hidden" name="key" value="<?= $key ?>">
                            <button class="btn btn-outline btn-sm"><?= $on ? 'Permitir' : 'Bloquear' ?></button></form>
                    </div>
                </div>
                <?php endforeach; ?>
                <div class="sec-prot">
                    <div><strong>Ataques globales a WordPress</strong><small>wp-login.php, xmlrpc.php y wp-admin responden 403.</small></div>
                    <span class="sec-state good">Bloqueado</span>
                </div>
                <div class="sec-prot">
                    <div><strong>Conexión MySQL sin contraseña</strong><small>El panel se conecta a la base de datos con contraseña.</small></div>
                    <span class="sec-state <?= $dbHasPass ? 'good' : 'bad' ?>"><?= $dbHasPass ? 'Desactivada' : 'Revisar' ?></span>
                </div>
                <div class="sec-prot">
                    <div><strong>Conexión MySQL remota</strong><small>bind-address: <span translate="no"><?= htmlspecialchars($bind ?: '—') ?></span></small></div>
                    <span class="sec-state <?= $remoteOff ? 'good' : 'bad' ?>"><?= $remoteOff ? 'Desactivada' : 'Activa (revisar)' ?></span>
                </div>
            </div>
        </div>
    </div>

    <div>
        <div class="card">
            <div class="card-header">
                <h3><?= cpIcon('x', 18) ?> IPs bloqueadas</h3>
                <?php if ($blocked): ?>
                <form method="POST" onsubmit="return confirm('¿Desbloquear todas las IPs?')"><?= csrfField() ?><input type="hidden" name="action" value="unblock_all">
                    <button class="btn btn-outline btn-sm">Desbloquear todas</button></form>
                <?php endif; ?>
            </div>
            <div class="card-body no-padding">
                <?php if (!$blocked): ?>
                    <div class="empty-state"><p style="margin-bottom:6px"><?= cpIcon('shield', 36) ?></p><p>No hay IPs bloqueadas.</p></div>
                <?php else: ?>
                <div class="table-responsive">
                    <table class="table">
                        <thead><tr><th>IP</th><th>Motivo</th><th>Hasta</th><th></th></tr></thead>
                        <tbody>
                        <?php foreach ($blocked as $b): ?>
                            <tr>
                                <td translate="no"><strong><?= htmlspecialchars($b['ip']) ?></strong></td>
                                <td style="font-size:12.5px"><?= htmlspecialchars((string)$b['motivo']) ?></td>
                                <td style="white-space:nowrap;font-size:12.5px" translate="no"><?= date('d/m H:i', strtotime((string)$b['bloqueado_hasta'])) ?></td>
                                <td><form method="POST"><?= csrfField() ?><input type="hidden" name="action" value="unblock"><input type="hidden" name="ip" value="<?= htmlspecialchars($b['ip']) ?>">
                                    <button class="btn btn-success btn-sm">Desbloquear</button></form></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <div class="card">
            <div class="card-header"><h3><?= cpIcon('plus', 18) ?> Bloquear una IP</h3></div>
            <div class="card-body">
                <form method="POST" style="display:grid;grid-template-columns:minmax(0,1fr) 120px auto;gap:10px;align-items:end">
                    <?= csrfField() ?><input type="hidden" name="action" value="block">
                    <div class="form-group" style="margin:0"><label>IP</label><input type="text" name="ip" class="form-control" placeholder="203.0.113.10" required></div>
                    <div class="form-group" style="margin:0"><label>Minutos</label><input type="number" name="minutes" class="form-control" value="60" min="1"></div>
                    <button class="btn btn-danger">Bloquear</button>
                </form>
            </div>
        </div>

        <div class="card">
            <div class="card-header"><h3><?= cpIcon('clock', 18) ?> Últimos intentos fallidos</h3></div>
            <div class="card-body no-padding">
                <?php if (!$recent): ?>
                    <div class="empty-state"><p>Sin intentos fallidos.</p></div>
                <?php else: ?>
                <div class="table-responsive">
                    <table class="table">
                        <thead><tr><th>Fecha</th><th>IP</th><th>Usuario</th><th>Área</th></tr></thead>
                        <tbody>
                        <?php foreach ($recent as $r): ?>
                            <tr>
                                <td style="white-space:nowrap;font-size:12.5px" translate="no"><?= date('d/m H:i', strtotime((string)$r['created_at'])) ?></td>
                                <td translate="no"><?= htmlspecialchars($r['ip']) ?></td>
                                <td translate="no"><?= htmlspecialchars((string)$r['usuario']) ?: '—' ?></td>
                                <td><span class="badge"><?= $areaLabels[$r['area']] ?? htmlspecialchars($r['area']) ?></span></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/layout_footer.php'; ?>
