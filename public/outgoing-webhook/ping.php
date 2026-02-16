<?php
/**
 * Диагностика: логирует любой пришедший запрос.
 * Откройте в браузере или вызовите curl — в лог запишется факт запроса.
 * Так можно убедиться, что путь /outgoing-webhook/ доступен снаружи.
 *
 * URL: https://ваш-домен/outgoing-webhook/ping.php
 */
header('Content-Type: application/json; charset=utf-8');

$logDir = dirname(__DIR__, 2) . '/outgoing-webhook/logs';
$logFile = $logDir . '/incoming-ping.log';

$entry = [
    'time' => date('Y-m-d H:i:s'),
    'ip' => $_SERVER['REMOTE_ADDR'] ?? '-',
    'method' => $_SERVER['REQUEST_METHOD'] ?? '-',
    'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? '-',
];

if (!is_dir($logDir)) {
    @mkdir($logDir, 0755, true);
}
@file_put_contents($logFile, json_encode($entry, JSON_UNESCAPED_UNICODE) . "\n", FILE_APPEND | LOCK_EX);

http_response_code(200);
echo json_encode(['status' => 'ok', 'message' => 'ping received', 'your_ip' => $entry['ip']], JSON_UNESCAPED_UNICODE);
