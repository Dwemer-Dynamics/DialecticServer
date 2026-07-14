<?php
require_once($GLOBALS["ENGINE_PATH"]."/lib/dynamic_update_util.php");
require_once($GLOBALS["ENGINE_PATH"]."/lib/utils_game_timestamp.php");
require_once($GLOBALS["ENGINE_PATH"]."/lib/playthrough_snapshot.php");
require_once($GLOBALS["ENGINE_PATH"]."/lib/save_rollback.php");
require_once($GLOBALS["ENGINE_PATH"]."/lib/core/game_plugins.php");
require_once($GLOBALS["ENGINE_PATH"]."/lib/player_tts_helpers.php");

$MUST_END=false;

if (!isset($gameRequest[3])) {
    $gameRequest[3] = '';
}
$gameRequest[3] = @mb_convert_encoding((string)$gameRequest[3], 'UTF-8', 'UTF-8');

// Auto-sync player_name when game prefix doesn't match what core_player has.
// Plugin sends "<PlayerName>: <text>" - that prefix is the live player name from Fallout.
if (function_exists('dialecticMaybeSyncPlayerName')
    && in_array($gameRequest[0] ?? '', ['inputtext', 'inputtext_s'], true)
    && preg_match('/^([A-Za-z][A-Za-z0-9_\' -]{0,40}):\s/', (string)$gameRequest[3], $_dialecticPlayerNameMatch)) {
    dialecticMaybeSyncPlayerName($_dialecticPlayerNameMatch[1]);
}

if (!function_exists("resolvePeopleForIncomingEvent")) {
    function resolvePeopleForIncomingEvent($eventType, $eventData, $fallbackPeople = "")
    {
        $strictModeEnabled = function_exists("isStrictSpatialPeopleModeEnabled") ? isStrictSpatialPeopleModeEnabled() : false;
        $normalizedEventType = strtolower((string)$eventType);
        $pluginAuthoritativeActorEvents = [
            "inputtext",
            "inputtext_s",
            "narrator_inputtext",
            "chat"
        ];
        $isPluginAuthoritativeActorEvent = in_array($normalizedEventType, $pluginAuthoritativeActorEvents, true);

        if ($fallbackPeople === "") {
            if ($isPluginAuthoritativeActorEvent) {
                $fallbackPeople = "";
            } elseif (isset($GLOBALS["CACHE_PEOPLE_LIMITED"]) && trim((string)$GLOBALS["CACHE_PEOPLE_LIMITED"]) !== "") {
                $fallbackPeople = (string)$GLOBALS["CACHE_PEOPLE_LIMITED"];
            } elseif (isset($GLOBALS["CACHE_PEOPLE"]) && trim((string)$GLOBALS["CACHE_PEOPLE"]) !== "") {
                $fallbackPeople = (string)$GLOBALS["CACHE_PEOPLE"];
            } elseif (!$strictModeEnabled) {
                $fallbackPeople = DataBeingsInCloseRange(true);
            }
        }

        return buildScopedPeopleForEvent(
            $eventType,
            $eventData,
            $GLOBALS["DIALECTIC_NAME"] ?? "",
            $fallbackPeople
        );
    }
}

if (!function_exists("extractPlayerMenuDialogueLine")) {
    function extractPlayerMenuDialogueLine($rawLine)
    {
        if (function_exists('dialecticExtractPlayerTtsDialogueLine')) {
            return dialecticExtractPlayerTtsDialogueLine($rawLine);
        }

        $line = @mb_convert_encoding((string)$rawLine, 'UTF-8', 'UTF-8');
        $line = trim($line);
        if ($line === "") {
            return "";
        }

        $decoded = json_decode($line, true);
        if (is_array($decoded)) {
            foreach (["text", "line", "dialogue", "message"] as $key) {
                if (isset($decoded[$key]) && is_scalar($decoded[$key]) && trim((string)$decoded[$key]) !== "") {
                    $line = trim((string)$decoded[$key]);
                    break;
                }
            }
        }

        $split = explode(":", $line, 2);
        if (count($split) === 2) {
            $line = trim($split[1]);
        }

        $line = str_replace(["\r", "\n", "|"], " ", $line);
        $line = preg_replace('/\s+/', ' ', $line);
        return trim($line);
    }
}

if (!function_exists("playerMenuTtsCachePath")) {
    function playerMenuTtsCachePath($line)
    {
        $subtitle = function_exists('formatPlayerSubtitleText')
            ? formatPlayerSubtitleText((string)$line, $GLOBALS["PLAYER_NAME"] ?? null)
            : trim(preg_replace('/\s+/', ' ', str_replace(["\r", "\n", "|"], " ", (string)$line)));

        if (function_exists('dialectic_tts_soundcache_path')) {
            return dialectic_tts_soundcache_path(dirname(__DIR__), "Player", $subtitle);
        }

        return __DIR__ . DIRECTORY_SEPARATOR . ".." . DIRECTORY_SEPARATOR . "soundcache" . DIRECTORY_SEPARATOR . md5(trim((string)$line)) . ".wav";
    }
}

if (!function_exists("emitPlayerMenuSpeechLine")) {
    function emitPlayerMenuSpeechLine($line)
    {
        $subtitle = str_replace(["\r", "\n", "|"], " ", (string)$line);
        $subtitle = trim(preg_replace('/\s+/', ' ', $subtitle));
        if ($subtitle === "") {
            return;
        }

        dialectic_buffer_speech_response_line("Player", $subtitle, "", "__player_menu_tts", "", "", 1.0);
    }
}

