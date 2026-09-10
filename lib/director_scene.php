<?php

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
    $closingActions = $scene['actions'] ?? [];
    if (!is_array($lines) || !array_is_list($lines) || count($lines) < 1 || count($lines) > 6
        || !is_array($closingActions) || !array_is_list($closingActions) || count($closingActions) > 3) {
        throw new RuntimeException('Director returned an invalid scene size');
    }
    $cast = [];
    $result = ['schema' => 'dialectic.director_scene.v1', 'id' => bin2hex(random_bytes(16)), 'lines' => [], 'actions' => []];
    foreach ($lines as $line) {
        if (!is_array($line) || !is_string($line['speaker'] ?? null)
            || !is_string($line['listener'] ?? null) || !is_string($line['text'] ?? null)) {
            throw new RuntimeException('Director returned an invalid dialogue line');
        }
        $speaker = trim($line['speaker']);
        $listener = trim($line['listener']);
        $text = trim($line['text']);
        if (!isset($actors[$speaker]) || ($listener !== $player && !isset($actors[$listener]))
            || $speaker === $listener || $text === '' || mb_strlen($text) > 600) {
            throw new RuntimeException('Director returned an unavailable actor or invalid dialogue');
        }
        $cast[$speaker] = true;
        $result['lines'][] = ['speaker' => $speaker, 'listener' => $listener, 'text' => $text];
    }
    foreach ($closingActions as $action) {
        if (!is_array($action) || !is_string($action['speaker'] ?? null)
            || !is_string($action['command_name'] ?? null)) {
            throw new RuntimeException('Director returned an invalid action');
        }
        $speaker = trim($action['speaker']);
        $command = trim($action['command_name']);
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
        $result['actions'][] = ['speaker' => $speaker, 'command_name' => $command, 'parameters' => $parameters];
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
        . 'Follow the requested outcome while keeping distinct character voices. Use exact eligible names. '
        . 'Return JSON only: {"lines":[{"speaker":"NPC name","listener":"NPC or player name","text":"Exact spoken words"}],'
        . '"actions":[{"speaker":"Eligible action speaker","command_name":"Catalog code","parameters":{}}]}. '
        . 'Use 1-6 short lines, at most 3 NPC speakers, and 0-3 closing actions. '
        . 'Lines play in order, then closing actions are attempted. Do not write dialogue that assumes an action succeeded. '
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
        ['role' => 'user', 'content' => "# Current scene and relationships\n" . $worldContext
            . "\n# Eligible NPC profiles\n" . json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
            . "\n# Player name\n" . $player],
        ['role' => 'user', 'content' => $instruction],
    ];
    $GLOBALS['CONNECTOR'][$GLOBALS['CURRENT_CONNECTOR']]['json_schema'] = false;
    $connection->open($prompt, ['response_format' => ['type' => 'json_object'], 'MAX_TOKENS' => 4000]);
    do {
        $connection->process();
    } while (!$connection->isDone());
    $raw = $connection->close('director_scene');
    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        throw new RuntimeException('Director did not return a JSON scene');
    }
    $scene = dialecticValidateDirectorScene($decoded, $actors, $actions, $player);
    // Resolve structured arguments before audio; native execution remains deferred until speech ends.
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
        $line['utterance_id'] = $scene['id'] . '-' . $index;
    }
    unset($line);
    // Queue atomically only after every line is valid and all audio is available.
    $requestToken = (string)($GLOBALS['argv'][5] ?? '');
    $tag = preg_match('/^[a-f0-9]{32}$/D', $requestToken) ? 'director_scene:' . $requestToken : '';
    dialecticQueueCommandResponse('rolemaster', 'DirectorScene', ['payload' => json_encode($scene, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)], '', $tag);
    Logger::info('[DIRECTOR] Authored scene queued: ' . $scene['id'] . ' lines=' . count($scene['lines']) . ' actions=' . count($scene['actions']));
}
