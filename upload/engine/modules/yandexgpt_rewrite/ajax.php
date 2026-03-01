<?php

require_once __DIR__ . '/class.yandexgpt.php';

if (!defined('DATALIFEENGINE')) {
    die('Hacking attempt!');
}

header('Content-Type: application/json; charset=utf-8');

$userHash = (string)($_POST['user_hash'] ?? '');
$sessionHash = (string)($_SESSION['dle_user_hash'] ?? '');
if ($userHash === '' || $sessionHash === '' || !hash_equals($sessionHash, $userHash)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Некорректный CSRF-токен.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$config = require ENGINE_DIR . '/data/yandexgpt_rewrite.php';
$module = new YandexGptRewrite($config, $pdo ?? null);

try {
    $payload = [
        'title' => (string)($_POST['title'] ?? ''),
        'short_story' => (string)($_POST['short_story'] ?? ''),
        'full_story' => (string)($_POST['full_story'] ?? ''),
        'xfields' => (string)($_POST['xfields'] ?? ''),
    ];

    $result = $module->processArticle($payload);
    echo json_encode(['success' => true, 'result' => $result], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
