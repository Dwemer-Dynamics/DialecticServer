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

require_once($path . "lib" . DIRECTORY_SEPARATOR . "runtime_bootstrap.php");
require_once($path . "lib" . DIRECTORY_SEPARATOR . "logger.php");

if (PHP_SAPI !== "cli" && !headers_sent()) {
    header("Content-Type: application/json; charset=utf-8");
    header("Cache-Control: no-cache, must-revalidate");
}

try {
    dialecticRuntimeBootstrap($path, [
        "load_general_settings" => false,
        "load_stt_connector" => false,
        "load_player_name" => false,
        "load_narrator" => false,
        "run_db_updates" => false,
    ]);

    $db = $GLOBALS["db"] ?? null;
    if (!$db) {
        throw new RuntimeException("Database unavailable.");
    }

    $modeRow = $db->fetchOne("SELECT value FROM conf_opts WHERE id='dialectic_mode'");
    $mode = strtoupper(trim((string)($modeRow["value"] ?? "STANDARD")));
    $allowedModes = [
        "STANDARD" => 0,
        "WHISPER" => 1,
        "SHOUT" => 2,
        "NARRATOR" => 3,
        "DIRECTOR" => 4,
        "INJECTION_LOG" => 5,
        "INJECTION_CHAT" => 6,
        "CHEATMODE" => 7,
    ];
    if (!array_key_exists($mode, $allowedModes)) {
        $mode = "STANDARD";
    }

    $slotRow = $db->fetchOne("SELECT value FROM conf_opts WHERE id='dialectic_profile_model'");
    $profileModel = max(1, min(4, intval($slotRow["value"] ?? 1)));
    $slotLabels = [
        1 => "Standard",
        2 => "Fast",
        3 => "Powerful",
        4 => "Experimental",
    ];

    echo json_encode([
        "schema" => "dialectic.runtime_status.v1",
        "ok" => true,
        "dialectic_mode" => $mode,
        "mode" => $mode,
        "mode_index" => $allowedModes[$mode],
        "dialectic_profile_model" => $profileModel,
        "active_model_slot" => $profileModel,
        "model_slot_label" => $slotLabels[$profileModel] ?? "Standard",
        "updated" => time(),
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL;
} catch (Throwable $e) {
    Logger::warn("[runtime_status] Failed to read runtime status: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        "schema" => "dialectic.runtime_status.v1",
        "ok" => false,
        "error" => "runtime_status_failed",
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL;
}

