/**
 * CastPanel v2.0 - JavaScript principal
 */

// Toggle sidebar mobile
function toggleSidebar() {
    document.getElementById('sidebar').classList.toggle('open');
    document.getElementById('sidebarOverlay').classList.toggle('show');
}

// Copy — works on HTTP (no secure context) via textarea fallback
function castpanelCopyText(text, btn) {
    function markCopied() {
        if (!btn) return;
        const origHtml = btn.innerHTML;
        const origBg = btn.style.background;
        const origColor = btn.style.color;
        btn.innerHTML = '&#x2705; Copiado';
        btn.classList.add('copied');
        btn.style.background = '#059669';
        btn.style.color = '#fff';
        setTimeout(function () {
            btn.innerHTML = origHtml;
            btn.classList.remove('copied');
            btn.style.background = origBg;
            btn.style.color = origColor;
        }, 1800);
    }
    function fallbackCopy() {
        const ta = document.createElement('textarea');
        ta.value = text;
        ta.setAttribute('readonly', '');
        ta.style.position = 'fixed';
        ta.style.left = '-9999px';
        document.body.appendChild(ta);
        ta.focus();
        ta.select();
        try {
            document.execCommand('copy');
            markCopied();
        } catch (e) {
            /* ignore */
        }
        document.body.removeChild(ta);
    }
    if (!text) return;
    if (navigator.clipboard && window.isSecureContext) {
        navigator.clipboard.writeText(text).then(markCopied).catch(fallbackCopy);
    } else {
        fallbackCopy();
    }
}

function copyToClipboard(text, btn) {
    if (typeof text === 'string' && document.getElementById(text)) {
        const el = document.getElementById(text);
        text = el.value !== undefined ? el.value : el.textContent;
    }
    castpanelCopyText(text, btn);
}

function copyField(inputId, btnEl) {
    const el = document.getElementById(inputId);
    if (!el) return;
    castpanelCopyText(el.value !== undefined ? el.value : el.textContent, btnEl);
}

// Toast notification
function showToast(message, type = 'success') {
    const toast = document.createElement('div');
    toast.className = `toast ${type}`;
    toast.innerHTML = message;
    document.body.appendChild(toast);
    setTimeout(() => {
        toast.style.opacity = '0';
        toast.style.transform = 'translateX(100%)';
        setTimeout(() => toast.remove(), 300);
    }, 3000);
}

// Confirmar eliminación
function confirmDelete(message) {
    return confirm(message || '¿Estás seguro de que deseas eliminar esto?');
}

// Drag & Drop upload
function initDropZone(dropZone, fileInput, onFiles) {
    if (!dropZone || !fileInput) return;

    dropZone.addEventListener('click', () => fileInput.click());

    dropZone.addEventListener('dragover', (e) => {
        e.preventDefault();
        dropZone.classList.add('dragover');
    });

    dropZone.addEventListener('dragleave', () => {
        dropZone.classList.remove('dragover');
    });

    dropZone.addEventListener('drop', (e) => {
        e.preventDefault();
        dropZone.classList.remove('dragover');
        if (e.dataTransfer.files.length) {
            onFiles(e.dataTransfer.files);
        }
    });

    fileInput.addEventListener('change', () => {
        if (fileInput.files.length) {
            onFiles(fileInput.files);
        }
    });
}

// Upload de archivos con progreso
/**
 * Upload many files: one request per file (2 at a time) so 10–100 songs work
 * without hitting the server's request size / time limits. Only the plan's
 * storage quota limits how much can be uploaded.
 * onComplete gets {success, uploaded, total, results[]} with results in the
 * same order as `files`; onFileDone(index, result) is optional.
 */
