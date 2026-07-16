<?php

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

require_once __DIR__ . DIRECTORY_SEPARATOR . 'lib' . DIRECTORY_SEPARATOR . 'npc_tts_status.php';

$cacheKey = strval($_GET['cache_key'] ?? '');
$status = dialectic_read_npc_tts_status($cacheKey, __DIR__);
if (($status['status'] ?? '') === 'invalid') {
    http_response_code(400);
}

echo json_encode($status, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

?>
