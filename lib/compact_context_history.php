<?php

/**
 * Compact prior NPC conversation history without changing live response schemas.
 */

if (!function_exists('dialecticCompactNpcContextHistoryEnabled')) {
    function dialecticCompactNpcContextHistoryEnabled(): bool
    {
        $value = $GLOBALS['COMPACT_NPC_CONTEXT_HISTORY'] ?? true;
        if (is_bool($value)) {
            return $value;
        }

        return in_array(strtolower(trim((string)$value)), ['1', 'true', 'yes', 'on'], true);
    }
}

if (!function_exists('dialecticShouldCompactNpcContextHistory')) {
    function dialecticShouldCompactNpcContextHistory(?string $actorName = null): bool
    {
        if (!dialecticCompactNpcContextHistoryEnabled()) {
            return false;
        }

        $actorName = trim($actorName ?? (string)($GLOBALS['DIALECTIC_NAME'] ?? ''));
        return $actorName !== '' && strcasecmp($actorName, 'The Narrator') !== 0;
    }
}

if (!function_exists('dialecticCompactHistoryWhitespace')) {
    function dialecticCompactHistoryWhitespace(string $text): string
    {
        return trim((string)preg_replace('/\s+/u', ' ', $text));
    }
}

if (!function_exists('dialecticCompactHistoryDialogue')) {
    function dialecticCompactHistoryDialogue(
        string $content,
        string $fallbackSpeaker,
        bool $acceptAnySpeakerPrefix = true
    ): string {
        $content = trim($content);
        $speaker = trim($fallbackSpeaker);
        $listener = '';

        if (preg_match('/\s*\((?:talking|whispering|shouting|speaking privately)\s+to\s+([^\)]+)\)\s*\.?\s*$/iu', $content, $match)) {
            $listener = dialecticCompactHistoryWhitespace($match[1]);
            $content = trim((string)preg_replace(
                '/\s*\((?:talking|whispering|shouting|speaking privately)\s+to\s+[^\)]+\)\s*\.?\s*$/iu',
                '',
                $content
            ));
        }

        if (preg_match('/^([^:\r\n]{1,100}):\s*(.+)$/us', $content, $match)) {
            $candidateSpeaker = dialecticCompactHistoryWhitespace($match[1]);
            if ($acceptAnySpeakerPrefix || $speaker === '' || strcasecmp($candidateSpeaker, $speaker) === 0) {
                $speaker = $candidateSpeaker;
                $content = $match[2];
            }
        }

        $content = dialecticCompactHistoryWhitespace($content);
        if ($speaker === '') {
            return $content;
        }

        return $listener !== ''
            ? "{$speaker}, speaking to {$listener}: {$content}"
            : "{$speaker}: {$content}";
    }
}

if (!function_exists('dialecticCompactAssistantHistoryEntry')) {
    function dialecticCompactAssistantHistoryEntry(string $content, string $actorName): string
    {
        $decoded = json_decode($content, true);
        if (!is_array($decoded)) {
            return dialecticCompactHistoryDialogue($content, $actorName, false);
        }

        $speaker = dialecticCompactHistoryWhitespace((string)($decoded['speaker'] ?? $decoded['character'] ?? $actorName));
        $listener = dialecticCompactHistoryWhitespace((string)($decoded['listener'] ?? ''));
        $message = dialecticCompactHistoryWhitespace((string)($decoded['text'] ?? $decoded['message'] ?? ''));
        $action = dialecticCompactHistoryWhitespace((string)($decoded['action'] ?? ''));
        $target = dialecticCompactHistoryWhitespace((string)($decoded['target'] ?? ''));

        $line = $listener !== '' ? "{$speaker}, speaking to {$listener}" : $speaker;
        if ($message !== '') {
            $line .= ': ' . $message;
        }
        if ($action !== '' && strcasecmp($action, 'Talk') !== 0 && strcasecmp($action, 'JustTalk') !== 0) {
            $line .= ' [Action: ' . $action . ($target !== '' ? ", targeting {$target}" : '') . ']';
        }

        return trim($line);
    }
}