async function uploadFiles(url, files, playlistField, playlistValue, onProgress, onComplete, onFileDone) {
    const list = Array.from(files || []);
    const csrfInput = document.querySelector('input[name="csrf_token"]');
    const totalBytes = list.reduce((n, f) => n + (f.size || 0), 0) || 1;
    const loaded = new Array(list.length).fill(0);
    const results = new Array(list.length);
    let next = 0;
    const report = () => {
        if (onProgress) onProgress(Math.min(100, Math.round(loaded.reduce((a, b) => a + b, 0) / totalBytes * 100)));
    };
    const sendOne = (i) => new Promise((resolve) => {
        const f = list[i];
        const fd = new FormData();
        fd.append('archivos[]', f);
        if (playlistField && playlistValue) fd.append(playlistField, playlistValue);
        if (csrfInput) fd.append('csrf_token', csrfInput.value);
        const xhr = new XMLHttpRequest();
        xhr.open('POST', url);
        xhr.upload.onprogress = (e) => { if (e.lengthComputable) { loaded[i] = e.loaded / e.total * (f.size || 0); report(); } };
        const done = (r) => { loaded[i] = f.size || 0; report(); results[i] = r; if (onFileDone) onFileDone(i, r); resolve(); };
        xhr.onload = () => {
            let r;
            try {
                const resp = JSON.parse(xhr.responseText);
                r = (resp.results && resp.results[0]) || { file: f.name, ok: !!resp.success, error: resp.error };
                if (!resp.success && resp.error && r.ok === undefined) r = { file: f.name, ok: false, error: resp.error };
            } catch (e) {
                r = { file: f.name, ok: false, error: xhr.status === 413 ? 'File too large for the server' : 'Server error (' + xhr.status + ')' };
            }
            done(r);
        };
        xhr.onerror = () => done({ file: f.name, ok: false, error: 'Connection error' });
        xhr.send(fd);
    });
    const worker = async () => { while (next < list.length) { await sendOne(next++); } };
    await Promise.all([worker(), worker()]);
    const uploaded = results.filter(r => r && r.ok).length;
    const firstError = (results.find(r => r && !r.ok) || {}).error;
    onComplete({ success: uploaded > 0, uploaded, total: list.length, results, error: uploaded ? undefined : (firstError || 'Upload failed') });
}

// Reordenar (mover arriba/abajo)
function moveItem(itemId, direction, url) {
    fetch(url, {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: `id=${itemId}&direction=${direction}&csrf_token=${document.querySelector('input[name="csrf_token"]')?.value || ''}`
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) location.reload();
        else showToast(data.error || 'Error', 'error');
    });
}

// Modal
function openModal(id) {
    document.getElementById(id).classList.add('show');
}

function closeModal(id) {
    document.getElementById(id).classList.remove('show');
}

// Auto-refresh del dashboard cada 30 segundos
function initDashboardRefresh(refreshUrl) {
    setInterval(() => {
        fetch(refreshUrl)
            .then(r => r.json())
            .then(data => {
                if (data.oyentes !== undefined) {
                    const el = document.getElementById('stat-oyentes');
                    if (el) el.textContent = data.oyentes;
                }
                if (data.pico !== undefined) {
                    const el = document.getElementById('stat-pico');
                    if (el) el.textContent = data.pico;
                }
            })
            .catch(() => {});
    }, 30000);
}

// Player de escucha de prueba
function initPlayer(streamUrl) {
    const audio = new Audio();
    const playBtn = document.getElementById('playerPlayBtn');
    const timeEl = document.getElementById('playerTime');
    let playing = false;

    if (!playBtn) return;

    playBtn.addEventListener('click', () => {
        if (playing) {
            audio.pause();
            audio.src = '';
            playBtn.innerHTML = '&#x25B6;';
            if (timeEl) timeEl.textContent = '0:00 / LIVE';
            playing = false;
        } else {
            audio.src = streamUrl;
            audio.play().catch(() => showToast('No se puede reproducir', 'error'));
            playBtn.innerHTML = '&#x23F9;';
            playing = true;
        }
    });

    audio.addEventListener('timeupdate', () => {
        if (timeEl) {
            const m = Math.floor(audio.currentTime / 60);
            const s = Math.floor(audio.currentTime % 60);
            timeEl.textContent = `${m}:${s.toString().padStart(2, '0')} / LIVE`;
        }
    });
}

// Filtros del log de emisión
function filterLog(tipo) {
    document.querySelectorAll('.filter-btn').forEach(b => b.classList.remove('active'));
    event.target.classList.add('active');

    document.querySelectorAll('.log-row').forEach(row => {
        if (tipo === 'todas' || row.dataset.tipo === tipo) {
            row.style.display = '';
        } else {
            row.style.display = 'none';
        }
    });
}

// Buscar en tabla (soporta ID de input o valor directo en oninput)
function searchTable(inputOrValue, tableId) {
    const table = document.getElementById(tableId);
    if (!table) return;

    const input = typeof inputOrValue === 'string' ? document.getElementById(inputOrValue) : null;
    // An input id that does not exist on the page must not be used as filter text (it hid every row)
    if (!input && typeof inputOrValue === 'string' && /^[A-Za-z][\w-]*(Input|_input|-input)$/.test(inputOrValue)) return;
    if (input) {
        input.addEventListener('input', () => {
            const filter = input.value.toLowerCase();
            table.querySelectorAll('tbody tr').forEach(row => {
                const text = row.textContent.toLowerCase();
                row.style.display = text.includes(filter) ? '' : 'none';
            });
        });
    } else {
        const filter = String(inputOrValue ?? '').toLowerCase();
        table.querySelectorAll('tbody tr').forEach(row => {
            const text = row.textContent.toLowerCase();
            row.style.display = text.includes(filter) ? '' : 'none';
        });
    }
}
