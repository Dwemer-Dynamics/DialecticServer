<?php

require_once(__DIR__ . DIRECTORY_SEPARATOR . "settings.php");
require_once(__DIR__ . DIRECTORY_SEPARATOR . "dialectic_runtime.php");
require_once(__DIR__ . DIRECTORY_SEPARATOR . "logger.php");

if (!function_exists('dialecticRuntimeDatabaseEncoding')) {
    function dialecticRuntimeDatabaseEncoding(): string
    {
        $db = $GLOBALS['db'] ?? null;
        if (!$db) {
            return '';
        }

        try {
            $row = $db->fetchOne('SHOW server_encoding');
            return strtoupper(trim(strval($row['server_encoding'] ?? '')));
        } catch (\Throwable $e) {
            Logger::warn('[DATABASE] Could not read Dialectic database encoding: ' . $e->getMessage());
            return '';
        }
    }
}

if (!function_exists('dialecticRuntimeDatabaseEncodingIsSupported')) {
    function dialecticRuntimeDatabaseEncodingIsSupported(): bool
    {
        return dialecticRuntimeDatabaseEncoding() === 'UTF8';
    }
}

if (!function_exists('dialecticRuntimeDatabaseEncodingError')) {
    function dialecticRuntimeDatabaseEncodingError(): string
    {
        $encoding = dialecticRuntimeDatabaseEncoding();
        $label = $encoding !== '' ? $encoding : 'unknown encoding';
        return "Dialectic database uses {$label}; UTF8 is required for NPC metadata. "
            . 'Run sudo bash /var/www/html/DialecticServer/tools/migrate-dialectic-db-utf8-wsl.sh.';
    }
}

