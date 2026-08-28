<?php

require_once dirname(__DIR__) . '/settings.php';

/** Explicit global-only allowlist; prompts, identities, profiles and connectors never enter a snapshot. */
function dialecticSettingsPresetDefaults(): array
{
    return [
        'AUTO_LOCK_PROFILE' => true,
        'AUTOFILL_CUSTOM_PROFILES' => true,
        'AUTOFILL_CUSTOM_PROFILES_TRIGGER' => 40,
        'CLEAN_CONTEXT_FOCUS_CHAT_HISTORY' => 25,
        'FEATURES@MEMORY_EMBEDDING@ENABLED' => true,
        'FEATURES@MEMORY_EMBEDDING@USE_TEXT2VEC' => true,
        'FEATURES@MEMORY_EMBEDDING@AUTO_CREATE_SUMMARY_INTERVAL' => 10,
        'COMPACT_NPC_CONTEXT_HISTORY' => true,
        'PROMPT_HEAD_MARKDOWN_ENABLED' => true,
        'RECHAT_MODE' => 'random',
        'ENFORCE_STRICT_RECHAT_RESPONSE' => false,
        'RELATIONSHIP_SYSTEM_ENABLED' => true,
        'SCENE_CLASSIFIER_ENABLED' => true,
        'POWER_AWARENESS_ENABLED' => false,
        'WORLDKNOWLEDGE_CUSTOM' => false,
        'WORLDKNOWLEDGE_INFINIUM' => true,
        'WORLDKNOWLEDGE_AMOUNT' => 1,
        'WORLDKNOWLEDGE_RESULT_LIMIT' => 3,
        'LOCATION_WORLDKNOWLEDGE' => true,
        'RACE_WORLDKNOWLEDGE' => true,
        'FACTION_WORLDKNOWLEDGE' => true,
        'WORLDKNOWLEDGE_EXTRACTOR_FALLBACK' => false,
        'WORLDKNOWLEDGE_EXTRACTOR_TIMEOUT_MS' => 1500,
        'GROUND_ITEMS_DESCRIPTIONS_ONLY' => false,
        'INVENTORY_ITEMS_DESCRIPTIONS_ONLY' => false,
        'HIDE_AMBIENT_COMBAT' => false,
        'PROMPT_TIMESTAMP' => false,
        'SHORTER_NEARBY_ITEM_LIST' => false,
    ];
}

function dialecticSettingsPresetBuiltIns(): array
{
    $defaults = dialecticSettingsPresetDefaults();
    $local = array_replace($defaults, [
        'AUTOFILL_CUSTOM_PROFILES' => false,
        'CLEAN_CONTEXT_FOCUS_CHAT_HISTORY' => 15,
        'FEATURES@MEMORY_EMBEDDING@ENABLED' => false,
        'RELATIONSHIP_SYSTEM_ENABLED' => false,
        'SCENE_CLASSIFIER_ENABLED' => false,
        'WORLDKNOWLEDGE_INFINIUM' => false,
        'GROUND_ITEMS_DESCRIPTIONS_ONLY' => true,
        'INVENTORY_ITEMS_DESCRIPTIONS_ONLY' => true,
        'HIDE_AMBIENT_COMBAT' => true,
        'SHORTER_NEARBY_ITEM_LIST' => true,
    ]);
    $presets = [];
    foreach ([
        'builtin:default' => ['Default', 'Restore the standard global options included in presets.', $defaults],
        'builtin:local_llm' => ['Local LLM', 'Less context and fewer optional AI tasks. Profiles and connections stay unchanged.', $local],
    ] as $id => [$name, $description, $settings]) {
        $presets[$id] = [
            'id' => $id, 'name' => $name, 'description' => $description, 'built_in' => true,
            'snapshot' => ['version' => 1, 'settings' => $settings,
                'prompt_context_options' => dialecticGetDefaultPromptContextOptions()],
        ];
    }
    return $presets;
}

