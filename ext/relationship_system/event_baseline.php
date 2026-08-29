<?php

if (!function_exists('dialecticRelNormalizeName')) {
    function dialecticRelNormalizeName(string $value): string
    {
        return trim($value);
    }
}

if (!function_exists('dialecticRelContainsName')) {
    function dialecticRelContainsName(string $haystack, string $name): bool
    {
        if ($haystack === '' || $name === '') {
            return false;
        }
        return preg_match(
            '/(?<![\p{L}\p{N}])' . preg_quote($name, '/') . '(?![\p{L}\p{N}])/iu',
            $haystack
        ) === 1;
    }
}

if (!function_exists('dialecticRelParticipants')) {
    function dialecticRelParticipants(array $row): array
    {
        $names = [];
        $add = static function ($name) use (&$names): void {
            $name = trim((string)$name);
            if ($name !== '' && strcasecmp($name, 'The Narrator') !== 0) {
                $names[strtolower($name)] = $name;
            }
        };

        foreach (explode('|', (string)($row['people'] ?? '')) as $name) {
            $add($name);
        }
        $data = (string)($row['data'] ?? '');
        if (preg_match('/^\s*(?:\*\s*)?(?:\([^)]*\)\s*)?([^:]+):/u', $data, $match)) {
            $add($match[1]);
        }
        if (preg_match('/\((?:talking|shouting|whispering|speaking privately)\s+to\s+([^)]+)\)/iu', $data, $match)) {
            $add($match[1]);
        }
        return array_values($names);
    }
}

if (!function_exists('dialecticRelBuildEventBaseline')) {
    function dialecticRelBuildEventBaseline(string $npcName, int $eventLimit = 200): array
    {
        $db = $GLOBALS['db'] ?? null;
        $npcName = dialecticRelNormalizeName($npcName);
        if (!$db || $npcName === '') {
            return ['ok' => false, 'error' => $db ? 'NPC name is required.' : 'Database unavailable.'];
        }

        $eventLimit = max(25, min(400, $eventLimit));
        $scanLimit = max(300, min(3500, $eventLimit * 8));
        $escapedName = $db->escape($npcName);
        $rows = $db->fetchAll(
            "SELECT rowid, type, data, gamets, people, location
             FROM eventlog
             WHERE type NOT IN ('prechat','setconf','status_msg','user_input','npc_snapshot','playerinfo')
               AND (
                    POSITION(LOWER('{$escapedName}') IN LOWER(COALESCE(people, ''))) > 0
                    OR POSITION(LOWER('{$escapedName}') IN LOWER(COALESCE(data, ''))) > 0
               )
             ORDER BY rowid DESC
             LIMIT " . intval($scanLimit)
        );

        $lines = [];
        $counterparts = [];
        foreach (is_array($rows) ? $rows : [] as $row) {
            $participants = dialecticRelParticipants($row);
            $includesNpc = false;
            foreach ($participants as $participant) {
                if (strcasecmp($participant, $npcName) === 0) {
                    $includesNpc = true;
                } else {
                    $counterparts[strtolower($participant)] = $participant;
                }
            }
            if (!$includesNpc
                && !dialecticRelContainsName((string)($row['people'] ?? ''), $npcName)
                && !dialecticRelContainsName((string)($row['data'] ?? ''), $npcName)) {
                continue;
            }

            $data = trim((string)($row['data'] ?? ''));
            if ($data === '') {
                continue;
            }
            if (function_exists('mb_substr')) {
                $data = mb_substr($data, 0, 220, 'UTF-8');
            } else {
                $data = substr($data, 0, 220);
            }
            $type = trim((string)($row['type'] ?? 'event')) ?: 'event';
            $gamets = max(0, (int)($row['gamets'] ?? 0));
            $lines[] = '[' . $type . ($gamets ? ' @ gamets=' . $gamets : '') . '] ' . $data;
            if (count($lines) >= $eventLimit) {
                break;
            }
        }

        $lines = array_reverse($lines);
        if (!$lines) {
            return ['ok' => false, 'error' => 'No recent event history found for this NPC.'];
        }
        $counterpartNames = array_values($counterparts);
        usort($counterpartNames, 'strnatcasecmp');
        return [
            'ok' => true,
            'event_count' => count($lines),
            'history' => implode("\n", $lines),
            'counterparts' => $counterpartNames,
        ];
    }
}
