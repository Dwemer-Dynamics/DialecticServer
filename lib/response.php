<?php

require_once(__DIR__ . DIRECTORY_SEPARATOR . 'dialectic_command_payload.php');

function dialectic_json_response_enabled(): bool
{
    return ($GLOBALS["DIALECTIC_RESPONSE_FORMAT"] ?? "json") === "json";
}

function dialectic_response_stream_requested(): bool
{
    return !empty($GLOBALS["DIALECTIC_RESPONSE_STREAMING"]);
}

function dialectic_json_streaming_enabled(): bool
{
    return dialectic_json_response_enabled() && dialectic_response_stream_requested();
}

function dialectic_should_generate_npc_tts_before_emit(): bool
{
    return !dialectic_response_stream_requested();
}

function dialectic_emit_json_response_envelope(array $lines, bool $close = false): void
{
    $requestId = class_exists('Logger') ? Logger::getRequestId() : ($GLOBALS["DIALECTIC_REQUEST_ID"] ?? "");
    echo json_encode([
        "schema" => "dialectic.response.v1",
        "request_id" => $requestId,
        "ok" => true,
        "lines" => array_values($lines),
        "close" => $close,
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL;

    if (ob_get_level()) {
        @ob_flush();
    }
    @flush();
}

function dialectic_buffer_response_close(): void
{
    $GLOBALS["DIALECTIC_JSON_RESPONSE_CLOSE"] = true;
}

function dialectic_abort_json_response(): void
{
    dialectic_buffer_response_close();
    if (function_exists("terminate")) {
        terminate();
    }
    dialectic_emit_buffered_json_response();
    die();
}

function dialectic_buffer_response_line(string $speaker, string $action, string $text, array $metadata = []): void
{
    $speaker = trim($speaker);
    $action = trim($action);
    $text = trim($text);
    if ($speaker === "" && $action === "" && $text === "") {
        return;
    }
    if (strcasecmp($action, "rolecommand") === 0 && $text === "" &&
        trim(strval($metadata["command_name"] ?? $metadata["command"] ?? "")) === "") {
        if (class_exists('Logger')) {
            Logger::warn('[plugin-response] Dropped empty rolecommand response line');
        }
        return;
    }

    $line = [
        "schema" => "dialectic.response.line.v1",
        "speaker" => $speaker,
        "action" => $action,
        "text" => $text,
    ];

    if (strcasecmp($speaker, 'The Narrator') === 0) {
        $line["display_name"] = function_exists('dialecticGetNarratorRoleplayName')
            ? dialecticGetNarratorRoleplayName()
            : 'The Narrator';
    }

    if (strtolower($action) === "say") {
        $textOnly = strtolower(trim(strval($metadata["listener"] ?? ""))) === "__player_text_only" ||
            !empty($metadata["text_only"]);
        $line["action"] = "say";
        $line["text"] = $text;
        $line["subtitle"] = $text;
        if (!$textOnly) {
            $line["tts_text"] = $text;
            if (function_exists("dialectic_tts_cache_key")) {
                $line["tts_cache_key"] = dialectic_tts_cache_key(dirname(__DIR__), $speaker, $text);
            }
            $explicitTtsCacheKey = trim(strval($metadata["tts_cache_key"] ?? ""));
            if ($explicitTtsCacheKey !== "") {
                $line["tts_cache_key"] = $explicitTtsCacheKey;
            }
        }
    }

    $requestId = class_exists('Logger') ? Logger::getRequestId() : ($GLOBALS["DIALECTIC_REQUEST_ID"] ?? "");
    if ($requestId !== "") {
        $line["request_id"] = $requestId;
        $metadata["request_id"] = $requestId;
    }

    $metadata = array_filter($metadata, static function ($value) {
        return $value !== null && $value !== "";
    });
    if (!empty($metadata)) {
        $topLevelMetadataKeys = [
            "listener",
            "listener_formid",
            "rechat_target",
            "rechat_target_formid",
            "utterance_id",
            "message",
            "target",
            "item",
            "amount",
            "location",
            "npc",
            "speaker_refid",
            "speaker_formid",
            "target_refid",
            "target_formid",
            "item_refid",
            "item_baseid",
            "baseid",
            "command",
            "command_name",
            "payload",
            "model",
            "setting",
            "value",
            "refid",
            "name",
            "character",
            "instruction",
            "task_id",
            "speech",
            "request_type",
            "display_name",
        ];
        foreach ($topLevelMetadataKeys as $metadataKey) {
            if (isset($metadata[$metadataKey]) && trim(strval($metadata[$metadataKey])) !== "") {
                $line[$metadataKey] = trim(strval($metadata[$metadataKey]));
            }
        }
        if (isset($metadata["command_args"]) && is_array($metadata["command_args"])) {
            $line["command_args"] = array_values(array_map("strval", $metadata["command_args"]));
        }
        $line["metadata"] = $metadata;
    }

    $GLOBALS["DIALECTIC_JSON_RESPONSE_LINES"][] = $line;

    if (dialectic_json_streaming_enabled()) {
        if (class_exists('Logger')) {
            $elapsedMs = null;
            if (isset($GLOBALS["DIALECTIC_TURN_START_TIME"]) && is_numeric($GLOBALS["DIALECTIC_TURN_START_TIME"])) {
                $elapsedMs = round((microtime(true) - (float)$GLOBALS["DIALECTIC_TURN_START_TIME"]) * 1000, 2);
            }
            Logger::info("[plugin-response] streamed JSON envelope" . Logger::formatContext([
                "elapsed_ms" => $elapsedMs,
                "speaker" => $line["speaker"] ?? "",
                "action" => $line["action"] ?? "",
                "utterance_id" => $line["utterance_id"] ?? ($line["metadata"]["utterance_id"] ?? ""),
                "tts_cache_key" => $line["tts_cache_key"] ?? "",
                "chars" => strlen((string)($line["text"] ?? "")),
            ]));
        }
        dialectic_emit_json_response_envelope([$line], false);
    }
}

function dialectic_buffer_speech_response_line(
    string $speaker,
    string $subtitle,
    string $expression = "",
    string $listener = "",
    string $animation = "",
    string $phonetic = "",
    $volume = null,
    string $rechatTarget = "",
    string $utteranceId = ""
): void {
    $metadata = [
        "expression" => trim($expression),
        "listener" => trim($listener),
        "animation" => trim($animation),
        "phonetic" => trim($phonetic),
        "volume" => $volume,
        "rechat_target" => trim($rechatTarget),
        "utterance_id" => trim($utteranceId),
    ];
    if (($GLOBALS["gameRequest"][0] ?? "") === "rechat") {
        $previousSpeakerFormId = trim((string)($GLOBALS["RECHAT_REQUEST_PAYLOAD"]["speaker_formid"] ?? ""));
        if ($previousSpeakerFormId !== "") {
            $metadata["listener_formid"] = $previousSpeakerFormId;
            $metadata["rechat_target_formid"] = $previousSpeakerFormId;
        }
    }
    dialectic_buffer_response_line($speaker, "say", $subtitle, $metadata);
}

function dialectic_buffer_command_response_line(string $speaker, string $command, array $args = []): void
{
    $decodedCommand = dialecticDecodeCommandAction($command, $args);
    $metadata = $decodedCommand["metadata"];
    $commandPayload = $decodedCommand["command_payload"];
    $commandName = $decodedCommand["command_name"];
    $commandArgs = $decodedCommand["command_args"];
    if (trim($commandName) === "") {
        if (class_exists('Logger')) {
            Logger::warn('[plugin-response] Dropped command response without a command name');
        }
        return;
    }
    if ($commandPayload !== "") {
        $metadata["command"] = $commandPayload;
    }
    if ($commandName !== "") {
        $metadata["command_name"] = $commandName;
    }
    if (!empty($commandArgs)) {
        $metadata["command_args"] = $commandArgs;
    }

    $normalizedCommand = strtolower($commandName);
    if (!empty($commandArgs)) {
        if (in_array($normalizedCommand, ["attack", "follow", "moveto", "inspect", "travelto"], true)) {
            $metadata["target"] = $metadata["target"] ?? $commandArgs[0];
            if ($normalizedCommand === "travelto") {
                $metadata["location"] = $metadata["location"] ?? $commandArgs[0];
            }
        } elseif ($normalizedCommand === "givecapsto") {
            $metadata["target"] = $metadata["target"] ?? ($commandArgs[0] ?? "");
            $metadata["amount"] = $metadata["amount"] ?? ($commandArgs[1] ?? "");
        } elseif ($normalizedCommand === "takecapsfromplayer") {
            $metadata["amount"] = $metadata["amount"] ?? ($commandArgs[0] ?? "");
        } elseif ($normalizedCommand === "giveitemto") {
            $metadata["target"] = $metadata["target"] ?? ($commandArgs[0] ?? "");
            $metadata["item"] = $metadata["item"] ?? ($commandArgs[1] ?? "");
            $metadata["amount"] = $metadata["amount"] ?? ($commandArgs[2] ?? "");
        } elseif (in_array($normalizedCommand, ["pickupitem", "consume"], true)) {
            $metadata["item"] = $metadata["item"] ?? ($commandArgs[0] ?? "");
        } elseif ($normalizedCommand === "debugnotification") {
            $metadata["message"] = $metadata["message"] ?? ($commandArgs[0] ?? "");
        } elseif ($normalizedCommand === "refreshnpcvoice") {
            $metadata["npc"] = $metadata["npc"] ?? ($commandArgs[0] ?? "");
        } else {
            $metadata["target"] = $metadata["target"] ?? ($commandArgs[0] ?? "");
            $metadata["item"] = $metadata["item"] ?? ($commandArgs[1] ?? "");
            $metadata["amount"] = $metadata["amount"] ?? ($commandArgs[2] ?? "");
        }
    }

    foreach ([
        "message",
        "target",
        "item",
        "amount",
        "location",
        "npc",
        "speaker_refid",
        "speaker_formid",
        "target_refid",
        "target_formid",
        "item_refid",
        "item_baseid",
        "baseid",
    ] as $key) {
        if (array_key_exists($key, $args) && is_scalar($args[$key]) && trim(strval($args[$key])) !== "") {
            $metadata[$key] = trim(strval($args[$key]));
        }
    }
    dialectic_buffer_response_line($speaker, "rolecommand", $commandName, $metadata);
}

function dialectic_capture_response_output(string $chunk): string
{
    $GLOBALS["DIALECTIC_JSON_RESPONSE_RAW"] = ($GLOBALS["DIALECTIC_JSON_RESPONSE_RAW"] ?? "") . $chunk;
    return "";
}

function dialectic_start_json_response_buffer(): void
{
    if (!dialectic_json_response_enabled()) {
        return;
    }

    $GLOBALS["DIALECTIC_JSON_RESPONSE_LINES"] = [];
    $GLOBALS["DIALECTIC_JSON_RESPONSE_RAW"] = "";
    $GLOBALS["DIALECTIC_JSON_RESPONSE_CLOSE"] = false;
    $GLOBALS["DIALECTIC_JSON_RESPONSE_STREAM_FINAL_EMITTED"] = false;

    $streamingEnabled = dialectic_json_streaming_enabled();
    if ($streamingEnabled) {
        @ini_set("display_errors", "0");
        @ini_set("output_buffering", "0");
        @ini_set("zlib.output_compression", "0");
    }

    if (PHP_SAPI !== "cli" && !headers_sent()) {
        if ($streamingEnabled) {
            header("Content-Type: application/x-ndjson; charset=utf-8");
            header("Cache-Control: no-cache");
            header("X-Accel-Buffering: no");
        } else {
            header("Content-Type: application/json; charset=utf-8");
        }
    }

    if ($streamingEnabled) {
        return;
    }

    ob_start("dialectic_capture_response_output");
    $GLOBALS["DIALECTIC_JSON_RESPONSE_BUFFER_LEVEL"] = ob_get_level();
}

function dialectic_emit_buffered_json_response(): void
{
    if (!dialectic_json_response_enabled()) {
        return;
    }

    if (dialectic_json_streaming_enabled()) {
        if (!empty($GLOBALS["DIALECTIC_JSON_RESPONSE_STREAM_FINAL_EMITTED"])) {
            return;
        }

        $GLOBALS["DIALECTIC_JSON_RESPONSE_STREAM_FINAL_EMITTED"] = true;
        dialectic_emit_json_response_envelope(
            [],
            (bool)($GLOBALS["DIALECTIC_JSON_RESPONSE_CLOSE"] ?? false)
        );
        return;
    }

    $bufferLevel = intval($GLOBALS["DIALECTIC_JSON_RESPONSE_BUFFER_LEVEL"] ?? 0);
    if ($bufferLevel > 0) {
        while (ob_get_level() >= $bufferLevel) {
            @ob_end_clean();
        }
    }

    dialectic_emit_json_response_envelope(
        $GLOBALS["DIALECTIC_JSON_RESPONSE_LINES"] ?? [],
        (bool)($GLOBALS["DIALECTIC_JSON_RESPONSE_CLOSE"] ?? false)
    );
}

?>
