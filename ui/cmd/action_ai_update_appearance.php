<?php

http_response_code(410);
header('Content-Type: application/json; charset=utf-8');
echo json_encode([
    'ok' => false,
    'error' => 'Appearance image updates are disabled in DIALECTIC.',
], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
