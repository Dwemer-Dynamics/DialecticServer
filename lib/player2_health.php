<?php

const DIALECTIC_PLAYER2_HEALTH_ACTIVITY_TTL = 180;
const DIALECTIC_PLAYER2_HEALTH_ACTIVITY_WRITE_INTERVAL = 15;
const DIALECTIC_PLAYER2_HEALTH_USE_TTL = 300;
const DIALECTIC_PLAYER2_HEALTH_INTERVAL = 60;
const DIALECTIC_PLAYER2_HEALTH_LOCK_ID = 873421;

function dialecticPlayer2HealthNormalizeUrl(string $url): string
{
    $url = trim($url);
    if ($url === '') {
        $url = 'http://127.0.0.1:4315';
    }

    if (!preg_match('#^https?://#i', $url)) {
        $url = 'http://' . ltrim($url, '/');
    }

    $parts = parse_url($url);
    if (!is_array($parts) || empty($parts['host'])) {
        return 'http://127.0.0.1:4315/v1/health';
    }

    $scheme = strtolower(strval($parts['scheme'] ?? 'http')) === 'https' ? 'https' : 'http';
    $healthUrl = $scheme . '://' . $parts['host'];
    if (!empty($parts['port'])) {
        $healthUrl .= ':' . intval($parts['port']);
    }

    return $healthUrl . '/v1/health';
}

function dialecticPlayer2HealthGetOption(string $id, string $default = ''): string
{
    $db = $GLOBALS['db'] ?? null;
    if (!$db) {
        return $default;
    }

    $escapedId = $db->escape($id);
    $row = $db->fetchOne("SELECT value FROM conf_opts WHERE id='{$escapedId}' LIMIT 1");
    return is_array($row) && array_key_exists('value', $row) ? strval($row['value']) : $default;
}

function dialecticPlayer2HealthSetOption(string $id, string $value): bool
{
    $db = $GLOBALS['db'] ?? null;
    if (!$db) {
        return false;
    }

    return (bool)$db->upsertRowOnConflict('conf_opts', [
        'id' => $id,
        'value' => $value,
    ], 'id');
}

function dialecticPlayer2HealthMarkGameActivity(?int $now = null): bool
{
    $now = $now ?? time();
    $lastActivity = intval(dialecticPlayer2HealthGetOption('PLAYER2_GAME_LAST_ACTIVITY_TS', '0'));
    $newSession = $lastActivity <= 0 || ($now - $lastActivity) > DIALECTIC_PLAYER2_HEALTH_ACTIVITY_TTL;

    if ($newSession) {
        dialecticPlayer2HealthSetOption('PLAYER2_GAME_SESSION_STARTED_TS', strval($now));
    }
    if ($newSession || ($now - $lastActivity) >= DIALECTIC_PLAYER2_HEALTH_ACTIVITY_WRITE_INTERVAL) {
        dialecticPlayer2HealthSetOption('PLAYER2_GAME_LAST_ACTIVITY_TS', strval($now));
    }
    $GLOBALS['PLAYER2_GAME_REQUEST_ACTIVE'] = true;

    return $newSession;
}

function dialecticPlayer2HealthMarkUsed(string $connectorUrl, ?int $now = null): bool
{
    if (empty($GLOBALS['PLAYER2_GAME_REQUEST_ACTIVE'])) {
        return false;
    }

    $now = $now ?? time();
    $sessionStarted = intval(dialecticPlayer2HealthGetOption('PLAYER2_GAME_SESSION_STARTED_TS', '0'));
    $lastActivity = intval(dialecticPlayer2HealthGetOption('PLAYER2_GAME_LAST_ACTIVITY_TS', '0'));
    if ($sessionStarted <= 0 || $lastActivity <= 0 || ($now - $lastActivity) > DIALECTIC_PLAYER2_HEALTH_ACTIVITY_TTL) {
        return false;
    }

    dialecticPlayer2HealthSetOption('PLAYER2_HEALTH_ACTIVE_SESSION_TS', strval($sessionStarted));
    dialecticPlayer2HealthSetOption('PLAYER2_HEALTH_LAST_USED_TS', strval($now));
    dialecticPlayer2HealthSetOption('PLAYER2_HEALTH_URL', dialecticPlayer2HealthNormalizeUrl($connectorUrl));
    return true;
}

function dialecticPlayer2HealthShouldPing(array $state, int $now): bool
{
    $lastActivity = intval($state['last_activity'] ?? 0);
    $sessionStarted = intval($state['session_started'] ?? 0);
    $activeSession = intval($state['active_session'] ?? 0);
    $lastUsed = intval($state['last_used'] ?? 0);
    $lastAttempt = intval($state['last_attempt'] ?? 0);
    $healthUrl = trim(strval($state['health_url'] ?? ''));

    return $healthUrl !== ''
        && $lastActivity > 0
        && ($now - $lastActivity) <= DIALECTIC_PLAYER2_HEALTH_ACTIVITY_TTL
        && $sessionStarted > 0
        && $activeSession === $sessionStarted
        && $lastUsed > 0
        && ($now - $lastUsed) <= DIALECTIC_PLAYER2_HEALTH_USE_TTL
        && ($lastAttempt <= 0 || ($now - $lastAttempt) >= DIALECTIC_PLAYER2_HEALTH_INTERVAL);
}

