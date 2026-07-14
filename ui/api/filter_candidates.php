<?php
error_reporting(E_ERROR);
session_start();

define('BASE_PATH', dirname(dirname(__DIR__)));
define('LIB_PATH', BASE_PATH . DIRECTORY_SEPARATOR . 'lib');

require_once(LIB_PATH . DIRECTORY_SEPARATOR . 'runtime_bootstrap.php');

dialecticRuntimeBootstrap(BASE_PATH, [
    'load_general_settings' => false,
    'load_stt_connector' => false,
    'run_db_updates' => false,
]);

header('Content-Type: application/json');
header('Cache-Control: no-store, no-cache, must-revalidate');

function filterCandidateConfigs(): array
{
    return [
        'LOCATION_BLACKLIST' => [
            'title' => 'Recent Locations',
            'description' => 'Recent Points of Interest names parsed from the last 5000 eventlog rows.',
        ],
        'ITEM_BLACKLIST' => [
            'title' => 'Recent Items',
            'description' => 'Recent item names parsed from itemfound and nearby-item eventlog entries.',
        ],
        'EVENT_TYPE_FILTER' => [
            'title' => 'Recent Event Types',
            'description' => 'Prompt-relevant event types seen in the last 5000 eventlog rows.',
        ],
    ];
}

function filterCandidateNormalizeKey(string $value): string
{
    $value = trim($value);
    $value = preg_replace('/\s+/u', ' ', $value);
    return strtolower($value ?? '');
}

function filterCandidateCleanValue(string $value): string
{
    $value = trim($value);
    return preg_replace('/\s+/u', ' ', $value) ?? '';
}

function filterCandidateAdd(array &$items, string $value, ?string $sample = null): void
{
    $value = filterCandidateCleanValue($value);
    if ($value === '') {
        return;
    }

    $key = filterCandidateNormalizeKey($value);
    if ($key === '') {
        return;
    }

    if (!isset($items[$key])) {
        $items[$key] = [
            'value' => $value,
            'count' => 0,
            'sample' => $sample ? filterCandidateCleanValue($sample) : '',
        ];
    }

    $items[$key]['count']++;

    if ($items[$key]['sample'] === '' && $sample) {
        $items[$key]['sample'] = filterCandidateCleanValue($sample);
    }
}

function filterCandidateSort(array $items): array
{
    $items = array_values($items);
    usort($items, function (array $left, array $right): int {
        $countCompare = intval($right['count']) <=> intval($left['count']);
        if ($countCompare !== 0) {
            return $countCompare;
        }
        return strcasecmp(strval($left['value']), strval($right['value']));
    });
    return $items;
}

function filterCandidateQueryRecentRows(sql $db, array $types): array
{
    if (empty($types)) {
        return [];
    }

    $quotedTypes = array_map(function (string $type) use ($db): string {
        return "'" . $db->escape($type) . "'";
    }, $types);

    $sql = "
        SELECT rowid, type, data, party
        FROM (
            SELECT rowid, type, data, party
            FROM eventlog
            ORDER BY rowid DESC
            LIMIT 5000
        ) recent
        WHERE type IN (" . implode(', ', $quotedTypes) . ")
        ORDER BY rowid DESC
    ";

    return $db->fetchAll($sql);
}

function filterCandidateStripItemTags(string $itemName): string
{
    $itemName = str_replace(' (STEALING)', '', $itemName);
    $itemName = str_replace(' (LOOKING AT)', '', $itemName);
    $itemName = str_replace(' (HOLDING)', '', $itemName);
    $itemName = preg_replace('/\s+\([^)]+ is holding this\)$/', '', $itemName) ?? $itemName;
    return filterCandidateCleanValue($itemName);
}

function filterCandidateExtractItemNameFromEvent(string $data): string
{
    if (!preg_match('/^.+?\s+(?:found|took|looted|traded|gave)\s+(.+?)(?:,\(value.+\))?\s*$/i', trim($data), $matches)) {
        return '';
    }

    $itemInfo = trim($matches[1]);
    $itemInfo = preg_replace('/\s+(?:from|to)\s+.+$/i', '', $itemInfo) ?? $itemInfo;
    $itemInfo = preg_replace('/\s+in\s+a\s+.+$/i', '', $itemInfo) ?? $itemInfo;
    $itemInfo = preg_replace('/^\d+\s+/i', '', $itemInfo) ?? $itemInfo;
    $itemInfo = preg_replace('/^an?\s+/i', '', $itemInfo) ?? $itemInfo;

    return filterCandidateStripItemTags($itemInfo);
}

