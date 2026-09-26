<?php
require_once __DIR__ . '/chat_helper_functions.php';
// Rolemaster runs outside main.php, which normally sets the dialogue chunk sizes.
if (!defined('MAXIMUM_SENTENCE_SIZE')) define('MAXIMUM_SENTENCE_SIZE', 125);
if (!defined('MINIMUM_SENTENCE_SIZE')) define('MINIMUM_SENTENCE_SIZE', 15);

// Constrain the scene to the current cast and each action's existing parameter contract.
function dialecticDirectorResponseFormat(array $actors, array $catalog, string $player): array
{
    $speakers = array_values(array_diff(array_keys($actors), [$player, 'The Narrator']));
    $actionSchemas = [];
    foreach ($catalog as $code => $definition) {
        $parameters = $definition['parameters'] ?? [];
        $properties = $parameters['properties'] ?? [];
        foreach ($properties as $key => &$property) {
            // Strict schemas require every property; null represents an omitted optional argument.
            if (!in_array($key, $parameters['required'] ?? [], true)) {
                $property = ['anyOf' => [$property, ['type' => 'null']]];
            }
        }
        unset($property);
        $actionSchemas[] = ['type' => 'object', 'additionalProperties' => false,
            'properties' => [
                'speaker' => ['type' => 'string', 'enum' => array_values($definition['speakers'])],
                'after_line' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 5],
                'command_name' => ['type' => 'string', 'enum' => [$code]],
                'parameters' => ['type' => 'object', 'additionalProperties' => false,
                    'properties' => (object)$properties, 'required' => array_keys($properties)],
            ], 'required' => ['speaker', 'after_line', 'command_name', 'parameters']];
    }
    return ['type' => 'json_schema', 'json_schema' => [
        'name' => 'director_scene', 'strict' => true,
        'schema' => ['type' => 'object', 'additionalProperties' => false,
            'properties' => [
                'lines' => ['type' => 'array', 'minItems' => 1, 'maxItems' => 5,
                    'items' => ['type' => 'object', 'additionalProperties' => false,
                        'properties' => [
                            'speaker' => ['type' => 'string', 'enum' => $speakers],
                            'listener' => ['type' => 'string', 'enum' => array_values(array_unique([...$speakers, $player])),
                                'description' => 'If the listener is the player, this must be the final line.'],
                            'text' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 600],
                        ], 'required' => ['speaker', 'listener', 'text']]],
                'actions' => ['type' => 'array', 'maxItems' => $actionSchemas ? 3 : 0,
                    'items' => $actionSchemas ? ['anyOf' => $actionSchemas]
                        : ['type' => 'object', 'properties' => new stdClass(), 'additionalProperties' => false]],
            ], 'required' => ['lines', 'actions']],
    ]];
}

