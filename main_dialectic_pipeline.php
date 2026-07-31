<?php

/* Definitions and main includes */
error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('log_errors', '1');

@define("STOPALL_MAGIC_WORD", "/wake up/i");

@define("MAXIMUM_SENTENCE_SIZE", 125);
@define("MINIMUM_SENTENCE_SIZE", 75);

date_default_timezone_set('America/Los_Angeles');

if (!function_exists('mb_scrub')) {
    function mb_scrub($string, $encoding = null)
    {
        return is_string($string) ? $string : '';
    }
}

$GLOBALS["AVOID_TTS_CACHE"]=true;
$GLOBALS["DIALECTIC_NO_EXAMPLES"]=true; // Keep prompt examples disabled for the Dialectic runtime.
$GLOBALS["MEMORY_THRESHOLD_MODIFIER"]=0;    // POST MEMORY
$GLOBALS["fallout_start_date"] = '2281-10-19 00:00:00'; // Fallout: New Vegas start period used for in-game date conversion.
$GLOBALS["DIALECTIC_GAME_ID"] = "fnv";
$GLOBALS["DIALECTIC_WORLD_NAME"] = "Mojave Wasteland";
$GLOBALS["SEMAPHORES_TIMEOUT"] = 300; 
$GLOBALS["TTS_INJECT_NONVERBAL_VOCALIZATION"] = true; // Spice the TTS with non-verbal vocalization when expressing strong emotion. 
$GLOBALS['use_emotions_expression'] = true; 


// Cooldown for some actions
$COOLDOWNMAP=[];

$path = dirname((__FILE__)) . DIRECTORY_SEPARATOR;
$GLOBALS["ENGINE_PATH"]=$path;

require_once($path . "lib/runtime_bootstrap.php");
require_once($path . "lib/request.php");
require_once($path . "lib/response.php");
require_once($path . "lib/player_tts_helpers.php");
dialecticRuntimeBootstrap($path, [
    'load_general_settings' => true,
    'load_stt_connector' => true,
    'load_player_name' => true,
    'load_narrator' => true,
    'run_db_updates' => false,
]);
require_once($path . "lib/player2_health.php");
require_once($path . "lib/auditing.php");
require_once($path . "lib/model_dynmodel.php");
require_once($path . "lib/minimet5_service.php");
require_once($path . "lib/data_functions.php");
require_once($path . "lib/chat_helper_functions.php");
require_once($path . "lib/lazy_xml.php");
require_once($path . "lib/memory_helper_vectordb.php");
require_once($path . "lib/llm_randomizer.php");
require_once($path . "lib/utils_game_timestamp.php");
require_once($path . "lib/logger.php"); 
require_once($path . "lib/save_rollback.php");
require_once($path . "processor/captured_dialogue.php");

// New profile system
require_once($path . "lib/core/api_badge.class.php");
require_once($path . "lib/core/llm_connector.class.php");
require_once($path . "lib/core/tts_connector.class.php");
require_once($path . "lib/core/npc_master.class.php");
require_once($path . "lib/core/core_profiles.class.php");
require_once($path . "lib/semaphore_manager.class.php");

// Normalize the structured JSON request into the internal $gameRequest tuple.
$cooldownPeriod = 600;


if (php_sapi_name()=="cli" && !getenv('PHPUNIT_TEST')) {
    // You can run this script directly with php: main.php "Player text"
    $GLOBALS["db"] = new sql();
    $db = $GLOBALS["db"];

    $latsRid=$db->fetchAll("select * from eventlog order by rowid desc LIMIT 1 OFFSET 0");
    $res=$db->fetchAll("select max(gamets)+1 as gamets,max(ts)+1 as ts  from eventlog where rowid={$latsRid[0]["rowid"]}");
    $res[0]["ts"]=$res[0]["ts"]+1;
    $res[0]["gamets"]=$res[0]["gamets"]+1;
        
    
        
    $dialecticRequestEvent = dialectic_normalize_json_event([
        "schema" => "dialectic.input.v1",
        "type" => "inputtext",
        "ts" => $res[0]["ts"],
        "gamets" => $res[0]["gamets"],
        "player" => [
            "name" => $GLOBALS["PLAYER_NAME"] ?? "Player",
        ],
        "target" => [
            "name" => $GLOBALS["DIALECTIC_NAME"] ?? "The Narrator",
        ],
        "text" => $argv[1] ?? "",
        "game" => "fnv",
        "response_format" => "json",
    ]);
    $receivedData = dialectic_event_to_received_data($dialecticRequestEvent);
    $GLOBALS["DIALECTIC_REQUEST_EVENT"] = $dialecticRequestEvent;
    $GLOBALS["DIALECTIC_RESPONSE_FORMAT"] = "json";
    dialecticRuntimeSetActiveProfile($argv[2] ?? '');
    $GLOBALS["FUNCTIONS_ARE_ENABLED"]=true;

    unset($GLOBALS["db"]);
} else {
    $dialecticRequestEvent = dialectic_decode_event_from_request();
    if (!empty($dialecticRequestEvent["request_id"])) {
        Logger::setRequestId((string)$dialecticRequestEvent["request_id"]);
    } elseif (!empty($dialecticRequestEvent["payload"]["request_id"])) {
        Logger::setRequestId((string)$dialecticRequestEvent["payload"]["request_id"]);
    } else {
        Logger::bootstrapRequestId("main");
    }
    $receivedData = mb_scrub(dialectic_event_to_received_data($dialecticRequestEvent));
    $GLOBALS["DIALECTIC_REQUEST_EVENT"] = $dialecticRequestEvent;
    $GLOBALS["DIALECTIC_RESPONSE_FORMAT"] = "json";
    $acceptHeader = strtolower((string)($_SERVER["HTTP_ACCEPT"] ?? ""));
    $streamHeader = strtolower((string)($_SERVER["HTTP_X_DIALECTIC_STREAM"] ?? ""));
    $GLOBALS["DIALECTIC_RESPONSE_STREAMING"] = dialectic_should_stream_json_response(
        $dialecticRequestEvent,
        $acceptHeader,
        $streamHeader
    );

    // Runtime profile is resolved from JSON payload data later in the pipeline.

}


if (!isset($FUNCTIONS_ARE_ENABLED)) {
    $FUNCTIONS_ARE_ENABLED=false;
}

if (!function_exists('dialecticSummarizePlayerConsumedPayload')) {
function dialecticSummarizePlayerConsumedPayload(string $rawPayload): string
{
    $payload = json_decode($rawPayload, true);
    if (!is_array($payload)) {
        return $rawPayload;
    }

    $text = trim((string)($payload["text"] ?? ""));
    if ($text !== "") {
        return $text;
    }

    $playerName = trim((string)($payload["player"] ?? ($GLOBALS["PLAYER_NAME"] ?? "The Courier")));
    $itemNames = [];
    foreach (($payload["items"] ?? []) as $item) {
        if (!is_array($item)) {
            continue;
        }
        $name = trim((string)($item["name"] ?? ""));
        if ($name !== "") {
            $itemNames[] = $name;
        }
    }
    if (empty($itemNames) && isset($payload["item"]) && is_array($payload["item"])) {
        $name = trim((string)($payload["item"]["name"] ?? ""));
        if ($name !== "") {
            $itemNames[] = $name;
        }
    }
    if (empty($itemNames)) {
        return $rawPayload;
    }

    if (count($itemNames) === 1) {
        $itemText = $itemNames[0];
    } elseif (count($itemNames) === 2) {
        $itemText = $itemNames[0] . " and " . $itemNames[1];
    } else {
        $last = array_pop($itemNames);
        $itemText = implode(", ", $itemNames) . ", and " . $last;
    }

    return $playerName . " consumed " . $itemText;
}
}


while (!getenv('PHPUNIT_TEST') && ob_get_length() && ob_end_clean())	;
if (!getenv('PHPUNIT_TEST')) {
    dialectic_start_json_response_buffer();
}
ignore_user_abort(true);
set_time_limit(1200);

$momentum=time();
$GLOBALS["runid"]=uniqid("run_",false);
// Array with sentences talked so far
$talkedSoFar = array();

// Array with sentences sent so far
$alreadysent = array();

// Array with parameters to override
$overrideParameters=array();

$ERROR_TRIGGERED=false;

$LAST_ROLE="user";

// SCRIPT LINE QUEUE
$GLOBALS["SCRIPTLINE_EXPRESSION"]="";
$GLOBALS["SCRIPTLINE_LISTENER"]="";
$GLOBALS["SCRIPTLINE_ANIMATION"]="";

$GLOBALS["TTS_FFMPEG_FILTERS"]=[];

/**********************
MAIN FLOW
***********************/

if (isset($GLOBALS["DIALECTIC_REQUEST_EVENT"]) && is_array($GLOBALS["DIALECTIC_REQUEST_EVENT"])) {
    $gameRequest = dialectic_event_to_game_request($GLOBALS["DIALECTIC_REQUEST_EVENT"]);
} else {
    $gameRequest = ["", "", "", ""];
}
$GLOBALS["gameRequest"] = &$gameRequest;
unset($GLOBALS["DIALECTIC_TURN_PEOPLE_SNAPSHOT"]);


$startTime = microtime(true);
$GLOBALS["DIALECTIC_TURN_START_TIME"] = $startTime;
//error_log("Audit run ID: " . $GLOBALS["AUDIT_RUNID"]. " ({$gameRequest[0]}) started: ".$startTime);
$GLOBALS["AUDIT_RUNID_REQUEST"]=$gameRequest[0];

$gameRequest[0] = strtolower($gameRequest[0]); // Who put 'diary' uppercase?
if (PHP_SAPI !== 'cli' && !getenv('PHPUNIT_TEST') && $gameRequest[0] !== 'request') {
    dialecticPlayer2HealthMarkGameActivity();
}
Logger::phaseStart("turn", [
    "type" => $gameRequest[0],
    "gamets" => $gameRequest[2] ?? "",
    "payload_preview" => isset($gameRequest[3]) ? Logger::summarizePayload((string)$gameRequest[3], 180) : "",
]);
Logger::info("[main] Request start" . Logger::formatContext([
    "type" => $gameRequest[0],
    "ts" => $gameRequest[1] ?? "",
    "gamets" => $gameRequest[2] ?? "",
    "streaming" => !empty($GLOBALS["DIALECTIC_RESPONSE_STREAMING"]),
]));

if (($gameRequest[0] ?? '') !== 'init') {
    dialecticMaybeHandleIncomingGametsRollback($gameRequest[2] ?? 0, 'main:' . ($gameRequest[0] ?? 'unknown'), false);
}

if (in_array($gameRequest[0], ["conversation_start", "conversation_end"], true)) {
    $conversationSpeaker = function_exists('dialectic_extract_conversation_target')
        ? dialectic_extract_conversation_target((string)($gameRequest[3] ?? ""))
        : "The Narrator";

    if ($gameRequest[0] === "conversation_start") {
        // Conversation start is state-only. Speaking a greeting here blocks the
        // first real player response when chat opens and inputtext is sent.
    } else {
        dialectic_buffer_response_close();
    }

    if (dialectic_json_response_enabled()) {
        dialectic_emit_buffered_json_response();
    }
    @flush();
    exit;
}

// Database Connection
$db = $GLOBALS["db"] ?? new sql();
$GLOBALS["db"] = $db;

if (isset($gameRequest[3]) && is_string($gameRequest[3]) && in_array($gameRequest[0], [
    'infoplayer',
    'playerinfo',
    'newgame',
    'conversation_start',
    'inputtext',
    'inputtext_s',
        'narrator_inputtext',
    'cheatmode',
], true)) {
    dialecticMaybeSyncPlayerNameFromGamePayload($gameRequest[3]);
}

if ($gameRequest[0] === "captured_dialogue") {
    dialecticHandleCapturedDialogueEvent($gameRequest);
    if (dialectic_json_response_enabled()) {
        dialectic_emit_buffered_json_response();
    }
    @flush();
    exit;
}

require_once($path . "processor" .DIRECTORY_SEPARATOR."dialectic_modes.php");

if (function_exists("dialectic_adapt_json_input_payload_for_pipeline")) {
    $normalizedInputPayload = dialectic_adapt_json_input_payload_for_pipeline($gameRequest);
    if (!empty($normalizedInputPayload["changed"])) {
        Logger::info("[main] Adapted structured JSON input payload for dialogue pipeline" . Logger::formatContext([
            "player" => $normalizedInputPayload["player"] ?? "",
            "target" => $normalizedInputPayload["target"] ?? "",
            "chars" => strlen((string)($normalizedInputPayload["text"] ?? "")),
            "skip_player_tts" => !empty($normalizedInputPayload["skip_player_tts"]) ? "true" : "false",
        ]));
    }
}

if (function_exists("dialectic_adapt_json_vision_payload_for_pipeline")) {
    $normalizedVisionPayload = dialectic_adapt_json_vision_payload_for_pipeline($gameRequest);
    if (!empty($normalizedVisionPayload["changed"])) {
        Logger::info("[main] Adapted structured PipVision payload for vision pipeline" . Logger::formatContext([
            "target" => $normalizedVisionPayload["target"] ?? "",
            "chars" => strlen((string)($normalizedVisionPayload["description"] ?? "")),
        ]));
    }
}

// In directed DIALECTIC modes, normalize incoming dialogue tags so logs/prompts stay aligned
// with the active speaking style.
$dialecticExecutionMode = strtoupper((string)($GLOBALS["DIALECTIC_EXECUTION_MODE"] ?? ""));
if (isset($gameRequest[3]) && is_string($gameRequest[3]) &&
    in_array($gameRequest[0], ["inputtext", "inputtext_s", "narrator_inputtext", "chat", "prechat", "rechat", "continue"], true)) {
    if ($dialecticExecutionMode === "WHISPER") {
        $gameRequest[3] = convertTalkingTagsToWhispering($gameRequest[3]);
    } elseif ($dialecticExecutionMode === "CLOSE") {
        $gameRequest[3] = convertTalkingTagsToPrivate($gameRequest[3]);
    } elseif ($dialecticExecutionMode === "SHOUT") {
        $gameRequest[3] = convertTalkingTagsToShouting($gameRequest[3]);
    }
}

if (in_array($gameRequest[0],["inputtext","inputtext_s","narrator_inputtext","cheatmode","instruction","init"])) {
    // This is just a mark that user has made an input request. We will check later when waiting for LLm response 
    // if user has made input after initial request, so we can abort it.
    // $db = new sql();
    $db->insert(
        'eventlog',
        array(
            'ts' => $gameRequest[1],
            'gamets' => $gameRequest[2],
            'type' => "user_input",
            'data' => $gameRequest[0],
            'sess' => 'pending',
            'localts' => time(),
            'people'=> '',
            'location'=>'',
            'party'=>''
        )
    );
    // unset($db);
}


$fast_commands = ["updateprofile","updateprofile_narrator","diary","diary_narrator","diary_player","setconf","request","_speech","captured_dialogue",
    "infoaction","status_msg","delete_event","itemfound","chat","goodnight","waitstart","waitstop",
    "updateprofiles_batch_async","core_profile_assign","switchrace","combatbark",
    "region"];

$GLOBALS["all_fast_commands"] = $fast_commands;

$semaphore_timeout = $GLOBALS["SEMAPHORES_TIMEOUT"] ?? 300;


// Use logical id "MAIN" so other code can still find $GLOBALS["SEMAPHORES"]["MAIN"]
if (!in_array($gameRequest[0],$fast_commands)) {
    if (!SemaphoreWait("MAIN", $semaphore_timeout, 1003, null)) {
        Logger::warn("[main] main semaphore wait failed for {$gameRequest[0]}");
        terminate();
    }
    Logger::info("Audit:Lock acquired by {$gameRequest[0]}");
} 

if (($gameRequest[0]=="playerinfo")||(($gameRequest[0]=="newgame"))) {
    sleep(1);   // Give time to populate data
}

// Misc events, some of them can terminate the request
// delete_event and other fast event-only handlers
require(__DIR__."/processor/misc.php");


// Player rewrite

// Will change  $gameRequest[3] with the rewritten LLM request.

$player_rewrite_speech = "";
if (in_array($gameRequest[0],["inputtext","inputtext_s","narrator_inputtext"]) && isset($GLOBALS["PLAYER_RESPEECH"]) && $GLOBALS["PLAYER_RESPEECH"]) {
    // Use preg_replace to remove the name and colon before the dialogue
    $cleaned_player_dialogue = addcslashes(preg_replace('/^[^:]+:/', '', $gameRequest[3]),'"');
    error_log($cleaned_player_dialogue);
    if (strpos($gameRequest[3],"**")===0 || strpos($cleaned_player_dialogue,"**")===0 ) {
        // If player speech starts with **
        error_log("Overwritting user prompt $cleaned_player_dialogue");

        // Profile isn't loaded yet at this point, so derive the NPC name from the DB using the profile MD5
        $npcTarget = '';
        $activeProfileForRewrite = dialecticRuntimeGetActiveProfile();
        if ($activeProfileForRewrite !== null && $activeProfileForRewrite !== md5('The Narrator')) {
            $npcRow = $db->fetchOne("SELECT npc_name FROM core_npc_master WHERE md5='" . $db->escape($activeProfileForRewrite) . "' LIMIT 1");
            if ($npcRow && !empty($npcRow['npc_name'])) {
                $npcTarget = $npcRow['npc_name'];
            }
        }
        $escapedDialogue = escapeshellarg($cleaned_player_dialogue);
        $escapedNpc = escapeshellarg($npcTarget);
        $player_rewrite_speech=`php player_rewrite.php $escapedDialogue $escapedNpc`;
        $player_rewrite_speech=cleanResponse($player_rewrite_speech);
        $player_rewrite_speech=sanitizePlayerRespeechText($player_rewrite_speech, $GLOBALS["PLAYER_NAME"] ?? null);
        $gameRequest[3]="{$GLOBALS["PLAYER_NAME"]}:$player_rewrite_speech";
        $GLOBALS["DIALECTIC_EXECUTION_MODE"] = "AUTOCHAT"; //required when using STANDARD/WHISPER and ** prefix triggers speech database fix
    }
}


// Narrator inititalization
// Note: We should check if we need to load Narrator profile in all type of requests. 
require(__DIR__."/processor/narrator_init.php");

// maybeQueueNpcVoiceRefresh function moved to misc.php. 
// If function is called only in one place,and seems has no other uses elsewhere, then there is no point of having a function, write the code in place.
// Also, we must not declare functions on this file (main.php).

