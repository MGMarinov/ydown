<?php
declare(strict_types=1);

require_once __DIR__ . '/TempPath.php';
require_once __DIR__ . '/FfmpegOrchestrator.php';

final class FileDownloader
{
    private ?string $ffmpegPfad = null;
    private YtDlpTool $ytDlpWerkzeug;
    private FfmpegOrchestrator $orchestrator;

    public function __construct(?YtDlpTool $ytDlpWerkzeug = null)
    {
        $this->ytDlpWerkzeug = $ytDlpWerkzeug ?? new YtDlpTool();
        $this->orchestrator = new FfmpegOrchestrator(TempPath::baseDir());
    }

    /**
     * Access the orchestrator for capabilities queries.
     */
    public function getOrchestrator(): FfmpegOrchestrator
    {
        return $this->orchestrator;
    }

    public function istFfmpegVerfuegbar(): bool
    {
        return $this->findeFfmpegPfad() !== null;
    }

    public function istYtDlpVerfuegbar(): bool
    {
        return $this->ytDlpWerkzeug->istVerfuegbar();
    }

    /**
     * @param array<string, mixed> $option
     */
    public function starteDownload(array $option, string $zielFormat, ?int $mp3BitrateKbps = null, ?callable $statusCallback = null): void
    {
        $ergebnis = $this->bereiteDownloadDateiVor($option, $zielFormat, $mp3BitrateKbps, $statusCallback);
        $this->sendeDatei(
            (string) $ergebnis['datei_pfad'],
            (string) $ergebnis['dateiname'],
            (string) $ergebnis['content_type'],
            $statusCallback
        );
    }

    /**
     * @param array<string, mixed> $option
     * @return array{datei_pfad:string, dateiname:string, content_type:string}
     */
    public function bereiteDownloadDateiVor(array $option, string $zielFormat, ?int $mp3BitrateKbps = null, ?callable $statusCallback = null): array
    {
        $zielFormat = strtolower(trim($zielFormat));
        if (!in_array($zielFormat, ['mp4', 'mp3'], true)) {
            throw new RuntimeException('Invalid target format.');
        }
        $this->meldeStatus($statusCallback, 5, 'Checking download settings...');

        $quelleTyp = trim((string) ($option['quelle_typ'] ?? ''));
        if ($quelleTyp === 'youtube') {
            $this->meldeStatus($statusCallback, 7, 'Starting YouTube processing...');
            if (!$this->ytDlpWerkzeug->istVerfuegbar()) {
                throw new RuntimeException('YouTube downloads require yt-dlp. Install yt-dlp or set YTDLP_PATH.');
            }

            $ergebnis = $this->ytDlpWerkzeug->ladeHerunter(
                $option,
                $zielFormat,
                $this->findeFfmpegPfad(),
                $this->normalisiereMp3Bitrate($mp3BitrateKbps),
                $statusCallback
            );

            if ($zielFormat === 'mp3') {
                $zielBitrate = $this->normalisiereMp3Bitrate($mp3BitrateKbps ?? (int) ($option['bitrate_kbps'] ?? 192));
                $kompressionsStufe = max(0, min(9, (int) ($option['compression_level'] ?? 2)));
                $quelleDatei = (string) $ergebnis['datei_pfad'];
                $mp3Datei = $this->konvertiereLokaleAudioDateiZuMp3($quelleDatei, $zielBitrate, $statusCallback, $kompressionsStufe);
                $dateiname = (string) $ergebnis['dateiname'];
                $dateiname = preg_replace('~\.[^.]+$~', '.mp3', $dateiname) ?? 'youtube_video.mp3';
                return [
                    'datei_pfad' => $mp3Datei,
                    'dateiname' => $dateiname,
                    'content_type' => 'audio/mpeg',
                ];
            }

            return $ergebnis;
        }

        $quelleUrl = trim((string) ($option['download_url'] ?? ''));
        if ($quelleUrl === '') {
            throw new RuntimeException('Download URL is missing.');
        }

        $this->validiereUrl($quelleUrl);
        $this->meldeStatus($statusCallback, 10, 'Preparing source...');

        if ($zielFormat === 'mp4') {
            $typ = (string) ($option['typ'] ?? 'direkt_mp4');
            if ($typ === 'direkt_mp4') {
                $this->meldeStatus($statusCallback, 30, 'Downloading video...');
                $tmpDatei = $this->ladeUrlInTempDatei($quelleUrl, 'mp4');
                $dateiname = $this->baueDateiname($option, 'mp4');
                return [
                    'datei_pfad' => $tmpDatei,
                    'dateiname' => $dateiname,
                    'content_type' => 'video/mp4',
                ];
            }

            $ffmpeg = $this->findeFfmpegPfad();
            if ($ffmpeg === null) {
                throw new RuntimeException('ffmpeg is required for HLS -> MP4.');
            }

            $this->meldeStatus($statusCallback, 34, 'Downloading HLS stream...');
            $tmpDatei = $this->erstelleTempDatei('mp4');
            $befehl = $this->orchestrator->buildHlsToMp4Command($ffmpeg, $quelleUrl, $tmpDatei);

            $this->meldeStatus($statusCallback, 68, 'Converting HLS to MP4...');
            $this->fuehreBefehlAus($befehl, $tmpDatei, 'MP4 conversion failed.');
            return [
                'datei_pfad' => $tmpDatei,
                'dateiname' => $this->baueDateiname($option, 'mp4'),
                'content_type' => 'video/mp4',
            ];
        }

        $ffmpeg = $this->findeFfmpegPfad();
        if ($ffmpeg === null) {
            throw new RuntimeException('MP3 requires ffmpeg. Install ffmpeg or set FFMPEG_PATH.');
        }

        $this->meldeStatus($statusCallback, 12, 'Downloading audio source...');
        $zielBitrate = $this->normalisiereMp3Bitrate($mp3BitrateKbps ?? (int) ($option['bitrate_kbps'] ?? 192));
        $kompressionsStufe = max(0, min(9, (int) ($option['compression_level'] ?? 2)));
        $quelleTemp = $this->ladeUrlInTempDatei($quelleUrl, 'audio_src');
        $this->meldeStatus($statusCallback, 22, 'Audio source downloaded.');
        $mp3Datei = $this->konvertiereLokaleAudioDateiZuMp3($quelleTemp, $zielBitrate, $statusCallback, $kompressionsStufe);
        return [
            'datei_pfad' => $mp3Datei,
            'dateiname' => $this->baueDateiname($option, 'mp3'),
            'content_type' => 'audio/mpeg',
        ];
    }

