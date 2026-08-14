<?php

require_once(__DIR__.DIRECTORY_SEPARATOR.'worldknowledge_topic.php');
require_once(__DIR__.DIRECTORY_SEPARATOR.'worldknowledge_runtime.php');

if (!function_exists('dialecticWorldKnowledgeNormalizeLookupLabel')) {
    function dialecticWorldKnowledgeNormalizeLookupLabel($value): string
    {
        $value = preg_replace('/([a-z0-9])([A-Z])/u', '$1 $2', trim((string)$value));
        $value = strtolower(str_replace('_', ' ', (string)$value));
        $value = preg_replace('/[^a-z0-9]+/u', ' ', $value);
        return trim((string)preg_replace('/\s+/u', ' ', $value));
    }
}

if (!function_exists('dialecticWorldKnowledgeUniqueSignals')) {
    function dialecticWorldKnowledgeUniqueSignals(array $signals): array
    {
        $result = [];
        foreach ($signals as $signal) {
            $normalized = dialecticWorldKnowledgeNormalizeLookupLabel($signal);
            if ($normalized !== '' && !isset($result[$normalized])) {
                $result[$normalized] = $normalized;
            }
        }
        return array_values($result);
    }
}

if (!function_exists('dialecticWorldKnowledgeLocationNameSignals')) {
    function dialecticWorldKnowledgeLocationNameSignals($location): array
    {
        $normalized = dialecticWorldKnowledgeNormalizeLookupLabel($location);
        if ($normalized === '') {
            return [];
        }

        $signals = [$normalized];
        $genericSuffixes = [
            'general store', 'trading post', 'medical clinic', 'police station',
            'ranger station', 'train station', 'power station', 'air force base',
            'hotel and casino', 'hotel', 'casino', 'saloon', 'store', 'shop',
            'market', 'office', 'house', 'home', 'farm', 'camp', 'vault',
            'bunker', 'cave', 'mine', 'factory', 'schoolhouse', 'station',
        ];
        foreach ($genericSuffixes as $suffix) {
            if ($normalized === $suffix || !str_ends_with($normalized, ' ' . $suffix)) {
                continue;
            }
            $base = trim(substr($normalized, 0, -strlen($suffix)));
            if ($base !== '') {
                $signals[] = $base;
            }
            break;
        }

        return dialecticWorldKnowledgeUniqueSignals($signals);
    }
}

if (!function_exists('dialecticWorldKnowledgeBuildLocationSignalGroups')) {
    function dialecticWorldKnowledgeBuildLocationSignalGroups($location, $worldspace, array $rows): array
    {
        $locationSignals = dialecticWorldKnowledgeLocationNameSignals($location);
        $worldspaceSignals = [$worldspace];

        foreach ($rows as $row) {
            $locationSignals = array_merge(
                $locationSignals,
                dialecticWorldKnowledgeLocationNameSignals($row['name'] ?? '')
            );
            $worldspaceSignals[] = $row['worldspace'] ?? '';
        }

        return [
            'location' => dialecticWorldKnowledgeUniqueSignals($locationSignals),
            'worldspace' => dialecticWorldKnowledgeUniqueSignals($worldspaceSignals),
        ];
    }
}

