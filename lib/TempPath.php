<?php
declare(strict_types=1);

final class TempPath
{
    public static function baseDir(): string
    {
        $kandidaten = [];

        $envPfad = trim((string) getenv('YDOWN_TEMP_DIR'));
        if ($envPfad !== '') {
            $kandidaten[] = $envPfad;
        }

        $kandidaten[] = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'tmp_runtime';
        $kandidaten[] = sys_get_temp_dir();

        foreach ($kandidaten as $kandidat) {
            $pfad = trim((string) $kandidat);
            if ($pfad === '') {
                continue;
            }

            if (!is_dir($pfad)) {
                @mkdir($pfad, 0775, true);
            }

            if (is_dir($pfad) && is_writable($pfad)) {
                return $pfad;
            }
        }

        throw new RuntimeException('No writable temporary directory is available.');
    }
}

