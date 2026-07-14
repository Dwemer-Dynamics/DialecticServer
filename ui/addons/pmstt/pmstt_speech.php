<?php
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

// If this is a preflight request, end here
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

error_reporting(E_ERROR);
$enginePath = __DIR__.DIRECTORY_SEPARATOR."..".DIRECTORY_SEPARATOR."..".DIRECTORY_SEPARATOR."..".DIRECTORY_SEPARATOR;;

require_once($enginePath . "lib".DIRECTORY_SEPARATOR."runtime_bootstrap.php");
dialecticRuntimeBootstrap($enginePath, [
    'load_general_settings' => true,
    'load_player_name' => true,
    'load_narrator' => true,
]);

require_once($enginePath . "lib" .DIRECTORY_SEPARATOR."model_dynmodel.php");
require_once($enginePath . "lib" .DIRECTORY_SEPARATOR."chat_helper_functions.php");
require_once($enginePath . "lib" .DIRECTORY_SEPARATOR."data_functions.php");
require_once($enginePath . "lib" .DIRECTORY_SEPARATOR."dialectic_command_payload.php");

$db = $GLOBALS["db"];

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode([
        'schema' => 'dialectic.pmstt.response.v1',
        'ok' => false,
        'error' => 'Method Not Allowed',
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL;
    exit;
}

$rawBody = file_get_contents('php://input');
$payload = json_decode(is_string($rawBody) ? $rawBody : '', true);
if (!is_array($payload) || trim(strval($payload['text'] ?? '')) === '') {
    http_response_code(400);
    echo json_encode([
        'schema' => 'dialectic.pmstt.response.v1',
        'ok' => false,
        'error' => 'Missing text',
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL;
    exit;
}

$speech=$db->escape(trim(strval($payload['text'])));

dialecticQueueCommandResponse(
    "rolemaster",
    "ImpersonatePlayer",
    ["speech" => $speech, "request_type" => "inputtext"]
);

echo json_encode([
    'schema' => 'dialectic.pmstt.response.v1',
    'ok' => true,
], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL;

?>
