<?php
/**
 * Floating language panel (admin, reseller and client panels).
 * Each user picks the language of their own dashboard; an admin can also
 * make it the panel default (login page and new accounts).
 * Included by layout_header.php; opened with cpLangOpen().
 */
$cpLangs = [
    'es' => ['🇪🇸', 'Español', 'Spanish'],   'en' => ['🇺🇸', 'English', 'English'],
    'pt' => ['🇧🇷', 'Português', 'Portuguese'], 'fr' => ['🇫🇷', 'Français', 'French'],
    'de' => ['🇩🇪', 'Deutsch', 'German'],     'it' => ['🇮🇹', 'Italiano', 'Italian'],
    'nl' => ['🇳🇱', 'Nederlands', 'Dutch'],   'tr' => ['🇹🇷', 'Türkçe', 'Turkish'],
    'pl' => ['🇵🇱', 'Polski', 'Polish'],      'ro' => ['🇷🇴', 'Română', 'Romanian'],
    'cs' => ['🇨🇿', 'Čeština', 'Czech'],      'sr' => ['🇷🇸', 'Srpski', 'Serbian'],
    'el' => ['🇬🇷', 'Ελληνικά', 'Greek'],     'af' => ['🇿🇦', 'Afrikaans', 'Afrikaans'],
    'fa' => ['🇮🇷', 'فارسی', 'Persian'],
];
if (!function_exists('cpIcon')) require_once __DIR__ . '/icons.php';
if (!function_exists('csrfToken')) require_once __DIR__ . '/functions.php';
// The DJ portal includes this panel with its own endpoint (see dj/includes/header.php)
$cpLangEndpoint = $cpLangEndpoint ?? ((defined('BASE_URL') ? BASE_URL : '') . '/api/set_language.php');
$cpLangRole = $cpLangRole ?? ($_SESSION['user_rol'] ?? '');
$cpLangNow = function_exists('getCurrentLang') ? getCurrentLang() : 'es';
if (!isset($cpLangs[$cpLangNow])) $cpLangNow = 'es';
$cpLangIsAdmin = empty($_SESSION['dj_id']) && ($_SESSION['user_rol'] ?? '') === 'admin';
$cpLangDefault = function_exists('cpDefaultLanguage') ? cpDefaultLanguage() : 'es';
?>
<style>
.cp-lang-btn{display:inline-flex;align-items:center;gap:6px;padding:6px 11px;border:1px solid var(--border);border-radius:9px;background:var(--card-bg);color:var(--text-primary);font-size:13px;font-weight:600;cursor:pointer;font-family:inherit;transition:var(--transition)}
.cp-lang-btn:hover{border-color:var(--primary);color:var(--primary)}
.cp-lang-btn .fl{font-size:16px;line-height:1}
.cp-lang-overlay{position:fixed;inset:0;z-index:1000;display:none;align-items:flex-start;justify-content:center;padding:70px 16px 16px;background:rgba(15,23,42,.35);-webkit-backdrop-filter:blur(4px);backdrop-filter:blur(4px)}
.cp-lang-overlay.open{display:flex;animation:cpLangFade .15s ease-out}
@keyframes cpLangFade{from{opacity:0}to{opacity:1}}
.cp-lang-panel{width:100%;max-width:380px;max-height:calc(100vh - 100px);display:flex;flex-direction:column;background:var(--card-bg);border:1px solid var(--border);border-radius:18px;box-shadow:0 24px 60px rgba(15,23,42,.25);overflow:hidden;animation:cpLangUp .18s ease-out}
@keyframes cpLangUp{from{transform:translateY(10px);opacity:.6}to{transform:none;opacity:1}}
.cp-lang-head{display:flex;align-items:center;justify-content:space-between;padding:16px 18px;border-bottom:1px solid var(--border)}
.cp-lang-head h3{margin:0;font-size:16px;font-weight:700;display:flex;align-items:center;gap:8px}
.cp-lang-close{border:none;background:none;font-size:22px;line-height:1;color:var(--text-secondary);cursor:pointer;padding:4px 8px;border-radius:8px}
.cp-lang-close:hover{background:var(--hover);color:var(--text-primary)}
.cp-lang-body{display:flex;flex-direction:column;min-height:0;flex:1}

