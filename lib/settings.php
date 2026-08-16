<?php

if (!function_exists('dialecticSettingsDb')) {
    function dialecticSettingsDb()
    {
        return $GLOBALS["db"] ?? null;
    }
}

if (!function_exists('dialecticSettingsStringifyValue')) {
    function dialecticSettingsStringifyValue($value): string
    {
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        if (is_array($value)) {
            return json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }

        if ($value === null) {
            return '';
        }

        return strval($value);
    }
}

if (!function_exists('dialecticSettingsNormalizeScalar')) {
    function dialecticSettingsNormalizeScalar(string $rawValue, array $definition = [])
    {
        $type = strtolower(trim(strval($definition['type'] ?? 'string')));

        if ($type === 'boolean') {
            $normalized = strtolower(trim($rawValue));
            return in_array($normalized, ['1', 'true', 'yes', 'on'], true);
        }

        if ($type === 'integer' || $type === 'int') {
            return intval($rawValue);
        }

        if ($type === 'number' || $type === 'float' || $type === 'double') {
            return floatval($rawValue);
        }

        if ($type === 'selectmultiple') {
            $decoded = json_decode($rawValue, true);
            return is_array($decoded) ? $decoded : [];
        }

        return $rawValue;
    }
}

if (!function_exists('dialecticLoadRawConfSchema')) {
    function dialecticLoadRawConfSchema(): array
    {
        static $schema = null;
        if (is_array($schema)) {
            return $schema;
        }

        $schemaPath = dirname(__DIR__) . DIRECTORY_SEPARATOR . "conf" . DIRECTORY_SEPARATOR . "conf_schema.json";
        $decoded = @json_decode(@file_get_contents($schemaPath), true);
        $schema = is_array($decoded) ? $decoded : [];
        return $schema;
    }
}

if (!function_exists('dialecticFlattenConfSchema')) {
    function dialecticFlattenConfSchema(?array $node = null, string $prefix = ''): array
    {
        if ($node === null) {
            $node = dialecticLoadRawConfSchema();
        }

        $flat = [];
        foreach ($node as $key => $value) {
            if (!is_array($value) || strpos(strval($key), '_') === 0) {
                continue;
            }

            $flatKey = ($prefix === '') ? strval($key) : ($prefix . '@' . strval($key));
            if (array_key_exists('type', $value)) {
                $flat[$flatKey] = $value;
            }

            foreach ($value as $childKey => $childValue) {
                if (strpos(strval($childKey), '_') === 0) {
                    continue;
                }

                if (is_array($childValue) && array_key_exists('type', $childValue)) {
                    $childFlatKey = $flatKey . '@' . strval($childKey);
                    $flat[$childFlatKey] = $childValue;
                } elseif (is_array($childValue)) {
                    $flat = array_merge($flat, dialecticFlattenConfSchema([$childKey => $childValue], $flatKey));
                }
            }
        }

        return $flat;
    }
}

if (!function_exists('dialecticGetSchemaDefinition')) {
    function dialecticGetSchemaDefinition(string $id): array
    {
        static $definitions = null;
        if (!is_array($definitions)) {
            $definitions = dialecticFlattenConfSchema();
        }

        return $definitions[$id] ?? [];
    }
}

if (!function_exists('dialecticGetSchemaDescription')) {
    function dialecticGetSchemaDescription(string $id): string
    {
        $definition = dialecticGetSchemaDefinition($id);
        return strval($definition['description'] ?? '');
    }
}

if (!function_exists('dialecticGetManagedGeneralSettingIds')) {
    function dialecticGetManagedGeneralSettingIds(): array
    {
        return [
            'AUTO_LOCK_PROFILE',
            'AUTOFILL_CUSTOM_PROFILES',
            'AUTOFILL_CUSTOM_PROFILES_TRIGGER',
            'CLEAN_CONTEXT_FOCUS_CHAT_HISTORY',
            'FEATURES@MEMORY_EMBEDDING@ENABLED',
            'FEATURES@MEMORY_EMBEDDING@USE_TEXT2VEC',
            'FEATURES@MEMORY_EMBEDDING@AUTO_CREATE_SUMMARY_INTERVAL',
            'FEATURES@MEMORY_EMBEDDING@AUTO_CREATE_SUMMARYS',
            'PROMPT_HEAD',
            'EMOTEMOODS',
            'LOCATION_BLACKLIST',
            'ITEM_BLACKLIST',
            'SHORTER_NEARBY_ITEM_LIST',
            'EVENT_TYPE_FILTER',
            'GROUND_ITEMS_DESCRIPTIONS_ONLY',
            'INVENTORY_ITEMS_DESCRIPTIONS_ONLY',
            'HIDE_AMBIENT_COMBAT',
            'PROMPT_TIMESTAMP',
            'COMPACT_NPC_CONTEXT_HISTORY',
            'PROMPT_CONTEXT_OPTIONS',
            'RECHAT_MODE',
            'ENFORCE_STRICT_RECHAT_RESPONSE',
            'CORE_CONNECTOR_PLAYER',
            'CORE_CONNECTOR_SUMMARY',
            'CORE_CONNECTOR_MEDIUMTERM',
            'CORE_CONNECTOR_SCENECLASSIFIER',
            'CORE_CONNECTOR_PROFILES',
            'CORE_CONNECTOR_DIRECTOR',
            'RELLLM_CONNECTOR',
            'CORE_CONNECTOR_WORLDKNOWLEDGE_CUSTOM',
            'RELATIONSHIP_SYSTEM_ENABLED',
            'SCENE_CLASSIFIER_ENABLED',
            'POWER_AWARENESS_ENABLED',
            'WORLDKNOWLEDGE_CUSTOM',
            'WORLDKNOWLEDGE_INFINIUM',
            'WORLDKNOWLEDGE_AMOUNT',
            'WORLDKNOWLEDGE_RESULT_LIMIT',
            'LOCATION_WORLDKNOWLEDGE',
            'RACE_WORLDKNOWLEDGE',
            'FACTION_WORLDKNOWLEDGE',
            'WORLDKNOWLEDGE_EXTRACTOR_FALLBACK',
            'WORLDKNOWLEDGE_EXTRACTOR_TIMEOUT_MS',
            'GLOBAL_ITT_CONNECTOR_ID',
            'VISUAL_CONTEXT_SCENE_TTL_MINUTES',
            'VISUAL_CONTEXT_PROMPT_MAX_CHARS',
            'PIPVISION_IMAGE_QUALITY',
            'PIPVISION_REQUEST_TIMEOUT_SECONDS',
        ];
    }
}

