<?php

class YandexGptRewrite
{
    private const API_URL = 'https://llm.api.cloud.yandex.net/foundationModels/v1/completion';

    private array $config;
    private ?\PDO $pdo;

    public function __construct(array $config, ?\PDO $pdo = null)
    {
        $this->config = $config;
        $this->pdo = $pdo;
    }

    public function processArticle(array $article): array
    {
        $variables = $this->buildVariables($article);

        $result = [
            'title' => $this->rewriteOrGenerate('title', $article['title'] ?? '', $variables),
            'short_story' => $this->rewriteOrGenerate('short_story', $article['short_story'] ?? '', $variables),
            'full_story' => $this->rewriteOrGenerate('full_story', $article['full_story'] ?? '', $variables),
            'meta_description' => $this->generateMetaDescription($article, $variables),
            'keywords' => $this->generateKeywords($article, $variables),
            'xfields' => $this->rewriteXfields($article, $variables),
            'used_variables' => array_keys($variables),
        ];

        return $result;
    }

    public function processByIds(array $ids): array
    {
        if ($this->pdo === null || $ids === []) {
            return [];
        }

        $in = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $this->pdo->prepare("SELECT id, title, short_story, full_story, xfields, disable_index, category FROM dle_post WHERE id IN ($in)");
        $stmt->execute($ids);

        $processed = [];
        while ($article = $stmt->fetch(\PDO::FETCH_ASSOC)) {
            $result = $this->processArticle($article);
            $this->persistResult((int)$article['id'], $article, $result, (int)($article['disable_index'] ?? 0));
            $processed[] = ['id' => (int)$article['id'], 'title' => $article['title'], 'status' => 'done'];
        }

        return $processed;
    }

    public function processCronBatch(): array
    {
        if ($this->pdo === null || empty($this->config['cron']['enabled'])) {
            return [];
        }

        $limit = (int)($this->config['cron']['limit'] ?? 20);
        $conditions = ['approve = 1'];
        $params = [];

        if (!empty($this->config['cron']['only_disable_index'])) {
            $conditions[] = 'disable_index = 1';
        }

        if (!empty($this->config['cron']['category_ids'])) {
            $categoryIds = array_map('intval', (array)$this->config['cron']['category_ids']);
            $conditions[] = 'category IN (' . implode(',', $categoryIds) . ')';
        }

        $sql = 'SELECT id, title, short_story, full_story, xfields, disable_index, category FROM dle_post WHERE ' . implode(' AND ', $conditions) . ' ORDER BY id ASC LIMIT ' . $limit;
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        $items = [];
        while ($article = $stmt->fetch(\PDO::FETCH_ASSOC)) {
            try {
                $result = $this->processArticle($article);
                $this->persistResult((int)$article['id'], $article, $result, (int)($article['disable_index'] ?? 0));
                $items[] = ['id' => (int)$article['id'], 'status' => 'done'];
            } catch (\Throwable $e) {
                $items[] = ['id' => (int)$article['id'], 'status' => 'error', 'error' => $e->getMessage()];
            }
        }

        return $items;
    }

