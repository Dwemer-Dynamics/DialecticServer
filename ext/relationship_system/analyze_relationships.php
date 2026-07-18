<?php

header('Content-Type: application/json');

$enginePath = __DIR__ . '/../../';
$GLOBALS['ENGINE_PATH'] = $enginePath;
require_once $enginePath . 'lib/runtime_bootstrap.php';
dialecticRuntimeBootstrap($enginePath, [
    'load_general_settings' => true,
    'load_player_name' => true,
    'load_narrator' => false,
]);
require_once $enginePath . 'lib/core/llm_connector.class.php';
require_once $enginePath . 'lib/core/npc_master.class.php';
require_once $enginePath . 'lib/logger.php';
require_once $enginePath . 'lib/relationship_manager.php';
require_once __DIR__ . '/event_baseline.php';

try {
    $npcId = (int)($_POST['npc_id'] ?? 0);
    $npcName = trim((string)($_POST['npc_name'] ?? ''));
    $eventLimit = max(25, min(400, (int)($_POST['event_limit'] ?? 200)));
    $direction = trim((string)($_POST['direction'] ?? ''));
    $customTypes = json_decode((string)($_POST['custom_types'] ?? '[]'), true);
    if (!is_array($customTypes)) {
        $customTypes = [];
    }
    $customTypes = array_values(array_filter(array_map(static function ($type) {
        $type = strtolower(trim((string)$type));
        return preg_match('/^[a-z0-9_-]{1,40}$/', $type) ? $type : '';
    }, $customTypes)));
    if ($npcId <= 0 && $npcName === '') {
        throw new InvalidArgumentException('Missing npc_id or npc_name.');
    }

    $npcData = null;
    if ($npcId > 0) {
        $npcData = (new NpcMaster())->getById($npcId);
        if ($npcData) {
            $npcName = trim((string)($npcData['npc_name'] ?? $npcName));
        }
    }
    if ($npcName === '') {
        throw new RuntimeException('Unable to resolve NPC name.');
    }

    $baseline = dialecticRelBuildEventBaseline($npcName, $eventLimit);
    if (empty($baseline['ok'])) {
        throw new RuntimeException((string)($baseline['error'] ?? 'No event history found.'));
    }

    $connectorStore = new LLMConnector();
    $connectorId = (int)($GLOBALS['RELLLM_CONNECTOR'] ?? 0);
    $connector = $connectorId > 0 ? $connectorStore->readOne($connectorId) : null;
    if (!$connector) {
        throw new RuntimeException('Configure the Relationship Management connector in Global Settings first.');
    }
    $connectorStore->setOldGlobals($connector);
    $driver = $connectorStore->getConnector($connector);

    $npcContext = [];
    foreach (['npc_static_bio' => 'Background', 'personality' => 'Personality', 'occupation' => 'Occupation', 'race' => 'Race'] as $field => $label) {
        if (!empty($npcData[$field])) {
            $npcContext[] = $label . ': ' . trim((string)$npcData[$field]);
        }
    }
    $playerName = trim((string)($GLOBALS['PLAYER_NAME'] ?? 'the Player')) ?: 'the Player';
    $systemPrompt = <<<'PROMPT'
You analyze relationships for a Fallout role-playing character from recent game events.
Return only valid JSON with this shape:
{"relationships":{"Target Name":{"aff":25,"type":"platonic","relation":"friend","best":"optional concise event","worst":"optional concise event"}}}

Affinity is an integer from -100 to 100. Extreme values are rare. Valid common types are romantic, platonic, familial, professional, rival, enemy, and neutral. A concise custom lowercase type is allowed when it is more accurate. Only include people supported by the event history. Do not include the analyzed NPC as their own target. Optional relation, best, and worst fields must be grounded in the supplied events.
PROMPT;
    $userPrompt = "NPC: {$npcName}\nPlayer: {$playerName}\n";
    if ($npcContext) {
        $userPrompt .= implode("\n", $npcContext) . "\n";
    }
    if (!empty($baseline['counterparts'])) {
        $userPrompt .= 'Observed counterparts: ' . implode(', ', $baseline['counterparts']) . "\n";
    }
    if ($direction !== '') {
        $userPrompt .= "User direction: {$direction}\n";
    }
    if ($customTypes) {
        $userPrompt .= 'Available custom relationship types: ' . implode(', ', $customTypes) . "\n";
    }
    $userPrompt .= "\nRecent events (oldest to newest):\n" . $baseline['history'];
    $messages = [
        ['role' => 'system', 'content' => $systemPrompt],
        ['role' => 'user', 'content' => $userPrompt],
    ];
    $response = $driver->fast_request($messages, ['MAX_TOKENS' => 1024], 'relationship_analysis');

    $json = trim((string)$response);
    if (preg_match('/```(?:json)?\s*([\s\S]*?)```/i', $json, $match)) {
        $json = trim($match[1]);
    }
    if (!str_starts_with($json, '{') && preg_match('/\{[\s\S]*\}/', $json, $match)) {
        $json = $match[0];
    }
    $parsed = json_decode($json, true);
    if (!is_array($parsed) || !is_array($parsed['relationships'] ?? null)) {
        throw new RuntimeException('The relationship model returned invalid JSON.');
    }

    $relationships = RelationshipManager::normalizeRelationshipMap($parsed['relationships']);
    foreach (array_keys($relationships) as $targetName) {
        if (strcasecmp($targetName, $npcName) === 0) {
            unset($relationships[$targetName]);
        }
    }
    $model = (string)($connector['model'] ?? $connector['label'] ?? $connector['driver'] ?? 'unknown');
    $auditChanges = [];
    foreach ($relationships as $targetName => $relationship) {
        $auditChanges[$targetName] = [
            'delta' => (int)($relationship['aff'] ?? 0),
            'type' => (string)($relationship['type'] ?? 'neutral'),
            'reason' => 'Built from recent event history',
        ];
    }
    $auditResult = ['changes' => $auditChanges, 'event_count' => (int)$baseline['event_count']];
    $GLOBALS['db']->insert('audit_request', [
        'request' => json_encode(['type' => 'analyze_' . $npcName, 'model' => $model, 'messages' => $messages], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        'result' => json_encode($auditResult, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        'connector' => 'RelationshipLLM (' . ((string)($connector['label'] ?? $model)) . ')',
        'url' => 'ext/relationship_system/analyze_relationships',
    ]);

    echo json_encode([
        'ok' => true,
        'relationships' => $relationships,
        'count' => count($relationships),
        'event_count' => (int)$baseline['event_count'],
        'model' => $model,
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    if (class_exists('Logger')) {
        Logger::error('[REL-AI] ' . $e->getMessage());
    }
    http_response_code($e instanceof InvalidArgumentException ? 400 : 500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
