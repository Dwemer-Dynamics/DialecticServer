<?php

error_reporting(E_ALL);
ini_set("display_errors", "0");
ini_set("log_errors", "1");

$jobPath = strval($argv[1] ?? "");
if ($jobPath === "" || !is_file($jobPath)) {
    exit(1);
}

$jobJson = (string)@file_get_contents($jobPath);
$jobJson = preg_replace('/^\xEF\xBB\xBF/', '', $jobJson);
$job = json_decode(is_string($jobJson) ? $jobJson : '', true);
if (!is_array($job)) {
    exit(1);
}

$root = dirname(__DIR__);
$path = $root . DIRECTORY_SEPARATOR;
$line = trim(strval($job["line"] ?? ""));
$cachePath = trim(strval($job["cache_path"] ?? ""));
$lockPath = trim(strval($job["lock_path"] ?? ""));

if ($line === "") {
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
$GLOBALS["DIALECTIC_NAME"] = "Player";
$GLOBALS["TTS_FFMPEG_FILTERS"] = [];
$GLOBALS["AUDIT_RUNID_REQUEST"] = "player_menu_tts_worker";

require_once($path . "lib" . DIRECTORY_SEPARATOR . "runtime_bootstrap.php");
require_once($path . "lib" . DIRECTORY_SEPARATOR . "response.php");
require_once($path . "lib" . DIRECTORY_SEPARATOR . "auditing.php");
require_once($path . "lib" . DIRECTORY_SEPARATOR . "logger.php");
require_once($path . "lib" . DIRECTORY_SEPARATOR . "dialectic_tts.php");
require_once($path . "lib" . DIRECTORY_SEPARATOR . "chat_helper_functions.php");
require_once($path . "lib" . DIRECTORY_SEPARATOR . "player_tts_helpers.php");

try {
    dialecticRuntimeBootstrap($path, [
        "load_general_settings" => true,
        "load_stt_connector" => false,
        "load_tts_connector" => true,
        "load_player_name" => true,
        "load_narrator" => false,
        "run_db_updates" => false,
    ]);

    $playerPrefix = trim((string)($job["player_name"] ?? ($GLOBALS["PLAYER_NAME"] ?? "Player")));
    if ($playerPrefix === "") {
        $playerPrefix = "Player";
    }

    $gameRequest = [
        "player_menu_tts_play",
        strval(time()),
        strval(time()),
        $playerPrefix . ": " . $line,
    ];
    $GLOBALS["gameRequest"] = &$gameRequest;
    $GLOBALS["PLAYER_TTS_WRITE_OUTPUT"] = false;
    $GLOBALS["AVOID_TTS_CACHE"] = false;
    if ($cachePath !== "") {
        $GLOBALS["PLAYER_TTS_EXPECTED_CACHE_PATH"] = $cachePath;
    }

    require($path . "processor" . DIRECTORY_SEPARATOR . "player_tts.php");
} catch (Throwable $e) {
    if (class_exists("Logger")) {
        Logger::error("[Player TTS] worker failed: " . $e->getMessage());
    }
} finally {
    if ($lockPath !== "") {
        @unlink($lockPath);
    }
}

?>
