
</div><!-- .dj-content -->
</main>

<script>
function toggleSidebar() {
    document.getElementById('sidebar').classList.toggle('open');
    document.getElementById('sidebarOverlay').classList.toggle('show');
}

// Tabs
document.querySelectorAll('[data-tab-group]').forEach(group => {
    const btns = group.querySelectorAll('.tab-btn');
    btns.forEach(btn => {
        btn.addEventListener('click', () => {
            const target = btn.dataset.tab;
            const container = group.closest('.section-card') || group.parentElement;
            container.querySelectorAll('.tab-btn').forEach(b => b.classList.remove('active'));
            container.querySelectorAll('.tab-panel').forEach(p => p.classList.remove('active'));
            btn.classList.add('active');
            const panel = container.querySelector('#' + target);
            if (panel) panel.classList.add('active');
        });
    });
});

// Modals
function openModal(id) { document.getElementById(id).classList.add('show'); }
function closeModal(id) { document.getElementById(id).classList.remove('show'); }
document.querySelectorAll('.modal-overlay').forEach(m => {
    m.addEventListener('click', e => { if (e.target === m) m.classList.remove('show'); });
});

// Toast notifications
function showToast(msg, type) {
    const t = document.createElement('div');
    t.className = 'toast-msg toast-' + (type || 'info');
    t.textContent = msg;
    t.style.cssText = 'position:fixed;bottom:24px;right:24px;padding:12px 20px;border-radius:8px;font-size:14px;font-weight:600;z-index:300;animation:fadeIn .3s;color:#fff;background:' + (type==='success'?'#10b981':type==='error'?'#ef4444':'#3b82f6');
    document.body.appendChild(t);
    setTimeout(() => { t.style.opacity = '0'; t.style.transition = 'opacity .3s'; setTimeout(() => t.remove(), 300); }, 3000);
}

// Close sidebar on mobile when clicking a link
if (window.innerWidth <= 768) {
    document.querySelectorAll('.sidebar-menu a').forEach(a => {
        a.addEventListener('click', () => {
            document.getElementById('sidebar').classList.remove('open');
            document.getElementById('sidebarOverlay').classList.remove('show');
        });
    });
}
</script>
</body>
</html>
