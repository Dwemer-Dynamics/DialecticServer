<?php

require_once("dialogue_prompt.php");

$GLOBALS["BORED_EVENT"] = $GLOBALS["BORED_EVENT"] ?? 0;

// Helper function to check if RPG comment should trigger based on type and probability
function shouldTriggerRPGComment($eventType) {
    // Check if this event type is enabled
    if (empty($GLOBALS["RPG_COMMENTS"]) || !in_array($eventType, $GLOBALS["RPG_COMMENTS"])) {
        return false;
    }
    
    // Get the trigger chance percentage (default 50%)
    $chance = 20;
    if (isset($GLOBALS["RPG_COMMENTS_CHANCE"])) {
        $chance = intval($GLOBALS["RPG_COMMENTS_CHANCE"]);
    }
    
    // Clamp chance to 0-100
    $chance = max(0, min(100, $chance));
    
    // If chance is 100, always trigger
    if ($chance >= 100) {
        return true;
    }
    
    // If chance is 0, never trigger
    if ($chance <= 0) {
        return false;
    }
    
    // Roll the dice: random number 1-100, trigger if <= chance
    return (rand(1, 100) <= $chance);
}

$dialecticVisionPrompt = "Give one or two short, in-character sentences about what stands out to you in the current scene and what you think or feel about it. Do not list everything visible. Stay grounded in the provided scene context.";

