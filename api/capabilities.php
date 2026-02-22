<?php
declare(strict_types=1);

require __DIR__ . '/../lib/TempPath.php';
require __DIR__ . '/../lib/FfmpegOrchestrator.php';
require __DIR__ . '/../lib/FileDownloader.php';
require __DIR__ . '/../lib/YtDlpTool.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

$flags = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT;
if (defined('JSON_INVALID_UTF8_SUBSTITUTE')) {
    $flags |= JSON_INVALID_UTF8_SUBSTITUTE;
}

try {
    $downloader = new FileDownloader();

    if (!$downloader->istFfmpegVerfuegbar()) {
        echo json_encode([
            'ok' => false,
            'error' => 'ffmpeg not found. Install ffmpeg or set FFMPEG_PATH.',
        ], $flags);
        exit;
    }

    $orchestrator = $downloader->getOrchestrator();
    $refresh = isset($_GET['refresh']);

    // Use findeFfmpegPfad indirectly — we know it's available because istFfmpegVerfuegbar() passed.
    // Probe via a minimal detection call.
    $ffmpegPaths = [
        dirname(__DIR__) . DIRECTORY_SEPARATOR . 'bin' . DIRECTORY_SEPARATOR . 'ffmpeg.exe',
        dirname(__DIR__) . DIRECTORY_SEPARATOR . 'bin' . DIRECTORY_SEPARATOR . 'ffmpeg' . DIRECTORY_SEPARATOR . 'ffmpeg.exe',
        dirname(__DIR__) . DIRECTORY_SEPARATOR . 'bin' . DIRECTORY_SEPARATOR . 'ffmpeg' . DIRECTORY_SEPARATOR . 'bin' . DIRECTORY_SEPARATOR . 'ffmpeg.exe',
        (string) getenv('FFMPEG_PATH'),
        'D:\\ffmpeg\\bin\\ffmpeg.exe',
        'C:\\ffmpeg\\bin\\ffmpeg.exe',
        'D:\\wamp\\bin\\ffmpeg\\ffmpeg.exe',
        'C:\\Program Files\\ffmpeg\\bin\\ffmpeg.exe',
    ];

    $ffmpegPath = null;
    foreach ($ffmpegPaths as $candidate) {
        if ($candidate !== '' && is_file($candidate)) {
            $ffmpegPath = $candidate;
            break;
        }
    }

    if ($ffmpegPath === null) {
        $output = [];
        $code = 1;
        @exec('where ffmpeg 2>NUL', $output, $code);
        if ($code === 0 && isset($output[0]) && is_file($output[0])) {
            $ffmpegPath = trim($output[0]);
        }
    }

    if ($ffmpegPath === null) {
        echo json_encode([
            'ok' => false,
            'error' => 'ffmpeg path could not be resolved.',
        ], $flags);
        exit;
    }

    if ($refresh) {
        $orchestrator->refreshCapabilities($ffmpegPath);
    }

    $report = $orchestrator->getCapabilitiesReport($ffmpegPath);

    echo json_encode([
        'ok' => true,
        'capabilities' => $report,
    ], $flags);
} catch (Throwable $e) {
    echo json_encode([
        'ok' => false,
        'error' => $e->getMessage(),
    ], $flags);
}
