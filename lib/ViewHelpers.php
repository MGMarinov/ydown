<?php
declare(strict_types=1);

if (!function_exists('auto_version')) {
    /**
     * Build a versioned asset URL using file modification time.
     * Example: js/main.js -> js/main.js?v=1700000000
     */
    function auto_version(string $file): string
    {
        $asset = trim($file);
        if ($asset === '') {
            return $file;
        }

        $queryPos = strpos($asset, '?');
        $pathOnly = $queryPos === false ? $asset : substr($asset, 0, $queryPos);
        $relativePath = ltrim(str_replace(['\\', '/'], DIRECTORY_SEPARATOR, (string) $pathOnly), DIRECTORY_SEPARATOR);

        $projectRoot = dirname(__DIR__);
        $absolutePath = $projectRoot . DIRECTORY_SEPARATOR . $relativePath;

        if (!is_file($absolutePath)) {
            return $asset;
        }

        $mtime = filemtime($absolutePath);
        if ($mtime === false) {
            return $asset;
        }

        $separator = $queryPos === false ? '?' : '&';
        return $asset . $separator . 'v=' . (string) $mtime;
    }
}