    private function validiereUrl(string $url): void
    {
        $teile = parse_url($url);
        $schema = strtolower((string) ($teile['scheme'] ?? ''));
        if (!in_array($schema, ['http', 'https'], true)) {
            throw new RuntimeException('Download URL is invalid.');
        }
    }

    private function findeFfmpegPfad(): ?string
    {
        if ($this->ffmpegPfad !== null) {
            return $this->ffmpegPfad;
        }

        $lokaleKandidaten = [
            dirname(__DIR__) . DIRECTORY_SEPARATOR . 'bin' . DIRECTORY_SEPARATOR . 'ffmpeg.exe',
            dirname(__DIR__) . DIRECTORY_SEPARATOR . 'bin' . DIRECTORY_SEPARATOR . 'ffmpeg' . DIRECTORY_SEPARATOR . 'ffmpeg.exe',
            dirname(__DIR__) . DIRECTORY_SEPARATOR . 'bin' . DIRECTORY_SEPARATOR . 'ffmpeg' . DIRECTORY_SEPARATOR . 'bin' . DIRECTORY_SEPARATOR . 'ffmpeg.exe',
        ];

        foreach ($lokaleKandidaten as $kandidat) {
            if (is_file($kandidat)) {
                $this->ffmpegPfad = $kandidat;
                return $this->ffmpegPfad;
            }
        }

        $kandidaten = [
            (string) getenv('FFMPEG_PATH'),
            'D:\\ffmpeg\\bin\\ffmpeg.exe',
            'C:\\ffmpeg\\bin\\ffmpeg.exe',
            'D:\\wamp\\bin\\ffmpeg\\ffmpeg.exe',
            'C:\\Program Files\\ffmpeg\\bin\\ffmpeg.exe',
        ];

        foreach ($kandidaten as $kandidat) {
            if ($kandidat !== '' && is_file($kandidat)) {
                $this->ffmpegPfad = $kandidat;
                return $this->ffmpegPfad;
            }
        }

        $ausgabe = [];
        $code = 1;
        @exec('where ffmpeg 2>NUL', $ausgabe, $code);
        if ($code === 0 && isset($ausgabe[0]) && is_file($ausgabe[0])) {
            $this->ffmpegPfad = trim($ausgabe[0]);
            return $this->ffmpegPfad;
        }

        return null;
    }

