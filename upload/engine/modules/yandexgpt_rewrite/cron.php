<?php

require_once __DIR__ . '/class.yandexgpt.php';

$config = require ENGINE_DIR . '/data/yandexgpt_rewrite.php';
$module = new YandexGptRewrite($config, $pdo ?? null);

$result = $module->processCronBatch();

echo 'YandexGPT Rewrite: processed ' . count($result) . PHP_EOL;
foreach ($result as $item) {
    echo sprintf('#%d %s', (int)$item['id'], $item['status']) . PHP_EOL;
}
