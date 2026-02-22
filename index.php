<?php
declare(strict_types=1);

session_start();

require __DIR__ . '/lib/YtDlpTool.php';
require __DIR__ . '/lib/VideoFormatDetector.php';
require __DIR__ . '/lib/FileDownloader.php';
require __DIR__ . '/lib/ProcessStatusStore.php';
require __DIR__ . '/lib/WorkerJobStore.php';
require __DIR__ . '/lib/ViewHelpers.php';

$ytDlpTool = new YtDlpTool();
$ermittler = new VideoFormatDetector($ytDlpTool);
$downloader = new FileDownloader($ytDlpTool);

$fehler = '';
$hinweis = '';
$warnung = '';
$seitenUrl = (string) ($_SESSION['video_tool_quelle'] ?? '');
$seitenUrl2 = (string) ($_SESSION['video_tool_quelle_slot2'] ?? '');

/**
 * @param array<string, mixed> $daten
 */
function sendeJsonAntwort(array $daten): void
{
    $flags = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;
    if (defined('JSON_INVALID_UTF8_SUBSTITUTE')) {
        $flags |= JSON_INVALID_UTF8_SUBSTITUTE;
    }

    $json = json_encode($daten, $flags);
    if ($json === false) {
        $json = '{"ok":false,"meldung":"Internal JSON encoding error.","optionen":[]}';
    }

    echo $json;
    exit;
}

function bereinigeAntwortText(string $text): string
{
    $text = str_replace("\r\n", "\n", $text);
    $text = preg_replace('~(?:^|\n)\s*null\s*(?=\n|$)~i', '', $text) ?? $text;
    $text = preg_replace("~\n{2,}~", "\n", $text) ?? $text;
    return trim($text);
}

function generiereWorkerJobId(): string
{
    try {
        $zufall = bin2hex(random_bytes(12));
    } catch (Throwable) {
        $zufall = substr(hash('sha256', uniqid((string) mt_rand(), true)), 0, 24);
    }

    return 'job_' . $zufall;
}

function ermittlePhpCliPfad(): string
{
    $kandidaten = [];
    $phpBinary = trim((string) PHP_BINARY);
    if ($phpBinary !== '') {
        $kandidaten[] = $phpBinary;
        $cgiZuCli = preg_replace('~php-cgi\.exe$~i', 'php.exe', $phpBinary);
        if (is_string($cgiZuCli) && $cgiZuCli !== '') {
            $kandidaten[] = $cgiZuCli;
        }
        $kandidaten[] = dirname($phpBinary) . DIRECTORY_SEPARATOR . 'php.exe';
    }

    $wampWurzel = dirname(__DIR__, 3);
    $wampKandidaten = @glob(
        $wampWurzel . DIRECTORY_SEPARATOR
        . 'bin' . DIRECTORY_SEPARATOR
        . 'php' . DIRECTORY_SEPARATOR
        . 'php*' . DIRECTORY_SEPARATOR
        . 'php.exe'
    );
    if (is_array($wampKandidaten) && $wampKandidaten !== []) {
        rsort($wampKandidaten, SORT_NATURAL);
        foreach ($wampKandidaten as $kandidat) {
            if (is_string($kandidat) && $kandidat !== '') {
                $kandidaten[] = $kandidat;
            }
        }
    }

    $kandidaten[] = 'php';
    $eindeutig = [];
    foreach ($kandidaten as $kandidat) {
        $kandidat = trim((string) $kandidat);
        if ($kandidat === '') {
            continue;
        }
        $schluessel = strtolower($kandidat);
        if (isset($eindeutig[$schluessel])) {
            continue;
        }
        $eindeutig[$schluessel] = $kandidat;
    }

    foreach ($eindeutig as $kandidat) {
        if ($kandidat === 'php' || is_file($kandidat)) {
            return $kandidat;
        }
    }

    throw new RuntimeException('PHP CLI executable was not found for worker start.');
}

function starteWorkerProzess(string $jobId): void
{
    if (preg_match('~^[a-zA-Z0-9_-]{8,64}$~', $jobId) !== 1) {
        throw new RuntimeException('Invalid worker job ID.');
    }

    $runnerPfad = __DIR__ . DIRECTORY_SEPARATOR . 'cli' . DIRECTORY_SEPARATOR . 'worker_job_runner.php';
    if (!is_file($runnerPfad)) {
        throw new RuntimeException('Worker runner script is missing.');
    }

    $phpPfad = ermittlePhpCliPfad();

    $basisBefehl = escapeshellarg($phpPfad)
        . ' '
        . escapeshellarg($runnerPfad)
        . ' '
        . escapeshellarg($jobId);

    if (PHP_OS_FAMILY === 'Windows') {
        $befehl = 'start "" /B ' . $basisBefehl . ' >NUL 2>&1';
        $handle = @popen($befehl, 'r');
        if (!is_resource($handle)) {
            throw new RuntimeException('Worker process could not be started.');
        }
        @pclose($handle);
        return;
    }

    $befehl = $basisBefehl . ' > /dev/null 2>&1 &';
    $ausgabe = [];
    $code = 1;
    @exec($befehl, $ausgabe, $code);
    if (!is_int($code) || $code !== 0) {
        throw new RuntimeException('Worker process could not be started.');
    }
}

function starteWorkerHttpAuftrag(string $jobId, string $token): void
{
    if (preg_match('~^[a-zA-Z0-9_-]{8,64}$~', $jobId) !== 1) {
        throw new RuntimeException('Invalid worker job ID.');
    }
    if (preg_match('~^[a-zA-Z0-9]{16,128}$~', $token) !== 1) {
        throw new RuntimeException('Invalid worker token.');
    }

    $scriptName = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? '/index.php'));
    $basisPfad = rtrim((string) dirname($scriptName), '/.');
    $workerPfad = ($basisPfad !== '' ? $basisPfad : '')
        . '/api/internal/worker_job_http.php?job='
        . rawurlencode($jobId)
        . '&token='
        . rawurlencode($token);

    $port = (int) ($_SERVER['SERVER_PORT'] ?? 80);
    if ($port <= 0) {
        $port = 80;
    }

    $hostHeader = trim((string) ($_SERVER['HTTP_HOST'] ?? ''));
    if ($hostHeader === '') {
        $hostHeader = '127.0.0.1' . ($port === 80 ? '' : ':' . $port);
    }

    $socket = @fsockopen('127.0.0.1', $port, $errno, $errstr, 2.0);
    if (!is_resource($socket)) {
        throw new RuntimeException('Worker trigger request failed.');
    }

    $anfrage = "GET {$workerPfad} HTTP/1.1\r\n";
    $anfrage .= "Host: {$hostHeader}\r\n";
    $anfrage .= "Connection: Close\r\n";
    $anfrage .= "User-Agent: ydown-worker-trigger/1.0\r\n\r\n";
    @fwrite($socket, $anfrage);
    @fclose($socket);
}

function starteWorkerAuftrag(string $jobId, string $token): void
{
    try {
        starteWorkerHttpAuftrag($jobId, $token);
        return;
    } catch (Throwable) {
    }

    starteWorkerProzess($jobId);
}

