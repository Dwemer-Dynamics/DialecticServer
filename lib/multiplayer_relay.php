<?php

// This relay is deliberately independent of game state, AI requests and playthrough databases.
function dialectic_share_request(array $config, array $input, string $key, string $root): array
{
    $session = $config['session'] ?? '';
    $hostKey = $config['host_key'] ?? '';
    $listenKey = $config['listen_key'] ?? '';
    if (!is_string($session) || !preg_match('/^[a-zA-Z0-9_-]{1,64}$/D', $session)
        || !is_string($hostKey) || !is_string($listenKey)
        || strlen($hostKey) < 32 || strlen($listenKey) < 32 || $hostKey === $listenKey) {
        return [503, 'Sharing is not configured.'];
    }
    $host = hash_equals($hostKey, $key);
    if ((!$host && !hash_equals($listenKey, $key)) || ($input['session'] ?? null) !== $session) {
        return [403, 'Access denied.'];
    }
    $op = $input['op'] ?? '';
    if (!in_array($op, $host ? ['reset', 'heartbeat', 'publish', 'cancel', 'close', 'pause', 'resume'] : ['poll', 'audio'], true)) {
        return [403, 'Access denied.'];
    }

    // One private, bounded file per installation; no web-accessible cache or saved-game data.
    $directory = $config['state_directory'] ?? (sys_get_temp_dir() . '/dialectic-share-' . hash('sha256', $root));
    if (!is_dir($directory) && !@mkdir($directory, 0700, true) && !is_dir($directory)) {
        return [503, 'Sharing is unavailable.'];
    }
    if (is_link($directory) || !@chmod($directory, 0700) || is_link($directory . '/session.json')) {
        return [503, 'Sharing is unavailable.'];
    }
    $file = @fopen($directory . '/session.json', 'c+');
    if (!$file) {
        return [503, 'Sharing is unavailable.'];
    }
    try {
        if (!flock($file, LOCK_EX | LOCK_NB)) {
            return [503, 'Sharing is busy.'];
        }
        $raw = stream_get_contents($file, 2097153);
        if (strlen($raw) > 2097152) {
            return [503, 'Sharing is unavailable.'];
        }
        $state = json_decode($raw, true) ?: [];
        $now = microtime(true);
        $identity = hash('sha256', $session . $hostKey . $listenKey);
        if (($state['identity'] ?? '') !== $identity) {
            $state = ['identity' => $identity, 'epoch' => '', 'seq' => 0, 'events' => [], 'owner' => '', 'seen' => 0];
        }
        $state['events'] = array_values(array_filter($state['events'], static fn($e) => $e['time'] > $now - 120));
        $alive = $state['seen'] > $now - 15;
        $rateKey = $host ? 'host_rate' : 'listen_rate';
        $rate = $state[$rateKey] ?? [0, 0];
        $rate = $rate[0] === (int)$now ? [$rate[0], $rate[1] + 1] : [(int)$now, 1];
        if ($rate[1] > ($host ? 20 : 64)) {
            return [429, 'Sharing is busy.'];
        }
        $state[$rateKey] = $rate;
        $audioPath = null;
        $reply = '';
        if ($host) {
            $owner = $input['owner'] ?? '';
            $serial = $input['serial'] ?? 0;
            if (!is_string($owner) || !preg_match('/^[a-f0-9]{32}$/D', $owner)
                || !is_int($serial) || $serial < 1) {
                return [400, 'Invalid request.'];
            }
            if ($alive && $state['owner'] !== $owner) {
                return [409, 'Another dialogue host is active.'];
            }
            // A timed-out or replaced host must explicitly start a fresh session.
            if ($op !== 'reset' && (!$alive || $state['owner'] !== $owner)) {
                return [409, 'Start a new sharing session.'];
            }
            if ($state['owner'] === $owner && $serial <= ($state['serial'] ?? 0)) {
                return [200, 'ok']; // Idempotent retry; never replay an old publish or reset.
            }
            if ($op === 'reset') {
                $state['epoch'] = bin2hex(random_bytes(16));
                $state['seq'] = 0;
                $state['events'] = [];
            }
            $state['owner'] = $owner;
            $state['serial'] = $serial;
            $state['seen'] = $op === 'close' ? 0 : $now;
            if ($op === 'cancel' || $op === 'close') {
                $state['events'] = [];
                $state['events'][] = ['seq' => ++$state['seq'], 'time' => $now, 'type' => 'cancel'];
            }
            if ($op === 'pause' || $op === 'resume') {
                $state['events'][] = ['seq' => ++$state['seq'], 'time' => $now, 'type' => $op];
                $state['events'] = array_slice($state['events'], -128);
            }
            if ($op === 'publish') {
                $speaker = $input['speaker'] ?? '';
                $text = $input['text'] ?? '';
                $utterance = $input['utterance'] ?? '';
                $cache = $input['cache'] ?? '';
                if (!is_string($speaker) || strlen($speaker) < 1 || strlen($speaker) > 160
                    || !is_string($text) || strlen($text) < 1 || strlen($text) > 4096
                    || !is_string($utterance) || !preg_match('/^[a-zA-Z0-9_-]{1,128}$/D', $utterance)
                    || !is_string($cache) || !preg_match('/^[a-f0-9]{32}$/D', $cache)
                    || preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f]/', $speaker . $text)) {
                    return [400, 'Invalid dialogue.'];
                }
                $path = $root . '/soundcache/' . $cache . '.wav';
                if (!is_file($path) || filesize($path) < 44 || filesize($path) > 16777216) {
                    return [404, 'Audio is unavailable.'];
                }
                $duplicate = false;
                foreach ($state['events'] as $event) {
                    $duplicate = $duplicate || ($event['utterance'] ?? '') === $utterance;
                }
                if (!$duplicate) {
                    $state['events'][] = ['seq' => ++$state['seq'], 'time' => $now, 'type' => 'say',
                        'speaker' => $speaker, 'text' => $text, 'utterance' => $utterance, 'cache' => $cache];
                    $state['events'] = array_slice($state['events'], -128);
                }
            }
            $reply = 'ok';
        } elseif ($op === 'poll') {
            $cursor = $input['cursor'] ?? -1;
            if (!is_int($cursor) || $cursor < -1) {
                return [400, 'Invalid cursor.'];
            }
            // A join, missing history or epoch change advances to live speech, never a backlog.
            $first = $state['events'][0]['seq'] ?? $state['seq'] + 1;
            $reset = !$alive || ($input['epoch'] ?? '') !== $state['epoch']
                || $cursor < $first - 1 || $cursor > $state['seq'] || $cursor === -1;
            $events = $reset ? [] : array_slice(array_values(array_filter($state['events'],
                static fn($e) => $e['seq'] > $cursor)), 0, 1);
            $next = $events ? end($events)['seq'] : ($reset ? $state['seq'] : $cursor);
            // Percent-encoded UTF-8 fields keep the native parser small and unambiguous.
            $reply = "dialectic.share.v1\t{$state['epoch']}\t{$next}\t" . ($alive ? 1 : 0)
                . "\t" . ($reset ? 1 : 0) . "\n";
            foreach ($events as $e) {
                $reply .= implode("\t", [$e['type'], $e['seq'], rawurlencode($e['utterance'] ?? ''),
                    rawurlencode($e['speaker'] ?? ''), rawurlencode($e['text'] ?? ''), $e['cache'] ?? '']) . "\n";
            }
        } else {
            if (!$alive || ($input['epoch'] ?? '') !== $state['epoch']) {
                return [410, 'Session ended.'];
            }
            foreach ($state['events'] as $e) {
                if (($e['type'] ?? '') === 'say' && ($input['sequence'] ?? null) === $e['seq']) {
                    $audioPath = $root . '/soundcache/' . $e['cache'] . '.wav';
                    break;
                }
            }
            if (!$audioPath || !is_file($audioPath) || filesize($audioPath) > 16777216) {
                return [404, 'Audio is unavailable.'];
            }
        }
        $encoded = json_encode($state, JSON_THROW_ON_ERROR);
        rewind($file);
        if (fwrite($file, $encoded) !== strlen($encoded) || !ftruncate($file, strlen($encoded)) || !fflush($file)) {
            return [503, 'Sharing is unavailable.'];
        }
        return [200, $reply, $audioPath];
    } finally {
        flock($file, LOCK_UN);
        fclose($file);
    }
}
