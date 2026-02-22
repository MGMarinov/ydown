<?php
declare(strict_types=1);

require __DIR__ . '/../lib/YtDlpTool.php';
require __DIR__ . '/../lib/FileDownloader.php';
require __DIR__ . '/../lib/ProcessStatusStore.php';
require __DIR__ . '/../lib/WorkerJobStore.php';
require __DIR__ . '/../lib/WorkerJobRunner.php';

$jobId = trim((string) ($argv[1] ?? ''));
if ($jobId === '') {
    fwrite(STDERR, "Missing worker job ID.\n");
    exit(1);
}

try {
    $runner = new WorkerJobRunner();
    $runner->run($jobId);
    exit(0);
} catch (Throwable $exception) {
    $meldung = trim($exception->getMessage()) !== '' ? trim($exception->getMessage()) : 'Worker job failed.';
    fwrite(STDERR, $meldung . PHP_EOL);
    exit(1);
}
