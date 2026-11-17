<?php
/**
 * CastPanel DJ portal — save the DJ's own panel language (floating language panel)
 * POST lang=<code> csrf_token=<token>
 */
if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/i18n.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit(json_encode(['success' => false, 'error' => 'POST only']));
}
if (empty($_SESSION['dj_id']) || !verifyCsrf()) {
    http_response_code(403);
    exit(json_encode(['success' => false, 'error' => 'Session expired — reload the page']));
}
$lang = (string)($_POST['lang'] ?? '');
if (!in_array($lang, cpLanguageCodes(), true)) {
    http_response_code(422);
    exit(json_encode(['success' => false, 'error' => 'Unknown language']));
}
if (!cpSaveDjLanguage((int)$_SESSION['dj_id'], $lang)) {
    http_response_code(500);
    exit(json_encode(['success' => false, 'error' => 'Could not save']));
}
echo json_encode(['success' => true, 'lang' => $lang]);
