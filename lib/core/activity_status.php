<?php

function dialecticActivityStatusNowMs(): int
{
    return (int) round(microtime(true) * 1000);
}

function dialecticNormalizeActivityUseType(?string $useType): string
{
    $normalized = strtolower(trim((string) $useType));
    $normalized = strtr($normalized, [' ' => '_', '-' => '_']);

    $aliases = [
        'seat' => 'chair',
        'stool' => 'chair',
        'bedroll' => 'bed',
        'campfire' => 'campfire',
        'reloading' => 'reloading_bench',
        'reloading_bench' => 'reloading_bench',
        'workbench' => 'workbench',
        'cookpot' => 'cooking_pot',
    ];

    return $aliases[$normalized] ?? $normalized;
}

function dialecticInferActivityUseTypeFromFurniture(string $furnitureName): string
{
    $label = strtolower(trim($furnitureName));
    if ($label === '') {
        return '';
    }

    $contains = static function (array $needles) use ($label): bool {
        foreach ($needles as $needle) {
            if (strpos($label, $needle) !== false) {
                return true;
            }
        }
        return false;
    };

    if ($contains(['reloading'])) {
        return 'reloading_bench';
    }
    if ($contains(['workbench', 'bench'])) {
        return 'workbench';
    }
    if ($contains(['cook', 'cooking', 'spit', 'campfire'])) {
        return 'cooking_pot';
    }
    if ($contains(['bed', 'sleep'])) {
        return 'bed';
    }
    if ($contains(['chair', 'stool'])) {
        return 'chair';
    }

    return 'furniture';
}

function dialecticInferCurrentActionFromStatus(array $status): string
{
    if (!empty($status['is_dead'])) {
        return 'dead';
    }
    if (!empty($status['is_unconscious'])) {
        return 'unconscious';
    }
    if (!empty($status['is_sleeping'])) {
        return 'sleeping';
    }
    if (!empty($status['is_attacking'])) {
        return 'attacking';
    }
    if (!empty($status['is_in_combat'])) {
        return 'combat';
    }
    if (!empty($status['use_type'])) {
        if ($status['use_type'] === 'furniture' && !empty($status['is_sitting'])) {
            return 'leaning';
        }
        if ($status['use_type'] === 'chair') {
            if (!empty($status['is_sitting'])) {
                return 'sitting';
            }
            return 'sitting';
        }
        if ($status['use_type'] === 'bed') {
            return 'sleeping';
        }
        return 'using';
    }
    if (!empty($status['is_sitting'])) {
        return 'sitting';
    }
    if (!empty($status['is_sneaking'])) {
        return 'sneaking';
    }
    if (!empty($status['is_running'])) {
        return 'running';
    }
    if (!empty($status['is_moving'])) {
        return 'moving';
    }

    return 'idle';
}

function dialecticSanitizeActivityStatusPayload(array $payload): array
{
    $status = [
        'current_action' => strtolower(trim((string) ($payload['current_action'] ?? ''))),
        'current_use' => trim((string) ($payload['current_use'] ?? '')),
        'use_type' => dialecticNormalizeActivityUseType($payload['use_type'] ?? ''),
        'furniture_name' => trim((string) ($payload['furniture_name'] ?? ($payload['furniture'] ?? ''))),
        'attack_target' => trim((string) ($payload['attack_target'] ?? '')),
        'is_in_combat' => !empty($payload['is_in_combat']),
        'is_attacking' => !empty($payload['is_attacking']),
        'is_moving' => !empty($payload['is_moving']),
        'is_running' => !empty($payload['is_running']),
        'is_sneaking' => !empty($payload['is_sneaking']),
        'is_sitting' => !empty($payload['is_sitting']),
        'is_sleeping' => !empty($payload['is_sleeping']),
        'is_unconscious' => !empty($payload['is_unconscious']),
        'is_dead' => !empty($payload['is_dead']),
        'is_weapon_drawn' => !empty($payload['is_weapon_drawn']),
        'timestamp' => isset($payload['timestamp']) && is_numeric($payload['timestamp'])
            ? (int) $payload['timestamp']
            : dialecticActivityStatusNowMs(),
        'gamets' => isset($payload['gamets']) && is_numeric($payload['gamets'])
            ? (int) $payload['gamets']
            : 0,
    ];

    if ($status['use_type'] === '' && $status['furniture_name'] !== '') {
        $status['use_type'] = dialecticInferActivityUseTypeFromFurniture($status['furniture_name']);
    }

    if ($status['current_use'] === '' && $status['use_type'] !== '') {
        $status['current_use'] = $status['use_type'];
    }

    if ($status['current_action'] === '') {
        $status['current_action'] = dialecticInferCurrentActionFromStatus($status);
    }

    return $status;
}

