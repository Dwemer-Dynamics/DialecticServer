<?php

require_once __DIR__ . DIRECTORY_SEPARATOR . 'relationship_manager.php';

if (!function_exists('dialecticRelationshipSettingEnabled')) {
    function dialecticRelationshipSettingEnabled($value = null): bool
    {
        if (func_num_args() === 0) {
            $value = $GLOBALS['RELATIONSHIP_SYSTEM_ENABLED'] ?? false;
        }

        if (is_bool($value)) {
            return $value;
        }
        if (is_int($value) || is_float($value)) {
            return $value != 0;
        }

        return in_array(strtolower(trim((string)$value)), ['1', 'true', 'yes', 'on'], true);
    }
}
if (!function_exists('dialecticRelationshipUsesDedicatedConnector')) {
    function dialecticRelationshipUsesDedicatedConnector($value = null): bool
    {
        if (func_num_args() === 0) {
            $value = $GLOBALS['RELLLM_CONNECTOR'] ?? 0;
        }

        return dialecticRelationshipSettingEnabled() && intval($value) > 0;
    }
}

if (!function_exists('dialecticRelationshipCompletedText')) {
    function dialecticRelationshipCompletedText(array $lines): string
    {
        $clean = [];
        foreach ($lines as $line) {
            $line = trim((string)$line);
            if ($line !== '') {
                $clean[] = $line;
            }
        }

        return trim(implode(' ', $clean));
    }
}

if (!function_exists('dialecticRelationshipProcessInlineCompletedResponse')) {
    function dialecticRelationshipProcessInlineCompletedResponse(
        array $lines,
        string $npcName,
        ?callable $parser = null
    ): string {
        $fullResponse = dialecticRelationshipCompletedText($lines);
        if (!dialecticRelationshipSettingEnabled()
            || dialecticRelationshipUsesDedicatedConnector()
            || $fullResponse === ''
            || trim($npcName) === ''
            || strcasecmp(trim($npcName), 'The Narrator') === 0) {
            return $fullResponse;
        }

        $parser = $parser ?? [RelationshipManager::class, 'parseChanges'];
        return (string)$parser($fullResponse, trim($npcName));
    }
}
