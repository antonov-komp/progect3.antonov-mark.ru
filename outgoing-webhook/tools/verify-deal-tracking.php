<?php
declare(strict_types=1);

/**
 * Проверка трекинга сделок: конфиг, БД, тестовые события, entity_states / entity_field_changes.
 *
 * Использование:
 *   php verify-deal-tracking.php [--deal-id=ID] [--endpoint=URL] [--skip-http]
 *
 * --deal-id    ID реальной сделки в Bitrix24 (по умолчанию 13177)
 * --endpoint   URL вебхука (по умолчанию из OUTGOING_WEBHOOK_URL или localhost)
 * --skip-http  Не слать HTTP-запросы, только конфиг и БД
 */

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../services/bootstrap.php';

$options = getopt('', ['deal-id::', 'endpoint::', 'skip-http']);
$dealId = isset($options['deal-id']) ? trim((string) $options['deal-id']) : '13177';
$skipHttp = array_key_exists('skip-http', $options);
$endpoint = isset($options['endpoint']) ? trim((string) $options['endpoint']) : null;

if ($endpoint === null || $endpoint === '') {
    $endpoint = getenv('OUTGOING_WEBHOOK_URL') ?: 'http://127.0.0.1/outgoing-webhook/index.php';
}

$ok = 0;
$fail = 0;

function pass(string $msg): void
{
    global $ok;
    $ok++;
    echo "  ✓ " . $msg . "\n";
}

function fail(string $msg): void
{
    global $fail;
    $fail++;
    echo "  ✗ " . $msg . "\n";
}

function postWebhook(string $url, array $payload, int $timeout = 15): array
{
    $body = json_encode($payload, JSON_UNESCAPED_SLASHES);
    if ($body === false) {
        return ['status' => 0, 'error' => 'json_encode failed'];
    }
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_TIMEOUT => $timeout,
        ]);
        $response = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        return ['status' => $status, 'error' => $err !== '' ? $err : null, 'body' => is_string($response) ? $response : null];
    }
    $ctx = stream_context_create([
        'http' => [
            'method' => 'POST',
            'header' => "Content-Type: application/json\r\n",
            'content' => $body,
            'timeout' => $timeout,
        ],
    ]);
    $response = @file_get_contents($url, false, $ctx);
    $status = 0;
    if (is_array($http_response_header ?? null)) {
        foreach ($http_response_header as $h) {
            if (preg_match('/^HTTP\/\d+\.\d+\s+(\d+)/', $h, $m)) {
                $status = (int) $m[1];
                break;
            }
        }
    }
    return ['status' => $status, 'error' => $response === false ? 'request_failed' : null, 'body' => is_string($response) ? $response : null];
}

echo "=== Проверка трекинга сделок ===\n\n";

// 1. Конфиг
echo "1. Конфиг\n";
$token = outgoingWebhookGetSetting('OUTGOING_WEBHOOK_TOKEN');
if ($token !== null && $token !== '') {
    pass('OUTGOING_WEBHOOK_TOKEN задан');
} else {
    fail('OUTGOING_WEBHOOK_TOKEN не задан в config.local.php');
}

$dbType = outgoingWebhookGetSetting('DATABASE_TYPE', '');
if (strtolower($dbType) === 'sqlite') {
    pass('DATABASE_TYPE=sqlite');
} else {
    fail('DATABASE_TYPE не sqlite (сейчас: ' . ($dbType ?: 'не задан') . '), снимки/изменения в БД не используются');
}

$dbPath = outgoingWebhookGetSetting('DATABASE_PATH', __DIR__ . '/../database/events.db');
if ($dbPath !== '' && file_exists($dbPath)) {
    pass('Файл БД существует: ' . $dbPath);
} else {
    fail('Файл БД не найден: ' . $dbPath . '. Запустите init-database.php.');
}

