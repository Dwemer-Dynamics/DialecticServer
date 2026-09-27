<?php

// Generate a complete replacement before touching the selected NPC's four profile fields.
function dialecticRunHypnosis(array $payload): void
{
    $db = $GLOBALS['db'];
    foreach (['npc', 'npc_id', 'text'] as $key) {
        if (!isset($payload[$key]) || !is_string($payload[$key])) {
            dialecticModeNotify('Hypnosis needs one NPC target and an instruction.');
            return;
        }
    }
    $name = trim((string)($payload['npc'] ?? ''));
    $ref = preg_replace('/^0x/i', '', trim((string)($payload['npc_id'] ?? '')));
    $instruction = trim((string)($payload['text'] ?? ''));
    if ($instruction === '' || strlen($instruction) > 8192 || $name === ''
        || !preg_match('/^[0-9a-f]{1,8}$/i', $ref) || hexdec($ref) === 0 || hexdec($ref) === 0x14
        || in_array(strtolower($name), ['the narrator', 'everyone', 'all'], true)
        || strcasecmp($name, (string)($GLOBALS['PLAYER_NAME'] ?? 'Player')) === 0) {
        dialecticModeNotify('Hypnosis needs one NPC target and an instruction.');
        return;
    }

    require_once __DIR__ . '/../lib/core/npc_master.class.php';
    require_once __DIR__ . '/../lib/core/llm_connector.class.php';
    require_once __DIR__ . '/../lib/dynamic_update_util.php';
    $npc = (new NpcMaster())->getByName($name);
    $storedRef = preg_replace('/^0x/i', '', trim((string)($npc['refid'] ?? '')));
    if (!$npc || !preg_match('/^[0-9a-f]{1,8}$/i', $storedRef) || hexdec($storedRef) !== hexdec($ref)) {
        dialecticModeNotify('Hypnosis target changed or has no saved profile. Select the NPC again.');
        return;
    }
    if ((int)($GLOBALS['CORE_CONNECTOR_PROFILES'] ?? 0) <= 0
        || (function_exists('chimIsGlobalLlmConnectorEnabled') && !chimIsGlobalLlmConnectorEnabled('CORE_CONNECTOR_PROFILES'))) {
        dialecticModeNotify('Enable Profile Tasks before using Hypnosis.');
        return;
    }

    dialecticModeNotify('Hypnosis started for ' . $name . '.');
    $updates = [];
    try {
        foreach (['personality', 'goals', 'speechstyle', 'occupation'] as $field) {
            $prompt = "Rewrite this character's profile using Fallout lore and this mandatory Hypnosis instruction: " . $instruction;
            $prompt .= "\nThis changes the written profile only, not quests, factions, inventory or game actions.";
            if (isset($updates['personality'])) $prompt .= "\nNew personality: " . $updates['personality'];
            $value = updateDynamicProfileField($name, $field, $prompt);
            if (!is_string($value) || trim($value) === '') throw new RuntimeException('Incomplete hypnosis profile');
            $updates[$field] = trim($value);
        }

        // Compare the original identity and fields so concurrent edits or registrations are preserved.
        $where = 'id=' . (int)$npc['id'] . " AND npc_name='" . $db->escape($name) . "' AND refid='" . $db->escape($npc['refid']) . "'";
        $sets = [];
        foreach ($updates as $field => $value) {
            $sets[] = $field . "='" . $db->escape($value) . "'";
            $where .= $npc[$field] === null ? " AND $field IS NULL" : " AND $field='" . $db->escape($npc[$field]) . "'";
        }
        $saved = $db->fetchOne('UPDATE core_npc_master SET ' . implode(',', $sets) . ' WHERE ' . $where . ' RETURNING id');
        dialecticModeNotify($saved ? 'Updated basic profile for ' . $name . '.' : 'Hypnosis could not save. The profile changed while generating.');
    } catch (Throwable $error) {
        Logger::warn('[HYPNOSIS] Profile generation or save failed: ' . get_class($error));
        dialecticModeNotify('Hypnosis could not update the profile. Existing fields were kept.');
    }
}
