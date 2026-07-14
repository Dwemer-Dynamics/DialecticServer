<?php

error_reporting(E_ALL);
ini_set("display_errors", "0");
ini_set("log_errors", "1");

$jobPath = strval($argv[1] ?? "");
if ($jobPath === "" || !is_file($jobPath)) {
    exit(1);
}

$job = json_decode((string)@file_get_contents($jobPath), true);
if (!is_array($job)) {
    exit(1);
}

$root = dirname(__DIR__);
$path = $root . DIRECTORY_SEPARATOR;
$lockPath = trim(strval($job["lock_path"] ?? ""));

$speaker = trim(strval($job["speaker"] ?? ""));
$text = trim(strval($job["text"] ?? ""));
$mood = trim(strval($job["mood"] ?? "default"));
$cacheKey = trim(strval($job["cache_key"] ?? ""));

if ($speaker === "" || $text === "" || $cacheKey === "") {
    if ($lockPath !== "") {
        @unlink($lockPath);
    }
    exit(0);
}

$GLOBALS["ENGINE_PATH"] = $path;
$GLOBALS["DIALECTIC_RESPONSE_FORMAT"] = "json";
$GLOBALS["DIALECTIC_RESPONSE_STREAMING"] = false;
$GLOBALS["DIALECTIC_GAME_ID"] = "fnv";
$GLOBALS["DIALECTIC_WORLD_NAME"] = "Mojave Wasteland";
$GLOBALS["DIALECTIC_NAME"] = $speaker;
$GLOBALS["TTS_FFMPEG_FILTERS"] = [];
$GLOBALS["AUDIT_RUNID_REQUEST"] = "npc_tts_worker";
$GLOBALS["gameRequest"] = [
    "npc_tts_worker",
    strval(time()),
    strval(time()),
    $speaker . ": " . $text,
];

require_once($path . "lib" . DIRECTORY_SEPARATOR . "runtime_bootstrap.php");
require_once($path . "lib" . DIRECTORY_SEPARATOR . "response.php");
require_once($path . "lib" . DIRECTORY_SEPARATOR . "auditing.php");
require_once($path . "lib" . DIRECTORY_SEPARATOR . "logger.php");
require_once($path . "lib" . DIRECTORY_SEPARATOR . "dialectic_tts.php");
require_once($path . "lib" . DIRECTORY_SEPARATOR . "core" . DIRECTORY_SEPARATOR . "npc_master.class.php");
require_once($path . "lib" . DIRECTORY_SEPARATOR . "core" . DIRECTORY_SEPARATOR . "llm_connector.class.php");
require_once($path . "lib" . DIRECTORY_SEPARATOR . "core" . DIRECTORY_SEPARATOR . "core_profiles.class.php");
require_once($path . "lib" . DIRECTORY_SEPARATOR . "chat_helper_functions.php");

try {
    if (class_exists("Logger")) {
        $requestId = trim(strval($job["request_id"] ?? ""));
        if ($requestId !== "") {
            Logger::setRequestId($requestId);
        } else {
            Logger::bootstrapRequestId("npc_tts_worker");
        }
    }

    dialecticRuntimeBootstrap($path, [
        "load_general_settings" => true,
        "load_stt_connector" => false,
        "load_tts_connector" => true,
        "load_player_name" => true,
        "load_narrator" => false,
        "run_db_updates" => false,
    ]);

    if (!empty($job["connector_data"]) && is_array($job["connector_data"])) {
        $connector = new LLMConnector();
        $connector->setOldGlobals($job["connector_data"]);
    }
    if (!empty($job["profile_data"]) && is_array($job["profile_data"])) {
        $profile = new CoreProfile();
        $profile->setOldGlobals($job["profile_data"]);
    }
    if (!empty($job["npc_data"]) && is_array($job["npc_data"])) {
        $npcMaster = new NpcMaster();
        $GLOBALS["DIALECTIC_CORE_CURRENT_NPC_DATA"] = $job["npc_data"];
        $GLOBALS["DIALECTIC_NAME"] = trim(strval($job["npc_data"]["npc_name"] ?? $speaker));
        if ($GLOBALS["DIALECTIC_NAME"] === "") {
            $GLOBALS["DIALECTIC_NAME"] = $speaker;
        }
        $npcMaster->setOldGlobalsFromCurrentNpcData($job["npc_data"], true);
    }
    if (trim(strval($job["tts_function"] ?? "")) !== "") {
        $GLOBALS["TTSFUNCTION"] = trim(strval($job["tts_function"]));
    }

    $cachePath = $path . "soundcache" . DIRECTORY_SEPARATOR . $cacheKey . ".wav";
    if (is_file($cachePath) && filesize($cachePath) > 44) {
        Logger::debug("[TTS] NPC deferred worker found existing cache" . Logger::formatContext([
            "speaker" => $GLOBALS["DIALECTIC_NAME"] ?? $speaker,
            "cache_key" => $cacheKey,
        ]));
    } else {
        $phaseName = "npc_tts_worker:" . substr(md5($speaker . "|" . $cacheKey), 0, 8);
        Logger::phaseStart($phaseName, [
            "speaker" => $GLOBALS["DIALECTIC_NAME"] ?? $speaker,
            "connector" => $GLOBALS["TTSFUNCTION"] ?? "",
            "cache_key" => $cacheKey,
            "chars" => strlen($text),
        ]);

        $ttsOutput = callNpcTtsWithFallback($text, $mood !== "" ? $mood : "default", $cacheKey);
        if (!$ttsOutput && isset($GLOBALS["TTS_FALLBACK_FNCT"])) {
            $ttsOutput = $GLOBALS["TTS_FALLBACK_FNCT"]($text, $mood !== "" ? $mood : "default", $cacheKey);
        }

        if ($ttsOutput) {
            Logger::phaseEnd($phaseName, [
                "status" => "ok",
                "speaker" => $GLOBALS["DIALECTIC_NAME"] ?? $speaker,
                "output" => $ttsOutput,
            ], "info");
        } else {
            Logger::phaseEnd($phaseName, [
                "status" => "failed",
                "speaker" => $GLOBALS["DIALECTIC_NAME"] ?? $speaker,
                "cache_key" => $cacheKey,
            ], "warn");
        }
    }
} catch (Throwable $e) {
    if (class_exists("Logger")) {
        Logger::error("[TTS] NPC deferred worker failed: " . $e->getMessage());
    }
} finally {
    if ($lockPath !== "") {
        @unlink($lockPath);
    }
}

?>
