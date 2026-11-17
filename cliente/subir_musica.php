<?php
/**
 * CastPanel v2.0 - Subir Música
 */
$pageTitle = 'Subir Música';
$pageIcon = 'upload';
require_once __DIR__ . '/../includes/layout_header.php';

if (!$estacion) { echo '<div class="alert alert-warning">Sin estación asignada.</div>'; require_once __DIR__ . '/../includes/layout_footer.php'; exit; }

$db = getDB();
$playlists = [];
$_tableError = false;

try {
    $stmt = $db->prepare("SELECT * FROM playlists WHERE estacion_id = ? AND activa = 1 ORDER BY nombre");
    $stmt->execute([$estacion['id']]);
    $playlists = $stmt->fetchAll();
} catch (PDOException $e) {
    $_tableError = true;
}

$storageUsedBytes = getStorageUsed($estacion['id']);
$storageUsedMB = round($storageUsedBytes / (1024 * 1024), 1);
$maxStorageMB = (int)($estacion['max_storage_mb'] ?? 0); // plan storage, 0 = unlimited
$storagePercent = $maxStorageMB > 0 ? min(100, round(($storageUsedMB / $maxStorageMB) * 100, 1)) : 0;
?>

<?php if ($_tableError): ?>
<div class="alert alert-warning"><?= cpIcon('warning',14) ?> La tabla de playlists no est&aacute; disponible. Ejecute la migraci&oacute;n SQL: <code>mysql -u root -p castpanel &lt; /var/www/castpanel/install/schema_update_v2.3.sql</code></div>
<?php endif; ?>

<!-- Almacenamiento -->
<div class="card" style="margin-bottom:20px">
    <div class="card-body">
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:8px">
            <span style="font-weight:600">Almacenamiento AutoDJ</span>
            <span style="font-size:13px;color:var(--text-secondary)"><?= $maxStorageMB > 0 ? formatMB($storageUsedMB) . ' / ' . formatMB($maxStorageMB) : formatMB($storageUsedMB) . ' — Ilimitado' ?></span>
        </div>
        <?php if ($maxStorageMB > 0): ?>
        <div class="storage-bar">
            <div class="storage-bar-fill <?= $storagePercent > 90 ? 'danger' : ($storagePercent > 70 ? 'warning' : '') ?>" style="width:<?= $storagePercent ?>%"></div>
        </div>
        <?php endif; ?>
    </div>
</div>

