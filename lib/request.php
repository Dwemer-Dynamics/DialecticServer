<?php

function dialectic_reject_json_request(int $statusCode, string $error, array $extra = []): void
{
    if (PHP_SAPI !== "cli" && !headers_sent()) {
        header('Content-Type: application/json; charset=utf-8');
    }

    http_response_code($statusCode);

    $payload = array_merge([
        "schema" => "dialectic.error.response.v1",
        "ok" => false,
        "error" => $error,
    ], $extra);

    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL;
    exit;
}

function dialectic_decode_json_body(): array
{
    if (PHP_SAPI === "cli" && getenv('PHPUNIT_TEST') && isset($GLOBALS["DIALECTIC_TEST_JSON_BODY"])) {
        $testBody = $GLOBALS["DIALECTIC_TEST_JSON_BODY"];
        if (is_array($testBody)) {
            return $testBody;
        }
        if (is_string($testBody) && trim($testBody) !== "") {
            $decoded = json_decode($testBody, true);
            return is_array($decoded) ? $decoded : [];
        }
    }

    if (PHP_SAPI === "cli") {
        return [];
    }

    $method = strtoupper(strval($_SERVER["REQUEST_METHOD"] ?? "GET"));
    if ($method === "OPTIONS") {
        http_response_code(204);
        exit;
    }

    if ($method !== "POST") {
        dialectic_reject_json_request(405, "Method Not Allowed", [
            "method" => $method,
        ]);
    }

    $contentType = strtolower(strval($_SERVER["CONTENT_TYPE"] ?? $_SERVER["HTTP_CONTENT_TYPE"] ?? ""));
    if (strpos($contentType, "application/json") === false) {
        dialectic_reject_json_request(415, "Unsupported Media Type", [
            "expected" => "application/json",
            "content_type" => $contentType,
        ]);
    }

    $raw = file_get_contents("php://input");
    if (!is_string($raw) || trim($raw) === "") {
        dialectic_reject_json_request(400, "Missing JSON request body");
    }

    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        dialectic_reject_json_request(400, "Invalid JSON request body");
    }

    return $decoded;
}

function dialectic_json_actor_name(array $actor): string
{
    foreach (["name", "actor_name", "display_name"] as $key) {
        $value = trim(strval($actor[$key] ?? ""));
        if ($value !== "") {
            return $value;
        }
    }
    return "";
}

