<?php

// Standalone public ingress: no game database, AI credentials or host-side inbound connection.
require_once dirname(__DIR__) . '/lib/multiplayer_relay.php';
header('Cache-Control: no-store');
header('Content-Type: text/plain; charset=utf-8');
header('X-Content-Type-Options: nosniff');

// Room paths are generated internally. Never follow links during expiry or media cleanup.
function relay_remove_room(string $path): void
{
    foreach (['soundcache', 'state'] as $child) {
        if (is_link("$path/$child")) {
            throw new RuntimeException('Unsafe storage');
        }
        foreach (glob("$path/$child/*") ?: [] as $file) {
            if (is_file($file) && !is_link($file)) unlink($file);
        }
        if (is_dir("$path/$child")) rmdir("$path/$child");
    }
    if (is_dir($path) && !is_link($path)) rmdir($path);
}

// Keep rate-limit state bounded; use the actual peer address, never an arbitrary forwarded header.
function relay_allow(array &$rates, string $bucket, int $limit, int $window): bool
{
    $now = time();
    foreach ($rates as $id => $rate) if ($rate[0] <= $now) unset($rates[$id]);
    if (!isset($rates[$bucket]) && count($rates) >= 1024) return false;
    $rates[$bucket] ??= [$now + $window, 0];
    return ++$rates[$bucket][1] <= $limit;
}