function dialecticUpsertActivityStatusMetadata(array $metadata, array $payload): array
{
    $status = dialecticSanitizeActivityStatusPayload($payload);

    $metadata['activity_status'] = $status;
    $metadata['current_action'] = $status['current_action'];
    $metadata['furniture'] = $status['furniture_name'];

    if ($status['use_type'] !== '') {
        $metadata['use_type'] = $status['use_type'];
    } else {
        unset($metadata['use_type']);
    }

    $metadata['activity_status_timestamp'] = $status['timestamp'];

    return $metadata;
}

function dialecticClearActivityStatusMetadata(array $metadata): array
{
    unset($metadata['activity_status']);
    unset($metadata['current_action']);
    unset($metadata['furniture']);
    unset($metadata['use_type']);
    unset($metadata['activity_status_timestamp']);

    return $metadata;
}

function dialecticBuildActivityStatusMetadataUpdates(array $payload): array
{
    $status = dialecticSanitizeActivityStatusPayload($payload);

    $setValues = [
        'activity_status' => $status,
        'current_action' => $status['current_action'],
        'furniture' => $status['furniture_name'],
        'activity_status_timestamp' => $status['timestamp'],
    ];
    $unsetKeys = [];

    if ($status['use_type'] !== '') {
        $setValues['use_type'] = $status['use_type'];
    } else {
        $unsetKeys[] = 'use_type';
    }

    return [
        'set' => $setValues,
        'unset' => $unsetKeys,
    ];
}

function dialecticApplyNpcMetadataUpdatesByName(string $npcName, array $updates): bool
{
    $npcName = trim($npcName);
    if ($npcName === '' || count($updates) === 0) {
        return false;
    }

    require_once __DIR__ . DIRECTORY_SEPARATOR . 'npc_master.class.php';
    if (!class_exists('NpcMaster')) {
        return false;
    }

    $npcMaster = new NpcMaster();
    $npcData = $npcMaster->getByName($npcName);
    if (!is_array($npcData) || count($npcData) === 0) {
        return false;
    }

    $setValues = [];
    $unsetKeys = [];

    if (array_key_exists('activity_status', $updates)) {
        $activityPayload = $updates['activity_status'];
        unset($updates['activity_status']);

        if (is_array($activityPayload)) {
            $activityUpdates = dialecticBuildActivityStatusMetadataUpdates($activityPayload);
            $setValues = array_merge($setValues, $activityUpdates['set']);
            $unsetKeys = array_merge($unsetKeys, $activityUpdates['unset']);
        } elseif ($activityPayload === null) {
            $unsetKeys = array_merge($unsetKeys, [
                'activity_status',
                'current_action',
                'furniture',
                'use_type',
                'activity_status_timestamp',
            ]);
        }
    }

    foreach ($updates as $key => $value) {
        $metadataKey = trim((string) $key);
        if ($metadataKey === '') {
            continue;
        }

        if ($value === null) {
            $unsetKeys[] = $metadataKey;
        } else {
            $setValues[$metadataKey] = $value;
        }
    }

    return $npcMaster->updateMetadataKeysByName($npcName, $setValues, $unsetKeys);
}

function dialecticActivityStatusIsFresh(array $status, int $maxAgeMs = 45000): bool
{
    $timestamp = (int) ($status['timestamp'] ?? 0);
    if ($timestamp <= 0) {
        return false;
    }

    return (dialecticActivityStatusNowMs() - $timestamp) <= $maxAgeMs;
}

function dialecticHumanizeActivityUseType(string $useType, string $furnitureName = ''): string
{
    $useType = dialecticNormalizeActivityUseType($useType);
    $map = [
        'chair' => 'a chair',
        'bed' => 'a bed',
        'workbench' => 'a workbench',
        'reloading_bench' => 'a reloading bench',
        'cooking_pot' => 'a cooking pot',
        'campfire' => 'a campfire',
        'furniture' => trim($furnitureName) !== '' ? $furnitureName : 'furniture',
    ];

    return $map[$useType] ?? (trim($furnitureName) !== '' ? $furnitureName : str_replace('_', ' ', $useType));
}