if (isset($_GET['reset']) && $_GET['reset'] === '1') {
    unset($_SESSION['video_tool_optionen'], $_SESSION['video_tool_quelle'], $_SESSION['video_tool_quelle_slot2']);
    header('Location: ' . strtok((string) $_SERVER['REQUEST_URI'], '?'));
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $aktion = (string) ($_POST['aktion'] ?? '');

    if ($aktion === 'analysieren') {
        $seitenUrl = trim((string) ($_POST['seiten_url'] ?? ''));
        $seitenUrl2 = trim((string) ($_POST['seiten_url_2'] ?? ''));

        try {
            $optionen = $ermittler->ermittleOptionen($seitenUrl);
            if ($optionen === []) {
                throw new RuntimeException('No downloadable MP4/HLS source could be found on this page.');
            }

            $_SESSION['video_tool_optionen'] = $optionen;
            $_SESSION['video_tool_quelle'] = $seitenUrl;
            $_SESSION['video_tool_quelle_slot2'] = $seitenUrl2;
            $hinweis = 'Scan completed.';
            if ($ermittler->wurdeSslFallbackGenutzt()) {
                $warnung = 'SSL certificate could not be verified. Analysis was performed using an insecure fallback mode.';
            }
        } catch (Throwable $exception) {
            $fehler = $exception->getMessage();
            unset($_SESSION['video_tool_optionen']);
        }
    }

    if ($aktion === 'analysieren_ajax') {
        header('Content-Type: application/json; charset=utf-8');

        $seitenUrl = trim((string) ($_POST['seiten_url'] ?? ''));

        try {
            if ($seitenUrl === '') {
                throw new RuntimeException('Please provide a page URL.');
            }

            $optionen = $ermittler->ermittleOptionen($seitenUrl);
            if (!is_array($optionen) || $optionen === []) {
                throw new RuntimeException('No downloadable MP4/HLS source could be found on this page.');
            }

            $frontendOptionen = [];
            foreach ($optionen as $option) {
                if (!is_array($option)) {
                    continue;
                }
                $frontendOptionen[] = baueFrontendOption($option);
            }
            $scanMeta = ermittleScanMetadaten($optionen);

            sendeJsonAntwort([
                'ok' => true,
                'hinweis' => 'Scan completed.',
                'warnung' => $ermittler->wurdeSslFallbackGenutzt()
                    ? 'SSL certificate could not be verified. Analysis was performed using an insecure fallback mode.'
                    : '',
                'optionen' => $frontendOptionen,
                'scan_title' => $scanMeta['scan_title'],
                'scan_duration_seconds' => $scanMeta['scan_duration_seconds'],
            ]);
        } catch (Throwable $exception) {
            sendeJsonAntwort([
                'ok' => false,
                'meldung' => bereinigeAntwortText($exception->getMessage()),
                'optionen' => [],
            ]);
        }
    }

    if ($aktion === 'start_worker_job') {
        header('Content-Type: application/json; charset=utf-8');

        $zielFormat = ((string) ($_POST['ziel_format'] ?? 'mp4') === 'mp3') ? 'mp3' : 'mp4';
        $optionPayload = trim((string) ($_POST['option_payload'] ?? ''));
        $praeferenzBitrate = max(0, (int) ($_POST['praeferenz_bitrate'] ?? 0));
        $kompressionsStufe = max(0, min(9, (int) ($_POST['compression_level'] ?? 2)));
        $jobSpeicher = null;
        $statusSpeicher = null;
        try {
            $workerToken = bin2hex(random_bytes(16));
        } catch (Throwable) {
            $workerToken = substr(hash('sha256', uniqid((string) mt_rand(), true)), 0, 32);
        }

        try {
            $gewaehlteOption = dekodiereAusgewaehlteOption($optionPayload);
            if (!is_array($gewaehlteOption)) {
                throw new RuntimeException('Selected quality is invalid. Please scan and choose a quality again.');
            }

            $gewaehlteOption['compression_level'] = $kompressionsStufe;
            $quelleTyp = trim((string) ($gewaehlteOption['quelle_typ'] ?? ''));
            $hatAudio = !array_key_exists('audio_vorhanden', $gewaehlteOption) || (bool) $gewaehlteOption['audio_vorhanden'];
            $hatVideo = !array_key_exists('video_vorhanden', $gewaehlteOption) || (bool) $gewaehlteOption['video_vorhanden'];
            $ext = optionExt($gewaehlteOption);

            if ($quelleTyp === 'youtube' && !$downloader->istYtDlpVerfuegbar()) {
                throw new RuntimeException('YouTube downloads require yt-dlp. Install yt-dlp or set YTDLP_PATH.');
            }
            if ($zielFormat === 'mp3' && !$hatAudio) {
                throw new RuntimeException('The selected option has no audio. Choose another option for MP3.');
            }
            if ($zielFormat === 'mp4' && !$hatVideo) {
                throw new RuntimeException('The selected option has no video. Choose another option for MP4.');
            }
            if ($zielFormat === 'mp4' && $quelleTyp === 'youtube' && $ext !== '' && $ext !== 'mp4') {
                throw new RuntimeException('Only MP4 video formats are allowed for video downloads.');
            }
            if ($zielFormat === 'mp4' && !$hatAudio) {
                if ($quelleTyp === 'youtube') {
                    if (!$downloader->istFfmpegVerfuegbar()) {
                        throw new RuntimeException('This YouTube quality is video-only. ffmpeg is required for MP4 with audio.');
                    }
                } else {
                    throw new RuntimeException('The selected option has no audio. Choose another option for MP4 with audio.');
                }
            }
            if ($zielFormat === 'mp3' && !$downloader->istFfmpegVerfuegbar()) {
                throw new RuntimeException('ffmpeg is required for MP3 (install it or set FFMPEG_PATH).');
            }

            $mp3Bitrate = null;
            if ($zielFormat === 'mp3') {
                $mp3Bitrate = $praeferenzBitrate > 0
                    ? $praeferenzBitrate
                    : (int) ($gewaehlteOption['bitrate_kbps'] ?? 192);
            }

            $jobId = generiereWorkerJobId();
            $statusSpeicher = new ProcessStatusStore($jobId);
            $jobSpeicher = new WorkerJobStore($jobId);

            $statusSpeicher->setzeFortschritt(1, 'Queued worker job...');
            $jobSpeicher->erstelle([
                'ziel_format' => $zielFormat,
                'option' => $gewaehlteOption,
                'mp3_bitrate' => $mp3Bitrate,
                'worker_token' => $workerToken,
                'created_at_iso' => gmdate('c'),
            ]);

            starteWorkerAuftrag($jobId, $workerToken);
            $statusSpeicher->setzeFortschritt(2, 'Worker started...');

            sendeJsonAntwort([
                'ok' => true,
                'job_id' => $jobId,
            ]);
        } catch (Throwable $exception) {
            $meldung = bereinigeAntwortText($exception->getMessage());
            if ($statusSpeicher instanceof ProcessStatusStore) {
                $statusSpeicher->setzeFehler($meldung);
            }
            if ($jobSpeicher instanceof WorkerJobStore) {
                try {
                    $jobSpeicher->setzeFehler($meldung);
                } catch (Throwable) {
                }
            }

            sendeJsonAntwort([
                'ok' => false,
                'meldung' => $meldung,
            ]);
        }
    }

    if ($aktion === 'herunterladen') {
        $optionen = $_SESSION['video_tool_optionen'] ?? [];
        $seitenUrl = (string) ($_SESSION['video_tool_quelle'] ?? '');
        $zielFormat = ((string) ($_POST['ziel_format'] ?? 'mp4') === 'mp3') ? 'mp3' : 'mp4';
        $jobId = trim((string) ($_POST['job_id'] ?? ''));
        $statusSpeicher = null;
        $statusCallback = null;

        if ($jobId !== '') {
            try {
                $statusSpeicher = new ProcessStatusStore($jobId);
                $statusSpeicher->setzeFortschritt(1, 'Preparing download job...');
                $statusCallback = static function (int $prozent, string $meldung, string $status = 'running') use ($statusSpeicher): void {
                    $statusSpeicher->setzeFortschritt($prozent, $meldung, $status);
                };
            } catch (Throwable $statusFehler) {
                $statusSpeicher = null;
                $statusCallback = null;
            }
        }

        $indexRaw = (string) ($_POST['qualitaet_index'] ?? '');
        $index = filter_var($indexRaw, FILTER_VALIDATE_INT);

        try {
            if ($statusSpeicher !== null) {
                $statusSpeicher->setzeFortschritt(2, 'Validating input...');
            }
            if (!is_array($optionen) || $optionen === []) {
                throw new RuntimeException('Run analysis first.');
            }
            if ($index === false || !isset($optionen[$index])) {
                throw new RuntimeException('Select a valid quality option.');
            }
            $gewaehlteOption = $optionen[$index];
            $kompressionsStufe = max(0, min(9, (int) ($_POST['compression_level'] ?? 2)));
            $gewaehlteOption['compression_level'] = $kompressionsStufe;
            $quelleTyp = trim((string) ($gewaehlteOption['quelle_typ'] ?? ''));
            $hatAudio = !array_key_exists('audio_vorhanden', $gewaehlteOption) || (bool) $gewaehlteOption['audio_vorhanden'];
            $hatVideo = !array_key_exists('video_vorhanden', $gewaehlteOption) || (bool) $gewaehlteOption['video_vorhanden'];
            $ext = optionExt($gewaehlteOption);

            if ($zielFormat === 'mp3' && !$hatAudio) {
                throw new RuntimeException('The selected option has no audio. Choose another option for MP3.');
            }
            if ($zielFormat === 'mp4' && !$hatVideo) {
                throw new RuntimeException('The selected option has no video. Choose another option for MP4.');
            }
            if ($zielFormat === 'mp4' && $quelleTyp === 'youtube' && $ext !== '' && $ext !== 'mp4') {
                throw new RuntimeException('Only MP4 video formats are allowed for video downloads.');
            }
            if ($zielFormat === 'mp4' && !$hatAudio) {
                if ($quelleTyp === 'youtube') {
                    if (!$downloader->istFfmpegVerfuegbar()) {
                        throw new RuntimeException('This YouTube quality is video-only. ffmpeg is required for MP4 with audio.');
                    }
                } else {
                    throw new RuntimeException('The selected option has no audio. Choose another option for MP4 with audio.');
                }
            }
            if ($zielFormat === 'mp3' && !$downloader->istFfmpegVerfuegbar()) {
                throw new RuntimeException('ffmpeg is required for MP3 (install it or set FFMPEG_PATH).');
            }

            $mp3Bitrate = null;
            if ($zielFormat === 'mp3') {
                $mp3Bitrate = (int) ($gewaehlteOption['bitrate_kbps'] ?? 192);
            }

            if (session_status() === PHP_SESSION_ACTIVE) {
                session_write_close();
            }

            $downloader->starteDownload($gewaehlteOption, $zielFormat, $mp3Bitrate, $statusCallback);
        } catch (Throwable $exception) {
            if ($statusSpeicher !== null) {
                $statusSpeicher->setzeFehler($exception->getMessage());
            }
            $fehler = $exception->getMessage();
        }
    }

    if ($aktion === 'herunterladen_url') {
        $seitenUrl = trim((string) ($_POST['seiten_url'] ?? ''));
        $zielFormat = ((string) ($_POST['ziel_format'] ?? 'mp4') === 'mp3') ? 'mp3' : 'mp4';
        $praeferenzHoehe = max(0, (int) ($_POST['praeferenz_hoehe'] ?? 0));
        $praeferenzBitrate = max(0, (int) ($_POST['praeferenz_bitrate'] ?? 0));
        $jobId = trim((string) ($_POST['job_id'] ?? ''));
        $statusSpeicher = null;
        $statusCallback = null;

        if ($jobId !== '') {
            try {
                $statusSpeicher = new ProcessStatusStore($jobId);
                $statusSpeicher->setzeFortschritt(1, 'Preparing download job...');
                $statusCallback = static function (int $prozent, string $meldung, string $status = 'running') use ($statusSpeicher): void {
                    $statusSpeicher->setzeFortschritt($prozent, $meldung, $status);
                };
            } catch (Throwable) {
                $statusSpeicher = null;
                $statusCallback = null;
            }
        }

        try {
            if ($statusSpeicher !== null) {
                $statusSpeicher->setzeFortschritt(2, 'Analyzing URL...');
            }
            if ($seitenUrl === '') {
                throw new RuntimeException('Please provide a page URL.');
            }

            $optionenVonUrl = $ermittler->ermittleOptionen($seitenUrl);
            if (!is_array($optionenVonUrl) || $optionenVonUrl === []) {
                throw new RuntimeException('No downloadable source was found for this URL.');
            }

            $gewaehlteOption = waehleOptionNachPraeferenz($optionenVonUrl, $zielFormat, $praeferenzHoehe, $praeferenzBitrate);
            if ($gewaehlteOption === null) {
                throw new RuntimeException('No matching option is available for the selected format.');
            }

            $kompressionsStufe = max(0, min(9, (int) ($_POST['compression_level'] ?? 2)));
            $gewaehlteOption['compression_level'] = $kompressionsStufe;
            $quelleTyp = trim((string) ($gewaehlteOption['quelle_typ'] ?? ''));
            $hatAudio = !array_key_exists('audio_vorhanden', $gewaehlteOption) || (bool) $gewaehlteOption['audio_vorhanden'];
            $hatVideo = !array_key_exists('video_vorhanden', $gewaehlteOption) || (bool) $gewaehlteOption['video_vorhanden'];
            $ext = optionExt($gewaehlteOption);

            if ($statusSpeicher !== null) {
                $statusSpeicher->setzeFortschritt(3, 'Selected quality: ' . baueAnzeigeText($gewaehlteOption, $zielFormat));
            }

            if ($zielFormat === 'mp3' && !$hatAudio) {
                throw new RuntimeException('The selected option has no audio. Choose another option for MP3.');
            }
            if ($zielFormat === 'mp4' && !$hatVideo) {
                throw new RuntimeException('The selected option has no video. Choose another option for MP4.');
            }
            if ($zielFormat === 'mp4' && $quelleTyp === 'youtube' && $ext !== '' && $ext !== 'mp4') {
                throw new RuntimeException('Only MP4 video formats are allowed for video downloads.');
            }
            if ($zielFormat === 'mp4' && !$hatAudio) {
                if ($quelleTyp === 'youtube') {
                    if (!$downloader->istFfmpegVerfuegbar()) {
                        throw new RuntimeException('This YouTube quality is video-only. ffmpeg is required for MP4 with audio.');
                    }
                } else {
                    throw new RuntimeException('The selected option has no audio. Choose another option for MP4 with audio.');
                }
            }
            if ($zielFormat === 'mp3' && !$downloader->istFfmpegVerfuegbar()) {
                throw new RuntimeException('ffmpeg is required for MP3 (install it or set FFMPEG_PATH).');
            }

            $mp3Bitrate = null;
            if ($zielFormat === 'mp3') {
                $mp3Bitrate = $praeferenzBitrate > 0
                    ? $praeferenzBitrate
                    : (int) ($gewaehlteOption['bitrate_kbps'] ?? 192);
            }

            if (session_status() === PHP_SESSION_ACTIVE) {
                session_write_close();
            }

            $downloader->starteDownload($gewaehlteOption, $zielFormat, $mp3Bitrate, $statusCallback);
        } catch (Throwable $exception) {
            if ($statusSpeicher !== null) {
                $statusSpeicher->setzeFehler($exception->getMessage());
            }
            $fehler = $exception->getMessage();
        }
    }

    if ($aktion === 'herunterladen_option') {
        $zielFormat = ((string) ($_POST['ziel_format'] ?? 'mp4') === 'mp3') ? 'mp3' : 'mp4';
        $jobId = trim((string) ($_POST['job_id'] ?? ''));
        $optionPayload = trim((string) ($_POST['option_payload'] ?? ''));
        $praeferenzBitrate = max(0, (int) ($_POST['praeferenz_bitrate'] ?? 0));
        $statusSpeicher = null;
        $statusCallback = null;

        if ($jobId !== '') {
            try {
                $statusSpeicher = new ProcessStatusStore($jobId);
                $statusSpeicher->setzeFortschritt(1, 'Preparing download job...');
                $statusCallback = static function (int $prozent, string $meldung, string $status = 'running') use ($statusSpeicher): void {
                    $statusSpeicher->setzeFortschritt($prozent, $meldung, $status);
                };
            } catch (Throwable) {
                $statusSpeicher = null;
                $statusCallback = null;
            }
        }

        try {
            if ($statusSpeicher !== null) {
                $statusSpeicher->setzeFortschritt(2, 'Validating selected quality...');
            }

            $gewaehlteOption = dekodiereAusgewaehlteOption($optionPayload);
            if (!is_array($gewaehlteOption)) {
                throw new RuntimeException('Selected quality is invalid. Please scan and choose a quality again.');
            }

            $kompressionsStufe = max(0, min(9, (int) ($_POST['compression_level'] ?? 2)));
            $gewaehlteOption['compression_level'] = $kompressionsStufe;
            $quelleTyp = trim((string) ($gewaehlteOption['quelle_typ'] ?? ''));
            $hatAudio = !array_key_exists('audio_vorhanden', $gewaehlteOption) || (bool) $gewaehlteOption['audio_vorhanden'];
            $hatVideo = !array_key_exists('video_vorhanden', $gewaehlteOption) || (bool) $gewaehlteOption['video_vorhanden'];
            $ext = optionExt($gewaehlteOption);

            if ($zielFormat === 'mp3' && !$hatAudio) {
                throw new RuntimeException('The selected option has no audio. Choose another option for MP3.');
            }
            if ($zielFormat === 'mp4' && !$hatVideo) {
                throw new RuntimeException('The selected option has no video. Choose another option for MP4.');
            }
            if ($zielFormat === 'mp4' && $quelleTyp === 'youtube' && $ext !== '' && $ext !== 'mp4') {
                throw new RuntimeException('Only MP4 video formats are allowed for video downloads.');
            }
            if ($zielFormat === 'mp4' && !$hatAudio) {
                if ($quelleTyp === 'youtube') {
                    if (!$downloader->istFfmpegVerfuegbar()) {
                        throw new RuntimeException('This YouTube quality is video-only. ffmpeg is required for MP4 with audio.');
                    }
                } else {
                    throw new RuntimeException('The selected option has no audio. Choose another option for MP4 with audio.');
                }
            }
            if ($zielFormat === 'mp3' && !$downloader->istFfmpegVerfuegbar()) {
                throw new RuntimeException('ffmpeg is required for MP3 (install it or set FFMPEG_PATH).');
            }

            $mp3Bitrate = null;
            if ($zielFormat === 'mp3') {
                $mp3Bitrate = $praeferenzBitrate > 0
                    ? $praeferenzBitrate
                    : (int) ($gewaehlteOption['bitrate_kbps'] ?? 192);
            }

            if (session_status() === PHP_SESSION_ACTIVE) {
                session_write_close();
            }

            $downloader->starteDownload($gewaehlteOption, $zielFormat, $mp3Bitrate, $statusCallback);
        } catch (Throwable $exception) {
            if ($statusSpeicher !== null) {
                $statusSpeicher->setzeFehler($exception->getMessage());
            }
            $fehler = $exception->getMessage();
        }
    }
}

