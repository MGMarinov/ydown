<?php
declare(strict_types=1);

final class WorkerJobRunner
{
    public function run(string $jobId): void
    {
        $jobId = trim($jobId);
        if ($jobId === '') {
            throw new RuntimeException('Missing worker job ID.');
        }

        $statusStore = new ProcessStatusStore($jobId);
        $jobStore = new WorkerJobStore($jobId);

        try {
            $jobData = $jobStore->lese();
            if (!is_array($jobData)) {
                throw new RuntimeException('Worker job was not found.');
            }

            $payload = $jobData['payload'] ?? null;
            if (!is_array($payload)) {
                throw new RuntimeException('Worker payload is missing.');
            }

            $zielFormat = ((string) ($payload['ziel_format'] ?? 'mp4') === 'mp3') ? 'mp3' : 'mp4';
            $option = $payload['option'] ?? null;
            if (!is_array($option)) {
                throw new RuntimeException('Worker option payload is invalid.');
            }

            $mp3Bitrate = null;
            $bitrateRaw = $payload['mp3_bitrate'] ?? null;
            if (is_int($bitrateRaw)) {
                $mp3Bitrate = $bitrateRaw;
            } elseif (is_numeric($bitrateRaw)) {
                $mp3Bitrate = (int) $bitrateRaw;
            }

            $jobStore->setzeLaufend();
            $statusStore->setzeFortschritt(3, 'Worker is running...');

            $ytDlpTool = new YtDlpTool();
            $downloader = new FileDownloader($ytDlpTool);

            $statusCallback = static function (int $prozent, string $meldung, string $status = 'running') use ($statusStore): void {
                $statusStore->setzeFortschritt($prozent, $meldung, $status);
            };

            $result = $downloader->bereiteDownloadDateiVor($option, $zielFormat, $mp3Bitrate, $statusCallback);
            if (!is_array($result)) {
                throw new RuntimeException('Worker did not produce a valid result.');
            }

            $jobStore->setzeFertig($result);
            $statusStore->setzeAbgeschlossen('File is ready. Download will start now.');
        } catch (Throwable $exception) {
            $meldung = trim($exception->getMessage()) !== '' ? trim($exception->getMessage()) : 'Worker job failed.';
            $statusStore->setzeFehler($meldung);
            try {
                $jobStore->setzeFehler($meldung);
            } catch (Throwable) {
            }

            throw new RuntimeException($meldung, 0, $exception);
        }
    }
}