// Scope the existing JSON connectors' templates and dialogue-only options to this scene request.
function dialecticRequestDirectorScene($connection, array $prompt, array $actors, array $catalog, string $player): array
{
    require_once __DIR__ . '/../functions/json_response.php';
    $keys = ['responseTemplate', 'structuredOutputTemplate', 'CONNECTOR', 'PATCH', 'DIALECTIC_NO_EXAMPLES',
        'FUNCTIONS_ARE_ENABLED', 'PATCH_PROMPT_ENFORCE_ACTIONS', 'DIRECT_NARRATOR_DIALOGUE',
        'DIALECTIC_NAME', 'DIALECTIC_PERS', 'DIALECTIC_SPEECHSTYLE', 'TTSFUNCTION'];
    $saved = [];
    foreach ($keys as $key) {
        if (array_key_exists($key, $GLOBALS)) $saved[$key] = $GLOBALS[$key];
    }
    try {
        $GLOBALS['responseTemplate'] = ['lines' => [['speaker' => 'Eligible NPC name',
            'listener' => 'Present NPC or player name', 'text' => 'Exact spoken words']], 'actions' => []];
        if ($catalog) {
            $GLOBALS['responseTemplate']['actions'][] = ['speaker' => 'Eligible action speaker',
                'after_line' => 1, 'command_name' => 'Catalog code', 'parameters' => new stdClass()];
        }
        $GLOBALS['structuredOutputTemplate'] = dialecticDirectorResponseFormat($actors, $catalog, $player);
        $GLOBALS['FUNCTIONS_ARE_ENABLED'] = false;
        $GLOBALS['PATCH_PROMPT_ENFORCE_ACTIONS'] = false;
        $GLOBALS['DIRECT_NARRATOR_DIALOGUE'] = false;
        $GLOBALS['DIALECTIC_NAME'] = 'Director';
        $GLOBALS['DIALECTIC_PERS'] = '';
        $GLOBALS['DIALECTIC_SPEECHSTYLE'] = '';
        $GLOBALS['TTSFUNCTION'] = '';
        $GLOBALS['DIALECTIC_NO_EXAMPLES'] = true;
        unset($GLOBALS['PATCH']['PREAPPEND']);
        $driver = $GLOBALS['CURRENT_CONNECTOR'];
        $GLOBALS['CONNECTOR'][$driver]['PREFILL_JSON'] = false;
        $GLOBALS['CONNECTOR'][$driver]['ENFORCE_JSON'] = true;
        // Preserve the connector's schema opt-in; JSON-only connectors still receive the scene template.
        $format = ['type' => 'json_object'];
        if (!empty($GLOBALS['CONNECTOR'][$driver]['json_schema'])) {
            $format = $GLOBALS['structuredOutputTemplate'];
        }
        $connection->open($prompt, ['response_format' => $format, 'MAX_TOKENS' => 4000]);
        do { $connection->process(); } while (!$connection->isDone());
        $raw = $connection->close('director_scene');
        $raw = trim($raw);
        // Accept one complete Markdown JSON fence, but keep surrounding prose invalid.
        if (preg_match('/\A```(?:json)?[ \t]*\R(.*)\R```[ \t]*\z/is', $raw, $match)) {
            $raw = trim($match[1]);
        }
        try {
            $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $error) {
            throw new RuntimeException('Director did not return JSON: ' . $error->getMessage(), 0, $error);
        }
        if (!is_array($decoded)) throw new RuntimeException('Director did not return a scene object');
        return dialecticValidateDirectorScene($decoded, $actors, $catalog, $player);
    } finally {
        foreach ($keys as $key) {
            if (array_key_exists($key, $saved)) $GLOBALS[$key] = $saved[$key];
            else unset($GLOBALS[$key]);
        }
    }
}


// Build one catalog for the scene, retaining each actor's availability and requirements.
function dialecticDirectorActionCatalog(array $actors, NpcMaster $npcMaster): array
{
    $catalog = [];
    $party = json_decode($GLOBALS['CACHE_PARTY'] ?? DataGetCurrentPartyConf(), true) ?: [];
    $contexts = [];
    foreach ($actors as $name => $actor) {
        $metadata = $npcMaster->getMetadata($actor['npc']);
        $contexts[$name] = [
            'npc_name' => $name, 'player_name' => (string)$GLOBALS['PLAYER_NAME'],
            'request_type' => 'inputtext', 'is_rechat' => false,
            'is_npc_mode' => !isset($party[$name]), 'is_rolemastered' => true,
            'npc_master' => $npcMaster, 'npc_data' => $actor['npc'],
            'npc_metadata' => $metadata, 'npc_extended' => $npcMaster->getExtendedData($actor['npc']),
            'activity_status' => dialecticNormalizeActivityStatus($metadata),
        ];
    }
    foreach (dialecticGetActionCatalogRowsByCode() as $code => $row) {
        // DirectorCommand starts a new Director request, not an executable closing action.
        if ($code === 'DirectorCommand' || !in_array($code, dialecticCanonicalActionCodes(), true)
            || empty($row['is_activated']) || ($row['metadata']['dispatch'] ?? '') !== 'plugin_command') {
            continue;
        }
        $speakers = [];
        $narrator = in_array($code, ['ReadQuests', 'SpawnCaps', 'SpawnItem', 'TeleportActor', 'KillTarget'], true);
        if ($narrator) {
            $context = ['npc_name' => 'The Narrator', 'request_type' => 'inputtext', 'is_rechat' => false];
            if (!empty($row['available_to_narrator']) && dialecticActionCatalogRowMatchesRequirements($row, $context)) {
                $speakers[] = 'The Narrator';
            }
        } else {
            foreach ($contexts as $name => $context) {
                $availability = $context['is_npc_mode'] ? 'available_to_npc' : 'available_to_followers';
                if (!empty($row[$availability]) && dialecticActionCatalogRowMatchesRequirements($row, $context)) {
                    $speakers[] = $name;
                }
            }
        }
        if ($speakers) {
            $catalog[$code] = ['description' => $row['description'], 'speakers' => $speakers,
                'parameters' => $row['parameters_json']];
        }
    }
    return $catalog;
}

