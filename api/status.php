<?php
declare(strict_types=1);

require __DIR__ . '/../lib/ProcessStatusStore.php';
require __DIR__ . '/../lib/WorkerJobStore.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

/**
 * @param array<string, mixed> $daten
 */
function sendeStatusJson(array $daten): void
{
    $flags = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;
    if (defined('JSON_INVALID_UTF8_SUBSTITUTE')) {
        $flags |= JSON_INVALID_UTF8_SUBSTITUTE;
    }

    $json = json_encode($daten, $flags);
    if ($json === false) {
        $json = '{"ok":false,"status":"error","meldung":"Internal JSON encoding error."}';
    }

    echo $json;
    exit;
}

$jobId = trim((string) ($_GET['job'] ?? ''));
if ($jobId === '') {
    sendeStatusJson([
        'ok' => false,
        'status' => 'error',
        'meldung' => 'Missing job ID.',
    ]);
}

try {
    $speicher = new ProcessStatusStore($jobId);
    $daten = $speicher->lese();

    if ($daten === null) {
        $jobSpeicher = new WorkerJobStore($jobId);
        $jobDaten = $jobSpeicher->lese();
        if (is_array($jobDaten)) {
            $jobStatus = (string) ($jobDaten['status'] ?? 'queued');
            if ($jobStatus === 'error') {
                sendeStatusJson([
                    'ok' => true,
                    'status' => 'error',
                    'prozent' => 100,
                    'meldung' => (string) ($jobDaten['error'] ?? 'Download failed.'),
                ]);
            }
            if ($jobStatus === 'done') {
                sendeStatusJson([
                    'ok' => true,
                    'status' => 'done',
                    'prozent' => 100,
                    'meldung' => 'File is ready. Download will start now.',
                ]);
            }
            if ($jobStatus === 'running') {
                sendeStatusJson([
                    'ok' => true,
                    'status' => 'running',
                    'prozent' => 3,
                    'meldung' => 'Worker is running...',
                ]);
            }
            sendeStatusJson([
                'ok' => true,
                'status' => 'running',
                'prozent' => 1,
                'meldung' => 'Queued worker job...',
            ]);
        }

        sendeStatusJson([
            'ok' => true,
            'status' => 'idle',
            'prozent' => 0,
            'meldung' => 'No status data available yet.',
        ]);
    }

    sendeStatusJson([
        'ok' => true,
        'status' => (string) ($daten['status'] ?? 'running'),
        'prozent' => (int) ($daten['prozent'] ?? 0),
        'meldung' => (string) ($daten['meldung'] ?? 'Processing...'),
        'updated_at' => (int) ($daten['updated_at'] ?? 0),
    ]);
} catch (Throwable $exception) {
    sendeStatusJson([
        'ok' => false,
        'status' => 'error',
        'meldung' => $exception->getMessage(),
    ]);
}
