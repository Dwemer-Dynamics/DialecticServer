<?php

require_once __DIR__ . DIRECTORY_SEPARATOR . "dialectic_runtime.php";

function dialectic_prompt_manager_get(object $db, string $promptKey, string $fallback, array $replacements = []): string
{
    $prompt = $fallback;

    if (method_exists($db, 'fetchOne')) {
        try {
            $escapedKey = dialectic_db_escape($db, $promptKey);
            $row = $db->fetchOne("
                SELECT COALESCE(NULLIF(custom_prompt, ''), default_prompt) AS prompt
                FROM public.prompts
                WHERE prompt_key='{$escapedKey}'
                LIMIT 1
            ");
            if (is_array($row) && trim(strval($row['prompt'] ?? '')) !== '') {
                $prompt = strval($row['prompt']);
            }
        } catch (Throwable $e) {
            error_log("[Dialectic PromptManager] prompt lookup failed for {$promptKey}: " . $e->getMessage());
        }
    }

    return strtr($prompt, $replacements);
}

function dialectic_prompt_manager_xml_section(string $name, string $content): string
{
    $content = trim($content);
    if ($content === '') {
        return '';
    }

    return "\n\n<{$name}>\n{$content}\n</{$name}>";
}

function dialectic_prompt_manager_recent_context(object $db, string $speaker, string $playerName, int $limit = 24): array
{
    if (!method_exists($db, 'fetchAll')) {
        return [];
    }

    $speakerEscaped = dialectic_db_escape($db, $speaker);
    $playerEscaped = dialectic_db_escape($db, $playerName);
    $rows = $db->fetchAll("
        SELECT type, data, people, location, gamets, party
        FROM public.eventlog
        WHERE sess='dialectic'
          AND (
              people ILIKE '%{$speakerEscaped}%'
              OR people ILIKE '%{$playerEscaped}%'
          )
          AND type NOT IN ('nearby_actors', 'nearby_items', 'points_of_interest', 'active_quests', 'world_context')
        ORDER BY rowid DESC
        LIMIT " . intval($limit) . "
    ");

    return array_reverse(is_array($rows) ? $rows : []);
}

function dialectic_prompt_manager_format_history(array $rows, string $speaker, string $playerName): string
{
    $lines = [];

    foreach ($rows as $row) {
        $type = strtolower(trim(strval($row['type'] ?? '')));
        $data = trim(strval($row['data'] ?? ''));
        if ($data === '') {
            continue;
        }

        if (in_array($type, ['inputtext', 'inputtext_s', 'user_input'], true)) {
            $lines[] = $playerName . ': ' . dialectic_extract_player_text($data);
        } elseif ($type === 'backgroundchat') {
            $lines[] = $data;
        } elseif (in_array($type, ['chat', 'prechat', 'response'], true)) {
            $lines[] = $speaker . ': ' . $data;
        }
    }

    return implode("\n", array_slice($lines, -18));
}

function dialectic_prompt_manager_decode_json($value): array
{
    if (is_array($value)) {
        return $value;
    }

    $decoded = json_decode(strval($value ?? ''), true);
    return is_array($decoded) ? $decoded : [];
}

function dialectic_prompt_manager_payload(array $row): array
{
    return dialectic_prompt_manager_decode_json($row['party'] ?? '');
}

function dialectic_prompt_manager_payload_location(array $payload): string
{
    $location = trim(strval($payload['location'] ?? ''));
    if ($location !== '' && !in_array(strtolower($location), ['unknown', 'unknown location', 'none', 'null'], true)) {
        return $location;
    }

    $worldspace = trim(strval($payload['worldspace'] ?? ''));
    return in_array(strtolower($worldspace), ['unknown', 'unknown location', 'none', 'null'], true) ? '' : $worldspace;
}

function dialectic_prompt_manager_payload_actor_names(array $payload): array
{
    $names = [];
    foreach (($payload['actors'] ?? []) as $actor) {
        if (!is_array($actor)) {
            continue;
        }

        $name = trim(strval($actor['name'] ?? ''));
        if ($name !== '' && $name !== '<no name>' && !isset($names[strtolower($name)])) {
            $names[strtolower($name)] = $name;
        }
    }

    $player = trim(strval($payload['player'] ?? ''));
    if ($player !== '' && !isset($names[strtolower($player)])) {
        $names[strtolower($player)] = $player;
    }

    return array_values($names);
}

function dialectic_prompt_manager_payload_item_names(array $payload): array
{
    $items = [];
    foreach (($payload['items'] ?? []) as $item) {
        if (!is_array($item)) {
            continue;
        }

        $name = trim(strval($item['name'] ?? ''));
        if ($name === '' || $name === '<no name>') {
            continue;
        }

        $refId = trim(strval($item['refid'] ?? ''));
        $baseId = trim(strval($item['baseid'] ?? ''));
        $label = $name;
        if ($refId !== '' && $baseId !== '') {
            $label = "{$refId}:{$baseId}:{$name}";
        }

        if (!isset($items[strtolower($label)])) {
            $items[strtolower($label)] = $label;
        }
    }

    return array_values($items);
}

function dialectic_prompt_manager_format_npc_profile(array $npc): string
{
    $lines = [];
    $labels = [
        'core' => 'Core',
        'npc_static_bio' => 'Background',
        'personality' => 'Personality',
        'speechstyle' => 'Speech style',
        'occupation' => 'Occupation',
        'appearance' => 'Appearance',
        'skills' => 'Skills',
        'goals' => 'Goals',
        'gender' => 'Gender',
        'race' => 'Race',
        'refid' => 'Reference ID',
    ];

    foreach ($labels as $field => $label) {
        $value = trim(strval($npc[$field] ?? ''));
        if ($value !== '') {
            $lines[] = "{$label}: {$value}";
        }
    }

    return implode("\n", $lines);
}

function dialectic_prompt_manager_format_extended_data(array $npc): array
{
    $extended = dialectic_prompt_manager_decode_json($npc['extended_data'] ?? '{}');
    $sections = [
        'actor_data' => [],
        'relationships' => [],
        'memories' => [],
    ];

    $actorKeys = [
        'baseid', 'level', 'health', 'health_max', 'action_points', 'action_points_max', 'scale', 'xp', 'karma',
        'strength', 'perception', 'endurance', 'charisma', 'intelligence', 'agility', 'luck',
        'barter', 'energy_weapons', 'explosives', 'guns', 'lockpick', 'medicine', 'melee_weapons',
        'repair', 'science', 'sneak', 'speech', 'survival', 'unarmed',
        'head', 'hair', 'upper_body', 'left_hand', 'right_hand', 'upper_body_addon', 'lower_body_addon',
    ];

    foreach ($actorKeys as $key) {
        if (isset($extended[$key]) && trim(strval($extended[$key])) !== '') {
            $sections['actor_data'][] = $key . ': ' . (is_scalar($extended[$key]) ? strval($extended[$key]) : json_encode($extended[$key], JSON_UNESCAPED_SLASHES));
        }
    }

    if (!empty($extended['factions'])) {
        $sections['actor_data'][] = 'factions: ' . json_encode($extended['factions'], JSON_UNESCAPED_SLASHES);
    }

    foreach (['relationships', 'dynamic_relationships'] as $key) {
        if (!empty($extended[$key])) {
            $sections['relationships'][] = is_scalar($extended[$key])
                ? strval($extended[$key])
                : json_encode($extended[$key], JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
        }
    }

    if (!empty($extended['middle_term_memory'])) {
        if (is_array($extended['middle_term_memory'])) {
            $middleTermMemory = $extended['middle_term_memory'];
            $latest = end($middleTermMemory);
            if (is_scalar($latest)) {
                $sections['memories'][] = strval($latest);
            }
        } elseif (is_scalar($extended['middle_term_memory'])) {
            $sections['memories'][] = strval($extended['middle_term_memory']);
        }
    }

    return [
        'actor_data' => implode("\n", $sections['actor_data']),
        'relationships' => implode("\n", $sections['relationships']),
        'memories' => implode("\n", $sections['memories']),
    ];
}

function dialectic_prompt_manager_format_scene(object $db, array $context, array $historyRows): string
{
    $lines = [];
    $world = trim(strval($context['world'] ?? 'Mojave Wasteland'));
    if ($world !== '') {
        $lines[] = 'World: ' . $world;
    }

    return implode("\n", $lines);
}

function dialectic_prompt_manager_build_messages(object $db, array $event, string $speaker, string $playerName, array $context, array $npc): array
{
    $worldName = trim(strval($context['world'] ?? 'Mojave Wasteland'));
    $replacements = [
        '{DIALECTIC_NAME}' => $speaker,
        '{PLAYER_NAME}' => $playerName,
        '{WORLD_NAME}' => $worldName,
        '#DIALECTIC_NAME#' => $speaker,
        '#PLAYER_NAME#' => $playerName,
    ];

    $systemPrompt = dialectic_prompt_manager_get($db, 'dialectic_system_prompt', '', $replacements);
    $worldPrompt = dialectic_prompt_manager_get($db, 'dialectic_world_prompt', '', $replacements);
    $sceneRules = dialectic_prompt_manager_get($db, 'dialectic_scene_prompt', '', $replacements);
    $memoryRules = dialectic_prompt_manager_get($db, 'dialectic_memory_prompt', '', $replacements);
    $responseRules = dialectic_prompt_manager_get($db, 'dialectic_response_rules', '', $replacements);

    $historyRows = dialectic_prompt_manager_recent_context($db, $speaker, $playerName);
    $history = dialectic_prompt_manager_format_history($historyRows, $speaker, $playerName);
    $extendedSections = dialectic_prompt_manager_format_extended_data($npc);

    $system = '';
    $system .= dialectic_prompt_manager_xml_section('roleplay_instructions', $systemPrompt);
    $system .= dialectic_prompt_manager_xml_section('world_state', $worldPrompt);
    $system .= dialectic_prompt_manager_xml_section('scene_rules', $sceneRules);
    $system .= dialectic_prompt_manager_xml_section('memory_rules', $memoryRules);
    $system .= dialectic_prompt_manager_xml_section('character_profile', dialectic_prompt_manager_format_npc_profile($npc));
    $system .= dialectic_prompt_manager_xml_section('actor_data', $extendedSections['actor_data']);
    $system .= dialectic_prompt_manager_xml_section('relationships', $extendedSections['relationships']);
    $system .= dialectic_prompt_manager_xml_section('memories', $extendedSections['memories']);
    $system .= dialectic_prompt_manager_xml_section('current_scene', dialectic_prompt_manager_format_scene($db, $context, $historyRows));
    $system .= dialectic_prompt_manager_xml_section('recent_history', $history);
    $system .= dialectic_prompt_manager_xml_section('output_instructions', $responseRules);

    $playerText = dialectic_extract_player_text(strval($event['payload'] ?? ''));
    if ($playerText === '') {
        $playerText = 'Hello.';
    }

    return [
        ['role' => 'system', 'content' => trim($system)],
        ['role' => 'user', 'content' => $playerText],
    ];
}

?>