    private function erstelleTempDatei(string $endung): string
    {
        $rohDatei = tempnam(TempPath::baseDir(), 'video_tool_');
        if ($rohDatei === false) {
            throw new RuntimeException('Temporary file could not be created.');
        }
        $zielDatei = $rohDatei . '.' . ltrim($endung, '.');
        if (!@rename($rohDatei, $zielDatei)) {
            @unlink($rohDatei);
            throw new RuntimeException('Temporary file could not be prepared.');
        }

        return $zielDatei;
    }

    private function ladeUrlInTempDatei(string $url, string $endung): string
    {
        $tmpDatei = $this->erstelleTempDatei($endung);
        $stream = fopen($tmpDatei, 'wb');
        if ($stream === false) {
            @unlink($tmpDatei);
            throw new RuntimeException('Temporary file is not writable.');
        }

        $ch = curl_init($url);
        if ($ch === false) {
            fclose($stream);
            @unlink($tmpDatei);
            throw new RuntimeException('Could not initialize cURL.');
        }

        $ergebnis = $this->fuehreDateiDownloadAus($ch, $stream, true);
        if (!$ergebnis['erfolg'] && $this->istSslZertifikatsFehler((int) $ergebnis['errno'], (string) $ergebnis['fehler'])) {
            ftruncate($stream, 0);
            rewind($stream);
            $ergebnis = $this->fuehreDateiDownloadAus($ch, $stream, false);
        }

        curl_close($ch);
        fclose($stream);

        if (!$ergebnis['erfolg'] || !is_file($tmpDatei) || (int) filesize($tmpDatei) <= 0) {
            @unlink($tmpDatei);
            $meldung = (string) $ergebnis['fehler'];
            throw new RuntimeException('Failed to download file: ' . $meldung);
        }

        return $tmpDatei;
    }

    /**
     * @param resource $ch
     * @param resource $stream
     * @return array{erfolg:bool, fehler:string, errno:int}
     */
    private function fuehreDateiDownloadAus($ch, $stream, bool $sslPruefung): array
    {
        curl_setopt_array($ch, [
            CURLOPT_FILE => $stream,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 6,
            CURLOPT_CONNECTTIMEOUT => 20,
            CURLOPT_TIMEOUT => 0,
            CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) MP4-Tool/1.0',
            CURLOPT_SSL_VERIFYPEER => $sslPruefung,
            CURLOPT_SSL_VERIFYHOST => $sslPruefung ? 2 : 0,
        ]);

        $ok = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $errno = curl_errno($ch);
        $fehler = curl_error($ch);

        if ($ok === true && $httpCode < 400) {
            return [
                'erfolg' => true,
                'fehler' => '',
                'errno' => $errno,
            ];
        }

