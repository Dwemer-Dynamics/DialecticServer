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

?>
