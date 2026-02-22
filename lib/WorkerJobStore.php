<?php
declare(strict_types=1);

require_once __DIR__ . '/TempPath.php';

final class WorkerJobStore
{
    private string $jobId;
    private string $dateiPfad;

    public function __construct(string $jobId)
    {
        $jobId = trim($jobId);
        if ($jobId === '' || preg_match('~^[a-zA-Z0-9_-]{8,64}$~', $jobId) !== 1) {
            throw new RuntimeException('Invalid job ID.');
        }

        $this->jobId = $jobId;
        $this->dateiPfad = self::baueDateiPfad($jobId);
    }

    public static function baueDateiPfad(string $jobId): string
    {
        return TempPath::baseDir() . DIRECTORY_SEPARATOR . 'video_tool_job_' . $jobId . '.json';
    }

    public function erstelle(array $payload): void
    {
        $daten = [
            'job_id' => $this->jobId,
            'status' => 'queued',
            'error' => '',
            'payload' => $payload,
            'result' => null,
            'created_at' => time(),
            'started_at' => 0,
            'finished_at' => 0,
            'updated_at' => time(),
            'updated_at_ms' => (int) round(microtime(true) * 1000),
            'downloaded_at' => 0,
        ];

        $this->speichere($daten);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function lese(): ?array
    {
        if (!is_file($this->dateiPfad)) {
            return null;
        }

        $inhalt = @file_get_contents($this->dateiPfad);
        if (!is_string($inhalt) || trim($inhalt) === '') {
            return null;
        }

        $daten = json_decode($inhalt, true);
        if (!is_array($daten)) {
            return null;
        }

        return $daten;
    }

    public function setzeLaufend(): void
    {
        $daten = $this->lese();
        if (!is_array($daten)) {
            throw new RuntimeException('Worker job is missing.');
        }

        $daten['status'] = 'running';
        $daten['error'] = '';
        $daten['started_at'] = (int) ($daten['started_at'] ?? 0);
        if ((int) $daten['started_at'] <= 0) {
            $daten['started_at'] = time();
        }

        $this->setzeZeitstempel($daten);
        $this->speichere($daten);
    }

    public function setzeFertig(array $result): void
    {
        $daten = $this->lese();
        if (!is_array($daten)) {
            throw new RuntimeException('Worker job is missing.');
        }

        $daten['status'] = 'done';
        $daten['error'] = '';
        $daten['result'] = $result;
        $daten['finished_at'] = time();
        $this->setzeZeitstempel($daten);
        $this->speichere($daten);
    }

    public function setzeFehler(string $meldung): void
    {
        $daten = $this->lese();
        if (!is_array($daten)) {
            $daten = [
                'job_id' => $this->jobId,
                'payload' => null,
                'result' => null,
                'created_at' => time(),
                'started_at' => 0,
                'finished_at' => 0,
                'downloaded_at' => 0,
            ];
        }

        $daten['status'] = 'error';
        $daten['error'] = trim($meldung) !== '' ? trim($meldung) : 'Unknown worker error.';
        $daten['finished_at'] = time();
        $this->setzeZeitstempel($daten);
        $this->speichere($daten);
    }

    public function setzeHeruntergeladen(): void
    {
        $daten = $this->lese();
        if (!is_array($daten)) {
            return;
        }

        $daten['downloaded_at'] = time();
        $this->setzeZeitstempel($daten);
        $this->speichere($daten);
    }

    public function loesche(): void
    {
        @unlink($this->dateiPfad);
    }

    /**
     * @param array<string, mixed> $daten
     */
    private function speichere(array $daten): void
    {
        $flags = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;
        if (defined('JSON_INVALID_UTF8_SUBSTITUTE')) {
            $flags |= JSON_INVALID_UTF8_SUBSTITUTE;
        }

        $json = json_encode($daten, $flags);
        if (!is_string($json)) {
            throw new RuntimeException('Worker job JSON encoding failed.');
        }

        $ok = @file_put_contents($this->dateiPfad, $json, LOCK_EX);
        if ($ok === false) {
            throw new RuntimeException('Worker job state could not be written.');
        }
    }

    /**
     * @param array<string, mixed> $daten
     */
    private function setzeZeitstempel(array &$daten): void
    {
        $daten['updated_at'] = time();
        $daten['updated_at_ms'] = (int) round(microtime(true) * 1000);
    }
}