echo "\n2. Таблицы БД\n";
try {
    $pdo = new PDO('sqlite:' . $dbPath);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $tables = $pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name IN ('entity_states','entity_field_changes','deal_details')")->fetchAll(PDO::FETCH_COLUMN);
    if (in_array('entity_states', $tables, true)) {
        pass('Таблица entity_states есть');
    } else {
        fail('Таблица entity_states отсутствует. Запустите init-database.php.');
    }
    if (in_array('entity_field_changes', $tables, true)) {
        pass('Таблица entity_field_changes есть');
    } else {
        fail('Таблица entity_field_changes отсутствует. Запустите init-database.php.');
    }
    if (in_array('deal_details', $tables, true)) {
        pass('Таблица deal_details есть');
    } else {
        fail('Таблица deal_details отсутствует. Запустите init-database.php.');
    }
} catch (Throwable $e) {
    fail('Ошибка подключения к БД: ' . $e->getMessage());
}

if ($skipHttp) {
    echo "\n(пропуск HTTP-проверок по --skip-http)\n";
    echo "\nИтого: $ok ок, $fail ошибок\n";
    exit($fail > 0 ? 1 : 0);
}

// 3. HTTP: ONCRMDEALADD, ONCRMDEALUPDATE
echo "\n3. Тестовые события (endpoint: $endpoint, deal-id: $dealId)\n";

$payload = [
    'event' => 'ONCRMDEALADD',
    'event_handler_id' => 'verify-handler',
    'token' => $token,
    'auth' => ['application_token' => $token, 'member_id' => 'verify-member'],
    'data' => ['FIELDS' => ['ID' => $dealId]],
];
$r = postWebhook($endpoint, $payload);
if ($r['status'] === 200) {
    pass('ONCRMDEALADD -> 200');
} else {
    fail('ONCRMDEALADD -> ' . $r['status'] . ($r['error'] ? ' ' . $r['error'] : ''));
}

$payload['event'] = 'ONCRMDEALUPDATE';
$r = postWebhook($endpoint, $payload);
if ($r['status'] === 200) {
    pass('ONCRMDEALUPDATE -> 200');
} else {
    fail('ONCRMDEALUPDATE -> ' . $r['status'] . ($r['error'] ? ' ' . $r['error'] : ''));
}

if ($r['status'] === 404) {
    echo "    Подсказка: 404 — неверный URL. Используйте --endpoint=https://ваш-домен/outgoing-webhook/index.php (без /public/).\n";
}
if ($r['status'] === 403) {
    echo "    Подсказка: 403 — токен или IP. Проверьте OUTGOING_WEBHOOK_TOKEN и OUTGOING_WEBHOOK_ALLOWED_IPS.\n";
}

// 4. Записи в entity_states
echo "\n4. Записи в entity_states\n";
try {
    $stmt = $pdo->prepare("SELECT entity_id, updated_at FROM entity_states WHERE entity_type = 'deal' AND entity_id = ?");
    $stmt->execute([$dealId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($row) {
        pass("Снимок сделки $dealId в БД (updated_at: {$row['updated_at']})");
    } else {
        fail("Снимок сделки $dealId в entity_states не найден. Проверьте DealEventHandler и StateStorage.");
    }
} catch (Throwable $e) {
    fail('Ошибка запроса: ' . $e->getMessage());
}

// 5. entity_field_changes (может быть 0, если поля не менялись)
$stmt = $pdo->query("SELECT COUNT(*) FROM entity_field_changes WHERE entity_type = 'deal'");
$changesCount = (int) $stmt->fetchColumn();
echo "\n5. entity_field_changes\n";
echo "   Записей по сделкам: $changesCount (0 — норма, если между ADD и UPDATE данные не менялись)\n";

// 6. deal_details (дозапрос crm.deal.get, детали по сделке)
echo "\n6. Детали сделок (deal_details)\n";
$stmt = $pdo->prepare("SELECT COUNT(*) FROM deal_details WHERE deal_id = ?");
$stmt->execute([$dealId]);
$detailsCount = (int) $stmt->fetchColumn();
if ($detailsCount >= 1) {
    pass("Записей по сделке $dealId в deal_details: $detailsCount (ожидается ≥1 после ADD/UPDATE)");
} else {
    fail("В deal_details нет записей по сделке $dealId. Проверьте DealDetailsService и crm.deal.get.");
}

echo "\n=== Итого: $ok ок, $fail ошибок ===\n";
exit($fail > 0 ? 1 : 0);