if (!function_exists('dialecticReadLegacyGlobalValue')) {
    function dialecticReadLegacyGlobalValue(string $flatId, $default = null)
    {
        if (strpos($flatId, '@') === false) {
            return array_key_exists($flatId, $GLOBALS) ? $GLOBALS[$flatId] : $default;
        }

        $parts = explode('@', $flatId);
        $cursor = $GLOBALS;
        foreach ($parts as $part) {
            if (!is_array($cursor) || !array_key_exists($part, $cursor)) {
                return $default;
            }
            $cursor = $cursor[$part];
        }

        return $cursor;
    }
}

if (!function_exists('dialecticGetManagedGeneralSettingDescriptions')) {
    function dialecticGetManagedGeneralSettingDescriptions(): array
    {
        $descriptions = [];
        foreach (dialecticGetManagedGeneralSettingIds() as $id) {
            $description = dialecticGetSchemaDescription($id);
            if ($description !== '') {
                $descriptions[$id] = $description;
            }
        }

        $descriptions['FEATURES@MEMORY_EMBEDDING@AUTO_CREATE_SUMMARYS'] = 'Controls whether automatic memory summaries are created by the memory embedding system.';
        $descriptions['PROMPT_CONTEXT_OPTIONS'] = 'Controls which XML-like prompt context sections are included in the final system prompt sent to the LLM. Managed from Global Settings.';
        $descriptions['GLOBAL_STT_CONNECTOR_ID'] = 'Active global STT connector. Only one STT connector is used globally for player speech-to-text.';
        $descriptions['GLOBAL_ITT_CONNECTOR_ID'] = 'Active global PipVision connector. Only one image-to-text connector is used for visual captures.';

        return $descriptions;
    }
}

if (!function_exists('dialecticPrettySettingLabel')) {
    function dialecticPrettySettingLabel(string $flatName): string
    {
        if (strpos($flatName, 'FEATURES@MEMORY_EMBEDDING@') === 0) {
            $parts = explode('@', $flatName);
            $last = end($parts) ?: $flatName;
            return ucwords(str_replace('_', ' ', strtolower(trim($last))));
        }

        $customLabels = [
            'CORE_CONNECTOR_PLAYER' => 'Player Respeech',
            'CORE_CONNECTOR_SUMMARY' => 'Summaries',
            'CORE_CONNECTOR_MEDIUMTERM' => 'Middle Term Memory',
            'CORE_CONNECTOR_SCENECLASSIFIER' => 'Scene Classifier',
            'SCENE_CLASSIFIER_ENABLED' => 'Scene Classifier',
            'CORE_CONNECTOR_PROFILES' => 'Dynamic Profile',
            'CORE_CONNECTOR_DIRECTOR' => 'Director Mode',
            'CORE_CONNECTOR_WORLDKNOWLEDGE_CUSTOM' => 'World Knowledge Fallback Connector',
            'WORLDKNOWLEDGE_INFINIUM' => 'World Knowledge Enabled',
            'WORLDKNOWLEDGE_AMOUNT' => 'World Knowledge Amount',
            'WORLDKNOWLEDGE_RESULT_LIMIT' => 'World Knowledge Result Limit',
            'LOCATION_WORLDKNOWLEDGE' => 'Force Location World Knowledge',
            'RACE_WORLDKNOWLEDGE' => 'Force Race / Species World Knowledge',
            'FACTION_WORLDKNOWLEDGE' => 'Force Faction World Knowledge',
            'WORLDKNOWLEDGE_EXTRACTOR_FALLBACK' => 'Explicit Request Fallback',
            'WORLDKNOWLEDGE_EXTRACTOR_TIMEOUT_MS' => 'Fallback Timeout (ms)',
            'RELLLM_CONNECTOR' => 'Relationship Management',
            'EMOTEMOODS' => 'Emote Moods',
            'RECHAT_H' => 'Rechat Rounds',
            'RECHAT_P' => 'Rechat Probability',
            'RECHAT_MODE' => 'Rechat Mode',
            'ENFORCE_STRICT_RECHAT_RESPONSE' => 'Strict Rechat Targeting',
            'RECHAT_ALLOW_ACTIONS' => 'Allow Rechat Actions',
            'SHORTER_NEARBY_ITEM_LIST' => 'Shorter Nearby Item List',
            'CLEAN_CONTEXT_FOCUS_CHAT_HISTORY' => 'Focus Chat Context',
            'GLOBAL_STT_CONNECTOR_ID' => 'Speech To Text Connector',
            'GLOBAL_ITT_CONNECTOR_ID' => 'PipVision Connector',
            'VISUAL_CONTEXT_SCENE_TTL_MINUTES' => 'PipVision Scene Lifetime',
            'VISUAL_CONTEXT_PROMPT_MAX_CHARS' => 'PipVision Prompt Limit',
            'PIPVISION_IMAGE_QUALITY' => 'PipVision Image Quality',
            'PIPVISION_REQUEST_TIMEOUT_SECONDS' => 'PipVision Request Timeout',
        ];
        if (isset($customLabels[$flatName])) {
            return $customLabels[$flatName];
        }

        $parts = explode('@', $flatName);
        $prettyParts = [];
        foreach ($parts as $part) {
            $prettyParts[] = ucwords(str_replace('_', ' ', strtolower(trim($part))));
        }
        return implode(' -> ', $prettyParts);
    }
}

