<?php
declare(strict_types=1);

$options = getopt('', ['limit::']);
$limit = isset($options['limit']) ? (int) $options['limit'] : 50;
if ($limit <= 0) {
    $limit = 50;
}

$_GET['limit'] = $limit;

require __DIR__ . '/process-queue.php';
