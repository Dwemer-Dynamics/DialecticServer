<?php
/**
 * Game Data Endpoint
 * 
 * Handles JSON POST requests for equipment, inventory, skills, and stats updates
 * for both NPCs and player data.
 * 
 * This endpoint does not trigger LLM requests - it only updates database metadata.
 */

error_reporting(E_ALL);
ini_set('display_errors', '0'); // Don't output errors to response body
require_once(__DIR__ . "/lib/runtime_bootstrap.php");
dialecticRuntimeBootstrap(__DIR__, [
    'load_general_settings' => true,
    'load_stt_connector' => false,
    'load_player_name' => true,
    'load_narrator' => true,
    'run_db_updates' => false,
]);
$GLOBALS["db"] = $GLOBALS["db"] ?? new sql();
require_once(__DIR__ . "/lib/core/npc_master.class.php");
require_once(__DIR__ . "/lib/core/activity_status.php");
require_once(__DIR__ . "/lib/core/game_plugins.php");
require_once(__DIR__ . "/lib/dialectic_runtime.php");
require_once(__DIR__ . "/lib/logger.php");
require_once(__DIR__ . "/lib/settings.php");
require_once(__DIR__ . "/lib/save_rollback.php");
require_once(__DIR__ . "/lib/auto_greeting.php");

$requestStart = microtime(true);

function dialecticGameDataRespond(int $statusCode, bool $ok, string $error = '', array $extra = []): void
{
    if (PHP_SAPI !== 'cli' && !headers_sent()) {
        header('Content-Type: application/json; charset=utf-8');
    }
    http_response_code($statusCode);

    $payload = array_merge([
        'schema' => 'dialectic.gamedata.response.v1',
        'request_id' => class_exists('Logger') ? Logger::getRequestId() : '',
        'ok' => $ok,
        'status' => $ok ? 'OK' : 'ERROR',
    ], $extra);

    if ($error !== '') {
        $payload['error'] = $error;
    }

    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL;
}

// Only accept POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    Logger::warn("[gamedata.php] Rejected non-POST request" . Logger::formatContext([
        "method" => $_SERVER['REQUEST_METHOD'] ?? "",
    ]));
    dialecticGameDataRespond(405, false, "Method Not Allowed", [
        "method" => $_SERVER['REQUEST_METHOD'] ?? "",
    ]);
    exit;
}

// Game data transport is JSON-only.
$contentType = strtolower(strval($_SERVER['CONTENT_TYPE'] ?? $_SERVER['HTTP_CONTENT_TYPE'] ?? ''));
if (strpos($contentType, 'application/json') === false) {
    Logger::warn("[gamedata.php] Rejected non-JSON request" . Logger::formatContext([
        "content_type" => $contentType,
    ]));
    dialecticGameDataRespond(415, false, "Unsupported Media Type", [
        "expected" => "application/json",
        "content_type" => $contentType,
    ]);
    exit;
}

// Parse JSON body
$json = file_get_contents('php://input');
$data = json_decode($json, true);

if (!$data || !isset($data['type'])) {
    Logger::error("[gamedata.php] Bad request - missing type field" . Logger::formatContext([
        "bytes" => strlen($json),
        "payload" => Logger::summarizePayload($json),
    ]));
    dialecticGameDataRespond(400, false, "Missing type field", [
        "bytes" => strlen($json),
    ]);
    exit;
}

if (!empty($data['request_id'])) {
    Logger::setRequestId((string)$data['request_id']);
} else {
    Logger::bootstrapRequestId("gd");
}

Logger::phaseStart("gamedata", [
    "type" => $data['type'] ?? "",
    "actor" => $data['actor_name'] ?? "",
    "actor_type" => $data['actor_type'] ?? "",
]);
Logger::debug("[gamedata.php] Payload summary" . Logger::formatContext([
    "type" => $data['type'] ?? "",
    "payload" => Logger::summarizePayload($data),
]));

if (($data['type'] ?? '') !== 'dialogue_delivery') {
    dialecticMaybeHandleIncomingGametsRollback($data['gamets'] ?? 0, 'gamedata:' . ($data['type'] ?? 'unknown'), false);
}

// Types that operate on global data and do not require an actor
$actorlessTypes = ['market_stock', 'activity_status_bulk', 'loaded_plugins', 'world_locations', 'world_factions', 'dialogue_delivery', 'world_context', 'nearby_actors', 'nearby_items', 'points_of_interest', 'active_quests', 'trade_summary'];

// Validate required fields (skipped for actorless types)
if (!in_array($data['type'], $actorlessTypes)) {
    if (!isset($data['actor_name']) || !isset($data['actor_type'])) {
        Logger::error("[gamedata.php] Bad request - missing actor_name or actor_type" . Logger::formatContext([
            "type" => $data['type'] ?? "",
            "payload" => Logger::summarizePayload($data),
        ]));
        Logger::phaseEnd("gamedata", ["status" => "bad_request"], "warn");
        dialecticGameDataRespond(400, false, "Missing actor_name or actor_type", [
            "type" => $data['type'] ?? "",
        ]);
        exit;
    }
}

$npcMaster = new NpcMaster();

try {
    $responseExtra = [];
    if (strtolower(trim((string)($data['actor_type'] ?? ''))) === 'player') {
        dialecticMaybeSyncPlayerNameFromGameData($data);
    }

    switch ($data['type']) {
        case 'actor_profile':
            handleActorProfileUpdate($data, $npcMaster);
            break;
        case 'equipment':
            handleEquipmentUpdate($data, $npcMaster);
            break;
        case 'inventory':
            handleInventoryUpdate($data, $npcMaster);
            break;
        case 'npc_voice':
            handleNpcVoiceUpdate($data, $npcMaster);
            break;
        case 'skills':
            handleSkillsUpdate($data, $npcMaster);
            break;
        case 'stats':
            handleStatsUpdate($data, $npcMaster);
            break;
        case 'fallout_stats':
            handleFalloutStatsUpdate($data);
            break;
        case 'furniture':
            handleFurnitureUpdate($data, $npcMaster);
            break;
        case 'activity_status':
            handleActivityStatusUpdate($data, $npcMaster);
            break;
        case 'activity_status_bulk':
            handleActivityStatusBulkUpdate($data, $npcMaster);
            break;
        case 'market_stock':
            handleMarketStockUpdate($data);
            break;
        case 'loaded_plugins':
            handleLoadedPluginsUpdate($data);
            break;
        case 'world_locations':
            handleWorldLocationsUpdate($data);
            break;
        case 'world_factions':
            handleWorldFactionsUpdate($data);
            break;
        case 'dialogue_delivery':
            handleDialogueDeliveryUpdate($data);
            break;
        case 'world_context':
            handleWorldContextUpdate($data);
            break;
        case 'nearby_actors':
            $responseExtra = handleNearbyActorsUpdate($data, $npcMaster);
            break;
        case 'nearby_items':
            handleNearbyItemsUpdate($data);
            break;
        case 'points_of_interest':
            handlePointsOfInterestUpdate($data);
            break;
        case 'active_quests':
            handleActiveQuestsUpdate($data);
            break;
        case 'trade_summary':
            handleTradeSummaryUpdate($data);
            break;
        default:
            Logger::error("[gamedata.php] Bad request - unknown type" . Logger::formatContext([
                "type" => $data['type'],
                "payload" => Logger::summarizePayload($data),
            ]));
            Logger::phaseEnd("gamedata", ["status" => "unknown_type"], "warn");
            dialecticGameDataRespond(400, false, "Unknown type", [
                "type" => $data['type'],
            ]);
            exit;
    }
    
    Logger::phaseEnd("gamedata", [
        "status" => "ok",
        "type" => $data['type'] ?? "",
        "elapsed_total_ms" => round((microtime(true) - $requestStart) * 1000, 2),
    ]);
    dialecticGameDataRespond(200, true, "", array_merge([
        "type" => $data['type'] ?? "",
        "elapsed_total_ms" => round((microtime(true) - $requestStart) * 1000, 2),
    ], $responseExtra));
} catch (Exception $e) {
    Logger::error("[gamedata.php] Error processing request" . Logger::formatContext([
        "type" => $data['type'] ?? "",
        "error" => $e->getMessage(),
    ]));
    Logger::phaseEnd("gamedata", [
        "status" => "error",
        "type" => $data['type'] ?? "",
        "elapsed_total_ms" => round((microtime(true) - $requestStart) * 1000, 2),
    ], "error");
    dialecticGameDataRespond(500, false, "Internal Server Error", [
        "type" => $data['type'] ?? "",
    ]);
}

/**
 * Handle equipment update
 */