$lock = null;
try {
    $storage = getenv('DIALECTIC_RELAY_STORAGE') ?: '';
    if (!$storage || !is_dir($storage) || is_link($storage) || is_link("$storage/rooms.json")
        || is_link("$storage/rooms.lock") || is_link("$storage/rooms.tmp")) {
        http_response_code(503);
        exit('Public relay is not configured.');
    }
    $clean = PHP_SAPI === 'cli' && in_array('--clean', $argv ?? [], true);
    $input = [];
    $audio = '';
    if (!$clean) {
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
            header('Allow: POST'); http_response_code(405); exit;
        }
        if ((int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 4210692) {
            http_response_code(413); exit('Speech is too large.');
        }
        $raw = file_get_contents('php://input', false, null, 0, 4210693);
        if (strlen($raw) > 4210692) { http_response_code(413); exit; }
        if (($_SERVER['CONTENT_TYPE'] ?? '') === 'application/octet-stream') {
            if (strlen($raw) < 4) { http_response_code(400); exit; }
            $length = unpack('V', substr($raw, 0, 4))[1];
            if ($length > 16384 || $length + 4 > strlen($raw)) { http_response_code(400); exit; }
            $input = json_decode(substr($raw, 4, $length), true);
            $audio = substr($raw, 4 + $length);
        } else {
            $input = strlen($raw) <= 16384 ? json_decode($raw, true) : null;
        }
        if (!is_array($input)) { http_response_code(400); exit('Invalid request.'); }
    }
    $lock = fopen("$storage/rooms.lock", 'c+');
    if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) {
        http_response_code(503); exit('Relay is busy. Try again.');
    }
    chmod("$storage/rooms.lock", 0600);
    $rawState = is_file("$storage/rooms.json") ? file_get_contents("$storage/rooms.json", false, null, 0, 1048577) : '';
    if (strlen($rawState) > 1048576) throw new RuntimeException('Storage limit');
    $state = $rawState === '' ? ['rooms' => [], 'rates' => []] : json_decode($rawState, true, 512, JSON_THROW_ON_ERROR);
    $now = time();
    foreach ($state['rooms'] as $id => $room) {
        if ($room['expires'] <= $now || $room['active'] < $now - 180) {
            relay_remove_room("$storage/$id");
            unset($state['rooms'][$id]);
            continue;
        }
        foreach (glob("$storage/$id/soundcache/*.wav") ?: [] as $file) {
            if (!is_link($file) && filemtime($file) < $now - 120) unlink($file);
        }
    }
    $status = 200;
    $reply = 'ok';
    $audioPath = null;
    if (!$clean) {
        $op = $input['op'] ?? '';
        $key = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
        $key = str_starts_with($key, 'Bearer ') ? substr($key, 7) : '';
        $peer = hash('sha256', $_SERVER['REMOTE_ADDR'] ?? 'unknown');
        $bucket = in_array($op, ['create', 'join'], true) ? 'setup:' : 'traffic:';
        if (!relay_allow($state['rates'], $bucket . $peer, $bucket === 'setup:' ? 12 : 128, $bucket === 'setup:' ? 60 : 1)
            || !relay_allow($state['rates'], 'global', 256, 1)) {
            $status = 429; $reply = 'Relay is busy. Try again later.';
        } elseif (!preg_match('/^[a-f0-9]{64}$/D', $key)) {
            $status = 403; $reply = 'Access denied.';
        } elseif ($op === 'create') {
            // The client generates its host token once, making a retry idempotent.
            $id = substr(hash('sha256', $key), 0, 32);
            if (!isset($state['rooms'][$id]) && (count($state['rooms']) >= 16
                || count(array_filter($state['rooms'], static fn($r) => ($r['peer'] ?? '') === $peer)) >= 2)) {
                $status = 503; $reply = 'Relay is full. Try again later.';
            } else {
                if (!isset($state['rooms'][$id])) {
                    do { $code = strtoupper(bin2hex(random_bytes(6))); }
                    while (in_array($code, array_column($state['rooms'], 'code'), true));
                    $state['rooms'][$id] = ['host_key' => $key, 'listen_key' => bin2hex(random_bytes(32)),
                        'peer' => $peer, 'code' => $code, 'expires' => $now + 21600, 'active' => $now];
                    foreach (['soundcache', 'state'] as $child) {
                        if (!mkdir("$storage/$id/$child", 0700, true)) throw new RuntimeException('Storage unavailable');
                    }
                }
                $room = $state['rooms'][$id];
                $reply = "dialectic.session.v1\t$id\t$key\t{$room['code']}";
            }
        } elseif ($op === 'join') {
            $code = $input['code'] ?? '';
            $status = 403; $reply = 'Code is invalid or expired.';
            if (is_string($code) && preg_match('/^[A-F0-9]{12}$/D', $code)) {
                foreach ($state['rooms'] as $id => $room) {
                    if (hash_equals($room['code'], $code)) {
                        $status = 200;
                        $reply = "dialectic.session.v1\t$id\t{$room['listen_key']}\t$code";
                        break;
                    }
                }
            }
        } else {
            $id = $input['session'] ?? '';
            $room = is_string($id) ? ($state['rooms'][$id] ?? null) : null;
            if (!$room || (!hash_equals($room['host_key'], $key) && !hash_equals($room['listen_key'], $key))) {
                $status = 403; $reply = 'Session expired or access denied.';
            } elseif ($op === 'end' && hash_equals($room['host_key'], $key)) {
                relay_remove_room("$storage/$id");
                unset($state['rooms'][$id]);
            } else {
                $path = "$storage/$id";
                $config = $room + ['session' => $id, 'state_directory' => "$path/state"];
                $uploaded = null;
                if ($op === 'publish' && hash_equals($room['host_key'], $key)) {
                    $cache = $input['cache'] ?? '';
                    if (!is_string($cache) || !preg_match('/^[a-f0-9]{32}$/D', $cache)
                        || strlen($audio) < 44 || strlen($audio) > 4194304
                        || substr($audio, 0, 4) !== 'RIFF' || substr($audio, 8, 4) !== 'WAVE') {
                        $status = 400; $reply = 'Invalid shared audio.';
                    } else {
                        $files = glob("$path/soundcache/*.wav") ?: [];
                        $used = array_sum(array_map('filesize', $files));
                        $uploaded = "$path/soundcache/$cache.wav";
                        if (is_file($uploaded)) {
                            // Never replace media still referenced by an earlier event.
                            if (!hash_equals(hash_file('sha256', $uploaded), hash('sha256', $audio))) {
                                $status = 409; $reply = 'Audio identity conflict.';
                            }
                            $uploaded = null;
                        } elseif (count($files) >= 128 || $used + strlen($audio) > 16777216) {
                            $status = 429; $reply = 'Session audio limit reached.'; $uploaded = null;
                        } elseif (file_put_contents($uploaded, $audio, LOCK_EX) !== strlen($audio)) {
                            throw new RuntimeException('Audio storage failed');
                        }
                    }
                }
                if ($status === 200) {
                    [$status, $reply, $audioPath] = array_pad(dialectic_share_request($config, $input, $key, $path), 3, null);
                    if ($status === 200 && hash_equals($room['host_key'], $key)) {
                        $state['rooms'][$id]['active'] = $now;
                        if ($op === 'publish') touch("$path/soundcache/{$input['cache']}.wav");
                    }
                }
                if ($uploaded && $status !== 200) unlink($uploaded);
                if ($status === 200 && in_array($op, ['reset', 'cancel', 'close'], true)) {
                    foreach (glob("$path/soundcache/*.wav") ?: [] as $file) unlink($file);
                }
            }
        }
    }
    $encoded = json_encode($state, JSON_THROW_ON_ERROR);
    // Keep the prior complete registry if the process exits during a write.
    if (file_put_contents("$storage/rooms.tmp", $encoded) !== strlen($encoded)
        || !chmod("$storage/rooms.tmp", 0600) || !rename("$storage/rooms.tmp", "$storage/rooms.json")) {
        throw new RuntimeException('State write failed');
    }
    // Open before releasing the lock so cancellation cannot substitute a different file.
    $stream = $audioPath && $status === 200 ? fopen($audioPath, 'rb') : null;
    flock($lock, LOCK_UN); fclose($lock); $lock = null;
    http_response_code($status);
    if ($stream) {
        header('Content-Type: audio/wav');
        fpassthru($stream); fclose($stream);
    } else echo $reply;
} catch (Throwable $error) {
    error_log('[public-relay] Request failed: ' . get_class($error));
    http_response_code(503); echo 'Relay is unavailable.';
} finally {
    if (is_resource($lock)) { flock($lock, LOCK_UN); fclose($lock); }
}
