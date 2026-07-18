<?php

if (($gameRequest[0] == "delete_event")) {
    // Do this ASAP
    $datacn = $db->escape($gameRequest[3]);
    $db->delete("eventlog", "type in ('chat','prechat') and data like '%$datacn%' and localts>" . (time() - 120));
    // audit_log(__FILE__);
    terminate();
}

if (!function_exists('maybeQueueNpcVoiceRefresh')) {
function maybeQueueNpcVoiceRefresh($currentNpcData, $npcMaster)
{
    if (!$currentNpcData || !($npcMaster instanceof NpcMaster)) {
        return $currentNpcData;
    }

    $npcName = trim((string) ($currentNpcData["npc_name"] ?? ""));
    if ($npcName === "" || strcasecmp($npcName, "The Narrator") === 0) {
        return $currentNpcData;
    }

    $voiceId = trim((string) ($currentNpcData["voiceid"] ?? ""));
    if ($voiceId !== "") {
        return $currentNpcData;
    }

    $extended = $npcMaster->getExtendedData($currentNpcData);
    $lastRequestedAt = intval($extended["voice_refresh_requested_at"] ?? 0);
    $cooldownSeconds = 300;
    $now = time();

    if ($lastRequestedAt > 0 && ($now - $lastRequestedAt) < $cooldownSeconds) {
        return $currentNpcData;
    }

    $extended["voice_refresh_requested_at"] = $now;
    $extended["voice_refresh_attempts"] = intval($extended["voice_refresh_attempts"] ?? 0) + 1;
    $extended["voice_refresh_last_result"] = "awaiting_plugin_profile";

    $currentNpcData = $npcMaster->setExtendedData($currentNpcData, $extended);
    $npcMaster->updateByArray($currentNpcData);

    $refId = trim((string) ($currentNpcData["refid"] ?? ""));
    if ($refId !== "" && stripos($refId, "0x") !== 0) {
        $refId = "0x{$refId}";
    }

    Logger::info("[NPC_VOICE] Missing voice mapping for {$npcName} ({$refId}); awaiting the plugin profile refresh");

    return $currentNpcData;
}
}


if (!function_exists('dialecticFormatPromptXmlSections')) {
function dialecticFormatPromptXmlSections($content)
{
    $content = str_replace(["\r\n", "\r"], "\n", (string) $content);
    $content = preg_replace("/[ \t]+\n/", "\n", $content);

    $lines = explode("\n", $content);
    $formatted = [];
    $lineCount = count($lines);

    for ($i = 0; $i < $lineCount; $i++) {
        $line = rtrim($lines[$i]);
        $trimmed = trim($line);

        if ($trimmed === '') {
            if (!empty($formatted) && trim(end($formatted)) !== '') {
                $formatted[] = '';
            }
            continue;
        }

        $isBlockOpenTag = preg_match('/^<([A-Za-z0-9_]+)>$/', $trimmed) === 1;
        $isBlockCloseTag = preg_match('/^<\/([A-Za-z0-9_]+)>$/', $trimmed) === 1;

        if ($isBlockOpenTag && !empty($formatted) && trim(end($formatted)) !== '') {
            $formatted[] = '';
        }

        $formatted[] = $line;

        if ($isBlockCloseTag) {
            $nextNonEmpty = '';
            for ($j = $i + 1; $j < $lineCount; $j++) {
                $candidate = trim(rtrim($lines[$j]));
                if ($candidate !== '') {
                    $nextNonEmpty = $candidate;
                    break;
                }
            }

            if ($nextNonEmpty !== '' && trim(end($formatted)) !== '') {
                $formatted[] = '';
            }
        }
    }

    while (!empty($formatted) && trim($formatted[0]) === '') {
        array_shift($formatted);
    }
    while (!empty($formatted) && trim(end($formatted)) === '') {
        array_pop($formatted);
    }

    $content = implode("\n", $formatted);
    $content = preg_replace("/\n{3,}/", "\n\n", $content);

    return $content . "\n";
}
}

if (!function_exists('dialecticRemovePromptXmlBlock')) {
function dialecticRemovePromptXmlBlock($content, string $tag)
{
    $tagPattern = preg_quote($tag, '/');
    return preg_replace('/\n*<' . $tagPattern . '>\s*.*?\s*<\/' . $tagPattern . '>\n*/s', "\n", (string) $content);
}
}

if (!function_exists('dialecticApplyPromptContextOptionsToSystemPrompt')) {
function dialecticApplyPromptContextOptionsToSystemPrompt($content)
{
    if (!function_exists('dialecticGetPromptContextOptions') || !function_exists('dialecticGetPromptContextOptionCatalog')) {
        return dialecticFormatPromptXmlSections($content);
    }

    $options = dialecticGetPromptContextOptions();
    $catalog = dialecticGetPromptContextOptionCatalog();

    foreach ($catalog as $bucket => $bucketOptions) {
        $enabledTags = $options[$bucket] ?? array_keys($bucketOptions ?? []);
        foreach (array_keys($bucketOptions ?? []) as $tag) {
            if (!in_array($tag, $enabledTags, true)) {
                $content = dialecticRemovePromptXmlBlock($content, $tag);
            }
        }
    }

    if (!preg_match('/<character>\s*<\/character>/s', $content)) {
        $content = preg_replace('/\n{3,}/', "\n\n", $content);
    }

    if (!preg_match('/<general_instructions>\s*<\/general_instructions>/s', $content)) {
        $content = preg_replace('/\n{3,}/', "\n\n", $content);
    }

    return dialecticFormatPromptXmlSections($content);
}
}
?>