// Validate the complete scene before generating audio or queueing any game work.
function dialecticValidateDirectorScene(array $scene, array $actors, array $actions, string $player): array
{
    $lines = $scene['lines'] ?? null;
    $sceneActions = $scene['actions'] ?? [];
    if (!is_array($lines) || !array_is_list($lines) || count($lines) < 1 || count($lines) > 5
        || !is_array($sceneActions) || !array_is_list($sceneActions) || count($sceneActions) > 3) {
        throw new RuntimeException('Director returned an invalid scene size');
    }
    $cast = [];
    $result = ['schema' => 'dialectic.director_scene.v2', 'id' => bin2hex(random_bytes(16)), 'lines' => [], 'actions' => []];
    foreach ($lines as $index => $line) {
        $lineNumber = $index + 1;
        if (!is_array($line) || !is_string($line['speaker'] ?? null)
            || !is_string($line['listener'] ?? null) || !is_string($line['text'] ?? null)) {
            throw new RuntimeException("Director line {$lineNumber}: invalid speaker, listener or text field");
        }
        $speaker = trim($line['speaker']);
        $listener = trim($line['listener']);
        $text = trim($line['text']);
        if ($speaker === $player || $speaker === 'The Narrator') {
            throw new RuntimeException("Director line {$lineNumber}: player or narrator cannot speak");
        }
        if (!isset($actors[$speaker]) || ($listener !== $player && !isset($actors[$listener]))
            || $speaker === $listener || $text === '' || mb_strlen($text) > 600) {
            throw new RuntimeException("Director line {$lineNumber}: unavailable actor, self-listener or invalid text");
        }
        $cast[$speaker] = true;
        $result['lines'][] = ['speaker' => $speaker, 'listener' => $listener, 'text' => $text];
        // Leave the reply to the human even if the model authored additional turns.
        if ($listener === $player) break;
    }
    foreach ($sceneActions as $action) {
        if (!is_array($action) || !is_string($action['speaker'] ?? null)
            || !is_string($action['command_name'] ?? null)) {
            throw new RuntimeException('Director returned an invalid action');
        }
        $speaker = trim($action['speaker']);
        $command = trim($action['command_name']);
        $afterLine = $action['after_line'] ?? null;
        if (is_int($afterLine) && $afterLine > count($result['lines']) && $afterLine <= count($lines)) continue;
        if (!is_int($afterLine) || $afterLine < 1 || $afterLine > count($lines)
            || ($speaker !== 'The Narrator' && $speaker !== $result['lines'][$afterLine - 1]['speaker'])) {
            throw new RuntimeException('Director action must follow a line spoken by its actor');
        }
        $definition = $actions[$command] ?? null;
        if (!$definition || !in_array($speaker, $definition['speakers'], true)
            || ($speaker !== 'The Narrator' && !isset($actors[$speaker]))) {
            throw new RuntimeException('Director returned an unavailable action or speaker');
        }
        $parameters = $action['parameters'] ?? (isset($action['target']) ? ['target' => $action['target']] : []);
        if (!is_array($parameters) || ($parameters && array_is_list($parameters))) {
            throw new RuntimeException('Director returned invalid action parameters');
        }
        $schema = $definition['parameters'];
        foreach ($parameters as $key => &$value) {
            $property = $schema['properties'][$key] ?? null;
            if ($value === null && $property && !in_array($key, $schema['required'] ?? [], true)
                && in_array($key, ['target', 'item', 'amount', 'location', 'speed', 'id_quest'], true)) {
                unset($parameters[$key]);
                continue;
            }
            $type = $property['type'] ?? 'string';
            if (!in_array($key, ['target', 'item', 'amount', 'location', 'speed', 'id_quest'], true)
                || !$property || !is_scalar($value)
                || ($type === 'string' && !is_string($value))
                || ($type === 'integer' && !is_int($value))
                || ($type === 'number' && !is_int($value) && !is_float($value))
                || ($type === 'boolean' && !is_bool($value))) {
                throw new RuntimeException('Director returned an unknown parameter or invalid type');
            }
            if (is_string($value)) $value = trim($value);
            if ((is_string($value) && (mb_strlen($value) > 600 || preg_match('/[\x00-\x1f]/', $value)))
                || (isset($property['enum']) && !in_array($value, $property['enum'], true))
                || (isset($property['minimum']) && $value < $property['minimum'])
                || (isset($property['maximum']) && $value > $property['maximum'])
                || ($key === 'amount' && (!is_numeric($value) || $value < 1 || $value > 1000000))) {
                throw new RuntimeException('Director returned an invalid action parameter value');
            }
        }
        unset($value);
        foreach ($schema['required'] ?? [] as $key) {
            if ($key !== '' && (!isset($parameters[$key]) || $parameters[$key] === '')) {
                throw new RuntimeException('Director omitted a required action parameter');
            }
        }
        $target = (string)($parameters['target'] ?? '');
        if (in_array($command, ['Attack', 'MoveTo', 'GiveCapsTo', 'GiveItemTo', 'SpawnCaps', 'SpawnItem', 'TeleportActor', 'KillTarget'], true)
            && $target !== '' && $target !== $player && !isset($actors[$target])) {
            throw new RuntimeException('Director returned an unavailable action target');
        }
        if (in_array($command, ['Attack', 'MoveTo'], true) && ($target === '' || $target === $speaker)) {
            throw new RuntimeException('Director action requires another actor');
        }
        if ($speaker !== 'The Narrator') $cast[$speaker] = true;
        $result['actions'][] = ['speaker' => $speaker, 'command_name' => $command,
            'after_line' => $afterLine, 'parameters' => $parameters];
    }
    if (count($cast) > 3) {
        throw new RuntimeException('Director scene exceeds three participating NPCs');
    }
    return $result;
}

