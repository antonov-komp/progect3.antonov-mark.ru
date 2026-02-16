<?php
/**
 * Статус приёма исходящих событий Bitrix24
 * Откройте в браузере: https://ваш-домен/outgoing-webhook/tools/check-events-status.php
 *
 * Показывает: приходят ли события в приложение (по данным БД).
 */
header('Content-Type: text/html; charset=utf-8');

$basePath = dirname(__DIR__, 3);
$dbPath = $basePath . '/outgoing-webhook/database/events.db';

$total = 0;
$lastReceivedAt = null;
$lastEvents = [];
$error = null;

if (file_exists($dbPath)) {
    try {
        $pdo = new PDO('sqlite:' . $dbPath);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $total = (int) $pdo->query('SELECT COUNT(*) FROM events')->fetchColumn();
        $lastEvents = $pdo->query(
            "SELECT id, request_id, event_type, entity_type, entity_id, received_at FROM events ORDER BY received_at DESC LIMIT 10"
        )->fetchAll(PDO::FETCH_ASSOC);
        if (!empty($lastEvents)) {
            $lastReceivedAt = $lastEvents[0]['received_at'];
        }
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
} else {
    $error = 'БД не найдена: ' . $dbPath;
}

$title = 'Исходящие события — статус приёма';
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= htmlspecialchars($title) ?></title>
    <style>
        body { font-family: system-ui, sans-serif; margin: 1rem; max-width: 800px; }
        h1 { font-size: 1.25rem; }
        .ok { color: #0a0; }
        .warn { color: #a60; }
        .err { color: #c00; }
        table { border-collapse: collapse; width: 100%; margin-top: 0.5rem; }
        th, td { border: 1px solid #ccc; padding: 0.35rem 0.5rem; text-align: left; font-size: 0.9rem; }
        th { background: #f5f5f5; }
        .meta { margin: 1rem 0; color: #666; font-size: 0.9rem; }
    </style>
</head>
<body>
<h1><?= htmlspecialchars($title) ?></h1>

<?php if ($error): ?>
    <p class="err">Ошибка: <?= htmlspecialchars($error) ?></p>
    <p>Проверьте путь к БД и права доступа.</p>
    <?php exit; ?>
<?php endif; ?>

<div class="meta">
    <strong>Всего событий в БД:</strong> <?= $total ?>
    <?php if ($lastReceivedAt): ?>
        <br><strong>Последнее событие получено:</strong> <?= htmlspecialchars($lastReceivedAt) ?>
        <?php
        $ts = strtotime($lastReceivedAt);
        $diff = $ts ? (time() - $ts) : 0;
        if ($diff > 86400) {
            $ago = round($diff / 86400) . ' дн. назад';
            $class = 'warn';
        } elseif ($diff > 3600) {
            $ago = round($diff / 3600) . ' ч. назад';
            $class = 'warn';
        } elseif ($diff > 60) {
            $ago = round($diff / 60) . ' мин. назад';
            $class = 'ok';
        } else {
            $ago = 'только что';
            $class = 'ok';
        }
        ?>
        <span class="<?= $class ?>">(<?= $ago ?>)</span>
    <?php endif; ?>
</div>

<?php if ($total > 0): ?>
    <p><strong>Приём событий работает.</strong> Bitrix24 отправляет события на этот сервер, они записываются в БД.</p>
    <p>Последние 10 событий:</p>
    <table>
        <thead>
            <tr>
                <th>ID</th>
                <th>Тип события</th>
                <th>Сущность</th>
                <th>ID сущности</th>
                <th>Получено</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($lastEvents as $row): ?>
                <tr>
                    <td><?= (int) $row['id'] ?></td>
                    <td><code><?= htmlspecialchars($row['event_type'] ?? '') ?></code></td>
                    <td><?= htmlspecialchars($row['entity_type'] ?? '') ?></td>
                    <td><?= htmlspecialchars($row['entity_id'] ?? '') ?></td>
                    <td><?= htmlspecialchars($row['received_at'] ?? '') ?></td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
<?php else: ?>
    <p class="warn">Событий в БД пока нет.</p>
    <p>Возможные причины: исходящий вебхук в Bitrix24 ещё не настроен или не вызывается, либо указан неверный URL/токен. Проверьте настройки исходящего обработчика в Bitrix24 (URL должен вести на <code>/outgoing-webhook/index.php</code>, токен — <code>OUTGOING_WEBHOOK_TOKEN</code> из config).</p>
<?php endif; ?>

<p class="meta" style="margin-top: 1.5rem;">Страница только для проверки; данные только читаются из БД.</p>
</body>
</html>
