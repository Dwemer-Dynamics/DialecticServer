<?php

if (!function_exists('dialecticGetVisibleEventLogExcludedTypes')) {
    function dialecticGetVisibleEventLogExcludedTypes()
    {
        return [
            'prechat',
            'rechat',
            'request',
            'user_input',
            'infosave',
            'init',
            'playerinfo',
            'world_context',
            'nearby_actors',
            'nearby_items',
            'points_of_interest',
            'status_msg',
            'region',
        ];
    }
}

if (!function_exists('dialecticGetEventLogUiOrderBy')) {
    function dialecticGetEventLogUiOrderBy()
    {
        return "localts DESC, rowid DESC, gamets DESC, ts DESC";
    }
}

if (!function_exists('dialecticBuildVisibleEventLogWhereClause')) {
    function dialecticBuildVisibleEventLogWhereClause($db, $selectedType = '', $additionalExcludedTypes = [])
    {
        $excludedTypes = array_values(array_unique(array_merge(
            dialecticGetVisibleEventLogExcludedTypes(),
            is_array($additionalExcludedTypes) ? $additionalExcludedTypes : []
        )));
        $escapedTypes = array_map(function ($type) use ($db) {
            return "'" . $db->escape($type) . "'";
        }, $excludedTypes);

        $clauses = [
            "type NOT IN (" . implode(',', $escapedTypes) . ")",
        ];

        $selectedType = trim((string)$selectedType);
        if ($selectedType !== '') {
            $clauses[] = "type = '" . $db->escape($selectedType) . "'";
        }

        return implode(' AND ', $clauses);
    }
}

if (!function_exists('dialecticGetVisibleEventLogTypes')) {
    function dialecticGetVisibleEventLogTypes($db, $additionalExcludedTypes = [])
    {
        $visibleWhereClause = dialecticBuildVisibleEventLogWhereClause($db, '', $additionalExcludedTypes);

        return $db->fetchAll("
            SELECT type, COUNT(*) AS total
            FROM eventlog
            WHERE {$visibleWhereClause}
            GROUP BY type
            ORDER BY type ASC
        ");
    }
}

if (!function_exists('dialecticNormalizeEventLogTypeList')) {
    function dialecticNormalizeEventLogTypeList($types)
    {
        if (!is_array($types)) {
            return [];
        }

        $normalized = [];
        foreach ($types as $type) {
            $type = trim((string)$type);
            if ($type === '') {
                continue;
            }
            $normalized[$type] = $type;
        }

        return array_values($normalized);
    }
}

if (!function_exists('dialecticGetPersistedEventLogHiddenTypes')) {
    function dialecticGetPersistedEventLogHiddenTypes($db)
    {
        $confKey = 'dialectic_eventlog_hidden_types';
        $row = $db->fetchOne("SELECT value FROM conf_opts WHERE id='" . $db->escape($confKey) . "' LIMIT 1");
        $rawValue = trim((string)($row['value'] ?? ''));
        if ($rawValue === '') {
            return [];
        }

        $decoded = json_decode($rawValue, true);
        if (is_array($decoded)) {
            return dialecticNormalizeEventLogTypeList($decoded);
        }

        return dialecticNormalizeEventLogTypeList(explode(',', $rawValue));
    }
}

if (!function_exists('dialecticSavePersistedEventLogHiddenTypes')) {
    function dialecticSavePersistedEventLogHiddenTypes($db, $types)
    {
        $confKey = 'dialectic_eventlog_hidden_types';
        $normalizedTypes = dialecticNormalizeEventLogTypeList($types);

        if (empty($normalizedTypes)) {
            $db->delete('conf_opts', "id='" . $db->escape($confKey) . "'");
            return true;
        }

        return $db->upsertRowOnConflict('conf_opts', [
            'id' => $confKey,
            'value' => json_encode(array_values($normalizedTypes), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        ], 'id');
    }
}

if (!function_exists('dialecticDeleteLatestVisibleEventLogRows')) {
    function dialecticDeleteLatestVisibleEventLogRows($db, $deleteCount, $selectedType = '', $additionalExcludedTypes = [])
    {
        $deleteCount = intval($deleteCount);
        if (!in_array($deleteCount, [20, 50, 100], true)) {
            return [
                'ok' => false,
                'deleted_count' => 0,
                'message' => 'Unsupported delete count.',
            ];
        }

        $visibleWhereClause = dialecticBuildVisibleEventLogWhereClause($db, $selectedType, $additionalExcludedTypes);
        $targetRows = $db->fetchAll("
            SELECT rowid
            FROM eventlog
            WHERE {$visibleWhereClause}
            ORDER BY " . dialecticGetEventLogUiOrderBy() . "
            LIMIT {$deleteCount}
        ");

        $targetRowids = [];
        foreach ($targetRows as $targetRow) {
            $targetRowid = intval($targetRow['rowid'] ?? 0);
            if ($targetRowid > 0) {
                $targetRowids[] = $targetRowid;
            }
        }

        if (!empty($targetRowids)) {
            $targetRowidsStr = implode(',', $targetRowids);
            $db->query("DELETE FROM eventlog WHERE rowid IN ({$targetRowidsStr})");
        }

        return [
            'ok' => true,
            'deleted_count' => count($targetRowids),
            'requested_count' => $deleteCount,
            'message' => 'Deleted latest visible events.',
        ];
    }
}
