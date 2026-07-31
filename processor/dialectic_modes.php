<?php 

// Check modes should be here
// * Standard (STANDARD)
//      - when using text input, Easy Roleplay can be done just by prepending ** to the text)
//      Example:**(create a long speech about being the Courier) => I am no mere drifter on these cracked roads. I carried a bullet and walked back from the grave...
//      Example:**you are like a feral ghoul => You move like something the wastes forgot to finish killing.
//      - when using text input, you can achieve Event Injection With Response just putting text bewteen parenthesys
//      Example:(Volkur falls to the ground wounded)
//
// * Whisper (WHISPER)
//      Routes the turn privately to the selected listener using a quiet request-local range.
//
// * Close (CLOSE)
//      Routes the turn privately to one nearby listener within the plugin-owned 200-unit close radius.
//
// * Shout (SHOUT)
//      Expands local hearing/activation range and marks dialogue as shouted.
//
// * Narrator (NARRATOR)
//      Routes player speech privately to The Narrator only, using narrator_inputtext semantics.
//
// * Director. (DIRECTOR)
//      Call instruction directly.
//
//
// * Cheat Mode (CHEATMODE)
//      Processes ALL user input through cheatmode function (no # prefix required).
//      Sends input wrapped in <> brackets directly to LLM with functions enabled.
//      NPCs will execute whatever action/command is requested.
//      Example: "give me 1000 caps" => <give me 1000 caps>
//
// * Auto Chat (AUTOCHAT)
//      Generates clean text following player instructions using Fallout lore language.
//      Wraps input with **() to generate contextual text without stage directions.
//      Example: "Speech about being the Courier" => **(Generate text employing Fallout lore language and drawing upon the context, following the next instruction:Speech about being the Courier)
//      Example: "Hello" => **(Hello) 
//
// * Event Injection (INJECTION_LOG)
//      (Whatever is typed/said is injected into event log as an roleplay instruction)
//      Just store player speech on eventlog and die.
//
// * Event Injection With Response  (INJECTION_CHAT)
//      (Whatever is typed/said is injected into event log as an roleplay instruction expecting response)
//      Just store player speech on eventlog and follow the standard flow.

if (!isset($db)) $db = new sql();

function dialecticRunServiceManager(array $args, array &$output = null, int &$returnCode = null): void
{
    $managerPath = realpath(__DIR__ . DIRECTORY_SEPARATOR . ".." . DIRECTORY_SEPARATOR . "service" . DIRECTORY_SEPARATOR . "manager.php");
    if ($managerPath === false) {
        $managerPath = __DIR__ . DIRECTORY_SEPARATOR . ".." . DIRECTORY_SEPARATOR . "service" . DIRECTORY_SEPARATOR . "manager.php";
    }

    $cmd = escapeshellarg(PHP_BINARY) . " " . escapeshellarg($managerPath);
    foreach ($args as $arg) {
        $cmd .= " " . escapeshellarg((string)$arg);
    }

    $output = [];
    $returnCode = 0;
    exec($cmd, $output, $returnCode);
}

function dialecticModePayloadToArray($payload): array
{
    if (is_array($payload)) {
        return $payload;
    }

    $payload = trim((string)$payload);
    if ($payload === '') {
        return [];
    }

    $json = json_decode($payload, true);
    if (is_array($json)) {
        return $json;
    }

    return [];
}

function dialecticModeTextFromPayload($payload): string
{
    $payloadText = is_string($payload) ? trim($payload) : '';
    $parsed = dialecticModePayloadToArray($payload);

    foreach (["text", "speech", "message", "instruction", "prompt"] as $key) {
        if (isset($parsed[$key]) && trim((string)$parsed[$key]) !== '') {
            return trim((string)$parsed[$key]);
        }
    }

    if (isset($parsed["payload"])) {
        $nested = dialecticModeTextFromPayload($parsed["payload"]);
        if ($nested !== '') {
            return $nested;
        }
    }

    if (isset($parsed["json"]) && is_array($parsed["json"])) {
        $nested = dialecticModeTextFromPayload($parsed["json"]);
        if ($nested !== '') {
            return $nested;
        }
    }

    if ($payloadText === '') {
        return '';
    }

    return trim(preg_replace('/^[^:]+:\s*/', '', $payloadText));
}

function dialecticModeExtractPlayerText(array $gameRequest, string $receivedData): string
{
    $text = dialecticModeTextFromPayload($gameRequest[3] ?? '');
    if ($text === '') {
        $decoded = json_decode($receivedData, true);
        if (is_array($decoded)) {
            $text = dialecticModeTextFromPayload($decoded);
        }
    }

    return trim(preg_replace('/^[^:]+:\s*/', '', $text));
}