if ($gameRequest[0] == "init") { // Reset responses if init sent (Think about this)
    // avoid a rare case where fallout briefly reverts to level 1 Prisoner during load
    // Moved Dynamic Updates functions here
    if ($gameRequest[2] == "10000000") {
        Logger::warn("Ignoring init with a gamets of 10000000.");
        $MUST_END=true;
        return;
    }
    $now=time();

    error_log("[INIT] Pruning Dialectic data after loaded-save gamets {$gameRequest[2]}");
    dialecticMaybeHandleIncomingGametsRollback($gameRequest[2], 'init', true);
    try {
        $inFlightDeliverySql = dialecticBuildChatDeliveryStateSql(
            'delivery_state',
            array_values(array_unique(array_merge(dialecticGetInFlightChatDeliveryStates(), ['playing']))),
            'emitted'
        );
        $db->update(
            'eventlog',
            "delivery_state='aborted'",
            "type='chat' AND {$inFlightDeliverySql}"
        );
        Logger::info("[INIT] Aborted stale in-flight dialogue delivery rows after game load");
    } catch (Throwable $e) {
        Logger::warn("[INIT] Could not abort stale in-flight dialogue rows: " . $e->getMessage());
    }
    $db->insert(
        'eventlog',
        array(
            'ts' => $gameRequest[1],
            'gamets' => $gameRequest[2],
            'type' => $gameRequest[0],
            'data' => $gameRequest[3],
            'sess' => 'pending',
            'localts' => time(),
            'people' => resolvePeopleForIncomingEvent($gameRequest[0], $gameRequest[3] ?? "")
        )
    );
    
    if (isset($gameRequest[3]) && preg_match('/^\d+(?:\.\d+){1,3}/', trim((string)$gameRequest[3]))) {
        $db->upsertRowOnConflict(
            'conf_opts',
            array(
                'id' => "plugin_dll_version",
                'value' =>$gameRequest[3]
            ),
            "id"
        );
    }

    Logger::trace("INIT PROCESSING ".(time()-$now));
    // Delete TTS(STT cache
    $directory = __DIR__.DIRECTORY_SEPARATOR."..".DIRECTORY_SEPARATOR."soundcache";

    touch(__DIR__.DIRECTORY_SEPARATOR."..".DIRECTORY_SEPARATOR."soundcache".DIRECTORY_SEPARATOR.".placeholder");
    $sixHoursAgo = time() - (6 * 60 * 60);

    $handle = opendir($directory);
    if ($handle) {
        while (false !== ($file = readdir($handle))) {
            $filePath = $directory . DIRECTORY_SEPARATOR . $file;

            if (is_file($filePath)) {
                if (strpos($filePath, ".placeholder")!==false) {
                    continue;
                }
                $fileMTime = filemtime($filePath);
                if ($fileMTime < $sixHoursAgo) {
                    @unlink($filePath);
                }
            }
        }
        closedir($handle);
    }
    
    /* Restore NPCs state */
      
    $npcMaster=new NpcMaster();
    $npcMaster->restoreNPC($gameRequest[2]);
    Logger::trace("POST INIT PROCESSING ".(time()-$now));
    
    // Narrator Welcome Message on Load
    try {
        require_once($GLOBALS["ENGINE_PATH"] . "/lib/core/narrator.class.php");
        $narrator = new Narrator();
        
        // Check if narrator is enabled and welcome message is enabled
        if ($narrator->getBool('enabled', true) && $narrator->getBool('welcome_enabled', false)) {
            // Get cooldown from narrator settings (in minutes, default 10)
            $cooldownMinutes = $narrator->getInt('welcome_cooldown', 10);
            $cooldownSeconds = $cooldownMinutes * 60;
            
            // Check cooldown
            $lastWelcomeTs = $db->fetchOne("SELECT value FROM conf_opts WHERE id='last_narrator_welcome'");
            $currentTime = time();
            
            $canTrigger = true;
            if ($lastWelcomeTs && isset($lastWelcomeTs['value'])) {
                $timeSinceLastWelcome = $currentTime - intval($lastWelcomeTs['value']);
                if ($timeSinceLastWelcome < $cooldownSeconds) {
                    $canTrigger = false;
                    Logger::debug("Narrator welcome message on cooldown. {$timeSinceLastWelcome}s since last, need {$cooldownSeconds}s");
                }
            }
            
            if ($canTrigger) {
                // Queue the event in eventlog so it shows up in context
                $db->insert(
                    'eventlog',
                    array(
                        'ts' => $gameRequest[1],
                        'gamets' => $gameRequest[2],
                        'type' => 'narrator_welcome',
                        'data' => 'Narrator welcome message triggered on game load',
                        'sess' => 'complete', // Mark as complete so it doesn't get processed again
                        'localts' => $currentTime,
                        'people' => resolvePeopleForIncomingEvent('narrator_welcome', 'Narrator welcome message triggered on game load')
                    )
                );
                
                // Update last welcome timestamp
                $db->upsertRowOnConflict(
                    'conf_opts',
                    array(
                        'id' => 'last_narrator_welcome',
                        'value' => (string)$currentTime
                    ),
                    'id'
                );
                
                // Store flag to trigger narrator after init processing
                $GLOBALS["TRIGGER_NARRATOR_WELCOME"] = true;
                
                Logger::info("Narrator welcome message will be triggered");
            }
        }
    } catch (Exception $e) {
        Logger::warn("Could not trigger narrator welcome message: " . $e->getMessage());
    }
    
    $MUST_END=true;


}

