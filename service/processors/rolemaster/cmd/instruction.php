<?php 
require_once(__DIR__ . '/../../../../lib/logger.php');
require_once(__DIR__ . '/../../../../lib/dialectic_command_payload.php');

require_once($GLOBALS["ENGINE_ROOT"] . "/lib/{$GLOBALS["DBDRIVER"]}.class.php");
if (!isset($GLOBALS["db"])) { $GLOBALS["db"] = new sql(); }

require_once($GLOBALS["ENGINE_ROOT"] . "/lib/core/api_badge.class.php");
require_once($GLOBALS["ENGINE_ROOT"] . "/lib/core/llm_connector.class.php");
require_once($GLOBALS["ENGINE_ROOT"] . "/lib/core/npc_master.class.php");
require_once($GLOBALS["ENGINE_ROOT"] . "/lib/core/core_profiles.class.php");
require_once($GLOBALS["ENGINE_ROOT"] . "/lib/relationship_manager.php");

$GLOBALS["ENGINE_PATH"]=$GLOBALS["ENGINE_ROOT"]; // Todo, make this uniform

$GLOBALS["active_profile"]=md5("The Narrator");
$GLOBALS["CURRENT_CONNECTOR"]=DMgetCurrentModel();
$GLOBALS["DIALECTIC_NO_EXAMPLES"]=true; // When no assistant entry in history, will try ti provide a bogus example.

$connector=new LLMConnector();
$currentConnectorData = $connector->getById(intval($GLOBALS["CORE_CONNECTOR_DIRECTOR"] ?? 0));
$connectionHandler = $connector->getConnector($currentConnectorData);

$GLOBALS["DIALECTIC_CORE_CURRENT_CONNECTOR_DATA"]=$currentConnectorData;
$GLOBALS["CURRENT_CONNECTOR"]=$currentConnectorData["driver"];

$connector->setOldGlobals($currentConnectorData);

$isBoredInstruction = (($GLOBALS["argv"][4] ?? "") === "bored");
$boredSeedActor = trim((string)($GLOBALS["argv"][5] ?? ""));
$boredActors = json_decode((string)($GLOBALS["argv"][6] ?? "[]"), true);
$boredActors = is_array($boredActors) ? $boredActors : [];
$boredActorMap = dialecticRolemasterBoredActorMap(
    $boredActors,
    (string)($GLOBALS["PLAYER_NAME"] ?? ''),
    $boredSeedActor
);
$GLOBALS["ROLEMASTER_BORED_MODE"] = $isBoredInstruction;
$GLOBALS["ROLEMASTER_BORED_SEED"] = $boredSeedActor;
$GLOBALS["ROLEMASTER_BORED_ALLOWED_ACTORS"] = $boredActorMap;
if ($isBoredInstruction) {
    $GLOBALS["DIALECTIC_ROLEMASTER_BORED_ACTORS"] = $boredActorMap;
}

