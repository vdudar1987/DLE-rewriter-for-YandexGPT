<?php

require_once __DIR__ . '/class.yandexgpt.php';

$config = require ENGINE_DIR . '/data/yandexgpt_rewrite.php';
$module = new YandexGptRewrite($config, $pdo ?? null);

header('Content-Type: application/json; charset=utf-8');

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
