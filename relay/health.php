<?php

// Probe PHP, private storage and the cleanup worker without disclosing session data.
header('Content-Type: text/plain; charset=utf-8');
header('Cache-Control: no-store');
$storage = getenv('DIALECTIC_RELAY_STORAGE') ?: '';
$cleaned = $storage !== '' ? @filemtime("$storage/cleanup.ok") : false;
if (!$storage || !is_dir($storage) || !is_writable($storage) || !$cleaned || $cleaned < time() - 180) {
    http_response_code(503);
    exit('unavailable');
}
echo 'ok';
