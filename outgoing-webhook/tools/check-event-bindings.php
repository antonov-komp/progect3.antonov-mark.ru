<?php
declare(strict_types=1);

/**
 * Проверка подписок на исходящие события в Bitrix24 (event.get).
 * Показывает, на какой URL Bitrix24 отправляет события и какие события подписаны.
 *
 * Запуск: php outgoing-webhook/tools/check-event-bindings.php
 * Или в браузере: https://ваш-домен/outgoing-webhook/tools/check-event-bindings.php
 */
require_once __DIR__ . '/../bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/crest.php';
require_once dirname(__DIR__, 2) . '/app/Services/Bitrix24Client.php';

header('Content-Type: text/plain; charset=utf-8');

$client = new Bitrix24Client();
$result = $client->call('event.get', []);

if (!empty($result['error'])) {
    echo "Ошибка Bitrix24 REST: " . ($result['error_description'] ?? $result['error']) . "\n";
    echo "Проверьте B24_WEBHOOK_BASE в config.local.php (входящий вебхук с правами event).\n";
    exit(1);
}

$bindings = $result['result'] ?? [];
if (!is_array($bindings)) {
    echo "Непредвиденный ответ event.get\n";
    exit(1);
}

$expectedUrl = 'progect3.antonov-mark.ru/outgoing-webhook';
$expectedEvents = ['ONTASKADD', 'ONTASKUPDATE', 'ONTASKDELETE', 'ONTASKCOMMENTADD', 'ONCRMDEALUPDATE', 'ONCRMDEALADD'];

echo "=== Подписки на исходящие события в Bitrix24 (event.get) ===\n\n";
echo "Ожидаемый URL приложения: содержит \"{$expectedUrl}\"\n\n";

if (empty($bindings)) {
    echo "⚠ Зарегистрированных обработчиков событий НЕТ.\n";
    echo "Bitrix24 не будет отправлять события на этот или любой другой URL.\n";
    echo "\nЧто сделать: в Bitrix24 создать исходящий вебхук (Outgoing webhook)\n";
    echo "и указать URL: https://progect3.antonov-mark.ru/outgoing-webhook/index.php\n";
    echo "и подписать нужные события (ONTASKADD, ONTASKUPDATE, ONCRMDEALUPDATE и т.д.).\n";
    exit(0);
}

// Группируем по URL
$byUrl = [];
foreach ($bindings as $item) {
    if (!is_array($item)) {
        continue;
    }
    $event = $item['event'] ?? $item['EVENT'] ?? '?';
    $handler = $item['handler'] ?? $item['HANDLER'] ?? $item['url'] ?? '?';
    if (is_array($handler)) {
        $handler = $handler['url'] ?? json_encode($handler);
    }
    $byUrl[$handler] = $byUrl[$handler] ?? [];
    $byUrl[$handler][] = $event;
}

$foundOurUrl = false;
foreach ($byUrl as $url => $events) {
    $isOur = (stripos($url, $expectedUrl) !== false);
    if ($isOur) {
        $foundOurUrl = true;
    }
    echo "URL: " . $url . ($isOur ? "  <-- наш сервер" : "") . "\n";
    echo "События (" . count($events) . "): " . implode(', ', array_slice($events, 0, 25));
    if (count($events) > 25) {
        echo " ... и ещё " . (count($events) - 25);
    }
    echo "\n";
    $missing = array_diff($expectedEvents, $events);
    if ($isOur && !empty($missing)) {
        echo "  Не хватает для задач/сделок: " . implode(', ', $missing) . "\n";
    }
    echo "\n";
}

if (!$foundOurUrl) {
    echo "⚠ URL вашего приложения (содержащий \"{$expectedUrl}\") НЕ найден среди обработчиков.\n";
    echo "Исходящие события от Bitrix24 уходят на другой адрес или не настроены.\n";
    echo "Добавьте исходящий вебхук в Bitrix24 с URL:\n";
    echo "  https://progect3.antonov-mark.ru/outgoing-webhook/index.php\n";
    echo "и токеном из OUTGOING_WEBHOOK_TOKEN в config.local.php.\n";
}
