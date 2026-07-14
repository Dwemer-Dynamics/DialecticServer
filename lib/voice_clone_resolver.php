<?php
require_once __DIR__ . DIRECTORY_SEPARATOR . 'voice_clone_sync.php';

if (!function_exists('dialectic_voice_clone_root')) {
    function dialectic_voice_clone_root(?string $root = null): string
    {
        $root = trim(strval($root ?? ''));
        return $root !== '' ? rtrim($root, "\\/") : dirname(__DIR__);
    }
}

if (!function_exists('dialectic_voice_clone_normalize_key')) {
    function dialectic_voice_clone_normalize_key(string $value): string
    {
        $value = trim($value);
        $value = preg_replace('/\.wav$/i', '', $value);
        $value = strtolower($value);
        $value = preg_replace('/[^a-z0-9]+/', '', $value);
        return strval($value);
    }
}

if (!function_exists('dialectic_voice_clone_candidate_names')) {
    function dialectic_voice_clone_candidate_names(array $values): array
    {
        $candidates = [];
        foreach ($values as $value) {
            $value = trim(strval($value));
            if ($value === '') {
                continue;
            }

            $value = preg_replace('/\.wav$/i', '', $value);
            $simple = preg_replace('/[^A-Za-z0-9_\- ]+/', '', $value);
            $underscore = preg_replace('/[^A-Za-z0-9]+/', '_', $simple);
            $compact = preg_replace('/[^A-Za-z0-9]+/', '', $simple);

            foreach ([$value, $simple, $underscore, $compact, strtolower($underscore), strtolower($compact)] as $candidate) {
                $candidate = trim(strval($candidate), " \t\n\r\0\x0B_.-");
                if ($candidate !== '') {
                    $candidates[$candidate . '.wav'] = true;
                }
            }
        }

        return array_keys($candidates);
    }
}

if (!function_exists('dialectic_voice_clone_dirs')) {
    function dialectic_voice_clone_dirs(?string $root = null): array
    {
        $root = dialectic_voice_clone_root($root);
        $dirs = [
            $root . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'voices',
        ];

        foreach (['DIALECTIC_VOICE_SAMPLE_DIR', 'VOICE_SAMPLE_DIR', 'VOICE_DIR'] as $globalKey) {
            $value = trim(strval($GLOBALS[$globalKey] ?? ''));
            if ($value !== '') {
                $dirs[] = $value;
            }
        }

        $result = [];
        foreach ($dirs as $dir) {
            $real = realpath($dir);
            if ($real !== false && is_dir($real)) {
                $result[$real] = true;
            }
        }

        return array_keys($result);
    }
}

if (!function_exists('dialectic_voice_clone_sample_name')) {
    function dialectic_voice_clone_sample_name(string $value): string
    {
        $value = trim($value);
        $value = preg_replace('/\.wav$/i', '', $value);
        $value = strtolower($value);
        $value = preg_replace('/[^a-z0-9_+-]+/', '_', $value);
        return trim(strval($value), '_');
    }
}

if (!function_exists('dialectic_voice_clone_ffmpeg_path')) {
    function dialectic_voice_clone_ffmpeg_path(): string
    {
        foreach ([
            getenv('FFMPEG_PATH') ?: '',
            'C:\\Program Files\\ShareX\\ffmpeg.exe',
            'ffmpeg',
        ] as $candidate) {
            $candidate = trim(strval($candidate));
            if ($candidate === '') {
                continue;
            }

            if ($candidate === 'ffmpeg' || is_file($candidate)) {
                return $candidate;
            }
        }

        return 'ffmpeg';
    }
}

if (!function_exists('dialectic_voice_clone_game_roots')) {
    function dialectic_voice_clone_game_roots(): array
    {
        $roots = [];
        foreach ([
            'DIALECTIC_FNV_VOICE_ROOTS',
            'DIALECTIC_GAME_VOICE_ROOTS',
            'FNV_VOICE_SAMPLE_ROOTS',
        ] as $globalKey) {
            $value = $GLOBALS[$globalKey] ?? '';
            $values = is_array($value) ? $value : preg_split('/[;|]/', strval($value));
            foreach ($values ?: [] as $root) {
                $root = trim(strval($root), " \t\n\r\0\x0B\"'");
                if ($root !== '') {
                    $roots[] = $root;
                }
            }
        }

        foreach ([
            'C:\\Modlists\\Fallout TTW\\mods',
            'C:\\Modlists\\Fallout New Vegas\\mods',
        ] as $root) {
            $roots[] = $root;
        }

        $result = [];
        foreach ($roots as $root) {
            $real = realpath($root);
            if ($real !== false && is_dir($real)) {
                $result[$real] = true;
            }
        }

        return array_keys($result);
    }
}