<!-- Zona de Upload -->
<div class="card">
    <div class="card-header">
        <h3><?= cpIcon('upload',18) ?> Subir Archivos de Audio</h3>
    </div>
    <div class="card-body">
        <form id="uploadForm">
            <?= csrfField() ?>
            <div class="form-group">
                <label>Lista de Reproducción Destino</label>
                <select name="playlist_id" id="uploadPlaylist" class="form-control">
                    <?php if (empty($playlists)): ?>
                        <option value="">-- Sin lista --</option>
                    <?php else: ?>
                        <?php
                        $activePlId = (int)($estacion['autodj_playlist_activa'] ?? 0);
                        // Folder chosen with "Upload" from a folder (?playlist=ID) wins;
                        // otherwise the on-air folder, otherwise "General".
                        $wantedPlId = (int)($_GET['playlist'] ?? 0);
                        if ($wantedPlId && !in_array($wantedPlId, array_map('intval', array_column($playlists, 'id')), true)) {
                            $wantedPlId = 0;
                        }
                        $selPlId = $wantedPlId ?: $activePlId;
                        foreach ($playlists as $pl):
                            $sel = ($selPlId > 0 && (int)$pl['id'] === $selPlId) || ($selPlId === 0 && ($pl['slug'] ?? '') === 'general');
                        ?>
                            <option value="<?= $pl['id'] ?>" <?= $sel ? 'selected' : '' ?>><?= htmlspecialchars($pl['nombre']) ?><?= (int)$pl['id'] === $activePlId ? ' (AutoDJ active)' : '' ?></option>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </select>
            </div>

            <div class="upload-area" id="dropZone">
                <div class="upload-icon"><?= cpIcon('upload',24) ?></div>
                <div class="upload-title">Arrastra tus archivos aquí</div>
                <div class="upload-hint">o haz clic para seleccionar</div>
                <div class="upload-formats">Formatos: MP3, OGG, AAC, M4A, WAV, FLAC — sin límite de cantidad de archivos</div>
                <div class="upload-formats"><?= $maxStorageMB > 0 ? 'Espacio usado: ' . formatMB($storageUsedMB) . ' de ' . formatMB($maxStorageMB) : 'Espacio usado: ' . formatMB($storageUsedMB) . ' (ilimitado)' ?></div>
                <input type="file" id="fileInput" accept=".mp3,.ogg,.aac,.m4a,.wav,.flac" multiple style="display:none">
            </div>
        </form>

        <!-- Cola de archivos -->
        <div id="uploadQueue" style="display:none;margin-top:20px">
            <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:12px">
                <h4 style="margin:0">Cola de archivos</h4>
                <button class="btn btn-primary btn-sm" id="btnStartUpload"><?= cpIcon('upload',14) ?> Subir Todos</button>
            </div>
            <div id="fileList"></div>
        </div>

        <!-- Resultados -->
        <div id="uploadResults" style="display:none;margin-top:20px">
            <h4>Resultados</h4>
            <div id="resultList"></div>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', () => {
    const dropZone = document.getElementById('dropZone');
    const fileInput = document.getElementById('fileInput');
    const queueDiv = document.getElementById('uploadQueue');
    const fileList = document.getElementById('fileList');
    const btnStart = document.getElementById('btnStartUpload');
    let selectedFiles = [];

    initDropZone(dropZone, fileInput, (files) => {
        selectedFiles = Array.from(files).filter(f => {
            const ext = f.name.split('.').pop().toLowerCase();
            return ['mp3','ogg','aac','m4a','wav','flac'].includes(ext);
        });
        if (selectedFiles.length === 0) {
            showToast('No se encontraron archivos de audio válidos', 'error');
            return;
        }
        queueDiv.style.display = '';
        fileList.innerHTML = '';
        selectedFiles.forEach((f, i) => {
            const size = (f.size / (1024*1024)).toFixed(1);
            fileList.innerHTML += `
                <div class="song-item" id="file-${i}">
                    <span class="song-icon">&#x1F3B5;</span>
                    <div class="song-info">
                        <div class="song-title">${f.name.replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]))}</div>
                        <div class="song-meta">${size} MB</div>
                    </div>
                    <span class="badge" id="status-${i}">Pendiente</span>
                </div>`;
        });
    });

    btnStart.addEventListener('click', () => {
        const playlist = document.getElementById('uploadPlaylist').value;
        btnStart.disabled = true;
        btnStart.textContent = 'Subiendo...';

        uploadFiles(
            '<?= BASE_URL ?>/api/upload.php?estacion=<?= $estacion['id'] ?>',
            selectedFiles,
            'playlist_id', playlist,
            (pct) => { btnStart.textContent = `Subiendo... ${pct}%`; },
            (resp) => {
                btnStart.disabled = false;
                btnStart.textContent = '⬆ Subir Todos';
                const failed = resp.total - resp.uploaded;
                if (resp.uploaded > 0) {
                    showToast(`${resp.uploaded} archivo(s) subido(s) correctamente` + (failed ? ` — ${failed} con error` : ''), failed ? 'warning' : 'success');
                } else {
                    showToast(resp.error || 'Error al subir', 'error');
                }
            },
            (i, r) => { // each file as soon as it is done
                const el = document.getElementById(`status-${i}`);
                if (el) {
                    el.className = r.ok ? 'badge badge-success' : 'badge badge-danger';
                    el.textContent = r.ok ? 'Subido ✓' : (r.error || 'Error');
                }
            }
        );
    });
});
</script>

<?php require_once __DIR__ . '/../includes/layout_footer.php'; ?>