$optionen = $_SESSION['video_tool_optionen'] ?? [];
$ffmpegVerfuegbar = $downloader->istFfmpegVerfuegbar();
$ytDlpVerfuegbar = $downloader->istYtDlpVerfuegbar();
$ausgewaehltesFormat = ((string) ($_POST['ziel_format'] ?? 'mp4') === 'mp3') ? 'mp3' : 'mp4';

/**
 * @param array<string, mixed> $option
 */
function optionHatAudio(array $option): bool
{
    if (array_key_exists('audio_vorhanden', $option)) {
        return (bool) $option['audio_vorhanden'];
    }
    return true;
}

/**
 * @param array<string, mixed> $option
 */
function optionHatVideo(array $option): bool
{
    if (array_key_exists('video_vorhanden', $option)) {
        return (bool) $option['video_vorhanden'];
    }
    return true;
}

/**
 * @param array<string, mixed> $option
 */
function optionExt(array $option): string
{
    return strtolower(trim((string) ($option['ext'] ?? '')));
}

function normalisiereMp3AnzeigeBitrate(int $bitrate): int
{
    $gueltigeBitraten = [96, 128, 160, 192, 256, 320];
    if ($bitrate <= 0) {
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

/**
 * @param array<string, mixed> $option
 */
function baueAnzeigeText(array $option, string $format): string
{
    $quelleTyp = (string) ($option['quelle_typ'] ?? 'web');
    $bitrate = (int) ($option['bitrate_kbps'] ?? 0);
    $aufloesung = trim((string) ($option['aufloesung'] ?? ''));

    if ($format === 'mp3') {
        $quelleLabel = $quelleTyp === 'youtube' ? 'YouTube' : 'Audio';
        $zielBitrate = normalisiereMp3AnzeigeBitrate($bitrate);
        $teile = ['MP3'];
        $teile[] = $quelleLabel;
        $teile[] = $zielBitrate . ' kbps';
        return implode(' - ', $teile);
    }

    $standard = trim((string) ($option['anzeige'] ?? ''));
    if ($standard !== '') {
        $standard = preg_replace('~\s*-\s*(with|without)\s+audio\s*$~i', '', $standard) ?? $standard;
        return $standard;
    }

    $teile = ['MP4'];
    if ($aufloesung !== '') {
        $teile[] = $aufloesung;
    }
    if ($bitrate > 0) {
        $teile[] = $bitrate . ' kbps';
    }
    return implode(' - ', $teile);
}

/**
 * @param array<string, mixed> $option
 * @return array<string, int|string|bool>
 */
function baueFrontendOption(array $option): array
{
    return [
        'id' => (int) ($option['id'] ?? -1),
        'typ' => (string) ($option['typ'] ?? ''),
        'quelle_typ' => (string) ($option['quelle_typ'] ?? 'web'),
        'bitrate_kbps' => (int) ($option['bitrate_kbps'] ?? 0),
        'aufloesung' => (string) ($option['aufloesung'] ?? ''),
        'hoehe' => (int) ($option['hoehe'] ?? 0),
        'ext' => optionExt($option),
        'audio_vorhanden' => optionHatAudio($option),
        'video_vorhanden' => optionHatVideo($option),
        'download_url' => (string) ($option['download_url'] ?? ''),
        'format_id' => (string) ($option['format_id'] ?? ''),
        'titel' => (string) ($option['titel'] ?? ''),
        'duration_seconds' => max(0, (int) ($option['dauer_sekunden'] ?? $option['duration_seconds'] ?? 0)),
        'label_mp4' => baueAnzeigeText($option, 'mp4'),
        'label_mp3' => baueAnzeigeText($option, 'mp3'),
    ];
}

/**
 * @param array<int, array<string, mixed>> $optionen
 * @return array{scan_title:string, scan_duration_seconds:int}
 */
function ermittleScanMetadaten(array $optionen): array
{
    $scanTitle = '';
    $scanDurationSeconds = 0;

    foreach ($optionen as $option) {
        if (!is_array($option)) {
            continue;
        }

        if ($scanTitle === '') {
            $titel = trim((string) ($option['titel'] ?? ''));
            if ($titel !== '') {
                $scanTitle = $titel;
            }
        }

        if ($scanDurationSeconds <= 0) {
            $dauer = max(0, (int) ($option['dauer_sekunden'] ?? $option['duration_seconds'] ?? 0));
            if ($dauer > 0) {
                $scanDurationSeconds = $dauer;
            }
        }

        if ($scanTitle !== '' && $scanDurationSeconds > 0) {
            break;
        }
    }

    return [
        'scan_title' => $scanTitle,
        'scan_duration_seconds' => $scanDurationSeconds,
    ];
}

/**
 * @return array<string, mixed>|null
 */
function dekodiereAusgewaehlteOption(string $payload): ?array
{
    $payload = trim($payload);
    if ($payload === '') {
        return null;
    }

    $daten = json_decode($payload, true);
    if (!is_array($daten)) {
        return null;
    }

    $downloadUrl = trim((string) ($daten['download_url'] ?? ''));
    $urlTeile = parse_url($downloadUrl);
    $schema = strtolower((string) ($urlTeile['scheme'] ?? ''));
    if ($downloadUrl === '' || !in_array($schema, ['http', 'https'], true)) {
        return null;
    }

    $quelleTyp = strtolower(trim((string) ($daten['quelle_typ'] ?? 'web')));
    if (!in_array($quelleTyp, ['youtube', 'web'], true)) {
        $quelleTyp = 'web';
    }

    $typ = strtolower(trim((string) ($daten['typ'] ?? '')));
    if ($quelleTyp === 'youtube') {
        $typ = 'youtube';
    } elseif (!in_array($typ, ['direkt_mp4', 'hls'], true)) {
        $typ = 'direkt_mp4';
    }

    $formatId = trim((string) ($daten['format_id'] ?? ''));
    if ($quelleTyp === 'youtube' && preg_match('~^[a-zA-Z0-9._+-]+$~', $formatId) !== 1) {
        return null;
    }

    $titel = trim((string) ($daten['titel'] ?? ''));
    if ($titel === '') {
        $titel = 'youtube_video';
    }
    if (strlen($titel) > 260) {
        $titel = substr($titel, 0, 260);
    }

    $aufloesung = trim((string) ($daten['aufloesung'] ?? ''));
    if ($aufloesung !== '' && preg_match('~^[0-9]{2,5}x[0-9]{2,5}$~', $aufloesung) !== 1) {
        $aufloesung = '';
    }

    $ext = strtolower(trim((string) ($daten['ext'] ?? '')));
    if ($ext !== '' && preg_match('~^[a-z0-9]{1,8}$~', $ext) !== 1) {
        $ext = '';
    }

    $option = [
        'typ' => $typ,
        'quelle_typ' => $quelleTyp,
        'download_url' => $downloadUrl,
        'bitrate_kbps' => max(0, (int) ($daten['bitrate_kbps'] ?? 0)),
        'aufloesung' => $aufloesung,
        'hoehe' => max(0, (int) ($daten['hoehe'] ?? 0)),
        'ext' => $ext,
        'audio_vorhanden' => array_key_exists('audio_vorhanden', $daten) ? (bool) $daten['audio_vorhanden'] : true,
        'video_vorhanden' => array_key_exists('video_vorhanden', $daten) ? (bool) $daten['video_vorhanden'] : true,
    ];

    if ($quelleTyp === 'youtube') {
        $option['format_id'] = $formatId;
        $option['titel'] = $titel;
    }

    return $option;
}

/**
 * @param array<int, array<string, mixed>> $optionen
 * @return array<int, array<string, mixed>>
 */
function filtereOptionenNachFormat(array $optionen, string $format): array
{
    $gefiltert = [];
    foreach ($optionen as $option) {
        if ($format === 'mp3' && !optionHatAudio($option)) {
            continue;
        }
        if ($format === 'mp4') {
            if (!optionHatVideo($option)) {
                continue;
            }
            $quelleTyp = trim((string) ($option['quelle_typ'] ?? ''));
            $ext = optionExt($option);

            if ($quelleTyp === 'youtube' && $ext !== '' && $ext !== 'mp4') {
                continue;
            }

            if (!optionHatAudio($option) && $quelleTyp !== 'youtube') {
                continue;
            }
        }
        $gefiltert[] = $option;
    }
    return $gefiltert;
}

/**
 * @param array<string, mixed> $option
 * @return array<int, int>
 */
function bauePraeferenzScore(array $option, string $format, int $zielHoehe, int $zielBitrate): array
{
    $hatAudio = optionHatAudio($option);
    $hoehe = max(0, (int) ($option['hoehe'] ?? 0));
    $bitrate = max(0, (int) ($option['bitrate_kbps'] ?? 0));

    if ($format === 'mp3') {
        $bitrateScore = $zielBitrate > 0
            ? abs($bitrate - $zielBitrate)
            : -$bitrate;

        return [
            $hatAudio ? 0 : 1,
            $bitrateScore,
            -$bitrate,
        ];
    }

    $hoehenScore = $zielHoehe > 0
        ? ($hoehe > 0 ? abs($hoehe - $zielHoehe) : 100000)
        : -$hoehe;

    $bitrateScore = $zielBitrate > 0
        ? ($bitrate > 0 ? abs($bitrate - $zielBitrate) : 100000)
        : -$bitrate;

    return [
        $hoehenScore,
        $bitrateScore,
        $hatAudio ? 0 : 1,
        -$hoehe,
        -$bitrate,
    ];
}

/**
 * @param array<int, int> $links
 * @param array<int, int> $rechts
 */
function vergleichePraeferenzScore(array $links, array $rechts): int
{
    $max = max(count($links), count($rechts));
    for ($index = 0; $index < $max; $index++) {
        $wertLinks = $links[$index] ?? 0;
        $wertRechts = $rechts[$index] ?? 0;
        if ($wertLinks !== $wertRechts) {
            return $wertLinks <=> $wertRechts;
        }
    }
    return 0;
}

/**
 * @param array<int, array<string, mixed>> $optionen
 * @return array<string, mixed>|null
 */
function waehleOptionNachPraeferenz(array $optionen, string $format, int $zielHoehe = 0, int $zielBitrate = 0): ?array
{
    $gefiltert = filtereOptionenNachFormat($optionen, $format);
    if ($gefiltert === []) {
        return null;
    }

    usort($gefiltert, static function (array $a, array $b) use ($format, $zielHoehe, $zielBitrate): int {
        $scoreA = bauePraeferenzScore($a, $format, $zielHoehe, $zielBitrate);
        $scoreB = bauePraeferenzScore($b, $format, $zielHoehe, $zielBitrate);
        return vergleichePraeferenzScore($scoreA, $scoreB);
    });

    return $gefiltert[0] ?? null;
}

/**
 * @param array<int, array<string, mixed>> $optionen
 * @return array<int, array<string, mixed>>
 */
function reduziereAnzeigeOptionen(array $optionen, string $format, int $maxAnzahl = 8): array
{
    $gefiltert = filtereOptionenNachFormat($optionen, $format);

    if (count($gefiltert) <= $maxAnzahl) {
        return $gefiltert;
    }

    $ausgewaehlt = [];
    $verwendeteIds = [];

    foreach ($gefiltert as $option) {
        $id = (int) ($option['id'] ?? -1);
        if ($id < 0) {
            continue;
        }

        $quelleTyp = (string) ($option['quelle_typ'] ?? 'web');
        $aufloesung = trim((string) ($option['aufloesung'] ?? ''));
        $bitrate = (int) ($option['bitrate_kbps'] ?? 0);
        $hatAudio = optionHatAudio($option);
        $hatVideo = optionHatVideo($option);
        $gruppe = $format . '|' . $quelleTyp . '|' . $aufloesung . '|' . $bitrate . '|' . ($hatAudio ? '1' : '0') . '|' . ($hatVideo ? '1' : '0');

        if (!isset($ausgewaehlt[$gruppe])) {
            $ausgewaehlt[$gruppe] = $option;
            $verwendeteIds[$id] = true;
        }

        if (count($ausgewaehlt) >= $maxAnzahl) {
            break;
        }
    }

    foreach ($gefiltert as $option) {
        if (count($ausgewaehlt) >= $maxAnzahl) {
            break;
        }

        $id = (int) ($option['id'] ?? -1);
        if ($id < 0 || isset($verwendeteIds[$id])) {
            continue;
        }

        $ausgewaehlt['rest_' . $id] = $option;
        $verwendeteIds[$id] = true;
    }

    return array_values($ausgewaehlt);
}

$gesamtOptionenFuerFormat = filtereOptionenNachFormat(is_array($optionen) ? $optionen : [], $ausgewaehltesFormat);
$anzeigeOptionen = $gesamtOptionenFuerFormat;
$wurdeListeReduziert = false;
$optionenFuerFrontend = [];
if (is_array($optionen)) {
    foreach ($optionen as $option) {
        if (!is_array($option)) {
            continue;
        }
        $optionenFuerFrontend[] = baueFrontendOption($option);
    }
}

function h(string $wert): string
{
    return htmlspecialchars($wert, ENT_QUOTES, 'UTF-8');
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Embedded Video and MP3 downloader</title>
    <script>
        (function () {
            let storedTheme = '';
            try {
                const value = window.localStorage.getItem('video_tool_theme_mode');
                if (value === 'light' || value === 'dark') {
                    storedTheme = value;
                }
            } catch (error) {
            }

            let theme = storedTheme;
            let source = 'manual';
            if (theme === '') {
                source = 'system';
                if (window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches) {
                    theme = 'dark';
                } else if (window.matchMedia && window.matchMedia('(prefers-color-scheme: light)').matches) {
                    theme = 'light';
                } else {
                    theme = 'dark';
                }
            }

            document.documentElement.setAttribute('data-theme', theme);
            document.documentElement.setAttribute('data-theme-source', source);
        })();
    </script>
    <style>
        :root {
            color-scheme: light;
            --bg: #f3f7fd;
            --bg-gradient: radial-gradient(circle at 20% 10%, #ffffff 0%, #eef4fc 52%, #e2ebf8 100%);
            --card: #ffffff;
            --text: #0f172a;
            --muted: #42546d;
            --line: #c8d7ea;
            --slot-bg: #f7fbff;
            --ok: #065f46;
            --ok-bg: #d1fae5;
            --err: #991b1b;
            --err-bg: #fee2e2;
            --btn: #245fd8;
            --btn-h: #1f4fb2;
            --btn-text: #ffffff;
            --select-bg: #173f74;
            --select-text: #edf4ff;
            --select-border: #3168ac;
            --option-bg: #183a67;
            --option-text: #f3f7ff;
            --card-shadow: 0 14px 36px rgba(9, 29, 56, 0.2);
            --theme-chip-bg: #e5eefc;
            --theme-chip-text: #1f3f6d;
            --theme-chip-border: #a3bddf;
            --theme-chip-hover: #d7e5fb;
        }

        :root[data-theme="dark"] {
            color-scheme: dark;
            --bg: #0c1423;
            --bg-gradient: radial-gradient(circle at 20% 10%, #183258 0%, #0e1a2d 50%, #070d16 100%);
            --card: #111b2d;
            --text: #e5eefb;
            --muted: #9cb0c8;
            --line: #2f4565;
            --slot-bg: #11253f;
            --ok: #98edbb;
            --ok-bg: #184f37;
            --err: #ffd0d0;
            --err-bg: #7a202b;
            --btn: #3f89f8;
            --btn-h: #2f74dc;
            --btn-text: #ffffff;
            --select-bg: #17345f;
            --select-text: #edf3ff;
            --select-border: #3a6195;
            --option-bg: #16345c;
            --option-text: #edf3ff;
            --card-shadow: 0 14px 36px rgba(0, 12, 28, 0.48);
            --theme-chip-bg: #1a2b45;
            --theme-chip-text: #d7e6ff;
            --theme-chip-border: #4e6f9e;
            --theme-chip-hover: #243a5d;
        }

        * { box-sizing: border-box; }
        body {
            margin: 0;
            min-height: 100vh;
            font-family: Segoe UI, Tahoma, sans-serif;
            background: var(--bg-gradient);
            color: var(--text);
            padding: 18px;
        }
        .app-shell {
            width: 100%;
            min-height: calc(100vh - 36px);
            display: flex;
            flex-direction: column;
            position: relative;
        }
        .main-center {
            flex: 1 1 auto;
            width: 100%;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
        }
        .header-row {
            position: relative;
            display: flex;
            align-items: flex-start;
            justify-content: center;
            width: min(980px, 100%);
            margin-top: 0;
            padding: 0;
        }
        .header-text {
            min-width: 0;
            width: 100%;
            margin: 0 auto;
            text-align: center;
        }
        h1 {
            margin-top: 0;
            margin-bottom: 6px;
            font-size: clamp(26px, 5.8vw, 40px);
            line-height: 1.06;
            letter-spacing: 0.01em;
        }
        .header-subtitle {
            color: var(--muted);
            margin-top: 0;
            margin-bottom: 0;
            font-size: clamp(10px, 1.9vw, 12px);
            line-height: 1.35;
        }
        p {
            color: var(--muted);
            margin-top: 0;
            margin-bottom: 0;
        }
        .theme-menu-wrap {
            position: fixed;
            top: 8px;
            right: 18px;
            z-index: 30;
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }
        .theme-controls {
            display: inline-flex;
            gap: 8px;
            align-items: center;
        }
        .theme-mode-switch {
            display: none;
            position: relative;
            width: 74px;
            height: 36px;
            border: 1px solid var(--theme-chip-border);
            border-radius: 999px;
            background: var(--theme-chip-bg);
            color: var(--theme-chip-text);
            padding: 0 8px;
            align-items: center;
            justify-content: space-between;
            transition: background 180ms ease, border-color 180ms ease;
        }
        .theme-mode-switch:hover {
            background: var(--theme-chip-hover);
        }
        .theme-mode-switch:focus-visible {
            outline: 2px solid #60a5fa;
            outline-offset: 2px;
        }
        .theme-switch-icon {
            position: relative;
            z-index: 2;
            font-size: 14px;
            line-height: 1;
            pointer-events: none;
            opacity: 0.8;
        }
        .theme-switch-thumb {
            position: absolute;
            top: 3px;
            left: 3px;
            width: 28px;
            height: 28px;
            border-radius: 999px;
            background: #ffffff;
            box-shadow: 0 2px 8px rgba(2, 6, 23, 0.3);
            transition: transform 180ms ease;
            z-index: 1;
        }
        :root[data-theme="dark"] .theme-switch-thumb {
            background: #13233d;
        }
        .theme-mode-switch.is-light .theme-switch-thumb {
            transform: translateX(38px);
        }
        .job-list-open-btn {
            min-height: 36px;
            padding: 8px 12px;
            font-size: 13px;
            border: 1px solid var(--theme-chip-border);
            border-radius: 10px;
            background: var(--theme-chip-bg);
            color: var(--theme-chip-text);
        }
        .job-list-open-btn:hover {
            background: var(--theme-chip-hover);
            color: var(--theme-chip-text);
        }
        .alerts {
            width: min(980px, 100%);
            display: grid;
            gap: 10px;
        }
        label {
            display: block;
            font-weight: 600;
            margin-bottom: 8px;
        }
        input[type="url"], select, textarea {
            width: 100%;
            border: 1px solid var(--line);
            border-radius: 10px;
            padding: 10px 12px;
            font-size: 15px;
            background: transparent;
            color: inherit;
            margin-bottom: 12px;
        }
        textarea {
            resize: vertical;
            min-height: 120px;
            line-height: 1.45;
        }
        .url-input-wrap {
            position: relative;
            margin-bottom: 0;
        }
        .url-input-wrap input[type="url"] {
            margin-bottom: 0;
            padding-right: 46px;
        }
        .clear-url-link {
            position: absolute;
            top: 50%;
            right: 10px;
            width: 24px;
            height: 24px;
            transform: translateY(-50%);
            border-radius: 999px;
            background: var(--btn);
            border: 1px solid #7aa6df;
            color: #ffffff;
            text-decoration: none;
            font-size: 15px;
            font-weight: 700;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            line-height: 1;
        }
        .clear-url-link:hover {
            background: var(--btn-h);
        }
        .inline-clear-btn {
            position: absolute;
            top: 50%;
            right: 10px;
            width: 24px;
            height: 24px;
            transform: translateY(-50%);
            border-radius: 999px;
            background: var(--btn);
            border: 1px solid #7aa6df;
            color: #ffffff;
            font-size: 15px;
            font-weight: 700;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            line-height: 1;
            padding: 0;
            cursor: pointer;
        }
        .inline-clear-btn:hover {
            background: var(--btn-h);
        }
        .slots {
            width: min(980px, 100%);
            display: flex;
            flex-direction: column;
            align-items: stretch;
            justify-content: flex-start;
            margin-top: 72px;
            gap: 16px;
        }
        .slot-card {
            width: 100%;
            border: 1px solid var(--line);
            border-radius: 12px;
            padding: 12px;
            background: var(--slot-bg);
            box-shadow: var(--card-shadow);
        }
        .slot-head {
            margin-bottom: 6px;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .slot-head h2 {
            margin: 0;
            font-size: 18px;
            min-width: 0;
            flex: 1 1 auto;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        .slot-duration {
            flex: 0 0 auto;
            min-width: 86px;
            text-align: right;
            font-size: 14px;
            font-weight: 700;
            color: var(--muted);
            letter-spacing: 0.02em;
        }
        .slot-output-summary {
            margin: 0 0 10px;
            font-size: 12px;
            font-weight: 600;
            color: var(--muted);
            letter-spacing: 0.01em;
        }
        .url-row {
            display: flex;
            gap: 10px;
            align-items: flex-end;
            margin-bottom: 10px;
        }
        .url-field {
            flex: 1 1 auto;
            min-width: 0;
        }
        .url-start-btn {
            flex: 0 0 auto;
            min-width: 120px;
            height: 42px;
            margin-bottom: 0;
            align-self: flex-end;
        }
        .url-start-btn.is-processing {
            background: #1d8f49;
            color: #ecfff3;
            cursor: default;
        }
        .url-start-btn.is-processing:hover {
            background: #1d8f49;
        }
        .url-start-btn:disabled:not(.is-processing) {
            background: #2a4d7c;
            color: #c4d7f4;
            cursor: not-allowed;
            opacity: 0.86;
        }
        .url-start-btn:disabled:not(.is-processing):hover {
            background: #2a4d7c;
        }
        select {
            background: var(--select-bg);
            color: var(--select-text);
            border-color: var(--select-border);
            appearance: none;
            -webkit-appearance: none;
            -moz-appearance: none;
            scrollbar-width: none;
            -ms-overflow-style: none;
        }
        select::-webkit-scrollbar {
            width: 0;
            height: 0;
            display: none;
        }
        option {
            background: var(--option-bg);
            color: var(--option-text);
        }
        button {
            border: none;
            border-radius: 10px;
            padding: 10px 14px;
            font-size: 15px;
            font-weight: 600;
            cursor: pointer;
            color: var(--btn-text);
            background: var(--btn);
        }
        button:hover { background: var(--btn-h); }
        .theme-controls .theme-button {
            min-height: 36px;
            padding: 8px 12px;
            font-size: 13px;
            border: 1px solid var(--theme-chip-border);
            border-radius: 10px;
            text-align: left;
        }
        .theme-controls .theme-button {
            background: var(--theme-chip-bg);
            color: var(--theme-chip-text);
        }
        .theme-controls .theme-button:hover {
            background: var(--theme-chip-hover);
            color: var(--theme-chip-text);
        }
        .alert {
            border-radius: 10px;
            padding: 10px 12px;
            margin-bottom: 12px;
            font-weight: 600;
        }
        .ok { color: var(--ok); background: var(--ok-bg); }
        .error { color: var(--err); background: var(--err-bg); }
        .warn {
            color: #7c2d12;
            background: #ffedd5;
        }
        .grid {
            display: grid;
            grid-template-columns: 1fr 180px 180px;
            gap: 12px;
            align-items: end;
        }
        .slot-grid.is-mp4 {
            grid-template-columns: 1fr 180px;
        }
        .grid > div {
            display: flex;
            flex-direction: column;
            justify-content: flex-end;
        }
        @media (max-width: 760px) {
            .grid { grid-template-columns: 1fr; }
        }
        .slot-grid {
            margin-top: 6px;
        }
        .bottom-notices {
            margin-top: 6px;
            display: flex;
            justify-content: space-between;
            align-items: flex-end;
            gap: 14px;
            color: var(--muted);
            font-size: 13px;
        }
        .notice-left,
        .notice-right {
            margin: 0;
        }
        .notice-right {
            text-align: right;
            white-space: nowrap;
        }
        .prozess-zeile {
            position: relative;
            z-index: 1;
            margin-top: 14px;
            background: #1c3f6d;
            color: #e8f1ff;
            border: 1px solid #4f78aa;
            border-radius: 12px;
            padding: 8px 14px 10px;
            display: block;
            font-size: 13px;
        }
        .prozess-kopf {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            gap: 12px;
            margin-bottom: 6px;
        }
        .prozess-meta {
            display: inline-flex;
            align-items: center;
            gap: 12px;
            white-space: nowrap;
            font-weight: 600;
            font-size: 12px;
        }
        .prozess-prozent {
            min-width: 44px;
            text-align: right;
        }
        .prozess-balken {
            width: 100%;
            height: 10px;
            border-radius: 99px;
            background: rgba(0, 87, 146, 0.55);
            overflow: hidden;
        }
        .prozess-fuellung {
            height: 100%;
            width: 0%;
            background: linear-gradient(90deg, #38bdf8, #60a5fa);
            transition: width 280ms linear;
            background-size: 220% 100%;
        }
        .prozess-zeile.status-running {
            background: #1c3f6d;
            border-color: #4f78aa;
        }
        .prozess-zeile.status-success {
            background: #14532d;
            border-color: #22c55e;
            color: #e8f1ff;
        }
        .prozess-zeile.status-error {
            background: #7f1d1d;
            border-color: #ef4444;
        }
        .prozess-zeile.status-running .prozess-fuellung {
            background: linear-gradient(110deg, #2f7ae0 0%, #5bb5ff 34%, #9ccfff 50%, #5bb5ff 66%, #2f7ae0 100%);
            animation: progress-shimmer 2.6s linear infinite, progress-pulse 3.8s ease-in-out infinite;
        }
        .prozess-zeile.status-running.is-near-complete .prozess-fuellung {
            background: linear-gradient(110deg, #2f7ae0 0%, #6ab8ff 28%, #ffd76a 58%, #f6bb51 100%);
            animation: progress-shimmer 2.2s linear infinite, progress-pulse 3.4s ease-in-out infinite;
        }
        .prozess-zeile.status-success .prozess-fuellung {
            background: linear-gradient(90deg, #22c55e, #86efac);
            animation: none;
        }
        .prozess-zeile.status-error .prozess-fuellung {
            background: linear-gradient(90deg, #ef4444, #fca5a5);
            animation: none;
        }
        @keyframes progress-shimmer {
            0% { background-position: 0% 50%; }
            100% { background-position: 100% 50%; }
        }
        @keyframes progress-pulse {
            0%, 100% { filter: saturate(1) brightness(1); }
            50% { filter: saturate(1.05) brightness(1.03); }
        }
        @media (prefers-reduced-motion: reduce) {
            .prozess-fuellung {
                animation: none !important;
            }
        }
        .prozess-text {
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            min-width: 0;
            flex: 1 1 auto;
        }
        .small-note {
            margin-top: -6px;
            margin-bottom: 10px;
            color: var(--muted);
            font-size: 12px;
        }
        .modal-backdrop {
            position: fixed;
            inset: 0;
            z-index: 110;
            background: rgba(0, 0, 0, 0.6);
            backdrop-filter: blur(4px);
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 16px;
            opacity: 0;
            pointer-events: none;
            transition: opacity 240ms ease;
        }
        .modal-backdrop.sichtbar {
            opacity: 1;
            pointer-events: auto;
        }
        .modal-card {
            background: var(--card);
            color: var(--text);
            border: 1px solid var(--line);
            border-radius: 16px;
            box-shadow: 0 20px 44px rgba(2, 6, 23, 0.38);
            width: min(560px, 100%);
            transform: scale(0.95);
            transition: transform 240ms ease;
            overflow: hidden;
        }
        .modal-card.modal-card-large {
            width: min(900px, 100%);
            max-height: 90vh;
            display: flex;
            flex-direction: column;
        }
        .modal-backdrop.sichtbar .modal-card {
            transform: scale(1);
        }
        .modal-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            padding: 14px 16px;
            border-bottom: 1px solid var(--line);
        }
        .modal-title {
            margin: 0;
            font-size: 18px;
            font-weight: 700;
        }
        .modal-close {
            background: transparent;
            color: var(--muted);
            border: 0;
            font-size: 22px;
            line-height: 1;
            padding: 2px 4px;
            cursor: pointer;
        }
        .modal-close:hover {
            color: var(--text);
            background: transparent;
        }
        .modal-content {
            padding: 16px;
            color: var(--text);
            font-size: 15px;
        }
        .joblist-content {
            display: flex;
            flex-direction: column;
            gap: 12px;
            overflow-y: auto;
            max-height: calc(90vh - 140px);
        }
        .joblist-defaults {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
        }
        .joblist-defaults .field {
            flex: 1 1 180px;
            min-width: 150px;
        }
        .joblist-output-summary {
            border: 1px solid var(--line);
            border-radius: 10px;
            padding: 8px 10px;
            background: rgba(56, 189, 248, 0.1);
            color: var(--text);
            font-size: 13px;
            font-weight: 600;
        }
        .joblist-actions {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
        }
        .joblist-feedback {
            border-radius: 10px;
            padding: 8px 10px;
            border: 1px solid var(--line);
            background: rgba(62, 114, 187, 0.12);
            color: var(--text);
            font-size: 13px;
        }
        .joblist-feedback.is-ok {
            border-color: #22c55e;
            background: rgba(34, 197, 94, 0.14);
        }
        .joblist-feedback.is-warn {
            border-color: #f59e0b;
            background: rgba(245, 158, 11, 0.16);
        }
        .joblist-feedback.is-error {
            border-color: #ef4444;
            background: rgba(239, 68, 68, 0.16);
        }
        .joblist-stats {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
        }
        .joblist-stat {
            border: 1px solid var(--line);
            border-radius: 999px;
            padding: 4px 10px;
            font-size: 12px;
            color: var(--muted);
        }
        .joblist-items {
            border: 1px solid var(--line);
            border-radius: 12px;
            background: rgba(18, 52, 92, 0.08);
            min-height: 120px;
            max-height: 320px;
            overflow-y: auto;
            padding: 8px;
            display: flex;
            flex-direction: column;
            gap: 8px;
        }
        .joblist-empty {
            color: var(--muted);
            font-size: 13px;
            padding: 6px;
        }
        .joblist-item {
            border: 1px solid var(--line);
            border-radius: 10px;
            padding: 8px;
            display: flex;
            gap: 10px;
            justify-content: space-between;
            align-items: flex-start;
            background: var(--card);
        }
        .joblist-item.status-failed {
            border-color: #ef4444;
            background: rgba(239, 68, 68, 0.08);
        }
        .joblist-item.status-running,
        .joblist-item.status-scanning,
        .joblist-item.status-assigned {
            border-color: #60a5fa;
            background: rgba(96, 165, 250, 0.09);
        }
        .joblist-item-main {
            min-width: 0;
            flex: 1 1 auto;
        }
        .joblist-item-url {
            font-size: 13px;
            font-weight: 600;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        .joblist-item-meta {
            margin-top: 4px;
            display: inline-flex;
            flex-wrap: wrap;
            gap: 6px;
            font-size: 12px;
            color: var(--muted);
        }
        .joblist-item-msg {
            margin-top: 4px;
            font-size: 12px;
            color: var(--muted);
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        .joblist-badge {
            padding: 2px 8px;
            border-radius: 999px;
            border: 1px solid var(--line);
            font-size: 11px;
            text-transform: uppercase;
            letter-spacing: 0.04em;
        }
        .joblist-badge-format {
            border-color: #60a5fa;
            color: #bfdbfe;
            background: rgba(59, 130, 246, 0.16);
        }
        .joblist-item-actions {
            display: inline-flex;
            gap: 6px;
            flex: 0 0 auto;
        }
        .joblist-mini {
            min-height: 30px;
            padding: 6px 10px;
            font-size: 12px;
            border-radius: 8px;
        }
        .joblist-mini.is-danger {
            background: #b91c1c;
            color: #fee2e2;
        }
        .joblist-mini.is-danger:hover {
            background: #991b1b;
        }
        .modal-footer {
            padding: 12px 16px;
            border-top: 1px solid var(--line);
            display: flex;
            justify-content: flex-end;
            gap: 10px;
        }
        .modal-secondary {
            background: #64748b;
            color: #eaf8ff;
        }
        .modal-secondary:hover {
            background: #475569;
            color: #eaf8ff;
        }
        @media (max-width: 760px) {
            body {
                padding: 14px;
            }
            .app-shell {
                min-height: calc(100vh - 28px);
            }
            .main-center {
                justify-content: flex-start;
                padding-top: 8px;
            }
            .header-row {
                padding-right: 170px;
            }
            .header-text {
                width: 100%;
            }
            .slots {
                justify-content: flex-start;
                margin-top: 48px;
                width: 100%;
            }
            h1 {
                font-size: clamp(21px, 7vw, 28px);
                line-height: 1.1;
                margin-bottom: 6px;
            }
            .header-subtitle {
                font-size: clamp(9px, 2.8vw, 11px);
                line-height: 1.4;
            }
            .theme-menu-wrap {
                top: 8px;
                right: 10px;
                gap: 6px;
            }
            .theme-controls {
                display: none;
            }
            .theme-mode-switch {
                display: inline-flex;
                width: 66px;
                height: 34px;
            }
            .theme-mode-switch.is-light .theme-switch-thumb {
                transform: translateX(32px);
            }
            .job-list-open-btn {
                min-height: 34px;
                padding: 7px 10px;
                font-size: 12px;
            }
            .joblist-actions {
                flex-direction: column;
            }
            .joblist-actions button {
                width: 100%;
            }
            .joblist-item {
                flex-direction: column;
            }
            .joblist-item-actions {
                width: 100%;
            }
            .joblist-item-actions .joblist-mini {
                flex: 1 1 auto;
            }
            .url-row {
                flex-direction: column;
                align-items: stretch;
            }
            .url-start-btn {
                width: 100%;
            }
            .bottom-notices {
                flex-direction: column;
                align-items: flex-start;
                gap: 8px;
            }
            .notice-right {
                text-align: left;
                white-space: normal;
            }
        }
    </style>
</head>
<body>
<div class="app-shell">
    <div class="main-center">
        <div class="header-row">
            <div class="header-text">
                <h1>Embedded Video and MP3 downloader</h1>
                <p class="header-subtitle">Use Slot A and Slot B for independent parallel downloads. Paste a video URL to auto-scan available qualities in each slot.<br>For the highest quality choose Best quality (slow); for faster processing choose High quality (balanced).</p>
            </div>
            <div class="theme-menu-wrap">
                <div id="theme-controls" class="theme-controls">
                    <button type="button" id="theme-toggle" class="theme-button">Switch to Light</button>
                </div>
                <button type="button" id="job-list-open" class="job-list-open-btn">Jobs</button>
                <button type="button" id="theme-mode-switch" class="theme-mode-switch" aria-label="Current theme: Dark. Switch to Light." aria-pressed="false">
                    <span class="theme-switch-icon" aria-hidden="true">🌙</span>
                    <span class="theme-switch-icon" aria-hidden="true">☀</span>
                    <span class="theme-switch-thumb" aria-hidden="true"></span>
                </button>
            </div>
        </div>

        <div class="alerts">
            <?php if (trim($fehler) !== ''): ?>
                <div class="alert error"><?= h($fehler) ?></div>
            <?php endif; ?>

            <?php if (trim($warnung) !== ''): ?>
                <div class="alert warn"><?= h($warnung) ?></div>
            <?php endif; ?>
        </div>

        <div class="slots">
            <section class="slot-card" aria-labelledby="slot-a-title">
                <div class="slot-head">
                    <h2 id="slot-a-title">Download Slot A</h2>
                    <span id="slot-a-duration" class="slot-duration"></span>
                </div>
                <p id="slot-output-summary-a" class="slot-output-summary">Final file: MP3 | 320 kbps | High quality (balanced)</p>
                <div class="url-row">
                    <div class="url-field">
                        <label for="seiten_url">Video URL 1</label>
                        <div class="url-input-wrap">
                            <input id="seiten_url" name="seiten_url" type="url" placeholder="https://example.com/video-page" value="<?= h($seitenUrl) ?>">
                            <button type="button" class="inline-clear-btn" id="slot1_clear" aria-label="Clear URL 1" title="Clear URL 1">x</button>
                        </div>
                    </div>
                    <button type="button" class="url-start-btn" id="start-url-1">Start download</button>
                </div>
                <div class="grid slot-grid">
                    <div>
                        <label id="qualitaet_label_a" for="qualitaet_index_a">Preferred bitrate/resolution</label>
                        <select id="qualitaet_index_a">
                            <option value="" disabled selected>No analyzed options yet (paste URL and press Enter)</option>
                        </select>
                    </div>
                    <div>
                        <label for="ziel_format_a">Download format</label>
                        <select id="ziel_format_a" required>
                            <option value="mp4" selected>mp4</option>
                            <option value="mp3" <?= $ffmpegVerfuegbar ? '' : 'disabled' ?>>mp3<?= $ffmpegVerfuegbar ? '' : ' (ffmpeg required)' ?></option>
                        </select>
                    </div>
                    <div class="mp3-only-col" id="encoding-speed-col-a">
                        <label for="encoding_speed_a" title="Controls ffmpeg LAME compression_level.&#10;&#10;Best quality (0): Highest quality, slowest.&#10;High quality (2): Near-best quality, much faster. Recommended.&#10;Fast (9): Standard quality, fastest.">MP3 encoding speed</label>
                        <select id="encoding_speed_a">
                            <option value="0">Best quality (slow)</option>
                            <option value="2" selected>High quality (balanced)</option>
                            <option value="9">Fast (standard)</option>
                        </select>
                    </div>
                </div>
                <div class="small-note">Settings are saved automatically for this slot.</div>
                <div id="prozess-zeile-a" class="prozess-zeile status-running" aria-live="polite">
                    <div class="prozess-kopf">
                        <div id="prozess-text-a" class="prozess-text">Ready.</div>
                        <div class="prozess-meta">
                            <span id="prozess-prozent-a" class="prozess-prozent">0%</span>
                        </div>
                    </div>
                    <div class="prozess-balken" aria-hidden="true">
                        <div id="prozess-fuellung-a" class="prozess-fuellung"></div>
                    </div>
                </div>
            </section>
            <section class="slot-card" aria-labelledby="slot-b-title">
            <div class="slot-head">
                <h2 id="slot-b-title">Download Slot B</h2>
                <span id="slot-b-duration" class="slot-duration"></span>
            </div>
            <p id="slot-output-summary-b" class="slot-output-summary">Final file: MP3 | 320 kbps | High quality (balanced)</p>
            <div class="url-row">
                <div class="url-field">
                    <label for="seiten_url_2">Video URL 2</label>
                    <div class="url-input-wrap">
                        <input id="seiten_url_2" name="seiten_url_2" type="url" placeholder="https://example.com/video-page" value="<?= h($seitenUrl2) ?>">
                        <button type="button" class="inline-clear-btn" id="slot2_clear" aria-label="Clear URL 2" title="Clear URL 2">x</button>
                    </div>
                </div>
                <button type="button" class="url-start-btn" id="start-url-2">Start download</button>
            </div>
            <div class="grid slot-grid">
                <div>
                    <label id="qualitaet_label_b" for="qualitaet_index_b">Preferred bitrate/resolution</label>
                    <select id="qualitaet_index_b">
                        <option value="" disabled selected>No analyzed options yet (paste URL and press Enter)</option>
                    </select>
                </div>
                <div>
                    <label for="ziel_format_b">Download format</label>
                    <select id="ziel_format_b" required>
                        <option value="mp4" selected>mp4</option>
                        <option value="mp3" <?= $ffmpegVerfuegbar ? '' : 'disabled' ?>>mp3<?= $ffmpegVerfuegbar ? '' : ' (ffmpeg required)' ?></option>
                    </select>
                </div>
                <div class="mp3-only-col" id="encoding-speed-col-b">
                    <label for="encoding_speed_b" title="Controls ffmpeg LAME compression_level.&#10;&#10;Best quality (0): Highest quality, slowest.&#10;High quality (2): Near-best quality, much faster. Recommended.&#10;Fast (9): Standard quality, fastest.">MP3 encoding speed</label>
                    <select id="encoding_speed_b">
                        <option value="0">Best quality (slow)</option>
                        <option value="2" selected>High quality (balanced)</option>
                        <option value="9">Fast (standard)</option>
                    </select>
                </div>
            </div>
            <div class="small-note">Settings are saved automatically for this slot.</div>
            <div id="prozess-zeile-b" class="prozess-zeile status-running" aria-live="polite">
                <div class="prozess-kopf">
                    <div id="prozess-text-b" class="prozess-text">Ready.</div>
                    <div class="prozess-meta">
                        <span id="prozess-prozent-b" class="prozess-prozent">0%</span>
                    </div>
                </div>
                <div class="prozess-balken" aria-hidden="true">
                    <div id="prozess-fuellung-b" class="prozess-fuellung"></div>
                </div>
            </div>
            </section>
        </div>
    </div>

    <div class="bottom-notices">
        <p class="notice-left">Only download content that you have the rights to download.</p>
        <p class="notice-right">Copyright &copy; 2026 Miklos Marinov.</p>
    </div>
</div>
<form method="post" id="download-form-a" target="download-frame-a" style="display:none;">
    <input type="hidden" name="aktion" id="download_aktion_a" value="herunterladen_option">
    <input type="hidden" name="seiten_url" id="download_seiten_url_a" value="">
    <input type="hidden" name="option_payload" id="download_option_payload_a" value="">
    <input type="hidden" name="job_id" id="job_id_a" value="">
    <input type="hidden" name="praeferenz_hoehe" id="praeferenz_hoehe_a" value="0">
    <input type="hidden" name="praeferenz_bitrate" id="praeferenz_bitrate_a" value="0">
    <input type="hidden" name="ziel_format" id="download_ziel_format_a" value="mp4">
    <input type="hidden" name="compression_level" id="compression_level_a" value="2">
</form>
<form method="post" id="download-form-b" target="download-frame-b" style="display:none;">
    <input type="hidden" name="aktion" id="download_aktion_b" value="herunterladen_option">
    <input type="hidden" name="seiten_url" id="download_seiten_url_b" value="">
    <input type="hidden" name="option_payload" id="download_option_payload_b" value="">
    <input type="hidden" name="job_id" id="job_id_b" value="">
    <input type="hidden" name="praeferenz_hoehe" id="praeferenz_hoehe_b" value="0">
    <input type="hidden" name="praeferenz_bitrate" id="praeferenz_bitrate_b" value="0">
    <input type="hidden" name="ziel_format" id="download_ziel_format_b" value="mp4">
    <input type="hidden" name="compression_level" id="compression_level_b" value="2">
</form>
<iframe name="download-frame-a" id="download-frame-a" style="display:none;"></iframe>
<iframe name="download-frame-b" id="download-frame-b" style="display:none;"></iframe>
<div id="joblist-modal" class="modal-backdrop" hidden>
    <div class="modal-card modal-card-large" role="dialog" aria-modal="true" aria-labelledby="joblist-modal-title">
        <div class="modal-header">
            <h3 id="joblist-modal-title" class="modal-title">Job List</h3>
            <button type="button" id="joblist-modal-close" class="modal-close" aria-label="Close">x</button>
        </div>
        <div class="modal-content joblist-content">
            <div class="joblist-defaults">
                <div class="field">
                    <label for="job-default-format">Default output</label>
                    <select id="job-default-format">
                        <option value="mp3" selected>Audio (MP3)</option>
                        <option value="mp4">Video (MP4)</option>
                    </select>
                </div>
                <div class="field">
                    <label for="job-default-mp3-bitrate">MP3 bitrate</label>
                    <select id="job-default-mp3-bitrate">
                        <option value="96">96 kbps</option>
                        <option value="128">128 kbps</option>
                        <option value="160">160 kbps</option>
                        <option value="192">192 kbps</option>
                        <option value="256">256 kbps</option>
                        <option value="320" selected>320 kbps</option>
                    </select>
                </div>
                <div class="field">
                    <label for="job-default-mp3-speed">MP3 encoding speed</label>
                    <select id="job-default-mp3-speed">
                        <option value="0">Best quality (slow)</option>
                        <option value="2" selected>High quality (balanced)</option>
                        <option value="9">Fast (standard)</option>
                    </select>
                </div>
                <div class="field">
                    <label for="job-default-mp4-height">MP4 target resolution</label>
                    <select id="job-default-mp4-height">
                        <option value="720">720p</option>
                        <option value="1080" selected>1080p (Full HD)</option>
                        <option value="1440">1440p</option>
                        <option value="2160">2160p (4K)</option>
                    </select>
                </div>
                <div class="field">
                    <label for="job-default-mp4-mode">MP4 bitrate profile</label>
                    <select id="job-default-mp4-mode">
                        <option value="average" selected>Average</option>
                        <option value="highest">Highest</option>
                    </select>
                </div>
            </div>
            <div id="job-output-summary" class="joblist-output-summary">Current default output: Audio (MP3). Jobs will download MP3 files.</div>

            <div>
                <label for="job-list-input">URLs (one per line)</label>
                <textarea id="job-list-input" placeholder="https://example.com/video-1&#10;https://example.com/video-2"></textarea>
            </div>

            <div class="joblist-actions">
                <button type="button" id="job-list-add">Add jobs</button>
                <button type="button" id="job-list-start">Start auto</button>
                <button type="button" id="job-list-stop" class="modal-secondary">Stop auto</button>
                <button type="button" id="job-list-clear-failed" class="modal-secondary">Clear failed</button>
            </div>

            <div id="joblist-feedback" class="joblist-feedback">Ready. Paste URLs and click Add jobs.</div>

            <div class="joblist-stats">
                <span class="joblist-stat">Pending: <strong id="joblist-stat-pending">0</strong></span>
                <span class="joblist-stat">Running: <strong id="joblist-stat-running">0</strong></span>
                <span class="joblist-stat">Failed: <strong id="joblist-stat-failed">0</strong></span>
                <span class="joblist-stat">Completed: <strong id="joblist-stat-completed">0</strong></span>
            </div>

            <div id="joblist-items" class="joblist-items" role="list"></div>
        </div>
        <div class="modal-footer">
            <button type="button" id="joblist-close-footer" class="modal-secondary">Close</button>
        </div>
    </div>
</div>
<div id="alert-modal" class="modal-backdrop" hidden>
    <div class="modal-card" role="dialog" aria-modal="true" aria-labelledby="alert-modal-title">
        <div class="modal-header">
            <h3 id="alert-modal-title" class="modal-title">Missing settings</h3>
            <button type="button" id="alert-modal-close" class="modal-close" aria-label="Close">x</button>
        </div>
        <div class="modal-content" id="alert-modal-message">
            Please set your download settings first.
        </div>
        <div class="modal-footer">
            <button type="button" id="alert-modal-ok">OK</button>
        </div>
    </div>
</div>
<div id="duplicate-modal" class="modal-backdrop" hidden>
    <div class="modal-card" role="dialog" aria-modal="true" aria-labelledby="duplicate-modal-title">
        <div class="modal-header">
            <h3 id="duplicate-modal-title" class="modal-title">Duplicate download detected</h3>
            <button type="button" id="duplicate-modal-close" class="modal-close" aria-label="Close">x</button>
        </div>
        <div class="modal-content" id="duplicate-modal-message">
            This URL was already downloaded successfully in this session. Do you want to download it again?
        </div>
        <div class="modal-footer">
            <button type="button" id="duplicate-modal-cancel" class="modal-secondary">Cancel</button>
            <button type="button" id="duplicate-modal-confirm">Download again</button>
        </div>
    </div>
</div>
<script>
window.YDOWN_CONFIG = {
    initialOptions: <?= json_encode($optionenFuerFrontend, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>,
    ffmpegAvailable: <?= $ffmpegVerfuegbar ? 'true' : 'false' ?>
};
</script>
<?php
$jsDateien = [
    'js/ydown-namespace.js',
    'js/config.js',
    'js/core/storage-service.js',
    'js/core/url-utils.js',
    'js/core/base-utils.js',
    'js/services/progress-mapper.js',
    'js/services/api-client.js',
    'js/services/download-history-service.js',
    'js/services/job-list-store.js',
    'js/services/job-scheduler.js',
    'js/ui/modal-controller.js',
    'js/ui/theme-controller.js',
    'js/ui/job-list-modal-controller.js',
    'js/slots/slot-controller.js',
    'js/app-controller.js',
    'js/main.js',
];
foreach ($jsDateien as $jsDatei):
?>
<script src="<?= htmlspecialchars(auto_version($jsDatei), ENT_QUOTES, 'UTF-8') ?>"></script>
<?php endforeach; ?>
</body>
</html>
