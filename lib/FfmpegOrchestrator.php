<?php
declare(strict_types=1);

/**
 * Intelligent FFmpeg orchestrator — auto-detects GPU/CPU capabilities,
 * builds optimal commands, and implements encoder fallback.
 *
 * Key design decisions (based on research):
 * - Audio encoding (MP3/AAC/Opus) is ALWAYS CPU — no production GPU audio encoder exists
 * - GPU acceleration applies to VIDEO codecs only (H.264, HEVC, AV1)
 * - Encoder priority: NVENC > QSV > AMF > software (libx264/libx265)
 * - -threads optimization based on detected CPU core count
 *
 * @see docs/11_ffmpeg_gpu_multithreaded_research_2026-02-22.md
 */
final class FfmpegOrchestrator
{
    /** @var array<string, mixed>|null Cached hardware detection result */
    private ?array $cachedCapabilities = null;

    /** @var string|null Path to capabilities cache file */
    private ?string $cacheFile = null;

    /** Cache TTL in seconds (1 hour — hardware doesn't change often) */
    private const CACHE_TTL = 3600;

    /**
     * Video encoder priority — first match wins.
     * Each entry: [ffmpeg_encoder_name, hwaccel_type, display_name]
     */
    private const VIDEO_ENCODER_PRIORITY = [
        ['h264_nvenc',  'cuda',  'NVIDIA NVENC'],
        ['h264_qsv',   'qsv',   'Intel QSV'],
        ['h264_amf',   'amf',   'AMD AMF'],
        ['libx264',    null,     'Software (x264)'],
    ];

    private const HEVC_ENCODER_PRIORITY = [
        ['hevc_nvenc',  'cuda',  'NVIDIA NVENC'],
        ['hevc_qsv',   'qsv',   'Intel QSV'],
        ['hevc_amf',   'amf',   'AMD AMF'],
        ['libx265',    null,     'Software (x265)'],
    ];

    public function __construct(?string $cacheDir = null)
    {
        if ($cacheDir !== null && is_dir($cacheDir)) {
            $this->cacheFile = rtrim($cacheDir, '/\\') . DIRECTORY_SEPARATOR . 'ffmpeg_hw_cache.json';
        }
    }

    // ─── Hardware Detection ──────────────────────────────────────────

    /**
     * Detect all hardware capabilities. Uses cache if available.
     *
     * @return array{
     *     hwaccels: string[],
     *     encoders: array<string, bool>,
     *     decoders: array<string, bool>,
     *     cpu_cores: int,
     *     optimal_threads: int,
     *     gpu_vendor: string|null,
     *     detected_at: string
     * }
     */
    public function detectCapabilities(string $ffmpegPath): array
    {
        if ($this->cachedCapabilities !== null) {
            return $this->cachedCapabilities;
        }

        $cached = $this->loadCache();
        if ($cached !== null) {
            $this->cachedCapabilities = $cached;
            return $cached;
        }

        $capabilities = [
            'hwaccels' => $this->detectHwAccels($ffmpegPath),
            'encoders' => $this->detectEncoders($ffmpegPath),
            'decoders' => $this->detectDecoders($ffmpegPath),
            'cpu_cores' => $this->detectCpuCores(),
            'optimal_threads' => 0,
            'gpu_vendor' => null,
            'detected_at' => date('c'),
        ];

        $capabilities['optimal_threads'] = $this->calculateOptimalThreads($capabilities['cpu_cores']);
        $capabilities['gpu_vendor'] = $this->identifyGpuVendor($capabilities['encoders']);

        $this->cachedCapabilities = $capabilities;
        $this->saveCache($capabilities);

        return $capabilities;
    }

    /**
     * Force re-detection (ignores cache).
     */
    public function refreshCapabilities(string $ffmpegPath): array
    {
        $this->cachedCapabilities = null;
        $this->clearCache();
        return $this->detectCapabilities($ffmpegPath);
    }

    // ─── Command Builders ────────────────────────────────────────────