function dialecticModeTargetFromPayload(array $gameRequest): string
{
    $payload = (string)($gameRequest[3] ?? '');

    if (strpos($payload, '=') !== false) {
        $parsed = dialecticModePayloadToArray($payload);
        foreach (["npc", "target", "listener", "actor_name"] as $key) {
            if (isset($parsed[$key]) && is_scalar($parsed[$key])) {
                $target = trim((string)$parsed[$key]);
                if ($target !== '') {
                    return $target;
                }
            }
        }
    }

    if (function_exists('dialectic_extract_conversation_target')) {
        $target = dialectic_extract_conversation_target($payload);
        if ($target !== '' && strcasecmp($target, 'The Narrator') !== 0) {
            return $target;
        }
    }

    return '';
}

function dialecticModeReadablePayloadWithTarget(array $gameRequest, string $text): string
{
    $display = "(" . trim($text) . ")";
    $target = dialecticModeTargetFromPayload($gameRequest);
    if ($target === '') {
        return $display;
    }

    $target = trim(str_replace(["\r", "\n", "|", "(", ")"], " ", $target));
    $target = preg_replace('/\s+/', ' ', $target);
    return $target !== '' ? "{$display} (Talking to {$target})" : $display;
}

function dialecticModeValueFromArray(array $data): string
{
    foreach (["dialectic_mode", "mode", "execution_mode"] as $key) {
        if (isset($data[$key]) && trim((string)$data[$key]) !== '') {
            return strtoupper(trim((string)$data[$key]));
        }
    }

    if (isset($data["payload"])) {
        $nested = dialecticModePayloadToArray($data["payload"]);
        if (!empty($nested)) {
            $mode = dialecticModeValueFromArray($nested);
            if ($mode !== '') {
                return $mode;
            }
        }
    }

    if (isset($data["json"]) && is_array($data["json"])) {
        $mode = dialecticModeValueFromArray($data["json"]);
        if ($mode !== '') {
            return $mode;
        }
    }

    return '';
}

function dialecticModeExtractRequestedMode(array $gameRequest, string $receivedData): string
{
    $allowedModes = [
        "STANDARD",
        "WHISPER",
        "CLOSE",
        "SHOUT",
        "NARRATOR",
        "DIRECTOR",
        "INJECTION_LOG",
        "INJECTION_CHAT",
        "CHEATMODE",
    ];

    $mode = dialecticModeValueFromArray(dialecticModePayloadToArray($gameRequest[3] ?? ''));
    if ($mode === '') {
        $decoded = json_decode($receivedData, true);
        if (is_array($decoded)) {
            $mode = dialecticModeValueFromArray($decoded);
        }
    }

    return in_array($mode, $allowedModes, true) ? $mode : '';
}

function dialecticModeResetToStandard($db): void
{
    $db->upsertRow(
        'conf_opts',
        array(
            'id' => 'dialectic_mode',
            'value' => 'STANDARD'
        ),
        "id='dialectic_mode'"
    );
}

function dialecticModeNotify(string $message): void
{
    $message = trim(str_replace(["\r", "\n", "@"], [" ", " ", " at "], $message));
    if ($message === '') {
        return;
    }

    if (function_exists('dialectic_buffer_command_response_line')) {
        dialectic_buffer_command_response_line("rolemaster", "DebugNotification", ["message" => $message]);
        return;
    }

    if (function_exists('dialecticQueueCommandResponse')) {
        dialecticQueueCommandResponse("rolemaster", "DebugNotification", ["message" => $message]);
    }
}

function dialecticModeFlushQueuedRolecommands(): void
{
    if (!function_exists('DataDequeue') || !function_exists('dialectic_buffer_command_response_line')) {
        return;
    }

    $rows = DataDequeue(time() + 1);
    foreach ($rows as $row) {
        $command = trim((string)($row["action"] ?? ""));
        if ($command === '') {
            continue;
        }
        $speaker = trim((string)($row["actor"] ?? ""));
        if ($speaker === '') {
            $speaker = "rolemaster";
        }
        dialectic_buffer_command_response_line($speaker, $command, [
            "text" => (string)($row["text"] ?? ""),
            "rowid" => (string)($row["rowid"] ?? ""),
        ]);
    }
}

$EXECUTION_MODE_=$db->fetchOne("SELECT value FROM conf_opts WHERE id='dialectic_mode'");
$EXECUTION_MODE=isset($EXECUTION_MODE_["value"])?$EXECUTION_MODE_["value"]:"STANDARD";

$EXECUTION_MODE=strtoupper($EXECUTION_MODE);

