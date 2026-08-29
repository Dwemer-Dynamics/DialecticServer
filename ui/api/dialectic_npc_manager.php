<?php

ob_start();

$enginePath = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR;
$GLOBALS['ENGINE_PATH'] = $enginePath;

require_once $enginePath . 'lib' . DIRECTORY_SEPARATOR . 'runtime_bootstrap.php';
dialecticRuntimeBootstrap($enginePath, [
    'load_general_settings' => true,
    'load_stt_connector' => false,
    'load_tts_connector' => false,
    'load_player_name' => true,
    'load_narrator' => true,
]);

require_once $enginePath . 'lib' . DIRECTORY_SEPARATOR . 'logger.php';
require_once $enginePath . 'lib' . DIRECTORY_SEPARATOR . 'dialectic_command_payload.php';
require_once $enginePath . 'lib' . DIRECTORY_SEPARATOR . 'utils_game_timestamp.php';
require_once $enginePath . 'lib' . DIRECTORY_SEPARATOR . 'eventlog_helper.php';

function dialecticNpcManagerRespond(array $payload, int $status = 200): void
{
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, no-cache, must-revalidate');
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

function dialecticNpcManagerDecodeMetadata($value): array
{
    if (is_array($value)) {
        return $value;
    }
    $decoded = json_decode((string)$value, true);
    return is_array($decoded) ? $decoded : [];
}

function dialecticNpcManagerNormalizeRefId($value): string
{
    $hex = strtoupper(preg_replace('/^0X/i', '', trim((string)$value)) ?? '');
    if (!preg_match('/^[0-9A-F]{1,8}$/', $hex)) {
        return '';
    }
    return '0x' . str_pad($hex, 8, '0', STR_PAD_LEFT);
}

function dialecticNpcManagerFormatFormId($value): string
{
    if (is_int($value) || (is_string($value) && preg_match('/^\d+$/', trim($value)))) {
        return sprintf('0x%08X', intval($value) & 0xFFFFFFFF);
    }
    return dialecticNpcManagerNormalizeRefId($value);
}

function dialecticNpcManagerFindNpc(array $input): array
{
    $id = intval($input['id'] ?? 0);
    if ($id <= 0) {
        throw new InvalidArgumentException('NPC id is required');
    }
    $row = $GLOBALS['db']->fetchOne("SELECT * FROM core_npc_master WHERE id = {$id} LIMIT 1");
    if (!is_array($row)) {
        throw new InvalidArgumentException('NPC not found');
    }
    return $row;
}

function dialecticNpcManagerList(): array
{
    $page = max(1, intval($_GET['page'] ?? 1));
    $limit = max(1, min(100, intval($_GET['limit'] ?? 40)));
    $offset = ($page - 1) * $limit;
    $conditions = ["npc_name IS NOT NULL", "btrim(npc_name) <> ''"];
    $search = trim((string)($_GET['search'] ?? ''));
    if ($search !== '') {
        $escapedSearch = $GLOBALS['db']->escape('%' . $search . '%');
        $conditions[] = "(npc_name ILIKE '{$escapedSearch}' OR race ILIKE '{$escapedSearch}' OR refid ILIKE '{$escapedSearch}')";
    }
    $where = implode(' AND ', $conditions);
    $countRow = $GLOBALS['db']->fetchOne("SELECT COUNT(*) AS total FROM core_npc_master WHERE {$where}");
    $rows = $GLOBALS['db']->fetchAll(
        "SELECT id, npc_name FROM core_npc_master WHERE {$where} ORDER BY npc_name ASC, id ASC LIMIT {$limit} OFFSET {$offset}"
    );

    return [
        'npcs' => array_map(static function ($row) {
            return [
                'id' => intval($row['id'] ?? 0),
                'name' => trim((string)($row['npc_name'] ?? 'Unknown NPC')),
            ];
        }, (array)$rows),
        'pagination' => [
            'page' => $page,
            'limit' => $limit,
            'total' => intval($countRow['total'] ?? 0),
        ],
    ];
}

function dialecticNpcManagerEventRecipients($people): array
{
    $recipients = [];
    foreach (explode('|', trim((string)$people, '|')) as $recipient) {
        $recipient = trim((string)$recipient);
        if ($recipient !== '' && !in_array($recipient, $recipients, true)) {
            $recipients[] = $recipient;
        }
    }
    return $recipients;
}

function dialecticNpcManagerHistory(array $input): array
{
    $npc = dialecticNpcManagerFindNpc($input);
    $npcName = trim((string)($npc['npc_name'] ?? ''));
    $limit = max(1, min(100, intval($input['limit'] ?? 100)));
    $selectedEventType = trim((string)($input['event_type'] ?? ''));
    $hiddenEventTypes = dialecticGetPersistedEventLogHiddenTypes($GLOBALS['db']);
    // Keep NPC History aligned with the PHP Adventure Log's default narrative event list.
    $allowedEventTypes = [
        'im_alive',
        'chat',
        'infoaction',
        'rpg_lvlup',
        'rechat',
        'quest',
        'itemfound',
        'inputtext',
        'goodnight',
        'goodmorning',
        'death',
        'combatendmighty',
        'combatend',
        'lockpicked',
    ];
    $escapedAllowedEventTypes = array_map(static function ($eventType) {
        return "'" . $GLOBALS['db']->escape($eventType) . "'";
    }, $allowedEventTypes);
    $allowedTypesWhere = 'a.type IN (' . implode(',', $escapedAllowedEventTypes) . ')';
    $peopleWhere = dialecticBuildNpcEventLogPeopleWhereClause($GLOBALS['db'], $npcName, 'a.people');
    $visibleWhere = dialecticBuildVisibleEventLogWhereClause(
        $GLOBALS['db'],
        $selectedEventType,
        $hiddenEventTypes
    );
    $rows = $GLOBALS['db']->fetchAll(
        "SELECT a.rowid, a.type, a.data, a.people, a.gamets, a.localts, a.ts, a.sess
         FROM eventlog a
         WHERE {$allowedTypesWhere} AND {$visibleWhere} AND {$peopleWhere}
         ORDER BY a.localts DESC, a.rowid DESC, a.gamets DESC, a.ts DESC
         LIMIT {$limit}"
    );
    $visibleTypesWhere = dialecticBuildVisibleEventLogWhereClause($GLOBALS['db'], '', $hiddenEventTypes);
    $eventTypes = $GLOBALS['db']->fetchAll(
        "SELECT a.type, COUNT(*) AS total
         FROM eventlog a
         WHERE {$allowedTypesWhere} AND {$visibleTypesWhere} AND {$peopleWhere}
         GROUP BY a.type
         ORDER BY a.type ASC"
    );

    // Relationship changes are virtual rows read from this NPC's history snapshots.
    // Fetched regardless of the selected type so the type filter can always offer them.
    $relationshipRows = dialecticRelationshipTimelineIsVisible('', $hiddenEventTypes)
        ? dialecticFetchRelationshipTimelineChanges($GLOBALS['db'], [
            'npc_id' => intval($npc['id'] ?? 0),
            'limit' => $limit,
            'scan_limit' => 400,
        ])
        : [];
    $relationshipRowsVisible = dialecticRelationshipTimelineIsVisible($selectedEventType, $hiddenEventTypes)
        ? $relationshipRows
        : [];

    $events = array_map(static function ($row) {
        $gamets = intval($row['gamets'] ?? 0);
        return [
            'rowid' => intval($row['rowid'] ?? 0),
            'type' => (string)($row['type'] ?? ''),
            'data' => (string)($row['data'] ?? ''),
            'recipients' => dialecticNpcManagerEventRecipients($row['people'] ?? ''),
            'gamets' => $gamets,
            'fallout_time' => $gamets > 0 ? convert_gamets2fallout_long_date2($gamets) : '',
            'local_time' => !empty($row['localts']) ? gmdate('d-m-Y H:i:s', intval($row['localts'])) : '',
            'localts' => intval($row['localts'] ?? 0),
            'ts' => intval($row['ts'] ?? 0),
            'manual_injection' => strtolower((string)($row['type'] ?? '')) === 'inputtext'
                && (string)($row['sess'] ?? '') === 'npc_editor',
        ];
    }, (array)$rows);

    $relationshipEvents = array_map(static function ($row) {
        return [
            'rowid' => 0,
            'virtual' => true,
            'type' => (string)($row['type'] ?? 'relationship'),
            'data' => (string)($row['data'] ?? ''),
            'detail' => (string)($row['detail'] ?? ''),
            'history_id' => intval($row['history_id'] ?? 0),
            'source' => (string)($row['source'] ?? ''),
            'source_label' => (string)($row['source_label'] ?? ''),
            'change_count' => intval($row['change_count'] ?? 0),
            'recipients' => array_values(array_filter(array_merge(
                [(string)($row['npc_name'] ?? '')],
                (array)($row['targets'] ?? [])
            ), 'strlen')),
            'gamets' => intval($row['gamets'] ?? 0),
            'fallout_time' => (string)($row['fallout_time'] ?? ''),
            'local_time' => (string)($row['local_time'] ?? ''),
            'localts' => intval($row['localts'] ?? 0),
            'ts' => 0,
            'manual_injection' => false,
        ];
    }, $relationshipRowsVisible);

    $mergedEvents = dialecticMergeRelationshipTimelineRows($events, $relationshipEvents, true, count($events) < $limit);

    $eventTypeOptions = array_map(static function ($row) {
        return [
            'type' => (string)($row['type'] ?? ''),
            'total' => intval($row['total'] ?? 0),
        ];
    }, (array)$eventTypes);
    if ($relationshipRows !== [] && dialecticRelationshipTimelineIsVisible('', $hiddenEventTypes)) {
        $eventTypeOptions[] = [
            'type' => dialecticRelationshipTimelineEventType(),
            'total' => count($relationshipRows),
        ];
        usort($eventTypeOptions, static function ($a, $b) {
            return strcasecmp((string)($a['type'] ?? ''), (string)($b['type'] ?? ''));
        });
    }

    return [
        'npc' => ['id' => intval($npc['id'] ?? 0), 'name' => $npcName],
        'events' => $mergedEvents,
        'relationship_change_count' => count($relationshipEvents),
        'filters' => [
            'selected_event_type' => $selectedEventType,
            'hidden_event_types' => $hiddenEventTypes,
            'event_types' => $eventTypeOptions,
        ],
    ];
}

function dialecticNpcManagerResolveEventRecipients(array $input, array $npc): array
{
    $ids = [intval($npc['id'] ?? 0)];
    $requestedIds = is_array($input['recipient_ids'] ?? null) ? $input['recipient_ids'] : [];
    foreach ($requestedIds as $requestedId) {
        $requestedId = intval($requestedId);
        if ($requestedId > 0 && !in_array($requestedId, $ids, true)) {
            $ids[] = $requestedId;
        }
    }
    if (count($ids) > 12) {
        throw new InvalidArgumentException('An event can include at most 12 NPCs');
    }

    $recipients = [];
    foreach ($ids as $id) {
        $row = $id === intval($npc['id'] ?? 0) ? $npc : dialecticNpcManagerFindNpc(['id' => $id]);
        $name = trim((string)($row['npc_name'] ?? ''));
        if ($name === '' || strpos($name, '|') !== false) {
            throw new InvalidArgumentException('One of the selected NPC names cannot be used for event routing');
        }
        $recipients[] = ['id' => intval($row['id'] ?? 0), 'name' => $name];
    }
    return $recipients;
}

function dialecticNpcManagerInjectEvent(array $input): array
{
    $npc = dialecticNpcManagerFindNpc($input);
    $eventText = trim((string)($input['event'] ?? ''));
    if (strlen($eventText) >= 2 && $eventText[0] === '(' && substr($eventText, -1) === ')') {
        $eventText = trim(substr($eventText, 1, -1));
    }
    if ($eventText === '') {
        throw new InvalidArgumentException('Event text is required');
    }
    $eventLength = function_exists('mb_strlen') ? mb_strlen($eventText, 'UTF-8') : strlen($eventText);
    if ($eventLength > 4000) {
        throw new InvalidArgumentException('Event text must be 4000 characters or fewer');
    }

    $recipients = dialecticNpcManagerResolveEventRecipients($input, $npc);
    $people = '|' . implode('|', array_column($recipients, 'name')) . '|';
    $rowId = $GLOBALS['db']->insertReturningId('eventlog', [
        'ts' => max(0, intval(DataLastKnownTS())) + 1,
        'gamets' => max(0, intval(DataLastKnownGameTS())),
        'type' => 'inputtext',
        'data' => '(' . $eventText . ')',
        'sess' => 'npc_editor',
        'localts' => time(),
        'people' => $people,
        'location' => '',
        'party' => '[]',
    ], 'rowid');
    if ($rowId <= 0) {
        throw new RuntimeException('Event could not be injected');
    }

    return [
        'message' => 'Event injected for ' . implode(', ', array_column($recipients, 'name')) . '.',
        'rowid' => $rowId,
        'recipients' => $recipients,
    ];
}

function dialecticNpcManagerDeleteEvent(array $input): array
{
    $npc = dialecticNpcManagerFindNpc($input);
    $rowId = intval($input['rowid'] ?? 0);
    if ($rowId <= 0) {
        throw new InvalidArgumentException('Invalid event row');
    }

    $npcName = trim((string)($npc['npc_name'] ?? ''));
    $peopleWhere = dialecticBuildNpcEventLogPeopleWhereClause($GLOBALS['db'], $npcName, 'a.people');
    $visibleWhere = dialecticBuildVisibleEventLogWhereClause($GLOBALS['db']);
    $event = $GLOBALS['db']->fetchOne(
        "SELECT a.rowid FROM eventlog a WHERE a.rowid = {$rowId} AND {$visibleWhere} AND {$peopleWhere} LIMIT 1"
    );
    if (!$event) {
        throw new InvalidArgumentException('Event is not available in this NPC history');
    }

    $result = dialecticDeleteEventLogRow($GLOBALS['db'], $rowId);
    if (empty($result['ok'])) {
        throw new RuntimeException((string)($result['message'] ?? 'Event could not be deleted'));
    }
    return ['message' => 'Event deleted.', 'rowid' => $rowId];
}

// Resolve the latest tracked NPC position to a stable synchronized location marker.
function dialecticNpcManagerResolveReturnLocation(array $lastCoords): ?array
{
    $locationName = trim((string)($lastCoords['location'] ?? ''));
    if ($locationName !== '') {
        $escapedLocation = $GLOBALS['db']->escape($locationName);
        $row = $GLOBALS['db']->fetchOne(
            "SELECT name, formid, worldspace, " .
            "CASE WHEN lower(name) = lower('{$escapedLocation}') THEN 1.0 ELSE similarity(name, '{$escapedLocation}') END AS score " .
            "FROM locations WHERE COALESCE(name, '') <> '' " .
            "ORDER BY CASE WHEN lower(name) = lower('{$escapedLocation}') THEN 1 ELSE 0 END DESC, score DESC, name ASC LIMIT 1"
        );
        $score = floatval($row['score'] ?? 0);
        $formIdHex = is_array($row) ? dialecticNpcManagerFormatFormId($row['formid'] ?? '') : '';
        if ($formIdHex !== '' && $score >= 0.35) {
            return [
                'name' => trim((string)($row['name'] ?? $locationName)),
                'worldspace' => trim((string)($row['worldspace'] ?? '')),
                'formid' => intval($row['formid']),
                'formid_hex' => $formIdHex,
            ];
        }
    }

    if (!is_numeric($lastCoords['x'] ?? null) || !is_numeric($lastCoords['y'] ?? null)) {
        return null;
    }

    $db = $GLOBALS['db'];
    $x = floatval($lastCoords['x']);
    $y = floatval($lastCoords['y']);
    $worldspace = trim((string)($lastCoords['worldspace'] ?? ''));
    $worldspaceClause = $worldspace === ''
        ? ''
        : " AND lower(COALESCE(worldspace, '')) = lower('" . $db->escape($worldspace) . "')";
    $point = $db->escape("({$x},{$y})");
    $row = $db->fetchOne(
        "SELECT name, formid, worldspace FROM locations " .
        "WHERE coords IS NOT NULL{$worldspaceClause} " .
        "ORDER BY coords <-> '{$point}'::point LIMIT 1"
    );
    if (!is_array($row) || !isset($row['formid'])) {
        return null;
    }

    $formIdHex = dialecticNpcManagerFormatFormId($row['formid']);
    if ($formIdHex === '') {
        return null;
    }
    return [
        'name' => trim((string)($row['name'] ?? 'Previous location')),
        'worldspace' => trim((string)($row['worldspace'] ?? '')),
        'formid' => intval($row['formid']),
        'formid_hex' => $formIdHex,
    ];
}

// Persist only the temporary return marker without overwriting live telemetry metadata.
function dialecticNpcManagerSaveReturnLocation(int $npcId, ?array $returnLocation): bool
{
    if ($returnLocation === null) {
        return $GLOBALS['db']->execQuery(
            "UPDATE core_npc_master SET metadata = COALESCE(metadata, '{}'::jsonb) - 'npc_manager_return_location' WHERE id = {$npcId}"
        ) !== false;
    }

    $encoded = json_encode($returnLocation, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if ($encoded === false) {
        return false;
    }
    return $GLOBALS['db']->execQuery(
        "UPDATE core_npc_master SET metadata = jsonb_set(" .
        "COALESCE(metadata, '{}'::jsonb), '{npc_manager_return_location}', '" .
        $GLOBALS['db']->escape($encoded) . "'::jsonb, true) WHERE id = {$npcId}"
    ) !== false;
}

function dialecticNpcManagerQueueTeleport(string $targetName, string $targetRefId, string $destinationName, string $destinationRefId): void
{
    dialecticQueueCommandResponse('The Narrator', 'TeleportActor', [
        'target' => $targetName,
        'location' => $destinationName,
        'target_refid' => $targetRefId,
        'target_formid' => $targetRefId,
        'location_refid' => $destinationRefId,
        'action_source' => 'narrator',
        'authority' => 'narrator',
    ]);
}

function dialecticNpcManagerAction(array $input): array
{
    $row = dialecticNpcManagerFindNpc($input);
    $action = strtolower(trim((string)($input['action'] ?? '')));
    if (!in_array($action, ['visit', 'teleport', 'return'], true)) {
        throw new InvalidArgumentException('Unsupported NPC action');
    }

    $npcId = intval($row['id'] ?? 0);
    $npcName = trim((string)($row['npc_name'] ?? 'NPC'));
    $npcRefId = dialecticNpcManagerNormalizeRefId($row['refid'] ?? '');
    if ($npcRefId === '') {
        throw new InvalidArgumentException("{$npcName} does not have a valid RefID");
    }

    $metadata = dialecticNpcManagerDecodeMetadata($row['metadata'] ?? '{}');
    $savedReturn = is_array($metadata['npc_manager_return_location'] ?? null)
        ? $metadata['npc_manager_return_location']
        : null;
    $playerName = trim((string)($GLOBALS['PLAYER_NAME'] ?? 'Player'));
    if ($playerName === '') {
        $playerName = 'Player';
    }

    if ($action === 'visit') {
        dialecticNpcManagerQueueTeleport($playerName, '0x00000014', $npcName, $npcRefId);
        return ['message' => "Visit command sent for {$npcName}."];
    }

    if ($action === 'return') {
        if ($savedReturn === null) {
            throw new InvalidArgumentException("{$npcName} does not have a saved return location");
        }
        $returnName = trim((string)($savedReturn['name'] ?? 'Previous location'));
        $returnRefId = dialecticNpcManagerNormalizeRefId($savedReturn['formid_hex'] ?? $savedReturn['formid'] ?? '');
        if ($returnRefId === '') {
            throw new InvalidArgumentException("{$npcName}'s saved return location is invalid");
        }

        dialecticNpcManagerQueueTeleport($npcName, $npcRefId, $returnName, $returnRefId);
        if (!dialecticNpcManagerSaveReturnLocation($npcId, null)) {
            throw new RuntimeException('Saved return location could not be cleared');
        }
        return [
            'message' => "Return command sent for {$npcName} to {$returnName}.",
            'next_action' => 'teleport',
            'return_location' => '',
        ];
    }

    if ($savedReturn !== null) {
        throw new InvalidArgumentException("Return {$npcName} before teleporting them again");
    }
    $lastCoords = is_array($metadata['last_coords'] ?? null) ? $metadata['last_coords'] : null;
    if ($lastCoords === null) {
        throw new InvalidArgumentException("{$npcName} does not have a tracked location to return to");
    }
    $returnLocation = dialecticNpcManagerResolveReturnLocation($lastCoords);
    if ($returnLocation === null) {
        throw new InvalidArgumentException("{$npcName}'s tracked location could not be resolved to a synchronized marker");
    }
    $returnLocation['tracked'] = $lastCoords;
    $returnLocation['saved_at'] = time();
    if (!dialecticNpcManagerSaveReturnLocation($npcId, $returnLocation)) {
        throw new RuntimeException('Return location could not be saved');
    }

    dialecticNpcManagerQueueTeleport($npcName, $npcRefId, $playerName, '0x00000014');
    return [
        'message' => "Teleport command sent for {$npcName}.",
        'next_action' => 'return',
        'return_location' => $returnLocation['name'] ?? '',
    ];
}

try {
    $method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
    if ($method === 'GET') {
        $operation = strtolower(trim((string)($_GET['operation'] ?? '')));
        if ($operation === 'list') {
            dialecticNpcManagerRespond(['success' => true, 'data' => dialecticNpcManagerList()]);
        }
        if ($operation === 'history') {
            dialecticNpcManagerRespond(['success' => true, 'data' => dialecticNpcManagerHistory($_GET)]);
        }
        throw new InvalidArgumentException('Unsupported NPC manager operation');
    }
    if ($method !== 'POST') {
        throw new InvalidArgumentException('GET or POST is required');
    }
    $input = json_decode((string)file_get_contents('php://input'), true);
    if (!is_array($input)) {
        throw new InvalidArgumentException('Invalid JSON request');
    }
    $operation = strtolower(trim((string)($input['operation'] ?? '')));
    if ($operation === 'action') {
        dialecticNpcManagerRespond(['success' => true, 'data' => dialecticNpcManagerAction($input)]);
    }
    if ($operation === 'inject_event') {
        dialecticNpcManagerRespond(['success' => true, 'data' => dialecticNpcManagerInjectEvent($input)]);
    }
    if ($operation === 'delete_event') {
        dialecticNpcManagerRespond(['success' => true, 'data' => dialecticNpcManagerDeleteEvent($input)]);
    }
    throw new InvalidArgumentException('Unsupported NPC manager operation');
} catch (InvalidArgumentException $error) {
    dialecticNpcManagerRespond(['success' => false, 'error' => $error->getMessage()], 400);
} catch (Throwable $error) {
    Logger::error('Dialectic NPC manager API failed: ' . $error->getMessage());
    dialecticNpcManagerRespond(['success' => false, 'error' => 'Unable to process NPC manager request'], 500);
}