if (!function_exists('dialecticGetOverrideableGeneralSettingCategory')) {
    function dialecticGetOverrideableGeneralSettingCategory(string $flatId): string
    {
        if (
            strpos($flatId, 'PIPVISION_') === 0
            || strpos($flatId, 'VISUAL_CONTEXT_') === 0
            || $flatId === 'GLOBAL_ITT_CONNECTOR_ID'
        ) {
            return 'PipVision';
        }

        if (in_array($flatId, ['WORLDKNOWLEDGE_INFINIUM', 'WORLDKNOWLEDGE_AMOUNT', 'WORLDKNOWLEDGE_RESULT_LIMIT', 'LOCATION_WORLDKNOWLEDGE', 'RACE_WORLDKNOWLEDGE', 'FACTION_WORLDKNOWLEDGE', 'WORLDKNOWLEDGE_EXTRACTOR_FALLBACK', 'WORLDKNOWLEDGE_EXTRACTOR_TIMEOUT_MS', 'WORLDKNOWLEDGE_CUSTOM', 'CORE_CONNECTOR_WORLDKNOWLEDGE_CUSTOM'], true)) {
            return 'World Knowledge';
        }

        if (
            strpos($flatId, 'PROMPT_') === 0
            || in_array($flatId, ['EMOTEMOODS', 'LOCATION_BLACKLIST', 'ITEM_BLACKLIST', 'EVENT_TYPE_FILTER'], true)
        ) {
            return 'Prompt';
        }

        if (strpos($flatId, 'RECHAT') === 0) {
            return 'Rechat';
        }

        if (strpos($flatId, 'FEATURES@MEMORY_EMBEDDING@') === 0) {
            return 'Memory';
        }

        if (
            strpos($flatId, 'CORE_CONNECTOR_') === 0
            || in_array($flatId, ['RELLLM_CONNECTOR', 'GLOBAL_STT_CONNECTOR_ID'], true)
        ) {
            return 'Global Connectors';
        }

        if (
            in_array($flatId, [
                'GROUND_ITEMS_DESCRIPTIONS_ONLY',
                'INVENTORY_ITEMS_DESCRIPTIONS_ONLY',
                'HIDE_AMBIENT_COMBAT',
                'POWER_AWARENESS_ENABLED',
                'SCENE_CLASSIFIER_ENABLED',
                'RELATIONSHIP_SYSTEM_ENABLED',
            ], true)
        ) {
            return 'Context';
        }

        return 'Misc';
    }
}

if (!function_exists('dialecticGetSelectOptionsForOverrideSetting')) {
    function dialecticGetSelectOptionsForOverrideSetting(string $flatId): array
    {
        $db = dialecticSettingsDb();
        if (!$db) {
            return [];
        }

        $definitions = [
            'GLOBAL_STT_CONNECTOR_ID' => [
                'query' => "SELECT id, COALESCE(NULLIF(label, ''), NULLIF(driver, ''), CAST(id AS text)) AS option_label FROM public.core_stt_connector ORDER BY id ASC",
            ],
            'GLOBAL_ITT_CONNECTOR_ID' => [
                'query' => "SELECT id, COALESCE(NULLIF(label, ''), NULLIF(driver, ''), CAST(id AS text)) AS option_label FROM public.core_itt_connector ORDER BY id ASC",
            ],
        ];

        if (strpos($flatId, 'CORE_CONNECTOR_') === 0 || $flatId === 'RELLLM_CONNECTOR') {
            $definitions[$flatId] = [
                'query' => "SELECT id, COALESCE(NULLIF(label, ''), NULLIF(model, ''), CAST(id AS text)) AS option_label FROM public.core_llm_connector ORDER BY LOWER(COALESCE(NULLIF(label, ''), model)) ASC",
            ];
        }

        if (!isset($definitions[$flatId])) {
            return [];
        }

        try {
            $rows = $db->fetchAll($definitions[$flatId]['query']);
        } catch (\Throwable $e) {
            return [];
        }

        $values = [];
        $valueLabels = [];
        foreach ((array)$rows as $row) {
            $id = strval($row['id'] ?? '');
            if ($id === '') {
                continue;
            }

            $values[] = $id;
            $valueLabels[$id] = strval($row['option_label'] ?? $id);
        }

        return [
            'values' => $values,
            'value_labels' => $valueLabels,
        ];
    }
}

if (!function_exists('dialecticGetOverrideableGeneralSettingsCatalog')) {
    function dialecticGetOverrideableGeneralSettingsCatalog(): array
    {
        $descriptions = dialecticGetManagedGeneralSettingDescriptions();
        $rowMap = [];
        foreach (dialecticGetAllGeneralSettings() as $row) {
            $id = strval($row['id'] ?? '');
            if ($id !== '') {
                $rowMap[$id] = $row;
            }
        }

        $candidateIds = array_values(array_unique(array_merge(
            dialecticGetManagedGeneralSettingIds(),
            ['GLOBAL_STT_CONNECTOR_ID', 'GLOBAL_ITT_CONNECTOR_ID'],
            array_keys($rowMap)
        )));

        $profileNativeIds = [
            'RECHAT_H',
            'RECHAT_P',
            'RECHAT_ALLOW_ACTIONS',
        ];
        $managedIds = array_flip(dialecticGetManagedGeneralSettingIds());
        $explicitOverrideIds = ['GLOBAL_STT_CONNECTOR_ID' => true, 'GLOBAL_ITT_CONNECTOR_ID' => true];

        $catalog = [];
        foreach ($candidateIds as $id) {
            if (in_array($id, $profileNativeIds, true)) {
                continue;
            }

            $isManagedOrExplicit = isset($managedIds[$id]) || isset($explicitOverrideIds[$id]);
            $definition = dialecticGetSchemaDefinition($id);
            if (!$isManagedOrExplicit && empty($definition)) {
                continue;
            }

            $type = strtolower(trim(strval($definition['type'] ?? '')));
            if ($type === '') {
                $currentValue = strval($rowMap[$id]['value'] ?? '');
                if (in_array(strtolower($currentValue), ['true', 'false'], true)) {
                    $type = 'boolean';
                } elseif ($currentValue !== '' && preg_match('/^-?\d+$/', $currentValue)) {
                    $type = 'integer';
                } else {
                    $type = 'string';
                }
            }

            if ($type === 'selectmultiple') {
                continue;
            }

            if (in_array($type, ['int'], true)) {
                $type = 'integer';
            } elseif (in_array($type, ['float', 'double'], true)) {
                $type = 'number';
            } elseif ($type === 'url') {
                $type = 'string';
            }

            $entry = [
                'type' => $type,
                'description' => trim(strval($rowMap[$id]['description'] ?? ($descriptions[$id] ?? dialecticGetSchemaDescription($id)))),
                'category' => dialecticGetOverrideableGeneralSettingCategory($id),
                'ui_label' => dialecticPrettySettingLabel($id),
            ];

            if (!empty($definition['values']) && is_array($definition['values'])) {
                $entry['values'] = array_map('strval', $definition['values']);
            }

            $selectOptions = dialecticGetSelectOptionsForOverrideSetting($id);
            if (!empty($selectOptions['values'])) {
                $entry['type'] = 'select';
                $entry['values'] = $selectOptions['values'];
                $entry['value_labels'] = $selectOptions['value_labels'];
            }

            $catalog[$id] = $entry;
        }

        ksort($catalog);
        return $catalog;
    }
}

