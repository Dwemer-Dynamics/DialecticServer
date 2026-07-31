<?php

require_once(__DIR__ . DIRECTORY_SEPARATOR . 'settings.php');

if (!function_exists('dialecticVisualContextText')) {
    function dialecticVisualContextText($value, int $maxLength = 500): string
    {
        if (is_array($value) || is_object($value)) {
            $value = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }
        $value = trim(preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', strval($value)) ?? '');
        return function_exists('mb_substr')
            ? mb_substr($value, 0, $maxLength)
            : substr($value, 0, $maxLength);
    }
}

if (!function_exists('dialecticVisualContextSubjectType')) {
    function dialecticVisualContextSubjectType($value): string
    {
        $value = strtolower(trim(strval($value)));
        $allowed = ['scene', 'location', 'actor', 'player', 'item', 'furniture', 'object'];
        return in_array($value, $allowed, true) ? $value : 'scene';
    }
}

if (!function_exists('dialecticVisualContextStore')) {
    function dialecticVisualContextStore(array $record): int
    {
        $db = $GLOBALS['db'] ?? null;
        if (!$db) {
            return 0;
        }

        $metadata = $record['metadata'] ?? [];
        if (!is_array($metadata)) {
            $metadata = [];
        }
        $metadataJson = json_encode($metadata, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($metadataJson === false) {
            $metadataJson = '{}';
        }

        $subjectType = dialecticVisualContextSubjectType($record['subject_type'] ?? 'scene');
        $subjectName = dialecticVisualContextText($record['subject_name'] ?? '', 300);
        $location = dialecticVisualContextText($record['location_name'] ?? '', 300);
        $worldspace = dialecticVisualContextText($record['worldspace_name'] ?? '', 300);
        $subjectKey = dialecticVisualContextText($record['subject_key'] ?? '', 500);
        if ($subjectKey === '') {
            $subjectKey = $subjectType . ':' . strtolower($subjectName !== '' ? $subjectName : ($location . ':' . $worldspace));
        }

        $row = $db->fetchOne("INSERT INTO public.visual_context (
                capture_id, subject_type, subject_key, subject_name, plugin, baseid, refid,
                cell_id, worldspace_id, location_name, worldspace_name, image_path,
                image_sha256, description, perspective, provider, model, metadata,
                locked, active, user_edited
            ) VALUES (" . implode(', ', [
                $db->escapeLiteral(dialecticVisualContextText($record['capture_id'] ?? '', 160)),
                $db->escapeLiteral($subjectType),
                $db->escapeLiteral($subjectKey),
                $db->escapeLiteral($subjectName),
                $db->escapeLiteral(dialecticVisualContextText($record['plugin'] ?? '', 255)),
                $db->escapeLiteral(dialecticVisualContextText($record['baseid'] ?? '', 32)),
                $db->escapeLiteral(dialecticVisualContextText($record['refid'] ?? '', 32)),
                $db->escapeLiteral(dialecticVisualContextText($record['cell_id'] ?? '', 32)),
                $db->escapeLiteral(dialecticVisualContextText($record['worldspace_id'] ?? '', 32)),
                $db->escapeLiteral($location),
                $db->escapeLiteral($worldspace),
                $db->escapeLiteral(dialecticVisualContextText($record['image_path'] ?? '', 1000)),
                $db->escapeLiteral(dialecticVisualContextText($record['image_sha256'] ?? '', 64)),
                $db->escapeLiteral(dialecticVisualContextText($record['description'] ?? '', 12000)),
                $db->escapeLiteral(dialecticVisualContextText($record['perspective'] ?? 'first_person', 50)),
                $db->escapeLiteral(dialecticVisualContextText($record['provider'] ?? '', 100)),
                $db->escapeLiteral(dialecticVisualContextText($record['model'] ?? '', 255)),
                $db->escapeLiteral($metadataJson) . '::jsonb',
                !empty($record['locked']) ? 'TRUE' : 'FALSE',
                array_key_exists('active', $record) && empty($record['active']) ? 'FALSE' : 'TRUE',
                !empty($record['user_edited']) ? 'TRUE' : 'FALSE',
            ]) . ') RETURNING id');

        return intval($row['id'] ?? 0);
    }
}

