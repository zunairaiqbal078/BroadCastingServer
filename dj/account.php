<?php
/**
 * CastPanel DJ Portal — Administrar Cuenta
 * Cambiar contraseña + redes sociales
 */
$pageTitle = 'Administrar Cuenta';
require_once __DIR__ . '/includes/header.php';
?>

<div class="section-card">
    <div class="section-header">
        <svg viewBox="0 0 24 24"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
        Administrar Cuenta
    </div>
    <div class="tabs" data-tab-group="account">
        <button class="tab-btn active" data-tab="tab-password">
            <svg viewBox="0 0 24 24"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
            Cambiar Contraseña
        </button>
        <button class="tab-btn" data-tab="tab-social-edit">
            <svg viewBox="0 0 24 24"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><polyline points="22,6 12,13 2,6"/></svg>
            Redes Sociales
        </button>
    </div>

    <!-- Tab: Cambiar Contraseña -->
    <div class="tab-panel active" id="tab-password">
        <?php if (!($dj['perm_cambiar_pass'] ?? true)): ?>
        <div class="alert alert-warning">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>
            <div>No tienes permiso para cambiar tu contraseña. Contacta al administrador.</div>
        </div>
        <?php else: ?>
        <form id="passwordForm" onsubmit="changePassword(event)">
            <div class="form-group">
                <label>Contraseña Actual:</label>
                <input type="password" name="current_password" class="form-control" required autocomplete="current-password">
            </div>
            <div class="form-group">
                <label>Nueva Contraseña:</label>
                <input type="password" name="new_password" class="form-control" required minlength="6" autocomplete="new-password">
            </div>
            <div class="form-group">
                <label>Confirmar Nueva Contraseña:</label>
                <input type="password" name="confirm_password" class="form-control" required minlength="6" autocomplete="new-password">
            </div>
            <button type="submit" class="btn btn-primary">
                <svg viewBox="0 0 24 24"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
                Cambiar Contraseña
            </button>
        </form>
        <?php endif; ?>
    </div>

    <!-- Tab: Redes Sociales -->
    <div class="tab-panel" id="tab-social-edit">
        <form id="socialEditForm" onsubmit="saveSocialInfo(event)">
            <div class="form-group">
                <label>Email:</label>
                <input type="email" name="email" class="form-control" value="<?= htmlspecialchars($dj['email'] ?? '') ?>" placeholder="tu@email.com">
            </div>
            <div class="form-group">
                <label>Instagram:</label>
                <div class="input-with-icon">
                    <svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="2" y="2" width="20" height="20" rx="5" ry="5"/><path d="M16 11.37A4 4 0 1 1 12.63 8 4 4 0 0 1 16 11.37z"/><line x1="17.5" y1="6.5" x2="17.51" y2="6.5"/></svg>
                    <input type="text" name="instagram" class="form-control" value="<?= htmlspecialchars($dj['instagram'] ?? '') ?>" placeholder="@tu_usuario">
                </div>
            </div>
            <div class="form-group">
                <label>Facebook:</label>
                <div class="input-with-icon">
                    <svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M18 2h-3a5 5 0 0 0-5 5v3H7v4h3v8h4v-8h3l1-4h-4V7a1 1 0 0 1 1-1h3z"/></svg>
                    <input type="text" name="facebook" class="form-control" value="<?= htmlspecialchars($dj['facebook'] ?? '') ?>" placeholder="https://facebook.com/tu_pagina">
                </div>
            </div>
            <button type="submit" class="btn btn-primary">
                <svg viewBox="0 0 24 24"><path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"/><polyline points="17 21 17 13 7 13 7 21"/></svg>
                Guardar Cambios
            </button>
        </form>
    </div>
</div>

<script>
function changePassword(e) {
    e.preventDefault();
    const form = e.target;
    const newPass = form.new_password.value;
    const confirmPass = form.confirm_password.value;

    if (newPass !== confirmPass) {
        showToast('Las contraseñas no coinciden', 'error');
        return;
    }
    if (newPass.length < 6) {
        showToast('La contraseña debe tener al menos 6 caracteres', 'error');
        return;
    }

    const btn = form.querySelector('button[type="submit"]');
    btn.disabled = true;
    btn.style.opacity = '.6';

    fetch('api/update.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({
            action: 'change_password',
            current_password: form.current_password.value,
            new_password: newPass
        })
    })
    .then(r => r.json())
    .then(data => {
        showToast(data.message || 'OK', data.success ? 'success' : 'error');
        if (data.success) form.reset();
    })
    .catch(() => showToast('Error de conexión', 'error'))
    .finally(() => { btn.disabled = false; btn.style.opacity = '1'; });
}

function saveSocialInfo(e) {
    e.preventDefault();
    const form = new FormData(e.target);
    const btn = e.target.querySelector('button[type="submit"]');
    btn.disabled = true;
    btn.style.opacity = '.6';

    fetch('api/update.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({
            action: 'social',
            email: form.get('email'),
            instagram: form.get('instagram'),
            facebook: form.get('facebook')
        })
    })
    .then(r => r.json())
    .then(data => showToast(data.message || 'Guardado', data.success ? 'success' : 'error'))
    .catch(() => showToast('Error de conexión', 'error'))
    .finally(() => { btn.disabled = false; btn.style.opacity = '1'; });
}
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
