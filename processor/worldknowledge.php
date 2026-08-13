<?php

declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . 'lib' . DIRECTORY_SEPARATOR . 'worldknowledge_runtime.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . 'lib' . DIRECTORY_SEPARATOR . 'worldknowledge_forced_context.php';

$GLOBALS['WORLDKNOWLEDGE_HINT'] = '';
$GLOBALS['WORLDKNOWLEDGE_INJECTED_TOPICS'] = [];
$GLOBALS['WORLDKNOWLEDGE_FORCED_SIGNALS'] = [];
$worldKnowledgeStarted = hrtime(true);

if (!function_exists('isWorldKnowledgeEnabled')) {
    function isWorldKnowledgeEnabled(mixed $value): bool
    {
        return !in_array($value, [null, false, 0, '0', 'false', 'off', 'no'], true);
    }
}

$db = $GLOBALS['db'] ?? null;
$requestType = strval($gameRequest[0] ?? '');
$enabled = isWorldKnowledgeEnabled($GLOBALS['WORLDKNOWLEDGE_INFINIUM'] ?? false);
$eligible = DialecticWorldKnowledgeRetriever::isEligibleRequest($requestType);
$inputText = '';

if ($requestType === 'rechat' && $db && method_exists($db, 'fetchOne')) {
    $lastChat = $db->fetchOne("SELECT data FROM eventlog WHERE type='chat' ORDER BY gamets DESC LIMIT 1");
    $inputText = trim(strval($lastChat['data'] ?? ''));
} else {
    $inputText = trim(strval($gameRequest[3] ?? ''));
}
$inputText = preg_replace('/\([^)]*Context location[^)]*\)/iu', ' ', $inputText) ?? $inputText;
$inputText = preg_replace('/\((?:(?:talking|whispering|shouting)|speaking privately)\s+to\s+[^()]+\)/iu', ' ', $inputText) ?? $inputText;
$inputText = trim(preg_replace('/\s+/u', ' ', $inputText) ?? $inputText);

$trace = [
    'algorithm_version' => DialecticWorldKnowledgeRetriever::VERSION,
    'status' => !$enabled ? 'disabled' : (!$eligible ? 'ineligible' : 'no_match'),
    'request_type' => $requestType,
    'npc_name' => strval($GLOBALS['DIALECTIC_NAME'] ?? ''),
    'input_text' => $inputText,
    'normalized_input' => DialecticWorldKnowledgeRetriever::normalize($inputText),
    'catalog_id' => '',
    'catalog_version' => '',
    'grounded_matches' => [],
    'rejected_candidates' => [],
    'tag_decisions' => [],
    'fallback' => ['eligible' => false, 'attempted' => false, 'suggestions' => [], 'resolved_topics' => []],
    'forced_signals' => [],
    'access_decisions' => [],
    'selected_articles' => [],
    'retrieval_elapsed_ms' => 0.0,
    'elapsed_ms' => 0.0,
];

if (!$db || !$enabled || !$eligible) {
    if ($db) {
        $trace['elapsed_ms'] = round((hrtime(true) - $worldKnowledgeStarted) / 1_000_000, 3);
        dialecticWorldKnowledgeRecordAudit($db, $trace);
    }
    return;
}

$catalog = dialecticWorldKnowledgeFetchEffectiveCatalog($db);
if ($catalog === []) {
    $trace['elapsed_ms'] = round((hrtime(true) - $worldKnowledgeStarted) / 1_000_000, 3);
    dialecticWorldKnowledgeRecordAudit($db, $trace);
    return;
}

foreach ($catalog as $row) {
    if (strval($row['source_kind'] ?? '') === 'factory' && strval($row['catalog_version'] ?? '') !== '') {
        $trace['catalog_id'] = strval($row['catalog_id'] ?? '');
        $trace['catalog_version'] = strval($row['catalog_version']);
        break;
    }
}

$limit = max(1, min(3, intval($GLOBALS['WORLDKNOWLEDGE_AMOUNT'] ?? 1)));
$retriever = new DialecticWorldKnowledgeRetriever($catalog);
$retrieval = $retriever->extract($inputText, [], $limit);
$topics = $retrieval['topics'];
$trace['grounded_matches'] = $retrieval['matches'];
$trace['rejected_candidates'] = $retrieval['rejected'];
$trace['tag_decisions'] = $retrieval['tag_decisions'];
$trace['fallback']['eligible'] = boolval($retrieval['fallback_eligible']);
$trace['retrieval_elapsed_ms'] = floatval($retrieval['elapsed_ms']);

