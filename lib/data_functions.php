<?php

require_once(__DIR__."/utils.php");
// used for openai_token_count table

require_once(__DIR__."/utils_game_timestamp.php");
require_once(__DIR__."/lazy_xml.php");
require_once(__DIR__."/model_dynmodel.php");
require_once(__DIR__."/emote_moods.php");
require_once(__DIR__."/core/activity_status.php");
require_once(__DIR__."/core/game_plugins.php");
require_once(__DIR__."/core/npc_master.class.php");
require_once(__DIR__."/core/core_profiles.class.php");
require_once(__DIR__."/prompt_injections.php");
require_once(__DIR__."/memory_ranking.php");


function ChangeDialecticName($new_name="") {
    if ($new_name > "") {
        SaveOriginalDialecticName();
        $GLOBALS["DIALECTIC_NAME"] = $new_name;
    }
}

function SaveOriginalDialecticName() {
    $b_already_saved = ($GLOBALS["ORIGINAL_DIALECTIC_NAME_SAVED"] ?? false);
    if (!$b_already_saved) {
        $dialectic = ($GLOBALS["DIALECTIC_NAME"] ?? "");
                if ((strlen($dialectic) > 0) && ($dialectic !== "The Narrator") && ($dialectic !== "Player") && ($dialectic !== "LLMFallback") && (stripos($dialectic, "Narrator") === false) && (stripos($dialectic, "actor") === false) && (stripos($dialectic, "everyone") === false) && (stripos($dialectic, "*") === false) && (stripos($dialectic, "none") === false) ) {
            $GLOBALS["ORIGINAL_DIALECTIC_NAME"] = $dialectic;
            $GLOBALS["ORIGINAL_DIALECTIC_NAME_SAVED"] = true;
        }
    }
}

function GetOriginalDialecticName() {
    $b_already_saved = ($GLOBALS["ORIGINAL_DIALECTIC_NAME_SAVED"] ?? false);
    if ($b_already_saved) {
        $dialectic = $GLOBALS["ORIGINAL_DIALECTIC_NAME"] ?? '';
    } else {
        $dialectic = $GLOBALS["DIALECTIC_NAME"];
    }
    return $dialectic;
} 

function get_connector_id($s_driver='', $s_model='', $s_url='') {
    $i_res = -1;
    if (isset($GLOBALS["DIALECTIC_CORE_CURRENT_CONNECTOR_DATA"])) {
        $i_res = $GLOBALS["DIALECTIC_CORE_CURRENT_CONNECTOR_DATA"]["id"] ?? -1;
    }
    if ($i_res < 0) {
        if ((strlen($s_model) > 0) && (strlen($s_url) > 0)  && (strlen($s_driver) > 0)) {
            $query = "SELECT id FROM public.core_llm_connector WHERE (url='{$s_url}') AND (model='{$s_model}') AND (driver='{$s_driver}') LIMIT 1 ";
            $ret = $GLOBALS["db"]->fetchAll($query);
            if ($ret) {
                $i_res = intval($ret[0]['id'] ?? -1);
            }
        }
    }
    return $i_res;
}

function ReplacePlayerNamePlaceholder($s_input) {
    //replace #PLAYER_NAME# with player name
    $s_res = $s_input;
    if ((strlen(trim($s_input))) > 12) {
        $promptCharacterName = function_exists('dialecticGetPromptCharacterName')
            ? dialecticGetPromptCharacterName()
            : $GLOBALS["DIALECTIC_NAME"];
        $narratorRoleplayName = function_exists('dialecticGetNarratorRoleplayName')
            ? dialecticGetNarratorRoleplayName()
            : 'The Narrator';
        $s_res = strtr($s_input, [
            "{DIALECTIC_NAME}" =>$promptCharacterName,
            "{NARRATOR_NAME}" =>$narratorRoleplayName,
            "{PLAYER_NAME}"=>$GLOBALS["PLAYER_NAME"],
            "#DIALECTIC_NAME#" =>$promptCharacterName,
            "#NARRATOR_NAME#" =>$narratorRoleplayName,
            "#PLAYER_NAME#"=>$GLOBALS["PLAYER_NAME"]
        ]);
    }
    return $s_res;
}

if (!function_exists('dialecticAppendDiaryConnectorCandidate')) {
    function dialecticAppendDiaryConnectorCandidate(array &$candidates, string $candidate): void
    {
        $normalized = strtolower(trim($candidate));
        if ($normalized === '' || $normalized === 'array') {
            return;
        }

        switch ($normalized) {
            case 'openrouterjson':
                $normalized = 'openrouter';
                break;
            case 'openaijson':
                $normalized = 'openai';
                break;
            case 'koboldcppjson':
                $normalized = 'koboldcpp';
                break;
        }

        if (!in_array($normalized, $candidates, true)) {
            $candidates[] = $normalized;
        }
    }
}

if (!function_exists('dialecticExtractDiaryConnectorCandidates')) {
    function dialecticExtractDiaryConnectorCandidates($value): array
    {
        $candidates = [];

        $pushValue = function ($candidate) use (&$candidates, &$pushValue): void {
            if (is_array($candidate)) {
                foreach ($candidate as $nestedCandidate) {
                    $pushValue($nestedCandidate);
                }
                return;
            }

            if (!is_scalar($candidate)) {
                return;
            }

            $stringValue = trim((string)$candidate);
            if ($stringValue === '') {
                return;
            }

            if (($stringValue[0] === '[' || $stringValue[0] === '{')) {
                $decoded = json_decode($stringValue, true);
                if (is_array($decoded)) {
                    foreach ($decoded as $decodedCandidate) {
                        $pushValue($decodedCandidate);
                    }
                    return;
                }
            }

            if (strpos($stringValue, ',') !== false) {
                foreach (explode(',', $stringValue) as $splitCandidate) {
                    $pushValue($splitCandidate);
                }
                return;
            }

            dialecticAppendDiaryConnectorCandidate($candidates, $stringValue);
        };

        $pushValue($value);

        return $candidates;
    }
}

if (!function_exists('dialecticResolveDiaryConnectorName')) {
    function dialecticResolveDiaryConnectorName($rawValue = null, bool $persistGlobal = true): ?string
    {
        $connectorDir = __DIR__ . DIRECTORY_SEPARATOR . ".." . DIRECTORY_SEPARATOR . "connector" . DIRECTORY_SEPARATOR;
        $sourceValue = func_num_args() > 0 ? $rawValue : ($GLOBALS["CONNECTORS_DIARY"] ?? null);

        $candidates = dialecticExtractDiaryConnectorCandidates($sourceValue);
        foreach (dialecticExtractDiaryConnectorCandidates($GLOBALS["CONNECTORS"] ?? null) as $candidate) {
            if (!in_array($candidate, $candidates, true)) {
                $candidates[] = $candidate;
            }
        }

        foreach (['openrouter', 'openai', 'google_openaijson', 'koboldcpp'] as $fallbackCandidate) {
            if (!in_array($fallbackCandidate, $candidates, true)) {
                $candidates[] = $fallbackCandidate;
            }
        }

        foreach ($candidates as $candidate) {
            if (file_exists($connectorDir . $candidate . ".php")) {
                if ($persistGlobal) {
                    $originalValue = is_scalar($sourceValue) ? trim((string)$sourceValue) : json_encode($sourceValue);
                    if ($originalValue !== $candidate) {
                        Logger::warn("DIARY: Resolved CONNECTORS_DIARY value " . var_export($sourceValue, true) . " to '{$candidate}'");
                    }
                    $GLOBALS["CONNECTORS_DIARY"] = $candidate;
                }
                return $candidate;
            }
        }

        return null;
    }
}

function getCapsFromMetadata($npcName = null) {
    if ($npcName === null) {
        $npcName = isset($GLOBALS["DIALECTIC_NAME"]) ? $GLOBALS["DIALECTIC_NAME"] : "";
    }
    
    if (empty($npcName)) {
        return 0;
    }
    
    try {
        $npcMaster = new NpcMaster();
        $npcData = $npcMaster->getByName($npcName);
        
        if (!$npcData) {
            return 0;
        }
        
        $metaData = $npcMaster->getMetaData($npcData);
        
        if (!isset($metaData["inventory"]) || !is_array($metaData["inventory"])) {
            return 0;
        }
        
        foreach ($metaData["inventory"] as $item) {
            $itemName = isset($item["name"]) ? strtolower($item["name"]) : "";
            if (stripos($itemName, "cap") !== false || stripos($itemName, "caps") !== false || stripos($itemName, "coin") !== false) {
                return isset($item["count"]) ? intval($item["count"]) : 0;
            }
        }
    } catch (Exception $e) {
        // Silently fail and return 0
    }
    
    return 0;
}

function isItemBlacklisted($itemName) {
    if (!isset($GLOBALS["ITEM_BLACKLIST"]) || empty($GLOBALS["ITEM_BLACKLIST"])) {
        return false;
    }
    
    $blacklistedItems = array_map('trim', explode(',', $GLOBALS["ITEM_BLACKLIST"]));
    $itemNameLower = strtolower(trim($itemName));
    
    foreach ($blacklistedItems as $blacklistedItem) {
        if (strtolower($blacklistedItem) === $itemNameLower) {
            return true;
        }
    }
    
    return false;
}

function dialecticNormalizePromptFormId($value): ?string
{
    $formId = trim((string)$value);
    if ($formId === '') {
        return null;
    }

    if (stripos($formId, '0x') === 0) {
        $formId = substr($formId, 2);
    }

    if (!preg_match('/^[0-9a-f]{1,8}$/i', $formId)) {
        return null;
    }

    return '0x' . strtoupper(str_pad($formId, 8, '0', STR_PAD_LEFT));
}

function dialecticEscapePromptItemText($value): string
{
    $value = str_replace(["\r", "\n", "\t"], ' ', (string)$value);
    $value = trim(preg_replace('/\s+/', ' ', $value) ?? $value);
    $value = htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    return str_replace('`', '&#96;', $value);
}

function dialecticInventoryMetadataLabels(array $item): array
{
    $labels = [];
    if (!empty($item['equipped'])) {
        $labels[] = 'equipped';
    }

    if (isset($item['condition']) && is_numeric($item['condition']) && (float)$item['condition'] >= 0) {
        $condition = (float)$item['condition'];
        $labels[] = 'condition ' . ($condition <= 1.0
            ? (string)round($condition * 100) . '%'
            : rtrim(rtrim(number_format($condition, 2, '.', ''), '0'), '.'));
    }

    if (isset($item['value']) && is_numeric($item['value']) && (int)$item['value'] >= 0) {
        $labels[] = 'value ' . (int)$item['value'] . ' caps';
    }

    $ammo = trim((string)($item['ammo'] ?? ''));
    if ($ammo !== '') {
        $labels[] = 'ammo ' . dialecticEscapePromptItemText($ammo);
    }

    $mods = $item['mods'] ?? [];
    if (is_string($mods)) {
        $decodedMods = json_decode($mods, true);
        $mods = is_array($decodedMods) ? $decodedMods : preg_split('/\s*[,|]\s*/', $mods, -1, PREG_SPLIT_NO_EMPTY);
    }
    if (is_array($mods)) {
        $safeMods = [];
        foreach ($mods as $mod) {
            $modName = is_array($mod) ? ($mod['name'] ?? $mod['id'] ?? '') : $mod;
            $modName = trim((string)$modName);
            if ($modName !== '') {
                $safeMods[] = dialecticEscapePromptItemText($modName);
            }
        }
        if ($safeMods) {
            $labels[] = 'mods ' . implode(', ', $safeMods);
        }
    }

    return $labels;
}

function dialecticFormatInventoryPromptLines(
    array $inventory,
    callable $getItemDescription = null,
    array &$describedBaseids = [],
    bool $descriptionsOnly = false
): array {
    $lines = [];
    $normalizedDescribedBaseids = [];
    foreach ($describedBaseids as $describedBaseid) {
        $normalized = dialecticNormalizePromptFormId($describedBaseid);
        if ($normalized !== null) {
            $normalizedDescribedBaseids[] = $normalized;
        }
    }

    foreach ($inventory as $item) {
        if (!is_array($item)) {
            continue;
        }

        $itemName = trim((string)($item['name'] ?? ''));
        if ($itemName === '' || $itemName === '<Missing Name>' || isItemBlacklisted($itemName)) {
            continue;
        }

        $count = max(0, (int)($item['count'] ?? 0));
        $rawBaseId = trim((string)($item['baseid'] ?? ''));
        $baseId = dialecticNormalizePromptFormId($rawBaseId);
        $description = null;

        if ($getItemDescription !== null && $count <= 5 &&
            ($baseId === null || !in_array($baseId, $normalizedDescribedBaseids, true))) {
            $description = $getItemDescription($itemName, $rawBaseId !== '' ? $rawBaseId : null);
            if ($description && $baseId !== null) {
                $describedBaseids[] = $baseId;
                $normalizedDescribedBaseids[] = $baseId;
            }
        }

        if ($descriptionsOnly && !$description) {
            continue;
        }

        $safeName = dialecticEscapePromptItemText($itemName);
        $identifier = $baseId !== null ? "`{$baseId}:{$safeName}`" : $safeName;
        $line = "- {$identifier} ({$count})";
        $metadata = dialecticInventoryMetadataLabels($item);
        if ($metadata) {
            $line .= ' [' . implode('; ', $metadata) . ']';
        }
        if ($description) {
            $line .= ' - ' . dialecticEscapePromptItemText($description);
        }
        $lines[] = $line;
    }

    return $lines;
}

function dialecticBuildInventoryPromptContext(
    array $inventory,
    callable $getItemDescription = null,
    array &$describedBaseids = [],
    bool $descriptionsOnly = false
): string {
    $lines = dialecticFormatInventoryPromptLines($inventory, $getItemDescription, $describedBaseids, $descriptionsOnly);
    if (!$lines) {
        return '';
    }

    return "<inventory>\n# INVENTORY\nFormat: BaseID:ItemName (quantity)\n\n"
        . implode("\n", $lines)
        . "\n</inventory>";
}

/**
 * Lookup a description by candidate base IDs while preserving override priority.
 * Custom rows must win across all wildcard/stable candidates before seeded defaults.
 */
function lookupDescriptionRecordByCandidates(array $candidateBaseIds, bool $requireDescription = false): ?array {
    global $db;

    $candidateRows = [];
    $pushCandidateRow = function (string $plugin, string $baseid) use (&$candidateRows): void {
        $plugin = trim($plugin);
        $baseid = trim($baseid);
        if ($baseid === '') {
            return;
        }

        $key = $plugin . '|' . $baseid;
        if (!isset($candidateRows[$key])) {
            $candidateRows[$key] = ['plugin' => $plugin, 'baseid' => $baseid];
        }
    };

    foreach ($candidateBaseIds as $candidateBaseId) {
        $candidateBaseId = trim((string) $candidateBaseId);
        if ($candidateBaseId === '') {
            continue;
        }

        if (strpos($candidateBaseId, '|') !== false) {
            $parsedStable = dialecticParseStableFormReference($candidateBaseId);
            if (!$parsedStable) {
                continue;
            }
            $plugin = $parsedStable['plugin_name'];
            $baseid = $parsedStable['local_formid'];
            $pushCandidateRow($plugin, $baseid);

            $pluginRow = function_exists('dialecticGetLoadedGamePluginByName')
                ? dialecticGetLoadedGamePluginByName($plugin)
                : null;
            if ($pluginRow && !empty($pluginRow['formid_prefix']) && function_exists('dialecticComputeRuntimeFormIdFromPrefix')) {
                $runtimeBaseid = dialecticComputeRuntimeFormIdFromPrefix($pluginRow['formid_prefix'], $baseid);
                if ($runtimeBaseid !== null && $runtimeBaseid !== $baseid) {
                    $pushCandidateRow($plugin, $runtimeBaseid);
                }
            }
        } else {
            $pushCandidateRow('', strtoupper($candidateBaseId));
        }
    }

    foreach (['descriptions_custom', 'descriptions'] as $tableName) {
        foreach ($candidateRows as $candidateRow) {
            $escapedPlugin = $db->escape($candidateRow['plugin']);
            $escapedBaseId = $db->escape($candidateRow['baseid']);
            $record = $db->fetchOne(
                "SELECT plugin, baseid, name, description
                   FROM public.{$tableName}
                  WHERE plugin = '{$escapedPlugin}'
                    AND baseid = '{$escapedBaseId}'
                  LIMIT 1"
            );

            if (!$record) {
                continue;
            }

            if ($requireDescription && empty($record['description'])) {
                continue;
            }

            return $record;
        }
    }

    return null;
}

/**
 * Lookup description from the merged descriptions view using runtime, wildcard, or stable keys.
 * Supports:
 * - exact runtime FormIDs (e.g. 020098A0)
 * - wildcard keys (e.g. XX0098A0, FEXXX822)
 * - internal plugin-aware candidates generated from loaded plugin metadata
 * 
 * @param string $formId The identifier to lookup
 * @return array|null Array with 'baseid', 'name', and 'description' keys, or null if not found
 */
function lookupDescriptionByFormID(string $formId): ?array {
    return lookupDescriptionRecordByCandidates(dialecticBuildDescriptionBaseIdCandidates($formId));
}

/**
 * Lookup description only by exact runtime FormID or internal plugin-aware candidate.
 * This deliberately skips wildcard keys and name fallback to avoid
 * cross-matching unrelated item descriptions.
 *
 * @param string $formId The runtime or stable identifier to lookup
 * @return array|null Array with 'baseid', 'name', and 'description' keys, or null if not found
 */
function lookupStrictDescriptionByFormID(string $formId): ?array {
    $candidates = [];
    $pushCandidate = function ($candidate) use (&$candidates): void {
        $candidate = trim((string) $candidate);
        if ($candidate === '') {
            return;
        }

        if (strpos($candidate, '|') !== false) {
            $parsedStable = dialecticParseStableFormReference($candidate);
            if ($parsedStable) {
                $candidate = $parsedStable['stable_key'];
            }
        } else {
            $candidate = strtoupper($candidate);
        }

        if (!in_array($candidate, $candidates, true)) {
            $candidates[] = $candidate;
        }
    };

    $formId = trim($formId);
    if ($formId === '') {
        return null;
    }

    $parsedStableReference = dialecticParseStableFormReference($formId);
    if ($parsedStableReference) {
        $pushCandidate($parsedStableReference['stable_key']);

        $pluginRow = dialecticGetLoadedGamePluginByName($parsedStableReference['plugin_name']);
        if ($pluginRow && !empty($pluginRow['formid_prefix'])) {
            $runtimeFormId = dialecticComputeRuntimeFormIdFromPrefix(
                $pluginRow['formid_prefix'],
                $parsedStableReference['local_formid']
            );
            if ($runtimeFormId) {
                $pushCandidate($runtimeFormId);
            }
        }
    } else {
        $runtimeFormId = dialecticNormalizeRuntimeFormId($formId);
        if ($runtimeFormId !== '') {
            $pushCandidate($runtimeFormId);

            $pluginRow = dialecticGetLoadedGamePluginByRuntimeFormId($runtimeFormId);
            $localFormId = dialecticExtractLocalFormIdFromRuntimeFormId($runtimeFormId);
            if ($pluginRow && !empty($pluginRow['plugin_name']) && $localFormId !== '') {
                $pushCandidate(dialecticBuildStableFormReference($pluginRow['plugin_name'], $localFormId));
            }
        }
    }

    return lookupDescriptionRecordByCandidates($candidates, true);
}

/**
 * Get height description based on scale value
 * Reads height descriptions from prompts table with hardcoded fallback
 * 
 * @param float $scale The NPC scale value (typically 0.6 to 1.4)
 * @return string Height description or empty string if not found
 */
function getHeightDescription(float $scale): string {
    static $heightDescriptions = null;
    
    // Hardcoded fallback in case database fails
    $fallbackDescriptions = [
        ['name' => 'VerySmall', 'min_scale' => 0.0, 'max_scale' => 0.60, 'description' => 'Very small and tiny in stature'],
        ['name' => 'Small', 'min_scale' => 0.60, 'max_scale' => 0.80, 'description' => 'Smaller than most people'],
        ['name' => 'ModestStature', 'min_scale' => 0.80, 'max_scale' => 0.95, 'description' => 'Slightly below average height'],
        ['name' => 'Average', 'min_scale' => 0.95, 'max_scale' => 1.05, 'description' => 'Typical height'],
        ['name' => 'Tall', 'min_scale' => 1.05, 'max_scale' => 1.20, 'description' => 'Tall, standing a head above most people'],
        ['name' => 'VeryTall', 'min_scale' => 1.20, 'max_scale' => 1.40, 'description' => 'Very tall'],
        ['name' => 'Giantlike', 'min_scale' => 1.40, 'max_scale' => 99.0, 'description' => 'Giant in height and stature']
    ];
    
    // Load height descriptions from prompts table (cached)
    if ($heightDescriptions === null) {
        try {
            global $db;
            $result = $db->fetchOne("SELECT COALESCE(custom_prompt, default_prompt) as prompt FROM prompts WHERE prompt_key = 'height_descriptions'");
            
            if ($result && !empty($result['prompt'])) {
                $data = json_decode($result['prompt'], true);
                $heightDescriptions = $data['height_descriptions'] ?? $fallbackDescriptions;
            } else {
                // Database query succeeded but no data - use fallback
                $heightDescriptions = $fallbackDescriptions;
            }
        } catch (Exception $e) {
            // Database error - use fallback
            Logger::debug("Using fallback height descriptions due to database error: " . $e->getMessage());
            $heightDescriptions = $fallbackDescriptions;
        }
    }
    
    // Find matching height description
    foreach ($heightDescriptions as $desc) {
        if ($scale >= $desc['min_scale'] && $scale < $desc['max_scale']) {
            return $desc['description'];
        }
    }
    
    return ''; // No description if out of range
}


function DataDequeue($timestamp = 0)
{
    global $db;
    if ($timestamp !== 0) {
        $clause="and localts<={$timestamp} ";
    } else {
        $clause="";
    }
    // Use atomic UPDATE...RETURNING to prevent race conditions where multiple concurrent
    // requests could fetch the same dialogue before it's marked as sent
    $results = $db->fetchAll(
        "UPDATE responselog 
         SET sent=1 
         WHERE rowid IN (
             SELECT rowid FROM responselog WHERE sent=0 $clause ORDER BY rowid ASC
         )
         RETURNING *, rowid"
    );
    
    $finalData = array();
    foreach ($results as $row) {
        $finalData[] = $row;
    }

    return $finalData;

}