if (!function_exists('dialecticGetPromptContextOptionCatalog')) {
    function dialecticGetPromptContextOptionCatalog(): array
    {
        return [
            'enabled_sections' => [
                'roleplay_instructions' => [
                    'label' => '<roleplay_instructions>',
                    'description' => 'Core roleplay rules, system preamble, and scene-director framing.',
                ],
                'world' => [
                    'label' => '<world>',
                    'description' => 'Current location, hold, weather, date, and time context.',
                ],
                'knowledge' => [
                    'label' => '<knowledge>',
                    'description' => 'Injected WorldKnowledge or lore knowledge for the active subject.',
                ],
                'available_actions_list' => [
                    'label' => '<available_actions_list>',
                    'description' => 'Available in-game actions the actor may choose from.',
                ],
                'nearby_actors' => [
                    'label' => '<nearby_actors>',
                    'description' => 'Nearby NPCs, creatures, and party members in the current scene.',
                ],
                'actors_nearby' => [
                    'label' => '<actors_nearby>',
                    'description' => 'Compact nearby actor list generated from current scene awareness.',
                ],
                'group_descriptions' => [
                    'label' => '<group_descriptions>',
                    'description' => 'Faction and group descriptions for nearby actors.',
                ],
                'nearby_items' => [
                    'label' => '<nearby_items>',
                    'description' => 'Ground items and item descriptions near the actor.',
                ],
                'adventuring_party' => [
                    'label' => '<adventuring_party>',
                    'description' => 'Companion-party framing and who counts as part of the active group.',
                ],
                'points_of_interest' => [
                    'label' => '<points_of_interest>',
                    'description' => 'Nearby doors, passages, and notable destinations.',
                ],
                'scene_notes' => [
                    'label' => '<scene_notes>',
                    'description' => 'Temporary director or rolemaster scene notes.',
                ],
                'paralinguistic_tags' => [
                    'label' => '<paralinguistic_tags>',
                    'description' => 'TTS-specific paralinguistic tag guidance when enabled.',
                ],
            ],
            'enabled_character_subsections' => [
                'basic_summary' => [
                    'label' => '<basic_summary>',
                    'description' => 'Core background summary or short biography.',
                ],
                'groups' => [
                    'label' => '<groups>',
                    'description' => 'Faction membership summary inside the character sheet.',
                ],
                'personality' => [
                    'label' => '<personality>',
                    'description' => 'Behavioral traits, psychology, and temperament.',
                ],
                'relationships' => [
                    'label' => '<relationships>',
                    'description' => 'Named relationships and relevant social ties.',
                ],
                'occupation' => [
                    'label' => '<occupation>',
                    'description' => 'Job, societal role, or current profession.',
                ],
                'skills' => [
                    'label' => '<skills>',
                    'description' => 'Narrative skills, talents, and expertise.',
                ],
                'rpg_skills' => [
                    'label' => '<rpg_skills>',
                    'description' => 'RPG-style skill proficiencies and levels.',
                ],
                'speech_style' => [
                    'label' => '<speech_style>',
                    'description' => 'Speaking style and communication habits.',
                ],
                'goals' => [
                    'label' => '<goals>',
                    'description' => 'Current ambitions, motivations, and long-term aims.',
                ],
                'middle_term_memory' => [
                    'label' => '<middle_term_memory>',
                    'description' => 'Longer-range memory summary.',
                ],
                'group' => [
                    'label' => '<group>',
                    'description' => 'Profile-level group membership prompt fragment.',
                ],
                'storyline_starring' => [
                    'label' => '<storyline_starring>',
                    'description' => 'Quest or storyline currently starring this actor.',
                ],
                'quest_topics' => [
                    'label' => '<quest_topics>',
                    'description' => 'Quest topics this actor specifically knows about.',
                ],
            ],
            'enabled_appearance_subsections' => [
                'appearance' => [
                    'label' => '<appearance>',
                    'description' => 'Physical appearance and identifying features.',
                ],
                'equipment' => [
                    'label' => '<equipment>',
                    'description' => 'Currently equipped gear and worn items.',
                ],
                'target_equipment' => [
                    'label' => '<target_equipment>',
                    'description' => 'Equipment summary for the current dialogue target when available.',
                ],
                'inventory' => [
                    'label' => '<inventory>',
                    'description' => 'Inventory listing.',
                ],
                'current_activity' => [
                    'label' => '<activity>',
                    'description' => 'What the actor is doing.',
                ],
                'current_condition' => [
                    'label' => '<condition>',
                    'description' => 'Health, action points, karma, visible condition, and player survival needs.',
                ],
            ],
            'enabled_general_subsections' => [
                'active_quests' => [
                    'label' => '<active_quests>',
                    'description' => 'Current active quest list.',
                ],
            ],
            'enabled_nearby_actor_subsections' => [
                'basic_summary' => [
                    'label' => 'Basic summary',
                    'description' => 'Nearby actor profile summary or short biography.',
                ],
                'appearance' => [
                    'label' => 'Appearance',
                    'description' => 'Nearby actor physical appearance and visible traits.',
                ],
                'equipment' => [
                    'label' => 'Equipment',
                    'description' => 'Nearby actor currently equipped gear and worn items.',
                ],
                'equipment_descriptions' => [
                    'label' => 'Equipment descriptions',
                    'description' => 'Adds item descriptions to nearby actor equipment when available.',
                ],
                'current_activity' => [
                    'label' => 'Current activity',
                    'description' => 'What nearby actors are currently doing.',
                ],
                'power_awareness' => [
                    'label' => 'Power awareness',
                    'description' => 'Relative strength assessment for nearby actors when power awareness is enabled.',
                ],
                'factions' => [
                    'label' => 'Factions',
                    'description' => 'Faction names and group descriptions for nearby actors.',
                ],
                'custom_state' => [
                    'label' => 'Plugin state',
                    'description' => 'Custom plugin state attached to nearby actor profile lines.',
                ],
            ],
        ];
    }
}

if (!function_exists('dialecticGetDefaultPromptContextOptions')) {
    function dialecticGetDefaultPromptContextOptions(): array
    {
        $catalog = dialecticGetPromptContextOptionCatalog();
        $defaults = [];
        foreach ($catalog as $bucket => $options) {
            $defaults[$bucket] = array_keys($options);
        }

        return $defaults;
    }
}

