<?php

// Explicit gameplay table policy. Shared presets/libraries and unknown plugin tables stay live.
function pts_table_policy(): array {
    return [
        'global' => explode(',', 'bio_template_actor_map,bio_templates,bio_templates_custom,core_action,core_action_custom,core_api_badge,core_itt_connector,core_llm_connector,core_narrator,core_profiles,core_stt_connector,core_tts_connector,core_tts_pronunciation,descriptions,descriptions_custom,global_settings_presets,import_rules,prompts,worldknowledge,worldknowledge_audit,worldknowledge_catalogs,worldknowledge_context_rule'),
        'playthrough' => explode(',', 'actions_issued,audit_memory,audit_request,core_npc_master,core_npc_master_history,core_player,diarylog,eventlog,factions,game_plugins,locations,log,memory,memory_summary,moods_issued,quests,relationship_eval_queue,relationship_init_queue,responselog,rolemaster,speech,visual_context'),
        'mixed' => ['conf_opts', 'general_settings'],
        'infrastructure' => ['database_versioning'],
        // Tables absent from these lists are unmanaged and must never be cleared.

    ];
}

// Only gameplay tables and gameplay rows of mixed tables belong in a save.
function pts_playthrough_tables(): array {
    $policy = pts_table_policy();
    return array_merge($policy['playthrough'], $policy['mixed']);
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
