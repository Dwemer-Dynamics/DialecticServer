<?php

declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'worldknowledge_retrieval.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'worldknowledge_topic.php';

function dialecticWorldKnowledgeFetchEffectiveCatalog(object $db): array
{
    if (!method_exists($db, 'fetchAll')) {
        return [];
    }
    try {
        $rows = $db->fetchAll(
            "SELECT entry_id, topic, canonical_topic, topic_desc, knowledge_class, topic_desc_basic,
                    knowledge_class_basic, tags, category, source_kind, catalog_id, catalog_version,
                    source_url, source_revision, setting, region, valid_from_year, valid_to_year,
                    editorial_note, metadata, is_active
               FROM public.worldknowledge_effective
              ORDER BY canonical_topic"
        );
    } catch (Throwable) {
        $rows = $db->fetchAll(
            "SELECT topic, lower(btrim(split_part(topic, ',', 1))) AS canonical_topic,
                    topic_desc, knowledge_class, topic_desc_basic, knowledge_class_basic,
                    tags, category, 'custom'::text AS source_kind, NULL::text AS catalog_id,
                    NULL::text AS catalog_version, NULL::text AS source_url,
                    NULL::text AS source_revision, NULL::text AS setting, NULL::text AS region,
                    NULL::integer AS valid_from_year, NULL::integer AS valid_to_year,
                    NULL::text AS editorial_note, '{}'::jsonb AS metadata, TRUE AS is_active
               FROM public.worldknowledge
              ORDER BY lower(btrim(split_part(topic, ',', 1)))"
        );
    }
    return is_array($rows) ? $rows : [];
}

function dialecticWorldKnowledgeCurrentYear(): ?int
{
    $payload = function_exists('dialecticLatestWorldContextPayload')
        ? dialecticLatestWorldContextPayload()
        : null;
    $year = is_array($payload) && is_array($payload['game_time'] ?? null)
        ? intval($payload['game_time']['year'] ?? 0)
        : 0;
    return $year > 0 ? $year : null;
}

function dialecticWorldKnowledgeChronologyAllows(array $row, ?int $year): bool
{
    if ($year === null) {
        return true;
    }
    $from = is_numeric($row['valid_from_year'] ?? null) ? intval($row['valid_from_year']) : null;
    $to = is_numeric($row['valid_to_year'] ?? null) ? intval($row['valid_to_year']) : null;
    return ($from === null || $year >= $from) && ($to === null || $year <= $to);
}

function dialecticWorldKnowledgeKnowledgeTags(): array
{
    $tags = array_map('trim', explode(',', strval($GLOBALS['WORLDKNOWLEDGE'] ?? '')));
    $tags[] = strval($GLOBALS['DIALECTIC_NAME'] ?? '');
    return array_values(array_unique(array_filter(array_map(
        static fn(string $value): string => strtolower(trim($value)),
        $tags
    ))));
}

/** Resolve one article to advanced, basic, or denied without exposing protected text. */
function dialecticWorldKnowledgeAccessDecision(array $row, array $knowledgeTags): array
{
    $normalizedTags = array_values(array_unique(array_filter(array_map(
        static fn(mixed $value): string => strtolower(trim(strval($value))),
        $knowledgeTags
    ))));
    $advanced = dialecticWorldKnowledgeClassDecision(strval($row['knowledge_class'] ?? ''), $normalizedTags);
    if (!$advanced['denied']
        && ($advanced['allowed'] || in_array('knowall', $normalizedTags, true))
        && trim(strval($row['topic_desc'] ?? '')) !== '') {
        return [
            'topic' => dialecticWorldKnowledgeCanonicalTopic(strval($row['topic'] ?? $row['canonical_topic'] ?? '')),
            'level' => 'advanced',
            'reason' => in_array('knowall', $normalizedTags, true) ? 'knowall' : $advanced['reason'],
            'description' => trim(strval($row['topic_desc'])),
        ];
    }

    $basic = dialecticWorldKnowledgeClassDecision(strval($row['knowledge_class_basic'] ?? ''), $normalizedTags);
    if (!$basic['denied'] && $basic['allowed'] && trim(strval($row['topic_desc_basic'] ?? '')) !== '') {
        return [
            'topic' => dialecticWorldKnowledgeCanonicalTopic(strval($row['topic'] ?? $row['canonical_topic'] ?? '')),
            'level' => 'basic',
            'reason' => $basic['reason'],
            'description' => trim(strval($row['topic_desc_basic'])),
        ];
    }

    return [
        'topic' => dialecticWorldKnowledgeCanonicalTopic(strval($row['topic'] ?? $row['canonical_topic'] ?? '')),
        'level' => 'denied',
        'reason' => $advanced['denied'] || $basic['denied'] ? 'negative_class' : 'missing_required_class',
        'description' => '',
    ];
}

