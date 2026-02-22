<?php
declare(strict_types=1);

require_once __DIR__ . '/TempPath.php';

final class ProcessStatusStore
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
        return TempPath::baseDir() . DIRECTORY_SEPARATOR . 'video_tool_status_' . $jobId . '.json';
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

    public function setzeFortschritt(int $prozent, string $meldung, string $status = 'running'): void
    {
        $prozent = max(0, min(100, $prozent));
        $meldung = trim($meldung);
        if ($meldung === '') {
            $meldung = 'Processing...';
        }

        $daten = [
            'job_id' => $this->jobId,
            'status' => $status,
            'prozent' => $prozent,
            'meldung' => $meldung,
            'updated_at' => time(),
        ];

        @file_put_contents($this->dateiPfad, json_encode($daten, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), LOCK_EX);
    }

    public function setzeFehler(string $meldung): void
    {
        $meldung = trim($meldung);
        if ($meldung === '') {
            $meldung = 'Unknown error.';
        }
        $this->setzeFortschritt(100, $meldung, 'error');
    }

    public function setzeAbgeschlossen(string $meldung = 'Download completed.'): void
    {
        $this->setzeFortschritt(100, $meldung, 'done');
    }

    public function loesche(): void
    {
        @unlink($this->dateiPfad);
    }
}