function DataLastDataFor($actor, $lastNelements = -10)
{
    global $db;
    $lastDialogFull = array();
    $results = $db->fetchAll("select  
    case 
      when type like 'info%' or type like 'death%' or  type like 'funcret%' or type like 'location%' or data like '%background chat%' then 'The Narrator:'
      else '' 
    end||a.data  as data 
    FROM  eventlog a WHERE data like '%$actor%' 
    and type<>'combatend'  
    and type<>'bored' and type<>'init' and type<>'lockpicked' and type<>'info' and type<>'funcret'  and type<>'quest'
    and type<>'user_input'
    and data not ilike '%sSubtitle%'
    and data not ilike '%sSpeakerName%'
    and type<>'funccall'  and type<>'togglemodel' order by gamets desc,ts desc,localts desc,rowid desc LIMIT 150 OFFSET 0");
    $lastData = "";


    foreach ($results as $row) {

        if ($lastData != md5($row["data"])) {
            if ((strpos($row["data"], "{$GLOBALS["DIALECTIC_NAME"]}:") !== false) || ((strpos($row["data"], "{$GLOBALS["PLAYER_NAME"]}:") !== false))) {
                $pattern = "/\(Context location:[^)]+?\)/"; // Remove only the exact context location pattern
                $replacement = "";
                $row["data"] = preg_replace($pattern, $replacement, $row["data"]); // // assistant vs user war
                if ((strpos($row["data"], "{$GLOBALS["DIALECTIC_NAME"]}:") !== false)) {
                    $role = "assistant";
                } else {
                    $role = "user";
                }

                $lastDialogFull[] = array('role' => $role, 'content' => $row["data"]);

            } else {
                $lastDialogFull[] = array('role' => 'user', 'content' => $row["data"]);
            }

        }
        $lastData = md5($row["data"]);

    }

    // Date issues

    foreach ($lastDialogFull as $n => $line) {

        $pattern = '/(\w+), (\d{1,2}:\d{2} (?:AM|PM)), (\d{1,2})(?:st|nd|rd|th) of ([A-Za-z\'\ ]+), (\d+)/';
        $replacement = 'Day name: $1, Hour: $2, Day Number: $3, Month: $4, Year: $5';
        $result = preg_replace($pattern, $replacement, $line["content"]);
        $lastDialogFull[$n]["content"] = $result;
    }


    // Clean context locations for Dialectics dialog.


    $lastDialogFullReversed = array_reverse($lastDialogFull);
    $lastDialog = array_slice($lastDialogFullReversed, $lastNelements);
    $last_location = null;


    return $lastDialog;

}

/**
 * Get context for actor to send to llm
 */
function dialecticCleanNearbyActorPromptText($text): string
{
    $text = trim((string)$text);
    if ($text === '') {
        return '';
    }

    $text = str_replace("''", "'", $text);
    $text = preg_replace('/\s+/', ' ', $text);
    return trim($text);
}

function dialecticAppendNearbyActorSentence(string $base, string $sentence): string
{
    $sentence = dialecticCleanNearbyActorPromptText($sentence);
    if ($sentence === '') {
        return $base;
    }

    $base = rtrim($base);
    if ($base === '') {
        return $sentence;
    }

    if (!preg_match('/[.!?]$/', $base)) {
        $base .= '.';
    }

    return $base . ' ' . $sentence;
}

function dialecticNormalizeUnknownProfileValue($value): string
{
    $value = trim((string)$value);
    if ($value === '' || strcasecmp($value, 'unknown') === 0 || strcasecmp($value, 'unknown unknown') === 0) {
        return '';
    }

    return $value;
}

function DataLastInfoFor($actorBeingCalled, $lastNelements = -2,$includeActorDescriptions=false,$excludeBusy=false)
{
    
    $lastDialog = array(); // Initialize the return array
    $followers=[];
    $actorsInRangeList=DataBeingsInCloseRange();
    $actorsInRange=strtr($actorsInRangeList,["|"=>"\n* "]);
    $actorDetailedList=explode("|",$actorsInRangeList);
    // Not always the same order
    shuffle($actorDetailedList);
    // error_log("[DataLastInfoFor] $actorsInRangeList");

    $nearbyContextOptionEnabled = static function (string $id): bool {
        if (function_exists('dialecticPromptContextOptionEnabled')) {
            return dialecticPromptContextOptionEnabled('enabled_nearby_actor_subsections', $id);
        }
        return true;
    };
    $nearbyActorsIncludeBasicSummary = $nearbyContextOptionEnabled('basic_summary');
    $nearbyActorsIncludeAppearance = $nearbyContextOptionEnabled('appearance');
    $nearbyActorsIncludeEquipment = $nearbyContextOptionEnabled('equipment');
    $nearbyActorsEquipmentDescriptions = $nearbyContextOptionEnabled('equipment_descriptions');
    $nearbyActorsIncludeActivity = $nearbyContextOptionEnabled('current_activity');
    $nearbyActorsIncludePower = $nearbyContextOptionEnabled('power_awareness');
    $nearbyActorsIncludeFactions = $nearbyContextOptionEnabled('factions');
    $nearbyActorsIncludeCustomState = $nearbyContextOptionEnabled('custom_state');

    $nearbyEquipmentDescriptionResolver = null;
    if ($nearbyActorsEquipmentDescriptions) {
        $nearbyEquipmentDescriptionResolver = static function ($itemName, $baseid = null) {
            global $db;

            if (!empty($baseid)) {
                $record = lookupDescriptionByFormID((string)$baseid);
                if (!empty($record['description'])) {
                    return (string)$record['description'];
                }
            }

            $itemName = trim((string)$itemName);
            if ($itemName !== '' && $itemName !== '<Missing Name>' && isset($db)) {
                $escapedName = $db->escape($itemName);
                $rows = $db->fetchAll("SELECT description FROM combined_descriptions WHERE LOWER(name) = LOWER('{$escapedName}') LIMIT 1");
                if (!empty($rows[0]['description'])) {
                    return (string)$rows[0]['description'];
                }
            }

            return null;
        };
    }
    
    // Track seen faction descriptions to avoid duplicates
    $seenFactionFormIDs = [];
    $factionDescriptions = []; // Store unique faction descriptions
    
    // Actors
    if ($actorsInRange && $includeActorDescriptions) {
        $actorDetailedListWithProfile=[];
        foreach ($actorDetailedList as $actor) {
            if (empty($actor))
                continue;
            if ($excludeBusy)
                if ((strpos($actor,"(busy)")>0)||(strpos($actor,"(dead)")>0))
                    continue;

            $actorName=trim(str_replace("(far away)","",$actor));
            if ($actorName==$GLOBALS["DIALECTIC_NAME"]) 
                continue;

            /* if (!(strpos($GLOBALS["DIALECTIC_NAME"],"actor")===false)) { // debug
                Logger::warn("DataLastInfoFor: unexpected value for DIALECTIC_NAME={$GLOBALS["DIALECTIC_NAME"]} | actor={$actor} actorname={$actorName} ");
            } */

            if ((strpos($actor,"(")===false) && ($GLOBALS["DIALECTIC_NAME"]!="The Narrator") && (strpos($GLOBALS["DIALECTIC_NAME"],"actor")===false)) {   
                $interactions=DirectConversationsWith($actor);
                if ($interactions==0) {
                    $ittext="{$actor} ({$GLOBALS["DIALECTIC_NAME"]} never talked to {$actorName} before, {$GLOBALS["DIALECTIC_NAME"]} should speak to this person as to a stranger or traveler...)";
                } else if ($interactions<5) {
                    $ittext="{$actor} ({$GLOBALS["DIALECTIC_NAME"]} has talked to {$actorName} a couple of times before)";
                } else {
                    $ittext="{$actor}";
                }
            } else {
                $ittext="{$actor}";
            }

            if ($actor==$GLOBALS["PLAYER_NAME"]) {
                // Player - read from core_player table (don't reveal they're "the player character")
                $profileString = "$actor";
                
                try {
                    require_once(__DIR__ . DIRECTORY_SEPARATOR . "core" . DIRECTORY_SEPARATOR . "player.class.php");
                    $player = new Player();
                    
                    // Add appearance if available
                    $appearance = $player->get('appearance');
                    if ($nearbyActorsIncludeAppearance && !empty($appearance)) {
                        $profileString .= ": " . dialecticCleanNearbyActorPromptText($appearance);
                    }

                    $playerBio = ResolvePlayerBackstory($player);
                    $bioKnownByAll = filter_var((string)($player->get('bio_known_by_all') ?? ''), FILTER_VALIDATE_BOOLEAN);
                    $isNarrator = isset($GLOBALS["DIALECTIC_NAME"]) && strcasecmp((string)$GLOBALS["DIALECTIC_NAME"], "The Narrator") === 0;
                    if ($nearbyActorsIncludeBasicSummary && $playerBio !== "" && ($bioKnownByAll || $isNarrator)) {
                        $profileString = dialecticAppendNearbyActorSentence($profileString, "Backstory: " . $playerBio);
                    }
                    
                    // Add equipment if available
                    $playerMetadata = [
                        'equipment_structured' => $player->getJson('equipment_structured'),
                        'equipment' => $player->getJson('equipment'),
                    ];
                    $equipmentLines = $nearbyActorsIncludeEquipment
                        ? dialecticBuildEquipmentLinesFromMetadata($playerMetadata, $nearbyEquipmentDescriptionResolver)
                        : [];
                    if ($nearbyActorsIncludeEquipment && !empty($equipmentLines)) {
                        $equipmentText = implode(', ', array_map(static function ($line) {
                            return preg_replace('/^-\s*/', '', $line);
                        }, $equipmentLines));
                        $profileString = dialecticAppendNearbyActorSentence($profileString, "Equipment: " . $equipmentText);
                    }

                    if ($nearbyActorsIncludeCustomState) {
                        $profileExtra = dialecticBuildActorProfileEnrichmentText($actor, "player", [
                            "source" => "nearby_actors",
                        ]);
                        if ($profileExtra !== "") {
                            $profileString = dialecticAppendNearbyActorSentence($profileString, $profileExtra);
                        }
                    }
                    
                    // Power Awareness: Add relative power assessment for player
                    if ($nearbyActorsIncludePower && isset($GLOBALS["POWER_AWARENESS_ENABLED"]) && $GLOBALS["POWER_AWARENESS_ENABLED"]) {
                        require_once(__DIR__ . DIRECTORY_SEPARATOR . "power_awareness.php");
                        
                        // Get player's level
                        $playerLevel = getPlayerLevel();
                        
                        // Get assessing actor's level (the NPC looking at the player)
                        if (!empty($GLOBALS["DIALECTIC_NAME"])) {
                            $assessorLevel = getNpcLevel($GLOBALS["DIALECTIC_NAME"]);
                            
                            if ($assessorLevel !== null && $playerLevel !== null) {
                                $powerComparison = calculatePowerComparison($assessorLevel, $playerLevel);
                                $profileString .= " ({$powerComparison})";
                            }
                        }
                    }
                    
                } catch (Exception $e) {
                    Logger::debug("Could not load player data for context: " . $e->getMessage());
                }
                
                // Don't append $ittext for player - profileString already starts with player name
                $actorDetailedListWithProfile[] = $profileString;
                
            } else {
                
                $actorName = preg_replace("/\s*\(.*?\)\s*/", "", $actor);
                $codename = npcNameToCodename($actorName);
                $npcMaster=new NpcMaster();
                $currentNpcData=$npcMaster->getByName($actorName);

                
                if (isset($currentNpcData["core"]) && !empty($currentNpcData["core"])) {
                    // NPC name should always be at core section.
                    $npcName = $currentNpcData["npc_name"];
                    error_log("[DataLastInfoFor] Actors around, Checking NPC Name: " . $npcName." actors in range: ".$actorsInRangeList);
                    // Format gender (capitalize first letter)
                    $gender = dialecticNormalizeUnknownProfileValue($currentNpcData["gender"] ?? "");
                    if ($gender !== "") {
                        $gender = ucfirst(strtolower($gender));
                    }
                    $race = dialecticNormalizeUnknownProfileValue($currentNpcData["race"] ?? "");
                    
                    // Build name with race/gender in parentheses
                    $nameWithRaceGender = $npcName;
                    if (!empty($gender) && !empty($race)) {
                        $nameWithRaceGender .= " ({$gender} {$race})";
                    } elseif (!empty($race)) {
                        $nameWithRaceGender .= " ({$race})";
                    }
                    
                    $profileString = $nearbyActorsIncludeBasicSummary
                        ? "{$nameWithRaceGender}: " . dialecticCleanNearbyActorPromptText($currentNpcData["core"] ?? "")
                        : $nameWithRaceGender;
                    
                    // Add appearance if available
                    if ($nearbyActorsIncludeAppearance && !empty($currentNpcData["appearance"])) {
                        $profileString = dialecticAppendNearbyActorSentence($profileString, "Appearance: " . $currentNpcData["appearance"]);
                    }
                    
                    // Get metadata once for both scale and equipment
                    $metaData = $npcMaster->getMetaData($currentNpcData);
                    
                    // Add height description based on scale
                    if ($nearbyActorsIncludeAppearance && isset($metaData["stats"]["scale"])) {
                        $heightDesc = getHeightDescription(floatval($metaData["stats"]["scale"]));
                        if (!empty($heightDesc)) {
                            $profileString = dialecticAppendNearbyActorSentence($profileString, $heightDesc);
                        }
                    }
                    
                    // Power Awareness: Add relative power assessment
                    if ($nearbyActorsIncludePower && isset($GLOBALS["POWER_AWARENESS_ENABLED"]) && $GLOBALS["POWER_AWARENESS_ENABLED"]) {
                        require_once(__DIR__ . DIRECTORY_SEPARATOR . "power_awareness.php");
                        
                        // Get current NPC's level
                        $npcLevel = isset($metaData["stats"]["level"]) ? intval($metaData["stats"]["level"]) : null;
                        
                        // Get assessing actor's level (the NPC looking at this person)
                        if (!empty($GLOBALS["DIALECTIC_NAME"])) {
                            $assessorLevel = getNpcLevel($GLOBALS["DIALECTIC_NAME"]);
                            
                            if ($assessorLevel !== null && $npcLevel !== null) {
                                $powerComparison = calculatePowerComparison($assessorLevel, $npcLevel);
                                $profileString .= " ({$powerComparison})";
                            }
                        }
                    }

                    $activityStatus = dialecticNormalizeActivityStatus($metaData);
                    if ($nearbyActorsIncludeActivity && !empty($activityStatus['fresh']) && !empty($activityStatus['summary'])) {
                        $profileString = dialecticAppendNearbyActorSentence($profileString, "Current activity: " . $activityStatus['summary']);
                    }
                    
                    // Add equipment if available
                    $equipmentLines = $nearbyActorsIncludeEquipment
                        ? dialecticBuildEquipmentLinesFromMetadata($metaData, $nearbyEquipmentDescriptionResolver)
                        : [];
                    if ($nearbyActorsIncludeEquipment && !empty($equipmentLines)) {
                        $equipmentText = implode(', ', array_map(static function ($line) {
                            return preg_replace('/^-\s*/', '', $line);
                        }, $equipmentLines));
                        $profileString = dialecticAppendNearbyActorSentence($profileString, "Equipment: " . $equipmentText);
                    }

                    if ($nearbyActorsIncludeCustomState) {
                        $profileExtra = dialecticBuildActorProfileEnrichmentText($npcName, "npc", [
                            "source" => "nearby_actors",
                            "metadata" => $metaData,
                            "npc_data" => $currentNpcData,
                        ]);
                        if ($profileExtra !== "") {
                            $profileString = dialecticAppendNearbyActorSentence($profileString, $profileExtra);
                        }
                    }
                    
                    // Add faction information after equipment
                    $extendedData = $npcMaster->getExtendedData($currentNpcData);
                    if ($nearbyActorsIncludeFactions && isset($extendedData['factions']) && is_array($extendedData['factions']) && count($extendedData['factions']) > 0) {
                        $factionNames = [];
                        foreach ($extendedData['factions'] as $faction) {
                            if (isset($faction['formid'])) {
                                // Lookup faction using helper function (supports XX prefix)
                                $factionRecord = lookupDescriptionByFormID($faction['formid']);
                                
                                // Only add if found in descriptions table
                                if ($factionRecord && !empty($factionRecord['name'])) {
                                    $factionNames[] = $factionRecord['name'];
                                    
                                    // Track faction description (only once)
                                    if (!in_array($faction['formid'], $seenFactionFormIDs)) {
                                        $seenFactionFormIDs[] = $faction['formid'];
                                        if (!empty($factionRecord['description'])) {
                                            $factionDescriptions[$factionRecord['name']] = $factionRecord['description'];
                                        }
                                    }
                                }
                            }
                        }
                        
                        if (!empty($factionNames)) {
                            $profileString .= ". Groups " . implode(", ", $factionNames);
                        }
                    }
                    
                    $actorDetailedListWithProfile[] = $profileString;

                }
                else {
                    error_log("[DataLastInfoFor] Actors around, Checking NPC Name: " . $ittext. " with no profile data, actors in range: ".$actorsInRangeList);
                    $actorDetailedListWithProfile[] = $ittext;
                }
                
            }
        }
        $actorDetailedListWithProfileSanitized=[];
        foreach ($actorDetailedListWithProfile as $e)
            if (!empty($e))
                $actorDetailedListWithProfileSanitized[]=$e;

        if (!empty($actorDetailedListWithProfileSanitized))
            $actorsInRange=implode("\n## ",$actorDetailedListWithProfileSanitized);
        else 
            $actorsInRange="\nNo more actors in scene";// Catch
    }

    
    //Followers

    foreach (json_decode(DataGetCurrentPartyConf(),JSON_OBJECT_AS_ARRAY) as $followername=>$followerdata) {
        if (!$followername)
            continue;

        if ($followername==$GLOBALS["PLAYER_NAME"]) {
            $followers[]="$followername (roleplayed by player)";

        } else {
            if (isset($followerdata["core"]))
                $followers[]="{$followerdata["core"]} level {$followerdata["level"]},{$followerdata["gender"]} {$followerdata["race"]}";
            else
                $followers[]="$followername, level {$followerdata["level"]},{$followerdata["gender"]} {$followerdata["race"]}";
            
            $followersV2[]=$followername;

        }
            
    }

    $followers[]="{$GLOBALS["PLAYER_NAME"]}";
    $followersV2[]=$GLOBALS["PLAYER_NAME"];

    if (!isset($GLOBALS["PROMPT_NEARBY_SECTIONS"])) {
        $GLOBALS["PROMPT_NEARBY_SECTIONS"] = "";
    }
    $GLOBALS["PROMPT_NEARBY_SECTIONS"] .= "\n<nearby_actors>\n# NEARBY ACTORS/NPC IN THE SCENE \n## $actorsInRange\n</nearby_actors>";
    
    // Add faction descriptions section if any factions were found
    if (!empty($factionDescriptions)) {
        $factionDescText = "";
        foreach ($factionDescriptions as $name => $desc) {
            $factionDescText .= "## {$name}: {$desc}\n";
        }
        $GLOBALS["PROMPT_NEARBY_SECTIONS"] .= "\n<group_descriptions>\n# GROUP/FACTION DESCRIPTIONS\n{$factionDescText}</group_descriptions>";
    }
    
    // Add nearby items to context if available
    $itemsInRange = DataItemsInCloseRange();
    
    if (!empty($itemsInRange)) {
        $itemsList = explode(',', $itemsInRange);
        $formattedItems = [];
        $seenBaseIDs = [];
        $itemDescriptions = [];
        $groupedItems = [];
        $playerName = $GLOBALS["PLAYER_NAME"] ?? "Player";
        $playerLookingTag = " ({$playerName} is looking at this)";
        $playerHoldingTag = " ({$playerName} is holding this)";
        $shorterNearbyItemList = !empty($GLOBALS["SHORTER_NEARBY_ITEM_LIST"]);
        
        foreach ($itemsList as $item) {
            $trimmedItem = trim($item);
            if (empty($trimmedItem)) continue;
            
            // Parse nearby item strings derived from structured game data.
            $parts = explode(':', $trimmedItem, 3);
            
            if (count($parts) >= 3) {
                // RefID, BaseID, and item name.
                $refID = $parts[0];
                $baseID = $parts[1];
                $itemName = $parts[2];
                
                // Strip prompt-only tags for blacklist and description lookup.
                $itemNameClean = str_replace([' (STEALING)', $playerLookingTag, $playerHoldingTag], '', $itemName);
                
                // Skip blacklisted items
                if (isItemBlacklisted($itemNameClean)) {
                    continue;
                }
                
                // Track unique base IDs for descriptions
                $hasDescription = false;
                if (!in_array($baseID, $seenBaseIDs)) {
                    $seenBaseIDs[] = $baseID;
                    
                    // Look up descriptions through the shared form ID resolver.
                    $descRecord = lookupDescriptionByFormID($baseID);
                    
                    if ($descRecord && !empty($descRecord['description'])) {
                        // Store description under clean name (without STEALING tag)
                        $itemDescriptions[$itemNameClean] = $descRecord['description'];
                        $hasDescription = true;
                    }
                }
                
                // If filter is enabled and item has no description, skip it
                if (isset($GLOBALS["GROUND_ITEMS_DESCRIPTIONS_ONLY"]) && $GLOBALS["GROUND_ITEMS_DESCRIPTIONS_ONLY"] && !$hasDescription) {
                    continue;
                }
                
                if ($shorterNearbyItemList) {
                    $groupKey = $itemName;
                    if (!isset($groupedItems[$groupKey])) {
                        $groupedItems[$groupKey] = [
                            'count' => 0,
                            'sample_refid' => $refID,
                            'sample_baseid' => $baseID,
                            'sample_item_name' => $itemName,
                            'description' => $itemDescriptions[$itemNameClean] ?? '',
                        ];
                    }
                    $groupedItems[$groupKey]['count']++;
                    if (empty($groupedItems[$groupKey]['description']) && !empty($itemDescriptions[$itemNameClean])) {
                        $groupedItems[$groupKey]['description'] = $itemDescriptions[$itemNameClean];
                    }
                } else {
                    $safeName = dialecticEscapePromptItemText($itemName);
                    $normalizedRefId = dialecticNormalizePromptFormId($refID);
                    $normalizedBaseId = dialecticNormalizePromptFormId($baseID);
                    $displayItem = $normalizedRefId !== null
                        ? "- `{$normalizedRefId}:{$safeName}`"
                        : "- {$safeName}";
                    if ($normalizedBaseId !== null) {
                        $displayItem .= " [BaseID: `{$normalizedBaseId}`]";
                    }
                    $formattedItems[] = $displayItem;
                }
            } elseif (count($parts) == 2) {
                // RefID and item name only.
                $refID = $parts[0];
                $itemName = $parts[1];
                
                // Strip prompt-only tags for blacklist checks.
                $itemNameClean = str_replace([' (STEALING)', $playerLookingTag, $playerHoldingTag], '', $itemName);
                
                // Skip blacklisted items
                if (isItemBlacklisted($itemNameClean)) {
                    continue;
                }
                
                if ($shorterNearbyItemList) {
                    $groupKey = $itemName;
                    if (!isset($groupedItems[$groupKey])) {
                        $groupedItems[$groupKey] = [
                            'count' => 0,
                            'sample_refid' => $refID,
                            'sample_item_name' => $itemName,
                            'description' => '',
                        ];
                    }
                    $groupedItems[$groupKey]['count']++;
                } else {
                    $safeName = dialecticEscapePromptItemText($itemName);
                    $normalizedRefId = dialecticNormalizePromptFormId($refID);
                    $displayItem = $normalizedRefId !== null
                        ? "- `{$normalizedRefId}:{$safeName}`"
                        : "- {$safeName}";
                    $formattedItems[] = $displayItem;
                }
            }
        }

        if ($shorterNearbyItemList && !empty($groupedItems)) {
            foreach ($groupedItems as $group) {
                $safeName = dialecticEscapePromptItemText($group['sample_item_name']);
                $normalizedRefId = dialecticNormalizePromptFormId($group['sample_refid']);
                $normalizedBaseId = dialecticNormalizePromptFormId($group['sample_baseid'] ?? '');
                $displayItem = $normalizedRefId !== null
                    ? "- `{$normalizedRefId}:{$safeName}` ({$group['count']})"
                    : "- {$safeName} ({$group['count']})";
                if ($normalizedBaseId !== null) {
                    $displayItem .= " [BaseID: `{$normalizedBaseId}`]";
                }
                if (!empty($group['description'])) {
                    $displayItem .= ' - ' . dialecticEscapePromptItemText($group['description']);
                }
                $formattedItems[] = $displayItem;
            }
        }
        
        if (!empty($formattedItems)) {
            $itemsText = implode("\n", $formattedItems);
            
            // Add descriptions for unique items if available
            $descriptionText = "";
            if ($shorterNearbyItemList) {
                // Grouped items already carry their description inline.
            } elseif (!empty($itemDescriptions)) {
                $descParts = [];
                foreach ($itemDescriptions as $name => $desc) {
                    $descParts[] = dialecticEscapePromptItemText($name) . ': ' . dialecticEscapePromptItemText($desc);
                }
                $descriptionText = "\n\n# ITEM DESCRIPTIONS\n## " . implode("\n## ", $descParts);
            }

            $nearbyItemsHeader = $shorterNearbyItemList
                ? "# NEARBY ITEMS\nFormat: RefID:ItemName (quantity); use the RefID for PickupItem"
                : "# NEARBY ITEMS\nFormat: RefID:ItemName; use the RefID for PickupItem";
            $contextContent = "<nearby_items>\n{$nearbyItemsHeader}\n\n{$itemsText}{$descriptionText}\n</nearby_items>";
            if (!isset($GLOBALS["PROMPT_NEARBY_SECTIONS"])) {
                $GLOBALS["PROMPT_NEARBY_SECTIONS"] = "";
            }
            $GLOBALS["PROMPT_NEARBY_SECTIONS"] .= "\n" . $contextContent;
        }
    }
    
    /*
    if (!isset($GLOBALS["IS_NPC"]) || !$GLOBALS["IS_NPC"])
        $lastDialog[] = array('role' => 'user', 'content' => "# PARTY STATUS\n## ". (implode("\n## ",$followers)));
    else 
        $lastDialog[] = array('role' => 'user', 'content' => "# YOU'RE NOT PART OF THE GROUP FORMED BY\n## ". (implode("\n## ",$followers)));

    $arr_poi = DataPosibleLocationsToGo();
    if (isset($arr_poi) && is_array($arr_poi) && (count($arr_poi) > 0)) {
        $lastDialog[] = array('role' => 'user', 'content' => "# POIs - Points of Interest nearby \n## ". (implode("\n## ",$arr_poi)));
    }
    */
    if (!empty($followersV2)) {
        $lastFollower = array_pop($followersV2);
        if (!empty($followersV2)) {
            $followersString = implode(", ", $followersV2) . " and " . $lastFollower;
        } else {
            $followersString = $lastFollower;
        }
    } else {
        $followersString = "";
    }

	if ($followersString!=$GLOBALS["PLAYER_NAME"] && !empty($followersString)) {
	    if (!isset($GLOBALS["PROMPT_NEARBY_SECTIONS"])) {
	        $GLOBALS["PROMPT_NEARBY_SECTIONS"] = "";
	    }
	    $GLOBALS["PROMPT_NEARBY_SECTIONS"] .= "\n<adventuring_party>
        # ADVENTURING PARTY
	     $followersString are together as an **adventuring party**, acting as close companions.
	     - The others **can know each other**, but they are **not part** of {$followersString}'s group.
	     - Generally speaking, any mention of **plans, missions, or objectives** refers **only to the adventuring party**, never to the other NPCs.
	     </adventuring_party>";
	}
    $arr_poi = DataPosibleLocationsToGo();
    if (isset($arr_poi) && is_array($arr_poi) && (count($arr_poi) > 0)) {
        // Filter blacklisted locations
        if (isset($GLOBALS["LOCATION_BLACKLIST"]) && !empty($GLOBALS["LOCATION_BLACKLIST"])) {
            $blacklistedLocations = array_map('trim', explode(',', strtolower($GLOBALS["LOCATION_BLACKLIST"])));
            $arr_poi = array_filter($arr_poi, function($poi) use ($blacklistedLocations) {
                $poiLower = strtolower($poi);
                foreach ($blacklistedLocations as $blacklistedLocation) {
                    if (!empty($blacklistedLocation) && strpos($poiLower, $blacklistedLocation) !== false) {
                        return false;
                    }
                }
                return true;
            });
        }
        
        if (count($arr_poi) > 0) {
            if (!isset($GLOBALS["PROMPT_NEARBY_SECTIONS"])) {
                $GLOBALS["PROMPT_NEARBY_SECTIONS"] = "";
            }
            $GLOBALS["PROMPT_NEARBY_SECTIONS"] .= "\n<points_of_interest>\n# POIs - Points of Interest nearby \n## ". (implode("\n## ",$arr_poi))."\n</points_of_interest>";
        }
    }
    
    
 
    // Rolemaster notes
    
    $timeCut=time();
    $rolemasterNotes=$GLOBALS["db"]->fetchAll("SELECT data FROM rolemaster where type='scenenote' and localts+ttl>$timeCut order by localts asc");
    if (is_array($rolemasterNotes) && !empty($rolemasterNotes)) {
        $notes=[];
        foreach ($rolemasterNotes as $note)
            $notes[]= $note["data"];
        if (!isset($GLOBALS["PROMPT_NEARBY_SECTIONS"])) {
            $GLOBALS["PROMPT_NEARBY_SECTIONS"] = "";
        }
        $GLOBALS["PROMPT_NEARBY_SECTIONS"] .= "\n<scene_notes>\n# SCENE NOTES \n## ".implode(".",$notes)."</scene_notes>";
    }
        
    // Compact CHIM-style nearby actor list from the latest structured game snapshot.
    $nearbyActorsList = !empty($GLOBALS["DIALECTIC_ROLEMASTER_BORED_ACTORS"]) &&
        is_array($GLOBALS["DIALECTIC_ROLEMASTER_BORED_ACTORS"])
        ? array_values($GLOBALS["DIALECTIC_ROLEMASTER_BORED_ACTORS"])
        : dialecticNearbyActorNamesFromPayload(false, false);
    if (!empty($nearbyActorsList)) {
        if (!isset($GLOBALS["PROMPT_NEARBY_SECTIONS"])) {
            $GLOBALS["PROMPT_NEARBY_SECTIONS"] = "";
        }
        $GLOBALS["PROMPT_NEARBY_SECTIONS"] .= "\n<actors_nearby>\n" . implode(", ", $nearbyActorsList) . "\n</actors_nearby>";
    }
    

    $lastDialog=[];
    // This function originally returned an array, now it's directly filling PROMPT_NEARBY_SECTIONS.
    // MUST return an array, even if empty; Review where is called to ensure it's handled properly
    // Proposal: $lastDialog[]=array('role' => 'user', 'content' => $GLOBALS["PROMPT_NEARBY_SECTIONS"]);
    return $lastDialog;

}

function DataLocationsAround($current_location = "") {
    $locations = DataPosibleLocationsToGo();
    if (!is_array($locations) || empty($locations)) {
        return "";
    }

    if (strlen($current_location) > 0) {
        $currentLocation = strtolower(trim($current_location));
        $locations = array_values(array_filter($locations, static function ($location) use ($currentLocation) {
            return stripos((string)$location, $currentLocation) !== false;
        }));
    }

    return implode(",", $locations);
}

function DataPosibleLocationsToGo()
{
    if (isset($GLOBALS["CACHE_POSIBLE_LOCATIONS_TO_GO"])) {
        return $GLOBALS["CACHE_POSIBLE_LOCATIONS_TO_GO"];
    }

    $retData = dialecticPointsOfInterestFromStructuredPayload();

    // Location blacklist // $LOCATION_BLACKLIST
    if (isset($GLOBALS["LOCATION_BLACKLIST"]) && (strlen($GLOBALS["LOCATION_BLACKLIST"])>0)) {
        $LOCATION_BLACKLIST_ARRAY = explode(",", $GLOBALS["LOCATION_BLACKLIST"]); 
        //$LOCATION_BLACKLIST_ARRAY = empty($GLOBALS["LOCATION_BLACKLIST"]) ? [] : explode(",", $GLOBALS["LOCATION_BLACKLIST"]); 
        if (count($LOCATION_BLACKLIST_ARRAY) > 0) {
            foreach ($retData as $k => $v) {
                foreach ($LOCATION_BLACKLIST_ARRAY as $blacklistedLocation) {
                    $blacklistedLocationTrimmed = trim($blacklistedLocation);
                    if (!empty($blacklistedLocationTrimmed) && (stripos($v, $blacklistedLocationTrimmed) !== false)) {
                        unset($retData[$k]);
                        break; // No need to check other blacklisted locations
                    }
                }
            }
        }
    }
    
    foreach ($retData as $k => $v) {
        if ($v=="Fallout") {
            $retData[$k].=" (exit)";
        }
    }
    $GLOBALS["CACHE_POSIBLE_LOCATIONS_TO_GO"] = array_values($retData);
    return $GLOBALS["CACHE_POSIBLE_LOCATIONS_TO_GO"];
}

function dialecticLatestPointsOfInterestPayload()
{
    global $db;

    $rows = $db->fetchAll("SELECT party FROM eventlog WHERE type='points_of_interest' AND COALESCE(party, '') <> '' ORDER BY gamets DESC, ts DESC LIMIT 1 OFFSET 0");
    if (!is_array($rows) || empty($rows)) {
        return null;
    }

    $payload = json_decode($rows[0]['party'] ?? '', true);
    return is_array($payload) ? $payload : null;
}

function dialecticPointsOfInterestFromStructuredPayload()
{
    $payload = dialecticLatestPointsOfInterestPayload();
    if (!is_array($payload)) {
        return [];
    }

    $pois = $payload['pois'] ?? [];
    if (!is_array($pois) || empty($pois)) {
        return [];
    }

    $playerName = trim((string)($payload['player'] ?? ($GLOBALS["PLAYER_NAME"] ?? "Player")));
    $retData = [];
    foreach ($pois as $poi) {
        if (!is_array($poi)) {
            continue;
        }

        $displayName = trim((string)($poi['display_name'] ?? ''));
        if ($displayName === "") {
            $displayName = trim((string)($poi['destination_name'] ?? ''));
        }
        if ($displayName === "") {
            $displayName = trim((string)($poi['name'] ?? ''));
        }
        if ($displayName === "" || $displayName === "Unknown") {
            continue;
        }

        if (filter_var($poi['locked'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
            $displayName .= " (locked)";
        }
        if (filter_var($poi['looking_at'] ?? false, FILTER_VALIDATE_BOOLEAN) && $playerName !== "") {
            $displayName .= " ({$playerName} is looking at this)";
        }

        if (!in_array($displayName, $retData, true)) {
            $retData[] = $displayName;
        }
    }

    return $retData;
}

function DataPosibleMoveToTargets()
{
    if (isset($GLOBALS["CACHE_POSIBLE_MOVETO_TARGETS"])) {
        return $GLOBALS["CACHE_POSIBLE_MOVETO_TARGETS"];
    }

    $retData = dialecticNearbyActorNamesFromPayload(true, false);

    $GLOBALS["CACHE_POSIBLE_MOVETO_TARGETS"] = array_values($retData);
    return $GLOBALS["CACHE_POSIBLE_MOVETO_TARGETS"];
}

function DataPosibleInspectTargets($pack=true)
{
    if (isset($GLOBALS["CACHE_POSIBLE_INSPECT_TARGETS"][(int)$pack])) {
        return $GLOBALS["CACHE_POSIBLE_INSPECT_TARGETS"][(int)$pack];
    }

    $retData = dialecticNearbyActorNamesFromPayload(false, false);

    $compData=[];

    if ($pack) {
        foreach ($retData as $k => $v) {
            if (strlen($v) < 2) {
                unset($retData[$k]);
            } else {
                $retData[$k] = preg_replace("/\([^)]+\)/", '', $v);
                $retData[$k] = $v;
                if (!isset($compData[$v]))
                    $compData[$v]=0;
                $compData[$v]++; // Reduce same names (Chicken, Chicken -> Chicken)
                //$retData[$k]=$v;

            }

        }
        $retData=[];
        foreach ($compData as $l=>$n) {
            if ($n==1)
                $retData[]="$l";
            else
                $retData[]="$n $l";
        }

        
    }

    $GLOBALS["CACHE_POSIBLE_INSPECT_TARGETS"][(int)$pack] = array_values($retData);
    return $GLOBALS["CACHE_POSIBLE_INSPECT_TARGETS"][(int)$pack];
}

function dialecticDescribeConditionStat(string $kind, float $cur, float $max): string
{
    if ($max <= 0) {
        return "Unknown";
    }

    $pct = ($cur < 0 ? 0.0 : ($cur > $max ? $max : $cur)) / $max * 100.0;
    if ($kind === 'health') {
        if ($pct >= 75.0) {
            return "Near full health";
        }
        if ($pct >= 50.0) {
            return "Wounded";
        }
        if ($pct >= 25.0) {
            return "Badly wounded";
        }
        return "On the brink of collapse";
    }

    if ($kind === 'action_points') {
        if ($pct >= 75.0) {
            return "Ready";
        }
        if ($pct >= 50.0) {
            return "Partly spent";
        }
        if ($pct >= 25.0) {
            return "Low";
        }
        return "Nearly spent";
    }

    if ($pct >= 75.0) {
        return "Strong";
    }
    if ($pct >= 50.0) {
        return "Winded";
    }
    if ($pct >= 25.0) {
        return "Exhausted";
    }
    return "Spent";
}

function dialecticBuildCurrentConditionLinesFromMetadata($stats, array $metadata = [])
{
    $lines = [];

    if (is_array($stats) && !empty($stats)) {
        $health = dialecticDescribeConditionStat(
            'health',
            (float)($stats['health'] ?? 0),
            (float)($stats['health_max'] ?? 0)
        );
        $actionPoints = dialecticDescribeConditionStat(
            'action_points',
            (float)($stats['action_points'] ?? 0),
            (float)($stats['action_points_max'] ?? 0)
        );

        if ($health !== 'Unknown') {
            $lines[] = "- Health: {$health}";
        }
        if ($actionPoints !== 'Unknown') {
            $lines[] = "- Action Points: {$actionPoints}";
        }
        if (isset($stats['karma']) && is_numeric($stats['karma'])) {
            $karma = (int)$stats['karma'];
            if ($karma >= 250) {
                $lines[] = "- Karma: Good";
            } elseif ($karma <= -250) {
                $lines[] = "- Karma: Evil";
            } else {
                $lines[] = "- Karma: Neutral";
            }
        }
    }

    $activityStatus = dialecticNormalizeActivityStatus($metadata);
    if (!empty($activityStatus['is_dead'])) {
        $lines[] = "- State: Dead";
    } elseif (!empty($activityStatus['is_unconscious'])) {
        $lines[] = "- State: Unconscious";
    } elseif (!empty($activityStatus['is_in_combat'])) {
        $lines[] = "- State: In combat";
    }
    if (!empty($activityStatus['is_weapon_drawn'])) {
        $lines[] = "- Weapon: Drawn";
    }

    return $lines;
}

function dialecticBuildCurrentConditionBlockFromMetadata($stats, array $metadata = [])
{
    $lines = dialecticBuildCurrentConditionLinesFromMetadata($stats, $metadata);
    if (empty($lines)) {
        return '';
    }

    return "<condition>\n#Condition\n" . implode("\n", $lines) . "\n</condition>";
}

function dialecticPlayerSurvivalStageLabel(string $kind, int $stage): string
{
    $labels = [
        'hunger' => [
            1 => 'Peckish', 2 => 'Hungry', 3 => 'Starving',
            4 => 'Critically starving', 5 => 'Dying of starvation',
        ],
        'dehydration' => [
            1 => 'Thirsty', 2 => 'Dehydrated', 3 => 'Severely dehydrated',
            4 => 'Critically dehydrated', 5 => 'Dying of dehydration',
        ],
        'sleep_deprivation' => [
            1 => 'Tired', 2 => 'Sleep deprived', 3 => 'Severely sleep deprived',
            4 => 'Critically sleep deprived', 5 => 'Near collapse from sleep deprivation',
        ],
        'radiation' => [
            1 => 'Minor radiation poisoning', 2 => 'Advanced radiation poisoning',
            3 => 'Critical radiation poisoning', 4 => 'Deadly radiation poisoning',
            5 => 'Fatal radiation exposure',
        ],
    ];

    return $labels[$kind][max(0, min(5, $stage))] ?? '';
}

function dialecticIsPlayerSurvivalStateFresh(array $survival, int $staleSeconds = 180, ?int $now = null): bool
{
    $updatedAt = max(0, (int)($survival['updated_at'] ?? $survival['captured_at'] ?? 0));
    if ($updatedAt <= 0) {
        return false;
    }

    return (($now ?? time()) - $updatedAt) <= max(1, $staleSeconds);
}

function dialecticGetFreshPlayerSurvivalState(int $staleSeconds = 180): ?array
{
    try {
        require_once(__DIR__ . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'player.class.php');
        $player = new Player();
        $survival = $player->getJson('survival');
    } catch (Throwable $e) {
        Logger::debug('Could not read player survival state: ' . $e->getMessage());
        return null;
    }

    if (!is_array($survival) || empty($survival)) {
        return null;
    }

    if (!dialecticIsPlayerSurvivalStateFresh($survival, $staleSeconds)) {
        return null;
    }

    return $survival;
}

function dialecticPlayerSurvivalEntries(?array $survival = null): array
{
    $survival = $survival ?? dialecticGetFreshPlayerSurvivalState();
    if (!is_array($survival)) {
        return [];
    }

    $entries = [];
    if (!empty($survival['hardcore_enabled'])) {
        $needs = is_array($survival['needs'] ?? null) ? $survival['needs'] : [];
        foreach ([
            'hunger' => 'Hunger',
            'dehydration' => 'Thirst',
            'sleep_deprivation' => 'Sleep',
        ] as $key => $label) {
            $stage = max(0, min(5, (int)($needs[$key]['stage'] ?? 0)));
            $description = dialecticPlayerSurvivalStageLabel($key, $stage);
            if ($description !== '') {
                $entries[] = [
                    'key' => $key,
                    'label' => $label,
                    'description' => $description,
                ];
            }
        }
    }

    $radiation = is_array($survival['radiation'] ?? null) ? $survival['radiation'] : [];
    $radiationStage = max(0, min(5, (int)($radiation['stage'] ?? 0)));
    $radiationDescription = dialecticPlayerSurvivalStageLabel('radiation', $radiationStage);
    if ($radiationDescription !== '') {
        $entries[] = [
            'key' => 'radiation',
            'label' => 'Radiation',
            'description' => $radiationDescription,
        ];
    }

    return $entries;
}

function dialecticDescribePlayerSurvivalState(?array $survival = null): string
{
    $descriptions = array_map(static function (array $entry): string {
        return strtolower((string)($entry['description'] ?? ''));
    }, dialecticPlayerSurvivalEntries($survival));

    return implode(', ', array_values(array_filter($descriptions)));
}

function dialecticBuildPlayerSurvivalConditionBlock(?array $survival = null): string
{
    if (!dialecticPromptContextSectionEnabled('enabled_appearance_subsections', 'current_condition')) {
        return '';
    }

    $lines = array_map(static function (array $entry): string {
        return '- ' . $entry['label'] . ': ' . $entry['description'];
    }, dialecticPlayerSurvivalEntries($survival));

    if (empty($lines)) {
        return '';
    }

    $playerName = trim((string)($GLOBALS['PLAYER_NAME'] ?? 'The Courier'));
    if ($playerName === '') {
        $playerName = 'The Courier';
    }
    return "\n\n<condition>\n#{$playerName}'s Condition\n" . implode("\n", $lines) . "\n</condition>\n";
}

function dialecticPlayerSurvivalProfileEnricher(string $actorName, string $actorType, array $context = []): string
{
    if (strcasecmp(trim($actorType), 'player') !== 0
        || !empty($GLOBALS['DIALECTIC_SUPPRESS_PLAYER_SURVIVAL_NEARBY'])
        || !dialecticPromptContextSectionEnabled('enabled_appearance_subsections', 'current_condition')) {
        return '';
    }

    $description = dialecticDescribePlayerSurvivalState();
    return $description === '' ? '' : 'Survival: ' . $description;
}

if (function_exists('dialecticRegisterActorProfileEnricher')) {
    dialecticRegisterActorProfileEnricher(
        'dialectic.player_survival',
        'dialecticPlayerSurvivalProfileEnricher',
        55
    );
}

function dialecticPromptContextSectionEnabled(string $bucket, string $id): bool
{
    if (function_exists('dialecticPromptContextOptionEnabled')) {
        return dialecticPromptContextOptionEnabled($bucket, $id);
    }
    return true;
}

function dialecticFNVEquipmentSlotLabel(string $slot): string
{
    $labels = [
        'face' => 'Face',
        'head' => 'Head',
        'hair' => 'Head',
        'body' => 'Body',
        'upper_body' => 'Body',
        'left_hand' => 'Left Hand',
        'right_hand' => 'Right Hand',
        'weapon' => 'Weapon',
        'pip_boy' => 'Pip-Boy',
        'backpack' => 'Backpack',
        'necklace' => 'Necklace',
        'headband' => 'Headband',
        'hat' => 'Hat',
        'eyeglasses' => 'Eyeglasses',
        'nosering' => 'Nose Ring',
        'earrings' => 'Earrings',
        'mask' => 'Mask',
        'choker' => 'Choker',
        'mouth_object' => 'Mouth Object',
        'body_addon_1' => 'Body Addon 1',
        'body_addon_2' => 'Body Addon 2',
        'body_addon_3' => 'Body Addon 3',
        'upper_body_addon' => 'Upper Body Addon',
        'lower_body_addon' => 'Lower Body Addon',
    ];

    return $labels[$slot] ?? ucwords(str_replace('_', ' ', $slot));
}

function dialecticNormalizeFNVEquipmentSlots(array $equipment): array
{
    $hasOldHairSlot = array_key_exists('hair', $equipment);
    $hasOldBodySlot = array_key_exists('upper_body', $equipment);
    $looksLikeOldFNVSlotNames = $hasOldHairSlot || $hasOldBodySlot;

    if ($looksLikeOldFNVSlotNames && !array_key_exists('face', $equipment) && array_key_exists('head', $equipment)) {
        $equipment['face'] = $equipment['head'];
        unset($equipment['head']);
    }

    if (!array_key_exists('head', $equipment) && $hasOldHairSlot) {
        $equipment['head'] = $equipment['hair'];
        unset($equipment['hair']);
    }

    if (!array_key_exists('body', $equipment) && $hasOldBodySlot) {
        $equipment['body'] = $equipment['upper_body'];
        unset($equipment['upper_body']);
    }

    return $equipment;
}

function dialecticFormatEquipmentCondition($condition): string
{
    if ($condition === null || $condition === '' || !is_numeric($condition)) {
        return '';
    }

    $value = (float)$condition;
    if ($value < 0) {
        return '';
    }
    if ($value <= 1.0) {
        return 'condition ' . (int)round($value * 100.0) . '%';
    }

    return 'condition ' . (int)round($value);
}

function dialecticBuildEquipmentLinesFromMetadata(array $metadata, callable $getItemDescription = null): array
{
    $equipment = [];
    if (isset($metadata['equipment_structured']) && is_array($metadata['equipment_structured'])) {
        $equipment = $metadata['equipment_structured'];
    } elseif (isset($metadata['equipment']) && is_array($metadata['equipment'])) {
        foreach ($metadata['equipment'] as $slot => $value) {
            $slot = (string)$slot;
            if (str_ends_with($slot, '_baseid') || str_ends_with($slot, '_slot') || str_ends_with($slot, '_condition')) {
                continue;
            }

            $equipment[$slot] = is_array($value)
                ? $value
                : [
                    'name' => (string)$value,
                    'baseid' => (string)($metadata['equipment'][$slot . '_baseid'] ?? ''),
                    'slot' => $metadata['equipment'][$slot . '_slot'] ?? null,
                    'condition' => $metadata['equipment'][$slot . '_condition'] ?? null,
                ];
        }
    }

    if (empty($equipment)) {
        return [];
    }
    $equipment = dialecticNormalizeFNVEquipmentSlots($equipment);

    $lines = [];
    $describedBaseids = [];
    foreach ($equipment as $slot => $item) {
        if (!is_array($item)) {
            continue;
        }

        $name = trim((string)($item['name'] ?? ''));
        if ($name === '' || $name === '<Missing Name>' || $name === '<no name>' || isItemBlacklisted($name)) {
            continue;
        }

        $baseid = trim((string)($item['baseid'] ?? ''));
        $detailParts = [];
        $conditionText = dialecticFormatEquipmentCondition($item['condition'] ?? null);
        if ($conditionText !== '') {
            $detailParts[] = $conditionText;
        }

        if ($getItemDescription !== null && $baseid !== '' && !in_array($baseid, $describedBaseids, true)) {
            $description = $getItemDescription($name, $baseid);
            if (!empty($description)) {
                $detailParts[] = (string)$description;
                $describedBaseids[] = $baseid;
            }
        }

        $line = '- ' . dialecticFNVEquipmentSlotLabel((string)$slot) . ": {$name}";
        if (!empty($detailParts)) {
            $line .= ' (' . implode('; ', $detailParts) . ')';
        }
        $lines[] = $line;
    }

    return $lines;
}

function dialecticBuildEquipmentBlockFromMetadata(array $metadata, callable $getItemDescription = null, string $actorName = ''): string
{
    $lines = dialecticBuildEquipmentLinesFromMetadata($metadata, $getItemDescription);
    if (empty($lines)) {
        return '';
    }

    $heading = trim($actorName) !== ''
        ? "#{$actorName}'s Current Equipment"
        : '#Current Equipment';

    return "\n<equipment>\n{$heading}\nCurrently wearing/wielding:\n" . implode("\n", $lines) . "\n</equipment>";
}

function dialecticBuildNpcInspectSummary(string $npcName)
{
    $npcName = trim($npcName);
    if ($npcName === '') {
        return '';
    }

    $npcMaster = new NpcMaster();
    $currentNpcData = $npcMaster->getByName($npcName);
    if (!is_array($currentNpcData) || empty($currentNpcData)) {
        return '';
    }

    $metaData = $npcMaster->getMetaData($currentNpcData);
    if (!is_array($metaData)) {
        $metaData = [];
    }

    $sections = [];

    $conditionBlock = dialecticBuildCurrentConditionBlockFromMetadata($metaData['stats'] ?? null, $metaData);
    if ($conditionBlock !== '') {
        $sections[] = $conditionBlock;
    }

    $activityStatus = dialecticNormalizeActivityStatus($metaData);
    if (!empty($activityStatus['summary'])) {
        $sections[] = "<activity>\n#Activity\n" . ucfirst($activityStatus['summary']) . ".\n</activity>";
    }

    return implode("\n", $sections);
}

function DataQuestJournal($quest)
{
    global $db;
    if (empty($quest)||($quest=="None")||true) {
        
        $results = $db->fetchAll("SElECT name,id_quest,briefing,briefing2 as notes, status FROM quests ORDER BY CASE WHEN status='selected' THEN 0 ELSE 1 END, gamets DESC");
        $finalRow = [];
        foreach ($results as $row) {
            if (isset($finalRow[$row["id_quest"]])) {
                continue;
            } else {
                $finalRow[$row["id_quest"]] = ["name"=>$row["name"],"briefing"=>$row["briefing"],"personal notes"=>$row["notes"],"status"=>$row["status"]];
            }
        }

        if (sizeof($finalRow) == 0) {
            $data[] = "no active quests";
        } else {
            $data = array_values($finalRow);
        }

        $extraData = DataGetCurrentTask();

        $data[] = ["side note" => "$extraData"];

        return json_encode($data);

    } else {
        $lastDialogFull = array();
        $results = $db->fetchAll("SElECT  name,id_quest,briefing,data
      FROM quests where lower(id_quest)=lower('$quest') or lower(name)=lower('$quest') ");
        $lastOne = -1;
        $data = array();
        if (!$results) {
            $data["error"] = "quest not found, make sure you use id_quest";
            return json_encode($data);

        }
        foreach ($results as $row) {
            $lastOne++;
            $data[] = $row;
        }
        if ($lastOne >= 0) {
            $data[$lastOne]["stage_completed"] = "no";
        }

        if (sizeof($data) == 0) {
            $data["error"] = "quest not found, make sure you use id_quest";

        }

        return json_encode($data);

    }
}

function removeTalkingToOccurrences($input) {
    $pattern = '/\((?:talking|whispering|shouting|speaking privately)\s+to\s+[^()]+\)/i';
    preg_match_all($pattern, $input, $matches, PREG_OFFSET_CAPTURE);

    // Get all positions of the matches
    $positions = $matches[0];

    // If there are no matches or only one match, return the input string as it is
    if (count($positions) <= 1) {
        return $input;
    }

    // Remove all but the last occurrence
    for ($i = 0; $i < count($positions) - 1; $i++) {
        $pos = $positions[$i][1];
        $input = substr_replace($input, '', $pos, strlen($positions[$i][0]));
        
        // After each removal, adjust the positions of subsequent matches
        for ($j = $i + 1; $j < count($positions); $j++) {
            $positions[$j][1] -= strlen($positions[$i][0]);
        }
    }

    return $input;
}

function moveDialogueTargetSuffixToEnd($input) {
    $input = trim((string)$input);
    if ($input === "") {
        return "";
    }

    $pattern = '/\s*(\((?:talking|whispering|shouting|speaking privately)\s+to [^()]+?\)|\(speaking loudly to [^()]+?\))\s*/i';
    if (preg_match_all($pattern, $input, $matches) !== 1 || empty($matches[1])) {
        return trim(preg_replace('/\s+/', ' ', $input));
    }

    $targetSuffix = trim((string)end($matches[1]));
    $withoutSuffix = preg_replace($pattern, ' ', $input);
    $withoutSuffix = trim(preg_replace('/\s+/', ' ', (string)$withoutSuffix));
    if ($withoutSuffix === "") {
        return $targetSuffix;
    }

    return "{$withoutSuffix} {$targetSuffix}";
}


function DataLastDataExpandedForNPC($actor, $lastNelements = -10,$sqlfilter="") {

        global $db;

        $actorcn=$db->escape($actor);
        $results = $db->fetchAll("SELECT speaker,speech,listener,gamets,localts,'speech',gamets - LAG(gamets) OVER (ORDER BY gamets ASC) AS gamets_diff,location,ts
        FROM speech where companions like '%$actorcn%' order by ts desc LIMIT 1000 OFFSET 0");    
         $rawData=[];
        foreach ($results as $row) {
            $rawData[] = $row;
        }


        $orderedData = array_reverse($rawData);
        
        $lastDialogFull=[];
        
        $lastlocation="";
        $lastSpeaker=null;
        $lastListener=null;
        $buffer="";
        foreach ($orderedData as $speechEvent)  {
            
            if (($speechEvent["gamets_diff"] * 0.0000024) > 1.0) { // more than one hour
                $lastDialogFull[$speechEvent["ts"]] = array('role' => "user", 'content' => "The Narrator: about ".number_format(floor($speechEvent["gamets_diff"]*0.0000024),0)." hours later...");
            }

            
            if ($lastlocation!=$speechEvent["location"]) {
                $lastlocation=$speechEvent["location"];
                $lastDialogFull[$speechEvent["ts"]] = array('role' => "user", 'content' => "The Narrator: action moved to new location: $lastlocation");
            }

            $currentSpeaker="user";
            
            
            if ($lastSpeaker==$actor)
                $currentSpeaker="assistant";
            else if ($speechEvent["speaker"]=="The Narrator")
                continue;
            
            if (($lastSpeaker!=$speechEvent["speaker"])&&($lastSpeaker!=null)) {
                $talkingto="";
                if ($lastListener!="The Narrator")
                    $talkingto="(talking to {$lastListener})";
                
                if ($lastSpeaker==$GLOBALS["PLAYER_NAME"])
                    $talkingto="";

                $lastDialogFull[$speechEvent["ts"]] = array('role' => $currentSpeaker, 'content' => "$lastSpeaker: $buffer $talkingto");
                $buffer="";
                $lastSpeaker=$speechEvent["speaker"];
            } else {
                $lastSpeaker=$speechEvent["speaker"];
            }
            $buffer.=$speechEvent["speech"];
            $lastListener=$speechEvent["listener"];

        }
        
        
        $results = $db->fetchAll("SELECT gamets,data,ts,type FROM eventlog where type in ('infoaction','itemfound','itemtransfer') order by gamets desc LIMIT 10 OFFSET 0");
        $rawData=[];
        foreach ($results as $row) {
            $eventData = $row["data"];
            if (($row["type"] ?? "") === "itemtransfer") {
                $decodedEvent = json_decode($eventData, true);
                if (is_array($decodedEvent) && !empty($decodedEvent["text"])) {
                    $eventData = $decodedEvent["text"];
                }
            }
            $lastDialogFull[$row["ts"]]= array('role' => 'user', 'content' => "The Narrator: {$eventData}");
        }
        
        $results = $db->fetchAll("SELECT gamets,party,ts FROM eventlog where type='world_context' and COALESCE(party, '') <> '' order by gamets desc LIMIT 10 OFFSET 0");
        $rawData=[];
        foreach ($results as $row) {
            $payload = json_decode($row["party"] ?? "", true);
            if (!is_array($payload)) {
                continue;
            }
            $location = dialecticWorldContextLocationFromPayload($payload);
            $date = dialecticWorldContextDateText($payload);
            $time = dialecticWorldContextTimeText($payload);
            $weatherValue = $payload["weather"] ?? "";
            $weather = is_array($weatherValue)
                ? trim((string)($weatherValue["name"] ?? $weatherValue["summary"] ?? ""))
                : trim((string)$weatherValue);
            $parts = array_filter([
                $location !== "" ? "location: {$location}" : "",
                $weather !== "" ? "weather: {$weather}" : "",
                $date !== "" ? "date: {$date}" : "",
                $time !== "" ? "time: {$time}" : "",
            ]);
            if (empty($parts)) {
                continue;
            }
            $lastDialogFull[$row["ts"]]= array('role' => 'user', 'content' => "The Narrator: World context: " . implode(", ", $parts));
        }

        ksort($lastDialogFull);
        
        $results = $db->fetchAll("SELECT gamets,data,ts
            FROM eventlog
            WHERE type in ('inputtext','inputtext_s','narrator_inputtext')
              AND people like '%$actorcn%'
            ORDER BY gamets desc, ts desc");
        $rawData=[];
        foreach ($results as $row) {
            $rawData[] = $row;
        }
        $rawData = array_reverse($rawData);
        foreach ($rawData as $row) {
            $lastDialogFull[] = array('role' => 'user', 'content' => "{$row["data"]}");
        }

       
                
        $orderedData = array_slice($lastDialogFull, $lastNelements);
        
        Logger::info("Using NPC data retriever");
        
        
        return $orderedData;
}

function removeEmptyElements(array $array): array {
    return array_filter($array, function($value) {
        return !empty($value) || $value === 0 || $value === "0"; 
    });
}

/**
 * Consolidate repeated similar events
 * 
 * @param array $events Array of event entries with role, content, subtype, type, gamets
 * @return array Consolidated array of events
 */
function consolidateEvents(array $events): array {
    // Hardcoded defaults - always enabled for efficiency
    $timeWindow = 300; // 5 minutes game time
    $typesToConsolidate = ["death", "itemfound", "infoaction"];
    
    $consolidated = [];
    $consolidationBuffer = [];
    
    foreach ($events as $event) {
        if (!isset($event['type']) || !in_array($event['type'], $typesToConsolidate)) {
            // Flush buffer if we hit a non-consolidatable event
            if (!empty($consolidationBuffer)) {
                $consolidated = array_merge($consolidated, flushConsolidationBuffer($consolidationBuffer));
                $consolidationBuffer = [];
            }
            $consolidated[] = $event;
            continue;
        }
        
        // Extract pattern from event content
        $pattern = extractEventPattern($event);
        if ($pattern === null) {
            // Can't extract pattern, add as-is
            if (!empty($consolidationBuffer)) {
                $consolidated = array_merge($consolidated, flushConsolidationBuffer($consolidationBuffer));
                $consolidationBuffer = [];
            }
            $consolidated[] = $event;
            continue;
        }
        
        // Check if this event can be merged with buffer
        $merged = false;
        $actorName = extractActorName($event['content']);
        
        foreach ($consolidationBuffer as $key => &$buffered) {
            if ($buffered['pattern'] === $pattern) {
                // Check time window
                $timeDiff = abs(($event['gamets'] ?? 0) - ($buffered['first_gamets'] ?? 0));
                if ($timeDiff <= $timeWindow) {
                    // Check if this is a different actor doing the same action (e.g., combat engagement)
                    $isMultiActorPattern = (strpos($pattern, 'combat:') === 0 || strpos($pattern, 'activate:') === 0);
                    
                    if ($isMultiActorPattern && $actorName) {
                        // Multi-actor pattern: collect actor names
                        if (!isset($buffered['actors'])) {
                            $buffered['actors'] = [extractActorName($buffered['event']['content'])];
                        }
                        if (!in_array($actorName, $buffered['actors'])) {
                            $buffered['actors'][] = $actorName;
                        }
                    } elseif (strpos($pattern, 'itemfound:') === 0) {
                        // Item collection pattern: collect items
                        if (!isset($buffered['items'])) {
                            $buffered['items'] = [extractItemInfo($buffered['event']['content'])];
                        }
                        $buffered['items'][] = extractItemInfo($event['content']);
                    } else {
                        // Same actor repeating: increment count
                        $buffered['count']++;
                    }
                    
                    $buffered['last_gamets'] = $event['gamets'] ?? 0;
                    $merged = true;
                    break;
                }
            }
        }
        unset($buffered);
        
        if (!$merged) {
            // Flush older patterns and start new buffer entry
            $consolidationBuffer[] = [
                'event' => $event,
                'pattern' => $pattern,
                'count' => 1,
                'first_gamets' => $event['gamets'] ?? 0,
                'last_gamets' => $event['gamets'] ?? 0,
                'actors' => $actorName ? [$actorName] : null,
                'items' => (strpos($pattern, 'itemfound:') === 0) ? [extractItemInfo($event['content'])] : null
            ];
        }
    }
    
    // Flush remaining buffer
    if (!empty($consolidationBuffer)) {
        $consolidated = array_merge($consolidated, flushConsolidationBuffer($consolidationBuffer));
    }
    
    return $consolidated;
}

/**
 * Extract actor name from event content
 * 
 * @param string $content Event content
 * @return string|null Actor name or null if not extractable
 */
function extractActorName(string $content): ?string {
    // Extract actor from patterns like "ActorName does something"
    if (preg_match('/^([^:]+?)(?:\s+(?:engages combat with|activates|uses|casts|has defeated|found|took|looted|gave)\s+.+)$/i', $content, $matches)) {
        return trim($matches[1]);
    }
    return null;
}

/**
 * Extract item information from item pickup event
 * 
 * @param string $content Event content
 * @return string|null Item description with quantity
 */
function extractItemInfo(string $content): ?string {
    // Extract "N ItemName from/in X" or just "N ItemName"
    if (preg_match('/(?:found|took|looted|traded|gave)\s+(.+?)(?:,\(value.+\))?$/i', $content, $matches)) {
        $itemInfo = trim($matches[1]);
        
        // Extract just the item name (remove quantity) for blacklist check
        // Pattern: "2 9mm Pistol" or "9mm Pistol" or "a 9mm Pistol"
        if (preg_match('/^(?:\d+\s+|an?\s+)?(.+?)$/i', $itemInfo, $nameMatches)) {
            $itemName = trim($nameMatches[1]);
            
            // Check if item is blacklisted
            if (isItemBlacklisted($itemName)) {
                return null; // Filter out blacklisted items
            }
        }
        
        return $itemInfo;
    }
    return null;
}

/**
 * Extract consolidation pattern from event
 * 
 * @param array $event Event data
 * @return string|null Pattern identifier or null if not extractable
 */
function extractEventPattern(array $event): ?string {
    $content = $event['content'] ?? '';
    $type = $event['type'] ?? '';
    
    if ($type === 'death') {
        // Pattern: "X DIED" (just death announcement)
        if (preg_match('/^(.+?)\s+died\s*$/i', $content, $matches)) {
            $victim = trim($matches[1]);
            return "death_announce:{$victim}";
        }
        // Pattern: "X has defeated Y" or "X killed Y" etc
        // Extract: actor + victim
        if (preg_match('/^(.+?)\s+(?:has defeated|defeated|killed|slain)\s+(.+?)(?:\s+with\s+.+)?(?:\s+in an awesome move)?$/i', $content, $matches)) {
            $actor = trim($matches[1]);
            $victim = trim($matches[2]);
            return "death:{$actor}->{$victim}";
        }
    } elseif ($type === 'itemfound') {
        // Pattern: "X found/took/looted N Y" or "X found/took/looted Y"
        // Group by actor only for multi-item consolidation
        if (preg_match('/^(.+?)\s+(found|took|looted|traded|gave)\s+(.+)$/i', $content, $matches)) {
            $actor = trim($matches[1]);
            $action = trim($matches[2]);
            return "itemfound:{$actor}->{$action}"; // Only actor+action, group all items together
        }
    } elseif ($type === 'infoaction') {
        // Pattern: "X engages combat with Y" - group by enemy only (multi-actor consolidation)
        if (preg_match('/^(.+?)\s+engages combat with\s+(.+?)$/i', $content, $matches)) {
            $enemy = trim($matches[2]);
            return "combat:{$enemy}"; // Only enemy in pattern, so multiple actors get grouped
        }
        // Pattern: "X activates Y" - group by object only (multi-actor consolidation)
        if (preg_match('/^(.+?)\s+activates\s+(.+?)$/i', $content, $matches)) {
            $object = trim($matches[2]);
            return "activate:{$object}"; // Only object in pattern
        }
    }
    
    return null;
}

/**
 * Flush consolidation buffer and format consolidated entries
 * 
 * @param array $buffer Consolidation buffer
 * @return array Formatted events
 */
function flushConsolidationBuffer(array $buffer): array {
    $result = [];
    
    foreach ($buffer as $buffered) {
        $event = $buffered['event'];
        
        // Check if this is an item event (single or multi) and filter blacklisted items
        if (isset($buffered['items'])) {
            // Filter out null entries (blacklisted items)
            $filteredItems = array_filter($buffered['items']);
            
            // If all items were filtered out, skip this event entirely
            if (empty($filteredItems)) {
                continue;
            }
            
            // Check if this is a multi-item consolidation
            if (count($filteredItems) > 1) {
                // Multiple items picked up by same actor - list them
                $content = $event['content'];
                
                if (preg_match('/^(.+?)\s+(found|took|looted|traded|gave)\s+/i', $content, $matches)) {
                    $actor = trim($matches[1]);
                    $action = trim($matches[2]);
                    
                    // Build item list from filtered items
                    $itemList = implode(', ', $filteredItems);
                    $event['content'] = "{$actor} {$action} {$itemList}";
                }
            }
            // Single item events will keep their original content (already filtered by extractItemInfo)
        } elseif (isset($buffered['actors']) && count($buffered['actors']) > 1) {
            // Multiple actors doing the same action - list them
            $actorList = implode(', ', $buffered['actors']);
            $content = $event['content'];
            
            // Replace single actor name with list and adjust verb to plural
            if (preg_match('/^(.+?)\s+(engages combat with|activates|uses|casts)\s+(.+?)$/i', $content, $matches)) {
                $action = trim($matches[2]);
                $target = trim($matches[3]);
                
                // Convert verb to plural form
                if (stripos($action, 'engages') !== false) {
                    $action = 'engage combat with';
                } elseif (stripos($action, 'activates') !== false) {
                    $action = 'activate';
                } elseif (stripos($action, 'uses') !== false) {
                    $action = 'use';
                } elseif (stripos($action, 'casts') !== false) {
                    $action = 'cast';
                }
                
                $event['content'] = "{$actorList} {$action} {$target}";
            }
        } elseif ($buffered['count'] > 1) {
            // Same event repeating - add count prefix for clarity (e.g., "2x SKEEVER DIED")
            $event['content'] = "{$buffered['count']}x " . trim($event['content']);
        }
        
        $result[] = $event;
    }
    
    return $result;
}

/**
 * Convert time difference in hours to a human-readable time category
 * 
 * @param float $hoursAgo Number of in-game hours since the event
 * @return string Human-readable time category
 */
function getTimeCategory($hoursAgo) {
    if ($hoursAgo < 0.02) return "Happened Recently";
    if ($hoursAgo < 0.1) return "Moments Ago";
    if ($hoursAgo < 0.25) return "A few minutes ago";
    if ($hoursAgo < 0.5) return "A while ago";
    if ($hoursAgo < 1.5) return "About an hour ago";
    if ($hoursAgo < 4) return "A couple of hours ago";
    if ($hoursAgo < 12) return "Earlier in the day";
    if ($hoursAgo < 36) return "A day ago";
    return "Days ago";
}


function dialecticShouldExcludeEventFromPromptContext(array $row): bool
{
    $type = strtolower(trim(strval($row['type'] ?? '')));
    $data = trim(strval($row['data'] ?? ''));

    static $csvImportEventTypes = [
        'biography_import',
        'worldknowledge_import',
        'dynamic_worldknowledge_import',
        'description_import',
        'custom_action_import',
        'traditional_quest_import',
        'item_import',
        'npcvoice_refresh',
    ];

    static $snapshotEventTypes = [
        'nearby_actors',
        'nearby_items',
        'points_of_interest',
        'active_quests',
        'world_context',
        'inventory',
        'equipment',
        'condition',
        'activity',
        'addnpc',
        'infonpc',
        'infonpc_close',
        'infoloc',
        'infoitems',
        'named_cell',
        'world_locations',
        'world_factions',
        'loaded_plugins',
    ];

    static $promptOnlyEventTypes = [
        'ext_held_item_pickup',
        'ext_held_item_drop',
    ];

    if (in_array($type, $csvImportEventTypes, true)) {
        return true;
    }

    // These are current-state snapshots injected through live prompt sections,
    // not durable history. Replaying them from eventlog makes stale actors/items
    // appear in later NPC prompts.
    if (in_array($type, $snapshotEventTypes, true)) {
        return true;
    }

    if (in_array($type, $promptOnlyEventTypes, true)) {
        return true;
    }

    if ($type === 'status_msg' && stripos($data, 'csv_import@') === 0) {
        return true;
    }

    if (preg_match('/^CSV upload(?:\s*\(|:| failed:)/i', $data) === 1) {
        return true;
    }

    if (preg_match('/^[^@]+@[0-9a-f]{8}@nullvoicetype$/i', $data) === 1) {
        return true;
    }

    return false;
}

function buildHistoricContext($actor, $lastNelements = -10,$sqlfilter="") {

    global $db;

    if ($lastNelements == 0) { // if context_history is 0, all records will be retrieved
        $lastNelements = -1;
    }

    $nRecordsLimit = 32 + (2 * abs($lastNelements)); // reduce the default 1000 recs loaded from db to a number proportional to context_history 

    if (isset($GLOBALS["gameRequest"][2])) { 
        $currentGameTs=intval($GLOBALS["gameRequest"][2]);
    } else {
        $currentGameTs=intval(DataLastKnownGameTS());
    }

    $removeBooks = "";
    
    $ext_sqlfilter1 = $GLOBALS["EXT_CONTEXT_SQL_FILTER1"] ?? "";
    $ext_sqlfilter2 = $GLOBALS["EXT_CONTEXT_SQL_FILTER2"] ?? "";

    $lastDialogFull = array();
    $b_actor = (strlen($actor) > 0);
    if ($b_actor)
        $actorEscaped=$db->escape($actor);
    else
        $actorEscaped='';
    //$playerEscaped=$db->escape($GLOBALS["PLAYER_NAME"]);

    $visibleChatStateSql = dialecticBuildChatDeliveryStateSql('delivery_state');

    $query="select  
    case 
      when type='infoaction' and a.data like '#%MEMORY%' then 'MEMORY'
      when type like 'info%' or type like 'funcret%' or type like 'location%' then 'CONTEXTI'
      when type='backgroundchat' or a.data like '%background chat%' then 'BACKDIAG'
      when type='quest' then 'QUEST' 
      when type='itemfound' then 'ITEM' 
      when type='rpg_lvl' then 'RPG_LVL' 
      when type='death' then 'RPG_DEATH' 
      when type='welcome' then 'RPG_SPAWN' 
      when type='waitstart' then 'CONTEXTI' 
      when type='waitstop' then 'CONTEXTI' 
      when type='info_timeforward' then 'TIMELAPSE' 
      when type like 'ext_%' then 'PLUGIN'
      else '' 
    end as subtype,a.data  as data , gamets,localts,type,location
    FROM  eventlog a WHERE
    type<>'combatend'
    and type<>'bored' and type<>'init' and type<>'info' and type<>'funcret' and type<>'book'
    and type<>'updateprofile' and type<>'rechat' and type<>'setconf' and  type<>'status_msg'  and type<>'user_input'
    and type<>'instruction'
    and data not ilike '%sSubtitle%'
    and data not ilike '%sSpeakerName%'
    and type<>'request' and type<>'playerinfo' and type<>'im_alive' and type<>'region'
    AND type<>'narrator_welcome'
    and (type<>'chat' or {$visibleChatStateSql})
    AND type<>'funccall' AND type<>'togglemodel'
    {$removeBooks} {$sqlfilter} {$ext_sqlfilter1}
    ".(($b_actor) ? "
    AND (
     people like '%|$actorEscaped|%'
     or people like '$actorEscaped'
     or people like '%|$actorEscaped (busy)|%'
     or people like '%|$actorEscaped (hostile)|%'
     or people like '%|$actorEscaped (in combat)|%'
     or people like '%|$actorEscaped (restrained)|%'
     or type='info_timeforward'
    )" : " ").
    //((false)?" and gamets>".($currentGameTs-(60*60*60*60)):"").
    " {$ext_sqlfilter2} 
    ORDER BY gamets desc, ts desc, rowid desc LIMIT {$nRecordsLimit} OFFSET 0 ";
    
    // Keep generic far-away actors out of historic context. Shared narrator rows are flattened on write.
    $results = $db->fetchAll($query);

    // Filter blacklisted event types
    if (isset($GLOBALS["EVENT_TYPE_FILTER"]) && !empty($GLOBALS["EVENT_TYPE_FILTER"])) {
        $blacklistedEventTypes = array_map('trim', explode(',', strtolower($GLOBALS["EVENT_TYPE_FILTER"])));
        $results = array_filter($results, function($row) use ($blacklistedEventTypes) {
            $eventType = strtolower($row["type"] ?? '');
            foreach ($blacklistedEventTypes as $blacklistedType) {
                if (!empty($blacklistedType) && $eventType === $blacklistedType) {
                    return false;
                }
            }
            return true;
        });
    }

    $results = array_filter($results, function ($row) {
        return !dialecticShouldExcludeEventFromPromptContext($row);
    });

    //error_log($query);
    $rawData=[];
    foreach ($results as $row) {
        $rawData[md5($row["data"].$row["localts"])] = $row;
    }

    
    $orderedData = array_reverse($rawData);

    //$orderedData = array_slice($orderedData, $lastNelements);

    
    $currentLocation = "";
    $writeLocation = true;

    $lastSpeaker = "";
    $buffer = [];
    $timeStampBuffer = [];

    $beingsPresent=null;
    $lastlocation="";
    $lastGameTs=0;
    $memoryLogToRemove=[];
    
    $lastTimeCategory = null; // Track last timestamp category for PROMPT_TIMESTAMP feature

    $focusOnChat=($GLOBALS["CLEAN_CONTEXT_FOCUS_CHAT"] ?? false);


    foreach ($orderedData as $n=>$row) {
        $rowData = $row["data"];
        
        if ($rowData==="The Narrator:") // Hunt empty rows
            continue;
        
        // Remove Context location from data
        $pattern = '/\s*\(Context location: .*?\)/';
        if ($rowData)
            $rowData = preg_replace($pattern, "", $rowData); 

        // Figure out location form location field, and only add to context if changed    
        $printLocation=false;
        $string = $row["location"];
        if (!empty($string)) {
            preg_match('/Context\s*(?:new\s*)?location:\s*([^,]+?)(?:,|$)/u', $string, $locationMatch);
        }
        
        if (!isset($locationMatch[1])) {
            //error_log(print_r($string,true));
            $locationFinal=$lastlocation;
        } else {
            $location = trim($locationMatch[1]);
            $locationFinal=$location;
        }
        
        if ($lastlocation!=$locationFinal) {
            $lastlocation=$locationFinal;
            $printLocation=true;
            $currentLocation=$lastlocation;
        }
        
        // Special case, logaction is the return data of an action call.
        if ($row["type"]=="logaction") {
            $logactionData=json_decode($rowData,true);
            if (is_array($logactionData)) {
                if ($logactionData["character"]!=$GLOBALS["DIALECTIC_NAME"])
                    continue;
            }
        }
        
        // Skip empty rows
        if (!$rowData)
            $rowData="";
        

        // Figure out real speaker
        if (($row["type"]=="logaction") && (strpos($rowData, "{$GLOBALS["DIALECTIC_NAME"]}") !== false))  {
            $speaker = "assistant";
            
        } else if ($row["type"]=="vision") {
            $speaker = "user";
            
        } else if ($row["subtype"]=="MEMORY") {
            $speaker = "memory";
            
        } else if ((strpos($rowData, "{$GLOBALS["DIALECTIC_NAME"]}:") !== false) && (strpos($rowData, "The Narrator:") === false)) {
            $speaker = "assistant";
            
        } else if ((strpos($rowData, "{$GLOBALS["PLAYER_NAME"]}:") === 0)) {
            $speaker = "player";
            
        } else if ((strpos($rowData, "The Narrator:") === 0) && $row["type"]=="chat") {
            $speaker = "narratorchat";
            
        } else if ($row["subtype"]=="BACKDIAG") {
            if ($focusOnChat)
                continue;
            $speaker = "backgroundchat";
            
        } else if ($row["subtype"]=="CONTEXTI") {
            if (strpos($rowData,"should not be visible")!==false)
                continue;
            if ($focusOnChat) {
                if (strpos($rowData," uses ")!==false) 
                    continue;
                if (strpos($rowData," casts ")!==false) 
                    continue;
                if (strpos($rowData," engages combat ")!==false) 
                    continue;
                if (strpos($rowData," has defeated ")!==false) 
                    continue;
                if (strpos($rowData," activates ")!==false) 
                    continue;
            }
            
         
            $speaker = "narratorci";
            
        } else if ($row["subtype"]=="QUEST") {
            if ($focusOnChat)
                continue;
            $speaker = "narratorci";
            
        } else if ($row["subtype"]=="ITEM") {
            if ($focusOnChat) {
                if (strpos($rowData,"{$GLOBALS["DIALECTIC_NAME"]}")===false) // This NPC's item transactions conserved
                    continue;
            }
            $speaker = "narratorci";
            
        } else if ($row["subtype"]=="RPG_LVL") {
            if ($focusOnChat)
                continue;
            $speaker = "narratorci";
            
        } else if ($row["subtype"]=="RPG_SPAWN") {
            if ($focusOnChat)
                continue;
            $speaker = "narratorci";
            
        } else if ($row["subtype"]=="RPG_DEATH") {
            if ($focusOnChat)
                continue;
            $speaker = "narratorci";
            $rowData = strtoupper($rowData);
            
        } else if ($row["subtype"]=="RPG_DEFEAT") {
            if ($focusOnChat)
                continue;
            $speaker = "narratorci";
            $rowData = strtoupper($rowData);
            
        } else if ($row["subtype"]=="TIMELAPSE") {
            $rowData = strtoupper($rowData);
            
        }  else if ($row["subtype"]=="PLUGIN") {
            $speaker = $row["type"];
            
        } else {
            
            $speaker = "npc";
            
        }

        // Compact info_timeforward events
        if ($row["type"] == "info_timeforward") {
            if (isset($previousRow) && $previousRow["type"] == "info_timeforward") {
                // Extract hours passed from the current row and the current date/time portion
                preg_match('/([\d.]+)\s*hours have passed\.?/i', $row["data"], $currentMatch);
                $currentHours = isset($currentMatch[1]) ? (float)$currentMatch[1] : 0;
                preg_match('/(Current date\/time: .+)$/i', $row["data"], $currentDateMatch);
                $currentDateTime = isset($currentDateMatch[1]) ? trim($currentDateMatch[1]) : '';

                // Extract hours passed from the previous row (if present)
                preg_match('/([\d.]+)\s*hours have passed\.?/i', $previousRow["content"], $previousMatch);
                $previousHours = isset($previousMatch[1]) ? (float)$previousMatch[1] : 0;

                // Sum the hours
                $totalHours = $currentHours + $previousHours;

                // error_log("[TIMEFORWARD] $totalHours = $currentHours + $previousHours ");

                // Build a normalized single-line content: "<hours> hours have passed. Current date/time: ..."
                if ($currentDateTime !== '') {
                    $previousRow["content"] = "{$totalHours} hours have passed. {$currentDateTime}";
                } else {
                    // Fallback: use the trimmed current row data if date/time portion wasn't found
                    $previousRow["content"] = "{$totalHours} hours have passed. " . trim($row["data"]);
                }

                continue; // Skip adding this row to the context
            } else {
                $row["role"]="narratorci";
                $row["content"]=trim($rowData);
                $row["gamets"]=$lastGameTs;// gamets will be previous record gamets

                $previousRow=$row;
                continue; // Skip adding this row to the context
            }
        } else if (isset($previousRow) && $previousRow["type"] == "info_timeforward") {
            $lastDialogFull[]=$previousRow;
            unset($previousRow);
        }

        //if (($GLOBALS["FEATURES"]["MISC"]["ADD_TIME_MARKS"])&&(true) && $row["type"] != "info_timeforward") {
        if ($row["type"] != "info_timeforward") {
    
            
            if ($lastGameTs==0)
                $lastGameTs=$row["gamets"];
            else {
                $timeGapInHours=round(($row["gamets"]-$lastGameTs) * 0.0000024, 0);
                
                if ($timeGapInHours>36) {
                    $timeGapInDays=round($timeGapInHours/24,1);
                    $lastDialogFull[] = array('role' => "narratorci", 'content' => "!!! IMPORTANT CONTEXT !!!
A MAJOR TIME JUMP HAS OCCURRED.
Elapsed time since last interaction: ~$timeGapInDays days
New setting: $currentLocation
!!! END CONTEXT !!! ");
                } else if ($timeGapInHours>5) {
                    $timeGapInDays=round($timeGapInHours/24,1);
                    $lastDialogFull[] = array('role' => "narratorci", 'content' => "(minor timelapse of about $timeGapInHours hours)");
                }
                $lastGameTs=$row["gamets"];
            }

            if ($printLocation ) {
                $hoursAgo=round(($currentGameTs-$row["gamets"]) * 0.0000024, 0);
                if (!isset($timeStampBuffer[$hoursAgo])) {
                    if ($currentLocation) {
                        if (DataLastKnownLocationHuman(false,true)==$currentLocation)   // Enforce current location.
                            $lastDialogFull[] = array('role' => "narratorci", 'content' => "LOCATION CHANGE, THIS IS THE CURRENT LOCATION: $currentLocation");
                        
                        else
                            $lastDialogFull[] = array('role' => "narratorci", 'content' => "LOCATION CHANGE to $currentLocation, timeline mark: $hoursAgo hours ago  ");
                    }
                }
            } else {
               

            }
        }

        $lastSpeaker = $speaker;
        
        // Insert timestamp subdividers if PROMPT_TIMESTAMP is enabled
        if (!empty($GLOBALS["PROMPT_TIMESTAMP"]) && $row["type"] != "info_timeforward") {
            $hoursAgo = ($currentGameTs - $row["gamets"]) * 0.0000024;
            $currentTimeCategory = getTimeCategory($hoursAgo);
            
            // If category changed, insert a subdivider
            if ($lastTimeCategory !== null && $currentTimeCategory !== $lastTimeCategory) {
                $lastDialogFull[] = array('role' => "narratorci", 'content' => "--- {$currentTimeCategory} ---");
            }
            
            $lastTimeCategory = $currentTimeCategory;
        }
        
        $row= array('role' => $lastSpeaker, 'content' => trim($rowData),'subtype'=>$row["subtype"]?:strtoupper($lastSpeaker),'type'=>$row["type"]);
        $lastDialogFull[] = $row;
        $previousRow=$row;

    }

    if (isset($previousRow)) {
        if (sizeof($previousRow)>0) {
            if (sizeof($lastDialogFull) === 0 || $previousRow !== end($lastDialogFull)) {
                $lastDialogFull[]=$previousRow;
            }
            
        }
    }

    file_put_contents(__DIR__."/../log/context_for_{$actor}_stage_1_.txt",print_r($lastDialogFull,true));

    // Remove memory logs, only leave last one.
    $lastDialogFullOnlyLastMemory=[];
    $localFlag=0;
    foreach (array_reverse($lastDialogFull) as $element) {
        if ($element["role"]=="memory") {
            if ($localFlag==0) {
                $element["role"]="narratorci";
                $lastDialogFullOnlyLastMemory[]=$element;
                $localFlag++;
            } else {
                $localFlag++;
            }
        } else {
            $lastDialogFullOnlyLastMemory[]=$element;
        }
    }

    error_log("[buildHistoricContext] $localFlag memories removed");
    $lastDialogFull=array_reverse($lastDialogFullOnlyLastMemory);
    // End of memory logs cleaning
    
    // Consolidate repeated events to reduce context size
    $eventCountBefore = count($lastDialogFull);
    $lastDialogFull = consolidateEvents($lastDialogFull);
    $eventCountAfter = count($lastDialogFull);
    if ($eventCountBefore > $eventCountAfter) {
        error_log("[buildHistoricContext] Consolidated events: {$eventCountBefore} -> {$eventCountAfter} (saved " . ($eventCountBefore - $eventCountAfter) . " slots)");
    }

    // Filter ambient combat deaths if configured.
    if (!empty($GLOBALS["HIDE_AMBIENT_COMBAT"])) {
        $protectedCombatNames = array_filter(array_map('trim', [
            (string)($GLOBALS["PLAYER_NAME"] ?? ''),
            (string)$actor,
        ]));
        try {
            $partyData = json_decode(DataGetCurrentPartyConf(), true);
            if (is_array($partyData)) {
                foreach (array_keys($partyData) as $partyName) {
                    $partyName = trim((string)$partyName);
                    if ($partyName !== '') {
                        $protectedCombatNames[] = $partyName;
                    }
                }
            }
        } catch (Throwable $e) {
            // Party lookup is best-effort; player/current actor protection still applies.
        }
        $protectedCombatNames = array_values(array_unique($protectedCombatNames));
        $beforeFilter = count($lastDialogFull);
        $lastDialogFull = array_values(array_filter($lastDialogFull, function($event) use ($protectedCombatNames) {
            // Keep non-death events
            if (!isset($event['type']) || $event['type'] !== 'death') {
                return true;
            }

            // Keep death events involving the player, current actor, or current party members.
            $content = $event['content'] ?? '';
            foreach ($protectedCombatNames as $name) {
                if ($name !== '' && stripos($content, $name) !== false) {
                    return true;
                }
            }

            return false;
        }));
        $afterFilter = count($lastDialogFull);
        if ($beforeFilter > $afterFilter) {
            error_log("[buildHistoricContext] Filtered ambient combat: {$beforeFilter} -> {$afterFilter} (removed " . ($beforeFilter - $afterFilter) . " events)");
        }
    }

    file_put_contents(__DIR__."/../log/context_for_{$actor}_stage_1_.txt",print_r($query,true),FILE_APPEND);
    
    return $lastDialogFull;

}

function compactHistoricContext($lastDialogFull,$actor,$compactContextInfo=false) {

    $lastrole="";
    $bufferDialectic=[];
    $lastDialogFullCopy=[];
    $compactedBuffer = "";
 
    foreach ($lastDialogFull as $n => $line) {
        if (($line["role"] == "assistant")) {
            $isJson=json_decode($line["content"],true);
            if (is_array($isJson)) {
                $lastDialogFullCopy[]=$line;
                continue;
            }
            $cleanedText=$line["content"];
           
            $bufferDialectic[]=$cleanedText;

            
        } else {
            if ($lastrole=="assistant") {
                // This breaks with spaces?
                $compactedBuffer="";
                foreach ($bufferDialectic as $m=>$singleline) {
                    $compactedBuffer .=" ";
                    if ($m>0) {
                        //$regexpNpcName = strtr($GLOBALS["DIALECTIC_NAME"],["-"=>'\-', "["=>"\[", "]"=>"\]"]);
                        // Capture spoken text after a leading "Name:" (supports names with brackets and dashes)
                        // and optionally strip a trailing parenthetical note like "(talking to X)".
                        preg_match('/^\s*[^:]+:\s*(.*?)\s*(?:\([^)]*\))?\s*$/s', $singleline, $matches);
                        $extracted=$matches[1] ?? $singleline;
                        $compactedBuffer .= trim(removeTalkingToOccurrences($extracted));
                        $compactedBuffer=str_replace("{$GLOBALS["DIALECTIC_NAME"]};","",$compactedBuffer);

                    } else {
                        $compactedBuffer .= trim(removeTalkingToOccurrences($singleline));
                        $compactedBuffer=str_replace("{$GLOBALS["DIALECTIC_NAME"]}:","",$compactedBuffer);
                    }


                }
                $lastDialogFullCopy[] = ["role"=>"assistant","content"=>trim($compactedBuffer)];

            }
            $bufferDialectic=[];
            $compactedBuffer="";
            $lastDialogFullCopy[]=$line;
        } 

        
        
        $lastrole=$line["role"];
    }

    // Last entry
    if (sizeof($bufferDialectic)>0) {
        foreach ($bufferDialectic as $m=>$singleline) {
            $compactedBuffer .=" ";
            if ($m>0) {
                //$regexpNpcName = strtr($GLOBALS["DIALECTIC_NAME"],["-"=>'\-', "["=>"\[", "]"=>"\]"]);
                // Same robust extraction for subsequent lines in the buffer
                preg_match('/^\s*[^:]+:\s*(.*?)\s*(?:\([^)]*\))?\s*$/s', $singleline, $matches);
                $extracted=$matches[1] ?? $singleline;
                $compactedBuffer .= trim(removeTalkingToOccurrences($extracted));
                $compactedBuffer=str_replace("{$GLOBALS["DIALECTIC_NAME"]};","",$compactedBuffer);

            } else {
                $compactedBuffer .= trim(removeTalkingToOccurrences($singleline));
                $compactedBuffer=str_replace("{$GLOBALS["DIALECTIC_NAME"]};","",$compactedBuffer);
            }



        }
        $lastDialogFullCopy[] = ["role"=>"assistant","content"=>trim($compactedBuffer)];
        $bufferDialectic=[];
    }

    // file_put_contents(__DIR__."/../log/context_for_{$actor}_stage_1_5_.txt",print_r($lastDialogFullCopy,true));

    
    // Compact other info
    $lastSpeaker = "";
    $buffer = [];
    $lastDialogFull=[];


    foreach ($lastDialogFullCopy as $n => $line) {
        $speaker=$line["role"];
        
        if ($speaker=="npc") { // Tricky, npc could be any char
            preg_match('/^([^:]+):/', $line["content"], $matches);
            // Output the extracted name
            $speakerNPC=$matches[1] ?? "";
            $speaker="npc_$speakerNPC";
        }
        

        if ($lastSpeaker == $speaker) {
            // Same speaker as last iteration, remove extra text
            if (strpos($speaker,"npc") === 0 || $speaker == "narratorchat") {
                $matches = [];
                
                // Clean talking to and npc name , only leave it on first line
                $matches = [];
                // And for compacting other dialog lines: capture content after the speaker name
                preg_match('/^\s*[^:]+:\s*(.*?)\s*(?:\([^)]*\))?\s*$/s', $line["content"], $matches);
                $buffer[]=$matches[1] ?? $line["content"];
            } else {

                if (!$compactContextInfo) {
                    $lastDialogFull[]=array('role' => $lastSpeaker, 'content' => trim(isset($buffer[0])?$buffer[0]:$line["content"]));
                    if (isset($buffer[0])) {
                        $buffer = [];
                        $buffer[] = $line["content"];
                    } else
                        $buffer = [];
                } else {
                    $buffer[] = strtr($line["content"],["The Narrator:"=>"","{$GLOBALS["DIALECTIC_NAME"]}:"=>""]);
                }
                
            }
        } else {

            if (sizeof($buffer) > 0) {
                if ($lastSpeaker=="narratorci" || $lastSpeaker=="narratorloc") {
                    if (!$compactContextInfo) {
                        $lastDialogFull[] = array('role' => $lastSpeaker, 'content' => "".implode(" ", removeEmptyElements($buffer)));  // Should be only one line
                    } else {
                        $lastDialogFull[] = array('role' => $lastSpeaker, 'content' => "* ".implode("\n* ", removeEmptyElements($buffer))); 
                    }

                }
                else if ($lastSpeaker=="backgroundchat")
                    $lastDialogFull[] = array('role' => $lastSpeaker, 'content' => implode("\n", removeEmptyElements($buffer)));
                else 
                    $lastDialogFull[] = array('role' => $lastSpeaker, 'content' => moveDialogueTargetSuffixToEnd(implode(" ", removeEmptyElements($buffer))));
            }
            $buffer = [];
            $buffer[] = $line["content"];
            $lastSpeaker = $speaker;

            if ($speaker=="assistant") {    //Leave as is
                $lastDialogFull[] = $line;
                $lastSpeaker = "";
                $buffer = [];
                continue;
            }
        }

    }

    // Clean empty entries
    $bufferCopy=[];
    foreach ($buffer as $n=>$bufferEntry) {
        if (!empty(trim($bufferEntry)))
            $bufferCopy[]=$bufferEntry;

    }

    // Last buffer, probably user input.
    if (sizeof($bufferCopy)) {
        if ($lastSpeaker=="narratorci" || $lastSpeaker=="narratorloc") 
            $lastDialogFull[] = array('role' => $lastSpeaker, 'content' => implode("\n* ", $bufferCopy));
        else if ($lastSpeaker=="backgroundchat")
            $lastDialogFull[] = array('role' => $lastSpeaker, 'content' => implode("\n", $bufferCopy));
        else 
            $lastDialogFull[] = array('role' => $lastSpeaker, 'content' => moveDialogueTargetSuffixToEnd(implode(" ", $bufferCopy)));
    }

    $contextDataHistory=[];
    foreach ($lastDialogFull as $n=>$lastDialogFullEntry) {
        if (!empty(trim($lastDialogFullEntry["content"])))
                $contextDataHistory[]=$lastDialogFullEntry;

    }

    file_put_contents(__DIR__."/../log/context_for_{$actor}_stage_2_.txt",print_r($contextDataHistory,true));
    return $contextDataHistory;
}

function replaceRoles($lastDialogFull,$actor,$lastNelements) {

     // Replace roles for user.
     foreach ($lastDialogFull as $n => $line) {
        if ($line["role"] == "player") {
            $lastDialogFull[$n]["role"] = "user";
        } else if (strpos($line["role"],"npc")===0) {
        
            $lastDialogFull[$n]["role"] = "user";
        
        } else if ($line["role"] == "backgroundchat") {
        
            $lastDialogFull[$n]["role"] = "user";
            if (strlen(trim($lastDialogFull[$n]["content"])) > 0) {
                $lastDialogFull[$n]["content"] = " (... ".PHP_EOL.$lastDialogFull[$n]["content"]."\n...)";
            }
            
        } else if ($line["role"] == "narratorci") {
        
            $lastDialogFull[$n]["role"] = "user";
            $lastDialogFull[$n]["content"] = $lastDialogFull[$n]["content"]."\n";
        
        } else if ($line["role"] == "narratorchat") {

            $lastDialogFull[$n]["role"] = "user";

        } else if ($line["role"] == "narratorloc") {

            $lastDialogFull[$n]["role"] = "user";

        }
    }

    // Date issues

    foreach ($lastDialogFull as $n => $line) {

        $pattern = '/(\w+), (\d{1,2}:\d{2} (?:AM|PM)), (\d{1,2})(?:st|nd|rd|th) of ([A-Za-z\'\ ]+), (\d+)/';
        $replacement = 'Day name: $1, Hour: $2, Day Number: $3, Month: $4, Year: $5';
        $result = preg_replace($pattern, $replacement, $line["content"]);
        $lastDialogFull[$n]["content"] = $result;
    }



    error_log("[DIALECTIC] Using effective context limit of : $lastNelements");
    $orderedData = array_slice($lastDialogFull, $lastNelements);

    file_put_contents(__DIR__."/../log/context_for_$actor.txt",print_r($orderedData,true));
    $GLOBALS["CONTEXT_BUILDING_DATA"]=$orderedData;

    return $GLOBALS["CONTEXT_BUILDING_DATA"];

}

function DataLastDataExpandedFor($actor, $lastNelements = -10,$sqlfilter="")
{

    $localStartTime=microtime(true);

    $ctx1=buildHistoricContext($actor, $lastNelements ,$sqlfilter);    
    error_log("[buildHistoricContext] Elapsed time: " . (microtime(true) - $localStartTime) . " seconds");


    $ctx2=compactHistoricContext($ctx1,$actor,false);  // Don't compact Context Info

    error_log("[compactHistoricContext] Elapsed time: " . (microtime(true) - $localStartTime) . " seconds");

    $ctx3=replaceRoles($ctx2,$actor,$lastNelements);
      
    error_log("[replaceRoles] Elapsed time: " . (microtime(true) - $localStartTime) . " seconds");

    // Cases of self rechat
    if ((sizeof($ctx3)>3)&&(($GLOBALS["gameRequest"][3] ?? "")=="rechat")) {
        $lastElement = $ctx3[sizeof($ctx3)-1];
        // Last element is assistant
        if ($lastElement["role"]=="assistant") {
            if ($GLOBALS["gameRequest"][3]=="rechat") {
                // NPC is rechatting himself
                
                Logger::warn("[RECHAT] actor is replying itself, case 1, aborting");

                dialectic_abort_json_response();
            }

        }

        $preLastElement = $ctx3[sizeof($ctx3)-2];
        // Pre last element is assistant, and last is a memory.
        if (($preLastElement["role"]=="assistant")&&(strpos($lastElement["content"],"MEMORY")!==false)) {
            if ($GLOBALS["gameRequest"][3]=="rechat") {
                // NPC is rechatting himself
                
                Logger::warn("[RECHAT] actor is replying itself,case 2, aborting");

                dialectic_abort_json_response();
            }

        }
    }

    //error_log("[DataLastDataExpandedFor end] Elapsed time: " . (microtime(true) - $localStartTime) . " seconds");

    return $ctx3;

}

function DataSpeechJournal($topic,$limit=50) 
{

    global $db;

    $lastDialogFull = [];
    $tn=$db->escape($topic);
    $results = $db->fetchAll("SElECT  speaker,speech,location,listener,topic as quest, convert_gamets2fallout_date(gamets) AS fallout_date, gamets FROM speech
     where (speaker like '%$tn%' or  listener like '%$tn%' or location like '%$tn%' or  
      companions like '%|$tn|%' or  companions like '%$tn%' OR companions LIKE '%|$tn (busy)|%' 
      OR companions LIKE '%|$tn (hostile)|%' OR companions LIKE '%|$tn (restrained)|%' ) 
      and listener<>'unknown' 
      order by rowid desc");
    if (!$results) {
        return json_encode([]);
    }

    $data = [];

    foreach ($results as $row) {
        $data[] = $row;
    }

    if (sizeof($data) == 0) {
        return json_encode([]);
    } elseif (sizeof($data) < $limit) {
        $dataReversed = array_reverse($data);
    } else {
        $smalldata = array_slice($data, 0,$limit);
        $dataReversed = array_reverse($smalldata);
    }


    return json_encode($dataReversed);

}

function dialecticGetLatestTravelingPartyMemberNames(): array
{
    $party = json_decode(DataGetCurrentPartyConf(), true);
    if (!is_array($party)) {
        return [];
    }

    $names = [];
    foreach (array_keys($party) as $name) {
        $name = trim((string)$name);
        if ($name === '' || $name === '<no name>') {
            continue;
        }

        $names[] = $name;
    }

    return array_values(array_unique($names));
}

function dialecticIsActorInCurrentTravelingParty(string $actorName): bool
{
    $actorName = trim($actorName);
    if ($actorName === '') {
        return false;
    }

    foreach (dialecticGetLatestTravelingPartyMemberNames() as $memberName) {
        if (strcasecmp(trim((string)$memberName), $actorName) === 0) {
            return true;
        }
    }

    return false;
}

function DataGetCurrentTask()
{
    global $db;

    $includeActiveQuests = function_exists('dialecticPromptContextOptionEnabled')
        ? dialecticPromptContextOptionEnabled('enabled_general_subsections', 'active_quests')
        : true;

    if (!$includeActiveQuests) {
        return "";
    }

    $data = "";

    // quests are synchronized as JSON snapshots from gamedata.php.
    // for now lets just list all active quests rather than saying Current: xxx Previous: yyy
    // ! listing all quests could generate thousands tokens in prompt, let's limit
    $results = $db->fetchAll("SElECT name, briefing as description,gamets,status FROM quests order by CASE WHEN status='selected' THEN 0 ELSE 1 END, gamets desc LIMIT 8"); 
    if (!$results) {
        Logger::info("No quests ".__FILE__." ".__LINE__." ".__FUNCTION__);
        return $data . "\n\n#Active Quests\nNo active quests right now.";
    }

    // dont think we need to limit it now since we dont require exactly two to format Current: xxx Previous: yyy
    //    if (sizeof($results)>2) {
    //        Logger::info("Too much quests ".__FILE__);
    //        return $data;
    //    }

    $data .= "\n\n<active_quests>\n#Active Quests\n";
    foreach ($results as $row) {
        $questDesc = trim($row["description"]);
        if (strcasecmp($questDesc, trim((string)$row["name"])) === 0) {
            $questDesc = "";
        }
        $questPrefix = (($row["status"] ?? '') === 'selected') ? 'Current: ' : '';
        if (!empty($questDesc)) {
            $data .= "## {$questPrefix}{$row["name"]}: $questDesc\n";
        } else {
            $data .= "## {$questPrefix}{$row["name"]}\n";
        }
    }
    $data .="</active_quests>\n";
    return $data;
}


function DataLastRetFunc($actor, $lastNelements = -2)
{
    global $db;
    $lastDialogFull = array();
    $results = $db->fetchAll("select  a.data  as data  FROM  eventlog a 
    WHERE data like '%$actor%' and type in ('funcret')  order by gamets desc,ts desc LIMIT 1 OFFSET 0");
    $lastData = "";
    foreach ($results as $row) {
        $pattern = "/\{(.*?)\(/";
        preg_match($pattern, $row["data"], $matches);
        $functionName = $matches[1];
        $lastDialogFull[] = array('role' => 'function', 'name' => $functionName, 'content' => $row["data"]);

    }

    $lastDialogFullReversed = array_reverse($lastDialogFull);
    $lastDialog = array_slice($lastDialogFullReversed, $lastNelements);
    $last_location = null;

    // Remove Context Location part when repeated
    foreach ($lastDialog as $k => $message) {
        preg_match('/\(Context location: [^)]+?\)/', $message['content'], $matches);
        $current_location = isset($matches[1]) ? $matches[1] : null;
        if ($current_location === $last_location) {
            $message['content'] = preg_replace('/\(Context location: [^)]+?\)/', '', $message['content']);
        } else {
            $last_location = $current_location;
        }
        $lastDialog[$k]["content"] = $message['content'];
    }


    return $lastDialog;

}

function DataLastAction($actor)
{
    global $db;
    
    $lastDialogFull = array();
    $cnActor = $db->escape($actor);
    $results = $db->fetchOne("select  *  FROM public.actions_issued
    WHERE actorname='$cnActor' order by gamets desc,ts desc LIMIT 1 OFFSET 0");
    
    return $results;

}

function DataActorHasDied($actor)
{
    global $db;
    
    $lastDialogFull = array();
    $cnActor = $db->escape($actor);
    
    $rows = $GLOBALS["db"]->fetchAll("select 1 as n,gamets from eventlog where type='death'
        and (data like '%defeated $cnActor%' or data like '%killed $cnActor%')
        order by gamets desc limit 1");
    if ($rows)
        return true;
    
    return false;

}

function DataLastKnowDate() 
{
    if (isset($GLOBALS["CACHE_LAST_KNOW_DATE"])) {
        return $GLOBALS["CACHE_LAST_KNOW_DATE"];
    }

    $payloadDate = dialecticWorldContextDateText(dialecticLatestWorldContextPayload());
    if ($payloadDate !== "") {
        $GLOBALS["CACHE_LAST_KNOW_DATE"] = $payloadDate;
        return $GLOBALS["CACHE_LAST_KNOW_DATE"];
    }

    $GLOBALS["CACHE_LAST_KNOW_DATE"] = "";
    return "";
}


function DataLastKnownLocation()
{
    if (isset($GLOBALS["CACHE_LAST_KNOWN_LOCATION"])) {
        return $GLOBALS["CACHE_LAST_KNOWN_LOCATION"];
    }

    $location = dialecticWorldContextLocationFromPayload(dialecticLatestWorldContextPayload());
    $GLOBALS["CACHE_LAST_KNOWN_LOCATION"] = $location;
    return $GLOBALS["CACHE_LAST_KNOWN_LOCATION"];

}

function dialecticWorldContextIsUnknown($value): bool
{
    $clean = strtolower(trim((string)$value));
    return $clean === '' || $clean === 'unknown' || $clean === 'unknown location' || $clean === 'none' || $clean === 'null';
}

function dialecticLatestWorldContextPayload(): ?array
{
    global $db;

    $rows = $db->fetchAll(
        "SELECT party, data
           FROM eventlog
          WHERE type='world_context'
            AND COALESCE(party, '') <> ''
          ORDER BY localts DESC, ts DESC, rowid DESC
          LIMIT 1"
    );
    if (!is_array($rows) || empty($rows)) {
        return null;
    }

    $payload = json_decode($rows[0]['party'] ?? '', true);
    if (!is_array($payload)) {
        return null;
    }

    $contextText = (string)($rows[0]['data'] ?? '');
    if ($contextText !== '' && preg_match('/\(\s*Context\s+location:\s*([^,\)]+)/iu', $contextText, $matches)) {
        $contextLocation = trim((string)($matches[1] ?? ''));
        $payloadLocation = trim((string)($payload['location'] ?? ''));
        $payloadWorldspace = trim((string)($payload['worldspace'] ?? ''));
        if ($contextLocation !== ''
            && !dialecticWorldContextIsUnknown($contextLocation)
            && (dialecticWorldContextIsUnknown($payloadLocation) || strcasecmp($payloadLocation, $payloadWorldspace) === 0)
        ) {
            $payload['location'] = $contextLocation;
        }
    }

    return $payload;
}

function dialecticWorldContextLocationFromPayload(?array $payload): string
{
    if (!is_array($payload)) {
        return "";
    }

    $location = trim((string)($payload['location'] ?? ''));
    if (!dialecticWorldContextIsUnknown($location)) {
        return $location;
    }

    $worldspace = trim((string)($payload['worldspace'] ?? ''));
    return dialecticWorldContextIsUnknown($worldspace) ? "" : $worldspace;
}

function dialecticWorldContextWorldspaceFromPayload(?array $payload): string
{
    if (!is_array($payload)) {
        return "";
    }

    $worldspace = trim((string)($payload['worldspace'] ?? ''));
    return dialecticWorldContextIsUnknown($worldspace) ? "" : $worldspace;
}

function dialecticWorldContextDateText(?array $payload): string
{
    if (!is_array($payload) || empty($payload['game_time']) || !is_array($payload['game_time'])) {
        return "";
    }

    $gameTime = $payload['game_time'];
    $year = intval($gameTime['year'] ?? 0);
    $month = intval($gameTime['month'] ?? 0);
    $day = intval($gameTime['day'] ?? 0);
    $months = [
        1 => 'January',
        2 => 'February',
        3 => 'March',
        4 => 'April',
        5 => 'May',
        6 => 'June',
        7 => 'July',
        8 => 'August',
        9 => 'September',
        10 => 'October',
        11 => 'November',
        12 => 'December',
    ];

    if ($year <= 0 || $month < 1 || $month > 12 || $day < 1 || $day > 31) {
        return "";
    }

    return "{$months[$month]} {$day}, {$year}";
}

function dialecticWorldContextTimeText(?array $payload): string
{
    if (!is_array($payload) || empty($payload['game_time']) || !is_array($payload['game_time'])) {
        return "";
    }

    $hourFloat = floatval($payload['game_time']['hour'] ?? -1);
    if ($hourFloat < 0.0) {
        return "";
    }

    $totalMinutes = ((int)round($hourFloat * 60.0)) % 1440;
    $hour24 = intdiv($totalMinutes, 60);
    $minute = $totalMinutes % 60;
    $ampm = $hour24 >= 12 ? "PM" : "AM";
    $hour12 = $hour24 % 12;
    if ($hour12 === 0) {
        $hour12 = 12;
    }

    $clock = sprintf("%d:%02d %s", $hour12, $minute, $ampm);
    $dayPart = hour2part_of_day(sprintf("%02d", $hour24));
    return $dayPart !== "" ? "{$clock}, {$dayPart}" : $clock;
}

function normalizeLocationContextToken($value, $stripStateSuffix = false)
{
    $value = trim((string) $value);
    if ($value === "") {
        return "";
    }

    $value = preg_replace('/\s+/u', ' ', $value);
    $value = trim((string) $value, " \t\n\r\0\x0B,");

    if ($stripStateSuffix) {
        $value = preg_replace('/\s+(outdoors|interior)\s*$/iu', '', $value);
        $value = trim((string) $value, " \t\n\r\0\x0B,");
    }

    return $value;
}

function getCanonicalRegionGroups()
{
    return [
        "Mojave Wasteland" => ["Mojave Wasteland", "Mojave"],
        "New Vegas" => ["New Vegas", "Freeside", "The Strip"],
        "Capital Wasteland" => ["Capital Wasteland", "DC Wasteland"],
    ];
}

function canonicalizeRegionName($value)
{
    static $aliasMap = null;

    if ($aliasMap === null) {
        $aliasMap = [];
        foreach (getCanonicalRegionGroups() as $canonical => $aliases) {
            foreach ($aliases as $alias) {
                $aliasKey = strtolower(normalizeLocationContextToken($alias, true));
                if ($aliasKey !== "") {
                    $aliasMap[$aliasKey] = $canonical;
                }
            }
        }
    }

    $valueKey = strtolower(normalizeLocationContextToken($value, true));
    if ($valueKey === "") {
        return "";
    }

    return $aliasMap[$valueKey] ?? "";
}

function getCanonicalRegionAliases($value)
{
    $canonical = canonicalizeRegionName($value);
    $groups = getCanonicalRegionGroups();

    if ($canonical !== "" && isset($groups[$canonical])) {
        return $groups[$canonical];
    }

    $value = normalizeLocationContextToken($value, true);
    return $value !== "" ? [$value] : [];
}

function DataLastKnownLocationContextParts($cached = false)
{
    if (isset($GLOBALS["CACHE_LAST_KNOWN_LOCATION_CONTEXT_PARTS"])) {
        return $GLOBALS["CACHE_LAST_KNOWN_LOCATION_CONTEXT_PARTS"];
    }

    $payload = dialecticLatestWorldContextPayload();
    $payloadLocation = dialecticWorldContextLocationFromPayload($payload);
    if ($payloadLocation !== "") {
        $GLOBALS["CACHE_LAST_KNOWN_LOCATION_CONTEXT_PARTS"] = [
            "location" => $payloadLocation,
            "location_base" => normalizeLocationContextToken($payloadLocation, true),
            "region_raw" => "",
        ];
        return $GLOBALS["CACHE_LAST_KNOWN_LOCATION_CONTEXT_PARTS"];
    }

    $GLOBALS["CACHE_LAST_KNOWN_LOCATION_CONTEXT_PARTS"] = [
        "location" => "",
        "location_base" => "",
        "region_raw" => "",
    ];

    return $GLOBALS["CACHE_LAST_KNOWN_LOCATION_CONTEXT_PARTS"];
}

function DataLastKnownLocationBaseHuman($cached = false)
{
    $parts = DataLastKnownLocationContextParts($cached);
    return $parts["location_base"] ?? "";
}

function locationFieldMatchesCandidate($row, $field, $candidateKey)
{
    if (!isset($row[$field])) {
        return false;
    }

    return strtolower(normalizeLocationContextToken($row[$field], true)) === $candidateKey;
}

function resolveCanonicalRegionFromLocationRows($rows, $candidateKey)
{
    if (!is_array($rows) || empty($rows) || $candidateKey === "") {
        return "";
    }

    $prioritizedMatches = [
        ["matchField" => "name", "valueField" => "region"],
        ["matchField" => "region", "valueField" => "region"],
    ];

    foreach ($prioritizedMatches as $rule) {
        foreach ($rows as $row) {
            if (locationFieldMatchesCandidate($row, $rule["matchField"], $candidateKey)) {
                $canonical = canonicalizeRegionName($row[$rule["valueField"]] ?? "");
                if ($canonical !== "") {
                    return $canonical;
                }
            }
        }
    }

    foreach (["region", "name"] as $field) {
        foreach ($rows as $row) {
            $canonical = canonicalizeRegionName($row[$field] ?? "");
            if ($canonical !== "") {
                return $canonical;
            }
        }
    }

    return "";
}

function locationsTableColumns()
{
    global $db;

    if (isset($GLOBALS["CACHE_LOCATIONS_TABLE_COLUMNS"])) {
        return $GLOBALS["CACHE_LOCATIONS_TABLE_COLUMNS"];
    }

    $columns = [];
    try {
        $rows = $db->fetchAll(
            "SELECT column_name
               FROM information_schema.columns
              WHERE table_name = 'locations'"
        );
        foreach ($rows as $row) {
            $column = strtolower((string) ($row["column_name"] ?? ""));
            if ($column !== "") {
                $columns[$column] = true;
            }
        }
    } catch (Throwable $e) {
        Logger::warn("[locations] Could not inspect locations table columns: " . $e->getMessage());
    }

    if (empty($columns)) {
        $columns["name"] = true;
    }

    $GLOBALS["CACHE_LOCATIONS_TABLE_COLUMNS"] = $columns;
    return $columns;
}

function lookupCanonicalRegionByLocationCandidate($candidate)
{
    global $db;

    $candidateKey = trim(strtolower(normalizeLocationContextToken($candidate, true)));
    if ($candidateKey === "") {
        return "";
    }

    if (isset($GLOBALS["CACHE_CANONICAL_REGION_BY_LOCATION_CANDIDATE"][$candidateKey])) {
        return $GLOBALS["CACHE_CANONICAL_REGION_BY_LOCATION_CANDIDATE"][$candidateKey];
    }

    $columns = locationsTableColumns();
    $candidateEsc = $db->escape($candidateKey);

    $selectFields = ["name"];
    $whereFields = ["name"];

    if (isset($columns["region"])) {
        $selectFields[] = "region";
        $whereFields[] = "region";
    } elseif (isset($columns["worldspace"])) {
        $selectFields[] = "worldspace AS region";
        $whereFields[] = "worldspace";
    } else {
        $selectFields[] = "'' AS region";
    }

    $whereClauses = [];
    foreach (array_unique($whereFields) as $field) {
        if (isset($columns[strtolower($field)])) {
            $whereClauses[] = "LOWER({$field}) = '{$candidateEsc}'";
        }
    }

    if (empty($whereClauses)) {
        $GLOBALS["CACHE_CANONICAL_REGION_BY_LOCATION_CANDIDATE"][$candidateKey] = "";
        return "";
    }

    $rows = $db->fetchAll(
        "SELECT " . implode(", ", $selectFields) . "
           FROM locations
          WHERE " . implode(" OR ", $whereClauses)
    );

    $canonical = resolveCanonicalRegionFromLocationRows($rows, $candidateKey);
    if ($canonical === "" && is_array($rows)) {
        foreach ($rows as $row) {
            if (!locationFieldMatchesCandidate($row, "name", $candidateKey)) {
                continue;
            }

            $parentCandidate = normalizeLocationContextToken($row["region"] ?? "", true);
            if ($parentCandidate !== "" && $parentCandidate !== $candidateKey) {
                $canonical = lookupCanonicalRegionByLocationCandidate($parentCandidate);
                if ($canonical !== "") {
                    break;
                }
            }
        }
    }
    $GLOBALS["CACHE_CANONICAL_REGION_BY_LOCATION_CANDIDATE"][$candidateKey] = $canonical;

    return $canonical;
}

function DataLastKnownCanonicalRegionHuman($cached = false)
{
    $cacheKey = "REGION_CANONICAL";
    if (isset($GLOBALS["CACHE_LAST_KNOWN_LOCATION_HUMAN"][$cacheKey])) {
        return $GLOBALS["CACHE_LAST_KNOWN_LOCATION_HUMAN"][$cacheKey];
    }

    $parts = DataLastKnownLocationContextParts($cached);
    $canonical = "";

    $currentLocation = $parts["location_base"] ?? "";
    if ($currentLocation !== "") {
        $canonical = lookupCanonicalRegionByLocationCandidate($currentLocation);
    }

    $reportedRegion = $parts["region_raw"] ?? "";
    if ($canonical === "" && $reportedRegion !== "") {
        $canonical = canonicalizeRegionName($reportedRegion);
    }
    if ($canonical === "" && $reportedRegion !== "") {
        $canonical = lookupCanonicalRegionByLocationCandidate($reportedRegion);
    }
    if ($canonical === "" && $reportedRegion !== "") {
        $canonical = normalizeLocationContextToken($reportedRegion, true);
    }

    $GLOBALS["CACHE_LAST_KNOWN_LOCATION_HUMAN"][$cacheKey] = $canonical;
    return $canonical;
}

function DataLastKnownLocationHuman($region=false,$cached=false)
{

    $cache_key = $region ? "REGION" : "LOC";
    if (isset($GLOBALS["CACHE_LAST_KNOWN_LOCATION_HUMAN"][$cache_key]))
        return $GLOBALS["CACHE_LAST_KNOWN_LOCATION_HUMAN"][$cache_key];

    $parts = DataLastKnownLocationContextParts($cached);
    $val = $region ? ($parts["region_raw"] ?? "") : ($parts["location"] ?? "");

    $GLOBALS["CACHE_LAST_KNOWN_LOCATION_HUMAN"][$cache_key] = $val;
    return $val;

}

function buildWorldPrompt($gamets = 0)
{
    $worldLines = [];
    $worldPayload = dialecticLatestWorldContextPayload();

    $currentWorldspace = dialecticWorldContextWorldspaceFromPayload($worldPayload);
    $currentLoc = trim(dialecticWorldContextLocationFromPayload($worldPayload));
    if ($currentLoc === "") {
        $currentLoc = trim(DataLastKnownLocationHuman(false, false));
    }
    if ($currentWorldspace !== "") {
        $worldLines[] = "  <worldspace>" . xml_fragment_escape_text($currentWorldspace) . "</worldspace>";
    }
    if ($currentLoc !== "") {
        $worldLines[] = "  <location>" . xml_fragment_escape_text($currentLoc) . "</location>";
    }

    $currentWeather = "";
    if (is_array($worldPayload)) {
        $currentWeather = trim((string)($worldPayload['weather'] ?? ''));
    }
    if ($currentWeather === "") {
        $currentWeather = trim(DataLastKnownWeatherHuman());
    }
    if ($currentWeather !== "") {
        $worldLines[] = "  <weather>" . xml_fragment_escape_text($currentWeather) . "</weather>";
    }

    $directDate = dialecticWorldContextDateText($worldPayload);
    $directTime = dialecticWorldContextTimeText($worldPayload);
    if ($directDate !== "" || $directTime !== "") {
        if ($directDate !== "") {
            $worldLines[] = "  <date>" . xml_fragment_escape_text($directDate) . "</date>";
        }
        if ($directTime !== "") {
            $worldLines[] = "  <time>" . xml_fragment_escape_text($directTime) . "</time>";
        }
    }

    if (empty($worldLines)) {
        return "";
    }

    return "\n\n<world>\n" . implode("\n", $worldLines) . "\n</world>";
}

function DataLastKnownWeatherHuman()
{
    $cacheKey = "WEATHER";
    if (isset($GLOBALS["CACHE_LAST_KNOWN_LOCATION_HUMAN"][$cacheKey])) {
        return $GLOBALS["CACHE_LAST_KNOWN_LOCATION_HUMAN"][$cacheKey];
    }

    global $db;

    $payload = dialecticLatestWorldContextPayload();
    if (is_array($payload)) {
        $payloadWeather = trim((string)($payload['weather'] ?? ''));
        if ($payloadWeather !== "") {
            $GLOBALS["CACHE_LAST_KNOWN_LOCATION_HUMAN"][$cacheKey] = $payloadWeather;
            return $payloadWeather;
        }
    }

    $GLOBALS["CACHE_LAST_KNOWN_LOCATION_HUMAN"][$cacheKey] = "";
    return "";
}


function PackIntoSummary($onlyMissingDiary=false)
{

    global $db;
    
    if ($onlyMissingDiary) {
        $results = $db->query("insert into memory_summary (gamets_truncated,n,packed_message,summary,classifier,uid,companions,scope)
        select gamets,1,message,message,'diary',uid,
            case
                when nullif(trim(speaker), '') is null then ''
                else '|' || trim(both '|' from trim(speaker)) || '|'
            end,
            'global'
        from memory
        where event in ('diary','auto_diary')
        and uid not in (select uid from memory_summary where classifier in  ('diary','auto_diary'))");

        $maxRow=0;

    } else {
        $lastGameTsRecord = $GLOBALS["db"]->fetchOne("select gamets as gamets from eventlog order by gamets desc LIMIT 1"); // 2.1ms
        $results = $GLOBALS["db"]->fetchAll("select gamets_truncated from memory_summary order by gamets_truncated desc LIMIT 1"); // 0.5ms, faster 

        $maxRow = isset($results[0]["gamets_truncated"]) ? intval($results[0]["gamets_truncated"]) : 0;
        $minRow = intval($lastGameTsRecord["gamets"]);
        $minRowTs = intval($lastGameTsRecord["gamets"] -  ( 1 /0.0000024));
        
        $pfi = intval($GLOBALS["FEATURES"]["MEMORY_EMBEDDING"]["AUTO_CREATE_SUMMARY_INTERVAL"] ?? 10) * 100000;
        $minEventsPerSummary = intval($GLOBALS["FEATURES"]["MEMORY_EMBEDDING"]["AUTO_CREATE_SUMMARY_MIN_EVENTS"] ?? 5);
        if ($minEventsPerSummary < 1) {
            $minEventsPerSummary = 1;
        }
        // Queue boundaries are hard-cut by location changes. Dialogue rows inherit
        // the most recent world-context location so they remain in the scene queue.
        $query="insert into memory_summary (gamets_truncated,n,packed_message,summary,classifier,uid,scope,companions)
                                with source_rows as (
                                    select
                                        uid,
                                        gamets,
                                        coalesce(ts, 0) as ts,
                                        message,
                                        speaker,
                                        listener,
                                        round(gamets::numeric/$pfi, 0) as time_bucket,
                                        trim(regexp_replace(coalesce(
                                            substring(message from '(?i)\\(context\\s+(?:new\\s+)?location:\\s*([^,\\)]+)'),
                                            substring(message from '(?i)\\(at\\s+([^\\)]+)\\)'),
                                            ''
                                        ), '\\s+', ' ', 'g')) as location_key
                                    from memory_v
                                    where message not ilike 'Dear Diary%'
                                      and gamets>$maxRow
                                ),
                                location_grouped_rows as (
                                    select
                                        *,
                                        count(location_key) filter (where location_key <> '') over (
                                            order by gamets asc, ts asc, uid asc
                                            rows between unbounded preceding and current row
                                        ) as location_group
                                    from source_rows
                                ),
                                normalized_rows as (
                                    select
                                        uid,
                                        gamets,
                                        ts,
                                        message,
                                        speaker,
                                        listener,
                                        time_bucket,
                                        case
                                            when location_group=0 then null
                                            else lower(max(nullif(location_key, '')) over (partition by location_group))
                                        end as location_key
                                    from location_grouped_rows
                                ),
                                queue_boundaries as (
                                    select
                                        uid,
                                        gamets,
                                        ts,
                                        message,
                                        speaker,
                                        listener,
                                        time_bucket,
                                        location_key,
                                        lag(location_key) over (order by gamets asc, ts asc, uid asc) as prev_location_key,
                                        lag(time_bucket) over (order by gamets asc, ts asc, uid asc) as prev_time_bucket
                                    from normalized_rows
                                ),
                                queued_rows as (
                                    select
                                        uid,
                                        gamets,
                                        ts,
                                        message,
                                        speaker,
                                        listener,
                                        case
                                            when prev_time_bucket is null then 1
                                            when location_key is null then 1
                                            when prev_location_key is null then 1
                                            when location_key<>prev_location_key then 1
                                            when time_bucket<>prev_time_bucket then 1
                                            else 0
                                        end as is_new_queue
                                    from queue_boundaries
                                ),
                                grouped_rows as (
                                    select
                                        uid,
                                        gamets,
                                        ts,
                                        message,
                                        speaker,
                                        listener,
                                        sum(is_new_queue) over (
                                            order by gamets asc, ts asc, uid asc
                                            rows between unbounded preceding and current row
                                        ) as queue_id
                                    from queued_rows
                                ),
                                grouped_summaries as (
                                    select
                                        queue_id,
                                        max(gamets) as gamets_truncated,
                                        count(*) as n,
                                        STRING_AGG(message, chr(13) || chr(10) || chr(13) || chr(10) order by gamets asc, ts asc, uid asc) AS packed_message,
                                        NULL as summary,
                                        'dialogue' as classifier,
                                        max(uid) as uid,
                                        'global' as scope
                                    from grouped_rows
                                    group by queue_id
                                    having count(*)>=$minEventsPerSummary
                                ),
                                queue_participants as (
                                    select queue_id, '|' || string_agg(name, '|' order by name) || '|' as companions
                                    from (
                                        select distinct
                                            queue_id,
                                            trim(regexp_replace(person, '\\s*\\([^)]*\\)\\s*$', '', 'g')) as name
                                        from grouped_rows
                                        cross join lateral unnest(array[speaker, listener]) as participant(person)
                                        where lower(trim(person)) not in ('', '-', '--', 'unknown', 'the narrator')
                                    ) participant_names
                                    where name <> ''
                                    group by queue_id
                                )
                                select * from (
                                    select
                                        summaries.gamets_truncated,
                                        summaries.n,
                                        summaries.packed_message,
                                        summaries.summary,
                                        summaries.classifier,
                                        summaries.uid,
                                        summaries.scope,
                                        participants.companions
                                    from grouped_summaries summaries
                                    left join queue_participants participants on participants.queue_id=summaries.queue_id
                                    order by summaries.gamets_truncated asc
                                ) as T
                                where gamets_truncated>$maxRow and gamets_truncated<$minRowTs";
        //error_log($query);

        $results = $db->query($query);
        
        $results = $db->query("insert into memory_summary (gamets_truncated,n,packed_message,summary,classifier,uid,companions,scope)
                                    select gamets,1,message,message,'diary',uid,
                                        case
                                            when nullif(trim(speaker), '') is null then ''
                                            else '|' || trim(both '|' from trim(speaker)) || '|'
                                        end,
                                        'global'
                                    from memory
                                    where event='diary'
                                    and gamets>$maxRow
                                ");

    }

    
    return $maxRow;
}

if (!function_exists('dialecticFilterRechatHistorySinceLatestInput')) {
    function dialecticFilterRechatHistorySinceLatestInput(array $historyRows)
    {
        $chainRows = [];

        foreach ($historyRows as $row) {
            $eventType = strtolower(trim((string)($row['type'] ?? '')));
            if ($eventType === '') {
                continue;
            }

            if (in_array($eventType, ['inputtext', 'inputtext_s', 'narrator_inputtext'], true)) {
                // A fresh player turn must reset the rechat chain. The first rechat after player input
                // should therefore start at round 0 instead of inheriting an older chain budget.
                $chainRows = [];
                continue;
            }

            if (in_array($eventType, ['rechat', 'narration'], true)) {
                $chainRows[] = $row;
            }
        }

        return $chainRows;
    }
}

function DataRechatHistory()
{

    global $db;
    // Include only actual rechat turns. Player input is not part of the rechat budget.
    // Keep the row payload so callers can scope the count to the current speaker.
    $lastRechat=$db->fetchAll("select type,data,gamets FROM  eventlog a  WHERE type in ('rechat','narration') 
    and localts>".(time()-120)."  order by gamets desc,ts desc LIMIT 10 OFFSET 0");
    
    return $lastRechat;

}



function extractDialogueTarget($string) {
    // Check if the string contains a directed-dialogue tag.
    if ($string && preg_match('/\((?:talking|whispering|shouting|speaking privately)\s+to\s+/i', $string)) {
        // Extract the target's name using regular expression
        preg_match('/\((?:talking|whispering|shouting|speaking privately)\s+to\s+([^\)]+)\)/i', $string, $matches);
        
        // Check if a match is found and extract the target's name
        if (isset($matches[1])) {
            $target = $matches[1];

            // Remove the directed-dialogue tag from the original string
            $cleanedString = preg_replace('/\((?:talking|whispering|shouting|speaking privately)\s+to\s+[^\)]+\)/i', '', $string);
            if (strpos($cleanedString,"{$GLOBALS["DIALECTIC_NAME"]}:")===0) {
                $cleanedString=str_replace("{$GLOBALS["DIALECTIC_NAME"]}:","",$cleanedString);
            }
            
            return ['target' => $target, 'cleanedString' => trim($cleanedString)];
        }
    }

    // Return the original string if no target is found
    return ['target' => null, 'cleanedString' => $string];
}

function DataGetTrackedStat($stat) {
    global $db;
    
    // Try to get from core_player table first
    try {
        require_once(__DIR__ . DIRECTORY_SEPARATOR . "core" . DIRECTORY_SEPARATOR . "player.class.php");
        $player = new Player();
        $value = $player->get($stat);
        
        if ($value !== null) {
            return json_encode([['id' => $stat, 'value' => $value]]);
        }
    } catch (Exception $e) {
        Logger::debug("Could not read stat from core_player: " . $e->getMessage());
    }
    
    return json_encode([]);
}

function ResolvePlayerBackstory($player = null): string
{
    $playerBio = '';

    if ($player === null) {
        try {
            require_once(__DIR__ . DIRECTORY_SEPARATOR . "core" . DIRECTORY_SEPARATOR . "player.class.php");
            $player = new Player();
        } catch (Throwable $e) {
            Logger::debug("Could not initialize Player while resolving backstory: " . $e->getMessage());
        }
    }

    if ($player instanceof Player) {
        try {
            $playerBio = trim((string)($player->get('bio') ?? ''));
        } catch (Throwable $e) {
            Logger::debug("Could not read player bio from core_player: " . $e->getMessage());
        }
    }

    if ($playerBio !== '') {
        return $playerBio;
    }

    return '';
}

function dialecticPartyRowsToConf(array $guys)
{
    $finalparty=[];
    foreach ($guys as $guy) {
        if (isset($guy["name"])) {
            $finalparty[$guy["name"]]=$guy;
            $npcMaster=new NpcMaster();
            $currentNpcData=$npcMaster->getByName($guy["name"]);
            if (isset($currentNpcData["core"])&&!empty($currentNpcData["core"]))
                $finalparty[$guy["name"]]["core"]=$currentNpcData["core"];

        }
    }

    return json_encode($finalparty);
}

function dialecticGetCurrentPartyFromConfOpts()
{
    global $db;

    $results = $db->fetchAll("select value from conf_opts where id='CurrentParty'");
    if (!is_array($results) || empty($results)) {
        return json_encode([]);
    }

    $partyData = trim((string)($results[0]["value"] ?? ""));
    if ($partyData === "") {
        return json_encode([]);
    }

    $partyData = rtrim($partyData, ',');
    $guys = json_decode("[" . $partyData . "]", true);
    if (!is_array($guys)) {
        Logger::warn("DataGetCurrentPartyConf: Failed to parse CurrentParty JSON");
        return json_encode([]);
    }

    return dialecticPartyRowsToConf($guys);
}

function DataGetCurrentPartyConf() {
    return dialecticGetCurrentPartyFromConfOpts();
}

function DataBeingsInRange()
{
    if (isset($GLOBALS["CACHE_BEINGS_IN_RANGE"])) {
        return $GLOBALS["CACHE_BEINGS_IN_RANGE"];
    }

    $names = dialecticNearbyActorNamesFromPayload(false, true);
    $GLOBALS["CACHE_BEINGS_IN_RANGE"] = empty($names) ? "" : "|" . implode("|", $names) . "|";
    return $GLOBALS["CACHE_BEINGS_IN_RANGE"];
}

function DataBeingsInRangeExcluding($excludeNPC="", $excludePlayer=true)
{
    if (isset($GLOBALS["CACHE_BEINGS_IN_RANGE_EXCLUDING"][$excludeNPC][(int)$excludePlayer])) {
        return $GLOBALS["CACHE_BEINGS_IN_RANGE_EXCLUDING"][$excludeNPC][(int)$excludePlayer];
    }

    if (trim($excludeNPC) > "")
        $exNPC = trim($excludeNPC);
    else
        $exNPC = "x_y_z";

    $beingsArrayNew=[];
    foreach (dialecticNearbyActorNamesFromPayload(false, !$excludePlayer) as $name) {
        if (strpos($name, $exNPC) !== 0) {
            $beingsArrayNew[] = $name;
        }
    }
    if (empty($beingsArrayNew)) {
        $GLOBALS["CACHE_BEINGS_IN_RANGE_EXCLUDING"][$excludeNPC][(int)$excludePlayer] = "";
        return "";
    }
    $beingsFormatted=implode("|",$beingsArrayNew);
    $GLOBALS["CACHE_BEINGS_IN_RANGE_EXCLUDING"][$excludeNPC][(int)$excludePlayer] = "|".$beingsFormatted."|";
    return $GLOBALS["CACHE_BEINGS_IN_RANGE_EXCLUDING"][$excludeNPC][(int)$excludePlayer];
}

function DataBeingsOrDeathsInRangeExcluding($excludeNPC="", $excludePlayer=true)
{
    if (isset($GLOBALS["CACHE_BEINGS_OR_DEATHS_IN_RANGE_EXCLUDING"][$excludeNPC][(int)$excludePlayer])) {
        return $GLOBALS["CACHE_BEINGS_OR_DEATHS_IN_RANGE_EXCLUDING"][$excludeNPC][(int)$excludePlayer];
    }

    if (trim($excludeNPC) > "")
        $exNPC = trim($excludeNPC);
    else
        $exNPC = "x_y_z";

    $beingsArrayNew=[];
    foreach (dialecticNearbyActorNamesFromPayload(false, !$excludePlayer) as $name) {
        if (strpos($name, $exNPC) !== 0) {
            $beingsArrayNew[] = $name;
        }
    }
    if (empty($beingsArrayNew)) {
        $GLOBALS["CACHE_BEINGS_OR_DEATHS_IN_RANGE_EXCLUDING"][$excludeNPC][(int)$excludePlayer] = "";
        return "";
    }
    $beingsFormatted=implode("|",$beingsArrayNew);
    $GLOBALS["CACHE_BEINGS_OR_DEATHS_IN_RANGE_EXCLUDING"][$excludeNPC][(int)$excludePlayer] = "|".$beingsFormatted."|";
    return $GLOBALS["CACHE_BEINGS_OR_DEATHS_IN_RANGE_EXCLUDING"][$excludeNPC][(int)$excludePlayer];
}

function dialecticDataActorStatusSuffixPattern()
{
    return '/\s*\((?:busy|hostile|in combat|far away|too far away|too_far|too_quiet|closed_door|different_interior_cells|interior_exterior_boundary|navmesh_no_path|path_unavailable|path_ratio_blocked|path_ratio_distance_blocked|invalid_actor|invalid_position|invalid_distance|restrained|dead|disabled|unavailable|audible|narrator|checking(?: hearing|: [^)]+)?|can hear you(?:, muffled|: [^)]+)?|can[\'"]?t hear you(?: clearly)?(?:: [^)]+)?|no (?:target|crosshair target))\)\s*$/iu';
}

function dialecticDataStripActorStateSuffix($name)
{
    $name = trim((string)$name);
    if ($name === "") {
        return "";
    }

    $name = trim($name, "|");
    $name = preg_replace(dialecticDataActorStatusSuffixPattern(), '', $name);
    return trim((string)$name);
}

function dialecticDataActorStatusBlocksCloseRange($token)
{
    if (!preg_match('/\s*\(([^()]*)\)\s*$/u', (string)$token, $matches)) {
        return false;
    }

    $status = strtolower(trim((string)$matches[1]));
    if ($status === "") {
        return false;
    }

    if (strpos($status, "can hear you") === 0) {
        return false;
    }

    return preg_match('/^(?:busy|hostile|in combat|far away|too far away|too_far|too_quiet|closed_door|different_interior_cells|interior_exterior_boundary|navmesh_no_path|path_unavailable|path_ratio_blocked|path_ratio_distance_blocked|invalid_actor|invalid_position|invalid_distance|restrained|dead|disabled|unavailable|checking|can[\'"]?t hear you|no target|no crosshair target)/i', $status) === 1;
}

function dialecticLatestNearbyActorsPayload()
{
    global $db;

    $rows = $db->fetchAll("SELECT party FROM eventlog WHERE type='nearby_actors' AND COALESCE(party, '') <> '' ORDER BY gamets DESC, ts DESC LIMIT 1 OFFSET 0");
    if (!is_array($rows) || empty($rows)) {
        return null;
    }

    $payload = json_decode($rows[0]['party'] ?? '', true);
    return is_array($payload) ? $payload : null;
}

function dialecticPeoplePipeFromNearbyActorsPayload($excludeFarAway = false)
{
    $payload = dialecticLatestNearbyActorsPayload();
    if (!is_array($payload)) {
        return "";
    }

    $actors = $payload['actors'] ?? [];
    if (!is_array($actors)) {
        return "";
    }

    $names = [];
    foreach ($actors as $actor) {
        if (!is_array($actor)) {
            continue;
        }

        $name = trim((string)($actor['name'] ?? ''));
        if ($name === "" || $name === "<no name>") {
            continue;
        }

        $eligible = filter_var($actor['eligible'] ?? true, FILTER_VALIDATE_BOOLEAN);
        if (!$eligible) {
            continue;
        }

        $status = trim((string)($actor['status'] ?? ''));
        $token = $name;
        if ($status !== "" && stripos($status, "can hear you") !== 0) {
            $token .= " ({$status})";
        }

        if ($excludeFarAway && dialecticDataActorStatusBlocksCloseRange($token)) {
            continue;
        }
        if (preg_match('/\((?:dead|disabled)\)\s*$/i', $token)) {
            continue;
        }

        $cleanName = dialecticDataStripActorStateSuffix($token);
        if ($cleanName === "") {
            continue;
        }

        if (!in_array($cleanName, $names, true)) {
            $names[] = $cleanName;
        }
    }

    $player = trim((string)($payload['player'] ?? ''));
    if ($player !== "" && !in_array($player, $names, true)) {
        $names[] = $player;
    }

    if (empty($names)) {
        return "";
    }

    return "|" . implode("|", $names) . "|";
}

function dialecticNearbyActorNamesFromPayload($excludeFarAway = false, $includePlayer = true)
{
    $pipe = dialecticPeoplePipeFromNearbyActorsPayload($excludeFarAway);
    if ($pipe === "") {
        return [];
    }

    $names = array_values(array_filter(array_map('trim', explode('|', trim($pipe, '|'))), static function ($name) {
        return $name !== '';
    }));

    if (!$includePlayer) {
        $player = trim((string)($GLOBALS["PLAYER_NAME"] ?? ""));
        if ($player !== "") {
            $names = array_values(array_filter($names, static function ($name) use ($player) {
                return strcasecmp($name, $player) !== 0;
            }));
        }
    }

    return $names;
}

function DataBeingsInCloseRange($excludeFarAway=false)
{
    if (!empty($GLOBALS["DIALECTIC_ROLEMASTER_BORED_ACTORS"]) &&
        is_array($GLOBALS["DIALECTIC_ROLEMASTER_BORED_ACTORS"])) {
        return "|" . implode("|", array_values($GLOBALS["DIALECTIC_ROLEMASTER_BORED_ACTORS"])) . "|";
    }

    $structuredPeople = dialecticPeoplePipeFromNearbyActorsPayload($excludeFarAway);
    if ($structuredPeople !== "") {
        return $structuredPeople;
    }

    return "";
}

function dialecticLatestNearbyItemsPayload()
{
    global $db;

    $rows = $db->fetchAll("SELECT party FROM eventlog WHERE type='nearby_items' AND COALESCE(party, '') <> '' ORDER BY gamets DESC, ts DESC LIMIT 1 OFFSET 0");
    if (!is_array($rows) || empty($rows)) {
        return null;
    }

    $payload = json_decode($rows[0]['party'] ?? '', true);
    return is_array($payload) ? $payload : null;
}

function dialecticNearbyPayloadFirstString(array $item, array $keys): string
{
    foreach ($keys as $key) {
        if (!array_key_exists($key, $item)) {
            continue;
        }
        $value = trim((string)$item[$key]);
        if ($value !== '') {
            return $value;
        }
    }

    return '';
}

function dialecticNormalizeNearbyPayloadItem(array $item): ?array
{
    $refId = dialecticNearbyPayloadFirstString($item, ['refid', 'ref_id', 'reference_id', 'reference', 'id']);
    $baseId = dialecticNearbyPayloadFirstString($item, ['baseid', 'base_id', 'formid', 'form_id', 'base_formid']);
    $name = dialecticNearbyPayloadFirstString($item, ['name', 'item_name', 'display_name', 'base_name']);

    if ($name === '' || strcasecmp($name, '<no name>') === 0 || strcasecmp($name, 'Missing Name') === 0) {
        return null;
    }
    if ($refId === '' && $baseId === '') {
        return null;
    }
    if ($refId === '') {
        $refId = $baseId;
    }
    if ($baseId === '' || strcasecmp($baseId, 'Unknown') === 0) {
        $baseId = $refId;
    }

    $item['refid'] = $refId;
    $item['baseid'] = $baseId;
    $item['name'] = $name;
    return $item;
}

function dialecticAppendUniqueNearbyPayloadItem(array &$items, array $item): void
{
    $needle = strtolower(($item['refid'] ?? '') . '|' . ($item['name'] ?? ''));
    foreach ($items as $existing) {
        $existingKey = strtolower(($existing['refid'] ?? '') . '|' . ($existing['name'] ?? ''));
        if ($existingKey !== '' && $existingKey === $needle) {
            return;
        }
    }

    $items[] = $item;
}

function dialecticNormalizeNearbyPayloadItemsContainer($items): array
{
    if (!is_array($items)) {
        return [];
    }

    foreach (['refid', 'ref_id', 'reference_id', 'reference', 'id', 'baseid', 'base_id', 'formid', 'form_id', 'name', 'item_name', 'display_name'] as $field) {
        if (array_key_exists($field, $items)) {
            return [$items];
        }
    }

    return array_values($items);
}

function dialecticItemsStringFromNearbyItemsPayload()
{
    $payload = dialecticLatestNearbyItemsPayload();
    if (!is_array($payload)) {
        return "";
    }

    $items = dialecticNormalizeNearbyPayloadItemsContainer($payload['items'] ?? []);
    if (empty($items) && (!isset($payload['held_item']) || !is_array($payload['held_item']))) {
        return "";
    }

    $normalizedItems = [];
    foreach ($items as $item) {
        if (!is_array($item)) {
            continue;
        }
        $normalized = dialecticNormalizeNearbyPayloadItem($item);
        if ($normalized !== null) {
            dialecticAppendUniqueNearbyPayloadItem($normalizedItems, $normalized);
        }
    }
    if (isset($payload['held_item']) && is_array($payload['held_item'])) {
        $heldItem = dialecticNormalizeNearbyPayloadItem($payload['held_item']);
        if ($heldItem !== null) {
            $heldItem['holding'] = true;
            dialecticAppendUniqueNearbyPayloadItem($normalizedItems, $heldItem);
        }
    }
    $items = $normalizedItems;
    if (empty($items)) {
        return "";
    }

    $playerName = trim((string)($payload['player'] ?? ($GLOBALS["PLAYER_NAME"] ?? "Player")));
    if ($playerName === "") {
        $playerName = "Player";
    }

    $parts = [];
    foreach ($items as $item) {
        if (!is_array($item)) {
            continue;
        }

        $refId = trim((string)($item['refid'] ?? ''));
        $baseId = trim((string)($item['baseid'] ?? ''));
        $name = trim((string)($item['name'] ?? ''));
        if ($refId === "" || $baseId === "" || $name === "" || $name === "<no name>") {
            continue;
        }

        if (filter_var($item['stealing'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
            $name .= " (STEALING)";
        }
        if (filter_var($item['looking_at'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
            $name .= " ({$playerName} is looking at this)";
        }
        if (filter_var($item['holding'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
            $name .= " ({$playerName} is holding this)";
        }

        $parts[] = "{$refId}:{$baseId}:{$name}";
    }

    return implode(',', $parts);
}

function DataItemsInCloseRange()
{
    $structuredItems = dialecticItemsStringFromNearbyItemsPayload();
    if ($structuredItems !== "") {
        return $structuredItems;
    }

    return "";
}

// Find actor name with closest name, useful to sanitize actions parameters
function FindClosestActorName($actorName)
{
    $beingsArrayCleaned = dialecticNearbyActorNamesFromPayload(true, false);

    if (empty($beingsArrayCleaned)) {
        return "";
    }

    // Find the closest match using Levenshtein distance
    $closest = null;
    $shortest = -1;

    foreach ($beingsArrayCleaned as $name) {
        $lev = levenshtein($actorName, $name);

        if ($lev == 0) {
            return $name; // Exact match
        }

        if ($lev < $shortest || $shortest < 0) {
            $closest = $name;
            $shortest = $lev;
        }
    }

    return $closest;
}

function FindClosestNPCName($actorName)
{
    $beingsArrayCleaned = dialecticNearbyActorNamesFromPayload(true, false);

    if (empty($beingsArrayCleaned)) {
        return $actorName;
    }

    // Find the closest match using Levenshtein distance
    $closest = null;
    $shortest = -1;

    foreach ($beingsArrayCleaned as $name) {
        $lev = levenshtein($actorName, $name);
        error_log("Comparing: $actorName, $name");

        if ($lev == 0) {
            return $name; // Exact match
        }

        if ($lev < $shortest || $shortest < 0) {
            $closest = $name;
            $shortest = $lev;
        }
    }

    return (!empty(trim($closest)))?$closest:$actorName;
}

function DirectConversationsWith($actor, $speaker="")
{

    global $db;
    $i_res = 0;
    
    if ($speaker=="")
        $speakerprmt=$db->escape(GetOriginalDialecticName());
    else 
        $speakerprmt=$db->escape($speaker);
    
    $listenerprmt=$db->escape($actor);
    $gametsLimit=round(($GLOBALS["gameRequest"][2]??0)-(getGametsLimitFor($actor)/0.0000024),0);
    $lastLoc=$db->fetchAll("SELECT count(*) as N FROM speech WHERE (speaker='$speakerprmt' and listener='$listenerprmt') OR (listener='$speakerprmt' and speaker='$listenerprmt') and gamets<$gametsLimit");
    
    if (!is_array($lastLoc) || sizeof($lastLoc)==0) {
        Logger::warn("DirectConversationsWith: zero interactions {$speakerprmt} - {$listenerprmt} ");
    } else {
        $i_res = intval($lastLoc[0]["n"]);
    }
    //error_log(" --- dbg DirectConversationsWith: |{$i_res}| {$speakerprmt} - {$listenerprmt} ");
    return $i_res;
    
}

function isIndividualMemoryEnabledForNpc($npcName)
{
    static $cache = [];

    $npcName = trim((string) $npcName);
    if ($npcName === '' || $npcName === '%' || strpos($npcName, '%') !== false || strpos($npcName, '_') !== false) {
        return false;
    }

    if (isset($cache[$npcName])) {
        return $cache[$npcName];
    }

    $enabled = false;
    try {
        $escaped = $GLOBALS["db"]->escape($npcName);
        $row = $GLOBALS["db"]->fetchOne("SELECT extended_data FROM core_npc_master WHERE npc_name='$escaped' LIMIT 1");
        if (is_array($row) && !empty($row["extended_data"])) {
            $extendedData = json_decode($row["extended_data"], true);
            if (
                is_array($extendedData)
                && array_key_exists('individual_memory_enabled', $extendedData)
                && $extendedData['individual_memory_enabled'] !== null
                && $extendedData['individual_memory_enabled'] !== ''
            ) {
                $enabled = !empty($extendedData['individual_memory_enabled']);
            }
        }
    } catch (Throwable $e) {
        Logger::warn("isIndividualMemoryEnabledForNpc failed for {$npcName}: " . $e->getMessage());
    }

    $cache[$npcName] = $enabled;
    return $enabled;
}

function dataGetMemoryScopeConditionSql($npcName)
{
    if (isIndividualMemoryEnabledForNpc($npcName)) {
        $npcEsc = $GLOBALS["db"]->escape($npcName);
        return "scope='$npcEsc'";
    }

    return "(scope IS NULL OR scope='global')";
}

function dataGetMemoryCompanionConditionSql(
    $npcName,
    string $column = 'companions',
    string $classifierColumn = 'classifier'
): string
{
    $npcName = trim((string)$npcName);
    if ($npcName === '') {
        $narratorOnlyDiaryAccess = filter_var(
            $GLOBALS['NARRATOR_ONLY_DIARY_ACCESS'] ?? false,
            FILTER_VALIDATE_BOOLEAN
        );
        if (!$narratorOnlyDiaryAccess) {
            // By default, the narrator searches every NPC diary in the global memory bank.
            return 'TRUE';
        }

        $narratorName = $GLOBALS['db']->escape('The Narrator');
        return "(COALESCE($classifierColumn, '') NOT IN ('diary','auto_diary','backgroundlife_diary')"
            . " OR $column LIKE '%|$narratorName|%' OR $column='$narratorName')";
    }

    $npcEsc = $GLOBALS['db']->escape($npcName);
    return "($column LIKE '%|$npcEsc|%' OR $column='$npcEsc')";
}

// Removes request-routing labels before text and vector memory matching.
function dialecticNormalizeMemorySearchInput($rawstring): string
{
    $text = (string)$rawstring;
    $playerName = trim((string)($GLOBALS['PLAYER_NAME'] ?? ''));
    if ($playerName !== '') {
        $text = str_replace("{$playerName}:", '', $text);
    }

    $text = strtr($text, [
        'Talking to The Narrator' => '',
        'Whispering to The Narrator' => '',
        'Speaking privately to The Narrator' => '',
    ]);
    $text = preg_replace('/\(Context location:[^)]+?\)/i', '', $text);
    $text = preg_replace('/\((?:(?:talking|whispering|shouting)|speaking privately)\s+to\s+[^()]+\)/i', '', $text);

    return trim((string)$text);
}

function DataSearchMemory($rawstring,$npcfilter) {
    
    //$kw=explode(" ",($rawstring));
    if (is_array($rawstring)) {
        $kwStringAny=implode(" | ",$rawstring);
        $kwStringAll=implode(" & ",$rawstring);
        
    } else if (isMinimeT5Enabled()) {
        // MiniMe keyword extraction
        Logger::info("Using minime-t5 context");
        $TEST_TEXT = dialecticNormalizeMemorySearchInput($rawstring);

        $keywords=minimeExtract($TEST_TEXT);
        $reponse=json_decode($keywords,true);
        
        //print_r($reponse);
        
        if (isset($reponse["is_memory_recall"]) && $reponse["is_memory_recall"]=="No") {
             $GLOBALS["db"]->insert(
                'audit_memory',
                array(
                    'input' => $TEST_TEXT,
                    'keywords' =>'memory recall declined',
                    'rank_any'=> -1,
                    'rank_all'=>-1,
                    'memory'=>'',
                    'time'=>$reponse["elapsed_time"]
                )
            );
            return "";
        } else  if (isset($reponse["is_memory_recall"])) {
        
            if (isset($reponse["version"]) && $reponse["version"]==2) {
                $altKeywords=explode(" ",lastNames(15,["inputtext"]));
                $altKeywords=[];
                $keywords=explode(" ",strtr($reponse["generated_tags"],["remember"=>"","Remember"=>""]));
                $kwStringAny=implode(" | ",$keywords);
                $kwStringAll=implode(" & ",$keywords);
                $result = array_unique($keywords);
            } else {
                $altKeywords=explode(" ",lastNames(15,["inputtext"]));
                $altKeywords=[];
                $keywords=explode("|",strtr($reponse["generated_tags"],["remember"=>"","Remember"=>""]));
                array_merge($keywords,$altKeywords);
                $kw=[];
            
                foreach ($keywords as $tag) {
                    if (strlen($tag)<4)
                        continue;

                    
                    $lkwPre="";
                    foreach (explode(" ",$tag) as $stag) {
                        $lkwPre.=ucfirst($stag);
                    }
                    
                    //$lkw=hashtagify($tag);    
                    $lkw="#$lkwPre";
                    
                    if ($lkw) {
                        $kw=array_merge($kw,explode(" ",$lkw));
                    }
                }
                $result = array_unique($kw);

                $kwStringAny=implode(" | ",$result);
                $kwStringAll=implode(" & ",$result);
            }
            Logger::debug("CONTEXT SEARCH KEYWORDS FROM MINIME: ".print_r($result,true));
        }
        
    } 

    if (empty($kwStringAll)) {
        Logger::info("Using dumb context");
        $TEST_TEXT = dialecticNormalizeMemorySearchInput($rawstring);

        $keywords=hashtagifySentences($TEST_TEXT);
        $kw=[];
        
        //print_r($keywords);

        foreach (explode(" ",$keywords) as $tag) {
            if (strlen($tag)<4)
                continue;
            $lkw=hashtagify(strtr($tag,["remember"=>"","Remember"=>""]));    
            if ($lkw) {
                $kw=array_merge($kw,explode(" ",$lkw));
            }
        }
        $result = array_unique($kw);

        $kwStringAny=implode(" | ",$result);
        $kwStringAll=implode(" & ",$result);
        Logger::debug("CONTEXT SEARCH KEYWORDS FROM DUMB: ".print_r($result,true));
    }
        
    
    
    
    $scopeConditionSql = dataGetMemoryScopeConditionSql($npcfilter);
    $companionConditionSql = dataGetMemoryCompanionConditionSql($npcfilter, 'A.companions', 'A.classifier');

    $memory=$GLOBALS["db"]->fetchAll("
        SELECT summary,gamets_truncated,
        ts_rank(native_vec, to_tsquery('$kwStringAny')) AS rank_any,
        ts_rank(native_vec, to_tsquery('$kwStringAll')) AS rank_all
        FROM memory_summary A
        where native_vec @@to_tsquery('$kwStringAny')
        and not (native_vec @@to_tsquery('#Reminiscence'))
        and $scopeConditionSql
        and $companionConditionSql

        ORDER BY rank_all DESC, rank_any DESC;
        ",true);
            
        if (!isset($memory[0]))
            $memory[0]=["rank_any"=>null,"rank_all"=>null,"summary"=>null];

        $GLOBALS["db"]->insert(
                'audit_memory',
                array(
                    'input' => $TEST_TEXT,
                    'keywords' =>$kwStringAny,
                    'rank_any'=> $memory[0]["rank_any"],
                    'rank_all'=>$memory[0]["rank_all"],
                    'memory'=>$memory[0]["summary"],
                    'time'=>isset($reponse["elapsed_time"])?$reponse["elapsed_time"]:"0 secs (internal)"
                )
            );
            
    
    return $memory;
    
}


function dialecticNormalizeTsQueryTerms(string $text): array
{
    if (!preg_match_all('/[\p{L}\p{N}_]+/u', $text, $matches)) {
        return [];
    }

    $terms = [];
    foreach ($matches[0] as $term) {
        if (mb_strlen($term, 'UTF-8') < 3) {
            continue;
        }
        $terms[] = $term;
    }

    return array_values(array_unique($terms));
}

function DataSearchMemoryByVector($rawstring,$npcfilter,$useContextKw=false,$timeThreshold=0) {
    
        $localStartTime=microtime(true);
        Logger::info("Using DataSearchMemoryByVector $rawstring,$npcfilter,$useContextKw=false,$timeThreshold=0");
        
        if (!$timeThreshold)
            $timeThreshold=0;
        
        $result=[];
        if (is_array($rawstring)) {
            $kwStringAny=implode(" ",$rawstring);
            $kwStringAll=implode(" ",$rawstring);
        
        } else if (isMinimeT5Enabled()) {
            // MiniMe keyword extraction
            Logger::info("Using minime-t5 context");
            error_log("[DataSearchMemoryByVector] Using minime-t5 context");
            $TEST_TEXT = dialecticNormalizeMemorySearchInput($rawstring);

            error_log("[DataSearchMemoryByVector start] minimeExtract : " . (microtime(true) - $localStartTime) . " seconds");
            $TEST_TEXT = preg_replace('/[(),;:!?."\'-]/', ' ', $TEST_TEXT);
            $TEST_TEXT = preg_replace('/\s+/', ' ', trim($TEST_TEXT));
            
            if (isset($GLOBALS["PATCH_BYPASS_MINIME_EXTRACT"]) && $GLOBALS["PATCH_BYPASS_MINIME_EXTRACT"]) {
                error_log("[DataSearchMemoryByVector ] PATCH_BYPASS_MINIME_EXTRACT");
                $keywords=json_encode(["is_memory_recall"=>"Yes"]);
            } else {
                $keywords=minimeExtract($TEST_TEXT,true);// Only to check if memory is needed
            }

            error_log("[DataSearchMemoryByVector end] minimeExtract : " . (microtime(true) - $localStartTime) . " seconds");
            $reponse=json_decode($keywords,true);
            
            error_log("[DataSearchMemoryByVector end] minimeExtract : " .print_r($reponse,true));

            
            if (isset($reponse["is_memory_recall"]) && $reponse["is_memory_recall"]=="No") {
                $GLOBALS["db"]->insert(
                    'audit_memory',
                    array(
                        'input' => $TEST_TEXT,
                        'keywords' =>'memory recall declined',
                        'rank_any'=> -1,
                        'rank_all'=>-1,
                        'memory'=>'',
                        'time'=>$reponse["elapsed_time"]
                    )
                );
                return null;

            }/* else  if (isset($reponse["is_memory_recall"])) {
            
                if (isset($reponse["version"]) && $reponse["version"]==2) {
                    $altKeywords=explode(" ",lastNames(15,["inputtext"]));
                    $altKeywords=[];
                    $keywords=explode(" ",strtr($reponse["generated_tags"],["remember"=>"","Remember"=>""]));
                    $result = array_unique($keywords);

                } else {
                    $altKeywords=explode(" ",lastNames(15,["inputtext"]));
                    $altKeywords=[];
                    $keywords=explode("|",strtr($reponse["generated_tags"],["remember"=>"","Remember"=>""]));
                    array_merge($keywords,$altKeywords);
                    $kw=[];
                
                    foreach ($keywords as $tag) {
                        if (strlen($tag)<4)
                            continue;

                        
                        $lkwPre="";
                        foreach (explode(" ",$tag) as $stag) {
                            $lkwPre.=ucfirst($stag);
                        }
                        
                        //$lkw=hashtagify($tag);    
                        $lkw="$lkwPre";
                        
                        if ($lkw) {
                            $kw=array_merge($kw,explode(" ",$lkw));
                        }
                    }
                    $result = array_unique($kw);

                   
                }
                Logger::debug("CONTEXT SEARCH KEYWORDS FROM MINIME: ".print_r($result,true));
            }*/
            
        } else {
            error_log("[DataSearchMemoryByVector] Minime disabled; using dumb context fallback.");
            $TEST_TEXT = dialecticNormalizeMemorySearchInput($rawstring);
        }

        if (sizeof($result)<1) {
            Logger::info("Using dumb context");
            $TEST_TEXT = dialecticNormalizeMemorySearchInput($rawstring);

            $keywords=strtr($TEST_TEXT,["."=>" ",","=>" ","'"=>" "]);
            $kw=[];
            
            //print_r($keywords);

            foreach (explode(" ",$keywords) as $tag) {
                if (strlen($tag)<4)
                    continue;
                $lkw=$tag;
                if ($lkw) {
                    $kw=array_merge($kw,explode(" ",$lkw));
                }
            }
            $result = array_unique($kw);

            $resultEn=[];
            foreach ($result as $r) {
                $resultEn[]=$r;
            }

            if (sizeof($resultEn)<1) {
                $resultEn=$result;
            }

            $kwStringAny=implode(" | ",$resultEn);
            $kwStringAll=implode(" & ",$resultEn);

            Logger::debug("CONTEXT SEARCH KEYWORDS FROM DUMB: ".print_r($resultEn,true));
            error_log("CONTEXT SEARCH KEYWORDS FROM DUMB: <".implode("><",$resultEn).">");
        }

       
        if (!empty($npcfilter) && $useContextKw) {
            $result=array_merge($result,lastKeyWordsContext(5,$npcfilter));
        }

        $scopeConditionSql = dataGetMemoryScopeConditionSql($npcfilter);
        $companionConditionSql = dataGetMemoryCompanionConditionSql($npcfilter);

        $contextKeywords  = implode(" ", $result);
        $contextKeywords=strtr($contextKeywords,["remember"=>"","Remember"=>"","do you remember"=>""]);


        $url = $GLOBALS["FEATURES"]["MEMORY_EMBEDDING"]["TXTAI_URL"].'/embed';

        $data = [
            
            'text' => $contextKeywords   
        ];

        // Convert to JSON
        $options = [
            'http' => [
                'method'  => 'POST',
                'header'  => "Content-Type: application/json\r\n" .
                            "Accept: application/json\r\n",
                'content' => json_encode($data),
                'ignore_errors' => true // to capture error messages if any
            ]
        ];

        // Create context and send the request
        $context  = stream_context_create($options);
        
        error_log("[DataSearchMemoryByVector Embedding start] Elapsed time: " . (microtime(true) - $localStartTime) . " seconds");
        $response = file_get_contents($url, false, $context);
        error_log("[DataSearchMemoryByVector Embedding end] Elapsed time: " . (microtime(true) - $localStartTime) . " seconds");

        // Output the response
        if ($response === false) {
            Logger::error("Request failed.\n");
        } else {
            Logger::info("Request done:\n");

        }

        $resultNormalized = dialecticNormalizeTsQueryTerms($contextKeywords);
        $kwStringAny=implode(" | ",$resultNormalized);
        $kwStringAll=implode(" & ",$resultNormalized);
        error_log("[DataSearchMemoryByVector] Generated Tags: $kwStringAny" );
        $vector=json_decode($response,true);

        if (is_array($vector) && isset($vector["embedding"])) {
            $vectorString="'[".implode(",",$vector["embedding"])."]'";
            $rankAnySql = $kwStringAny !== ''
                ? "ts_rank(native_vec, to_tsquery('" . $GLOBALS["db"]->escape($kwStringAny) . "'))"
                : "0::real";
            $rankAllSql = $kwStringAll !== ''
                ? "ts_rank(native_vec, to_tsquery('" . $GLOBALS["db"]->escape($kwStringAll) . "'))"
                : "0::real";
            $rankCombinedSql = "($rankAnySql + $rankAllSql)";

            $finalQuery="
                SELECT rowid,gamets_truncated,
                        embedding <-> $vectorString as distance,
                         $rankAnySql AS rank_any_fts_raw,
                         $rankAllSql AS rank_all_fts_raw,
                         $rankCombinedSql AS rank_fts,
                         (embedding <-> $vectorString) - $rankCombinedSql AS mixed_distance,
                         summary
                    FROM public.memory_summary 
                    WHERE embedding IS NOT NULL
                    and $scopeConditionSql
                    and $companionConditionSql
                    and (gamets_truncated<$timeThreshold or $timeThreshold=0)
                    
                    ORDER BY
                        mixed_distance ASC,
                        distance ASC,
                        gamets_truncated DESC,
                        rowid DESC
                    LIMIT 50 OFFSET 0
                ";    
            $memory=$GLOBALS["db"]->fetchAll($finalQuery);
            //error_log($finalQuery);
            $singleMemory = dialecticSelectBestHybridMemoryCandidate($memory);
         
            if (!isset($singleMemory)) {
                $singleMemory = [
                    "rank_any" => null,
                    "rank_all" => null,
                    "summary" => null,
                    "distance" => 1.4,
                    "mixed_distance" => 1.4,
                ];
            }
            
            /*error_log("
                 SELECT summary, gamets_truncated,
                        embedding <-> $vectorString as distance,
                         ts_rank(native_vec, to_tsquery('$kwStringAny')) AS rank_any_fts,
                         ts_rank(native_vec, to_tsquery('$kwStringAll')) AS rank_all_fts
                    FROM public.memory_summary 
                    WHERE embedding IS NOT NULL
                    and companions like '%{$GLOBALS["db"]->escape($npcfilter)}%'
                    ORDER BY (embedding <-> $vectorString)-ts_rank(native_vec, to_tsquery('$kwStringAny')) 
                    LIMIT 5 OFFSET 0
                ");*/

            $GLOBALS["db"]->insert(
                    'audit_memory',
                    array(
                        'input' => $TEST_TEXT,
                        'keywords' =>'text2vec search / (input plus "'.$contextKeywords.'"',
                        'rank_any'=> (1.40 - floatval($singleMemory["mixed_distance"] ?? $singleMemory["distance"] ?? 0)),// Try to mimic FTS query rank
                        'rank_all'=> (1.40 - floatval($singleMemory["distance"] ?? 0)),// Try to mimic FTS query rank
                        'memory'=>$singleMemory["summary"],
                        'time'=>isset($vector["timing"])?$vector["timing"]["generation_time_seconds"]:"0 secs (text2vec)"
                    )
                );
            
        } else {
            return null;
        }
            
    
    return [$singleMemory];
    
}

function DataSearchWorldKnowledgeByVector($rawstring,$currentWorldKnowledgeTopic,$locationCtx,$contextKeywords) {
//function DataSearchWorldKnowledgeByVector($rawstring) {
    
    
    Logger::info("Using DataSearchWorldKnowledgeByVector");
    $rawstring=strtr($rawstring,["{$GLOBALS["PLAYER_NAME"]}:"=>""]);
    $rawstring=strtr($rawstring,["Talking to The Narrator"=>""]);

    $pattern = "/\(Context location:[^)]+?\)/"; // Remove only the exact context location pattern
    $replacement = "";
    $TEST_TEXT = preg_replace($pattern, $replacement, $rawstring); 
                
    $pattern = '/\(talking to [^()]+\)/i';
    $TEST_TEXT = preg_replace($pattern, '', $TEST_TEXT);

   
    Logger::info("DataSearchWorldKnowledgeByVector <$TEST_TEXT> Expanded keywords: <$currentWorldKnowledgeTopic> <$locationCtx> <$contextKeywords>");
    /***/

    $embeddingFunction=function($text) {
        if (empty($text))
            return ["embedding"=>array_fill(0, 384, 0)];

        $url = $GLOBALS["FEATURES"]["MEMORY_EMBEDDING"]["TXTAI_URL"].'/embed';
        $data = [
            'text' => $text   // We add previous keywords
        ];

        // Convert to JSON
        $options = [
            'http' => [
                'method'  => 'POST',
                'header'  => "Content-Type: application/json\r\n" .
                            "Accept: application/json\r\n",
                'content' => json_encode($data),
                'ignore_errors' => true // to capture error messages if any
            ]
        ];

        // Create context and send the request
        $context  = stream_context_create($options);
        $response = file_get_contents($url, false, $context);

        // Output the response
        if ($response === false) {
            Logger::error("Request failed.\n");
        } else {
            Logger::info("Request done: Searched: {$data["text"]}\n");

        }

        $vector=json_decode($response,true);
        return sizeof($vector)>0?$vector:["embedding"=>array_fill(0, 384, 0)];

    };

    $vector1=$embeddingFunction($TEST_TEXT);
    $vector2=$embeddingFunction($locationCtx);
    $vector3=$embeddingFunction($contextKeywords);
    $vector4=$embeddingFunction($currentWorldKnowledgeTopic);
    
    

    if (is_array($vector1) && isset($vector1["embedding"])) {
        $vectorString1="'[".implode(",",$vector1["embedding"])."]'";
        $vectorString2="'[".implode(",",$vector2["embedding"])."]'";
        $vectorString3="'[".implode(",",$vector3["embedding"])."]'";
        $vectorString4="'[".implode(",",$vector4["embedding"])."]'";

        $memory=$GLOBALS["db"]->fetchAll("
            SELECT  topic_desc,
                                topic,
                                knowledge_class,
                                knowledge_class_basic,
                                topic_desc_basic, 
                    vector384 <-> $vectorString1 as distance1,
                    vector384 <-> $vectorString2 as distance2,
                    vector384 <-> $vectorString3 as distance3,
                    vector384 <-> $vectorString4 as distance4,
                    ((vector384 <-> $vectorString1) + (vector384 <-> $vectorString2)/4 + (vector384 <-> $vectorString3)/2 + (vector384 <-> $vectorString4)/2 )/2 as distance
                FROM public.worldknowledge 
                WHERE vector384 IS NOT NULL
                ORDER BY ((vector384 <-> $vectorString1) + (vector384 <-> $vectorString2)/4 + (vector384 <-> $vectorString3)/2 + (vector384 <-> $vectorString4)/2 )/4 ASC
                LIMIT 5 OFFSET 0
            ");
        
        

        if (!isset($memory[0]))
            $memory[0]=["combined_rank"=>null];
        else {
             $memory[0]['combined_rank']=(7.95-$memory[0]["distance"]);
             $memory[0]['combined_rank']=(7.95-$memory[0]["distance"]);
        }
        
        $GLOBALS["db"]->insert(
                'audit_memory',
                array(
                    'input' => $TEST_TEXT,
                    'keywords' =>'text2vec worldknowledge search /'.$contextKeywords,
                    'rank_any'=> (1.40-$memory[0]["distance"]),// Try to mimic FTS query rank
                    'rank_all'=> (1.40-$memory[0]["distance"]),// Try to mimic FTS query rank
                    'memory'=>$memory[0]["topic"],
                    'time'=>isset($vector1["timing"])?$vector1["timing"]["generation_time_seconds"]:"0 secs (text2vec)"
                )
            );
        
    } else {
        return null;
    }
        

    return $memory;

}

function FastCallOAI($question) {
    
    $call["messages"]=[
        [
            "role"=>"user",
            "content"=>"$question"
        ]
    ];


    $call["stream"]=false;
    $call["stop"]=["\n"];

    $headers = ['Content-Type: application/json'];

    $options = array(
        'http' => array(
            'method' => 'POST',
                'header' => implode("\r\n", $headers),
                'content' => json_encode($call),
            )
    );

    $netContext = stream_context_create($options);
    $response=file_get_contents('http://localhost:5001/v1/chat/completions', false,$netContext);
    $rawResponse=json_decode($response,true);
    
    if (isset($rawResponse["choices"][0]["message"]["content"]))
        return $rawResponse["choices"][0]["message"]["content"];
    else
        return null;
    
}

function snapshot_response_prompt_debug_data($connectorData = null) {
    if (!isset($GLOBALS["DEBUG_DATA"]) || !is_array($GLOBALS["DEBUG_DATA"])) {
        $GLOBALS["DEBUG_DATA"] = [];
    }

    if (isset($GLOBALS["DEBUG_DATA"]["full"]) && is_array($GLOBALS["DEBUG_DATA"]["full"])) {
        $GLOBALS["DEBUG_DATA"]["response_full"] = $GLOBALS["DEBUG_DATA"]["full"];
    } else {
        unset($GLOBALS["DEBUG_DATA"]["response_full"]);
    }

    if ($connectorData === null
        && isset($GLOBALS["DIALECTIC_CORE_CURRENT_CONNECTOR_DATA"])
        && is_array($GLOBALS["DIALECTIC_CORE_CURRENT_CONNECTOR_DATA"])) {
        $connectorData = $GLOBALS["DIALECTIC_CORE_CURRENT_CONNECTOR_DATA"];
    }

    if (!is_array($connectorData)) {
        unset($GLOBALS["DEBUG_DATA"]["response_connector"]);
        return;
    }

    $responseConnector = array_filter([
        'id' => $connectorData['id'] ?? null,
        'label' => $connectorData['label'] ?? null,
        'driver' => $connectorData['driver'] ?? null,
        'model' => $connectorData['model'] ?? null,
    ], function ($value) {
        return $value !== null && $value !== '';
    });

    if (!empty($responseConnector)) {
        $GLOBALS["DEBUG_DATA"]["response_connector"] = $responseConnector;
    } else {
        unset($GLOBALS["DEBUG_DATA"]["response_connector"]);
    }
}

function dialectic_try_llm_fallback($reason = "unknown") {
    if (empty($GLOBALS["DIALECTIC_CORE_CURRENT_PROFILE_DATA"]) || !is_array($GLOBALS["DIALECTIC_CORE_CURRENT_PROFILE_DATA"])) {
        Logger::info("[FALLBACK] No current profile data available; cannot retry. reason={$reason}");
        return null;
    }

    $profileData = $GLOBALS["DIALECTIC_CORE_CURRENT_PROFILE_DATA"];
    $legacyFallbackConnectorId = class_exists('LLMRandomizer')
        ? LLMRandomizer::getConnectorIdForField($profileData, "llm_fallback_id")
        : ($profileData["llm_fallback_id"] ?? null);

    $metadata = [];
    if (!empty($profileData["metadata"])) {
        $metadata = is_string($profileData["metadata"])
            ? json_decode($profileData["metadata"], true)
            : $profileData["metadata"];
        if (!is_array($metadata)) {
            $metadata = [];
        }
    }

    $fallbackEnabled = array_key_exists("LLM_FALLBACK_ENABLED", $metadata)
        ? filter_var($metadata["LLM_FALLBACK_ENABLED"], FILTER_VALIDATE_BOOLEAN)
        : true;
    if (!$fallbackEnabled) {
        Logger::info("[FALLBACK] Fallback not available" . Logger::formatContext([
            "reason" => $reason,
            "enabled" => "no",
        ]));
        return null;
    }

    $fallbackConnectorIds = [];
    foreach ([$legacyFallbackConnectorId, $metadata["LLM_FALLBACK_2_ID"] ?? null, $metadata["LLM_FALLBACK_3_ID"] ?? null] as $connectorId) {
        $connectorId = filter_var($connectorId, FILTER_VALIDATE_INT, ["options" => ["min_range" => 1]]);
        if ($connectorId && !in_array($connectorId, $fallbackConnectorIds, true)) {
            $fallbackConnectorIds[] = $connectorId;
        }
    }

    $attempted = $GLOBALS["DIALECTIC_LLM_ATTEMPTED_CONNECTOR_IDS"] ?? [];
    if (!is_array($attempted)) {
        $attempted = [];
    }
    $currentConnectorId = intval($GLOBALS["DIALECTIC_CORE_CURRENT_CONNECTOR_DATA"]["id"] ?? 0);
    if ($currentConnectorId > 0 && !in_array($currentConnectorId, $attempted, true)) {
        $attempted[] = $currentConnectorId;
    }

    $connector = new LLMConnector();
    $fallbackConnectorData = null;
    $fallbackConnectorId = null;
    foreach ($fallbackConnectorIds as $candidateId) {
        if (in_array($candidateId, $attempted, true)) {
            continue;
        }
        $attempted[] = $candidateId;
        $candidate = $connector->getById($candidateId);
        if ($candidate) {
            $fallbackConnectorId = $candidateId;
            $fallbackConnectorData = $candidate;
            break;
        }
        Logger::warn("[FALLBACK] Connector ID {$candidateId} not found; advancing chain. reason={$reason}");
    }
    $GLOBALS["DIALECTIC_LLM_ATTEMPTED_CONNECTOR_IDS"] = $attempted;

    if (!$fallbackConnectorData) {
        Logger::info("[FALLBACK] Connector chain exhausted" . Logger::formatContext([
            "reason" => $reason,
            "attempted" => implode(",", $attempted),
        ]));
        return null;
    }

    $chainPosition = array_search($fallbackConnectorId, $fallbackConnectorIds, true);
    Logger::warn("[FALLBACK] Retrying LLM request with connector chain" . Logger::formatContext([
        "reason" => $reason,
        "fallback_id" => $fallbackConnectorId,
        "chain_position" => $chainPosition === false ? "" : $chainPosition + 1,
        "chain_length" => count($fallbackConnectorIds),
        "driver" => $fallbackConnectorData["driver"] ?? "",
        "model" => $fallbackConnectorData["model"] ?? "",
    ]));

    $fallbackDepth = intval($GLOBALS["DIALECTIC_LLM_FALLBACK_DEPTH"] ?? 0) + 1;
    $GLOBALS["DIALECTIC_LLM_FALLBACK_DEPTH"] = $fallbackDepth;
    $GLOBALS["IN_FALLBACK_MODE"] = true;
    $GLOBALS["DIALECTIC_CORE_CURRENT_CONNECTOR_DATA"] = $fallbackConnectorData;
    $connector->setOldGlobals($fallbackConnectorData);

    $result = call_llm_internal();
    $fallbackDepth = intval($GLOBALS["DIALECTIC_LLM_FALLBACK_DEPTH"] ?? 1) - 1;
    if ($fallbackDepth <= 0) {
        unset($GLOBALS["DIALECTIC_LLM_FALLBACK_DEPTH"], $GLOBALS["IN_FALLBACK_MODE"]);
    } else {
        $GLOBALS["DIALECTIC_LLM_FALLBACK_DEPTH"] = $fallbackDepth;
    }
    return $result;
}

function call_llm() {
    global $contextData, $gameRequest, $receivedData, $startTime, $db;
    global $ERROR_TRIGGERED, $talkedSoFar, $alreadysent, $FUNCTIONS_ARE_ENABLED;
    global $overrideParameters, $request;
    
    unset($GLOBALS["DIALECTIC_LLM_ATTEMPTED_CONNECTOR_IDS"], $GLOBALS["DIALECTIC_LLM_FALLBACK_DEPTH"], $GLOBALS["IN_FALLBACK_MODE"]);
    // Call the internal function (which now handles the full fallback chain itself)
    return call_llm_internal();
}

function dialecticRecoverPlainLlmSpeech(): bool
{
    global $gameRequest, $talkedSoFar, $alreadysent;

    if (($gameRequest[0] ?? '') === 'diary' || count($talkedSoFar ?? []) > 0 || count($alreadysent ?? []) > 0) {
        return false;
    }

    $raw = trim(strval($GLOBALS['DIALECTIC_LLM_RAW_TEXT'] ?? ''));
    if ($raw === '' || strlen($raw) < 3 || preg_match('/[[:alpha:]]/u', $raw) !== 1) {
        return false;
    }
    if (preg_match('/^(?:\{|\[|data\s*:|<!doctype|<html|error\b)/i', $raw) === 1) {
        return false;
    }

    $raw = preg_replace('/^```(?:json|text|markdown)?\s*|\s*```$/i', '', $raw);
    $raw = preg_replace('/<(think|thinking|reasoning)>.*?<\/\1>/is', '', $raw);
    $clean = trim(cleanResponse($raw));
    if ($clean === '' || preg_match('/[[:alpha:]]/u', $clean) !== 1) {
        return false;
    }

    $sentences = array_values(array_filter(split_sentences_stream($clean), static function ($line) {
        return trim(strval($line)) !== '';
    }));
    if (empty($sentences)) {
        return false;
    }

    Logger::warn('[LLM] Recovered valid plain-text model output as speech' . Logger::formatContext([
        'speaker' => $GLOBALS['DIALECTIC_NAME'] ?? '',
        'chars' => strlen($clean),
        'preview' => Logger::summarizePayload($clean, 160),
    ]));
    returnLines($sentences);
    return true;
}

function call_llm_internal() {
    global $contextData, $gameRequest, $receivedData, $startTime, $db;
    global $ERROR_TRIGGERED, $talkedSoFar, $alreadysent, $FUNCTIONS_ARE_ENABLED;
    global $overrideParameters, $request;
    
    $outputWasValid = true;
    $firstLlmChunkLogged = false;
    unset($GLOBALS['DIALECTIC_LLM_RAW_TEXT']);
    

    if (isset($GLOBALS["DIALECTIC_CORE_CURRENT_CONNECTOR_DATA"])) {
        $connector=new LLMConnector();
        $connectionHandler = $connector->getConnector($GLOBALS["DIALECTIC_CORE_CURRENT_CONNECTOR_DATA"]);
        error_log("[CORE SYSTEM] Using new profile system {$GLOBALS["DIALECTIC_CORE_CURRENT_CONNECTOR_DATA"]["driver"]}/{$GLOBALS["DIALECTIC_CORE_CURRENT_CONNECTOR_DATA"]["model"]}");
    } else {
        error_log("No connector defined");
        Logger::error("No connector defined");
        terminate();
    }

    if (isset($GLOBALS["CLEAN_CONTEXT_FOCUS_CHAT"]) && $GLOBALS["CLEAN_CONTEXT_FOCUS_CHAT"]) {

         /* *****
        Player TTS

        Player TTS. We overwrite some confs an then restore them.
        */
        // Only process player TTS on the first attempt, not during fallback retry
        if (!isset($GLOBALS["IN_FALLBACK_MODE"])
            && in_array($gameRequest[0],["inputtext","inputtext_s","narrator_inputtext"])
            && (!function_exists('dialecticShouldSkipPlayerTtsForRequest') || !dialecticShouldSkipPlayerTtsForRequest($gameRequest[0], $gameRequest[3] ?? ""))) {
            require(__DIR__."/../processor/player_tts.php");
        } elseif (function_exists('dialecticShouldSkipPlayerTtsForRequest') && dialecticShouldSkipPlayerTtsForRequest($gameRequest[0], $gameRequest[3] ?? "")) {
            Logger::info("[Player TTS] Skipping LLM-path player TTS for input request; sidecar playback expected.");
        }
        $currentConnectorData=$GLOBALS["DIALECTIC_CORE_CURRENT_CONNECTOR_DATA"];
        error_log("[CLEAN_CONTEXT_FOCUS_CHAT] Using 2-step schema, model: {$currentConnectorData["driver"]}/{$currentConnectorData["model"]}");


        if ($gameRequest[0] === "narrator_inputtext") {
            require_once($GLOBALS["ENGINE_PATH"]."/functions".DIRECTORY_SEPARATOR."json_response.php");
            if (function_exists('dialecticEnsureNarratorJsonResponseState')) {
                dialecticEnsureNarratorJsonResponseState('DATA_FUNCTIONS_FAST_STANDARD');
            }
            if (
                !empty($GLOBALS["PROMPT_ACTIONS_LIST"])
                && isset($contextData[0]["content"])
                && strpos($contextData[0]["content"], '<available_actions_list>') === false
            ) {
                $contextData[0]["content"] .= "\n" . $GLOBALS["PROMPT_ACTIONS_LIST"];
            }
        }

        $buffer=$connectionHandler->fast_request($contextData,$overrideParameters,'standard');
        snapshot_response_prompt_debug_data($currentConnectorData);
        $preserveAsterisksInContext = isset($GLOBALS["PRESERVE_ASTERISKS_IN_CONTEXT"]) ? (bool)$GLOBALS["PRESERVE_ASTERISKS_IN_CONTEXT"] : false;
        $inlineNarrationMode = strtolower(trim((string)($GLOBALS["INLINE_NARRATION_MODE"] ?? '')));
        if (!in_array($inlineNarrationMode, ['disabled', 'narrator', 'npc', 'text_only'], true)) {
            $inlineNarrationMode = 'disabled';
        }
        if ($inlineNarrationMode === 'disabled' && !$preserveAsterisksInContext) {
            $buffer = preg_replace('/\*([^*]*\s+[^*]*)\*/', '', $buffer);
        }

        error_log("[STEP 1] Elapsed time: " . (microtime(true) - $startTime) . " seconds");

        if ($GLOBALS["FUNCTIONS_ARE_ENABLED"]) {
            require_once($GLOBALS["ENGINE_PATH"]."/functions".DIRECTORY_SEPARATOR."json_response.php");
            $GLOBALS["COMMAND_PROMPT"]="";

            setActions();
        } else {
            $GLOBALS["FUNC_LIST"][]="Talk";
            $GLOBALS["COMMAND_PROMPT"]="";

        }

        $jsonformat= json_encode(["character"=>$GLOBALS["DIALECTIC_NAME"],
        "listener"=>"specify who {$GLOBALS["DIALECTIC_NAME"]} is talking to, comma separated, max two listeners, in addressing order",
        "message"=>"lines of dialogue",
        "mood"=>"One of :".implode("|",normalizeEmoteMoods($GLOBALS["EMOTEMOODS"] ?? "")),
        "action"=>"One of :".implode("|",$GLOBALS["FUNC_LIST"]),
        "target"=>"action target actor or action destination location name",
        "item"=>"item identifier and name. For GiveItemTo, Consume, EquipItem, or UnequipItem, use exact BaseID:ItemName from inventory. For PickupItem, use exact RefID:ItemName from nearby_items",
        "lang"=>"language used, (es|en|fr|...)"]);


        $minimalContextData = array_slice($contextData, -5);
        $minimalContext=[];
        foreach ($minimalContextData as $ele) {
            if (strpos($ele["content"],"#MEMORY")===false) {
                $minimalContext[]="{$ele["content"]}";
            }
        }
        array_pop($minimalContext);

        $minimalContext[]="$buffer";// Add whole generated content.

        $buffer=preg_replace('/\([^)]*\)/', '', $buffer);//Remove text between space.
        $contextData2=[
            array('role' => 'system', 'content' => "Create a JSON object with this format: $jsonformat , using a 'Generated dialogue line' as source. "),
            array('role' => 'user', 'content' => "* Available actions:\n".$GLOBALS["COMMAND_PROMPT"]),
            array('role' => 'user', 'content' => "* Historic context information:\n".implode("\n",$minimalContext)),
            array('role' => 'user', 'content' => "* Generated dialogue line: <$buffer>"),
            array('role' => 'user', 'content' => "Convert the '* Generated dialogue line' , and ONLY the  '* Generated dialogue line' section, to a JSON object with this format: $jsonformat\n.You must infer some properties like action (check Available actions list ) and mood from context"),
        ];

        $connector=new LLMConnector();
        $formatterConnectorId = class_exists('LLMRandomizer')
            ? LLMRandomizer::getConnectorIdForField($GLOBALS["DIALECTIC_CORE_CURRENT_PROFILE_DATA"], "llm_formatter_id")
            : ($GLOBALS["DIALECTIC_CORE_CURRENT_PROFILE_DATA"]["llm_formatter_id"] ?? null);
        $currentConnectorData=$connector->getById($formatterConnectorId);



        $connector->setOldGlobals($currentConnectorData);

        $connectionHandler = $connector->getConnector($currentConnectorData);

        $buffer2=$connectionHandler->fast_request($contextData2,[],'formatter');
        unset($GLOBALS["_JSON_BUFFER"]);
        $finalRes=__jpd_decode_lazy($buffer2);
        file_put_contents(__DIR__."/../log/output_from_llm_fast_step_2.log", $buffer2, FILE_APPEND);
        file_put_contents(__DIR__."/../log/output_from_llm_fast_step_2.log", print_r($finalRes,true), FILE_APPEND);
        unset($GLOBALS["_JSON_BUFFER"]);
        $fakeObject["choices"][0]=[
            "index"=>0,
            "delta"=>["role"=>"assistant","content"=>json_encode($finalRes)]
        ];

        $connectionHandler->primary_handler=fopen("php://memory", "r+");// Total hack, we're emulating streaming mode.
        $fakedStream='data: '.json_encode($fakeObject);
        fwrite($connectionHandler->primary_handler,$fakedStream);
        rewind($connectionHandler->primary_handler);

        error_log("[CLEAN_CONTEXT_FOCUS_CHAT] Using 2-step schema, model: {$currentConnectorData["driver"]}/{$currentConnectorData["model"]}" );

    } else {

        
            /* *****
        Player TTS

        Player TTS. We overwrite some confs an then restore them.
        */
        // Only process player TTS on the first attempt, not during fallback retry
        if (!isset($GLOBALS["IN_FALLBACK_MODE"])
            && in_array($gameRequest[0],["inputtext","inputtext_s","narrator_inputtext"])
            && (!function_exists('dialecticShouldSkipPlayerTtsForRequest') || !dialecticShouldSkipPlayerTtsForRequest($gameRequest[0], $gameRequest[3] ?? ""))) {
            require(__DIR__."/../processor/player_tts.php");
        } elseif (function_exists('dialecticShouldSkipPlayerTtsForRequest') && dialecticShouldSkipPlayerTtsForRequest($gameRequest[0], $gameRequest[3] ?? "")) {
            Logger::info("[Player TTS] Skipping LLM-path player TTS for input request; sidecar playback expected.");
        }

        Logger::phaseStart("llm_provider_open", [
            "connector" => $GLOBALS["DIALECTIC_CORE_CURRENT_CONNECTOR_DATA"]["driver"] ?? "",
            "model" => $GLOBALS["DIALECTIC_CORE_CURRENT_CONNECTOR_DATA"]["model"] ?? "",
        ]);
        $connectionHandler->open($contextData,$overrideParameters);
        Logger::phaseEnd("llm_provider_open", [
            "status" => $connectionHandler->primary_handler === false ? "failed" : "ok",
        ], $connectionHandler->primary_handler === false ? "warn" : "debug");
        snapshot_response_prompt_debug_data();
    }
    error_log("[FALLBACK DEBUG] Checking primary_handler status: " . ($connectionHandler->primary_handler === false ? "FALSE" : "OK"));
    
    if ($connectionHandler->primary_handler === false) {
        error_log("[FALLBACK DEBUG] primary_handler is false, checking fallback conditions");
        
        $fallbackResult = dialectic_try_llm_fallback("connection_error");
        if ($fallbackResult !== null) {
            return $fallbackResult;
        }
        
        // No fallback or fallback also failed - send error message
        error_log("[FALLBACK DEBUG] Sending ERROR_OPENAI message to user");
        $db->insert(
            'log',
            array(
                'localts' => time(),
                'prompt' => nl2br((json_encode($GLOBALS["DEBUG_DATA"], JSON_PRETTY_PRINT))),
                'response' => ((print_r(error_get_last(), true))),
                'url' => nl2br(("$receivedData in " . (microtime(true) - $startTime) . " secs "))
            )
        );
        returnLines([$GLOBALS["ERROR_OPENAI"]]);
        
        $ERROR_TRIGGERED=true;
        @ob_end_flush();

        Logger::error(print_r(error_get_last(), true));
        return false;
    }

    // Check for error response code
    $statusCode = method_exists($connectionHandler, 'getHttpStatusCode') ? $connectionHandler->getHttpStatusCode() : 200;
    if ($statusCode >= 300) {
        $fallbackResult = dialectic_try_llm_fallback("http_{$statusCode}");
        if ($fallbackResult !== null) {
            return $fallbackResult;
        }
        
        Logger::error("LLM provider error response code: $statusCode");
        return false;
    }

    // Read and process the response line by line
    $buffer="";
    $totalBuffer="";
    $breakFlag=false;
    $lineCounter=0;
    $fullContent="";
    $totalProcessedData="";
    $numOutputTokens = 0;
    $INCREMENTAL_SENTENCESIZE=20;
    $actionsAllowedForTurn = (
        !empty($GLOBALS["FUNCTIONS_ARE_ENABLED"])
        || (
            isset($GLOBALS["PROMPT_ACTIONS_LIST"])
            && is_string($GLOBALS["PROMPT_ACTIONS_LIST"])
            && trim($GLOBALS["PROMPT_ACTIONS_LIST"]) !== ""
        )
    );

    while (true) {
        if ($breakFlag) {
            break;
        }

        $tmpData=$connectionHandler->process();
        if (!$firstLlmChunkLogged && is_string($tmpData) && $tmpData !== "") {
            $firstLlmChunkLogged = true;
            Logger::info("[LLM] First stream chunk received" . Logger::formatContext([
                "elapsed_ms" => round((microtime(true) - $startTime) * 1000, 2),
                "bytes" => strlen($tmpData),
                "preview" => Logger::summarizePayload($tmpData, 120),
            ]));
        }
        if ($tmpData==-1 || (isset($GLOBALS["VALIDATE_LLM_OUTPUT_FNCT"]) && !$GLOBALS["VALIDATE_LLM_OUTPUT_FNCT"]($tmpData))) {
            if ($tmpData == -1 && count($talkedSoFar) == 0 && count($alreadysent) == 0) {
                if (dialecticRecoverPlainLlmSpeech()) {
                    if (method_exists($connectionHandler, "close")) {
                        $connectionHandler->close("plain-text-recovery");
                    }
                    $buffer = "";
                    $breakFlag = true;
                    continue;
                }
                $streamErrorReason = "stream_error";
                if (method_exists($connectionHandler, "getLastStreamErrorCode")) {
                    $streamErrorReason .= " code=" . strval($connectionHandler->getLastStreamErrorCode() ?? "");
                }
                if (method_exists($connectionHandler, "getLastStreamErrorType")) {
                    $streamErrorType = trim((string)$connectionHandler->getLastStreamErrorType());
                    if ($streamErrorType !== "") {
                        $streamErrorReason .= " type=" . $streamErrorType;
                    }
                }
                if (method_exists($connectionHandler, "getLastStreamErrorMessage")) {
                    $streamErrorMessage = trim((string)$connectionHandler->getLastStreamErrorMessage());
                    if ($streamErrorMessage !== "") {
                        $streamErrorReason .= " message=" . $streamErrorMessage;
                    }
                }

                if (method_exists($connectionHandler, "close")) {
                    $connectionHandler->close("failed-stream-before-fallback");
                }

                $fallbackResult = dialectic_try_llm_fallback($streamErrorReason);
                if ($fallbackResult !== null) {
                    return $fallbackResult;
                }
            }

            Logger::warn("Invalid JSON Output.");
            $outputWasValid=false;
            $buffer="";
            $breakFlag=true;
        }
        else {
            $buffer.= $tmpData;
            $totalBuffer.=$buffer; 
        }

        if ($connectionHandler->isDone()) {
            $breakFlag=true;
        }

        $buffer=strtr($buffer, array("\""=>"",".)"=>")."));

        // For narration events, allow immediate streaming without minimum buffer size
        if ($gameRequest[0] !== "narration" && strlen($buffer)<$INCREMENTAL_SENTENCESIZE) {	// Avoid too short buffers
            continue;
        }

        $position = findFastSentencePosition($buffer,$INCREMENTAL_SENTENCESIZE);

        //echo "<$buffer>".PHP_EOL;
        if (($position !== false) && ($gameRequest[0] === "narration" || $position>$INCREMENTAL_SENTENCESIZE)) {
            $extractedData = substr($buffer, 0, $position + 1);
            $remainingData = substr($buffer, $position + 1);
            $sentences=split_sentences_stream(cleanResponse($extractedData));
            $GLOBALS["DEBUG_DATA"]["response"][]=["raw"=>$buffer,"processed"=>implode("|", $sentences)];
            $GLOBALS["DEBUG_DATA"]["perf"][]=(microtime(true) - $startTime)." secs in openai stream";

            if ($gameRequest[0] != "diary") {
                returnLines($sentences);
                $INCREMENTAL_SENTENCESIZE=MINIMUM_SENTENCE_SIZE;
            } else { //why is the diary talking? is this correct?
                $talkedSoFar[md5(implode(" ", $sentences))]=implode(" ", $sentences);
            }

            //echo "$extractedData  # ".(microtime(true)-$startTime)."\t".strlen($finalData)."\t".PHP_EOL;  // Output
            $totalProcessedData.=$extractedData;
            $extractedData="";
            $buffer=$remainingData;
            //$user_input_after=$GLOBALS["db"]->fetchAll("select count(*) as N from eventlog where type='user_input' and ts>$gameRequest[1]"); //9.0ms
            

        }
        // This is intended to stop the generation as soon as user input is detected, so we will attend new request instead of keeping generating this
        $user_input_after=$GLOBALS["db"]->fetchAll("select rowid as N from eventlog where type='user_input' and ts>$gameRequest[1] LIMIT 1"); // 2.1ms, faster than count(*)
        if (isset($user_input_after[0]))
            if (isset($user_input_after[0]["N"]))
                if ($user_input_after[0]["N"]>0) {
                    Logger::info("Generation stopped because user_input. ".__FILE__." ".__LINE__." ".__FUNCTION__);
                    error_log("Generation stopped because user_input. ".__FILE__." ".__LINE__." ".__FUNCTION__);
                    $connectionHandler->close();
                    dialectic_abort_json_response();
                    // Abort , user input detected
                }

    } // --- end while

    if ($outputWasValid && trim($buffer) === '' && count($talkedSoFar) == 0 && count($alreadysent) == 0) {
        if (!dialecticRecoverPlainLlmSpeech()) {
            if (method_exists($connectionHandler, "close")) {
                $connectionHandler->close("empty-response-before-fallback");
            }
            $fallbackResult = dialectic_try_llm_fallback("empty_response");
            if ($fallbackResult !== null) {
                return $fallbackResult;
            }
        }
    }
    
    
    if ($outputWasValid && trim($buffer)) {
        Logger::info("REMAINING DATA <$buffer>");
        $sentences=split_sentences_stream(cleanResponse(trim($buffer)));

        $GLOBALS["DEBUG_DATA"]["response"][]=["raw"=>$buffer,"processed"=>implode("|", $sentences)];
        $GLOBALS["DEBUG_DATA"]["perf"][]=(microtime(true) - $startTime)." secs in openai stream";
        if ($gameRequest[0] != "diary") {
            returnLines($sentences);
        } else {
            $talkedSoFar[md5(implode(" ", $sentences))]=implode(" ", $sentences);
        }
        $totalBuffer.=trim($buffer);
        $totalProcessedData.=trim($buffer);
    }

    if ($actionsAllowedForTurn && $outputWasValid)  {
        $functionsEnabledBeforeActionProcessing = !empty($GLOBALS["FUNCTIONS_ARE_ENABLED"]);
        $GLOBALS["FUNCTIONS_ARE_ENABLED"] = true;
        $requestTypeForActions = (string)($gameRequest[0] ?? "");
        $isRechatActionContext = in_array($requestTypeForActions, ["rechat", "narration"], true);
        $rechatActionsAllowed = filter_var($GLOBALS["RECHAT_ALLOW_ACTIONS"] ?? false, FILTER_VALIDATE_BOOLEAN);

        if ($isRechatActionContext && !$rechatActionsAllowed) {
            $actions = [];
            $rawActionsForLog = [];
            Logger::info("[actions] Dropped LLM actions because rechat actions are disabled" . Logger::formatContext([
                "speaker" => $GLOBALS["DIALECTIC_NAME"] ?? "",
                "request_type" => $requestTypeForActions,
            ]));
        } else {
            $actions=$connectionHandler->processActions();
            $rawActionsForLog = is_array($actions) ? $actions : [];
            if (isset($GLOBALS["action_post_process_fnct"])) {
                $actions=$GLOBALS["action_post_process_fnct"]($actions);
            }

            // Extnded version which is an array, so we can hook more than one function
            if (isset($GLOBALS["action_post_process_fnct_ex"]) && is_array($GLOBALS["action_post_process_fnct_ex"])) {
                foreach ($GLOBALS["action_post_process_fnct_ex"] as $postFilterFunc)
                    $actions=$postFilterFunc($actions);
            }
        }

        if (is_array($actions)) {
            require_once(__DIR__ . DIRECTORY_SEPARATOR . 'dialectic_command_payload.php');
            $actionNamesForLog = [];
            foreach ($actions as $actionForLog) {
                $decodedForLog = dialecticDecodeActionLine((string)$actionForLog);
                $nameForLog = $decodedForLog['action'] ?? '';
                if ($nameForLog !== "") {
                    $actionNamesForLog[] = $nameForLog;
                }
            }
            Logger::info("[actions] Processed LLM actions" . Logger::formatContext([
                "raw_count" => count($rawActionsForLog),
                "postfilter_count" => count($actions),
                "names" => $actionNamesForLog,
                "speaker" => $GLOBALS["DIALECTIC_NAME"] ?? "",
                "request_type" => $gameRequest[0] ?? "",
            ]));
            if (($gameRequest[0] ?? "") === "rechat") {
                Logger::info("[rechat] Rechat action pass" . Logger::formatContext([
                    "speaker" => $GLOBALS["DIALECTIC_NAME"] ?? "",
                    "actions" => $actionNamesForLog,
                    "action_count" => count($actions),
                ]));
            }
        }

        
        if (is_array($actions) && (sizeof($actions)>0)) {
            
            // ACTION POST-FILTER
            
            if ($GLOBALS["FUNCTIONS_ARE_ENABLED"]) {
                $isRolemasteredNpc = (
                    (!empty($GLOBALS["NPC_ROLEMASTERED"])) ||
                    dialecticResolveNpcRolemasterState($GLOBALS["DIALECTIC_NAME"] ?? '', ['load_lookup' => true])
                );
                $copyActions=[];
                $replaceAction = static function ($sourceAction, string $newAction, $newParameter = '') {
                    $decodedSource = dialecticDecodeActionLine((string)$sourceAction);
                    return dialecticEncodeActionLine(
                        (string)($decodedSource['actor'] ?? ''),
                        $newAction,
                        $newParameter
                    );
                };
                foreach ($actions as $n=>$action) {
                    $copyActions[$n]=$actions[$n];
                    $decodedAction = dialecticDecodeActionLine((string)$action);
                    $actionName = (string)($decodedAction['action'] ?? '');
                    $actionParts2 = array_merge([$actionName], array_values($decodedAction['parameter_args'] ?? []));
                    
                    if (isset($actionParts2[1])) {
                        // Parameter part 
                        if ($actionParts2[0]=="Attack") {
                            // Lets polish the parameters
                            $localtarget=$actionParts2[1];
                            $mang1=explode(",",$localtarget);
                            $mang2=explode(" and ",$mang1[0]);
                            $mang3=explode("(",$mang2[0]);
                            $mang4=FindClosestNPCName($mang3[0]);

                            if ($mang4)
                                $actions[$n]=$replaceAction($action, "Attack", $mang4);
                            else
                                $actions[$n]=$replaceAction($action, "Attack", $mang3[0]);

                            error_log("[ACTION POSTFILTER Attack] $localtarget => {$mang3[0]} => $mang4");
                        } else if ($actionParts2[0]=="GiveItemTo") {
                            // Check if parameter is JSON (multi-param) - skip post-filtering for JSON
                            if (isset($actionParts2[1]) && substr(trim($actionParts2[1]), 0, 1) === '{') {
                                error_log("[ACTION POSTFILTER GiveItemTo] JSON parameter detected, skipping post-filter");
                                // Keep the action as-is for JSON parameters
                            } else {
                                $localtarget=$actionParts2[1] ?? "";
                                $itemArg=trim(strval($actionParts2[2] ?? ""));
                                $amountArg=trim(strval($actionParts2[3] ?? ""));
                                $mang1=explode(",",$localtarget);
                                $mang2=explode(" and ",$mang1[0]);
                                $mang3=explode("(",$mang2[0]);
                                $mang4=FindClosestActorName($mang3[0]);
                                error_log("[ACTION POSTFILTER GiveItemTo] $localtarget => {$mang3[0]} => $mang4");

                                $resolvedTarget = $mang4 ? $mang4 : trim($mang3[0]);
                                $rebuiltParameter = ['target' => $resolvedTarget];
                                if ($itemArg !== "") {
                                    $rebuiltParameter['item'] = $itemArg;
                                }
                                if ($amountArg !== "") {
                                    $rebuiltParameter['amount'] = $amountArg;
                                }
                                $actions[$n]=$replaceAction($action, "GiveItemTo", $rebuiltParameter);
                            }


                        }  else if ($actionParts2[0]=="TradeItems") {
                            // Lets polish the parammeters
                            $localtarget=$actionParts2[1];
                            $mang1=explode(",",$localtarget);
                            $mang2=explode(" and ",$mang1[0]);
                            $mang3=explode("(",$mang2[0]);

                            $mang4=FindClosestActorName($mang3[0]);

                            error_log("[ACTION POSTFILTER TradeItems] $localtarget => {$mang3[0]} => $mang4");

                            if ($mang4)
                                $destination=$mang4;
                            else
                                $destination=$mang3[0];

                            error_log("[ACTION POSTFILTER TradeItems] $localtarget => {$mang3[0]} => $destination");

                            if ($destination!=$GLOBALS["PLAYER_NAME"])
                                $actions[$n]=$replaceAction($action, "TradeItems", $destination);

                        }  else if ($actionParts2[0]=="Follow") {
                            // Lets polish the parammeters
                            $localtarget=$actionParts2[1];
                            $mang1=explode(",",$localtarget);
                            $mang2=explode(" and ",$mang1[0]);
                            $mang3=explode("(",$mang2[0]);
                            $requestedFollowTarget=trim($mang3[0]);
                            $playerNameForFollow=trim(strval($GLOBALS["PLAYER_NAME"] ?? ""));
                            $isPlayerFollowTarget=(
                                $requestedFollowTarget !== "" &&
                                (
                                    ($playerNameForFollow !== "" && strcasecmp($requestedFollowTarget, $playerNameForFollow) === 0) ||
                                    in_array(strtolower($requestedFollowTarget), ["player", "me", "the player"], true)
                                )
                            );

                            if ($isPlayerFollowTarget) {
                                error_log("[ACTION POSTFILTER Follow] $localtarget => {$requestedFollowTarget} => FollowPlayer");
                                $actions[$n]=$replaceAction($action, "FollowPlayer", "");
                                continue;
                            }

                            $mang4=FindClosestActorName($requestedFollowTarget);

                            error_log("[ACTION POSTFILTER Follow] $localtarget =>  {$mang3[0]} => $mang4");

                            if ($mang4)
                                $destination=$mang4;
                            else
                                $destination=$requestedFollowTarget;
                            $actions[$n]=$replaceAction($action, "Follow", $destination);
                            

                            error_log("[ACTION POSTFILTER Follow] $localtarget => {$mang3[0]} => $destination");

                        } else if ($actionParts2[0]=="TravelTo") {
                            // Lets polish the parammeters
                            $localtarget=$actionParts2[1];
                            $mang1=explode(",",$localtarget);
                            $mang2=explode(" and ",$mang1[0]);
                            $mang3=explode("(",$mang2[0]);
                            $mang4=explode("--",$mang3[0]);
                            
                            $destination=$mang4[0];

                            error_log("[ACTION POSTFILTER TravelTo]  $localtarget => {$mang4[0]} => $destination");

                            $destinationName=$GLOBALS["db"]->escape(trim($destination));
                            $dbDestination=$GLOBALS["db"]->fetchOne("SELECT name, similarity(name, '$destinationName') AS sim,formid FROM locations ORDER BY sim DESC LIMIT 1");
                            $dbDestinationRegion=$GLOBALS["db"]->fetchOne("SELECT name, similarity(worldspace, '$destinationName') AS sim,formid FROM locations ORDER BY sim DESC LIMIT 1");

                            $contextDestinations=DataPosibleLocationsToGo();

                            if (in_array(trim($localtarget),$contextDestinations)) {
                                // Perfect match
                                error_log("[ACTION POSTFILTER TravelTo] Seems valid as-is (context destination): <$localtarget> => $localtarget");
                                $travelPayload = function_exists('dialecticBuildTravelToActionPayload') ? dialecticBuildTravelToActionPayload($localtarget) : $localtarget;
                                $actions[$n]=$replaceAction($action, "TravelTo", $travelPayload);

                            } else if (in_array($destination,$contextDestinations)) {
                                error_log("[ACTION POSTFILTER TravelTo] Seemd valid (context destination): $localtarget => $destination");
                                $travelPayload = function_exists('dialecticBuildTravelToActionPayload') ? dialecticBuildTravelToActionPayload($destination) : $destination;
                                $actions[$n]=$replaceAction($action, "TravelTo", $travelPayload);

                            } else {
                                if ($isRolemasteredNpc) {
                                    if (stripos($destination,"home")===0) {
                                        // Rolemastered NPC wants to return back home
                                        $actions[$n]=$replaceAction($action, "ReturnBackHome", "");
                                        continue;

                                    }

                                } 
                                if (is_array($dbDestination) && isset($dbDestination["formid"])) {
                                    $destination=trim(strval($dbDestination["name"] ?? $destination));
                                    error_log("[ACTION POSTFILTER TravelTo] found database entry for $localtarget => {$dbDestination["formid"]} => {$dbDestination["name"]}, similarity ({$dbDestination["sim"]})");
                                    $travelPayload = function_exists('dialecticBuildTravelToActionPayload') ? dialecticBuildTravelToActionPayload($destination) : $destination;
                                    $actions[$n]=$replaceAction($action, "TravelTo", $travelPayload);
                                
                                } else if (is_array($dbDestinationRegion) && isset($dbDestinationRegion["formid"])) {

                                    $destination=trim(strval($dbDestinationRegion["name"] ?? $destination));
                                    error_log("[ACTION POSTFILTER TravelTo] found database (searching by region) entry for $localtarget => {$dbDestinationRegion["formid"]} => {$dbDestinationRegion["name"]}, similarity ({$dbDestinationRegion["sim"]})");
                                    $travelPayload = function_exists('dialecticBuildTravelToActionPayload') ? dialecticBuildTravelToActionPayload($destination) : $destination;
                                    $actions[$n]=$replaceAction($action, "TravelTo", $travelPayload);
                                } else if (stripos($destination,"outside")!==false) {
                                    $destination=DataLastKnownLocationBaseHuman(false);
                                    error_log("[ACTION POSTFILTER TravelTo] reference to outside detected , $localtarget => $destination");
                                    $travelPayload = function_exists('dialecticBuildTravelToActionPayload') ? dialecticBuildTravelToActionPayload($destination) : $destination;
                                    $actions[$n]=$replaceAction($action, "TravelTo", $travelPayload);
                                } else {
                                    $travelPayload = function_exists('dialecticBuildTravelToActionPayload') ? dialecticBuildTravelToActionPayload($destination) : $destination;
                                    $actions[$n]=$replaceAction($action, "TravelTo", $travelPayload);
                                }
                            }
                            
                        } else if ($actionParts2[0]=="MoveTo") {
                            // MoveTo is actor-only. Locations must remain TravelTo.
                            $localtarget=$actionParts2[1] ?? "";
                            $mang1=explode(",",$localtarget);
                            $mang2=explode(" and ",$mang1[0]);
                            $mang3=explode("(",$mang2[0]);
                            $mang4=explode("--",$mang3[0]);
                            
                            $target=trim($mang4[0]);
                            $resolvedTarget="";

                            if ($target !== "" && isset($GLOBALS["PLAYER_NAME"]) && strcasecmp($target, $GLOBALS["PLAYER_NAME"]) === 0) {
                                $resolvedTarget=$GLOBALS["PLAYER_NAME"];
                            }

                            if ($resolvedTarget === "") {
                                foreach (DataPosibleMoveToTargets() as $candidateTarget) {
                                    if (strcasecmp($target, $candidateTarget) === 0) {
                                        $resolvedTarget=$candidateTarget;
                                        break;
                                    }
                                }
                            }

                            if ($resolvedTarget === "") {
                                $closestTarget=FindClosestActorName($target);
                                if ($closestTarget !== "" && levenshtein(strtolower($target), strtolower($closestTarget)) <= 3) {
                                    $resolvedTarget=$closestTarget;
                                }
                            }

                            if ($resolvedTarget === "") {
                                $resolvedTarget=$target;
                            }

                            error_log("[ACTION POSTFILTER MoveTo] $localtarget => $target => $resolvedTarget");
                            $actions[$n]=$replaceAction($action, "MoveTo", $resolvedTarget);
                            
                        }  else if ($actionParts2[0]=="FollowPlayer") {
                            
                            error_log("[ACTION POSTFILTER FollowPlayer] Just Cleaning here");
                            $actions[$n]=$replaceAction($action, "FollowPlayer", "");
                            
                        }  else if ($actionParts2[0]=="ReturnBackHome") {
                            
                            error_log("[ACTION POSTFILTER ReturnBackHome] Just Cleaning here");
                            $actions[$n]=$replaceAction($action, "ReturnBackHome", "");
                            
                        } else if ($actionParts2[0]=="PickupItem") {
                            // Parse item parameter - can be JSON or plain string
                            $itemParam = trim($actionParts2[1]);
                            
                            Logger::info("[PickupItem PostFilter] Raw LLM item parameter: '{$itemParam}'");
                            
                            // Check if parameter is JSON (multi-param format)
                            if (substr($itemParam, 0, 1) === '{') {
                                // JSON format: {"target":"","item":"0xFF00550D:Diamond"}
                                $params = json_decode($itemParam, true);
                                $itemParam = isset($params['item']) ? trim($params['item']) : '';
                                Logger::info("[PickupItem PostFilter] Extracted item from JSON: '{$itemParam}'");
                            }
                            $itemParam = trim($itemParam, " \t\n\r\0\x0B`\"'");
                            
                            // If still empty, can't proceed
                            if (empty($itemParam)) {
                                Logger::warn("[PickupItem PostFilter] No item parameter provided, skipping");
                                continue;
                            }
                            
                            $itemsStr = DataItemsInCloseRange();
                            if ($itemsStr !== "") {
                                $itemsList = explode(',', $itemsStr);
                                    
                                    Logger::info("[PickupItem PostFilter] Found " . count($itemsList) . " items in database");
                                    Logger::info("[PickupItem PostFilter] First 3 items: " . implode(' | ', array_slice($itemsList, 0, 3)));
                                    
                                    $foundItem = false;
                                    
                                    // Check if LLM provided the RefID:ItemName format
                                    if (preg_match('/^0x[0-9A-Fa-f]+:/', $itemParam)) {
                                        // LLM provided "0xRefID:ItemName", extract the RefID
                                        $paramParts = explode(':', $itemParam, 2);
                                        $paramRefID = $paramParts[0];
                                        
                                        Logger::info("[PickupItem PostFilter] LLM provided RefID: {$paramRefID}, searching for exact match...");
                                        
                                        // Search for exact RefID match
                                        foreach ($itemsList as $itemEntry) {
                                            // Parse "RefID:BaseID:ItemName" from database
                                            $entryParts = explode(':', trim($itemEntry), 3);
                                            if (count($entryParts) >= 3) {
                                                $refID = $entryParts[0];
                                                $itemName = $entryParts[2];
                                                
                                                // Exact RefID match (case-insensitive)
                                                if (strcasecmp($refID, $paramRefID) === 0) {
                                                    // Send RefID:ItemName without (STEALING) tag to game
                                                    $cleanItemName = str_replace(' (STEALING)', '', $itemName);
                                                    $cleanFormat = "{$refID}:{$cleanItemName}";
                                                    Logger::info("[PickupItem PostFilter] EXACT MATCH FOUND! Sending: {$cleanFormat}");
                                                    $actions[$n]=$replaceAction($action, "PickupItem", $cleanFormat);
                                                    $foundItem = true;
                                                    break;
                                                }
                                            }
                                        }
                                        
                                        if (!$foundItem) {
                                            Logger::warn("[PickupItem PostFilter] No exact match found for RefID: {$paramRefID}");
                                            Logger::warn("[PickupItem PostFilter] Item may have despawned or moved. Available RefIDs: " . 
                                                implode(', ', array_map(function($item) {
                                                    $parts = explode(':', trim($item), 3);
                                                    return $parts[0] ?? 'invalid';
                                                }, array_slice($itemsList, 0, 10))));
                                        }
                                    } else {
                                        // LLM provided just the item name, search by name
                                        foreach ($itemsList as $itemEntry) {
                                            $entryParts = explode(':', trim($itemEntry), 3);
                                            if (count($entryParts) >= 3) {
                                                $refID = $entryParts[0];
                                                $itemName = $entryParts[2];
                                                
                                                // Strip (STEALING) tag for comparison
                                                $cleanItemName = str_replace(' (STEALING)', '', $itemName);
                                                
                                                if (stripos($cleanItemName, $itemParam) !== false) {
                                                    // Send RefID:ItemName without (STEALING) tag to game
                                                    $displayFormat = "{$refID}:{$cleanItemName}";
                                                    $actions[$n]=$replaceAction($action, "PickupItem", $displayFormat);
                                                    $foundItem = true;
                                                    break;
                                                }
                                            }
                                        }
                                    }
                            }

                        }
                    }
                    
                }
            }
            
            require_once(__DIR__ . DIRECTORY_SEPARATOR . 'dialectic_command_payload.php');
            if (is_array($actions)) {
                $actions = array_map(static function ($action) {
                    $decodedAction = dialecticDecodeActionLine((string)$action);
                    $actor = trim((string)($decodedAction['actor'] ?? ''));
                    $actionName = trim((string)($decodedAction['action'] ?? ''));
                    if ($actionName === '') {
                        return (string)$action;
                    }
                    return dialecticEncodeActionLine(
                        $actor,
                        $actionName,
                        '',
                        (string)($decodedAction['parameter_string'] ?? '')
                    );
                }, $actions);
            }

            // Log actions
            foreach ($actions as $n=>$singleaction) {
                $decodedAction = dialecticDecodeActionLine((string)$singleaction);
                $actionName = trim((string)($decodedAction['action'] ?? ''));
                $actorName = trim((string)($decodedAction['actor'] ?? ''));
                if ($actionName === '') {
                    continue;
                }
                
                $GLOBALS["db"]->insert(
                    'actions_issued',
                    array(
                        'action' => $actionName,
                        'fullcall' =>$singleaction,
                        'actorname'=> isset($GLOBALS["PATCH_ACTION_ALL_ACTORS"])?$GLOBALS["PATCH_ACTION_ALL_ACTORS"]:$actorName,
                        'ts' => $gameRequest[1],
                        'gamets' => $gameRequest[2],
                        'localts'=>time(),
                        'original'=>function_exists('dialecticActionCatalogApplyFollowupChainToActionsIssuedOriginal')
                            ? dialecticActionCatalogApplyFollowupChainToActionsIssuedOriginal($copyActions[$n])
                            : $copyActions[$n]
                    )
                );


            }
            $GLOBALS["DEBUG_DATA"]["response"][]=$actions;
            
            $requestTypeForActionEmit = (string)($gameRequest[0] ?? "");
            $isRechatActionEmitContext = in_array($requestTypeForActionEmit, ["rechat", "narration"], true);
            $rechatActionEmitAllowed = filter_var($GLOBALS["RECHAT_ALLOW_ACTIONS"] ?? false, FILTER_VALIDATE_BOOLEAN);
            if ($isRechatActionEmitContext && !$rechatActionEmitAllowed) {
                Logger::info("[actions] Suppressed plugin action emission because rechat actions are disabled" . Logger::formatContext([
                    "speaker" => $GLOBALS["DIALECTIC_NAME"] ?? "",
                    "request_type" => $requestTypeForActionEmit,
                    "action_count" => count($actions),
                ]));
                $actions = [];
            }

            $pluginOutputLogLines = [];
            // Queue actions for the JSON response and mirror the structured records to output_to_plugin.log.
            foreach ($actions as $action) {
                Logger::info("Echoing action to plugin: {$action}");
                if (function_exists('dialectic_buffer_command_response_line')) {
                    $decodedActionLine = dialecticDecodeActionLine((string)$action);
                    $speaker = trim((string)($decodedActionLine['actor'] ?? ""));
                    if ($speaker === "") {
                        $speaker = trim((string)($GLOBALS["DIALECTIC_NAME"] ?? "rolemaster"));
                    }

                    $commandName = trim((string)($decodedActionLine['action'] ?? ""));
                    if ($commandName === "") {
                        continue;
                    }
                    $commandArgsForResponse = array_values($decodedActionLine['parameter_args'] ?? []);
                    $payload = dialecticEncodeCommandAction($commandName, $commandArgsForResponse);
                    $responseMetadata = [
                        "request_type" => $requestTypeForActionEmit,
                    ];
                    $structuredParameter = $decodedActionLine['parameter'] ?? null;
                    if (!is_array($structuredParameter)) {
                        $parameterString = trim(strval($decodedActionLine['parameter_string'] ?? ''));
                        if ($parameterString !== '' && $parameterString[0] === '{') {
                            $decodedParameter = json_decode($parameterString, true);
                            if (is_array($decodedParameter)) {
                                $structuredParameter = $decodedParameter;
                            }
                        }
                    }
                    if (is_array($structuredParameter)) {
                        foreach ($structuredParameter as $metadataKey => $metadataValue) {
                            if (is_string($metadataKey) && is_scalar($metadataValue)) {
                                $responseMetadata[$metadataKey] = $metadataValue;
                            }
                        }
                    }
                    dialectic_buffer_command_response_line($speaker, $payload, $responseMetadata);
                    $decodedCommand = dialecticDecodeCommandAction($payload);
                    $commandPayload = $decodedCommand["command_payload"];
                    $pluginOutputLogLines[] = json_encode([
                        "schema" => "dialectic.response.line.v1",
                        "speaker" => $speaker,
                        "action" => "rolecommand",
                        "request_type" => $requestTypeForActionEmit,
                        "text" => $decodedCommand["command_name"],
                        "command" => $commandPayload,
                        "command_name" => $decodedCommand["command_name"],
                        "command_args" => $decodedCommand["command_args"],
                    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                } else {
                    Logger::error("[actions] JSON response buffer is unavailable; action was not queued");
                }
            }

            $actionOutput = implode(PHP_EOL, $pluginOutputLogLines);
            if ($actionOutput !== "") {
                $actionOutput .= PHP_EOL;
            }
            $outputToPluginLog = __DIR__."/../log/output_to_plugin.log";
            Logger::rotateLogIfTooLarge($outputToPluginLog);
            Logger::info("[plugin-output] queued action batch" . Logger::formatContext([
                "count" => count($actions),
                "request_type" => $gameRequest[0] ?? "",
                "speaker" => $GLOBALS["DIALECTIC_NAME"] ?? "",
            ]));
            if ($actionOutput !== "") {
                file_put_contents($outputToPluginLog,$actionOutput, FILE_APPEND | LOCK_EX);
            }

        }
        $GLOBALS["FUNCTIONS_ARE_ENABLED"] = $functionsEnabledBeforeActionProcessing;
    }
    
    if (isset($GLOBALS["CLEAN_CONTEXT_FOCUS_CHAT"]) && $GLOBALS["CLEAN_CONTEXT_FOCUS_CHAT"]) {
        ;//Was a faked stream
    } else 
        $connectionHandler->close('standard');
    //fwrite($fileLog, $totalBuffer . PHP_EOL); // Write the line to the file with a line break // DEBUG CODE


    return $outputWasValid;
}

function AddFirstTimeMet($followerName,$momentum,$gamets,$ts) {

    $fn=$GLOBALS["db"]->escape($followerName);
    
    // Check if already recorded - with error handling
    $already = @$GLOBALS["db"]->fetchAll("select 1 as t from memory where event='first_met' and message like '%met {$fn}%'");
    if ($already === false) {
        Logger::warn("[AddFirstTimeMet] Query to memory table failed for follower: {$followerName}");
        return;
    }
    
    if (is_array($already) && sizeof($already)>0) {
        // Already exists;
        return;
    }

    // Get first interaction timestamp - with error handling
    $realFirst = @$GLOBALS["db"]->fetchAll("SELECT gamets,convert_gamets2fallout_date(gamets) as fallout_date,ts,localts FROM speech where companions ilike '%$fn%' order by rowid asc limit 1 offset 0");
    
    if ($realFirst === false) {
        Logger::warn("[AddFirstTimeMet] Query to speech table failed for follower: {$followerName}");
        return;
    }

    if (is_array($realFirst) && sizeof($realFirst)>0) {
        $gamets=$realFirst[0]["gamets"];
        $ts=$realFirst[0]["ts"];
        $momentum=$realFirst[0]["localts"];
        $fallout_date=$realFirst[0]["fallout_date"]; // game timestamp converted to fallout date YYYY-MM-DD HH:MM:SS

        logMemory($GLOBALS["PLAYER_NAME"], $followerName,
        "(Important note: {$GLOBALS["PLAYER_NAME"]} met {$followerName} for the first time on {$fallout_date}. This is an important event, so use tag #FirstTimeMet.)",
        $momentum, $gamets,'first_met',$ts);
    }


}


function DataRetrieveFirstTimeMet($s_player_name, $s_npc_name) {
    global $db;

	$s_res = "";

	if ((strlen($s_player_name)>0) && (strlen($s_npc_name)>0) && ($s_player_name != $s_npc_name)) {
        if (($s_npc_name == "Dialectic") || ($s_player_name == "Dialectic")) { // Dialectic easter egg
            return "{$s_player_name} met {$s_npc_name} for the first time on 0199-04-26, 15:36:00, years ago.";
        }
		$s_player = $db->escape($s_player_name);
		$s_npc = $db->escape($s_npc_name);

        $crt_gamets = intval(DataLastKnownGameTS());

		$db_rec = $db->fetchAll("SELECT speaker,listener,
			message,gamets,momentum,rowid  
			FROM memory 
			WHERE event = 'first_met' AND gamets > 0 AND
			((speaker = '{$s_player}' AND listener = '$s_npc') OR
			(listener = '{$s_player}' AND speaker = '$s_npc'))
			ORDER BY rowid ASC LIMIT 1; ");
            
        $b_found_memory = (is_array($db_rec) && sizeof($db_rec)>0); 
        
        if (!$b_found_memory) { // check conversations
            $gts_met = GetFirstInteraction($s_player, $s_npc); 
        } else {
			$gts_met = intval($db_rec[0]['gamets'] ?? 0);
		}

        if (($gts_met > 0) && ($crt_gamets > $gts_met)) {
            $gts_ago = $crt_gamets - $gts_met;
            $s_met = convert_gamets2fallout_date($gts_ago);
			$hours_ago = convert_gamets2hours($gts_ago);
            
			if ($hours_ago < 49)
				$s_time_ago = "{$hours_ago} hours ago";
			else {
				$days_ago = intval($hours_ago / 24); 
				$s_time_ago = "{$days_ago} days ago";
			}
			$s_res = "{$s_player_name} met {$s_npc_name} for the first time on {$s_met}, {$s_time_ago}.";

        } else { 
			Logger::info("DataRetrieveLastMet: NO match found");
			//$s_res = "There is no record of when {$s_player_name} met {$s_npc_name}.";
		}
	}
	return $s_res;
}

function GetFirstTimeMetMemory($s_player_name, $s_npc_name) {
    global $db;
    $i_res = 0;

	if ((strlen($s_player_name)>0) && (strlen($s_npc_name)>0) && ($s_player_name != $s_npc_name)) {
		$s_player = $db->escape($s_player_name);
		$s_npc = $db->escape($s_npc_name);

        //$crt_gamets = intval(DataLastKnownGameTS());

		$db_rec = $db->fetchAll("SELECT speaker,listener,
			message,gamets,momentum,rowid  
			FROM memory 
			WHERE event = 'first_met' AND gamets > 0 AND
			((speaker = '{$s_player}' AND listener = '$s_npc') OR
			(listener = '{$s_player}' AND speaker = '$s_npc'))
			ORDER BY rowid ASC LIMIT 1; ");
            
        $b_found_memory = (is_array($db_rec) && sizeof($db_rec)>0); 
        
        if ($b_found_memory) { 
			$i_res = intval($db_rec[0]['gamets'] ?? 0);
		}

	}
	return $i_res;
}

function GetFirstTimeMet($s_player_name, $s_npc_name) {
    $i_res = 0;

	if ((strlen($s_player_name)>0) && (strlen($s_npc_name)>0) && ($s_player_name != $s_npc_name)) {
        
        $i_res = GetFirstTimeMetMemory($s_player_name, $s_npc_name); 

        if ($i_res <= 0) { // check conversations
            $i_res = GetFirstInteraction($s_player_name, $s_npc_name); 
		}
	}
	return $i_res;
}

function GetLastInteraction($s_player_name, $s_npc_name) {
    global $db;
	$i_res = 0;
	if ((strlen($s_player_name)>0) && (strlen($s_npc_name)>0) && ($s_player_name != $s_npc_name)) {
		$s_player = $db->escape($s_player_name);
		$s_npc = $db->escape($s_npc_name);
		$db_rec = $db->fetchAll("SELECT gamets FROM speech 
        WHERE (gamets > 0) AND 
          ((speaker = '{$s_player}' AND listener = '{$s_npc}') OR 
          (listener = '{$s_player}' AND speaker = '{$s_npc}'))  
        ORDER BY gamets DESC LIMIT 1 ");
		if (is_array($db_rec) && sizeof($db_rec)>0) {
			$i_res = intval($db_rec[0]['gamets']);
		}
	}
	return $i_res;
}

function GetLastSpeechTs() {
    global $db;
    $i_res=0;
	$db_rec = $db->fetchAll("SELECT gamets as gamets FROM speech 
        WHERE (gamets > 0) ORDER BY gamets DESC LIMIT 1 ");
	if (is_array($db_rec) && sizeof($db_rec)>0) {
		$i_res = intval($db_rec[0]['gamets']);
	}
	
	return $i_res;
}

function GetFirstInteraction($s_player_name, $s_npc_name) {
    global $db;
	$i_res = 0;
	if ((strlen($s_player_name)>0) && (strlen($s_npc_name)>0) && ($s_player_name != $s_npc_name)) {
		$s_player = $db->escape($s_player_name);
		$s_npc = $db->escape($s_npc_name);
		$db_rec = $db->fetchAll("SELECT gamets FROM speech 
        WHERE (gamets > 0) AND 
          ((speaker = '{$s_player}' AND listener = '{$s_npc}') OR 
          (listener = '{$s_player}' AND speaker = '{$s_npc}'))  
        ORDER BY gamets ASC LIMIT 1 ");
		if (is_array($db_rec) && sizeof($db_rec)>0) {
			$i_res = intval($db_rec[0]['gamets']);
		}
	}
	return $i_res;
}

function DataRetrieveLastTimeTalk($s_player_name, $s_npc_name) {
    global $db;

	$s_res = "";

	if ((strlen($s_player_name)>0) && (strlen($s_npc_name)>0) && (!($s_player_name == 'The Narrator')) && (!($s_npc_name == 'The Narrator'))) {
		$crt_gamets = intval(DataLastKnownGameTS());
		$gts_met = GetLastInteraction($s_player_name, $s_npc_name); 
		if ($gts_met > 0) {
			$s_date = gamets2str_format_date($gts_met, $dt_format = 'Y-m-d'); 
			$gts_ago = $crt_gamets - $gts_met;
			$hours_ago = convert_gamets2hours($gts_ago);
			if ($hours_ago > 3) {
				if ($hours_ago < 48) {
					$s_res = "{$s_player_name} and {$s_npc_name} last spoke {$hours_ago} hours ago.";
				} else {
					$days_ago = convert_gamets2days($gts_ago);
					if ($days_ago < 31) {
						$s_res = "{$s_player_name} and {$s_npc_name} last spoke {$days_ago} days ago.";
					} else {
						$months_ago = intval($days_ago * 0.03333333);
						if ($months_ago < 12) {
							$s_res = "{$s_player_name} and {$s_npc_name} last spoke {$months_ago} months ago on {$s_date}.";
						} else {
							$s_res = "{$s_player_name} and {$s_npc_name} last spoke long time ago on {$s_date}.";
						}
					}
				}	
			} else {
                Logger::debug("DataRetrieveLastTimeTalk: {$s_player_name} and {$s_npc_name} spoke recently");
				//$s_res = "{$s_player_name} and {$s_npc_name} spoke recently.";
			}
		} else { 
			Logger::debug("DataRetrieveLastTimeTalk: NO match found for {$s_player_name} - {$s_npc_name}");
			//$s_res = "There is no record of when {$s_player_name} and {$s_npc_name} last spoke.";
		}
	}
	return $s_res;
}


function GetAnimationHex($mood)
{
    $mood = extractFirstEmoteMood($mood);
    if ($mood === '') {
        return "";
    }

    //error_log("Getting animation for mood: $mood");
    $ANIMATIONS=[
        "ArmsCrossed"=>"IdleExamine",        // Arms crossed
        "PointClose"=>"IdlePointClose",
        "HandsBehindBack"=>"IdleHandsBehindBack",    // 000B240A ? // Arms behind back
        //"DrawAttention"=>"0x0006FF15",     // Continous
        //"Cheer"=>"0x00066374",             // Continous
        "ApplauseSarcastic"=>"IdleApplaudSarcastic",  // Continous
        "WaveHand"=>"IdleWave",
        "Nervous"=>"IdleNervous",
        "ArmsRaised"=>"IdleSurrender",
        "NervousDialogue"=>"IdleDialogueMovingTalkA",
        "NervousDialogue1"=>"IdleDialogueMovingTalkB",
        "NervousDialogue2"=>"IdleDialogueMovingTalkC",
        "NervousDialogue3"=>"IdleDialogueMovingTalkD",
        "Cheer"=>"SpectatorCheer",
        "ComeThisWay"=>"IdleComeThisWay",
        "SarcasticMove"=>"IdleDialogueExpressiveStart",
        "Applause1"=>"IdleApplaud2",
        "Applause2"=>"IdleApplaud3",
        "Applause3"=>"IdleApplaud4",
        "Applause4"=>"IdleApplaud5",
        "DrinkPotion"=>"IdleDrinkPotion",        // Don't use while talking
        "PointFar"=>"IdlePointFar_01",
        "PointFar2"=>"IdlePointFar_02",
        "GiveSomething"=>"IdleGive",
        "TakeSomething"=>"IdleTake",
        "Salute"=>"IdleSalute",
        "CleanSweat"=>"IdleWipeBrow",
        "NoteRead"=>"IdleNoteRead",
        "LookFar"=>"IdleLookFar",
        "Laugh"=>"IdleLaugh",
        "CleanSword"=>"IdleCleanSword",
        "WarmArms"=>"IdleWarmArms",
        "Positive"=>"LooseDialogueResponsePositive",
        "Negative"=>"LooseDialogueResponseNegative",
        "HappyDialogue"=>"IdleDialogueHappyStart",
        "AngryDialogue"=>"IdleDialogueAngryStart",
        "Agitated"=>"IdleDialogueAngryStart",
        "HandOnChinGesture"=>"IdleDialogueHandOnChinGesture",
        
    ];
    
    if ($mood=="sarcastic") {
        return array_rand(array_flip([$ANIMATIONS["SarcasticMove"],$ANIMATIONS["CleanSweat"],$ANIMATIONS["Agitated"],$ANIMATIONS["ApplauseSarcastic"]]), 1);
        
        
    } else if ($mood=="sassy") {
        return array_rand(array_flip([$ANIMATIONS["SarcasticMove"],$ANIMATIONS["CleanSweat"],$ANIMATIONS["Agitated"],$ANIMATIONS["ApplauseSarcastic"]]), 1);
        
        
    } else if ($mood=="sardonic") {
        return array_rand(array_flip([$ANIMATIONS["SarcasticMove"],$ANIMATIONS["CleanSweat"],$ANIMATIONS["Agitated"],$ANIMATIONS["ApplauseSarcastic"]]), 1);
        
        
    } else if ($mood=="irritated") {
        return array_rand(array_flip([$ANIMATIONS["PointClose"],$ANIMATIONS["Negative"],$ANIMATIONS["AngryDialogue"]]), 1);
       
        
    } else if ($mood=="mocking") {
        return array_rand(array_flip([$ANIMATIONS["Applause1"],$ANIMATIONS["Applause2"],$ANIMATIONS["Applause3"],$ANIMATIONS["Applause4"]]), 1);
        
        
    } else if ($mood=="playful") {
        return array_rand(array_flip([$ANIMATIONS["Cheer"],$ANIMATIONS["HappyDialogue"],$ANIMATIONS["Positive"]]), 1);
            
    } else if ($mood=="teasing") {
        return array_rand(array_flip([$ANIMATIONS["NervousDialogue"],$ANIMATIONS["NervousDialogue1"],$ANIMATIONS["NervousDialogue2"],$ANIMATIONS["NervousDialogue3"]]), 1);
        
        
    } else if ($mood=="smug") {
        return $ANIMATIONS["Nervous"];
        
        
    } else if ($mood=="amused") {
        return $ANIMATIONS["ArmsRaised"];
        
    } else if ($mood=="smirking") {
        return $ANIMATIONS["Nervous"];
    
        
    } else if ($mood=="serious") {
        return array_rand(array_flip([$ANIMATIONS["CleanSweat"],$ANIMATIONS["PointClose"],$ANIMATIONS["HandOnChinGesture"]]), 1);
    
        
    } else if ($mood=="firm") {
        return array_rand(array_flip([$ANIMATIONS["CleanSweat"],$ANIMATIONS["PointClose"],$ANIMATIONS["HandOnChinGesture"]]), 1);
    
        
    } else if ($mood=="neutral") {
        return array_rand(array_flip([$ANIMATIONS["HappyDialogue"]]), 1);
        
        
    } else if ($mood=="drunk") {
        // No animation :(
        Logger::info("Using filter for mood drunk");
        $GLOBALS["TTS_FFMPEG_FILTERS"]["tempo"]='atempo=0.65';
        return "DrunkStart";
        
    } else if ($mood=="sober") {

        Logger::info("Resetting mood drunk.");
        
        return "DrunkStop";
        
    } else if ($mood=="high") {
        // No animation :(
        $GLOBALS["TTS_FFMPEG_FILTERS"]["tempo"]='atempo=1.45';
        
    } 
                      
    
    //error_log("Getting animation for mood: $mood, no result found");
    return "";

}


function GetExpression($mood) {
     $mood = extractFirstEmoteMood($mood);
     if ($mood === '') {
         return "";
     }
     $EXPRESSIONS=[
     "DialogueAnger",    "DialogueFear",    "DialogueHappy",     "DialogueSad",
     "DialogueSurprise", "DialoguePuzzled", "DialogueDisgusted", "MoodNeutral",
     "MoodAnger",        "MoodFear",        "MoodHappy",        "MoodSad",
     "MoodSurprise",    "MoodPuzzled",    "MoodDisgusted",    "CombatAnger",
     "CombatShout"
     ];
     
     $result="";
     if ($mood=="sarcastic") {
        $result= array_rand(array_flip(["DialoguePuzzled"]), 1);
         
         
     } else if ($mood=="sassy") {
        $result= array_rand(array_flip(["DialoguePuzzled"]), 1);
         
         
     } else if ($mood=="sardonic") {
        $result= array_rand(array_flip(["DialoguePuzzled"]), 1);
         
         
     } else if ($mood=="irritated") {
        $result= array_rand(array_flip(["DialogueAnger"]), 1);
        
         
     } else if ($mood=="mocking") {
        $result= array_rand(array_flip(["DialogueHappy"]), 1);
         
         
     } else if ($mood=="playful") {
        $result= array_rand(array_flip(["DialogueHappy"]), 1);
             
     } else if ($mood=="teasing") {
        $result= array_rand(array_flip(["DialogueSurprise"]), 1);
         
         
     } else if ($mood=="smug") {
        $result= array_rand(array_flip(["DialogueAnger"]), 1);
         
         
     } else if ($mood=="amused") {
        $result= array_rand(array_flip(["DialogueSurprise"]), 1);
         
     } else if ($mood=="smirking") {
        $result= array_rand(array_flip(["DialogueHappy"]), 1);
     
         
     } else if ($mood=="serious") {
        $result= array_rand(array_flip(["MoodNeutral"]), 1);
     
         
     } else if ($mood=="firm") {
        $result= array_rand(array_flip(["MoodNeutral"]), 1);
     
         
     } if ($mood=="neutral") {
        $result= array_rand(array_flip(["MoodNeutral"]), 1);
         
         
     }
                             
     
     $GLOBALS["PATCH_ORIGINAL_MOOD_ISSUED"]=$mood;
     return $result;
     
 }

 

function isOk($arr) {
    if (is_array($arr))
        if (sizeof($arr)>0)
            return true;

    return false;
}

function getArrayKey($arr,$key) {
    if (is_array($arr))
        if (isset($arr[$key]))
            return $arr[$key];

    return false;
}

function createProfile($npcname, $FORCE_PARMS = [], $overwrite = false, $baseprofile = '')
{
    // This should be done at NpcMaster::createProfile
    global $db;

    if ($npcname == "The Narrator")   // Refuse to add Narrator [review this]
        return;

    $path = dirname((__FILE__)) . DIRECTORY_SEPARATOR . ".." . DIRECTORY_SEPARATOR;
    $newConfFile = md5($npcname);

    $codename = npcNameToCodename($npcname);
    $baseprofileName = npcNameToCodename($baseprofile);

    $npcMaster = new NpcMaster();
    $currentNpcData = $npcMaster->getByName($npcname);

    $EMPTY_PROFILE=false;

    if (!$currentNpcData || $overwrite ) {
        error_log("Creating/overwriting:$overwrite  profile for $npcname");
        //sleep (1);
        $npcTemlate = $db->fetchAll("SELECT core FROM combined_bio_templates where npc_name='$codename'");
        $npcNewFields = $db->fetchAll("SELECT npc_static_bio, personality, appearance, relationships, occupation, skills, speechstyle, goals, worldknowledge_tags, voiceid, gender, race, refid FROM combined_bio_templates where npc_name='$codename'");

        // 3. Extract the bracketed portion and convert it to the "stripped" version
        //    e.g. Ranger [NCR Patrol] -> ncr_patrol
        $bracketMatch = '';
        if (preg_match('/\[(.*?)\]/', $npcname, $matches)) {
            $bracketMatch = trim($matches[1]);    // remove possible extra spaces
            $bracketMatch = strtolower($bracketMatch);
            $bracketMatch = str_replace(' ', '_', $bracketMatch);
        }

        // Original logic for pulling from database
        if (isset($npcTemlate[0]) && is_array($npcTemlate[0])) {
                error_log("Creating from template ");

            // Build core from template core (or npc_pers in translated case)
            $coreFull = '';
            if (array_key_exists('core', $npcTemlate[0])) {
                $coreFull = trim((string)$npcTemlate[0]['core']);
            } elseif (array_key_exists('npc_pers', $npcTemlate[0])) {
                $coreFull = trim((string)$npcTemlate[0]['npc_pers']);
            }
            if ($coreFull === '') {
                // Fallback: minimal core
                $coreFull = trim($npcname);
            }

            $npcMaster->create([

                    "npc_name" => $npcname,
                    'npc_static_bio' => $npcNewFields[0]["npc_static_bio"] ?? '',
                    'personality' => $npcNewFields[0]["personality"] ?? '',
                    'core' => $coreFull,
                    'relationships' => $npcNewFields[0]["relationships"] ?? '',
                    'occupation' => $npcNewFields[0]["occupation"] ?? '',
                    'appearance' => $npcNewFields[0]["appearance"] ?? '',
                    'skills' => $npcNewFields[0]["skills"] ?? '',
                    'speechstyle' => $npcNewFields[0]["speechstyle"] ?? '',
                    'goals' => $npcNewFields[0]["goals"] ?? '',
                    'worldknowledge_tags' => $npcNewFields[0]["worldknowledge_tags"] ?? ''

                ]
            );

            // RealNamesExtended support for generic npcs
        } elseif (!empty($bracketMatch)) {
            
            // Query for new DIALECTIC fields for bracket match (bio tables)
            $npcNewFields2 = $db->fetchAll("SELECT npc_static_bio, personality, appearance, relationships, occupation, skills, speechstyle, goals, worldknowledge_tags, voiceid, gender, race, refid FROM combined_bio_templates WHERE npc_name='" . $db->escape($bracketMatch) . "'");
            $npcCore2 = $db->fetchAll("SELECT core FROM combined_bio_templates WHERE npc_name='" . $db->escape($bracketMatch) . "'");

            if (!empty($npcNewFields2[0])) {
                error_log("Creating from template bracketMatch");
                // Build core from template core
                $coreFull2 = '';
                if (!empty($npcCore2[0]) && array_key_exists('core', $npcCore2[0])) {
                    $coreFull2 = trim((string)$npcCore2[0]['core']);
                }
                if ($coreFull2 === '') { $coreFull2 = trim($npcname); }

                $npcMaster->create([
                        "npc_name" => $npcname,
                        'npc_static_bio' => $npcNewFields2[0]["npc_static_bio"] ?? '',
                        'personality' => $npcNewFields2[0]["personality"] ?? '',
                        'core' => $coreFull2,
                        'relationships' => $npcNewFields2[0]["relationships"] ?? '',
                        'occupation' => $npcNewFields2[0]["occupation"] ?? '',
                        'appearance' => $npcNewFields2[0]["appearance"] ?? '',
                        'skills' => $npcNewFields2[0]["skills"] ?? '',
                        'speechstyle' => $npcNewFields2[0]["speechstyle"] ?? '',
                        'goals' => $npcNewFields2[0]["goals"] ?? '',
                        'worldknowledge_tags' => $npcNewFields2[0]["worldknowledge_tags"] ?? ''
                    ]
                );
            } else {
                error_log("Creating initial empty profile");
                $npcMaster->create([
                        "npc_name" => $npcname
                    ]
                );
            }
        } else {
            error_log("Creating initial empty profile");
            $npcMaster->create([
                    "npc_name" => $npcname
                ]
            );
            $EMPTY_PROFILE=true;
            $newData = $npcMaster->GetByName($npcname);
            

        }

        // Populate voiceid for core_npc_master from imported structured fields.
        $voiceid = "";

        if (isset($npcNewFields) && isset($npcNewFields[0]["voiceid"]) && !empty($npcNewFields[0]["voiceid"])) {
            $voiceid = trim($npcNewFields[0]["voiceid"]);
        } else if (isset($npcNewFields2) && isset($npcNewFields2[0]["voiceid"]) && !empty($npcNewFields2[0]["voiceid"])) {
            $voiceid = trim($npcNewFields2[0]["voiceid"]);
        }

        $currentData = $npcMaster->GetByName($npcname);
        $currentData["voiceid"] = $voiceid;

        $existingMetadata = [];
        if (!empty($currentData['metadata'])) {
            $decodedMetadata = json_decode((string)$currentData['metadata'], true);
            if (is_array($decodedMetadata)) {
                $existingMetadata = $decodedMetadata;
            }
        }
        $currentData['metadata'] = json_encode($existingMetadata, JSON_UNESCAPED_UNICODE);

        $existingExtendedData = [];
        if (!empty($currentData['extended_data'])) {
            $decodedExtendedData = json_decode((string)$currentData['extended_data'], true);
            if (is_array($decodedExtendedData)) {
                $existingExtendedData = $decodedExtendedData;
            }
        }
        $existingExtendedData['dialectic_core_migrated'] = 2;
        $currentData['extended_data'] = json_encode($existingExtendedData, JSON_UNESCAPED_UNICODE);
        $defaultProfileId = 1;
        try {
            $coreProfile = new CoreProfile();
            $defaultProfile = $coreProfile->getDefaultNpc();
            if (is_array($defaultProfile) && !empty($defaultProfile['id'])) {
                $defaultProfileId = (int)$defaultProfile['id'];
            }
        } catch (Throwable $e) {
            error_log("[CREATEPROFILE] Could not resolve default NPC profile, falling back to profile #1: " . $e->getMessage());
        }

        $currentData['profile_id'] = $defaultProfileId;
        $currentData['md5'] = md5($currentData["npc_name"]);
        $currentData['gamets_last_updated'] = $GLOBALS["gameRequest"][2];

        if ($EMPTY_PROFILE) {
            error_log("[CREATEPROFILE] Created initial empty profile");
        }

        $npcMaster->updateByArray($currentData);
        return 1;
    }
    return 2;
}

function buildDynamicBiography(array $FOLLOWER_CONF, bool $forLetter = false, bool $forThought = false)
{
    /**
     * Build dynamic biography from structured DIALECTIC fields.
     * @param array $FOLLOWER_CONF Configuration array containing DIALECTIC fields
     * @param bool $forLetter If false, removes <letter_guidance> sections from DIALECTIC_SPEECHSTYLE
     * @param bool $forThought If false, removes <inner_thought_guidance> sections from DIALECTIC_SPEECHSTYLE
     * @return string The dynamic biography content
     */
    $dynamicBio = '';
    
    // Helper function to get item description from combined view
    $getItemDescription = function($itemName, $baseid = null) {
        global $db;
        
        // Try the shared runtime/stable/wildcard baseid resolver first if provided
        if (!empty($baseid)) {
            $record = lookupDescriptionByFormID((string) $baseid);
            if (!empty($record['description'])) {
                return $record['description'];
            }
        }
        
        // Fallback to name-based search
        if (!empty($itemName) && $itemName != '<Missing Name>') {
            $escapedName = $db->escape($itemName);
            $result = $db->fetchAll("SELECT description FROM combined_descriptions WHERE LOWER(name) = LOWER('{$escapedName}') LIMIT 1");
            if (!empty($result) && !empty($result[0]['description'])) {
                return $result[0]['description'];
            }
        }
        
        return null;
    };
    
    // List of new DIALECTIC fields to include
    $dialecticFields = [
        'DIALECTIC_BACKGROUND' => 'Basic Summary',
        'DIALECTIC_PERSONALITY' => 'Personality', 
        'DIALECTIC_APPEARANCE' => 'Appearance',
        'DIALECTIC_OCCUPATION' => 'Occupation',
        'DIALECTIC_SKILLS' => 'Skills',
        'DIALECTIC_SPEECHSTYLE' => 'Speech Style',
        'DIALECTIC_GOALS' => 'Goals'
    ];
    $hasStructuredBiographyFields = false;
    foreach (array_keys($dialecticFields) as $structuredFieldName) {
        if (isset($FOLLOWER_CONF[$structuredFieldName]) && !empty(trim((string)$FOLLOWER_CONF[$structuredFieldName]))) {
            $hasStructuredBiographyFields = true;
            break;
        }
    }
    $SKILLS_ADD="";
    $EQUIPMENT_ADD="";
    $TARGET_EQUIPMENT_ADD="";
    $STATS_ADD="";
    $ACTIVITY_ADD="";
    
    $npcMaster=new NpcMaster();
    $currentNpcData=$npcMaster->getByName($FOLLOWER_CONF["DIALECTIC_NAME"]);
    $metaData=$npcMaster->getMetaData($currentNpcData);
    $extendedData=$npcMaster->getExtendedData($currentNpcData);
    $activityStatus = dialecticNormalizeActivityStatus($metaData);

    if (dialecticPromptContextSectionEnabled('enabled_appearance_subsections', 'current_activity') && !empty($activityStatus['summary'])) {
        $ACTIVITY_ADD = "\n\n<activity>\n#Activity\n" . ucfirst($activityStatus['summary']) . ".\n</activity>\n";
    }
    
    if (isset($metaData["skills"])) {
        // Convert numeric Fallout skills to descriptive levels, grouped by category.
        $skillCategories = [
            'Combat' => ['guns', 'energy_weapons', 'explosives', 'melee_weapons', 'unarmed'],
            'Practical' => ['barter', 'lockpick', 'medicine', 'repair', 'science', 'survival'],
            'Social' => ['speech'],
            'Stealth' => ['sneak']
        ];
        
        $formattedSkills = "\n\nSkill Proficiencies:";
        
        foreach ($skillCategories as $category => $skillNames) {
            $categorySkills = [];
            foreach ($skillNames as $skillName) {
                if (isset($metaData["skills"][$skillName])) {
                    $skillValue = $metaData["skills"][$skillName];
                    $level = '';
                    if ($skillValue >= 100) {
                        $level = 'Master';
                    } elseif ($skillValue >= 75) {
                        $level = 'Expert';
                    } elseif ($skillValue >= 50) {
                        $level = 'Adept';
                    } elseif ($skillValue >= 25) {
                        $level = 'Apprentice';
                    } else {
                        $level = 'Novice';
                    }
                    
                    // Always show skills, including Novice
                    $categorySkills[] = ucfirst($skillName) . " (" . $level . ")";
                }
            }
            
            if (!empty($categorySkills)) {
                $formattedSkills .= "\n  - {$category}: " . implode(", ", $categorySkills);
            }
        }
        
        $SKILLS_ADD = $formattedSkills;
    } 
    
    // Add NPC's own equipment (skip for The Narrator - they don't need equipment context)
    if ($FOLLOWER_CONF["DIALECTIC_NAME"] !== "The Narrator" && isset($metaData["equipment"]) && is_array($metaData["equipment"])) {
        $equipmentParts = [];
        $describedBaseids = []; // Track which baseids we've already described
        $slots = [
            'helmet' => 'Helmet',
            'armor' => 'Armor', 
            'boots' => 'Boots',
            'gloves' => 'Gloves',
            'amulet' => 'Amulet',
            'ring' => 'Ring',
            'cape' => 'Cape',
            'backpack' => 'Backpack',
            'left_hand' => 'Left Hand',
            'right_hand' => 'Right Hand'
        ];
        
        foreach ($slots as $slot => $label) {
            if (!empty($metaData["equipment"][$slot])) {
                $itemName = $metaData["equipment"][$slot];
                
                // Skip blacklisted items
                if (isItemBlacklisted($itemName)) {
                    continue;
                }
                
                $baseid = isset($metaData["equipment"][$slot . '_baseid']) ? $metaData["equipment"][$slot . '_baseid'] : null;
                
                $itemLine = "  - {$label}: {$itemName}";
                
                // Try to add item description only if we haven't described this baseid yet
                if (!empty($baseid) && !in_array($baseid, $describedBaseids)) {
                    $description = $getItemDescription($itemName, $baseid);
                    if ($description) {
                        $itemLine .= " - {$description}";
                        $describedBaseids[] = $baseid; // Mark this baseid as described
                    }
                } elseif (empty($baseid)) {
                    // No baseid, try name-based (won't dedupe without baseid)
                    $description = $getItemDescription($itemName, null);
                    if ($description) {
                        $itemLine .= " - {$description}";
                    }
                }
                
                $equipmentParts[] = $itemLine;
            }
        }
        
        if (!empty($equipmentParts)) {
            $EQUIPMENT_ADD = "\n<equipment>\n#Current Equipment\nYou are currently wearing/wielding:\n" . implode("\n", $equipmentParts);
            
            // Check if humanoid NPC has no body armor - if so, note they're naked
            $humanoidRaces = ['human', 'caucasian', 'asian', 'hispanic', 'africanamerican', 'african american',
                            'ghoul', 'nonferalghoul', 'non-feral ghoul', 'non feral ghoul',
                            'supermutant', 'super mutant', 'nightkin', 'synth'];
            $npcRace = isset($currentNpcData["race"]) ? strtolower(trim($currentNpcData["race"])) : '';
            
            if ($npcRace && in_array($npcRace, $humanoidRaces) && empty($metaData["equipment"]["armor"])) {
                $EQUIPMENT_ADD .= "\nNote: You are naked (no body armor/clothing worn).";
            }
            
            $EQUIPMENT_ADD .= "\n</equipment>";
        }
    }

    if (
        $FOLLOWER_CONF["DIALECTIC_NAME"] !== "The Narrator" &&
        dialecticPromptContextSectionEnabled('enabled_appearance_subsections', 'equipment')
    ) {
        $structuredEquipmentBlock = dialecticBuildEquipmentBlockFromMetadata(
            $metaData,
            $getItemDescription,
            $FOLLOWER_CONF["DIALECTIC_NAME"] ?? ''
        );
        if ($structuredEquipmentBlock !== '') {
            $EQUIPMENT_ADD = $structuredEquipmentBlock;
        }
    } elseif (!dialecticPromptContextSectionEnabled('enabled_appearance_subsections', 'equipment')) {
        $EQUIPMENT_ADD = "";
    }

     // Add NPC's inventory (skip for The Narrator - they don't need inventory context)
    if ($FOLLOWER_CONF["DIALECTIC_NAME"] !== "The Narrator" && isset($metaData["inventory"]) && is_array($metaData["inventory"])) {
        if (!isset($describedBaseids)) {
            $describedBaseids = [];
        }
        $inventoryContext = dialecticBuildInventoryPromptContext(
            $metaData["inventory"],
            $getItemDescription,
            $describedBaseids,
            !empty($GLOBALS["INVENTORY_ITEMS_DESCRIPTIONS_ONLY"])
        );
        if ($inventoryContext !== '') {
            $INVENTORY_ADD = "\n" . $inventoryContext;
        }
    }
    
	// Add current condition (qualitative HP/AP based on percent, with richer descriptors)
	if (isset($metaData["stats"]) && is_array($metaData["stats"])) {
		$s = $metaData["stats"];
		$describe = function(string $kind, float $cur, float $max): string {
			if ($max <= 0) return "Unknown";
			$pct = ($cur < 0 ? 0.0 : ($cur > $max ? $max : $cur)) / $max * 100.0;
			if ($kind === 'health') {
				if ($pct >= 75.0) return "Near full health";           // 100-75
				if ($pct >= 50.0) return "Wounded";                    // 74-50
				if ($pct >= 25.0) return "Badly wounded";              // 50-25
				return "On the brink of collapse";                      // 24-0
			}
			if ($pct >= 75.0) return "Ready to act";
			if ($pct >= 50.0) return "Action points partly spent";
			if ($pct >= 25.0) return "Action points low";
			return "Nearly out of action points";
		};
		$h = $describe('health', (float)($s['health'] ?? 0), (float)($s['health_max'] ?? 0));
		$ap = $describe('action_points', (float)($s['action_points'] ?? 0), (float)($s['action_points_max'] ?? 0));
		if ($h !== 'Unknown' || $ap !== 'Unknown') {
			$lines = [];
			if ($h !== 'Unknown') { $lines[] = "  - Health: {$h}"; }
			if ($ap !== 'Unknown') { $lines[] = "  - Action Points: {$ap}"; }
			if (!empty($lines)) {
				$STATS_ADD = "\n\n<condition>\n#Condition\n" . implode("\n", $lines)."\n</condition>\n";
			}
		}
	}
    
    $conditionLines = dialecticPromptContextSectionEnabled('enabled_appearance_subsections', 'current_condition')
        ? dialecticBuildCurrentConditionLinesFromMetadata($metaData["stats"] ?? null, $metaData)
        : [];
    if (!empty($conditionLines)) {
        $STATS_ADD = "\n\n<condition>\n#Condition\n" . implode("\n", $conditionLines)."\n</condition>\n";
    } else {
        $STATS_ADD = "";
    }
    // Add dialogue target's equipment (if DIALOGUE_TARGET is set)
    if (isset($GLOBALS["DIALOGUE_TARGET"]) && !empty($GLOBALS["DIALOGUE_TARGET"])) {
        $targetName = $GLOBALS["DIALOGUE_TARGET"];
        $targetNpcData = $npcMaster->getByName($targetName);
        
        if ($targetNpcData) {
            $targetMetaData = $npcMaster->getMetaData($targetNpcData);
            
            if (isset($targetMetaData["equipment"]) && is_array($targetMetaData["equipment"])) {
                $targetEquipmentParts = [];
                $slots = [
                    'helmet' => 'Helmet',
                    'armor' => 'Armor',
                    'boots' => 'Boots', 
                    'gloves' => 'Gloves',
                    'amulet' => 'Amulet',
                    'ring' => 'Ring',
                    'left_hand' => 'Left Hand',
                    'right_hand' => 'Right Hand'
                ];
                
                foreach ($slots as $slot => $label) {
                    if (!empty($targetMetaData["equipment"][$slot])) {
                        $targetEquipmentParts[] = "  - {$label}: {$targetMetaData["equipment"][$slot]}";
                    }
                }
                
                if (!empty($targetEquipmentParts)) {
                    $TARGET_EQUIPMENT_ADD = "\n<target_equipment>\n#{$targetName}'s Equipment\n{$targetName} is currently wearing/wielding:\n" . implode("\n", $targetEquipmentParts);
                    
                    // Check if humanoid NPC has no body armor - if so, note they're naked
                    $humanoidRaces = ['human', 'caucasian', 'asian', 'hispanic', 'africanamerican', 'african american',
                                    'ghoul', 'nonferalghoul', 'non-feral ghoul', 'non feral ghoul',
                                    'supermutant', 'super mutant', 'nightkin', 'synth'];
                    $targetRace = isset($targetNpcData["race"]) ? strtolower(trim($targetNpcData["race"])) : '';
                    
                    if ($targetRace && in_array($targetRace, $humanoidRaces) && empty($targetMetaData["equipment"]["armor"])) {
                        $TARGET_EQUIPMENT_ADD .= "\nNote: {$targetName} is naked (no body armor/clothing worn).";
                    }
                    
                    $TARGET_EQUIPMENT_ADD .= "\n</target_equipment>\n";
                }
            }

            if (dialecticPromptContextSectionEnabled('enabled_appearance_subsections', 'target_equipment')) {
                $targetEquipmentLines = dialecticBuildEquipmentLinesFromMetadata($targetMetaData, $getItemDescription);
                if (!empty($targetEquipmentLines)) {
                    $TARGET_EQUIPMENT_ADD = "\n<target_equipment>\n#{$targetName}'s Equipment\n{$targetName} is currently wearing/wielding:\n" . implode("\n", $targetEquipmentLines) . "\n</target_equipment>\n";
                }
            } else {
                $TARGET_EQUIPMENT_ADD = "";
            }
        }
    }

    $appearanceContextAdded = false;

    foreach ($dialecticFields as $fieldName => $label) {
        if (isset($FOLLOWER_CONF[$fieldName]) && !empty(trim($FOLLOWER_CONF[$fieldName]))) {
            $xmlLabel=strtr(strtolower($label),[" "=>"_"]);
            $fieldValue = trim($FOLLOWER_CONF[$fieldName]);

            // Apply conditional XML tag removal for DIALECTIC_SPEECHSTYLE field
            if ($fieldName === 'DIALECTIC_SPEECHSTYLE') {
                if (!$forLetter) {
                    // Remove <letter_guidance>...</letter_guidance> and its content
                    $fieldValue = preg_replace('/<letter_guidance>.*?<\/letter_guidance>/is', '', $fieldValue);
                }
                if (!$forThought) {
                    // Remove <inner_thought_guidance>...</inner_thought_guidance> and its content
                    $fieldValue = preg_replace('/<inner_thought_guidance>.*?<\/inner_thought_guidance>/is', '', $fieldValue);
                }
                // Clean up any excessive whitespace left after removal
                $fieldValue = trim(preg_replace('/\n{3,}/', "\n\n", $fieldValue));
            }


            $dynamicBio .= "\n<$xmlLabel>\n" . $fieldValue ."\n</$xmlLabel>";
            
            // Add groups (factions) right after DIALECTIC_BACKGROUND (basic_summary) section
            if ($fieldName=="DIALECTIC_BACKGROUND") {
                $extendedData = $npcMaster->getExtendedData($currentNpcData);
                if (isset($extendedData['factions']) && is_array($extendedData['factions']) && count($extendedData['factions']) > 0) {
                    $factionLines = [];
                    foreach ($extendedData['factions'] as $faction) {
                        if (isset($faction['formid'])) {
                            // Lookup faction using helper function (supports XX prefix)
                            $factionRecord = lookupDescriptionByFormID($faction['formid']);
                            
                            // Only add to prompt if found in descriptions table
                            if ($factionRecord && !empty($factionRecord['name'])) {
                                $factionName = $factionRecord['name'];
                                $factionDesc = !empty($factionRecord['description']) ? $factionRecord['description'] : '';
                                $factionLines[] = "{$factionName} - {$factionDesc}";
                            }
                        }
                    }
                    
                    if (count($factionLines) > 0) {
                        $dynamicBio .= "\n<groups>\nYou belong to these factions:\n" . implode("\n", $factionLines) . "\n</groups>";
                    }
                }
            }
            
            // Add skills right after DIALECTIC_SKILLS section
            if ($fieldName=="DIALECTIC_SKILLS") {
                $dynamicBio.=!empty($SKILLS_ADD) ?"\n<rpg_skills>\n$SKILLS_ADD\n</rpg_skills>\n": "";
            }
            
        }

        if ($fieldName=="DIALECTIC_APPEARANCE" && $hasStructuredBiographyFields) {
            $appearanceContextAdded = true;
            $dynamicBio.=$EQUIPMENT_ADD ?? "";
            $dynamicBio.=$TARGET_EQUIPMENT_ADD ?? "";
            $dynamicBio.=$INVENTORY_ADD ?? "";
            $dynamicBio.=$ACTIVITY_ADD ?? "";
            $dynamicBio.=$STATS_ADD ?? "";
        }
    }
    
    

    if (!$appearanceContextAdded) {
        $dynamicBio .= $EQUIPMENT_ADD ?? "";
        $dynamicBio .= $TARGET_EQUIPMENT_ADD ?? "";
        $dynamicBio .= $INVENTORY_ADD ?? "";
        $dynamicBio .= $ACTIVITY_ADD ?? "";
        $dynamicBio .= $STATS_ADD ?? "";
    }
    
    if (isset($GLOBALS["HOOKS"]["BIOGRAPHY_BUILDER"])) {
        foreach ($GLOBALS["HOOKS"]["BIOGRAPHY_BUILDER"] as $fName => $builder) {
            error_log("[buildDynamicBiography] BIOGRAPHY_BUILDER {$fName}");

            if (!is_callable($builder)) {
                error_log("[buildDynamicBiography] Builder {$fName} is not callable, skipping.");
                continue;
            }

            // Call the builder. Support both styles:
            //  - builder returns a new bio string
            //  - builder modifies the first argument by-reference
            // We call with call_user_func_array and pass $dynamicBio by-reference so
            // builders that accept a reference can mutate it directly. If the builder
            // returns a non-empty string, prefer that return value as the new bio.
            $result = null;
            try {
                $result = call_user_func_array($builder, array(&$dynamicBio, $currentNpcData));
            } catch (Throwable $e) {
                // Protect against hook errors - log and continue with current bio
                error_log("[buildDynamicBiography] Exception in builder {$fName}: " . $e->getMessage());
                continue;
            }

            if (is_string($result) && strlen(trim($result)) > 0) {
                // Builder returned a non-empty string -> use it
                $dynamicBio = $result;
            }
            // otherwise assume builder mutated $dynamicBio by reference (or left it unchanged)
        }
    }

    return $dynamicBio;
}

function buildDynamicProfileDisplay() {
    /**
     * Build formatted dynamic profile display for profile updates
     * @return string The formatted dynamic profile content
     */
    $currentDynamicProfile = '';
    $dialecticFields = [
        'DIALECTIC_BACKGROUND' => 'Background',
        'DIALECTIC_PERSONALITY' => 'Personality', 
        'DIALECTIC_APPEARANCE' => 'Appearance',
        'DIALECTIC_OCCUPATION' => 'Occupation',
        'DIALECTIC_SKILLS' => 'Skills',
        'DIALECTIC_SPEECHSTYLE' => 'Speech Style',
        'DIALECTIC_GOALS' => 'Goals'
    ];
    
    foreach ($dialecticFields as $fieldName => $label) {
        if (isset($GLOBALS[$fieldName]) && !empty(trim($GLOBALS[$fieldName]))) {
            $currentDynamicProfile .= "\n$label:\n" . trim($GLOBALS[$fieldName]) . "\n";
        }
    }
    
    if (empty(trim($currentDynamicProfile))) {
        $currentDynamicProfile = "No dynamic profile information available.";
    }
    
    return $currentDynamicProfile;
}


/**
 * Parses a PHP file and extracts variable assignments into an associative array.
 *
 * Handles:
 * - Scalars: $name = 'Dialectic';
 * - Arrays: $data = ["a", "b"];
 * - Nested array keys: $a["x"]["y"] = 123;
 *
 * All values are returned in raw form (e.g., quoted strings are unquoted).
 *
 * @param string $filePath Path to the PHP file to parse.
 * @return array Associative array of variable names (or paths) => raw values.
 */
function extract_assignments($filePath) {
    $code = file_get_contents($filePath);
    $tokens = token_get_all($code);

    $variables = [];
    $varName = '';
    $indexStack = [];
    $collectValue = false;
    $valueBuffer = '';
    $bracketDepth = 0;
    $expectingKey = false;

    foreach ($tokens as $i => $token) {
        if (is_array($token)) {
            [$id, $text] = $token;

            if ($id === T_VARIABLE) {
                $varName = substr($text, 1);
                $indexStack = [];
                $collectValue = false;
                $valueBuffer = '';
                $bracketDepth = 0;
                $expectingKey = false;
            }

            elseif ($expectingKey && in_array($id, [T_CONSTANT_ENCAPSED_STRING, T_STRING, T_LNUMBER, T_DNUMBER])) {
                $indexStack[] = trim($text, "'\"");
                $expectingKey = false;
            }

            elseif ($collectValue) {
                $valueBuffer .= $text;
            }

        } else {
            // Symbolic tokens
            if ($token === '[' && !$collectValue) {
                $expectingKey = true;
            }

            elseif ($token === '=' && !$collectValue) {
                $collectValue = true;
                $valueBuffer = '';
                $bracketDepth = 0;
            }

            elseif ($collectValue) {
                if ($token === '[') {
                    $bracketDepth++;
                    $valueBuffer .= $token;
                } elseif ($token === ']') {
                    $bracketDepth--;
                    $valueBuffer .= $token;
                } elseif (($token === ';' || $token === ',') && $bracketDepth === 0) {
                    // Don't add the terminating character to the buffer
                    $rawValue = trim($valueBuffer);

                    // Remove quotes and unescape if present
                    if (strlen($rawValue) >= 2) {
                        if ($rawValue[0] === '"' && $rawValue[-1] === '"') {
                            // Double-quoted string - remove quotes and unescape
                            $rawValue = stripcslashes(substr($rawValue, 1, -1));
                        } elseif ($rawValue[0] === "'" && $rawValue[-1] === "'") {
                            // Single-quoted string - remove quotes and unescape single quotes and backslashes
                            $rawValue = substr($rawValue, 1, -1);
                            $rawValue = str_replace(["\\'", "\\\\"], ["'", "\\"], $rawValue);
                        }
                    }

                    // Build full key
                    $fullKey = $varName;
                    foreach ($indexStack as $key) {
                        $fullKey .= "['$key']";
                    }

                    $variables[$fullKey] = $rawValue;

                    // Reset state
                    $collectValue = false;
                    $valueBuffer = '';
                    $indexStack = [];
                } else {
                    $valueBuffer .= $token;
                }
            }

            // Reset expectingKey if we see closing bracket
            if ($token === ']' && !$collectValue) {
                $expectingKey = false;
            }
        }
    }

    return $variables;
}


/**
 * Writes variable assignments to a PHP file using clean formatting.
 *
 * Accepts keys like 'VAR' or 'ARRAY["KEY"]["SUB"]' and writes them back to PHP code.
 * Automatically quotes strings, and leaves numbers, booleans, null, and arrays untouched.
 *
 * @param array $assignments The variable assignments, as [name => raw_value]
 * @param string $filePath Path to save the output PHP code
 */
function write_php_assignments(array $assignments, string $filePath): bool {
    $output = "<?php\n\n";

    foreach ($assignments as $key => $value) {
        // Clean the value - remove trailing semicolons and trim whitespace
        $cleaned = rtrim(trim($value), ';');
        
        // If the value is already quoted, unquote it first
        if (strlen($cleaned) >= 2) {
            if (($cleaned[0] === '"' && $cleaned[-1] === '"') || 
                ($cleaned[0] === "'" && $cleaned[-1] === "'")) {
                $cleaned = substr($cleaned, 1, -1);
            }
        }
        
        // Now determine the correct output format based on the cleaned value
        $lowerCleaned = strtolower($cleaned);
        
        if (in_array($lowerCleaned, ['true', 'false', 'null'], true)) {
            // Boolean or null values - output as-is
            $finalValue = $lowerCleaned;
        } elseif (is_numeric($cleaned) && !str_contains($cleaned, ' ')) {
            // Numeric values - output as-is
            $finalValue = $cleaned;
        } elseif (preg_match('/^\s*\[.*\]\s*$/s', $cleaned)) {
            // Array literals - output as-is
            $finalValue = $cleaned;
        } else {
            // String values - apply comprehensive sanitization for AI-generated content
            if (is_string($cleaned)) {
                // Sanitize AI-generated content to prevent PHP syntax errors
                $cleaned = str_replace("\0", '', $cleaned); // Remove null bytes
                $cleaned = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $cleaned); // Remove control chars
                if (!mb_check_encoding($cleaned, 'UTF-8')) {
                    $cleaned = mb_convert_encoding($cleaned, 'UTF-8', 'UTF-8'); // Fix encoding
                }
                if (strlen($cleaned) > 100000) {
                    $cleaned = substr($cleaned, 0, 100000) . '... [truncated]'; // Limit length
                }
                $cleaned = str_replace(['<?php', '<?', '?>'], ['&lt;?php', '&lt;?', '?&gt;'], $cleaned); // Escape PHP tags
                
                // Additional sanitization for var_export compatibility
                $cleaned = str_replace('\\', '\\\\', $cleaned); // Escape backslashes
                $cleaned = str_replace("\r\n", "\n", $cleaned); // Normalize line endings
                $cleaned = str_replace("\r", "\n", $cleaned); // Convert Mac line endings
                $cleaned = preg_replace('/\n{3,}/', "\n\n", $cleaned); // Limit consecutive newlines
            }
            
            // Use var_export for safer escaping instead of addslashes
            $finalValue = var_export($cleaned, true);
        }

        // Build the assignment line
        if (strpos($key, '[') !== false) {
            $line = "\${$key} = {$finalValue};";
        } else {
            $line = "\$$key = {$finalValue};";
        }

        $output .= $line . "\n";
    }

    return file_put_contents($filePath, $output, LOCK_EX);
}

function getInGameSkillDataFor($npcName) {

    $npcEscapedName=$GLOBALS["db"]->escape($npcName);
    $query="
WITH npc_weapons AS (
  SELECT
    TRIM(SUBSTRING(data FROM 'using weapon\s+(.+)$')) AS weapon
  FROM public.eventlog
  WHERE type = 'death' AND data LIKE '%$npcEscapedName has defeated%'
)

SELECT
  'weapon' AS type,
  weapon AS item,
  COUNT(*) AS usage_count
FROM npc_weapons
where weapon is not null
GROUP BY weapon
HAVING COUNT(*)>1

ORDER BY type, usage_count DESC;
";
    $skillsData=$GLOBALS["db"]->fetchAll($query);

    if (sizeof ($skillsData)==0)
        return "";

    $weapons = [];

    foreach ($skillsData as $entry) {
        if ($entry['type'] === 'weapon') {
            $weapons[] = $entry['item'];
        }
    }

    $weaponsList = sizeof($weapons)>0?implode(', ', $weapons):"none";
    

    return "* Fav. Weapons: $weaponsList\n";
}

/**
 * Safely export a value to PHP code with comprehensive sanitization to prevent syntax errors
 * 
 * This function sanitizes AI-generated content to prevent PHP syntax errors that can occur
 * with standard var_export() when dealing with special characters, encoding issues, etc.
 * 
 * @param mixed $value The value to export
 * @param bool $return Whether to return the string instead of outputting it
 * @return string|null The exported PHP code
 */
function safe_var_export($value, $return = true) {
    // First, sanitize string values
    if (is_string($value)) {
        // Remove null bytes that can break PHP parsing
        $value = str_replace("\0", '', $value);
        
        // Ensure valid UTF-8 encoding
        if (!mb_check_encoding($value, 'UTF-8')) {
            $value = mb_convert_encoding($value, 'UTF-8', 'UTF-8');
        }
        
        // Remove or replace problematic characters
        $value = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $value);
        
        // Limit length to prevent extremely long strings
        if (strlen($value) > 100000) {
            $value = substr($value, 0, 100000) . '... [truncated]';
        }
        
        // Ensure balanced quotes and backslashes don't break escaping
        $value = str_replace(['\\', "'", '"'], ['\\\\', "\\'", '\\"'], $value);
        $value = stripslashes($value); // Remove double escaping
    }
    
    // Try var_export first
    $exported = var_export($value, true);
    
    // Validate that the exported code is syntactically correct
    $testCode = "<?php return $exported; ?>";
    
    // Use eval to test syntax (in a safe way)
    $oldLevel = error_reporting(0);
    $syntaxValid = @eval("return true; $testCode") !== false;
    error_reporting($oldLevel);
    
    if (!$syntaxValid) {
        // Fallback: manual string escaping for safety
        if (is_string($value)) {
            $exported = "'" . addcslashes($value, "'\\") . "'";
        } else {
            // For non-strings, convert to string safely
            $exported = "'" . addcslashes((string)$value, "'\\") . "'";
        }
    }
    
    if ($return) {
        return $exported;
    } else {
        echo $exported;
        return null;
    }
}

/**
 * Safely update a PHP configuration file variable with proper error handling
 * 
 * @param string $filePath Path to the PHP file
 * @param string $varName Variable name (without $)
 * @param mixed $value New value
 * @return array Result with success status and message
 */
function safe_update_php_variable($filePath, $varName, $value) {
    if (!file_exists($filePath)) {
        return ["success" => false, "error" => "File not found: " . basename($filePath)];
    }
    
    // Read current content
    $content = file_get_contents($filePath);
    if ($content === false) {
        return ["success" => false, "error" => "Cannot read file: " . basename($filePath)];
    }
    
    // Use safe export
    $escapedValue = safe_var_export($value, true);
    
    // Validate the escaped value produces valid PHP
    $testAssignment = "\$$varName = $escapedValue;";
    $testCode = "<?php $testAssignment ?>";
    
    $oldLevel = error_reporting(0);
    $syntaxValid = @eval("return true; $testCode") !== false;
    error_reporting($oldLevel);
    
    if (!$syntaxValid) {
        return ["success" => false, "error" => "Generated PHP code would be invalid for variable $varName"];
    }
    
    // Update or add variable
    $pattern = '/\$' . preg_quote($varName, '/') . '\s*=\s*[^;]+;/';
    if (preg_match($pattern, $content)) {
        $content = preg_replace($pattern, '$' . $varName . '=' . $escapedValue . ';', $content);
    } else {
        // Add before closing 
        $content = str_replace('?>', '$' . $varName . '=' . $escapedValue . ';' . PHP_EOL . '?>', $content);
    }
    
    // Write with atomic operation
    $tempFile = $filePath . '.tmp.' . uniqid();
    if (file_put_contents($tempFile, $content, LOCK_EX) === false) {
        return ["success" => false, "error" => "Cannot write to temporary file"];
    }
    
    if (!rename($tempFile, $filePath)) {
        unlink($tempFile);
        return ["success" => false, "error" => "Cannot update file: " . basename($filePath)];
    }
    
    return ["success" => true, "message" => "Variable $varName updated successfully"];
}


/**
 * Retrieves base data for an NPC from the current NPC profile table.
 *
 * If the NPC name is empty or no matching profile row is found, the function returns null.
 *
 * @param string $npcname The name of the NPC to retrieve data for.
 * @return array|null An associative array containing 'gender', 'race', and 'refid' keys,
 *                    or null if no valid data is found.
 */
function getBaseDataForNpcFromLog($npcname) {
    if (empty($npcname)) {
        error_log("getBaseDataForNpcFromLog: NPC name is empty.");
        return null;
    }

    $npcNameEscaped = $GLOBALS["db"]->escape($npcname);
    $result = $GLOBALS["db"]->fetchOne("
        SELECT gender, race, refid
        FROM core_npc_master
        WHERE npc_name = '$npcNameEscaped'
        ORDER BY id DESC
        LIMIT 1
    ");

    if (!$result) {
        error_log("getBaseDataForNpcFromLog: No data found for NPC '$npcname'.");
        return null;
    }

    $currentNpcData = [
        "gender" => trim((string)($result["gender"] ?? "")),
        "race" => trim((string)($result["race"] ?? "")),
        "refid" => trim((string)($result["refid"] ?? ""))
    ];

    return $currentNpcData;
}