// Profile loading
if (!isset($GLOBALS["NARRATOR_BORED_EVENT_ACTIVE"])) {
    $GLOBALS["NARRATOR_BORED_EVENT_ACTIVE"] = false;
}

if (in_array(($gameRequest[0] ?? ''), ['bored', 'auto_greeting'], true) && dialecticRuntimeGetActiveProfile() === null) {
    $boredTarget = function_exists('dialectic_extract_conversation_target')
        ? dialectic_extract_conversation_target((string)($gameRequest[3] ?? ""))
        : "";

    if ($boredTarget !== "" &&
        strcasecmp($boredTarget, "The Narrator") !== 0) {
        $boredNpcMaster = new NpcMaster();
        $boredNpcData = $boredNpcMaster->getByName($boredTarget);
        if (is_array($boredNpcData) && !empty($boredNpcData["md5"])) {
            dialecticRuntimeSetActiveProfile($boredNpcData["md5"]);
            Logger::info("[GAME_EVENT_PROFILE] Bound {$gameRequest[0]} event to NPC profile {$boredTarget}");
        } else {
            Logger::warn("[GAME_EVENT_PROFILE] Could not bind {$gameRequest[0]} event to NPC profile {$boredTarget}");
        }
    }
}

if (in_array(($gameRequest[0] ?? ''), ['rpg_lvlup', 'combatend', 'combatendmighty', 'combatbark', 'lockpicked', 'goodmorning', 'player_consumed', 'location_changed', 'quest_updated'], true) && dialecticRuntimeGetActiveProfile() === null) {
    $rpgTarget = function_exists('dialectic_extract_conversation_target')
        ? dialectic_extract_conversation_target((string)($gameRequest[3] ?? ""))
        : "";

    if ($rpgTarget !== "" &&
        strcasecmp($rpgTarget, "The Narrator") !== 0) {
        $rpgNpcMaster = new NpcMaster();
        $rpgNpcData = $rpgNpcMaster->getByName($rpgTarget);
        if (is_array($rpgNpcData) && !empty($rpgNpcData["md5"])) {
            dialecticRuntimeSetActiveProfile($rpgNpcData["md5"]);
            Logger::info("[GAME_EVENT_PROFILE] Bound {$gameRequest[0]} event to NPC profile {$rpgTarget}");
        } else {
            Logger::warn("[GAME_EVENT_PROFILE] Could not bind {$gameRequest[0]} event to NPC profile {$rpgTarget}");
        }
    }
}

$inputRequestType = $gameRequest[0] ?? '';
if (in_array($inputRequestType, ["inputtext", "inputtext_s", "cheatmode", "vision"], true)) {
    Logger::phaseStart("input_profile_bind", [
        "type" => $inputRequestType,
    ]);
    $inputTarget = ($gameRequest[0] ?? '') === "cheatmode"
        ? trim((string)($GLOBALS["DIALECTIC_CHEATMODE_TARGET"] ?? ""))
        : "";
    if ($inputTarget === "") {
        $inputTarget = trim((string)($GLOBALS["DIALECTIC_INPUT_TARGET"] ?? ""));
    }
    if ($inputTarget === "") {
        $inputTarget = function_exists('dialectic_extract_conversation_target')
            ? dialectic_extract_conversation_target((string)($gameRequest[3] ?? ""))
            : "";
    }

    if ($inputTarget !== "" &&
        strcasecmp($inputTarget, "The Narrator") !== 0) {
        $inputProfileFields = function_exists('dialectic_extract_npc_profile_fields')
            ? dialectic_extract_npc_profile_fields((string)($gameRequest[3] ?? ""))
            : [];
        if (empty($inputProfileFields) && isset($GLOBALS["DIALECTIC_REQUEST_EVENT"]["payload"])) {
            $inputProfileFields = function_exists('dialectic_extract_npc_profile_fields')
                ? dialectic_extract_npc_profile_fields((string)$GLOBALS["DIALECTIC_REQUEST_EVENT"]["payload"])
                : [];
        }
        $inputRefid = function_exists('dialectic_extract_npc_refid')
            ? dialectic_extract_npc_refid((string)($gameRequest[3] ?? ""), $inputProfileFields)
            : "";
        if ($inputRefid === "" && isset($GLOBALS["DIALECTIC_REQUEST_EVENT"]["payload"])) {
            $inputRefid = function_exists('dialectic_extract_npc_refid')
                ? dialectic_extract_npc_refid((string)$GLOBALS["DIALECTIC_REQUEST_EVENT"]["payload"], $inputProfileFields)
                : "";
        }
        dialectic_ensure_npc($db, $inputTarget, $inputRefid, $inputProfileFields);

        $inputNpcMaster = new NpcMaster();
        $inputNpcData = $inputNpcMaster->getByName($inputTarget);
        if (is_array($inputNpcData) && !empty($inputNpcData["md5"])) {
            dialecticRuntimeSetActiveProfile($inputNpcData["md5"]);
            Logger::info("[INPUT_PROFILE] Bound {$gameRequest[0]} request to NPC profile {$inputTarget}");
        } else {
            Logger::warn("[INPUT_PROFILE] Could not bind {$gameRequest[0]} request to NPC profile {$inputTarget}");
        }
    }
    Logger::phaseEnd("input_profile_bind", [
        "type" => $inputRequestType,
        "target" => $inputTarget,
        "active_profile" => dialecticRuntimeGetActiveProfile() ?? "",
    ], "info");
}


// Bored
if (($gameRequest[0] ?? '') === 'bored') {
    require_once(__DIR__ . DIRECTORY_SEPARATOR . "lib" . DIRECTORY_SEPARATOR . "core" . DIRECTORY_SEPARATOR . "narrator.class.php");
    $narratorSettings = new Narrator();
    if ($narratorSettings->getBool('bored_enabled', false)) {
        $boredChance = max(1, min(100, $narratorSettings->getInt('bored_chance', 25)));
        $boredRoll = random_int(1, 100);
        if ($boredRoll <= $boredChance) {
            dialecticRuntimeSetActiveProfile(md5('The Narrator'));
            $GLOBALS["NARRATOR_BORED_EVENT_ACTIVE"] = true;
            Logger::info("[NARRATOR_BORED] Routing bored event through The Narrator runtime (roll {$boredRoll}/{$boredChance})");
        } else {
            Logger::info("[NARRATOR_BORED] Keeping bored event on NPC runtime (roll {$boredRoll}/{$boredChance})");
        }
    }
}

if (($activeProfile = dialecticRuntimeGetActiveProfile()) !== null) {
    Logger::phaseStart("profile_runtime_load", [
        "type" => $gameRequest[0] ?? "",
        "profile" => $activeProfile,
    ]);
    
    // Initialize OVERRIDES array for all profile types
$OVERRIDES["MINIME_T5"] = isset($GLOBALS["MINIME_T5"]) ? $GLOBALS["MINIME_T5"] : false;
    $OVERRIDES["STTFUNCTION"] = isset($GLOBALS["STTFUNCTION"]) ? $GLOBALS["STTFUNCTION"] : "";
    $OVERRIDES["TTSFUNCTION_PLAYER"] = isset($GLOBALS["TTSFUNCTION_PLAYER"]) ? $GLOBALS["TTSFUNCTION_PLAYER"] : "";
    $OVERRIDES["TTSFUNCTION_PLAYER_VOICE"] = isset($GLOBALS["TTSFUNCTION_PLAYER_VOICE"]) ? $GLOBALS["TTSFUNCTION_PLAYER_VOICE"] : "";
    $OVERRIDES["TTSFUNCTION_PLAYER_VOICE_ID"] = isset($GLOBALS["TTSFUNCTION_PLAYER_VOICE_ID"]) ? $GLOBALS["TTSFUNCTION_PLAYER_VOICE_ID"] : "";
    $OVERRIDES["TTSFUNCTION_PLAYER_LANGUAGE"] = isset($GLOBALS["TTSFUNCTION_PLAYER_LANGUAGE"]) ? $GLOBALS["TTSFUNCTION_PLAYER_LANGUAGE"] : "";
    
    // Direct narrator requests must load the narrator runtime profile even if the
    // inbound request still carries the current NPC profile hash.
    $isNarratorRequest = in_array($gameRequest[0], [
        "narrator_inputtext",
        "narration",
        "narrator_welcome",
        "narrator_quest_comment"
    ], true);

    // Check if this is The Narrator (by MD5) or an explicit narrator request.
    $isNarratorProfile = $isNarratorRequest || ($activeProfile === md5('The Narrator'));
    
    // If this is The Narrator, use Narrator class instead of NpcMaster
    if ($isNarratorProfile) {
        require_once(__DIR__ . DIRECTORY_SEPARATOR . "lib" . DIRECTORY_SEPARATOR . "core" . DIRECTORY_SEPARATOR . "narrator.class.php");
        $narrator = new Narrator();
        $narratorData = $narrator->getNarratorData();
        
        // Load narrator settings into GLOBALS (includes NARRATOR_DIARY_ENABLED, etc.)
        $narrator->loadIntoGlobals();
        
        if ($narratorData && isset($narratorData["profile_id"])) {
            $profile = new CoreProfile();
            $currentProfileData = $profile->getById($narratorData["profile_id"]);
            
            $GLOBALS["DIALECTIC_CORE_CURRENT_PROFILE_DATA"] = $currentProfileData;
            
            $connector = new LLMConnector();
            $npcMaster = new NpcMaster(); // LLMRandomizer persists connector state through NPC metadata
            $connectorSlot = LLMRandomizer::getConnectorSlot($currentProfileData, $narratorData, $npcMaster);
            $connectorId = LLMRandomizer::getConnectorIdForSlot($currentProfileData, $connectorSlot);
            $currentConnectorData = $connector->getById($connectorId);
            
            $connector->setOldGlobals($currentConnectorData);
            $profile->setOldGlobals($currentProfileData);
            
            // Load narrator character data into GLOBALS (this sets PROMPT_HEAD and all character fields)
            $narrator->loadCharacterIntoGlobals();
            
            $GLOBALS["DIALECTIC_CORE_CURRENT_CONNECTOR_DATA"] = $currentConnectorData;
            
            error_log("[CORE SYSTEM] Using Narrator profile from core_narrator table, profile: {$currentProfileData["label"]}");
        } else {
            error_log("[CORE SYSTEM] Narrator profile not found, using defaults");
        }
    } else {
        // Regular NPC profile loading
        //$OVERRIDES["PROMPT_HEAD"]=$GLOBALS["PROMPT_HEAD"];
        
        $npcMaster=new NpcMaster();
        Logger::phaseStart("profile_npc_lookup", [
            "profile" => $activeProfile,
        ]);
        $currentNpcData=$npcMaster->getByMD5($activeProfile);
        Logger::phaseEnd("profile_npc_lookup", [
            "found" => $currentNpcData ? "yes" : "no",
            "npc" => $currentNpcData["npc_name"] ?? "",
        ], "info");
    
        if (!$currentNpcData) {
            error_log(__FILE__.". Using default profile because the requested active profile does not exist");

            // Recovery path: when a stale/unknown profile hash is passed, we still need
            // a valid profile + connector context or call_llm_internal() will terminate.
            $profile = new CoreProfile();

            $requestText = isset($gameRequest[3]) ? trim((string)$gameRequest[3]) : "";
            $fallbackNpcName = null;
            $fallbackNpcData = null;
            $currentProfileData = null;

            // Highest-confidence target extraction from player text payload.
            if ($requestText !== "" && preg_match('/\(\s*(?:(?:talking|whispering|shouting|speaking\s+privately)\s+to|speaking\s+loudly\s+to)\s+([^()]+?)(?:\s+from\s+far\s+away)?\s*\)/i', $requestText, $matches)) {
                $candidate = trim($matches[1]);
                if ($candidate !== "") {
                    $fallbackNpcName = $candidate;
                }
            }

            $isNarratorScopedRequest = in_array($gameRequest[0], ["narrator_inputtext", "narration", "narrator_welcome"], true)
                || stripos($requestText, '(Talking to The Narrator)') !== false
                || stripos($requestText, '(Whispering to The Narrator)') !== false
                || stripos($requestText, '(Speaking privately to The Narrator)') !== false
                || stripos($requestText, '(Shouting to The Narrator)') !== false
                || ($fallbackNpcName !== null && strcasecmp($fallbackNpcName, "The Narrator") === 0);

            if ($fallbackNpcName !== null && strcasecmp($fallbackNpcName, "The Narrator") !== 0) {
                $escapedNpcName = $db->escape($fallbackNpcName);
                $fallbackNpcData = $db->fetchOne("SELECT * FROM core_npc_master WHERE lower(npc_name)=lower('{$escapedNpcName}') LIMIT 1");
                if ($fallbackNpcData) {
                    $npcMaster->setOldGlobalsFromCurrentNpcData($fallbackNpcData, false);
                    $GLOBALS["DIALECTIC_CORE_CURRENT_NPC_DATA"] = $fallbackNpcData;
                    error_log("[CORE SYSTEM] Resolved unknown profile hash to NPC '{$fallbackNpcData["npc_name"]}' from request payload");
                } else {
                    error_log("[CORE SYSTEM] Could not resolve NPC '{$fallbackNpcName}' for unknown profile hash");
                }
            }

            // Prefer the resolved NPC profile when available.
            if ($fallbackNpcData) {
                if (empty($fallbackNpcData["profile_id"])) {
                    $defProfile = $profile->getDefaultNpc();
                    if ($defProfile) {
                        $fallbackNpcData["profile_id"] = (int)$defProfile["id"];
                        $npcMaster->updateByArray($fallbackNpcData);
                        error_log("[CORE SYSTEM] Resolved NPC '{$fallbackNpcData["npc_name"]}' had no profile, assigned default profile #{$defProfile["id"]}");
                    }
                }
                if (!empty($fallbackNpcData["profile_id"])) {
                    $currentProfileData = $profile->getById((int)$fallbackNpcData["profile_id"]);
                }
            }

            if (!$currentProfileData) {
                // NPC/default profile should win for normal requests; narrator only for narrator-scoped requests.
                $fallbackProfile = $isNarratorScopedRequest ? $profile->getDefaultNarrator() : $profile->getDefaultNpc();
                if (!$fallbackProfile) {
                    $fallbackProfile = $isNarratorScopedRequest ? $profile->getDefaultNpc() : $profile->getDefaultNarrator();
                }
                if (!$fallbackProfile) {
                    $fallbackProfile = $profile->getById(1);
                }

                if ($fallbackProfile) {
                    // Ensure we have the full profile row (id/label/connectors/metadata).
                    $currentProfileData = isset($fallbackProfile["id"])
                        ? $profile->getById((int)$fallbackProfile["id"])
                        : $fallbackProfile;
                }
            }

            if ($currentProfileData) {
                $GLOBALS["DIALECTIC_CORE_CURRENT_PROFILE_DATA"] = $currentProfileData;

                Logger::phaseStart("profile_connector_select", [
                    "npc" => $fallbackNpcData["npc_name"] ?? "",
                    "profile_label" => $currentProfileData["label"] ?? "",
                ]);
                $connector = new LLMConnector();
                // Respect current in-game mode when selecting active connector slot.
                $result = $GLOBALS["db"]->fetchOne("SELECT value FROM conf_opts WHERE id='dialectic_profile_model'");
                $connectorSlot = (isset($result['value']) && $result['value'] >= 1 && $result['value'] <= 4)
                    ? (int)$result['value']
                    : 1;
                $connectorId = LLMRandomizer::getConnectorIdForSlot($currentProfileData, $connectorSlot);
                $currentConnectorData = $connector->getById($connectorId);
                Logger::phaseEnd("profile_connector_select", [
                    "slot" => $connectorSlot,
                    "connector_id" => $connectorId,
                    "driver" => $currentConnectorData["driver"] ?? "",
                    "model" => $currentConnectorData["model"] ?? "",
                ], "info");

                if ($currentConnectorData) {
                    $connector->setOldGlobals($currentConnectorData);
                    $profile->setOldGlobals($currentProfileData);
                    $GLOBALS["DIALECTIC_CORE_CURRENT_CONNECTOR_DATA"] = $currentConnectorData;
                    if ($fallbackNpcData) {
                        $npcMaster->setOldGlobalsFromCurrentNpcData($fallbackNpcData, false);
                        error_log("[CORE SYSTEM] Loaded fallback NPC profile '{$currentProfileData["label"]}' for '{$fallbackNpcData["npc_name"]}'");
                    } else {
                        error_log("[CORE SYSTEM] Loaded fallback profile '{$currentProfileData["label"]}' for unknown profile hash");
                    }
                } else {
                    Logger::error("[CORE SYSTEM] Fallback profile loaded but no connector found for slot {$connectorSlot}");
                }
            } else {
                Logger::error("[CORE SYSTEM] No fallback profile available for unknown profile hash");
            }

        } else {
            error_log("[DIALECTIC CORE] USING CORE PROFILE {$currentNpcData["npc_name"]}")    ;
        

            // Profile has been migrated
            $npcMaster->setOldGlobalsFromCurrentNpcData($currentNpcData, false);
            $GLOBALS["DIALECTIC_CORE_CURRENT_NPC_DATA"] = $currentNpcData;

            $profile=new CoreProfile();

            // Fallback: assign default profile if NPC has none (orphaned by profile deletion)
            if (empty($currentNpcData["profile_id"])) {
                $defProfile = $profile->getDefaultNpc();
                if ($defProfile) {
                    $currentNpcData["profile_id"] = (int)$defProfile['id'];
                    $npcMaster->updateByArray($currentNpcData);
                    error_log("[CORE SYSTEM] NPC '{$currentNpcData["npc_name"]}' had no profile, assigned default profile #{$defProfile['id']}");
                }
            }

            $currentProfileData=$profile->getById($currentNpcData["profile_id"]);
        
            $GLOBALS["DIALECTIC_CORE_CURRENT_PROFILE_DATA"]=$currentProfileData;

            if (!empty($GLOBALS['AUTOFILL_CUSTOM_PROFILES'])) {
                require_once __DIR__ . DIRECTORY_SEPARATOR . "ui" . DIRECTORY_SEPARATOR . "cmd" . DIRECTORY_SEPARATOR . "ai_profile_generation_service.php";
                require_once __DIR__ . DIRECTORY_SEPARATOR . "lib" . DIRECTORY_SEPARATOR . "profile_autofill_async.php";
                if (aiProfileShouldAttemptAutofill($currentNpcData, $npcMaster)) {
                    $trigger = aiProfileGetAutofillTrigger($currentNpcData, $npcMaster);
                    $queuedProfileJob = dialecticSpawnProfileAutofillWorker([
                        'name' => $currentNpcData["npc_name"],
                        'event_limit' => $trigger,
                        'source' => 'auto',
                    ]);
                    if ($queuedProfileJob) {
                        aiProfileStampAutofillQueued($currentNpcData, $npcMaster);
                        Logger::info("[PROFILE_AUTOFILL] Queued async profile generation for {$currentNpcData["npc_name"]}; continuing dialogue turn");
                    } else {
                        Logger::warn("[PROFILE_AUTOFILL] Failed to queue async profile generation for {$currentNpcData["npc_name"]}; continuing dialogue turn");
                    }
                }
            }

            Logger::phaseStart("profile_connector_select", [
                "npc" => $currentNpcData["npc_name"] ?? "",
                "profile_label" => $currentProfileData["label"] ?? "",
            ]);
            $connector=new LLMConnector();
            
            // Use randomizer to determine which connector slot to use
            $connectorSlot = LLMRandomizer::getConnectorSlot($currentProfileData, $currentNpcData, $npcMaster);
            $connectorId = LLMRandomizer::getConnectorIdForSlot($currentProfileData, $connectorSlot);
            
            $currentConnectorData = $connector->getById($connectorId); 
            Logger::phaseEnd("profile_connector_select", [
                "slot" => $connectorSlot,
                "connector_id" => $connectorId,
                "driver" => $currentConnectorData["driver"] ?? "",
                "model" => $currentConnectorData["model"] ?? "",
            ], "info");
            
        
            $connector->setOldGlobals($currentConnectorData);
            $profile->setOldGlobals($currentProfileData);
            $npcMaster->setOldGlobalsFromCurrentNpcData($currentNpcData, false);
                $GLOBALS["DIALECTIC_CORE_CURRENT_NPC_DATA"] = $currentNpcData;

            $GLOBALS["DIALECTIC_CORE_CURRENT_CONNECTOR_DATA"]=$currentConnectorData;

            $debugLang = $GLOBALS["LLM_LANG"] ?? "unset";
            $debugOverrideTtsLang = $GLOBALS["PATCH_OVERRIDE_TTS_LANGUAGE"] ?? "unset";
            error_log("[CORE SYSTEM] Using new profile system , GLOBALS['LLM_LANG']:{$debugLang} profile: {$currentProfileData["label"]}");
            error_log("[CORE SYSTEM] GLOBALS['LLM_LANG']:{$debugLang} GLOBALS['PATCH_OVERRIDE_TTS_LANGUAGE']:{$debugOverrideTtsLang}");
        }
    }
    Logger::phaseEnd("profile_runtime_load", [
        "npc" => $GLOBALS["DIALECTIC_NAME"] ?? "",
        "profile_label" => $currentProfileData["label"] ?? "",
        "connector" => $currentConnectorData["driver"] ?? "",
        "model" => $currentConnectorData["model"] ?? "",
    ], "info");

//$GLOBALS["MINIME_T5"]=$OVERRIDES["MINIME_T5"];
    $GLOBALS["STTFUNCTION"]=$OVERRIDES["STTFUNCTION"];
    $GLOBALS["TTSFUNCTION_PLAYER"]=$OVERRIDES["TTSFUNCTION_PLAYER"];
    $GLOBALS["TTSFUNCTION_PLAYER_VOICE"]=$OVERRIDES["TTSFUNCTION_PLAYER_VOICE"];
    $GLOBALS["TTSFUNCTION_PLAYER_VOICE_ID"]=$OVERRIDES["TTSFUNCTION_PLAYER_VOICE_ID"];
    $GLOBALS["TTSFUNCTION_PLAYER_LANGUAGE"]=$OVERRIDES["TTSFUNCTION_PLAYER_LANGUAGE"];
    
    // $GLOBALS["PROMPT_HEAD"]=$OVERRIDES["PROMPT_HEAD"];
    
} else {
    $isNarratorRequestWithoutProfile = in_array($gameRequest[0], [
        "narrator_inputtext",
        "narration",
        "narrator_welcome",
        "narrator_quest_comment"
    ], true);

    if ($isNarratorRequestWithoutProfile) {
        require_once(__DIR__ . DIRECTORY_SEPARATOR . "lib" . DIRECTORY_SEPARATOR . "core" . DIRECTORY_SEPARATOR . "narrator.class.php");
        $narrator = new Narrator();
        $narratorData = $narrator->getNarratorData();

        if ($narratorData && isset($narratorData["profile_id"])) {
            $profile = new CoreProfile();
            $currentProfileData = $profile->getById($narratorData["profile_id"]);

            if ($currentProfileData) {
                $GLOBALS["DIALECTIC_CORE_CURRENT_PROFILE_DATA"] = $currentProfileData;

                $connector = new LLMConnector();
                $npcMaster = new NpcMaster(); // LLMRandomizer persists connector state through NPC metadata
                $connectorSlot = LLMRandomizer::getConnectorSlot($currentProfileData, $narratorData, $npcMaster);
                $connectorId = LLMRandomizer::getConnectorIdForSlot($currentProfileData, $connectorSlot);
                $currentConnectorData = $connector->getById($connectorId);

                if ($currentConnectorData) {
                    $connector->setOldGlobals($currentConnectorData);
                    $profile->setOldGlobals($currentProfileData);
                    $narrator->loadCharacterIntoGlobals();
                    $GLOBALS["DIALECTIC_CORE_CURRENT_CONNECTOR_DATA"] = $currentConnectorData;

                    error_log("[CORE SYSTEM] Using Narrator profile without explicit profile hash, profile: {$currentProfileData["label"]}");
                } else {
                    Logger::error("[CORE SYSTEM] Narrator request without profile hash could not resolve connector");
                    $GLOBALS["USING_DEFAULT_PROFILE"] = true;
                }
            } else {
                Logger::error("[CORE SYSTEM] Narrator request without profile hash could not resolve profile");
                $GLOBALS["USING_DEFAULT_PROFILE"] = true;
            }
        } else {
            Logger::error("[CORE SYSTEM] Narrator request without profile hash has no narrator profile configured");
            $GLOBALS["USING_DEFAULT_PROFILE"] = true;
        }
    } else {
        //error_log(__FILE__.". Using default profile because no active profile was resolved");
        $GLOBALS["USING_DEFAULT_PROFILE"]=true;
    }
}