if (!in_array($gameRequest[0],["inputtext","inputtext_s","narrator_inputtext"])) {
    $EXECUTION_MODE="STANDARD";
} else {
    $REQUESTED_EXECUTION_MODE = dialecticModeExtractRequestedMode($gameRequest, $receivedData ?? '');
    if ($REQUESTED_EXECUTION_MODE !== '') {
        $EXECUTION_MODE = $REQUESTED_EXECUTION_MODE;
        $db->upsertRow(
            'conf_opts',
            array(
                'id' => 'dialectic_mode',
                'value' => $EXECUTION_MODE
            ),
            "id='dialectic_mode'"
        );
    }
}

// Store globally for later use (e.g., updating speech table after LLM response)
$GLOBALS["DIALECTIC_EXECUTION_MODE"] = $EXECUTION_MODE;

if ($EXECUTION_MODE=="STANDARD") {


} else if ($EXECUTION_MODE=="WHISPER") {
    // The game plugin owns request-local whisper distance and audience routing.

} else if ($EXECUTION_MODE=="CLOSE") {
    // The game plugin owns the compact 200-unit close radius and target-only audience.

} else if ($EXECUTION_MODE=="SHOUT") {
    // The game plugin owns request-local shout distance and audience routing.

} else if ($EXECUTION_MODE=="NARRATOR") {
    if (in_array($gameRequest[0],["inputtext","inputtext_s","narrator_inputtext"], true)) {
        $gameRequest[0] = "narrator_inputtext";
    }
    
} else if ($EXECUTION_MODE=="DIRECTOR") {
    
    ignore_user_abort(true);

    $instruction = dialecticModeExtractPlayerText($gameRequest, $receivedData ?? '');
    $output=[];
    $returnCode=0;
    dialecticModeResetToStandard($db);

    if ($instruction === '') {
        dialecticModeNotify("Director mode failed: no instruction text was received.");
        terminate();
    }

    dialecticModeNotify("Director mode instruction received.");
    dialecticRunServiceManager(["rolemaster", "instruction", $instruction, "notify"], $output, $returnCode);
    dialecticModeFlushQueuedRolecommands();
    if (intval($returnCode ?? 0) !== 0) {
        dialecticModeNotify("Director mode instruction failed.");
    }
    terminate();

} else if ($EXECUTION_MODE=="CHEATMODE") {
    // Process all input as cheat commands
    $cleaned_player_dialogue = dialecticModeExtractPlayerText($gameRequest, $receivedData ?? '');
    $newSpeech = strtr($cleaned_player_dialogue, ["#"=>""]);
    $target = dialecticModeTargetFromPayload($gameRequest);
    if ($target !== '' && strcasecmp($target, 'The Narrator') !== 0) {
        $target = trim(str_replace(["\r", "\n", "|", "(", ")"], " ", $target));
        $target = preg_replace('/\s+/', ' ', $target);
        if ($target !== '') {
            $GLOBALS["DIALECTIC_CHEATMODE_TARGET"] = $target;
        }
    }
    error_log("[DIALECTIC_MODE] Cheat Mode request: " . $newSpeech);
    $gameRequest[0] = "cheatmode";
    $gameRequest[3] = "<$newSpeech>";
    $GLOBALS["FUNCTIONS_ARE_ENABLED"] = true;
    
} else if ($EXECUTION_MODE=="AUTOCHAT") {
    
    $cleaned_player_dialogue = dialecticModeExtractPlayerText($gameRequest, $receivedData ?? '');
    $gameRequest[3]="**(".trim($cleaned_player_dialogue).")";
    $GLOBALS["PLAYER_RESPEECH"] = true; // Route through player_rewrite.php for bio/speech style context
    
} else if ($EXECUTION_MODE=="INJECTION_LOG") {
    $cleaned_player_dialogue = dialecticModeExtractPlayerText($gameRequest, $receivedData ?? '');
    $gameRequest[3] = dialecticModeReadablePayloadWithTarget($gameRequest, $cleaned_player_dialogue);
    logEvent($gameRequest);
    terminate();
    
} else if ($EXECUTION_MODE=="INJECTION_CHAT") {
    $cleaned_player_dialogue = dialecticModeExtractPlayerText($gameRequest, $receivedData ?? '');

    $gameRequest[3] = dialecticModeReadablePayloadWithTarget($gameRequest, $cleaned_player_dialogue);

    
}

$CONTEXT_MODE=$db->fetchOne("SELECT value FROM conf_opts WHERE id='dialectic_context_mode'");
if (isset($CONTEXT_MODE["value"]) && $CONTEXT_MODE["value"]==1) 
    $GLOBALS["CLEAN_CONTEXT_FOCUS_CHAT"]=true;
else
    $GLOBALS["CLEAN_CONTEXT_FOCUS_CHAT"]=false;


?>
