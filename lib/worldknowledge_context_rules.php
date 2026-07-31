<?php

require_once(__DIR__.DIRECTORY_SEPARATOR.'worldknowledge_forced_context.php');

if (!function_exists('dialecticWorldKnowledgeRuleValues')) {
    function dialecticWorldKnowledgeRuleValues($value): array
    {
        if (is_string($value)) {
            $decoded = json_decode($value, true);
            $value = is_array($decoded) ? $decoded : (preg_split('/\s*,\s*/u', $value) ?: []);
        }
        if (!is_array($value)) {
            return [];
        }

        return dialecticWorldKnowledgeUniqueSignals($value);
    }
}

if (!function_exists('dialecticWorldKnowledgePeopleSignals')) {
    function dialecticWorldKnowledgePeopleSignals($people): array
    {
        $names = [];
        foreach (explode('|', (string)$people) as $name) {
            $name = function_exists('dialecticDataStripActorStateSuffix')
                ? dialecticDataStripActorStateSuffix($name)
                : trim((string)preg_replace('/\s*\([^)]+\)\s*$/u', '', $name));
            if ($name !== '') {
                $names[] = $name;
            }
        }
        return dialecticWorldKnowledgeUniqueSignals($names);
    }
}

if (!function_exists('dialecticWorldKnowledgeRuleFactions')) {
    function dialecticWorldKnowledgeRuleFactions($npcMaster, array $currentNpcData): array
    {
        if (!$npcMaster || !method_exists($npcMaster, 'getExtendedData')) {
            return [];
        }

        $extendedData = $npcMaster->getExtendedData($currentNpcData);
        if (!is_array($extendedData) || !is_array($extendedData['factions'] ?? null)) {
            return [];
        }

        $factions = [];
        foreach ($extendedData['factions'] as $faction) {
            if (!is_array($faction)) {
                continue;
            }
            foreach (['name', 'editorid', 'formid'] as $field) {
                if (!empty($faction[$field])) {
                    $factions[] = $faction[$field];
                }
            }
        }
        return dialecticWorldKnowledgeUniqueSignals($factions);
    }
}

if (!function_exists('dialecticWorldKnowledgeWeatherSignals')) {
    function dialecticWorldKnowledgeWeatherSignals($weather): array
    {
        $weather = trim((string)$weather);
        if ($weather === '') {
            return [];
        }

        $withoutPrefix = preg_replace('/^outdoors\s+it\s+is\s+/iu', '', $weather);
        return dialecticWorldKnowledgeUniqueSignals(array_merge(
            [$weather, $withoutPrefix],
            preg_split('/\s*,\s*/u', (string)$withoutPrefix) ?: []
        ));
    }
}

if (!function_exists('dialecticWorldKnowledgeBuildContextRuleContext')) {
    function dialecticWorldKnowledgeBuildContextRuleContext($db, $npcMaster = null): array
    {
        $currentNpcData = is_array($GLOBALS['DIALECTIC_CORE_CURRENT_NPC_DATA'] ?? null)
            ? $GLOBALS['DIALECTIC_CORE_CURRENT_NPC_DATA']
            : [];
        $locationGroups = dialecticWorldKnowledgeCollectLocationSignalGroups($db);
        $payload = function_exists('dialecticLatestWorldContextPayload')
            ? dialecticLatestWorldContextPayload()
            : null;
        $worldspace = function_exists('dialecticWorldContextWorldspaceFromPayload')
            ? dialecticWorldContextWorldspaceFromPayload($payload)
            : '';
        $weather = is_array($payload) ? trim((string)($payload['weather'] ?? '')) : '';
        if ($weather === '' && function_exists('DataLastKnownWeatherHuman')) {
            $weather = DataLastKnownWeatherHuman();
        }

        $environment = '';
        if (is_array($payload)) {
            if (array_key_exists('is_interior', $payload)) {
                $environment = filter_var($payload['is_interior'], FILTER_VALIDATE_BOOLEAN)
                    ? 'interior'
                    : 'exterior';
            } elseif (!empty($payload['environment'])) {
                $environment = trim((string)$payload['environment']);
            }
        }

        $gameRequest = is_array($GLOBALS['gameRequest'] ?? null) ? $GLOBALS['gameRequest'] : [];
        return [
            'npc' => dialecticWorldKnowledgeUniqueSignals([
                $currentNpcData['npc_name'] ?? '',
                $GLOBALS['DIALECTIC_NAME'] ?? '',
            ]),
            'nearby_actor' => dialecticWorldKnowledgePeopleSignals($GLOBALS['CACHE_PEOPLE'] ?? ''),
            'race' => dialecticWorldKnowledgeRuleValues([$currentNpcData['race'] ?? '']),
            'faction' => dialecticWorldKnowledgeRuleFactions($npcMaster, $currentNpcData),
            'profile' => dialecticWorldKnowledgeRuleValues([$currentNpcData['profile_id'] ?? '']),
            'location' => dialecticWorldKnowledgeUniqueSignals($locationGroups['location'] ?? []),
            'worldspace' => dialecticWorldKnowledgeRuleValues(array_merge(
                [$worldspace],
                $locationGroups['worldspace'] ?? []
            )),
            'environment' => dialecticWorldKnowledgeRuleValues([$environment]),
            'weather' => dialecticWorldKnowledgeWeatherSignals($weather),
            'event_type' => dialecticWorldKnowledgeRuleValues([$gameRequest[0] ?? '']),
        ];
    }
}