if ($gameRequest[0] == "wipe") { // Reset reponses if init sent (Think about this)
    $now=time();
    $db->delete("eventlog", " 1=1");
    $db->delete("quests", " 1=1");
    $db->delete("speech", " 1=1 ");
    $db->delete("diarylog", " 1=1 ");

    if ($GLOBALS["FEATURES"]["MEMORY_EMBEDDING"]["ENABLED"]) {
        $results = $db->query("select gamets_truncated,uid from memory_summary where gamets_truncated>{$gameRequest[2]}");
        while ($memoryRow = $db->fetchArray($results)) {
            deleteElement($memoryRow["uid"]);
        }
    }
    $db->delete("memory_summary", " 1=1 ");
    $db->delete("memory", " 1=1 ");

    //die(print_r($gameRequest,true));
    $db->update("responselog", "sent=0", "sent=1 and (action='dialectic_dialogue_response')");
    $db->insert(
        'eventlog',
        array(
            'ts' => $gameRequest[1],
            'gamets' => $gameRequest[2],
            'type' => $gameRequest[0],
            'data' => $gameRequest[3],
            'sess' => 'pending',
            'localts' => time(),
            'people' => resolvePeopleForIncomingEvent($gameRequest[0], $gameRequest[3] ?? "")
        )
    );

    // Delete TTS(STT cache
    $directory = __DIR__.DIRECTORY_SEPARATOR."..".DIRECTORY_SEPARATOR."soundcache";

    touch(__DIR__.DIRECTORY_SEPARATOR."..".DIRECTORY_SEPARATOR."soundcache".DIRECTORY_SEPARATOR.".placeholder");
    $sixHoursAgo = time() - (6 * 60 * 60);

    $handle = opendir($directory);
    if ($handle) {
        while (false !== ($file = readdir($handle))) {
            $filePath = $directory . DIRECTORY_SEPARATOR . $file;

            if (is_file($filePath)) {
                if (strpos($filePath, ".placeholder")!==false) {
                    continue;
                }
                $fileMTime = filemtime($filePath);
                if ($fileMTime < $sixHoursAgo) {
                    @unlink($filePath);
                }
            }
        }
        closedir($handle);
    }
    

    $MUST_END=true;


} elseif ($gameRequest[0] == "request") { // Just requested response
    $responseDataMl = DataDequeue(time()+1);// Allow responses queued up to 1 second in the future
    foreach ($responseDataMl as $responseData) {
        dialectic_buffer_response_line(
            (string)($responseData["actor"] ?? ""),
            (string)($responseData["action"] ?? ""),
            (string)($responseData["text"] ?? "")
        );
    }
    
    if (time()%5==0) {
        logEvent($gameRequest);
    }
    
    $MUST_END=true;

} elseif ($gameRequest[0] == "_speech_abort") {
    error_reporting(E_ALL);
    $abortData = json_decode($gameRequest[3], true);

    if (is_array($abortData)) {
        $utteranceIds = [];
        if (isset($abortData["utterance_ids"]) && is_array($abortData["utterance_ids"])) {
            foreach ($abortData["utterance_ids"] as $utteranceId) {
                $utteranceId = trim((string)$utteranceId);
                if ($utteranceId === "") {
                    continue;
                }
                $utteranceIds[$utteranceId] = $db->escape($utteranceId);
            }
        }

        if (!empty($utteranceIds)) {
            $quotedIds = array_map(function ($escapedId) {
                return "'" . $escapedId . "'";
            }, array_values($utteranceIds));
            $abortableChatStateSql = dialecticBuildChatDeliveryStateSql(
                'delivery_state',
                dialecticGetInFlightChatDeliveryStates(),
                'emitted'
            );
            $db->execQuery(
                "UPDATE public.eventlog
                 SET delivery_state='aborted'
                 WHERE type='chat'
                   AND utterance_id IN (" . implode(",", $quotedIds) . ")
                   AND {$abortableChatStateSql}"
            );
        }
    }

    $MUST_END=true;

} elseif ($gameRequest[0] == "_speech") {
    error_reporting(E_ALL);
    $speech = json_decode($gameRequest[3], true);
   
    // error_log(print_r($speech,true));
    if (is_array($speech)) {
        $nonAbortedChatStateSql = "COALESCE(delivery_state, 'emitted')<>'aborted'";
        $speechSpeaker = isset($speech["speaker"]) ? trim((string)$speech["speaker"]) : "";
        $speechListener = isset($speech["listener"]) ? trim((string)$speech["listener"]) : "";
        $speechUtteranceId = isset($speech["utterance_id"]) ? trim((string)$speech["utterance_id"]) : "";
        $audiblePeople = [];
        if (isset($speech["companions"]) && is_array($speech["companions"])) {
            foreach ($speech["companions"] as $companionName) {
                $companionName = trim((string)$companionName);
                if ($companionName === "") {
                    continue;
                }
                appendUniqueActorName($audiblePeople, $companionName);
            }
        }
        if ($speechSpeaker !== "") {
            appendUniqueActorName($audiblePeople, $speechSpeaker);
        }
        $companionsReformatStr = normalizePeoplePipeList($audiblePeople);

        // Store distance for shouting detection
        $distance = isset($speech["distance"]) ? floatval($speech["distance"]) : 0.0;

        // Store distance globally for context building
        $GLOBALS["LAST_SPEECH_DISTANCE"] = $distance;

        if (isset($speech["spatial_volume"])) {
            $GLOBALS["LAST_SPEECH_VOLUME"] = max(0.0, min(1.0, floatval($speech["spatial_volume"])));
        } else {
            unset($GLOBALS["LAST_SPEECH_VOLUME"]);
        }

        if (isset($speech["spatial_reason"]) && is_string($speech["spatial_reason"])) {
            $GLOBALS["LAST_SPEECH_REASON"] = trim($speech["spatial_reason"]);
        } else {
            unset($GLOBALS["LAST_SPEECH_REASON"]);
        }

        $topic = isset($speech["debug"]) ? $speech["debug"] : null;
        if (isset($speech["spatial_reason"]) && is_string($speech["spatial_reason"]) && $speech["spatial_reason"] !== "") {
            $spatialTag = "spatial:" . trim($speech["spatial_reason"]);
            $topic = empty($topic) ? $spatialTag : "{$topic}|{$spatialTag}";
        }
        
        $db->insert(
            'speech',
            array(
                'ts' => $gameRequest[1],
                'gamets' => $gameRequest[2],
                'listener' => $speech["listener"],
                'speaker' => $speech["speaker"],
                'speech' => $speech["speech"],
                'location' => $speech["location"],
                'companions' => $companionsReformatStr,
                'sess' => 'pending',
                'audios' => isset($speech["audios"])?$speech["audios"]:null,
                'utterance_id' => $speechUtteranceId,
                'topic' => $topic,
                'localts' => time()
            )
        );

        $matchedUtteranceRowIds = [];
        if ($speechUtteranceId !== "") {
            $speechUtteranceIdEscaped = $db->escape($speechUtteranceId);
            $matchedRows = $db->fetchAll(
                "SELECT rowid
                 FROM eventlog
                 WHERE type='chat'
                   AND utterance_id='{$speechUtteranceIdEscaped}'
                   AND {$nonAbortedChatStateSql}
                 ORDER BY rowid DESC"
            );
            foreach ((array)$matchedRows as $matchedRow) {
                $matchedRowId = intval($matchedRow["rowid"] ?? 0);
                if ($matchedRowId > 0) {
                    $matchedUtteranceRowIds[] = $matchedRowId;
                }
            }
            $matchedUtteranceRowIds = array_values(array_unique($matchedUtteranceRowIds));
        }

        // Plugin-authoritative mode: _speech companions are the source of truth for
        // actor-originated audience scope. For direct NPC replies to player prompts,
        // we temporarily inherit the latest player-scoped audience as SOT until fresh
        // spatial truth is available for that reply.
        $playerName = isset($GLOBALS["PLAYER_NAME"]) ? trim((string)$GLOBALS["PLAYER_NAME"]) : "";
        $normalizedPlayerName = normalizeActorNameForComparison($playerName);
        $normalizedSpeechSpeaker = normalizeActorNameForComparison($speechSpeaker);
        $normalizedSpeechListener = normalizeActorNameForComparison($speechListener);
        $appendAudienceName = function (&$names, $actorName) {
            $actorName = trim((string)$actorName);
            if ($actorName === "") {
                return;
            }
            if (normalizeActorNameForComparison($actorName) === "unknown") {
                return;
            }
            appendUniqueActorName($names, $actorName);
        };
        $isPlayerSpeech = ($normalizedSpeechSpeaker !== "" && $normalizedPlayerName !== "" &&
                           $normalizedSpeechSpeaker === $normalizedPlayerName);
        $isNpcReplyToPlayer = (!$isPlayerSpeech && $normalizedPlayerName !== "" &&
                               $normalizedSpeechListener === $normalizedPlayerName);
        $speechGamets = intval($gameRequest[2]);
        $dialecticModeRow = $db->fetchOne("SELECT value FROM conf_opts WHERE id='dialectic_mode'");
        $dialecticMode = isset($dialecticModeRow["value"]) ? strtoupper(trim((string)$dialecticModeRow["value"])) : "STANDARD";
        $isWhisperMode = ($dialecticMode === "WHISPER");
        $payloadCompanionCount = (isset($speech["companions"]) && is_array($speech["companions"])) ? count($speech["companions"]) : 0;
        $hasSpatialReason = isset($speech["spatial_reason"]) && trim((string)$speech["spatial_reason"]) !== "";
        $hasSpatialVolume = array_key_exists("spatial_volume", $speech);
        $hasAuthoritativeSpeechAudience = ($payloadCompanionCount > 0 && ($hasSpatialReason || $hasSpatialVolume));
        $audiblePeoplePipe = $companionsReformatStr;
        if ($audiblePeoplePipe === "") {
            $failClosedPeople = [];
            $appendAudienceName($failClosedPeople, $speechListener);
            $appendAudienceName($failClosedPeople, $speechSpeaker);
            $audiblePeoplePipe = normalizePeoplePipeList($failClosedPeople);
        }

        if ($audiblePeoplePipe !== "") {
            if ($isWhisperMode && $isPlayerSpeech) {
                $db->upsertRow(
                    'conf_opts',
                    array(
                        'id' => 'dialectic_whisper_people',
                        'value' => $audiblePeoplePipe
                    ),
                    "id='dialectic_whisper_people'"
                );
                $db->upsertRow(
                    'conf_opts',
                    array(
                        'id' => 'dialectic_whisper_target',
                        'value' => $speechListener
                    ),
                    "id='dialectic_whisper_target'"
                );
                $db->upsertRow(
                    'conf_opts',
                    array(
                        'id' => 'dialectic_whisper_updated',
                        'value' => (string)time()
                    ),
                    "id='dialectic_whisper_updated'"
                );
            }

            // _speech no longer mutates eventlog.people. It only marks matching rows as spoken.
            if (!$isPlayerSpeech && $speechSpeaker !== "") {
                $chatRowId = 0;
                $rowsToUpdate = $matchedUtteranceRowIds;
                $speakerEscaped = $db->escape($speechSpeaker);
                $matchesSpeechListener = function ($chatData) use ($speechListener) {
                    if ($speechListener === "") {
                        return true;
                    }

                    $chatTargetMeta = extractTalkTargetMetadata($chatData);
                    if (!$chatTargetMeta["hasExplicitTarget"]) {
                        return true;
                    }
                    if ($chatTargetMeta["isBroadcast"]) {
                        return false;
                    }
                    if (!empty($chatTargetMeta["targets"])) {
                        return talkTargetsIncludeName($chatTargetMeta["targets"], $speechListener);
                    }
                    return false;
                };

                if (!empty($rowsToUpdate)) {
                    $rowsToUpdate = array_values(array_unique(array_map('intval', $rowsToUpdate)));
                    rsort($rowsToUpdate);
                    $chatRowId = intval($rowsToUpdate[0]);
                } else {
                    // Fast path: same gamets linkage if available.
                    $sameGametsChatRows = $db->fetchAll(
                        "SELECT rowid, gamets, data
                         FROM eventlog
                         WHERE gamets={$speechGamets}
                           AND type='chat'
                           AND {$nonAbortedChatStateSql}
                           AND data ILIKE '{$speakerEscaped}:%'
                         ORDER BY ts DESC, rowid DESC
                         LIMIT 40"
                    );
                    foreach ((array)$sameGametsChatRows as $sameRow) {
                        $sameRowId = intval($sameRow["rowid"] ?? 0);
                        $sameData = isset($sameRow["data"]) ? (string)$sameRow["data"] : "";
                        if ($sameRowId <= 0 || $sameData === "") {
                            continue;
                        }
                        if (!$matchesSpeechListener($sameData)) {
                            continue;
                        }
                        $rowsToUpdate[] = $sameRowId;
                    }
                    if (!empty($rowsToUpdate)) {
                        $rowsToUpdate = array_values(array_unique(array_map('intval', $rowsToUpdate)));
                        rsort($rowsToUpdate);
                        $chatRowId = intval($rowsToUpdate[0]);
                    }
                }

                if ($chatRowId <= 0) {
                    $recentChatRows = $db->fetchAll(
                        "SELECT rowid, gamets, data
                         FROM eventlog
                         WHERE type='chat'
                           AND {$nonAbortedChatStateSql}
                           AND localts>" . (time() - 180) . "
                           AND data ILIKE '{$speakerEscaped}:%'
                         ORDER BY rowid DESC
                         LIMIT 60"
                    );

                    $expectedSpeech = normalizeDialogTextForComparison($speech["speech"] ?? "");
                    $bestChatRowId = 0;
                    $bestChatGamets = 0;
                    $bestScore = -1;
                    foreach ((array)$recentChatRows as $chatRow) {
                        $chatData = isset($chatRow["data"]) ? (string)$chatRow["data"] : "";
                        if ($chatData === "") {
                            continue;
                        }

                        $chatSpeaker = extractSpeakerNameFromChatEvent($chatData);
                        if (normalizeActorNameForComparison($chatSpeaker) !== normalizeActorNameForComparison($speechSpeaker)) {
                            continue;
                        }

                        $score = 2; // speaker matched
                        $rowGamets = intval($chatRow["gamets"] ?? 0);
                        if ($speechGamets > 0 && $rowGamets === $speechGamets) {
                            $score += 2;
                        }

                        $chatTargetMeta = extractTalkTargetMetadata($chatData);
                        if (!$matchesSpeechListener($chatData)) {
                            continue;
                        }
                        if ($speechListener !== "" && talkTargetsIncludeName($chatTargetMeta["targets"], $speechListener)) {
                            $score += 4;
                        }

                        $chatUtterance = extractCoreUtteranceFromChatEvent($chatData);
                        $chatUtteranceNorm = normalizeDialogTextForComparison($chatUtterance);
                        if ($expectedSpeech !== "" && $chatUtteranceNorm !== "") {
                            if ($chatUtteranceNorm === $expectedSpeech) {
                                $score += 8;
                            } elseif (
                                strpos($chatUtteranceNorm, $expectedSpeech) !== false ||
                                strpos($expectedSpeech, $chatUtteranceNorm) !== false
                            ) {
                                $score += 4;
                            } else {
                                // Keep as a weak candidate on speaker/target/gamets match.
                                // This avoids dropping to strict participant-only rows when text
                                // normalization diverges slightly between chat and speech payloads.
                                $score += 1;
                            }
                        }

                        if ($score > $bestScore) {
                            $bestScore = $score;
                            $bestChatRowId = intval($chatRow["rowid"] ?? 0);
                            $bestChatGamets = $rowGamets;
                        }
                        if ($bestScore >= 12) {
                            break;
                        }
                    }

                    if ($bestChatRowId > 0) {
                        $chatRowId = $bestChatRowId;
                        $groupRowIds = [];
                        foreach ((array)$recentChatRows as $groupRow) {
                            $groupRowId = intval($groupRow["rowid"] ?? 0);
                            $groupGamets = intval($groupRow["gamets"] ?? 0);
                            $groupData = isset($groupRow["data"]) ? (string)$groupRow["data"] : "";
                            if ($groupRowId <= 0 || $groupData === "") {
                                continue;
                            }
                            if ($bestChatGamets > 0 && $groupGamets !== $bestChatGamets) {
                                continue;
                            }

                            $groupSpeaker = extractSpeakerNameFromChatEvent($groupData);
                            if (normalizeActorNameForComparison($groupSpeaker) !== normalizeActorNameForComparison($speechSpeaker)) {
                                continue;
                            }
                            if (!$matchesSpeechListener($groupData)) {
                                continue;
                            }
                            $groupRowIds[] = $groupRowId;
                        }

                        if (empty($groupRowIds)) {
                            $groupRowIds[] = $bestChatRowId;
                        }
                        $rowsToUpdate = array_values(array_unique(array_map('intval', $groupRowIds)));
                        rsort($rowsToUpdate);
                    }
                }

                if ($chatRowId > 0) {
                    if (empty($rowsToUpdate)) {
                        $rowsToUpdate = [intval($chatRowId)];
                    }

                    foreach ($rowsToUpdate as $rowIdToUpdate) {
                        if ($rowIdToUpdate <= 0) {
                            continue;
                        }
                        $db->update("eventlog", "delivery_state='spoken'", "rowid={$rowIdToUpdate} AND {$nonAbortedChatStateSql}");
                    }
                }
            } elseif (!empty($matchedUtteranceRowIds)) {
                foreach ($matchedUtteranceRowIds as $matchedRowId) {
                    if ($matchedRowId <= 0) {
                        continue;
                    }
                    $db->update("eventlog", "delivery_state='spoken'", "rowid={$matchedRowId} AND {$nonAbortedChatStateSql}");
                }
            }
        }
    } else {
        Logger::error(__FILE__." data was not an array");

    }
    $MUST_END=true;

} elseif ($gameRequest[0] == "togglemodel") {

    $newModel=DMtoggleModel();
    if (function_exists('dialectic_buffer_command_response_line')) {
        dialectic_buffer_command_response_line((string)$GLOBALS["DIALECTIC_NAME"], "ToggleModel", ["model" => $newModel]);
    } else {
        Logger::warn("[actions] ToggleModel requested before JSON response buffer was available");
    }

    $db->insert(
        'eventlog',
        array(
            'ts' => $gameRequest[1],
            'gamets' => $gameRequest[2],
            'type' => "togglemodel",
            'data' => $newModel,
            'sess' => 'pending',
            'localts' => time(),
            'people' => resolvePeopleForIncomingEvent("togglemodel", $newModel)
        )
    );

    $MUST_END=true;

} elseif ($gameRequest[0] == "death") {

    $MUST_END=true;

} elseif ($gameRequest[0] == "quest") {
    //13333334
    if (($gameRequest[2]>13333334)||($gameRequest[2]<13333332)) {  // ?? How this works.
        
        if (strpos($gameRequest[3],'New quest ""')) {
          // plugin couldn't get quest name  
            $MUST_END=true;
        } else if (stripos($gameRequest[3],'Storyline Tracker')!==false) {
            // Dialectic internal quest tracker entries are not gameplay quest events.
            $MUST_END=true;

    } else {
            logEvent($gameRequest);
            
        }
    } else
        $MUST_END=true;
    /*
    if (isset($GLOBALS["FEATURES"]["MISC"]["QUEST_COMMENT"]))
        if ($GLOBALS["FEATURES"]["MISC"]["QUEST_COMMENT"]===false)
            $MUST_END=true;
    */
    // Check if quest comments are enabled for narrator
    try {
        require_once(__DIR__ . DIRECTORY_SEPARATOR . ".." . DIRECTORY_SEPARATOR . "lib" . DIRECTORY_SEPARATOR . "core" . DIRECTORY_SEPARATOR . "narrator.class.php");
        $narrator = new Narrator();
        
        if ($narrator->getBool('enabled', true) && $narrator->getBool('quest_comment_enabled', false)) {
            $questCommentChance = $narrator->getInt('quest_comment_chance', 10);
            $randomChance = random_int(1, 100);
            
            if ($randomChance > $questCommentChance) {
                $MUST_END = true;
            } else {
                // Chance check passed, now check cooldown
                $cooldownMinutes = $narrator->getInt('quest_comment_cooldown', 3);
                $cooldownSeconds = $cooldownMinutes * 60;
                
                // Fetch last quest comment timestamp
                $lastQuestCommentTs = $db->fetchOne("SELECT value FROM conf_opts WHERE id='QUEST_COMMENT_LAST_TIMESTAMP'");
                $currentTime = time();
                
                $canTrigger = true;
                if ($lastQuestCommentTs && isset($lastQuestCommentTs['value'])) {
                    $timeSinceLastComment = $currentTime - intval($lastQuestCommentTs['value']);
                    if ($timeSinceLastComment < $cooldownSeconds) {
                        $canTrigger = false;
                        Logger::info("Quest comment on cooldown. {$timeSinceLastComment}s since last, need {$cooldownSeconds}s");
                    }
                }
                
                if (!$canTrigger) {
                    $MUST_END = true;
                } else {
                    // Queue the event in eventlog so it shows up in context
                    $db->insert(
                        'eventlog',
                        array(
                            'ts' => $gameRequest[1],
                            'gamets' => $gameRequest[2],
                            'type' => 'narrator_quest_comment',
                            'data' => $gameRequest[3],
                            'sess' => 'complete', // Mark as complete so it doesn't get processed again
                            'localts' => $currentTime,
                            'people' => resolvePeopleForIncomingEvent('narrator_quest_comment', $gameRequest[3] ?? "")
                        )
                    );
                    
                    // Update timestamp for successful quest comment
                    $db->upsertRowOnConflict(
                        "conf_opts",
                        array(
                            "id"    => "QUEST_COMMENT_LAST_TIMESTAMP",
                            "value" => $currentTime
                        ),
                        'id'
                    );
                    
                    // Store flag to trigger narrator after init processing
                    $GLOBALS["TRIGGER_NARRATOR_QUEST_COMMENT"] = true;
                    
                    Logger::info("Narrator quest comment will be triggered");
                }
            }
        } else {
            $MUST_END = true;
        }
    } catch (Exception $e) {
        Logger::warn("Could not check narrator quest comment settings: " . $e->getMessage());
        $MUST_END = true;
    }
} elseif ($gameRequest[0] == "just_say") {
    
    returnLines([trim($gameRequest[3])]);
    
    $MUST_END=true;
    
} elseif ($gameRequest[0] == "playerdied") {
    
    
    // Timeline Break autosnapshot: detect large rollback and snapshot before pruning
    try {
        $prevGamets = DataLastKnownGameTS();
        $incomingGamets = intval($gameRequest[2]);
        $snapshotId = timeline_break_snapshot_if_needed($prevGamets, $incomingGamets);
        if ($snapshotId > 0) {
            Logger::info("TimelineBreak: Created snapshot id {$snapshotId} prior to death rollback prune");
        }
    } catch (Exception $e) {
        Logger::warn("TimelineBreak: Snapshot attempt (playerdied) failed: ".$e->getMessage());
    }

    $lastSaveHistory=$db->fetchAll("select gamets from eventlog where type='infosave' order by ts desc limit 1 offset 0");
    if (isset($lastSaveHistory[0]["ts"])) {
        $lastSave=$lastSaveHistory[0]["ts"];
        
        $db->delete("eventlog", "gamets>$lastSave ");
        
        $db->delete("speech", "gamets>$lastSave  ");
        $db->delete("diarylog", "gamets>$lastSave  ");

        if ($GLOBALS["FEATURES"]["MEMORY_EMBEDDING"]["ENABLED"]) {
            $results = $db->query("select gamets_truncated,uid from memory_summary where gamets_truncated>$lastSave");
            while ($memoryRow = $db->fetchArray($results)) {
                deleteElement($memoryRow["uid"]);
            }
        }
        $db->delete("memory_summary", "gamets_truncated>$lastSave  ");
        $db->delete("memory", "gamets>$lastSave  ");

        //die(print_r($gameRequest,true));
        $db->update("responselog", "sent=0", "sent=1 and (action='dialectic_dialogue_response')");
        $db->insert(
            'eventlog',
            array(
                'ts' => $gameRequest[1],
                'gamets' => $gameRequest[2],
                'type' => $gameRequest[0],
                'data' => $gameRequest[3],
                'sess' => 'pending',
                'localts' => time(),
                'people' => resolvePeopleForIncomingEvent($gameRequest[0], $gameRequest[3] ?? "")
            )
        );
    }
    
    
    $MUST_END=true;
    
} elseif ($gameRequest[0] == "setconf") {
    
    // logEvent($gameRequest);

    $rawSetconfPayload = trim((string)($gameRequest[3] ?? ""));
    $decodedSetconf = is_string($gameRequest[3] ?? null) ? json_decode($gameRequest[3], true) : null;
    if (is_array($decodedSetconf)) {
        $vars = [
            trim(strval($decodedSetconf["setting"] ?? $decodedSetconf["id"] ?? $decodedSetconf["key"] ?? "")),
            trim(strval($decodedSetconf["value"] ?? "")),
        ];
        if (($vars[0] ?? "") === "" && !empty($decodedSetconf["dialectic_mode"])) {
            $vars[0] = "dialectic_mode";
            $vars[1] = trim(strval($decodedSetconf["dialectic_mode"]));
        }
    } elseif (strpos($rawSetconfPayload, "@") !== false) {
        $rawParts = explode("@", $rawSetconfPayload, 2);
        $vars = [
            trim((string)($rawParts[0] ?? "")),
            trim((string)($rawParts[1] ?? "")),
        ];
    } else {
        Logger::warn("Ignoring non-JSON setconf payload");
        $MUST_END=true;
        return;
    }
    if ($vars[0]=="dialectic_context_mode") {
        $cRw=$db->fetchOne("select value from conf_opts where id='{$vars[0]}'");
        $vars[1]=(isset($cRw["value"])&&$cRw["value"]=="1")?"0":"1";
        dialecticQueueCommandResponse(
            "rolemaster",
            "DebugNotification",
            ["message" => "Focus on Chat mode ".($vars[1] ? "enabled" : "disabled")]
        );
    } else if ($vars[0]=="dialectic_mode") {
        $mode = strtoupper(trim((string)($vars[1] ?? "STANDARD")));
        $modeLabels = [
            "STANDARD" => "Standard",
            "WHISPER" => "Whisper",
            "SHOUT" => "Shout",
            "NARRATOR" => "Narrator",
            "DIRECTOR" => "Director",
            "INJECTION_LOG" => "Inject Event",
            "INJECTION_CHAT" => "Inject & Chat",
            "CHEATMODE" => "Cheat Mode",
        ];
        if (!isset($modeLabels[$mode])) {
            Logger::warn("Invalid dialectic_mode requested: ".$vars[1]);
            $mode = "STANDARD";
        }
        $vars[1] = $mode;
    } else if ($vars[0]=="dialectic_profile_model") {
        $slot = isset($vars[1]) ? intval($vars[1]) : 1;
        $slot = max(1, min(4, $slot));
        $vars[1] = (string)$slot;
        $slotLabels = [
            1 => "Standard",
            2 => "Fast",
            3 => "Powerful",
            4 => "Experimental",
        ];
        dialecticQueueCommandResponse(
            "rolemaster",
            "DebugNotification",
            ["message" => "LLM profile: ".$slotLabels[$slot]]
        );
    } else if ($vars[0]=="dialectic_renamenpc") {
        // Convert signed to unsigned using bitwise AND
        $unsignedInt = ($vars[3]+0) & 0xFFFFFFFF;
        // Represent as 8-digit zero-padded hex with 0x prefix
        $unsignedIntHex = '0x' . strtoupper(str_pad(dechex($unsignedInt), 8, '0', STR_PAD_LEFT));
            
        $npcMaster=new NpcMaster();
        $oldNpcData=$npcMaster->getByName($vars[1]);
        $newNpcData=$npcMaster->getByName($vars[2]);
        
        if (!$newNpcData) {
            createProfile($vars[2]);
            $newNpcData=$npcMaster->getByName($vars[2]);
        }

        $npcMaster->renameNPC($vars[1],$vars[2]);

            dialecticQueueCommandResponse(
                "rolemaster",
                "RenameNPC",
                [
                    "refid" => $unsignedIntHex,
                    "name" => $db->escape($vars[2]),
                ]
            );
            
        }
        

        $confOptId = $db->escape($vars[0]);
        $db->upsertRow(
            'conf_opts',
            array(
                'id' => $vars[0],
                'value' => $vars[1]
            ),
            "id='{$confOptId}'"
        );
    
    
    $MUST_END=true;
    
} elseif (strpos($gameRequest[0], "infosave")===0) {    // user saves. lets backup all NPC state.

    error_log("[INFOSAVE] Backup all profiles");
    logEvent($gameRequest);

    $npcMaster=new NpcMaster();
    $npcMaster->backupAllNpcs($gameRequest[2]);
    $MUST_END=true;
    
} elseif (strpos($gameRequest[0], "info")===0) {    // info_whatever requests

    logEvent($gameRequest);

    $MUST_END=true;

} elseif (strpos($gameRequest[0], "updateprofile_narrator")===0) {
    
    Logger::info("updateprofile_narrator: Direct narrator dynamic profile update requested");
    dialectic_buffer_command_response_line("The Narrator", "DebugNotification", ["message" => "Updating The Narrator dynamic profile..."]);
    
    try {
        $success = processNarratorDynamicProfile($db);
        if ($success) {
            dialectic_buffer_command_response_line("The Narrator", "DebugNotification", ["message" => "The Narrator dynamic profile updated."]);
            Logger::info("updateprofile_narrator: Successfully updated The Narrator dynamic profile");
        } else {
            dialectic_buffer_command_response_line("The Narrator", "DebugNotification", ["message" => "The Narrator dynamic profile update failed."]);
            Logger::warn("updateprofile_narrator: Failed to update The Narrator dynamic profile");
        }
    } catch (Throwable $e) {
        dialectic_buffer_command_response_line("The Narrator", "DebugNotification", ["message" => "The Narrator dynamic profile update failed."]);
        Logger::error("updateprofile_narrator: Error updating The Narrator dynamic profile: " . $e->getMessage());
    }
    
    terminate();
    
} elseif (strpos($gameRequest[0], "updateprofiles_batch_async")===0) {
    
    // Async batch processing for timer-based dynamic profile updates.
    // Payload must be JSON: {"schema":"dialectic.profile_update_batch.v1","npcs":["NPC1","NPC2"]}.
    
    if (!isset($gameRequest[3]) || empty($gameRequest[3])) {
        Logger::debug("updateprofiles_batch_async: No NPCs provided");
        die();
    }
    
    $decodedProfilePayload = is_string($gameRequest[3] ?? null) ? json_decode($gameRequest[3], true) : null;
    if (!is_array($decodedProfilePayload) || !isset($decodedProfilePayload["npcs"]) || !is_array($decodedProfilePayload["npcs"])) {
        Logger::warn("updateprofiles_batch_async: Ignoring non-JSON profile update payload");
        die();
    }
    $npcList = $decodedProfilePayload["npcs"];
    $enabledNPCs = [];
    
    Logger::info("updateprofiles_batch_async: Checking " . count($npcList) . ",{$gameRequest[3]} NPCs for enabled dynamic profiles");
    
    // First pass: quickly check which NPCs have DYNAMIC_PROFILE enabled
    foreach ($npcList as $npcName) {
        $npcName = trim($npcName);
        if (empty($npcName)) continue;
        
        // Handle The Narrator separately
        if ($npcName === "The Narrator") {
            require_once(__DIR__ . DIRECTORY_SEPARATOR . ".." . DIRECTORY_SEPARATOR . "lib" . DIRECTORY_SEPARATOR . "core" . DIRECTORY_SEPARATOR . "narrator.class.php");
            $narrator = new Narrator();
            
            // Check if narrator has dynamic profile enabled
            if ($narrator->getBool('dynamic_profile', false)) {
                $enabledNPCs[] = $npcName;
                Logger::debug("updateprofiles_batch_async: The Narrator has dynamic profile enabled");
            }
            continue;
        }
        
        // Check if profile exists for this NPC
        $npcMaster=new NpcMaster();
        $npcData=$npcMaster->getByName($npcName);
        if (!$npcData) {
            continue;
        }
        
        // Check if DYNAMIC_PROFILE is enabled for this NPC
        $isDynamicEnabled = $npcData["dynamic_profile"] ?? $GLOBALS["DYNAMIC_PROFILE"] ?? false;

        // Check  if DYNAMIC_PROFILE is enabled for NPC's profile.
        $profile=new CoreProfile();
        $currentProfileData=$profile->getById($npcData["profile_id"]);
        $profile_metadata=json_decode($currentProfileData["metadata"] ?? "",true);
        if (!is_array($profile_metadata)) {
            $profile_metadata = [];
        }
        if (!empty($profile_metadata["DYNAMIC_PROFILE_ENABLED"]))
            $isDynamicEnabled=true;
        

        if ($isDynamicEnabled) {
            $enabledNPCs[] = $npcName;
        }
    }
    
    $enabledCount = count($enabledNPCs);
    
    // Send immediate ACK message back to plugin with count - ONLY notification we send
    if ($enabledCount > 0) {
        dialectic_buffer_command_response_line("The Narrator", "DebugNotification", [
            "message" => "Updating $enabledCount dynamic profile" . ($enabledCount == 1 ? "" : "s") . "...",
        ]);
        Logger::info("updateprofiles_batch_async: Will update $enabledCount profiles in background: " . implode(', ', $enabledNPCs));
    } else {
        Logger::info("updateprofiles_batch_async: No profiles to update - none had DYNAMIC_PROFILE enabled");
    }
    
    if (ob_get_level() > 0) {
        @ob_flush();
    }
    @flush();
    
    // Process in background if we have enabled NPCs
    if ($enabledCount > 0) {
        // Try to fork process for background processing
        if (function_exists('pcntl_fork')) {
            $pid = pcntl_fork();
            if ($pid == 0) {
                // Child process - do the background work
                Logger::info("updateprofiles_batch_async: Child process started for background processing");
                
                $successCount = 0;
                foreach ($enabledNPCs as $npcName) {
                    try {
                        if (processSingleDynamicProfile($npcName, $gameRequest)) {
                            $successCount++;
                        }
                    } catch (Exception $e) {
                        Logger::error("updateprofiles_batch_async: Error processing profile for $npcName: " . $e->getMessage());
                    }
                }
                
                Logger::info("updateprofiles_batch_async: Background processing completed. Updated $successCount of $enabledCount profiles");
                exit(0);
            } elseif ($pid > 0) {
                // Parent process - continue normally
                Logger::info("updateprofiles_batch_async: Forked background process with PID $pid");
            } else {
                // Fork failed - fall back to database queue method
                Logger::warn("updateprofiles_batch_async: Fork failed, using database queue fallback");
                $queueData = [
                    'timestamp' => time(),
                    'npcs' => $enabledNPCs,
                    'gameRequest' => $gameRequest
                ];
                $queueId = 'dynamic_profiles_queue_' . time() . '_' . uniqid();
                
                try {
                    $db->upsertRowOnConflict('conf_opts', array(
                        'id' => $queueId,
                        'value' => json_encode($queueData)
                    ), 'id');
                    Logger::info("updateprofiles_batch_async: Queued $enabledCount profiles for background processing in database");
                } catch (Exception $e) {
                    Logger::error("updateprofiles_batch_async: Failed to write to database queue: " . $e->getMessage());
                }
            }
        } else {
            // No fork available - use database queue method
            Logger::info("updateprofiles_batch_async: pcntl_fork not available, using database queue method");
            $queueData = [
                'timestamp' => time(),
                'npcs' => $enabledNPCs,
                'gameRequest' => $gameRequest
            ];
            $queueId = 'dynamic_profiles_queue_' . time() . '_' . uniqid();
            
            try {
                $db->upsertRowOnConflict('conf_opts', array(
                    'id' => $queueId,
                    'value' => json_encode($queueData)
                ), 'id');
                Logger::info("updateprofiles_batch_async: Queued $enabledCount profiles for background processing in database");
            } catch (Exception $e) {
                Logger::error("updateprofiles_batch_async: Failed to write to database queue: " . $e->getMessage());
            }
        }
        
        // Trigger immediate background processing
        close();
        triggerImmediateProfileProcessing();
    }
    
    terminate();
    
} elseif (strpos($gameRequest[0], "waitstart")===0) {
    
    
    if (isset($gameRequest[3]) && $gameRequest[3]) {
        $db->upsertRowOnConflict(
            'conf_opts',
            array(
                'id' => "last_waitstart",
                'value' =>$gameRequest[2]
            ),
            "id"
        );
    }
    
    // AUTO_DIARY functionality - trigger diary entries for nearby NPCs with auto_diary_enabled
    Logger::info("WAITSTART event: Processing auto-diary for nearby NPCs");
    processAutoDiary($gameRequest, "waitstart");
    
    $MUST_END=true;
    
    
} elseif (strpos($gameRequest[0], "goodnight")===0) {    // goodnight event
    
    // Log the goodnight event
    $db->insert(
        'eventlog',
        array(
            'ts' => $gameRequest[1],
            'gamets' => $gameRequest[2],
            'type' => $gameRequest[0],
            'data' => isset($gameRequest[3]) ? $gameRequest[3] : '',
            'sess' => 'pending',
            'localts' => time(),
            'people' => resolvePeopleForIncomingEvent($gameRequest[0], $gameRequest[3] ?? "")
        )
    );
    
    // AUTO_DIARY functionality - trigger diary entries for nearby NPCs with auto_diary_enabled
    Logger::info("GOODNIGHT event: Processing auto-diary for nearby NPCs");
    processAutoDiary($gameRequest, "goodnight");
    
    $MUST_END=true;
    
    
} elseif (strpos($gameRequest[0], "waitstop")===0) {
    
    $lastgameTs=$db->fetchOne("select value from conf_opts where id='last_waitstart'");
    
    $elapsed=($gameRequest[2]-$lastgameTs["value"])* 0.0000024;
    $db->insert(
        'eventlog',
        array(
            'ts' => $gameRequest[1],
            'gamets' => $gameRequest[2],
            'type' => "info_timeforward",
            'data' => "$elapsed hours have passed. Current date/time: ".convert_gamets2fallout_long_date($gameRequest[2]),
            'sess' => 'pending',
            'localts' => time(),
            'people' => resolvePeopleForIncomingEvent("info_timeforward", "$elapsed hours have passed. Current date/time: ".convert_gamets2fallout_long_date($gameRequest[2]))
        )
    );

    $MUST_END=true;
    
    
} elseif (strpos($gameRequest[0], "diary_narrator")===0) {
    
    Logger::info("diary_narrator: Direct narrator diary request received");
    
    if (empty($GLOBALS["NARRATOR_DIARY_ENABLED"])) {
        dialectic_buffer_command_response_line("The Narrator", "DebugNotification", ["message" => "Narrator diary is disabled."]);
        Logger::warn("diary_narrator: Narrator diary is disabled");
        terminate();
    }
    
    dialectic_buffer_command_response_line("The Narrator", "DebugNotification", ["message" => "The Narrator is writing a diary entry..."]);
    
    try {
        $success = generateFollowerDiary("The Narrator", $gameRequest, "manual_narrator");
        if (!$success) {
            dialectic_buffer_command_response_line("The Narrator", "DebugNotification", ["message" => "The Narrator diary update failed."]);
            Logger::warn("diary_narrator: Failed to generate narrator diary entry");
        } else {
            Logger::info("diary_narrator: Successfully generated narrator diary entry");
        }
    } catch (Throwable $e) {
        dialectic_buffer_command_response_line("The Narrator", "DebugNotification", ["message" => "The Narrator diary update failed."]);
        Logger::error("diary_narrator: Error generating narrator diary entry: " . $e->getMessage());
    }
    
    terminate();
    
} elseif (strpos($gameRequest[0], "diary_player")===0) {
    
    Logger::info("diary_player: Direct player diary request received");
    
    if (!isPlayerDiaryEnabled()) {
        $playerName = isset($GLOBALS["PLAYER_NAME"]) && trim((string)$GLOBALS["PLAYER_NAME"]) !== ''
            ? trim((string)$GLOBALS["PLAYER_NAME"])
            : "Player";
        dialectic_buffer_command_response_line($playerName, "DebugNotification", ["message" => $playerName . " diary is disabled."]);
        Logger::warn("diary_player: Player diary is disabled");
        terminate();
    }
    
    if (!class_exists('Player')) {
        require_once(__DIR__ . DIRECTORY_SEPARATOR . ".." . DIRECTORY_SEPARATOR . "lib" . DIRECTORY_SEPARATOR . "core" . DIRECTORY_SEPARATOR . "player.class.php");
    }
    $player = new Player();
    $playerName = getConfiguredPlayerDiaryName($player);
    
    dialectic_buffer_command_response_line($playerName, "DebugNotification", ["message" => "Writing diary entry for " . $playerName]);
    
    try {
        $success = generatePlayerDiary($gameRequest, "manual_player");
        if (!$success) {
            dialectic_buffer_command_response_line($playerName, "DebugNotification", ["message" => $playerName . " diary update failed."]);
            Logger::warn("diary_player: Failed to generate player diary entry");
        } else {
            Logger::info("diary_player: Successfully generated player diary entry");
        }
    } catch (Throwable $e) {
        dialectic_buffer_command_response_line($playerName, "DebugNotification", ["message" => $playerName . " diary update failed."]);
        Logger::error("diary_player: Error generating player diary entry: " . $e->getMessage());
    }
    
    terminate();
    
} elseif (strpos($gameRequest[0], "diary_nearby")===0) {    // diary_nearby event - manual trigger for all NPCs in range
    
    // Process diary entries for all nearby NPCs (not just followers)
    processNearbyDiary($gameRequest, "manual_nearby");
    
    $MUST_END=true;
    
    
} elseif (strpos($gameRequest[0], "core_profile_assign")===0) {    // diary_nearby event - manual trigger for all NPCs in range
    
    // logEvent($gameRequest);

    $activeProfile = function_exists('dialecticRuntimeGetActiveProfile') ? dialecticRuntimeGetActiveProfile() : null;
    if ($activeProfile !== null) {
        $npcMaster=new NpcMaster();
        $currentNpcData=$npcMaster->getByMD5($activeProfile);
        $profileMgr=new CoreProfile();
        $profileData=$profileMgr->getBySlot($gameRequest[3]);
        if (is_array($currentNpcData)) {
            $currentNpcData["profile_id"]=$profileData["id"];
            $npcMaster->updateByArray($currentNpcData);
            error_log("[CORE SYSTEM] <{$currentNpcData["npc_name"]}> asigned to slot <{$profileData["label"]}>");
            
        } else {
            error_log("[CORE SYSTEM] No valid NPC found {$activeProfile}");
        }
    } else {
        error_log("[CORE SYSTEM] No valid profile specified");
    }
    
    $MUST_END=true;
    
    
} elseif (strpos($gameRequest[0], "switchrace")===0) {    // diary_nearby event - manual trigger for all NPCs in range
    
    logEvent($gameRequest);
    
    $MUST_END=true;
    
    
} 

