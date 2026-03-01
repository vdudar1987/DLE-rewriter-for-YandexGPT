<?php

require_once __DIR__ . '/class.yandexgpt.php';

$config = require ENGINE_DIR . '/data/yandexgpt_rewrite.php';
$module = new YandexGptRewrite($config, $pdo ?? null);

function yagptParseIds(string $raw): array
{
    $ids = [];
    foreach (explode(',', $raw) as $chunk) {
        $chunk = trim($chunk);
        if ($chunk === '') {
            continue;
        }

        if (strpos($chunk, '-') !== false) {
            [$start, $end] = array_map('intval', explode('-', $chunk, 2));
            if ($start > 0 && $end >= $start) {
                $ids = array_merge($ids, range($start, $end));
            }
            continue;
        }

        $id = (int)$chunk;
        if ($id > 0) {
            $ids[] = $id;
        }
    }

    return array_values(array_unique($ids));
}

$action = $_POST['action'] ?? '';
if ($action === 'process_ids') {
    $ids = yagptParseIds((string)($_POST['ids'] ?? ''));
    $result = $module->processByIds($ids);

    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['processed' => $result], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($action === 'run_cron_batch') {
    $result = $module->processCronBatch();

    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['processed' => $result], JSON_UNESCAPED_UNICODE);
    exit;
}

?>
<div class="box">
    <div class="box-header with-border"><h3 class="box-title">YandexGPT Rewrite</h3></div>
    <div class="box-body">
        <form method="post">
            <input type="hidden" name="action" value="process_ids">
            <label>Обработка по ID/диапазону (пример: 1-20,25,33-35)</label>
            <input type="text" class="form-control" name="ids" placeholder="1-20,25,33-35">
            <br>
            <button class="btn btn-success" type="submit">Запустить обработку</button>
        </form>
    </div>
</div>
