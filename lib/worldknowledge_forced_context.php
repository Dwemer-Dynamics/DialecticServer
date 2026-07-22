<?php

require_once(__DIR__.DIRECTORY_SEPARATOR.'worldknowledge_topic.php');

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
        $classes = array_values(array_filter(array_map(
            static fn($value) => strtolower(trim((string)$value)),
            explode(',', (string)$classes)
        )));
        if (empty($classes)) {
            return true;
        }

        $knowledgeTags = array_values(array_filter(array_map(
            static fn($value) => strtolower(trim((string)$value)),
            $knowledgeTags
        )));
        $denied = array_map(
            static fn($value) => substr($value, 1),
            array_filter($classes, static fn($value) => str_starts_with($value, '!'))
        );
        if (!empty(array_intersect($denied, $knowledgeTags))) {
            return false;
        }

        $allowed = array_filter($classes, static fn($value) => !str_starts_with($value, '!'));
        return !empty(array_intersect($allowed, $knowledgeTags));
    }
}

if (!function_exists('dialecticWorldKnowledgeResolveKnowledgePayload')) {
    function dialecticWorldKnowledgeResolveKnowledgePayload(array $row, array $knowledgeTags): ?array
    {
        $normalizedTags = array_map(static fn($value) => strtolower(trim((string)$value)), $knowledgeTags);
        $advancedAllowed = in_array('knowall', $normalizedTags, true)
            || dialecticWorldKnowledgeClassAllows($row['knowledge_class'] ?? '', $knowledgeTags);
        if ($advancedAllowed && trim((string)($row['topic_desc'] ?? '')) !== '') {
            return ['level' => 'advanced', 'description' => trim((string)$row['topic_desc'])];
        }

        if (dialecticWorldKnowledgeClassAllows($row['knowledge_class_basic'] ?? '', $knowledgeTags)
            && trim((string)($row['topic_desc_basic'] ?? '')) !== '') {
            return ['level' => 'basic', 'description' => trim((string)$row['topic_desc_basic'])];
        }

        return null;
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
            if ($added >= $limit || dialecticWorldKnowledgeTopicWasInjected($row['topic'] ?? '')) {
                continue;
            }
            $payload = dialecticWorldKnowledgeResolveKnowledgePayload($row, $knowledgeTags);
            if ($payload === null) {
                continue;
            }

            $topic = dialecticWorldKnowledgeCanonicalTopic($row['topic'] ?? '');
            $levelText = $payload['level'] === 'advanced'
                ? 'You have advanced knowledge on this subject, you can use it in your dialogue'
                : 'You only have basic knowledge on this subject, you can use it in your dialogue';
            $GLOBALS['WORLDKNOWLEDGE_HINT'] .= " \n#World Knowledge ({$levelText}): {$topic}\n\"{$payload['description']}\"";
            dialecticWorldKnowledgeMarkTopicInjected($row['topic'] ?? '');
            $added++;
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

        $knowledgeTags = array_values(array_filter(array_map(
            'trim',
            explode(',', (string)($GLOBALS['WORLDKNOWLEDGE'] ?? ''))
        )));
        $knowledgeTags[] = (string)($GLOBALS['DIALECTIC_NAME'] ?? '');
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