if (!function_exists('dialecticWorldKnowledgeCurrentNpcSignals')) {
    /** Collect grounded race/species and faction labels from the active NPC profile. */
    function dialecticWorldKnowledgeCurrentNpcSignals($db): array
    {
        $npc = $GLOBALS['DIALECTIC_CORE_CURRENT_NPC_DATA'] ?? [];
        if (!is_array($npc)) {
            return ['race' => [], 'faction' => []];
        }

        $race = trim((string)($npc['race'] ?? ''));
        $raceSignals = [$race];
        $normalizedRace = dialecticWorldKnowledgeNormalizeLookupLabel($race);
        foreach ([' race', ' species'] as $suffix) {
            if ($normalizedRace !== '' && str_ends_with($normalizedRace, $suffix)) {
                $raceSignals[] = trim(substr($normalizedRace, 0, -strlen($suffix)));
            }
        }

        $extended = json_decode((string)($npc['extended_data'] ?? '{}'), true);
        $factions = is_array($extended) && is_array($extended['factions'] ?? null)
            ? $extended['factions']
            : [];
        $factionSignals = [];
        $formIds = [];
        foreach ($factions as $faction) {
            if (!is_array($faction) || (isset($faction['rank']) && intval($faction['rank']) < 0)) {
                continue;
            }
            foreach (['name', 'faction_name', 'label'] as $nameKey) {
                if (trim((string)($faction[$nameKey] ?? '')) !== '') {
                    $factionSignals[] = (string)$faction[$nameKey];
                }
            }
            $formId = trim((string)($faction['formid'] ?? ''));
            if ($formId !== '') {
                $formIds[] = $formId;
                if (function_exists('lookupDescriptionByFormID')) {
                    $record = lookupDescriptionByFormID($formId);
                    if (is_array($record) && trim((string)($record['name'] ?? '')) !== '') {
                        $factionSignals[] = (string)$record['name'];
                    }
                }
            }
        }

        if ($db && method_exists($db, 'fetchAll') && method_exists($db, 'escape') && $formIds !== []) {
            $quoted = array_map(
                static fn(string $formId): string => "'" . $db->escape(strtoupper($formId)) . "'",
                array_values(array_unique($formIds))
            );
            try {
                $rows = $db->fetchAll(
                    'SELECT name FROM public.factions WHERE upper(formid) IN (' . implode(',', $quoted) . ')'
                );
                foreach ((array)$rows as $row) {
                    $factionSignals[] = (string)($row['name'] ?? '');
                }
            } catch (Throwable) {
                // Older installs can reach this code before the factions table migration runs.
            }
        }

        return [
            'race' => dialecticWorldKnowledgeUniqueSignals($raceSignals),
            'faction' => dialecticWorldKnowledgeUniqueSignals($factionSignals),
        ];
    }
}

if (!function_exists('dialecticWorldKnowledgeCollectLocationSignalGroups')) {
    function dialecticWorldKnowledgeCollectLocationSignalGroups($db): array
    {
        $payload = function_exists('dialecticLatestWorldContextPayload')
            ? dialecticLatestWorldContextPayload()
            : null;
        $location = function_exists('dialecticWorldContextLocationFromPayload')
            ? dialecticWorldContextLocationFromPayload($payload)
            : '';
        if ($location === '' && function_exists('DataLastKnownLocationHuman')) {
            $location = trim((string)DataLastKnownLocationHuman(false, false));
        }
        $worldspace = function_exists('dialecticWorldContextWorldspaceFromPayload')
            ? dialecticWorldContextWorldspaceFromPayload($payload)
            : '';

        $rows = [];
        if ($location !== '' && $db && method_exists($db, 'fetchAll')) {
            $locationKey = dialecticWorldKnowledgeNormalizeLookupLabel($location);
            $locationEsc = $db->escape($locationKey);
            $hasWorldspace = false;
            if (function_exists('locationsTableColumns')) {
                $columns = locationsTableColumns();
                $hasWorldspace = !empty($columns['worldspace']);
            }
            $worldspaceSelect = $hasWorldspace ? 'worldspace' : "'' AS worldspace";
            $rows = $db->fetchAll(
                "SELECT name, {$worldspaceSelect}
                   FROM public.locations
                  WHERE btrim(regexp_replace(lower(coalesce(name, '')), '[^a-z0-9]+', ' ', 'g')) = '{$locationEsc}'
                  LIMIT 20"
            );
        }

        return dialecticWorldKnowledgeBuildLocationSignalGroups(
            $location,
            $worldspace,
            is_array($rows) ? $rows : []
        );
    }
}

if (!function_exists('dialecticWorldKnowledgeTopicAliases')) {
    function dialecticWorldKnowledgeTopicAliases($topic): array
    {
        return dialecticWorldKnowledgeUniqueSignals(dialecticWorldKnowledgeTopicParts($topic));
    }
}

