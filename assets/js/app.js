/**
 * CastPanel v2.0 - JavaScript principal
 */

// Toggle sidebar mobile
function toggleSidebar() {
    document.getElementById('sidebar').classList.toggle('open');
    document.getElementById('sidebarOverlay').classList.toggle('show');
}

// Copiar al portapapeles
function copyToClipboard(text, btn) {
    // Si text es un ID de input, copiar su valor
    if (typeof text === 'string' && !btn) {
        const el = document.getElementById(text);
        if (el) text = el.value || el.textContent;
    }
    if (typeof text === 'string' && btn) {
        navigator.clipboard.writeText(text).then(() => {
            const original = btn.textContent;
            btn.textContent = 'Copiado!';
            btn.style.background = '#059669';
            setTimeout(() => {
                btn.textContent = original;
                btn.style.background = '';
            }, 1500);
        });
    }
}

// Copiar valor de un input por su ID
function copyField(inputId, btnEl) {
    const el = document.getElementById(inputId);
    if (!el) return;
    const text = el.value || el.textContent;
    navigator.clipboard.writeText(text).then(() => {
        const original = btnEl.textContent;
        btnEl.textContent = '✓ Copiado';
        btnEl.style.background = '#059669';
        btnEl.style.color = '#fff';
        setTimeout(() => {
            btnEl.textContent = original;
            btnEl.style.background = '';
            btnEl.style.color = '';
        }, 1500);
    });
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
async function uploadFiles(url, files, playlistField, playlistValue, onProgress, onComplete) {
    const formData = new FormData();
    for (let i = 0; i < files.length; i++) {
        formData.append('archivos[]', files[i]);
    }
    if (playlistField && playlistValue) {
        formData.append(playlistField, playlistValue);
    }
    // CSRF
    const csrfInput = document.querySelector('input[name="csrf_token"]');
    if (csrfInput) formData.append('csrf_token', csrfInput.value);

    const xhr = new XMLHttpRequest();
    xhr.open('POST', url);

    xhr.upload.onprogress = (e) => {
        if (e.lengthComputable && onProgress) {
            onProgress(Math.round((e.loaded / e.total) * 100));
        }
    };

    xhr.onload = () => {
        try {
            const resp = JSON.parse(xhr.responseText);
            onComplete(resp);
        } catch (e) {
            onComplete({ success: false, error: 'Error de servidor' });
        }
    };

    xhr.onerror = () => {
        onComplete({ success: false, error: 'Error de conexión' });
    };

    xhr.send(formData);
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

// Buscar en tabla
function searchTable(inputId, tableId) {
    const input = document.getElementById(inputId);
    const table = document.getElementById(tableId);
    if (!input || !table) return;

    input.addEventListener('input', () => {
        const filter = input.value.toLowerCase();
        table.querySelectorAll('tbody tr').forEach(row => {
            const text = row.textContent.toLowerCase();
            row.style.display = text.includes(filter) ? '' : 'none';
        });
    });
}