function dialecticWorldKnowledgeClassDecision(string $classes, array $knowledgeTags): array
{
    $parts = array_values(array_filter(array_map(
        static fn(string $value): string => strtolower(trim($value)),
        explode(',', $classes)
    )));
    if ($parts === []) {
        return ['allowed' => true, 'denied' => false, 'reason' => 'unrestricted'];
    }
    $negative = array_map(
        static fn(string $value): string => substr($value, 1),
        array_filter($parts, static fn(string $value): bool => str_starts_with($value, '!'))
    );
    if (array_intersect($negative, $knowledgeTags) !== []) {
        return ['allowed' => false, 'denied' => true, 'reason' => 'negative_class'];
    }
    $positive = array_values(array_filter($parts, static fn(string $value): bool => !str_starts_with($value, '!')));
    if ($positive === []) {
        return ['allowed' => true, 'denied' => false, 'reason' => 'negative_only'];
    }
    return [
        'allowed' => array_intersect($positive, $knowledgeTags) !== [],
        'denied' => false,
        'reason' => array_intersect($positive, $knowledgeTags) !== [] ? 'class_match' : 'missing_required_class',
    ];
}

function dialecticWorldKnowledgeRenderArticleXml(array $row, array $decision, string $source): string
{
    $attributes = [
        'topic' => strval($decision['topic'] ?? ''),
        'level' => strval($decision['level'] ?? 'denied'),
        'category' => strval($row['category'] ?? ''),
        'source' => $source,
        'ownership' => strval($row['source_kind'] ?? ''),
        'catalog' => trim(strval($row['catalog_id'] ?? '') . '/' . strval($row['catalog_version'] ?? ''), '/'),
        'reason' => strval($decision['reason'] ?? ''),
    ];
    $attributeText = implode(' ', array_map(
        static fn(string $name, string $value): string => $name . '="' . htmlspecialchars($value, ENT_QUOTES | ENT_XML1, 'UTF-8') . '"',
        array_keys($attributes),
        array_values($attributes)
    ));
    $description = strval($decision['description'] ?? '');
    return $description === ''
        ? '<article ' . $attributeText . ' />'
        : '<article ' . $attributeText . '>' . htmlspecialchars($description, ENT_QUOTES | ENT_XML1, 'UTF-8') . '</article>';
}

function dialecticWorldKnowledgeRecordAudit(object $db, array $trace): void
{
    if (!method_exists($db, 'execQuery') || !method_exists($db, 'escapeLiteral')) {
        return;
    }
    try {
        $json = static fn(mixed $value): string => $db->escapeLiteral(json_encode(
            $value,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
        )) . '::jsonb';
        $sql = 'INSERT INTO public.worldknowledge_audit ('
            . 'algorithm_version,status,request_type,npc_name,input_text,normalized_input,catalog_id,catalog_version,'
            . 'grounded_matches,rejected_candidates,tag_decisions,fallback,forced_signals,access_decisions,selected_articles,retrieval_elapsed_ms,elapsed_ms'
            . ') VALUES (' . implode(',', [
                $db->escapeLiteral(strval($trace['algorithm_version'] ?? DialecticWorldKnowledgeRetriever::VERSION)),
                $db->escapeLiteral(strval($trace['status'] ?? 'no_match')),
                $db->escapeLiteral(strval($trace['request_type'] ?? '')),
                $db->escapeLiteral(strval($trace['npc_name'] ?? '')),
                $db->escapeLiteral(strval($trace['input_text'] ?? '')),
                $db->escapeLiteral(strval($trace['normalized_input'] ?? '')),
                ($trace['catalog_id'] ?? '') === '' ? 'NULL' : $db->escapeLiteral(strval($trace['catalog_id'])),
                ($trace['catalog_version'] ?? '') === '' ? 'NULL' : $db->escapeLiteral(strval($trace['catalog_version'])),
                $json($trace['grounded_matches'] ?? []),
                $json($trace['rejected_candidates'] ?? []),
                $json($trace['tag_decisions'] ?? []),
                $json($trace['fallback'] ?? []),
                $json($trace['forced_signals'] ?? []),
                $json($trace['access_decisions'] ?? []),
                $json($trace['selected_articles'] ?? []),
                strval(round(floatval($trace['retrieval_elapsed_ms'] ?? 0), 3)),
                strval(round(floatval($trace['elapsed_ms'] ?? 0), 3)),
            ]) . ')';
        $db->execQuery($sql);
    } catch (Throwable $exception) {
        if (class_exists('Logger')) {
            Logger::warn('[WORLDKNOWLEDGE] Unable to record structured audit: ' . $exception->getMessage());
        }
    }
}