// Trigger narrator welcome message if flagged during init
if (isset($GLOBALS["TRIGGER_NARRATOR_WELCOME"]) && $GLOBALS["TRIGGER_NARRATOR_WELCOME"]) {
    // Change the request type to narrator_welcome so main.php processes it
    $gameRequest[0] = "narrator_welcome";
    $MUST_END = false; // Don't end, continue to main.php
    
    // Load narrator profile
    require_once(__DIR__ . DIRECTORY_SEPARATOR . ".." . DIRECTORY_SEPARATOR . "lib" . DIRECTORY_SEPARATOR . "core" . DIRECTORY_SEPARATOR . "narrator.class.php");
    $narrator = new Narrator();
    
    // Get narrator profile ID
    $narratorProfileId = $narrator->getProfileId();
    if (!$narratorProfileId) {
        // Try to find The Narrator profile
        $narratorProfile = $db->fetchOne("SELECT id FROM core_profiles WHERE name = 'The Narrator' LIMIT 1");
        if ($narratorProfile && isset($narratorProfile['id'])) {
            $narratorProfileId = $narratorProfile['id'];
        }
    }
    
    if ($narratorProfileId) {
        if (function_exists('dialecticRuntimeSetActiveProfile')) {
            dialecticRuntimeSetActiveProfile($narratorProfileId);
        } else {
            $GLOBALS["active_profile"] = $narratorProfileId;
        }
    } else {
        Logger::warn("[NARRATOR_WELCOME] Could not find narrator profile, welcome message cancelled");
        $MUST_END = true;
    }
}