/** Validate before storage and again on apply; unknown fields can never expand the write scope. */
function dialecticSettingsPresetNormalizeSnapshot(array $snapshot): array
{
    if (($snapshot['version'] ?? null) !== 1 || !is_array($snapshot['settings'] ?? null)
        || !is_array($snapshot['prompt_context_options'] ?? null)) {
        throw new InvalidArgumentException('Invalid preset settings.');
    }
    $settings = [];
    $ranges = [
        'AUTOFILL_CUSTOM_PROFILES_TRIGGER' => [10, 100],
        'CLEAN_CONTEXT_FOCUS_CHAT_HISTORY' => [0, 10000],
        'FEATURES@MEMORY_EMBEDDING@AUTO_CREATE_SUMMARY_INTERVAL' => [1, 10000],
        'WORLDKNOWLEDGE_AMOUNT' => [1, 3],
        'WORLDKNOWLEDGE_RESULT_LIMIT' => [1, 5],
        'WORLDKNOWLEDGE_EXTRACTOR_TIMEOUT_MS' => [250, 3000],
    ];
    foreach (dialecticSettingsPresetDefaults() as $id => $default) {
        if (!array_key_exists($id, $snapshot['settings'])) {
            continue;
        }
        $raw = $snapshot['settings'][$id];
        if (!is_scalar($raw)) {
            throw new InvalidArgumentException('Invalid setting: ' . $id);
        }
        $value = dialecticSettingsStringifyValue($raw);
        if (is_bool($default)) {
            if (!in_array(strtolower(trim($value)), ['1', '0', 'true', 'false', 'yes', 'no', 'on', 'off'], true)) {
                throw new InvalidArgumentException('Invalid setting: ' . $id);
            }
            $settings[$id] = dialecticSettingsNormalizeScalar($value, ['type' => 'boolean']);
        } elseif (isset($ranges[$id])) {
            if (filter_var($value, FILTER_VALIDATE_INT) === false
                || (int)$value < $ranges[$id][0] || (int)$value > $ranges[$id][1]) {
                throw new InvalidArgumentException('Setting is outside its allowed range: ' . $id);
            }
            $settings[$id] = dialecticSettingsNormalizeScalar($value, ['type' => 'integer']);
        } else {
            if (!in_array($value, ['tight', 'conversational', 'group', 'random'], true)) {
                throw new InvalidArgumentException('Invalid rechat mode.');
            }
            $settings[$id] = $value;
        }
    }
    if (!$settings) {
        throw new InvalidArgumentException('No supported global settings were supplied.');
    }
    foreach ($snapshot['prompt_context_options'] as $values) {
        if (!is_array($values) || count($values) > 100) {
            throw new InvalidArgumentException('Invalid context selections.');
        }
        foreach ($values as $value) {
            if (!is_string($value)) {
                throw new InvalidArgumentException('Invalid context selections.');
            }
        }
    }
    return ['version' => 1, 'settings' => $settings,
        'prompt_context_options' => dialecticNormalizePromptContextOptions($snapshot['prompt_context_options'])];
}

function dialecticSettingsPresetEnsureStorage(): void
{
    $db = dialecticSettingsDb();
    if (!$db) {
        throw new RuntimeException('Database connection is unavailable.');
    }
    $row = $db->fetchOne("SELECT to_regclass('public.global_settings_presets') AS relation");
    if (empty($row['relation'])) {
        $schema = file_get_contents(__DIR__ . '/database_schema/global_settings_presets.sql');
        if ($schema === false || $db->execQuery($schema) === false) {
            throw new RuntimeException('Could not prepare preset storage.');
        }
    }
}

function dialecticSettingsPresetCatalog(): array
{
    dialecticSettingsPresetEnsureStorage();
    $presets = array_values(dialecticSettingsPresetBuiltIns());
    foreach ($presets as &$preset) {
        unset($preset['snapshot']);
    }
    unset($preset);
    foreach (dialecticSettingsDb()->fetchAll('SELECT id, name FROM public.global_settings_presets ORDER BY lower(name), id LIMIT 50') as $row) {
        $presets[] = ['id' => 'custom:' . (int)$row['id'], 'name' => $row['name'],
            'description' => 'Saved global options. Profiles and connections stay unchanged.', 'built_in' => false];
    }
    return $presets;
}

function dialecticSettingsPresetCustomId(string $id): int
{
    if (!preg_match('/^custom:([1-9][0-9]{0,8})$/D', $id, $matches)) {
        throw new InvalidArgumentException('Select a saved custom preset.');
    }
    return (int)$matches[1];
}