    /**
     * Build optimized MP3 conversion command.
     * Always CPU-based (no GPU audio encoder exists).
     * Adds -threads for multi-core utilization.
     */
    public function buildMp3Command(
        string $ffmpegPath,
        string $inputFile,
        string $outputFile,
        int $bitrate,
        int $compressionLevel
    ): string {
        $caps = $this->detectCapabilities($ffmpegPath);
        $threads = $caps['optimal_threads'];

        return sprintf(
            '%s -y -threads %d -i %s -vn -c:a libmp3lame -b:a %sk -compression_level %d -threads %d -progress pipe:1 -nostats %s 2>&1',
            escapeshellarg($ffmpegPath),
            $threads,          // global (decoding) threads
            escapeshellarg($inputFile),
            (string) $bitrate,
            $compressionLevel,
            $threads,          // encoder threads
            escapeshellarg($outputFile)
        );
    }

    /**
     * Build HLS-to-MP4 remux command (codec copy, no transcoding).
     * GPU is irrelevant here — no re-encoding happens.
     */
    public function buildHlsToMp4Command(
        string $ffmpegPath,
        string $inputUrl,
        string $outputFile
    ): string {
        return sprintf(
            '%s -y -loglevel error -i %s -c copy -movflags +faststart %s 2>&1',
            escapeshellarg($ffmpegPath),
            escapeshellarg($inputUrl),
            escapeshellarg($outputFile)
        );
    }

    /**
     * Build video transcode commands with GPU fallback chain.
     * Returns an ordered list of commands to try — first success wins.
     *
     * @param array{
     *     codec?: string,
     *     bitrate?: string,
     *     crf?: int,
     *     preset?: string,
     *     resolution?: string
     * } $options
     * @return array<int, array{command: string, encoder: string, label: string}>
     */
    public function buildVideoTranscodeCommands(
        string $ffmpegPath,
        string $inputFile,
        string $outputFile,
        array $options = []
    ): array {
        $caps = $this->detectCapabilities($ffmpegPath);
        $codec = $options['codec'] ?? 'h264';
        $priority = $codec === 'hevc' ? self::HEVC_ENCODER_PRIORITY : self::VIDEO_ENCODER_PRIORITY;

        $commands = [];
        foreach ($priority as [$encoder, $hwaccel, $label]) {
            if (!($caps['encoders'][$encoder] ?? false)) {
                continue;
            }

            $parts = [escapeshellarg($ffmpegPath), '-y'];

            // Hardware acceleration input (helps with decoding too)
            if ($hwaccel !== null) {
                $parts[] = '-hwaccel ' . escapeshellarg($hwaccel);
            }

            $parts[] = sprintf('-threads %d', $caps['optimal_threads']);
            $parts[] = '-i ' . escapeshellarg($inputFile);

            // Video encoding
            $parts[] = '-c:v ' . escapeshellarg($encoder);

            // Encoder-specific options
            $parts = array_merge($parts, $this->getEncoderOptions($encoder, $options));

            // Audio: always copy or re-encode with CPU
            $parts[] = '-c:a aac -b:a 192k';

            // Resolution
            if (!empty($options['resolution'])) {
                $parts[] = '-vf scale=' . escapeshellarg($options['resolution']);
            }

            $parts[] = '-movflags +faststart';
            $parts[] = '-progress pipe:1 -nostats';
            $parts[] = escapeshellarg($outputFile);
            $parts[] = '2>&1';

            $commands[] = [
                'command' => implode(' ', $parts),
                'encoder' => $encoder,
                'label' => $label,
            ];
        }

        return $commands;
    }

