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
    /** Normalize generated and profile-provided access tags to one stable vocabulary. */
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
        $parts = explode(':', $value, 2);
        $normalized = [];
        foreach ($parts as $part) {
            $part = preg_replace('/[^a-z0-9]+/', '_', trim($part));
            $part = trim((string)$part, '_');
            if ($part === '') {
                return '';
            }
            $normalized[] = $part;
        }
        $result = implode(':', $normalized);
        return $negative ? '!' . $result : $result;
    }
}

if (!function_exists('dialecticWorldKnowledgeParseAccessRule')) {
    /** Parse v2 OR-of-AND clauses while retaining comma-separated legacy any-of rules. */
    function dialecticWorldKnowledgeParseAccessRule($value): array
    {
        $value = trim((string)$value);
        $isV2 = str_contains($value, '&') || str_contains($value, '|');
        $rawClauses = $isV2 ? explode('|', $value) : [$value];
        $clauses = [];
        $denied = [];
        foreach ($rawClauses as $rawClause) {
            $rawTerms = $isV2 ? explode('&', $rawClause) : explode(',', $rawClause);
            $positive = [];
            foreach ($rawTerms as $rawTerm) {
                $tag = dialecticWorldKnowledgeNormalizeAccessTag($rawTerm);
                if ($tag === '') {
                    continue;
                }
                if (str_starts_with($tag, '!')) {
                    $denied[] = substr($tag, 1);
                } else {
                    $positive[] = $tag;
                }
            }
            if ($positive !== []) {
                $clauses[] = array_values(array_unique($positive));
            }
        }
        return [
            'version' => $isV2 ? 2 : 1,
            'clauses' => $clauses,
            'denied' => array_values(array_unique($denied)),
            'unrestricted' => $value === '',
        ];
    }
}

?>