if (!function_exists('dialecticNormalizePromptContextOptions')) {
    function dialecticNormalizePromptContextOptions($rawOptions): array
    {
        $catalog = dialecticGetPromptContextOptionCatalog();
        $defaults = dialecticGetDefaultPromptContextOptions();

        if (is_string($rawOptions) && trim($rawOptions) !== '') {
            $decoded = json_decode($rawOptions, true);
            if (is_array($decoded)) {
                $rawOptions = $decoded;
            }
        }

        if (!is_array($rawOptions)) {
            return $defaults;
        }

        $appearanceSubsectionIds = [
            'appearance',
            'equipment',
            'inventory',
            'current_activity',
            'current_condition',
        ];
        $normalized = [];
        foreach ($defaults as $bucket => $defaultIds) {
            $hasBucket = array_key_exists($bucket, $rawOptions);
            $rawIds = $hasBucket ? $rawOptions[$bucket] : $defaultIds;
            if (
                !$hasBucket
                && $bucket === 'enabled_appearance_subsections'
                && isset($rawOptions['enabled_character_subsections'])
                && is_array($rawOptions['enabled_character_subsections'])
            ) {
                $characterIds = array_values(array_map('strval', $rawOptions['enabled_character_subsections']));
                $rawIds = $defaultIds;
                foreach ($appearanceSubsectionIds as $subsectionId) {
                    if (!in_array($subsectionId, $characterIds, true)) {
                        $rawIds = array_values(array_diff($rawIds, [$subsectionId]));
                    }
                }
            }
            if ($hasBucket && !is_array($rawIds)) {
                $rawIds = [];
            } elseif (!$hasBucket && !is_array($rawIds)) {
                $rawIds = $defaultIds;
            }

            $allowedIds = array_keys($catalog[$bucket] ?? []);
            $enabled = [];
            foreach ($rawIds as $id) {
                $id = strval($id);
                if ($bucket === 'enabled_sections' && $id === 'traveling_party') {
                    $id = 'adventuring_party';
                }
                if ($id !== '' && in_array($id, $allowedIds, true) && !in_array($id, $enabled, true)) {
                    $enabled[] = $id;
                }
            }
            if (
                $hasBucket
                && $bucket === 'enabled_sections'
                && in_array('actors_nearby', $allowedIds, true)
                && !in_array('actors_nearby', $enabled, true)
            ) {
                $enabled[] = 'actors_nearby';
            }

            $normalized[$bucket] = $hasBucket
                ? $enabled
                : (!empty($enabled) ? $enabled : $defaultIds);
        }

        return $normalized;
    }
}

if (!function_exists('dialecticGetPromptContextOptions')) {
    function dialecticGetPromptContextOptions(): array
    {
        $rawValue = dialecticGetGeneralSetting('PROMPT_CONTEXT_OPTIONS', '');
        return dialecticNormalizePromptContextOptions($rawValue);
    }
}

if (!function_exists('dialecticPromptContextOptionEnabled')) {
    function dialecticPromptContextOptionEnabled(string $bucket, string $id): bool
    {
        $options = dialecticGetPromptContextOptions();
        $enabled = $options[$bucket] ?? [];
        return in_array($id, $enabled, true);
    }
}

if (!function_exists('dialecticGetGeneralSettingRow')) {
    function dialecticGetGeneralSettingRow(string $id): array
    {
        $db = dialecticSettingsDb();
        if (!$db) {
            return [];
        }

        $safeId = trim($id);
        if ($safeId === '') {
            return [];
        }

        $query = "SELECT id, value, description, updated_at FROM public.general_settings WHERE id = " . $db->escapeLiteral($safeId) . " LIMIT 1";
        $row = $db->fetchOne($query);
        return is_array($row) ? $row : [];
    }
}

if (!function_exists('dialecticGetGeneralSetting')) {
    function dialecticGetGeneralSetting(string $id, string $default = ''): string
    {
        $row = dialecticGetGeneralSettingRow($id);
        if (!$row) {
            return $default;
        }

        return strval($row['value'] ?? $default);
    }
}

if (!function_exists('dialecticGetGeneralSettingBool')) {
    function dialecticGetGeneralSettingBool(string $id, bool $default = false): bool
    {
        $value = dialecticGetGeneralSetting($id, $default ? 'true' : 'false');
        return (bool)dialecticSettingsNormalizeScalar($value, ['type' => 'boolean']);
    }
}

if (!function_exists('dialecticGetGeneralSettingInt')) {
    function dialecticGetGeneralSettingInt(string $id, int $default = 0): int
    {
        $value = dialecticGetGeneralSetting($id, strval($default));
        return intval(dialecticSettingsNormalizeScalar($value, ['type' => 'integer']));
    }
}

if (!function_exists('dialecticGetGeneralSettingFloat')) {
    function dialecticGetGeneralSettingFloat(string $id, float $default = 0.0): float
    {
        $value = dialecticGetGeneralSetting($id, strval($default));
        return floatval(dialecticSettingsNormalizeScalar($value, ['type' => 'number']));
    }
}

if (!function_exists('dialecticGetAllGeneralSettings')) {
    function dialecticGetAllGeneralSettings(): array
    {
        $db = dialecticSettingsDb();
        if (!$db) {
            return [];
        }

        try {
            $rows = $db->fetchAll("SELECT id, value, description, updated_at FROM public.general_settings ORDER BY id ASC");
        } catch (\Throwable $e) {
            return [];
        }

        return is_array($rows) ? $rows : [];
    }
}

if (!function_exists('dialecticSetGeneralSetting')) {
    function dialecticSetGeneralSetting(string $id, $value, ?string $description = null): bool
    {
        $db = dialecticSettingsDb();
        if (!$db) {
            return false;
        }

        $safeId = trim($id);
        if ($safeId === '') {
            return false;
        }

        $valueLiteral = $db->escapeLiteral(dialecticSettingsStringifyValue($value));
        $descriptionSql = ($description === null)
            ? "description"
            : $db->escapeLiteral($description);

        $query = "
            INSERT INTO public.general_settings (id, value, description, updated_at)
            VALUES (" . $db->escapeLiteral($safeId) . ", {$valueLiteral}, " . (($description === null) ? "''" : $descriptionSql) . ", CURRENT_TIMESTAMP)
            ON CONFLICT (id) DO UPDATE SET
                value = EXCLUDED.value,
                description = " . (($description === null) ? "public.general_settings.description" : "EXCLUDED.description") . ",
                updated_at = CURRENT_TIMESTAMP
        ";

        return $db->execQuery($query) !== false;
    }
}

