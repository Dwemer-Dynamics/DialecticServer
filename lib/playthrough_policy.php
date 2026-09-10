<?php

// Explicit gameplay table policy. Shared presets/libraries and unknown plugin tables stay live.
function pts_playthrough_tables(): array {
    return [
        'actions_issued',
        'audit_memory',
        'audit_request',
        'conf_opts',
        'core_action',
        'core_action_custom',
        'core_api_badge',
        'core_itt_connector',
        'core_llm_connector',
        'core_narrator',
        'core_npc_master',
        'core_npc_master_history',
        'core_player',
        'core_profiles',
        'core_stt_connector',
        'core_tts_connector',
        'core_tts_pronunciation',
        'database_versioning',
        'diarylog',
        'eventlog',
        'factions',
        'game_plugins',
        'general_settings',
        'import_rules',
        'locations',
        'log',
        'memory',
        'memory_summary',
        'moods_issued',
        'prompts',
        'quests',
        'relationship_eval_queue',
        'relationship_init_queue',
        'responselog',
        'rolemaster',
        'speech',
        'visual_context',
        'worldknowledge',
        'worldknowledge_audit',
        'worldknowledge_catalogs',
        'worldknowledge_context_rule',
    ];
}

// Shared libraries excluded here include biography templates, descriptions and preset stores.

// Run after database updates so new tables and retired labels follow the capture policy.
function pts_update_playthrough_policy($conn): bool {
    if (!pts_ensure_functions($conn)) return false;
    $result = @pg_query_params($conn,
        'SELECT dialectic_meta.sync_playthrough_comments(ARRAY(SELECT jsonb_array_elements_text($1::jsonb)))',
        [json_encode(pts_playthrough_tables())]);
    if (!$result) Logger::error('Could not refresh Playthrough Save table comments: ' . pg_last_error($conn));
    return $result !== false;
}
