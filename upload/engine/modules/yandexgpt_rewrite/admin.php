<?php

require_once __DIR__ . '/class.yandexgpt.php';

if (!defined('DATALIFEENGINE')) {
    die('Hacking attempt!');
}

if (($member_id['user_group'] ?? 0) != 1) {
    msg('error', 'Доступ запрещён', 'Недостаточно прав для управления модулем YandexGPT Rewrite.');
}

$configFile = ENGINE_DIR . '/data/yandexgpt_rewrite.php';
$config = require $configFile;
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

function yagptCheckCsrfToken(): void
{
    $token = (string)($_REQUEST['user_hash'] ?? '');
    $session = (string)($_SESSION['dle_user_hash'] ?? '');

    if ($token === '' || $session === '' || !hash_equals($session, $token)) {
        header('Content-Type: application/json; charset=utf-8');
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Некорректный CSRF-токен.'], JSON_UNESCAPED_UNICODE);
        exit;
    }
}

function yagptArrayToPhpCode(array $value, int $level = 0): string
{
    $indent = str_repeat('    ', $level);
    $nextIndent = str_repeat('    ', $level + 1);
    $out = "[\n";

    foreach ($value as $key => $item) {
        $out .= $nextIndent . var_export($key, true) . ' => ';

        if (is_array($item)) {
            $out .= yagptArrayToPhpCode($item, $level + 1);
        } else {
            $out .= var_export($item, true);
        }

        $out .= ",\n";
    }

    return $out . $indent . ']';
}

function yagptSaveConfig(array $config, string $file): bool
{
    $content = "<?php\n\nreturn " . yagptArrayToPhpCode($config) . ";\n";
    return file_put_contents($file, $content) !== false;
}

$action = (string)($_REQUEST['action'] ?? '');