if (isset($GLOBALS["DIALECTIC_CORE_CURRENT_NPC_DATA"]) && $GLOBALS["DIALECTIC_CORE_CURRENT_NPC_DATA"] && ($GLOBALS["DIALECTIC_NAME"] ?? "") !== "The Narrator") {
    $npcMasterForVoiceRefresh = isset($npcMaster) && ($npcMaster instanceof NpcMaster)
        ? $npcMaster
        : new NpcMaster();
    
    Logger::phaseStart("profile_voice_refresh_check", [
        "npc" => $GLOBALS["DIALECTIC_CORE_CURRENT_NPC_DATA"]["npc_name"] ?? "",
        "voiceid" => $GLOBALS["DIALECTIC_CORE_CURRENT_NPC_DATA"]["voiceid"] ?? "",
    ]);
    $refreshedNpcData = maybeQueueNpcVoiceRefresh($GLOBALS["DIALECTIC_CORE_CURRENT_NPC_DATA"], $npcMasterForVoiceRefresh);
    Logger::phaseEnd("profile_voice_refresh_check", [
        "npc" => $refreshedNpcData["npc_name"] ?? "",
        "voiceid" => $refreshedNpcData["voiceid"] ?? "",
    ], "info");
    if ($refreshedNpcData) {
                $GLOBALS["DIALECTIC_CORE_CURRENT_NPC_DATA"] = $refreshedNpcData;
    }
}



if (in_array($gameRequest[0],["inputtext","inputtext_s","narrator_inputtext","cheatmode"]) ) {
    // Empty request
    if (empty($gameRequest[3]) || trim($gameRequest[3])=="{$GLOBALS["PLAYER_NAME"]}:") {
        error_log("[MAIN] Empty request... aborting");
        terminate();
    } else {
        error_log("[MAIN] Request: {$gameRequest[3]}");
    }
    
}

dialecticRuntimeSetActiveProfile(md5($GLOBALS["DIALECTIC_NAME"]));


// End of profile selection

foreach ($gameRequest as $i => $ele) {
    $gameRequest[$i] = trim(preg_replace('/\s\s+/', ' ', preg_replace('/\'/m', "'", $ele)));
    //$gameRequest[$i] = trim(preg_replace('/\s\s+/', ' ', preg_replace('/\'/m', "''", $ele)));
    $gameRequest[$i]=strtr($gameRequest[$i],["#DIALECTIC_NPC1#"=>$GLOBALS["DIALECTIC_NAME"]]);
}



// $gameRequest = type of message|localts|gamets|data


if ($gameRequest[0]=="diary") {
    $resolvedDiaryConnector = function_exists('dialecticResolveDiaryConnectorName')
        ? dialecticResolveDiaryConnectorName()
        : ($GLOBALS["CONNECTORS_DIARY"] ?? '');
    if (!empty($resolvedDiaryConnector)) {
        $GLOBALS["CURRENT_CONNECTOR"] = $resolvedDiaryConnector;
    }
    
    // Add configurable cooldown for diary events to prevent spam (per NPC)
    $diaryCooldownPeriod = isset($GLOBALS["DIARY_COOLDOWN"]) ? intval($GLOBALS["DIARY_COOLDOWN"]) : 30;
    
    // Create a per-NPC cooldown key using the current NPC's name
    $npcName = preg_replace('/[^a-zA-Z0-9_]/', '_', $GLOBALS["DIALECTIC_NAME"]);
    $cooldownKey = "DIARY_LAST_TIMESTAMP_" . $npcName;
    
    // Fetch the last diary trigger timestamp for this specific NPC
    $diaryRecord = $GLOBALS["db"]->fetchAll("SELECT value FROM conf_opts WHERE id='" . $GLOBALS["db"]->escape($cooldownKey) . "'");
    
    // Check if the timestamp exists in the database
    if (!empty($diaryRecord)) {
        $lastTrigger = (int) $diaryRecord[0]['value'];
        $timeElapsed = time() - $lastTrigger;

        if ($timeElapsed < $diaryCooldownPeriod) {
            // Cooldown is still active for this NPC, exit
            Logger::info("DIARY is on cooldown for {$GLOBALS["DIALECTIC_NAME"]}. Try again in " . ($diaryCooldownPeriod - $timeElapsed) . " seconds.");
            terminate();
        }
    }

    // Update the timestamp in the database for this specific NPC
    $currentTimestamp = time();
    $GLOBALS["db"]->upsertRowOnConflict(
        "conf_opts",
        array(
            "id"    => $cooldownKey,
            "value" => $currentTimestamp
        ),
        'id'
    );
}


// Exit if only a event info log.
// Optional events

if (in_array($gameRequest[0],["info","chatme","chat","infoaction","death","itemfound",
    "travelcancel","infoplayer","status_msg","util_npcname","itempickup"])) {
    $gameRequest[3]=isset($gameRequest[3])?$gameRequest[3]:"";
    if ($gameRequest[0] == 'infoplayer') {
        // infoplayer format: level:{},name:"{}",race:"{}",gender:"{}"
        dialecticMaybeSyncPlayerNameFromGamePayload($gameRequest[3]);
    }

    logEvent($gameRequest);
    terminate();
}

// Check if the gameRequest matches specific types
if (in_array($gameRequest[0], ["playerinfo", "newgame"])) {
    dialecticMaybeSyncPlayerNameFromGamePayload($gameRequest[3] ?? '');
    logEvent($gameRequest);
    terminate();
}


