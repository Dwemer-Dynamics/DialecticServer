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
$effectiveSettings = dialecticWorldKnowledgeEffectiveSettings();
$settings = $effectiveSettings['values'];
$enabled = boolval($settings['enabled']);
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
    'catalog_checksum' => '',
    'grounded_matches' => [],
    'rejected_candidates' => [],
    'tag_decisions' => [],
    'context_tags' => [],
    'fallback' => ['eligible' => false, 'attempted' => false, 'suggestions' => [], 'resolved_topics' => []],
    'forced_signals' => [],
    'access_decisions' => [],
    'selected_articles' => [],
    'settings' => $effectiveSettings,
    'prompt_hash' => '',
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
    $trace['status'] = 'unavailable';
    $trace['elapsed_ms'] = round((hrtime(true) - $worldKnowledgeStarted) / 1_000_000, 3);
    dialecticWorldKnowledgeRecordAudit($db, $trace);
    return;
}

foreach ($catalog as $row) {
    if (strval($row['source_kind'] ?? '') === 'factory' && strval($row['catalog_version'] ?? '') !== '') {
        $trace['catalog_id'] = strval($row['catalog_id'] ?? '');
        $trace['catalog_version'] = strval($row['catalog_version']);
        if (method_exists($db, 'fetchOne') && method_exists($db, 'escapeLiteral')) {
            $catalogRecord = $db->fetchOne(
                'SELECT checksum_sha256 FROM public.worldknowledge_catalogs WHERE catalog_id='
                . $db->escapeLiteral($trace['catalog_id']) . ' AND catalog_version='
                . $db->escapeLiteral($trace['catalog_version']) . ' LIMIT 1'
            );
            $trace['catalog_checksum'] = strval($catalogRecord['checksum_sha256'] ?? '');
        }
        break;
    }
}

$topicCount = intval($settings['topic_count']);
$resultLimit = intval($settings['result_limit']);
$retriever = new DialecticWorldKnowledgeRetriever($catalog);
$retrieval = $retriever->extract($inputText, [], $topicCount);
$topics = $retrieval['topics'];
$trace['grounded_matches'] = $retrieval['matches'];
$trace['rejected_candidates'] = $retrieval['rejected'];
$trace['tag_decisions'] = $retrieval['tag_decisions'];
$trace['fallback']['eligible'] = boolval($retrieval['fallback_eligible']);
$trace['retrieval_elapsed_ms'] = floatval($retrieval['elapsed_ms']);

$fallbackEnabled = boolval($settings['extractor_fallback_enabled']);
$fallbackConfigured = strval($settings['connector_id']) !== '';
if ($topics === [] && $retrieval['fallback_eligible'] && $fallbackEnabled && $fallbackConfigured) {
    $trace['fallback']['attempted'] = true;
    $fallbackStarted = hrtime(true);
    try {
        require_once __DIR__ . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . 'lib' . DIRECTORY_SEPARATOR . 'worldknowledge_llm_service.php';
        $language = trim(strval($GLOBALS['CORE_LANG'] ?? 'en')) ?: 'en';
        $response = LLMTopic($inputText, $language);
        $payload = is_string($response) ? json_decode($response, true) : null;
        $generated = trim(strval(is_array($payload) ? ($payload['generated_tags'] ?? '') : ''));
        $suggestions = array_values(array_filter(array_map('trim', preg_split('/[,;|]+/', $generated) ?: [])));
        $topics = $retriever->resolveSuggestions($suggestions, [], $topicCount);
        $trace['fallback']['suggestions'] = $suggestions;
        $trace['fallback']['resolved_topics'] = $topics;
    } catch (Throwable $exception) {
        $trace['fallback']['error'] = $exception->getMessage();
    } finally {
        $trace['fallback']['elapsed_ms'] = round((hrtime(true) - $fallbackStarted) / 1_000_000, 3);
    }
}
$topics = array_slice($topics, 0, $resultLimit);

$rowsByTopic = [];
foreach ($catalog as $row) {
    $canonical = trim(strval($row['canonical_topic'] ?? dialecticWorldKnowledgeCanonicalTopic(strval($row['topic'] ?? ''))));
    if ($canonical !== '') {
        $rowsByTopic[$canonical] = $row;
    }
}

$year = dialecticWorldKnowledgeCurrentYear();
$knowledgeTags = dialecticWorldKnowledgeKnowledgeTags();
$trace['context_tags'] = $knowledgeTags;
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

$GLOBALS['WORLDKNOWLEDGE_FORCED_REMAINING'] = max(0, $resultLimit - $promptEntryCount);
$forcedCount = dialecticWorldKnowledgeInjectForcedLocationContext($db)
    + dialecticWorldKnowledgeInjectForcedActorContext($db);
unset($GLOBALS['WORLDKNOWLEDGE_FORCED_REMAINING']);
$trace['forced_signals'] = array_values($GLOBALS['WORLDKNOWLEDGE_FORCED_SIGNALS'] ?? []);
$forcedDeniedCount = count(array_filter(
    $trace['forced_signals'],
    static fn(array $signal): bool => strval($signal['level'] ?? '') === 'denied'
));

if ($trace['fallback']['attempted'] && $topics !== []) {
    $trace['status'] = 'fallback_succeeded';
} elseif ($promptEntryCount > 0 || $forcedCount > 0 || $forcedDeniedCount > 0) {
    $trace['status'] = 'grounded';
} elseif ($trace['fallback']['attempted']) {
    $trace['status'] = isset($trace['fallback']['error']) ? 'fallback_failed' : 'fallback_unresolved';
} elseif ($trace['fallback']['eligible'] && !$fallbackEnabled) {
    $trace['status'] = 'fallback_disabled';
} elseif ($trace['fallback']['eligible'] && !$fallbackConfigured) {
    $trace['status'] = 'fallback_unconfigured';
} else {
    $trace['status'] = 'no_match';
}

$trace['elapsed_ms'] = round((hrtime(true) - $worldKnowledgeStarted) / 1_000_000, 3);
$articleXml = trim(strval($GLOBALS['WORLDKNOWLEDGE_HINT'] ?? ''));
if ($articleXml !== '') {
    $status = htmlspecialchars(strval($trace['status']), ENT_QUOTES | ENT_XML1, 'UTF-8');
    $GLOBALS['WORLDKNOWLEDGE_HINT'] = '<oghma contract="oghma-parity-v1" status="' . $status . '">' . "\n"
        . $articleXml . "\n</oghma>";
    $trace['prompt_hash'] = hash('sha256', $GLOBALS['WORLDKNOWLEDGE_HINT']);
}
dialecticWorldKnowledgeRecordAudit($db, $trace);