if (!function_exists('dialecticWorldKnowledgeFindRowsForSignals')) {
    function dialecticWorldKnowledgeFindRowsForSignals($db, array $signals): array
    {
        $signals = dialecticWorldKnowledgeUniqueSignals($signals);
        if (!$db || empty($signals) || !method_exists($db, 'fetchAll')) {
            return [];
        }

        $quoted = array_map(static fn($signal) => "'" . $db->escape($signal) . "'", $signals);
        try {
            $rows = $db->fetchAll(
                "SELECT topic, canonical_topic, topic_desc, knowledge_class, topic_desc_basic,
                        knowledge_class_basic, category, source_kind, catalog_id, catalog_version,
                        valid_from_year, valid_to_year
                   FROM public.worldknowledge_effective
                  WHERE EXISTS (
                        SELECT 1
                          FROM regexp_split_to_table(topic, E'\\\\s*,\\\\s*') AS topic_alias
                         WHERE btrim(regexp_replace(replace(lower(topic_alias), '_', ' '), '[^a-z0-9]+', ' ', 'g'))
                               IN (" . implode(',', $quoted) . ")
                  )"
            );
        } catch (Throwable) {
            $rows = $db->fetchAll(
                "SELECT topic, topic_desc, knowledge_class, topic_desc_basic, knowledge_class_basic
                   FROM public.worldknowledge
                  WHERE EXISTS (
                        SELECT 1
                          FROM regexp_split_to_table(topic, E'\\\\s*,\\\\s*') AS topic_alias
                         WHERE btrim(regexp_replace(replace(lower(topic_alias), '_', ' '), '[^a-z0-9]+', ' ', 'g'))
                               IN (" . implode(',', $quoted) . ")
                  )"
            );
        }

        $rows = is_array($rows) ? $rows : [];
        $priorities = array_flip($signals);
        usort($rows, static function ($left, $right) use ($priorities) {
            $leftPriority = PHP_INT_MAX;
            foreach (dialecticWorldKnowledgeTopicAliases($left['topic'] ?? '') as $alias) {
                $leftPriority = min($leftPriority, $priorities[$alias] ?? PHP_INT_MAX);
            }
            $rightPriority = PHP_INT_MAX;
            foreach (dialecticWorldKnowledgeTopicAliases($right['topic'] ?? '') as $alias) {
                $rightPriority = min($rightPriority, $priorities[$alias] ?? PHP_INT_MAX);
            }
            return $leftPriority <=> $rightPriority;
        });

        return $rows;
    }
}

if (!function_exists('dialecticWorldKnowledgeClassAllows')) {
    function dialecticWorldKnowledgeClassAllows($classes, array $knowledgeTags): bool
    {
        $decision = dialecticWorldKnowledgeClassDecision((string)$classes, $knowledgeTags);
        return !$decision['denied'] && $decision['allowed'];
    }
}

if (!function_exists('dialecticWorldKnowledgeResolveKnowledgePayload')) {
    function dialecticWorldKnowledgeResolveKnowledgePayload(array $row, array $knowledgeTags): ?array
    {
        $decision = dialecticWorldKnowledgeAccessDecision($row, $knowledgeTags);
        return $decision['level'] === 'denied'
            ? null
            : ['level' => $decision['level'], 'description' => $decision['description']];
    }
}

if (!function_exists('dialecticWorldKnowledgeTopicWasInjected')) {
    function dialecticWorldKnowledgeTopicWasInjected($topic): bool
    {
        foreach (dialecticWorldKnowledgeTopicAliases($topic) as $alias) {
            if (!empty($GLOBALS['WORLDKNOWLEDGE_INJECTED_TOPICS'][$alias])) {
                return true;
            }
        }
        return false;
    }
}

if (!function_exists('dialecticWorldKnowledgeMarkTopicInjected')) {
    function dialecticWorldKnowledgeMarkTopicInjected($topic): void
    {
        foreach (dialecticWorldKnowledgeTopicAliases($topic) as $alias) {
            $GLOBALS['WORLDKNOWLEDGE_INJECTED_TOPICS'][$alias] = true;
        }
    }
}

