# YandexGPT Rewrite DLE

Модуль для DataLife Engine, повторяющий концепцию ChatGPT Rewrite DLE, но с интеграцией YandexGPT (Yandex Cloud Foundation Models API) с авторизацией через OAuth-токен и Folder ID.

## Что реализовано

- Рерайт и генерация `short_story` / `full_story` по правилу `>= 5 слов = рерайт`, иначе генерация.
- Рерайт заголовка (`title`) и SEO-поля (`meta description`, `keywords`).
- Поддержка X-Fields:
  - переменные `{xfield_имя}` в промптах;
  - индивидуальные промпты для любого дополнительного поля.
- Полноценная админ-страница настроек в стиле DLE (с сохранением в `engine/data/yandexgpt_rewrite.php`).
- Массовая обработка по ID/диапазонам (`1-20,25,30-35`) через `admin.php` c JS-прогресс-баром.
- CRON-режим (`cron.php`) с лимитами, категориями и опцией обработки `disable_index = 1`.
- Retry-логика при ошибках API.
- Логирование результатов в таблицу `dle_yagpt_log` с хранением `до/после` для diff-отчётов.
- Детальная страница отчётов (до/после) в админке.
- RBAC и CSRF-проверки для admin/ajax действий.

## Структура

- `upload/engine/modules/yandexgpt_rewrite/class.yandexgpt.php` — ядро интеграции.
- `upload/engine/modules/yandexgpt_rewrite/admin.php` — обработка в админке.
- `upload/engine/modules/yandexgpt_rewrite/ajax.php` — AJAX-хендлер для `addnews/editnews` AI-кнопок.
- `upload/engine/modules/yandexgpt_rewrite/cron.php` — фоновый запуск.
- `upload/engine/modules/yandexgpt_rewrite/install.sql` — SQL-схема для логов.
- `upload/engine/data/yandexgpt_rewrite.php` — конфиг.

## Настройка

1. Скопируйте папку `upload` в корень DLE-проекта.
2. Выполните SQL из `install.sql`.
3. В `engine/data/yandexgpt_rewrite.php` заполните:
   - `oauth_token` — OAuth-токен Yandex Cloud (Bearer);
   - `folder_id` — Folder ID в Yandex Cloud;
   - при необходимости `model_uri`, `temperature`, `max_tokens`, `retry_*`.
4. Подключите модуль в админ-панели DLE (меню/роутинг) и добавьте AI-кнопки в шаблоны `addnews/editnews` на `ajax.php`.

## Пример CRON

```bash
*/10 * * * * /usr/bin/php /path/to/dle/engine/modules/yandexgpt_rewrite/cron.php >> /var/log/yagpt_cron.log 2>&1
```

## Миграция

После обновления выполните SQL-миграцию (добавление `before_payload` в `dle_yagpt_log`) из `install.sql`, если таблица уже была создана в старой версии модуля.