if (!function_exists('dialectic_voice_clone_find_game_sample')) {
    function dialectic_voice_clone_find_game_sample(string $voiceId, array $options = []): string
    {
        static $cache = [];

        $voiceKey = dialectic_voice_clone_normalize_key($voiceId);
        if ($voiceKey === '') {
            return '';
        }

        $roots = dialectic_voice_clone_game_roots();
        $cacheKey = $voiceKey . '|' . implode('|', $roots);
        if (array_key_exists($cacheKey, $cache)) {
            return $cache[$cacheKey];
        }

        $bestPath = '';
        $bestSize = 0;
        foreach ($roots as $root) {
            try {
                $iterator = new RecursiveIteratorIterator(
                    new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
                    RecursiveIteratorIterator::SELF_FIRST
                );
            } catch (Throwable $e) {
                continue;
            }

            foreach ($iterator as $entry) {
                if (!$entry->isDir()) {
                    continue;
                }

                $dirName = dialectic_voice_clone_normalize_key($entry->getBasename());
                if ($dirName !== $voiceKey) {
                    continue;
                }

                $path = $entry->getPathname();
                $normalizedPath = strtolower(str_replace('/', '\\', $path));
                if (strpos($normalizedPath, '\\sound\\voice\\') === false) {
                    continue;
                }

                $files = @scandir($path);
                if (!is_array($files)) {
                    continue;
                }

                foreach ($files as $fileName) {
                    if ($fileName === '.' || $fileName === '..') {
                        continue;
                    }

                    $samplePath = $path . DIRECTORY_SEPARATOR . $fileName;
                    if (!is_file($samplePath)) {
                        continue;
                    }

                    $extension = strtolower(pathinfo($samplePath, PATHINFO_EXTENSION));
                    if (!in_array($extension, ['wav', 'ogg', 'xwm'], true)) {
                        continue;
                    }

                    $size = @filesize($samplePath);
                    if ($size > $bestSize) {
                        $bestSize = $size;
                        $bestPath = $samplePath;
                    }
                }
            }
        }

        $cache[$cacheKey] = $bestPath;
        return $bestPath;
    }
}

if (!function_exists('dialectic_voice_clone_materialize_game_sample')) {
    function dialectic_voice_clone_materialize_game_sample(string $voiceId, array $options = []): string
    {
        $root = dialectic_voice_clone_root($options['root'] ?? null);
        $sampleName = dialectic_voice_clone_sample_name($voiceId);
        if ($sampleName === '') {
            return '';
        }

        $voiceDir = $root . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'voices';
        if (!is_dir($voiceDir)) {
            @mkdir($voiceDir, 0775, true);
        }

        $target = $voiceDir . DIRECTORY_SEPARATOR . $sampleName . '.wav';
        if (is_file($target) && @filesize($target) > 44) {
            return realpath($target) ?: $target;
        }

        $source = dialectic_voice_clone_find_game_sample($voiceId, $options);
        if ($source === '') {
            return '';
        }

        $extension = strtolower(pathinfo($source, PATHINFO_EXTENSION));
        if ($extension === 'wav') {
            @copy($source, $target);
        } else {
            $ffmpeg = dialectic_voice_clone_ffmpeg_path();
            $nullDevice = strtoupper(substr(PHP_OS, 0, 3)) === 'WIN' ? 'NUL' : '/dev/null';
            $inputArgs = ($extension === 'xwm')
                ? '-f xwma -i ' . escapeshellarg($source)
                : '-i ' . escapeshellarg($source);
            $command = escapeshellarg($ffmpeg) . ' -v 0 -y ' . $inputArgs . ' -ar 22050 -ac 1 -sample_fmt s16 ' . escapeshellarg($target) . " >$nullDevice 2>$nullDevice";
            shell_exec($command);
        }

        if (is_file($target) && @filesize($target) > 44) {
            error_log("[Dialectic Voice] Materialized voice sample {$sampleName}.wav from {$source}");
            return realpath($target) ?: $target;
        }

        error_log("[Dialectic Voice] Failed to materialize voice sample {$sampleName}.wav from {$source}");
        return '';
    }
}

if (!function_exists('dialectic_voice_clone_wav_index')) {
    function dialectic_voice_clone_wav_index(?string $root = null): array
    {
        static $cache = [];
        $cacheKey = implode('|', dialectic_voice_clone_dirs($root));
        if (isset($cache[$cacheKey])) {
            return $cache[$cacheKey];
        }

        $index = [];
        foreach (dialectic_voice_clone_dirs($root) as $dir) {
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS)
            );

            foreach ($iterator as $file) {
                if (!$file->isFile() || strtolower($file->getExtension()) !== 'wav') {
                    continue;
                }

                $path = $file->getPathname();
                if (@filesize($path) <= 44) {
                    continue;
                }

                $key = dialectic_voice_clone_normalize_key($file->getBasename('.wav'));
                if ($key !== '' && !isset($index[$key])) {
                    $index[$key] = $path;
                }
            }
        }

        $cache[$cacheKey] = $index;
        return $index;
    }
}

