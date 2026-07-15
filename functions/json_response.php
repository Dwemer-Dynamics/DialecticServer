<?php
    require_once(__DIR__.DIRECTORY_SEPARATOR."..".DIRECTORY_SEPARATOR."lib".DIRECTORY_SEPARATOR."emote_moods.php");

    global $FUNC_LIST;
    global $responseTemplate;
    global $structuredOutputTemplate;
    global $grammar;
    $FUNC_LIST=[];
    $responseTemplate=[];
    $structuredOutputTemplate=array();
    $grammar = "";

    if (!function_exists('dialecticIsDirectNarratorDialogue')) {
        function dialecticIsDirectNarratorDialogue() {
            if (isset($GLOBALS["DIRECT_NARRATOR_DIALOGUE"])) {
                return (bool)$GLOBALS["DIRECT_NARRATOR_DIALOGUE"];
            }

            return isset($GLOBALS["gameRequest"][0]) && $GLOBALS["gameRequest"][0] === "narrator_inputtext";
        }
    }

    if (!function_exists('dialecticIsVisionRequest')) {
        function dialecticIsVisionRequest() {
            return isset($GLOBALS["gameRequest"][0]) && $GLOBALS["gameRequest"][0] === "vision";
        }
    }

    if (!function_exists('dialecticShouldExposePromptActions')) {
        function dialecticShouldExposePromptActions() {
            if (dialecticIsVisionRequest()) {
                return false;
            }

            if (dialecticIsDirectNarratorDialogue()) {
                return true;
            }

            return isset($GLOBALS["FUNCTIONS_ARE_ENABLED"]) && $GLOBALS["FUNCTIONS_ARE_ENABLED"];
        }
    }

    if (!function_exists('dialecticApplyJsonTemplateHooks')) {
        function dialecticApplyJsonTemplateHooks() {
            if (isset($GLOBALS["HOOKS"]) && isset($GLOBALS["HOOKS"]["JSON_TEMPLATE"]) && is_array($GLOBALS["HOOKS"]["JSON_TEMPLATE"])) {
                foreach ($GLOBALS["HOOKS"]["JSON_TEMPLATE"] as $hook) {
                    call_user_func($hook);
                }
            }
        }
    }

    if (!function_exists('dialecticEnsureRecursiveRequireHelper')) {
        function dialecticEnsureRecursiveRequireHelper() {
            if (!function_exists('requireFilesRecursively')) {
                require_once(__DIR__.DIRECTORY_SEPARATOR."..".DIRECTORY_SEPARATOR."lib".DIRECTORY_SEPARATOR."data_functions.php");
            }
        }
    }

    if (!function_exists('dialecticRefreshJsonResponseState')) {
        function dialecticRefreshJsonResponseState($loadExtensionCustomizers = false) {
            global $FUNC_LIST;
            global $responseTemplate;
            global $structuredOutputTemplate;
            global $grammar;

            $FUNC_LIST = [];
            $responseTemplate = [];
            $structuredOutputTemplate = array();
            $grammar = "";

            setActions();
            setResponseTemplate();
            setStructuredOutputTemplate();
            setGBNFGrammar();

            dialecticApplyJsonTemplateHooks();
        }
    }

    if (!function_exists('dialecticGetNarratorJsonResponseStateSummary')) {
        function dialecticGetNarratorJsonResponseStateSummary(): array
        {
            $actionTemplate = trim(strval($GLOBALS["responseTemplate"]["action"] ?? ""));
            $funcList = array_values(array_filter(
                is_array($GLOBALS["FUNC_LIST"] ?? null) ? $GLOBALS["FUNC_LIST"] : [],
                function ($value) {
                    return trim(strval($value)) !== "";
                }
            ));

            $structuredActionProperty = $GLOBALS["structuredOutputTemplate"]["json_schema"]["schema"]["properties"]["action"] ?? null;
            $structuredActionEnum = [];
            if (is_array($structuredActionProperty) && isset($structuredActionProperty["enum"]) && is_array($structuredActionProperty["enum"])) {
                $structuredActionEnum = array_values(array_filter($structuredActionProperty["enum"], function ($value) {
                    return trim(strval($value)) !== "";
                }));
            }

            $hasOnlyTalkAction = count($funcList) === 1 && strcasecmp($funcList[0], "Talk") === 0;

            $needsRefresh = dialecticIsDirectNarratorDialogue() && (
                empty($GLOBALS["PROMPT_ACTIONS_LIST"])
                || empty($funcList)
                || $actionTemplate === ""
                || strcasecmp($actionTemplate, "Talk") === 0
                || empty($structuredActionEnum)
                || $hasOnlyTalkAction
            );

            return [
                "request" => strtolower(trim(strval($GLOBALS["gameRequest"][0] ?? ''))),
                "direct_flag" => !empty($GLOBALS["DIRECT_NARRATOR_DIALOGUE"]) ? '1' : '0',
                "dialectic" => strval($GLOBALS["DIALECTIC_NAME"] ?? ''),
                "func_count" => count($funcList),
                "prompt_actions_len" => strlen(strval($GLOBALS["PROMPT_ACTIONS_LIST"] ?? "")),
                "response_action" => $actionTemplate,
                "structured_action_count" => count($structuredActionEnum),
                "has_only_talk_action" => $hasOnlyTalkAction,
                "needs_refresh" => $needsRefresh,
            ];
        }
    }

    if (!function_exists('dialecticFormatNarratorJsonResponseStateSummary')) {
        function dialecticFormatNarratorJsonResponseStateSummary(?array $summary = null): string
        {
            $summary = $summary ?? dialecticGetNarratorJsonResponseStateSummary();

            return "request=" . strval($summary["request"] ?? '') .
                " direct_flag=" . strval($summary["direct_flag"] ?? '0') .
                " dialectic=" . strval($summary["dialectic"] ?? '') .
                " func_count=" . strval($summary["func_count"] ?? 0) .
                " prompt_actions_len=" . strval($summary["prompt_actions_len"] ?? 0) .
                " response_action=" . strval($summary["response_action"] ?? '') .
                " structured_action_count=" . strval($summary["structured_action_count"] ?? 0) .
                " has_only_talk_action=" . (!empty($summary["has_only_talk_action"]) ? '1' : '0');
        }
    }

    if (!function_exists('dialecticNarratorJsonResponseNeedsRefresh')) {
        function dialecticNarratorJsonResponseNeedsRefresh(): bool
        {
            if (!dialecticIsDirectNarratorDialogue()) {
                return false;
            }

            $summary = dialecticGetNarratorJsonResponseStateSummary();
            return !empty($summary["needs_refresh"]);
        }
    }

    if (!function_exists('dialecticEnsureNarratorJsonResponseState')) {
        function dialecticEnsureNarratorJsonResponseState($logContext = 'JSON_RESPONSE')
        {
            if (!function_exists('dialecticRefreshJsonResponseState')) {
                return;
            }

            $requestType = strtolower(trim(strval($GLOBALS["gameRequest"][0] ?? '')));
            $directNarratorDialogue = dialecticIsDirectNarratorDialogue();
            if (!$directNarratorDialogue) {
                if ($requestType === 'narrator_inputtext' || strcasecmp(trim(strval($GLOBALS["DIALECTIC_NAME"] ?? '')), 'The Narrator') === 0) {
                    Logger::warn("[{$logContext}] Skipping narrator JSON refresh because dialecticIsDirectNarratorDialogue() is false (" . dialecticFormatNarratorJsonResponseStateSummary() . ")");
                }
                return;
            }

            $stateSummary = dialecticGetNarratorJsonResponseStateSummary();
            if (empty($stateSummary["needs_refresh"])) {
                return;
            }

            Logger::warn("[{$logContext}] Rebuilding narrator JSON response state because prompt actions/schema were incomplete (" . dialecticFormatNarratorJsonResponseStateSummary($stateSummary) . ")");
            dialecticRefreshJsonResponseState();
            $stateSummary = dialecticGetNarratorJsonResponseStateSummary();
            if (!empty($stateSummary["needs_refresh"])) {
                Logger::warn("[{$logContext}] Narrator JSON response state still incomplete after rebuild (" . dialecticFormatNarratorJsonResponseStateSummary($stateSummary) . ")");
            }
        }
    }

    if (!function_exists('buildFunctionExecutionContextFromResponse')) {
        require_once(__DIR__ . DIRECTORY_SEPARATOR . "functions.php");
    }

    // specify the available actions which will be made available in the context
    if (!function_exists('setActions')) {
    Function setActions() {
        $promptCharacterName = function_exists('dialecticGetPromptCharacterName')
            ? dialecticGetPromptCharacterName()
            : ($GLOBALS["DIALECTIC_NAME"] ?? 'The Narrator');
        // Initialize actions list
        $GLOBALS["PROMPT_ACTIONS_LIST"] = "";
        $GLOBALS["COMMAND_PROMPT_FUNCTIONS"] = $GLOBALS["COMMAND_PROMPT_FUNCTIONS"] ?? "";
        $GLOBALS["FUNCTIONS"] = is_array($GLOBALS["FUNCTIONS"] ?? null) ? $GLOBALS["FUNCTIONS"] : [];
        $GLOBALS["ENABLED_FUNCTIONS"] = is_array($GLOBALS["ENABLED_FUNCTIONS"] ?? null) ? $GLOBALS["ENABLED_FUNCTIONS"] : [];
        $GLOBALS["FUNCTION_PARM_INSPECT"] = is_array($GLOBALS["FUNCTION_PARM_INSPECT"] ?? null) ? $GLOBALS["FUNCTION_PARM_INSPECT"] : [];
        $GLOBALS["FUNCTION_PARM_MOVETO"] = is_array($GLOBALS["FUNCTION_PARM_MOVETO"] ?? null) ? $GLOBALS["FUNCTION_PARM_MOVETO"] : [];
        
        // Narration-style requests should not browse the full action catalog, but
        // they still need a stable Talk action in the response schema.
        if (isset($GLOBALS["gameRequest"]) && in_array($GLOBALS["gameRequest"][0], ["narration", "vision"], true)) {
            $GLOBALS["FUNC_LIST"] = ["Talk"];
            return;
        }

        $shouldExposePromptActions = dialecticShouldExposePromptActions();
        if ($shouldExposePromptActions && empty($GLOBALS["FUNCTIONS_ARE_ENABLED"])) {
            $GLOBALS["FUNCTIONS_ARE_ENABLED"] = true;
        }

        // Build actions list separately (not in PROMPT_HEAD)
        if ($shouldExposePromptActions) {
            $GLOBALS["PROMPT_ACTIONS_LIST"] = "\n<available_actions_list>\n";
            $GLOBALS["PROMPT_ACTIONS_LIST"] .= $GLOBALS["COMMAND_PROMPT_FUNCTIONS"];
            
            foreach ($GLOBALS["FUNCTIONS"] as $index => $function) {
                if (!$function) {
                    continue;
                }
                
                $fname=getFunctionCodeName($function["name"]);

                if (!in_array($fname,$GLOBALS["ENABLED_FUNCTIONS"])) {
                    error_log("[FUNCTIONS] Skipping disabled function: {$function["name"]} <$fname>");
                    continue;
                } else {
                    error_log("[FUNCTIONS] NOT Skipping function: {$function["name"]} <$fname>");
                }

                $actionDescription = function_exists('dialecticGetPromptActionDescription')
                    ? dialecticGetPromptActionDescription($fname, $function["description"] ?? '')
                    : strval($function["description"] ?? '');

                $GLOBALS["FUNC_LIST"][]=$function["name"];
                if ($fname == "Attack") {
                    $GLOBALS["PROMPT_ACTIONS_LIST"].="\nAVAILABLE ACTION: {$function["name"]} ({$actionDescription})";
                    $GLOBALS["PROMPT_ACTIONS_LIST"].="(available targets: ".implode(",",$GLOBALS["FUNCTION_PARM_INSPECT"]).")";
                } else if ($fname == "GiveCapsTo") {
                    require_once(__DIR__ . DIRECTORY_SEPARATOR . ".." . DIRECTORY_SEPARATOR . "lib" . DIRECTORY_SEPARATOR . "data_functions.php");
                    $capsAmount = function_exists('getCapsFromMetadata') ? getCapsFromMetadata() : '';
                    $capsText = trim(strval($capsAmount)) !== '' ? " You currently have {$capsAmount} caps." : "";
                    $GLOBALS["PROMPT_ACTIONS_LIST"].="\nAVAILABLE ACTION: {$function["name"]} ({$actionDescription}).{$capsText} Put the recipient in 'target' and the caps amount in 'amount'.";
                } else if ($fname == "TakeCapsFromPlayer") {
                    $GLOBALS["PROMPT_ACTIONS_LIST"].="\nAVAILABLE ACTION: {$function["name"]} ({$actionDescription}). Put the caps amount in 'amount'.";
                } else if ($fname == "Consume") {
                $GLOBALS["PROMPT_ACTIONS_LIST"].="\nAVAILABLE ACTION: {$function["name"]} ({$actionDescription}). Put the exact item name from <inventory> in the 'target' field. Only use this for food, drinks, chems, or aid items already in inventory. Leave 'item' blank unless you need it as a fallback copy of the same item name. The spoken reply for this action happens after the item is consumed, so use it only when {$promptCharacterName} is actually going to use that item.";
                } else if ($fname == "GiveItemTo") {
                    $GLOBALS["PROMPT_ACTIONS_LIST"].="\nAVAILABLE ACTION: {$function["name"]} ({$actionDescription}). Put the recipient in 'target', exact inventory item name in 'item', and quantity in 'amount'.";
                } else if ($fname == "PickupItem") {
                    $GLOBALS["PROMPT_ACTIONS_LIST"].="\nAVAILABLE ACTION: {$function["name"]} ({$actionDescription}). Put the exact nearby item RefID:ItemName in 'item'.";
                } else if ($fname == "TravelTo") {
                    $GLOBALS["PROMPT_ACTIONS_LIST"].="\nAVAILABLE ACTION: {$function["name"]} ({$actionDescription}). Put the destination location in 'target' or 'location'.";
                } else {
                    $GLOBALS["PROMPT_ACTIONS_LIST"].="\nAVAILABLE ACTION: {$function["name"]} ({$actionDescription})";
                }
            }
            
            $GLOBALS["PROMPT_ACTIONS_LIST"].="\nAVAILABLE ACTION: Talk (default action, used when no other action is suitable)\n</available_actions_list>";
            $GLOBALS["FUNC_LIST"][]="Talk";
            shuffle($GLOBALS["FUNC_LIST"]);
        }
    }
    }

    // specify the json object that will be requested from the LLM (via prompt, not enforced)
    if (!function_exists('setResponseTemplate')) {
    Function setResponseTemplate() {
        $promptCharacterName = function_exists('dialecticGetPromptCharacterName')
            ? dialecticGetPromptCharacterName()
            : ($GLOBALS["DIALECTIC_NAME"] ?? 'The Narrator');
        $moods=normalizeEmoteMoods($GLOBALS["EMOTEMOODS"] ?? "");
        shuffle($moods);
        $moodDescription = empty($moods)
            ? "choose exactly one mood while speaking, never combine moods"
            : "choose exactly one mood while speaking from this list, never combine moods: ".implode("|", $moods);
    
        // Auto-detect language from TTS config if LLM_LANG not set
        if (!isset($GLOBALS["LLM_LANG"]) && isset($GLOBALS["LANG_LLM_XTTS"]) && $GLOBALS["LANG_LLM_XTTS"]) {
            if (isset($GLOBALS["TTS"]["XTTSFASTAPI"]["language"])) {
                $GLOBALS["LLM_LANG"] = $GLOBALS["TTS"]["XTTSFASTAPI"]["language"];
            } elseif (isset($GLOBALS["TTS"]["OMNIVOICE"]["language"])) {
                $GLOBALS["LLM_LANG"] = $GLOBALS["TTS"]["OMNIVOICE"]["language"];
            } elseif (isset($GLOBALS["TTS"]["CHATTERBOX"]["language"])) {
                $GLOBALS["LLM_LANG"] = $GLOBALS["TTS"]["CHATTERBOX"]["language"];
            } elseif (isset($GLOBALS["TTS"]["POCKETTTS"]["language"])) {
                $GLOBALS["LLM_LANG"] = $GLOBALS["TTS"]["POCKETTTS"]["language"];
            }
        }
    
        // Build listener description - for rechat events, encourage addressing the previous speaker
        $listenerDesc = "specify who {$promptCharacterName} is talking to, comma separated, max two listeners, in addressing order";
        if (dialecticIsVisionRequest()) {
            $listenerDesc = "leave blank unless {$promptCharacterName} directly addresses someone while explaining the current scene";
        } elseif (
            isset($GLOBALS["gameRequest"]) &&
            (
                (function_exists('dialecticIsStrictResponsePromptContext') && dialecticIsStrictResponsePromptContext()) ||
                in_array($GLOBALS["gameRequest"][0], ["rechat"], true)
            )
        ) {
            $listenerDesc = function_exists('dialecticLoadManagedRechatListenerPrompt')
                ? dialecticLoadManagedRechatListenerPrompt()
                : "specify who {$promptCharacterName} is talking to. Address whoever just spoke - can be any person in the conversation.";
        }
    
        // Determine message description based on inline narration mode.
        $inlineNarrationMode = strtolower(trim((string)($GLOBALS["INLINE_NARRATION_MODE"] ?? '')));
        if (!in_array($inlineNarrationMode, ['disabled', 'narrator', 'npc', 'text_only'], true)) {
            $inlineNarrationMode = 'disabled';
        }
        if (dialecticIsDirectNarratorDialogue()) {
            $inlineNarrationMode = 'disabled';
        }
        $inlineNarrationEnabled = $inlineNarrationMode !== 'disabled';
        $messageDescription = "lines of dialogue";
        if (dialecticIsVisionRequest()) {
            $messageDescription = "{$promptCharacterName}'s spoken explanation of the current scene. Describe only what is visibly present right now through {$GLOBALS["PLAYER_NAME"]}'s eyes, focusing on people, environment, objects, and immediate activity. Do not continue unrelated conversation, do not answer stale dialogue, and do not invent unseen details.";
        } elseif ($inlineNarrationEnabled) {
            $messageDescription = "If needed, start with one brief third-person narration block in single asterisks, then put {$promptCharacterName}'s spoken text after it. Example: *She smiles* It's good to see you again, my friend! Do not wrap the entire reply in asterisks, and keep spoken dialogue outside the asterisks.";
        } elseif (dialecticIsDirectNarratorDialogue()) {
            $messageDescription = "plain spoken dialogue addressed directly to {$GLOBALS["PLAYER_NAME"]}. Keep the spoken reply consistent with the chosen narrator action when you use one. Do not include third-person narration, scene description, stage directions, or text in asterisks.";
        }
    
        if (isset($GLOBALS["FEATURES"]["MISC"]["JSON_DIALOGUE_FORMAT_REORDER"])&&($GLOBALS["FEATURES"]["MISC"]["JSON_DIALOGUE_FORMAT_REORDER"])) {
            if (isset($GLOBALS["LANG_LLM_XTTS"])&&($GLOBALS["LANG_LLM_XTTS"])) {
                $GLOBALS["responseTemplate"] = [
                    "character"=>$GLOBALS["DIALECTIC_NAME"],
                    "listener"=>$listenerDesc,
                    "message"=>$messageDescription,
                    "mood"=>$moodDescription,
                    "action"=>implode("|",$GLOBALS["FUNC_LIST"]),
                    "target"=>"action target actor, #PLAYER_NAME#, PLAYER, me, or destination. Required for Attack, Follow, MoveTo, GiveItemTo, GiveCapsTo, Inspect, TravelTo, and similar target-based actions. For Consume, use the exact inventory item name in target. Leave blank when the chosen action does not need a target.",
                    "item"=>"exact inventory item name for GiveItemTo or Consume, exact nearby RefID:ItemName for PickupItem, or optional caps amount fallback for GiveCapsTo. Leave blank when the chosen action does not need an item.",
                    "amount"=>"caps amount or item quantity when the chosen action supports it. Required for GiveCapsTo or TakeCapsFromPlayer when an amount is known. Optional when action is GiveItemTo. Use a positive integer when needed.",
                    "lang"=>isset($GLOBALS["LLM_LANG"])?$GLOBALS["LLM_LANG"]:"en|es|fr|de|it|pt|ru|zh-cn|ja|ko|ar|pl|tr|cs|nl|hu|hi",
                ];
            } else {
                $GLOBALS["responseTemplate"] = [
                    "character"=>$GLOBALS["DIALECTIC_NAME"],
                    "listener"=>$listenerDesc,
                    "message"=>$messageDescription,
                    "mood"=>$moodDescription,
                    "action"=>implode("|",$GLOBALS["FUNC_LIST"]),
                    "target"=>"action target actor, #PLAYER_NAME#, PLAYER, me, or destination. Required for Attack, Follow, MoveTo, GiveItemTo, GiveCapsTo, Inspect, TravelTo, and similar target-based actions. For Consume, use the exact inventory item name in target. Leave blank when the chosen action does not need a target.",
                    "item"=>"exact inventory item name for GiveItemTo or Consume, exact nearby RefID:ItemName for PickupItem, or optional caps amount fallback for GiveCapsTo. Leave blank when the chosen action does not need an item.",
                    "amount"=>"caps amount or item quantity when the chosen action supports it. Required for GiveCapsTo or TakeCapsFromPlayer when an amount is known. Optional when action is GiveItemTo. Use a positive integer when needed."
                ];
            }
        } else {
            if (isset($GLOBALS["LANG_LLM_XTTS"])&&($GLOBALS["LANG_LLM_XTTS"])) {
                $GLOBALS["responseTemplate"] = [
                    "character"=>$GLOBALS["DIALECTIC_NAME"],
                    "listener"=>$listenerDesc,
                    "mood"=>$moodDescription,
                    "action"=>implode("|",$GLOBALS["FUNC_LIST"]),
                    "target"=>"action target actor, #PLAYER_NAME#, PLAYER, me, or destination. Required for Attack, Follow, MoveTo, GiveItemTo, GiveCapsTo, Inspect, TravelTo, and similar target-based actions. For Consume, use the exact inventory item name in target. Leave blank when the chosen action does not need a target.",
                    "item"=>"exact inventory item name for GiveItemTo or Consume, exact nearby RefID:ItemName for PickupItem, or optional caps amount fallback for GiveCapsTo. Leave blank when the chosen action does not need an item.",
                    "amount"=>"caps amount or item quantity when the chosen action supports it. Required for GiveCapsTo or TakeCapsFromPlayer when an amount is known. Optional when action is GiveItemTo. Use a positive integer when needed.",
                    "lang"=>isset($GLOBALS["LLM_LANG"])?$GLOBALS["LLM_LANG"]:"en|es|fr|de|it|pt|ru|zh-cn|ja|ko|ar|pl|tr|cs|nl|hu|hi",
                    "message"=>$messageDescription
                ];
            } else {
                $GLOBALS["responseTemplate"] = [
                    "character"=>$GLOBALS["DIALECTIC_NAME"],
                    "listener"=>$listenerDesc,
                    "mood"=>$moodDescription,
                    "action"=>implode("|",$GLOBALS["FUNC_LIST"]),
                    "target"=>"action target actor, #PLAYER_NAME#, PLAYER, me, or destination. Required for Attack, Follow, MoveTo, GiveItemTo, GiveCapsTo, Inspect, TravelTo, and similar target-based actions. For Consume, use the exact inventory item name in target. Leave blank when the chosen action does not need a target.",
                    "item"=>"exact inventory item name for GiveItemTo or Consume, exact nearby RefID:ItemName for PickupItem, or optional caps amount fallback for GiveCapsTo. Leave blank when the chosen action does not need an item.",
                    "amount"=>"caps amount or item quantity when the chosen action supports it. Required for GiveCapsTo or TakeCapsFromPlayer when an amount is known. Optional when action is GiveItemTo. Use a positive integer when needed.",
                    "message"=>$messageDescription
                ];
            }
        }

        // emotions expression:
        if (isset($GLOBALS['use_emotions_expression']) && $GLOBALS['use_emotions_expression']) {
            if (!array_key_exists("emotion", $GLOBALS["responseTemplate"])) {
                $GLOBALS["responseTemplate"]["emotion"] = 
                "calm|surprised|aroused|desire|love|happy|amusement|gratitude|proud|anxious|fearful|panic|grieving|envious|jealous|sad|disappointed|ashamed|angry|offended|disgusted|sarcastic";
            }
            if (!array_key_exists("emotion_intensity", $GLOBALS["responseTemplate"])) {
                $GLOBALS["responseTemplate"]["emotion_intensity"] = "low|moderate|strong";
            }
        }
        
    }
    }
    
    // for use with openai and openrouter providers that support structured outputs to enforce a json schema
    if (!function_exists('setStructuredOutputTemplate')) {
    Function setStructuredOutputTemplate() {
        $promptCharacterName = function_exists('dialecticGetPromptCharacterName')
            ? dialecticGetPromptCharacterName()
            : ($GLOBALS["DIALECTIC_NAME"] ?? 'The Narrator');
        $moods=normalizeEmoteMoods($GLOBALS["EMOTEMOODS"] ?? "");
        shuffle($moods);
        $moodDescription = "choose exactly one mood while speaking, never combine moods";
        $listenerDescription = dialecticIsVisionRequest()
            ? "leave blank unless {$promptCharacterName} directly addresses someone while explaining the current scene"
            : "specify who {$promptCharacterName} is talking to, comma separated, max two listeners, in addressing order";

        // Determine message description based on inline narration mode.
        $inlineNarrationMode = strtolower(trim((string)($GLOBALS["INLINE_NARRATION_MODE"] ?? '')));
        if (!in_array($inlineNarrationMode, ['disabled', 'narrator', 'npc', 'text_only'], true)) {
            $inlineNarrationMode = 'disabled';
        }
        if (dialecticIsDirectNarratorDialogue()) {
            $inlineNarrationMode = 'disabled';
        }
        $inlineNarrationEnabled = $inlineNarrationMode !== 'disabled';
        $messageDescription = "lines of {$promptCharacterName}'s dialogue";
        if (dialecticIsVisionRequest()) {
            $messageDescription = "{$promptCharacterName}'s spoken explanation of the current scene. Describe only what is visibly present right now through {$GLOBALS["PLAYER_NAME"]}'s eyes, focusing on people, environment, objects, and immediate activity. Do not continue unrelated conversation, do not answer stale dialogue, and do not invent unseen details.";
        } elseif ($inlineNarrationEnabled) {
            $messageDescription = "If needed, start with one brief third-person narration block in single asterisks, then put {$promptCharacterName}'s spoken text after it. Example: *She smiles* It's good to see you again, my friend! Do not wrap the entire reply in asterisks, and keep spoken dialogue outside the asterisks.";
        } elseif (dialecticIsDirectNarratorDialogue()) {
            $messageDescription = "plain spoken dialogue addressed directly to {$GLOBALS["PLAYER_NAME"]}. Keep the spoken reply consistent with the chosen narrator action when you use one. Do not include third-person narration, scene description, stage directions, or text in asterisks.";
        }

        $GLOBALS["structuredOutputTemplate"] = array(
            "type" => "json_schema",
            "json_schema" => array(
                "name" => "response",
                "schema" => array(
                    "type" => "object",
                    "properties" => array(
                        "character" => array(
                            "type" => "string",
                            "description" => $promptCharacterName
                        ),
                        "listener" => array(
                            "type" => "string",
                            "description" => $listenerDescription,
                        ),
                        "message" => array(
                            "type" => "string",
                            "description" => $messageDescription
                        ),
                        "mood" => empty($moods) ?
                            array(
                                "type" => "string",
                                "description" => $moodDescription
                            ) :
                            array(
                                "type" => "string",
                                "description" => $moodDescription,
                                "enum" => $moods
                            ),
                        "action" => empty($GLOBALS["FUNC_LIST"]) ? 
                            array(
                                "type" => "string",
                                "description" => "a valid action (refer to available actions list)"
                            ) :
                            array(
                                "type" => "string",
                                "description" => "a valid action (refer to available actions list)",
                                "enum" => $GLOBALS["FUNC_LIST"]
                            ),
                        "target" => array(
                            "type" => "string",
                "description" => "action target actor, #PLAYER_NAME#, PLAYER, me, destination, or exact inventory item name for Consume. Required for Attack, Follow, MoveTo, GiveItemTo, GiveCapsTo, Inspect, TravelTo, and similar target-based actions. Leave blank when the chosen action does not need a target."
                        ),
                        "item" => array(
                            "type" => "string",
                "description" => "exact inventory item name for GiveItemTo or Consume, exact nearby RefID:ItemName for PickupItem, or optional caps amount fallback for GiveCapsTo. For Consume, leave item blank unless target is empty and item is the same exact inventory item name fallback."
                        ),
                        "amount" => array(
                            "type" => "integer",
                "description" => "caps amount or item quantity when the chosen action supports it. Required for GiveCapsTo or TakeCapsFromPlayer when an amount is known. Optional when action is GiveItemTo. Use a positive integer."
                        )
                    ),
                    "required" => [
                        "character",
                        "listener",
                        "message",
                        "mood",
                        "action",
                        "target",
                        "item"
                    ],
                    "additionalProperties" => false
                ),
                "strict" => true
            )
        );

        if (isset($GLOBALS["LANG_LLM_XTTS"])&&($GLOBALS["LANG_LLM_XTTS"])) {
            if (isset($GLOBALS["LLM_LANG"])) {

                $GLOBALS["structuredOutputTemplate"]["json_schema"]["schema"]["properties"] = array_merge(
                    $GLOBALS["structuredOutputTemplate"]["json_schema"]["schema"]["properties"], array(
                        "lang" => array(
                            "type" => "string",
                            "description" => "Language to use. Must be {$GLOBALS["LLM_LANG"]}"
                        )
                    ));
                $GLOBALS["structuredOutputTemplate"]["json_schema"]["schema"]["required"][]="lang";
            } else {
                $GLOBALS["structuredOutputTemplate"]["json_schema"]["schema"]["properties"] = array_merge(
                    $GLOBALS["structuredOutputTemplate"]["json_schema"]["schema"]["properties"], array(
                        "lang" => array(
                            "type" => "string",
                            "description" => "Language to use"
                        )
                    ));
                $GLOBALS["structuredOutputTemplate"]["json_schema"]["schema"]["required"][]="lang";    
            }

        }

        // emotions expression:
        if (isset($GLOBALS['use_emotions_expression']) && $GLOBALS['use_emotions_expression']) {
            $GLOBALS["structuredOutputTemplate"]["json_schema"]["schema"]["properties"] = array_merge(
                $GLOBALS["structuredOutputTemplate"]["json_schema"]["schema"]["properties"], array(
                    "emotion" => array(
                        "type" => "string",
                        "description" => "The emotion expressed."
                    ),
                    "emotion_intensity" => array(
                        "type" => "string",
                        "description" => "The intensity of the emotion expressed, possible values 'low', 'moderate' or 'strong'."
                    )
                ));
            $GLOBALS["structuredOutputTemplate"]["json_schema"]["schema"]["required"][]="emotion";
            $GLOBALS["structuredOutputTemplate"]["json_schema"]["schema"]["required"][]="emotion_intensity";
        }
        
    }
    }

    // sets the grammar used by koboldcpp
    if (!function_exists('setGBNFGrammar')) {
    Function setGBNFGrammar() {
        // build the string for moods
        // should look like: ("\"playful\"" | "\"default\"" | ...)
        $moods=normalizeEmoteMoods($GLOBALS["EMOTEMOODS"] ?? "");
        shuffle($moods);

        $moods_quoted = [];
        foreach ($moods as $n=>$mood) {
            $moods_quoted[] = '"\"'.$mood.'\""';
        }
        $moods_str = "(".implode(' | ', $moods_quoted).")";

        if (sizeof($moods) == 0) {
            $moods_str = "string";
        }

        // build the string for actions
        // should look like: ("\"Talk\"" | "\"Attack\"" | ...)
        $actions_quoted = [];
        foreach ($GLOBALS["FUNC_LIST"] as $n=>$action) {
            $actions_quoted[] = '"\"'.$action.'\""';
        }
        $actions_str = "(".implode(' | ', $actions_quoted).")";

        if (sizeof($GLOBALS["FUNC_LIST"]) == 0) {
            $actions_str = "string";
        }

        // using a quoted heredoc to avoid having to escape everything
        $GLOBALS["grammar"] = <<<'EOD'
        root ::= "{" ws root-character "," ws root-listener "," ws root-message "," ws root-mood "," ws root-action "," ws root-target "," ws root-item "," ws root-amount "}" ws
        root-character ::= "\"character\"" ":" ws string
        root-listener ::= "\"listener\"" ":" ws string
        root-message ::= "\"message\"" ":" ws string
        root-mood ::= "\"mood\"" ":" ws {$MOODS}
        root-action ::= "\"action\"" ":" ws {$ACTIONS}
        root-target ::= "\"target\"" ":" ws string
        root-item ::= "\"item\"" ":" ws string
        root-amount ::= "\"amount\"" ":" ws number
        string ::=
        "\"" (
            [^"\\] |
            "\\" (["\\/bfnrt] | "u" [0-9a-fA-F] [0-9a-fA-F] [0-9a-fA-F] [0-9a-fA-F]) # escapes
        )* "\"" ws

        number ::= ("-"? ([0-9] | [1-9] [0-9]*)) ("." [0-9]+)? ([eE] [-+]? [0-9]+)? ws

        # Optional space: by convention, applied in this grammar after literal chars when allowed
        ws ::= ([ \t\n] ws)?
        EOD;

        // replace the mood and action templates with the strings built earlier
        $GLOBALS["grammar"]=str_replace('{$MOODS}', $moods_str, $GLOBALS["grammar"]);
        $GLOBALS["grammar"]=str_replace('{$ACTIONS}', $actions_str, $GLOBALS["grammar"]);
    }
    }

    dialecticRefreshJsonResponseState(true);

?>
