<?php

// Functions to be provided to OpenAI
$startTime=$GLOBALS["startTime"] ?? microtime(true);

if (!function_exists('dialecticTraceFunctionsIncludePhase')) {
    function dialecticTraceFunctionsIncludePhase($line, $label, $startTime)
    {
        // error_log("TRACE:\t{$line}\t".__FILE__.":\t".(microtime(true) - $startTime)."\t{$label}");
    }
}

error_log("TRACE:\t".__LINE__. "\t".__FILE__.":\t".(microtime(true) - $startTime));

function dialecticCanonicalActionCodes()
{
    return [
        'Attack',
        'CheckInventory',
        'ComeCloser',
        'Consume',
        'EquipItem',
        'IncreaseWalkSpeed',
        'DecreaseWalkSpeed',
        'EndConversation',
        'Follow',
        'FollowPlayer',
        'GiveItemTo',
        'GiveCapsTo',
        'Inspect',
        'InspectSurroundings',
        'MakeFollower',
        'MoveTo',
        'PickupItem',
        'ReadQuests',
        'Relax',
        'DirectorCommand',
        'SpawnCaps',
        'SpawnItem',
        'TeleportActor',
        'KillTarget',
        'SheatheWeapon',
        'StopFollowing',
        'StopWalk',
        'TakeASeat',
        'TakeCapsFromPlayer',
        'Barter',
        'OpenInventory',
        'TravelTo',
        'UnequipItem',
        'WaitHere',
    ];
}

function dialecticCanonicalActionCodeSet()
{
    static $set = null;
    if ($set === null) {
        $set = array_fill_keys(dialecticCanonicalActionCodes(), true);
    }
    return $set;
}

function dialecticNormalizeActionCodeName($codeName)
{
    $codeName = trim(strval($codeName));
    $aliases = [
        'ReadQuestJournal' => 'ReadQuests',
        'TradeItems' => 'OpenInventory',
        'ExchangeItems' => 'OpenInventory',
        'AcceptGift' => 'OpenInventory',
        'StopFollow' => 'StopFollowing',
        'DismissFollower' => 'StopFollowing',
        'LeaveParty' => 'StopFollowing',
    ];
    return $aliases[$codeName] ?? $codeName;
}

function dialecticFilterCanonicalActionCodeList($codes)
{
    $filtered = [];
    $allowed = dialecticCanonicalActionCodeSet();
    foreach ((array) $codes as $codeName) {
        $normalizedCodeName = dialecticNormalizeActionCodeName($codeName);
        if (isset($allowed[$normalizedCodeName])) {
            $filtered[$normalizedCodeName] = $normalizedCodeName;
        }
    }
    return array_values($filtered);
}

function dialecticFilterCanonicalActionMap($map)
{
    $filtered = [];
    $allowed = dialecticCanonicalActionCodeSet();
    foreach ((array) $map as $codeName => $value) {
        $normalizedCodeName = dialecticNormalizeActionCodeName($codeName);
        if (isset($allowed[$normalizedCodeName]) && ($codeName === $normalizedCodeName || !isset($filtered[$normalizedCodeName]))) {
            $filtered[$normalizedCodeName] = $value;
        }
    }
    return $filtered;
}

$ENABLED_FUNCTIONS_LOCAL = dialecticCanonicalActionCodes();

$GLOBALS["ENABLED_FUNCTIONS"] = $ENABLED_FUNCTIONS_LOCAL;

dialecticTraceFunctionsIncludePhase(__LINE__, 'enabled_functions_initialized', $startTime);

// Ensure PLAYER_NAME is defined before use in string templates below.
// Prefer database (conf_opts) value; fallback to existing global or 'Player'.
if (!isset($GLOBALS["PLAYER_NAME"]) || $GLOBALS["PLAYER_NAME"] === '') {
    $safePlayerName = 'Player';
    try {
        $rootPath = __DIR__ . DIRECTORY_SEPARATOR . ".." . DIRECTORY_SEPARATOR;
        dialecticTraceFunctionsIncludePhase(__LINE__, 'player_name_bootstrap_require_start', $startTime);
        require_once $rootPath . "lib" . DIRECTORY_SEPARATOR . "runtime_bootstrap.php";
        dialecticTraceFunctionsIncludePhase(__LINE__, 'player_name_bootstrap_require_done', $startTime);
        dialecticTraceFunctionsIncludePhase(__LINE__, 'player_name_bootstrap_run_start', $startTime);
        dialecticRuntimeBootstrapIfNeeded($rootPath, [
            'run_db_updates' => false,
            'load_general_settings' => false,
            'load_stt_connector' => false,
            'load_player_name' => true,
        ]);
        dialecticTraceFunctionsIncludePhase(__LINE__, 'player_name_bootstrap_run_done', $startTime);
        if (isset($GLOBALS["PLAYER_NAME"]) && $GLOBALS["PLAYER_NAME"] !== '') {
            $safePlayerName = (string)$GLOBALS["PLAYER_NAME"];
        }
    } catch (Throwable $_) {
        // ignore and use fallback
    }
    $GLOBALS["PLAYER_NAME"] = $safePlayerName;
}

dialecticTraceFunctionsIncludePhase(__LINE__, 'player_name_ready', $startTime);

require_once __DIR__ . DIRECTORY_SEPARATOR . ".." . DIRECTORY_SEPARATOR . "lib" . DIRECTORY_SEPARATOR . "core" . DIRECTORY_SEPARATOR . "action_catalog.php";

dialecticTraceFunctionsIncludePhase(__LINE__, 'action_catalog_required', $startTime);

function decodeFunctionExecutionParameterPayload($parameter)
{
    if (is_array($parameter)) {
        return $parameter;
    }

    $text = trim(strval($parameter));
    if ($text === '' || $text[0] !== '{') {
        return null;
    }

    $decoded = json_decode($text, true);
    return is_array($decoded) ? $decoded : null;
}

function buildTravelExecutionParameter($parameter, $amount)
{
    $payload = decodeFunctionExecutionParameterPayload($parameter);
    if (!is_array($payload)) {
        $payload = [];
    }

    if (!isset($payload["target"]) || trim(strval($payload["target"])) === "") {
        $payload["target"] = is_array($parameter) ? "" : trim(strval($parameter));
    }

    $payload["amount"] = intval($amount);

    return json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
}

function dialecticFormatActionFormIdHex($formId)
{
    if (is_int($formId) || is_float($formId)) {
        return sprintf('0x%08X', intval($formId) & 0xFFFFFFFF);
    }

    $text = trim(strval($formId));
    if ($text === '') {
        return '';
    }

    if (preg_match('/^0x([0-9a-f]+)$/i', $text, $matches)) {
        return '0x' . strtoupper(str_pad(substr($matches[1], -8), 8, '0', STR_PAD_LEFT));
    }

    if (preg_match('/^[0-9a-f]{8}$/i', $text)) {
        return '0x' . strtoupper($text);
    }

    if (preg_match('/^\d+$/', $text)) {
        return sprintf('0x%08X', intval($text) & 0xFFFFFFFF);
    }

    $hex = preg_replace('/[^0-9a-f]/i', '', $text) ?? '';
    if ($hex === '') {
        return '';
    }

    return '0x' . strtoupper(str_pad(substr($hex, -8), 8, '0', STR_PAD_LEFT));
}

function dialecticCompactLocationSearchText($value)
{
    return strtolower(preg_replace('/[^a-z0-9]+/i', '', strval($value)) ?? '');
}

function dialecticResolveLocationForTravelAction($destinationName)
{
    $destinationName = trim(str_replace('@', ' ', strval($destinationName)));
    if ($destinationName === '' || !isset($GLOBALS["db"])) {
        return null;
    }

    $db = $GLOBALS["db"];
    $escapedDestination = method_exists($db, 'escape')
        ? $db->escape($destinationName)
        : str_replace("'", "''", $destinationName);

    try {
        $row = $db->fetchOne(
            "SELECT name, formid, worldspace, " .
            "GREATEST(" .
            "CASE WHEN lower(COALESCE(name, '')) = lower('{$escapedDestination}') THEN 1.0 ELSE similarity(COALESCE(name, ''), '{$escapedDestination}') END, " .
            "similarity(COALESCE(worldspace, ''), '{$escapedDestination}')" .
            ") AS sim, " .
            "CASE " .
            "WHEN lower(COALESCE(name, '')) = lower('{$escapedDestination}') THEN 3 " .
            "WHEN lower(COALESCE(worldspace, '')) = lower('{$escapedDestination}') THEN 2 " .
            "ELSE 0 END AS exact_rank " .
            "FROM public.locations " .
            "WHERE COALESCE(name, '') <> '' " .
            "ORDER BY exact_rank DESC, sim DESC, name ASC " .
            "LIMIT 1"
        );
    } catch (Throwable $e) {
        Logger::warn("TravelTo location lookup failed: " . $e->getMessage());
        return null;
    }

    if (!is_array($row) || !isset($row["formid"])) {
        return null;
    }

    $resolvedName = trim(strval($row["name"] ?? ''));
    $worldspace = trim(strval($row["worldspace"] ?? ''));
    $similarity = floatval($row["sim"] ?? 0);
    $destinationCompact = dialecticCompactLocationSearchText($destinationName);
    $nameCompact = dialecticCompactLocationSearchText($resolvedName);
    $worldspaceCompact = dialecticCompactLocationSearchText($worldspace);
    $compactMatch = $destinationCompact !== '' && (
        ($nameCompact !== '' && (strpos($nameCompact, $destinationCompact) !== false || strpos($destinationCompact, $nameCompact) !== false)) ||
        ($worldspaceCompact !== '' && (strpos($worldspaceCompact, $destinationCompact) !== false || strpos($destinationCompact, $worldspaceCompact) !== false))
    );
    $exactRank = intval($row["exact_rank"] ?? 0);
    if ($exactRank <= 0 && !$compactMatch && $similarity < 0.12) {
        return null;
    }

    $formIdHex = dialecticFormatActionFormIdHex($row["formid"]);
    if ($formIdHex === '') {
        return null;
    }

    return [
        'name' => $resolvedName !== '' ? $resolvedName : $destinationName,
        'worldspace' => $worldspace,
        'formid' => intval($row["formid"]),
        'formid_hex' => $formIdHex,
        'similarity' => $similarity,
    ];
}