    /**
     * Execute a command with fallback chain.
     * Tries each command in order; returns on first success.
     *
     * @param array<int, array{command: string, encoder: string, label: string}> $commands
     * @return array{success: bool, encoder_used: string, label: string, output: string}
     */
    public function executeWithFallback(array $commands, string $outputFile, ?callable $onAttempt = null): array
    {
        foreach ($commands as $i => $entry) {
            if ($onAttempt !== null) {
                $onAttempt($entry['encoder'], $entry['label'], $i);
            }

            $output = [];
            $code = 1;
            @exec($entry['command'], $output, $code);

            if ($code === 0 && is_file($outputFile) && (int) filesize($outputFile) > 0) {
                return [
                    'success' => true,
                    'encoder_used' => $entry['encoder'],
                    'label' => $entry['label'],
                    'output' => implode("\n", $output),
                ];
            }

            // Clean up failed output before trying next
            if (is_file($outputFile)) {
                @unlink($outputFile);
            }
        }

        return [
            'success' => false,
            'encoder_used' => '',
            'label' => '',
            'output' => 'All encoders failed.',
        ];
    }

    // ─── Capabilities Report ─────────────────────────────────────────

    /**
     * Human-readable capabilities report (for diagnostics / API).
     *
     * @return array<string, mixed>
     */
    public function getCapabilitiesReport(string $ffmpegPath): array
    {
        $caps = $this->detectCapabilities($ffmpegPath);

        $availableVideoEncoders = [];
        foreach (self::VIDEO_ENCODER_PRIORITY as [$enc, $hw, $label]) {
            if ($caps['encoders'][$enc] ?? false) {
                $availableVideoEncoders[] = [
                    'encoder' => $enc,
                    'type' => $hw !== null ? 'hardware' : 'software',
                    'label' => $label,
                ];
            }
        }

        $bestVideoEncoder = $availableVideoEncoders[0] ?? null;

        return [
            'ffmpeg_path' => $ffmpegPath,
            'gpu_vendor' => $caps['gpu_vendor'],
            'cpu_cores' => $caps['cpu_cores'],
            'optimal_threads' => $caps['optimal_threads'],
            'hwaccels' => $caps['hwaccels'],
            'video_encoders' => $availableVideoEncoders,
            'best_video_encoder' => $bestVideoEncoder,
            'audio_note' => 'Audio encoding (MP3/AAC/Opus) is always CPU-based — no production GPU audio encoder exists.',
            'mp3_optimization' => sprintf(
                'Using %d threads for MP3 encoding (CPU cores: %d)',
                $caps['optimal_threads'],
                $caps['cpu_cores']
            ),
            'detected_at' => $caps['detected_at'],
        ];
    }

    // ─── Thread Calculation ──────────────────────────────────────────

    /**
     * Get optimal thread count for audio encoding.
     */
    public function getOptimalAudioThreads(string $ffmpegPath): int
    {
        $caps = $this->detectCapabilities($ffmpegPath);
        return $caps['optimal_threads'];
    }

    // ─── Private: Detection Methods ──────────────────────────────────

    /**
     * Query ffmpeg -hwaccels for available hardware acceleration APIs.
     *
     * @return string[]
     */
    private function detectHwAccels(string $ffmpegPath): array
    {
        $output = [];
        $code = 1;
        @exec(escapeshellarg($ffmpegPath) . ' -hwaccels -hide_banner 2>&1', $output, $code);

        if ($code !== 0) {
            return [];
        }

        $accels = [];
        $started = false;
        foreach ($output as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            if (str_contains(strtolower($line), 'hardware acceleration methods')) {
                $started = true;
                continue;
            }
            if ($started && $line !== '') {
                $accels[] = strtolower($line);
            }
        }

        return $accels;
    }

