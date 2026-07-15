<?php

/**
 * Background processor helpers for DialecticServer.
 *
 * Uses a small heartbeat/startup pattern for the Dialectic background service:
 * - Probe heartbeat TCP port.
 * - Start daemon via service/start.sh when needed.
 */

function dialecticBackgroundProcessorPort(): int
{
    // CHIM owns 12345 and Stobe owns 12346. Keep Dialectic isolated so
    // one server cannot mistake another server's worker for its own.
    $configuredPort = (int)(getenv('DIALECTIC_BACKGROUND_PORT') ?: 12347);
    return ($configuredPort >= 1 && $configuredPort <= 65535) ? $configuredPort : 12347;
}

function dialecticBackgroundProcessorStartScriptPath(): string
{
    $configuredPath = trim((string)getenv('DIALECTIC_BACKGROUND_START_SCRIPT'));
    if ($configuredPath !== '') {
        return $configuredPath;
    }

    if (isset($GLOBALS['ENGINE_ROOT']) && is_string($GLOBALS['ENGINE_ROOT']) && $GLOBALS['ENGINE_ROOT'] !== '') {
        $engineRoot = rtrim($GLOBALS['ENGINE_ROOT'], DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
    } elseif (defined('BASE_PATH')) {
        $engineRoot = rtrim((string)BASE_PATH, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
    } else {
        $engineRoot = rtrim(dirname(__DIR__), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
    }

    return $engineRoot . 'service' . DIRECTORY_SEPARATOR . 'start.sh';
}

function dialecticBackgroundProcessorStateDirectory(): string
{
    $configuredPath = trim((string)getenv('DIALECTIC_BACKGROUND_STATE_DIR'));
    $stateDirectory = $configuredPath !== '' ? $configuredPath : sys_get_temp_dir();
    return rtrim($stateDirectory, DIRECTORY_SEPARATOR);
}

function dialecticBackgroundProcessorLog(string $level, string $message, array $context = []): void
{
    if (!empty($context)) {
        $encoded = json_encode($context, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (is_string($encoded) && $encoded !== '') {
            $message .= ' ' . $encoded;
        }
    }

    if (class_exists('Logger')) {
        $level = strtolower($level);
        if ($level === 'error') {
            Logger::error($message);
            return;
        }
        if ($level === 'warn' || $level === 'warning') {
            Logger::warn($message);
            return;
        }
        Logger::info($message);
        return;
    }

    error_log('[HELPER] ' . $message);
}

function dialecticBackgroundProcessorIsRunning(float $timeoutSeconds = 0.15): bool
{
    $port = dialecticBackgroundProcessorPort();
    if ($port < 1 || $port > 65535) {
        return false;
    }

    if ($timeoutSeconds < 0.1) {
        $timeoutSeconds = 0.1;
    } elseif ($timeoutSeconds > 2.0) {
        $timeoutSeconds = 2.0;
    }

    $socket = @fsockopen('127.0.0.1', $port, $errno, $errstr, $timeoutSeconds);
    if (is_resource($socket)) {
        @fclose($socket);
        return true;
    }

    return false;
}

function dialecticEnsureBackgroundProcessorRunning(bool $logFailures = true): bool
{
    if (dialecticBackgroundProcessorIsRunning()) {
        return true;
    }

    $startScript = dialecticBackgroundProcessorStartScriptPath();
    if (!is_file($startScript)) {
        if ($logFailures) {
            dialecticBackgroundProcessorLog('warn', 'Background processor start script missing', [
                'path' => $startScript,
            ]);
        }
        return false;
    }

    if (!function_exists('shell_exec')) {
        if ($logFailures) {
            dialecticBackgroundProcessorLog('warn', 'shell_exec unavailable; cannot auto-start background processor');
        }
        return false;
    }

    $stateDirectory = dialecticBackgroundProcessorStateDirectory();
    if (!is_dir($stateDirectory) && !@mkdir($stateDirectory, 0775, true) && !is_dir($stateDirectory)) {
        if ($logFailures) {
            dialecticBackgroundProcessorLog('warn', 'Background processor state directory is unavailable', [
                'path' => $stateDirectory,
            ]);
        }
        return false;
    }

    $lockPath = $stateDirectory . DIRECTORY_SEPARATOR . 'dialectic_background_processor_start.lock';
    $attemptPath = $stateDirectory . DIRECTORY_SEPARATOR . 'dialectic_background_processor_start.attempt';
    $lockHandle = @fopen($lockPath, 'c');
    if (!is_resource($lockHandle) || !@flock($lockHandle, LOCK_EX | LOCK_NB)) {
        if (is_resource($lockHandle)) {
            @fclose($lockHandle);
        }
        return false;
    }

    try {
        if (dialecticBackgroundProcessorIsRunning(0.1)) {
            return true;
        }

        $cooldownSeconds = max(5, (int)(getenv('DIALECTIC_BACKGROUND_START_COOLDOWN_SECONDS') ?: 30));
        $lastAttempt = is_file($attemptPath) ? (int)@filemtime($attemptPath) : 0;
        if ($lastAttempt > 0 && (time() - $lastAttempt) < $cooldownSeconds) {
            return false;
        }

        @touch($attemptPath);
        $command = 'nohup bash ' . escapeshellarg($startScript) . ' > /dev/null 2>&1 < /dev/null &';
        @shell_exec($command);

        // Do not hold a game request open while the daemon completes startup.
        usleep(100000);
        $running = dialecticBackgroundProcessorIsRunning(0.1);
        dialecticBackgroundProcessorLog($running ? 'info' : 'warn', $running
            ? 'Background processor started'
            : 'Background processor start requested; service is not ready yet', [
            'port' => dialecticBackgroundProcessorPort(),
            'script' => $startScript,
            'cooldown_seconds' => $cooldownSeconds,
        ]);
        return $running;
    } finally {
        @flock($lockHandle, LOCK_UN);
        @fclose($lockHandle);
    }
}
