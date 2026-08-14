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
    $tags = ['common'];
    foreach (explode(',', strval($GLOBALS['WORLDKNOWLEDGE'] ?? '')) as $value) {
        $tags[] = $value;
    }

    $npcName = dialecticWorldKnowledgeNormalizeAccessTag(strval($GLOBALS['DIALECTIC_NAME'] ?? ''));
    if ($npcName !== '') {
        $tags[] = $npcName;
    }

    $db = $GLOBALS['db'] ?? null;
    $currentNpc = $GLOBALS['DIALECTIC_CORE_CURRENT_NPC_DATA'] ?? [];
    $npcCodename = is_array($currentNpc)
        ? trim(strval($currentNpc['npc_codename'] ?? ''))
        : '';
    // Read factory template tags into the request context so existing profiles
    // gain controlled defaults without changing their user-authored database row.
    if ($npcCodename !== '' && $db && method_exists($db, 'fetchOne') && method_exists($db, 'escape')) {
        try {
            $template = $db->fetchOne(
                "SELECT worldknowledge_tags FROM public.combined_bio_templates"
                . " WHERE lower(npc_name)=lower('" . $db->escape($npcCodename) . "') LIMIT 1"
            );
            foreach (explode(',', strval($template['worldknowledge_tags'] ?? '')) as $value) {
                $tags[] = $value;
            }
        } catch (Throwable) {
            // Custom installs may not have loaded the optional factory templates yet.
        }
    }
    if (function_exists('dialecticWorldKnowledgeCurrentNpcSignals')) {
        $signals = dialecticWorldKnowledgeCurrentNpcSignals($db);
        foreach ((array)($signals['race'] ?? []) as $value) {
            $tag = dialecticWorldKnowledgeNormalizeAccessTag($value);
            if ($tag !== '') {
                $tags[] = $tag;
            }
        }
        foreach ((array)($signals['faction'] ?? []) as $value) {
            $tag = dialecticWorldKnowledgeNormalizeAccessTag($value);
            if ($tag !== '') {
                $tags[] = $tag;
            }
        }
    }

    if (function_exists('dialecticWorldKnowledgeCollectLocationSignalGroups')) {
        $signals = dialecticWorldKnowledgeCollectLocationSignalGroups($db);
        foreach ((array)($signals['location'] ?? []) as $value) {
            $tag = dialecticWorldKnowledgeNormalizeAccessTag($value);
            if ($tag !== '') {
                $tags[] = $tag;
            }
        }
        foreach ((array)($signals['worldspace'] ?? []) as $value) {
            foreach (dialecticWorldKnowledgeNormalizeRegionTags($value) as $tag) {
                $tags[] = $tag;
            }
        }
    }

    return array_values(array_unique(array_filter(array_map(
        'dialecticWorldKnowledgeNormalizeAccessTag',
        $tags
    ))));
}

/** Map runtime worldspace variants into the two principal TTW knowledge regions. */
function dialecticWorldKnowledgeNormalizeRegionTag($value): string
{
    $tags = dialecticWorldKnowledgeNormalizeRegionTags($value);
    foreach (['mojave', 'capital_wasteland'] as $parent) {
        if (in_array($parent, $tags, true)) {
            return $parent;
        }
    }
    return '';
}

/** Map DLC worldspaces to their local scope plus their principal TTW region. */
function dialecticWorldKnowledgeNormalizeRegionTags($value): array
{
    $value = dialecticWorldKnowledgeNormalizeAccessTag($value);
    if ($value === '') {
        return [];
    }
    $dlcRegions = [
        'point_lookout' => ['point_lookout', 'capital_wasteland'],
        'the_pitt' => ['the_pitt', 'capital_wasteland'],
        'anchorage' => ['anchorage', 'capital_wasteland'],
        'mothership_zeta' => ['mothership_zeta', 'capital_wasteland'],
        'zion' => ['zion', 'mojave'],
        'big_mt' => ['big_mt', 'mojave'],
        'big_empty' => ['big_mt', 'mojave'],
        'sierra_madre' => ['sierra_madre', 'mojave'],
        'the_divide' => ['divide', 'mojave'],
        'divide' => ['divide', 'mojave'],
    ];
    foreach ($dlcRegions as $needle => [$local, $parent]) {
        if (str_contains($value, $needle)) {
            return [$local, $parent];
        }
    }
    if (str_contains($value, 'mojave')) {
        return ['mojave'];
    }
    if (str_contains($value, 'capital_wasteland') || $value === 'washington_dc') {
        return ['capital_wasteland'];
    }
    return [];
}

