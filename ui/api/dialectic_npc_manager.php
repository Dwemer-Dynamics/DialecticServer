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
    if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST') {
        throw new InvalidArgumentException('POST is required');
    }
    $input = json_decode((string)file_get_contents('php://input'), true);
    if (!is_array($input)) {
        throw new InvalidArgumentException('Invalid JSON request');
    }
    if (strtolower(trim((string)($input['operation'] ?? ''))) !== 'action') {
        throw new InvalidArgumentException('Unsupported NPC manager operation');
    }
    dialecticNpcManagerRespond(['success' => true, 'data' => dialecticNpcManagerAction($input)]);
} catch (InvalidArgumentException $error) {
    dialecticNpcManagerRespond(['success' => false, 'error' => $error->getMessage()], 400);
} catch (Throwable $error) {
    Logger::error('Dialectic NPC manager API failed: ' . $error->getMessage());
    dialecticNpcManagerRespond(['success' => false, 'error' => 'Unable to process NPC manager request'], 500);
}