function dialecticPlayer2HealthRequest(string $url): array
{
    if (function_exists('curl_init')) {
        $handle = curl_init($url);
        curl_setopt_array($handle, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 2,
            CURLOPT_TIMEOUT => 5,
            CURLOPT_HTTPHEADER => ['player2-game-key: DIALECTIC'],
        ]);
        $body = curl_exec($handle);
        $error = curl_error($handle);
        $status = intval(curl_getinfo($handle, CURLINFO_RESPONSE_CODE));
        curl_close($handle);

        return [
            'ok' => $body !== false && $status >= 200 && $status < 300,
            'http_code' => $status,
            'error' => $error,
        ];
    }

    $context = stream_context_create([
        'http' => [
            'method' => 'GET',
            'header' => "player2-game-key: DIALECTIC\r\n",
            'timeout' => 5,
            'ignore_errors' => true,
        ],
    ]);
    $body = @file_get_contents($url, false, $context);
    $status = 0;
    foreach (($http_response_header ?? []) as $header) {
        if (preg_match('#^HTTP/\S+\s+(\d{3})#i', $header, $matches)) {
            $status = intval($matches[1]);
            break;
        }
    }

    return [
        'ok' => $body !== false && $status >= 200 && $status < 300,
        'http_code' => $status,
        'error' => $body === false ? 'Player2 health request failed' : '',
    ];
}

function dialecticPlayer2HealthTick(?int $now = null, ?callable $transport = null): bool
{
    $db = $GLOBALS['db'] ?? null;
    if (!$db) {
        return false;
    }

    $now = $now ?? time();
    $state = [
        'last_activity' => dialecticPlayer2HealthGetOption('PLAYER2_GAME_LAST_ACTIVITY_TS', '0'),
        'session_started' => dialecticPlayer2HealthGetOption('PLAYER2_GAME_SESSION_STARTED_TS', '0'),
        'active_session' => dialecticPlayer2HealthGetOption('PLAYER2_HEALTH_ACTIVE_SESSION_TS', '0'),
        'last_used' => dialecticPlayer2HealthGetOption('PLAYER2_HEALTH_LAST_USED_TS', '0'),
        'last_attempt' => dialecticPlayer2HealthGetOption('PLAYER2_HEALTH_LAST_ATTEMPT_TS', '0'),
        'health_url' => dialecticPlayer2HealthGetOption('PLAYER2_HEALTH_URL', ''),
    ];
    if (!dialecticPlayer2HealthShouldPing($state, $now)) {
        return false;
    }

    $lockRow = $db->fetchOne('SELECT pg_try_advisory_lock(' . DIALECTIC_PLAYER2_HEALTH_LOCK_ID . ') AS locked');
    $locked = in_array(strtolower(strval($lockRow['locked'] ?? '')), ['1', 't', 'true'], true);
    if (!$locked) {
        return false;
    }

    try {
        $lastAttempt = intval(dialecticPlayer2HealthGetOption('PLAYER2_HEALTH_LAST_ATTEMPT_TS', '0'));
        if ($lastAttempt > 0 && ($now - $lastAttempt) < DIALECTIC_PLAYER2_HEALTH_INTERVAL) {
            return false;
        }

        dialecticPlayer2HealthSetOption('PLAYER2_HEALTH_LAST_ATTEMPT_TS', strval($now));
        $result = $transport
            ? $transport(strval($state['health_url']))
            : dialecticPlayer2HealthRequest(strval($state['health_url']));
        $httpCode = intval($result['http_code'] ?? 0);
        $error = trim(strval($result['error'] ?? ''));

        dialecticPlayer2HealthSetOption('PLAYER2_HEALTH_LAST_HTTP_CODE', strval($httpCode));
        dialecticPlayer2HealthSetOption('PLAYER2_HEALTH_LAST_ERROR', substr($error, 0, 500));
        if (!empty($result['ok'])) {
            dialecticPlayer2HealthSetOption('PLAYER2_HEALTH_LAST_SUCCESS_TS', strval($now));
            if (class_exists('Logger')) {
                Logger::info("[Player2 Health] Heartbeat succeeded ({$httpCode})");
            }
        } elseif (class_exists('Logger')) {
            Logger::warn("[Player2 Health] Heartbeat failed ({$httpCode}) {$error}");
        }

        return !empty($result['ok']);
    } finally {
        $db->fetchOne('SELECT pg_advisory_unlock(' . DIALECTIC_PLAYER2_HEALTH_LOCK_ID . ') AS unlocked');
    }
}
