<?php

// Resolve one quest speaker before the normal profile/connector loading path.
$GLOBALS['QUEST_COMMENT_SELECTED'] = false;
$questPayload = json_decode((string)($gameRequest[3] ?? ''), true);
$questText = is_array($questPayload) ? ($questPayload['text'] ?? '') : ($gameRequest[3] ?? '');
$questText = is_string($questText) ? trim($questText) : '';
// Normalize both current JSON and older quest events into the dedicated prompt path.
$gameRequest[0] = 'quest';
$gameRequest[3] = $questText;
if ($questText === '' || stripos($questText, 'Storyline Tracker') !== false
    || str_contains($questText, 'quest ""')
    || ((float)$gameRequest[2] >= 13333332 && (float)$gameRequest[2] <= 13333334)
    || !dialecticInteractionAllowed()) {
    Logger::debug('[QUEST_COMMENT] Ignored excluded or passive quest event');
    return;
}

$narrator = new Narrator();
$cooldownSeconds = max(1, min(60, $narrator->getInt('quest_comment_cooldown', 3))) * 60;
$lastComment = $db->fetchOne("SELECT value FROM conf_opts WHERE id='QUEST_COMMENT_LAST_TIMESTAMP'");
if ($lastComment && time() - (int)$lastComment['value'] < $cooldownSeconds) {
    Logger::debug('[QUEST_COMMENT] Shared cooldown active');
    return;
}

// New clients send eligible nearby actors; older RPG clients send one explicit NPC.
$speakers = is_array($questPayload) && ($questPayload['schema'] ?? '') === 'dialectic.quest_comment.v1'
    && is_array($questPayload['speakers'] ?? null) ? array_slice($questPayload['speakers'], 0, 32) : [];
if (!$speakers && is_array($questPayload) && ($questPayload['schema'] ?? '') === 'dialectic.rpg_event.v1') {
    $speakers = [['name' => $questPayload['npc'] ?? '', 'refid' => $questPayload['npc_id'] ?? '']];
}
$actors = [];
foreach ($speakers as $speaker) {
    if (!is_array($speaker) || !is_string($speaker['name'] ?? null)) continue;
    $name = trim($speaker['name']);
    if ($name === '' || strcasecmp($name, Narrator::CANONICAL_NAME) === 0 || $name === ($GLOBALS['PLAYER_NAME'] ?? '')) continue;
    $refid = is_string($speaker['refid'] ?? null) ? $speaker['refid'] : '';
    $actors[$name] = preg_match('/^(?:0x)?[0-9a-fA-F]{8}$/', $refid) ? $refid : '';
}
$names = array_keys($actors);
$candidates = [];
if ($names) {
    $quotedNames = array_map(static fn($name) => "'" . $db->escape($name) . "'", $names);
    $rows = $db->fetchAll('SELECT n.npc_name, n.metadata, n.extended_data, p.metadata AS profile_metadata '
        . 'FROM core_npc_master n JOIN core_profiles p ON p.id=n.profile_id '
        . 'WHERE n.npc_name IN (' . implode(',', $quotedNames) . ')');
    foreach ($rows as $npc) {
        $settings = json_decode($npc['profile_metadata'] ?? '{}', true);
        $settings = is_array($settings) ? $settings : [];
        // Match normal loading: NPC metadata, then extended overrides, win over the profile.
        foreach (['metadata', 'extended_data'] as $field) {
            $overrides = json_decode($npc[$field] ?? '{}', true);
            foreach (is_array($overrides) ? $overrides : [] as $key => $value) {
                if (!empty($value) || is_numeric($value) || is_bool($value)) {
                    $settings[$key] = $value;
                }
            }
        }
        if (filter_var($settings['QUEST_COMMENT'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
            $candidates[] = ['name' => $npc['npc_name'], 'chance' => max(0, min(100, (int)($settings['QUEST_COMMENT_CHANCE'] ?? 10)))];
        }
    }
}
// Roll once for one enabled NPC, so a larger party does not multiply the chance.
if ($candidates) {
    $candidate = $candidates[random_int(0, count($candidates) - 1)];
    if (random_int(1, 100) <= $candidate['chance']) {
        dialecticRuntimeSetActiveProfile(md5($candidate['name']));
        $GLOBALS['DIALECTIC_RESPONSE_SPEAKER_FORMID'] = $actors[$candidate['name']];
        $GLOBALS['QUEST_COMMENT_SPEAKER'] = $candidate['name'];
        $GLOBALS['QUEST_COMMENT_SELECTED'] = true;
        Logger::info('[QUEST_COMMENT] Selected NPC ' . $candidate['name']);
        return;
    }
}

if ($narrator->getBool('enabled', true) && $narrator->getBool('quest_comment_enabled', false)
    && random_int(1, 100) <= max(0, min(100, $narrator->getInt('quest_comment_chance', 10)))) {
    dialecticRuntimeSetActiveProfile(md5(Narrator::CANONICAL_NAME));
    unset($GLOBALS['DIALECTIC_RESPONSE_SPEAKER_FORMID']);
    $gameRequest[0] = 'narrator_quest_comment';
    $GLOBALS['QUEST_COMMENT_SELECTED'] = true;
    Logger::info('[QUEST_COMMENT] Selected Narrator fallback');
} else {
    Logger::debug('[QUEST_COMMENT] No speaker selected');
}
