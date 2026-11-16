<?php
/**
 * CastPanel — save the signed-in user's panel language (floating language panel)
 *
 * POST lang=<code> csrf_token=<token> rol=<role of the page>
 *      [make_default=1]   admin only: also the panel default (login page, new accounts)
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/i18n.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit(json_encode(['success' => false, 'error' => 'POST only']));
}
if (!isLoggedIn() || !verifyCsrf()) {
    http_response_code(403);
    exit(json_encode(['success' => false, 'error' => 'Session expired — reload the page']));
}

// Several accounts can share this browser session (admin "Login as user"):
// change the language of the account the page belongs to.
$rol = (string)($_POST['rol'] ?? '');
if ($rol !== '' && ($_SESSION['user_rol'] ?? '') !== $rol && function_exists('cpAuthSwitchTo')) {
    cpAuthSwitchTo($rol);
}
if (!in_array($_SESSION['user_rol'] ?? '', ['admin', 'reseller', 'cliente'], true)) {
    http_response_code(403);
    exit(json_encode(['success' => false, 'error' => 'Not allowed']));
}

$lang = (string)($_POST['lang'] ?? '');
if (!in_array($lang, cpLanguageCodes(), true)) {
    http_response_code(422);
    exit(json_encode(['success' => false, 'error' => 'Unknown language']));
}

if (!cpSaveUserLanguage((int)$_SESSION['user_id'], $lang)) {
    http_response_code(500);
    exit(json_encode(['success' => false, 'error' => 'Could not save']));
}

$madeDefault = false;
if (!empty($_POST['make_default']) && $_SESSION['user_rol'] === 'admin') {
    $db = getDB();
    $exists = $db->query("SELECT 1 FROM configuracion WHERE clave = 'default_language'")->fetchColumn();
    if ($exists) {
        $db->prepare("UPDATE configuracion SET valor = ? WHERE clave = 'default_language'")->execute([$lang]);
    } else {
        $db->prepare("INSERT INTO configuracion (clave, valor) VALUES ('default_language', ?)")->execute([$lang]);
    }
    $madeDefault = true;
}

echo json_encode(['success' => true, 'lang' => $lang, 'default' => $madeDefault]);
