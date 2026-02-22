<?php
declare(strict_types=1);

require __DIR__ . '/../../lib/YtDlpTool.php';
require __DIR__ . '/../../lib/FileDownloader.php';
require __DIR__ . '/../../lib/ProcessStatusStore.php';
require __DIR__ . '/../../lib/WorkerJobStore.php';
require __DIR__ . '/../../lib/WorkerJobRunner.php';

header('Content-Type: text/plain; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

$remoteAddr = trim((string) ($_SERVER['REMOTE_ADDR'] ?? ''));
if (!in_array($remoteAddr, ['127.0.0.1', '::1'], true)) {
    http_response_code(403);
    echo 'Forbidden';
    exit;
}

$jobId = trim((string) ($_GET['job'] ?? ''));
$token = trim((string) ($_GET['token'] ?? ''));
if ($jobId === '' || $token === '') {
    http_response_code(400);
    echo 'Missing job parameters.';
    exit;
}

try {
    $jobStore = new WorkerJobStore($jobId);
    $jobData = $jobStore->lese();
    if (!is_array($jobData)) {
        throw new RuntimeException('Worker job was not found.');
    }

    $payload = $jobData['payload'] ?? null;
    if (!is_array($payload)) {
        throw new RuntimeException('Worker payload is missing.');
    }

    $expectedToken = trim((string) ($payload['worker_token'] ?? ''));
    if ($expectedToken === '' || !hash_equals($expectedToken, $token)) {
        throw new RuntimeException('Worker token is invalid.');
    }

    ignore_user_abort(true);
    if (function_exists('set_time_limit')) {
        @set_time_limit(0);
    }

    $runner = new WorkerJobRunner();
    $runner->run($jobId);
    echo 'OK';
} catch (Throwable $exception) {
    http_response_code(500);
    echo $exception->getMessage();
}