/** Resolve one article to advanced, basic, or denied without exposing protected text. */
function dialecticWorldKnowledgeAccessDecision(array $row, array $knowledgeTags): array
{
    $normalizedTags = array_values(array_unique(array_filter(array_map(
        static fn(mixed $value): string => strtolower(trim(strval($value))),
        $knowledgeTags
    ))));
    $isFactoryArticle = strtolower(trim(strval($row['source_kind'] ?? ''))) === 'factory'
        || trim(strval($row['catalog_id'] ?? '')) !== '';
    // Legacy custom articles retain the historical knowall shortcut. Factory
    // access-v2 articles must satisfy their reviewed rule so a broad or stale
    // profile tag cannot expose expert, secret, or personal text.
    $knowallAllowed = !$isFactoryArticle && in_array('knowall', $normalizedTags, true);
    $advanced = dialecticWorldKnowledgeClassDecision(strval($row['knowledge_class'] ?? ''), $normalizedTags);
    if (!$advanced['denied']
        && ($advanced['allowed'] || $knowallAllowed)
        && trim(strval($row['topic_desc'] ?? '')) !== '') {
        $decision = [
            'topic' => dialecticWorldKnowledgeCanonicalTopic(strval($row['topic'] ?? $row['canonical_topic'] ?? '')),
            'level' => 'advanced',
            'reason' => $knowallAllowed ? 'knowall' : $advanced['reason'],
            'description' => trim(strval($row['topic_desc'])),
        ];
        if (isset($advanced['matched_clause'])) {
            $decision['matched_clause'] = $advanced['matched_clause'];
            $decision['rule_version'] = $advanced['rule_version'] ?? 1;
        }
        $decision['required_clauses'] = $advanced['required_clauses'] ?? [];
        return $decision;
    }

    $basic = dialecticWorldKnowledgeClassDecision(strval($row['knowledge_class_basic'] ?? ''), $normalizedTags);
    if (!$basic['denied'] && $basic['allowed'] && trim(strval($row['topic_desc_basic'] ?? '')) !== '') {
        $decision = [
            'topic' => dialecticWorldKnowledgeCanonicalTopic(strval($row['topic'] ?? $row['canonical_topic'] ?? '')),
            'level' => 'basic',
            'reason' => $basic['reason'],
            'description' => trim(strval($row['topic_desc_basic'])),
        ];
        if (isset($basic['matched_clause'])) {
            $decision['matched_clause'] = $basic['matched_clause'];
            $decision['rule_version'] = $basic['rule_version'] ?? 1;
        }
        $decision['required_clauses'] = $basic['required_clauses'] ?? [];
        return $decision;
    }

    return [
        'topic' => dialecticWorldKnowledgeCanonicalTopic(strval($row['topic'] ?? $row['canonical_topic'] ?? '')),
        'level' => 'denied',
        'reason' => $advanced['denied'] || $basic['denied'] ? 'negative_class' : 'missing_required_class',
        'description' => '',
        'advanced_rule_version' => $advanced['rule_version'] ?? 1,
        'advanced_required_clauses' => $advanced['required_clauses'] ?? [],
        'basic_rule_version' => $basic['rule_version'] ?? 1,
        'basic_required_clauses' => $basic['required_clauses'] ?? [],
    ];
}

function dialecticWorldKnowledgeClassDecision(string $classes, array $knowledgeTags): array
{
    $rule = dialecticWorldKnowledgeParseAccessRule($classes);
    $knowledgeTags = array_values(array_unique(array_filter(array_map(
        'dialecticWorldKnowledgeNormalizeAccessTag',
        $knowledgeTags
    ))));
    if ($rule['unrestricted']) {
        return [
            'allowed' => true, 'denied' => false, 'reason' => 'unrestricted',
            'rule_version' => $rule['version'], 'required_clauses' => [],
        ];
    }
    if (array_intersect($rule['denied'], $knowledgeTags) !== []) {
        return [
            'allowed' => false, 'denied' => true, 'reason' => 'negative_class',
            'rule_version' => $rule['version'], 'required_clauses' => $rule['clauses'],
        ];
    }
    if ($rule['clauses'] === []) {
        if ($rule['denied'] === []) {
            return [
                'allowed' => false, 'denied' => false, 'reason' => 'invalid_rule',
                'rule_version' => $rule['version'], 'required_clauses' => [],
            ];
        }
        return [
            'allowed' => true, 'denied' => false, 'reason' => 'negative_only',
            'rule_version' => $rule['version'], 'required_clauses' => [],
        ];
    }
    foreach ($rule['clauses'] as $clause) {
        $matched = $rule['version'] === 1
            ? array_intersect($clause, $knowledgeTags) !== []
            : array_diff($clause, $knowledgeTags) === [];
        if ($matched) {
            return [
                'allowed' => true,
                'denied' => false,
                'reason' => $rule['version'] === 1 ? 'legacy_class_match' : 'rule_match',
                'matched_clause' => $clause,
                'rule_version' => $rule['version'],
                'required_clauses' => $rule['clauses'],
            ];
        }
    }
    return [
        'allowed' => false,
        'denied' => false,
        'reason' => 'missing_required_class',
        'matched_clause' => [],
        'rule_version' => $rule['version'],
        'required_clauses' => $rule['clauses'],
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
            . 'grounded_matches,rejected_candidates,tag_decisions,context_tags,fallback,forced_signals,access_decisions,selected_articles,retrieval_elapsed_ms,elapsed_ms'
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
                $json($trace['context_tags'] ?? []),
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