$fallbackEnabled = isWorldKnowledgeEnabled($GLOBALS['WORLDKNOWLEDGE_EXTRACTOR_FALLBACK'] ?? true);
$customExtractorEnabled = isWorldKnowledgeEnabled($GLOBALS['WORLDKNOWLEDGE_CUSTOM'] ?? false);
if ($topics === [] && $retrieval['fallback_eligible'] && $fallbackEnabled && $customExtractorEnabled) {
    $trace['fallback']['attempted'] = true;
    try {
        require_once __DIR__ . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . 'lib' . DIRECTORY_SEPARATOR . 'worldknowledge_llm_service.php';
        $language = trim(strval($GLOBALS['CORE_LANG'] ?? 'en')) ?: 'en';
        $response = LLMTopic($inputText, $language);
        $payload = is_string($response) ? json_decode($response, true) : null;
        $generated = trim(strval(is_array($payload) ? ($payload['generated_tags'] ?? '') : ''));
        $suggestions = array_values(array_filter(array_map('trim', preg_split('/[,;|]+/', $generated) ?: [])));
        $topics = $retriever->resolveSuggestions($suggestions, [], $limit);
        $trace['fallback']['suggestions'] = $suggestions;
        $trace['fallback']['resolved_topics'] = $topics;
    } catch (Throwable $exception) {
        $trace['fallback']['error'] = $exception->getMessage();
    }
}

$rowsByTopic = [];
foreach ($catalog as $row) {
    $canonical = trim(strval($row['canonical_topic'] ?? dialecticWorldKnowledgeCanonicalTopic(strval($row['topic'] ?? ''))));
    if ($canonical !== '') {
        $rowsByTopic[$canonical] = $row;
    }
}

$year = dialecticWorldKnowledgeCurrentYear();
$knowledgeTags = dialecticWorldKnowledgeKnowledgeTags();
$selectedCount = 0;
$promptEntryCount = 0;
$deniedCount = 0;
foreach ($topics as $topic) {
    $row = $rowsByTopic[$topic] ?? null;
    if (!is_array($row) || dialecticWorldKnowledgeTopicWasInjected(strval($row['topic'] ?? $topic))) {
        continue;
    }
    if (!dialecticWorldKnowledgeChronologyAllows($row, $year)) {
        $trace['rejected_candidates'][] = [
            'topic' => $topic,
            'reason' => 'outside_chronology',
            'year' => $year,
            'valid_from_year' => $row['valid_from_year'] ?? null,
            'valid_to_year' => $row['valid_to_year'] ?? null,
        ];
        continue;
    }

    $decision = dialecticWorldKnowledgeAccessDecision($row, $knowledgeTags);
    $decision['source'] = 'conversation';
    $trace['access_decisions'][] = $decision;
    dialecticWorldKnowledgeMarkTopicInjected(strval($row['topic'] ?? $topic));
    if ($decision['level'] === 'denied') {
        $deniedCount++;
        $GLOBALS['WORLDKNOWLEDGE_HINT'] .= ($GLOBALS['WORLDKNOWLEDGE_HINT'] === '' ? '' : "\n")
            . dialecticWorldKnowledgeRenderArticleXml($row, $decision, 'conversation');
        $promptEntryCount++;
    } else {
        $GLOBALS['WORLDKNOWLEDGE_HINT'] .= ($GLOBALS['WORLDKNOWLEDGE_HINT'] === '' ? '' : "\n")
            . dialecticWorldKnowledgeRenderArticleXml($row, $decision, 'conversation');
        $selectedCount++;
        $promptEntryCount++;
    }
    $trace['selected_articles'][] = [
        'topic' => $topic,
        'level' => $decision['level'],
        'source_kind' => $row['source_kind'] ?? '',
        'catalog_id' => $row['catalog_id'] ?? null,
        'catalog_version' => $row['catalog_version'] ?? null,
    ];
}

$GLOBALS['WORLDKNOWLEDGE_FORCED_REMAINING'] = max(0, $limit - $promptEntryCount);
$forcedCount = dialecticWorldKnowledgeInjectForcedLocationContext($db)
    + dialecticWorldKnowledgeInjectForcedActorContext($db);
unset($GLOBALS['WORLDKNOWLEDGE_FORCED_REMAINING']);
$trace['forced_signals'] = array_values($GLOBALS['WORLDKNOWLEDGE_FORCED_SIGNALS'] ?? []);
$forcedDeniedCount = count(array_filter(
    $trace['forced_signals'],
    static fn(array $signal): bool => strval($signal['level'] ?? '') === 'denied'
));

if ($trace['fallback']['attempted'] && $selectedCount > 0) {
    $trace['status'] = 'fallback_succeeded';
} elseif ($selectedCount > 0 || $forcedCount > 0) {
    $trace['status'] = 'grounded';
} elseif ($deniedCount > 0 || $forcedDeniedCount > 0) {
    $trace['status'] = 'denied';
} elseif ($trace['fallback']['attempted']) {
    $trace['status'] = 'fallback_failed';
} else {
    $trace['status'] = 'no_match';
}

$trace['elapsed_ms'] = round((hrtime(true) - $worldKnowledgeStarted) / 1_000_000, 3);
dialecticWorldKnowledgeRecordAudit($db, $trace);
