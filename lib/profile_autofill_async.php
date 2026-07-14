<?php

if (!function_exists('dialecticProfileAutofillPhpBinary')) {
    function dialecticProfileAutofillPhpBinary(): string
    {
        if (function_exists('dialectic_npc_tts_php_binary')) {
            return dialectic_npc_tts_php_binary();
        }

        $candidates = PHP_SAPI === 'cli'
            ? [defined('PHP_BINARY') ? (string)PHP_BINARY : '', 'php']
            : ['php'];

        if (defined('PHP_BINDIR')) {
            $bindir = rtrim((string)PHP_BINDIR, DIRECTORY_SEPARATOR);
            if ($bindir !== '') {
                array_unshift($candidates, $bindir . DIRECTORY_SEPARATOR . (DIRECTORY_SEPARATOR === '\\' ? 'php.exe' : 'php'));
            }
        }

        foreach ($candidates as $candidate) {
            $candidate = trim((string)$candidate);
            if ($candidate === '') {
                continue;
            }
            if ($candidate === 'php' || is_file($candidate)) {
                return $candidate;
            }
        }

        return 'php';
    }
}

if (!function_exists('dialecticSpawnProfileAutofillWorker')) {
    function dialecticSpawnProfileAutofillWorker(array $job): bool
    {
        $root = dirname(__DIR__);
        $worker = $root . DIRECTORY_SEPARATOR . 'processor' . DIRECTORY_SEPARATOR . 'profile_autofill_worker.php';
        if (!is_file($worker)) {
            Logger::warn('[PROFILE_AUTOFILL] Worker missing' . Logger::formatContext([
                'worker' => $worker,
            ]));
            return false;
        }

        $npcName = trim((string)($job['name'] ?? ''));
        if ($npcName === '') {
            return false;
        }

        $jobsDir = $root . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'tmp' . DIRECTORY_SEPARATOR . 'profile_autofill_jobs';
        if (!is_dir($jobsDir)) {
            @mkdir($jobsDir, 0775, true);
        }

        $jobId = md5(strtolower($npcName));
        $jobPath = $jobsDir . DIRECTORY_SEPARATOR . $jobId . '.json';
        $lockPath = $jobsDir . DIRECTORY_SEPARATOR . $jobId . '.lock';

        if (file_exists($lockPath) && (time() - (int)@filemtime($lockPath)) < 600) {
            Logger::debug('[PROFILE_AUTOFILL] Worker already queued or active' . Logger::formatContext([
                'npc' => $npcName,
            ]));
            return true;
        }

        if (@file_put_contents($lockPath, (string)time(), LOCK_EX) === false) {
            Logger::warn('[PROFILE_AUTOFILL] Could not write worker lock' . Logger::formatContext([
                'lock_path' => $lockPath,
            ]));
            return false;
        }

        $job['lock_path'] = $lockPath;
        $job['engine_root'] = $root;
        $job['request_id'] = class_exists('Logger') ? Logger::getRequestId() : ($GLOBALS['DIALECTIC_REQUEST_ID'] ?? '');

        if (@file_put_contents($jobPath, json_encode($job, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), LOCK_EX) === false) {
            @unlink($lockPath);
            Logger::warn('[PROFILE_AUTOFILL] Could not write worker job' . Logger::formatContext([
                'job_path' => $jobPath,
            ]));
            return false;
        }

        $php = dialecticProfileAutofillPhpBinary();
        if (DIRECTORY_SEPARATOR === '\\') {
            $command = 'cmd /C start "" /B "' . str_replace('"', '\"', $php) . '" "' . str_replace('"', '\"', $worker) . '" "' . str_replace('"', '\"', $jobPath) . '" >NUL 2>NUL';
        } else {
            $command = 'nohup ' . escapeshellarg($php) . ' ' . escapeshellarg($worker) . ' ' . escapeshellarg($jobPath) . ' > /dev/null 2>&1 < /dev/null &';
        }

        $handle = @popen($command, 'r');
        if (is_resource($handle)) {
            @pclose($handle);
            Logger::info('[PROFILE_AUTOFILL] Spawned async profile worker' . Logger::formatContext([
                'npc' => $npcName,
                'job' => basename($jobPath),
            ]));
            return true;
        }

        @unlink($lockPath);
        Logger::warn('[PROFILE_AUTOFILL] Failed to spawn async profile worker' . Logger::formatContext([
            'npc' => $npcName,
        ]));
        return false;
    }
}

?>