if (!function_exists('dialecticRuntimeNeedsDbUpdates')) {
    function dialecticRuntimeNeedsDbUpdates(): bool
    {
        $db = $GLOBALS["db"] ?? null;
        if (!$db) {
            return false;
        }

        $requiredRelations = [
            'public.actions_issued',
            'public.audit_memory',
            'public.audit_request',
            'public.bio_templates',
            'public.bio_templates_custom',
            'public.conf_opts',
            'public.core_action',
            'public.core_action_custom',
            'public.core_api_badge',
            'public.core_llm_connector',
            'public.core_narrator',
            'public.core_npc_master',
            'public.core_npc_master_history',
            'public.core_player',
            'public.core_profiles',
            'public.core_stt_connector',
            'public.core_tts_connector',
            'public.database_versioning',
            'public.descriptions',
            'public.descriptions_custom',
            'public.diarylog',
            'public.eventlog',
            'public.factions',
            'public.game_plugins',
            'public.general_settings',
            'public.import_rules',
            'public.locations',
            'public.log',
            'public.memory',
            'public.memory_summary',
            'public.moods_issued',
            'public.prompts',
            'public.quests',
            'public.relationship_eval_queue',
            'public.relationship_init_queue',
            'public.responselog',
            'public.rolemaster',
            'public.speech',
            'public.worldknowledge',
            'public.combined_bio_templates',
            'public.combined_core_action',
            'public.combined_descriptions',
            'public.dialecticnpcs',
            'public.memory_v',
            'dialectic_meta.playthrough_profiles',
            'dialectic_meta.settings',
        ];

        try {
            $relationRows = $db->fetchAll("
                SELECT n.nspname || '.' || c.relname AS relation_name
                  FROM pg_class c
                  JOIN pg_namespace n ON n.oid = c.relnamespace
                 WHERE c.relkind IN ('r', 'p', 'v')
                   AND n.nspname IN ('public', 'dialectic_meta')
            ");
        } catch (\Throwable $e) {
            return true;
        }

        $existingRelations = [];
        foreach ($relationRows as $row) {
            $relationName = strval($row['relation_name'] ?? '');
            if ($relationName !== '') {
                $existingRelations[$relationName] = true;
            }
        }

        foreach ($requiredRelations as $requiredRelation) {
            if (empty($existingRelations[$requiredRelation])) {
                return true;
            }
        }

        if (!empty($existingRelations['public.currentmission'])) {
            return true;
        }

        try {
            $managedGeneralSettingIds = array_values(array_unique(dialecticGetManagedGeneralSettingIds()));
            $managedGeneralSettingLiterals = implode(',', array_map(
                static fn(string $settingId): string => $db->escapeLiteral($settingId),
                $managedGeneralSettingIds
            ));
            $runtimeSeedState = $db->fetchOne("
                SELECT
                    (SELECT COUNT(*)
                       FROM public.prompts
                      WHERE prompt_key IN (
                        'dialectic_system_prompt',
                        'dialectic_response_rules',
                        'dialectic_world_prompt',
                        'dialectic_scene_prompt',
                        'dialectic_memory_prompt'
                      )) AS prompt_defaults,
                    (SELECT COUNT(*) FROM public.core_profiles WHERE default_npc = '1') AS npc_defaults,
                    (SELECT COUNT(*) FROM public.core_profiles WHERE default_narrator = '1') AS narrator_defaults,
                    (SELECT COUNT(DISTINCT p.proname)
                       FROM pg_proc p
                       JOIN pg_namespace n ON n.oid = p.pronamespace
                      WHERE n.nspname = 'dialectic_meta'
                        AND p.proname IN ('clone_schema', 'drop_schema_safe', 'get_schema_size')) AS playthrough_functions,
                    (SELECT COUNT(DISTINCT id)
                       FROM public.general_settings
                      WHERE id IN ({$managedGeneralSettingLiterals})) AS managed_general_settings
            ");
        } catch (\Throwable $e) {
            return true;
        }
        if (
            intval($runtimeSeedState['prompt_defaults'] ?? 0) !== 5 ||
            intval($runtimeSeedState['npc_defaults'] ?? 0) !== 1 ||
            intval($runtimeSeedState['narrator_defaults'] ?? 0) !== 1 ||
            intval($runtimeSeedState['playthrough_functions'] ?? 0) !== 3 ||
            intval($runtimeSeedState['managed_general_settings'] ?? 0) !== count($managedGeneralSettingIds)
        ) {
            return true;
        }

        $requiredVersions = [
            'conf_opts' => 20260626001,
            'core_action' => 20260624003,
            'core_player' => 20260707001,
            'general_settings' => 20260511001,
            'core_stt_connector' => 20260502002,
            'descriptions_defaults' => 20260626004,
            'prompts' => 20260627001,
            'core_profiles' => 20260629002,
            'moods_issued_sequence' => 20260626001,
            'core_tts_connector_metadata' => 20260626001,
            'core_tts_connector_omnivoice' => 20260708001,
            'core_tts_connector_removed_drivers' => 20260712001,
            'legacy_translation_tables_cleanup' => 20260628001,
            'legacy_currentmission_cleanup' => 20260713003,
            'general_settings_seed_repair' => 20260713004,
            'legacy_quest_tables_cleanup' => 20260628001,
            'memory_v' => 20260713001,
            'prompt_manager_defaults' => 20260713002,
            'dialecticnpcs_view' => 20260713002,
            'profile_defaults' => 20260713002,
            'playthrough_metadata_schema' => 20260713002,
            'relationship_async_queues' => 20260713002,
        ];

        try {
            $versionNames = implode(',', array_map(
                static fn(string $name): string => "'" . str_replace("'", "''", $name) . "'",
                array_keys($requiredVersions)
            ));
            $versionRows = $db->fetchAll(
                "SELECT tablename, version
                 FROM public.database_versioning
                WHERE tablename IN ({$versionNames})"
            );
        } catch (\Throwable $e) {
            return true;
        }

        $versions = [];
        foreach ($versionRows as $row) {
            $tableName = strval($row['tablename'] ?? '');
            if ($tableName !== '') {
                $versions[$tableName] = intval($row['version'] ?? -1);
            }
        }

        foreach ($requiredVersions as $tableName => $requiredVersion) {
            if (intval($versions[$tableName] ?? -1) < $requiredVersion) {
                return true;
            }
        }

        return false;
    }
}

if (!function_exists('dialecticRuntimeImportConfigVariables')) {
    function dialecticRuntimeImportConfigVariables(array $variables): void
    {
        foreach ($variables as $name => $value) {
            if (!is_string($name) || $name === '' || $name[0] === '_') {
                continue;
            }
            if (!preg_match('/^[A-Z0-9_]+$/', $name)) {
                continue;
            }
            $GLOBALS[$name] = $value;
        }
    }
}

if (!function_exists('dialecticRuntimeEnsureDbUpdates')) {
    function dialecticRuntimeEnsureDbUpdates(string $enginePath): void
    {
        static $ran = false;
        if ($ran) {
            return;
        }
        if (!dialecticRuntimeNeedsDbUpdates()) {
            $ran = true;
            return;
        }

        $updatesPath = $enginePath . "debug" . DIRECTORY_SEPARATOR . "db_updates.php";
        $db=$GLOBALS["db"] ?? null;
        if (!file_exists($updatesPath)) {
            throw new \RuntimeException("Dialectic database update file is missing: {$updatesPath}");
        }

        require($updatesPath);
        if (dialecticRuntimeNeedsDbUpdates()) {
            throw new \RuntimeException("Dialectic database bootstrap completed with pending schema updates.");
        }
        $ran = true;
    }
}

if (!function_exists('dialecticRuntimeApplyBootstrapOptions')) {
    function dialecticRuntimeApplyBootstrapOptions(string $enginePath, array $options = []): void
    {
        $runDbUpdates = !empty($options['run_db_updates']);
        $loadGeneralSettings = !array_key_exists('load_general_settings', $options) || (bool)$options['load_general_settings'];
        $loadSttConnector = !array_key_exists('load_stt_connector', $options) || (bool)$options['load_stt_connector'];
        $loadTtsConnector = $options['load_tts_connector'] ?? false;
        $loadPlayerName = !empty($options['load_player_name']);
        $loadNarrator = !empty($options['load_narrator']);

        if ($runDbUpdates) {
            dialecticRuntimeEnsureDbUpdates($enginePath);
        }
        if ($loadGeneralSettings) {
            dialecticLoadGeneralSettingsIntoGlobals();
        }
        if ($loadSttConnector) {
            dialecticLoadActiveSttConnectorIntoGlobals();
        }
        if (is_string($loadTtsConnector) && trim($loadTtsConnector) !== '') {
            dialecticLoadPreferredTtsConnectorIntoGlobals(trim($loadTtsConnector));
        } elseif ($loadTtsConnector) {
            dialecticLoadPreferredTtsConnectorIntoGlobals();
        }
        if ($loadPlayerName) {
            dialecticLoadPlayerNameIntoGlobals();
        }
        if ($loadNarrator) {
            dialecticLoadNarratorSettingsIntoGlobals();
        }
    }
}

if (!function_exists('dialecticRuntimeBootstrap')) {
    function dialecticRuntimeBootstrap(string $enginePath, array $options = []): void
    {
        $enginePath = rtrim($enginePath, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
        $GLOBALS["ENGINE_PATH"] = $enginePath;
        Logger::bootstrapRequestId("rt");
        Logger::rotateKnownLogs($enginePath);

        $confPath = $enginePath . "conf" . DIRECTORY_SEPARATOR . "conf.php";
        $confSamplePath = $enginePath . "conf" . DIRECTORY_SEPARATOR . "conf.sample.php";

        if (file_exists($confSamplePath)) {
            require($confSamplePath);
        }
        if (file_exists($confPath)) {
            require($confPath);
        }

        dialecticRuntimeImportConfigVariables(get_defined_vars());

        if (empty($GLOBALS["DBDRIVER"])) {
            throw new \RuntimeException("DBDRIVER is not configured during runtime bootstrap.");
        }

        require_once($enginePath . "lib" . DIRECTORY_SEPARATOR . "{$GLOBALS["DBDRIVER"]}.class.php");
        $needsDbConnection = !isset($GLOBALS["db"]) || !($GLOBALS["db"] instanceof sql);
        if (!$needsDbConnection) {
            try {
                $needsDbConnection = !$GLOBALS["db"]->query("SELECT 1");
            } catch (\Throwable $e) {
                $needsDbConnection = true;
            }
        }
        if ($needsDbConnection) {
            $GLOBALS["db"] = new sql();
        }

        dialecticRuntimeApplyBootstrapOptions($enginePath, $options);
    }
}

if (!function_exists('dialecticRuntimeBootstrapIfNeeded')) {
    function dialecticRuntimeBootstrapIfNeeded(string $enginePath, array $options = []): void
    {
        $enginePath = rtrim($enginePath, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;

        if (empty($GLOBALS["DBDRIVER"]) || !isset($GLOBALS["db"]) || !is_object($GLOBALS["db"])) {
            dialecticRuntimeBootstrap($enginePath, $options);
            return;
        }

        $GLOBALS["ENGINE_PATH"] = $enginePath;
        Logger::bootstrapRequestId("rt");
        Logger::rotateKnownLogs($enginePath);
        dialecticRuntimeApplyBootstrapOptions($enginePath, $options);
    }
}

if (!function_exists('dialecticRuntimeSetActiveProfile')) {
    function dialecticRuntimeSetActiveProfile($profile): ?string
    {
        $profile = trim(strval($profile ?? ''));
        if ($profile === '') {
            unset($GLOBALS["active_profile"]);
            return null;
        }

        $GLOBALS["active_profile"] = $profile;
        return $profile;
    }
}

if (!function_exists('dialecticRuntimeGetActiveProfile')) {
    function dialecticRuntimeGetActiveProfile(): ?string
    {
        $profile = trim(strval($GLOBALS["active_profile"] ?? ''));
        return $profile !== '' ? $profile : null;
    }
}

if (!function_exists('dialecticRuntimeBindActiveProfileFromRequest')) {
    function dialecticRuntimeBindActiveProfileFromRequest(): ?string
    {
        return dialecticRuntimeGetActiveProfile();
    }
}

?>