    /**
     * Detect which GPU/HW video encoders are available in this ffmpeg build.
     *
     * @return array<string, bool>
     */
    private function detectEncoders(string $ffmpegPath): array
    {
        $interesting = [
            // H.264
            'h264_nvenc', 'h264_qsv', 'h264_amf', 'libx264',
            // HEVC
            'hevc_nvenc', 'hevc_qsv', 'hevc_amf', 'libx265',
            // AV1
            'av1_nvenc', 'av1_qsv', 'av1_amf', 'libaom-av1', 'libsvtav1',
            // Audio (always software)
            'libmp3lame', 'aac', 'libopus', 'libvorbis', 'flac',
        ];

        $output = [];
        $code = 1;
        @exec(escapeshellarg($ffmpegPath) . ' -encoders -hide_banner 2>&1', $output, $code);

        if ($code !== 0) {
            // Fallback: assume basic software encoders are available
            return ['libx264' => true, 'libmp3lame' => true, 'aac' => true];
        }

        $allText = implode("\n", $output);
        $result = [];
        foreach ($interesting as $enc) {
            // FFmpeg encoder list format: " V..... h264_nvenc" or " A..... libmp3lame"
            $result[$enc] = (bool) preg_match('~^\s*[VAF][A-Z.]{5}\s+' . preg_quote($enc, '~') . '\s~m', $allText);
        }

        return $result;
    }

    /**
     * Detect available hardware decoders.
     *
     * @return array<string, bool>
     */
    private function detectDecoders(string $ffmpegPath): array
    {
        $interesting = [
            'h264_cuvid', 'hevc_cuvid', 'av1_cuvid',   // NVIDIA
            'h264_qsv', 'hevc_qsv', 'av1_qsv',         // Intel
        ];

        $output = [];
        $code = 1;
        @exec(escapeshellarg($ffmpegPath) . ' -decoders -hide_banner 2>&1', $output, $code);

        if ($code !== 0) {
            return [];
        }

        $allText = implode("\n", $output);
        $result = [];
        foreach ($interesting as $dec) {
            $result[$dec] = (bool) preg_match('~^\s*[VAF][A-Z.]{5}\s+' . preg_quote($dec, '~') . '\s~m', $allText);
        }

        return $result;
    }

    /**
     * Detect CPU core count.
     */
    private function detectCpuCores(): int
    {
        // Windows
        $cores = (int) trim((string) getenv('NUMBER_OF_PROCESSORS'));
        if ($cores > 0) {
            return $cores;
        }

        // WMIC fallback (Windows)
        $output = [];
        $code = 1;
        @exec('wmic cpu get NumberOfLogicalProcessors /value 2>NUL', $output, $code);
        if ($code === 0) {
            foreach ($output as $line) {
                if (preg_match('~NumberOfLogicalProcessors=(\d+)~i', $line, $m)) {
                    $val = (int) $m[1];
                    if ($val > 0) {
                        return $val;
                    }
                }
            }
        }

        // Linux/macOS fallback
        if (is_file('/proc/cpuinfo')) {
            $content = @file_get_contents('/proc/cpuinfo');
            if ($content !== false) {
                $count = substr_count($content, 'processor');
                if ($count > 0) {
                    return $count;
                }
            }
        }

        // Safe default
        return 4;
    }

    /**
     * Calculate optimal thread count.
     * For audio encoding, more threads beyond a certain point has diminishing returns.
     * libmp3lame itself is single-threaded, but FFmpeg pipeline threading helps.
     */
    private function calculateOptimalThreads(int $cpuCores): int
    {
        if ($cpuCores <= 2) {
            return $cpuCores;
        }

        // Use all cores but leave 1 for the system.
        // Cap at 16 — beyond that, thread scheduling overhead > benefit for audio.
        return min($cpuCores - 1, 16);
    }

    /**
     * Identify GPU vendor from available encoders.
     */
    private function identifyGpuVendor(array $encoders): ?string
    {
        if (!empty($encoders['h264_nvenc'])) {
            return 'NVIDIA';
        }
        if (!empty($encoders['h264_qsv'])) {
            return 'Intel';
        }
        if (!empty($encoders['h264_amf'])) {
            return 'AMD';
        }
        return null;
    }

