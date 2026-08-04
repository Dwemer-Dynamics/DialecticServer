<?php

/**
 * Compact prior NPC conversation history without changing live response schemas.
 */

if (!function_exists('dialecticCompactNpcContextHistoryEnabled')) {
    function dialecticCompactNpcContextHistoryEnabled(): bool
    {
        $value = $GLOBALS['COMPACT_NPC_CONTEXT_HISTORY'] ?? false;
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

if (!function_exists('dialecticFormatCompactNpcContextHistory')) {
    function dialecticFormatCompactNpcContextHistory(array $history, string $actorName): array
    {
        $formatted = [];
        foreach ($history as $entry) {
            if (!is_array($entry) || !isset($entry['role'], $entry['content'])) {
                $formatted[] = $entry;
                continue;
            }

            $role = (string)$entry['role'];
            if ($role === 'assistant') {
                if (isset($entry['tool_calls']) || (!is_string($entry['content']) && !is_scalar($entry['content']))) {
                    $formatted[] = $entry;
                    continue;
                }

                $content = dialecticCompactAssistantHistoryEntry((string)$entry['content'], $actorName);
                if ($content !== '') {
                    $formatted[] = [
                        'role' => 'assistant',
                        'content' => $content,
                        '_dialectic_compact_history' => true,
                    ];
                }
                continue;
            }

            if ($role === 'user') {
                if (!is_string($entry['content']) && !is_scalar($entry['content'])) {
                    $formatted[] = $entry;
                    continue;
                }

                $content = dialecticCompactUserHistoryEntry((string)$entry['content']);
                if ($content !== '') {
                    $formatted[] = ['role' => 'user', 'content' => $content];
                }
                continue;
            }

            $formatted[] = $entry;
        }

        return $formatted;
    }
}
