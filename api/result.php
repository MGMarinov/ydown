<?php
declare(strict_types=1);

require __DIR__ . '/../lib/ProcessStatusStore.php';
require __DIR__ . '/../lib/WorkerJobStore.php';

function sendeFehlerAntwort(int $httpCode, string $meldung): void
{
    while (ob_get_level() > 0) {
        ob_end_clean();
    }

    http_response_code($httpCode);
    header('Content-Type: text/plain; charset=utf-8');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    echo trim($meldung) !== '' ? trim($meldung) : 'Download file is not available.';
    exit;
}

$jobId = trim((string) ($_GET['job'] ?? ''));
if ($jobId === '') {
    sendeFehlerAntwort(400, 'Missing job ID.');
}

try {
    $jobStore = new WorkerJobStore($jobId);
    $statusStore = new ProcessStatusStore($jobId);
    $jobData = $jobStore->lese();

    if (!is_array($jobData)) {
        throw new RuntimeException('Download job was not found.');
    }

    $status = (string) ($jobData['status'] ?? '');
    if ($status !== 'done') {
        if ($status === 'error') {
            $meldung = trim((string) ($jobData['error'] ?? 'Download failed.'));
            throw new RuntimeException($meldung !== '' ? $meldung : 'Download failed.');
        }
        throw new RuntimeException('Download is still in progress. Please wait.');
    }

    $result = $jobData['result'] ?? null;
    if (!is_array($result)) {
        throw new RuntimeException('Download result payload is missing.');
    }

    $dateiPfad = trim((string) ($result['datei_pfad'] ?? ''));
    $dateiname = trim((string) ($result['dateiname'] ?? ''));
    $contentType = trim((string) ($result['content_type'] ?? 'application/octet-stream'));

    if ($dateiPfad === '' || !is_file($dateiPfad)) {
        throw new RuntimeException('Result file is missing.');
    }
    if ($dateiname === '') {
        $dateiname = basename($dateiPfad);
    }

    $statusStore->setzeFortschritt(98, 'Sending file to browser...');

    while (ob_get_level() > 0) {
        ob_end_clean();
    }

    header('Content-Description: File Transfer');
    header('Content-Type: ' . $contentType);
    header('Content-Disposition: attachment; filename="' . str_replace('"', '', $dateiname) . '"');
    header('Content-Length: ' . (string) filesize($dateiPfad));
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');

    readfile($dateiPfad);

    $statusStore->setzeAbgeschlossen('Download completed.');
    $jobStore->setzeHeruntergeladen();
    @unlink($dateiPfad);

    $basisVerzeichnis = basename((string) dirname($dateiPfad));
    if (str_starts_with($basisVerzeichnis, 'video_tool_yt_')) {
        @rmdir((string) dirname($dateiPfad));
    }

    $jobStore->loesche();
    $statusStore->loesche();
    exit;
} catch (Throwable $exception) {
    sendeFehlerAntwort(404, $exception->getMessage());
}