if (!function_exists('dialecticSetGeneralSettingDescription')) {
    function dialecticSetGeneralSettingDescription(string $id, string $description): bool
    {
        $db = dialecticSettingsDb();
        if (!$db) {
            return false;
        }

        $safeId = trim($id);
        if ($safeId === '') {
            return false;
        }

        $query = "
            INSERT INTO public.general_settings (id, value, description, updated_at)
            VALUES (" . $db->escapeLiteral($safeId) . ", '', " . $db->escapeLiteral($description) . ", CURRENT_TIMESTAMP)
            ON CONFLICT (id) DO UPDATE SET
                description = EXCLUDED.description,
                updated_at = CURRENT_TIMESTAMP
        ";

        return $db->execQuery($query) !== false;
    }
}

if (!function_exists('dialecticGeneralSettingsToRuntimeGlobals')) {
    function dialecticGeneralSettingsToRuntimeGlobals(array $rows): void
    {
        foreach ($rows as $row) {
            if (!is_array($row) || !isset($row['id'])) {
                continue;
            }

            $flatId = strval($row['id']);
            $rawValue = strval($row['value'] ?? '');
            $definition = dialecticGetSchemaDefinition($flatId);
            $normalizedValue = dialecticSettingsNormalizeScalar($rawValue, $definition);

            if (strpos($flatId, '@') === false) {
                $GLOBALS[$flatId] = $normalizedValue;
                continue;
            }

            $parts = explode('@', $flatId);
            dialecticAssignNestedGlobalValueToGlobals($parts, $normalizedValue);
        }
    }
}

if (!function_exists('dialecticAssignNestedGlobalValueToGlobals')) {
    function dialecticAssignNestedGlobalValueToGlobals(array $parts, $value): void
    {
        if (empty($parts)) {
            return;
        }

        $rootKey = strval(array_shift($parts));
        if ($rootKey === '') {
            return;
        }

        if (empty($parts)) {
            $GLOBALS[$rootKey] = $value;
            return;
        }

        if (!isset($GLOBALS[$rootKey]) || !is_array($GLOBALS[$rootKey])) {
            $GLOBALS[$rootKey] = [];
        }

        $cursor =& $GLOBALS[$rootKey];
        $lastIndex = count($parts) - 1;
        foreach ($parts as $index => $part) {
            $part = strval($part);
            if ($part === '') {
                return;
            }

            if ($index === $lastIndex) {
                $cursor[$part] = $value;
                return;
            }

            if (!isset($cursor[$part]) || !is_array($cursor[$part])) {
                $cursor[$part] = [];
            }
            $cursor =& $cursor[$part];
        }
    }
}

if (!function_exists('dialecticApplyOverrideValueToGlobals')) {
    function dialecticApplyOverrideValueToGlobals(string $rawKey, $value): void
    {
        $rawKey = trim($rawKey);
        if ($rawKey === '') {
            return;
        }

        $schemaKey = strpos($rawKey, '@') !== false
            ? $rawKey
            : str_replace(' ', '@', $rawKey);
        $definition = dialecticGetSchemaDefinition($schemaKey);
        if (!empty($definition)) {
            $value = dialecticSettingsNormalizeScalar(dialecticSettingsStringifyValue($value), $definition);
        } elseif ($value === 'true') {
            $value = true;
        } elseif ($value === 'false') {
            $value = false;
        }

        if (strpos($rawKey, '@') !== false) {
            dialecticAssignNestedGlobalValueToGlobals(explode('@', $rawKey), $value);
            return;
        }

        if (strpos($rawKey, ' ') !== false) {
            dialecticAssignNestedGlobalValueToGlobals(explode(' ', $rawKey), $value);
            return;
        }

        $GLOBALS[$rawKey] = $value;
    }
}

if (!function_exists('dialecticAssignNestedGlobalValue')) {
    function dialecticAssignNestedGlobalValue(array &$target, array $parts, $value, int $index = 0): void
    {
        $part = strval($parts[$index] ?? '');
        if ($part === '') {
            return;
        }

        if ($index >= (count($parts) - 1)) {
            $target[$part] = $value;
            return;
        }

        if (!isset($target[$part]) || !is_array($target[$part])) {
            $target[$part] = [];
        }

        dialecticAssignNestedGlobalValue($target[$part], $parts, $value, $index + 1);
    }
}

if (!function_exists('dialecticLoadGeneralSettingsIntoGlobals')) {
    function dialecticLoadGeneralSettingsIntoGlobals(): void
    {
        try {
            $rows = dialecticGetAllGeneralSettings();
        } catch (\Throwable $e) {
            $rows = [];
        }

        if (!empty($rows)) {
            dialecticGeneralSettingsToRuntimeGlobals($rows);
        }
    }
}

if (!function_exists('dialecticLoadActiveSttConnectorIntoGlobals')) {
    function dialecticLoadActiveSttConnectorIntoGlobals(): void
    {
        $connectorId = dialecticGetGeneralSettingInt('GLOBAL_STT_CONNECTOR_ID', 0);
        if ($connectorId <= 0) {
            return;
        }

        if (!class_exists('STTConnector')) {
            require_once(__DIR__ . DIRECTORY_SEPARATOR . "core" . DIRECTORY_SEPARATOR . "stt_connector.class.php");
        }

        $connector = new STTConnector();
        try {
            $row = $connector->getById($connectorId);
        } catch (\Throwable $e) {
            $row = [];
        }
        if ($row) {
            $connector->setOldGlobals($row);
        }
    }
}

if (!function_exists('dialecticLoadActiveIttConnectorIntoGlobals')) {
    function dialecticLoadActiveIttConnectorIntoGlobals(): void
    {
        $connectorId = dialecticGetGeneralSettingInt('GLOBAL_ITT_CONNECTOR_ID', 0);
        if ($connectorId <= 0) {
            return;
        }

        if (!class_exists('ITTConnector')) {
            require_once(__DIR__ . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'itt_connector.class.php');
        }

        try {
            $row = (new ITTConnector())->getById($connectorId);
        } catch (Throwable $e) {
            $row = null;
        }
        if ($row) {
            (new ITTConnector())->setOldGlobals($row);
        }
    }
}

