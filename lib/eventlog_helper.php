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

if (!function_exists('dialecticBuildNpcEventLogPeopleWhereClause')) {
    // Match one NPC token without allowing partial-name matches or far-away audience markers.
    function dialecticBuildNpcEventLogPeopleWhereClause($db, $npcName, $peopleColumn = 'people')
    {
        $peopleColumn = trim((string)$peopleColumn);
        if (!preg_match('/^(?:[A-Za-z_][A-Za-z0-9_]*\.)?[A-Za-z_][A-Za-z0-9_]*$/', $peopleColumn)) {
            $peopleColumn = 'people';
        }

        $escapedNpcName = $db->escape(trim((string)$npcName));
        return "EXISTS (
            SELECT 1
            FROM unnest(string_to_array(trim(BOTH '|' FROM COALESCE({$peopleColumn}, '')), '|')) AS dialectic_person(person_name)
            WHERE lower(regexp_replace(btrim(dialectic_person.person_name), ' \\((busy|hostile|in combat|restrained)\\)$', '', 'i')) = lower('{$escapedNpcName}')
        )";
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

if (!function_exists('dialecticDeleteEventLogRow')) {
    function dialecticDeleteEventLogRow($db, $rowId)
    {
        $rowId = intval($rowId);
        if ($rowId <= 0) {
            return [
                'ok' => false,
                'deleted_count' => 0,
                'message' => 'Invalid event row.',
            ];
        }

        $visibleWhereClause = dialecticBuildVisibleEventLogWhereClause($db);
        $existing = $db->fetchOne("SELECT rowid FROM eventlog WHERE rowid={$rowId} AND {$visibleWhereClause} LIMIT 1");
        if (!$existing) {
            return [
                'ok' => true,
                'rowid' => $rowId,
                'deleted_count' => 0,
                'message' => 'Event is no longer available.',
            ];
        }

        if (!$db->delete('eventlog', "rowid={$rowId}")) {
            return [
                'ok' => false,
                'rowid' => $rowId,
                'deleted_count' => 0,
                'message' => 'Failed to delete event.',
            ];
        }

        return [
            'ok' => true,
            'rowid' => $rowId,
            'deleted_count' => 1,
            'message' => 'Event deleted.',
        ];
    }
}

/*
 * Relationship timeline helpers.
 *
 * Relationship progress is persisted as durable snapshots in core_npc_master_history
 * (tagged through the extended_data history-source marker). The UI surfaces those
 * snapshots as read-only *virtual* event rows so the timeline stays complete without
 * duplicating physical eventlog records.
 */

if (!function_exists('dialecticRelationshipHistorySourceKey')) {
    function dialecticRelationshipHistorySourceKey()
    {
        return '_dialectic_history_source';
    }
}

if (!function_exists('dialecticRelationshipTimelineEventType')) {
    function dialecticRelationshipTimelineEventType()
    {
        return 'relationship';
    }
}

if (!function_exists('dialecticStripHistorySourceMarker')) {
    // The history-source marker is internal bookkeeping: never expose it on live rows,
    // restored profiles, exports or any other raw JSON the user can read.
    function dialecticStripHistorySourceMarker($extendedData)
    {
        $markerKey = dialecticRelationshipHistorySourceKey();

        if (is_array($extendedData)) {
            unset($extendedData[$markerKey]);
            return $extendedData;
        }

        if (!is_string($extendedData) || trim($extendedData) === '') {
            return $extendedData;
        }

        // Decode to objects so empty JSON objects survive the round trip as `{}`.
        $decoded = json_decode($extendedData);
        if (!($decoded instanceof stdClass) || !property_exists($decoded, $markerKey)) {
            return $extendedData;
        }

        unset($decoded->{$markerKey});
        $encoded = json_encode($decoded, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return $encoded === false ? $extendedData : $encoded;
    }
}

if (!function_exists('dialecticStripHistorySourceMarkerFromRow')) {
    function dialecticStripHistorySourceMarkerFromRow($row, $column = 'extended_data')
    {
        if (is_array($row) && array_key_exists($column, $row)) {
            $row[$column] = dialecticStripHistorySourceMarker($row[$column]);
        }

        return $row;
    }
}

if (!function_exists('dialecticRelationshipTierLabel')) {
    function dialecticRelationshipTierLabel($affinity)
    {
        if (!class_exists('RelationshipManager')) {
            $managerPath = __DIR__ . DIRECTORY_SEPARATOR . 'relationship_manager.php';
            if (is_file($managerPath)) {
                require_once $managerPath;
            }
        }

        if (class_exists('RelationshipManager') && method_exists('RelationshipManager', 'getTierLabel')) {
            return trim((string)RelationshipManager::getTierLabel((int)$affinity));
        }

        return '';
    }
}

if (!function_exists('dialecticRelationshipTimelineNormalizeMap')) {
    // Reduce a stored relationship map to the comparable fields the timeline reports on.
    function dialecticRelationshipTimelineNormalizeMap($relationships)
    {
        if (is_string($relationships)) {
            $relationships = json_decode($relationships, true);
        }
        if (!is_array($relationships)) {
            return [];
        }

        $normalized = [];
        foreach ($relationships as $target => $relationship) {
            $target = trim((string)$target);
            if ($target === '' || !is_array($relationship)) {
                continue;
            }
            $type = strtolower(trim((string)($relationship['type'] ?? 'neutral')));
            $normalized[$target] = [
                'aff' => max(-100, min(100, (int)($relationship['aff'] ?? 0))),
                'type' => $type === '' ? 'neutral' : $type,
            ];
        }

        uksort($normalized, 'strcasecmp');

        return $normalized;
    }
}

if (!function_exists('dialecticDiffRelationshipSnapshots')) {
    // Target-level changes between two adjacent snapshots: added, removed, affinity, type.
    function dialecticDiffRelationshipSnapshots($before, $after)
    {
        $before = dialecticRelationshipTimelineNormalizeMap($before);
        $after = dialecticRelationshipTimelineNormalizeMap($after);

        $targets = array_keys($before + $after);
        usort($targets, 'strcasecmp');

        $changes = [];
        foreach ($targets as $target) {
            $old = $before[$target] ?? null;
            $new = $after[$target] ?? null;

            if ($old === null && $new === null) {
                continue;
            }

            if ($old === null) {
                $changes[] = [
                    'target' => $target,
                    'kind' => 'added',
                    'after_aff' => $new['aff'],
                    'after_type' => $new['type'],
                    'after_tier' => dialecticRelationshipTierLabel($new['aff']),
                ];
                continue;
            }

            if ($new === null) {
                $changes[] = [
                    'target' => $target,
                    'kind' => 'removed',
                    'before_aff' => $old['aff'],
                    'before_type' => $old['type'],
                    'before_tier' => dialecticRelationshipTierLabel($old['aff']),
                ];
                continue;
            }

            $affinityChanged = $old['aff'] !== $new['aff'];
            $typeChanged = $old['type'] !== $new['type'];
            if (!$affinityChanged && !$typeChanged) {
                continue;
            }

            $changes[] = [
                'target' => $target,
                'kind' => $affinityChanged && $typeChanged ? 'affinity_type' : ($affinityChanged ? 'affinity' : 'type'),
                'before_aff' => $old['aff'],
                'after_aff' => $new['aff'],
                'before_type' => $old['type'],
                'after_type' => $new['type'],
                'before_tier' => dialecticRelationshipTierLabel($old['aff']),
                'after_tier' => dialecticRelationshipTierLabel($new['aff']),
            ];
        }

        return $changes;
    }
}

if (!function_exists('dialecticRelationshipHistorySourceLabel')) {
    function dialecticRelationshipHistorySourceLabel($source)
    {
        $source = strtolower(trim((string)$source));
        $labels = [
            '' => 'Timeline snapshot',
            'infosave' => 'Game save snapshot',
            'relationship' => 'Relationship update',
            'relationship_evaluation' => 'Relationship evaluation',
            'relationship_profile_editor' => 'Profile editor',
        ];

        if (isset($labels[$source])) {
            return $labels[$source];
        }

        return ucfirst(trim(str_replace(['_', '-'], ' ', $source)));
    }
}

if (!function_exists('dialecticFormatRelationshipAffinity')) {
    function dialecticFormatRelationshipAffinity($affinity)
    {
        return sprintf('%+d', (int)$affinity);
    }
}

if (!function_exists('dialecticDescribeRelationshipChange')) {
    // Short, scannable phrase for one target-level change.
    function dialecticDescribeRelationshipChange(array $change)
    {
        $target = (string)($change['target'] ?? 'Unknown');
        $kind = (string)($change['kind'] ?? '');
        $beforeTier = trim((string)($change['before_tier'] ?? ''));
        $afterTier = trim((string)($change['after_tier'] ?? ''));

        if ($kind === 'added') {
            return sprintf(
                '%s added at %s%s (%s)',
                $target,
                $afterTier !== '' ? $afterTier . ' ' : '',
                dialecticFormatRelationshipAffinity($change['after_aff'] ?? 0),
                (string)($change['after_type'] ?? 'neutral')
            );
        }

        if ($kind === 'removed') {
            return sprintf(
                '%s removed (was %s%s, %s)',
                $target,
                $beforeTier !== '' ? $beforeTier . ' ' : '',
                dialecticFormatRelationshipAffinity($change['before_aff'] ?? 0),
                (string)($change['before_type'] ?? 'neutral')
            );
        }

        if ($kind === 'type') {
            return sprintf(
                '%s %s -> %s',
                $target,
                (string)($change['before_type'] ?? 'neutral'),
                (string)($change['after_type'] ?? 'neutral')
            );
        }

        $notes = [];
        if ($beforeTier !== '' || $afterTier !== '') {
            $notes[] = $beforeTier === $afterTier ? $afterTier : trim($beforeTier . ' -> ' . $afterTier);
        }
        if ($kind === 'affinity_type') {
            $notes[] = (string)($change['before_type'] ?? 'neutral') . ' -> ' . (string)($change['after_type'] ?? 'neutral');
        }

        $summary = sprintf(
            '%s %s -> %s',
            $target,
            dialecticFormatRelationshipAffinity($change['before_aff'] ?? 0),
            dialecticFormatRelationshipAffinity($change['after_aff'] ?? 0)
        );

        return $notes === [] ? $summary : $summary . ' (' . implode(', ', $notes) . ')';
    }
}

if (!function_exists('dialecticExplainRelationshipChange')) {
    // Longer sentence used only inside the hover/focus detail, never as persistent copy.
    function dialecticExplainRelationshipChange($npcName, array $change)
    {
        $npcName = trim((string)$npcName) !== '' ? trim((string)$npcName) : 'NPC';
        $target = (string)($change['target'] ?? 'Unknown');
        $kind = (string)($change['kind'] ?? '');
        $beforeTier = trim((string)($change['before_tier'] ?? ''));
        $afterTier = trim((string)($change['after_tier'] ?? ''));

        if ($kind === 'added') {
            return sprintf(
                '%s to %s: relationship added at %s%s, type %s.',
                $npcName,
                $target,
                $afterTier !== '' ? $afterTier . ' ' : '',
                dialecticFormatRelationshipAffinity($change['after_aff'] ?? 0),
                (string)($change['after_type'] ?? 'neutral')
            );
        }

        if ($kind === 'removed') {
            return sprintf(
                '%s to %s: relationship removed, previously %s%s, type %s.',
                $npcName,
                $target,
                $beforeTier !== '' ? $beforeTier . ' ' : '',
                dialecticFormatRelationshipAffinity($change['before_aff'] ?? 0),
                (string)($change['before_type'] ?? 'neutral')
            );
        }

        $parts = [];
        if ($kind === 'affinity' || $kind === 'affinity_type') {
            $tierNote = '';
            if ($beforeTier !== '' || $afterTier !== '') {
                $tierNote = $beforeTier === $afterTier
                    ? sprintf(' (%s, unchanged tier)', $afterTier)
                    : sprintf(' (%s -> %s)', $beforeTier, $afterTier);
            }
            $parts[] = sprintf(
                'affinity %s -> %s%s',
                dialecticFormatRelationshipAffinity($change['before_aff'] ?? 0),
                dialecticFormatRelationshipAffinity($change['after_aff'] ?? 0),
                $tierNote
            );
        }
        if ($kind === 'type' || $kind === 'affinity_type') {
            $parts[] = sprintf(
                'type %s -> %s',
                (string)($change['before_type'] ?? 'neutral'),
                (string)($change['after_type'] ?? 'neutral')
            );
        }

        return sprintf('%s to %s: %s.', $npcName, $target, implode('; ', $parts));
    }
}

if (!function_exists('dialecticBuildRelationshipTimelineText')) {
    /**
     * Build the concise summary plus the hover/focus detail for one snapshot.
     *
     * @return array{summary:string,detail:string,hidden:int}
     */
    function dialecticBuildRelationshipTimelineText($npcName, array $changes, array $context = [])
    {
        $npcName = trim((string)$npcName) !== '' ? trim((string)$npcName) : 'NPC';
        $visibleLimit = max(1, (int)($context['visible_limit'] ?? 3));

        $phrases = [];
        foreach ($changes as $change) {
            $phrases[] = dialecticDescribeRelationshipChange($change);
        }

        $hidden = max(0, count($phrases) - $visibleLimit);
        $visible = array_slice($phrases, 0, $visibleLimit);
        $summary = $npcName . ': ' . implode('; ', $visible);
        if ($hidden > 0) {
            $summary .= sprintf(' (+%d more)', $hidden);
        }

        $detailLines = [];
        $when = trim((string)($context['when_fallout'] ?? ''));
        $detailLines[] = $when !== ''
            ? sprintf('%s relationship changes recorded %s.', $npcName, $when)
            : sprintf('%s relationship changes.', $npcName);
        $sourceLabel = trim((string)($context['source_label'] ?? ''));
        if ($sourceLabel !== '') {
            $detailLines[] = 'Source: ' . $sourceLabel . '.';
        }
        foreach ($changes as $change) {
            $detailLines[] = dialecticExplainRelationshipChange($npcName, $change);
        }

        return [
            'summary' => $summary,
            'detail' => implode("\n", $detailLines),
            'hidden' => $hidden,
        ];
    }
}

if (!function_exists('dialecticRelationshipTimelineTargets')) {
    function dialecticRelationshipTimelineTargets(array $changes)
    {
        $targets = [];
        foreach ($changes as $change) {
            $target = trim((string)($change['target'] ?? ''));
            if ($target !== '' && !in_array($target, $targets, true)) {
                $targets[] = $target;
            }
        }

        return $targets;
    }
}

if (!function_exists('dialecticEventLogRowSortKey')) {
    // Mirrors dialecticGetEventLogUiOrderBy() so virtual rows interleave the same way.
    function dialecticEventLogRowSortKey($row)
    {
        return [
            (float)($row['localts'] ?? 0),
            (float)($row['rowid'] ?? 0),
            (float)($row['gamets'] ?? 0),
            (float)($row['ts'] ?? 0),
        ];
    }
}

if (!function_exists('dialecticCompareEventLogRowsDesc')) {
    function dialecticCompareEventLogRowsDesc($a, $b)
    {
        $keyA = dialecticEventLogRowSortKey($a);
        $keyB = dialecticEventLogRowSortKey($b);

        foreach ($keyA as $index => $value) {
            if ($value == $keyB[$index]) {
                continue;
            }
            return $value < $keyB[$index] ? 1 : -1;
        }

        return 0;
    }
}

if (!function_exists('dialecticFetchRelationshipTimelineChanges')) {
    /**
     * Read-only virtual timeline rows derived from adjacent relationship snapshots.
     * Nothing is written here: the timeline never inserts duplicate eventlog records.
     *
     * Supported options: npc_id, npc_name, limit, scan_limit, visible_limit, min_gamets.
     */
    function dialecticFetchRelationshipTimelineChanges($db, array $options = [])
    {
        if (!is_object($db)) {
            return [];
        }

        $limit = max(1, min(500, (int)($options['limit'] ?? 100)));
        $scanLimit = max(2, min(5000, (int)($options['scan_limit'] ?? 800)));
        $visibleLimit = max(1, (int)($options['visible_limit'] ?? 3));

        $rowScanLimit = max($scanLimit, min(20000, (int)($options['row_scan_limit'] ?? 6000)));
        $perNpcLimit = max(2, min(100, (int)($options['per_npc_limit'] ?? 12)));
        $markerKey = dialecticRelationshipHistorySourceKey();

        $conditions = [
            "extended_data IS NOT NULL",
            "jsonb_typeof(extended_data) = 'object'",
            // Only snapshots that carry relationship state can produce a timeline row.
            "(extended_data ? 'relationships' OR extended_data ? '{$markerKey}')",
        ];

        $npcId = (int)($options['npc_id'] ?? 0);
        if ($npcId > 0) {
            $conditions[] = "npc_id = {$npcId}";
        }

        $npcName = trim((string)($options['npc_name'] ?? ''));
        if ($npcName !== '') {
            $conditions[] = "lower(btrim(COALESCE(npc_name, ''))) = lower('" . $db->escape($npcName) . "')";
        }

        $where = implode(' AND ', $conditions);
        // Bound the scan by history_id, then keep a per-NPC window so one mass save
        // snapshot cannot crowd every other NPC out of the result.
        $query = "SELECT history_id, npc_id, npc_name, gamets_last_updated,
                         created_epoch, relationships, history_source
                  FROM (
                      SELECT h.history_id, h.npc_id, h.npc_name, h.gamets_last_updated,
                             EXTRACT(EPOCH FROM h.created)::bigint AS created_epoch,
                             h.extended_data -> 'relationships' AS relationships,
                             h.extended_data ->> '{$markerKey}' AS history_source,
                             row_number() OVER (PARTITION BY h.npc_id ORDER BY h.history_id DESC) AS rn
                      FROM (
                          SELECT history_id, npc_id, npc_name, gamets_last_updated, created, extended_data
                          FROM core_npc_master_history
                          WHERE {$where}
                          ORDER BY history_id DESC
                          LIMIT {$rowScanLimit}
                      ) h
                  ) s
                  WHERE s.rn <= {$perNpcLimit}
                  ORDER BY s.history_id DESC
                  LIMIT {$scanLimit}";

        try {
            $rows = $db->fetchAll($query);
        } catch (Throwable $e) {
            error_log('[REL TIMELINE] Snapshot query failed: ' . $e->getMessage());
            return [];
        }

        if (!is_array($rows) || $rows === []) {
            return [];
        }

        // Group by NPC, then walk each NPC oldest -> newest so adjacent snapshots can be diffed.
        $byNpc = [];
        foreach ($rows as $row) {
            $byNpc[(int)($row['npc_id'] ?? 0)][] = $row;
        }

        $eventType = dialecticRelationshipTimelineEventType();
        $timelineRows = [];
        foreach ($byNpc as $snapshots) {
            $snapshots = array_reverse($snapshots);
            $previous = null;
            foreach ($snapshots as $snapshot) {
                if ($previous === null) {
                    // Oldest snapshot in the scanned window has no predecessor to diff against.
                    $previous = $snapshot;
                    continue;
                }

                $changes = dialecticDiffRelationshipSnapshots(
                    $previous['relationships'] ?? null,
                    $snapshot['relationships'] ?? null
                );
                $previous = $snapshot;
                if ($changes === []) {
                    continue;
                }

                $gamets = (float)($snapshot['gamets_last_updated'] ?? 0);
                $source = (string)($snapshot['history_source'] ?? '');
                $snapshotNpcName = trim((string)($snapshot['npc_name'] ?? ''));
                $falloutTime = ($gamets > 0 && function_exists('convert_gamets2fallout_long_date2'))
                    ? convert_gamets2fallout_long_date2($gamets)
                    : '';
                $sourceLabel = dialecticRelationshipHistorySourceLabel($source);
                $text = dialecticBuildRelationshipTimelineText($snapshotNpcName, $changes, [
                    'when_fallout' => $falloutTime !== '' ? 'at ' . $falloutTime : '',
                    'source_label' => $sourceLabel,
                    'visible_limit' => $visibleLimit,
                ]);
                $targets = dialecticRelationshipTimelineTargets($changes);
                $localts = (int)($snapshot['created_epoch'] ?? 0);

                $timelineRows[] = [
                    'virtual' => true,
                    'history_id' => (int)($snapshot['history_id'] ?? 0),
                    'npc_id' => (int)($snapshot['npc_id'] ?? 0),
                    'npc_name' => $snapshotNpcName,
                    'type' => $eventType,
                    'data' => $text['summary'],
                    'detail' => $text['detail'],
                    'hidden_change_count' => $text['hidden'],
                    'people' => '|' . implode('|', array_merge([$snapshotNpcName], $targets)) . '|',
                    'targets' => $targets,
                    'changes' => $changes,
                    'change_count' => count($changes),
                    'source' => $source,
                    'source_label' => $sourceLabel,
                    'gamets' => $gamets,
                    'fallout_time' => $falloutTime,
                    'localts' => $localts,
                    'local_time' => $localts > 0 ? gmdate('d-m-Y H:i:s', $localts) : '',
                    'ts' => 0,
                    'rowid' => 0,
                ];
            }
        }

        $minGamets = (float)($options['min_gamets'] ?? 0);
        if ($minGamets > 0) {
            $timelineRows = array_values(array_filter($timelineRows, function ($row) use ($minGamets) {
                return (float)($row['gamets'] ?? 0) >= $minGamets;
            }));
        }

        usort($timelineRows, 'dialecticCompareEventLogRowsDesc');

        return array_slice($timelineRows, 0, $limit);
    }
}

if (!function_exists('dialecticMergeRelationshipTimelineRows')) {
    /**
     * Interleave virtual rows into one already-ordered page of physical rows.
     *
     * Physical pagination is untouched: a virtual row only joins the page whose
     * timestamp window contains it, so page numbers, offsets and totals still
     * describe the real eventlog table.
     */
    function dialecticMergeRelationshipTimelineRows(array $eventRows, array $relationshipRows, $isFirstPage = true, $isLastPage = true)
    {
        if ($relationshipRows === []) {
            return $eventRows;
        }

        if ($eventRows === []) {
            if (!$isFirstPage || !$isLastPage) {
                return $eventRows;
            }
            usort($relationshipRows, 'dialecticCompareEventLogRowsDesc');
            return $relationshipRows;
        }

        $newestRow = $eventRows[0];
        $oldestRow = $eventRows[count($eventRows) - 1];

        $windowed = array_filter($relationshipRows, function ($row) use ($newestRow, $oldestRow, $isFirstPage, $isLastPage) {
            if (!$isFirstPage && dialecticCompareEventLogRowsDesc($row, $newestRow) < 0) {
                return false;
            }
            if (!$isLastPage && dialecticCompareEventLogRowsDesc($row, $oldestRow) > 0) {
                return false;
            }
            return true;
        });

        $merged = array_merge($eventRows, array_values($windowed));
        usort($merged, 'dialecticCompareEventLogRowsDesc');

        return $merged;
    }
}

if (!function_exists('dialecticRelationshipTimelineIsVisible')) {
    // Honour the same "hide event type" / "only this type" filters as physical rows.
    function dialecticRelationshipTimelineIsVisible($selectedType = '', $hiddenTypes = [])
    {
        $eventType = dialecticRelationshipTimelineEventType();

        $selectedType = trim((string)$selectedType);
        if ($selectedType !== '' && strcasecmp($selectedType, $eventType) !== 0) {
            return false;
        }

        foreach (dialecticNormalizeEventLogTypeList($hiddenTypes) as $hiddenType) {
            if (strcasecmp($hiddenType, $eventType) === 0) {
                return false;
            }
        }

        return !in_array($eventType, dialecticGetVisibleEventLogExcludedTypes(), true);
    }
}

if (!function_exists('dialecticGetEventLogTypeOptions')) {
    // Visible physical types plus the virtual relationship type, so both can be hidden.
    function dialecticGetEventLogTypeOptions($db, $additionalExcludedTypes = [], $relationshipRowCount = null)
    {
        $options = dialecticGetVisibleEventLogTypes($db, $additionalExcludedTypes);
        $options = is_array($options) ? $options : [];

        if ($relationshipRowCount === null || (int)$relationshipRowCount <= 0) {
            return $options;
        }

        if (!dialecticRelationshipTimelineIsVisible('', $additionalExcludedTypes)) {
            return $options;
        }

        $options[] = [
            'type' => dialecticRelationshipTimelineEventType(),
            'total' => (int)$relationshipRowCount,
        ];
        usort($options, function ($a, $b) {
            return strcasecmp((string)($a['type'] ?? ''), (string)($b['type'] ?? ''));
        });

        return $options;
    }
}

if (!function_exists('dialecticRelationshipTimelineTooltipId')) {
    function dialecticRelationshipTimelineTooltipId(array $row, $idPrefix = 'rel-tip')
    {
        $idPrefix = preg_replace('/[^A-Za-z0-9_-]/', '', (string)$idPrefix);
        if ($idPrefix === '') {
            $idPrefix = 'rel-tip';
        }

        return $idPrefix . '-' . (int)($row['history_id'] ?? 0);
    }
}

if (!function_exists('dialecticRelationshipTimelineTooltipHtml')) {
    /**
     * Compact amber summary with the full explanation exposed on hover and keyboard
     * focus (aria-describedby), instead of printing the long form into the table.
     */
    function dialecticRelationshipTimelineTooltipHtml(array $row, $idPrefix = 'rel-tip')
    {
        $summary = (string)($row['data'] ?? '');
        $detail = trim((string)($row['detail'] ?? ''));

        if ($detail === '') {
            return '<span class="rel-timeline-summary">' . htmlspecialchars($summary) . '</span>';
        }

        $tooltipId = dialecticRelationshipTimelineTooltipId($row, $idPrefix);

        return '<span class="rel-timeline-tip" tabindex="0" role="note" aria-describedby="'
            . htmlspecialchars($tooltipId, ENT_QUOTES) . '">'
            . '<span class="rel-timeline-summary">' . htmlspecialchars($summary) . '</span>'
            . '<span class="rel-timeline-detail" role="tooltip" id="'
            . htmlspecialchars($tooltipId, ENT_QUOTES) . '">'
            . htmlspecialchars($detail)
            . '</span></span>';
    }
}

if (!function_exists('dialecticRelationshipTimelineReadOnlyHtml')) {
    // Virtual rows are derived data: no checkbox, no delete affordance.
    function dialecticRelationshipTimelineReadOnlyHtml($label = 'Read-only relationship timeline entry')
    {
        return '<span class="rel-timeline-readonly" role="img" aria-label="' . htmlspecialchars($label, ENT_QUOTES)
            . '" title="' . htmlspecialchars($label, ENT_QUOTES) . '">&#128274;</span>';
    }
}