    /**
     * Get encoder-specific options for video transcoding.
     *
     * @param array<string, mixed> $options
     * @return string[]
     */
    private function getEncoderOptions(string $encoder, array $options): array
    {
        $preset = $options['preset'] ?? 'medium';
        $crf = $options['crf'] ?? null;
        $bitrate = $options['bitrate'] ?? null;
        $parts = [];

        switch ($encoder) {
            // NVIDIA NVENC
            case 'h264_nvenc':
            case 'hevc_nvenc':
                $nvPresetMap = [
                    'ultrafast' => 'p1', 'superfast' => 'p2', 'veryfast' => 'p3',
                    'faster' => 'p4', 'fast' => 'p4', 'medium' => 'p5',
                    'slow' => 'p6', 'slower' => 'p7', 'veryslow' => 'p7',
                ];
                $parts[] = '-preset ' . ($nvPresetMap[$preset] ?? 'p5');
                $parts[] = '-tune hq';
                $parts[] = '-rc vbr';
                if ($crf !== null) {
                    $parts[] = '-cq ' . (int) $crf;
                } elseif ($bitrate !== null) {
                    $parts[] = '-b:v ' . escapeshellarg((string) $bitrate);
                } else {
                    $parts[] = '-cq 23';
                }
                break;

            // Intel QSV
            case 'h264_qsv':
            case 'hevc_qsv':
                $qsvPresetMap = [
                    'ultrafast' => 'veryfast', 'superfast' => 'veryfast',
                    'veryfast' => 'veryfast', 'faster' => 'faster', 'fast' => 'fast',
                    'medium' => 'medium', 'slow' => 'slow',
                    'slower' => 'slower', 'veryslow' => 'veryslow',
                ];
                $parts[] = '-preset ' . ($qsvPresetMap[$preset] ?? 'medium');
                if ($bitrate !== null) {
                    $parts[] = '-b:v ' . escapeshellarg((string) $bitrate);
                } else {
                    $parts[] = '-global_quality 23';
                }
                break;

            // AMD AMF
            case 'h264_amf':
            case 'hevc_amf':
                $amfQualityMap = [
                    'ultrafast' => 'speed', 'superfast' => 'speed', 'veryfast' => 'speed',
                    'faster' => 'speed', 'fast' => 'speed',
                    'medium' => 'balanced',
                    'slow' => 'quality', 'slower' => 'quality', 'veryslow' => 'quality',
                ];
                $parts[] = '-quality ' . ($amfQualityMap[$preset] ?? 'balanced');
                if ($bitrate !== null) {
                    $parts[] = '-b:v ' . escapeshellarg((string) $bitrate);
                } else {
                    $parts[] = '-rc cqp -qp_i 23 -qp_p 23';
                }
                break;

            // Software fallback
            case 'libx264':
            case 'libx265':
                $parts[] = '-preset ' . escapeshellarg($preset);
                if ($crf !== null) {
                    $parts[] = '-crf ' . (int) $crf;
                } elseif ($bitrate !== null) {
                    $parts[] = '-b:v ' . escapeshellarg((string) $bitrate);
                } else {
                    $parts[] = '-crf 23';
                }
                break;
        }

        return $parts;
    }

    // ─── Private: Cache ──────────────────────────────────────────────

    /**
     * @return array<string, mixed>|null
     */
    private function loadCache(): ?array
    {
        if ($this->cacheFile === null || !is_file($this->cacheFile)) {
            return null;
        }

        $content = @file_get_contents($this->cacheFile);
        if ($content === false) {
            return null;
        }

        $data = json_decode($content, true);
        if (!is_array($data) || empty($data['detected_at'])) {
            return null;
        }

        $detectedAt = strtotime((string) $data['detected_at']);
        if ($detectedAt === false || (time() - $detectedAt) > self::CACHE_TTL) {
            @unlink($this->cacheFile);
            return null;
        }

        return $data;
    }

    /**
     * @param array<string, mixed> $capabilities
     */
    private function saveCache(array $capabilities): void
    {
        if ($this->cacheFile === null) {
            return;
        }

        $dir = dirname($this->cacheFile);
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }

        @file_put_contents(
            $this->cacheFile,
            json_encode($capabilities, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
            LOCK_EX
        );
    }

    private function clearCache(): void
    {
        if ($this->cacheFile !== null && is_file($this->cacheFile)) {
            @unlink($this->cacheFile);
        }
    }
}