if (!function_exists('dialecticResolvePreferredTtsConnectorRow')) {
    function dialecticResolvePreferredTtsConnectorRow(string $driver = ''): ?array
    {
        if (!class_exists('TTSConnector')) {
            require_once(__DIR__ . DIRECTORY_SEPARATOR . "core" . DIRECTORY_SEPARATOR . "tts_connector.class.php");
        }

        $connector = new TTSConnector();
        $normalizedDriver = $driver !== '' ? $connector->normalizeDriverValue($driver) : '';
        $rows = $connector->readAll();
        if (!is_array($rows) || empty($rows)) {
            return null;
        }

        if ($normalizedDriver !== '') {
            $rows = array_values(array_filter($rows, static function ($row) use ($connector, $normalizedDriver) {
                return $connector->normalizeDriverValue($row['driver'] ?? '') === $normalizedDriver;
            }));
            if (empty($rows)) {
                return null;
            }
        }

        $profileUsageMap = [];
        try {
            $usageRows = $GLOBALS["db"]->fetchAll(
                "SELECT tts_connector_id, COUNT(*) AS c
                 FROM core_profiles
                 WHERE tts_connector_id IS NOT NULL
                 GROUP BY tts_connector_id"
            );
            foreach ($usageRows as $usageRow) {
                $profileUsageMap[intval($usageRow['tts_connector_id'] ?? 0)] = intval($usageRow['c'] ?? 0);
            }
        } catch (\Throwable $e) {
        }

        $playerConnectorId = 0;
        try {
            $playerRow = $GLOBALS["db"]->fetchOne("SELECT value FROM core_player WHERE id = 'tts_connector_id' LIMIT 1");
            if (is_array($playerRow)) {
                $playerConnectorId = intval($playerRow['value'] ?? 0);
            }
        } catch (\Throwable $e) {
        }

        usort($rows, static function ($a, $b) use ($profileUsageMap, $playerConnectorId) {
            $aId = intval($a['id'] ?? 0);
            $bId = intval($b['id'] ?? 0);

            $aIsPlayer = ($aId > 0 && $aId === $playerConnectorId) ? 1 : 0;
            $bIsPlayer = ($bId > 0 && $bId === $playerConnectorId) ? 1 : 0;
            if ($aIsPlayer !== $bIsPlayer) {
                return $bIsPlayer <=> $aIsPlayer;
            }

            $aUsage = $profileUsageMap[$aId] ?? 0;
            $bUsage = $profileUsageMap[$bId] ?? 0;
            if ($aUsage !== $bUsage) {
                return $bUsage <=> $aUsage;
            }

            $aLabel = strtolower(trim(strval($a['label'] ?? '')));
            $bLabel = strtolower(trim(strval($b['label'] ?? '')));
            if ($aLabel !== $bLabel) {
                return $aLabel <=> $bLabel;
            }

            return $aId <=> $bId;
        });

        return $connector->getById(intval($rows[0]['id'] ?? 0)) ?: null;
    }
}

if (!function_exists('dialecticLoadPreferredTtsConnectorIntoGlobals')) {
    function dialecticLoadPreferredTtsConnectorIntoGlobals(string $driver = ''): void
    {
        if (!class_exists('TTSConnector')) {
            require_once(__DIR__ . DIRECTORY_SEPARATOR . "core" . DIRECTORY_SEPARATOR . "tts_connector.class.php");
        }

        $row = dialecticResolvePreferredTtsConnectorRow($driver);
        if (!$row) {
            return;
        }

        $connector = new TTSConnector();
        $connector->setOldGlobals($row);
    }
}

if (!function_exists('dialecticEnsureTtsConnectorGlobals')) {
    function dialecticEnsureTtsConnectorGlobals(string $driver): void
    {
        if (!class_exists('TTSConnector')) {
            require_once(__DIR__ . DIRECTORY_SEPARATOR . "core" . DIRECTORY_SEPARATOR . "tts_connector.class.php");
        }

        $connector = new TTSConnector();
        $normalizedDriver = $connector->normalizeDriverValue($driver);
        if ($normalizedDriver === '') {
            return;
        }

        $currentDriver = $connector->normalizeDriverValue($GLOBALS["TTSFUNCTION"] ?? '');
        $providerKey = $connector->getProviderKeyFromDriver($normalizedDriver);
        $hasProviderGlobals = ($providerKey !== '' && !empty($GLOBALS["TTS"][$providerKey]) && is_array($GLOBALS["TTS"][$providerKey]));
        if ($currentDriver === $normalizedDriver && $hasProviderGlobals) {
            return;
        }

        dialecticLoadPreferredTtsConnectorIntoGlobals($normalizedDriver);
    }
}

if (!function_exists('dialecticLoadPlayerNameIntoGlobals')) {
    function dialecticLoadPlayerNameIntoGlobals(): void
    {
        if (!class_exists('Player')) {
            require_once(__DIR__ . DIRECTORY_SEPARATOR . "core" . DIRECTORY_SEPARATOR . "player.class.php");
        }

        try {
            $player = new Player();
            $playerNameFromTable = $player->get('player_name');
            if ($playerNameFromTable !== null && $playerNameFromTable !== '') {
                $GLOBALS["PLAYER_NAME"] = $playerNameFromTable;
                return;
            }
        } catch (\Throwable $e) {
        }

        $db = dialecticSettingsDb();
        if (!$db) {
            return;
        }

        try {
            $playerNameFromDb = $db->fetchOne("SELECT value FROM conf_opts WHERE id='PLAYER_NAME'");
        } catch (\Throwable $e) {
            $playerNameFromDb = [];
        }

        if ($playerNameFromDb && !empty($playerNameFromDb['value'])) {
            $GLOBALS["PLAYER_NAME"] = $playerNameFromDb['value'];
        }
    }
}

if (!function_exists('dialecticLoadNarratorSettingsIntoGlobals')) {
    function dialecticLoadNarratorSettingsIntoGlobals(): void
    {
        if (!class_exists('Narrator')) {
            require_once(__DIR__ . DIRECTORY_SEPARATOR . "core" . DIRECTORY_SEPARATOR . "narrator.class.php");
        }

        try {
            $narrator = new Narrator();
            $narrator->loadIntoGlobals();
        } catch (\Throwable $e) {
        }
    }
}

if (!function_exists('dialecticNormalizeDetectedPlayerName')) {
    function dialecticNormalizeDetectedPlayerName($candidateName): ?string
    {
        if (!is_string($candidateName)) return null;
        $candidate = trim($candidateName);
        $candidate = trim($candidate, " \t\r\n\"'{}[]()");
        $candidate = preg_replace('/\s+/u', ' ', $candidate);
        $candidate = is_string($candidate) ? trim($candidate) : '';
        if ($candidate === '') return null;

        $invalid = [
            'The Narrator',
            'Player',
            'Courier',
            'Prisoner',
            'Unknown',
            'Unknown Player',
            'null',
            'none',
            '{}',
            '[]',
        ];
        foreach ($invalid as $placeholder) {
            if (strcasecmp($candidate, $placeholder) === 0) {
                return null;
            }
        }

        if (strlen($candidate) > 80) {
            return null;
        }

        return $candidate;
    }
}