function filterCandidateExtractLocationNamesFromPayload(string $party): array
{
    $payload = json_decode($party, true);
    if (!is_array($payload)) {
        return [];
    }

    $pois = $payload['pois'] ?? [];
    if (!is_array($pois)) {
        return [];
    }

    $locations = [];
    foreach ($pois as $poi) {
        if (!is_array($poi)) {
            continue;
        }
        $name = trim((string)($poi['display_name'] ?? $poi['destination_name'] ?? $poi['name'] ?? ''));
        if ($name !== '' && strcasecmp($name, 'Unknown') !== 0) {
            $locations[] = filterCandidateCleanValue($name);
        }
    }

    return array_values(array_unique(array_filter($locations), SORT_STRING));
}

function filterCandidateExtractItemsFromPayload(string $party): array
{
    $payload = json_decode($party, true);
    if (!is_array($payload)) {
        return [];
    }

    $payloadItems = $payload['items'] ?? [];
    if (!is_array($payloadItems)) {
        return [];
    }

    $items = [];
    foreach ($payloadItems as $item) {
        if (!is_array($item)) {
            continue;
        }
        $name = trim((string)($item['name'] ?? ''));
        if ($name !== '' && strcasecmp($name, '<no name>') !== 0) {
            $items[] = filterCandidateStripItemTags($name);
        }
    }

    return array_values(array_unique(array_filter($items), SORT_STRING));
}

function filterCandidateBuildLocations(sql $db): array
{
    $rows = filterCandidateQueryRecentRows($db, ['points_of_interest']);
    $items = [];

    foreach ($rows as $row) {
        $party = strval($row['party'] ?? '');
        foreach (filterCandidateExtractLocationNamesFromPayload($party) as $value) {
            filterCandidateAdd($items, $value, strval($row['data'] ?? ''));
        }
    }

    return filterCandidateSort($items);
}

function filterCandidateBuildItems(sql $db): array
{
    $rows = filterCandidateQueryRecentRows($db, ['itemfound', 'nearby_items']);
    $items = [];

    foreach ($rows as $row) {
        $type = strval($row['type'] ?? '');
        $data = strval($row['data'] ?? '');

        if ($type === 'itemfound') {
            $value = filterCandidateExtractItemNameFromEvent($data);
            if ($value !== '') {
                filterCandidateAdd($items, $value, $data);
            }
            continue;
        }

        if ($type === 'nearby_items') {
            foreach (filterCandidateExtractItemsFromPayload(strval($row['party'] ?? '')) as $value) {
                filterCandidateAdd($items, $value, $data);
            }
        }
    }

    return filterCandidateSort($items);
}

function filterCandidateBuildEventTypes(sql $db): array
{
    $excludedTypes = [
        'combatend',
        'bored',
        'init',
        'info',
        'funcret',
        'book',
        'updateprofile',
        'rechat',
        'setconf',
        'status_msg',
        'user_input',
        'instruction',
        'request',
        'playerinfo',
        'im_alive',
        'region',
    ];

    $quotedTypes = array_map(function (string $type) use ($db): string {
        return "'" . $db->escape($type) . "'";
    }, $excludedTypes);

    $sql = "
        SELECT type, COUNT(*) AS count
        FROM (
            SELECT rowid, type
            FROM eventlog
            ORDER BY rowid DESC
            LIMIT 5000
        ) recent
        WHERE type NOT IN (" . implode(', ', $quotedTypes) . ")
        GROUP BY type
        ORDER BY COUNT(*) DESC, LOWER(type) ASC
    ";

    $rows = $db->fetchAll($sql);
    $items = [];

    foreach ($rows as $row) {
        $value = filterCandidateCleanValue(strval($row['type'] ?? ''));
        if ($value === '') {
            continue;
        }
        $items[] = [
            'value' => $value,
            'count' => intval($row['count'] ?? 0),
            'sample' => '',
        ];
    }

    return $items;
}

try {
    $field = strtoupper(trim(strval($_GET['field'] ?? '')));
    $configs = filterCandidateConfigs();

    if (!isset($configs[$field])) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'error' => 'Unsupported field.',
        ]);
        exit;
    }

    /** @var sql $db */
    $db = $GLOBALS['db'];

    if ($field === 'LOCATION_BLACKLIST') {
        $data = filterCandidateBuildLocations($db);
    } elseif ($field === 'ITEM_BLACKLIST') {
        $data = filterCandidateBuildItems($db);
    } else {
        $data = filterCandidateBuildEventTypes($db);
    }

    echo json_encode([
        'success' => true,
        'field' => $field,
        'meta' => $configs[$field],
        'data' => $data,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (\Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage(),
    ]);
}