if (!function_exists('dialecticCompactUserHistoryEntry')) {
    function dialecticCompactUserHistoryEntry(string $content): string
    {
        $content = trim($content);

        if (preg_match(
            '/^LOCATION CHANGE to (.*?)(?:,\s*worldspace:\s*(.*?))?,\s*timeline mark:\s*([^\r\n]+)$/iu',
            $content,
            $match
        )) {
            $location = dialecticCompactHistoryWhitespace($match[1]);
            $worldspace = dialecticCompactHistoryWhitespace($match[2] ?? '');
            $time = dialecticCompactHistoryWhitespace($match[3]);
            $place = $worldspace !== '' ? "{$location} in {$worldspace}" : $location;
            if (preg_match('/^0(?:\.0+)?\s+hours?\s+ago$/iu', $time)) {
                return "The current scene is at {$place}.";
            }

            return "The scene {$time} took place at {$place}.";
        }

        if (preg_match('/^\(minor timelapse of about ([^)]+)\)$/iu', $content, $match)) {
            return 'About ' . dialecticCompactHistoryWhitespace($match[1]) . ' later.';
        }

        if (preg_match('/^\*?\(Context location:\s*(.*?)\s+background chat\)\s*(.*?)\*?$/ius', $content, $match)) {
            $location = dialecticCompactHistoryWhitespace($match[1]);
            $dialogue = dialecticCompactHistoryDialogue(trim($match[2], " \t\n\r\0\x0B*"), '');
            return "Background dialogue at {$location}: {$dialogue}";
        }

        if (preg_match('/\((?:talking|whispering|shouting|speaking privately)\s+to\s+[^\)]+\)\s*\.?\s*$/iu', $content)) {
            return dialecticCompactHistoryDialogue($content, '');
        }

        $content = dialecticCompactHistoryWhitespace($content);
        if (preg_match(
            '/\b(?:traded with|sold:|gave .* caps|issued ACTION|consumed|picked up|quest|lockpicked|combat)\b/iu',
            $content
        )) {
            return 'Event: ' . $content;
        }

        return $content;
    }
}

if (!function_exists('dialecticCompactToolHistoryEntry')) {
    function dialecticCompactToolHistoryEntry(array $entry): string
    {
        $content = $entry['content'] ?? '';
        if (is_string($content) || is_scalar($content)) {
            $content = dialecticCompactHistoryWhitespace((string)$content);
            if ($content !== '') {
                return 'Tool result: ' . $content;
            }
        }

        $toolCalls = $entry['tool_calls'] ?? [];
        if (!is_array($toolCalls)) {
            return '';
        }

        $calls = [];
        foreach ($toolCalls as $toolCall) {
            if (!is_array($toolCall)) {
                continue;
            }

            $function = $toolCall['function'] ?? [];
            if (!is_array($function)) {
                continue;
            }

            $name = dialecticCompactHistoryWhitespace((string)($function['name'] ?? ''));
            if ($name !== '') {
                $calls[] = $name;
            }
        }

        return $calls === [] ? '' : 'Requested action: ' . implode(', ', $calls) . '.';
    }
}

if (!function_exists('dialecticFormatCompactNpcContextHistory')) {
    function dialecticFormatCompactNpcContextHistory(array $history, string $actorName): string
    {
        $lines = [];

        foreach ($history as $entry) {
            if (!is_array($entry) || !isset($entry['role'])) {
                continue;
            }

            $role = (string)$entry['role'];
            if ($role === 'assistant') {
                if (isset($entry['tool_calls'])) {
                    $content = dialecticCompactToolHistoryEntry($entry);
                    if ($content !== '') {
                        $lines[] = $content;
                    }
                    continue;
                }

                $entryContent = $entry['content'] ?? '';
                if (!is_string($entryContent) && !is_scalar($entryContent)) {
                    continue;
                }

                $content = dialecticCompactAssistantHistoryEntry((string)$entryContent, $actorName);
                if ($content !== '') {
                    $lines[] = $content;
                }
                continue;
            }

            if ($role === 'user') {
                $entryContent = $entry['content'] ?? '';
                if (!is_string($entryContent) && !is_scalar($entryContent)) {
                    continue;
                }

                $content = dialecticCompactUserHistoryEntry((string)$entryContent);
                if ($content !== '') {
                    $lines[] = $content;
                }
                continue;
            }

            if ($role === 'tool') {
                $content = dialecticCompactToolHistoryEntry($entry);
            } else {
                $entryContent = $entry['content'] ?? '';
                $content = (is_string($entryContent) || is_scalar($entryContent))
                    ? dialecticCompactHistoryWhitespace((string)$entryContent)
                    : '';
            }

            if ($content !== '') {
                $lines[] = $content;
            }
        }

        return implode("\n", array_map(
            static fn(string $line): string => '# ' . $line,
            $lines
        ));
    }
}

if (!function_exists('dialecticAppendCompactHistoryToPrompt')) {
    function dialecticAppendCompactHistoryToPrompt(array $worldContext, string $historyBlock, bool $markdownEnabled = false): array
    {
        $historyBlock = trim($historyBlock);
        if ($historyBlock === '') {
            return $worldContext;
        }

        if ($markdownEnabled) {
            $historyBlock = "# Conversation History\n\n" . preg_replace('/^# /m', '- ', $historyBlock);
        }

        foreach ($worldContext as &$entry) {
            if (
                is_array($entry)
                && ($entry['role'] ?? '') === 'system'
                && isset($entry['content'])
                && (is_string($entry['content']) || is_scalar($entry['content']))
            ) {
                $entry['content'] = rtrim((string)$entry['content']) . "\n\n" . $historyBlock;
                unset($entry);
                return $worldContext;
            }
        }
        unset($entry);

        array_unshift($worldContext, [
            'role' => 'system',
            'content' => $historyBlock,
        ]);

        return $worldContext;
    }
}