/** Save or overwrite a bounded snapshot without changing active settings. */
function dialecticSettingsPresetSave(string $name, array $snapshot, string $overwriteId = ''): array
{
    $snapshot = dialecticSettingsPresetNormalizeSnapshot($snapshot);
    $name = trim(preg_replace('/\s+/u', ' ', $name) ?? '');
    $nameLength = function_exists('mb_strlen') ? mb_strlen($name) : strlen($name);
    if ($overwriteId === '' && ($name === '' || $nameLength > 60
        || preg_match('/[\x00-\x1f\x7f]/', $name)
        || in_array(strtolower($name), ['default', 'local llm', 'local-llm', 'local_llm'], true))) {
        throw new InvalidArgumentException('Use a new preset name of 1–60 characters.');
    }
    dialecticSettingsPresetEnsureStorage();
    $db = dialecticSettingsDb();
    if ($db->execQuery('BEGIN') === false) {
        throw new RuntimeException('Could not start the preset update.');
    }
    try {
        // Serialize writers so the count cap and duplicate-name check remain true under concurrent saves.
        if ($db->execQuery('LOCK TABLE public.global_settings_presets IN SHARE ROW EXCLUSIVE MODE') === false) {
            throw new RuntimeException('Could not lock preset storage.');
        }
        $data = ['snapshot' => json_encode($snapshot, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)];
        if ($overwriteId !== '') {
            $id = dialecticSettingsPresetCustomId($overwriteId);
            $row = $db->fetchOne('SELECT name FROM public.global_settings_presets WHERE id = ' . $id);
            if (!isset($row['name'])) {
                throw new InvalidArgumentException('Preset not found.');
            }
            $name = $row['name'];
            $data['updated_at'] = date('Y-m-d H:i:s');
            if ($db->updateRow('global_settings_presets', $data, 'id = ' . $id) === false) {
                throw new RuntimeException('Could not overwrite the preset.');
            }
        } else {
            $existing = $db->fetchOne('SELECT count(*) AS count, count(*) FILTER (WHERE lower(name) = lower('
                . $db->escapeLiteral($name) . ')) AS duplicate FROM public.global_settings_presets');
            if ((int)$existing['duplicate'] > 0) {
                throw new InvalidArgumentException('That name already exists. Select it and use Overwrite.');
            }
            if ((int)$existing['count'] >= 50) {
                throw new InvalidArgumentException('The 50-preset limit has been reached. Overwrite an existing preset.');
            }
            $id = $db->insertReturningId('global_settings_presets', ['name' => $name] + $data);
            if ($id <= 0) {
                throw new RuntimeException('Could not save the preset.');
            }
        }
        if ($db->execQuery('COMMIT') === false) {
            throw new RuntimeException('Could not finish the preset update.');
        }
    } catch (Throwable $error) {
        $db->execQuery('ROLLBACK');
        throw $error;
    }
    return ['id' => 'custom:' . $id, 'name' => $name, 'built_in' => false];
}

/** Apply only the snapshot's approved general settings as one database transaction. */
function dialecticSettingsPresetApply(string $presetId): array
{
    $preset = dialecticSettingsPresetBuiltIns()[$presetId] ?? null;
    $db = dialecticSettingsDb();
    if (!$db) {
        throw new RuntimeException('Database connection is unavailable.');
    }
    if ($preset === null) {
        $id = dialecticSettingsPresetCustomId($presetId);
        dialecticSettingsPresetEnsureStorage();
        $row = $db->fetchOne('SELECT name, snapshot FROM public.global_settings_presets WHERE id = ' . $id);
        if (!isset($row['name'])) {
            throw new InvalidArgumentException('Preset not found.');
        }
        $preset = ['id' => $presetId, 'name' => $row['name'],
            'snapshot' => json_decode($row['snapshot'], true, 32, JSON_THROW_ON_ERROR)];
    }
    $snapshot = dialecticSettingsPresetNormalizeSnapshot($preset['snapshot']);
    if ($db->execQuery('BEGIN') === false) {
        throw new RuntimeException('Could not start the settings update.');
    }
    try {
        $settings = $snapshot['settings'] + ['PROMPT_CONTEXT_OPTIONS' => $snapshot['prompt_context_options']];
        foreach ($settings as $id => $value) {
            if (!dialecticSetGeneralSetting($id, $value)) {
                throw new RuntimeException('Could not apply global settings.');
            }
        }
        if ($db->execQuery('COMMIT') === false) {
            throw new RuntimeException('Could not finish the settings update.');
        }
    } catch (Throwable $error) {
        $db->execQuery('ROLLBACK');
        throw $error;
    }
    return ['preset' => ['id' => $presetId, 'name' => $preset['name']], 'settings_updated' => count($settings)];
}