if (!function_exists('dialecticWorldKnowledgeContextRuleMatches')) {
    function dialecticWorldKnowledgeContextRuleMatches(
        array $conditions,
        array $context,
        ?array &$reasons = null
    ): bool {
        $reasons = [];
        foreach ($conditions as $field => $expectedValues) {
            $expected = dialecticWorldKnowledgeRuleValues($expectedValues);
            if (empty($expected)) {
                continue;
            }
            $actual = dialecticWorldKnowledgeRuleValues($context[$field] ?? []);
            $matched = array_values(array_intersect($expected, $actual));
            if (empty($matched)) {
                return false;
            }
            $reasons[] = $field . '=' . implode('|', $matched);
        }
        return true;
    }
}

if (!function_exists('dialecticWorldKnowledgeFindRowsForRuleSelector')) {
    function dialecticWorldKnowledgeFindRowsForRuleSelector(
        $db,
        string $selectorType,
        string $selectorValue,
        int $limit
    ): array {
        if (!$db || !method_exists($db, 'fetchAll')) {
            return [];
        }
        $selectorType = strtolower(trim($selectorType));
        $selectorValue = dialecticWorldKnowledgeNormalizeLookupLabel($selectorValue);
        if ($selectorValue === '' || !in_array($selectorType, ['topic', 'tag', 'category'], true)) {
            return [];
        }

        $escaped = $db->escape($selectorValue);
        if ($selectorType === 'topic') {
            $condition = "EXISTS (
                SELECT 1
                  FROM regexp_split_to_table(topic, E'\\\\s*,\\\\s*') AS selector_value
                 WHERE regexp_replace(replace(lower(selector_value), '_', ' '), '[^a-z0-9]+', ' ', 'g') = '{$escaped}'
            )";
        } elseif ($selectorType === 'tag') {
            $condition = "EXISTS (
                SELECT 1
                  FROM regexp_split_to_table(coalesce(tags, ''), E'\\\\s*,\\\\s*') AS selector_value
                 WHERE regexp_replace(replace(lower(selector_value), '_', ' '), '[^a-z0-9]+', ' ', 'g') = '{$escaped}'
            )";
        } else {
            $condition = "regexp_replace(replace(lower(coalesce(category, '')), '_', ' '), '[^a-z0-9]+', ' ', 'g') = '{$escaped}'";
        }

        $limit = max(1, min(5, $limit));
        $rows = $db->fetchAll(
            "SELECT topic, topic_desc, knowledge_class, topic_desc_basic, knowledge_class_basic
               FROM public.worldknowledge
              WHERE {$condition}
              ORDER BY topic
              LIMIT {$limit}"
        );
        return is_array($rows) ? $rows : [];
    }
}

if (!function_exists('dialecticWorldKnowledgeLoadContextRules')) {
    function dialecticWorldKnowledgeLoadContextRules($db): array
    {
        if (!$db || !method_exists($db, 'fetchAll')) {
            return [];
        }
        try {
            $rules = $db->fetchAll(
                "SELECT id, label, priority, selector_type, selector_value, conditions, max_articles
                   FROM public.worldknowledge_context_rule
                  WHERE enabled = TRUE
                  ORDER BY priority, id"
            );
            return is_array($rules) ? $rules : [];
        } catch (Throwable $exception) {
            if (class_exists('Logger')) {
                Logger::warn('[WORLDKNOWLEDGE] Context rules unavailable: ' . $exception->getMessage());
            }
            return [];
        }
    }
}

if (!function_exists('dialecticWorldKnowledgeInjectContextRules')) {
    function dialecticWorldKnowledgeInjectContextRules($db, $npcMaster = null): int
    {
        $rules = dialecticWorldKnowledgeLoadContextRules($db);
        if (empty($rules)) {
            return 0;
        }

        $knowledgeTags = array_values(array_filter(array_map(
            'trim',
            explode(',', (string)($GLOBALS['WORLDKNOWLEDGE'] ?? ''))
        )));
        $knowledgeTags[] = (string)($GLOBALS['DIALECTIC_NAME'] ?? '');
        $context = dialecticWorldKnowledgeBuildContextRuleContext($db, $npcMaster);
        $added = 0;

        foreach ($rules as $rule) {
            $conditions = $rule['conditions'] ?? [];
            if (is_string($conditions)) {
                $conditions = json_decode($conditions, true);
            }
            if (!is_array($conditions)) {
                $conditions = [];
            }
            $reasons = [];
            if (!dialecticWorldKnowledgeContextRuleMatches($conditions, $context, $reasons)) {
                continue;
            }

            $limit = max(1, min(5, (int)($rule['max_articles'] ?? 1)));
            $rows = dialecticWorldKnowledgeFindRowsForRuleSelector(
                $db,
                (string)($rule['selector_type'] ?? 'topic'),
                (string)($rule['selector_value'] ?? ''),
                $limit
            );
            $ruleAdded = dialecticWorldKnowledgeAppendForcedRows(
                $rows,
                $knowledgeTags,
                'context rule ' . (int)($rule['id'] ?? 0),
                $limit
            );
            $added += $ruleAdded;

            if (class_exists('Logger')) {
                $label = trim((string)($rule['label'] ?? 'Unnamed rule'));
                $reasonText = empty($reasons) ? 'always' : implode(', ', $reasons);
                Logger::info(
                    "[WORLDKNOWLEDGE] Context rule " . (int)($rule['id'] ?? 0)
                    . " '{$label}' matched ({$reasonText}); added {$ruleAdded} article(s)"
                );
            }
        }
        return $added;
    }
}
