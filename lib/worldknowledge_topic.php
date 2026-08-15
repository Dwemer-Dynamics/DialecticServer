<?php

if (!function_exists('dialecticWorldKnowledgeTopicParts')) {
    function dialecticWorldKnowledgeTopicParts($value): array
    {
        $parts = array_map('trim', explode(',', (string)$value));
        return array_values(array_filter($parts, static fn($part) => $part !== ''));
    }
}

if (!function_exists('dialecticWorldKnowledgeCanonicalTopic')) {
    function dialecticWorldKnowledgeCanonicalTopic($value): string
    {
        $parts = dialecticWorldKnowledgeTopicParts($value);
        return $parts[0] ?? '';
    }
}

if (!function_exists('dialecticWorldKnowledgeComparableTopic')) {
    function dialecticWorldKnowledgeComparableTopic($value): string
    {
        $value = strtolower(str_replace('_', ' ', trim((string)$value)));
        $value = preg_replace('/[^a-z0-9\s]/', ' ', $value);
        return trim(preg_replace('/\s+/', ' ', $value));
    }
}

if (!function_exists('dialecticWorldKnowledgeNormalizeTopicList')) {
    function dialecticWorldKnowledgeNormalizeTopicList($value): string
    {
        $parts = dialecticWorldKnowledgeTopicParts($value);
        if (empty($parts)) {
            return '';
        }

        $canonical = strtolower($parts[0]);
        $canonical = preg_replace('/[^a-z0-9]+/', '_', $canonical);
        $canonical = trim($canonical, '_');
        if ($canonical === '') {
            return '';
        }

        $result = [$canonical];
        $seen = [dialecticWorldKnowledgeComparableTopic($canonical) => true];
        foreach (array_slice($parts, 1) as $alias) {
            $alias = trim(preg_replace('/\s+/', ' ', $alias));
            $comparable = dialecticWorldKnowledgeComparableTopic($alias);
            if ($alias === '' || $comparable === '' || isset($seen[$comparable])) {
                continue;
            }
            $seen[$comparable] = true;
            $result[] = $alias;
        }

        return implode(',', $result);
    }
}

if (!function_exists('dialecticWorldKnowledgeNormalizeCanonicalTopic')) {
    /** Normalize the stable Herika-compatible topic identifier. */
    function dialecticWorldKnowledgeNormalizeCanonicalTopic($value): string
    {
        $value = strtolower(trim((string)$value));
        $value = preg_replace('/[^a-z0-9]+/', '_', $value);
        return trim((string)$value, '_');
    }
}

if (!function_exists('dialecticWorldKnowledgeSplitAliases')) {
    function dialecticWorldKnowledgeSplitAliases($value): array
    {
        $parts = preg_split('/\s*[,;|]\s*/u', (string)$value) ?: [];
        return array_values(array_filter(array_map('trim', $parts), static fn(string $part): bool => $part !== ''));
    }
}