    public function processSingleById(int $id): array
    {
        if ($this->pdo === null || $id <= 0) {
            throw new \InvalidArgumentException('Некорректный ID новости.');
        }

        $stmt = $this->pdo->prepare('SELECT id, title, short_story, full_story, xfields, disable_index, category FROM dle_post WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $id]);
        $article = $stmt->fetch(\PDO::FETCH_ASSOC);

        if (!$article) {
            throw new \RuntimeException('Новость не найдена.');
        }

        $result = $this->processArticle($article);
        $this->persistResult((int)$article['id'], $article, $result, (int)($article['disable_index'] ?? 0));

        return ['id' => (int)$article['id'], 'title' => (string)$article['title'], 'status' => 'done'];
    }

    public function getLogs(int $limit = 50): array
    {
        if ($this->pdo === null) {
            return [];
        }

        $limit = max(1, min(200, $limit));
        $sql = 'SELECT l.id, l.post_id, l.processed_at, p.title FROM dle_yagpt_log l LEFT JOIN dle_post p ON p.id = l.post_id ORDER BY l.id DESC LIMIT ' . $limit;
        $stmt = $this->pdo->query($sql);

        return $stmt ? $stmt->fetchAll(\PDO::FETCH_ASSOC) : [];
    }

    public function getLogById(int $id): ?array
    {
        if ($this->pdo === null || $id <= 0) {
            return null;
        }

        $stmt = $this->pdo->prepare('SELECT * FROM dle_yagpt_log WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $id]);
        $log = $stmt->fetch(\PDO::FETCH_ASSOC);

        if (!$log) {
            return null;
        }

        $log['before_payload'] = json_decode((string)($log['before_payload'] ?? '{}'), true) ?: [];
        $log['payload'] = json_decode((string)($log['payload'] ?? '{}'), true) ?: [];
        $log['used_variables'] = json_decode((string)($log['used_variables'] ?? '[]'), true) ?: [];

        return $log;
    }

    private function rewriteOrGenerate(string $field, string $source, array $variables): string
    {
        $wordCount = str_word_count(strip_tags($source));
        $action = $wordCount >= (int)$this->config['rewrite_min_words'] ? 'rewrite' : 'generate';
        $promptKey = sprintf('%s_%s', $field, $action);
        $prompt = $this->interpolate($this->config['prompts'][$promptKey] ?? '', $variables);

        return $this->sendPrompt($prompt);
    }

    private function rewriteXfields(array $article, array $variables): array
    {
        $sourceXFields = $this->parseXFields($article['xfields'] ?? '');
        $result = $sourceXFields;

        foreach (($this->config['xfield_prompts'] ?? []) as $fieldName => $promptTemplate) {
            $prompt = $this->interpolate($promptTemplate, $variables);
            $result[$fieldName] = $this->sendPrompt($prompt);
        }

        return $result;
    }

    private function generateMetaDescription(array $article, array $variables): string
    {
        $variables['seo_source'] = $this->resolveSeoSource($article);
        $prompt = $this->interpolate($this->config['prompts']['meta_description'] ?? '', $variables);

        return $this->sendPrompt($prompt);
    }

    private function generateKeywords(array $article, array $variables): string
    {
        $variables['seo_source'] = $this->resolveSeoSource($article);
        $prompt = $this->interpolate($this->config['prompts']['keywords'] ?? '', $variables);

        return $this->sendPrompt($prompt);
    }

    private function resolveSeoSource(array $article): string
    {
        return trim((string)($article['full_story'] ?: $article['short_story'] ?: $article['title'] ?: ''));
    }

    private function sendPrompt(string $prompt): string
    {
        if (!$this->config['enabled'] || empty($this->config['api_key']) || empty($this->config['catalog_id'])) {
            throw new \RuntimeException('YandexGPT не настроен: заполните api_key и catalog_id.');
        }

        $catalogId = $this->config['catalog_id'];
        $modelUri = str_replace('{catalog_id}', $catalogId, $this->config['model_uri']);

        $payload = [
            'modelUri' => $modelUri,
            'completionOptions' => [
                'stream' => false,
                'temperature' => (float)$this->config['temperature'],
                'maxTokens' => (int)$this->config['max_tokens'],
            ],
            'messages' => [
                ['role' => 'system', 'text' => 'Ты SEO-редактор для DataLife Engine. Выводи только финальный результат без пояснений.'],
                ['role' => 'user', 'text' => trim($prompt)],
            ],
        ];

        $attempts = max(1, (int)($this->config['retry_attempts'] ?? 1));
        $delay = (int)($this->config['retry_delay_ms'] ?? 800);
        $timeout = (int)($this->config['timeout'] ?? 30);

        $lastError = 'Unknown API error';
        for ($i = 1; $i <= $attempts; $i++) {
            $ch = curl_init(self::API_URL);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST => true,
                CURLOPT_HTTPHEADER => [
                    'Authorization: Api-Key ' . $this->config['api_key'],
                    'Content-Type: application/json',
                    'x-folder-id: ' . $catalogId,
                ],
                CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE),
                CURLOPT_TIMEOUT => $timeout,
            ]);

            $response = curl_exec($ch);
            $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlError = curl_error($ch);
            curl_close($ch);

            if ($curlError !== '') {
                $lastError = $curlError;
            } elseif ($httpCode >= 200 && $httpCode < 300) {
                return $this->extractText((string)$response);
            } else {
                $lastError = 'HTTP ' . $httpCode . ': ' . $response;
            }

            if ($i < $attempts) {
                usleep($delay * 1000);
            }
        }

