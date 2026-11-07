<?php
/**
 * CastPanel v2.4 - Admin: Cambiar Idioma
 * Configurar idioma predeterminado del panel con soporte i18n completo
 */
$pageTitle = 'Cambiar Idioma';
$pageIcon = 'languages';
$isAdmin = true;
require_once __DIR__ . '/../includes/layout_header.php';
require_once __DIR__ . '/../includes/i18n.php';
requireRole('admin');

$db = getDB();
$msg = '';
$msgType = '';

// Obtener idiomas disponibles con cobertura
$availableLanguages = getAvailableLanguages();

// POST: cambiar idioma
if ($_SERVER['REQUEST_METHOD'] === 'POST' && verifyCsrf()) {
    $lang = $_POST['language'] ?? 'es';
    if (array_key_exists($lang, $availableLanguages)) {
        try {
            $stmtCheck = $db->query("SELECT 1 FROM configuracion WHERE clave = 'default_language'");
            if ($stmtCheck->fetch()) {
                $db->prepare("UPDATE configuracion SET valor = ? WHERE clave = 'default_language'")->execute([$lang]);
            } else {
                $db->prepare("INSERT INTO configuracion (clave, valor) VALUES ('default_language', ?)")->execute([$lang]);
            }
            // Recargar traducciones
            loadTranslations($lang);
            $msg = __('language_changed') . " {$availableLanguages[$lang]['name']}";
            $msgType = 'success';
        } catch (PDOException $e) {
            $msg = 'Error: ' . $e->getMessage();
            $msgType = 'error';
        }
    }
}

// Idioma actual
$currentLang = 'es';
try {
    $row = $db->query("SELECT valor FROM configuracion WHERE clave = 'default_language'")->fetch();
    if ($row) $currentLang = $row['valor'];
} catch (PDOException $e) {}

// Contar claves base (español)
$baseKeys = cpPhraseCount('es');
?>

<?php if ($msg): ?>
<div class="alert alert-<?= $msgType === 'error' ? 'danger' : 'success' ?>">
    <?= htmlspecialchars($msg) ?>
</div>
<?php endif; ?>

<div class="card" style="margin-bottom:1.5rem;">
    <div class="card-header">
        <h3><?= cpIcon('languages', 18) ?> Idioma del Panel</h3>
    </div>
    <div class="card-body">
        <form method="post">
            <?= csrfField() ?>

            <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(280px,1fr));gap:1rem;margin-bottom:1.5rem;">
                <?php foreach ($availableLanguages as $code => $lang):
                    $coverage = $lang['has_file'] ? getTranslationCoverage($code) : 0;
                    $isSelected = $currentLang === $code;
                    $barColor = $coverage >= 90 ? '#28a745' : ($coverage >= 50 ? '#fd7e14' : '#dc3545');
                ?>
                <label style="display:flex;align-items:center;gap:1rem;padding:1rem;border:2px solid <?= $isSelected ? 'var(--primary)' : '#e5e7eb' ?>;border-radius:10px;cursor:<?= $lang['has_file'] ? 'pointer' : 'not-allowed' ?>;background:<?= $isSelected ? '#f0f9ff' : '#fff' ?>;opacity:<?= $lang['has_file'] ? '1' : '0.6' ?>;">
                    <input type="radio" name="language" value="<?= $code ?>"
                           <?= $isSelected ? 'checked' : '' ?>
                           <?= !$lang['has_file'] ? 'disabled' : '' ?>>
                    <span style="font-size:1.5rem;"><?= $lang['flag'] ?></span>
                    <div style="flex:1;">
                        <div style="font-weight:600;"><?= htmlspecialchars($lang['name']) ?></div>
                        <div style="font-size:.8rem;color:#999;"><?= strtoupper($code) ?> · <?= $lang['native'] ?></div>
                        <?php if ($lang['has_file']): ?>
                        <div style="margin-top:.4rem;">
                            <div style="display:flex;justify-content:space-between;font-size:.7rem;color:#666;margin-bottom:.2rem;">
                                <span><?= $lang['keys_count'] ?>/<?= $baseKeys ?> <?= __('translated_keys') ?></span>
                                <span><?= $coverage ?>%</span>
                            </div>
                            <div style="background:#e9ecef;border-radius:3px;height:4px;">
                                <div style="width:<?= $coverage ?>%;height:100%;background:<?= $barColor ?>;border-radius:3px;"></div>
                            </div>
                        </div>
                        <?php else: ?>
                        <div style="font-size:.7rem;color:#dc3545;margin-top:.3rem;">Traducción no disponible</div>
                        <?php endif; ?>
                    </div>
                    <?php if ($isSelected): ?>
                    <span class="badge badge-success" style="font-size:.7rem;">Actual</span>
                    <?php endif; ?>
                </label>
                <?php endforeach; ?>
            </div>

            <button type="submit" class="btn btn-primary">
                <?= cpIcon('save', 14) ?> Guardar Idioma
            </button>
        </form>
    </div>
</div>

<!-- Translation Info -->
<div class="card">
    <div class="card-header">
        <h3><?= cpIcon('info', 18) ?> Sistema de Traducción</h3>
    </div>
    <div class="card-body">
        <div class="form-grid-2">
            <div style="padding:1rem;background:#f8f9fa;border-radius:8px;">
                <strong>Idioma activo:</strong> <?= htmlspecialchars($availableLanguages[$currentLang]['name'] ?? 'Español') ?>
                <br><br>
                <strong>Archivos de traducción:</strong> <code>/lang/*.php</code>
                <br><br>
                <strong>Claves base (ES):</strong> <?= $baseKeys ?>
            </div>
            <div style="padding:1rem;background:#f0fdf4;border:1px solid #bbf7d0;border-radius:8px;">
                <strong>Cómo agregar un idioma:</strong>
                <ol style="margin:.5rem 0 0 1.2rem;font-size:.9rem;">
                    <li>Copie <code>lang/es.php</code> a <code>lang/XX.php</code></li>
                    <li>Traduzca los valores del array</li>
                    <li>El idioma aparecerá automáticamente</li>
                </ol>
                <p style="font-size:.8rem;color:#666;margin-top:.5rem;">
                    Use la función <code>__('clave')</code> en cualquier archivo PHP para mostrar texto traducido.
                </p>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/layout_footer.php'; ?>