if (!isset($GLOBALS["DIALECTIC_CORE_CURRENT_CONNECTOR_DATA"]) ) {
        logMsg("Choose a LLM model and connector. Used connector: '{$GLOBALS["CORE_CONNECTOR_DIRECTOR"]}'",S_LOG_CRITICAL);

    } else {
        logMsg("Using {$GLOBALS["CURRENT_CONNECTOR"]}");
    
        $sqlfilter=" and type not in ('prechat') ";

        $historyActor = ($isBoredInstruction && $boredSeedActor !== "") ? $boredSeedActor : "";
        $contextDataHistoric = DataLastDataExpandedFor($historyActor, -50);    // Full context
        
        foreach ($contextDataHistoric as $element) {
            // We should clean here background events entries
        }
        
        $contextDataHistoric =array_merge([["role"=>"user","content"=>"# HISTORIC DIALOGUE AND EVENTS IN CHRONOLOGICAL ORDER"]], $contextDataHistoric);

        $GLOBALS["PROMPT_NEARBY_SECTIONS"] = "";
        $contextDataWorld = DataLastInfoFor("", -2,$includeActorDescriptions=true,$excludeBusy=true)??[];
        $nearbySceneContext = trim((string)($GLOBALS["PROMPT_NEARBY_SECTIONS"] ?? ""));
        $contextDataFull = array_merge($contextDataWorld, $contextDataHistoric);
        $historyData="";

            
        foreach ($contextDataFull as $element) {
        
            $historyData.=trim("{$element["content"]}").PHP_EOL.PHP_EOL;
            
        }
        if ($nearbySceneContext !== "") {
            $historyData .= $nearbySceneContext . PHP_EOL . PHP_EOL;
        }
        
        $recap=$GLOBALS["db"]->fetchOne("SELECT * FROM rolemaster where type='story_summary' ORDER BY rowid DESC LIMIT 1");
        if (isset($recap["data"])) {
            $historyData=$recap["data"]."\n".$historyData;

        }

        if ($isBoredInstruction) {
            $historyData .= "# BORED EVENT SCENE\n";
            $historyData .= "Selected initiating actor: "
                . ($boredSeedActor !== "" ? $boredSeedActor : "not provided") . "\n";
            $historyData .= "Nearby eligible actors: "
                . implode(", ", array_values($boredActorMap)) . "\n\n";
        }

        require_once $enginePath . "lib/relationship_runtime.php";
        if (dialecticRelationshipSettingEnabled()) {
            // Get nearby NPCs from the same spatially scoped source used by normal turns.
            $nearbyNpcsRaw = DataBeingsInCloseRange();
            $nearbyNpcsList = array_filter(array_map('trim', explode('|', $nearbyNpcsRaw)));
            $relContext = RelationshipManager::buildDirectorContext($nearbyNpcsList);
            if (!empty($relContext)) {
                $historyData .= "\n" . $relContext . "\n";
            }
        }

        // Function stuff
        require($enginePath . "functions/functions_instruction.php");

        if (isset($GLOBALS["BASE_FUNCTIONS"]["ReturnBackHome"]) &&
            (!function_exists('dialecticActionCatalogIsActionEnabled') || dialecticActionCatalogIsActionEnabled("ReturnBackHome"))) {
            $GLOBALS["ENABLED_FUNCTIONS"][]="ReturnBackHome";
            $GLOBALS["FUNCTIONS"][]=$GLOBALS["BASE_FUNCTIONS"]["ReturnBackHome"];
        }

        $fnames=[];
        foreach ($GLOBALS["F_NAMES"] as $functionCode=>$functionName) {
            if (in_array($functionCode,$GLOBALS["ENABLED_FUNCTIONS"])) {
                if ($functionCode!="OpenInventory") {
                    $function=findFunctionByName($functionName);
                    if ($function) {
                        $fnames[]=$GLOBALS["F_NAMES"]["$functionCode"]." ({$function["description"]})";
                        
                    } else 
                        $fnames[]=$GLOBALS["F_NAMES"]["$functionCode"];
                    $GLOBALS["FUNCTION_SHORT_LIST"][]=$GLOBALS["F_NAMES"]["$functionCode"];
                }
            }
        }

        if (!$isBoredInstruction) {
            require_once $enginePath . 'lib/director_scene.php';
            dialecticGenerateDirectorScene($connectionHandler, (string)($GLOBALS['argv'][3] ?? ''), $historyData);
            return;
        }

// Load director examples prompt from database with fallback
$commonprompt = null;
try {
    $promptData = $GLOBALS["db"]->fetchOne("SELECT custom_prompt, default_prompt FROM prompts WHERE prompt_key = 'director_examples_prompt'");
    if ($promptData) {
        $commonprompt = (!empty($promptData['custom_prompt'])) ? $promptData['custom_prompt'] : $promptData['default_prompt'];
    }
} catch (Exception $e) {
    Logger::warn("Failed to load director_examples_prompt from database, using hardcoded fallback: " . $e->getMessage());
}

// Hardcoded fallback if database query failed
if (!$commonprompt) {
    $commonprompt='
# Examples

user request: actor \"a\" leaves the place 
{"instructions":[{
  "character": "actor a",
  "instruction": "actor a should say goodbye to everyone, hinting that they may not return for a long time",
  "action": "ExitLocation",
  "target": "everyone",
  "scene_note": "The mood is somber as actor a prepares to leave. Actor b watches in silence, perhaps with regret or longing."
},
{
  "character": "actor b",
  "instruction": "actor b should say goodbye to b",
  "action": "JustTalk",
  "target": "Actor a",
  "scene_note": "Is a sad moment, generally speaking."
}
]
}

(no user request, randomly generated content)
{"instructions":[
 {
  "character": "actor a",
  "instruction": "actor a should ask actor b for a few coins, claiming they desperately need a drink.",
  "action": "Talk",
  "target": "actor b",
  "scene_note": "actor a looks disheveled but charming, half-joking and half-serious. Actor b is unsure whether to laugh, help, or walk away. Other actors watch this two guys with curiosity"
 }
]
}

';
}
        if (!$GLOBALS["argv"][3]) {
            $userprompt="";
            $sysprompt="";
        } else {
            $sysprompt="user request: \"{$GLOBALS["argv"][3]}\". (As game director, you now must issue an instruction to any actor to make this wish happen. If user is addressing an actor, use instruction on it)"; 
            $userprompt="* Fufill the user's request no matter what, even if it means breaking roleplay.";
        }
        
        // Load director system prompt from database with fallback
        $directorSystemPrompt = null;
        try {
            $promptData = $GLOBALS["db"]->fetchOne("SELECT custom_prompt, default_prompt FROM prompts WHERE prompt_key = 'director_system_prompt'");
            if ($promptData) {
                $directorSystemPrompt = (!empty($promptData['custom_prompt'])) ? $promptData['custom_prompt'] : $promptData['default_prompt'];
            }
        } catch (Exception $e) {
            Logger::warn("Failed to load director_system_prompt from database, using hardcoded fallback: " . $e->getMessage());
        }
        
        if (!$directorSystemPrompt) {
            $directorSystemPrompt = "You are a game director, and we are roleplaying Fallout in the wasteland. You must create an instruction for an actor to generate new content/events in game.";
        }
        
        $prompt[] = array('role' => 'system', 'content' => "$directorSystemPrompt$commonprompt");
        $prompt[] = array('role' => 'user', 'content' => "# Contextual data\n$historyData");
        
        // Load director instruction rules from database with fallback
        $directorInstructionRules = null;
        try {
            $promptData = $GLOBALS["db"]->fetchOne("SELECT custom_prompt, default_prompt FROM prompts WHERE prompt_key = 'director_instruction_rules'");
            if ($promptData) {
                $directorInstructionRules = (!empty($promptData['custom_prompt'])) ? $promptData['custom_prompt'] : $promptData['default_prompt'];
            }
        } catch (Exception $e) {
            Logger::warn("Failed to load director_instruction_rules from database, using hardcoded fallback: " . $e->getMessage());
        }
        
        if (!$directorInstructionRules) {
            $directorInstructionRules = "Just provide instructions! You can also provide more than one instruction, but one per actor (keep limit at  2 or 3 max actors)\nIn addition, follow these general scene rules as a game director:\n * Use any actor in NEARBY ACTORS/NPC IN THE SCENE list ({PLAYER_NAME},busy actors and far away actors are EXCLUDED!)\n * Continue the scene as naturally and fully as possible, unless the user explicitly requests a new one. You can specify actions to reinforce the actors' dialogue.\n * If there are more actors in the room, try to involve them in the conversation.\n * When dialogue becomes repetitive, make a plot twist.\n * If a character reuses the same argument too often, nudge the scene towards a new topic.\n * Occasionally introduce subtle foreshadowing or hint at future events, dangers, or quests.\n * Do not resolve everything neatly - keep room for ongoing tension or future continuation.\n * You must always provide dialogue instructions for the character, as every request requires a dialogue response.\n * Here are a list of actions that can be used: \n{FUNCTION_LIST}\n  ** JustTalk \n * Add a Scene Note: A brief description of the topic, mood, or idea introduced by the instruction. Should serve to guide the desired instruction to become reality. Other actors can see this to properly react.\n * If scene is getting boring/repetitive, add a plot twist";
        }
        
        // Replace placeholders
        $functionList = "  ** " . implode("\n  ** ", $fnames);
        $directorInstructionRules = str_replace(
            ['{PLAYER_NAME}', '{FUNCTION_LIST}'],
            [$GLOBALS["PLAYER_NAME"], $functionList],
            $directorInstructionRules
        );
        if ($isBoredInstruction) {
            $directorInstructionRules .= "\n\n# Bored event rules";
            if ($boredSeedActor !== "") {
                $directorInstructionRules .= "\n* The first instruction must use the selected initiating actor: {$boredSeedActor}.";
            }
            $directorInstructionRules .= "\n* Only use speakers from the Nearby eligible actors list."
                . "\n* Do not invent distant or off-scene actors."
                . "\n* Do not target or comment on {$GLOBALS["PLAYER_NAME"]} merely because time passed or the player is idle."
                . "\n* Prefer a natural NPC-to-NPC interaction or scene action. Involve the player only when recent player activity clearly requires a response."
                . "\n* When an instruction targets another nearby actor, direct the dialogue to that actor.";
        }
        
        // Database Prompt (Director)
        $prompt[] = array('role' => 'user', 'content' => "$sysprompt\n$directorInstructionRules\n$userprompt");
        
        
        
        $customParm["response_format"]=["type"=>"json_object"];
        

        $customParm["MAX_TOKENS"]=4000;
        
        $GLOBALS["HOOKS"]["JSON_TEMPLATE"][]=function() {
            $GLOBALS["responseTemplate"] = ["instructions"=>[[
                "character"=>"selected actor's full name",
                "instruction"=>"the instruction for the actor, what should be said or done. Use 3rd person here.",
                "action"=>implode("|",$GLOBALS["FUNCTION_SHORT_LIST"]),
                "target"=>"action's target",
                "scene_note"=>"Something other actors should know about the instruction, if the instruction also involves another actors"
            ]]];

            
        };

        
        // Force unset json schema
        $GLOBALS["CONNECTOR"][$GLOBALS["CURRENT_CONNECTOR"]]["json_schema"]=false;

        $connectionHandler->open($prompt,$customParm);
        

        $buffer="";
        $totalBuffer="";
        $breakFlag=false;
        
        while (true) {

            if ($breakFlag) {
                break;
            }

            $buffer=$connectionHandler->process();
            $totalBuffer.=$buffer;

            if ($connectionHandler->isDone()) {
                $breakFlag=true;
            }
            
        }
        
        $rawbuffer=$connectionHandler->close("instruction");
        
        function parseInstruction($response) {
            // Extract the character name and the instruction line
            
            $characterName = trim($response["character"] ?? 'Unknown');
            $instructionText = trim($response["instruction"] ?? 'No instruction text');
            $action = !empty($response["action"]) ? "{$response["action"]} " . ($response["target"] ?? "") : "";
            if (!empty($GLOBALS["ROLEMASTER_BORED_MODE"])) {
                $instructionText .= dialecticRolemasterBoredListenerRequirement(
                    (string)($response["target"] ?? ""),
                    $GLOBALS["ROLEMASTER_BORED_ALLOWED_ACTORS"] ?? []
                );
            }
        
            if (!$characterName || !$instructionText) {
                return false;
            }

            // Generate unique task ID
            $taskId = uniqid();
        
            dialecticQueueCommandResponse(
                "rolemaster",
                "Instruction",
                [
                    "character" => make_replacements($characterName),
                    "instruction" => make_replacements("{$instructionText} (must use ACTION $action)"),
                    "task_id" => $taskId,
                    "target" => trim((string)($response["target"] ?? '')),
                ]
            );

            return true;
        }

        function parseSceneNote($response) {
            // Extract scene note after "Scene Note:"
            $characterName = trim($response["character"] ?? 'Unknown');
            $noteContent = trim($response["scene_note"] ?? 'No instruction text');
            
        
            // Generate unique task ID
            $taskId = uniqid();
        
            // Format action string
            $action = make_replacements("$noteContent");
        
            // Insert into database
            $GLOBALS["db"]->insert(
                'rolemaster',
                array(
                    'localts' => time(),
                    'ttl' => 300,
                    'type' => "scenenote",
                    'data' => $action
                )
            );
        }
        
        

        
        
        $rawbuffer.=PHP_EOL;
        unset($GLOBALS["_JSON_BUFFER"]);
        $response=__jpd_decode_lazy($rawbuffer);
        
        
        if (isset($response[0]["instructions"]))
            $response=$response[0];

        if (isset($response["instructions"]) && is_array($response["instructions"])) {
            if ($isBoredInstruction) {
                $originalInstructionCount = count($response["instructions"]);
                $response["instructions"] = dialecticRolemasterFilterBoredInstructions(
                    $response["instructions"],
                    $GLOBALS["ROLEMASTER_BORED_ALLOWED_ACTORS"] ?? [],
                    $boredSeedActor
                );
                if (empty($response["instructions"])) {
                    Logger::warn("Discarded bored rolemaster response because it omitted the selected actor or used no eligible nearby actors");
                } elseif (count($response["instructions"]) !== $originalInstructionCount) {
                    Logger::warn("Removed invalid or off-scene actors from bored rolemaster response");
                }
            }
            $allOk=!empty($response["instructions"]);
            foreach ($response["instructions"] as $r) {
                $allOk=$allOk && parseInstruction($r);
                parseSceneNote($r);
            }
        } else 
            $allOk=false;

        
        if (isset($GLOBALS["argv"][4]) && $GLOBALS["argv"][4]=="notify") {
            $pluginVersionRow = $GLOBALS['db']->fetchOne("SELECT value FROM conf_opts WHERE id='plugin_dll_version'");
            if ($pluginVersionRow && isset($pluginVersionRow['value'])) {
                if ($allOk)
                    dialecticQueueCommandResponse(
                        "rolemaster",
                        "DebugNotification",
                        ["message" => "Director mode instruction processed"]
                    );
                else 
                    dialecticQueueCommandResponse(
                        "rolemaster",
                        "DebugNotification",
                        ["message" => "Director mode instruction failed"]
                    );
            }
        }
        
        //print_r($response);
        
        
    }


    Logger::info("Successfully logged instruction command to responselog");
?>

