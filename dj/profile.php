<?php
/**
 * CastPanel DJ Portal — Foto de Perfil
 * Upload con drag & drop (500x500 recomendado, jpg/png, 3MB max)
 */
$pageTitle = 'Foto de Perfil';
require_once __DIR__ . '/includes/header.php';

$canUpload = $dj['perm_subir_foto'] ?? true;
?>

<div class="section-card">
    <div class="section-header">
        <svg viewBox="0 0 24 24"><rect x="3" y="3" width="18" height="18" rx="2" ry="2"/><circle cx="8.5" cy="8.5" r="1.5"/><polyline points="21 15 16 10 5 21"/></svg>
        Foto de Perfil
    </div>
    <div class="section-body">
        <?php if (!$canUpload): ?>
        <div class="alert alert-warning">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>
            <div>No tienes permiso para subir foto de perfil. Contacta al administrador.</div>
        </div>
        <?php else: ?>

        <div style="display:flex;gap:32px;align-items:flex-start;flex-wrap:wrap;">
            <!-- Current photo preview -->
            <div style="text-align:center;">
                <div id="currentPhoto" style="width:160px;height:160px;border-radius:12px;overflow:hidden;border:3px solid var(--border);margin-bottom:12px;background:#f3f4f6;display:flex;align-items:center;justify-content:center;">
                    <?php if (!empty($dj['foto']) && file_exists('/var/www/castpanel/dj/uploads/' . $dj['foto'])): ?>
                        <img src="uploads/<?= htmlspecialchars($dj['foto']) ?>" style="width:100%;height:100%;object-fit:cover;" id="photoPreview">
                    <?php else: ?>
                        <div style="font-size:64px;color:#9ca3af;" id="photoPreview"><?= strtoupper(substr($dj['nombre'] ?? 'D', 0, 1)) ?></div>
                    <?php endif; ?>
                </div>
                <p style="font-size:12px;color:var(--text-secondary);">Foto actual</p>
            </div>

            <!-- Upload area -->
            <div style="flex:1;min-width:280px;">
                <div id="dropZone" style="border:2px dashed var(--border);border-radius:12px;padding:40px 24px;text-align:center;cursor:pointer;transition:all .2s;background:#fafafa;"
                     onclick="document.getElementById('fileInput').click()">
                    <svg viewBox="0 0 24 24" width="48" height="48" fill="none" stroke="#9ca3af" stroke-width="1.5" style="margin-bottom:12px;">
                        <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/>
                        <polyline points="17 8 12 3 7 8"/>
                        <line x1="12" y1="3" x2="12" y2="15"/>
                    </svg>
                    <p style="font-size:15px;font-weight:600;color:var(--text-dark);margin-bottom:4px;">
                        Arrastra tu foto aquí o haz clic para seleccionar
                    </p>
                    <p style="font-size:13px;color:var(--text-secondary);">
                        JPG o PNG — Tamaño recomendado: 500 x 500px — Máximo 3MB
                    </p>
                </div>

                <input type="file" id="fileInput" accept="image/jpeg,image/png" style="display:none;" onchange="handleFile(this.files[0])">

                <div id="uploadProgress" style="display:none;margin-top:16px;">
                    <div style="background:var(--border);border-radius:6px;height:8px;overflow:hidden;">
                        <div id="progressBar" style="height:100%;background:var(--primary);width:0%;transition:width .3s;border-radius:6px;"></div>
                    </div>
                    <p id="progressText" style="font-size:12px;color:var(--text-secondary);margin-top:6px;">Subiendo...</p>
                </div>

                <?php if (!empty($dj['foto'])): ?>
                <div style="margin-top:16px;">
                    <button class="btn btn-danger btn-sm" onclick="deletePhoto()">
                        <svg viewBox="0 0 24 24"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/></svg>
                        Eliminar Foto
                    </button>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <?php endif; ?>
    </div>
</div>

<?php if ($canUpload): ?>
<script>
const dropZone = document.getElementById('dropZone');

['dragenter','dragover'].forEach(e => {
    dropZone.addEventListener(e, ev => {
        ev.preventDefault();
        dropZone.style.borderColor = 'var(--primary)';
        dropZone.style.background = '#f0fdf4';
    });
});
['dragleave','drop'].forEach(e => {
    dropZone.addEventListener(e, ev => {
        ev.preventDefault();
        dropZone.style.borderColor = 'var(--border)';
        dropZone.style.background = '#fafafa';
    });
});
dropZone.addEventListener('drop', ev => {
    const file = ev.dataTransfer.files[0];
    if (file) handleFile(file);
});

function handleFile(file) {
    if (!file) return;

    // Validate
    if (!['image/jpeg','image/png'].includes(file.type)) {
        showToast('Solo se permiten archivos JPG o PNG', 'error');
        return;
    }
    if (file.size > 3 * 1024 * 1024) {
        showToast('El archivo no puede pesar más de 3MB', 'error');
        return;
    }

    // Preview
    const reader = new FileReader();
    reader.onload = e => {
        document.getElementById('currentPhoto').innerHTML = '<img src="' + e.target.result + '" style="width:100%;height:100%;object-fit:cover;" id="photoPreview">';
    };
    reader.readAsDataURL(file);

    // Upload
    const formData = new FormData();
    formData.append('foto', file);

    const progress = document.getElementById('uploadProgress');
    const progressBar = document.getElementById('progressBar');
    const progressText = document.getElementById('progressText');
    progress.style.display = 'block';
    progressBar.style.width = '30%';
    progressText.textContent = 'Subiendo...';

    fetch('api/upload_photo.php', {
        method: 'POST',
        body: formData
    })
    .then(r => r.json())
    .then(data => {
        progressBar.style.width = '100%';
        if (data.success) {
            progressText.textContent = 'Foto actualizada correctamente';
            progressBar.style.background = 'var(--success)';
            showToast('Foto de perfil actualizada', 'success');
            // Update topbar photo
            const topbarImg = document.querySelector('.topbar-user img');
            if (topbarImg && data.url) topbarImg.src = data.url;
        } else {
            progressText.textContent = data.message || 'Error al subir';
            progressBar.style.background = 'var(--danger)';
            showToast(data.message || 'Error al subir la foto', 'error');
        }
    })
    .catch(() => {
        progressBar.style.width = '100%';
        progressBar.style.background = 'var(--danger)';
        progressText.textContent = 'Error de conexión';
        showToast('Error de conexión', 'error');
    });
}

function deletePhoto() {
    if (!confirm('¿Eliminar tu foto de perfil?')) return;

    fetch('api/upload_photo.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({ action: 'delete' })
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            showToast('Foto eliminada', 'success');
            setTimeout(() => location.reload(), 1000);
        } else {
            showToast(data.message || 'Error', 'error');
        }
    })
    .catch(() => showToast('Error de conexión', 'error'));
}
</script>
<?php endif; ?>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
