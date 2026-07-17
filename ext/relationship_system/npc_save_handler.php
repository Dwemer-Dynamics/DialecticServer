<?php

require_once __DIR__ . '/../../lib/relationship_manager.php';

if (!function_exists('dialecticMergePostedRelationshipsIntoExtendedData')) {
    function dialecticMergePostedRelationshipsIntoExtendedData(array &$post): bool
    {
        if (!array_key_exists('relationships_jsonb', $post)) {
            return false;
        }

        $decoded = json_decode((string)$post['relationships_jsonb'], true);
        if (!is_array($decoded)) {
            throw new InvalidArgumentException('Relationship data must be a JSON object.');
        }
        $relationships = RelationshipManager::normalizeRelationshipMap($decoded);

        $extended = json_decode((string)($post['extended_data'] ?? '{}'), true);
        if (!is_array($extended)) {
            throw new InvalidArgumentException('Extended metadata is not valid JSON.');
        }

        $oldRelationships = RelationshipManager::normalizeRelationshipMap($extended['relationships'] ?? []);
        $extended['relationships'] = $relationships;
        $extended['relationships_locked'] = filter_var(
            $post['relationships_locked'] ?? false,
            FILTER_VALIDATE_BOOLEAN
        );

        $npcName = trim((string)($post['npc_name'] ?? 'Unknown NPC'));
        if ($oldRelationships !== $relationships && class_exists('Logger')) {
            Logger::info(sprintf(
                '[REL-UI] %s relationship map saved (%d target%s)',
                $npcName,
                count($relationships),
                count($relationships) === 1 ? '' : 's'
            ));
        }

        $post['extended_data'] = json_encode(
            $extended,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
        );
        unset($post['relationships'], $post['npc_relationships']);
        return true;
    }
}
