<?php

return [
    'enabled' => true,
    'api_key' => '',
    'catalog_id' => '',
    'model_uri' => 'gpt://{catalog_id}/yandexgpt/latest',
    'temperature' => 0.7,
    'max_tokens' => 900,
    'retry_attempts' => 2,
    'retry_delay_ms' => 900,
    'timeout' => 30,
    'keyword_count' => 12,
    'rewrite_min_words' => 5,
    'prompts' => [
        'short_story_rewrite' => 'Перепиши краткое описание в нейтральном стиле, сохранив факты: {short_story}',
        'short_story_generate' => 'Создай краткое описание новости на основе заголовка "{title}" и контекста: {xfields_context}',
        'full_story_rewrite' => 'Перепиши полный текст для публикации, добавь структуру и подзаголовки: {full_story}',
        'full_story_generate' => 'Создай полный текст по заголовку "{title}" и данным: {xfields_context}',
        'title_rewrite' => 'Предложи новый SEO и CTR-ориентированный заголовок на основе: {title}',
        'meta_description' => 'Сгенерируй meta description до 160 символов на основе: {seo_source}',
        'keywords' => 'Сгенерируй {keyword_count} SEO-ключей через запятую на основе: {seo_source}',
    ],
    'xfield_prompts' => [
        // Пример: 'year' => 'Нормализуй год выпуска: {xfield_year}'
    ],
    'cron' => [
        'enabled' => false,
        'limit' => 20,
        'category_ids' => [],
        'only_disable_index' => false,
    ],
];