function handleEquipmentUpdate(array $data, NpcMaster $npcMaster): void {
    $actorName = $data['actor_name'];
    $actorType = $data['actor_type'];
    
    if (!isset($data['equipment'])) {
        Logger::error("[gamedata.php] Equipment update missing equipment data");
        return;
    }
    
    $equipment = $data['equipment'];
    
    // If this is a player, save directly to core_player table (player doesn't need NPC record)
    if ($actorType === 'player') {
        try {
            require_once(__DIR__ . "/lib/core/player.class.php");
            $player = new Player();

            $equipmentData = buildEquipmentMetadataValue($equipment);
            $player->setJson('equipment', $equipmentData);
            $player->setJson('equipment_structured', cleanBridgePayloadValue($equipment));
            Logger::debug("[gamedata.php] Saved player equipment to core_player table");
        } catch (Exception $e) {
            Logger::warn("[gamedata.php] Could not save player equipment to core_player: " . $e->getMessage());
        }
        
        // Keep NPC metadata in sync when the player also has a profile row.
        $currentData = $npcMaster->getByName($actorName);
        if ($currentData) {
            $npcMaster->updateMetadataKeysByName($actorName, [
                'equipment' => buildEquipmentMetadataValue($equipment),
                'equipment_structured' => cleanBridgePayloadValue($equipment),
            ]);
        }
        
        return; // Done with player, exit early
    }
    
    // Handle NPC equipment
    $currentData = findNpcForGameData($npcMaster, $actorName, $data);
    
    if (!$currentData) {
        // NPC not in database yet - this is normal, they haven't been encountered
        return;
    }
    
    $npcMaster->updateMetadataKeysByName($actorName, [
        'equipment' => buildEquipmentMetadataValue($equipment),
        'equipment_structured' => cleanBridgePayloadValue($equipment),
    ]);
    
    Logger::debug("[gamedata.php] Updated equipment for {$actorType}: {$actorName}");
}

function handleFurnitureUpdate(array $data, NpcMaster $npcMaster): void
{
    $currentData = $npcMaster->getByName($data['actor_name']);
    if (!$currentData) {
        return;
    }

    dialecticApplyNpcMetadataUpdatesByName($data['actor_name'], [
        'activity_status' => [
        'furniture_name' => $data['furniture'] ?? '',
        'timestamp' => $data['timestamp'] ?? dialecticActivityStatusNowMs(),
        'gamets' => $data['gamets'] ?? 0,
        ],
    ]);
}

function handleActivityStatusUpdate(array $data, NpcMaster $npcMaster): void
{
    $currentData = $npcMaster->getByName($data['actor_name']);
    if (!$currentData) {
        return;
    }

    dialecticApplyNpcMetadataUpdatesByName($data['actor_name'], [
        'activity_status' => $data,
    ]);
}

function handleActivityStatusBulkUpdate(array $data, NpcMaster $npcMaster): void
{
    if (!array_key_exists('statuses', $data) || !is_array($data['statuses'])) {
        Logger::warn("[gamedata.php] activity_status_bulk missing statuses payload");
        return;
    }

    if ($data['statuses'] === []) {
        Logger::debug("[gamedata.php] activity_status_bulk received an empty status list");
        return;
    }

    foreach ($data['statuses'] as $statusRow) {
        if (!is_array($statusRow) || empty($statusRow['actor_name'])) {
            continue;
        }

        $currentData = $npcMaster->getByName($statusRow['actor_name']);
        if (!$currentData) {
            continue;
        }

        dialecticApplyNpcMetadataUpdatesByName($statusRow['actor_name'], [
            'activity_status' => $statusRow,
        ]);
    }
}

function handleLoadedPluginsUpdate(array $data): void
{
    if (empty($data['plugins']) || !is_array($data['plugins'])) {
        Logger::warn("[gamedata.php] loaded_plugins missing plugins payload");
        return;
    }

    $pluginCount = dialecticReplaceLoadedGamePlugins($data['plugins']);
    Logger::debug("[gamedata.php] Updated loaded plugin manifest ({$pluginCount} plugins)");
}

