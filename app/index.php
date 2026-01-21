<?php
session_start();
require_once __DIR__ . '/Services/RequestContextService.php';
$assetBaseUrl = '/vue';
$publicRoot = dirname(__DIR__) . '/public';
$cssFile = $publicRoot . $assetBaseUrl . '/app.css';
$jsFile = $publicRoot . $assetBaseUrl . '/app.js';
$cssVersion = file_exists($cssFile) ? (string) filemtime($cssFile) : '';
$jsVersion = file_exists($jsFile) ? (string) filemtime($jsFile) : '';
$contextService = new RequestContextService($_REQUEST, $_SESSION);
$requestContext = $contextService->getContext();
$isEmbedded = !empty($requestContext['PLACEMENT'])
    || !empty($requestContext['PLACEMENT_OPTIONS'])
    || !empty($requestContext['IFRAME'])
    || !empty($requestContext['B24_FRAME']);

echo '<div id="app" class="app-root">';
echo '<div class="app-loading">Загрузка интерфейса...</div>';
echo '</div>';
echo '<noscript><div class="app-deny">Для работы приложения нужен JavaScript.</div></noscript>';

if (file_exists($cssFile)) {
    $cssQuery = $cssVersion !== '' ? ('?v=' . $cssVersion) : '';
    echo '<link rel="stylesheet" href="' . $assetBaseUrl . '/app.css' . $cssQuery . '">';
}

if (file_exists($jsFile)) {
    echo '<script>window.APP_REQUEST_CONTEXT = ' .
        json_encode($requestContext, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) .
        ';</script>';
    if ($isEmbedded) {
        echo '<script src="//api.bitrix24.com/api/v1/"></script>';
    }
    $jsQuery = $jsVersion !== '' ? ('?v=' . $jsVersion) : '';
    echo '<script type="module" src="' . $assetBaseUrl . '/app.js' . $jsQuery . '"></script>';
} else {
    echo '<div class="app-deny">Файлы интерфейса не найдены. Проверьте сборку фронтенда.</div>';
}