<?php

// Only this endpoint and its session-scoped audio need to be reachable by listeners.
require_once __DIR__ . '/lib/multiplayer_relay.php';
header('Cache-Control: no-store');
header('Content-Type: text/plain; charset=utf-8');
header('X-Content-Type-Options: nosniff');
try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        header('Allow: POST');
        exit;
    }
    $raw = file_get_contents('php://input', false, null, 0, 16385);
    $input = strlen($raw) <= 16384 ? json_decode($raw, true) : null;
    if (!is_array($input)) {
        http_response_code(400);
        exit('Invalid request.');
    }
    $configPath = __DIR__ . '/conf/multiplayer.php';
    $config = is_file($configPath) ? require $configPath : [];
    $authorization = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    $key = str_starts_with($authorization, 'Bearer ') ? substr($authorization, 7) : '';
    [$status, $body, $audio] = array_pad(dialectic_share_request(
        is_array($config) ? $config : [], $input, $key, __DIR__), 3, null);
    http_response_code($status);
    if ($audio !== null && $status === 200) {
        header('Content-Type: audio/wav');
        $handle = fopen($audio, 'rb');
        if (!$handle) {
            http_response_code(404);
            exit;
        }
        // Bound even a concurrently replaced cache file; release the session lock before I/O.
        $output = fopen('php://output', 'wb');
        stream_copy_to_stream($handle, $output, 16777216);
        fclose($output);
        fclose($handle);
    } else {
        echo $body;
    }
} catch (Throwable $error) {
    error_log('[multiplayer] Relay request failed: ' . get_class($error));
    http_response_code(503);
    echo 'Sharing is unavailable.';
}