        throw new \RuntimeException('Ошибка YandexGPT API: ' . $lastError);
    }

    private function extractText(string $response): string
    {
        $data = json_decode($response, true);
        return trim((string)($data['result']['alternatives'][0]['message']['text'] ?? ''));
    }

    private function buildVariables(array $article): array
    {
        $variables = [
            'title' => (string)($article['title'] ?? ''),
            'short_story' => (string)($article['short_story'] ?? ''),
            'full_story' => (string)($article['full_story'] ?? ''),
            'keyword_count' => (string)($this->config['keyword_count'] ?? 12),
        ];

        $xfields = $this->parseXFields($article['xfields'] ?? '');
        foreach ($xfields as $name => $value) {
            $variables['xfield_' . $name] = $value;
        }

        $variables['xfields_context'] = json_encode($xfields, JSON_UNESCAPED_UNICODE);

        return $variables;
    }

    private function parseXFields($raw): array
    {
        if (is_array($raw)) {
            return $raw;
        }

        $raw = (string)$raw;

        if ($raw === '') {
            return [];
        }

        if ($raw[0] === '{' || $raw[0] === '[') {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                $result = [];
                foreach ($decoded as $name => $value) {
                    $name = trim((string)$name);
                    if ($name === '') {
                        continue;
                    }

                    $result[$name] = is_scalar($value) || $value === null ? (string)$value : json_encode($value, JSON_UNESCAPED_UNICODE);
                }

                return $result;
            }
        }

        $rows = explode('||', $raw);
        $result = [];
        foreach ($rows as $row) {
            if ($row === '') {
                continue;
            }
            [$name, $value] = array_pad(explode('|', $row, 2), 2, '');
            if ($name !== '') {
                $result[$name] = $value;
            }
        }

        return $result;
    }

    private function compileXFields(array $xfields): string
    {
        $out = [];
        foreach ($xfields as $name => $value) {
            $out[] = $name . '|' . $value;
        }

        return implode('||', $out);
    }

    private function persistResult(int $id, array $before, array $result, int $disableIndex): void
    {
        if ($this->pdo === null) {
            return;
        }

        $sql = 'UPDATE dle_post SET title = :title, short_story = :short_story, full_story = :full_story, metatitle = :metatitle, keywords = :keywords, xfields = :xfields, disable_index = :disable_index WHERE id = :id';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            ':title' => $result['title'],
            ':short_story' => $result['short_story'],
            ':full_story' => $result['full_story'],
            ':metatitle' => $result['meta_description'],
            ':keywords' => $result['keywords'],
            ':xfields' => $this->compileXFields($result['xfields']),
            ':disable_index' => ($disableIndex === 1 && !empty($this->config['cron']['only_disable_index'])) ? 0 : $disableIndex,
            ':id' => $id,
        ]);

        $this->writeLog($id, $before, $result);
    }

    private function writeLog(int $id, array $before, array $result): void
    {
        if ($this->pdo === null) {
            return;
        }

        $sql = 'INSERT INTO dle_yagpt_log (post_id, processed_at, used_variables, before_payload, payload) VALUES (:post_id, NOW(), :used_variables, :before_payload, :payload)';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            ':post_id' => $id,
            ':used_variables' => json_encode($result['used_variables'], JSON_UNESCAPED_UNICODE),
            ':before_payload' => json_encode([
                'title' => (string)($before['title'] ?? ''),
                'short_story' => (string)($before['short_story'] ?? ''),
                'full_story' => (string)($before['full_story'] ?? ''),
                'xfields' => (string)($before['xfields'] ?? ''),
            ], JSON_UNESCAPED_UNICODE),
            ':payload' => json_encode($result, JSON_UNESCAPED_UNICODE),
        ]);
    }

    private function interpolate(string $template, array $variables): string
    {
        return preg_replace_callback('/\{([a-zA-Z0-9_]+)\}/', static function (array $matches) use ($variables) {
            return (string)($variables[$matches[1]] ?? '');
        }, $template) ?? $template;
    }
}
