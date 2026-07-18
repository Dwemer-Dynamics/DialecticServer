<?php

function dialectic_npc_tts_status_key(string $cacheKey): string
{
    $cacheKey = strtolower(trim($cacheKey));
    return preg_match('/^[a-f0-9]{32}$/', $cacheKey) === 1 ? $cacheKey : '';
}

function dialectic_npc_tts_status_dir(?string $root = null): string
{
    $root = $root ?: dirname(__DIR__);
    return rtrim($root, DIRECTORY_SEPARATOR)
        . DIRECTORY_SEPARATOR . 'data'
        . DIRECTORY_SEPARATOR . 'tmp'
        . DIRECTORY_SEPARATOR . 'npc_tts_status';
}

function dialectic_write_npc_tts_status(
    string $cacheKey,
    string $status,
    array $metadata = [],
    ?string $root = null
): bool {
    $cacheKey = dialectic_npc_tts_status_key($cacheKey);
    $status = strtolower(trim($status));
    if ($cacheKey === '' || !in_array($status, ['pending', 'ready', 'failed'], true)) {
        return false;
    }

    $directory = dialectic_npc_tts_status_dir($root);
    if (!is_dir($directory) && !@mkdir($directory, 0775, true) && !is_dir($directory)) {
        return false;
    }

    $payload = array_merge($metadata, [
        'schema' => 'dialectic.tts_status.v1',
        'cache_key' => $cacheKey,
        'status' => $status,
        'updated_at' => time(),
    ]);
    $target = $directory . DIRECTORY_SEPARATOR . $cacheKey . '.json';
    $temporary = $target . '.' . getmypid() . '.tmp';
    $encoded = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if (!is_string($encoded) || @file_put_contents($temporary, $encoded, LOCK_EX) === false) {
        @unlink($temporary);
        return false;
    }

    @chmod($temporary, 0664);
    if (!@rename($temporary, $target)) {
        @unlink($target);
        if (!@rename($temporary, $target)) {
            @unlink($temporary);
            return false;
        }
    }
    @chmod($target, 0664);
    return true;
}

function dialectic_read_npc_tts_status(string $cacheKey, ?string $root = null): array
{
    $cacheKey = dialectic_npc_tts_status_key($cacheKey);
    $root = $root ?: dirname(__DIR__);
    if ($cacheKey === '') {
        return [
            'schema' => 'dialectic.tts_status.v1',
            'cache_key' => '',
            'status' => 'invalid',
        ];
    }

    $cachePath = rtrim($root, DIRECTORY_SEPARATOR)
        . DIRECTORY_SEPARATOR . 'soundcache'
        . DIRECTORY_SEPARATOR . $cacheKey . '.wav';
    if (is_file($cachePath) && @filesize($cachePath) > 44) {
        return [
            'schema' => 'dialectic.tts_status.v1',
            'cache_key' => $cacheKey,
            'status' => 'ready',
            'updated_at' => (int)@filemtime($cachePath),
        ];
    }

    $path = dialectic_npc_tts_status_dir($root) . DIRECTORY_SEPARATOR . $cacheKey . '.json';
    if (!is_file($path)) {
        return [
            'schema' => 'dialectic.tts_status.v1',
            'cache_key' => $cacheKey,
            'status' => 'unknown',
        ];
    }

    $decoded = json_decode((string)@file_get_contents($path), true);
    if (!is_array($decoded)) {
        return [
            'schema' => 'dialectic.tts_status.v1',
            'cache_key' => $cacheKey,
            'status' => 'unknown',
        ];
    }

    $decoded['schema'] = 'dialectic.tts_status.v1';
    $decoded['cache_key'] = $cacheKey;
    return $decoded;
}

?>