.cp-lang-side{display:flex;flex-direction:column;min-height:0;background:var(--card-bg)}
.cp-lang-search{margin:12px;padding:9px 12px;border:1px solid var(--border);border-radius:9px;font-size:13px;background:var(--card-bg);color:var(--text-primary);font-family:inherit}
.cp-lang-search:focus{outline:none;border-color:var(--primary);box-shadow:0 0 0 3px rgba(108,60,224,.12)}
.cp-lang-list{list-style:none;margin:0;padding:0 8px 10px;overflow-y:auto;max-height:420px}
.cp-lang-item{display:flex;align-items:center;gap:10px;width:100%;padding:9px 10px;border:none;background:none;border-radius:10px;font-family:inherit;font-size:13.5px;color:var(--text-primary);cursor:pointer;text-align:left}
.cp-lang-item:hover{background:var(--body-bg,#f5f6fa)}
.cp-lang-item[disabled]{opacity:.6;cursor:wait}
.cp-lang-item.sel{background:var(--primary-bg);color:var(--primary);font-weight:600}
.cp-lang-item .fl{font-size:18px}
.cp-lang-item .nm{flex:1}
.cp-lang-item .cd{font-size:11px;color:var(--text-secondary);text-transform:uppercase}
.cp-lang-item .now{font-size:10px;font-weight:800;padding:2px 6px;border-radius:6px;background:#d1fae5;color:#065f46}
.cp-lang-detail{padding:22px;display:flex;flex-direction:column;gap:14px}
.cp-lang-big{display:flex;align-items:center;gap:14px}
.cp-lang-big .fl{font-size:44px;line-height:1}
.cp-lang-big strong{display:block;font-size:20px}
.cp-lang-big small{color:var(--text-secondary);font-size:13px}
.cp-lang-note{font-size:12.5px;color:var(--text-secondary);line-height:1.5;margin:0}
.cp-lang-default{display:flex;gap:8px;align-items:flex-start;margin:0 12px 12px;padding:10px 12px;border:1px solid var(--border);border-radius:10px;font-size:12px;cursor:pointer;color:var(--text-secondary)}
.cp-lang-default input{margin-top:2px}
.cp-lang-apply{align-self:flex-start;border:none;border-radius:10px;background:var(--primary);color:#fff;padding:11px 20px;font-size:14px;font-weight:700;font-family:inherit;cursor:pointer;display:inline-flex;gap:8px;align-items:center}
.cp-lang-apply:hover{background:var(--primary-dark)}
.cp-lang-apply[disabled]{opacity:.6;cursor:default}
.cp-lang-err{color:var(--danger);font-size:13px;margin:0 12px 12px;display:none}
</style>

<div class="cp-lang-overlay" id="cpLangOverlay" aria-hidden="true">
    <div class="cp-lang-panel" role="dialog" aria-modal="true" aria-labelledby="cpLangTitle">
        <div class="cp-lang-head">
            <h3 id="cpLangTitle"><?= cpIcon('globe', 18) ?> Idioma del panel</h3>
            <button type="button" class="cp-lang-close" onclick="cpLangClose()" aria-label="Cerrar">&times;</button>
        </div>
        <div class="cp-lang-body">
            <aside class="cp-lang-side">
                <input type="search" class="cp-lang-search" id="cpLangSearch" placeholder="Buscar idioma..." oninput="cpLangFilter(this.value)" aria-label="Buscar idioma...">
                <ul class="cp-lang-list" id="cpLangList">
                    <?php foreach ($cpLangs as $code => [$flag, $native, $eng]): ?>
                    <li><button type="button" class="cp-lang-item <?= $code === $cpLangNow ? 'sel' : '' ?>" data-code="<?= $code ?>"
                                data-search="<?= htmlspecialchars(mb_strtolower("$native $eng $code")) ?>" onclick="cpLangPick('<?= $code ?>')">
                        <span class="fl"><?= $flag ?></span><span class="nm" translate="no"><?= htmlspecialchars($native) ?></span>
                        <?php if ($code === $cpLangNow): ?><span class="now">✓</span><?php else: ?><span class="cd"><?= $code ?></span><?php endif; ?>
                    </button></li>
                    <?php endforeach; ?>
                </ul>
                <?php if ($cpLangIsAdmin): ?>
                <label class="cp-lang-default">
                    <input type="checkbox" id="cpLangDefault" <?= $cpLangNow === $cpLangDefault ? 'checked' : '' ?>>
                    <span>Usar también como idioma predeterminado (página de inicio de sesión y cuentas nuevas)</span>
                </label>
                <?php endif; ?>
                <p class="cp-lang-err" id="cpLangErr"></p>
            </aside>
        </div>
    </div>
</div>

<script>
(function () {
    var langs = <?= json_encode($cpLangs, JSON_UNESCAPED_UNICODE) ?>;
    var current = <?= json_encode($cpLangNow) ?>;
    var panelDefault = <?= json_encode($cpLangDefault) ?>;
    var picked = current;
    var overlay = document.getElementById('cpLangOverlay');

    function show(code) {
        picked = code;
        document.querySelectorAll('#cpLangList .cp-lang-item').forEach(function (b) {
            b.classList.toggle('sel', b.dataset.code === code);
        });
    }
    // Picking a language applies it at once (no separate detail/apply step)
    window.cpLangPick = function (code) {
        show(code);
        var def = document.getElementById('cpLangDefault');
        if (code === current && !(def && def.checked && code !== panelDefault)) { window.cpLangClose(); return; }
        window.cpLangApply();
    };
    window.cpLangOpen = function () {
        show(current);
        overlay.classList.add('open');
        overlay.setAttribute('aria-hidden', 'false');
        setTimeout(function () { document.getElementById('cpLangSearch').focus(); }, 50);
        return false;
    };
    window.cpLangClose = function () {
        overlay.classList.remove('open');
        overlay.setAttribute('aria-hidden', 'true');
    };
    window.cpLangFilter = function (q) {
        q = (q || '').toLowerCase().trim();
        document.querySelectorAll('#cpLangList .cp-lang-item').forEach(function (b) {
            b.parentElement.style.display = !q || b.dataset.search.indexOf(q) !== -1 ? '' : 'none';
        });
    };
    window.cpLangApply = function () {
        var err = document.getElementById('cpLangErr');
        var def = document.getElementById('cpLangDefault');
        var items = document.querySelectorAll('#cpLangList .cp-lang-item');
        items.forEach(function (b) { b.disabled = true; });
        err.style.display = 'none';
        var body = new URLSearchParams({ lang: picked, csrf_token: <?= json_encode(csrfToken()) ?>,
            rol: <?= json_encode($cpLangRole) ?>, make_default: def && def.checked ? '1' : '' });
        fetch(<?= json_encode($cpLangEndpoint) ?>, { method: 'POST', credentials: 'same-origin',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: body })
            .then(function (r) { return r.json(); })
            .then(function (d) {
                if (!d.success) throw new Error(d.error || 'Error');
                location.reload();
            })
            .catch(function (e) { items.forEach(function (b) { b.disabled = false; }); err.textContent = e.message; err.style.display = 'block'; });
    };
    overlay.addEventListener('click', function (e) { if (e.target === overlay) window.cpLangClose(); });
    document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && overlay.classList.contains('open')) window.cpLangClose(); });
})();
</script>
