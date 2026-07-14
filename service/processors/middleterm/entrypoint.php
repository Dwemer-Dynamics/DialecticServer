<?php 

$GLOBALS["TASKS"]["middleterm"]=[];
$GLOBALS["TASKS"]["middleterm"]["fn"]=function() {

    $enginePath = $GLOBALS["ENGINE_ROOT"];
    $GLOBALS["ENGINE_PATH"]=$enginePath;

    
	if (!isset($GLOBALS["db"])) { $GLOBALS["db"] = new sql(); }
    
    require_once($enginePath . "prompts/command_prompt.php");
    require_once($enginePath . "lib/chat_helper_functions.php");
    require_once($enginePath . "lib/data_functions.php");
    require_once($enginePath . "lib/utils_game_timestamp.php");

    require_once $enginePath . "lib/core/npc_master.class.php";
    require_once $enginePath . "lib/core/api_badge.class.php";
    require_once $enginePath . "lib/core/core_profiles.class.php";
    require_once $enginePath . "lib/core/llm_connector.class.php";

    //$results = $GLOBALS["db"]->fetchAll("select max(gamets_truncated) as gamets_truncated from memory_summary where summary is not null order by gamets_truncated desc limit 1"); //0.8ms
    $results = $GLOBALS["db"]->fetchAll("select max(gamets_truncated) as gamets_truncated from memory_summary where summary is not null"); // 0.5ms, faster 
    $lastMemory = intval($results[0]["gamets_truncated"]);
    
    //$results = $GLOBALS["db"]->fetchAll("select max(gamets) as gamets from eventlog ORDER BY gamets desc limit 1");
    $results = $GLOBALS["db"]->fetchAll("select max(gamets) as gamets from eventlog"); // faster
    $maxRow = intval($results[0]["gamets"]);

    $allEnabledMtNpc=$GLOBALS["db"]->fetchAll("
        SELECT n.*
        FROM core_npc_master n
        LEFT JOIN core_profiles p ON p.id = n.profile_id
        WHERE
            lower(COALESCE(n.extended_data->>'middle_term_enabled', '')) IN ('1', 'true', 't', 'yes', 'on')
            OR (
                NOT (COALESCE(n.extended_data, '{}'::jsonb) ? 'middle_term_enabled')
                AND lower(COALESCE(p.metadata->>'MIDDLE_TERM_MEMORY_ENABLED', '')) IN ('1', 'true', 't', 'yes', 'on')
            )
    ");

    foreach ($allEnabledMtNpc as $npc) {
        $mwdata=json_decode($npc["extended_data"]);
        //echo "[MIDDLETERM] {$npc["npc_name"]} has middleterm memory enabled".PHP_EOL;
        $GLOBALS["SELECTED_NPC"]=$npc["npc_name"];
        require("cmd" . DIRECTORY_SEPARATOR . "generate.php");
    }

    $pfi = intval($GLOBALS["FEATURES"]["MEMORY_EMBEDDING"]["AUTO_CREATE_SUMMARY_INTERVAL"] ?? 10) * 100000;
    $minimumEvents = max(1, intval($GLOBALS["FEATURES"]["MEMORY_EMBEDDING"]["AUTO_CREATE_SUMMARY_MIN_EVENTS"] ?? 5));
    $autoCreateValue = strtolower(trim((string)($GLOBALS["FEATURES"]["MEMORY_EMBEDDING"]["AUTO_CREATE_SUMMARYS"] ?? 'true')));
    $autoCreateEnabled = !in_array($autoCreateValue, ['0', 'false', 'off', 'no'], true);

    // PackIntoSummary intentionally leaves roughly one in-game hour unsettled so
    // late events can land in the correct memory bucket. Check the same eligible
    // range here; TTW can create large game-time jumps that otherwise cause this
    // five-second task to invoke an empty compaction forever.
    $settledCutoff = $maxRow - intval(1 / 0.0000024);
    $eligibleResult = $GLOBALS["db"]->fetchOne(
        "SELECT COUNT(*) AS n FROM memory_v WHERE gamets > {$lastMemory} AND gamets < {$settledCutoff}"
    );
    $eligibleEvents = intval($eligibleResult["n"] ?? 0);

    if ($autoCreateEnabled && ($maxRow - $lastMemory) > $pfi && $eligibleEvents >= $minimumEvents) {
        $memoryUtility = escapeshellarg($GLOBALS["ENGINE_PATH"] . "/debug/util_memory_subsystem.php");
        Logger::info("[MEMORY] Starting automatic compaction eligible_events={$eligibleEvents} last_memory={$lastMemory} max_event={$maxRow}");
        $shellResult = shell_exec("php {$memoryUtility} compact embed 1 2>&1");

        if ($shellResult === null) {
            Logger::warn("[MEMORY] Automatic compaction returned no process output");
        } else {
            $compactOutput = trim((string)$shellResult);
            if (strlen($compactOutput) > 2000) {
                $compactOutput = substr($compactOutput, -2000);
            }
            Logger::info("[MEMORY] Automatic compaction finished output=" . $compactOutput);
        }
    }

    //unset($GLOBALS["db"]);
    
}
?>
