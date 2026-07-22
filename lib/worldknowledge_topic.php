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

?>
