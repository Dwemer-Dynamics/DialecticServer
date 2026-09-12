<?php
// Installation-wide interaction state is deliberately outside Playthrough Saves.
function dialecticInteractionFile()
{
    $dir = dirname(__DIR__) . '/conf/dialectic_interaction';
    if (!is_dir($dir) && !@mkdir($dir, 02775, true) && !is_dir($dir)) {
        throw new RuntimeException('Cannot open DIALECTIC interaction state.');
    }
    @chmod($dir, 02775);
    $handle = @fopen($dir . '/state.json', 'c+e');
    if (!$handle) throw new RuntimeException('Cannot open DIALECTIC interaction state.');
    @chmod($dir . '/state.json', 0664);
    return $handle;
}

function dialecticInteractionRead($handle): array
{
    rewind($handle);
    $raw = stream_get_contents($handle);
    if ($raw === '') return ['enabled' => true, 'generation' => 0];
    $state = json_decode($raw, true);
    if (!is_array($state) || !is_bool($state['enabled'] ?? null) || !is_int($state['generation'] ?? null)) {
        throw new RuntimeException('Cannot read DIALECTIC interaction state.');
    }
    return $state;
}

function dialecticInteractionState(): array
{
    $handle = dialecticInteractionFile();
    try {
        if (!flock($handle, LOCK_SH)) throw new RuntimeException('Cannot read DIALECTIC interaction state.');
        return dialecticInteractionRead($handle);
    } finally { fclose($handle); }
}

// Capture once per request, so Off/On cannot revive an old generator.
function dialecticInteractionBegin(): void
{
    if (isset($GLOBALS['dialectic_interaction_generation'])) return;
    try {
        $state = dialecticInteractionState();
        $inherited = getenv('DIALECTIC_INTERACTION_GENERATION');
        $GLOBALS['dialectic_interaction_generation'] = isset($_SERVER['HTTP_X_DIALECTIC_GENERATION'])
            ? (int)$_SERVER['HTTP_X_DIALECTIC_GENERATION']
            : ($inherited !== false ? (int)$inherited : $state['generation']);
    } catch (Throwable $e) {
        $GLOBALS['dialectic_interaction_generation'] = -1;
        error_log('DIALECTIC interaction state unavailable; event recording remains active.');
    }
}

function dialecticInteractionAllowed(): bool
{
    dialecticInteractionBegin();
    try {
        $state = dialecticInteractionState();
        return ($_SERVER['HTTP_X_DIALECTIC_PASSIVE'] ?? '') !== '1' && $state['enabled']
            && $state['generation'] === $GLOBALS['dialectic_interaction_generation'];
    } catch (Throwable $e) { return false; }
}

// These are requests for invented speech/actions, not observations of Fallout.
function dialecticInteractionIsTrigger(string $type): bool
{
    $type = strtolower($type);
    return str_starts_with($type, 'diary') || str_starts_with($type, 'player_menu_tts_')
        || in_array($type, ['inputtext', 'inputtext_s', 'ginputtext', 'ginputtext_s',
            'narrator_inputtext', 'bored', 'rechat', 'continue', 'continue_group',
            'instruction', 'suggestion', 'narration', 'narrator_welcome', 'combatbark',
            'just_say', 'cheatmode', 'vision', 'npc_tts_play', 'force_current_task', 'recover_last_task'], true);
}

function dialecticInteractionRequire(): void
{
    if (!dialecticInteractionAllowed()) exit;
    $GLOBALS['dialectic_interaction_generated'] = true;
}

dialecticInteractionBegin();
