<?php
/*

Post tasks.

*/

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
$worldknowledgeInfiniumEnabled = isWorldKnowledgeSettingEnabled($GLOBALS["WORLDKNOWLEDGE_INFINIUM"] ?? false);

if ($minimeEnabled) {
    // Use the managed WORLDKNOWLEDGE_INFINIUM global setting.
    if ($worldknowledgeInfiniumEnabled) {
        if (in_array($gameRequest[0], ["inputtext", "inputtext_s", "rechat", "continue"])) {

            //$TEST_TEXT=lastSpeech($GLOBALS["DIALECTIC_NAME"]);
            //$TEST_TEXT="{$GLOBALS["DIALECTIC_NAME"]}:".implode(" ",$GLOBALS["talkedSoFar"]);
            $TEST_TEXT = implode(" ", $GLOBALS["talkedSoFar"]);

            $topic = json_decode(minimePostTopic($TEST_TEXT), true);

        }
    }
}
// POST MEMORY
if ($minimeEnabled) {
    if (in_array($gameRequest[0], ["inputtext", "inputtext_s"])) {
        if (sizeof($memoryInjectionCtx) == 0) {
            // In case main memory search didnt return resutls because minime activated and user is nt directly asking a question
            error_log("[POST MEMORY SEARCH]");
            $GLOBALS["PATCH_BYPASS_MINIME_EXTRACT"] = true;

            $GLOBALS["MEMORY_THRESHOLD_MODIFIER"] = 0.5;
            $memoryInjection                      = offerMemory($gameRequest);
            if ($memoryInjection) {

                $gameRequestCopy    = $gameRequest;
                $gameRequestCopy[0] = "infoaction";
                $gameRequestCopy[3] = "#MEMORY: {$GLOBALS["DIALECTIC_NAME"]} remembers this: [$memoryInjection]";
                error_log("[POST MEMORY SEARCH], memory found ($memoryInjection)");
                logEvent($gameRequestCopy, $GLOBALS["DIALECTIC_NAME"]); // Memory log only avaibale to current NPC.
            }

        }

        $historyData  = "";
        $lastPlace    = "";
        $lastListener = "";
        $lastDateTime = "";

        foreach (json_decode(DataSpeechJournal($GLOBALS["DIALECTIC_NAME"], 10), true) as $element) {
            if ($element["listener"] == "The Narrator") {
                continue;
            }
            if ($lastListener != $element["listener"]) {
                $listener     = " (talking to {$element["listener"]})";
                $lastListener = $element["listener"];
            } else {
                $listener = "";
            }

            if ($lastPlace != $element["location"]) {
                $place     = " (at {$element["location"]})";
                $lastPlace = $element["location"];
            } else {
                $place = "";
            }

            if ($lastDateTime != substr($element["fallout_date"], 0, 15)) {
                $date         = substr($element["fallout_date"], 0, 10);
                $time         = substr($element["fallout_date"], 11);
                $dateTime     = "(on date {$date} at {$time})";
                $lastDateTime = substr($element["fallout_date"], 0, 15);
            } else {
                $dateTime = "";
            }

            $historyData .= trim("{$element["speaker"]}:" . trim($element["speech"]) . " $listener $place $dateTime") . PHP_EOL;
        }

        $status = "default";
        //$topic  = json_decode(minimePostScene($historyData), true);// Not working well for now.
        $sceneClassifierEnabled = true;
        if (array_key_exists("SCENE_CLASSIFIER_ENABLED", $GLOBALS)) {
            $sceneClassifierEnabledValue = $GLOBALS["SCENE_CLASSIFIER_ENABLED"];
            if (is_string($sceneClassifierEnabledValue)) {
                $sceneClassifierEnabled = !in_array(strtolower(trim($sceneClassifierEnabledValue)), ["", "0", "false", "off", "no"], true);
            } else {
                $sceneClassifierEnabled = !empty($sceneClassifierEnabledValue);
            }
        }

        $topic = ["generated_tags" => "default"];
        if ($sceneClassifierEnabled) {
            $connector = new LLMConnector();
            $sceneClassifierLabels = [
                "Gemma 3N E4B",
                "Scene Classifier (Gemma 3N E4B)",
                "Scene Classifier (Gemini 2.5 Flash Lite)"
            ];
            $sceneClassifierConnectorId = intval($GLOBALS["CORE_CONNECTOR_SCENECLASSIFIER"] ?? 0);
            $mediumTermConnectorId = intval($GLOBALS["CORE_CONNECTOR_MEDIUMTERM"] ?? 0);
            $currentConnectorData = null;
            $connectionHandler = null;

            if ($sceneClassifierConnectorId > 0) {
                $currentConnectorData = $connector->getById($sceneClassifierConnectorId);
            }

            if (empty($currentConnectorData) && isset($GLOBALS["db"]) && $GLOBALS["db"]) {
                foreach ($sceneClassifierLabels as $sceneClassifierLabel) {
                    $sceneClassifierLabelEscaped = $GLOBALS["db"]->escape($sceneClassifierLabel);
                    $sceneClassifierRow = $GLOBALS["db"]->fetchOne(
                        "SELECT id FROM core_llm_connector WHERE LOWER(COALESCE(label,'')) = LOWER('{$sceneClassifierLabelEscaped}') LIMIT 1"
                    );
                    if (is_array($sceneClassifierRow) && !empty($sceneClassifierRow["id"])) {
                        $sceneClassifierConnectorId = intval($sceneClassifierRow["id"]);
                        $currentConnectorData = $connector->getById($sceneClassifierConnectorId);
                        if (!empty($currentConnectorData)) {
                            Logger::info("[SCENE CLASSIFIER] Auto-selected dedicated scene classifier connector ID {$sceneClassifierConnectorId}");
                            break;
                        }
                    }
                }
            }

            if (empty($currentConnectorData) && $mediumTermConnectorId > 0) {
                Logger::info("[SCENE CLASSIFIER] CORE_CONNECTOR_SCENECLASSIFIER not configured or invalid, falling back to CORE_CONNECTOR_MEDIUMTERM");
                $currentConnectorData = $connector->getById($mediumTermConnectorId);
            }

            if (!empty($currentConnectorData)) {
                $connector->setOldGlobals($currentConnectorData);
                $connectionHandler = $connector->getConnector($currentConnectorData);
            } else {
                Logger::warn("[SCENE CLASSIFIER] No connector configured for scene classification, skipping scene genre detection");
            }
            
            $allowedGenres = ["horror", "action", "thriller", "mystery", "romance", "comedy", "drama","nsfw"];

            $prompt = [];
            $prompt[] = ['role' => 'system', 'content' => "Classify the following dialogue into one of these genres: ".
                implode(", ", $allowedGenres)];
            
            $prompt[] = ['role' => 'user', 'content' => "Dialogue:\n$historyData"];
            $prompt[] = ['role' => 'user', 'content' => "Respond only with the genre name."];

            $buffer = "";
            if ($connectionHandler) {
                $buffer = $connectionHandler->fast_request(
                    $prompt,
                    ["MAX_TOKENS" => 64],
                    "sceneclassifier"
                );
            }

            // Parse LLM output to find matching genre
            $detectedGenre = "default";
            $bufferLower = strtolower(trim((string)$buffer));
            foreach ($allowedGenres as $genre) {
                if (stripos($bufferLower, strtolower($genre)) !== false) {
                    $detectedGenre = $genre;
                    break;
                }
            }
            
            $topic = ["generated_tags" => $detectedGenre];

            error_log("[minimePostScene] Detected genre: {$topic["generated_tags"]} from buffer $buffer");
        } else {
            Logger::info("[SCENE CLASSIFIER] Disabled, skipping scene genre detection");
        }
        if ($topic["generated_tags"] == "relax") {
            $GLOBALS["db"]->insert(
                'rolemaster',
                [
                    'localts' => time(),
                    'ttl'     => 60,
                    'type'    => "scenenote",
                    'data'    => "Overall ambient seems relaxed. Actors should behave in a relaxed way",
                ]
            );

            $status = "relax";

        } else if ($topic["generated_tags"] == "romance") {
            $GLOBALS["db"]->insert(
                'rolemaster',
                [
                    'localts' => time(),
                    'ttl'     => 60,
                    'type'    => "scenenote",
                    'data'    => "Overall ambient seems intimate. Actors should behave in a intimate way",
                ]
            );

            $status = "intimate";
        }

        $npcManager = new NpcMaster();
        $npcData    = $npcManager->getByName($GLOBALS["DIALECTIC_NAME"]);
        if ($npcData) {
            if (isset($npcData["extended_data"])) {
                $extended = json_decode($npcData["extended_data"], true);
            } else {
                $extended = [];
            }
            $extended["scene_status"] = $status;
            $npcData["extended_data"] = json_encode($extended);
            //$npcData["gamets_last_updated"]=$gameRequest[2];
            $npcManager->updateByArray($npcData);
        }

    }
}