if (!function_exists('dialectic_resolve_voice_clone_wav')) {
    function dialectic_voice_clone_maybe_sync_sample(string $voiceId, string $wavPath, array $options = []): void
    {
        if (array_key_exists('sync_sample', $options) && !$options['sync_sample']) {
            return;
        }

        dialectic_sync_voice_clone_sample($voiceId, $wavPath, $options);
    }

    function dialectic_resolve_voice_clone_wav($voiceId, array $options = []): string
    {
        static $missCache = [];

        $root = $options['root'] ?? null;
        $values = [$voiceId];
        foreach (['voice_name', 'voice_formid', 'actor_name'] as $key) {
            if (!empty($options[$key])) {
                $values[] = $options[$key];
            }
        }

        foreach ($values as $value) {
            $value = trim(strval($value));
            if ($value !== '' && is_file($value) && strtolower(pathinfo($value, PATHINFO_EXTENSION)) === 'wav' && @filesize($value) > 44) {
                $resolved = realpath($value) ?: $value;
                dialectic_voice_clone_maybe_sync_sample(strval($voiceId), $resolved, $options);
                return $resolved;
            }
        }

        $candidateKeyParts = [];
        foreach ($values as $value) {
            $key = dialectic_voice_clone_normalize_key(strval($value));
            if ($key !== '') {
                $candidateKeyParts[$key] = true;
            }
        }
        $missCacheKey = implode('|', array_keys($candidateKeyParts)) . '|' . implode('|', dialectic_voice_clone_dirs($root));
        if ($missCacheKey !== '|' && !empty($missCache[$missCacheKey])) {
            return '';
        }

        foreach (dialectic_voice_clone_dirs($root) as $dir) {
            foreach (dialectic_voice_clone_candidate_names($values) as $name) {
                $path = $dir . DIRECTORY_SEPARATOR . $name;
                if (is_file($path) && @filesize($path) > 44) {
                    $resolved = realpath($path) ?: $path;
                    dialectic_voice_clone_maybe_sync_sample(strval($voiceId), $resolved, $options);
                    return $resolved;
                }
            }
        }

        $index = dialectic_voice_clone_wav_index($root);
        foreach ($values as $value) {
            $key = dialectic_voice_clone_normalize_key(strval($value));
            if ($key !== '' && isset($index[$key])) {
                dialectic_voice_clone_maybe_sync_sample(strval($voiceId), $index[$key], $options);
                return $index[$key];
            }
        }

        $allowGameScan = !empty($options['allow_game_scan']);
        if ($allowGameScan) {
            foreach ($values as $value) {
                $materialized = dialectic_voice_clone_materialize_game_sample(strval($value), $options);
                if ($materialized !== '') {
                    dialectic_voice_clone_maybe_sync_sample(strval($voiceId), $materialized, $options);
                    return $materialized;
                }
            }
        }

        if ($missCacheKey !== '|') {
            $missCache[$missCacheKey] = true;
        }

        return '';
    }
}

if (!function_exists('dialectic_resolve_tts_voice_reference')) {
    function dialectic_resolve_tts_voice_reference($voiceId, array $options = []): string
    {
        $voiceId = trim(strval($voiceId));
        if ($voiceId === '') {
            return '';
        }

        $forceSample = !empty($options['force_sample']);
        if (!$forceSample && !dialectic_tts_active_provider_uses_voice_sample()) {
            return $voiceId;
        }

        $wav = dialectic_resolve_voice_clone_wav($voiceId, $options);
        return $wav !== '' ? $wav : $voiceId;
    }
}

if (!function_exists('dialectic_tts_active_provider_uses_voice_sample')) {
    function dialectic_tts_active_provider_uses_voice_sample(): bool
    {
        $driver = strtolower(trim(strval($GLOBALS['TTSFUNCTION'] ?? ($GLOBALS['TTS_FUNCTION'] ?? ''))));
        $driver = str_replace('_', '-', $driver);
        if ($driver === 'xtts' || $driver === 'xttsfastapi') {
            $driver = 'xtts-fastapi';
        }
        if ($driver === 'pockettts') {
            $driver = 'pockettts';
        }

        return in_array($driver, ['xtts-fastapi', 'chatterbox', 'pockettts'], true);
    }
}

?>