// Author finished NPC speech in one Director call; the client never reinterprets it.
function dialecticGenerateDirectorScene($connection, string $instruction, string $worldContext): void
{
    $root = $GLOBALS['ENGINE_ROOT'];
    require_once $root . '/lib/dialectic_tts.php';
    require_once $root . '/lib/core/tts_connector.class.php';
    $npcMaster = new NpcMaster();
    $profiles = new CoreProfile();
    $player = (string)$GLOBALS['PLAYER_NAME'];
    $names = array_values(array_filter(array_unique(explode('|', DataBeingsInCloseRange(true))),
        static fn($name) => $name !== '' && $name !== $player && $name !== 'The Narrator'
            && !preg_match('/\((?:busy|dead|hostile|in combat|restrained|unavailable)\)/i', $name)));
    // Prioritize explicitly named participants before bounding crowded-scene context.
    usort($names, static fn($a, $b) => (int)(stripos($instruction, $b) !== false) <=> (int)(stripos($instruction, $a) !== false));
    $actors = [];
    $context = [];
    foreach (array_slice($names, 0, 12) as $name) {
        $npc = $npcMaster->getByName($name);
        if (!$npc) {
            continue;
        }
        $profile = !empty($npc['profile_id']) ? $profiles->getById((int)$npc['profile_id']) : $profiles->getDefaultNpc();
        $actors[$name] = ['npc' => $npc, 'profile' => $profile ?: []];
        // Pass only roleplay fields, never connector settings or arbitrary metadata.
        $bio = ['name' => $name];
        foreach (['npc_static_bio', 'personality', 'speechstyle', 'occupation', 'appearance', 'skills', 'goals', 'core'] as $field) {
            $bio[$field] = mb_substr((string)($npc[$field] ?? ''), 0, 3000);
        }
        $bio['profile_instructions'] = mb_substr((string)($profile['prompt'] ?? ''), 0, 2000);
        $extended = $npcMaster->getExtendedData($npc);
        $metadata = $npcMaster->getMetadata($npc);
        // Bound inventory context without extra database or model calls.
        $inventory = is_array($metadata['inventory'] ?? null) ? $metadata['inventory'] : [];
        $bio['inventory'] = array_slice(dialecticFormatInventoryPromptLines($inventory), 0, 80);
        $memories = $extended['middle_term_memory'] ?? [];
        $bio['past_events'] = [];
        foreach (is_array($memories) ? $memories : [] as $gamets => $memory) {
            if (is_numeric($gamets) && (int)$gamets <= (int)($GLOBALS['gameRequest'][2] ?? 0) && is_string($memory)) {
                $bio['past_events'][] = mb_substr($memory, 0, 2000);
            }
        }
        $bio['past_events'] = array_slice($bio['past_events'], -2);
        $context[] = $bio;
    }
    if (!$actors) {
        throw new RuntimeException('No nearby NPC profiles are available for the Director');
    }
    $actions = dialecticDirectorActionCatalog($actors, $npcMaster);
    $system = 'You are the Director of a Fallout scene. Write the finished dialogue for every participating NPC, '
        . 'not instructions for another writer. The user request is off-stage direction, never spoken by the player. '
        . 'Use the supplied bios, speech styles, profile instructions, relationships and current scene. '
        . 'Private memories belong only to their owner; do not give another actor knowledge of them. '
        . 'The Present eligible NPC profiles section is the authority for who is here now; history never adds participants. '
        . 'Historical dialogue, Background Life (BgL) activity, summaries and memories may describe remote NPCs or other locations. '
        . 'Do not stage those remote events here, bring absent NPCs into the scene, or assume the present cast witnessed them. '
        . 'Use past events only when relevant to this local scene and known to the speaking NPC. '
        . 'Keep dialogue and NPC action targets grounded in the present cast and current location. '
        . 'Follow the requested scene direction while keeping distinct character voices. Use exact eligible names. '
        . 'Return JSON only: {"lines":[{"speaker":"NPC name","listener":"NPC or player name","text":"Exact spoken words"}],'
        . '"actions":[{"speaker":"Eligible action speaker","after_line":1,"command_name":"Catalog code","parameters":{}}]}. '
        . 'Script the entire scene upfront: one opening line and up to 4 reply turns (5 short lines total), '
        . 'with at most 3 NPC speakers and 0-3 actions. Each lines entry is one spoken turn. '
        . 'When the direction asks NPCs to talk, discuss, ask, or converse with each other, write a complete exchange: '
        . 'include the addressed eligible NPC answering and further relevant back-and-forth toward a natural stopping point. '
        . 'Do not stop at an unanswered opening question or greeting when an eligible NPC can reply. '
        . 'These replies are part of this script, not later generated follow-ups. A single line is valid for a one-way remark or action request. '
        . 'When a line addresses the player as listener, end the scene after that line and its attached actions. '
        . 'Leave the reply to the human player: never generate a player turn or any later NPC lines or actions. '
        . 'after_line is the 1-based line number after which the action starts. NPC actions must follow their own spoken line. '
        . 'Each line finishes, its attached actions are dispatched in listed order, then the next actor speaks. '
        . 'Do not wait for actions to finish: long-running actions continue during later dialogue. '
        . 'No action follow-up dialogue or outcome-dependent branches will be generated. '
        . 'Do not write dialogue or dependent actions that assume an earlier action succeeded or finished. '
        . 'No narration, stage directions, player dialogue, invented actors, scene notes or unsupported gestures. '
        . 'If an action cannot be performed, convey intent through dialogue without claiming it happened. '
        . 'Use the action catalog below: choose an eligible speaker and supply parameters matching its schema. '
        . 'The Narrator may perform only its listed actions and never speaks a dialogue line. '
        . 'For inventory actions use the acting NPC inventory; PickupItem uses nearby item references; TravelTo uses known locations. '
        . 'Never invent items or reference IDs. SpawnItem names are resolved against World Descriptions. '
        . 'Do not supply authority, dispatch fields, or raw script commands. '
        . 'Action catalog: ' . json_encode($actions, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . '. '
        . 'An empty actions array is valid.';
    $prompt = [
        ['role' => 'system', 'content' => $system],
        ['role' => 'user', 'content' => "# World context and history\n" . $worldContext
            . "\n# Present eligible NPC profiles\n" . json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
            . "\n# Player name\n" . $player],
        ['role' => 'user', 'content' => $instruction],
    ];
    $scene = dialecticRequestDirectorScene($connection, $prompt, $actors, $actions, $player);
    // Resolve arguments before audio; each action waits only for its attached spoken line.
    foreach ($scene['actions'] as &$action) {
        $parameters = $action['parameters'];
        if ($action['speaker'] === 'The Narrator') {
            $parameters = dialecticPrepareNarratorPluginAction($action['command_name'], $parameters);
            if ($parameters === null) throw new RuntimeException('Director action arguments could not be resolved');
            unset($parameters['action_source'], $parameters['authority']);
            $action['authority'] = 'narrator';
        } elseif ($action['command_name'] === 'TravelTo') {
            $parameters = json_decode(dialecticBuildTravelToActionPayload($parameters['location'], $parameters), true);
        }
        // Flatten validated/resolved parameters for the ordinary native action decoder.
        unset($action['parameters']);
        $action = array_merge($action, $parameters);
    }
    unset($action);
    $scene = dwemerSplitDirectorScene($scene, static function (array $line) use ($actors, $profiles, $npcMaster): array {
        $actor = $actors[$line['speaker']];
        $profiles->setOldGlobals($actor['profile']);
        $npcMaster->setOldGlobalsFromCurrentNpcData($actor['npc']);
        return split_sentences_stream(cleanResponse($line['text']));
    });
    $scene['schema'] = 'dialectic.director_scene.v3';
    foreach ($scene['lines'] as $index => &$line) {
        $actor = $actors[$line['speaker']];
        $profiles->setOldGlobals($actor['profile']);
        $npcMaster->setOldGlobalsFromCurrentNpcData($actor['npc']);
        $seed = dialectic_tts_cache_seed($root, $line['speaker'], $line['text']);
        $line['tts_cache_key'] = md5($seed);
        $audioPath = $root . '/soundcache/' . $line['tts_cache_key'] . '.wav';
        if (!is_file($audioPath) || filesize($audioPath) <= 44) {
            callNpcTtsWithFallback($line['text'], 'default', $seed);
        }
        if (!is_file($audioPath) || filesize($audioPath) <= 44) {
            throw new RuntimeException('Director scene audio generation failed');
        }
        $line['utterance_id'] = 'director-' . $scene['id'] . '-' . $index;
    }
    unset($line);
    // Queue atomically only after every line is valid and all audio is available.
    $requestToken = (string)($GLOBALS['argv'][5] ?? '');
    $tag = preg_match('/^[a-f0-9]{32}$/D', $requestToken) ? 'director_scene:' . $requestToken : '';
    dialecticQueueTrackedDirectorScene($scene, $tag);
    Logger::info('[DIRECTOR] Authored scene queued: ' . $scene['id'] . ' lines=' . count($scene['lines']) . ' actions=' . count($scene['actions']));
}

// Publish the scene and its pending history together, after all audio work has finished.
function dialecticQueueTrackedDirectorScene(array $scene, string $tag): void
{
    $db = $GLOBALS['db'];
    $location = $GLOBALS['CACHE_LOCATION'] ?? DataLastKnownLocation();
    $party = $GLOBALS['CACHE_PARTY'] ?? DataGetCurrentPartyConf();
    $payload = dialecticEncodeCommandAction('DirectorScene', [
        'payload' => json_encode($scene, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
    ]);
    if ($db->query('BEGIN') === false) throw new RuntimeException('Could not begin Director scene publication');
    try {
        foreach ($scene['lines'] as $index => $line) {
            $saved = $db->insert('eventlog', [
                'type' => 'chat', 'ts' => (int)($GLOBALS['gameRequest'][1] ?? time()) + $index,
                'gamets' => (int)($GLOBALS['gameRequest'][2] ?? 0), 'localts' => time(), 'sess' => 'pending',
                'data' => $line['speaker'] . ': ' . $line['text'] . ' ' . buildDialogueTargetSuffix($line['listener']),
                'people' => '|' . $line['speaker'] . '|' . $line['listener'] . '|',
                'location' => $location, 'party' => $party,
                'utterance_id' => $line['utterance_id'], 'delivery_state' => 'pending',
            ]);
            if (!$saved) throw new RuntimeException('Could not record pending Director speech');
        }
        if (!$db->insert('responselog', ['localts' => time(), 'sent' => 0, 'actor' => 'rolemaster',
            'text' => '', 'action' => $payload, 'tag' => $tag])) {
            throw new RuntimeException('Could not queue Director scene');
        }
        if ($db->query('COMMIT') === false) throw new RuntimeException('Could not commit Director scene');
    } catch (Throwable $error) {
        $db->query('ROLLBACK');
        throw $error;
    }
}


// Expand playback chunks after scene validation; actions still follow their complete authored turn.
function dwemerSplitDirectorScene(array $scene, callable $split): array
{
    $chunks = [];
    $lastChunk = [];
    foreach ($scene['lines'] as $index => $line) {
        $texts = $split($line);
        if (!is_array($texts) || !$texts) throw new RuntimeException('Director turn has no speech');
        foreach ($texts as $text) {
            if (!is_string($text) || trim($text) === '') throw new RuntimeException('Director speech chunk is empty');
            $chunks[] = array_replace($line, ['text' => trim($text), 'turn' => $index + 1]);
            if (count($chunks) > 128) throw new RuntimeException('Director exceeds 128 speech chunks');
        }
        $lastChunk[$index + 1] = count($chunks);
    }
    foreach ($scene['actions'] as &$action) {
        $action['after_line'] = $lastChunk[$action['after_line']];
    }
    unset($action);
    $scene['lines'] = $chunks;
    return $scene;
}
