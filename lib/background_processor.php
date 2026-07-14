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
    return 12347;
}

function dialecticBackgroundProcessorStartScriptPath(): string
{
    if (isset($GLOBALS['ENGINE_ROOT']) && is_string($GLOBALS['ENGINE_ROOT']) && $GLOBALS['ENGINE_ROOT'] !== '') {
        $engineRoot = rtrim($GLOBALS['ENGINE_ROOT'], DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
    } elseif (defined('BASE_PATH')) {
        $engineRoot = rtrim((string)BASE_PATH, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
    } else {
        $engineRoot = rtrim(dirname(__DIR__), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
    }

    return $engineRoot . 'service' . DIRECTORY_SEPARATOR . 'start.sh';
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

function dialecticBackgroundProcessorIsRunning(float $timeoutSeconds = 0.8): bool
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

    for ($attempt = 0; $attempt < 4; $attempt++) {
        $socket = @fsockopen('127.0.0.1', $port, $errno, $errstr, $timeoutSeconds);
        if (is_resource($socket)) {
            @fclose($socket);
            return true;
        }
        usleep(100000);
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

    $command = 'nohup bash ' . escapeshellarg($startScript) . ' > /dev/null 2>&1 < /dev/null &';
    @shell_exec($command);

    $running = false;
    for ($attempt = 0; $attempt < 20; $attempt++) {
        usleep(150000);
        if (dialecticBackgroundProcessorIsRunning(0.25)) {
            $running = true;
            break;
        }
    }

    if ($running) {
        dialecticBackgroundProcessorLog('info', 'Background processor started', [
            'port' => dialecticBackgroundProcessorPort(),
            'script' => $startScript,
        ]);
        return true;
    }

    if ($logFailures) {
        dialecticBackgroundProcessorLog('warn', 'Background processor failed to start', [
            'port' => dialecticBackgroundProcessorPort(),
            'script' => $startScript,
        ]);
    }

    return false;
}