if (!function_exists('dialecticVisualContextList')) {
    function dialecticVisualContextList(int $limit = 250): array
    {
        $limit = max(1, min($limit, 1000));
        try {
            $rows = $GLOBALS['db']->fetchAll(
                "SELECT * FROM public.visual_context ORDER BY locked DESC, captured_at DESC LIMIT {$limit}"
            );
            return is_array($rows) ? $rows : [];
        } catch (Throwable $e) {
            return [];
        }
    }
}

if (!function_exists('dialecticVisualContextUpdate')) {
    function dialecticVisualContextUpdate(int $id, array $values): bool
    {
        if ($id < 1) {
            return false;
        }
        $assignments = [];
        if (array_key_exists('description', $values)) {
            $assignments[] = 'description=' . $GLOBALS['db']->escapeLiteral(
                dialecticVisualContextText($values['description'], 12000)
            );
            $assignments[] = 'user_edited=TRUE';
        }
        foreach (['locked', 'active'] as $key) {
            if (array_key_exists($key, $values)) {
                $assignments[] = $key . '=' . (!empty($values[$key]) ? 'TRUE' : 'FALSE');
            }
        }
        if (!$assignments) {
            return false;
        }
        $assignments[] = 'updated_at=CURRENT_TIMESTAMP';
        return $GLOBALS['db']->execQuery(
            'UPDATE public.visual_context SET ' . implode(', ', $assignments) . ' WHERE id=' . $id
        ) !== false;
    }
}

if (!function_exists('dialecticVisualContextDelete')) {
    function dialecticVisualContextDelete(int $id): bool
    {
        return $id > 0 && $GLOBALS['db']->delete('public.visual_context', 'id=' . $id) !== false;
    }
}

if (!function_exists('dialecticBuildVisualContextPrompt')) {
    function dialecticBuildVisualContextPrompt(array $worldPayload = []): string
    {
        $db = $GLOBALS['db'] ?? null;
        if (!$db) {
            return '';
        }

        $location = trim(strval($worldPayload['location'] ?? ''));
        $worldspace = trim(strval($worldPayload['worldspace'] ?? ''));
        $cellId = strtolower(trim(strval($worldPayload['cell_formid'] ?? '')));
        if ($location === '' && $worldspace === '' && $cellId === '') {
            return '';
        }

        $ttlMinutes = max(1, min(dialecticGetGeneralSettingInt('VISUAL_CONTEXT_SCENE_TTL_MINUTES', 10), 1440));
        $maxChars = max(200, min(dialecticGetGeneralSettingInt('VISUAL_CONTEXT_PROMPT_MAX_CHARS', 1800), 12000));
        $locationLiteral = $db->escapeLiteral($location);
        $worldspaceLiteral = $db->escapeLiteral($worldspace);
        $cellLiteral = $db->escapeLiteral($cellId);

        try {
            $rows = $db->fetchAll("SELECT subject_name, description, locked, captured_at
                FROM public.visual_context
                WHERE active=TRUE
                  AND BTRIM(description)<>''
                  AND (
                        ({$cellLiteral}<>'' AND LOWER(cell_id)={$cellLiteral})
                        OR (
                            ({$cellLiteral}='' OR BTRIM(cell_id)='')
                            AND ({$locationLiteral}='' OR LOWER(location_name)=LOWER({$locationLiteral}))
                            AND ({$worldspaceLiteral}='' OR LOWER(worldspace_name)=LOWER({$worldspaceLiteral}))
                        )
                  )
                  AND (locked=TRUE OR captured_at>=CURRENT_TIMESTAMP - INTERVAL '{$ttlMinutes} minutes')
                ORDER BY locked DESC, user_edited DESC, captured_at DESC
                LIMIT 3");
        } catch (Throwable $e) {
            return '';
        }

        $entries = [];
        foreach ((array)$rows as $row) {
            $description = dialecticVisualContextText($row['description'] ?? '', $maxChars);
            if ($description === '') {
                continue;
            }
            $name = dialecticVisualContextText($row['subject_name'] ?? '', 120);
            $entries[] = ($name !== '' ? $name . ': ' : '') . $description;
        }
        if (!$entries) {
            return '';
        }

        $body = dialecticVisualContextText(implode("\n", $entries), $maxChars);
        $body = htmlspecialchars($body, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        return "<visual_context>\n# RECENT PIPVISION CONTEXT\n{$body}\n</visual_context>";
    }
}