        $meldung = $fehler !== '' ? $fehler : ('HTTP ' . $httpCode);
        return [
            'erfolg' => false,
            'fehler' => $meldung,
            'errno' => $errno,
        ];
    }

    private function istSslZertifikatsFehler(int $errno, string $fehler): bool
    {
        $sslFehlerCodes = [35, 51, 58, 60, 77, 80, 83, 90];

        if (defined('CURLE_PEER_FAILED_VERIFICATION')) {
            $sslFehlerCodes[] = (int) constant('CURLE_PEER_FAILED_VERIFICATION');
        }
        if (defined('CURLE_SSL_CACERT')) {
            $sslFehlerCodes[] = (int) constant('CURLE_SSL_CACERT');
        }
        if (defined('CURLE_SSL_CACERT_BADFILE')) {
            $sslFehlerCodes[] = (int) constant('CURLE_SSL_CACERT_BADFILE');
        }

        if (in_array($errno, array_unique($sslFehlerCodes), true)) {
            return true;
        }

        $fehlerKlein = strtolower($fehler);
        return str_contains($fehlerKlein, 'ssl certificate problem')
            || str_contains($fehlerKlein, 'unable to get local issuer certificate')
            || str_contains($fehlerKlein, 'certificate verify failed')
            || str_contains($fehlerKlein, 'self signed certificate');
    }

    private function fuehreBefehlAus(string $befehl, string $erwarteteDatei, string $fehlermeldung): void
    {
        $ausgabe = [];
        $code = 1;
        @exec($befehl, $ausgabe, $code);

        if ($code !== 0 || !is_file($erwarteteDatei) || (int) filesize($erwarteteDatei) <= 0) {
            @unlink($erwarteteDatei);
            $details = trim(implode("\n", $ausgabe));
            if ($details !== '') {
                throw new RuntimeException($fehlermeldung . ' ' . $details);
            }
            throw new RuntimeException($fehlermeldung);
        }
    }

    private function konvertiereLokaleAudioDateiZuMp3(string $eingabeDatei, int $zielBitrate, ?callable $statusCallback = null, int $kompressionsStufe = 2): string
    {
        if (!is_file($eingabeDatei) || (int) filesize($eingabeDatei) <= 0) {
            throw new RuntimeException('Downloaded audio source is missing.');
        }

        $ffmpegPfad = $this->findeFfmpegPfad();
        if ($ffmpegPfad === null) {
            throw new RuntimeException('ffmpeg is required for MP3 conversion.');
        }

        $zielBitrate = $this->normalisiereMp3Bitrate($zielBitrate);
        $dauerSekunden = $this->ermittleDateiDauerSekunden($eingabeDatei, $ffmpegPfad);
        $ausgabeDatei = $this->erstelleTempDatei('mp3');

        $kompressionsStufe = max(0, min(9, $kompressionsStufe));
        $befehl = $this->orchestrator->buildMp3Command(
            $ffmpegPfad,
            $eingabeDatei,
            $ausgabeDatei,
            $zielBitrate,
            $kompressionsStufe
        );

        $startMeldung = $this->baueMp3KonvertierungsMeldung();
        $this->meldeStatus($statusCallback, 25, $startMeldung);
        $letzterProzent = 25;
        $letzteGeschwindigkeit = '';
        $letzteStatusMeldung = $startMeldung;
        $ausgabePuffer = [];

        $stream = @popen($befehl, 'r');
        if (!is_resource($stream)) {
            @unlink($ausgabeDatei);
            throw new RuntimeException('Could not start ffmpeg conversion process.');
        }
        @stream_set_blocking($stream, false);

        while (!feof($stream)) {
            $zeile = fgets($stream);
            if ($zeile === false) {
                usleep(100000);
                continue;
            }

            $zeile = trim($zeile);
            if ($zeile === '') {
                continue;
            }

            $ausgabePuffer[] = $zeile;
            if (count($ausgabePuffer) > 140) {
                array_shift($ausgabePuffer);
            }

            if (preg_match('~^speed=(.+)$~i', $zeile, $treffer) === 1) {
                $aktuelleGeschwindigkeit = trim($treffer[1]);
                if (strtoupper($aktuelleGeschwindigkeit) === 'N/A') {
                    $aktuelleGeschwindigkeit = '';
                }

                if ($aktuelleGeschwindigkeit !== $letzteGeschwindigkeit) {
                    $letzteGeschwindigkeit = $aktuelleGeschwindigkeit;
                    $meldung = $this->baueMp3KonvertierungsMeldung();
                    if ($meldung !== $letzteStatusMeldung) {
                        $letzteStatusMeldung = $meldung;
                        $this->meldeStatus($statusCallback, $letzterProzent, $meldung);
                    }
                }
                continue;
            }

            if (preg_match('~^out_time_(?:ms|us)=([0-9]+)$~i', $zeile, $treffer) === 1) {
                $outTimeMikro = (float) $treffer[1];
                $sekunden = max(0.0, $outTimeMikro / 1000000.0);

                if ($dauerSekunden > 0.0) {
                    $norm = max(0.0, min(1.0, $sekunden / $dauerSekunden));
                } else {
                    $norm = 1.0 - exp(-$sekunden / 180.0);
                }

                $umgerechnet = 25 + (int) round((97 - 25) * $norm);
                $meldung = $this->baueMp3KonvertierungsMeldung();
                if ($umgerechnet > $letzterProzent) {
                    $letzterProzent = $umgerechnet;
                    $letzteStatusMeldung = $meldung;
                    $this->meldeStatus($statusCallback, $umgerechnet, $meldung);
                } elseif ($meldung !== $letzteStatusMeldung) {
                    $letzteStatusMeldung = $meldung;
                    $this->meldeStatus($statusCallback, $letzterProzent, $meldung);
                }
                continue;
            }

            if (preg_match('~^progress=end$~i', $zeile) === 1 && $letzterProzent < 98) {
                $letzterProzent = 98;
                $this->meldeStatus($statusCallback, 98, 'MP3 conversion finished.');
            }
        }

        $code = pclose($stream);
        if (!is_int($code) || $code !== 0 || !is_file($ausgabeDatei) || (int) filesize($ausgabeDatei) <= 0) {
            @unlink($ausgabeDatei);
            $details = trim(implode("\n", $ausgabePuffer));
            $meldung = $details !== '' ? ('MP3 conversion failed. ' . $details) : 'MP3 conversion failed.';
            throw new RuntimeException($meldung);
        }

        @unlink($eingabeDatei);
        $eingabeVerzeichnis = dirname($eingabeDatei);
        $basisVerzeichnis = basename($eingabeVerzeichnis);
        if (str_starts_with($basisVerzeichnis, 'video_tool_yt_')) {
            @rmdir($eingabeVerzeichnis);
        }

        return $ausgabeDatei;
    }

    private function baueMp3KonvertierungsMeldung(): string
    {
        return 'Decoding audio...';
    }

    private function ermittleDateiDauerSekunden(string $dateiPfad, string $ffmpegPfad): float
    {
        $ausgabe = [];
        $code = 1;
        $befehl = sprintf(
            '%s -i %s 2>&1',
            escapeshellarg($ffmpegPfad),
            escapeshellarg($dateiPfad)
        );
        @exec($befehl, $ausgabe, $code);

        foreach ($ausgabe as $zeile) {
            if (preg_match('~Duration:\s*([0-9]{2}):([0-9]{2}):([0-9]{2}(?:\.[0-9]+)?)~', $zeile, $treffer) !== 1) {
                continue;
            }

            $stunden = (int) $treffer[1];
            $minuten = (int) $treffer[2];
            $sekunden = (float) $treffer[3];
            $gesamt = ($stunden * 3600) + ($minuten * 60) + $sekunden;
            if ($gesamt > 0) {
                return $gesamt;
            }
        }

        return 0.0;
    }

    /**
     * @param array<string, mixed> $option
     */
    private function baueDateiname(array $option, string $endung): string
    {
        $quelleUrl = (string) ($option['download_url'] ?? '');
        $pfad = parse_url($quelleUrl, PHP_URL_PATH);
        $basis = is_string($pfad) ? pathinfo($pfad, PATHINFO_FILENAME) : 'video';
        if ($basis === '') {
            $basis = 'video';
        }

        $basis = preg_replace('~[^a-zA-Z0-9_-]+~', '_', $basis) ?? 'video';
        $basis = trim($basis, '_');
        if ($basis === '') {
            $basis = 'video';
        }

        return $basis . '.' . $endung;
    }

    private function sendeDatei(string $pfad, string $dateiname, string $contentType, ?callable $statusCallback = null): void
    {
        if (!is_file($pfad)) {
            throw new RuntimeException('Export file was not found.');
        }

        $this->meldeStatus($statusCallback, 98, 'Sending file to browser...');

        while (ob_get_level() > 0) {
            ob_end_clean();
        }

        header('Content-Description: File Transfer');
        header('Content-Type: ' . $contentType);
        header('Content-Disposition: attachment; filename="' . str_replace('"', '', $dateiname) . '"');
        header('Content-Length: ' . (string) filesize($pfad));
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        header('Pragma: no-cache');

        $this->meldeStatus($statusCallback, 100, 'Download completed.', 'done');
        readfile($pfad);
        $basisVerzeichnis = basename((string) dirname($pfad));
        @unlink($pfad);
        if (str_starts_with($basisVerzeichnis, 'video_tool_yt_')) {
            @rmdir((string) dirname($pfad));
        }
        exit;
    }

    private function meldeStatus(?callable $statusCallback, int $prozent, string $meldung, string $status = 'running'): void
    {
        if ($statusCallback === null) {
            return;
        }
        $statusCallback($prozent, $meldung, $status);
    }

    private function normalisiereMp3Bitrate(?int $bitrate): int
    {
        $gueltigeBitraten = [96, 128, 160, 192, 256, 320];
        if ($bitrate === null || $bitrate <= 0) {
            return 192;
        }

        $naechste = 192;
        $kleinsteDifferenz = PHP_INT_MAX;
        foreach ($gueltigeBitraten as $kandidat) {
            $differenz = abs($kandidat - $bitrate);
            if ($differenz < $kleinsteDifferenz) {
                $kleinsteDifferenz = $differenz;
                $naechste = $kandidat;
            }
        }

        return $naechste;
    }
}