function handleWorldLocationsUpdate(array $data): void
{
    $db = $GLOBALS['db'];
    $locations = $data['locations'] ?? [];
    if (!is_array($locations)) {
        Logger::warn("[gamedata.php] world_locations missing locations payload");
        return;
    }

    if (filter_var($data['replace'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
        $db->execQuery("TRUNCATE TABLE public.locations");
    }

    $saved = 0;
    foreach ($locations as $location) {
        if (!is_array($location)) {
            continue;
        }

        $name = cleanBridgeString($location['name'] ?? '');
        $formid = normalizeWorldDataFormIdBigint($location['formid'] ?? $location['formid_hex'] ?? '');
        if ($name === '' || $formid === null) {
            continue;
        }

        $worldspace = cleanBridgeString($location['worldspace'] ?? $location['region'] ?? '');
        $tags = cleanWorldDataListField($location['tags'] ?? '');
        $refs = cleanWorldDataListField($location['refs'] ?? '');
        $isInterior = filter_var($location['is_interior'] ?? false, FILTER_VALIDATE_BOOLEAN) ? '1' : '0';
        $cleared = filter_var($location['cleared'] ?? false, FILTER_VALIDATE_BOOLEAN) ? 'TRUE' : 'FALSE';
        $coordsSql = worldDataPointSql($location);

        $nameSql = dialectic_db_escape($db, $name);
        $worldspaceSql = dialectic_db_escape($db, $worldspace);
        $tagsSql = dialectic_db_escape($db, $tags);
        $refsSql = dialectic_db_escape($db, $refs);

        $exists = $db->fetchOne("SELECT 1 FROM public.locations WHERE formid={$formid} LIMIT 1");
        if ($exists) {
            $db->execQuery(
                "UPDATE public.locations SET " .
                "name='{$nameSql}', worldspace='{$worldspaceSql}', tags='{$tagsSql}', " .
                "is_interior={$isInterior}, coords={$coordsSql}, " .
                "refs='{$refsSql}', cleared={$cleared}, updated_at=now() " .
                "WHERE formid={$formid}"
            );
        } else {
            $db->execQuery(
                "INSERT INTO public.locations " .
                "(name, formid, worldspace, tags, is_interior, coords, refs, cleared, updated_at) VALUES " .
                "('{$nameSql}', {$formid}, '{$worldspaceSql}', '{$tagsSql}', " .
                "{$isInterior}, {$coordsSql}, '{$refsSql}', {$cleared}, now())"
            );
        }
        ++$saved;
    }

    Logger::debug("[gamedata.php] Updated world locations ({$saved} rows)");
}

function handleWorldFactionsUpdate(array $data): void
{
    $db = $GLOBALS['db'];
    $factions = $data['factions'] ?? [];
    if (!is_array($factions)) {
        Logger::warn("[gamedata.php] world_factions missing factions payload");
        return;
    }

    if (filter_var($data['replace'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
        $db->execQuery("TRUNCATE TABLE public.factions");
    }

    $saved = 0;
    foreach ($factions as $faction) {
        if (!is_array($faction)) {
            continue;
        }

        $name = cleanBridgeString($faction['name'] ?? '');
        $formid = dialecticNormalizeHexIdentifier($faction['formid'] ?? '', 8);
        if ($name === '' || $formid === '') {
            continue;
        }

        $vendorCont = dialecticNormalizeHexIdentifier($faction['vendor_cont'] ?? '', 8);
        $nameSql = dialectic_db_escape($db, $name);
        $formidSql = dialectic_db_escape($db, $formid);
        $vendorContSql = dialectic_db_escape($db, $vendorCont);

        $db->execQuery(
            "INSERT INTO public.factions (name, formid, vendor_cont) VALUES " .
            "('{$nameSql}', '{$formidSql}', '{$vendorContSql}') " .
            "ON CONFLICT (formid) DO UPDATE SET name=EXCLUDED.name, vendor_cont=EXCLUDED.vendor_cont"
        );
        ++$saved;
    }

    Logger::debug("[gamedata.php] Updated world factions ({$saved} rows)");
}

function handleDialogueDeliveryUpdate(array $data): void
{
    $db = $GLOBALS['db'];
    $state = strtolower(trim((string)($data['state'] ?? '')));
    if ($state === 'dropped') {
        $state = 'aborted';
    }
    if ($state === 'playing') {
        Logger::debug("[gamedata.php] dialogue_delivery playing received as runtime telemetry; eventlog remains emitted");
        return;
    }

    $allowedStates = ['emitted', 'spoken', 'text_only', 'aborted', 'failed'];
    if (!in_array($state, $allowedStates, true)) {
        Logger::warn("[gamedata.php] dialogue_delivery ignored invalid state '{$state}'");
        return;
    }

    $utteranceId = trim((string)($data['utterance_id'] ?? ''));
    $speaker = trim((string)($data['speaker'] ?? $data['actor_name'] ?? ''));
    $text = trim((string)($data['text'] ?? ''));
    Logger::info("[delivery] dialogue_delivery received" . Logger::formatContext([
        "state" => $state,
        "speaker" => $speaker,
        "utterance_id" => $utteranceId,
        "text_preview" => Logger::summarizePayload($text, 120),
    ]));

    $stateEscaped = dialectic_db_escape($db, $state);
    $nonAbortedSql = "COALESCE(delivery_state, 'emitted')<>'aborted'";

    if ($utteranceId !== '') {
        $utteranceEscaped = dialectic_db_escape($db, $utteranceId);
        $db->update(
            'public.eventlog',
            "delivery_state='{$stateEscaped}'",
            "type='chat' AND utterance_id='{$utteranceEscaped}' AND {$nonAbortedSql}"
        );
        Logger::debug("[gamedata.php] dialogue_delivery {$state} for utterance {$utteranceId}");
        return;
    }

    if ($text === '') {
        Logger::warn("[gamedata.php] dialogue_delivery missing utterance_id and text");
        return;
    }

    $where = "type='chat' AND {$nonAbortedSql}";
    $textEscaped = dialectic_db_escape($db, $text);
    $where .= " AND data='{$textEscaped}'";
    if ($speaker !== '') {
        $speakerEscaped = dialectic_db_escape($db, $speaker);
        $where .= " AND people ILIKE '%{$speakerEscaped}%'";
    }

    $row = $db->fetchOne("SELECT rowid FROM public.eventlog WHERE {$where} ORDER BY rowid DESC LIMIT 1");
    $rowId = intval($row['rowid'] ?? 0);
    if ($rowId <= 0) {
        $playerName = trim((string)($GLOBALS['PLAYER_NAME'] ?? ''));
        if ($playerName !== '' && strcasecmp($speaker, $playerName) === 0) {
            Logger::debug("[gamedata.php] dialogue_delivery ignored player TTS acknowledgement without a chat row");
            return;
        }
        Logger::warn("[gamedata.php] dialogue_delivery could not match chat row for speaker='{$speaker}'");
        return;
    }

    $db->update('public.eventlog', "delivery_state='{$stateEscaped}'", "rowid={$rowId} AND {$nonAbortedSql}");
    Logger::debug("[gamedata.php] dialogue_delivery {$state} for row {$rowId}");
}

function dialecticResolveNearestExteriorLocationName(string $location, string $worldspace, array $data): string
{
    $db = $GLOBALS['db'] ?? null;
    if (!$db || $worldspace === '') {
        return $location;
    }

    $isGenericLocation = $location === '' ||
        strcasecmp($location, 'Unknown Location') === 0 ||
        strcasecmp($location, 'Unknown') === 0 ||
        strcasecmp($location, $worldspace) === 0 ||
        strcasecmp($location, 'Mojave Wasteland') === 0;
    if (!$isGenericLocation) {
        return $location;
    }

    $position = $data['player_position'] ?? [];
    if (!is_array($position) || !filter_var($position['known'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
        return $location;
    }

    $playerX = floatval($position['x'] ?? 0);
    $playerY = floatval($position['y'] ?? 0);
    if (!is_finite($playerX) || !is_finite($playerY)) {
        return $location;
    }

    try {
        $escapedWorldspace = $db->escape($worldspace);
        $rows = $db->fetchAll(
            "SELECT name, coords
               FROM locations
              WHERE COALESCE(is_interior, 0)=0
                AND coords IS NOT NULL
                AND (worldspace IS NULL OR worldspace='' OR worldspace='{$escapedWorldspace}')
              LIMIT 2000"
        );
    } catch (\Throwable $e) {
        Logger::warn("[gamedata.php] Could not resolve nearest location: " . $e->getMessage());
        return $location;
    }

    $bestName = '';
    $bestDistanceSq = PHP_FLOAT_MAX;
    foreach ($rows as $row) {
        $name = cleanBridgeString($row['name'] ?? '');
        $coords = trim((string)($row['coords'] ?? ''));
        if ($name === '' || $coords === '') {
            continue;
        }
        if (!preg_match('/\(?\s*(-?\d+(?:\.\d+)?)\s*,\s*(-?\d+(?:\.\d+)?)\s*\)?/', $coords, $matches)) {
            continue;
        }

        $dx = $playerX - floatval($matches[1]);
        $dy = $playerY - floatval($matches[2]);
        $distanceSq = ($dx * $dx) + ($dy * $dy);
        if ($distanceSq < $bestDistanceSq) {
            $bestDistanceSq = $distanceSq;
            $bestName = $name;
        }
    }

    if ($bestName !== '' && sqrt($bestDistanceSq) <= 6000.0) {
        Logger::debug("[gamedata.php] Resolved world_context location '{$location}' to nearest marker '{$bestName}'");
        return $bestName;
    }

    return $location;
}

function handleWorldContextUpdate(array $data): void
{
    $db = $GLOBALS['db'];

    $location = cleanBridgeString($data['location'] ?? '');
    $worldspace = cleanBridgeString($data['worldspace'] ?? '');
    if ($location === '') {
        $location = $worldspace !== '' ? $worldspace : 'Unknown Location';
    }

    $weather = cleanBridgeString($data['weather'] ?? '');
    $isInterior = filter_var($data['is_interior'] ?? false, FILTER_VALIDATE_BOOLEAN);
    $rawLocation = $location;
    if (!$isInterior) {
        $location = dialecticResolveNearestExteriorLocationName($location, $worldspace, $data);
    }
    $state = $isInterior ? 'interior' : 'outdoors';
    $ts = intval($data['ts'] ?? time());
    if ($ts <= 0) {
        $ts = time();
    }
    $gamets = intval($data['gamets'] ?? 0);
    if ($gamets <= 0) {
        $gamets = $ts;
    }

    $context = "(Context location: {$location}, State: {$state}";
    if ($worldspace !== '' && strcasecmp($worldspace, $location) !== 0) {
        $context .= ", Worldspace: {$worldspace}";
    }
    if ($weather !== '') {
        $context .= ", current weather: {$weather}";
    }
    $context .= ")";

    $payload = $data;
    if ($rawLocation !== '' && strcasecmp($rawLocation, $location) !== 0) {
        $payload['raw_location'] = $rawLocation;
    }
    $payload['location'] = $location;
    $payload['worldspace'] = $worldspace;

    $payloadJson = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    $db->insert('eventlog', [
        'ts' => $ts,
        'gamets' => $gamets,
        'type' => 'world_context',
        'data' => $context,
        'sess' => 'dialectic',
        'localts' => time(),
        'people' => '|The Narrator|',
        'location' => $context,
        'party' => $payloadJson ?: '',
        'delivery_state' => 'received',
    ]);

    Logger::debug("[gamedata.php] Updated world_context: {$context}");
}

function dialecticNearbyActorIsSceneParticipant(array $actor): bool
{
    $eligible = filter_var($actor['eligible'] ?? true, FILTER_VALIDATE_BOOLEAN);
    if (!$eligible) {
        return false;
    }

    $canHearPlayer = filter_var($actor['can_hear_player'] ?? false, FILTER_VALIDATE_BOOLEAN);
    $status = strtolower(trim((string)($actor['status'] ?? '')));
    $reason = strtolower(trim((string)($actor['spatial_reason'] ?? '')));

    if ($canHearPlayer || strpos($status, 'can hear you') === 0) {
        return true;
    }

    $blockedTokens = [
        'far away',
        'too_far',
        'too far',
        'too_quiet',
        "can't hear",
        'cannot hear',
        'closed_door',
        'different_interior_cells',
        'interior_exterior_boundary',
        'navmesh_no_path',
        'path_unavailable',
        'path_ratio_blocked',
        'path_ratio_distance_blocked',
        'dead',
        'disabled',
        'unavailable',
    ];

    $combined = trim($status . ' ' . $reason);
    foreach ($blockedTokens as $token) {
        if ($token !== '' && strpos($combined, $token) !== false) {
            return false;
        }
    }

    return true;
}

function handleNearbyActorsUpdate(array $data, NpcMaster $npcMaster): array
{
    $db = $GLOBALS['db'];
    $previousRow = $db->fetchOne("SELECT party FROM eventlog WHERE type='nearby_actors' ORDER BY rowid DESC LIMIT 1");
    $previousSnapshot = is_array($previousRow)
        ? (json_decode((string)($previousRow['party'] ?? ''), true) ?: [])
        : [];
    $actors = $data['actors'] ?? [];
    if (!is_array($actors)) {
        $actors = [];
    }

    $ts = intval($data['ts'] ?? time());
    if ($ts <= 0) {
        $ts = time();
    }
    $gamets = intval($data['gamets'] ?? 0);
    if ($gamets <= 0) {
        $gamets = $ts;
    }

    $player = cleanBridgeString($data['player'] ?? '');
    $peopleNames = [];
    foreach ($actors as $actor) {
        if (!is_array($actor)) {
            continue;
        }

        $name = cleanBridgeString($actor['name'] ?? '');
        if ($name === '' || $name === '<no name>') {
            continue;
        }

        if (!dialecticNearbyActorIsSceneParticipant($actor)) {
            continue;
        }

        if (!in_array($name, $peopleNames, true)) {
            $peopleNames[] = $name;
        }
    }

    if ($player !== '' && !in_array($player, $peopleNames, true)) {
        $peopleNames[] = $player;
    }

    $ensureActorProfileFromPayload = static function (array $actor) use ($db): void {
        $name = cleanBridgeString($actor['name'] ?? '');
        if ($name === '' || $name === '<no name>' || strcasecmp($name, 'The Narrator') === 0) {
            return;
        }

        $profileFields = [
            'refid' => cleanBridgeString($actor['refid'] ?? ''),
            'baseid' => cleanBridgeString($actor['baseid'] ?? ''),
            'gender' => cleanBridgeString($actor['gender'] ?? ''),
            'race' => cleanBridgeString($actor['race'] ?? ''),
            'voice' => cleanBridgeString($actor['voiceid'] ?? ''),
            'voice_formid' => cleanBridgeString($actor['voice_formid'] ?? ''),
            'voice_name' => cleanBridgeString($actor['voice_name'] ?? ''),
            'source' => 'nearby_actors',
        ];

        dialectic_ensure_npc($db, $name, $profileFields['refid'], $profileFields);

        $baseid = trim((string)$profileFields['baseid']);
        if ($baseid !== '') {
            $db->execQuery("
                UPDATE public.core_npc_master
                SET base = COALESCE(NULLIF(base, ''), '" . $db->escape($baseid) . "')
                WHERE npc_name = '" . $db->escape($name) . "'
            ");
        }
    };

    foreach ($actors as $actor) {
        if (!is_array($actor)) {
            continue;
        }

        $eligible = filter_var($actor['eligible'] ?? true, FILTER_VALIDATE_BOOLEAN);
        if ($eligible) {
            $ensureActorProfileFromPayload($actor);
        }
    }

    $autoGreeting = dialecticBuildAutoGreetingDirective($data, $previousSnapshot, $npcMaster);

    $peoplePipe = empty($peopleNames) ? '' : '|' . implode('|', $peopleNames) . '|';
    $payloadJson = json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

    $baseRow = [
        'ts' => $ts,
        'gamets' => $gamets,
        'sess' => 'dialectic',
        'localts' => time(),
        'people' => $peoplePipe,
        'location' => '',
        'party' => $payloadJson ?: '',
        'delivery_state' => 'received',
    ];

    $db->insert('eventlog', array_merge($baseRow, [
        'type' => 'nearby_actors',
        'data' => "nearby actors: {$peoplePipe}",
    ]));

    $buildPartyRows = static function ($members): array {
        $partyRows = [];
        if (!is_array($members)) {
            return $partyRows;
        }

        foreach ($members as $member) {
            if (!is_array($member)) {
                continue;
            }

            $name = cleanBridgeString($member['name'] ?? '');
            if ($name === '' || $name === '<no name>') {
                continue;
            }

            $partyRows[$name] = [
                'name' => $name,
                'level' => intval($member['level'] ?? 0),
                'gender' => cleanBridgeString($member['gender'] ?? ''),
                'race' => cleanBridgeString($member['race'] ?? ''),
                'refid' => cleanBridgeString($member['refid'] ?? ''),
                'baseid' => cleanBridgeString($member['baseid'] ?? ''),
                'voiceid' => cleanBridgeString($member['voiceid'] ?? ''),
            ];
        }

        return $partyRows;
    };

    $partyMembers = $data['party_members'] ?? [];
    if (is_array($partyMembers)) {
        $partyRows = $buildPartyRows($partyMembers);
        $partySource = 'party_members';

        if (empty($partyRows) && is_array($actors)) {
            $actorPartyMembers = [];
            foreach ($actors as $actor) {
                if (!is_array($actor)) {
                    continue;
                }

                $isTeammate = filter_var($actor['is_player_teammate'] ?? false, FILTER_VALIDATE_BOOLEAN);
                if ($isTeammate) {
                    $actorPartyMembers[] = $actor;
                }
            }
            $partyRows = $buildPartyRows($actorPartyMembers);
            $partySource = 'actors.is_player_teammate';
        }

        if (!empty($partyRows)) {
            $partyFragments = [];
            foreach ($partyRows as $partyRow) {
                $partyFragments[] = json_encode($partyRow, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            }
            $partyValue = implode(',', $partyFragments) . ',';
            $db->upsertRowOnConflict('conf_opts', [
                'id' => 'CurrentParty',
                'value' => $partyValue,
            ], 'id');
            Logger::debug("[gamedata.php] Updated CurrentParty from nearby_actors {$partySource}: " . implode(', ', array_keys($partyRows)));
        } else {
            Logger::debug("[gamedata.php] Kept existing CurrentParty; nearby_actors {$partySource} had no party members");
        }
    }

    Logger::debug("[gamedata.php] Updated nearby_actors from structured payload: {$peoplePipe}");
    if ($autoGreeting !== null) {
        Logger::info("[AUTO_GREETING] Queued greeting directive" . Logger::formatContext([
            'npc' => $autoGreeting['npc'] ?? '',
            'refid' => $autoGreeting['npc_refid'] ?? '',
            'gamets' => $autoGreeting['gamets'] ?? 0,
            'runtime_generation' => $autoGreeting['runtime_generation'] ?? 0,
        ]));
        return ['auto_greeting' => $autoGreeting];
    }

    return [];
}

function dialecticNearbyItemFirstString(array $item, array $keys): string
{
    foreach ($keys as $key) {
        if (!array_key_exists($key, $item)) {
            continue;
        }
        $value = cleanBridgeString($item[$key]);
        if ($value !== '') {
            return $value;
        }
    }

    return '';
}

function dialecticNearbyItemNormalize(array $item): ?array
{
    $refId = dialecticNearbyItemFirstString($item, ['refid', 'ref_id', 'reference_id', 'reference', 'id']);
    $baseId = dialecticNearbyItemFirstString($item, ['baseid', 'base_id', 'formid', 'form_id', 'base_formid']);
    $name = dialecticNearbyItemFirstString($item, ['name', 'item_name', 'display_name', 'base_name']);

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

    return [
        'refid' => $refId,
        'baseid' => $baseId,
        'name' => $name,
        'type' => intval($item['type'] ?? $item['base_type'] ?? 0),
        'distance' => floatval($item['distance'] ?? 0),
        'cell_formid' => cleanBridgeString($item['cell_formid'] ?? $item['cell'] ?? ''),
        'looking_at' => dialecticTruthy($item['looking_at'] ?? $item['lookingAt'] ?? false),
        'stealing' => dialecticTruthy($item['stealing'] ?? false),
        'holding' => dialecticTruthy($item['holding'] ?? false),
    ];
}

function dialecticNearbyItemsAppendUnique(array &$items, array $item): void
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

function dialecticNormalizeNearbyItemsContainer($items): array
{
    if (!is_array($items)) {
        return [];
    }

    $hasItemFields = false;
    foreach (['refid', 'ref_id', 'reference_id', 'reference', 'id', 'baseid', 'base_id', 'formid', 'form_id', 'name', 'item_name', 'display_name'] as $field) {
        if (array_key_exists($field, $items)) {
            $hasItemFields = true;
            break;
        }
    }

    if ($hasItemFields) {
        return [$items];
    }

    return array_values($items);
}

function handleNearbyItemsUpdate(array $data): void
{
    $db = $GLOBALS['db'];
    $rawItems = $data['items'] ?? [];
    $items = dialecticNormalizeNearbyItemsContainer($rawItems);

    $ts = intval($data['ts'] ?? time());
    if ($ts <= 0) {
        $ts = time();
    }
    $gamets = intval($data['gamets'] ?? 0);
    if ($gamets <= 0) {
        $gamets = $ts;
    }

    $normalizedItems = [];
    $skippedSamples = [];
    foreach ($items as $item) {
        if (!is_array($item)) {
            if (count($skippedSamples) < 3) {
                $skippedSamples[] = ['non_array_item' => $item];
            }
            continue;
        }

        $normalized = dialecticNearbyItemNormalize($item);
        if ($normalized === null) {
            if (count($skippedSamples) < 3) {
                $skippedSamples[] = $item;
            }
            continue;
        }

        dialecticNearbyItemsAppendUnique($normalizedItems, $normalized);
    }

    if (isset($data['held_item']) && is_array($data['held_item'])) {
        $heldItem = dialecticNearbyItemNormalize($data['held_item']);
        if ($heldItem !== null) {
            $heldItem['holding'] = true;
            dialecticNearbyItemsAppendUnique($normalizedItems, $heldItem);
            $data['held_item'] = $heldItem;
        }
    }

    $itemCount = count($normalizedItems);
    $data['items'] = $normalizedItems;

    if (!empty($skippedSamples)) {
        Logger::debug("[gamedata.php] Skipped malformed nearby item rows" . Logger::formatContext([
            'skipped_count' => count($skippedSamples),
            'samples' => Logger::summarizePayload($skippedSamples),
        ]));
    }
    if ($itemCount === 0 && !empty($rawItems)) {
        $data['raw_items_debug'] = $rawItems;
        Logger::debug("[gamedata.php] nearby_items normalized to zero after processing" . Logger::formatContext([
            'raw_items_sample' => substr(json_encode(array_slice(dialecticNormalizeNearbyItemsContainer($rawItems), 0, 3), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), 0, 1200),
        ]));
    }

    $player = cleanBridgeString($data['player'] ?? '');
    $peoplePipe = $player !== '' ? "|{$player}|" : '';
    $payloadJson = json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

    $baseRow = [
        'ts' => $ts,
        'gamets' => $gamets,
        'sess' => 'dialectic',
        'localts' => time(),
        'people' => $peoplePipe,
        'location' => '',
        'party' => $payloadJson ?: '',
        'delivery_state' => 'received',
    ];

    $db->insert('eventlog', array_merge($baseRow, [
        'type' => 'nearby_items',
        'data' => 'nearby items: ' . $itemCount,
    ]));

    Logger::debug("[gamedata.php] Updated nearby_items from structured payload: {$itemCount}");
}

function handlePointsOfInterestUpdate(array $data): void
{
    $db = $GLOBALS['db'];
    $pois = $data['pois'] ?? [];
    if (!is_array($pois)) {
        $pois = [];
    }

    $ts = intval($data['ts'] ?? time());
    if ($ts <= 0) {
        $ts = time();
    }
    $gamets = intval($data['gamets'] ?? 0);
    if ($gamets <= 0) {
        $gamets = $ts;
    }

    $player = cleanBridgeString($data['player'] ?? '');
    $poiNames = [];
    $skippedPoiSamples = [];
    foreach ($pois as $poiKey => $poi) {
        if (is_string($poi) || is_numeric($poi)) {
            $displayName = cleanBridgeString($poi);
            $poi = [];
        } elseif (is_array($poi)) {
            $displayName = firstNonEmptyString([
                $poi['display_name'] ?? '',
                $poi['displayName'] ?? '',
                $poi['destination_name'] ?? '',
                $poi['destinationName'] ?? '',
                $poi['destination'] ?? '',
                $poi['dest_name'] ?? '',
                $poi['name'] ?? '',
                $poi['label'] ?? '',
                is_string($poiKey) ? $poiKey : '',
            ]);
        } else {
            $displayName = '';
        }

        if ($displayName === '' || $displayName === 'Unknown') {
            if (count($skippedPoiSamples) < 3) {
                $skippedPoiSamples[] = Logger::summarizePayload($poi);
            }
            continue;
        }

        if (is_array($poi) && filter_var($poi['locked'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
            $displayName .= ' (locked)';
        }
        if (is_array($poi) && filter_var($poi['looking_at'] ?? false, FILTER_VALIDATE_BOOLEAN) && $player !== '') {
            $displayName .= " ({$player} is looking at this)";
        }

        if (!in_array($displayName, $poiNames, true)) {
            $poiNames[] = $displayName;
        }
    }

    $peoplePipe = $player !== '' ? "|{$player}|" : '';
    $payloadJson = json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

    $baseRow = [
        'ts' => $ts,
        'gamets' => $gamets,
        'sess' => 'dialectic',
        'localts' => time(),
        'people' => $peoplePipe,
        'location' => '',
        'party' => $payloadJson ?: '',
        'delivery_state' => 'received',
    ];

    $db->insert('eventlog', array_merge($baseRow, [
        'type' => 'points_of_interest',
        'data' => 'points of interest: ' . implode(', ', $poiNames),
    ]));

    if (empty($poiNames) && !empty($pois)) {
        Logger::warn("[gamedata.php] points_of_interest payload had entries but no display names" . Logger::formatContext([
            'poi_count' => count($pois),
            'samples' => $skippedPoiSamples,
        ]));
    }
    Logger::debug("[gamedata.php] Updated points_of_interest from structured payload: " . implode(', ', $poiNames));
}

function handleActiveQuestsUpdate(array $data): void
{
    $db = $GLOBALS['db'];
    $quests = $data['quests'] ?? [];
    if (!is_array($quests)) {
        $quests = [];
    }

    $ts = intval($data['ts'] ?? time());
    if ($ts <= 0) {
        $ts = time();
    }
    $gamets = intval($data['gamets'] ?? 0);
    if ($gamets <= 0) {
        $gamets = $ts;
    }

    // The in-game journal snapshot is the source of truth for this table.
    $db->delete('quests', '1=1');

    $inserted = 0;
    foreach ($quests as $quest) {
        if (!is_array($quest)) {
            continue;
        }

        $questId = firstNonEmptyString([
            $quest['id_quest'] ?? '',
            $quest['formid'] ?? '',
            $quest['quest_id'] ?? '',
        ]);
        $name = firstNonEmptyString([
            $quest['name'] ?? '',
            $quest['editor_id'] ?? '',
            $questId,
        ]);
        if ($questId === '' || $name === '') {
            continue;
        }

        $objectives = $quest['objectives'] ?? [];
        if (!is_array($objectives)) {
            $objectives = [];
        }

        $objectiveTexts = [];
        foreach ($objectives as $objective) {
            if (!is_array($objective)) {
                continue;
            }
            $objectiveText = cleanBridgeString($objective['text'] ?? '');
            if ($objectiveText !== '') {
                $objectiveTexts[] = $objectiveText;
            }
        }

        $briefing = cleanBridgeString($quest['briefing'] ?? '');
        if ($briefing === '' && !empty($objectiveTexts)) {
            $briefing = implode('; ', $objectiveTexts);
        }
        if ($briefing === '') {
            $briefing = $name;
        }

        $stage = intval($quest['stage'] ?? 0);
        $selected = dialecticGameDataBool($quest['selected'] ?? ($quest['active_selected'] ?? false));
        $status = $selected ? 'selected' : cleanBridgeString($quest['status'] ?? 'active');
        if ($status === '') {
            $status = 'active';
        }
        $objectivesJson = json_encode($objectives, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $questJson = json_encode($quest, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        $db->insert('quests', [
            'ts' => (string)$ts,
            'gamets' => $gamets,
            'name' => $name,
            'editor_id' => cleanBridgeString($quest['editor_id'] ?? ''),
            'briefing' => $briefing,
            'briefing2' => $objectivesJson ?: '',
            'data' => $questJson ?: '',
            'stage' => $stage,
            'giver_actor_id' => cleanBridgeString($quest['giver_actor_id'] ?? ''),
            'id_quest' => $questId,
            'sess' => 'dialectic_active_quest',
            'status' => $status,
            'localts' => time(),
        ]);
        $inserted++;
    }

    Logger::info("[gamedata.php] Updated active_quests" . Logger::formatContext([
        'count' => $inserted,
        'source' => cleanBridgeString($data['source'] ?? ''),
        'gamets' => $gamets,
    ]));
}

function handleTradeSummaryUpdate(array $data): void
{
    $db = $GLOBALS['db'];

    $speaker = cleanBridgeString($data['speaker'] ?? '');
    $player = cleanBridgeString($data['player'] ?? ($GLOBALS['PLAYER_NAME'] ?? ''));
    $text = cleanBridgeString($data['text'] ?? '');
    if ($text === '') {
        $text = 'A trade was completed.';
    }

    $ts = intval($data['ts'] ?? time());
    if ($ts <= 0) {
        $ts = time();
    }
    $gamets = intval($data['gamets'] ?? 0);
    if ($gamets <= 0) {
        $gamets = DataLastKnownGameTS();
    }

    $people = [];
    foreach ([$speaker, $player] as $name) {
        if ($name !== '' && !in_array($name, $people, true)) {
            $people[] = $name;
        }
    }
    $peoplePipe = empty($people) ? '' : '|' . implode('|', $people) . '|';
    $payloadJson = json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

    $db->insert('eventlog', [
        'ts' => $ts,
        'gamets' => $gamets,
        'type' => 'itemtransfer',
        'data' => $text,
        'sess' => 'dialectic',
        'localts' => time(),
        'people' => $peoplePipe,
        'location' => cleanBridgeString($data['location'] ?? ''),
        'party' => $payloadJson ?: '',
        'delivery_state' => 'received',
    ]);

    Logger::info("[gamedata.php] Logged trade_summary" . Logger::formatContext([
        'speaker' => $speaker,
        'player' => $player,
        'caps_delta' => intval($data['caps_delta'] ?? 0),
        'received_count' => is_array($data['player_received_items'] ?? null) ? count($data['player_received_items']) : 0,
        'gave_count' => is_array($data['player_gave_items'] ?? null) ? count($data['player_gave_items']) : 0,
    ]));
}

function buildEquipmentMetadataValue(array $equipment): array
{
    $equipmentData = [];
    foreach ($equipment as $slot => $item) {
        $equipmentData[$slot] = isset($item['name']) ? $item['name'] : '';
        $equipmentData[$slot . '_baseid'] = isset($item['baseid']) ? $item['baseid'] : '';
        if (array_key_exists('slot', $item)) {
            $equipmentData[$slot . '_slot'] = intval($item['slot']);
        }
        if (array_key_exists('condition', $item) && $item['condition'] !== null && $item['condition'] !== '') {
            $equipmentData[$slot . '_condition'] = floatval($item['condition']);
        }
    }

    return $equipmentData;
}

function hasNonEmptyEquipmentPayload(array $equipment): bool
{
    foreach ($equipment as $item) {
        if (!is_array($item)) {
            if (trim((string)$item) !== '') {
                return true;
            }
            continue;
        }

        $name = trim((string)($item['name'] ?? ''));
        $baseid = trim((string)($item['baseid'] ?? ''));
        if ($name !== '' || $baseid !== '') {
            return true;
        }
    }

    return false;
}

function buildInventoryMetadataValue(array $items): array
{
    $inventoryData = [];
    foreach ($items as $item) {
        if (isset($item['name']) && isset($item['baseid']) && isset($item['count'])) {
            $inventoryItem = [
                'name' => $item['name'],
                'baseid' => $item['baseid'],
                'count' => intval($item['count']),
            ];

            if (array_key_exists('equipped', $item)) {
                $inventoryItem['equipped'] = filter_var($item['equipped'], FILTER_VALIDATE_BOOLEAN);
            }
            if (array_key_exists('condition', $item) && $item['condition'] !== null && $item['condition'] !== '') {
                $inventoryItem['condition'] = floatval($item['condition']);
            }
            if (array_key_exists('type', $item) && $item['type'] !== null && $item['type'] !== '') {
                $inventoryItem['type'] = intval($item['type']);
            }
            if (array_key_exists('ammo', $item) && $item['ammo'] !== null && $item['ammo'] !== '') {
                $inventoryItem['ammo'] = (string)$item['ammo'];
            }
            if (array_key_exists('mods', $item) && is_array($item['mods'])) {
                $inventoryItem['mods'] = array_values(array_filter(array_map('strval', $item['mods']), function ($modName) {
                    return trim($modName) !== '';
                }));
            }

            $inventoryData[] = $inventoryItem;
        }
    }

    return $inventoryData;
}

function firstNonEmptyString(array $values): string
{
    foreach ($values as $value) {
        $text = cleanBridgeString($value);
        if ($text !== '') {
            return $text;
        }
    }
    return '';
}

function dialecticGameDataBool($value): bool
{
    if (is_bool($value)) {
        return $value;
    }
    if (is_numeric($value)) {
        return intval($value) !== 0;
    }
    $text = strtolower(trim((string)$value));
    return in_array($text, ['1', 'true', 'yes', 'on', 'selected'], true);
}

function cleanBridgeString($value): string
{
    $text = trim((string)$value);
    while (str_ends_with($text, '\\n') || str_ends_with($text, '\\r')) {
        $text = trim(substr($text, 0, -2));
    }
    return $text;
}

function dialecticTruthy($value): bool
{
    if (is_bool($value)) {
        return $value;
    }
    if (is_numeric($value)) {
        return intval($value) !== 0;
    }
    $text = strtolower(trim((string)$value));
    return in_array($text, ['1', 'true', 'yes', 'on', 'selected'], true);
}

function normalizeWorldDataFormIdBigint($value): ?string
{
    $text = cleanBridgeString($value);
    if ($text === '') {
        return null;
    }

    if (str_starts_with(strtolower($text), '0x')) {
        $text = substr($text, 2);
    }

    if (preg_match('/^[0-9A-Fa-f]+$/', $text) && preg_match('/[A-Fa-f]/', $text)) {
        return (string)hexdec($text);
    }

    if (preg_match('/^[0-9]+$/', $text)) {
        return ltrim($text, '0') === '' ? '0' : ltrim($text, '0');
    }

    return null;
}

function cleanWorldDataListField($value): string
{
    if (is_array($value)) {
        $parts = [];
        foreach ($value as $entry) {
            $clean = cleanBridgeString($entry);
            if ($clean !== '') {
                $parts[] = $clean;
            }
        }
        return implode(',', array_values(array_unique($parts)));
    }

    return cleanBridgeString($value);
}

function worldDataPointSql(array $location): string
{
    $x = $location['x'] ?? null;
    $y = $location['y'] ?? null;
    if (($x === null || $y === null) && isset($location['coords']) && is_array($location['coords'])) {
        $x = $location['coords']['x'] ?? null;
        $y = $location['coords']['y'] ?? null;
    }

    if (!is_numeric($x) || !is_numeric($y)) {
        return 'NULL';
    }

    return 'point(' . (float)$x . ',' . (float)$y . ')';
}

function cleanBridgePayloadValue($value)
{
    if (is_string($value) || is_numeric($value)) {
        return cleanBridgeString($value);
    }
    if (is_array($value)) {
        $cleaned = [];
        foreach ($value as $key => $nestedValue) {
            $cleaned[$key] = cleanBridgePayloadValue($nestedValue);
        }
        return $cleaned;
    }
    return $value;
}

function dialecticMaybeSyncPlayerNameFromGameData(array $data): void
{
    $identity = is_array($data['identity'] ?? null) ? $data['identity'] : [];
    foreach ([
        $data['player_name'] ?? null,
        $data['playerName'] ?? null,
        $identity['player_name'] ?? null,
        $identity['playerName'] ?? null,
        $identity['name'] ?? null,
        $data['actor_name'] ?? null,
    ] as $candidate) {
        if (is_string($candidate) && dialecticMaybeSyncPlayerName($candidate)) {
            return;
        }
    }
}

function flattenActorProfileFields(array $data): array
{
    $identity = is_array($data['identity'] ?? null) ? $data['identity'] : [];
    $special = is_array($data['special'] ?? null) ? $data['special'] : [];
    $skills = is_array($data['skills'] ?? null) ? $data['skills'] : [];
    $stats = is_array($data['stats'] ?? null) ? $data['stats'] : [];

    $fields = [
        'refid' => firstNonEmptyString([$data['refid'] ?? '', $identity['refid'] ?? '']),
        'baseid' => firstNonEmptyString([$data['baseid'] ?? '', $identity['baseid'] ?? '']),
        'gender' => firstNonEmptyString([$data['gender'] ?? '', $identity['gender'] ?? '']),
        'race' => firstNonEmptyString([$data['race'] ?? '', $identity['race'] ?? '']),
        'voice' => firstNonEmptyString([$data['voiceid'] ?? '', $identity['voiceid'] ?? '', $identity['voice'] ?? '']),
        'voice_formid' => firstNonEmptyString([$data['voice_formid'] ?? '', $identity['voice_formid'] ?? '']),
        'voice_name' => firstNonEmptyString([$data['voice_name'] ?? '', $identity['voice_name'] ?? '']),
    ];

    foreach (['strength', 'perception', 'endurance', 'charisma', 'intelligence', 'agility', 'luck'] as $key) {
        if (array_key_exists($key, $special)) {
            $fields[$key] = $special[$key];
        }
    }

    foreach ([
        'barter', 'energy_weapons', 'explosives', 'guns', 'lockpick', 'medicine',
        'melee_weapons', 'repair', 'science', 'sneak', 'speech', 'survival', 'unarmed'
    ] as $key) {
        if (array_key_exists($key, $skills)) {
            $fields[$key] = $skills[$key];
        }
    }

    foreach (['level', 'health', 'health_max', 'action_points', 'action_points_max', 'scale', 'xp', 'karma'] as $key) {
        if (array_key_exists($key, $stats)) {
            $fields[$key] = $stats[$key];
        }
    }

    if (!empty($data['factions'])) {
        $fields['factions'] = $data['factions'];
    }
    if (!empty($data['reputation'])) {
        $fields['reputation'] = $data['reputation'];
    }

    return array_filter($fields, static function ($value) {
        return !(is_string($value) && trim($value) === '');
    });
}

function sanitizeActorProfilePayload(array $data): array
{
    $allowed = [
        'schema', 'type', 'actor_name', 'actor_type', 'identity', 'special',
        'skills', 'stats', 'equipment', 'factions', 'reputation', 'source',
        'refid', 'baseid', 'timestamp', 'gamets',
    ];

    $profile = [];
    foreach ($allowed as $key) {
        if (array_key_exists($key, $data)) {
            $profile[$key] = cleanBridgePayloadValue($data[$key]);
        }
    }
    $profile['schema'] = trim((string)($profile['schema'] ?? '')) !== ''
        ? $profile['schema']
        : 'dialectic.actor_profile.v1';
    $profile['updated_at'] = time();
    return $profile;
}

function handleActorProfileUpdate(array $data, NpcMaster $npcMaster): void
{
    $actorName = cleanBridgeString($data['actor_name'] ?? '');
    $actorType = cleanBridgeString($data['actor_type'] ?? '');
    if ($actorName === '' || $actorType === 'player') {
        return;
    }

    $profileFields = flattenActorProfileFields($data);
    $refid = trim((string)($profileFields['refid'] ?? ''));
    dialectic_ensure_npc($GLOBALS['db'], $actorName, $refid, $profileFields);

    $currentData = findNpcForGameData($npcMaster, $actorName, $data);
    if (!$currentData) {
        $currentData = $npcMaster->getByName($actorName);
    }
    if (!$currentData) {
        Logger::warn("[gamedata.php] actor_profile could not create/find NPC: {$actorName}");
        return;
    }

    $metadataUpdates = [
        'actor_profile' => sanitizeActorProfilePayload($data),
        'actor_profile_updated' => time(),
    ];
    if (!empty($data['special']) && is_array($data['special'])) {
        $metadataUpdates['special'] = $data['special'];
    }
    if (!empty($data['skills']) && is_array($data['skills'])) {
        $metadataUpdates['skills'] = buildSkillsMetadataValue($data['skills']);
    }
    if (!empty($data['stats']) && is_array($data['stats'])) {
        $metadataUpdates['stats'] = buildStatsMetadataValue($data['stats']);
    }
    if (!empty($data['equipment']) && is_array($data['equipment']) && hasNonEmptyEquipmentPayload($data['equipment'])) {
        $metadataUpdates['equipment'] = buildEquipmentMetadataValue($data['equipment']);
        $metadataUpdates['equipment_structured'] = $data['equipment'];
    }
    if (!empty($data['factions']) && is_array($data['factions'])) {
        $metadataUpdates['factions'] = array_values($data['factions']);
    }
    if (!empty($data['reputation']) && is_array($data['reputation'])) {
        $metadataUpdates['reputation'] = array_values($data['reputation']);
    }

    $npcMaster->updateMetadataKeysByName($currentData['npc_name'], $metadataUpdates);

    $baseid = firstNonEmptyString([$data['baseid'] ?? '', $data['identity']['baseid'] ?? '']);
    if ($baseid !== '') {
        $GLOBALS['db']->execQuery("
            UPDATE public.core_npc_master
            SET base = '" . $GLOBALS['db']->escape($baseid) . "'
            WHERE npc_name = '" . $GLOBALS['db']->escape($currentData['npc_name']) . "'
        ");
    }

    Logger::debug("[gamedata.php] Updated actor_profile for {$currentData['npc_name']}");
}

/**
 * Handle NPC voice metadata resolved from activation-time game data.
 */
function handleNpcVoiceUpdate(array $data, NpcMaster $npcMaster): void {
    $actorName = cleanBridgeString($data['actor_name'] ?? '');
    $actorType = cleanBridgeString($data['actor_type'] ?? '');

    if ($actorType === 'player' || $actorName === '') {
        return;
    }

    $currentData = findNpcForGameData($npcMaster, $actorName, $data);
    if (!$currentData) {
        Logger::debug("[gamedata.php] NPC voice update skipped; NPC not found: {$actorName}");
        return;
    }

    $voiceId = cleanBridgeString($data['voiceid'] ?? '');
    $voiceFormId = cleanBridgeString($data['voice_formid'] ?? '');
    $voiceName = cleanBridgeString($data['voice_name'] ?? '');
    $source = cleanBridgeString($data['source'] ?? 'fnv_snapshot');

    if ($voiceId === '' && $voiceName !== '') {
        $voiceId = $voiceName;
    }

    $voiceKey = strtolower(preg_replace('/[^a-z0-9]+/i', '', $voiceId . ' ' . $voiceName));
    if (
        str_contains($voiceKey, 'nodialogue') ||
        str_contains($voiceKey, 'donotrecord') ||
        str_contains($voiceKey, 'nvdlec01femaleunquenodialogue') ||
        str_contains($voiceKey, 'nvdlc01femaleunquenodialogue')
    ) {
        Logger::info("[gamedata.php] Ignored temporary/silent NPC voice metadata for {$currentData['npc_name']} ({$voiceId} / {$voiceName})");
        return;
    }

    $isRawFormId = preg_match('/^(0x)?[0-9a-f]{8}$/i', $voiceId) === 1;
    $attachableVoiceId = (!$isRawFormId && $voiceId !== '') ? strtolower($voiceId) : '';
    $currentVoiceId = strtolower(trim(strval($currentData['voiceid'] ?? '')));
    $currentVoiceIsTemporarySilent = function_exists('dialectic_is_temporary_silent_voice') &&
        dialectic_is_temporary_silent_voice($currentVoiceId);
    if ($attachableVoiceId !== '' && $currentVoiceId !== '' && !$currentVoiceIsTemporarySilent && $currentVoiceId !== $attachableVoiceId) {
        Logger::info("[gamedata.php] Ignored NPC voice metadata change for {$currentData['npc_name']} existing={$currentData['voiceid']} incoming={$voiceId}");
        return;
    }

    $extended = $npcMaster->getExtendedData($currentData);
    $extended['voice_metadata'] = [
        'voiceid' => $attachableVoiceId !== '' ? $attachableVoiceId : $voiceId,
        'voice_formid' => $voiceFormId,
        'voice_name' => $voiceName,
        'source' => $source !== '' ? $source : 'fnv_snapshot',
        'updated_at' => time(),
    ];

    if ($attachableVoiceId !== '') {
        unset($extended['voice_refresh_requested_at']);
        $extended['voice_refresh_last_result'] = 'metadata_resolved';
        $extended['voice_refresh_last_resolved_at'] = time();

        $currentData['voiceid'] = $attachableVoiceId;
    } else {
        $extended['voice_refresh_last_result'] = 'metadata_formid_only';
    }

    $currentData = $npcMaster->setExtendedData($currentData, $extended);
    $npcMaster->updateByArray($currentData);

    Logger::debug("[gamedata.php] Updated NPC voice metadata for {$currentData['npc_name']} ({$voiceFormId})");
}

function findNpcForGameData(NpcMaster $npcMaster, string $actorName, array $data): ?array
{
    $currentData = $npcMaster->getByName($actorName);
    if ($currentData) {
        return $currentData;
    }

    $refid = trim((string)($data['refid'] ?? ''));
    if ($refid === '') {
        return null;
    }

    $refCandidates = [$refid];
    if (str_starts_with(strtolower($refid), '0x')) {
        $refCandidates[] = substr($refid, 2);
    } else {
        $refCandidates[] = '0x' . $refid;
    }

    foreach (array_values(array_unique($refCandidates)) as $candidate) {
        $currentData = $npcMaster->getByRefId($candidate);
        if ($currentData) {
            return $currentData;
        }
    }

    return null;
}

function buildSkillsMetadataValue(array $skills): array
{
    $skillsData = [];
    foreach ($skills as $skillName => $skillValue) {
        $skillsData[$skillName] = floatval($skillValue);
    }

    return $skillsData;
}

function buildStatsMetadataValue(array $stats): array
{
    return [
        'level' => isset($stats['level']) ? intval($stats['level']) : 1,
        'health' => isset($stats['health']) ? floatval($stats['health']) : 0,
        'health_max' => isset($stats['health_max']) ? floatval($stats['health_max']) : 0,
        'action_points' => isset($stats['action_points']) ? floatval($stats['action_points']) : 0,
        'action_points_max' => isset($stats['action_points_max']) ? floatval($stats['action_points_max']) : 0,
        'scale' => isset($stats['scale']) ? floatval($stats['scale']) : 1.0,
        'xp' => isset($stats['xp']) ? intval($stats['xp']) : 0,
        'karma' => isset($stats['karma']) ? intval($stats['karma']) : 0,
    ];
}

/**
 * Handle inventory update
 */
function handleInventoryUpdate(array $data, NpcMaster $npcMaster): void {
    $actorName = $data['actor_name'];
    $actorType = $data['actor_type'];
    
    if (!isset($data['items'])) {
        Logger::error("[gamedata.php] Inventory update missing items data");
        return;
    }
    
    $items = $data['items'];
    
    // If this is a player, save directly to core_player table (player doesn't need NPC record)
    if ($actorType === 'player') {
        try {
            require_once(__DIR__ . "/lib/core/player.class.php");
            $player = new Player();

            $inventoryData = buildInventoryMetadataValue($items);
            $player->setJson('inventory', $inventoryData);
            Logger::debug("[gamedata.php] Saved player inventory to core_player table");
        } catch (Exception $e) {
            Logger::warn("[gamedata.php] Could not save player inventory to core_player: " . $e->getMessage());
        }
        
        $currentData = $npcMaster->getByName($actorName);
        if ($currentData) {
            $npcMaster->updateMetadataKeysByName($actorName, [
                'inventory' => buildInventoryMetadataValue($items),
                'inventory_updated' => time(),
            ]);
        }
        
        $itemCount = count($items);
        Logger::debug("[gamedata.php] Updated inventory for player: {$actorName} ({$itemCount} items)");
        return; // Done with player, exit early
    }
    
    // Handle NPC inventory
    $currentData = findNpcForGameData($npcMaster, $actorName, $data);
    
    if (!$currentData) {
        // NPC not in database yet - this is normal, they haven't been encountered
        Logger::debug("[gamedata.php] Inventory update skipped; NPC not found: {$actorName}");
        return;
    }
    
    $npcMaster->updateMetadataKeysByName($currentData['npc_name'], [
        'inventory' => buildInventoryMetadataValue($items),
        'inventory_updated' => time(),
    ]);
    
    $itemCount = count($items);
    Logger::debug("[gamedata.php] Updated inventory for {$actorType}: {$actorName} ({$itemCount} items)");
}

/**
 * Handle skills update
 */
function handleSkillsUpdate(array $data, NpcMaster $npcMaster): void {
    $actorName = $data['actor_name'];
    $actorType = $data['actor_type'];
    
    if (!isset($data['skills'])) {
        Logger::error("[gamedata.php] Skills update missing skills data");
        return;
    }
    
    $skills = $data['skills'];
    
    // If this is a player, save directly to core_player table (player doesn't need NPC record)
    if ($actorType === 'player') {
        try {
            require_once(__DIR__ . "/lib/core/player.class.php");
            $player = new Player();

            $skillsData = buildSkillsMetadataValue($skills);
            $player->setJson('skills', $skillsData);
            Logger::debug("[gamedata.php] Saved player skills to core_player table");
        } catch (Exception $e) {
            Logger::warn("[gamedata.php] Could not save player skills to core_player: " . $e->getMessage());
        }
        
        $currentData = $npcMaster->getByName($actorName);
        if ($currentData) {
            $npcMaster->updateMetadataKeysByName($actorName, [
                'skills' => buildSkillsMetadataValue($skills),
            ]);
        }
        
        Logger::debug("[gamedata.php] Updated skills for player: {$actorName}");
        return; // Done with player, exit early
    }
    
    // Handle NPC skills
    $currentData = $npcMaster->getByName($actorName);
    
    if (!$currentData) {
        // NPC not in database yet - this is normal, they haven't been encountered
        return;
    }
    
    $npcMaster->updateMetadataKeysByName($actorName, [
        'skills' => buildSkillsMetadataValue($skills),
    ]);
    
    Logger::debug("[gamedata.php] Updated skills for {$actorType}: {$actorName}");
}

/**
 * Handle stats update
 */
function handleStatsUpdate(array $data, NpcMaster $npcMaster): void {
    $actorName = $data['actor_name'];
    $actorType = $data['actor_type'];
    
    if (!isset($data['stats'])) {
        Logger::error("[gamedata.php] Stats update missing stats data");
        return;
    }
    
    $stats = $data['stats'];
    
    // If this is a player, save directly to core_player table (player doesn't need NPC record)
    if ($actorType === 'player') {
        try {
            require_once(__DIR__ . "/lib/core/player.class.php");
            $player = new Player();

            $statsData = buildStatsMetadataValue($stats);
            $player->setJson('stats', $statsData);
            Logger::debug("[gamedata.php] Saved player stats to core_player table");
        } catch (Exception $e) {
            Logger::warn("[gamedata.php] Could not save player stats to core_player: " . $e->getMessage());
        }
        
        $currentData = $npcMaster->getByName($actorName);
        if ($currentData) {
            $npcMaster->updateMetadataKeysByName($actorName, [
                'stats' => buildStatsMetadataValue($stats),
            ]);
        }
        
        Logger::debug("[gamedata.php] Updated stats for player: {$actorName}");
        return; // Done with player, exit early
    }
    
    // Handle NPC stats
    $currentData = $npcMaster->getByName($actorName);
    
    if (!$currentData) {
        // NPC not in database yet - this is normal, they haven't been encountered
        return;
    }
    
    $npcMaster->updateMetadataKeysByName($actorName, [
        'stats' => buildStatsMetadataValue($stats),
    ]);
    
    Logger::debug("[gamedata.php] Updated stats for {$actorType}: {$actorName}");
}

/**
 * Handle Fallout statistics update (player only)
 * This handles the ~40 Fallout stats like Quests Completed, Days Passed, etc.
 */
function handleFalloutStatsUpdate(array $data): void {
    if (!isset($data['stats']) || !is_array($data['stats'])) {
        Logger::error("[gamedata.php] Fallout stats update missing stats data");
        return;
    }
    
    try {
        require_once(__DIR__ . "/lib/core/player.class.php");
        $player = new Player();
        
        $stats = $data['stats'];
        
        // Save each stat to core_player table
        foreach ($stats as $statKey => $statValue) {
            $player->set($statKey, (string)$statValue);
        }
        
        Logger::debug("[gamedata.php] Updated " . count($stats) . " Fallout stats to core_player");
        
    } catch (Exception $e) {
        Logger::error("[gamedata.php] Failed to save Fallout stats: " . $e->getMessage());
    }
}

/**
 * Handle market stock update
 * Updates the stock JSONB column on the factions table for the given faction formid.
 * Expected payload:
 *   { "type": "market_stock", "faction": "0x01", "list": [ { "itemid": "0x02", "name": "item_name", "count": 2, "caps": 12 } ] }
 */
function handleMarketStockUpdate(array $data): void {
    if (!isset($data['faction']) || !isset($data['list']) || !is_array($data['list'])) {
        Logger::error("[gamedata.php] market_stock update missing faction or list data");
        return;
    }

    $db = $GLOBALS['db'];
    $factionFormId = $db->escape(strtoupper($data['faction']));

    // Build normalised stock array from the incoming list
    $stock = [];
    $caps=0;
    $rank=isset($data['player_rank']) ? intval($data['player_rank']) : -1;
    foreach ($data['list'] as $item) {
        if (isset($item['itemid'], $item['name'], $item['count'])) {
            $itemCaps = intval($item['caps'] ?? $item['value'] ?? 0);
            $itemId = strtoupper(preg_replace('/^0X/i', '', trim(strval($item['itemid']))));
            $stock[] = [
                'itemid' => $item['itemid'],
                'name'   => trim($item['name']),
                'count'  => intval($item['count']),
                'caps'  => $itemCaps,
                'condition' => isset($item['condition']) ? $item['condition'] : null,
                'ammo' => isset($item['ammo']) ? $item['ammo'] : null,
                'mods' => isset($item['mods']) && is_array($item['mods']) ? $item['mods'] : []
                
            ];
            if (ltrim($itemId, '0') === 'F') { // Bottle caps item
                $caps=intval($item['count']);
            } 

        }
    }

    $stockJson = $db->escape(json_encode($stock));

    $sql = "UPDATE public.factions
               SET stock = '{$stockJson}'::jsonb,caps=$caps,player_rank=$rank
             WHERE formid = '{$factionFormId}'";

    $result = $db->execQuery($sql);

    if ($result) {
        
        Logger::debug("[gamedata.php] Updated market stock for faction '{$data['faction']}': "
            . count($stock) . " item(s)");
    }
}