// Trigger narrator quest comment if flagged during quest event
if (isset($GLOBALS["TRIGGER_NARRATOR_QUEST_COMMENT"]) && $GLOBALS["TRIGGER_NARRATOR_QUEST_COMMENT"]) {
    // Change the request type to narrator_quest_comment so main.php processes it
    $gameRequest[0] = "narrator_quest_comment";
    $MUST_END = false; // Don't end, continue to main.php
    
    // Load narrator profile
    require_once(__DIR__ . DIRECTORY_SEPARATOR . ".." . DIRECTORY_SEPARATOR . "lib" . DIRECTORY_SEPARATOR . "core" . DIRECTORY_SEPARATOR . "narrator.class.php");
    $narrator = new Narrator();
    
    // Get narrator profile ID
    $narratorProfileId = $narrator->getProfileId();
    if (!$narratorProfileId) {
        // Try to find The Narrator profile
        $narratorProfile = $db->fetchOne("SELECT id FROM core_profiles WHERE name = 'The Narrator' LIMIT 1");
        if ($narratorProfile && isset($narratorProfile['id'])) {
            $narratorProfileId = $narratorProfile['id'];
        }
    }
    
    if ($narratorProfileId) {
        if (function_exists('dialecticRuntimeSetActiveProfile')) {
            dialecticRuntimeSetActiveProfile($narratorProfileId);
        } else {
            $GLOBALS["active_profile"] = $narratorProfileId;
        }
    } else {
        Logger::warn("[NARRATOR_QUEST_COMMENT] Could not find narrator profile, quest comment cancelled");
        $MUST_END = true;
    }
}

?>