if (!function_exists('dialecticWorldKnowledgeAppendForcedRows')) {
    function dialecticWorldKnowledgeAppendForcedRows(array $rows, array $knowledgeTags, string $source, int $limit): int
    {
        $added = 0;
        foreach ($rows as $row) {
            $remaining = intval($GLOBALS['WORLDKNOWLEDGE_FORCED_REMAINING'] ?? PHP_INT_MAX);
            if ($added >= $limit || $remaining <= 0 || dialecticWorldKnowledgeTopicWasInjected($row['topic'] ?? '')) {
                continue;
            }
            if (!dialecticWorldKnowledgeChronologyAllows($row, dialecticWorldKnowledgeCurrentYear())) {
                continue;
            }

            $topic = dialecticWorldKnowledgeCanonicalTopic($row['topic'] ?? '');
            $decision = dialecticWorldKnowledgeAccessDecision($row, $knowledgeTags);
            $GLOBALS['WORLDKNOWLEDGE_FORCED_SIGNALS'][] = [
                'source' => $source,
                'topic' => $topic,
                'level' => $decision['level'],
                'reason' => $decision['reason'],
            ];
            if ($decision['level'] === 'denied') {
                dialecticWorldKnowledgeMarkTopicInjected($row['topic'] ?? '');
                continue;
            }
            $GLOBALS['WORLDKNOWLEDGE_HINT'] .= ($GLOBALS['WORLDKNOWLEDGE_HINT'] === '' ? '' : "\n")
                . dialecticWorldKnowledgeRenderArticleXml($row, $decision, $source);
            dialecticWorldKnowledgeMarkTopicInjected($row['topic'] ?? '');
            $added++;
            if (isset($GLOBALS['WORLDKNOWLEDGE_FORCED_REMAINING'])) {
                $GLOBALS['WORLDKNOWLEDGE_FORCED_REMAINING'] = max(0, $remaining - 1);
            }
            if (class_exists('Logger')) {
                Logger::info("[WORLDKNOWLEDGE] Forced {$source} article: {$topic}");
            }
        }
        return $added;
    }
}

if (!function_exists('dialecticWorldKnowledgeInjectForcedLocationContext')) {
    function dialecticWorldKnowledgeInjectForcedLocationContext($db): int
    {
        $enabledValue = $GLOBALS['LOCATION_WORLDKNOWLEDGE'] ?? true;
        $enabled = function_exists('isWorldKnowledgeEnabled')
            ? isWorldKnowledgeEnabled($enabledValue)
            : !in_array($enabledValue, [false, 0, '0', 'false', null], true);
        if (!$enabled) {
            return 0;
        }

        $knowledgeTags = dialecticWorldKnowledgeKnowledgeTags();
        $signals = dialecticWorldKnowledgeCollectLocationSignalGroups($db);
        $added = dialecticWorldKnowledgeAppendForcedRows(
            dialecticWorldKnowledgeFindRowsForSignals($db, $signals['location']),
            $knowledgeTags,
            'location',
            1
        );
        $added += dialecticWorldKnowledgeAppendForcedRows(
            dialecticWorldKnowledgeFindRowsForSignals($db, $signals['worldspace']),
            $knowledgeTags,
            'worldspace',
            1
        );

        return $added;
    }
}

if (!function_exists('dialecticWorldKnowledgeInjectForcedActorContext')) {
    function dialecticWorldKnowledgeInjectForcedActorContext($db): int
    {
        $signals = dialecticWorldKnowledgeCurrentNpcSignals($db);
        $knowledgeTags = dialecticWorldKnowledgeKnowledgeTags();
        $added = 0;
        $isEnabled = static fn($value): bool => function_exists('isWorldKnowledgeEnabled')
            ? isWorldKnowledgeEnabled($value)
            : !in_array($value, [false, 0, '0', 'false', 'off', 'no', null], true);
        if ($isEnabled($GLOBALS['RACE_WORLDKNOWLEDGE'] ?? true)) {
            $added += dialecticWorldKnowledgeAppendForcedRows(
                dialecticWorldKnowledgeFindRowsForSignals($db, $signals['race']),
                $knowledgeTags,
                'race',
                1
            );
        }
        if ($isEnabled($GLOBALS['FACTION_WORLDKNOWLEDGE'] ?? true)) {
            $added += dialecticWorldKnowledgeAppendForcedRows(
                dialecticWorldKnowledgeFindRowsForSignals($db, $signals['faction']),
                $knowledgeTags,
                'faction',
                3
            );
        }
        return $added;
    }
}