if (!function_exists('dialecticWorldKnowledgeNormalizeAliases')) {
    /** Store aliases as one deduplicated comma-separated list, separate from topic. */
    function dialecticWorldKnowledgeNormalizeAliases($value, string $topic = ''): string
    {
        $topicKey = dialecticWorldKnowledgeComparableTopic($topic);
        $aliases = [];
        $seen = [];
        foreach (dialecticWorldKnowledgeSplitAliases($value) as $alias) {
            $alias = trim(preg_replace('/\s+/u', ' ', $alias) ?? $alias);
            $key = dialecticWorldKnowledgeComparableTopic($alias);
            if ($key === '' || $key === $topicKey || isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $aliases[] = $alias;
        }
        return implode(',', $aliases);
    }
}

if (!function_exists('dialecticWorldKnowledgeFilterAliases')) {
    /** Enforce one unambiguous owner for every canonical topic and alias. */
    function dialecticWorldKnowledgeFilterAliases(string $topic, $aliases, array $rows): array
    {
        $topicKey = dialecticWorldKnowledgeComparableTopic($topic);
        $canonicalOwners = [];
        $aliasOwners = [];
        foreach ($rows as $row) {
            $owner = trim((string)($row['topic'] ?? ''));
            $ownerKey = dialecticWorldKnowledgeComparableTopic($owner);
            if ($ownerKey !== '') {
                $canonicalOwners[$ownerKey] = $owner;
            }
            foreach (dialecticWorldKnowledgeSplitAliases($row['aliases'] ?? '') as $existingAlias) {
                $key = dialecticWorldKnowledgeComparableTopic($existingAlias);
                if ($key !== '') {
                    $aliasOwners[$key][$owner] = true;
                }
            }
        }

        $accepted = [];
        $rejected = [];
        $seen = [];
        foreach (dialecticWorldKnowledgeSplitAliases($aliases) as $alias) {
            $key = dialecticWorldKnowledgeComparableTopic($alias);
            $reason = '';
            if ($key === '' || $key === $topicKey || isset($seen[$key])) {
                $reason = 'duplicate or canonical variant';
            } elseif (isset($canonicalOwners[$key])
                && dialecticWorldKnowledgeComparableTopic($canonicalOwners[$key]) !== $topicKey) {
                $reason = 'matches canonical topic ' . $canonicalOwners[$key];
            } else {
                $otherOwners = array_filter(
                    array_keys($aliasOwners[$key] ?? []),
                    static fn(string $owner): bool => dialecticWorldKnowledgeComparableTopic($owner) !== $topicKey
                );
                if ($otherOwners !== []) {
                    $reason = 'already used by ' . implode(', ', $otherOwners);
                }
            }
            if ($reason !== '') {
                $rejected[] = ['alias' => $alias, 'reason' => $reason];
                continue;
            }
            $seen[$key] = true;
            $accepted[] = trim(preg_replace('/\s+/u', ' ', $alias) ?? $alias);
        }
        return ['aliases' => implode(',', $accepted), 'rejected' => $rejected];
    }
}

if (!function_exists('dialecticWorldKnowledgeNormalizeAccessTag')) {
    /** Normalize legacy namespaced and current plain tags to one canonical vocabulary. */
    function dialecticWorldKnowledgeNormalizeAccessTag($value): string
    {
        $value = strtolower(trim((string)$value));
        if ($value === '') {
            return '';
        }
        $negative = str_starts_with($value, '!');
        if ($negative) {
            $value = substr($value, 1);
        }
        $normalize = static function ($part): string {
            $part = preg_replace('/[^a-z0-9]+/', '_', trim((string)$part));
            return trim((string)$part, '_');
        };
        $parts = explode(':', $value, 2);
        if (count($parts) === 2) {
            $namespace = $normalize($parts[0]);
            $label = $normalize($parts[1]);
            if ($namespace === '' || $label === '') {
                return '';
            }
            $legacyAliases = [
                'person:lonewanderer' => 'lone_wanderer',
                'person:vault_dweller' => 'the_vault_dweller',
                'role:caravaner' => 'traveler',
                'role:courier' => 'traveler',
                'role:military' => 'soldier',
                'faction:military' => 'soldier',
                'faction:legion' => 'caesars_legion',
                'faction:brotherhood' => 'brotherhood_of_steel',
                'faction:brotherhood_of_steelf' => 'brotherhood_of_steel',
                'faction:followers' => 'followers_of_the_apocalypse',
                'faction:great_khan' => 'great_khans',
                'faction:powder_ganger' => 'powder_gangers',
                'faction:raiders' => 'raider',
                'race:supermutant' => 'super_mutant',
                'region:the_divide' => 'divide',
                'place:the_divide' => 'divide',
                'region:zion_canyon' => 'zion',
                'place:zion_canyon' => 'zion',
                'community:zion_canyon' => 'zion',
            ];
            $legacyKey = $namespace . ':' . $label;
            $knownNamespaces = ['person', 'region', 'community', 'place', 'faction', 'role', 'domain', 'race'];
            $result = $legacyAliases[$legacyKey]
                ?? (in_array($namespace, $knownNamespaces, true) ? $label : $normalize($legacyKey));
        } else {
            $result = $normalize($value);
            $plainAliases = [
                'lonewanderer' => 'lone_wanderer',
                'caravaner' => 'traveler',
                'legion' => 'caesars_legion',
                'brotherhood' => 'brotherhood_of_steel',
                'brotherhood_of_steelf' => 'brotherhood_of_steel',
                'followers' => 'followers_of_the_apocalypse',
                'great_khan' => 'great_khans',
                'powder_ganger' => 'powder_gangers',
                'raiders' => 'raider',
                'supermutant' => 'super_mutant',
                'big_empty' => 'big_mt',
                'the_divide' => 'divide',
                'zion_canyon' => 'zion',
            ];
            $result = $plainAliases[$result] ?? $result;
        }
        if ($result === '' || !preg_match('/^[a-z0-9][a-z0-9_]{0,100}$/', $result)) {
            return '';
        }
        return $negative ? '!' . $result : $result;
    }
}

if (!function_exists('dialecticWorldKnowledgeParseAccessRule')) {
    /** Parse the flat Oghma any-of classes while collecting negatives separately. */
    function dialecticWorldKnowledgeParseAccessRule($value): array
    {
        $value = trim((string)$value);
        $allowed = [];
        $denied = [];
        foreach (preg_split('/\s*[,;|]\s*/u', $value) ?: [] as $rawClass) {
            $tag = dialecticWorldKnowledgeNormalizeAccessTag($rawClass);
            if ($tag === '') {
                continue;
            }
            if (str_starts_with($tag, '!')) {
                $denied[] = substr($tag, 1);
            } else {
                $allowed[] = $tag;
            }
        }
        return [
            'allowed' => array_values(array_unique($allowed)),
            'denied' => array_values(array_unique($denied)),
            'unrestricted' => $value === '',
        ];
    }
}

if (!function_exists('dialecticWorldKnowledgeNormalizeAccessRule')) {
    /** Canonicalize a stored Oghma class list to comma-separated plain IDs. */
    function dialecticWorldKnowledgeNormalizeAccessRule($value): string
    {
        $rule = dialecticWorldKnowledgeParseAccessRule($value);
        $classes = $rule['allowed'];
        foreach ($rule['denied'] as $denied) {
            $classes[] = '!' . $denied;
        }
        return implode(',', array_values(array_unique($classes)));
    }
}

if (!function_exists('dialecticWorldKnowledgeAccessTierConflicts')) {
    /** Report duplicate or contradictory classes shared by advanced and basic tiers. */
    function dialecticWorldKnowledgeAccessTierConflicts($advancedValue, $basicValue): array
    {
        $signedClasses = static function ($value): array {
            $rule = dialecticWorldKnowledgeParseAccessRule($value);
            return array_merge($rule['allowed'], array_map(
                static fn(string $denied): string => '!' . $denied,
                $rule['denied']
            ));
        };
        $advanced = $signedClasses($advancedValue);
        $basic = $signedClasses($basicValue);
        $duplicates = array_values(array_unique(array_intersect($advanced, $basic)));
        $advancedByBase = [];
        foreach ($advanced as $class) {
            $advancedByBase[ltrim($class, '!')] = $class;
        }
        $basicByBase = [];
        foreach ($basic as $class) {
            $basicByBase[ltrim($class, '!')] = $class;
        }
        $contradictions = [];
        foreach (array_intersect(array_keys($advancedByBase), array_keys($basicByBase)) as $base) {
            if ($advancedByBase[$base] !== $basicByBase[$base]) {
                $contradictions[] = $base;
            }
        }
        sort($duplicates, SORT_STRING);
        sort($contradictions, SORT_STRING);
        return ['duplicates' => $duplicates, 'contradictions' => $contradictions];
    }
}

?>
