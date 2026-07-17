<?php

function dialecticCapturedDialoguePlayerName(array $payload): string
{
    foreach ([$payload['player_name'] ?? null, $payload['player'] ?? null, $GLOBALS['PLAYER_NAME'] ?? null] as $candidate) {
        $name = trim(strval($candidate ?? ''));
        if ($name !== '' && strcasecmp($name, 'Player') !== 0 && strcasecmp($name, 'Courier') !== 0) {
            return $name;
        }
    }
    return trim(strval($payload['player_name'] ?? $GLOBALS['PLAYER_NAME'] ?? 'Player'));
}

function dialecticCapturedDialogueNormalizeParticipant(string $name, string $playerName): string
{
    $name = trim($name);
    if ($name === '') {
        return '';
    }
    if ($playerName !== '' && (strcasecmp($name, 'Player') === 0 || strcasecmp($name, 'Courier') === 0)) {
        return $playerName;
    }
    return $name;
}

function dialecticCapturedDialoguePeople(array $payload): string
{
    $playerName = dialecticCapturedDialoguePlayerName($payload);
    $names = [];
    foreach (['speaker', 'listener'] as $key) {
        $name = dialecticCapturedDialogueNormalizeParticipant(strval($payload[$key] ?? ''), $playerName);
        if ($name === '' || strcasecmp($name, 'Unknown') === 0) {
            continue;
        }
        $lookup = strtolower($name);
        if (!isset($names[$lookup])) {
            $names[$lookup] = $name;
        }
    }

    if (empty($names)) {
        return '';
    }

    return '|' . implode('|', array_values($names)) . '|';
}

function dialecticCapturedDialogueLooksLikeContaminatedLine(string $text, array $payload): bool
{
    $line = trim($text);
    if ($line === '') {
        return true;
    }

    $lower = strtolower($line);
    if (str_starts_with($line, '{') || str_starts_with($lower, 'data: ') || str_contains($lower, '"schema":"dialectic.response')) {
        return true;
    }

    $metaTokens = [
        'ssubtitle',
        'sspeakername',
        'stargetname',
        'startcombat',
        'combattonormal',
        'megatonhelloresponse',
        'megcromwell',
    ];
    foreach ($metaTokens as $token) {
        if (str_contains($lower, strtolower($token))) {
            return true;
        }
    }

    $animationTokens = [
        'hit',
        'attack',
        'alertidle',
        'alerttocombat',
        'observecombat',
        'assault',
        'flee',
        'death',
        'deathresponse',
        'followersstealthing',
    ];
    if (in_array($lower, $animationTokens, true)) {
        return true;
    }

    $speaker = trim(strval($payload['speaker'] ?? ''));
    if ($speaker !== '' && preg_match('/^' . preg_quote($speaker, '/') . '\s*:/i', $line) === 1) {
        return true;
    }

    return false;
}

function dialecticHandleCapturedDialogueEvent(array $gameRequest): void
{
    $payload = json_decode(strval($gameRequest[3] ?? ''), true);
    if (!is_array($payload)) {
        Logger::warn('[captured_dialogue] Invalid payload');
        return;
    }

    $text = trim(strval($payload['text'] ?? $payload['speech'] ?? ''));
    if ($text === '') {
        Logger::warn('[captured_dialogue] Empty text');
        return;
    }

    if (dialecticCapturedDialogueLooksLikeContaminatedLine($text, $payload)) {
        Logger::debug('[captured_dialogue] Skipped contaminated/background bridge line: ' . substr($text, 0, 160));
        return;
    }

    $source = strtolower(trim(strval($payload['source'] ?? 'radiant')));
    if ($source === '') {
        $source = 'radiant';
    }

    $playerName = dialecticCapturedDialoguePlayerName($payload);
    $speaker = dialecticCapturedDialogueNormalizeParticipant(strval($payload['speaker'] ?? 'Unknown'), $playerName);
    if ($speaker === '') {
        $speaker = 'Unknown';
    }

    $eventType = 'backgroundchat';
    $location = trim(str_replace(['\r\n', '\n', '\r'], ' ', strval($payload['location'] ?? '')));
    if ($location === '') {
        $location = 'Unknown';
    }

    $sourceLabel = 'background chat';

    $chatLine = "(Context location: {$location} {$sourceLabel}) {$speaker}: {$text}";
    $metadata = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if (!is_string($metadata)) {
        $metadata = '';
    }

    $extraColumns = [
        'delivery_state' => 'spoken',
        'party' => $metadata,
    ];

    if ($location !== '') {
        $extraColumns['location'] = $location;
    }

    $event = [
        $eventType,
        $gameRequest[1] ?? time(),
        $gameRequest[2] ?? ($gameRequest[1] ?? time()),
        $chatLine,
        'web',
        $extraColumns,
    ];

    logEvent($event, dialecticCapturedDialoguePeople($payload));
    Logger::info("[captured_dialogue] Logged {$source} chat: {$speaker}: {$text}");
}

?>
