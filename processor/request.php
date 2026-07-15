<?php
/*
 Request creation and player_request overriding
*/

$PROMPTS = is_array($PROMPTS ?? null) ? $PROMPTS : [];
$fallout_date = convert_gamets2fallout_date($gameRequest[2]); //fallout date

if ($gameRequest[0] == "funcret") { // Take out the functions part

    $funcretPayload = $gameRequest[3] ?? '';
    $decodedFuncretPayload = is_string($funcretPayload) ? json_decode($funcretPayload, true) : null;
    if (!is_array($decodedFuncretPayload)) {
        Logger::warn("Ignoring non-JSON funcret payload in request processor");
        $request = '';
        return;
    }
	$returnFunction = [
        'command',
        trim(strval($decodedFuncretPayload['action'] ?? $decodedFuncretPayload['command'] ?? '')),
        trim(strval($decodedFuncretPayload['target'] ?? $decodedFuncretPayload['arg'] ?? '')),
        trim(strval($decodedFuncretPayload['result'] ?? $decodedFuncretPayload['message'] ?? '')),
    ];
	$functionCodeName=$returnFunction[1];
		
	//$request = str_replace("call function if needed,", "continue chat as $DIALECTIC_NAME,", $PROMPTS["inputtext"][0]); 
	if (isset($PROMPTS["afterfunc"]["cue"][$functionCodeName])) {
		$request =$PROMPTS["afterfunc"]["cue"][$functionCodeName];
	
	} else 
		$request =$PROMPTS["afterfunc"]["cue"]["default"];
	
	/*
	Functions of which return value is provided by server
	$returnFunction is built from a structured JSON payload.
	So here we will override the result (which probably will be nothing)
	*/
	
	if ($functionCodeName == "Attack") {
		if (strpos($returnFunction[3],"Error")!==false) {
			$GLOBALS["FUNCTIONS_ARE_ENABLED"]=false;	// RE-Enable functions	// Endless loop if enabled
			$request="Specify a valid target:(available targets: ".implode(",",$GLOBALS["FUNCTION_PARM_INSPECT"]).")";	
			Logger::info("Request function again {$returnFunction[3]}");
		}
		
	} else {
		if (isset($GLOBALS["FUNCSERV"][$functionCodeName])) {
			call_user_func_array($GLOBALS["FUNCSERV"][$functionCodeName],[]);
		}
		
	}
	
} else if ($gameRequest[0] == "memory") {
	
	$memoriesFound=queryMemory($gameRequest[3],null,$GLOBALS["DIALECTIC_NAME"]);
	$selectedMemory = [];
	if (is_array($memoriesFound) && isset($memoriesFound["content"]) && is_array($memoriesFound["content"])) {
		$currentMemory = current($memoriesFound["content"]);
		if (is_array($currentMemory)) {
			$selectedMemory = $currentMemory;
		}
	}
	$GLOBALS["DEBUG_DATA"]["memories"]["selected"]=$selectedMemory;
	$GLOBALS["MEMORY_INJECTION_RESULT"]=$selectedMemory["briefing"] ?? "No relevant memory was found.";
	
	$request=strtr(selectRandomInArray($PROMPTS[$gameRequest[0]]["cue"]),["#MEMORY_INJECTION_RESULT#"=>$GLOBALS["MEMORY_INJECTION_RESULT"]]);
	
	
} else if ($gameRequest[0] == "diary") {
	// Check if this is a narrator diary request and if narrator diary is disabled
	if (isset($GLOBALS["DIALECTIC_NAME"]) && $GLOBALS["DIALECTIC_NAME"] === "The Narrator") {
		if (!isset($GLOBALS["NARRATOR_DIARY_ENABLED"]) || !$GLOBALS["NARRATOR_DIARY_ENABLED"]) {
			Logger::info("[DIARY] Narrator diary is disabled, skipping");
			dialectic_buffer_command_response_line("The Narrator", "DebugNotification", ["message" => "The Narrator's diary is disabled"]);
			die();
		}
	}
	
	// Use configurable DIARY_PROMPT or fallback to default
	$diaryCharacterName = function_exists('dialecticGetPromptCharacterName')
		? dialecticGetPromptCharacterName()
		: $GLOBALS["DIALECTIC_NAME"];
	$diaryPrompt = isset($GLOBALS["DIARY_PROMPT"]) && !empty($GLOBALS["DIARY_PROMPT"]) 
		? strtr($GLOBALS["DIARY_PROMPT"],['{$GLOBALS["DIALECTIC_NAME"]}'=>$diaryCharacterName,'{$GLOBALS["PLAYER_NAME"]}'=>$GLOBALS["PLAYER_NAME"],'#DIALECTIC_NAME#'=>$diaryCharacterName,'#NARRATOR_NAME#'=>function_exists('dialecticGetNarratorRoleplayName') ? dialecticGetNarratorRoleplayName() : 'The Narrator','#PLAYER_NAME#'=>$GLOBALS["PLAYER_NAME"]])
		: "Please write a short summary of {$GLOBALS["PLAYER_NAME"]} and {$diaryCharacterName}s last dialogues and events written above into {$diaryCharacterName}s diary . WRITE AS IF YOU WERE {$diaryCharacterName}.";
	
	// Add current game date/time context to the prompt
	$diaryPrompt = "Current date and time: {$fallout_date}. " . $diaryPrompt;
	
	$request = $diaryPrompt;
	
	logMemory($GLOBALS["PLAYER_NAME"], $GLOBALS["DIALECTIC_NAME"],
        "(Important note: Something important happened here for {$GLOBALS["PLAYER_NAME"]} on {$fallout_date}. You should use the tag #PlotRelevantEvent)",
        $momentum, $gameRequest[2],'diary_intent',$gameRequest[1]);

} else if ($gameRequest[0] == "narrator_welcome") {
	// Handle narrator welcome message
	if (isset($PROMPTS["narrator_welcome"]["cue"]) && is_array($PROMPTS["narrator_welcome"]["cue"]) && count($PROMPTS["narrator_welcome"]["cue"]) > 0) {
		$request = selectRandomInArray($PROMPTS["narrator_welcome"]["cue"]);
	} else {
		Logger::error("[NARRATOR_WELCOME] Cue not found or empty in request.php!");
		$request = "Give a brief (2-3 sentence) recap of recent events and adventures. Welcome the player back to their journey.";
	}

} else if ($gameRequest[0] == "quest" || $gameRequest[0] == "narrator_quest_comment") {
	$questCharacterName = function_exists('dialecticGetPromptCharacterName')
		? dialecticGetPromptCharacterName()
		: $GLOBALS["DIALECTIC_NAME"];
	// Load quest comment prompt from database with fallback
	$questPromptText = null;
	try {
		$questPromptData = $db->fetchOne("SELECT custom_prompt, default_prompt FROM prompts WHERE prompt_key = 'quest_comment_prompt'");
		if ($questPromptData) {
			$questPromptText = (!empty($questPromptData['custom_prompt'])) 
				? $questPromptData['custom_prompt'] 
				: $questPromptData['default_prompt'];
		}
	} catch (Exception $e) {
		Logger::warn("[QUEST] Failed to load prompt from database: " . $e->getMessage());
	}
	
	// Hardcoded fallback
	if (!$questPromptText) {
		$questPromptText = "{$questCharacterName}, what should we do about this new quest?";
	}
	
	$questPromptText = strtr($questPromptText, [
		'{DIALECTIC_NAME}' => function_exists('dialecticGetPromptCharacterName') ? dialecticGetPromptCharacterName() : $GLOBALS["DIALECTIC_NAME"],
		'{NARRATOR_NAME}' => function_exists('dialecticGetNarratorRoleplayName') ? dialecticGetNarratorRoleplayName() : 'The Narrator',
	]);
	
	// Override the player_request in PROMPTS array
	$promptKey = $gameRequest[0];
	if (!isset($PROMPTS[$promptKey])) {
		$PROMPTS[$promptKey] = [];
	}
	$PROMPTS[$promptKey]["player_request"] = [$questPromptText];

} else {

	if ($gameRequest[0] == "instruction" || $gameRequest[0] == "suggestion") {
		// Override some descriptions when in instruction mode
		require_once(__DIR__."/../functions/functions_instruction.php");
	}

	
	if (isset($PROMPTS[$gameRequest[0]]["player_request"])) {
		$request = selectRandomInArray($PROMPTS[$gameRequest[0]]["cue"]); // Add support for arrays here	
		$playerRequestOptions = $PROMPTS[$gameRequest[0]]["player_request"];
		if (is_array($playerRequestOptions)) {
			$playerRequestOptions = array_values(array_filter($playerRequestOptions, static fn($item) => trim((string)$item) !== ""));
			if (count($playerRequestOptions) > 0) {
				$gameRequest[3]=selectRandomInArray($playerRequestOptions);	// Overwrite
			}
		}
		// error_log(__FILE__." ".__LINE__." $request {$gameRequest[3]}");
	}
	else {
		$requestPromptData = $PROMPTS[$gameRequest[0]] ?? [];
		if (isset($requestPromptData["cue"]))
			$request = selectRandomInArray($requestPromptData["cue"]); // Add support for arrays here
		else {
			Logger::warn("Request cue is empty! - ".$gameRequest[0]." - ".print_r($requestPromptData,true)." - ".__FILE__.":".__LINE__);
			$request = "{$GLOBALS["TEMPLATE_DIALOG"]}";
		}
	}
}






$commandSent = false;

// Add
if (($gameRequest[0] == "inputtext") || ($gameRequest[0] == "inputtext_s")) {
	$hasDialogueTarget = preg_match('/\(\s*(?:(?:talking|whispering|shouting)\s+to|speaking\s+loudly\s+to)\s+[^()]+(?:\s+from\s+far\s+away)?\s*\)/i', (string)$gameRequest[3]) === 1;
	if (!$hasDialogueTarget) {
		$gameRequest[3] = $gameRequest[3]." $DIALOGUE_TARGET";
	}
}

?>
