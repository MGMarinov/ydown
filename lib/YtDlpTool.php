<?php
declare(strict_types=1);

require_once __DIR__ . '/TempPath.php';

final class YtDlpTool
{
    /**
     * @var array{typ:string, wert:string}|null
     */
    private ?array $befehlKonfiguration = null;
    private bool $befehlGeprueft = false;

    public function istYouTubeUrl(string $url): bool
    {
        $host = strtolower((string) (parse_url($url, PHP_URL_HOST) ?? ''));
        if ($host === '') {
            return false;
        }

        return str_contains($host, 'youtube.com')
            || str_contains($host, 'youtu.be')
            || str_contains($host, 'youtube-nocookie.com');
    }

    public function istVerfuegbar(): bool
    {
        return $this->ermittleBefehlKonfiguration() !== null;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function ermittleOptionen(string $url): array
    {
        $daten = $this->holeVideoDaten($url);
        $titel = trim((string) ($daten['title'] ?? 'youtube_video'));
        if ($titel === '') {
            $titel = 'youtube_video';
        }
        $dauerSekunden = max(0, (int) round((float) ($daten['duration'] ?? 0)));

        $formate = $daten['formats'] ?? [];
        if (!is_array($formate)) {
            return [];
        }

        $optionen = [];
        foreach ($formate as $format) {
            if (!is_array($format)) {
                continue;
            }

            $formatId = trim((string) ($format['format_id'] ?? ''));
            if ($formatId === '' || !$this->istFormatIdErlaubt($formatId)) {
                continue;
            }

            $vcodec = strtolower((string) ($format['vcodec'] ?? 'none'));
            $acodec = strtolower((string) ($format['acodec'] ?? 'none'));
            $hatVideo = $vcodec !== 'none';
            $hatAudio = $acodec !== 'none';

            if (!$hatVideo && !$hatAudio) {
                continue;
            }

            $ext = strtolower((string) ($format['ext'] ?? ''));
            $breite = (int) ($format['width'] ?? 0);
            $hoehe = (int) ($format['height'] ?? 0);
            $aufloesung = ($breite > 0 && $hoehe > 0) ? ($breite . 'x' . $hoehe) : '';
            $bitrate = $this->ermittleBitrate($format);

            if ($hatVideo && $ext !== 'mp4') {
                continue;
            }

            $teile = ['YouTube'];
            if ($aufloesung !== '') {
                $teile[] = $aufloesung;
            } elseif (!$hatVideo) {
                $teile[] = 'audio only';
            }
            if ($bitrate > 0) {
                $teile[] = $bitrate . ' kbps';
            }
            if ($ext !== '') {
                $teile[] = $ext;
            }

            $optionen[] = [
                'typ' => 'youtube',
                'quelle_typ' => 'youtube',
                'download_url' => $url,
                'format_id' => $formatId,
                'titel' => $titel,
                'dauer_sekunden' => $dauerSekunden,
                'bitrate_kbps' => $bitrate,
                'aufloesung' => $aufloesung,
                'hoehe' => $hoehe,
                'ext' => $ext,
                'audio_vorhanden' => $hatAudio,
                'video_vorhanden' => $hatVideo,
                'anzeige' => implode(' - ', $teile),
            ];
        }

        $einzigartige = [];
        foreach ($optionen as $option) {
            $schluessel = (string) $option['format_id'];
            $einzigartige[$schluessel] = $option;
        }

        return array_values($einzigartige);
    }

    /**
     * @param array<string, mixed> $option
     * @return array{datei_pfad:string, dateiname:string, content_type:string}
     */
    public function ladeHerunter(array $option, string $zielFormat, ?string $ffmpegPfad, ?int $mp3BitrateKbps = null, ?callable $statusCallback = null): array
    {
        $zielFormat = strtolower(trim($zielFormat));
        if (!in_array($zielFormat, ['mp4', 'mp3'], true)) {
            throw new RuntimeException('Invalid target format.');
        }
        $this->meldeStatus($statusCallback, 3, 'Preparing YouTube format...');

        $url = trim((string) ($option['download_url'] ?? ''));
        $formatId = trim((string) ($option['format_id'] ?? ''));
        if ($url === '' || !$this->istYouTubeUrl($url)) {
            throw new RuntimeException('YouTube URL is invalid.');
        }
        if (!$this->istFormatIdErlaubt($formatId)) {
            throw new RuntimeException('YouTube format ID is invalid.');
        }

        $tempVerzeichnis = $this->erstelleTempVerzeichnis();
        $ausgabeVorlage = $tempVerzeichnis . DIRECTORY_SEPARATOR . 'download.%(ext)s';
        $downloadBereichStart = 5;
        $downloadBereichEnde = $zielFormat === 'mp3' ? 22 : 92;

        if ($zielFormat === 'mp4') {
            $formatAuswahl = $formatId . '+bestaudio[ext=m4a]/' . $formatId . '/best[ext=mp4]/best';
            $this->meldeStatus($statusCallback, $downloadBereichStart, 'Downloading YouTube video...');
            $argumente = [
                '--no-playlist',
                '--no-warnings',
                '--ignore-config',
                '--newline',
                '--progress-template',
                'download:CODXDL %(progress)j',
                '--progress-template',
                'postprocess:CODXPP %(progress)j',
                '--format',
                $formatAuswahl,
                '--merge-output-format',
                'mp4',
                '--output',
                $ausgabeVorlage,
                '--',
                $url,
            ];
        } else {
            $formatAuswahl = $formatId . '/bestaudio/best';
            $this->meldeStatus($statusCallback, $downloadBereichStart, 'Downloading YouTube audio source...');
            $argumente = [
                '--no-playlist',
                '--no-warnings',
                '--ignore-config',
                '--newline',
                '--progress-template',
                'download:CODXDL %(progress)j',
                '--format',
                $formatAuswahl,
                '--output',
                $ausgabeVorlage,
                '--',
                $url,
            ];
        }

        if (is_string($ffmpegPfad) && $ffmpegPfad !== '' && is_file($ffmpegPfad)) {
            array_splice($argumente, 3, 0, ['--ffmpeg-location', $ffmpegPfad]);
        }

        $letzterGemeldeterProzent = $downloadBereichStart;
        $letzteDownloadMeldung = '';
        $ytDlpZeilenCallback = function (string $zeile) use (
            $statusCallback,
            $zielFormat,
            $downloadBereichStart,
            $downloadBereichEnde,
            &$letzterGemeldeterProzent,
            &$letzteDownloadMeldung
        ): void {
            $downloadInfo = $this->ermittleYtDlpDownloadInfo($zeile);
            if ($downloadInfo !== null) {
                $norm = max(0.0, min(100.0, $downloadInfo['prozent']));
                $umgerechnet = (int) round($downloadBereichStart + (($downloadBereichEnde - $downloadBereichStart) * ($norm / 100.0)));
                $meldung = $downloadInfo['meldung'] !== ''
                    ? $downloadInfo['meldung']
                    : ($zielFormat === 'mp3' ? 'Downloading YouTube audio source...' : 'Downloading YouTube video...');
                if ($umgerechnet > $letzterGemeldeterProzent) {
                    $letzterGemeldeterProzent = $umgerechnet;
                    $letzteDownloadMeldung = $meldung;
                    $this->meldeStatus($statusCallback, $umgerechnet, $meldung);
                    return;
                }

                if ($meldung !== '' && $meldung !== $letzteDownloadMeldung) {
                    $letzteDownloadMeldung = $meldung;
                    $this->meldeStatus($statusCallback, $letzterGemeldeterProzent, $meldung);
                }
                return;
            }

            $postprocessStatus = $this->ermittleYtDlpPostprocessStatus($zeile);
            if ($postprocessStatus === null) {
                return;
            }

            if ($zielFormat === 'mp4' && $postprocessStatus === 'started') {
                $prozent = max($letzterGemeldeterProzent, 93);
                if ($prozent > $letzterGemeldeterProzent) {
                    $letzterGemeldeterProzent = $prozent;
                }
                $this->meldeStatus($statusCallback, $prozent, 'Merging video and audio...');
                return;
            }

            if ($zielFormat === 'mp4' && $postprocessStatus === 'finished') {
                $prozent = max($letzterGemeldeterProzent, 96);
                if ($prozent > $letzterGemeldeterProzent) {
                    $letzterGemeldeterProzent = $prozent;
                }
                $this->meldeStatus($statusCallback, $prozent, 'Post-processing finished.');
            }
        };
        $ausgabe = [];
        $code = $this->fuehreMitBotSchutzFallbackAus($argumente, $ausgabe, $ytDlpZeilenCallback);

        if ($code !== 0) {
            $this->entferneVerzeichnisInhalt($tempVerzeichnis);
            throw new RuntimeException($this->formatiereYtDlpFehler($ausgabe, 'YouTube download failed'));
        }

        $dateiPfad = $this->findeErsteDatei($tempVerzeichnis);
        if ($dateiPfad === null || !is_file($dateiPfad) || (int) filesize($dateiPfad) <= 0) {
            $this->entferneVerzeichnisInhalt($tempVerzeichnis);
            throw new RuntimeException('yt-dlp did not create an output file.');
        }
        if ($zielFormat === 'mp3') {
            $this->meldeStatus($statusCallback, max($letzterGemeldeterProzent, 25), 'Audio source downloaded. Starting MP3 conversion...');
        } else {
            $this->meldeStatus($statusCallback, max($letzterGemeldeterProzent, 95), 'Finalizing YouTube file...');
        }

        $titel = trim((string) ($option['titel'] ?? 'youtube_video'));
        $titel = $this->normalisiereDateiname($titel);
        if ($titel === '') {
            $titel = 'youtube_video';
        }

        $endung = strtolower((string) pathinfo($dateiPfad, PATHINFO_EXTENSION));
        if ($endung === '') {
            $endung = $zielFormat === 'mp3' ? 'm4a' : 'mp4';
        }
        $downloadName = $titel . '.' . $endung;

        $contentType = match ($endung) {
            'mp3' => 'audio/mpeg',
            'mp4' => 'video/mp4',
            'm4a' => 'audio/mp4',
            'webm' => 'audio/webm',
            default => 'application/octet-stream',
        };

        return [
            'datei_pfad' => $dateiPfad,
            'dateiname' => $downloadName,
            'content_type' => $contentType,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function holeVideoDaten(string $url): array
    {
        $basisArgumente = [
            '--dump-single-json',
            '--no-playlist',
            '--no-warnings',
            '--ignore-config',
            '--',
            $url,
        ];

        $ausgabe = [];
        $code = $this->fuehreMitBotSchutzFallbackAus($basisArgumente, $ausgabe);

        if ($code !== 0) {
            throw new RuntimeException($this->formatiereYtDlpFehler($ausgabe, 'YouTube analysis with yt-dlp failed'));
        }

        $jsonText = trim(implode("\n", $ausgabe));
        $daten = json_decode($jsonText, true);
        if (!is_array($daten)) {
            $zeilen = array_reverse(array_filter(array_map('trim', $ausgabe), static fn (string $zeile): bool => $zeile !== ''));
            foreach ($zeilen as $zeile) {
                if (!str_starts_with($zeile, '{')) {
                    continue;
                }
                $daten = json_decode($zeile, true);
                if (is_array($daten)) {
                    break;
                }
            }
        }
        if (!is_array($daten)) {
            throw new RuntimeException('yt-dlp did not return valid JSON.');
        }

        return $daten;
    }

    /**
     * @param array<int, string> $ausgabe
     */
    private function istBotSchutzFehler(array $ausgabe): bool
    {
        $text = strtolower(implode("\n", $ausgabe));
        return str_contains($text, 'sign in to confirm you')
            || str_contains($text, 'not a bot');
    }

    /**
     * @return array<int, array<int, string>>
     */
    private function ermittleAutomatischeBotSchutzRetryArgumente(): array
    {
        $kandidaten = [];
        $gesehen = [];

        $extractorArgumente = $this->ermittleExtractorRetryArgumente();
        foreach ($extractorArgumente as $argumente) {
            $this->fuegeRetryKandidatHinzu($kandidaten, $gesehen, $argumente);
        }

        $authArgumente = $this->ermittleAuthRetryArgumente();
        foreach ($authArgumente as $argumente) {
            $this->fuegeRetryKandidatHinzu($kandidaten, $gesehen, $argumente);
        }

        foreach ($extractorArgumente as $extractorKandidat) {
            foreach ($authArgumente as $authKandidat) {
                $kombiniert = array_merge($extractorKandidat, $authKandidat);
                $this->fuegeRetryKandidatHinzu($kandidaten, $gesehen, $kombiniert);
            }
        }

        return $kandidaten;
    }

    /**
     * @return array<int, array<int, string>>
     */
    private function ermittleExtractorRetryArgumente(): array
    {
        return [
            ['--extractor-retries', '2', '--retries', '2'],
            ['--extractor-args', 'youtube:player_client=android'],
            ['--extractor-args', 'youtube:player_client=web,android'],
        ];
    }

    /**
     * @return array<int, array<int, string>>
     */
    private function ermittleAuthRetryArgumente(): array
    {
        $kandidaten = [];
        $gesehen = [];

        $standardCookieDatei = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'bin' . DIRECTORY_SEPARATOR . 'youtube-cookies.txt';
        if (is_file($standardCookieDatei)) {
            $this->fuegeRetryKandidatHinzu($kandidaten, $gesehen, ['--cookies', $standardCookieDatei]);
        }

        $cookieDatei = trim((string) getenv('YTDLP_COOKIES'));
        if ($cookieDatei !== '' && is_file($cookieDatei)) {
            $this->fuegeRetryKandidatHinzu($kandidaten, $gesehen, ['--cookies', $cookieDatei]);
        }

        $browserAusEnv = trim((string) getenv('YTDLP_COOKIES_FROM_BROWSER'));
        if ($browserAusEnv !== '') {
            $this->fuegeRetryKandidatHinzu($kandidaten, $gesehen, ['--cookies-from-browser', $browserAusEnv]);
            return $kandidaten;
        }

        foreach (['edge', 'chrome', 'firefox', 'brave'] as $browser) {
            if (!$this->hatBrowserCookieProfil($browser)) {
                continue;
            }
            $this->fuegeRetryKandidatHinzu($kandidaten, $gesehen, ['--cookies-from-browser', $browser]);
        }

        return $kandidaten;
    }

    private function hatBrowserCookieProfil(string $browser): bool
    {
        $browser = strtolower(trim($browser));
        $localAppData = trim((string) getenv('LOCALAPPDATA'));
        $appData = trim((string) getenv('APPDATA'));

        return match ($browser) {
            'edge' => $localAppData !== '' && is_dir($localAppData . DIRECTORY_SEPARATOR . 'Microsoft' . DIRECTORY_SEPARATOR . 'Edge' . DIRECTORY_SEPARATOR . 'User Data'),
            'chrome' => $localAppData !== '' && is_dir($localAppData . DIRECTORY_SEPARATOR . 'Google' . DIRECTORY_SEPARATOR . 'Chrome' . DIRECTORY_SEPARATOR . 'User Data'),
            'brave' => $localAppData !== '' && is_dir($localAppData . DIRECTORY_SEPARATOR . 'BraveSoftware' . DIRECTORY_SEPARATOR . 'Brave-Browser' . DIRECTORY_SEPARATOR . 'User Data'),
            'firefox' => $appData !== '' && is_dir($appData . DIRECTORY_SEPARATOR . 'Mozilla' . DIRECTORY_SEPARATOR . 'Firefox' . DIRECTORY_SEPARATOR . 'Profiles'),
            default => false,
        };
    }

    /**
     * @param array<int, array<int, string>> $kandidaten
     * @param array<string, bool> $gesehen
     * @param array<int, string> $argumente
     */
    private function fuegeRetryKandidatHinzu(array &$kandidaten, array &$gesehen, array $argumente): void
    {
        if ($argumente === []) {
            return;
        }

        $schluessel = strtolower(implode("\x1F", $argumente));
        if (isset($gesehen[$schluessel])) {
            return;
        }

        $kandidaten[] = $argumente;
        $gesehen[$schluessel] = true;
    }

    /**
     * @param array<int, string> $basisArgumente
     * @param array<int, string> $ausgabe
     */
    private function fuehreMitBotSchutzFallbackAus(array $basisArgumente, array &$ausgabe, ?callable $zeilenCallback = null): int
    {
        $code = $this->fuehreBefehlAus($basisArgumente, $ausgabe, $zeilenCallback);
        if ($code === 0 || !$this->istBotSchutzFehler($ausgabe)) {
            return $code;
        }

        $ersteAusgabe = $ausgabe;
        $ersteCode = $code;
        $retryKandidaten = $this->ermittleAutomatischeBotSchutzRetryArgumente();
        foreach ($retryKandidaten as $retryKandidat) {
            $retryArgumente = $this->fuegeArgumenteVorUrlMarkerEin($basisArgumente, $retryKandidat);
            $retryAusgabe = [];
            $retryCode = $this->fuehreBefehlAus($retryArgumente, $retryAusgabe, $zeilenCallback);
            if ($retryCode === 0) {
                $ausgabe = $retryAusgabe;
                return 0;
            }
        }

        $ausgabe = $ersteAusgabe;
        return $ersteCode;
    }

    private function formatiereYtDlpFehler(array $ausgabe, string $prefix): string
    {
        if ($this->istBotSchutzFehler($ausgabe)) {
            return $prefix . ': YouTube blocked automated access for this request.';
        }

        $details = $this->bereinigeFehlerText(trim(implode("\n", $ausgabe)));
        if ($details === '') {
            $details = 'Unknown yt-dlp error.';
        }

        return $prefix . ': ' . $details;
    }

    private function bereinigeFehlerText(string $text): string
    {
        $text = preg_replace('~(?:\r?\n|\A)\s*null\s*(?=\r?\n|$| )~i', ' ', $text) ?? $text;
        return trim(preg_replace('~\s{2,}~', ' ', $text) ?? $text);
    }

    /**
     * @param array<int, string> $argumente
     * @param array<int, string> $einzufuegen
     * @return array<int, string>
     */
    private function fuegeArgumenteVorUrlMarkerEin(array $argumente, array $einzufuegen): array
    {
        $markerIndex = array_search('--', $argumente, true);
        if (!is_int($markerIndex)) {
            return array_merge($argumente, $einzufuegen);
        }

        array_splice($argumente, $markerIndex, 0, $einzufuegen);
        return $argumente;
    }

    /**
     * @param array<string, mixed> $format
     */
    private function ermittleBitrate(array $format): int
    {
        $werte = [
            (float) ($format['tbr'] ?? 0),
            (float) ($format['abr'] ?? 0),
            (float) ($format['vbr'] ?? 0),
        ];

        foreach ($werte as $wert) {
            if ($wert > 0) {
                return (int) round($wert);
            }
        }

        return 0;
    }

    private function istFormatIdErlaubt(string $formatId): bool
    {
        return preg_match('~^[a-zA-Z0-9._+-]+$~', $formatId) === 1;
    }

    private function normalisiereDateiname(string $name): string
    {
        $name = preg_replace('~[^a-zA-Z0-9_-]+~', '_', $name) ?? '';
        return trim($name, '_');
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

    private function meldeStatus(?callable $statusCallback, int $prozent, string $meldung, string $status = 'running'): void
    {
        if ($statusCallback === null) {
            return;
        }
        $statusCallback($prozent, $meldung, $status);
    }

    /**
     * @return array{prozent:float, meldung:string}|null
     */
    private function ermittleYtDlpDownloadInfo(string $zeile): ?array
    {
        $zeile = trim($zeile);
        if ($zeile === '') {
            return null;
        }

        if (str_starts_with($zeile, 'CODXDL ')) {
            $jsonText = trim(substr($zeile, 7));
            $daten = json_decode($jsonText, true);
            if (!is_array($daten)) {
                return null;
            }

            $wert = null;
            if (isset($daten['_percent'])) {
                $wert = (float) $daten['_percent'];
            } elseif (isset($daten['_percent_str'])) {
                $roh = str_replace('%', '', (string) $daten['_percent_str']);
                if (is_numeric(trim($roh))) {
                    $wert = (float) trim($roh);
                }
            }

            if ($wert === null) {
                return null;
            }

            $prozent = max(0.0, min(100.0, $wert));
            return [
                'prozent' => $prozent,
                'meldung' => 'Downloading media...',
            ];
        }

        if (preg_match('~\[\s*download\s*\]\s*([0-9]{1,3}(?:\.[0-9]+)?)%~i', $zeile, $treffer) === 1) {
            $wert = (float) $treffer[1];
            return [
                'prozent' => max(0.0, min(100.0, $wert)),
                'meldung' => 'Downloading from YouTube...',
            ];
        }

        return null;
    }

    private function ermittleYtDlpPostprocessStatus(string $zeile): ?string
    {
        $zeile = trim($zeile);
        if (!str_starts_with($zeile, 'CODXPP ')) {
            return null;
        }

        $jsonText = trim(substr($zeile, 7));
        $daten = json_decode($jsonText, true);
        if (!is_array($daten)) {
            return null;
        }

        $status = strtolower(trim((string) ($daten['status'] ?? '')));
        if ($status === 'started' || $status === 'finished') {
            return $status;
        }
        return null;
    }

    /**
     * @return array{typ:string, wert:string}|null
     */
    private function ermittleBefehlKonfiguration(): ?array
    {
        if ($this->befehlGeprueft) {
            return $this->befehlKonfiguration;
        }

        $this->befehlGeprueft = true;

        $lokaleKandidaten = [
            dirname(__DIR__) . DIRECTORY_SEPARATOR . 'bin' . DIRECTORY_SEPARATOR . 'yt-dlp.exe',
            dirname(__DIR__) . DIRECTORY_SEPARATOR . 'bin' . DIRECTORY_SEPARATOR . 'yt-dlp' . DIRECTORY_SEPARATOR . 'yt-dlp.exe',
            dirname(__DIR__) . DIRECTORY_SEPARATOR . 'bin' . DIRECTORY_SEPARATOR . 'yt-dlp',
        ];

        foreach ($lokaleKandidaten as $kandidat) {
            if (is_file($kandidat)) {
                $this->befehlKonfiguration = ['typ' => 'pfad', 'wert' => $kandidat];
                return $this->befehlKonfiguration;
            }
        }

        $umgebungsPfad = (string) getenv('YTDLP_PATH');
        if ($umgebungsPfad !== '' && is_file($umgebungsPfad)) {
            $this->befehlKonfiguration = ['typ' => 'pfad', 'wert' => $umgebungsPfad];
            return $this->befehlKonfiguration;
        }

        $ausgabe = [];
        $code = 1;
        @exec('where yt-dlp 2>NUL', $ausgabe, $code);
        if ($code === 0 && isset($ausgabe[0]) && is_file(trim($ausgabe[0]))) {
            $this->befehlKonfiguration = ['typ' => 'pfad', 'wert' => trim($ausgabe[0])];
            return $this->befehlKonfiguration;
        }

        $ausgabe = [];
        $code = 1;
        @exec('python -m yt_dlp --version 2>NUL', $ausgabe, $code);
        if ($code === 0) {
            $this->befehlKonfiguration = ['typ' => 'python_modul', 'wert' => 'python'];
            return $this->befehlKonfiguration;
        }

        $this->befehlKonfiguration = null;
        return null;
    }

    /**
     * @param array<int, string> $argumente
     * @param array<int, string> $ausgabe
     */
    private function fuehreBefehlAus(array $argumente, array &$ausgabe, ?callable $zeilenCallback = null): int
    {
        $konfiguration = $this->ermittleBefehlKonfiguration();
        if ($konfiguration === null) {
            throw new RuntimeException('YouTube support requires yt-dlp. Install yt-dlp or set YTDLP_PATH.');
        }

        $teile = [];
        if ($konfiguration['typ'] === 'pfad') {
            $teile[] = escapeshellarg($konfiguration['wert']);
        } else {
            $teile[] = 'python';
            $teile[] = '-m';
            $teile[] = 'yt_dlp';
        }

        foreach ($argumente as $argument) {
            $teile[] = escapeshellarg($argument);
        }

        $befehl = implode(' ', $teile) . ' 2>&1';
        $stream = @popen($befehl, 'r');
        if (!is_resource($stream)) {
            return 1;
        }

        while (!feof($stream)) {
            $zeile = fgets($stream);
            if ($zeile === false) {
                usleep(10000);
                continue;
            }

            $zeile = rtrim($zeile, "\r\n");
            if ($zeile === '') {
                continue;
            }

            $ausgabe[] = $zeile;
            if ($zeilenCallback !== null) {
                $zeilenCallback($zeile);
            }
        }

        $code = pclose($stream);
        if (!is_int($code)) {
            return 1;
        }
        if ($code > 255) {
            return ($code >> 8) & 0xFF;
        }
        return $code;
    }

    private function erstelleTempVerzeichnis(): string
    {
        $basis = TempPath::baseDir() . DIRECTORY_SEPARATOR . 'video_tool_yt_' . bin2hex(random_bytes(6));
        if (!@mkdir($basis, 0700, true) && !is_dir($basis)) {
            throw new RuntimeException('Temporary directory could not be created.');
        }
        return $basis;
    }

    private function findeErsteDatei(string $verzeichnis): ?string
    {
        $eintraege = @scandir($verzeichnis);
        if (!is_array($eintraege)) {
            return null;
        }

        foreach ($eintraege as $eintrag) {
            if ($eintrag === '.' || $eintrag === '..') {
                continue;
            }
            $pfad = $verzeichnis . DIRECTORY_SEPARATOR . $eintrag;
            if (is_file($pfad)) {
                return $pfad;
            }
        }

        return null;
    }

    private function entferneVerzeichnisInhalt(string $verzeichnis): void
    {
        $eintraege = @scandir($verzeichnis);
        if (is_array($eintraege)) {
            foreach ($eintraege as $eintrag) {
                if ($eintrag === '.' || $eintrag === '..') {
                    continue;
                }
                $pfad = $verzeichnis . DIRECTORY_SEPARATOR . $eintrag;
                if (is_file($pfad)) {
                    @unlink($pfad);
                }
            }
        }

        @rmdir($verzeichnis);
    }
}