// Fake entry to mark time passing when bored event
if (in_array($gameRequest[0],["bored"])) {
    //Loggar::trace(" bored event - exec trace"); // debug
    if ((($gameRequest[2] ?? 0)-GetLastSpeechTs()) > 416667) { // 1/0.0000024 = 416667 
        $localGameRequest=$gameRequest;
        $localGameRequest[0]="infoaction";
            $localGameRequest[3].=". (Time passes without anyone in the group talking) ";
        logEvent($localGameRequest);
    }
    
    if (!empty($GLOBALS["NARRATOR_BORED_EVENT_ACTIVE"])) {
        Logger::info("[NARRATOR_BORED] Using narrator bored flow");
    } elseif ((isset($GLOBALS["BORED_EVENT_SERVERSIDE"])&&($GLOBALS["BORED_EVENT_SERVERSIDE"]))) {
        $boredPayload = json_decode((string)($gameRequest[3] ?? ''), true);
        $boredPayload = is_array($boredPayload) ? $boredPayload : [];
        $boredSeedActor = trim((string)($boredPayload['actor_name'] ?? $boredPayload['speaker'] ?? ''));
        $boredEligibleActors = is_array($boredPayload['eligible_actors'] ?? null)
            ? array_values($boredPayload['eligible_actors'])
            : [];
        Logger::info(
            "Redirecting bored event to rolemaster with seed actor '{$boredSeedActor}' and "
            . count($boredEligibleActors) . " eligible actor(s)"
        );
        $phpCli = PHP_BINDIR . DIRECTORY_SEPARATOR . "php";
        if (!is_file($phpCli) && !is_file($phpCli . ".exe")) {
            $binaryName = strtolower((string)pathinfo(PHP_BINARY, PATHINFO_FILENAME));
            $phpCli = (strpos($binaryName, "php") === 0 && is_file(PHP_BINARY))
                ? PHP_BINARY
                : "php";
        }
        $managerPath = __DIR__ . DIRECTORY_SEPARATOR . "service" . DIRECTORY_SEPARATOR . "manager.php";
        $command = escapeshellarg($phpCli)
            . " " . escapeshellarg($managerPath)
            . " rolemaster instruction " . escapeshellarg("")
            . " bored " . escapeshellarg($boredSeedActor)
            . " " . escapeshellarg(json_encode($boredEligibleActors, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        exec($command, $output, $returnCode);
        if ($returnCode !== 0) {
            Logger::warn("Failed to start bored rolemaster request (exit code {$returnCode})");
        }
        terminate();

    }
}

// Combat bark event - log as infoaction and apply cooldown
if (in_array($gameRequest[0],["combatbark"])) {
    // Add configurable cooldown for combat barks to prevent spam (global across all NPCs)
    $combatBarkCooldownPeriod = isset($GLOBALS["COMBAT_BARK_COOLDOWN"]) ? intval($GLOBALS["COMBAT_BARK_COOLDOWN"]) : 30;
    
    // Use a global cooldown key (shared across all NPCs)
    $cooldownKey = "COMBAT_BARK_LAST_TIMESTAMP";
    
    // Fetch the last combat bark trigger timestamp
    $combatBarkRecord = $GLOBALS["db"]->fetchAll("SELECT value FROM conf_opts WHERE id='" . $GLOBALS["db"]->escape($cooldownKey) . "'");
    
    // Check if the timestamp exists in the database
    if (!empty($combatBarkRecord)) {
        $lastTrigger = (int) $combatBarkRecord[0]['value'];
        $timeElapsed = time() - $lastTrigger;

        if ($timeElapsed < $combatBarkCooldownPeriod) {
            // Cooldown is still active, exit
            Logger::info("COMBAT_BARK is on cooldown. Try again in " . ($combatBarkCooldownPeriod - $timeElapsed) . " seconds.");
            terminate();
        }
    }
    
    // Update the timestamp in the database to the current time
    $currentTimestamp = time();
    $GLOBALS["db"]->upsertRowOnConflict(
        "conf_opts",
        array(
            "id"    => $cooldownKey,
            "value" => $currentTimestamp
        ),
        'id'
    );
    
    $localGameRequest=$gameRequest;
    $localGameRequest[0]="infoaction";
    $localGameRequest[3].=" ({$GLOBALS["DIALECTIC_NAME"]} shouts during combat)";
    logEvent($localGameRequest);
}


// Only allow actions for explicit action-capable requests. Normal NPC chat is
// explicit player input, so it must opt in before prompt/includes build the
// JSON action schema and available action list.
$rechatAllowsActionsForPrompt = filter_var($GLOBALS["RECHAT_ALLOW_ACTIONS"] ?? false, FILTER_VALIDATE_BOOLEAN);
$actionCapableRequestTypes = ["inputtext","inputtext_s","narrator_inputtext","instruction","welcome","cheatmode"];
if ($rechatAllowsActionsForPrompt) {
    $actionCapableRequestTypes[] = "rechat";
    $actionCapableRequestTypes[] = "narration";
}
if (in_array($gameRequest[0], $actionCapableRequestTypes, true)) {
    $FUNCTIONS_ARE_ENABLED=true;
    $GLOBALS["FUNCTIONS_ARE_ENABLED"]=true;
} else {
    $FUNCTIONS_ARE_ENABLED=false;
    $GLOBALS["FUNCTIONS_ARE_ENABLED"]=false;
}

// Direct narrator dialogue is an explicit action-capable request path.
// Keep DB-backed narrator actions enabled so narrator_inputtext exposes
// the current Fallout action catalog instead of speech-only JSON.
if ($gameRequest[0] === "narrator_inputtext") {
    $FUNCTIONS_ARE_ENABLED=true;
}

// Force actions when instruction issued
if (in_array($gameRequest[0],["instruction"])) {
    $FUNCTIONS_ARE_ENABLED=true;
    // Remove any "SpeakerName:" prefix to prevent player/NPC attribution in instructions
    $gameRequest[3] = preg_replace('/^[^:]+:\s*/', '', $gameRequest[3]);
}

if (in_array($gameRequest[0],["suggestion"])) {
    $FUNCTIONS_ARE_ENABLED=false;
    // Remove any "SpeakerName:" prefix to prevent player/NPC attribution in suggestions
    $gameRequest[3] = preg_replace('/^[^:]+:\s*/', '', $gameRequest[3]);
}

Logger::phaseStart("party_context_prepare", [
    "type" => $gameRequest[0] ?? "",
    "speaker" => $GLOBALS["DIALECTIC_NAME"] ?? "",
]);
$GLOBALS["CACHE_PARTY"]=DataGetCurrentPartyConf();
$currentParty=json_decode($GLOBALS["CACHE_PARTY"],true);
if (is_array($currentParty)) {
    if (in_array($GLOBALS["DIALECTIC_NAME"],array_keys($currentParty))) {
        $GLOBALS["IS_NPC"]=false;
    } else
        $GLOBALS["IS_NPC"]=true;
} else
    $GLOBALS["IS_NPC"]=false;
Logger::phaseEnd("party_context_prepare", [
    "type" => $gameRequest[0] ?? "",
    "speaker" => $GLOBALS["DIALECTIC_NAME"] ?? "",
    "party_count" => is_array($currentParty) ? count($currentParty) : 0,
    "is_npc" => !empty($GLOBALS["IS_NPC"]) ? 1 : 0,
], "info");

// RECHAT PRE MANAGMENT



// Non-LLM request handling.
// We need to include this file asap. Most events are handled there.
// Log events are handled there to, which are the most called requests, and we want to exit as fast as possible for them.
// Most called event-only requests use gamedata.php; main.php keeps request and speech delivery handling fast.

Logger::phaseStart("processor_comm", [
    "type" => $gameRequest[0] ?? "",
]);
require(__DIR__.DIRECTORY_SEPARATOR."processor".DIRECTORY_SEPARATOR."comm.php");
Logger::phaseEnd("processor_comm", [
    "type" => $gameRequest[0] ?? "",
    "must_end" => !empty($MUST_END) ? 1 : 0,
], "info");


if (in_array($gameRequest[0],["rechat","narration"]) ) {
    if (function_exists('isPrivateConversationExecutionMode') && isPrivateConversationExecutionMode()) {
        Logger::info("[RECHAT_SELECT] Terminating " . ($gameRequest[0] ?? "rechat") .
            " because " . ($GLOBALS["DIALECTIC_EXECUTION_MODE"] ?? "private") . " mode is private");
        terminate();
    }
    Logger::phaseStart("rechat_pre_management", [
        "type" => $gameRequest[0] ?? "",
        "speaker" => $GLOBALS["DIALECTIC_NAME"] ?? "",
    ]);
    
    //RECHAT. Must choose if we continue conversation or no.
    // Note: narration is part of rechat system (random narrator interjections count as rechat rounds)

    if ($gameRequest[0] === "rechat") {
        $rechatPayload = dialecticParseServerSideRechatPayload($gameRequest[3] ?? "");
        $GLOBALS["RECHAT_PREVIOUS_SPEAKER"] = trim((string)($rechatPayload["speaker"] ?? ""));
        if (function_exists('dialecticRechatActorBlockReason')) {
            $rechatSpeakerName = trim((string)($rechatPayload["speaker"] ?? ""));
            $rechatSpeakerData = null;
            if ($rechatSpeakerName !== "") {
                try {
                    $rechatSpeakerData = (new NpcMaster())->getByName($rechatSpeakerName);
                } catch (Throwable $e) {
                    $rechatSpeakerData = null;
                }
            }
            $speakerBlockReason = dialecticRechatActorBlockReason($rechatSpeakerName, $rechatSpeakerData);
            if ($speakerBlockReason !== "") {
                Logger::info("[RECHAT_SELECT] Terminating rechat for {$rechatSpeakerName}: {$speakerBlockReason}");
                terminate();
            }
        }
        Logger::phaseStart("rechat_target_selection", [
            "speaker" => $rechatPayload["speaker"] ?? "",
            "listener_hint" => $rechatPayload["listener_hint"] ?? "",
            "target_hint" => $rechatPayload["rechat_target_hint"] ?? "",
            "audience_count" => is_array($rechatPayload["audience"] ?? null) ? count($rechatPayload["audience"]) : 0,
        ]);
        $resolvedRechatTarget = dialecticResolveServerSideRechatTarget($rechatPayload);
        Logger::phaseEnd("rechat_target_selection", [
            "speaker" => $resolvedRechatTarget["speaker"] ?? "",
            "selected" => $resolvedRechatTarget["selected"] ?? "",
            "mode" => $resolvedRechatTarget["mode"] ?? "",
            "candidate_count" => is_array($resolvedRechatTarget["candidates"] ?? null) ? count($resolvedRechatTarget["candidates"]) : 0,
        ], "info");
        $GLOBALS["RECHAT_REQUEST_PAYLOAD"] = $rechatPayload;
        $GLOBALS["RECHAT_RESOLVED_TARGET"] = $resolvedRechatTarget;

        if (empty($resolvedRechatTarget["selected"])) {
            Logger::info("[RECHAT_SELECT] No valid responder selected; terminating rechat");
            terminate();
        }

        Logger::phaseStart("rechat_profile_switch", [
            "selected" => $resolvedRechatTarget["selected"],
        ]);
        $dialecticRechatProfileSwitched = dialecticSwitchActiveNpcProfile($resolvedRechatTarget["selected"]);
        Logger::phaseEnd("rechat_profile_switch", [
            "selected" => $resolvedRechatTarget["selected"],
            "status" => $dialecticRechatProfileSwitched ? "ok" : "failed",
            "connector" => $GLOBALS["TTSFUNCTION"] ?? "",
            "llm" => $GLOBALS["CONNECTOR"] ?? ($GLOBALS["CURRENT_CONNECTOR"] ?? ""),
        ], $dialecticRechatProfileSwitched ? "info" : "warn");
        if (!$dialecticRechatProfileSwitched) {
            Logger::warn("[RECHAT_SELECT] Failed to switch active NPC profile to " . $resolvedRechatTarget["selected"]);
            terminate();
        }
    }

    $rechatHistory=DataRechatHistory();
    $currentSpeakerName = trim((string)($GLOBALS["DIALECTIC_NAME"] ?? ""));
    
    // Pre-calculated rechat budget with final-round closing prompt

    $sessionKey = isset($GLOBALS["RECHAT_RESOLVED_TARGET"])
        ? dialecticBuildServerSideRechatSessionKey($GLOBALS["RECHAT_RESOLVED_TARGET"])
        : md5($GLOBALS["DIALECTIC_NAME"] . "_" . floor(time() / 120));
    $budgetFile = sys_get_temp_dir() . "/dialectic_rechat_" . $sessionKey . ".json";
    $budgetStateWindow = 120;
    $budgetState = null;

    if (file_exists($budgetFile)) {
        $loadedBudgetState = json_decode(file_get_contents($budgetFile), true);
        if (is_array($loadedBudgetState) &&
            isset($loadedBudgetState["budget"]) &&
            isset($loadedBudgetState["used"]) &&
            isset($loadedBudgetState["ts"]) &&
            (time() - intval($loadedBudgetState["ts"]) <= $budgetStateWindow)) {
            $budgetState = $loadedBudgetState;
        } else {
            @unlink($budgetFile);
        }
    }

    if (!is_array($budgetState)) {
        $budget = 0;
        for ($i = 0; $i < intval($GLOBALS["RECHAT_H"]); $i++) {
            if (rand(1, 100) <= intval($GLOBALS["RECHAT_P"])) {
                $budget++;
            } else {
                break; // probability failed - chain ends here
            }
        }
        if ($budget === 0) {
            Logger::info("Rechat: pre-roll determined 0 rounds - terminating");
            terminate();
        }
        $budgetState = ['budget' => $budget, 'used' => 0, 'ts' => time()];
    }

    $budget = intval($budgetState['budget'] ?? 0);
    $currentRound = intval($budgetState['used'] ?? 0);
    if ($currentRound >= $budget) {
        Logger::info("Rechat: pre-roll budget exhausted ({$currentRound}/{$budget}) - terminating");
        Logger::info("[RECHAT_COUNT] exhausted speaker={$GLOBALS["DIALECTIC_NAME"]} chain_id=" .
            (isset($GLOBALS["RECHAT_RESOLVED_TARGET"]["chain_id"]) ? $GLOBALS["RECHAT_RESOLVED_TARGET"]["chain_id"] : "") .
            " used={$currentRound} budget={$budget}");
        @unlink($budgetFile);
        terminate();
    }

    $budgetState['used'] = $currentRound + 1;
    $budgetState['ts'] = time();
    file_put_contents($budgetFile, json_encode($budgetState));
    Logger::info("[RECHAT_COUNT] speaker={$GLOBALS["DIALECTIC_NAME"]} chain_id=" .
        (isset($GLOBALS["RECHAT_RESOLVED_TARGET"]["chain_id"]) ? $GLOBALS["RECHAT_RESOLVED_TARGET"]["chain_id"] : "") .
        " used={$budgetState['used']} budget={$budget}");

    // All gates passed - detect final round and inject closing prompt
    if ($currentRound + 1 >= $budget) {
        $GLOBALS["PROMPT_HEAD"] .= "\n[This is your final response in this exchange. Conclude your current thought naturally - you are not leaving, just finishing what you were saying for now.]";
        Logger::info("Rechat: final round ({$currentRound}/{$budget}) - closing prompt injected");
    }


    if ($currentRound > 1) {
        // Lets make rechat wait a bit, so events while NPCs are speaking get into context// disabled if using new rechat fire event
        SemaphoreManager::release("MAIN");
        // Check if this conflicts with smart rechat
        // Is this doing something?
        $semaphore_timeout = $GLOBALS["SEMAPHORES_TIMEOUT"] ?? 300;
        if (!SemaphoreWait("MAIN", $semaphore_timeout, 1007, function() use ($db, $gameRequest) {
            //$user_input_after=$db->fetchAll("select count(*) as N from eventlog where type='user_input' and ts>$gameRequest[1]"); // 72 ms 
            $user_input_after=$db->fetchAll("SELECT rowid as N FROM eventlog WHERE type='user_input' AND ts>{$gameRequest[1]} ORDER BY rowid DESC LIMIT 1 "); // faster, 1.5 ms
            if (isset($user_input_after[0])) {
                if (isset($user_input_after[0]["N"]))
                    if (intval($user_input_after[0]["N"])>0) {
                        Logger::warn("[main] rechat event - generation stopped because user_input. " .__FILE__ . " " . __LINE__); // debug
                        terminate();
                    }
            }
            return true;
        })) {
            Logger::warn("[main] rechat event - semaphore wait failed in " .__FILE__ . " " . __LINE__);
            terminate();
        }
    }

    // RANDOM NARRATION - Narrator visual scene descriptions
    // Trigger after any NPC response (after first NPC responds to player)
    // AND only on "rechat" events (not on events already converted to "narration")
    // AND only if The Narrator wasn't the last speaker (prevent consecutive narrations)
    if (!empty($GLOBALS["RANDOM_NARATION"]) && $GLOBALS["RANDOM_NARATION"] && $gameRequest[0] === "rechat" && sizeof($rechatHistory) >= 1) {
        // Check if the last event was a narration event (if so, skip to prevent consecutive narrations)
        $lastEvent = $db->fetchOne("SELECT type FROM eventlog WHERE type IN ('rechat', 'narration') ORDER BY gamets DESC, ts DESC LIMIT 1");
        $wasLastNarration = ($lastEvent && $lastEvent['type'] === 'narration');
        
        // Check cooldown - ensure at least N non-narration events occurred since last narration
        $cooldownRounds = isset($GLOBALS["RANDOM_NARRATION_COOLDOWN"]) ? intval($GLOBALS["RANDOM_NARRATION_COOLDOWN"]) : 2;
        $eventsSinceNarration = $db->fetchOne("
            SELECT COUNT(*) as count 
            FROM eventlog 
            WHERE type IN ('rechat', 'inputtext', 'inputtext_s') 
            AND gamets > (
                SELECT COALESCE(MAX(gamets), 0) 
                FROM eventlog 
                WHERE type = 'narration'
            )
        ");
        
        $eventCount = $eventsSinceNarration ? intval($eventsSinceNarration['count']) : 999;
        
        // Skip if cooldown hasn't passed
        if ($eventCount < $cooldownRounds) {
            Logger::info("[RANDOM_NARRATION] Skipped - Cooldown active (events since last: {$eventCount}, required: {$cooldownRounds})");
        } else if ($wasLastNarration) {
            Logger::info("[RANDOM_NARRATION] Skipped - Last event was narration, preventing consecutive narrations");
        } else {
            $randomChance = rand(1, 100);
            $narrationChance = isset($GLOBALS["RANDOM_NARATION_CHANCE"]) ? intval($GLOBALS["RANDOM_NARATION_CHANCE"]) : 15;
            
            if ($randomChance <= $narrationChance) {
                Logger::info("[RANDOM_NARRATION] Triggered (chance: $randomChance <= $narrationChance)");
            
            // Switch to The Narrator profile temporarily
            require_once(__DIR__ . DIRECTORY_SEPARATOR . "lib" . DIRECTORY_SEPARATOR . "core" . DIRECTORY_SEPARATOR . "narrator.class.php");
            $narrator = new Narrator();
            $narratorData = $narrator->getNarratorData();
            
            if ($narratorData && isset($narratorData["profile_id"])) {
                // Store current profile data
                $originalDialecticName = $GLOBALS["DIALECTIC_NAME"];
                
                // Load Narrator profile - set connector and profile first, character data last
                $profile = new CoreProfile();
                $currentProfileData = $profile->getById($narratorData["profile_id"]);
                
                $GLOBALS["DIALECTIC_CORE_CURRENT_PROFILE_DATA"] = $currentProfileData;
                
                $connector = new LLMConnector();
                $npcMaster = new NpcMaster(); // LLMRandomizer persists connector state through NPC metadata
                $connectorSlot = LLMRandomizer::getConnectorSlot($currentProfileData, $narratorData, $npcMaster);
                $connectorId = LLMRandomizer::getConnectorIdForSlot($currentProfileData, $connectorSlot);
                $currentConnectorData = $connector->getById($connectorId);
                
                $connector->setOldGlobals($currentConnectorData);
                $profile->setOldGlobals($currentProfileData);
                
                // Load narrator character data into GLOBALS
                $narrator->loadCharacterIntoGlobals();
                
                $GLOBALS["DIALECTIC_CORE_CURRENT_CONNECTOR_DATA"] = $currentConnectorData;
                
                // Load random narration prompt from database with fallback
                $narrationPrompt = null;
                try {
                    $promptData = $db->fetchOne("SELECT custom_prompt, default_prompt FROM prompts WHERE prompt_key = 'random_narration_prompt'");
                    if ($promptData) {
                        // Use custom_prompt if set, otherwise use default_prompt
                        $narrationPrompt = (!empty($promptData['custom_prompt'])) ? $promptData['custom_prompt'] : $promptData['default_prompt'];
                        Logger::info("[RANDOM_NARRATION] Loaded prompt from database (custom: " . (!empty($promptData['custom_prompt']) ? 'yes' : 'no') . ")");
                    }
                } catch (Exception $e) {
                    Logger::warn("[RANDOM_NARRATION] Failed to load prompt from database, using hardcoded fallback: " . $e->getMessage());
                }
                
                // Hardcoded fallback if database query failed or returned no results
                if (!$narrationPrompt) {
                    $narrationPrompt = 'Describe the current scene visually using ONLY details from the provided context. Focus on the characters present - their appearance, expressions, body language, and what they\'re wearing. Include environmental details like lighting and atmosphere. Keep it grounded and concise (2-3 sentences). Do not invent new information, advance the plot, or include dialogue.';
                    Logger::info("[RANDOM_NARRATION] Using hardcoded fallback prompt");
                }
                
                // Mark this as a narration event (not a regular rechat)
                $gameRequest[0] = "narration";
                
                // Send event type header IMMEDIATELY before any output
                // This must be done early so C++ plugin knows this is narration
                header("X-Event-Type: narration");
                Logger::info("[RANDOM_NARRATION] Sent X-Event-Type: narration header");
                
                // Store narration prompt for later injection (after prompts.php is loaded)
                $GLOBALS["RANDOM_NARRATION_PROMPT"] = $narrationPrompt;
                
                Logger::info("[RANDOM_NARRATION] Executing as The Narrator with narration request");
                
                // Process will continue with Narrator profile loaded
                // After response, it will send to game as normal narrator dialogue
            } else {
                Logger::warn("[RANDOM_NARRATION] Skipped - Narrator profile not found");
            }
            } else {
                Logger::trace("[RANDOM_NARRATION] Not triggered (chance: $randomChance > $narrationChance)");
            }
        }
    }

    $visibleChatStateSql = dialecticBuildChatDeliveryStateSql('delivery_state');
$sqlfilter=" and (type in ('prechat','inputtext','logaction','infoaction','death','itemfound','itemtransfer','backgroundchat') or (type='chat' and {$visibleChatStateSql} and data like '(Context%') )";  // Use prechat
    // chat entries starting by "(Context%" are standard fallout dialogue

    if (!filter_var($GLOBALS["RECHAT_ALLOW_ACTIONS"] ?? false, FILTER_VALIDATE_BOOLEAN)) {
        $FUNCTIONS_ARE_ENABLED=false;       // Enabling this can be funny => CHAOS MODE
        $GLOBALS["FUNCTIONS_ARE_ENABLED"]=false;
    }
    Logger::phaseEnd("rechat_pre_management", [
        "type" => $gameRequest[0] ?? "",
        "speaker" => $GLOBALS["DIALECTIC_NAME"] ?? "",
        "resolved_target" => $GLOBALS["RECHAT_RESOLVED_TARGET"]["selected"] ?? "",
    ], "info");

} else
    $sqlfilter=" and type<>'prechat' "; // Will dismiss prechat entries by default. prechat are LLM responses still not displayed in-game

Logger::phaseStart("post_rechat_runtime_prepare", [
    "type" => $gameRequest[0] ?? "",
    "speaker" => $GLOBALS["DIALECTIC_NAME"] ?? "",
]);

if (
    in_array($gameRequest[0], ["inputtext", "inputtext_s", "vision"], true) &&
    empty($GLOBALS["DIALECTIC_CORE_CURRENT_CONNECTOR_DATA"])
) {
    $jsonSpeaker = function_exists('dialectic_extract_conversation_target')
        ? dialectic_extract_conversation_target((string)($gameRequest[3] ?? ""))
        : "";

    if ($jsonSpeaker !== "" && strcasecmp($jsonSpeaker, "The Narrator") !== 0) {
        if (!dialecticSwitchActiveNpcProfile($jsonSpeaker)) {
            Logger::warn("[PROFILE_SELECT] Could not bind active NPC profile for {$jsonSpeaker}; falling back to default NPC profile");
        }
    }

    if (empty($GLOBALS["DIALECTIC_CORE_CURRENT_CONNECTOR_DATA"])) {
        $profile = new CoreProfile();
        $currentProfileData = $profile->getDefaultNpc();
        if ($currentProfileData) {
            $npcMaster = new NpcMaster();
            $npcData = [];
            if ($jsonSpeaker !== "" && strcasecmp($jsonSpeaker, "The Narrator") !== 0) {
                $maybeNpcData = $npcMaster->getByName($jsonSpeaker);
                if (is_array($maybeNpcData)) {
                    $npcData = $maybeNpcData;
                }
            }

            $connectorSlot = !empty($npcData) ? LLMRandomizer::getConnectorSlot($currentProfileData, $npcData, $npcMaster) : 1;
            $connectorId = intval(LLMRandomizer::getConnectorIdForSlot($currentProfileData, $connectorSlot));
            $connector = new LLMConnector();
            $currentConnectorData = $connectorId > 0 ? $connector->getById($connectorId) : null;
            if ($currentConnectorData) {
                $connector->setOldGlobals($currentConnectorData);
                $profile->setOldGlobals($currentProfileData);
                if (!empty($npcData)) {
                    $npcMaster->setOldGlobalsFromCurrentNpcData($npcData, false);
                } elseif ($jsonSpeaker !== "") {
                    $GLOBALS["DIALECTIC_NAME"] = $jsonSpeaker;
                }
                $GLOBALS["DIALECTIC_CORE_CURRENT_PROFILE_DATA"] = $currentProfileData;
                $GLOBALS["DIALECTIC_CORE_CURRENT_CONNECTOR_DATA"] = $currentConnectorData;
                $GLOBALS["CURRENT_CONNECTOR"] = $currentConnectorData["driver"] ?? "";
            } else {
                Logger::error("[PROFILE_SELECT] Default NPC profile has no usable LLM connector");
            }
        } else {
            Logger::error("[PROFILE_SELECT] No default NPC profile available");
        }
    }
}

if ($gameRequest[0] === "funcret") {
    $funcretActor = function_exists('dialectic_extract_funcret_actor')
        ? dialectic_extract_funcret_actor((string)($gameRequest[3] ?? ""))
        : "";

    if ($funcretActor !== "" && strcasecmp($funcretActor, "The Narrator") !== 0) {
        if (!dialecticSwitchActiveNpcProfile($funcretActor)) {
            Logger::warn("[FUNCRET_SELECT] Could not bind active NPC profile for {$funcretActor}; follow-up will use current profile");
        } else {
            Logger::info("[FUNCRET_SELECT] Bound funcret follow-up to NPC profile {$funcretActor}");
        }
    }
}



// Handle narrator_welcome events after the request processor converts init to narrator_welcome.
if ($gameRequest[0] == "narrator_welcome") {
    // Load narrator profile with full connector configuration
    require_once(__DIR__ . DIRECTORY_SEPARATOR . "lib" . DIRECTORY_SEPARATOR . "core" . DIRECTORY_SEPARATOR . "narrator.class.php");
    $narrator = new Narrator();
    $narratorData = $narrator->getNarratorData();
    
    if ($narratorData && isset($narratorData["profile_id"])) {
        // Load Narrator profile - set connector and profile first, character data last
        $profile = new CoreProfile();
        $currentProfileData = $profile->getById($narratorData["profile_id"]);
        
        if (!$currentProfileData) {
            Logger::error("[NARRATOR_WELCOME] Profile ID {$narratorData['profile_id']} not found in core_profiles table");
            Logger::error("[NARRATOR_WELCOME] Please ensure The Narrator has a valid profile assigned");
            terminate();
        }
        
        $GLOBALS["DIALECTIC_CORE_CURRENT_PROFILE_DATA"] = $currentProfileData;
        
        $connector = new LLMConnector();
        
        // Get global connector slot (respects in-game mode)
        $db = $GLOBALS['db'];
        $result = $db->fetchOne("SELECT value FROM conf_opts WHERE id='dialectic_profile_model'");
        $connectorSlot = (isset($result['value']) && $result['value'] >= 1 && $result['value'] <= 4) 
            ? (int)$result['value'] 
            : 1;
        
        $connectorId = LLMRandomizer::getConnectorIdForSlot($currentProfileData, $connectorSlot);
        
        $slotName = LLMRandomizer::getSlotName($connectorSlot);
        
        if (!$connectorId) {
            Logger::error("[NARRATOR_WELCOME] No connector assigned to {$slotName} slot (slot {$connectorSlot}) for profile '{$currentProfileData['label']}'");
            Logger::error("[NARRATOR_WELCOME] Please configure connectors for The Narrator's profile:");
            Logger::error("[NARRATOR_WELCOME]   - Go to Profile Management > Edit The Narrator's profile");
            Logger::error("[NARRATOR_WELCOME]   - Assign connectors to: Standard (slot 1), Fast (slot 2), Powerful (slot 3), Experimental (slot 4)");
            Logger::error("[NARRATOR_WELCOME]   - The system uses the ingame mode setting to pick which connector to use");
            terminate();
        }
        
        $currentConnectorData = $connector->getById($connectorId);
        
        if (!$currentConnectorData) {
            Logger::error("[NARRATOR_WELCOME] Connector ID {$connectorId} not found in core_connectors table");
            terminate();
        }
        
        $connector->setOldGlobals($currentConnectorData);
        $profile->setOldGlobals($currentProfileData);
        
        // Load narrator character data into GLOBALS
        $narrator->loadCharacterIntoGlobals();
        
        $GLOBALS["DIALECTIC_CORE_CURRENT_CONNECTOR_DATA"] = $currentConnectorData;
        
        // Keep connector globals populated for shared connector helpers.
        $GLOBALS["CURRENT_CONNECTOR"] = $currentConnectorData['driver'];
        
        // Load welcome prompt from prompts table with hardcoded fallback
        $welcomePrompt = null;
        try {
            $promptData = $db->fetchOne("SELECT custom_prompt, default_prompt FROM prompts WHERE prompt_key = 'narrator_welcome_prompt'");
            if ($promptData) {
                $welcomePrompt = (!empty($promptData['custom_prompt'])) 
                    ? $promptData['custom_prompt'] 
                    : $promptData['default_prompt'];
            }
        } catch (Exception $e) {
            Logger::warn("[NARRATOR_WELCOME] Failed to load prompt from database: " . $e->getMessage());
        }
        
        // Hardcoded fallback if database query failed
        if (!$welcomePrompt) {
            $welcomePrompt = "Give a brief (2-3 sentence) recap of recent events and adventures. Welcome the player back to their journey.";
        }
        
        $GLOBALS["NARRATOR_WELCOME_PROMPT"] = $welcomePrompt;
    } else {
        Logger::error("[NARRATOR_WELCOME] Narrator profile_id not found in core_narrator table");
        Logger::error("[NARRATOR_WELCOME] Please configure The Narrator in Narrator Management");
        terminate();
    }
}

// Handle narrator_quest_comment events after the request processor converts quest to narrator_quest_comment.
if ($gameRequest[0] == "narrator_quest_comment") {
    // Load narrator profile with full connector configuration
    require_once(__DIR__ . DIRECTORY_SEPARATOR . "lib" . DIRECTORY_SEPARATOR . "core" . DIRECTORY_SEPARATOR . "narrator.class.php");
    $narrator = new Narrator();
    $narratorData = $narrator->getNarratorData();
    
    if ($narratorData && isset($narratorData["profile_id"])) {
        // Load Narrator profile - set connector and profile first, character data last
        $profile = new CoreProfile();
        $currentProfileData = $profile->getById($narratorData["profile_id"]);
        
        if (!$currentProfileData) {
            Logger::error("[NARRATOR_QUEST_COMMENT] Profile ID {$narratorData['profile_id']} not found in core_profiles table");
            Logger::error("[NARRATOR_QUEST_COMMENT] Please ensure The Narrator has a valid profile assigned");
            terminate();
        }
        
        $GLOBALS["DIALECTIC_CORE_CURRENT_PROFILE_DATA"] = $currentProfileData;
        
        $connector = new LLMConnector();
        
        // Get global connector slot (respects in-game mode)
        $db = $GLOBALS['db'];
        $result = $db->fetchOne("SELECT value FROM conf_opts WHERE id='dialectic_profile_model'");
        $connectorSlot = (isset($result['value']) && $result['value'] >= 1 && $result['value'] <= 4) 
            ? (int)$result['value'] 
            : 1;
        
        $connectorId = LLMRandomizer::getConnectorIdForSlot($currentProfileData, $connectorSlot);
        
        $slotName = LLMRandomizer::getSlotName($connectorSlot);
        
        if (!$connectorId) {
            Logger::error("[NARRATOR_QUEST_COMMENT] No connector assigned to {$slotName} slot (slot {$connectorSlot}) for profile '{$currentProfileData['label']}'");
            Logger::error("[NARRATOR_QUEST_COMMENT] Please configure connectors for The Narrator's profile:");
            Logger::error("[NARRATOR_QUEST_COMMENT]   - Go to Profile Management > Edit The Narrator's profile");
            Logger::error("[NARRATOR_QUEST_COMMENT]   - Assign connectors to: Standard (slot 1), Fast (slot 2), Powerful (slot 3), Experimental (slot 4)");
            Logger::error("[NARRATOR_QUEST_COMMENT]   - The system uses the ingame mode setting to pick which connector to use");
            terminate();
        }
        
        $currentConnectorData = $connector->getById($connectorId);
        
        if (!$currentConnectorData) {
            Logger::error("[NARRATOR_QUEST_COMMENT] Connector ID {$connectorId} not found in core_connectors table");
            terminate();
        }
        
        $connector->setOldGlobals($currentConnectorData);
        $profile->setOldGlobals($currentProfileData);
        
        // Load narrator character data into GLOBALS
        $narrator->loadCharacterIntoGlobals();
        
        $GLOBALS["DIALECTIC_CORE_CURRENT_CONNECTOR_DATA"] = $currentConnectorData;
        
        // Keep connector globals populated for shared connector helpers.
        $GLOBALS["CURRENT_CONNECTOR"] = $currentConnectorData['driver'];
    } else {
        Logger::error("[NARRATOR_QUEST_COMMENT] Narrator profile_id not found in core_narrator table");
        Logger::error("[NARRATOR_QUEST_COMMENT] Please configure The Narrator in Narrator Management");
        terminate();
    }
}

if ($MUST_END) {  // Shorthand for non LLM processing
    dialectic_buffer_response_close();
    if (microtime(true) - $startTime > 0.5) {
        $dbExecutionTime = $GLOBALS["DB_EXECUTION_TIME"] ?? 0;
        error_log("*TRACE EARLY END SQL: TOTAL DATABASE query execution time: {$dbExecutionTime} seconds");
        error_log("*TRACE EARLY END: ".__LINE__. " at ".__FILE__.": ".(microtime(true) - $startTime)." resolving request");
    }
    terminate();

}
$executionMode = strtoupper((string)($GLOBALS["DIALECTIC_EXECUTION_MODE"] ?? ""));
if ($executionMode=="INJECTION_LOG") {
    
    terminate();

}

// What is this for?
if ($gameRequest[0] === "continue" && empty($GLOBALS["RECHAT_PREVIOUS_SPEAKER"])) {
    try {
        $lastSpeechRow = $db->fetchOne("SELECT speaker FROM speech ORDER BY rowid DESC LIMIT 1");
        $GLOBALS["RECHAT_PREVIOUS_SPEAKER"] = trim((string)($lastSpeechRow["speaker"] ?? ""));
    } catch (\Throwable $e) {
        $GLOBALS["RECHAT_PREVIOUS_SPEAKER"] = "";
    }
}

Logger::phaseEnd("post_rechat_runtime_prepare", [
    "type" => $gameRequest[0] ?? "",
    "speaker" => $GLOBALS["DIALECTIC_NAME"] ?? "",
    "must_end" => !empty($MUST_END) ? 1 : 0,
    "execution_mode" => $executionMode ?? "",
], "info");

error_log("TRACE:\t".__LINE__. "\t".__FILE__.":\t".(microtime(true) - $startTime));

/**********************
 CONTEXT DATA BUILDING
***********************/

$GLOBALS["DIRECT_NARRATOR_DIALOGUE"] = (
    $gameRequest[0] === "narrator_inputtext"
    || (
        ($GLOBALS["DIALECTIC_NAME"] ?? "") === "The Narrator"
        && in_array($gameRequest[0], ["cheatmode", "instruction"], true)
    )
);

// Narrator-scoped requests must execute with the narrator runtime profile even
// when the inbound request still carries a valid NPC profile hash. If that hash
// wins earlier profile loading, narrator-only actions get filtered out of the
// runtime function list before response processing.
$isNarratorScopedRequest = in_array($gameRequest[0], [
    "narrator_inputtext",
    "narration",
    "narrator_welcome",
    "narrator_quest_comment",
], true) || (
    ($GLOBALS["DIALECTIC_NAME"] ?? "") === "The Narrator"
    && in_array($gameRequest[0], ["cheatmode", "instruction"], true)
);

if ($isNarratorScopedRequest && (($GLOBALS["DIALECTIC_NAME"] ?? "") !== "The Narrator")) {
    require_once(__DIR__ . DIRECTORY_SEPARATOR . "lib" . DIRECTORY_SEPARATOR . "core" . DIRECTORY_SEPARATOR . "narrator.class.php");

    $narrator = new Narrator();
    $narratorData = $narrator->getNarratorData();

    if ($narratorData && isset($narratorData["profile_id"])) {
        $profile = new CoreProfile();
        $currentProfileData = $profile->getById($narratorData["profile_id"]);

        if ($currentProfileData) {
            $GLOBALS["DIALECTIC_CORE_CURRENT_PROFILE_DATA"] = $currentProfileData;

            $connector = new LLMConnector();
            $npcMaster = new NpcMaster();
            $connectorSlot = LLMRandomizer::getConnectorSlot($currentProfileData, $narratorData, $npcMaster);
            $connectorId = LLMRandomizer::getConnectorIdForSlot($currentProfileData, $connectorSlot);
            $currentConnectorData = $connector->getById($connectorId);

            if ($currentConnectorData) {
                $connector->setOldGlobals($currentConnectorData);
                $profile->setOldGlobals($currentProfileData);
                $narrator->loadCharacterIntoGlobals();

                $GLOBALS["DIALECTIC_CORE_CURRENT_CONNECTOR_DATA"] = $currentConnectorData;
                $GLOBALS["CURRENT_CONNECTOR"] = $currentConnectorData['driver'] ?? ($GLOBALS["CURRENT_CONNECTOR"] ?? "");
    unset($GLOBALS["DIALECTIC_CORE_CURRENT_NPC_DATA"]);
                $GLOBALS["IS_NPC"] = false;
                $GLOBALS["FUNCTIONS_ARE_ENABLED"] = true;

                error_log("[CORE SYSTEM] Re-synced narrator runtime profile before prompt build");
            } else {
                error_log("[CORE SYSTEM] Failed to re-sync narrator runtime profile: connector not found");
            }
        } else {
            error_log("[CORE SYSTEM] Failed to re-sync narrator runtime profile: profile not found");
        }
    } else {
        error_log("[CORE SYSTEM] Failed to re-sync narrator runtime profile: narrator data missing");
    }
}

error_log("*TRACE: ".__LINE__. " at ".__FILE__.": ".(microtime(true) - $startTime)."");

// Include prompts, command prompts and functions.
Logger::phaseStart("prompt_includes", [
    "type" => $gameRequest[0] ?? "",
    "npc" => $GLOBALS["DIALECTIC_NAME"] ?? "",
]);
require(__DIR__.DIRECTORY_SEPARATOR."prompt.includes.php");
$gameRequest[0] = strtolower($gameRequest[0]); // one more time in case it was changed by an extension
Logger::phaseEnd("prompt_includes", [
    "type" => $gameRequest[0] ?? "",
    "npc" => $GLOBALS["DIALECTIC_NAME"] ?? "",
    "functions" => is_array($GLOBALS["FUNCTIONS"] ?? null) ? count($GLOBALS["FUNCTIONS"]) : 0,
], "info");

error_log("*TRACE: ".__LINE__. " at ".__FILE__.": ".(microtime(true) - $startTime)."");

// Inject training function for trainer NPCs (only if Training is enabled)
if (in_array('Training', $GLOBALS["ENABLED_FUNCTIONS"]) && isset($currentNpcData) && $currentNpcData && $GLOBALS["DIALECTIC_NAME"] != "The Narrator") {
    $npcMaster = new NpcMaster();
    $extended = $npcMaster->getExtendedData($currentNpcData);
    if (isset($extended['class']['teaches']) && !empty($extended['class']['teaches'])) {
        $skill = $extended['class']['teaches'];
        $maxLevel = isset($extended['class']['max_training_level']) ? intval($extended['class']['max_training_level']) : 0;
        
        // Convert level to tier name
        $tier = 'Novice';
        if ($maxLevel >= 100) {
            $tier = 'Master';
        } elseif ($maxLevel >= 75) {
            $tier = 'Expert';
        } elseif ($maxLevel >= 50) {
            $tier = 'Adept';
        } elseif ($maxLevel >= 25) {
            $tier = 'Apprentice';
        }
        
        $functionName = "Train" . ucfirst($skill);
        $GLOBALS["FUNCTIONS"][] = [
            "name" => $functionName,
            "description" => (function_exists('dialecticGetPromptCharacterName') ? dialecticGetPromptCharacterName() : $GLOBALS["DIALECTIC_NAME"]) . " offers {$tier} {$skill} training.",
            "parameters" => [
                "type" => "object",
                "properties" => [
                    "target" => [
                        "type" => "string",
                        "description" => "Keep it blank",
                    ],
                ],
                "required" => [""],
            ],
        ];
        $GLOBALS["ENABLED_FUNCTIONS"][] = $functionName;
        $GLOBALS["F_NAMES"][$functionName] = $functionName;
    }
}

// Inject random narration prompt if this is a narration event
// This must happen AFTER prompts.php is loaded to avoid being overwritten
// Inject as the "cue" so it appears as the penultimate user message (like section 81 for normal NPCs)
if (isset($GLOBALS["RANDOM_NARRATION_PROMPT"]) && $gameRequest[0] == "narration") {
    $PROMPTS["narration"]["cue"] = [$GLOBALS["RANDOM_NARRATION_PROMPT"]];
    Logger::info("[RANDOM_NARRATION] Injected narration prompt as cue");
}

if (!empty($GLOBALS["NARRATOR_BORED_EVENT_ACTIVE"]) && $gameRequest[0] == "bored") {
    $boredPrompt = null;
    try {
        $promptData = $db->fetchOne("SELECT custom_prompt, default_prompt FROM prompts WHERE prompt_key = 'narrator_bored_prompt'");
        if ($promptData) {
            $boredPrompt = !empty($promptData['custom_prompt']) ? $promptData['custom_prompt'] : ($promptData['default_prompt'] ?? null);
        }
    } catch (Exception $e) {
        Logger::warn("[NARRATOR_BORED] Failed to load narrator_bored_prompt from database: " . $e->getMessage());
    }

    if (!$boredPrompt) {
        $boredPrompt = '({DIALECTIC_NAME} makes one short comment directly to {PLAYER_NAME} about something happening right now in the current scene. Keep it grounded in the present moment, do not ask follow-up questions, and do not continue the conversation.) {TEMPLATE_DIALOG}';
    }

    $PROMPTS["bored"]["cue"] = [strtr($boredPrompt, [
        '{DIALECTIC_NAME}' => function_exists('dialecticGetPromptCharacterName') ? dialecticGetPromptCharacterName() : ($GLOBALS["DIALECTIC_NAME"] ?? 'The Narrator'),
        '{NARRATOR_NAME}' => function_exists('dialecticGetNarratorRoleplayName') ? dialecticGetNarratorRoleplayName() : 'The Narrator',
        '{PLAYER_NAME}' => $GLOBALS["PLAYER_NAME"] ?? 'Player',
        '{TEMPLATE_DIALOG}' => $GLOBALS["TEMPLATE_DIALOG"] ?? '',
    ])];
    Logger::info("[NARRATOR_BORED] Injected narrator bored prompt");
}

// Inject narrator welcome prompt if this is a narrator_welcome event
if ($gameRequest[0] == "narrator_welcome") {
    $welcomePrompt = isset($GLOBALS["NARRATOR_WELCOME_PROMPT"]) && !empty($GLOBALS["NARRATOR_WELCOME_PROMPT"]) 
        ? $GLOBALS["NARRATOR_WELCOME_PROMPT"]
        : "Give a brief (2-3 sentence) recap of recent events and adventures. Welcome the player back to their journey.";
    
    $PROMPTS["narrator_welcome"]["cue"] = [$welcomePrompt];
}

// Take care of override request if needed..
Logger::phaseStart("request_processor", [
    "type" => $gameRequest[0] ?? "",
    "npc" => $GLOBALS["DIALECTIC_NAME"] ?? "",
]);
require(__DIR__.DIRECTORY_SEPARATOR."processor".DIRECTORY_SEPARATOR."request.php");
Logger::phaseEnd("request_processor", [
    "type" => $gameRequest[0] ?? "",
    "npc" => $GLOBALS["DIALECTIC_NAME"] ?? "",
    "must_end" => !empty($MUST_END) ? 1 : 0,
], "info");



/*
 Safe stop
*/
Logger::info("Current STOPALL_MAGIC_WORD ".STOPALL_MAGIC_WORD);
if (in_array($gameRequest[0],["inputtext","inputtext_s","narrator_inputtext","cheatmode","instruction"]) && preg_match(STOPALL_MAGIC_WORD, $gameRequest[3]) === 1) {
    if (function_exists('dialectic_buffer_command_response_line')) {
        dialectic_buffer_command_response_line((string)$GLOBALS["DIALECTIC_NAME"], "Halt");
    } else {
        Logger::warn("[actions] Halt command requested before JSON response buffer was available");
    }
    
}

Logger::phaseStart("pre_llm_cache_resolution", [
    "type" => $gameRequest[0] ?? "",
    "npc" => $GLOBALS["DIALECTIC_NAME"] ?? "",
]);
if (!isset($GLOBALS["CACHE_PEOPLE"])) {
    $GLOBALS["CACHE_PEOPLE"]=DataBeingsInCloseRange();
} 
if (!isset($GLOBALS["CACHE_LOCATION"])) {
    $GLOBALS["CACHE_LOCATION"]=DataLastKnownLocation();
}     

if (!isset($GLOBALS["CACHE_PARTY"])) {
    $GLOBALS["CACHE_PARTY"]=DataGetCurrentPartyConf();
} 

if (in_array($gameRequest[0],["inputtext_s"]) && dialecticDecodeAudienceSnapshotField($gameRequest[4] ?? "") === "") {    // Stealth-targeted follower: scope to target NPC only
    $GLOBALS["CACHE_PEOPLE"]=$GLOBALS["DIALECTIC_NAME"];
}
Logger::phaseEnd("pre_llm_cache_resolution", [
    "type" => $gameRequest[0] ?? "",
    "npc" => $GLOBALS["DIALECTIC_NAME"] ?? "",
    "people_chars" => strlen((string)($GLOBALS["CACHE_PEOPLE"] ?? "")),
    "location_chars" => strlen((string)($GLOBALS["CACHE_LOCATION"] ?? "")),
    "party_chars" => strlen((string)($GLOBALS["CACHE_PARTY"] ?? "")),
], "info");

error_log("TRACE:\t".__LINE__. "\t".__FILE__.":\t".(microtime(true) - $startTime));
// Scope all incoming events through spatial awareness when possible.
Logger::phaseStart("pre_llm_audience_scope", [
    "type" => $gameRequest[0] ?? "",
    "npc" => $GLOBALS["DIALECTIC_NAME"] ?? "",
]);
$playerInputEventTypes = ["inputtext", "inputtext_s", "narrator_inputtext", "cheatmode"];
$authoritativeAudienceEventTypes = array_merge($playerInputEventTypes, ["player_consumed", "vision"]);
$turnPeopleSnapshotEventTypes = array_merge($playerInputEventTypes, ["rechat", "vision"]);
$requestAudienceSnapshot = dialecticDecodeAudienceSnapshotField($gameRequest[4] ?? "");
$hasAuthoritativeRequestAudience = (
    in_array($gameRequest[0] ?? "", $authoritativeAudienceEventTypes, true) &&
    $requestAudienceSnapshot !== ""
);
$resolvedRechatPeople = "";
if (($gameRequest[0] ?? "") === "rechat" && isset($GLOBALS["RECHAT_RESOLVED_TARGET"])) {
    $resolvedRechatPeople = (string)($GLOBALS["RECHAT_RESOLVED_TARGET"]["people_pipe"] ?? "");
}
$authoritativePeople = $hasAuthoritativeRequestAudience ? $requestAudienceSnapshot : $resolvedRechatPeople;

if (function_exists('isPrivateConversationExecutionMode') &&
    function_exists('buildPrivateConversationPeople') &&
    isPrivateConversationExecutionMode() &&
    in_array($gameRequest[0] ?? "", $playerInputEventTypes, true)) {
    $privatePeople = buildPrivateConversationPeople($GLOBALS["DIALECTIC_NAME"] ?? "");
    if ($privatePeople !== "") {
        $authoritativePeople = $privatePeople;
        Logger::info("Scoped CACHE_PEOPLE for " . ($GLOBALS["DIALECTIC_EXECUTION_MODE"] ?? "private") .
            " " . ($gameRequest[0] ?? "input") . ": " . $privatePeople);
    }
}

if ($authoritativePeople !== "") {
    $GLOBALS["CACHE_PEOPLE"] = $authoritativePeople;
    Logger::info("Scoped CACHE_PEOPLE for {$gameRequest[0]}: " . $GLOBALS["CACHE_PEOPLE"]);
} else {
    $scopedPeople = buildScopedPeopleForEvent(
        $gameRequest[0] ?? "",
        $gameRequest[3] ?? "",
        $GLOBALS["DIALECTIC_NAME"] ?? "",
        $GLOBALS["CACHE_PEOPLE"] ?? ""
    );
    if (!empty($scopedPeople)) {
        $GLOBALS["CACHE_PEOPLE"] = $scopedPeople;
        Logger::info("Scoped CACHE_PEOPLE for {$gameRequest[0]}: " . $GLOBALS["CACHE_PEOPLE"]);
    }

    if (!empty($GLOBALS["DIALECTIC_NAME"])) {
        $shouldAppendListener = shouldAutoAppendListenerToPeople(
            $gameRequest[0] ?? "",
            $gameRequest[3] ?? "",
            $GLOBALS["DIALECTIC_NAME"]
        );
        $currentPeople = isset($GLOBALS["CACHE_PEOPLE"]) ? (string)$GLOBALS["CACHE_PEOPLE"] : "";
        $peopleTokens = array_values(array_filter(array_map('trim', explode('|', $currentPeople))));
        if ($shouldAppendListener && !in_array($GLOBALS["DIALECTIC_NAME"], $peopleTokens, true)) {
            $peopleTokens[] = $GLOBALS["DIALECTIC_NAME"];
            $GLOBALS["CACHE_PEOPLE"] = "|" . implode("|", $peopleTokens) . "|";
            Logger::info("Added listener to CACHE_PEOPLE: " . $GLOBALS["DIALECTIC_NAME"]);
        } elseif (!$shouldAppendListener) {
            Logger::info("Skipped listener auto-append for scoped input event: " . $GLOBALS["DIALECTIC_NAME"]);
        }
    }
}
Logger::phaseEnd("pre_llm_audience_scope", [
    "type" => $gameRequest[0] ?? "",
    "npc" => $GLOBALS["DIALECTIC_NAME"] ?? "",
    "authoritative" => $authoritativePeople !== "" ? 1 : 0,
    "people_chars" => strlen((string)($GLOBALS["CACHE_PEOPLE"] ?? "")),
], "info");

error_log("TRACE:\t".__LINE__. "\t".__FILE__.":\t".(microtime(true) - $startTime));

/// LOG INTO DB. Will use this later.
if ($gameRequest[0] != "diary") {
    // Filter out combat grunts
    $shouldLog = true;
    $data = isset($gameRequest[3]) ? $gameRequest[3] : '';
    
    // List of combat grunts to filter
    // Not agree. Make it optional. A guy using a chair, even 6 times, a combat grunt, a cough ... all of that is context relevant.

    $combatGrunts = [
        'Unff!', 'Argh!', 'Off!', 'Ugh!', 'Gah!', 'Oof!', 'Urgh!', 'Ngh!', 
        'Aah!', 'Ouch!', 'Grr!', 'Hah!', 'Huh!', 'Hmm!', 'Oof', 'Argh', 
        'Unff', 'Off', 'Ugh', 'Gah', 'Aah', 'Ouch', 'Hah',
        'Arghhh!', 'Yarghhh!', 'Rrrghhh!', 'Uuuuhhhnnnn... aaarrrghhh...',
        'Ooohhhh, ahhhrrrghhhh... uuuuggghhh.', 'Yrrrgh!', 'Weergh!', 'Yeagh!',
        'Hyargh!', 'Nyyarrggh!', 'Yearrgh!', 'Ah...', 'Hmph.', 'Hhyyarargghhhh!',
        'Aaaayyyaarrrrgghh!', 'Rrrraaaaarrggghhhh!', 'Ahhhhh!', 'Heh heh...',
        'Grrargh!'
    ];
    
    // Check if data is just a combat grunt
    $trimmedData = trim($data);
    if (in_array($trimmedData, $combatGrunts)) {
        $shouldLog = false;
        error_log("[FILTER] Blocked combat grunt from eventlog: {$trimmedData}");
    }
    
    if ($shouldLog) {
        if ($authoritativePeople !== "") {
            $eventPeople = $authoritativePeople;
            $GLOBALS["CACHE_PEOPLE"] = $authoritativePeople;
        } else {
            $eventPeople = buildScopedPeopleForEvent(
                $gameRequest[0] ?? "",
                $gameRequest[3] ?? "",
                $GLOBALS["DIALECTIC_NAME"] ?? "",
                $GLOBALS["CACHE_PEOPLE"] ?? ""
            );
            if (!empty($eventPeople)) {
                $GLOBALS["CACHE_PEOPLE"] = $eventPeople;
            }
        }

        if (in_array($gameRequest[0], $turnPeopleSnapshotEventTypes, true)) {
            dialecticSetCurrentTurnPeopleSnapshot($eventPeople);
        }

         // Fixes. This should net be here.
        if (isset($dataArray) && is_array($dataArray) && ($dataArray[0] ?? "")=="funcret") {
            $eventPeople=DataBeingsInCloseRange(true);
        }

        $eventlogInsert = array(
            'ts' => $gameRequest[1],
            'gamets' => $gameRequest[2],
            'type' => $gameRequest[0],
            'data' => ($gameRequest[0] === "player_consumed")
                ? dialecticSummarizePlayerConsumedPayload((string)($gameRequest[3] ?? ""))
                : ($gameRequest[3]),
            'sess' => (php_sapi_name()=="cli" && !getenv('PHPUNIT_TEST'))?'cli':'web',
            'localts' => time(),
            'people'=> $eventPeople,
            'location'=>$GLOBALS["CACHE_LOCATION"],
            'party'=>$GLOBALS["CACHE_PARTY"],
        );

        if ($gameRequest[0] === "chat") {
            $eventlogInsert["delivery_state"] = "spoken";
        }

        Logger::phaseStart("eventlog_insert", [
            "type" => $gameRequest[0] ?? "",
            "npc" => $GLOBALS["DIALECTIC_NAME"] ?? "",
            "people_chars" => strlen((string)$eventPeople),
        ]);
        $db->insert('eventlog', $eventlogInsert);
        Logger::phaseEnd("eventlog_insert", [
            "type" => $gameRequest[0] ?? "",
            "npc" => $GLOBALS["DIALECTIC_NAME"] ?? "",
        ], "info");
    }

}

// Check if this event  has been disabled 
if (isset($GLOBALS["PROMPTS"][$gameRequest[0]]["extra"]["dontuse"])) {
    //Logger::warn(" event=".$gameRequest[0]." use=". (!($GLOBALS["PROMPTS"][$gameRequest[0]]["extra"]["dontuse"]) ? "Y" : "N") ." - exec trace"); // debug
    if ($GLOBALS["PROMPTS"][$gameRequest[0]]["extra"]["dontuse"])
        terminate();
}

// Hard cooldown for RPG comment events (global, fixed to 60 seconds)
$rpgCommentEventMap = [
    'combatend'     => 'combat_end',
    'combatendmighty' => 'combat_end',
    'rpg_lvlup'     => 'levelup',
    'lockpicked'    => 'lockpick',
    'goodmorning'   => 'sleep',
    'location_changed' => 'location_changed',
    'quest_updated' => 'quest_updated',
];
$rpgCommentEventType = $rpgCommentEventMap[$gameRequest[0]] ?? null;

if (!empty($rpgCommentEventType)) {
    $rpgCooldownSeconds = 60;
    $rpgCooldownKey = 'RPG_COMMENT_LAST_TIMESTAMP';
    $rpgRecord = $GLOBALS["db"]->fetchAll("SELECT value FROM conf_opts WHERE id='" . $GLOBALS["db"]->escape($rpgCooldownKey) . "'");
    if (!empty($rpgRecord)) {
        $lastTrigger = (int)$rpgRecord[0]['value'];
        $elapsed = time() - $lastTrigger;
        if ($elapsed < $rpgCooldownSeconds) {
            Logger::info("RPG comment {$rpgCommentEventType} skipped (hard cooldown active: {$elapsed}/{$rpgCooldownSeconds}s)");
            terminate();
        }
    }
    $GLOBALS["db"]->upsertRowOnConflict(
        "conf_opts",
        [
            "id" => $rpgCooldownKey,
            "value" => time(),
        ],
        'id'
    );
}


// Narrator stop (from config)

if (isset($GLOBALS["NARRATOR_TALKS"])&&($GLOBALS["NARRATOR_TALKS"]==false)) {
    if ($GLOBALS["DIALECTIC_NAME"]=="The Narrator")
        terminate();
}

// Use diary-specific context history if this is a diary request and CONTEXT_HISTORY_DIARY is set
if (($gameRequest[0] == "diary" || $gameRequest[0] == "diary_followers") && isset($GLOBALS["CONTEXT_HISTORY_DIARY"]) && $GLOBALS["CONTEXT_HISTORY_DIARY"] > 0) {
    $lastNDataForContext = $GLOBALS["CONTEXT_HISTORY_DIARY"];
} else {
    $lastNDataForContext = (isset($GLOBALS["CONTEXT_HISTORY"])) ? ($GLOBALS["CONTEXT_HISTORY"]) : "25";
}

if ($GLOBALS["CLEAN_CONTEXT_FOCUS_CHAT"]==1) {
    $lastNDataForContext=$GLOBALS["CLEAN_CONTEXT_FOCUS_CHAT_HISTORY"];
}

// Historic context (last dialogues, events,...)
//if ((!$GLOBALS["IS_NPC"])||($GLOBALS["DIALECTIC_NAME"]=="The Narrator"))
Logger::phaseStart("context_history_fetch", [
    "npc" => $GLOBALS["DIALECTIC_NAME"] ?? "",
    "limit" => $lastNDataForContext,
]);
if (($GLOBALS["DIALECTIC_NAME"]=="The Narrator"))
    $contextDataHistoric = DataLastDataExpandedFor("", $lastNDataForContext * -1,$sqlfilter);
else if (!$GLOBALS["IS_NPC"])
    $contextDataHistoric = DataLastDataExpandedFor("{$GLOBALS["DIALECTIC_NAME"]}", $lastNDataForContext * -1,$sqlfilter);
else if ($GLOBALS["IS_NPC"]) {
    $contextDataHistoric = DataLastDataExpandedFor("{$GLOBALS["DIALECTIC_NAME"]}", $lastNDataForContext * -1,$sqlfilter);
    
}

// Ensure contextDataHistoric is an array
if (!is_array($contextDataHistoric)) {
    $contextDataHistoric = [];
}
Logger::phaseEnd("context_history_fetch", [
    "npc" => $GLOBALS["DIALECTIC_NAME"] ?? "",
    "rows" => count($contextDataHistoric),
], "info");

// Info about location and npcs in first position
// Check $nearbySections
Logger::phaseStart("context_world_fetch", [
    "npc" => $GLOBALS["DIALECTIC_NAME"] ?? "",
]);
$contextDataWorld = DataLastInfoFor("", -2,true);

// Ensure contextDataWorld is an array
if (!is_array($contextDataWorld)) {
    $contextDataWorld = [];
}
Logger::phaseEnd("context_world_fetch", [
    "rows" => count($contextDataWorld),
], "info");

// Add current plan/quest context to COMMAND_PROMPT. Enabled by default; Context Selections control visibility.
if ($gameRequest[0] != "diary") {
    Logger::phaseStart("current_task_fetch", [
        "npc" => $GLOBALS["DIALECTIC_NAME"] ?? "",
    ]);
    $task=DataGetCurrentTask();
    $GLOBALS["COMMAND_PROMPT"].=$task;
    Logger::phaseEnd("current_task_fetch", [
        "npc" => $GLOBALS["DIALECTIC_NAME"] ?? "",
        "chars" => strlen((string)$task),
    ], "info");
    Logger::info("Task injected for {$GLOBALS["DIALECTIC_NAME"]}");
}

// Offer memory in CONTEXT 

error_log("TRACE:\t".__LINE__. "\t".__FILE__.":\t".(microtime(true) - $startTime));

if (in_array($gameRequest[0],["inputtext","inputtext_s","narrator_inputtext","cheatmode","rechat","narration","continue"]) ) {

    Logger::phaseStart("memory_offer", [
        "type" => $gameRequest[0] ?? "",
        "npc" => $GLOBALS["DIALECTIC_NAME"] ?? "",
    ]);
    $memoryInjection=offerMemory($gameRequest);
    Logger::phaseEnd("memory_offer", [
        "type" => $gameRequest[0] ?? "",
        "npc" => $GLOBALS["DIALECTIC_NAME"] ?? "",
        "chars" => strlen((string)$memoryInjection),
    ], "info");
    //Logger::info("Memory injection:".json_encode($memoryInjection));

    if (!empty($memoryInjection)) {
        
        //$memoryInjectionCtx[]= array('role' => 'user', 'content' => $gameRequest[3]);
        $memoryInjectionCtx[]= array('role' => 'user', 'content' => "#MEMORY: {$GLOBALS["DIALECTIC_NAME"]} remembers this: [$memoryInjection]");
        //$GLOBALS["COMMAND_PROMPT"].="'{$gameRequest[3]}'\n{$GLOBALS["DIALECTIC_NAME"]}:$memoryInjection\n";
        
    } else {
        $memoryInjectionCtx=[];
        $request=str_replace($GLOBALS["MEMORY_STATEMENT"],"",$request);//Cleans the memory statement.
            
    }
} else
     $memoryInjectionCtx=[];

error_log("TRACE:\t".__LINE__. "\t".__FILE__.":\t".(microtime(true) - $startTime));

// Mode-specific response behavior keeps private and projected speech distinct.
if (isset($GLOBALS["DIALECTIC_EXECUTION_MODE"]) && strtoupper((string)$GLOBALS["DIALECTIC_EXECUTION_MODE"]) === "WHISPER") {
    if (!isset($GLOBALS["COMMAND_PROMPT"]) || !is_string($GLOBALS["COMMAND_PROMPT"])) {
        $GLOBALS["COMMAND_PROMPT"] = "";
    }
    $GLOBALS["COMMAND_PROMPT"] .= "\n\n[Whisper mode is active. {$GLOBALS["PLAYER_NAME"]} is whispering to you. Reply by whispering back in a quiet, discreet, close-range tone and keep the delivery private.]";
} elseif (isset($GLOBALS["DIALECTIC_EXECUTION_MODE"]) && strtoupper((string)$GLOBALS["DIALECTIC_EXECUTION_MODE"]) === "CLOSE") {
    if (!isset($GLOBALS["COMMAND_PROMPT"]) || !is_string($GLOBALS["COMMAND_PROMPT"])) {
        $GLOBALS["COMMAND_PROMPT"] = "";
    }
    $GLOBALS["COMMAND_PROMPT"] .= "\n\n[Close mode is active. {$GLOBALS["PLAYER_NAME"]} is speaking privately to you at close range. Reply discreetly to the player only; do not address or involve bystanders.]";
} elseif (isset($GLOBALS["DIALECTIC_EXECUTION_MODE"]) && strtoupper((string)$GLOBALS["DIALECTIC_EXECUTION_MODE"]) === "SHOUT") {
    if (!isset($GLOBALS["COMMAND_PROMPT"]) || !is_string($GLOBALS["COMMAND_PROMPT"])) {
        $GLOBALS["COMMAND_PROMPT"] = "";
    }
    $GLOBALS["COMMAND_PROMPT"] .= "\n\n[Shout mode is active. {$GLOBALS["PLAYER_NAME"]} is speaking loudly across the area. Reply loudly enough to be heard and treat the exchange as public, audible dialogue.]";
}


// array('role' => $currentSpeaker, 'content' => implode("\n", $buffer));

// Action-enforcement prompt is hard-disabled globally.
$GLOBALS["ENFORCE_ACTIONS_PROMPT"] = false;
$GLOBALS["PATCH_PROMPT_ENFORCE_ACTIONS"] = false;
$GLOBALS["COMMAND_PROMPT_ENFORCE_ACTIONS"] = "";


// Rechat case
if (in_array($gameRequest[0],["rechat","narration"]) ) {
    // CHAOS mode
    
    $rechatAllowsActions = filter_var($GLOBALS["RECHAT_ALLOW_ACTIONS"] ?? false, FILTER_VALIDATE_BOOLEAN);
    if ($rechatAllowsActions) {
        $FUNCTIONS_ARE_ENABLED=true;
        $GLOBALS["FUNCTIONS_ARE_ENABLED"]=true;

        $GLOBALS["PATCH_PROMPT_ENFORCE_ACTIONS"]=false;
        $GLOBALS["COMMAND_PROMPT_ENFORCE_ACTIONS"]="";
        
        // Plugin action prompts can break rechat actor addressing: "Respond to #target# as #dialectic_name#".
        $GLOBALS['action_prompts']=[];
        $GLOBALS["ENABLED_FUNCTIONS"] = function_exists('dialecticFilterCanonicalActionCodeList')
            ? dialecticFilterCanonicalActionCodeList($GLOBALS["ENABLED_FUNCTIONS"] ?? [])
            : ($GLOBALS["ENABLED_FUNCTIONS"] ?? []);

        $rechatSafeActionCodes = [
            "CheckInventory" => true,
            "Inspect" => true,
            "InspectSurroundings" => true,
            "ReadQuests" => true,
            "EndConversation" => true,
        ];
        $rechatEnabledFunctionSet = array_fill_keys($GLOBALS["ENABLED_FUNCTIONS"] ?? [], true);
        $rechatActionAllowed = function ($functionCode) use ($rechatSafeActionCodes, $rechatEnabledFunctionSet) {
            return isset($rechatSafeActionCodes[$functionCode]) && isset($rechatEnabledFunctionSet[$functionCode]);
        };

        $GLOBALS["ENABLED_FUNCTIONS"] = array_values(array_unique(array_filter(
            $GLOBALS["ENABLED_FUNCTIONS"] ?? [],
            function ($functionCode) use ($rechatActionAllowed) {
                return $rechatActionAllowed($functionCode);
            }
        )));

        $GLOBALS["FUNCTIONS"] = array_values(array_filter(
            $GLOBALS["FUNCTIONS"] ?? [],
            function ($functionEntry) use ($rechatActionAllowed) {
                if (!is_array($functionEntry) || empty($functionEntry["name"])) {
                    return false;
                }
                $functionCode = function_exists('getFunctionCodeName')
                    ? getFunctionCodeName($functionEntry["name"])
                    : $functionEntry["name"];
                return $functionCode !== false && $rechatActionAllowed($functionCode);
            }
        ));

        Logger::info("[RECHAT_ACTIONS] Restricted rechat action catalog to: " . implode(",", $GLOBALS["ENABLED_FUNCTIONS"]));
       
    }
}

// Instruction reinforcement
if (in_array($gameRequest[0],["instruction"]) ) {
    
    $GLOBALS["PATCH_PROMPT_ENFORCE_ACTIONS"]=false;
    $GLOBALS["COMMAND_PROMPT_ENFORCE_ACTIONS"]="";
    
}

// Enforce actions
$GLOBALS["PATCH_PROMPT_ENFORCE_ACTIONS"]=false;
$GLOBALS["COMMAND_PROMPT_ENFORCE_ACTIONS"]="";

// Rolemaster stuff
if (dialecticResolveNpcRolemasterState($GLOBALS["DIALECTIC_NAME"] ?? '', [
    'npc_data' => $currentNpcData ?? null,
])) {
    $GLOBALS["is_rolemastered"]=true;
    $GLOBALS["NPC_ROLEMASTERED"]=true;
    error_log("{$GLOBALS["DIALECTIC_NAME"]} is_rolemastered");
    $GLOBALS["PATCH_PROMPT_ENFORCE_ACTIONS"]=false;
    $GLOBALS["COMMAND_PROMPT_ENFORCE_ACTIONS"]="";
} 

error_log("TRACE:\t".__LINE__. "\t".__FILE__.":\t".(microtime(true) - $startTime));

if (!empty($GLOBALS["DIRECT_NARRATOR_DIALOGUE"])) {
    $GLOBALS["PATCH_PROMPT_ENFORCE_ACTIONS"] = false;
    $GLOBALS["COMMAND_PROMPT_ENFORCE_ACTIONS"] = "";
}


// audit_log(__FILE__." [MINIME]  ".__LINE__);

error_log("TRACE:\t".__LINE__. "\t".__FILE__.":\t".(microtime(true) - $startTime));

// WORLDKNOWLEDGE STUFF - Only run if WorldKnowledge is enabled in profile
// Helper function to properly check boolean values (handles string "false" from form submissions)
if (!function_exists('isWorldKnowledgeSettingEnabled')) {
    function isWorldKnowledgeSettingEnabled($value) {
        if ($value === null) return false;
        if ($value === false || $value === 'false' || $value === '0' || $value === 0) return false;
        if ($value === true || $value === 'true' || $value === '1' || $value === 1) return true;
        return (bool)$value;
    }
}

$minimeEnabled = isMinimeT5Enabled();
$worldknowledgeCustomEnabled = isWorldKnowledgeSettingEnabled($GLOBALS["WORLDKNOWLEDGE_CUSTOM"] ?? false);
$worldknowledgeInfiniumEnabled = isWorldKnowledgeSettingEnabled($GLOBALS["WORLDKNOWLEDGE_INFINIUM"] ?? false);
$locationWorldKnowledgeEnabled = isWorldKnowledgeSettingEnabled($GLOBALS["LOCATION_WORLDKNOWLEDGE"] ?? true);

// Debug: Log the actual values being checked BEFORE the conditional
error_log("[WORLDKNOWLEDGE CHECK] MINIME_T5(auto)=" . ($minimeEnabled ? 'Y' : 'N')
    . " | WORLDKNOWLEDGE_CUSTOM=" . var_export($GLOBALS["WORLDKNOWLEDGE_CUSTOM"] ?? null, true)
    . " (enabled=" . ($worldknowledgeCustomEnabled ? 'Y' : 'N') . ")"
    . " | WORLDKNOWLEDGE_INFINIUM=" . var_export($GLOBALS["WORLDKNOWLEDGE_INFINIUM"] ?? null, true)
    . " (enabled=" . ($worldknowledgeInfiniumEnabled ? 'Y' : 'N') . ")");

if (($minimeEnabled || $worldknowledgeCustomEnabled || $locationWorldKnowledgeEnabled) && $worldknowledgeInfiniumEnabled) {
    Logger::phaseStart("worldknowledge_processor", [
        "npc" => $GLOBALS["DIALECTIC_NAME"] ?? "",
    ]);
    require(__DIR__."/processor/worldknowledge.php");
    Logger::phaseEnd("worldknowledge_processor", [
        "npc" => $GLOBALS["DIALECTIC_NAME"] ?? "",
        "hint_chars" => strlen((string)($GLOBALS["WORLDKNOWLEDGE_HINT"] ?? "")),
    ], "info");
}

error_log("TRACE:\t".__LINE__. "\t".__FILE__.":\t".(microtime(true) - $startTime));

if (sizeof($memoryInjectionCtx)>0) {
    // Persist memory injection
    $gameRequestCopy=$gameRequest;
    $gameRequestCopy[0]="infoaction";
    $gameRequestCopy[3]=$memoryInjectionCtx[0]["content"];
    logEvent($gameRequestCopy,$GLOBALS["DIALECTIC_NAME"]);// Memory log only avaibale to current NPC.
}

$contextDataFull = array_merge($contextDataWorld, $contextDataHistoric);

$contextDataHistoric = filterHistoricContextForNarratorVisibility(
    $contextDataHistoric,
    $GLOBALS["DIALECTIC_NAME"] ?? ""
);
require_once __DIR__ . DIRECTORY_SEPARATOR . "lib" . DIRECTORY_SEPARATOR . "compact_context_history.php";
if (dialecticShouldCompactNpcContextHistory($GLOBALS["DIALECTIC_NAME"] ?? "")) {
    $contextDataHistoric = dialecticFormatCompactNpcContextHistory(
        $contextDataHistoric,
        (string)($GLOBALS["DIALECTIC_NAME"] ?? "")
    );
}
$contextDataFull = array_merge($contextDataWorld, $contextDataHistoric);

$GLOBALS["DIALECTIC_CONTEXT"] = implode("\n", array_values(array_filter(array_map(
    static function ($entry) {
        return is_array($entry) ? trim((string)($entry["content"] ?? "")) : "";
    },
    $contextDataHistoric
))));
require_once __DIR__ . DIRECTORY_SEPARATOR . "ext" . DIRECTORY_SEPARATOR
    . "relationship_system" . DIRECTORY_SEPARATOR . "context_pre.php";

// audit_log(__FILE__." [WORLDKNOWLEDGE]  ".__LINE__);

// Player bio/appearance is surfaced through the nearby actors section.


// Use centralized function from data_functions.php
Logger::phaseStart("prompt_dynamic_context_build", [
    "npc" => $GLOBALS["DIALECTIC_NAME"] ?? "",
]);
$dynamicBiography = buildDynamicBiography($GLOBALS);
$worldPrompt = buildWorldPrompt($gameRequest[2] ?? 0);
require_once(__DIR__ . DIRECTORY_SEPARATOR . 'lib' . DIRECTORY_SEPARATOR . 'visual_context.php');
$visualContextPrompt = dialecticBuildVisualContextPrompt(
    function_exists('dialecticLatestWorldContextPayload')
        ? (dialecticLatestWorldContextPayload() ?: [])
        : []
);

$playerBioSection = "";
try {
    require_once(__DIR__.DIRECTORY_SEPARATOR."lib".DIRECTORY_SEPARATOR."core".DIRECTORY_SEPARATOR."player.class.php");
    $playerObj = new Player();
    $playerBio = ResolvePlayerBackstory($playerObj);
    $bioKnownByAll = filter_var((string)($playerObj->get('bio_known_by_all') ?? ''), FILTER_VALIDATE_BOOLEAN);
    $isNarrator = isset($GLOBALS["DIALECTIC_NAME"]) && strcasecmp((string)$GLOBALS["DIALECTIC_NAME"], "The Narrator") === 0;

    if ($playerBio !== "" && ($bioKnownByAll || $isNarrator)) {
        $playerBioSection = "\n\n<player_character>\n# Player Character: {$GLOBALS["PLAYER_NAME"]}\n{$playerBio}\n</player_character>";
    }
} catch (Exception $e) {
    Logger::debug("Could not load player bio for prompt: " . $e->getMessage());
}


if (isset($GLOBALS["PROFILE_PROMPT"])) {
    $dynamicBiography.="\n<group>\n#Part of a group\n{$GLOBALS["PROFILE_PROMPT"]}\n</group>";
}




// Middle term memory experiment
// Skip middle-term memory for The Narrator (atmospheric narration shouldn't include individual NPC memories)
if ($GLOBALS["DIALECTIC_NAME"] !== "The Narrator" && ($activeProfile = dialecticRuntimeGetActiveProfile()) !== null) {
    $npcMaster=new NpcMaster();
    $currentNpcData=$npcMaster->getByMD5($activeProfile);
    // Only process if we got valid NPC data (not The Narrator)
    if ($currentNpcData && $currentNpcData["npc_name"] !== "The Narrator") {
        $extended_data=$npcMaster->getExtendedData($currentNpcData);
        if (isset($extended_data["middle_term_memory"])&&is_array($extended_data["middle_term_memory"])) {
            $middle_term_memory = end($extended_data["middle_term_memory"]);
            $dynamicBiography.="\n<middle_term_memory>\n#Past events\n{$middle_term_memory}\n</middle_term_memory>";
        }
    }
}

// Narration-like requests should stay descriptive instead of drifting into
// ordinary conversation turns.
if ($gameRequest[0] === "vision") {
    $GLOBALS["COMMAND_PROMPT"] = "Respond with a current-scene explanation only. Focus on what is visibly present in the provided scene context. Use the Talk action.";
} else if ($gameRequest[0] === "narration" || $gameRequest[0] === "narrator_welcome") {
    $GLOBALS["COMMAND_PROMPT"] = "Respond with atmospheric narration only. Use the Talk action.";
}

// Ensure actions and nearby sections are added to PROMPT_HEAD before building system prompt
require_once(__DIR__.DIRECTORY_SEPARATOR."functions".DIRECTORY_SEPARATOR."json_response.php");

if (
    $gameRequest[0] === "narrator_inputtext"
    && function_exists('dialecticEnsureNarratorJsonResponseState')
    && (
        !function_exists('dialecticNarratorJsonResponseNeedsRefresh')
        || dialecticNarratorJsonResponseNeedsRefresh()
    )
) {
    dialecticEnsureNarratorJsonResponseState('JSON_RESPONSE');
}

// Build nearby sections string
$nearbySections = "";
if (isset($GLOBALS["PROMPT_NEARBY_SECTIONS"]) && !empty($GLOBALS["PROMPT_NEARBY_SECTIONS"])) {
    $nearbySections = $GLOBALS["PROMPT_NEARBY_SECTIONS"];
}

// Build actions list string
$actionsList = "";
if (isset($GLOBALS["PROMPT_ACTIONS_LIST"]) && !empty($GLOBALS["PROMPT_ACTIONS_LIST"])) {
    $actionsList = $GLOBALS["PROMPT_ACTIONS_LIST"];
}

// Inject paralinguistic tags prompt if enabled (works for any TTS provider)
$paralinguisticTagsPrompt = "";
if (isset($GLOBALS["TTSFUNCTION"]) && !empty($GLOBALS["TTSFUNCTION"])) {
    // Map TTSFUNCTION to TTS array key
    $ttsMap = [
        'xtts-fastapi' => 'XTTSFASTAPI',
        'omnivoice' => 'OMNIVOICE',
        'chatterbox' => 'CHATTERBOX',
        'pockettts' => 'POCKETTTS',
        '11labs' => 'ELEVEN_LABS',
        'kokoro' => 'KOKORO',
        'piper-tts' => 'PIPERTTS',
        'cartesia' => 'CARTESIA',
        'inworld' => 'INWORLD'
    ];
    
    $ttsKey = $ttsMap[$GLOBALS["TTSFUNCTION"]] ?? strtoupper($GLOBALS["TTSFUNCTION"]);
    
    if (isset($GLOBALS["TTS"][$ttsKey]["PARALINGUISTIC_TAGS_ENABLED"]) && 
        (bool)$GLOBALS["TTS"][$ttsKey]["PARALINGUISTIC_TAGS_ENABLED"]) {
        if (isset($GLOBALS["TTS"][$ttsKey]["PARALINGUISTIC_TAGS_PROMPT"]) && 
            !empty(trim($GLOBALS["TTS"][$ttsKey]["PARALINGUISTIC_TAGS_PROMPT"]))) {
            $paralinguisticTagsPrompt = "\n\n<paralinguistic_tags>\n" . 
                trim($GLOBALS["TTS"][$ttsKey]["PARALINGUISTIC_TAGS_PROMPT"]) . 
                "\n</paralinguistic_tags>";
        }
    }
}

//dialecticFormatPromptXmlSections moved to misc.php, dialecticRemovePromptXmlBlock,dialecticApplyPromptContextOptionsToSystemPrompt moved to misc.php

$promptInjectionContext = [
    "game_request" => $gameRequest,
    "dialectic_name" => function_exists('dialecticGetPromptCharacterName') ? dialecticGetPromptCharacterName() : ($GLOBALS["DIALECTIC_NAME"] ?? ""),
    "narrator_name" => function_exists('dialecticGetNarratorRoleplayName') ? dialecticGetNarratorRoleplayName() : 'The Narrator',
    "player_name" => $GLOBALS["PLAYER_NAME"] ?? "",
];
$characterBottomInjections = function_exists('dialecticRenderPromptInjections')
    ? dialecticRenderPromptInjections("character_bottom", $promptInjectionContext)
    : "";
$latestDiaryContext = function_exists('dialecticBuildLatestDiaryContextBlock')
    ? dialecticBuildLatestDiaryContextBlock(
        strval($GLOBALS["DIALECTIC_NAME"] ?? ''),
        is_array($GLOBALS["DIALECTIC_CORE_CURRENT_PROFILE_DATA"] ?? null)
            ? $GLOBALS["DIALECTIC_CORE_CURRENT_PROFILE_DATA"]
            : []
    )
    : "";
$promptBottomInjections = function_exists('dialecticRenderPromptInjections')
    ? dialecticRenderPromptInjections("prompt_bottom", $promptInjectionContext)
    : "";

$knowledgeSection = "";
if (!empty($GLOBALS["WORLDKNOWLEDGE_HINT"])) {
    $knowledgeSection = "\n\n<knowledge>\n" . $GLOBALS["WORLDKNOWLEDGE_HINT"] . "\n</knowledge>";
}

$systemPromptRaw = "<roleplay_instructions>\n" . $GLOBALS["PROMPT_HEAD"] .
    "\n</roleplay_instructions>" . $worldPrompt . ($visualContextPrompt !== '' ? "\n\n" . $visualContextPrompt : '') .
    "\n\n<character>\n" . $GLOBALS["DIALECTIC_PERS"] . $dynamicBiography . $latestDiaryContext . $characterBottomInjections .
    "\n</character>" . $knowledgeSection .
    "\n\n<general_instructions>\n" . $GLOBALS["COMMAND_PROMPT"] .
    "\n</general_instructions>" . $actionsList . $nearbySections . $promptBottomInjections . $paralinguisticTagsPrompt . "\n";

$systemPrompt = dialecticFormatPromptXmlSections(
    strtr(
        $systemPromptRaw,
        [
            "#PLAYER_NAME#" => $GLOBALS["PLAYER_NAME"],
            "#DIALECTIC_NAME#" => function_exists('dialecticGetPromptCharacterName') ? dialecticGetPromptCharacterName() : $GLOBALS["DIALECTIC_NAME"],
            "#NARRATOR_NAME#" => function_exists('dialecticGetNarratorRoleplayName') ? dialecticGetNarratorRoleplayName() : 'The Narrator',
        ]
    )
);

$systemPrompt = dialecticApplyPromptContextOptionsToSystemPrompt($systemPrompt);

$head[] = array('role' => 'system', 'content' => $systemPrompt);
Logger::phaseEnd("prompt_dynamic_context_build", [
    "npc" => $GLOBALS["DIALECTIC_NAME"] ?? "",
    "system_chars" => strlen((string)$systemPrompt),
    "nearby_chars" => strlen((string)$nearbySections),
    "actions_chars" => strlen((string)$actionsList),
], "info");

if (!empty($GLOBALS["WORLDKNOWLEDGE_HINT"])) {
    //avoid reinjecting command prompt that we have already appended
    $GLOBALS["COMMAND_PROMPT"] = "";
} else {
    //avoid reinjecting command prompt that we have already appended
    $GLOBALS["COMMAND_PROMPT"] = "";
}

/**********************
CALL BUILDING
***********************/
error_log("*TRACE: ".__LINE__. " at ".__FILE__.": ".(microtime(true) - $startTime)."");

if ($gameRequest[0] == "funcret") {

    $prompt[] = array('role' => 'assistant', 'content' => $request);

    // Manage function stuff
    // $contextData will be populated

    require(__DIR__.DIRECTORY_SEPARATOR."processor".DIRECTORY_SEPARATOR."funcret.php");


} else if ($gameRequest[0] == "cheatmode") {

    $prompt[] = array('role' => $LAST_ROLE, 'content' => $request);
    $contextData = array_merge($head, ($contextDataFull), $prompt);
}  else {
    // Ensure CURRENT_CONNECTOR is set
    if (!isset($GLOBALS["CURRENT_CONNECTOR"]) || empty($GLOBALS["CURRENT_CONNECTOR"])) {
        if (isset($GLOBALS["DIALECTIC_CORE_CURRENT_CONNECTOR_DATA"]["driver"])) {
            $GLOBALS["CURRENT_CONNECTOR"] = $GLOBALS["DIALECTIC_CORE_CURRENT_CONNECTOR_DATA"]["driver"];
        } else {
            Logger::error("CURRENT_CONNECTOR not set and DIALECTIC_CORE_CURRENT_CONNECTOR_DATA not available!");
            $GLOBALS["CURRENT_CONNECTOR"] = "unknown";
        }
    }
    
    {
        $explicitDirectNarratorInput = false;
        if (!empty($GLOBALS["DIRECT_NARRATOR_DIALOGUE"]) && $gameRequest[0] === "narrator_inputtext" && !empty($gameRequest[3])) {
            $explicitDirectNarratorInput = true;
            if (!empty($contextDataFull)) {
                $lastContextEntry = end($contextDataFull);
                if (is_array($lastContextEntry)
                    && (($lastContextEntry["role"] ?? "") === "user")
                    && trim((string)($lastContextEntry["content"] ?? "")) === trim((string)$gameRequest[3])) {
                    $explicitDirectNarratorInput = false;
                }
                reset($contextDataFull);
            }
        }
        if (!empty($request)) {
            if ($explicitDirectNarratorInput) {
                $prompt[] = array('role' => 'user', 'content' => $gameRequest[3]);
            }
            $prompt[] = array('role' => $LAST_ROLE, 'content' => $request);
            if (sizeof($memoryInjectionCtx)>0) {
                array_splice($prompt, -1, 0, $memoryInjectionCtx); // add memory as second-to-last entry
                Logger::info("Injected memory");
            }
            
        } else {
            $connectorName = isset($GLOBALS["CURRENT_CONNECTOR"]) ? $GLOBALS["CURRENT_CONNECTOR"] : "unknown";
            Logger::error("CRITICAL? :: Empty request, prompt empty. Type: {$gameRequest[0]} Connector: {$connectorName}");
            $prompt=[];
        }
    }

    $contextData = array_merge($head, ($contextDataFull), $prompt);
    
}

if (isset($contextData) && is_array($contextData) && function_exists('dialecticApplyNarratorRoleplayNameToContext')) {
    $contextData = dialecticApplyNarratorRoleplayNameToContext($contextData);
}


if (microtime(true) - $startTime > 0.25) {
    $dbExecutionTime = $GLOBALS["DB_EXECUTION_TIME"] ?? 0;
    error_log("*TRACE SQL: TOTAL DATABASE query execution time: {$dbExecutionTime} seconds");
    error_log("*TRACE: ".__LINE__. " at ".__FILE__.": ".(microtime(true) - $startTime)." secs building call");
}

//returnLines(["Mmm..let me think"]);

// Global switch. Needed id we need to stop processing because sme function requires it. Example, funcret conditions.
if (isset($GLOBALS["AVOID_LLM_CALL"])&&($GLOBALS["AVOID_LLM_CALL"])) {
    Logger::info("Terminated by AVOID_LLM_CALL");
    terminate();
}

// Diary stuff 
if ($gameRequest[0] == "diary") {
    // TO-DO move this to its own processor file.

    generateFollowerDiary($GLOBALS["DIALECTIC_NAME"],$gameRequest,"diary");
    Logger::info("Terminated after diary request");
    terminate();
}

/**********************
CALL INITIALIZATION
***********************/


audit_log(__FILE__." [PRE LLM CALL]  ".__LINE__);

Logger::phaseStart("llm", [
    "type" => $gameRequest[0] ?? "",
    "speaker" => $GLOBALS["DIALECTIC_NAME"] ?? "",
    "connector" => ($GLOBALS["DIALECTIC_CORE_CURRENT_CONNECTOR_DATA"]["driver"] ?? ""),
    "model" => ($GLOBALS["DIALECTIC_CORE_CURRENT_CONNECTOR_DATA"]["model"] ?? ""),
]);

$outputWasValid = call_llm();

Logger::phaseEnd("llm", [
    "status" => $outputWasValid ? "ok" : "invalid",
    "lines_sent" => is_array($talkedSoFar) ? count($talkedSoFar) : 0,
    "actions_sent" => is_array($alreadysent) ? count($alreadysent) : 0,
], $outputWasValid ? "info" : "warn");

if (!$outputWasValid) {
    Logger::warn("LLM returned invalid output.");
    if (isset($GLOBALS["LLM_RETRY_FNCT"])) {
        $GLOBALS["LLM_RETRY_FNCT"]();
    }
}

// Relationship evaluation must see the complete turn. Run it only after the
// stream and any retry have finished, but before the JSON response closes.
require_once __DIR__ . DIRECTORY_SEPARATOR . "ext" . DIRECTORY_SEPARATOR
    . "relationship_system" . DIRECTORY_SEPARATOR . "postrequest.php";


if (sizeof($talkedSoFar) == 0) {
    if (sizeof($alreadysent) > 0) { // AI only issued commands

        $db->insert(
            'log',
            array(
                'localts' => time(),
                'prompt' => nl2br((json_encode($GLOBALS["DEBUG_DATA"], JSON_PRETTY_PRINT))),
                'response' => (print_r($alreadysent, true)),
                'url' => nl2br(("$receivedData in " . (microtime(true) - $startTime) . " secs "))


            )
        );
        if (($gameRequest[0] ?? "") === "cheatmode") {
            $cheatAck = "Done.";
            dialectic_buffer_speech_response_line(
                $GLOBALS["DIALECTIC_NAME"] ?? "The Narrator",
                $cheatAck,
                "",
                $GLOBALS["PLAYER_NAME"] ?? "",
                "",
                "",
                1.0
            );
            $talkedSoFar[] = $cheatAck;
            Logger::info("[CHEATMODE] Action-only response acknowledged with spoken line.");
        }

    } else { // Fail request? or maybe an invalid command was issued

        //returnLines(array($randomSentence));
        $db->insert(
            'log',
            array(
                'localts' => time(),
                'prompt' => nl2br((json_encode($GLOBALS["DEBUG_DATA"], JSON_PRETTY_PRINT))),
                'response' => (print_r($alreadysent, true)),
                'url' => nl2br(("$receivedData in " . (microtime(true) - $startTime) . " secs "))


            )
        );

    }
} else {

    if (sizeof($alreadysent) > 0) { // AI only issued commands
        $db->insert(
            'log',
            array(
                'localts' => time(),
                'prompt' => nl2br((json_encode($GLOBALS["DEBUG_DATA"], JSON_PRETTY_PRINT))),
                'response' => (print_r($alreadysent, true)),
                'url' => nl2br(("$receivedData in " . (microtime(true) - $startTime) . " secs "))
            )
        );
    }

    if (!$ERROR_TRIGGERED) {
        if ($gameRequest[0] == "diary") {
         

        } else {
            
            $lastPlayerLine=$db->fetchAll("SELECT data from eventlog where type in ('inputtext','inputtext_s') order by gamets desc limit 1 offset 0");
            if (php_sapi_name()!="cli" || getenv('PHPUNIT_TEST'))	{
                if (in_array($gameRequest[0],["inputtext","inputtext_s"]))
                    // logMemory($GLOBALS["DIALECTIC_NAME"], $GLOBALS["PLAYER_NAME"], "{$lastPlayerLine[0]["data"]} \n\r {$GLOBALS["DIALECTIC_NAME"]}:".implode(" ", $talkedSoFar), $momentum, $gameRequest[2],$gameRequest[1]);
                    ;
                else {
                    // Speech table will take care
                    //logMemory($GLOBALS["DIALECTIC_NAME"], $GLOBALS["PLAYER_NAME"], "{$GLOBALS["DIALECTIC_NAME"]}:".implode(" ", $talkedSoFar), $momentum, $gameRequest[2]);
                    ;
                }
            }
            
            // Update speech table with LLM-generated text for AUTOCHAT mode
            if (isset($GLOBALS["DIALECTIC_EXECUTION_MODE"]) && $GLOBALS["DIALECTIC_EXECUTION_MODE"] === "AUTOCHAT" 
                && in_array($gameRequest[0], ["inputtext", "inputtext_s"])
                && sizeof($talkedSoFar) > 0) {
                
                $transformedSpeech = trim($db->escape($player_rewrite_speech));
                $playerName = $db->escape($GLOBALS["PLAYER_NAME"]);
                $currentGamets = intval($gameRequest[2]);
                
                // Update the most recent player speech entry with the LLM-generated text
                $db->execQuery(
                    "UPDATE speech 
                     SET speech = '{$transformedSpeech}' 
                     WHERE speaker ILIKE '{$playerName}' 
                     AND gamets >= {$currentGamets} - 100 
                     AND gamets <= {$currentGamets} + 100
                     AND sess = 'pending'"
                );
                Logger::info("[AUTOCHAT] Updated speech table with LLM-generated player text");
            }
        }
    }
}



dialectic_buffer_response_close();


if (php_sapi_name()=="cli" && !getenv('PHPUNIT_TEST')) {
    echo PHP_EOL;
    file_put_contents("log/debug_comm_".basename(__FILE__).".log", print_r($GLOBALS["DEBUG_DATA"], true));

    //$db->delete("eventlog", "sess='cli'");

}


// POST PROCESS TASKS
SemaphoreManager::release("MAIN");


while(!getenv("PHPUNIT_TEST") && ob_get_length() && ob_end_flush());
$dialecticResponseEmittedBeforePostrequest = false;
if (dialectic_json_response_enabled()) {
    Logger::phaseStart("plugin_response_emit", [
        "lines" => count($GLOBALS["DIALECTIC_JSON_RESPONSE_LINES"] ?? []),
        "streaming" => !empty($GLOBALS["DIALECTIC_RESPONSE_STREAMING"]),
    ]);
    dialectic_emit_buffered_json_response();
    @flush();
    Logger::phaseEnd("plugin_response_emit", [
        "status" => "ok",
        "lines" => count($GLOBALS["DIALECTIC_JSON_RESPONSE_LINES"] ?? []),
    ], "info");

    if (function_exists("fastcgi_finish_request")) {
        Logger::debug("[plugin-response] Closing client response before deferred post-response work via fastcgi_finish_request");
        @fastcgi_finish_request();
    } else if (!empty($GLOBALS["DIALECTIC_DEFERRED_TTS"])) {
        Logger::warn("[plugin-response] fastcgi_finish_request unavailable; deferred TTS will still run before the HTTP connection fully closes");
    }

    if (function_exists("dialectic_generate_deferred_tts")) {
        dialectic_generate_deferred_tts();
    }

    Logger::phaseEnd("turn", [
        "status" => "json_emitted",
        "type" => $gameRequest[0] ?? "",
        "lines" => count($GLOBALS["DIALECTIC_JSON_RESPONSE_LINES"] ?? []),
        "files_generated" => count($GLOBALS["TRACK"]["FILES_GENERATED"] ?? []),
    ], "info");

    if (!getenv("PHPUNIT_TEST")) {
        exit;
    }

    $dialecticResponseEmittedBeforePostrequest = true;
}

if ($dialecticResponseEmittedBeforePostrequest && !getenv("PHPUNIT_TEST")) {
    ob_start();
    $dialecticPostrequestBufferLevel = ob_get_level();
}
require(__DIR__.DIRECTORY_SEPARATOR."processor".DIRECTORY_SEPARATOR."postrequest.php");
if ($dialecticResponseEmittedBeforePostrequest && !getenv("PHPUNIT_TEST")) {
    $dialecticPostrequestBufferLevel = intval($dialecticPostrequestBufferLevel ?? 0);
    while ($dialecticPostrequestBufferLevel > 0 && ob_get_level() >= $dialecticPostrequestBufferLevel) {
        @ob_end_clean();
    }
}

Logger::phaseEnd("turn", [
    "status" => "complete",
    "type" => $gameRequest[0] ?? "",
    "lines" => count($GLOBALS["DIALECTIC_JSON_RESPONSE_LINES"] ?? []),
    "files_generated" => count($GLOBALS["TRACK"]["FILES_GENERATED"] ?? []),
], "info");


?>