if (!function_exists('dialecticSeedMissingManagedGeneralSettings')) {
    function dialecticSeedMissingManagedGeneralSettings(): array
    {
        $result = [
            'inserted' => 0,
            'normalized' => 0,
            'missing' => [],
        ];
        $managedDescriptions = dialecticGetManagedGeneralSettingDescriptions();
        $missingValue = new \stdClass();
        $fallbacks = [
            'CORE_CONNECTOR_WORLDKNOWLEDGE_CUSTOM' => 0,
            'RELATIONSHIP_SYSTEM_ENABLED' => true,
            'PROMPT_CONTEXT_OPTIONS' => dialecticGetDefaultPromptContextOptions(),
        ];

        foreach (dialecticGetManagedGeneralSettingIds() as $settingId) {
            $existingRow = dialecticGetGeneralSettingRow($settingId);
            if ($existingRow) {
                if ($settingId === 'PROMPT_CONTEXT_OPTIONS') {
                    $normalized = dialecticNormalizePromptContextOptions(strval($existingRow['value'] ?? ''));
                    $normalizedValue = dialecticSettingsStringifyValue($normalized);
                    if ($normalizedValue !== strval($existingRow['value'] ?? '')) {
                        $description = $managedDescriptions[$settingId] ?? dialecticGetSchemaDescription($settingId);
                        if (!dialecticSetGeneralSetting($settingId, $normalized, $description)) {
                            $result['missing'][] = $settingId;
                        } else {
                            $result['normalized']++;
                        }
                    }
                }
                continue;
            }

            $definition = dialecticGetSchemaDefinition($settingId);
            $value = dialecticReadLegacyGlobalValue($settingId, $missingValue);
            if ($value === $missingValue) {
                if (array_key_exists('default', $definition)) {
                    $value = $definition['default'];
                } elseif (array_key_exists($settingId, $fallbacks)) {
                    $value = $fallbacks[$settingId];
                } else {
                    $result['missing'][] = $settingId;
                    continue;
                }
            }

            $description = $managedDescriptions[$settingId] ?? dialecticGetSchemaDescription($settingId);
            if (!dialecticSetGeneralSetting($settingId, $value, $description)) {
                $result['missing'][] = $settingId;
                continue;
            }
            $result['inserted']++;
        }

        return $result;
    }
}

if (!function_exists('dialecticExtractPlayerNameFromGamePayload')) {
    function dialecticExtractPlayerNameFromGamePayload($payload): ?string
    {
        if (is_array($payload)) {
            foreach (['player_name', 'playerName', 'player', 'name', 'Name'] as $key) {
                if (array_key_exists($key, $payload)) {
                    if (!is_scalar($payload[$key])) {
                        continue;
                    }
                    $candidate = dialecticNormalizeDetectedPlayerName(strval($payload[$key]));
                    if ($candidate !== null) {
                        return $candidate;
                    }
                }
            }
            foreach (['player', 'identity'] as $key) {
                if (isset($payload[$key]) && is_array($payload[$key])) {
                    $candidate = dialecticExtractPlayerNameFromGamePayload($payload[$key]);
                    if ($candidate !== null) {
                        return $candidate;
                    }
                }
            }
            return null;
        }

        if (!is_string($payload)) {
            return null;
        }

        $raw = trim($payload);
        if ($raw === '') {
            return null;
        }

        $decoded = json_decode($raw, true);
        if (is_array($decoded)) {
            return dialecticExtractPlayerNameFromGamePayload($decoded);
        }

        foreach ([
            '/(?:^|[,|\s])(?:player_name|playerName|player|name|Name)\s*:\s*"([^"]+)"/u',
            "/(?:^|[,|\s])(?:player_name|playerName|player|name|Name)\s*:\s*'([^']+)'/u",
            '/(?:^|[,|\s])(?:player_name|playerName|player|name|Name)\s*:\s*([^,|]+)/u',
        ] as $pattern) {
            if (preg_match($pattern, $raw, $matches)) {
                $candidate = dialecticNormalizeDetectedPlayerName(strval($matches[1]));
                if ($candidate !== null) {
                    return $candidate;
                }
            }
        }

        return null;
    }
}

if (!function_exists('dialecticMaybeSyncPlayerNameFromGamePayload')) {
    function dialecticMaybeSyncPlayerNameFromGamePayload($payload): bool
    {
        $candidate = dialecticExtractPlayerNameFromGamePayload($payload);
        return $candidate !== null ? dialecticMaybeSyncPlayerName($candidate) : false;
    }
}

if (!function_exists('dialecticMaybeSyncPlayerName')) {
    // Self-heal core_player.player_name when game's player differs from configured name.
    // Trusted only when candidate is non-empty, not a placeholder, and not in core_npc_master.
    function dialecticMaybeSyncPlayerName($candidateName): bool
    {
        $candidate = dialecticNormalizeDetectedPlayerName($candidateName);
        if ($candidate === null) return false;

        $current = trim((string)($GLOBALS["PLAYER_NAME"] ?? ''));
        if ($current !== '' && strcasecmp($current, $candidate) === 0) return false;

        static $cache = [];
        $key = strtolower($candidate);
        if (array_key_exists($key, $cache)) return $cache[$key];

        if (!isset($GLOBALS["db"]) || !is_object($GLOBALS["db"])) {
            $cache[$key] = false;
            return false;
        }

        try {
            $escaped = $GLOBALS["db"]->escape($candidate);
            $row = $GLOBALS["db"]->fetchOne(
                "SELECT 1 FROM core_npc_master WHERE LOWER(npc_name) = LOWER('{$escaped}') LIMIT 1"
            );
            if ($row) {
                $cache[$key] = false;
                return false;
            }
        } catch (\Throwable $e) {
            $cache[$key] = false;
            return false;
        }

        if (!class_exists('Player')) {
            require_once(__DIR__ . DIRECTORY_SEPARATOR . "core" . DIRECTORY_SEPARATOR . "player.class.php");
        }

        try {
            $player = new Player();
            $player->set('player_name', $candidate);
            $GLOBALS["PLAYER_NAME"] = $candidate;
            Logger::info("[DIALECTIC] Auto-synced player_name: '{$current}' -> '{$candidate}'");
            $cache[$key] = true;
            return true;
        } catch (\Throwable $e) {
            Logger::warn("[DIALECTIC] dialecticMaybeSyncPlayerName failed: " . $e->getMessage());
            $cache[$key] = false;
            return false;
        }
    }
}

?>