if ($action === 'process_single') {
    yagptCheckCsrfToken();

    header('Content-Type: application/json; charset=utf-8');

    try {
        $id = (int)($_POST['id'] ?? 0);
        $item = $module->processSingleById($id);
        echo json_encode(['success' => true, 'item' => $item], JSON_UNESCAPED_UNICODE);
    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

if ($action === 'save_settings') {
    yagptCheckCsrfToken();

    $config['enabled'] = !empty($_POST['enabled']);
    $config['api_key'] = trim((string)($_POST['api_key'] ?? ''));
    $config['catalog_id'] = trim((string)($_POST['catalog_id'] ?? ''));
    $config['model_uri'] = trim((string)($_POST['model_uri'] ?? $config['model_uri']));
    $config['temperature'] = (float)($_POST['temperature'] ?? $config['temperature']);
    $config['max_tokens'] = max(1, (int)($_POST['max_tokens'] ?? $config['max_tokens']));
    $config['retry_attempts'] = max(1, (int)($_POST['retry_attempts'] ?? $config['retry_attempts']));
    $config['retry_delay_ms'] = max(0, (int)($_POST['retry_delay_ms'] ?? $config['retry_delay_ms']));
    $config['timeout'] = max(5, (int)($_POST['timeout'] ?? $config['timeout']));
    $config['keyword_count'] = max(1, (int)($_POST['keyword_count'] ?? $config['keyword_count']));
    $config['rewrite_min_words'] = max(1, (int)($_POST['rewrite_min_words'] ?? $config['rewrite_min_words']));

    $config['cron']['enabled'] = !empty($_POST['cron_enabled']);
    $config['cron']['limit'] = max(1, (int)($_POST['cron_limit'] ?? $config['cron']['limit']));
    $config['cron']['only_disable_index'] = !empty($_POST['cron_only_disable_index']);

    $categoryIdsRaw = trim((string)($_POST['cron_category_ids'] ?? ''));
    $categoryIds = array_filter(array_map('intval', preg_split('/\s*,\s*/', $categoryIdsRaw) ?: []));
    $config['cron']['category_ids'] = array_values(array_unique($categoryIds));

    $saved = yagptSaveConfig($config, $configFile);

    $message = $saved ? 'Настройки сохранены.' : 'Не удалось сохранить настройки.';
    $msgType = $saved ? 'info' : 'error';

    msg($msgType, 'YandexGPT Rewrite', $message);
}

$logs = $module->getLogs(100);
$viewLogId = (int)($_GET['view_log'] ?? 0);
$logEntry = $viewLogId > 0 ? $module->getLogById($viewLogId) : null;
$userHash = (string)($_SESSION['dle_user_hash'] ?? '');
?>
<div class="box">
    <div class="box-header with-border"><h3 class="box-title">YandexGPT Rewrite: Настройки</h3></div>
    <div class="box-body">
        <form method="post" class="form-horizontal">
            <input type="hidden" name="action" value="save_settings">
            <input type="hidden" name="user_hash" value="<?=htmlspecialchars($userHash, ENT_QUOTES, 'UTF-8');?>">

            <div class="form-group">
                <label class="col-md-2 control-label">Модуль активен</label>
                <div class="col-md-10">
                    <label><input type="checkbox" name="enabled" value="1" <?=!empty($config['enabled']) ? 'checked' : '';?>> Включить</label>
                </div>
            </div>

            <div class="form-group">
                <label class="col-md-2 control-label">API Key</label>
                <div class="col-md-10"><input type="text" class="form-control" name="api_key" value="<?=htmlspecialchars((string)$config['api_key'], ENT_QUOTES, 'UTF-8');?>"></div>
            </div>

            <div class="form-group">
                <label class="col-md-2 control-label">Catalog ID</label>
                <div class="col-md-10"><input type="text" class="form-control" name="catalog_id" value="<?=htmlspecialchars((string)$config['catalog_id'], ENT_QUOTES, 'UTF-8');?>"></div>
            </div>

            <div class="form-group">
                <label class="col-md-2 control-label">Model URI</label>
                <div class="col-md-10"><input type="text" class="form-control" name="model_uri" value="<?=htmlspecialchars((string)$config['model_uri'], ENT_QUOTES, 'UTF-8');?>"></div>
            </div>

            <div class="form-group">
                <label class="col-md-2 control-label">Temperature</label>
                <div class="col-md-4"><input type="number" step="0.1" min="0" max="1" class="form-control" name="temperature" value="<?=htmlspecialchars((string)$config['temperature'], ENT_QUOTES, 'UTF-8');?>"></div>

                <label class="col-md-2 control-label">Max tokens</label>
                <div class="col-md-4"><input type="number" min="1" class="form-control" name="max_tokens" value="<?=htmlspecialchars((string)$config['max_tokens'], ENT_QUOTES, 'UTF-8');?>"></div>
            </div>

            <div class="form-group">
                <label class="col-md-2 control-label">Retry attempts</label>
                <div class="col-md-4"><input type="number" min="1" class="form-control" name="retry_attempts" value="<?=htmlspecialchars((string)$config['retry_attempts'], ENT_QUOTES, 'UTF-8');?>"></div>

                <label class="col-md-2 control-label">Retry delay ms</label>
                <div class="col-md-4"><input type="number" min="0" class="form-control" name="retry_delay_ms" value="<?=htmlspecialchars((string)$config['retry_delay_ms'], ENT_QUOTES, 'UTF-8');?>"></div>
            </div>

            <div class="form-group">
                <label class="col-md-2 control-label">Timeout</label>
                <div class="col-md-4"><input type="number" min="5" class="form-control" name="timeout" value="<?=htmlspecialchars((string)$config['timeout'], ENT_QUOTES, 'UTF-8');?>"></div>

                <label class="col-md-2 control-label">Keyword count</label>
                <div class="col-md-4"><input type="number" min="1" class="form-control" name="keyword_count" value="<?=htmlspecialchars((string)$config['keyword_count'], ENT_QUOTES, 'UTF-8');?>"></div>
            </div>

            <div class="form-group">
                <label class="col-md-2 control-label">Min words</label>
                <div class="col-md-4"><input type="number" min="1" class="form-control" name="rewrite_min_words" value="<?=htmlspecialchars((string)$config['rewrite_min_words'], ENT_QUOTES, 'UTF-8');?>"></div>

                <label class="col-md-2 control-label">CRON limit</label>
                <div class="col-md-4"><input type="number" min="1" class="form-control" name="cron_limit" value="<?=htmlspecialchars((string)$config['cron']['limit'], ENT_QUOTES, 'UTF-8');?>"></div>
            </div>

            <div class="form-group">
                <label class="col-md-2 control-label">CRON</label>
                <div class="col-md-10">
                    <label><input type="checkbox" name="cron_enabled" value="1" <?=!empty($config['cron']['enabled']) ? 'checked' : '';?>> Включить CRON</label>
                    <br>
                    <label><input type="checkbox" name="cron_only_disable_index" value="1" <?=!empty($config['cron']['only_disable_index']) ? 'checked' : '';?>> Только disable_index=1</label>
                </div>
            </div>

            <div class="form-group">
                <label class="col-md-2 control-label">CRON категории</label>
                <div class="col-md-10">
                    <input type="text" class="form-control" name="cron_category_ids" value="<?=htmlspecialchars(implode(',', (array)$config['cron']['category_ids']), ENT_QUOTES, 'UTF-8');?>" placeholder="1,2,3">
                </div>
            </div>

            <div class="text-right">
                <button class="btn btn-primary" type="submit">Сохранить настройки</button>
            </div>
        </form>
    </div>
</div>

<div class="box">
    <div class="box-header with-border"><h3 class="box-title">Массовая обработка с прогресс-баром</h3></div>
    <div class="box-body">
        <div class="form-group">
            <label>Обработка по ID/диапазону (пример: 1-20,25,33-35)</label>
            <input type="text" class="form-control" id="yagpt-ids" placeholder="1-20,25,33-35">
        </div>

        <button id="yagpt-start" class="btn btn-success" type="button">Запустить обработку</button>
        <hr>

        <div class="progress">
            <div id="yagpt-progress" class="progress-bar progress-bar-success" role="progressbar" style="width:0%;min-width:2em;">0%</div>
        </div>
        <div id="yagpt-status" class="alert alert-info" style="margin-top:10px;">Ожидание запуска.</div>
        <ul id="yagpt-result" style="max-height:220px;overflow:auto;padding-left:20px;"></ul>
    </div>
</div>

<div class="box">
    <div class="box-header with-border"><h3 class="box-title">Отчёты обработки</h3></div>
    <div class="box-body table-responsive">
        <table class="table table-bordered table-striped">
            <thead>
            <tr>
                <th>ID лога</th>
                <th>ID новости</th>
                <th>Заголовок</th>
                <th>Дата</th>
                <th>Действие</th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($logs as $log): ?>
                <tr>
                    <td><?= (int)$log['id']; ?></td>
                    <td><?= (int)$log['post_id']; ?></td>
                    <td><?= htmlspecialchars((string)($log['title'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
                    <td><?= htmlspecialchars((string)$log['processed_at'], ENT_QUOTES, 'UTF-8'); ?></td>
                    <td><a href="?mod=yandexgpt_rewrite&view_log=<?= (int)$log['id']; ?>" class="btn btn-xs btn-default">Открыть diff</a></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<?php if ($logEntry):
    $before = (array)($logEntry['before_payload'] ?? []);
    $after = (array)($logEntry['payload'] ?? []);
?>
    <div class="box">
        <div class="box-header with-border"><h3 class="box-title">Детальный отчёт #<?= (int)$logEntry['id']; ?> (post_id <?= (int)$logEntry['post_id']; ?>)</h3></div>
        <div class="box-body">
            <div class="row">
                <div class="col-md-6">
                    <h4>До</h4>
                    <pre><?= htmlspecialchars(json_encode($before, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8'); ?></pre>
                </div>
                <div class="col-md-6">
                    <h4>После</h4>
                    <pre><?= htmlspecialchars(json_encode($after, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8'); ?></pre>
                </div>
            </div>
        </div>
    </div>
<?php endif; ?>

<script>
(function () {
    function parseIds(raw) {
        var ids = [];
        raw.split(',').forEach(function (part) {
            var chunk = part.trim();
            if (!chunk) {
                return;
            }
            if (chunk.indexOf('-') !== -1) {
                var bounds = chunk.split('-', 2);
                var start = parseInt(bounds[0], 10);
                var end = parseInt(bounds[1], 10);
                if (start > 0 && end >= start) {
                    for (var i = start; i <= end; i++) {
                        ids.push(i);
                    }
                }
                return;
            }
            var id = parseInt(chunk, 10);
            if (id > 0) {
                ids.push(id);
            }
        });

        var unique = [];
        var map = {};
        ids.forEach(function (id) {
            if (!map[id]) {
                map[id] = true;
                unique.push(id);
            }
        });

        return unique;
    }

    function setProgress(done, total) {
        var percent = total > 0 ? Math.round((done / total) * 100) : 0;
        var bar = document.getElementById('yagpt-progress');
        bar.style.width = percent + '%';
        bar.textContent = percent + '%';
    }

    document.getElementById('yagpt-start').addEventListener('click', function () {
        var ids = parseIds(document.getElementById('yagpt-ids').value);
        var statusEl = document.getElementById('yagpt-status');
        var resultEl = document.getElementById('yagpt-result');
        resultEl.innerHTML = '';

        if (!ids.length) {
            statusEl.className = 'alert alert-warning';
            statusEl.textContent = 'Укажите корректные ID.';
            setProgress(0, 0);
            return;
        }

        var done = 0;
        var total = ids.length;
        statusEl.className = 'alert alert-info';
        statusEl.textContent = 'Запущена обработка ' + total + ' материалов...';
        setProgress(0, total);

        var processNext = function () {
            if (ids.length === 0) {
                statusEl.className = 'alert alert-success';
                statusEl.textContent = 'Обработка завершена.';
                return;
            }

            var currentId = ids.shift();
            var body = 'action=process_single&id=' + encodeURIComponent(currentId) + '&user_hash=' + encodeURIComponent('<?=htmlspecialchars($userHash, ENT_QUOTES, 'UTF-8');?>');

            fetch(location.pathname + location.search, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8',
                    'X-Requested-With': 'XMLHttpRequest'
                },
                body: body
            }).then(function (response) {
                return response.json().then(function (json) {
                    return {ok: response.ok, json: json};
                });
            }).then(function (result) {
                done++;
                setProgress(done, total);

                var item = document.createElement('li');
                if (result.ok && result.json.success) {
                    item.textContent = '#' + currentId + ' — успешно';
                } else {
                    var error = (result.json && result.json.error) ? result.json.error : 'Неизвестная ошибка';
                    item.textContent = '#' + currentId + ' — ошибка: ' + error;
                    item.style.color = '#d9534f';
                }
                resultEl.appendChild(item);

                statusEl.textContent = 'Обработано ' + done + ' из ' + total;
                processNext();
            }).catch(function (error) {
                done++;
                setProgress(done, total);
                var item = document.createElement('li');
                item.textContent = '#' + currentId + ' — ошибка сети: ' + error;
                item.style.color = '#d9534f';
                resultEl.appendChild(item);
                statusEl.className = 'alert alert-warning';
                statusEl.textContent = 'Часть запросов завершилась с ошибками. Обработано ' + done + ' из ' + total;
                processNext();
            });
        };

        processNext();
    });
})();
</script>
