<?php
declare(strict_types=1);

require_once __DIR__ . '/../services/bootstrap.php';

function assertSameValue($expected, $actual, string $message): void
{
    if ($expected !== $actual) {
        throw new RuntimeException($message . ' Expected=' . var_export($expected, true) . ' Actual=' . var_export($actual, true));
    }
}

function assertTrueValue(bool $value, string $message): void
{
    if ($value !== true) {
        throw new RuntimeException($message);
    }
}

$config = new ConfigService();
$filesystem = new FilesystemService();
$request = new RequestService($config);
$formatter = new LogValueFormatter();
$errors = new ErrorService($filesystem, $request);
$identity = new EntityIdentityService($request);
$taskDetails = new TaskDetailsService($filesystem, $request, $formatter);
$taskFiles = new TaskFilesService($errors);
$dealFiles = new DealFileService($filesystem, $request, $taskFiles, $errors);
$commentDetails = new CommentDetailsService(
    null,
    $errors,
    $taskDetails,
    $taskFiles,
    $dealFiles,
    $identity,
    $request,
    $formatter,
    $filesystem,
    $config
);

assertSameValue('****5678', $formatter->maskValue('12345678'), 'maskValue keeps last 4');
assertSameValue('unknown', $formatter->normalize(null), 'normalize handles null');
assertSameValue('a b', $formatter->normalize('  a   b  '), 'normalize collapses spaces');

$maskedPayload = $formatter->maskPayload(['token' => 'abcdef', 'nested' => ['token' => 'xyz123']]);
assertSameValue('****cdef', $maskedPayload['token'], 'maskPayload masks root token');
assertSameValue('****z123', $maskedPayload['nested']['token'], 'maskPayload masks nested token');

assertSameValue('ONTASK', $request->normalizeEventType(' on task '), 'normalizeEventType trims and uppercases');
assertSameValue('UNKNOWN', $request->normalizeEventType(''), 'normalizeEventType handles empty');

$firstValue = $request->getFirstValue(['a' => '', 'b' => ['id' => 7]], [['b', 'id'], 'a']);
assertSameValue(7, $firstValue, 'getFirstValue finds nested value');

$requestId = $request->generateRequestId();
assertTrueValue((bool) preg_match('/^[a-f0-9]{32}$/', $requestId), 'generateRequestId returns hex');

$payload = ['data' => ['FIELDS_AFTER' => ['TASK_ID' => 42]]];
assertSameValue('42', $identity->extractEntityId($payload), 'extractEntityId reads TASK_ID');
assertSameValue(null, $identity->normalizeEntityId('0'), 'normalizeEntityId filters zero');

$crmLinks = ['D_12', '/crm/deal/details/34/', 'D_12'];
assertSameValue(['12', '34'], $taskDetails->extractDealIds($crmLinks), 'extractDealIds unique');

assertSameValue('system', $commentDetails->resolveKind('0'), 'resolveKind system');
assertSameValue('user', $commentDetails->resolveKind('15'), 'resolveKind user');

echo "OK\n";