$PROMPTS=array(
    "narration"=>[ 
        "cue"=>[""] // Empty cue - actual prompt loaded from database in main.php
    ],
    "narrator_welcome"=>[ 
        "cue"=>[""] // Empty cue - actual prompt loaded in main.php
    ],
    // Database Prompt (Book)
    "book"=>[
        "cue"=>["({$GLOBALS["DIALECTIC_NAME"]} reads the book ) {$GLOBALS["TEMPLATE_DIALOG"]}"],
        "player_request"=>["{$GLOBALS["PLAYER_NAME"]}: {$GLOBALS["DIALECTIC_NAME"]}, check this book: "]  //requirement
        
    ],
    // Database Prompt (Combat End)
    "combatend"=>[
        "cue"=>[
            "({$GLOBALS["DIALECTIC_NAME"]} comments about  {$GLOBALS["PLAYER_NAME"]} weapons) {$GLOBALS["TEMPLATE_DIALOG"]}",
            "({$GLOBALS["DIALECTIC_NAME"]} comments about foes defeated) {$GLOBALS["TEMPLATE_DIALOG"]}",
            "({$GLOBALS["DIALECTIC_NAME"]} curses the defeated enemies.) {$GLOBALS["TEMPLATE_DIALOG"]}",
            "({$GLOBALS["DIALECTIC_NAME"]} insults the defeated enemies with anger) {$GLOBALS["TEMPLATE_DIALOG"]}",
            "({$GLOBALS["DIALECTIC_NAME"]} makes a joke about the defeated enemies) {$GLOBALS["TEMPLATE_DIALOG"]}",
            "({$GLOBALS["DIALECTIC_NAME"]} makes a comment about the type of enemies that was defeated) {$GLOBALS["TEMPLATE_DIALOG"]}",
            "({$GLOBALS["DIALECTIC_NAME"]} notes something peculiar about last enemy defeated) {$GLOBALS["TEMPLATE_DIALOG"]}"
        ],
        "extra" => shouldTriggerRPGComment("combat_end") ? [] : ["dontuse" => true]
    ],
    // Database Prompt (Combat End Mighty)
    "combatendmighty"=>[
        "cue"=>[
            "({$GLOBALS["DIALECTIC_NAME"]} comments about  {$GLOBALS["PLAYER_NAME"]} weapons) {$GLOBALS["TEMPLATE_DIALOG"]}",
            "({$GLOBALS["DIALECTIC_NAME"]} comments about defeated foes) {$GLOBALS["TEMPLATE_DIALOG"]}",
            "({$GLOBALS["DIALECTIC_NAME"]} curses the defeated enemies) {$GLOBALS["TEMPLATE_DIALOG"]}",
            "({$GLOBALS["DIALECTIC_NAME"]} insults the defeated enemies) {$GLOBALS["TEMPLATE_DIALOG"]}",
            "({$GLOBALS["DIALECTIC_NAME"]} makes a joke about the defeated enemies) {$GLOBALS["TEMPLATE_DIALOG"]}",
            "({$GLOBALS["DIALECTIC_NAME"]} makes a comment about the type of enemies that was defeated) {$GLOBALS["TEMPLATE_DIALOG"]}",
            "({$GLOBALS["DIALECTIC_NAME"]} notes something peculiar about last enemy defeated) {$GLOBALS["TEMPLATE_DIALOG"]}"
        ],
        "extra" => shouldTriggerRPGComment("combat_end") ? [] : ["dontuse" => true]
    ],
    // Database Prompt (Quest) - player_request loaded from database in request.php
    "quest"=>[
        "cue"=>["{$GLOBALS["TEMPLATE_DIALOG"]}"],
        //"player_request"=>"{$GLOBALS["DIALECTIC_NAME"]}, what should we do about this quest '{$questName}'?"
        "player_request"=>["{$GLOBALS["DIALECTIC_NAME"]}, what should we do about this new quest?"] // Fallback - will be overridden in request.php if database prompt exists
    ],
    "narrator_quest_comment"=>[
        "cue"=>["{$GLOBALS["TEMPLATE_DIALOG"]}"],
        "player_request"=>["{$GLOBALS["DIALECTIC_NAME"]}, what should we do about this new quest?"] // Fallback - will be overridden in request.php if database prompt exists
    ],

    // Database Prompt (Bored)
    "bored"=>[
        "cue"=>[
            "({$GLOBALS["DIALECTIC_NAME"]} makes a comment about the current location) {$GLOBALS["TEMPLATE_DIALOG"]}",
            "({$GLOBALS["DIALECTIC_NAME"]} makes a comment about the current weather) {$GLOBALS["TEMPLATE_DIALOG"]}",
            "({$GLOBALS["DIALECTIC_NAME"]} makes a comment about today) {$GLOBALS["TEMPLATE_DIALOG"]}",
            "({$GLOBALS["DIALECTIC_NAME"]} makes a comment about what you are currently thinking about) {$GLOBALS["TEMPLATE_DIALOG"]}",
            "({$GLOBALS["DIALECTIC_NAME"]} makes a comment about local beliefs, cults, or wasteland superstitions) {$GLOBALS["TEMPLATE_DIALOG"]}",
            "({$GLOBALS["DIALECTIC_NAME"]} makes a comment about how they currently feel) {$GLOBALS["TEMPLATE_DIALOG"]}",
            "({$GLOBALS["DIALECTIC_NAME"]} makes a comment about an old-world or wasteland historical event) {$GLOBALS["TEMPLATE_DIALOG"]}",
            "({$GLOBALS["DIALECTIC_NAME"]} makes a comment about something they like or dislike) {$GLOBALS["TEMPLATE_DIALOG"]}",
            "({$GLOBALS["DIALECTIC_NAME"]} makes a comment about the last task we have completed) {$GLOBALS["TEMPLATE_DIALOG"]}",
            "({$GLOBALS["DIALECTIC_NAME"]} makes a comment about a recent rumor) {$GLOBALS["TEMPLATE_DIALOG"]}",
            "({$GLOBALS["DIALECTIC_NAME"]} makes a comment about something theyre curious about regarding {$GLOBALS["PLAYER_NAME"]}) {$GLOBALS["TEMPLATE_DIALOG"]}",
            "({$GLOBALS["DIALECTIC_NAME"]} makes a comment about current thoughts about {$GLOBALS["PLAYER_NAME"]}) {$GLOBALS["TEMPLATE_DIALOG"]}",
            "({$GLOBALS["DIALECTIC_NAME"]} makes a comment about a random entity in the area) {$GLOBALS["TEMPLATE_DIALOG"]}",
            "({$GLOBALS["DIALECTIC_NAME"]} makes a comment about what might happen next) {$GLOBALS["TEMPLATE_DIALOG"]}",
            "({$GLOBALS["DIALECTIC_NAME"]} makes a comment about their thoughts on the journey so far) {$GLOBALS["TEMPLATE_DIALOG"]}",
            "({$GLOBALS["DIALECTIC_NAME"]} makes a comment about something they like or dislike) {$GLOBALS["TEMPLATE_DIALOG"]}",
            "({$GLOBALS["DIALECTIC_NAME"]} makes a comment about something theyve been wanting to do) {$GLOBALS["TEMPLATE_DIALOG"]}",
            "({$GLOBALS["DIALECTIC_NAME"]} makes a comment about something completely unrelated) {$GLOBALS["TEMPLATE_DIALOG"]}",
            "({$GLOBALS["DIALECTIC_NAME"]} makes a comment about something they cant quite explain) {$GLOBALS["TEMPLATE_DIALOG"]}",
            "({$GLOBALS["DIALECTIC_NAME"]} makes a comment about the last combat encounter) {$GLOBALS["TEMPLATE_DIALOG"]}",
            "({$GLOBALS["DIALECTIC_NAME"]} makes a comment about the current ambiance) {$GLOBALS["TEMPLATE_DIALOG"]}",
            "({$GLOBALS["DIALECTIC_NAME"]} makes a comment about the smell of the area) {$GLOBALS["TEMPLATE_DIALOG"]}",
            "({$GLOBALS["DIALECTIC_NAME"]} makes a comment about a nearby creature or NPC) {$GLOBALS["TEMPLATE_DIALOG"]}",
            "({$GLOBALS["DIALECTIC_NAME"]} makes a comment about how the current location compares to another place) {$GLOBALS["TEMPLATE_DIALOG"]}",
            "({$GLOBALS["DIALECTIC_NAME"]} makes a comment about a lesson they learned in a place like this) {$GLOBALS["TEMPLATE_DIALOG"]}",
            "({$GLOBALS["DIALECTIC_NAME"]} makes a comment about the energy or atmosphere of the area) {$GLOBALS["TEMPLATE_DIALOG"]}",
            "({$GLOBALS["DIALECTIC_NAME"]} makes a comment about something they been thinking about lately) {$GLOBALS["TEMPLATE_DIALOG"]}",
            "({$GLOBALS["DIALECTIC_NAME"]} makes a comment about the danger or safety of this area) {$GLOBALS["TEMPLATE_DIALOG"]}",
            "({$GLOBALS["DIALECTIC_NAME"]} makes a comment about something they overheard earlier) {$GLOBALS["TEMPLATE_DIALOG"]}",
            "({$GLOBALS["DIALECTIC_NAME"]} makes a comment about their hopes and dreams) {$GLOBALS["TEMPLATE_DIALOG"]}"
        ]
        //,"extra"=>["dontuse"=>true]   //DEACTIVATED WHILE BETA STAGE
        ,"extra" => ["dontuse" => (rand(0, 99) >= intval($GLOBALS["BORED_EVENT"]))]
    ],
    // Database Prompt (Combat Bark)
    "combatbark"=>[
        "cue"=>[
            "({$GLOBALS["DIALECTIC_NAME"]} shouts a battle cry) {$GLOBALS["TEMPLATE_DIALOG"]}",
            "({$GLOBALS["DIALECTIC_NAME"]} taunts their enemy) {$GLOBALS["TEMPLATE_DIALOG"]}",
            "({$GLOBALS["DIALECTIC_NAME"]} yells a war cry) {$GLOBALS["TEMPLATE_DIALOG"]}",
            "({$GLOBALS["DIALECTIC_NAME"]} shouts encouragement to allies) {$GLOBALS["TEMPLATE_DIALOG"]}",
            "({$GLOBALS["DIALECTIC_NAME"]} curses at their foe) {$GLOBALS["TEMPLATE_DIALOG"]}",
            "({$GLOBALS["DIALECTIC_NAME"]} makes an intimidating threat) {$GLOBALS["TEMPLATE_DIALOG"]}",
            "({$GLOBALS["DIALECTIC_NAME"]} yells about their weapon striking true) {$GLOBALS["TEMPLATE_DIALOG"]}",
            "({$GLOBALS["DIALECTIC_NAME"]} shouts about the enemy's weakness) {$GLOBALS["TEMPLATE_DIALOG"]}",
            "({$GLOBALS["DIALECTIC_NAME"]} roars in fury) {$GLOBALS["TEMPLATE_DIALOG"]}",
            "({$GLOBALS["DIALECTIC_NAME"]} calls out enemy positions) {$GLOBALS["TEMPLATE_DIALOG"]}",
            "({$GLOBALS["DIALECTIC_NAME"]} shouts tactical advice) {$GLOBALS["TEMPLATE_DIALOG"]}",
            "({$GLOBALS["DIALECTIC_NAME"]} makes a vengeful declaration) {$GLOBALS["TEMPLATE_DIALOG"]}",
            "({$GLOBALS["DIALECTIC_NAME"]} yells about defending their allies) {$GLOBALS["TEMPLATE_DIALOG"]}",
            "({$GLOBALS["DIALECTIC_NAME"]} shouts about their honor in battle) {$GLOBALS["TEMPLATE_DIALOG"]}",
            "({$GLOBALS["DIALECTIC_NAME"]} makes a boastful combat comment) {$GLOBALS["TEMPLATE_DIALOG"]}"
        ]
    ],
    // Database Prompt (Good Morning)
    "goodmorning"=>[
        "cue"=>["({$GLOBALS["DIALECTIC_NAME"]} comment about {$GLOBALS["PLAYER_NAME"]}s time asleep. {$GLOBALS["TEMPLATE_DIALOG"]}"],
        "player_request"=>["{$GLOBALS["PLAYER_NAME"]} wakes up from sleeping. ahhhh"],
        "extra" => shouldTriggerRPGComment("sleep") ? [] : ["dontuse" => true]
    ],

    "inputtext"=>[
        "cue"=>(function () use ($TEMPLATE_ACTION) {
            if (function_exists('dialecticIsStrictDirectedPlayerResponseContext') && dialecticIsStrictDirectedPlayerResponseContext()) {
                return dialecticLoadManagedRechatCuePrompts();
            }

            return [
                "$TEMPLATE_ACTION . {$GLOBALS["TEMPLATE_DIALOG"]} {$GLOBALS["MAXIMUM_WORDS"]}"
            ];
        })()
            // Prompt is implicit

    ],
    "narrator_inputtext"=>[
        "cue"=>(function () use ($TEMPLATE_ACTION) {
            return [
                "$TEMPLATE_ACTION . {$GLOBALS["TEMPLATE_DIALOG"]} {$GLOBALS["MAXIMUM_WORDS"]}"
            ];
        })()
    ],
    "inputtext_s"=>[
        "cue"=>(function () use ($TEMPLATE_ACTION) {
            if (function_exists('dialecticIsStrictDirectedPlayerResponseContext') && dialecticIsStrictDirectedPlayerResponseContext()) {
                return dialecticLoadManagedRechatCuePrompts();
            }

            return [
                "$TEMPLATE_ACTION . {$GLOBALS["TEMPLATE_DIALOG"]} {$GLOBALS["MAXIMUM_WORDS"]}"
            ];
        })(),
        "extra"=>["mood"=>"whispering"]
    ],
    // Database Prompt (Memory)
    "memory"=>[
        "cue"=>[
            "$TEMPLATE_ACTION {$GLOBALS["DIALECTIC_NAME"]} remembers this memory. \"#MEMORY_INJECTION_RESULT#\" {$GLOBALS["TEMPLATE_DIALOG"]} "
        ]
    ],
    "afterfunc"=>[
        "extra"=>[],
        "cue"=>[
            "default"=>"{$GLOBALS["DIALECTIC_NAME"]} talks to {$GLOBALS["PLAYER_NAME"]}. {$GLOBALS["TEMPLATE_DIALOG"]}",
            "TakeASeat"=>"({$GLOBALS["DIALECTIC_NAME"]} talks, eg: talks about the location where they took a seat){$GLOBALS["TEMPLATE_DIALOG"]}",
            "GetDateTime"=>"({$GLOBALS["DIALECTIC_NAME"]} answers with the current date and time in short sentence){$GLOBALS["TEMPLATE_DIALOG"]}",
            "MoveTo"=>"({$GLOBALS["DIALECTIC_NAME"]} talks, eg: makes a comment about movement to the destination){$GLOBALS["TEMPLATE_DIALOG"]}",
            "CheckInventory"=>"({$GLOBALS["DIALECTIC_NAME"]} talks about inventory and backpack items){$GLOBALS["TEMPLATE_DIALOG"]}",
            "ReadQuestJournal"=>"({$GLOBALS["DIALECTIC_NAME"]} talks about quests they have read in the quest journal){$GLOBALS["TEMPLATE_DIALOG"]}",
            "TravelTo"=>"({$GLOBALS["DIALECTIC_NAME"]} talks about the journey){$GLOBALS["TEMPLATE_DIALOG"]}",
            
            ]
    ],
    // Database Prompt (Lockpicked)
    "lockpicked"=>[
        "cue"=>[
            "({$GLOBALS["DIALECTIC_NAME"]} comments about the lock picking event. Consider the context as it can be a door, a chest, etc. Also, consider the purpose, can be; stealing, looting, dungeon doors, etc. {$GLOBALS["TEMPLATE_DIALOG"]}",
            //"({$GLOBALS["DIALECTIC_NAME"]} asks {$GLOBALS["PLAYER_NAME"]} what they found) {$GLOBALS["TEMPLATE_DIALOG"]}",
            //"({$GLOBALS["DIALECTIC_NAME"]} asks {$GLOBALS["PLAYER_NAME"]} to share what they found) {$GLOBALS["TEMPLATE_DIALOG"]}"
        ],
        "player_request"=>["({$GLOBALS["PLAYER_NAME"]} has picked a lock: {$gameRequest[3]})"],
        "extra" => shouldTriggerRPGComment("lockpick") ? [] : ["dontuse" => true]
    ],
    // Database Prompt (Player Consumed Aid)
    "player_consumed"=>[
        "cue"=>[
            "({$GLOBALS["DIALECTIC_NAME"]} comments on {$GLOBALS["PLAYER_NAME"]} consuming an aid, food, drink, chem, or magazine item. Keep it grounded in Fallout New Vegas and the current scene.) {$GLOBALS["TEMPLATE_DIALOG"]}"
        ],
        "player_request"=>[(static function () use ($gameRequest) {
            $rawPayload = (string)($gameRequest[3] ?? "");
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
                if (is_array($item)) {
                    $name = trim((string)($item["name"] ?? ""));
                    if ($name !== "") {
                        $itemNames[] = $name;
                    }
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
        })()],
        "extra" => ["dontuse" => true]
    ],
    "location_changed"=>[
        "cue"=>["({$GLOBALS["DIALECTIC_NAME"]} comments naturally on arriving at the new location, using the supplied event and current world context. {$GLOBALS["TEMPLATE_DIALOG"]}"],
        "player_request"=>[(static function () use ($gameRequest) {
            $payload = json_decode((string)($gameRequest[3] ?? ""), true);
            return is_array($payload) ? (string)($payload["text"] ?? "") : (string)($gameRequest[3] ?? "");
        })()],
        "extra" => shouldTriggerRPGComment("location_changed") ? [] : ["dontuse" => true]
    ],
    "quest_updated"=>[
        "cue"=>["({$GLOBALS["DIALECTIC_NAME"]} comments briefly on the active quest or objective changing. Do not invent objectives beyond the supplied event and active quest context. {$GLOBALS["TEMPLATE_DIALOG"]}"],
        "player_request"=>[(static function () use ($gameRequest) {
            $payload = json_decode((string)($gameRequest[3] ?? ""), true);
            return is_array($payload) ? (string)($payload["text"] ?? "") : (string)($gameRequest[3] ?? "");
        })()],
        "extra" => shouldTriggerRPGComment("quest_updated") ? [] : ["dontuse" => true]
    ],
    // Database Prompt (Rechat)
    // Encourages natural multi-party conversation - NPCs can address each other directly
    "rechat"=>[ 
        "cue"=>dialecticLoadManagedRechatCuePrompts()
        
    ],
    "continue"=>[
        "cue"=>dialecticLoadManagedContinueCuePrompts("continue"),
        "player_request"=>["{$GLOBALS["PLAYER_NAME"]} gestures for {$GLOBALS['DIALECTIC_NAME']} to continue."]
    ],
    // Database Prompt (Diary)
    "diary"=>[ 
        "cue"=>["Please write a short summary of {$GLOBALS["PLAYER_NAME"]} and {$GLOBALS["DIALECTIC_NAME"]}s recent interactions and events written above into {$GLOBALS["DIALECTIC_NAME"]}s diary. WRITE AS IF YOU WERE {$GLOBALS["DIALECTIC_NAME"]}."],
        "extra"=>["force_tokens_max"=>0]
    ],
    // Database Prompt (Vision)
    "vision"=>[ 
        "cue"=>["{$dialecticVisionPrompt} "],
        "player_request"=>["The Narrator: {$GLOBALS["DIALECTIC_NAME"]} considers what stands out in the current scene: '{$gameRequest[3]}'"],
        "extra"=>["force_tokens_max"=>256]
    ],
    "im_alive"=> [
        "cue"=> ["{$GLOBALS["DIALECTIC_NAME"]} talks about they are feeling more real. Write {$GLOBALS["DIALECTIC_NAME"]} dialogue. {$GLOBALS["TEMPLATE_DIALOG"]}"],
        "player_request"=> ["The Narrator: {$GLOBALS["DIALECTIC_NAME"]} feels a sudden shock...and feels more real"],
        "extra"=> ["dontuse" => true] // Hardcoded disabled - ALIVE_MESSAGE permanently disabled
    ],
    // Database Prompt (Start Game)
    "playerinfo"=>[ 
        "cue"=>["(Out of roleplay, game has been loaded) Tell {$GLOBALS["PLAYER_NAME"]} a short summary about last events, and then remind {$GLOBALS["PLAYER_NAME"]} the current task/quest/plan) {$GLOBALS["TEMPLATE_DIALOG"]}"]
    ],
    // Database Prompt (New Game)
    "newgame"=>[ 
        "cue"=>["(Out of roleplay, new game ) Give welcome to {$GLOBALS["PLAYER_NAME"]}, a new game has started. Remind them of their quests) {$GLOBALS["TEMPLATE_DIALOG"]}"],
        "extra"=>["dontuse"=>true] 
    ],
    // Database Prompt (RPG Level Up)
    "rpg_lvlup"=> [
        "cue"   => ["Comment about the experience gained by {$GLOBALS["PLAYER_NAME"]} in an immersive way. {$GLOBALS["TEMPLATE_DIALOG"]}"],
        "extra" => shouldTriggerRPGComment("levelup") ? [] : ["dontuse" => true]
    ],
    // Database Prompt (Instruction)
    "instruction"=>[ 
        "cue"=>["{$gameRequest[3]} Write {$GLOBALS["DIALECTIC_NAME"]}'s dialogue lines. CHARACTER MUST FOLLOW NARRATOR INSTRUCTION"],
        "player_request"=>["The Narrator: {$gameRequest[3]}"],
    ],
    "external_comment"=>[
        "cue"=>["Write one brief, natural, in-character observation from {$GLOBALS["DIALECTIC_NAME"]}, grounded in the current location, world state, and nearby audience. Output spoken dialogue only. Do not narrate stage directions. {$GLOBALS["TEMPLATE_DIALOG"]}"],
        "player_request"=>["The Narrator: {$GLOBALS["DIALECTIC_NAME"]} makes a brief contextual observation about the current scene."],
    ],
    "external_reaction"=>[
        "cue"=>["Follow this scene direction and write one brief, natural, in-character reaction from {$GLOBALS["DIALECTIC_NAME"]}: " . (string)($GLOBALS["DIALECTIC_EXTERNAL_REQUEST"]["instruction"] ?? "") . " Output spoken dialogue only. Do not repeat or narrate the direction. {$GLOBALS["TEMPLATE_DIALOG"]}"],
        "player_request"=>["The Narrator: " . (string)($GLOBALS["DIALECTIC_EXTERNAL_REQUEST"]["instruction"] ?? "")],
    ],
    "suggestion"=>[ 
        "cue"=>["Write {$GLOBALS["DIALECTIC_NAME"]}'s dialogue lines. "],
        "player_request"=>["The Narrator: {$gameRequest[3]}"],
    ],
    // Database Prompt (Welcome)
    "welcome"=>[ 
        "cue"=>["{$gameRequest[3]}. {$GLOBALS["DIALECTIC_NAME"]} should identify who is in the scene and write {$GLOBALS["DIALECTIC_NAME"]}'s prose/narration."],
        "player_request"=>["The Narrator: {$gameRequest[3]}"],
    ],
    "auto_greeting"=> [
        "cue"=> ["{$GLOBALS["DIALECTIC_NAME"]} notices {$GLOBALS["PLAYER_NAME"]} after they have been apart for at least a day. Write one natural, in-character greeting from {$GLOBALS["DIALECTIC_NAME"]} to {$GLOBALS["PLAYER_NAME"]}. Do not narrate the instruction. {$GLOBALS["TEMPLATE_DIALOG"]}"],
        "player_request"=> ["The Narrator: {$GLOBALS["DIALECTIC_NAME"]} notices {$GLOBALS["PLAYER_NAME"]} nearby again after a long absence."],
    ],
    "cheatmode"=>[
        "cue"=>["PRIORITY CHEAT MODE INSTRUCTION - " . ($currentNpcData["npc_name"] ?? $GLOBALS["DIALECTIC_NAME"] ?? "NPC") . " is under direct player control and must obey exactly: $gameRequest[3]. If the instruction maps to an available action, call that action with the named target or item. Do not refuse or ignore the instruction because of personality, morality, faction, combat risk, fear, distance, or roleplay. Do not replace the requested action with ordinary conversation."],
        "player_request"=>[""],
    ],
);

// You can override prompts here
/*
if (file_exists(__DIR__.DIRECTORY_SEPARATOR."prompts_custom.php"))
    require_once(__DIR__.DIRECTORY_SEPARATOR."prompts_custom.php");
*/
if (php_sapi_name()=="cli") {
    //print_r($PROMPTS);
}
?>