function dialecticBuildTravelToActionPayload($destinationName, $existingPayload = null)
{
    $payload = is_array($existingPayload) ? $existingPayload : [];
    $destinationName = trim(str_replace('@', ' ', strval($destinationName)));

    if ($destinationName === '') {
        $destinationName = trim(strval($payload["location"] ?? ($payload["target"] ?? '')));
    }

    if ($destinationName === '') {
        return json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    $payload["location"] = trim(strval($payload["location"] ?? '')) !== ''
        ? trim(strval($payload["location"]))
        : $destinationName;
    $payload["target"] = trim(strval($payload["target"] ?? '')) !== ''
        ? trim(strval($payload["target"]))
        : $destinationName;

    $resolvedLocation = dialecticResolveLocationForTravelAction($destinationName);
    if (is_array($resolvedLocation)) {
        $payload["location"] = $resolvedLocation["name"];
        $payload["target"] = $resolvedLocation["name"];
        $payload["target_refid"] = $resolvedLocation["formid_hex"];
        $payload["target_formid"] = $resolvedLocation["formid_hex"];
        Logger::info("TravelTo resolved location" . Logger::formatContext([
            "requested" => $destinationName,
            "resolved" => $resolvedLocation["name"],
            "formid" => $resolvedLocation["formid_hex"],
            "similarity" => $resolvedLocation["similarity"],
        ]));
    } else {
        Logger::debug("TravelTo location left unresolved" . Logger::formatContext([
            "requested" => $destinationName,
        ]));
    }

    return json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
}

function dialecticEnrichTravelToExecutionContext(&$executionContext)
{
    $functionCodeName = trim(strval($executionContext["function_code_name"] ?? ""));
    if ($functionCodeName !== "TravelTo") {
        return;
    }

    $parameterValue = $executionContext["parameter_value"] ?? [];
    $payload = decodeFunctionExecutionParameterPayload($parameterValue);
    if (!is_array($payload)) {
        $payload = decodeFunctionExecutionParameterPayload($executionContext["parameter_string"] ?? '');
    }
    if (!is_array($payload)) {
        $payload = [];
    }

    $destinationName = trim(strval($payload["location"] ?? ($payload["target"] ?? '')));
    if ($destinationName === '' && !is_array($parameterValue)) {
        $destinationName = trim(strval($parameterValue));
    }
    if ($destinationName === '') {
        $destinationName = trim(strval($executionContext["parameter_string"] ?? ''));
    }

    $executionContext["parameter_string"] = dialecticBuildTravelToActionPayload($destinationName, $payload);
    $executionContext["parameter_value"] = decodeFunctionExecutionParameterPayload($executionContext["parameter_string"]) ?? $payload;
    $executionContext["parameter_is_empty"] = functionExecutionParameterValueIsEmpty($executionContext["parameter_value"]);
}

function buildConfiguredActionParameterFromMetadata($functionCodeName, $parameter)
{
    if (!function_exists('dialecticGetActionCatalogRow') || !function_exists('dialecticActionCatalogResolveTemplateValue')) {
        return null;
    }

    $row = dialecticGetActionCatalogRow($functionCodeName);
    if (!is_array($row)) {
        return null;
    }

    $metadata = dialecticActionCatalogDecodeJson($row['metadata'] ?? [], []);
    $parameterTemplate = $metadata['parameter_template'] ?? null;
    if ($parameterTemplate === null || $parameterTemplate === '') {
        return null;
    }

    $parameterData = decodeFunctionExecutionParameterPayload($parameter);
    if (!is_array($parameterData)) {
        $parameterData = [];
    }

    $parameterTarget = strval($parameterData['target'] ?? (is_array($parameter) ? '' : trim(strval($parameter))));
    $context = [
        'action_name' => $functionCodeName,
        'parameter_raw' => is_array($parameter)
            ? json_encode($parameter, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
            : strval($parameter),
        'parameter_target' => $parameterTarget,
        'parameters' => $parameterData,
        'config' => function_exists('dialecticActionCatalogGetResolvedCustomConfig')
            ? dialecticActionCatalogGetResolvedCustomConfig($functionCodeName, $row)
            : [],
    ];

    $resolved = dialecticActionCatalogResolveTemplateValue($parameterTemplate, $context);
    if (is_array($resolved)) {
        return json_encode($resolved, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    if ($resolved === null) {
        return '';
    }

    return is_string($resolved)
        ? $resolved
        : json_encode($resolved, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
}

function dialecticExtractActionArgumentTargetValue($arguments)
{
    if (!is_array($arguments)) {
        return trim(strval($arguments));
    }

    foreach (['target', 'item', 'amount'] as $preferredKey) {
        if (array_key_exists($preferredKey, $arguments)) {
            $value = $arguments[$preferredKey];
            if (is_scalar($value) || $value === null) {
                return trim(strval($value));
            }
        }
    }

    $firstValue = reset($arguments);
    if (is_scalar($firstValue) || $firstValue === null) {
        return trim(strval($firstValue));
    }

    return '';
}

// We must use internal keys here.

$F_DESCRIPTIONS_LOCAL["MoveTo"] = "Move to a visible nearby actor or NPC. Use TravelTo for places, buildings, cities, doors, or locations.";
$F_DESCRIPTIONS_LOCAL["Barter"] = "Open a vendor-style barter menu with #DIALECTIC_NAME#.";
$F_DESCRIPTIONS_LOCAL["OpenInventory"] = "Open #DIALECTIC_NAME#'s inventory for free item exchange with #PLAYER_NAME#.";
$F_DESCRIPTIONS_LOCAL["Attack"] = "Attack with intention to kill a target actor or entity.";
$F_DESCRIPTIONS_LOCAL["Follow"] = "Move to and follow the specified target actor";
$F_DESCRIPTIONS_LOCAL["Inspect"] = "Inspect a nearby actor or being to get a closer read on their visible equipment, condition, and state.";
$F_DESCRIPTIONS_LOCAL["InspectSurroundings"] = "Look around and assess who or what is nearby, including people, creatures, and possible threats.";
$F_DESCRIPTIONS_LOCAL["CheckInventory"] = "Search in #DIALECTIC_NAME#'s inventory, backpack, or pocket. List their inventory contents.";
$F_DESCRIPTIONS_LOCAL["SheatheWeapon"] = "Sheathes/put away current weapon";
$F_DESCRIPTIONS_LOCAL["TravelTo"] = "Travel long distance to a building, city, door or other location. Also known as lead the way.";
$F_DESCRIPTIONS_LOCAL["TakeASeat"] = "#DIALECTIC_NAME# takes a seat at a nearby seating location.";
$F_DESCRIPTIONS_LOCAL["ReadQuests"] = "Only use if #PLAYER_NAME# explicitly asks about a quest. Read the quest log and get information about current quests.";
$F_DESCRIPTIONS_LOCAL["Relax"] = "#DIALECTIC_NAME# relaxes at the current location without being dismissed. JIP CCC companions use their native relaxation state.";
$F_DESCRIPTIONS_LOCAL["DirectorCommand"] = "Send a short freeform instruction to the game director so it can stage a scene or event.";
$F_DESCRIPTIONS_LOCAL["SpawnCaps"] = "Create caps and give them to #PLAYER_NAME# or another nearby actor.";
$F_DESCRIPTIONS_LOCAL["SpawnItem"] = "Create a named item from the descriptions database and give it to #PLAYER_NAME# or another nearby actor.";
$F_DESCRIPTIONS_LOCAL["TeleportActor"] = "Teleport #PLAYER_NAME# or another nearby actor to a named synchronized location.";
$F_DESCRIPTIONS_LOCAL["KillTarget"] = "Kill a chosen nearby actor immediately.";
$F_DESCRIPTIONS_LOCAL["IncreaseWalkSpeed"] = "Increase #DIALECTIC_NAME#'s speed when moving or travelling.";
$F_DESCRIPTIONS_LOCAL["DecreaseWalkSpeed"] = "Decrease #DIALECTIC_NAME#'s speed when moving or travelling.";
$F_DESCRIPTIONS_LOCAL["StopWalk"] = "Stop all of #DIALECTIC_NAME#'s actions immediately.";
$F_DESCRIPTIONS_LOCAL["WaitHere"] = "#DIALECTIC_NAME# waits and loiters at the current location.";
$F_DESCRIPTIONS_LOCAL["TakeCapsFromPlayer"] = "#DIALECTIC_NAME# takes the specified amount of caps from #PLAYER_NAME# once #PLAYER_NAME# agrees. Infer the amount from context.";
$F_DESCRIPTIONS_LOCAL["FollowPlayer"] = "#DIALECTIC_NAME# temporarily follows #PLAYER_NAME# without joining the party or follower roster. Do not use for requests to join the party or become a companion; use Join_#PLAYER_NAME#_Party.";
$F_DESCRIPTIONS_LOCAL["StopFollowing"] = "#DIALECTIC_NAME# stops following #PLAYER_NAME# and leaves the current follower role.";
$F_DESCRIPTIONS_LOCAL["ComeCloser"] = "#DIALECTIC_NAME# approaches #PLAYER_NAME#.";
$F_DESCRIPTIONS_LOCAL["GiveCapsTo"] = "#DIALECTIC_NAME# gives caps to another actor or #PLAYER_NAME#. REQUIRED: include the recipient in 'target' and the caps amount in 'amount' or 'item'.";
$F_DESCRIPTIONS_LOCAL["GiveItemTo"] = "#DIALECTIC_NAME# gives a specific item from inventory to another actor or #PLAYER_NAME#. REQUIRED: Must include 'item' field with exact item name from <inventory> tag, and 'target' field with recipient name.";
$F_DESCRIPTIONS_LOCAL["PickupItem"] = "#DIALECTIC_NAME# picks up a specific item from the ground. Use the exact RefID:ItemName format from nearby_items or from the representative RefID shown in ITEM DESCRIPTIONS when the nearby item list is grouped (e.g. 0x12345:9mm Pistol).";
$F_DESCRIPTIONS_LOCAL["MakeFollower"] = "#DIALECTIC_NAME# joins #PLAYER_NAME# as a recruited follower and party member, and begins following. Use for requests to join the party, become a follower or companion, join the squad, or travel as an ally.";

$F_DESCRIPTIONS_LOCAL["Consume"] = "#DIALECTIC_NAME# consumes food, drink, chems, or another aid item from inventory. Use the exact inventory item name in the target field.";
$F_DESCRIPTIONS_LOCAL["EquipItem"] = "#DIALECTIC_NAME# equips a weapon or wearable item already present in their inventory. Use the exact item name from <inventory>.";
$F_DESCRIPTIONS_LOCAL["UnequipItem"] = "#DIALECTIC_NAME# removes a currently equipped weapon or wearable item. Use the exact equipped item name from <inventory>.";
    
$F_DESCRIPTIONS_LOCAL["EndConversation"] = "#DIALECTIC_NAME# ends the conversation and becomes unavailable to talk for a short time.";

$F_RETURNMESSAGES_LOCAL["MoveTo"] = "#DIALECTIC_NAME# moves to #TARGET#.";
$F_RETURNMESSAGES_LOCAL["Barter"] = "Opens barter menu with #DIALECTIC_NAME#.";
$F_RETURNMESSAGES_LOCAL["OpenInventory"] = "Opens #DIALECTIC_NAME#'s inventory for item exchange with #PLAYER_NAME#.";
$F_RETURNMESSAGES_LOCAL["Attack"] = "#DIALECTIC_NAME# attacks #TARGET#.";
$F_RETURNMESSAGES_LOCAL["Follow"] = "#DIALECTIC_NAME# follows #TARGET#.";
$F_RETURNMESSAGES_LOCAL["Inspect"] = "#DIALECTIC_NAME# inspects #TARGET# and see this: #RESULT#";
$F_RETURNMESSAGES_LOCAL["InspectSurroundings"] = "#DIALECTIC_NAME# takes a look around and see this: #RESULT#";
$F_RETURNMESSAGES_LOCAL["CheckInventory"] = "#DIALECTIC_NAME#'s INVENTORY:#RESULT#";
$F_RETURNMESSAGES_LOCAL["SheatheWeapon"] = "Sheathes/put away current weapon";
$F_RETURNMESSAGES_LOCAL["TakeASeat"] = "#DIALECTIC_NAME# sits in a nearby chair or piece of furniture.";
$F_RETURNMESSAGES_LOCAL["ReadQuests"] = "";
$F_RETURNMESSAGES_LOCAL["Relax"] = "#DIALECTIC_NAME# relaxes at the current location.";
$F_RETURNMESSAGES_LOCAL["DirectorCommand"] = "The director is preparing a scene instruction.";
$F_RETURNMESSAGES_LOCAL["SpawnCaps"] = "#TARGET# receives #AMOUNT# caps.";
$F_RETURNMESSAGES_LOCAL["SpawnItem"] = "#TARGET# receives #ITEM#.";
$F_RETURNMESSAGES_LOCAL["TeleportActor"] = "#TARGET# teleports to #LOCATION#.";
$F_RETURNMESSAGES_LOCAL["KillTarget"] = "#TARGET# is killed.";
$F_RETURNMESSAGES_LOCAL["IncreaseWalkSpeed"] = "Increases #DIALECTIC_NAME#'s speed or pace when moving or travelling.";
$F_RETURNMESSAGES_LOCAL["DecreaseWalkSpeed"] = "Decreases #DIALECTIC_NAME#'s speed or pace when moving or travelling.";
$F_RETURNMESSAGES_LOCAL["StopWalk"] = "Stop all of #DIALECTIC_NAME#'s actions immediately.";
$F_RETURNMESSAGES_LOCAL["TravelTo"] = "#DIALECTIC_NAME# begins travelling to #TARGET#.";
$F_RETURNMESSAGES_LOCAL["WaitHere"] = "#DIALECTIC_NAME# waits and stands at the place.";
$F_RETURNMESSAGES_LOCAL["TakeCapsFromPlayer"] = "#PLAYER_NAME# gave #TARGET# caps to #DIALECTIC_NAME#. If this is a transaction, maybe GiveItemTo is needed.";
$F_RETURNMESSAGES_LOCAL["FollowPlayer"] = "#DIALECTIC_NAME# follows #PLAYER_NAME#.";
$F_RETURNMESSAGES_LOCAL["StopFollowing"] = "#DIALECTIC_NAME# stops following #PLAYER_NAME#.";
$F_RETURNMESSAGES_LOCAL["GiveCapsTo"] = "#DIALECTIC_NAME# gives #AMOUNT# caps to #TARGET#.";
$F_RETURNMESSAGES_LOCAL["GiveItemTo"] = "#DIALECTIC_NAME# gives #ITEM# to #TARGET#.";
$F_RETURNMESSAGES_LOCAL["PickupItem"] = "#DIALECTIC_NAME# picks up #ITEM#.";
$F_RETURNMESSAGES_LOCAL["MakeFollower"] = "#DIALECTIC_NAME# is now part of the adventuring party.";

$F_RETURNMESSAGES_LOCAL["Consume"] = "#DIALECTIC_NAME# consumes an item from inventory.";
$F_RETURNMESSAGES_LOCAL["EquipItem"] = "#DIALECTIC_NAME# equips #ITEM#.";
$F_RETURNMESSAGES_LOCAL["UnequipItem"] = "#DIALECTIC_NAME# removes #ITEM#.";

// Action display names. Plugin commands must always resolve back to canonical code names.

$F_NAMES_LOCAL["MoveTo"] = "MoveTo";
$F_NAMES_LOCAL["Barter"] = "Barter";
$F_NAMES_LOCAL["OpenInventory"] = "OpenInventory";
$F_NAMES_LOCAL["Attack"] = "Attack";
$F_NAMES_LOCAL["Follow"] = "Follow";
$F_NAMES_LOCAL["Inspect"] = "Inspect";
$F_NAMES_LOCAL["InspectSurroundings"] = "InspectSurroundings";
$F_NAMES_LOCAL["CheckInventory"] = "CheckInventory";
$F_NAMES_LOCAL["SheatheWeapon"] = "SheatheWeapon";
$F_NAMES_LOCAL["TakeASeat"] = "TakeASeat";
$F_NAMES_LOCAL["ReadQuests"] = "ReadQuests";
$F_NAMES_LOCAL["Relax"] = "Relax";
$F_NAMES_LOCAL["DirectorCommand"] = "DirectorCommand";
$F_NAMES_LOCAL["SpawnCaps"] = "SpawnCaps";
$F_NAMES_LOCAL["SpawnItem"] = "SpawnItem";
$F_NAMES_LOCAL["TeleportActor"] = "TeleportActor";
$F_NAMES_LOCAL["KillTarget"] = "KillTarget";
$F_NAMES_LOCAL["IncreaseWalkSpeed"] = "IncreaseWalkSpeed";
$F_NAMES_LOCAL["DecreaseWalkSpeed"] = "DecreaseWalkSpeed";
$F_NAMES_LOCAL["StopWalk"] = "StopWalk";
$F_NAMES_LOCAL["TravelTo"] = "TravelTo";
$F_NAMES_LOCAL["WaitHere"] = "WaitHere";
$F_NAMES_LOCAL["TakeCapsFromPlayer"] = "TakeCapsFromPlayer";
$F_NAMES_LOCAL["FollowPlayer"] = "Follow_#PLAYER_NAME#";
$F_NAMES_LOCAL["StopFollowing"] = "Stop_Following_#PLAYER_NAME#";
$F_NAMES_LOCAL["ComeCloser"] = "ComeCloser";
$F_NAMES_LOCAL["GiveCapsTo"] = "GiveCapsTo";
$F_NAMES_LOCAL["GiveItemTo"] = "GiveItemTo";
$F_NAMES_LOCAL["PickupItem"] = "PickupItem";
$F_NAMES_LOCAL["MakeFollower"] = "Join_#PLAYER_NAME#_Party";

$F_NAMES_LOCAL["Consume"] = "Consume";
$F_NAMES_LOCAL["EquipItem"] = "EquipItem";
$F_NAMES_LOCAL["UnequipItem"] = "UnequipItem";

$F_NAMES_LOCAL["EndConversation"] = "EndConversation";

if (function_exists('dialecticNormalizeActionCatalogDisplayActionName')) {
    foreach ($F_NAMES_LOCAL as $functionCode => $functionName) {
        $F_NAMES_LOCAL[$functionCode] = dialecticNormalizeActionCatalogDisplayActionName($functionName);
    }
}

$GLOBALS["F_DESCRIPTIONS"] = dialecticFilterCanonicalActionMap($F_DESCRIPTIONS_LOCAL);
$GLOBALS["F_RETURNMESSAGES"] = dialecticFilterCanonicalActionMap($F_RETURNMESSAGES_LOCAL);
$GLOBALS["F_NAMES"] = dialecticFilterCanonicalActionMap($F_NAMES_LOCAL);
$GLOBALS["F_DESCRIPTIONS_BASE"] = $F_DESCRIPTIONS_LOCAL;
$GLOBALS["F_RETURNMESSAGES_BASE"] = $F_RETURNMESSAGES_LOCAL;


dialecticTraceFunctionsIncludePhase(__LINE__, 'function_catalog_build_start', $startTime);

$GLOBALS["FUNCTIONS"] = [
    [
        "name" => $F_NAMES_LOCAL["MoveTo"],
        "description" => $F_DESCRIPTIONS_LOCAL["MoveTo"],
        "parameters" => [
            "type" => "object",
            "properties" => [
                "target" => [
                    "type" => "string",
                    "description" => "Visible nearby target NPC, actor, or being. Do not use this for places, buildings, cities, doors, or locations.",
                    "enum" => isset($GLOBALS['FUNCTION_PARM_MOVETO']) ? $GLOBALS['FUNCTION_PARM_MOVETO'] : [],
                ],
            ],
            "required" => ["target"],
        ],
    ],
    [
        "name" => $F_NAMES_LOCAL["Barter"],
        "description" => $F_DESCRIPTIONS_LOCAL["Barter"],
        "parameters" => [
            "type" => "object",
            "properties" => [
                "target" => [
                    "type" => "string",
                    "description" => "Keep it blank",
                ],
            ],
            "required" => [],
        ],
    ],
    [
        "name" => $F_NAMES_LOCAL["OpenInventory"],
        "description" => $F_DESCRIPTIONS_LOCAL["OpenInventory"],
        "parameters" => [
            "type" => "object",
            "properties" => [
                "target" => [
                    "type" => "string",
                    "description" => "Keep it blank",
                ],
            ],
            "required" => [],
        ],
    ],
    [
        "name" => $F_NAMES_LOCAL["Attack"],
        "description" => $F_DESCRIPTIONS_LOCAL["Attack"],
        "parameters" => [
            "type" => "object",
            "properties" => [
                "target" => [
                    "type" => "string",
                    "description" => "Target NPC, Actor, or being",
                ],
            ],
            "required" => ["target"],
        ],
    ],
    [
        "name" => $F_NAMES_LOCAL["Follow"],
        "description" => $F_DESCRIPTIONS_LOCAL["Follow"],
        "parameters" => [
            "type" => "object",
            "properties" => [
                "target" => [
                    "type" => "string",
                    "description" => "Target NPC, Actor, or being",
                ],
            ],
            "required" => ["target"],
        ],
    ],
    [
        "name" => $F_NAMES_LOCAL["Inspect"],
        "description" => $F_DESCRIPTIONS_LOCAL["Inspect"],
        "parameters" => [
            "type" => "object",
            "properties" => [
                "target" => [
                    "type" => "string",
                    "description" => "Nearby NPC, actor, or being to inspect more closely",
                    "enum" => isset($GLOBALS['FUNCTION_PARM_INSPECT']) ? $GLOBALS['FUNCTION_PARM_INSPECT'] : [],
                ],
            ],
            "required" => ["target"],
        ],
    ],
    [
        "name" => $F_NAMES_LOCAL["InspectSurroundings"],
        "description" => $F_DESCRIPTIONS_LOCAL["InspectSurroundings"],
        "parameters" => [
            "type" => "object",
            "properties" => [
                "target" => [
                    "type" => "string",
                    "description" => "Keep it blank",
                ],
            ],
            "required" => [],
        ],
    ],
    [
        "name" => $F_NAMES_LOCAL["CheckInventory"],
        "description" => $F_DESCRIPTIONS_LOCAL["CheckInventory"],
        "parameters" => [
            "type" => "object",
            "properties" => [
                "target" => [
                    "type" => "string",
                    "description" => "item to look for, if empty all items will be returned",
                ],
            ],
            "required" => [],
        ],
    ],
    [
        "name" => $F_NAMES_LOCAL["SheatheWeapon"],
        "description" => $F_DESCRIPTIONS_LOCAL["SheatheWeapon"],
        "parameters" => [
            "type" => "object",
            "properties" => [
                "target" => [
                    "type" => "string",
                    "description" => "Keep it blank",
                ],
            ],
            "required" => [],
        ],
    ],
    [
        "name" => $F_NAMES_LOCAL["TravelTo"],
        "description" => $F_DESCRIPTIONS_LOCAL["TravelTo"],
        "parameters" => [
            "type" => "object",
            "properties" => [
                "location" => [
                    "type" => "string",
                    "description" => "Building, city, door, or other location to travel to.",

                ],
            ],
            "required" => ["location"],
        ],
    ],
    [
        "name" => $F_NAMES_LOCAL["TakeASeat"],
        "description" => $F_DESCRIPTIONS_LOCAL["TakeASeat"],
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
    ],
    [
        "name" => $F_NAMES_LOCAL["ReadQuests"],
        "description" => $F_DESCRIPTIONS_LOCAL["ReadQuests"],
        "parameters" => [
            "type" => "object",
            "properties" => [
                "id_quest" => [
                    "type" => "string",
                    "description" => "Specific quest to read. Leave blank to read current quests.",
                ],
            ],
            "required" => [""],
        ],
    ],
    [
        "name" => $F_NAMES_LOCAL["IncreaseWalkSpeed"],
        "description" => $F_DESCRIPTIONS_LOCAL["IncreaseWalkSpeed"],
        "parameters" => [
            "type" => "object",
            "properties" => [
                "speed" => [
                    "type" => "string",
                    "description" => "Speed",
                    "enum" => ["run", "jog"],
                ],

            ],
            "required" => [],
        ],
    ],
    [
        "name" => $F_NAMES_LOCAL["DecreaseWalkSpeed"],
        "description" => $F_DESCRIPTIONS_LOCAL["DecreaseWalkSpeed"],
        "parameters" => [
            "type" => "object",
            "properties" => [
                "speed" => [
                    "type" => "string",
                    "description" => "Speed",
                    "enum" => ["jog", "walk"],
                ],

            ],
            "required" => [],
        ],
    ],
    [
        "name" => $F_NAMES_LOCAL["StopWalk"],
        "description" => $F_DESCRIPTIONS_LOCAL["StopWalk"],
        "parameters" => [
            "type" => "object",
            "properties" => [
                "target" => [
                    "type" => "string",
                    "description" => "action",
                ],
            ],
            "required" => [""],
        ],
    ],
    [
        "name" => $F_NAMES_LOCAL["WaitHere"],
        "description" => $F_DESCRIPTIONS_LOCAL["WaitHere"],
        "parameters" => [
            "type" => "object",
            "properties" => [
                "target" => [
                    "type" => "string",
                    "description" => "",
                ],
            ],
            "required" => [""],
        ],
    ],
    [
        "name" => $F_NAMES_LOCAL["TakeCapsFromPlayer"],
        "description" => $F_DESCRIPTIONS_LOCAL["TakeCapsFromPlayer"],
        "parameters" => [
            "type" => "object",
            "properties" => [
                "amount" => [
                    "type" => "string",
                    "description" => "Caps amount to take from #PLAYER_NAME#.",
                ],
            ],
            "required" => ["amount"],
        ],
    ],
    [
        "name" => $F_NAMES_LOCAL["FollowPlayer"],
        "description" => $F_DESCRIPTIONS_LOCAL["FollowPlayer"],
        "parameters" => [
            "type" => "object",
            "properties" => [
                "target" => [
                    "type" => "string",
                    "description" => "",
                ],
            ],
            "required" => [""],
        ],
    ],
    [
        "name" => $F_NAMES_LOCAL["StopFollowing"],
        "description" => $F_DESCRIPTIONS_LOCAL["StopFollowing"],
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
    ],
    [
        "name" => $F_NAMES_LOCAL["ComeCloser"],
        "description" => $F_DESCRIPTIONS_LOCAL["ComeCloser"],
        "properties" => [
            "target" => [
                "type" => "string",
                "description" => "Keep it blank",
            ],
        ],
        "required" => [""],
    ],
    [
        "name" => $F_NAMES_LOCAL["GiveCapsTo"],
        "description" => $F_DESCRIPTIONS_LOCAL["GiveCapsTo"],
        "parameters" => [
            "type" => "object",
            "properties" => [
                "target" => [
                    "type" => "string",
                    "description" => "Target NPC, Actor, or #PLAYER_NAME# who receives the caps.",
                ],
                "item" => [
                    "type" => "string",
                    "description" => "Optional caps amount as a number string if amount is not used.",
                ],
                "amount" => [
                    "type" => "integer",
                    "description" => "Caps amount to give.",
                ],
            ],
            "required" => ["target"],
        ]
    ],
    [
        "name" => $F_NAMES_LOCAL["GiveItemTo"],
        "description" => $F_DESCRIPTIONS_LOCAL["GiveItemTo"],
        "parameters" => [
            "type" => "object",
            "properties" => [
                "target" => [
                    "type" => "string",
                    "description" => "Target NPC, Actor, or being to receive the item",
                ],
                "item" => [
                    "type" => "string",
                    "description" => "REQUIRED: Exact name of item from <inventory> tag. Must match item name exactly.",
                ],
                "amount" => [
                    "type" => "integer",
                    "description" => "Number of items to give (default: 1). Cannot exceed quantity in <inventory>.",
                ],
            ],
            "required" => ["target", "item"],
        ],
    ],
    [
        "name" => $F_NAMES_LOCAL["PickupItem"],
        "description" => $F_DESCRIPTIONS_LOCAL["PickupItem"],
        "parameters" => [
            "type" => "object",
            "properties" => [
                "target" => [
                    "type" => "string",
                    "description" => "Target actor (leave empty for PickupItem)",
                ],
                "item" => [
                    "type" => "string",
        "description" => "REQUIRED: Exact RefID:ItemName from <nearby_items> or from the representative RefID shown in ITEM DESCRIPTIONS when nearby items are grouped (e.g., 0x12345:9mm Pistol). Must match format exactly.",
                ],
                "amount" => [
                    "type" => "integer",
                    "description" => "Optional quantity to pick up when multiple matching items are stacked or grouped.",
                ],
            ],
            "required" => ["item"],
        ],
    ],
    [
        "name" => $F_NAMES_LOCAL["MakeFollower"],
        "description" => $F_DESCRIPTIONS_LOCAL["MakeFollower"],
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
    ],
    [
        "name" => $F_NAMES_LOCAL["Consume"],
        "description" => $F_DESCRIPTIONS_LOCAL["Consume"],
        "parameters" => [
            "type" => "object",
            "properties" => [
                "target" => [
                    "type" => "string",
                    "description" => "REQUIRED: Exact name of the food, drink, chem, or aid item from <inventory> to consume.",
                ],
                "item" => [
                    "type" => "string",
                    "description" => "Optional fallback copy of the same inventory item name if target is empty.",
                ],
            ],
            "required" => ["target"],
        ],
    ],
    [
        "name" => $F_NAMES_LOCAL["Relax"],
        "description" => $F_DESCRIPTIONS_LOCAL["Relax"],
        "parameters" => [
            "type" => "object",
            "properties" => new stdClass(),
            "required" => [],
            "additionalProperties" => false,
        ],
    ],
    [
        "name" => $F_NAMES_LOCAL["EquipItem"],
        "description" => $F_DESCRIPTIONS_LOCAL["EquipItem"],
        "parameters" => [
            "type" => "object",
            "properties" => [
                "item" => [
                    "type" => "string",
                    "description" => "Exact weapon or wearable item name from <inventory> to equip.",
                ],
            ],
            "required" => ["item"],
        ],
    ],
    [
        "name" => $F_NAMES_LOCAL["UnequipItem"],
        "description" => $F_DESCRIPTIONS_LOCAL["UnequipItem"],
        "parameters" => [
            "type" => "object",
            "properties" => [
                "item" => [
                    "type" => "string",
                    "description" => "Exact currently equipped weapon or wearable item name from <inventory> to remove.",
                ],
            ],
            "required" => ["item"],
        ],
    ],

    [
        "name" => $F_NAMES_LOCAL["EndConversation"],
        "description" => $F_DESCRIPTIONS_LOCAL["EndConversation"],
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
    ],
];

dialecticTraceFunctionsIncludePhase(__LINE__, 'function_catalog_build_done', $startTime);

$GLOBALS["F_DESCRIPTIONS"] = dialecticFilterCanonicalActionMap($F_DESCRIPTIONS_LOCAL);
$GLOBALS["F_RETURNMESSAGES"] = dialecticFilterCanonicalActionMap($F_RETURNMESSAGES_LOCAL);
$GLOBALS["F_NAMES"] = dialecticFilterCanonicalActionMap($F_NAMES_LOCAL);
$GLOBALS["FUNCTIONS"] = array_values(array_filter($GLOBALS["FUNCTIONS"], function ($functionEntry) {
    $codeName = getFunctionCodeName($functionEntry["name"] ?? "");
    $codeName = $codeName === false ? false : dialecticNormalizeActionCodeName($codeName);
    return $codeName !== false && isset(dialecticCanonicalActionCodeSet()[$codeName]);
}));

// Mantain a copy of all functions defined here
foreach ($GLOBALS["FUNCTIONS"] as $n => $functionEntry) {
    $GLOBALS["BASE_FUNCTIONS"][dialecticNormalizeActionCodeName(getFunctionCodeName($functionEntry["name"]))] = $GLOBALS["FUNCTIONS"][$n];
}
$DIALECTIC_BASE_FUNCTIONS_LOCAL = $GLOBALS["BASE_FUNCTIONS"];
$GLOBALS["DIALECTIC_BASE_FUNCTIONS_FALLBACK"] = $GLOBALS["BASE_FUNCTIONS"];

dialecticTraceFunctionsIncludePhase(__LINE__, 'base_functions_indexed', $startTime);

function getFunctionNameAliases()
{
    static $cachedAliases = null;
    if (is_array($cachedAliases)) {
        return $cachedAliases;
    }

    $playerName = strval($GLOBALS["PLAYER_NAME"] ?? "Player");

    $aliases = [
        'ExchangeItems' => 'OpenInventory',
        'ListInventory' => 'CheckInventory',
        'TradeItems' => 'OpenInventory',
        'AcceptGift' => 'OpenInventory',
        "TakeMoneyFrom{$playerName}" => 'TakeCapsFromPlayer',
        'ReadQuestJournal' => 'ReadQuests',
        "JoinTo{$playerName}Squad" => 'MakeFollower',
        "StopFollowing{$playerName}" => 'StopFollowing',
        "Stop_Following_{$playerName}" => 'StopFollowing',
        "StopFollow{$playerName}" => 'StopFollowing',
        'StopFollowingPlayer' => 'StopFollowing',
        'StopFollowingMe' => 'StopFollowing',
        'DismissFollower' => 'StopFollowing',
        'LeaveParty' => 'StopFollowing',
    ];

    if (function_exists('dialecticNormalizeActionCatalogDisplayActionName')) {
        foreach ($aliases as $displayActionName => $codeName) {
            $normalizedDisplayActionName = dialecticNormalizeActionCatalogDisplayActionName($displayActionName);
            if ($normalizedDisplayActionName !== '' && !isset($aliases[$normalizedDisplayActionName])) {
                $aliases[$normalizedDisplayActionName] = $codeName;
            }
        }
    }

    $cachedAliases = $aliases;
    return $cachedAliases;
}

function getFunctionCodeName($key)
{
    $key = strval($key);
    static $resolvedCodeNames = [];

    if (array_key_exists($key, $resolvedCodeNames)) {
        return $resolvedCodeNames[$key];
    }

    if (!isset($GLOBALS["F_NAMES"]) || !is_array($GLOBALS["F_NAMES"])) {
        return $resolvedCodeNames[$key] = false;
    }

    if (isset($GLOBALS["F_NAMES"][$key])) {
        return $resolvedCodeNames[$key] = $key;
    }

    if (isset($GLOBALS["DIALECTIC_ACTION_NAME_PREFERRED_CODE"]) && is_array($GLOBALS["DIALECTIC_ACTION_NAME_PREFERRED_CODE"])) {
        $preferredCode = $GLOBALS["DIALECTIC_ACTION_NAME_PREFERRED_CODE"][$key] ?? false;
        if ($preferredCode !== false) {
            return $resolvedCodeNames[$key] = $preferredCode;
        }
    }

    $keysToTry = [$key];
    if (function_exists('dialecticNormalizeActionCatalogDisplayActionName')) {
        $normalizedKey = dialecticNormalizeActionCatalogDisplayActionName($key);
        if ($normalizedKey !== '' && !in_array($normalizedKey, $keysToTry, true)) {
            $keysToTry[] = $normalizedKey;
        }
    }

    foreach ($keysToTry as $candidateKey) {
        if (isset($GLOBALS["DIALECTIC_ACTION_NAME_PREFERRED_CODE"]) && is_array($GLOBALS["DIALECTIC_ACTION_NAME_PREFERRED_CODE"])) {
            $preferredCode = $GLOBALS["DIALECTIC_ACTION_NAME_PREFERRED_CODE"][$candidateKey] ?? false;
            if ($preferredCode !== false) {
                return $resolvedCodeNames[$key] = $preferredCode;
            }
        }

        $matchingCodes = [];
        foreach ($GLOBALS["F_NAMES"] as $functionCode => $functionName) {
            if ($functionName === $candidateKey) {
                $matchingCodes[] = $functionCode;
            }
        }

        if (count($matchingCodes) === 1) {
            return $resolvedCodeNames[$key] = $matchingCodes[0];
        }

        if (count($matchingCodes) > 1) {
            foreach ($matchingCodes as $matchingCode) {
                if (function_exists('dialecticGetActionCatalogRow')) {
                    $row = dialecticGetActionCatalogRow($matchingCode);
                    if (is_array($row) && dialecticActionCatalogRowIsAvailableInCurrentMode($row) && !empty(($row['metadata'] ?? [])['builtin']) === false) {
                        return $resolvedCodeNames[$key] = $matchingCode;
                    }
                }
            }

            return $resolvedCodeNames[$key] = $matchingCodes[0];
        }
    }

    $aliases = getFunctionNameAliases();
    if (isset($aliases[$key])) {
        return $resolvedCodeNames[$key] = $aliases[$key];
    }

    if (function_exists('dialecticResolveActionCatalogCodeName')) {
        $catalogCodeName = dialecticResolveActionCatalogCodeName($key, true);
        if ($catalogCodeName !== false) {
            return $resolvedCodeNames[$key] = $catalogCodeName;
        }

        $catalogCodeName = dialecticResolveActionCatalogCodeName($key, false);
        if ($catalogCodeName !== false) {
            return $resolvedCodeNames[$key] = $catalogCodeName;
        }
    }

    return $resolvedCodeNames[$key] = false;
}

function dialecticBuildActionPromptTemplateContext($rowOrCode = null, array $extraContext = [])
{
    $row = null;
    $codeName = '';

    if (is_array($rowOrCode)) {
        $row = $rowOrCode;
        $codeName = trim(strval($row['code_name'] ?? ''));
    } else {
        $codeName = trim(strval($rowOrCode ?? ''));
        if ($codeName !== '' && function_exists('dialecticGetActionCatalogRow')) {
            $row = dialecticGetActionCatalogRow($codeName);
        }
    }

    if ($codeName === '' && is_array($row)) {
        $codeName = trim(strval($row['code_name'] ?? ''));
    }

    $context = [
        'code_name' => $codeName,
        'dialectic_name' => strval($GLOBALS["DIALECTIC_NAME"] ?? 'NPC'),
        'player_name' => strval($GLOBALS["PLAYER_NAME"] ?? 'Player'),
        'config' => [],
    ];

    if ($codeName !== '' && function_exists('dialecticActionCatalogGetResolvedCustomConfig')) {
        $context['config'] = dialecticActionCatalogGetResolvedCustomConfig($codeName, $row);
    }

    if (count($extraContext) > 0) {
        $context = array_replace_recursive($context, $extraContext);
    }

    return $context;
}

function dialecticFormatReturnMessageTemplate($codeName, $primaryArgument = '', array $extraReplacements = [])
{
    $codeName = trim(strval($codeName));
    if ($codeName === '') {
        return '';
    }

    $actionRow = function_exists('dialecticGetActionCatalogRow')
        ? dialecticGetActionCatalogRow($codeName)
        : null;

    $template = '';
    if (is_array($actionRow)) {
        $template = strval($actionRow['return_message'] ?? '');
    }
    if ($template === '' && isset($GLOBALS["F_RETURNMESSAGES"][$codeName])) {
        $template = strval($GLOBALS["F_RETURNMESSAGES"][$codeName] ?? '');
    }
    if ($template === '') {
        return '';
    }

    $argumentData = [];
    if (is_array($primaryArgument)) {
        $argumentData = $primaryArgument;
        $primaryArgument = trim(strval($argumentData['target'] ?? ''));
        if ($primaryArgument === '') {
            $primaryArgument = dialecticExtractActionArgumentTargetValue($argumentData);
        }
    } else {
        $primaryArgument = is_scalar($primaryArgument) || $primaryArgument === null
            ? strval($primaryArgument ?? '')
            : '';
    }

    $itemDisplayValue = dialecticFormatActionDisplayItemValue(trim(strval($argumentData['item'] ?? ($argumentData['location'] ?? ''))));

    $replacements = [
        '#TARGET#' => $primaryArgument,
        '#ITEM#' => $itemDisplayValue,
        '#AMOUNT#' => trim(strval($argumentData['amount'] ?? '')),
        '#LOCATION#' => dialecticFormatActionDisplayItemValue(trim(strval($argumentData['location'] ?? ($argumentData['item'] ?? '')))),
        '#DIALECTIC_NAME#' => strval($GLOBALS["DIALECTIC_NAME"] ?? 'NPC'),
        '#PLAYER_NAME#' => strval($GLOBALS["PLAYER_NAME"] ?? 'Player'),
    ];

    foreach ($extraReplacements as $key => $value) {
        $replacements[strval($key)] = is_scalar($value) || $value === null ? strval($value ?? '') : '';
    }

    $rendered = strtr($template, $replacements);
    return dialecticFormatActionPromptTemplate(
        $rendered,
        [],
        is_array($actionRow) ? $actionRow : $codeName,
        [
            'parameter_target' => $primaryArgument,
            'parameters' => $argumentData,
        ]
    );
}

function dialecticFormatActionDisplayItemValue($value)
{
    $value = trim(strval($value));
    if ($value === '') {
        return '';
    }

    if (preg_match('/^(?:0x)?[0-9a-f]{4,8}\s*:\s*(.+)$/i', $value, $matches)) {
        return trim($matches[1]);
    }

    return $value;
}

function dialecticResolveFuncretArgumentName($codeName, array $followupConfig = [])
{
    $argName = trim(strval($followupConfig['arg_name'] ?? ''));
    if ($argName !== '') {
        return $argName;
    }

    $row = function_exists('dialecticGetActionCatalogRow')
        ? dialecticGetActionCatalogRow($codeName)
        : null;
    if (!is_array($row)) {
        return 'target';
    }

    $parameters = $row['parameters_json'] ?? [];
    if (!is_array($parameters)) {
        $decodedParameters = json_decode(strval($parameters), true);
        $parameters = is_array($decodedParameters) ? $decodedParameters : [];
    }

    $required = $parameters['required'] ?? [];
    if (!is_array($required)) {
        $required = [$required];
    }
    foreach ($required as $requiredName) {
        $requiredName = trim(strval($requiredName));
        if ($requiredName !== '') {
            return $requiredName;
        }
    }

    $properties = $parameters['properties'] ?? [];
    if (!is_array($properties)) {
        return 'target';
    }

    foreach (['target', 'location', 'item', 'amount', 'speed'] as $preferredName) {
        if (array_key_exists($preferredName, $properties)) {
            return $preferredName;
        }
    }

    foreach (array_keys($properties) as $propertyName) {
        $propertyName = trim(strval($propertyName));
        if ($propertyName !== '') {
            return $propertyName;
        }
    }

    return 'target';
}

function dialecticBuildFuncretResultInfoActionMessage($codeName, $argName = 'target', $argValue = '', $resultText = '')
{
    $codeName = trim(strval($codeName));
    if ($codeName === '') {
        return '';
    }

    $argName = trim(strval($argName));
    if ($argName === '') {
        $argName = 'target';
    }

    $resultText = trim(strval($resultText));
    $dialecticName = strval($GLOBALS["DIALECTIC_NAME"] ?? 'NPC');
    if ($resultText !== '' && stripos($resultText, 'error') === 0) {
        return "{$dialecticName} issued ACTION, but {$resultText}";
    }

    $arguments = [];
    if (is_array($argValue)) {
        $arguments = $argValue;
    } elseif (is_scalar($argValue) || $argValue === null) {
        $arguments[$argName] = trim(strval($argValue ?? ''));
    }

    $message = dialecticFormatReturnMessageTemplate(
        $codeName,
        $arguments,
        ['#RESULT#' => $resultText]
    );
    $message = trim(strval($message));
    if ($message !== '') {
        return $message;
    }

    if ($resultText === '') {
        return '';
    }

    $actionName = function_exists('getFunctionDisplayName') ? getFunctionDisplayName($codeName) : $codeName;
    return "{$dialecticName} issued ACTION {$actionName}: {$resultText}";
}

function dialecticFormatActionPromptTemplate($template, array $extraReplacements = [], $rowOrCode = null, array $extraContext = [])
{
    $template = strval($template);
    if ($template === '') {
        return '';
    }

    $replacements = [
        '#DIALECTIC_NAME#' => strval($GLOBALS["DIALECTIC_NAME"] ?? 'NPC'),
        '#PLAYER_NAME#' => strval($GLOBALS["PLAYER_NAME"] ?? 'Player'),
        '{$GLOBALS["DIALECTIC_NAME"]}' => strval($GLOBALS["DIALECTIC_NAME"] ?? 'NPC'),
        '{$GLOBALS["PLAYER_NAME"]}' => strval($GLOBALS["PLAYER_NAME"] ?? 'Player'),
    ];

    foreach ($extraReplacements as $key => $value) {
        $replacements[strval($key)] = is_scalar($value) || $value === null ? strval($value ?? '') : '';
    }

    $rendered = strtr($template, $replacements);

    if (function_exists('dialecticActionCatalogResolveTemplateValue')) {
        $context = dialecticBuildActionPromptTemplateContext($rowOrCode, $extraContext);
        $resolved = dialecticActionCatalogResolveTemplateValue($rendered, $context);
        if (!is_array($resolved) && $resolved !== null) {
            $rendered = strval($resolved);
        }
    }

    // Some catalog/imported strings can still carry SQL-style doubled apostrophes.
    return str_replace("''", "'", $rendered);
}

function dialecticGetPromptActionDescription($codeName, $fallbackDescription = '')
{
    $codeName = trim(strval($codeName));
    $description = '';

    if ($codeName !== '' && isset($GLOBALS["F_DESCRIPTIONS"][$codeName])) {
        $description = strval($GLOBALS["F_DESCRIPTIONS"][$codeName] ?? '');
    }

    if ($description === '') {
        $description = strval($fallbackDescription);
    }

    return dialecticFormatActionPromptTemplate($description, [], $codeName);
}

function getFunctionDisplayName($key)
{
    if (isset($GLOBALS["F_NAMES"][$key]) && !empty($GLOBALS["F_NAMES"][$key])) {
        return $GLOBALS["F_NAMES"][$key];
    } else {
        return $key;
    }

}

function getSingleFunctionParameterValue($functionDef, $parsedResponse)
{
    if (!is_array($parsedResponse)) {
        return "";
    }

    $properties = $functionDef["parameters"]["properties"] ?? [];
    if (is_array($properties) && count($properties) === 0) {
        return "";
    }

    if (is_array($properties) && count($properties) === 1) {
        $parameterName = array_key_first($properties);
        if (is_string($parameterName) && array_key_exists($parameterName, $parsedResponse)) {
            return $parsedResponse[$parameterName];
        }
    }

    return $parsedResponse["target"] ?? "";
}

function normalizeFunctionParameterValueFromSchema($parameterSchema, $value)
{
    if (!is_array($parameterSchema)) {
        return $value;
    }

    $parameterType = strtolower(trim(strval($parameterSchema["type"] ?? "")));
    if ($parameterType === "integer" && is_numeric($value)) {
        return intval(round(floatval($value)));
    }

    if ($parameterType === "number" && is_numeric($value)) {
        return floatval($value);
    }

    if ($parameterType === "boolean") {
        if (is_bool($value)) {
            return $value;
        }

        $text = strtolower(trim(strval($value)));
        if (in_array($text, ["1", "true", "yes", "on", "t"], true)) {
            return true;
        }
        if (in_array($text, ["0", "false", "no", "off", "f"], true)) {
            return false;
        }
    }

    return $value;
}

function functionDefinitionHasRequiredParameters($functionDef)
{
    if (!is_array($functionDef)) {
        return false;
    }

    foreach (($functionDef["parameters"]["required"] ?? []) as $requiredParameter) {
        if (trim(strval($requiredParameter)) !== "") {
            return true;
        }
    }

    return false;
}

function functionExecutionParameterValueIsEmpty($parameterValue)
{
    if (is_array($parameterValue)) {
        return count($parameterValue) === 0;
    }

    return trim(strval($parameterValue)) === "";
}

function buildFunctionParameterValueFromResponse($functionDef, $parsedResponse)
{
    $properties = $functionDef["parameters"]["properties"] ?? [];
    $requiredParameters = [];
    foreach (($functionDef["parameters"]["required"] ?? []) as $requiredParameter) {
        $requiredParameter = trim(strval($requiredParameter));
        if ($requiredParameter !== "") {
            $requiredParameters[] = $requiredParameter;
        }
    }

    $missingRequiredParameters = [];
    foreach ($requiredParameters as $requiredParameter) {
        if (!array_key_exists($requiredParameter, $parsedResponse) || $parsedResponse[$requiredParameter] === "" || $parsedResponse[$requiredParameter] === null) {
            $missingRequiredParameters[] = $requiredParameter;
        }
    }

    if (count($properties) > 1) {
        $parameters = [];
        foreach ($properties as $parameterName => $parameterSchema) {
            if (array_key_exists($parameterName, $parsedResponse)) {
                $parameters[$parameterName] = normalizeFunctionParameterValueFromSchema($parameterSchema, $parsedResponse[$parameterName]);
            }
        }

        return [
            "parameter_value" => $parameters,
            "missing_required" => $missingRequiredParameters,
        ];
    }

    return [
        "parameter_value" => getSingleFunctionParameterValue($functionDef, $parsedResponse),
        "missing_required" => $missingRequiredParameters,
    ];
}

function buildFunctionExecutionContextFromResponse($parsedResponse)
{
    if (is_array($parsedResponse)) {
        $rawActionName = trim(strval($parsedResponse["action"] ?? ""));
        $rawCodeName = $rawActionName !== "" ? getFunctionCodeName($rawActionName) : false;
        $normalizedActionName = is_string($rawCodeName) && $rawCodeName !== "" ? $rawCodeName : $rawActionName;
        if ($normalizedActionName === "TravelTo") {
            if ((!array_key_exists("location", $parsedResponse) || trim(strval($parsedResponse["location"] ?? "")) === "") &&
                array_key_exists("target", $parsedResponse) && trim(strval($parsedResponse["target"] ?? "")) !== "") {
                $parsedResponse["location"] = $parsedResponse["target"];
            }
            if ((!array_key_exists("target", $parsedResponse) || trim(strval($parsedResponse["target"] ?? "")) === "") &&
                array_key_exists("location", $parsedResponse) && trim(strval($parsedResponse["location"] ?? "")) !== "") {
                $parsedResponse["target"] = $parsedResponse["location"];
            }
        }
    }

    $actionName = trim(strval($parsedResponse["action"] ?? ""));
    $resolvedCodeName = $actionName !== "" ? getFunctionCodeName($actionName) : false;
    $functionCodeName = is_string($resolvedCodeName) && $resolvedCodeName !== ""
        ? $resolvedCodeName
        : $actionName;
    $functionDef = null;
    if ($functionCodeName !== "") {
        $functionDef = findFunctionByName($functionCodeName);
    }
    if (!is_array($functionDef) && $actionName !== "" && $functionCodeName !== $actionName) {
        $functionDef = findFunctionByName($actionName);
    }
    $parameterValue = $parsedResponse["target"] ?? "";
    $missingRequired = [];

    if (is_array($functionDef)) {
        $parameterData = buildFunctionParameterValueFromResponse($functionDef, is_array($parsedResponse) ? $parsedResponse : []);
        $parameterValue = $parameterData["parameter_value"];
        $missingRequired = $parameterData["missing_required"];
    }

    return [
        "action_name" => $actionName,
        "function_def" => $functionDef,
        "function_found" => is_array($functionDef),
        "function_code_name" => $functionCodeName,
        "parameter_value" => $parameterValue,
        "parameter_string" => buildFunctionExecutionParameter($functionCodeName, $parameterValue),
        "missing_required" => $missingRequired,
        "has_required_parameters" => functionDefinitionHasRequiredParameters($functionDef),
        "parameter_is_empty" => functionExecutionParameterValueIsEmpty($parameterValue),
    ];
}

function queueFunctionExecutionCommand(&$commandBuffer, &$alreadySent, $executionContext, $connectorName, $actorName = null)
{
    require_once(__DIR__ . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . 'lib' . DIRECTORY_SEPARATOR . 'dialectic_command_payload.php');
    $actionName = trim(strval($executionContext["action_name"] ?? ""));
    if ($actionName === "") {
        return false;
    }

    if (empty($executionContext["function_found"])) {
        if ($actionName !== "Talk") {
            Logger::warn("{$connectorName}: Function not found for {$actionName}");
        }
        return false;
    }

    $missingRequired = $executionContext["missing_required"] ?? [];
    if (count($missingRequired) > 0) {
        Logger::warn("{$connectorName}: Missing required parameter(s) for " . strval($executionContext["function_code_name"] ?? $actionName) . ": " . implode(", ", $missingRequired));
    }

    if (!empty($executionContext["has_required_parameters"]) && !empty($executionContext["parameter_is_empty"])) {
        Logger::warn("{$connectorName}: Missing required parameter(s) for " . strval($executionContext["function_code_name"] ?? $actionName) . ": " . implode(", ", $missingRequired));
        return false;
    }

    dialecticEnrichTravelToExecutionContext($executionContext);

    $actorName = ($actorName !== null && trim(strval($actorName)) !== "") ? strval($actorName) : strval($GLOBALS["DIALECTIC_NAME"] ?? "DIALECTIC");
    $commandStr = dialecticEncodeActionLine(
        $actorName,
        strval($executionContext["function_code_name"] ?? ""),
        $executionContext["parameter_value"] ?? "",
        strval($executionContext["parameter_string"] ?? "")
    ) . "\n";
    $commandHash = md5($commandStr);

    if (isset($alreadySent[$commandHash])) {
        return false;
    }

    $commandBuffer[] = $commandStr;
    $alreadySent[$commandHash] = $commandStr;
    return true;
}

function dialecticPrepareActionsIssuedOriginalValue($originalValue)
{
    if (function_exists('dialecticActionCatalogApplyFollowupChainToActionsIssuedOriginal')) {
        return dialecticActionCatalogApplyFollowupChainToActionsIssuedOriginal($originalValue);
    }

    return strval($originalValue);
}

function buildFunctionExecutionParameter($functionCodeName, $parameter)
{
    $functionCodeName = trim(strval($functionCodeName));

    $configuredPayload = buildConfiguredActionParameterFromMetadata($functionCodeName, $parameter);
    if ($configuredPayload !== null) {
        return $configuredPayload;
    }

    if (is_array($parameter)) {
        return json_encode($parameter, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    return strval($parameter);
}

function findFunctionByName($name)
{
    $name = trim(strval($name));
    if (function_exists('dialecticFindActionCatalogRowByNameOrCode') && function_exists('dialecticActionCatalogBuildFunctionEntryFromRow')) {
        $row = dialecticFindActionCatalogRowByNameOrCode($name, true);
        if (is_array($row) && !empty($row['is_activated'])) {
            $functionEntry = dialecticActionCatalogBuildFunctionEntryFromRow($row);
            if (is_array($functionEntry) && !empty($functionEntry['name'])) {
                $functionEntry['description'] = function_exists('dialecticFormatActionPromptTemplate')
                    ? dialecticFormatActionPromptTemplate($row['description'] ?? '', [], $row)
                    : strval($row['description'] ?? '');
                return $functionEntry;
            }
        }
    }

    foreach ($GLOBALS["FUNCTIONS"] as $function) {
        if (($function['name'] ?? '') === $name) {
            return $function;
        }
    }

    $resolvedCodeName = getFunctionCodeName($name);
    if (is_string($resolvedCodeName) && $resolvedCodeName !== '') {
        foreach ($GLOBALS["FUNCTIONS"] as $function) {
            $functionName = trim(strval($function['name'] ?? ''));
            if ($functionName === '') {
                continue;
            }

            if (getFunctionCodeName($functionName) === $resolvedCodeName) {
                return $function;
            }
        }
    }

    if (function_exists('dialecticGetActionCatalogRowsByCode') && function_exists('dialecticActionCatalogBuildFunctionEntryFromRow')) {
        $rowsByCode = dialecticGetActionCatalogRowsByCode();
        $candidateCodes = [];

        if ($name !== '') {
            $candidateCodes[] = $name;
        }
        if (is_string($resolvedCodeName) && $resolvedCodeName !== '' && !in_array($resolvedCodeName, $candidateCodes, true)) {
            $candidateCodes[] = $resolvedCodeName;
        }

        foreach ($candidateCodes as $candidateCode) {
            $row = $rowsByCode[$candidateCode] ?? null;
            if (!is_array($row) || empty($row['is_activated'])) {
                continue;
            }
            if (function_exists('dialecticActionCatalogRowIsAvailableInCurrentMode') && !dialecticActionCatalogRowIsAvailableInCurrentMode($row)) {
                continue;
            }

            $functionEntry = dialecticActionCatalogBuildFunctionEntryFromRow($row);
            if (is_array($functionEntry) && !empty($functionEntry['name'])) {
                return $functionEntry;
            }
        }

        foreach ($rowsByCode as $row) {
            if (!is_array($row) || empty($row['code_name']) || empty($row['is_activated'])) {
                continue;
            }
            if (function_exists('dialecticActionCatalogRowIsAvailableInCurrentMode') && !dialecticActionCatalogRowIsAvailableInCurrentMode($row)) {
                continue;
            }

            $rowActionName = trim(strval($row['action_name'] ?? ''));
            $runtimeActionName = function_exists('dialecticFormatActionPromptTemplate')
                ? trim(strval(dialecticFormatActionPromptTemplate($rowActionName, [], $row)))
                : $rowActionName;
            $normalizedRuntimeActionName = function_exists('dialecticNormalizeActionCatalogDisplayActionName')
                ? trim(strval(dialecticNormalizeActionCatalogDisplayActionName($runtimeActionName)))
                : $runtimeActionName;

            if (!in_array($name, [$rowActionName, $runtimeActionName, $normalizedRuntimeActionName], true)) {
                continue;
            }

            $functionEntry = dialecticActionCatalogBuildFunctionEntryFromRow($row);
            if (is_array($functionEntry) && !empty($functionEntry['name'])) {
                return $functionEntry;
            }
        }
    }

    return null; // Return null if function not found
}

function getFunctionCodeNameByDisplayName($searchValue)
{
    if (function_exists('dialecticResolveActionCatalogCodeName')) {
        $catalogCodeName = dialecticResolveActionCatalogCodeName($searchValue, true);
        if ($catalogCodeName !== false) {
            return $catalogCodeName;
        }
    }

    foreach ($GLOBALS["F_NAMES"] as $key => $value) {
        if ($value === $searchValue) {
            return $key;
        }
    }

}

function unsetFunction($functionCodename)
{
    $functionCodename = dialecticNormalizeActionCodeName($functionCodename);
    if (($key = array_search($functionCodename, $GLOBALS["ENABLED_FUNCTIONS"])) !== false) {
        unset($GLOBALS["ENABLED_FUNCTIONS"][$key]);

    }

    foreach ($GLOBALS["FUNCTIONS"] as $n => $v) {
        if (!in_array(getFunctionCodeName($v["name"]), $GLOBALS["ENABLED_FUNCTIONS"])) {
            // error_log("Removing {$GLOBALS["FUNCTIONS"][$n]["name"]}");
            unset($GLOBALS["FUNCTIONS"][$n]);
        }
    }

}

$seedActionRows = dialecticBuildActionCatalogSeedRows(
    dialecticFilterCanonicalActionMap($F_NAMES_LOCAL ?? []),
    dialecticFilterCanonicalActionMap($F_DESCRIPTIONS_LOCAL ?? []),
    dialecticFilterCanonicalActionMap($F_RETURNMESSAGES_LOCAL ?? []),
    [],
    $ENABLED_FUNCTIONS_LOCAL,
    dialecticFilterCanonicalActionMap(dialecticBuildActionCatalogFunctionDefinitionsByCode($DIALECTIC_BASE_FUNCTIONS_LOCAL ?? []))
);
dialecticTraceFunctionsIncludePhase(__LINE__, 'seed_rows_built', $startTime);
if (dialecticActionCatalogDbReady()) {
    dialecticTraceFunctionsIncludePhase(__LINE__, 'seed_rows_db_sync_start', $startTime);
    dialecticEnsureActionCatalogBaseRowsSeeded($seedActionRows);
    dialecticTraceFunctionsIncludePhase(__LINE__, 'seed_rows_db_sync_done', $startTime);
}

$isNpcMode = isset($GLOBALS["IS_NPC"]) && $GLOBALS["IS_NPC"];
$dbEnabledFunctions = dialecticFilterCanonicalActionCodeList(dialecticLoadEnabledActionCodesForMode($isNpcMode, true));
$GLOBALS["ENABLED_FUNCTIONS"] = dialecticActionCatalogDbReady()
    ? $dbEnabledFunctions
    : array_values(array_unique($ENABLED_FUNCTIONS_LOCAL));
$GLOBALS["ENABLED_FUNCTIONS"] = dialecticFilterCanonicalActionCodeList($GLOBALS["ENABLED_FUNCTIONS"]);

dialecticTraceFunctionsIncludePhase(__LINE__, 'enabled_functions_loaded_from_runtime', $startTime);

if (dialecticActionCatalogDbReady()) {
    // Do not re-seed core_action from the live runtime list here.
    // Runtime functions may already include DB-backed custom actions that
    // intentionally share an action_name with shipped actions (for example
    // DIALECTIC-Custom NFF wrappers like WaitHere / FollowMe / BehindMe). If we
    // write back from the runtime list, those custom rows can be mistaken for
    // built-in functions and get rewritten as source=function.php rows.
    dialecticTraceFunctionsIncludePhase(__LINE__, 'runtime_function_merge_start', $startTime);
    dialecticActionCatalogApplyRowsToRuntimeFunctions();
    dialecticTraceFunctionsIncludePhase(__LINE__, 'runtime_function_merge_done', $startTime);
}

$GLOBALS["FUNCTIONS"] = array_values(array_filter($GLOBALS["FUNCTIONS"], function ($functionEntry) {
    $codeName = getFunctionCodeName($functionEntry["name"] ?? "");
    $codeName = $codeName === false ? false : dialecticNormalizeActionCodeName($codeName);
    return $codeName !== false && isset(dialecticCanonicalActionCodeSet()[$codeName]);
}));
$GLOBALS["ENABLED_FUNCTIONS"] = dialecticFilterCanonicalActionCodeList($GLOBALS["ENABLED_FUNCTIONS"] ?? []);

if (file_exists(__DIR__ . DIRECTORY_SEPARATOR . "../prompts/prompts_custom.php")) {
    require __DIR__ . DIRECTORY_SEPARATOR . "../prompts/prompts_custom.php";
}

dialecticTraceFunctionsIncludePhase(__LINE__, 'prompt_overrides_loaded', $startTime);

// Delete non wanted functions

dialecticTraceFunctionsIncludePhase(__LINE__, 'enabled_function_filter_start', $startTime);
$enabledFunctionSet = array_fill_keys($GLOBALS["ENABLED_FUNCTIONS"], true);
foreach ($GLOBALS["FUNCTIONS"] as $n => $v) {
    $codeName = getFunctionCodeName($v["name"]);
    if ($codeName === false) {
        error_log("[FUNCTION] Warning: Could not get code name for function: {$v["name"]}");
        continue;
    }
    $codeName = dialecticNormalizeActionCodeName($codeName);
    if (!isset($enabledFunctionSet[$codeName])) {
        error_log("[FUNCTION] Removing $n {$v["name"]}:$codeName");
        unset($GLOBALS["FUNCTIONS"][$n]);
    } 
    
    $GLOBALS["DEFINED_FUNCTIONS"][] = $codeName;
    
}

dialecticTraceFunctionsIncludePhase(__LINE__, 'enabled_function_filter_done', $startTime);

dialecticTraceFunctionsIncludePhase(__LINE__, 'bug_func_write_start', $startTime);
Logger::debug(json_encode([
    "function_count" => count($GLOBALS["FUNCTIONS"]),
    "enabled_functions" => array_values($GLOBALS["ENABLED_FUNCTIONS"]),
], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), __DIR__ . "/../log/bug_func.txt");
dialecticTraceFunctionsIncludePhase(__LINE__, 'bug_func_write_done', $startTime);

$GLOBALS["FUNCTIONS"] = array_values($GLOBALS["FUNCTIONS"]); //Get rid of array keys

dialecticTraceFunctionsIncludePhase(__LINE__, 'functions_reindexed', $startTime);


// POST FILTER HOOK. Used for cleaning actions returned by LLM
// We are putting this here because we want this actions to be executed serverside via ScriptProxy
// They will NOT be sent to DLL for execution using the standard method

require_once __DIR__ . "/../lib/scriptproxy_fallout.php";
require_once __DIR__ . "/../lib/core/activity_status.php";
require_once __DIR__ . "/../lib/narrator_actions.php";

dialecticTraceFunctionsIncludePhase(__LINE__, 'post_filter_dependencies_loaded', $startTime);

// action_post_process_fnct_ex is an arrya containing functions that process the actions after they are generated by the LLM
// more working examples in data_functions.php
$GLOBALS["action_post_process_fnct_ex"][]=function($actions) {
    
    global $gameRequest;

    require_once __DIR__ . DIRECTORY_SEPARATOR . ".." . DIRECTORY_SEPARATOR . "lib" . DIRECTORY_SEPARATOR . "dialectic_command_payload.php";

    $actionsCopy=$actions;
    foreach ($actions as $n=>$action) {
        
        $decodedAction = dialecticDecodeActionLine(strval($action));
        $actionCodeNameRaw = trim(strval($decodedAction['action'] ?? ''));
        if ($actionCodeNameRaw === '') {
            continue;
        }
        
        if ($actionCodeNameRaw !== '') {
            if (dialecticActionCatalogExecuteScriptProxyAction($action)) {
                unset($actionsCopy[$n]);
                continue;
            }
        }
    }

    return $actionsCopy;
};

$GLOBALS["action_post_process_fnct_ex"][]=function($actions) {
    return dialecticPostProcessNarratorActions(is_array($actions) ? $actions : []);
};

error_log("TRACE:\t".__LINE__. "\t".__FILE__.":\t".(microtime(true) - $startTime));

?>
