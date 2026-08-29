<?php


/* STT entry point */

$path = dirname((__FILE__)) . DIRECTORY_SEPARATOR;
require_once($path . "lib" .DIRECTORY_SEPARATOR."runtime_bootstrap.php");
dialecticRuntimeBootstrap($path, [
    'load_general_settings' => true,
    'load_stt_connector' => true,
    'run_db_updates' => false,
]);
require_once($path . "lib" .DIRECTORY_SEPARATOR."auditing.php");
require_once($path . "lib" .DIRECTORY_SEPARATOR."logger.php");

function dialecticSttRespond(int $statusCode, bool $ok, string $text = '', string $error = ''): void
{
    if (PHP_SAPI !== 'cli' && !headers_sent()) {
        header('Content-Type: application/json; charset=utf-8');
    }

    http_response_code($statusCode);

    $payload = [
        'schema' => 'dialectic.stt.response.v1',
        'request_id' => class_exists('Logger') ? Logger::getRequestId() : '',
        'ok' => $ok,
        'text' => $text,
    ];

    if ($error !== '') {
        $payload['error'] = $error;
    }

    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL;
}

function dialecticSttDecodeMetadata(): array
{
    $method = strtoupper(strval($_SERVER['REQUEST_METHOD'] ?? 'GET'));
    if ($method !== 'POST') {
        dialecticSttRespond(405, false, '', 'Method Not Allowed');
        exit;
    }

    $rawMetadata = trim(strval($_POST['metadata'] ?? ''));
    if ($rawMetadata === '') {
        dialecticSttRespond(400, false, '', 'Missing STT metadata');
        exit;
    }

    $metadata = json_decode($rawMetadata, true);
    if (!is_array($metadata)) {
        dialecticSttRespond(400, false, '', 'Invalid STT metadata JSON');
        exit;
    }

    $schema = trim(strval($metadata['schema'] ?? ''));
    if ($schema !== 'dialectic.stt.v1') {
        dialecticSttRespond(400, false, '', 'Unsupported STT metadata schema');
        exit;
    }

    return [
        'game' => trim(strval($metadata['game'] ?? 'fnv')),
    ];
}

$startTime = microtime(true);
Logger::bootstrapRequestId("stt");
Logger::trace("Audit run ID: " . $GLOBALS["AUDIT_RUNID"]. " (STT) started: ".$startTime);
$GLOBALS["AUDIT_RUNID_REQUEST"]="STT";
$sttMetadata = dialecticSttDecodeMetadata();
Logger::phaseStart("stt", [
    "connector" => $GLOBALS["STTFUNCTION"] ?? "",
    "file_bytes" => intval($_FILES["file"]["size"] ?? 0),
    "game" => $sttMetadata["game"] ?? "fnv",
]);

if (empty($_FILES["file"]["tmp_name"])) {
    Logger::error("STT error, no data given");
    Logger::phaseEnd("stt", ["status" => "missing_file"], "error");
    dialecticSttRespond(400, false, '', 'No audio file given');
    exit;
}

$finalName=__DIR__.DIRECTORY_SEPARATOR."soundcache/_stt_".md5($_FILES["file"]["tmp_name"]).".wav";

@copy($_FILES["file"]["tmp_name"] ,$finalName);


if ($STTFUNCTION=="azure") {
    
    require_once($path."stt/stt-azure.php");
    $text= stt($finalName);
    
} else if ($STTFUNCTION=="whisper") { 

    require_once($path."stt/stt-whisper.php");
    $text= stt($finalName);
    
} else if ($STTFUNCTION=="localwhisper") { 
    require_once($path."stt/stt-localwhisper.php");
    $text= stt($finalName);
    
} else if ($STTFUNCTION=="deepgram") {
    require_once($path."stt/stt-deepgram.php");
    $text= stt($finalName);

} else if ($STTFUNCTION=="gemini") {
    require_once($path."stt/stt-gemini.php");
    $text= stt($finalName);

} else if (file_exists($path . "stt" . DIRECTORY_SEPARATOR . "stt-{$STTFUNCTION}.php")){
    require_once($path . "stt" . DIRECTORY_SEPARATOR . "stt-{$STTFUNCTION}.php");
    $text= stt($finalName);
} else {
    require_once($path."stt/stt-none.php");
    $text= stt($finalName);
} 

$text = trim((string)$text);
if ($text === '') {
    Logger::phaseEnd("stt", [
        "status" => "empty_transcript",
        "connector" => $GLOBALS["STTFUNCTION"] ?? "",
        "text_length" => 0,
    ], "warn");
    dialecticSttRespond(422, false, '', 'empty_transcript');
    exit;
}

Logger::phaseEnd("stt", [
    "status" => "ok",
    "connector" => $GLOBALS["STTFUNCTION"] ?? "",
    "text_length" => strlen($text),
    "preview" => Logger::summarizePayload($text, 120),
]);
dialecticSttRespond(200, true, $text);

?>