function dialectic_normalize_json_event(array $data): array
{
    $now = time();
    $type = strtolower(trim(strval($data["type"] ?? "")));
    $ts = strval($data["ts"] ?? $now);
    $gamets = strval($data["gamets"] ?? $ts);
    $requestId = trim(strval($data["request_id"] ?? ""));

    $payload = $data["payload"] ?? "";
    if (is_array($payload)) {
        $encodedPayload = json_encode($payload, JSON_UNESCAPED_SLASHES);
        $payload = is_string($encodedPayload) ? $encodedPayload : "";
    } else {
        $payload = strval($payload);
    }

    $player = is_array($data["player"] ?? null) ? $data["player"] : [];
    $speaker = is_array($data["speaker"] ?? null) ? $data["speaker"] : [];
    $target = is_array($data["target"] ?? null) ? $data["target"] : [];
    $text = trim(strval($data["text"] ?? ""));
    $playerName = dialectic_json_actor_name($player);
    $speakerName = dialectic_json_actor_name($speaker);
    $targetName = dialectic_json_actor_name($target);

    if ($payload === "") {
        if (in_array($type, ["inputtext", "inputtext_s", "narrator_inputtext"], true)) {
            $structuredPayload = [
                "schema" => "dialectic.input.v1",
                "npc" => $targetName !== "" ? $targetName : $speakerName,
                "npc_id" => strval($target["refid"] ?? ""),
                "player" => $playerName,
                "text" => $text,
                "game" => strval($data["game"] ?? "fnv"),
            ];
            $structuredPayload = array_filter($structuredPayload, static function ($value) {
                return trim(strval($value)) !== "";
            });
            $payload = json_encode($structuredPayload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            $payload = is_string($payload) ? $payload : "";
        } elseif ($type === "_speech" || $type === "rechat") {
            $encoded = json_encode($data, JSON_UNESCAPED_SLASHES);
            $payload = is_string($encoded) ? $encoded : "";
        } elseif ($text !== "") {
            $payload = $text;
        }
    }

    return [
        "type" => $type,
        "ts" => $ts,
        "gamets" => $gamets,
        "payload" => $payload,
        "json" => $data,
        "source" => "json",
        "request_id" => $requestId,
        "response_format" => strtolower(trim(strval($data["response_format"] ?? ""))),
    ];
}

function dialectic_should_stream_json_response(array $event, string $acceptHeader = "", string $streamHeader = ""): bool
{
    $json = is_array($event["json"] ?? null) ? $event["json"] : [];
    $schema = trim(strval($json["schema"] ?? ""));
    $type = trim(strval($event["type"] ?? ""));
    $streamOverrideValue = array_key_exists("response_streaming", $json)
        ? $json["response_streaming"]
        : ($json["streaming"] ?? "");
    if (is_bool($streamOverrideValue)) {
        $streamOverride = $streamOverrideValue ? "true" : "false";
    } else {
        $streamOverride = strtolower(trim(strval($streamOverrideValue)));
    }

    if (in_array($streamOverride, ["0", "false", "no", "off", "buffered"], true)) {
        return false;
    }

    if (strpos(strtolower($acceptHeader), "application/x-ndjson") !== false) {
        return true;
    }

    if (in_array(strtolower(trim($streamHeader)), ["1", "true", "yes"], true)) {
        return true;
    }

    return strpos($schema, "dialectic.event.") === 0 && $type !== "";
}

function dialectic_decode_event_from_request(): array
{
    $json = dialectic_decode_json_body();
    if (!empty($json)) {
        return dialectic_normalize_json_event($json);
    }

    return [
        "type" => "",
        "ts" => "",
        "gamets" => "",
        "payload" => "",
        "json" => [],
        "source" => "json",
        "response_format" => "json",
    ];
}

function dialectic_event_to_received_data(array $event): string
{
    $encoded = json_encode($event["json"] ?? $event, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    return is_string($encoded) ? $encoded : "";
}

function dialectic_event_to_game_request(array $event): array
{
    $request = [
        strval($event["type"] ?? ""),
        strval($event["ts"] ?? ""),
        strval($event["gamets"] ?? ""),
        strval($event["payload"] ?? ""),
    ];

    $json = is_array($event["json"] ?? null) ? $event["json"] : [];
    $payload = $json["payload"] ?? null;
    if (!empty($json["audience_snapshot"])) {
        $request[4] = json_encode($json["audience_snapshot"], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    } elseif (is_array($payload) && !empty($payload["audience_snapshot"])) {
        $request[4] = json_encode($payload["audience_snapshot"], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    } elseif (!empty($json["people"]) || !empty($json["companions"])) {
        $snapshot = [];
        if (!empty($json["people"])) {
            $snapshot["people"] = $json["people"];
        }
        if (!empty($json["companions"])) {
            $snapshot["companions"] = $json["companions"];
        }
        $request[4] = json_encode($snapshot, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    return $request;
}

// Validates an actor reference before an external request can bind a profile.
function dialectic_normalize_external_formid(string $value): string
{
    $value = trim($value);
    if (preg_match('/^0x([0-9a-f]{8})$/i', $value, $matches) !== 1) {
        return "";
    }

    $normalized = "0x" . strtoupper($matches[1]);
    if ($normalized === "0x00000000" || $normalized === "0x00000014") {
        return "";
    }

    return $normalized;
}

// Decodes the fixed public xNVSE request contract without accepting arbitrary event types.
function dialectic_decode_external_actor_request(
    string $payload,
    string $expectedSchema,
    string $mode
): array {
    $result = [
        "ok" => false,
        "error" => "Invalid external request",
        "request" => "",
        "npc" => "",
        "npc_id" => "",
        "player" => "",
        "text" => "",
        "instruction" => "",
        "payload" => [],
    ];

    $decoded = json_decode($payload, true);
    if (!is_array($decoded)) {
        $result["error"] = "Payload must be a JSON object";
        return $result;
    }

    if (trim(strval($decoded["schema"] ?? "")) !== $expectedSchema) {
        $result["error"] = "Unsupported external request schema";
        return $result;
    }
    if (strtolower(trim(strval($decoded["game"] ?? ""))) !== "fnv") {
        $result["error"] = "External request must target fnv";
        return $result;
    }

    $mode = strtolower(trim($mode));
    $request = strtolower(trim(strval($decoded["request"] ?? $mode)));
    if ($request !== $mode || !in_array($mode, ["comment", "reaction", "tts"], true)) {
        $result["error"] = "External request type does not match endpoint";
        return $result;
    }

    $npc = preg_replace('/\s+/u', ' ', trim(strval($decoded["npc"] ?? $decoded["actor_name"] ?? "")));
    if (!is_string($npc) || $npc === "" || strlen($npc) > 160) {
        $result["error"] = "NPC name is missing or too long";
        return $result;
    }

    $npcId = dialectic_normalize_external_formid(strval(
        $decoded["npc_id"] ?? $decoded["speaker_formid"] ?? $decoded["refid"] ?? ""
    ));
    if ($npcId === "") {
        $result["error"] = "NPC FormID must be a non-player 0xXXXXXXXX reference";
        return $result;
    }

    $textKey = $mode === "reaction" ? "instruction" : ($mode === "tts" ? "text" : "");
    $text = $textKey !== "" ? trim(strval($decoded[$textKey] ?? "")) : "";
    if ($mode === "reaction" && $text !== "") {
        $text = preg_replace('/\s+/u', ' ', $text);
    }
    if ($textKey !== "" && (!is_string($text) || $text === "" || strlen($text) > 1000)) {
        $result["error"] = ucfirst($textKey) . " must contain 1 to 1000 bytes";
        return $result;
    }

    $result["ok"] = true;
    $result["error"] = "";
    $result["request"] = $request;
    $result["npc"] = $npc;
    $result["npc_id"] = $npcId;
    $result["player"] = trim(strval($decoded["player"] ?? ""));
    $result["text"] = $mode === "tts" ? $text : "";
    $result["instruction"] = $mode === "reaction" ? $text : "";
    $result["payload"] = $decoded;
    return $result;
}

// Renders the native combat snapshot supplied with a combat-bark event.
function dialectic_build_combat_prompt_from_event(array $event): string
{
    if (strtolower(trim(strval($event["type"] ?? ""))) !== "combatbark") {
        return "";
    }

    $json = is_array($event["json"] ?? null) ? $event["json"] : [];
    $payload = $json["payload"] ?? ($event["payload"] ?? []);
    if (is_string($payload)) {
        $decoded = json_decode($payload, true);
        $payload = is_array($decoded) ? $decoded : [];
    }
    if (!is_array($payload)) {
        return "";
    }

    $combat = $payload["combat"] ?? [];
    if (!is_array($combat)) {
        return "";
    }

    $normalizeNames = static function ($values): array {
        if (!is_array($values)) {
            return [];
        }

        $names = [];
        $seen = [];
        foreach ($values as $value) {
            if (!is_scalar($value)) {
                continue;
            }
            $name = preg_replace('/\s+/u', ' ', trim(strval($value)));
            if (!is_string($name) || $name === '') {
                continue;
            }
            $key = function_exists('mb_strtolower') ? mb_strtolower($name, 'UTF-8') : strtolower($name);
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $names[] = htmlspecialchars($name, ENT_QUOTES | ENT_XML1 | ENT_SUBSTITUTE, 'UTF-8');
        }
        return $names;
    };

    $allies = $normalizeNames($combat["allies_currently_fighting"] ?? []);
    $hostiles = $normalizeNames($combat["hostile_combatants"] ?? []);
    if (empty($allies) && empty($hostiles)) {
        return "";
    }

    $allyLines = implode("\n", array_map(static fn(string $name): string => "- {$name}", $allies));
    $hostileLines = implode("\n", array_map(static fn(string $name): string => "- {$name}", $hostiles));

    return "<combat>\n# Allies Currently Fighting\n{$allyLines}\n\n"
        . "# Hostile Combatants\n{$hostileLines}\n</combat>";
}

function dialectic_extract_conversation_target(string $payload): string
{
    $decoded = json_decode($payload, true);
    if (is_array($decoded)) {
        $targetValue = $decoded["target"] ?? "";
        $speakerValue = $decoded["speaker"] ?? "";
        $targetName = is_array($targetValue) ? ($targetValue["name"] ?? "") : $targetValue;
        $speakerName = is_array($speakerValue) ? ($speakerValue["name"] ?? "") : $speakerValue;
        foreach ([
            $targetName,
            $speakerName,
            $decoded["npc"] ?? "",
            $decoded["actor_name"] ?? "",
        ] as $candidate) {
            $candidate = trim(strval($candidate));
            if ($candidate !== "") {
                return $candidate;
            }
        }
    }

    return "The Narrator";
}

function dialectic_payload_bool_value($value): bool
{
    if (is_bool($value)) {
        return $value;
    }

    $normalized = strtolower(trim(strval($value)));
    return in_array($normalized, ["1", "true", "yes", "on"], true);
}

function dialectic_sanitize_pipeline_input_fragment(string $value): string
{
    $value = str_replace(["\r", "\n", "|"], " ", $value);
    $value = preg_replace('/\s+/', ' ', $value);
    return trim((string)$value);
}

function dialectic_adapt_json_input_payload_for_pipeline(array &$gameRequest): array
{
    $result = [
        "changed" => false,
        "raw" => "",
        "fields" => [],
        "text" => "",
        "player" => "",
        "target" => "",
        "display" => "",
        "skip_player_tts" => false,
    ];

    $eventType = strtolower(trim(strval($gameRequest[0] ?? "")));
    if (!in_array($eventType, ["inputtext", "inputtext_s", "narrator_inputtext"], true)) {
        return $result;
    }

    $rawPayload = strval($gameRequest[3] ?? "");
    $result["raw"] = $rawPayload;
    if (trim($rawPayload) === "") {
        return $result;
    }

    $fields = function_exists("dialectic_parse_payload_fields")
        ? dialectic_parse_payload_fields($rawPayload)
        : [];

    if (empty($fields)) {
        return $result;
    }

    $result["fields"] = $fields;
    $result["skip_player_tts"] = dialectic_payload_bool_value($fields["skip_player_tts"] ?? false);

    $text = "";
    foreach (["text", "message", "input", "utterance", "player_text", "speech", "line"] as $key) {
        if (isset($fields[$key]) && is_scalar($fields[$key]) && trim(strval($fields[$key])) !== "") {
            $text = trim(strval($fields[$key]));
            break;
        }
    }

    if ($text === "") {
        return $result;
    }

    $player = "";
    foreach (["player", "player_name", "player_actor"] as $key) {
        if (isset($fields[$key]) && is_scalar($fields[$key]) && trim(strval($fields[$key])) !== "") {
            $player = trim(strval($fields[$key]));
            break;
        }
    }
    if ($player === "") {
        $player = trim(strval($GLOBALS["PLAYER_NAME"] ?? "Player"));
    }

    $target = "";
    foreach (["npc", "target", "listener", "actor_name", "speaker"] as $key) {
        if (isset($fields[$key]) && is_scalar($fields[$key]) && trim(strval($fields[$key])) !== "") {
            $target = trim(strval($fields[$key]));
            break;
        }
    }
    if ($target === "" && function_exists("dialectic_extract_conversation_target")) {
        $target = dialectic_extract_conversation_target($rawPayload);
        if (strcasecmp($target, "The Narrator") === 0) {
            $target = "";
        }
    }

    $player = dialectic_sanitize_pipeline_input_fragment($player);
    $text = dialectic_sanitize_pipeline_input_fragment($text);
    $target = dialectic_sanitize_pipeline_input_fragment(str_replace(["(", ")"], " ", $target));

    $isDirectorInstruction = dialectic_payload_bool_value($fields["director_instruction"] ?? false);
    $display = $player !== "" ? "{$player}: {$text}" : $text;
    if ($isDirectorInstruction) {
        $listener = dialectic_sanitize_pipeline_input_fragment(
            is_scalar($fields["director_target"] ?? null) ? strval($fields["director_target"]) : ''
        );
        $display = "Director instruction for {$target}";
        if ($listener !== '') {
            $display .= " (listener: {$listener})";
        }
        $display .= ": {$text}";
        $result["skip_player_tts"] = true;
    } elseif ($target !== "" && strcasecmp($target, "The Narrator") !== 0) {
        $display .= " (Talking to {$target})";
    }

    if (trim($display) === "") {
        return $result;
    }

    $gameRequest[3] = $display;
    if (!isset($gameRequest[4]) && isset($fields["audience_snapshot"]) && trim(strval($fields["audience_snapshot"])) !== "") {
        $gameRequest[4] = strval($fields["audience_snapshot"]);
    }

    $GLOBALS["DIALECTIC_STRUCTURED_INPUT_FIELDS"] = $fields;
    $GLOBALS["DIALECTIC_DIRECTOR_INPUT"] = $isDirectorInstruction;
    $GLOBALS["DIALECTIC_PLAYER_INPUT_TEXT"] = $text;
    $GLOBALS["DIALECTIC_INPUT_TARGET"] = $target;
    $GLOBALS["DIALECTIC_SKIP_PLAYER_TTS"] = $result["skip_player_tts"];

    $result["changed"] = true;
    $result["text"] = $text;
    $result["player"] = $player;
    $result["target"] = $target;
    $result["display"] = $display;
    return $result;
}

function dialectic_adapt_json_vision_payload_for_pipeline(array &$gameRequest): array
{
    $result = [
        "changed" => false,
        "target" => "",
        "description" => "",
    ];
    if (strtolower(trim(strval($gameRequest[0] ?? ""))) !== "vision") {
        return $result;
    }

    $rawPayload = strval($gameRequest[3] ?? "");
    $fields = json_decode($rawPayload, true);
    if (!is_array($fields)) {
        return $result;
    }

    $description = "";
    foreach (["text", "description", "visual_context", "scene"] as $key) {
        if (isset($fields[$key]) && is_scalar($fields[$key]) && trim(strval($fields[$key])) !== "") {
            $description = dialectic_sanitize_pipeline_input_fragment(trim(strval($fields[$key])));
            break;
        }
    }
    if ($description === "") {
        return $result;
    }

    $target = dialectic_extract_conversation_target($rawPayload);
    if (strcasecmp($target, "The Narrator") === 0) {
        $target = "";
    }
    $target = dialectic_sanitize_pipeline_input_fragment($target);
    if ($target !== "") {
        $GLOBALS["DIALECTIC_INPUT_TARGET"] = $target;
    }

    $gameRequest[3] = "PipVision visual observation: " . $description;
    $result["changed"] = true;
    $result["target"] = $target;
    $result["description"] = $description;
    return $result;
}

function dialectic_extract_funcret_actor(string $payload): string
{
    $decoded = json_decode($payload, true);
    if (is_array($decoded)) {
        foreach ([
            $decoded["speaker"] ?? "",
            $decoded["npc"] ?? "",
            $decoded["actor_name"] ?? "",
            $decoded["character"] ?? "",
            $decoded["target"] ?? "",
        ] as $candidate) {
            $candidate = is_array($candidate) ? ($candidate["name"] ?? "") : $candidate;
            $candidate = trim(strval($candidate));
            if ($candidate !== "") {
                return $candidate;
            }
        }
    }

    return "";
}

?>
