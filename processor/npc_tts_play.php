<?php

error_reporting(E_ALL);
ini_set("display_errors", "0");
ini_set("log_errors", "1");

$root = dirname(__DIR__);
$path = $root . DIRECTORY_SEPARATOR;
$GLOBALS["ENGINE_PATH"] = $path;
$GLOBALS["DIALECTIC_RESPONSE_FORMAT"] = "json";
$GLOBALS["DIALECTIC_RESPONSE_STREAMING"] = false;
$GLOBALS["DIALECTIC_GAME_ID"] = "fnv";
$GLOBALS["DIALECTIC_WORLD_NAME"] = "Mojave Wasteland";
$GLOBALS["TTS_FFMPEG_FILTERS"] = [];
$GLOBALS["AUDIT_RUNID_REQUEST"] = "external_npc_tts";

require_once($path . "lib" . DIRECTORY_SEPARATOR . "runtime_bootstrap.php");
require_once($path . "lib" . DIRECTORY_SEPARATOR . "request.php");
require_once($path . "lib" . DIRECTORY_SEPARATOR . "response.php");
require_once($path . "lib" . DIRECTORY_SEPARATOR . "auditing.php");
require_once($path . "lib" . DIRECTORY_SEPARATOR . "logger.php");
require_once($path . "lib" . DIRECTORY_SEPARATOR . "dialectic_tts.php");
require_once($path . "lib" . DIRECTORY_SEPARATOR . "chat_helper_functions.php");

dialecticRuntimeBootstrap($path, [
    "load_general_settings" => true,
    "load_stt_connector" => false,
    "load_tts_connector" => true,
    "load_player_name" => true,
    "load_narrator" => false,
    "run_db_updates" => false,
]);

$event = dialectic_decode_event_from_request();
$externalRequest = dialectic_decode_external_actor_request(
    strval($event["payload"] ?? ""),
    "dialectic.npc_tts.v1",
    "tts"
);
if (empty($externalRequest["ok"])) {
    dialectic_reject_json_request(400, (string)($externalRequest["error"] ?? "Invalid NPC TTS request"));
}

$requestId = trim(strval($event["request_id"] ?? ""));
if ($requestId !== "") {
    Logger::setRequestId($requestId);
} else {
    Logger::bootstrapRequestId("external_npc_tts");
}

$speaker = (string)$externalRequest["npc"];
$speakerFormId = (string)$externalRequest["npc_id"];
$text = (string)$externalRequest["text"];
$player = (string)$externalRequest["player"];
$GLOBALS["DIALECTIC_NAME"] = $speaker;
$GLOBALS["DIALECTIC_RESPONSE_SPEAKER_FORMID"] = $speakerFormId;
$gameRequest = [
    "external_npc_tts",
    strval($event["ts"] ?? time()),
    strval($event["gamets"] ?? time()),
    strval($event["payload"] ?? ""),
];
$GLOBALS["gameRequest"] = &$gameRequest;

if (!dialectic_tts_generate_for_response($root, $speaker, $text)) {
    dialectic_reject_json_request(502, "NPC TTS generation failed");
}

dialectic_start_json_response_buffer();
$utteranceId = "external_" . substr(hash("sha256", Logger::getRequestId() . "|" . $speakerFormId . "|" . $text), 0, 20);
dialectic_buffer_response_line($speaker, "say", $text, [
    "speaker_formid" => $speakerFormId,
    "speaker_refid" => $speakerFormId,
    "listener" => $player,
    "utterance_id" => $utteranceId,
    "tts_text" => $text,
    "tts_cache_key" => dialectic_tts_cache_key($root, $speaker, $text),
    "request_type" => "external_npc_tts",
    "action_source" => "xnvse_event",
]);
dialectic_emit_buffered_json_response();

?>