function dialecticBuildActivityStatusSummary(array $status): string
{
    $status = dialecticSanitizeActivityStatusPayload($status);
    $action = $status['current_action'];
    $useType = $status['use_type'];
    $target = $status['attack_target'];

    if ($action === 'dead') {
        return 'dead';
    }
    if ($action === 'unconscious') {
        return 'unconscious';
    }
    if ($action === 'sleeping') {
        if ($useType === 'bed') {
            return 'sleeping in ' . dialecticHumanizeActivityUseType($useType, $status['furniture_name']);
        }
        return 'sleeping';
    }
    if ($action === 'sitting') {
        return 'seated';
    }
    if ($action === 'leaning') {
        if ($status['furniture_name'] !== '') {
            return 'leaning on ' . $status['furniture_name'];
        }
        return 'leaning on furniture';
    }
    if ($action === 'attacking') {
        return $target !== '' ? "attacking {$target}" : 'attacking';
    }
    if ($action === 'combat') {
        return $target !== '' ? "in combat with {$target}" : 'in combat';
    }
    if ($action === 'ritual') {
        $ritualType = strtolower(trim((string) ($status['current_use'] ?? '')));
        if ($ritualType !== '' && $ritualType !== 'ritual') {
            return "performing a {$ritualType} ritual";
        }
        return 'performing a ritual';
    }
    if ($useType !== '' && $action === 'using') {
        return 'using ' . dialecticHumanizeActivityUseType($useType, $status['furniture_name']);
    }
    if ($action === 'sneaking') {
        return 'sneaking';
    }
    if ($action === 'running') {
        return 'running';
    }
    if ($action === 'moving') {
        return 'idle';
    }
    if (!empty($status['is_weapon_drawn'])) {
        return 'idle with weapon drawn';
    }

    return 'idle';
}

function dialecticNormalizeActivityStatus(array $metadata): array
{
    $rawStatus = [];
    if (isset($metadata['activity_status']) && is_array($metadata['activity_status'])) {
        $rawStatus = $metadata['activity_status'];
    } elseif (isset($metadata['activity_status']) && is_string($metadata['activity_status'])) {
        $decoded = json_decode($metadata['activity_status'], true);
        if (is_array($decoded)) {
            $rawStatus = $decoded;
        }
    }

    if (!empty($metadata['furniture']) && empty($rawStatus['furniture_name'])) {
        $rawStatus['furniture_name'] = $metadata['furniture'];
    }
    if (!empty($metadata['use_type']) && empty($rawStatus['use_type'])) {
        $rawStatus['use_type'] = $metadata['use_type'];
    }
    if (!empty($metadata['current_action']) && empty($rawStatus['current_action'])) {
        $rawStatus['current_action'] = $metadata['current_action'];
    }
    if (!empty($metadata['activity_status_timestamp']) && empty($rawStatus['timestamp'])) {
        $rawStatus['timestamp'] = $metadata['activity_status_timestamp'];
    } elseif (empty($metadata['activity_status']) && empty($rawStatus['timestamp'])) {
        $rawStatus['timestamp'] = 0;
    }

    $hasMeaningfulData =
        !empty($rawStatus['current_action']) ||
        !empty($rawStatus['current_use']) ||
        !empty($rawStatus['use_type']) ||
        !empty($rawStatus['furniture_name']) ||
        !empty($rawStatus['attack_target']) ||
        !empty($rawStatus['is_in_combat']) ||
        !empty($rawStatus['is_attacking']) ||
        !empty($rawStatus['is_moving']) ||
        !empty($rawStatus['is_running']) ||
        !empty($rawStatus['is_sneaking']) ||
        !empty($rawStatus['is_sitting']) ||
        !empty($rawStatus['is_sleeping']) ||
        !empty($rawStatus['is_unconscious']) ||
        !empty($rawStatus['is_dead']) ||
        !empty($rawStatus['is_weapon_drawn']);

    $status = dialecticSanitizeActivityStatusPayload($rawStatus);
    $status['available'] = $hasMeaningfulData;
    $status['fresh'] = $hasMeaningfulData && dialecticActivityStatusIsFresh($status);
    $status['summary'] = $hasMeaningfulData ? dialecticBuildActivityStatusSummary($status) : '';

    return $status;
}
