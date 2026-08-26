<?php

require_once(__DIR__ . DIRECTORY_SEPARATOR . 'game_plugins.php');
require_once(__DIR__ . DIRECTORY_SEPARATOR . 'npc_master.class.php');

function dialecticActionCatalogCanonicalActionCodes()
{
    if (function_exists('dialecticCanonicalActionCodes')) {
        return dialecticCanonicalActionCodes();
    }

    $seedRows = dialecticLoadActionCatalogBaseSeedRowsFromSeedFile();
    return array_keys($seedRows);
}

function dialecticActionCatalogCanonicalActionCodeSet()
{
    return array_fill_keys(dialecticActionCatalogCanonicalActionCodes(), true);
}

function dialecticActionCatalogDecodeSqlQuotedText($value)
{
    $text = trim(strval($value));
    if ($text === '' || strcasecmp($text, 'NULL') === 0) {
        return null;
    }

    if (strlen($text) >= 2 && $text[0] === "'" && substr($text, -1) === "'") {
        $text = substr($text, 1, -1);
    }

    return str_replace("''", "'", $text);
}

function dialecticActionCatalogSplitSqlTuple($tuple)
{
    $fields = [];
    $current = '';
    $inString = false;
    $length = strlen($tuple);

    for ($index = 0; $index < $length; $index++) {
        $char = $tuple[$index];

        if ($char === "'") {
            $current .= $char;
            if ($inString && $index + 1 < $length && $tuple[$index + 1] === "'") {
                $current .= "'";
                $index++;
                continue;
            }

            $inString = !$inString;
            continue;
        }

        if (!$inString && $char === ',') {
            $fields[] = trim($current);
            $current = '';
            continue;
        }

        $current .= $char;
    }

    if (trim($current) !== '') {
        $fields[] = trim($current);
    }

    return $fields;
}

function dialecticActionCatalogSplitSqlInsertTuples($sql)
{
    $valuesPos = stripos($sql, 'VALUES');
    $conflictPos = stripos($sql, 'ON CONFLICT');
    if ($valuesPos === false || $conflictPos === false || $conflictPos <= $valuesPos) {
        return [];
    }

    $valuesSql = trim(substr($sql, $valuesPos + strlen('VALUES'), $conflictPos - ($valuesPos + strlen('VALUES'))));
    if ($valuesSql === '') {
        return [];
    }

    $tuples = [];
    $current = '';
    $depth = 0;
    $inString = false;
    $length = strlen($valuesSql);

    for ($index = 0; $index < $length; $index++) {
        $char = $valuesSql[$index];

        if ($char === "'") {
            $current .= $char;
            if ($inString && $index + 1 < $length && $valuesSql[$index + 1] === "'") {
                $current .= "'";
                $index++;
                continue;
            }

            $inString = !$inString;
            continue;
        }

        if (!$inString) {
            if ($char === '(') {
                if ($depth > 0) {
                    $current .= $char;
                }
                $depth++;
                continue;
            }

            if ($char === ')') {
                $depth--;
                if ($depth === 0) {
                    $tuples[] = $current;
                    $current = '';
                    continue;
                }
            }
        }

        if ($depth > 0) {
            $current .= $char;
        }
    }

    return $tuples;
}

function dialecticLoadActionCatalogBaseSeedRowsFromSeedFile()
{
    static $cache = null;
    if (is_array($cache)) {
        return $cache;
    }

    $cache = [];
    $seedFile = dialecticGetActionCatalogBaseSeedFilePath();
    if (!file_exists($seedFile)) {
        return $cache;
    }

    $sql = trim(strval(file_get_contents($seedFile)));
    if ($sql === '') {
        return $cache;
    }

    foreach (dialecticActionCatalogSplitSqlInsertTuples($sql) as $tuple) {
        $fields = dialecticActionCatalogSplitSqlTuple($tuple);
        if (count($fields) < 8) {
            continue;
        }

        $codeName = dialecticActionCatalogDecodeSqlQuotedText($fields[0] ?? '');
        if ($codeName === null || trim($codeName) === '') {
            continue;
        }

        $cache[$codeName] = [
            'code_name' => $codeName,
            'action_name' => dialecticActionCatalogDecodeSqlQuotedText($fields[1] ?? ''),
            'description' => dialecticActionCatalogDecodeSqlQuotedText($fields[2] ?? ''),
            'return_message' => dialecticActionCatalogDecodeSqlQuotedText($fields[3] ?? ''),
            'available_to_npc' => dialecticActionCatalogToBool($fields[4] ?? false),
            'available_to_followers' => dialecticActionCatalogToBool($fields[5] ?? false),
            'available_to_narrator' => dialecticActionCatalogToBool($fields[6] ?? false),
            'is_activated' => dialecticActionCatalogToBool($fields[7] ?? false),
        ];
    }

    return $cache;
}

function dialecticActionCatalogSqlBool($value)
{
    return $value ? 'TRUE' : 'FALSE';
}

function dialecticActionCatalogNormalizeImportVersion($value)
{
    if ($value === null) {
        return 0;
    }

    if (is_bool($value)) {
        return $value ? 1 : 0;
    }

    if (is_numeric($value)) {
        return max(0, intval(floor(floatval($value))));
    }

    $text = trim(strval($value));
    if ($text === '') {
        return 0;
    }

    if (is_numeric($text)) {
        return max(0, intval(floor(floatval($text))));
    }

    return 0;
}

function dialecticActionCatalogShouldOverwriteImportVersion($incomingVersion, $existingVersion)
{
    return dialecticActionCatalogNormalizeImportVersion($incomingVersion)
        > dialecticActionCatalogNormalizeImportVersion($existingVersion);
}

function dialecticActionCatalogSqlText($value)
{
    $text = strval($value);
    if ($text === '') {
        return "''";
    }

    return $GLOBALS["db"]->escapeLiteral($text);
}

function dialecticActionCatalogSqlJson($value, $allowNull = false)
{
    if ($value === null) {
        return $allowNull ? 'NULL' : "'{}'::jsonb";
    }

    if (is_string($value)) {
        $json = trim($value);
        if ($json === '') {
            return $allowNull ? 'NULL' : "'{}'::jsonb";
        }
    } else {
        $json = dialecticActionCatalogJsonEncode($value);
        if ($json === '') {
            return $allowNull ? 'NULL' : "'{}'::jsonb";
        }
    }

    return $GLOBALS["db"]->escapeLiteral($json) . '::jsonb';
}

function dialecticActionCatalogJsonEncode($value)
{
    $json = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    return is_string($json) ? $json : '';
}

function dialecticActionCatalogDecodeJson($value, $default = [])
{
    if (is_array($value)) {
        return $value;
    }

    $text = trim(strval($value));
    if ($text === '') {
        return $default;
    }

    $decoded = json_decode($text, true);
    return is_array($decoded) ? $decoded : $default;
}

function dialecticActionCatalogMergePreservedCustomMetadata($baseMetadata, $existingMetadata)
{
    $baseMetadata = dialecticActionCatalogDecodeJson($baseMetadata, []);
    $existingMetadata = dialecticActionCatalogDecodeJson($existingMetadata, []);

    if (isset($existingMetadata['custom_config']) && is_array($existingMetadata['custom_config']) && count($existingMetadata['custom_config']) > 0) {
        $baseMetadata['custom_config'] = $existingMetadata['custom_config'];
    }

    return $baseMetadata;
}

function dialecticActionCatalogNormalizeEditorFieldOptions($options)
{
    if (!is_array($options)) {
        return [];
    }

    $normalized = [];
    foreach ($options as $key => $option) {
        if (is_array($option)) {
            $value = strval($option['value'] ?? '');
            if ($value === '') {
                $value = is_string($key) ? $key : '';
            }
            if ($value === '') {
                continue;
            }

            $normalized[] = [
                'value' => $value,
                'label' => strval($option['label'] ?? $value),
            ];
            continue;
        }

        if (is_string($key) && $key !== '') {
            $normalized[] = [
                'value' => $key,
                'label' => strval($option),
            ];
            continue;
        }

        $value = strval($option);
        if ($value === '') {
            continue;
        }

        $normalized[] = [
            'value' => $value,
            'label' => $value,
        ];
    }

    return $normalized;
}

function dialecticActionCatalogGetSharedEditorFields()
{
    return [
        [
            'key' => 'followup_enabled',
            'label' => 'Follow-up Enabled',
            'type' => 'boolean',
            'default' => false,
            'metadata_default_path' => 'followup.enabled',
            'help' => 'If enabled, this action may trigger a follow-up LLM response when a funcret result arrives.',
        ],
        [
            'key' => 'followup_arg_name',
            'label' => 'Follow-up Argument Name',
            'type' => 'text',
            'default' => 'target',
            'metadata_default_path' => 'followup.arg_name',
            'placeholder' => 'target',
            'help' => 'Tool-call argument name to use in the synthetic follow-up context.',
        ],
        [
            'key' => 'followup_prompt',
            'label' => 'Follow-up Prompt',
            'type' => 'textarea',
            'default' => '',
            'metadata_default_path' => 'followup.prompt',
            'placeholder' => 'Reply with one short in-character line reacting to the tool result below. Do not ask follow-up questions.',
            'help' => 'The full instruction used to generate the follow-up response.',
        ],
        [
            'key' => 'followup_use_functions_again',
            'label' => 'Allow Follow-up Actions',
            'type' => 'boolean',
            'default' => false,
            'metadata_default_path' => 'followup.use_functions_again',
            'help' => 'If enabled, the follow-up response may call another action.',
        ],
    ];
}

function dialecticActionCatalogNormalizeEditorField($field)
{
    if (!is_array($field)) {
        return null;
    }

    $key = trim(strval($field['key'] ?? ''));
    if ($key === '') {
        return null;
    }

    $type = strtolower(trim(strval($field['type'] ?? 'text')));
    if (!in_array($type, ['text', 'textarea', 'integer', 'number', 'boolean', 'select'], true)) {
        $type = 'text';
    }

    $normalized = [
        'key' => $key,
        'label' => trim(strval($field['label'] ?? $key)),
        'type' => $type,
        'default' => $field['default'] ?? null,
        'global_default_key' => trim(strval($field['global_default_key'] ?? '')),
        'metadata_default_path' => trim(strval($field['metadata_default_path'] ?? '')),
        'minimum' => array_key_exists('minimum', $field) ? $field['minimum'] : null,
        'maximum' => array_key_exists('maximum', $field) ? $field['maximum'] : null,
        'step' => array_key_exists('step', $field) ? $field['step'] : null,
        'format' => trim(strval($field['format'] ?? '')),
        'placeholder' => strval($field['placeholder'] ?? ''),
        'help' => strval($field['help'] ?? ''),
        'options' => dialecticActionCatalogNormalizeEditorFieldOptions($field['options'] ?? []),
    ];

    if ($normalized['label'] === '') {
        $normalized['label'] = $key;
    }

    return $normalized;
}

function dialecticActionCatalogGetEditorFields($rowOrCode = null)
{
    $row = null;
    if (is_array($rowOrCode)) {
        $row = $rowOrCode;
    } elseif ($rowOrCode !== null) {
        $row = dialecticGetActionCatalogRow($rowOrCode);
    }

    if (!is_array($row)) {
        return [];
    }

    $metadata = dialecticActionCatalogDecodeJson($row['metadata'] ?? [], []);
    $fields = $metadata['editor_fields'] ?? [];
    if (!is_array($fields)) {
        return [];
    }

    $normalized = [];
    foreach (dialecticActionCatalogGetSharedEditorFields() as $field) {
        $normalizedField = dialecticActionCatalogNormalizeEditorField($field);
        if ($normalizedField === null) {
            continue;
        }

        $normalized[$normalizedField['key']] = $normalizedField;
    }

    foreach ($fields as $field) {
        $normalizedField = dialecticActionCatalogNormalizeEditorField($field);
        if ($normalizedField === null) {
            continue;
        }

        $normalized[$normalizedField['key']] = $normalizedField;
    }

    return array_values($normalized);
}

function dialecticActionCatalogCastEditorFieldValue($field, $value)
{
    $field = dialecticActionCatalogNormalizeEditorField($field);
    if ($field === null) {
        return $value;
    }

    $type = $field['type'];
    if ($type === 'boolean') {
        return dialecticActionCatalogToBool($value);
    }

    if ($type === 'integer') {
        if (is_bool($value) || $value === null || trim(strval($value)) === '' || !is_numeric($value)) {
            $value = $field['default'] ?? 0;
        }

        $normalizedValue = intval(round(floatval($value)));
        if (is_numeric($field['minimum'])) {
            $normalizedValue = max($normalizedValue, intval($field['minimum']));
        }
        if (is_numeric($field['maximum'])) {
            $normalizedValue = min($normalizedValue, intval($field['maximum']));
        }
        return $normalizedValue;
    }

    if ($type === 'number') {
        if (is_bool($value) || $value === null || trim(strval($value)) === '' || !is_numeric($value)) {
            $value = $field['default'] ?? 0;
        }

        $normalizedValue = floatval($value);
        if (is_numeric($field['minimum'])) {
            $normalizedValue = max($normalizedValue, floatval($field['minimum']));
        }
        if (is_numeric($field['maximum'])) {
            $normalizedValue = min($normalizedValue, floatval($field['maximum']));
        }
        return $normalizedValue;
    }

    if ($type === 'select') {
        $textValue = trim(strval($value));
        foreach ($field['options'] as $option) {
            if ($textValue === strval($option['value'] ?? '')) {
                return $textValue;
            }
        }

        if (count($field['options']) > 0) {
            return strval($field['options'][0]['value'] ?? '');
        }

        return '';
    }

    return strval($value ?? '');
}

function dialecticActionCatalogGetEditorFieldDefaultValue($field, $row = null)
{
    $field = dialecticActionCatalogNormalizeEditorField($field);
    if ($field === null) {
        return null;
    }

    $defaultValue = $field['default'] ?? null;
    $globalDefaultKey = trim(strval($field['global_default_key'] ?? ''));
    if ($globalDefaultKey !== '' && array_key_exists($globalDefaultKey, $GLOBALS)) {
        $defaultValue = $GLOBALS[$globalDefaultKey];
    }

    $metadataDefaultPath = trim(strval($field['metadata_default_path'] ?? ''));
    if ($metadataDefaultPath !== '' && is_array($row)) {
        $rowMetadata = dialecticActionCatalogDecodeJson($row['metadata'] ?? [], []);
        $metadataDefaultValue = dialecticActionCatalogResolveContextPath($rowMetadata, $metadataDefaultPath);
        if ($metadataDefaultValue !== null) {
            $defaultValue = $metadataDefaultValue;
        }
    }

    return dialecticActionCatalogCastEditorFieldValue($field, $defaultValue);
}

function dialecticActionCatalogGetResolvedCustomConfig($codeName, $row = null)
{
    $codeName = trim(strval($codeName));
    if ($codeName === '') {
        return [];
    }

    if (!is_array($row)) {
        $row = dialecticGetActionCatalogRow($codeName);
    }
    if (!is_array($row)) {
        return [];
    }

    $metadata = dialecticActionCatalogDecodeJson($row['metadata'] ?? [], []);
    $customConfig = is_array($metadata['custom_config'] ?? null) ? $metadata['custom_config'] : [];
    $resolvedConfig = [];

    foreach (dialecticActionCatalogGetEditorFields($row) as $field) {
        $fieldKey = $field['key'];
        if (array_key_exists($fieldKey, $customConfig)) {
            $resolvedConfig[$fieldKey] = dialecticActionCatalogCastEditorFieldValue($field, $customConfig[$fieldKey]);
        } else {
            $resolvedConfig[$fieldKey] = dialecticActionCatalogGetEditorFieldDefaultValue($field, $row);
        }
    }

    foreach ($customConfig as $fieldKey => $fieldValue) {
        if (!array_key_exists($fieldKey, $resolvedConfig)) {
            $resolvedConfig[$fieldKey] = $fieldValue;
        }
    }

    return $resolvedConfig;
}

function dialecticActionCatalogToBool($value)
{
    if (is_bool($value)) {
        return $value;
    }

    $text = strtolower(trim(strval($value)));
    return in_array($text, ['1', 'true', 't', 'yes', 'on'], true);
}

function dialecticNormalizeActionCatalogDisplayToken($text, $token, $replacement)
{
    $token = trim(strval($token));
    if ($token === '') {
        return $text;
    }

    $quotedToken = preg_quote($token, '/');
    $text = preg_replace('/\b[Tt]he\s+' . $quotedToken . '\b/u', $replacement, $text);
    return str_replace($token, $replacement, $text);
}

function dialecticNormalizeActionCatalogDisplayText($text)
{
    $text = strval($text);
    if ($text === '') {
        return '';
    }

    $text = dialecticNormalizeActionCatalogDisplayToken($text, $GLOBALS["DIALECTIC_NAME"] ?? '', 'NPC');
    $text = dialecticNormalizeActionCatalogDisplayToken($text, $GLOBALS["PLAYER_NAME"] ?? '', 'PLAYER');
    $text = dialecticNormalizeActionCatalogDisplayToken($text, 'The Narrator', 'NPC');
    $text = dialecticNormalizeActionCatalogDisplayToken($text, 'Narrator', 'NPC');

    $text = preg_replace('/\b[Tt]he\s+NPC\b/u', 'NPC', $text);
    $text = preg_replace('/\b[Tt]he\s+PLAYER\b/u', 'PLAYER', $text);

    return $text;
}

function dialecticNormalizeActionCatalogDisplayActionName($text)
{
    $text = strval($text);
    if ($text === '') {
        return '';
    }

    $text = dialecticNormalizeActionCatalogDisplayToken($text, $GLOBALS["DIALECTIC_NAME"] ?? '', 'Npc');
    $text = dialecticNormalizeActionCatalogDisplayToken($text, $GLOBALS["PLAYER_NAME"] ?? '', 'Player');
    $text = dialecticNormalizeActionCatalogDisplayToken($text, 'The Narrator', 'Npc');
    $text = dialecticNormalizeActionCatalogDisplayToken($text, 'Narrator', 'Npc');

    $text = preg_replace('/\b[Tt]he\s+Npc\b/u', 'Npc', $text);
    $text = preg_replace('/\b[Tt]he\s+Player\b/u', 'Player', $text);

    $text = preg_replace('/[\s\-]+/u', '_', $text);
    $text = preg_replace('/(?<=[a-z0-9])(?=[A-Z])/u', '_', $text);
    $text = preg_replace('/(?<=[A-Z])(?=[A-Z][a-z])/u', '_', $text);
    $text = preg_replace('/(?<=[A-Za-z])(?=\d)/u', '_', $text);
    $text = preg_replace('/(?<=\d)(?=[A-Za-z])/u', '_', $text);
    $text = preg_replace('/_+/u', '_', $text);
    $text = trim($text, '_');

    return $text;
}

function dialecticActionCatalogNormalizeParameterSchema($parameters)
{
    if (!is_array($parameters)) {
        return [
            'type' => 'object',
            'properties' => [],
            'required' => [],
        ];
    }

    if (($parameters['type'] ?? '') !== 'object') {
        $parameters['type'] = 'object';
    }

    if (!isset($parameters['properties']) || !is_array($parameters['properties'])) {
        $parameters['properties'] = [];
    }

    if (!isset($parameters['required']) || !is_array($parameters['required'])) {
        $parameters['required'] = [];
    }

    $normalizedRequired = [];
    foreach ($parameters['required'] as $requiredField) {
        $requiredField = trim(strval($requiredField));
        if ($requiredField !== '' && !in_array($requiredField, $normalizedRequired, true)) {
            $normalizedRequired[] = $requiredField;
        }
    }
    $parameters['required'] = $normalizedRequired;

    return $parameters;
}

function dialecticActionCatalogGetBaseScriptProxyPrograms()
{
    static $programs = null;
    if ($programs !== null) {
        return $programs;
    }

    $programs = [];

    return $programs;
}

function dialecticActionCatalogGetBuiltinEditorFields($codeName)
{
    return [];
}

function dialecticActionCatalogGetBuiltinParameterTemplate($codeName)
{
    return null;
}

function dialecticActionCatalogGetBuiltinCooldownSeconds($codeName)
{
    $cooldowns = [
        'ComeCloser' => 30,
        'WaitHere' => 30,
        'Follow' => 60,
        'StopFollowing' => 30,
    ];

    return $cooldowns[$codeName] ?? null;
}

function dialecticActionCatalogGetBuiltinRequirements($codeName)
{
    $requirements = [
        'SheatheWeapon' => [
            'activity' => [
                'require_fresh' => true,
                'is_weapon_drawn' => true,
                'current_action_not_in' => ['dead', 'unconscious', 'sleeping'],
            ],
        ],
        'TakeASeat' => [
            'activity' => [
                'current_action_not_in' => ['dead', 'unconscious', 'sleeping', 'sitting', 'using', 'leaning'],
            ],
        ],
    ];

    return $requirements[$codeName] ?? [];
}

function dialecticActionCatalogBuildBaseMetadata($codeName, $scriptProxyProgram = null)
{
    $dispatch = 'plugin_command';
    if ($scriptProxyProgram !== null) {
        $dispatch = 'script_proxy';
    }

    $metadata = [
        'dispatch' => $dispatch,
        'builtin' => true,
        'status' => 'active',
        'source' => 'functions.php',
    ];

    $editorFields = dialecticActionCatalogGetBuiltinEditorFields($codeName);
    if (count($editorFields) > 0) {
        $metadata['editor_fields'] = $editorFields;
    }

    $parameterTemplate = dialecticActionCatalogGetBuiltinParameterTemplate($codeName);
    if ($parameterTemplate !== null) {
        $metadata['parameter_template'] = $parameterTemplate;
    }

    $requirements = dialecticActionCatalogGetBuiltinRequirements($codeName);
    if (count($requirements) > 0) {
        $metadata['requirements'] = $requirements;
    }

    $cooldownSeconds = dialecticActionCatalogGetBuiltinCooldownSeconds($codeName);
    if ($cooldownSeconds !== null) {
        $metadata['cooldown_seconds'] = intval($cooldownSeconds);
    }

    $followupConfig = dialecticActionCatalogBuildBaseFollowupConfig($codeName);
    if (count($followupConfig) > 0) {
        $metadata['followup'] = $followupConfig;
    }

    return $metadata;
}

function dialecticActionCatalogNormalizeFollowupConfig($config)
{
    if (!is_array($config)) {
        return [];
    }

    $normalized = [];
    if (array_key_exists('enabled', $config)) {
        $normalized['enabled'] = dialecticActionCatalogToBool($config['enabled']);
    }

    $prompt = trim(strval($config['prompt'] ?? ''));
    if ($prompt !== '') {
        $normalized['prompt'] = $prompt;
    }

    $argName = trim(strval($config['arg_name'] ?? ''));
    if ($argName !== '') {
        $normalized['arg_name'] = $argName;
    }

    if (array_key_exists('use_functions_again', $config)) {
        $normalized['use_functions_again'] = dialecticActionCatalogToBool($config['use_functions_again']);
    }

    return $normalized;
}

function dialecticActionCatalogGetFollowupChainLimit()
{
    return 1;
}

function dialecticActionCatalogGetFollowupChainMarkerPrefix()
{
    return '__dialectic_followup_chain__';
}

function dialecticActionCatalogParseActionsIssuedOriginalValue($value)
{
    $value = strval($value);
    $parsed = [
        'is_followup_chain' => false,
        'followup_chain_depth' => 0,
        'original' => $value,
    ];

    if ($value === '') {
        return $parsed;
    }

    $prefix = dialecticActionCatalogGetFollowupChainMarkerPrefix();
    if (strpos($value, $prefix) !== 0) {
        return $parsed;
    }

    $payload = json_decode(substr($value, strlen($prefix)), true);
    if (!is_array($payload)) {
        return $parsed;
    }

    $depth = max(0, intval($payload['depth'] ?? 0));
    $parsed['is_followup_chain'] = $depth > 0;
    $parsed['followup_chain_depth'] = $depth;
    $parsed['original'] = strval($payload['original'] ?? '');

    return $parsed;
}

function dialecticActionCatalogEncodeActionsIssuedOriginalValue($originalValue, $depth)
{
    $depth = max(0, intval($depth));
    if ($depth <= 0) {
        return strval($originalValue);
    }

    $payload = [
        'depth' => $depth,
    ];

    $originalValue = strval($originalValue);
    if ($originalValue !== '') {
        $payload['original'] = $originalValue;
    }

    return dialecticActionCatalogGetFollowupChainMarkerPrefix()
        . json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
}

function dialecticActionCatalogApplyFollowupChainToActionsIssuedOriginal($originalValue)
{
    $depth = intval($GLOBALS["FOLLOWUP_CHAIN_NEXT_DEPTH"] ?? 0);
    if ($depth <= 0) {
        return strval($originalValue);
    }

    return dialecticActionCatalogEncodeActionsIssuedOriginalValue($originalValue, $depth);
}

function dialecticActionCatalogBuildBaseFollowupConfig($codeName)
{
    $disabledFollowUpCodes = [
        'Attack',
        'Consume',
        'Follow',
        'GiveCapsTo',
        'GiveItemTo',
        'MoveTo',
        'StopFollowing',
        'TakeCapsFromPlayer',
    ];

    $promptMap = [
        'GetTopicInfo' => ['arg_name' => 'topic', 'prompt' => 'Reply with one short in-character line about the requested topic using the tool result below. Do not ask follow-up questions.'],
        'MoveTo' => ['arg_name' => 'target', 'prompt' => 'Reply with one short in-character line acknowledging that you moved to the target. Do not ask follow-up questions.'],
        'Attack' => ['arg_name' => 'target', 'prompt' => 'Reply with one short in-character combat line reacting to the attack outcome. Do not ask follow-up questions.'],
        'Inspect' => ['arg_name' => 'target', 'prompt' => 'Reply with one short in-character observation using the inspect result below. Do not ask follow-up questions.'],
        'InspectSurroundings' => ['arg_name' => 'target', 'prompt' => 'Reply with one short in-character observation about the surroundings using the tool result below. Do not ask follow-up questions.'],
        'GetTime' => ['arg_name' => 'datestring', 'prompt' => 'Reply with one short in-character line acknowledging the reported time. Do not ask follow-up questions.'],
        'get_current_mission' => ['arg_name' => 'description', 'prompt' => 'Reply with one short in-character line about the current mission using the tool result below. Do not ask follow-up questions.'],
        'CheckInventory' => ['arg_name' => 'target', 'prompt' => 'Reply with one short in-character line about the inventory result below. Do not ask follow-up questions.'],
        'ReadQuests' => ['arg_name' => 'id_quest', 'prompt' => 'Reply with one short in-character line about the quest result below. Do not ask follow-up questions.'],
        'GiveItemTo' => ['arg_name' => 'target', 'prompt' => 'Reply with one short in-character line reacting to the item handoff result below. Do not ask follow-up questions.'],
        'GiveCapsTo' => ['arg_name' => 'target', 'prompt' => 'Reply with one short in-character line reacting to the caps transfer result below. Do not ask follow-up questions.'],
        'TakeCapsFromPlayer' => ['arg_name' => 'amount', 'prompt' => 'Reply with one short in-character line reacting to the caps transfer result below. Do not ask follow-up questions.'],
    ];

    if (in_array($codeName, $disabledFollowUpCodes, true)) {
        $config = $promptMap[$codeName] ?? [];
        return dialecticActionCatalogNormalizeFollowupConfig([
            'enabled' => false,
        ] + $config);
    }

    if (isset($promptMap[$codeName])) {
        return dialecticActionCatalogNormalizeFollowupConfig([
            'enabled' => true,
        ] + $promptMap[$codeName]);
    }

    return [];
}

function dialecticActionCatalogGetResolvedFollowupConfig($codeName, $row = null)
{
    $codeName = trim(strval($codeName));
    if ($codeName === '') {
        return [];
    }

    if (!is_array($row)) {
        $row = dialecticGetActionCatalogRow($codeName);
    }
    if (!is_array($row)) {
        return [];
    }

    $metadata = dialecticActionCatalogDecodeJson($row['metadata'] ?? [], []);
    $resolvedConfig = dialecticActionCatalogNormalizeFollowupConfig($metadata['followup'] ?? []);

    $customConfig = is_array($metadata['custom_config'] ?? null) ? $metadata['custom_config'] : [];
    $resolvedCustomConfig = dialecticActionCatalogGetResolvedCustomConfig($codeName, $row);
    $customKeyToConfigKeyMap = [
        'followup_enabled' => 'enabled',
        'followup_arg_name' => 'arg_name',
        'followup_prompt' => 'prompt',
        'followup_use_functions_again' => 'use_functions_again',
    ];

    foreach ($customKeyToConfigKeyMap as $customKey => $configKey) {
        if (!array_key_exists($customKey, $customConfig) || !array_key_exists($customKey, $resolvedCustomConfig)) {
            continue;
        }

        $resolvedConfig[$configKey] = $resolvedCustomConfig[$customKey];
    }

    if (!empty($resolvedConfig['prompt']) && function_exists('dialecticFormatActionPromptTemplate')) {
        $resolvedConfig['prompt'] = dialecticFormatActionPromptTemplate(
            strval($resolvedConfig['prompt']),
            [],
            $row
        );
    }

    return dialecticActionCatalogNormalizeFollowupConfig($resolvedConfig);
}

function dialecticActionCatalogGetLastIssuedActionFollowupChainDepth($codeName)
{
    $codeName = trim(strval($codeName));
    if ($codeName === '') {
        return 0;
    }

    $rows = dialecticActionCatalogGetLastActionsIssuedMap();
    $row = is_array($rows) ? ($rows[$codeName] ?? null) : null;
    if (!is_array($row)) {
        return 0;
    }

    $parsed = dialecticActionCatalogParseActionsIssuedOriginalValue($row['original'] ?? '');
    return max(0, intval($parsed['followup_chain_depth'] ?? 0));
}

function dialecticActionCatalogIsGameFunction($metadata)
{
    $dispatch = strtolower(trim(strval($metadata['dispatch'] ?? 'plugin_command')));
    return !in_array($dispatch, ['server_action', 'server_query'], true);
}

function dialecticActionCatalogNormalizeRequirementStringList($values)
{
    if (is_string($values)) {
        $values = explode(',', $values);
    }

    if (!is_array($values)) {
        return [];
    }

    $normalized = [];
    foreach ($values as $value) {
        $text = strtolower(trim(strval($value)));
        if ($text === '') {
            continue;
        }

        $normalized[] = $text;
    }

    return array_values(array_unique($normalized));
}

function dialecticActionCatalogRequirementListContains($needle, $values)
{
    $needle = strtolower(trim(strval($needle)));
    if ($needle === '') {
        return false;
    }

    return in_array($needle, dialecticActionCatalogNormalizeRequirementStringList($values), true);
}

function dialecticActionCatalogGetCurrentNpcLookup()
{
    static $cachedKey = null;
    static $cachedLookup = null;

    $dialecticName = trim(strval($GLOBALS["DIALECTIC_NAME"] ?? ''));
    if ($cachedKey === $dialecticName && is_array($cachedLookup)) {
        return $cachedLookup;
    }

    $cachedKey = $dialecticName;
    $cachedLookup = [
        'npc_master' => null,
        'npc_data' => [],
        'metadata' => [],
        'extended' => [],
    ];

    if ($dialecticName === '' || $dialecticName === '(actor)' || !class_exists('NpcMaster')) {
        return $cachedLookup;
    }

    $npcMaster = new NpcMaster();
    $npcData = $npcMaster->getByName($dialecticName);
    if (!is_array($npcData) || count($npcData) === 0) {
        return $cachedLookup;
    }

    $cachedLookup['npc_master'] = $npcMaster;
    $cachedLookup['npc_data'] = $npcData;
    $cachedLookup['metadata'] = $npcMaster->getMetadata($npcData);
    $cachedLookup['extended'] = $npcMaster->getExtendedData($npcData);

    return $cachedLookup;
}

function dialecticActionCatalogGetRuntimeRequirementContext()
{
    static $cachedKey = null;
    static $cachedContext = null;

    $requestType = strtolower(trim(strval($GLOBALS["gameRequest"][0] ?? '')));
    $cacheKey = implode('|', [
        trim(strval($GLOBALS["DIALECTIC_NAME"] ?? '')),
        trim(strval($GLOBALS["PLAYER_NAME"] ?? '')),
        !empty($GLOBALS["IS_NPC"]) ? '1' : '0',
        $requestType,
        strval($GLOBALS["gameRequest"][2] ?? ''),
        !empty($GLOBALS["is_rolemastered"]) ? '1' : '0',
    ]);

    if ($cachedKey === $cacheKey && is_array($cachedContext)) {
        return $cachedContext;
    }

    require_once __DIR__ . DIRECTORY_SEPARATOR . 'activity_status.php';

    $lookup = dialecticActionCatalogGetCurrentNpcLookup();
    $metadata = is_array($lookup['metadata']) ? $lookup['metadata'] : [];
    $extended = is_array($lookup['extended']) ? $lookup['extended'] : [];
    $activityStatus = dialecticNormalizeActivityStatus($metadata);

    $cachedKey = $cacheKey;
    $cachedContext = [
        'npc_name' => trim(strval($GLOBALS["DIALECTIC_NAME"] ?? '')),
        'player_name' => trim(strval($GLOBALS["PLAYER_NAME"] ?? '')),
        'request_type' => $requestType,
        'is_rechat' => in_array($requestType, ['rechat', 'narration'], true),
        'is_npc_mode' => !empty($GLOBALS["IS_NPC"]),
        'is_rolemastered' => dialecticResolveNpcRolemasterState($GLOBALS["DIALECTIC_NAME"] ?? '', [
            'metadata' => $metadata,
            'extended' => $extended,
            'npc_data' => $lookup['npc_data'],
            'load_lookup' => false,
        ]),
        'npc_master' => $lookup['npc_master'],
        'npc_data' => $lookup['npc_data'],
        'npc_metadata' => $metadata,
        'npc_extended' => $extended,
        'activity_status' => $activityStatus,
    ];

    return $cachedContext;
}

function dialecticActionCatalogGetConfigListValues($definition)
{
    $configKey = '';
    $fallbackCsv = '';
    $fallbackValues = [];

    if (is_string($definition)) {
        $configKey = trim($definition);
    } elseif (is_array($definition)) {
        $configKey = trim(strval($definition['config_key'] ?? ''));
        $fallbackCsv = trim(strval($definition['fallback_csv'] ?? ''));
        $fallbackValues = $definition['fallback_values'] ?? [];
    }

    $rawValues = '';
    if ($configKey !== '' && isset($GLOBALS[$configKey])) {
        $rawValues = trim(strval($GLOBALS[$configKey]));
    }
    if ($rawValues === '') {
        $rawValues = $fallbackCsv;
    }

    $values = dialecticActionCatalogNormalizeRequirementStringList($rawValues);
    if (count($fallbackValues) > 0) {
        $values = array_values(array_unique(array_merge(
            $values,
            dialecticActionCatalogNormalizeRequirementStringList($fallbackValues)
        )));
    }

    return $values;
}

function dialecticActionCatalogGetActionConfigListValues($config, $definition)
{
    $config = is_array($config) ? $config : [];
    $configKey = '';
    $fallbackCsv = '';
    $fallbackValues = [];

    if (is_string($definition)) {
        $configKey = trim($definition);
    } elseif (is_array($definition)) {
        $configKey = trim(strval($definition['config_key'] ?? ''));
        $fallbackCsv = trim(strval($definition['fallback_csv'] ?? ''));
        $fallbackValues = $definition['fallback_values'] ?? [];
    }

    $rawValues = '';
    if ($configKey !== '' && array_key_exists($configKey, $config)) {
        $rawValues = strval($config[$configKey]);
    }
    if (trim($rawValues) === '') {
        $rawValues = $fallbackCsv;
    }

    $values = dialecticActionCatalogNormalizeRequirementStringList(preg_split('/[\r\n,]+/', $rawValues) ?: []);
    if (count($fallbackValues) > 0) {
        $values = array_values(array_unique(array_merge(
            $values,
            dialecticActionCatalogNormalizeRequirementStringList($fallbackValues)
        )));
    }

    return $values;
}

function dialecticActionCatalogNpcMatchesFactionRequirement($npcMaster, $npcData, $factionIds, $requireAll = false)
{
    $factionIds = dialecticActionCatalogNormalizeRequirementStringList($factionIds);
    if (count($factionIds) === 0) {
        return true;
    }

    if (!$npcMaster || !is_array($npcData) || count($npcData) === 0) {
        return false;
    }

    $npcFactions = $npcMaster->getNpcFactions($npcData, true);

    foreach ($factionIds as $factionId) {
        $matches = false;
        $stableReference = dialecticParseStableFormReference($factionId);

        if ($stableReference) {
            foreach ($npcFactions as $npcFaction) {
                if (dialecticFactionEntryMatchesStableFormReference($npcFaction, $stableReference['stable_key'])) {
                    $matches = true;
                    break;
                }
            }

            if (!$matches) {
                $runtimeFormId = dialecticResolveStableFormReferenceToRuntimeFormId($stableReference['stable_key']);
                if ($runtimeFormId !== null) {
                    $matches = $npcMaster->isNpcInFaction($npcData, $runtimeFormId);
                }
            }
        } else {
            $matches = $npcMaster->isNpcInFaction($npcData, strtoupper($factionId));
        }

        if ($requireAll && !$matches) {
            return false;
        }
        if (!$requireAll && $matches) {
            return true;
        }
    }

    return $requireAll;
}

function dialecticActionCatalogMatchesActivityRequirements($requirements, $status)
{
    $requirements = dialecticActionCatalogDecodeJson($requirements, []);
    if (!is_array($requirements) || count($requirements) === 0) {
        return true;
    }

    $status = is_array($status) ? $status : [];
    $available = !empty($status['available']);
    $fresh = !empty($status['fresh']);

    if (!empty($requirements['require_available']) && !$available) {
        return false;
    }
    if (!empty($requirements['require_fresh']) && !$fresh) {
        return false;
    }

    $boolKeys = [
        'is_in_combat',
        'is_attacking',
        'is_moving',
        'is_running',
        'is_sneaking',
        'is_sitting',
        'is_sleeping',
        'is_unconscious',
        'is_dead',
        'is_weapon_drawn',
    ];

    foreach ($boolKeys as $boolKey) {
        if (!array_key_exists($boolKey, $requirements)) {
            continue;
        }

        $expected = dialecticActionCatalogToBool($requirements[$boolKey]);
        if (!$available) {
            if ($expected) {
                return false;
            }
            continue;
        }

        if (dialecticActionCatalogToBool($status[$boolKey] ?? false) !== $expected) {
            return false;
        }
    }

    $currentAction = strtolower(trim(strval($status['current_action'] ?? '')));
    $useType = strtolower(trim(strval($status['use_type'] ?? '')));

    if (isset($requirements['current_action'])) {
        $expectedAction = strtolower(trim(strval($requirements['current_action'])));
        if ($expectedAction !== '' && $currentAction !== $expectedAction) {
            return false;
        }
    }

    $currentActionIn = dialecticActionCatalogNormalizeRequirementStringList($requirements['current_action_in'] ?? []);
    if (count($currentActionIn) > 0) {
        if ($currentAction === '' || !in_array($currentAction, $currentActionIn, true)) {
            return false;
        }
    }

    $currentActionNotIn = dialecticActionCatalogNormalizeRequirementStringList($requirements['current_action_not_in'] ?? []);
    if ($currentAction !== '' && in_array($currentAction, $currentActionNotIn, true)) {
        return false;
    }

    if (isset($requirements['use_type'])) {
        $expectedUseType = strtolower(trim(strval($requirements['use_type'])));
        if ($expectedUseType !== '' && $useType !== $expectedUseType) {
            return false;
        }
    }

    $useTypeIn = dialecticActionCatalogNormalizeRequirementStringList($requirements['use_type_in'] ?? []);
    if (count($useTypeIn) > 0) {
        if ($useType === '' || !in_array($useType, $useTypeIn, true)) {
            return false;
        }
    }

    $useTypeNotIn = dialecticActionCatalogNormalizeRequirementStringList($requirements['use_type_not_in'] ?? []);
    if ($useType !== '' && in_array($useType, $useTypeNotIn, true)) {
        return false;
    }

    return true;
}

function dialecticActionCatalogRequirementsMatch($requirements, $context)
{
    $requirements = dialecticActionCatalogDecodeJson($requirements, []);
    if (!is_array($requirements) || count($requirements) === 0) {
        return true;
    }

    $context = is_array($context) ? $context : dialecticActionCatalogGetRuntimeRequirementContext();

    if (!empty($requirements['hide_in_rechat']) && !empty($context['is_rechat'])) {
        return false;
    }
    if (!empty($requirements['show_only_in_rechat']) && empty($context['is_rechat'])) {
        return false;
    }

    $requestTypesAny = dialecticActionCatalogNormalizeRequirementStringList($requirements['request_types_any'] ?? []);
    if (count($requestTypesAny) > 0 && !in_array(strtolower(trim(strval($context['request_type'] ?? ''))), $requestTypesAny, true)) {
        return false;
    }

    $requestTypesNone = dialecticActionCatalogNormalizeRequirementStringList($requirements['request_types_none'] ?? []);
    if (count($requestTypesNone) > 0 && in_array(strtolower(trim(strval($context['request_type'] ?? ''))), $requestTypesNone, true)) {
        return false;
    }

    $npcNamesAny = dialecticActionCatalogNormalizeRequirementStringList($requirements['npc_names_any'] ?? []);
    if (count($npcNamesAny) > 0 && !in_array(strtolower(trim(strval($context['npc_name'] ?? ''))), $npcNamesAny, true)) {
        return false;
    }

    if (isset($requirements['npc_name_in_config_list'])) {
        $allowedNpcNames = dialecticActionCatalogGetConfigListValues($requirements['npc_name_in_config_list']);
        if (count($allowedNpcNames) > 0 && !in_array(strtolower(trim(strval($context['npc_name'] ?? ''))), $allowedNpcNames, true)) {
            return false;
        }
    }

    if (isset($requirements['npc_name_in_action_config_list'])) {
        $allowedNpcNames = dialecticActionCatalogGetActionConfigListValues(
            $context['action_config'] ?? [],
            $requirements['npc_name_in_action_config_list']
        );
        if (count($allowedNpcNames) > 0 && !in_array(strtolower(trim(strval($context['npc_name'] ?? ''))), $allowedNpcNames, true)) {
            return false;
        }
    }

    if (!dialecticActionCatalogNpcMatchesFactionRequirement(
        $context['npc_master'] ?? null,
        $context['npc_data'] ?? [],
        $requirements['npc_factions_any'] ?? [],
        false
    )) {
        return false;
    }

    if (!dialecticActionCatalogNpcMatchesFactionRequirement(
        $context['npc_master'] ?? null,
        $context['npc_data'] ?? [],
        $requirements['npc_factions_all'] ?? [],
        true
    )) {
        return false;
    }

    if (!dialecticActionCatalogMatchesActivityRequirements($requirements['activity'] ?? [], $context['activity_status'] ?? [])) {
        return false;
    }

    return true;
}

function dialecticActionCatalogGetLastActionsIssuedMap()
{
    static $cachedKey = null;
    static $cachedRows = null;

    if (!isset($GLOBALS["db"]) || !($GLOBALS["db"] instanceof sql)) {
        return [];
    }

    $localActorName = trim(strval($GLOBALS["DIALECTIC_NAME"] ?? ''));
    if ($localActorName === '') {
        return [];
    }

    if ($cachedKey === $localActorName && is_array($cachedRows)) {
        return $cachedRows;
    }

    $escapedActorName = $GLOBALS["db"]->escape($localActorName);
    $rows = $GLOBALS["db"]->fetchAll(
        "SELECT * FROM (
            SELECT DISTINCT ON (action) *
            FROM actions_issued
            WHERE (actorname = '$escapedActorName' or actorname like '%$escapedActorName,%' or actorname='*')
            ORDER BY action, gamets DESC, ts DESC
        ) AS sub
        ORDER BY gamets DESC, ts DESC"
    );

    $cachedKey = $localActorName;
    $cachedRows = [];
    foreach ($rows as $row) {
        $actionCode = trim(strval($row['action'] ?? ''));
        if ($actionCode === '') {
            continue;
        }

        $cachedRows[$actionCode] = $row;
    }

    return $cachedRows;
}

function dialecticActionCatalogIsActionOnCooldown($codeName, $cooldownSeconds)
{
    $codeName = trim(strval($codeName));
    $cooldownSeconds = intval($cooldownSeconds);
    if ($codeName === '' || $cooldownSeconds <= 0 || empty($GLOBALS["gameRequest"][2])) {
        return false;
    }

    require_once __DIR__ . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . 'utils_game_timestamp.php';

    $lastActionsIssuedMap = dialecticActionCatalogGetLastActionsIssuedMap();
    if (!isset($lastActionsIssuedMap[$codeName])) {
        return false;
    }

    $ingameNow = convert_gamets2seconds($GLOBALS["gameRequest"][2]);
    $lastTriggered = convert_gamets2seconds($lastActionsIssuedMap[$codeName]["gamets"] ?? 0);
    if ($ingameNow <= 0 || $lastTriggered <= 0) {
        return false;
    }

    return ($ingameNow - $lastTriggered) < $cooldownSeconds;
}

function dialecticActionCatalogRowMatchesRequirements($row, $context = null)
{
    if (!is_array($row)) {
        return true;
    }

    $metadata = dialecticActionCatalogDecodeJson($row['metadata'] ?? [], []);
    $context = is_array($context) ? $context : dialecticActionCatalogGetRuntimeRequirementContext();
    $context['action_config'] = function_exists('dialecticActionCatalogGetResolvedCustomConfig')
        ? dialecticActionCatalogGetResolvedCustomConfig($row['code_name'] ?? '', $row)
        : [];

    if (!dialecticActionCatalogRequirementsMatch($metadata['requirements'] ?? [], $context)) {
        return false;
    }

    $cooldownSeconds = intval($metadata['cooldown_seconds'] ?? 0);
    if ($cooldownSeconds > 0 && dialecticActionCatalogIsActionOnCooldown($row['code_name'] ?? '', $cooldownSeconds)) {
        error_log("[FUNCTIONS COOLDOWN] Action '{$row['code_name']}' is on cooldown for {$cooldownSeconds} seconds.");
        return false;
    }

    return true;
}

function dialecticActionCatalogResetCache()
{
    unset($GLOBALS["DIALECTIC_ACTION_CATALOG_DB_READY"]);
    unset($GLOBALS["DIALECTIC_ACTION_CATALOG_ROWS_BY_CODE"]);
}

function dialecticActionCatalogDbReady()
{
    if (isset($GLOBALS["DIALECTIC_ACTION_CATALOG_DB_READY"])) {
        return $GLOBALS["DIALECTIC_ACTION_CATALOG_DB_READY"];
    }

    if (($GLOBALS["DBDRIVER"] ?? '') !== 'postgresql') {
        $GLOBALS["DIALECTIC_ACTION_CATALOG_DB_READY"] = false;
        return false;
    }

    if (!isset($GLOBALS["db"]) || !($GLOBALS["db"] instanceof sql)) {
        $GLOBALS["DIALECTIC_ACTION_CATALOG_DB_READY"] = false;
        return false;
    }

    $coreAction = $GLOBALS["db"]->fetchOne("
        SELECT 1 AS exists
        FROM information_schema.tables
        WHERE table_schema = 'public' AND table_name = 'core_action'
    ");
    $coreActionCustom = $GLOBALS["db"]->fetchOne("
        SELECT 1 AS exists
        FROM information_schema.tables
        WHERE table_schema = 'public' AND table_name = 'core_action_custom'
    ");
    $combinedView = $GLOBALS["db"]->fetchOne("
        SELECT 1 AS exists
        FROM information_schema.views
        WHERE table_schema = 'public' AND table_name = 'combined_core_action'
    ");

    $ready = isset($coreAction["exists"]) && isset($coreActionCustom["exists"]) && isset($combinedView["exists"]);
    $GLOBALS["DIALECTIC_ACTION_CATALOG_DB_READY"] = $ready;
    return $ready;
}

function dialecticActionCatalogGetExistingCustomImportVersion($codeName)
{
    $codeName = trim(strval($codeName));
    if ($codeName === '' || !dialecticActionCatalogDbReady()) {
        return null;
    }

    $row = $GLOBALS["db"]->fetchOne("
        SELECT import_version
        FROM public.core_action_custom
        WHERE LOWER(code_name) = LOWER(" . dialecticActionCatalogSqlText($codeName) . ")
        LIMIT 1
    ");

    if (!is_array($row) || !array_key_exists('import_version', $row)) {
        return null;
    }

    return dialecticActionCatalogNormalizeImportVersion($row['import_version']);
}

function dialecticActionCatalogResolveFunctionCodeName($functionName)
{
    $resolver = $GLOBALS['DIALECTIC_ACTION_CODE_RESOLVER'] ?? null;
    if (is_callable($resolver)) {
        return $resolver($functionName);
    }

    return function_exists('getFunctionCodeName') ? getFunctionCodeName($functionName) : false;
}

function dialecticBuildActionCatalogFunctionDefinitionsByCode($runtimeFunctions = null)
{
    $definitions = [];
    $runtimeFunctions = is_array($runtimeFunctions) ? $runtimeFunctions : ($GLOBALS["FUNCTIONS"] ?? []);
    $canonicalCodes = dialecticActionCatalogCanonicalActionCodeSet();

    foreach ($runtimeFunctions as $functionEntry) {
        if (!is_array($functionEntry) || empty($functionEntry['name'])) {
            continue;
        }

        $codeName = dialecticActionCatalogResolveFunctionCodeName($functionEntry['name']);
        if ($codeName === false || !isset($canonicalCodes[$codeName])) {
            continue;
        }

        $definitions[$codeName] = $functionEntry;
    }

    return $definitions;
}

function dialecticBuildActionCatalogSeedRows($actionNames, $descriptions, $returnMessages, $currentEnabledCodes = [], $defaultEnabledCodes = [], $functionDefinitionsByCode = [], $seedDefaultsByCode = null)
{
    $seedDefaultsByCode = is_array($seedDefaultsByCode) ? $seedDefaultsByCode : dialecticLoadActionCatalogBaseSeedRowsFromSeedFile();
    $activationDefaults = count($defaultEnabledCodes) > 0
        ? $defaultEnabledCodes
        : array_unique(array_merge(
            array_keys($seedDefaultsByCode),
            is_array($currentEnabledCodes) ? $currentEnabledCodes : []
        ));
    $allCodeNames = array_unique(array_merge(
        array_keys(is_array($actionNames) ? $actionNames : []),
        array_keys(is_array($descriptions) ? $descriptions : []),
        array_keys(is_array($returnMessages) ? $returnMessages : []),
        is_array($currentEnabledCodes) ? $currentEnabledCodes : [],
        $activationDefaults,
        array_keys($seedDefaultsByCode),
        array_keys(is_array($functionDefinitionsByCode) ? $functionDefinitionsByCode : [])
    ));

    natcasesort($allCodeNames);

    $canonicalCodes = dialecticActionCatalogCanonicalActionCodeSet();
    $scriptProxyPrograms = dialecticActionCatalogGetBaseScriptProxyPrograms();
    $rows = [];

    foreach ($allCodeNames as $codeName) {
        $codeName = trim(strval($codeName));
        if ($codeName === '' || !isset($canonicalCodes[$codeName])) {
            continue;
        }

        $seedDefaults = is_array($seedDefaultsByCode[$codeName] ?? null) ? $seedDefaultsByCode[$codeName] : [];
        $availableToNpc = dialecticActionCatalogToBool($seedDefaults['available_to_npc'] ?? false);
        $availableToFollowers = dialecticActionCatalogToBool($seedDefaults['available_to_followers'] ?? false);
        $availableToNarrator = dialecticActionCatalogToBool($seedDefaults['available_to_narrator'] ?? false);
        $isActivated = array_key_exists('is_activated', $seedDefaults)
            ? dialecticActionCatalogToBool($seedDefaults['is_activated'])
            : (in_array($codeName, $activationDefaults, true) || in_array($codeName, $currentEnabledCodes, true));
        $functionDefinition = is_array($functionDefinitionsByCode[$codeName] ?? null) ? $functionDefinitionsByCode[$codeName] : [];
        $parameters = dialecticActionCatalogNormalizeParameterSchema($functionDefinition['parameters'] ?? null);
        $scriptProxyProgram = $scriptProxyPrograms[$codeName] ?? null;
        $metadata = dialecticActionCatalogBuildBaseMetadata($codeName, $scriptProxyProgram);

        $rows[$codeName] = [
            'code_name' => $codeName,
            'action_name' => isset($actionNames[$codeName]) && trim(strval($actionNames[$codeName])) !== ''
                ? dialecticNormalizeActionCatalogDisplayActionName($actionNames[$codeName])
                : $codeName,
            'description' => isset($descriptions[$codeName]) ? dialecticNormalizeActionCatalogDisplayText($descriptions[$codeName]) : '',
            'return_message' => isset($returnMessages[$codeName]) ? dialecticNormalizeActionCatalogDisplayText($returnMessages[$codeName]) : '',
            'available_to_npc' => $availableToNpc,
            'available_to_followers' => $availableToFollowers,
            'available_to_narrator' => $availableToNarrator,
            'is_activated' => $isActivated,
            'parameters_json' => $parameters,
            'metadata' => $metadata,
            'game_function' => dialecticActionCatalogIsGameFunction($metadata),
            'import_version' => 0,
            'script_proxy_program' => $scriptProxyProgram,
        ];
    }

    return $rows;
}

function dialecticDeleteNonCanonicalActionCatalogRows($updateCustomRows = true)
{
    if (!dialecticActionCatalogDbReady()) {
        return;
    }

    $canonicalCodes = dialecticActionCatalogCanonicalActionCodes();
    if (count($canonicalCodes) === 0) {
        return;
    }

    $literals = [];
    foreach ($canonicalCodes as $canonicalCode) {
        $literals[] = dialecticActionCatalogSqlText($canonicalCode);
    }

    $inList = implode(',', $literals);
    if ($updateCustomRows) {
        $GLOBALS["db"]->execQuery("DELETE FROM public.core_action_custom WHERE code_name NOT IN ({$inList})");
    }
    $GLOBALS["db"]->execQuery("DELETE FROM public.core_action WHERE code_name NOT IN ({$inList})");
}

function dialecticSyncActionCatalogBaseRows($rowsByCode, $updateCustomRows = true)
{
    if (!dialecticActionCatalogDbReady()) {
        return;
    }

    dialecticDeleteNonCanonicalActionCatalogRows($updateCustomRows);
    dialecticDeleteUnexpectedBaseActionCatalogRows($rowsByCode, $updateCustomRows);

    $existingCustomMetadataByCode = [];
    if ($updateCustomRows) {
        $existingCustomRows = $GLOBALS["db"]->fetchAll("
            SELECT code_name, metadata
            FROM public.core_action_custom
        ");
        foreach ($existingCustomRows as $existingCustomRow) {
            $existingCodeName = strtolower(trim(strval($existingCustomRow['code_name'] ?? '')));
            if ($existingCodeName === '') {
                continue;
            }

            $existingCustomMetadataByCode[$existingCodeName] = dialecticActionCatalogDecodeJson($existingCustomRow['metadata'] ?? [], []);
        }
    }

    foreach ($rowsByCode as $row) {
        if (!is_array($row) || empty($row['code_name'])) {
            continue;
        }

        $preservedCustomMetadata = dialecticActionCatalogMergePreservedCustomMetadata(
            $row['metadata'] ?? [],
            $existingCustomMetadataByCode[strtolower(trim(strval($row['code_name'])))] ?? []
        );

        $GLOBALS["db"]->execQuery("
            INSERT INTO public.core_action (
                code_name,
                action_name,
                description,
                return_message,
                available_to_npc,
                available_to_followers,
                available_to_narrator,
                is_activated,
                parameters_json,
                metadata,
                game_function,
                import_version,
                script_proxy_program
            ) VALUES (
                " . dialecticActionCatalogSqlText($row['code_name']) . ",
                " . dialecticActionCatalogSqlText($row['action_name'] ?? '') . ",
                " . dialecticActionCatalogSqlText($row['description'] ?? '') . ",
                " . dialecticActionCatalogSqlText($row['return_message'] ?? '') . ",
                " . dialecticActionCatalogSqlBool(!empty($row['available_to_npc'])) . ",
                " . dialecticActionCatalogSqlBool(!empty($row['available_to_followers'])) . ",
                " . dialecticActionCatalogSqlBool(!empty($row['available_to_narrator'])) . ",
                " . dialecticActionCatalogSqlBool(!empty($row['is_activated'])) . ",
                " . dialecticActionCatalogSqlJson($row['parameters_json'] ?? []) . ",
                " . dialecticActionCatalogSqlJson($row['metadata'] ?? []) . ",
                " . dialecticActionCatalogSqlBool(!empty($row['game_function'])) . ",
                " . dialecticActionCatalogNormalizeImportVersion($row['import_version'] ?? 0) . ",
                " . dialecticActionCatalogSqlJson($row['script_proxy_program'] ?? null, true) . "
            )
            ON CONFLICT (code_name) DO UPDATE SET
                action_name = EXCLUDED.action_name,
                description = EXCLUDED.description,
                return_message = EXCLUDED.return_message,
                available_to_npc = EXCLUDED.available_to_npc,
                available_to_followers = EXCLUDED.available_to_followers,
                available_to_narrator = EXCLUDED.available_to_narrator,
                is_activated = EXCLUDED.is_activated,
                parameters_json = EXCLUDED.parameters_json,
                metadata = EXCLUDED.metadata,
                game_function = EXCLUDED.game_function,
                import_version = EXCLUDED.import_version,
                script_proxy_program = EXCLUDED.script_proxy_program,
                updated_at = NOW()
        ");

        if ($updateCustomRows) {
            $GLOBALS["db"]->execQuery("
                UPDATE public.core_action_custom
                SET
                    action_name = " . dialecticActionCatalogSqlText($row['action_name'] ?? '') . ",
                    description = " . dialecticActionCatalogSqlText($row['description'] ?? '') . ",
                    return_message = " . dialecticActionCatalogSqlText($row['return_message'] ?? '') . ",
                    available_to_npc = " . dialecticActionCatalogSqlBool(!empty($row['available_to_npc'])) . ",
                    available_to_followers = " . dialecticActionCatalogSqlBool(!empty($row['available_to_followers'])) . ",
                    available_to_narrator = " . dialecticActionCatalogSqlBool(!empty($row['available_to_narrator'])) . ",
                    parameters_json = " . dialecticActionCatalogSqlJson($row['parameters_json'] ?? []) . ",
                    metadata = " . dialecticActionCatalogSqlJson($preservedCustomMetadata) . ",
                    game_function = " . dialecticActionCatalogSqlBool(!empty($row['game_function'])) . ",
                    import_version = " . dialecticActionCatalogNormalizeImportVersion($row['import_version'] ?? 0) . ",
                    script_proxy_program = " . dialecticActionCatalogSqlJson($row['script_proxy_program'] ?? null, true) . ",
                    updated_at = NOW()
                WHERE LOWER(code_name) = LOWER(" . dialecticActionCatalogSqlText($row['code_name']) . ")
            ");
        }
    }

    dialecticActionCatalogResetCache();
}

function dialecticDeleteUnexpectedBaseActionCatalogRows($rowsByCode, $updateCustomRows = true)
{
    if (!dialecticActionCatalogDbReady()) {
        return;
    }

    $seedCodeLiterals = [];
    foreach ($rowsByCode as $row) {
        if (!is_array($row) || empty($row['code_name'])) {
            continue;
        }

        $seedCodeLiterals[] = dialecticActionCatalogSqlText(strtolower(trim(strval($row['code_name']))));
    }

    if (count($seedCodeLiterals) === 0) {
        return;
    }

    $seedCodeList = implode(',', array_unique($seedCodeLiterals));
    $builtinFilter = "metadata @> '{\"source\":\"functions.php\",\"builtin\":true}'::jsonb";

    $GLOBALS["db"]->execQuery("
        DELETE FROM public.core_action
        WHERE {$builtinFilter}
          AND LOWER(code_name) NOT IN ({$seedCodeList})
    ");

    if ($updateCustomRows) {
        $GLOBALS["db"]->execQuery("
            DELETE FROM public.core_action_custom
            WHERE {$builtinFilter}
              AND LOWER(code_name) NOT IN ({$seedCodeList})
        ");
    }
}

function dialecticGetActionCatalogRowsByCode()
{
    if (isset($GLOBALS["DIALECTIC_ACTION_CATALOG_ROWS_BY_CODE"])) {
        return $GLOBALS["DIALECTIC_ACTION_CATALOG_ROWS_BY_CODE"];
    }

    $GLOBALS["DIALECTIC_ACTION_CATALOG_ROWS_BY_CODE"] = [];
    if (!dialecticActionCatalogDbReady()) {
        return $GLOBALS["DIALECTIC_ACTION_CATALOG_ROWS_BY_CODE"];
    }

    $rows = $GLOBALS["db"]->fetchAll("
        SELECT
            code_name,
            action_name,
            description,
            return_message,
            available_to_npc,
            available_to_followers,
            available_to_narrator,
            is_activated,
            parameters_json,
            metadata,
            game_function,
            import_version,
            script_proxy_program
        FROM public.combined_core_action
    ");

    foreach ($rows as $row) {
        $codeName = trim(strval($row['code_name'] ?? ''));
        if ($codeName === '') {
            continue;
        }

        $normalizedRow = [
            'code_name' => $codeName,
            'action_name' => dialecticNormalizeActionCatalogDisplayActionName(strval($row['action_name'] ?? $codeName)),
            'description' => strval($row['description'] ?? ''),
            'return_message' => strval($row['return_message'] ?? ''),
            'available_to_npc' => dialecticActionCatalogToBool($row['available_to_npc'] ?? false),
            'available_to_followers' => dialecticActionCatalogToBool($row['available_to_followers'] ?? false),
            'available_to_narrator' => dialecticActionCatalogToBool($row['available_to_narrator'] ?? false),
            'is_activated' => dialecticActionCatalogToBool($row['is_activated'] ?? false),
            'parameters_json' => dialecticActionCatalogNormalizeParameterSchema(
                dialecticActionCatalogDecodeJson($row['parameters_json'] ?? [], [])
            ),
            'metadata' => dialecticActionCatalogDecodeJson($row['metadata'] ?? [], []),
            'game_function' => dialecticActionCatalogToBool($row['game_function'] ?? false),
            'import_version' => dialecticActionCatalogNormalizeImportVersion($row['import_version'] ?? 0),
            'script_proxy_program' => dialecticActionCatalogDecodeJson($row['script_proxy_program'] ?? null, []),
        ];
        $GLOBALS["DIALECTIC_ACTION_CATALOG_ROWS_BY_CODE"][$codeName] = $normalizedRow;
    }

    return $GLOBALS["DIALECTIC_ACTION_CATALOG_ROWS_BY_CODE"];
}

function dialecticGetActionCatalogRow($codeName)
{
    $codeName = trim(strval($codeName));
    if ($codeName === '') {
        return null;
    }

    $rowsByCode = dialecticGetActionCatalogRowsByCode();
    return $rowsByCode[$codeName] ?? null;
}

function dialecticActionCatalogMetadataFlagEnabled($codeName, $flagName): bool
{
    $flagName = trim(strval($flagName));
    if ($flagName === '') {
        return false;
    }

    $row = dialecticGetActionCatalogRow($codeName);
    if (!is_array($row)) {
        return false;
    }

    $metadata = $row['metadata'] ?? [];
    if (!is_array($metadata) || !array_key_exists($flagName, $metadata)) {
        return false;
    }

    return dialecticActionCatalogToBool($metadata[$flagName]);
}

function dialecticFindActionCatalogRowByNameOrCode($actionNameOrCode, $requireCurrentMode = false)
{
    $actionNameOrCode = trim(strval($actionNameOrCode));
    if ($actionNameOrCode === '') {
        return null;
    }

    $rowsByCode = dialecticGetActionCatalogRowsByCode();
    if (count($rowsByCode) === 0) {
        return null;
    }

    $normalizedSearchName = function_exists('dialecticNormalizeActionCatalogDisplayActionName')
        ? trim(strval(dialecticNormalizeActionCatalogDisplayActionName($actionNameOrCode)))
        : $actionNameOrCode;

    $matchedRow = null;
    foreach ($rowsByCode as $row) {
        if (!is_array($row) || empty($row['code_name'])) {
            continue;
        }
        if ($requireCurrentMode && !dialecticActionCatalogRowIsAvailableInCurrentMode($row)) {
            continue;
        }

        $rowCodeName = trim(strval($row['code_name'] ?? ''));
        $rawActionName = trim(strval($row['action_name'] ?? ''));
        $runtimeActionName = function_exists('dialecticFormatActionPromptTemplate')
            ? trim(strval(dialecticFormatActionPromptTemplate($rawActionName, [], $row)))
            : $rawActionName;
        $normalizedRuntimeActionName = function_exists('dialecticNormalizeActionCatalogDisplayActionName')
            ? trim(strval(dialecticNormalizeActionCatalogDisplayActionName($runtimeActionName)))
            : $runtimeActionName;

        $isMatch = strcasecmp($rowCodeName, $actionNameOrCode) === 0
            || strcasecmp($rawActionName, $actionNameOrCode) === 0
            || strcasecmp($runtimeActionName, $actionNameOrCode) === 0
            || ($normalizedSearchName !== '' && strcasecmp($normalizedRuntimeActionName, $normalizedSearchName) === 0);
        if (!$isMatch) {
            continue;
        }

        if ($matchedRow === null || dialecticActionCatalogShouldPreferRowForActionName($row, $matchedRow)) {
            $matchedRow = $row;
        }
    }

    return $matchedRow;
}

function dialecticResolveActionCatalogCodeName($actionNameOrCode, $requireCurrentMode = false)
{
    $row = dialecticFindActionCatalogRowByNameOrCode($actionNameOrCode, $requireCurrentMode);
    if (!is_array($row) || empty($row['code_name'])) {
        return false;
    }

    return trim(strval($row['code_name']));
}

function dialecticFindActionCatalogActionNameConflict($actionName, $excludeCodeName = '')
{
    $actionName = trim(strval($actionName));
    $excludeCodeName = trim(strval($excludeCodeName));
    if ($actionName === '') {
        return null;
    }

    $rowsByCode = dialecticGetActionCatalogRowsByCode();
    if (count($rowsByCode) === 0) {
        return null;
    }

    $normalizedSearchName = function_exists('dialecticNormalizeActionCatalogDisplayActionName')
        ? trim(strval(dialecticNormalizeActionCatalogDisplayActionName($actionName)))
        : $actionName;
    if ($normalizedSearchName === '') {
        return null;
    }

    foreach ($rowsByCode as $row) {
        if (!is_array($row) || empty($row['code_name'])) {
            continue;
        }

        $rowCodeName = trim(strval($row['code_name'] ?? ''));
        if ($rowCodeName === '') {
            continue;
        }
        if ($excludeCodeName !== '' && strcasecmp($rowCodeName, $excludeCodeName) === 0) {
            continue;
        }

        $rawActionName = trim(strval($row['action_name'] ?? ''));
        $runtimeActionName = function_exists('dialecticFormatActionPromptTemplate')
            ? trim(strval(dialecticFormatActionPromptTemplate($rawActionName, [], $row)))
            : $rawActionName;
        $normalizedCodeName = function_exists('dialecticNormalizeActionCatalogDisplayActionName')
            ? trim(strval(dialecticNormalizeActionCatalogDisplayActionName($rowCodeName)))
            : $rowCodeName;
        $normalizedRawActionName = function_exists('dialecticNormalizeActionCatalogDisplayActionName')
            ? trim(strval(dialecticNormalizeActionCatalogDisplayActionName($rawActionName)))
            : $rawActionName;
        $normalizedRuntimeActionName = function_exists('dialecticNormalizeActionCatalogDisplayActionName')
            ? trim(strval(dialecticNormalizeActionCatalogDisplayActionName($runtimeActionName)))
            : $runtimeActionName;

        if (
            ($normalizedCodeName !== '' && strcasecmp($normalizedCodeName, $normalizedSearchName) === 0) ||
            ($normalizedRawActionName !== '' && strcasecmp($normalizedRawActionName, $normalizedSearchName) === 0) ||
            ($normalizedRuntimeActionName !== '' && strcasecmp($normalizedRuntimeActionName, $normalizedSearchName) === 0)
        ) {
            return $row;
        }
    }

    return null;
}

function dialecticActionCatalogGetCustomConfigValue($codeName, $configKey, $default = null)
{
    $codeName = trim(strval($codeName));
    $configKey = trim(strval($configKey));
    if ($codeName === '' || $configKey === '') {
        return $default;
    }

    $row = dialecticGetActionCatalogRow($codeName);
    if (!is_array($row)) {
        return $default;
    }

    $config = dialecticActionCatalogGetResolvedCustomConfig($codeName, $row);
    if (!array_key_exists($configKey, $config)) {
        return $default;
    }

    return $config[$configKey];
}

function dialecticLoadEnabledActionCodesForMode($isNpc, $applyRequirements = false)
{
    $rowsByCode = dialecticGetActionCatalogRowsByCode();
    if (count($rowsByCode) === 0) {
        return [];
    }

    $enabledCodes = [];
    foreach ($rowsByCode as $codeName => $row) {
        if (!$row['is_activated']) {
            continue;
        }

        if ($applyRequirements && !dialecticActionCatalogRowMatchesRequirements($row)) {
            continue;
        }

        if (dialecticActionCatalogIsNarratorMode()) {
            if (!empty($row['available_to_narrator'])) {
                $enabledCodes[] = $codeName;
            }
        } elseif ($isNpc && !empty($row['available_to_npc'])) {
            $enabledCodes[] = $codeName;
        } elseif (!$isNpc && !empty($row['available_to_followers'])) {
            $enabledCodes[] = $codeName;
        }
    }

    return array_values(array_unique($enabledCodes));
}

function dialecticActionCatalogIsActionEnabled($codeName)
{
    $codeName = trim(strval($codeName));
    if ($codeName === '') {
        return false;
    }

    $rowsByCode = dialecticGetActionCatalogRowsByCode();
    if (!isset($rowsByCode[$codeName])) {
        return true;
    }

    return !empty($rowsByCode[$codeName]['is_activated']);
}

function dialecticActionCatalogIsNarratorMode()
{
    $requestType = strtolower(trim(strval($GLOBALS["gameRequest"][0] ?? '')));
    if (in_array($requestType, [
        'narrator_inputtext',
        'narration',
        'narrator_welcome',
        'narrator_quest_comment',
    ], true)) {
        return true;
    }

    if (!empty($GLOBALS["DIRECT_NARRATOR_DIALOGUE"])) {
        return true;
    }

    return strcasecmp(trim(strval($GLOBALS["DIALECTIC_NAME"] ?? '')), 'The Narrator') === 0;
}

function dialecticActionCatalogBuildFunctionEntryFromRow($row)
{
    if (!is_array($row) || empty($row['code_name']) || trim(strval($row['action_name'] ?? '')) === '') {
        return null;
    }

    $runtimeActionName = function_exists('dialecticFormatActionPromptTemplate')
        ? dialecticFormatActionPromptTemplate(strval($row['action_name'] ?? ''), [], $row)
        : strval($row['action_name'] ?? '');
    $runtimeDescription = function_exists('dialecticFormatActionPromptTemplate')
        ? dialecticFormatActionPromptTemplate(strval($row['description'] ?? ''), [], $row)
        : strval($row['description'] ?? '');

    return [
        'name' => $runtimeActionName,
        'description' => $runtimeDescription,
        'parameters' => dialecticActionCatalogNormalizeParameterSchema($row['parameters_json'] ?? null),
    ];
}

function dialecticActionCatalogRowIsAvailableInCurrentMode($row)
{
    if (dialecticActionCatalogIsNarratorMode()) {
        return !empty($row['available_to_narrator']);
    }

    $isNpcMode = !empty($GLOBALS["IS_NPC"]);
    if ($isNpcMode) {
        return !empty($row['available_to_npc']);
    }

    return !empty($row['available_to_followers']);
}

function dialecticActionCatalogHasBaseRows()
{
    if (!dialecticActionCatalogDbReady()) {
        return false;
    }

    $row = $GLOBALS["db"]->fetchOne("
        SELECT 1 AS has_row
        FROM public.core_action
        LIMIT 1
    ");

    return is_array($row) && !empty($row['has_row']);
}

function dialecticGetActionCatalogBaseSeedFilePath()
{
    return dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'core_action_seed.sql';
}

function dialecticSeedActionCatalogBaseRowsFromSeedFile()
{
    if (!dialecticActionCatalogDbReady()) {
        return false;
    }

    $seedFile = dialecticGetActionCatalogBaseSeedFilePath();
    if (!file_exists($seedFile)) {
        return false;
    }

    $sql = trim(strval(file_get_contents($seedFile)));
    if ($sql === '') {
        return false;
    }

    try {
        $GLOBALS["db"]->execQuery($sql);
        dialecticActionCatalogResetCache();
        return dialecticActionCatalogHasBaseRows();
    } catch (Throwable $e) {
        if (class_exists('Logger')) {
            Logger::warn("core_action seed import failed: " . $e->getMessage());
        }
    }

    return false;
}

function dialecticEnsureActionCatalogBaseRowsSeeded($rowsByCode)
{
    if (!dialecticActionCatalogDbReady() || dialecticActionCatalogHasBaseRows()) {
        return false;
    }

    if (dialecticSeedActionCatalogBaseRowsFromSeedFile()) {
        return true;
    }

    dialecticSyncActionCatalogBaseRows($rowsByCode, false);
    dialecticActionCatalogResetCache();
    return true;
}

function dialecticActionCatalogRowIsUsableInCurrentContext($row)
{
    if (!is_array($row) || empty($row['is_activated'])) {
        return false;
    }

    if (!dialecticActionCatalogRowIsAvailableInCurrentMode($row)) {
        return false;
    }

    return dialecticActionCatalogRowMatchesRequirements($row);
}

function dialecticActionCatalogShouldPreferRowForActionName($candidateRow, $currentRow)
{
    $candidateAvailable = dialecticActionCatalogRowIsUsableInCurrentContext($candidateRow);
    $currentAvailable = dialecticActionCatalogRowIsUsableInCurrentContext($currentRow);
    if ($candidateAvailable !== $currentAvailable) {
        return $candidateAvailable;
    }

    $candidateEnabled = !empty($candidateRow['is_activated']);
    $currentEnabled = !empty($currentRow['is_activated']);
    if ($candidateEnabled !== $currentEnabled) {
        return $candidateEnabled;
    }

    $candidateBuiltin = !empty(($candidateRow['metadata'] ?? [])['builtin']);
    $currentBuiltin = !empty(($currentRow['metadata'] ?? [])['builtin']);
    if ($candidateBuiltin !== $currentBuiltin) {
        return !$candidateBuiltin;
    }

    $candidateDispatch = strtolower(trim(strval(($candidateRow['metadata'] ?? [])['dispatch'] ?? '')));
    $currentDispatch = strtolower(trim(strval(($currentRow['metadata'] ?? [])['dispatch'] ?? '')));
    if ($candidateDispatch !== $currentDispatch) {
        if ($candidateDispatch === 'script_proxy') {
            return true;
        }
        if ($currentDispatch === 'script_proxy') {
            return false;
        }
    }

    return false;
}

function dialecticActionCatalogApplyRowsToRuntimeFunctions()
{
    $rowsByCode = dialecticGetActionCatalogRowsByCode();
    if (count($rowsByCode) === 0) {
        return;
    }

    $runtimeFunctionMap = [];
    $fallbackBaseFunctionMap = is_array($GLOBALS["DIALECTIC_BASE_FUNCTIONS_FALLBACK"] ?? null)
        ? $GLOBALS["DIALECTIC_BASE_FUNCTIONS_FALLBACK"]
        : [];
    foreach ($GLOBALS["FUNCTIONS"] ?? [] as $functionEntry) {
        if (!is_array($functionEntry) || empty($functionEntry['name'])) {
            continue;
        }

        $codeName = dialecticActionCatalogResolveFunctionCodeName($functionEntry['name']);
        if ($codeName === false) {
            continue;
        }

        if (isset($fallbackBaseFunctionMap[$codeName])) {
            continue;
        }

        $runtimeFunctionMap[$codeName] = $functionEntry;
    }

    foreach ($rowsByCode as $codeName => $row) {
        $runtimeActionName = function_exists('dialecticFormatActionPromptTemplate')
            ? dialecticFormatActionPromptTemplate(strval($row['action_name'] ?? ''), [], $row)
            : strval($row['action_name'] ?? '');
        $runtimeDescription = function_exists('dialecticFormatActionPromptTemplate')
            ? dialecticFormatActionPromptTemplate($row['description'] ?? '', [], $row)
            : strval($row['description'] ?? '');

        $GLOBALS["F_NAMES"][$codeName] = $runtimeActionName;
        $GLOBALS["F_DESCRIPTIONS"][$codeName] = $runtimeDescription;
        $GLOBALS["F_RETURNMESSAGES"][$codeName] = strval($row['return_message'] ?? '');

        $catalogFunctionEntry = dialecticActionCatalogBuildFunctionEntryFromRow($row);
        if ($catalogFunctionEntry === null) {
            continue;
        }

        $catalogFunctionEntry['description'] = $runtimeDescription;

        if (isset($runtimeFunctionMap[$codeName])) {
            $runtimeFunctionMap[$codeName]['name'] = $catalogFunctionEntry['name'];
            $runtimeFunctionMap[$codeName]['description'] = $runtimeDescription;
            $runtimeFunctionMap[$codeName]['parameters'] = $catalogFunctionEntry['parameters'];
        } else {
            $runtimeFunctionMap[$codeName] = $catalogFunctionEntry;
        }
    }

    $preferredCodeByActionName = [];
    foreach ($runtimeFunctionMap as $codeName => $functionEntry) {
        $actionName = trim(strval($functionEntry['name'] ?? ''));
        if ($actionName === '') {
            continue;
        }

        if (!isset($preferredCodeByActionName[$actionName])) {
            $preferredCodeByActionName[$actionName] = $codeName;
            continue;
        }

        $currentCode = $preferredCodeByActionName[$actionName];
        $candidateRow = $rowsByCode[$codeName] ?? ['metadata' => ['builtin' => true], 'is_activated' => true];
        $currentRow = $rowsByCode[$currentCode] ?? ['metadata' => ['builtin' => true], 'is_activated' => true];
        if (dialecticActionCatalogShouldPreferRowForActionName($candidateRow, $currentRow)) {
            $preferredCodeByActionName[$actionName] = $codeName;
        }
    }

    $dedupedRuntimeFunctionMap = [];
    foreach ($preferredCodeByActionName as $actionName => $codeName) {
        if (isset($runtimeFunctionMap[$codeName])) {
            $dedupedRuntimeFunctionMap[$codeName] = $runtimeFunctionMap[$codeName];
        }
    }

    $GLOBALS["DIALECTIC_ACTION_NAME_PREFERRED_CODE"] = $preferredCodeByActionName;
    $GLOBALS["BASE_FUNCTIONS"] = $dedupedRuntimeFunctionMap;
    $GLOBALS["FUNCTIONS"] = array_values($dedupedRuntimeFunctionMap);
}

function dialecticActionCatalogUpsertCustomToggle($codeName, $enabled)
{
    $codeName = trim(strval($codeName));
    if ($codeName === '' || !dialecticActionCatalogDbReady()) {
        return false;
    }

    $literalCode = dialecticActionCatalogSqlText($codeName);
    $row = $GLOBALS["db"]->fetchOne("
        SELECT
            code_name,
            action_name,
            description,
            return_message,
            available_to_npc,
            available_to_followers,
            available_to_narrator,
            parameters_json,
            metadata,
            game_function,
            import_version,
            script_proxy_program
        FROM public.combined_core_action
        WHERE code_name = {$literalCode}
        LIMIT 1
    ");

    if (!$row) {
        return false;
    }

    $actionName = dialecticNormalizeActionCatalogDisplayActionName(strval($row['action_name'] ?? ''));

    $result = $GLOBALS["db"]->execQuery("
        INSERT INTO public.core_action_custom (
            code_name,
            action_name,
            description,
            return_message,
            available_to_npc,
            available_to_followers,
            available_to_narrator,
            is_activated,
            parameters_json,
            metadata,
            game_function,
            import_version,
            script_proxy_program
        ) VALUES (
            " . dialecticActionCatalogSqlText($row['code_name'] ?? $codeName) . ",
            " . dialecticActionCatalogSqlText($actionName) . ",
            " . dialecticActionCatalogSqlText($row['description'] ?? '') . ",
            " . dialecticActionCatalogSqlText($row['return_message'] ?? '') . ",
            " . dialecticActionCatalogSqlBool(dialecticActionCatalogToBool($row['available_to_npc'] ?? false)) . ",
            " . dialecticActionCatalogSqlBool(dialecticActionCatalogToBool($row['available_to_followers'] ?? false)) . ",
            " . dialecticActionCatalogSqlBool(dialecticActionCatalogToBool($row['available_to_narrator'] ?? false)) . ",
            " . dialecticActionCatalogSqlBool((bool) $enabled) . ",
            " . dialecticActionCatalogSqlJson($row['parameters_json'] ?? []) . ",
            " . dialecticActionCatalogSqlJson($row['metadata'] ?? []) . ",
            " . dialecticActionCatalogSqlBool(dialecticActionCatalogToBool($row['game_function'] ?? false)) . ",
            " . dialecticActionCatalogNormalizeImportVersion($row['import_version'] ?? 0) . ",
            " . dialecticActionCatalogSqlJson($row['script_proxy_program'] ?? null, true) . "
        )
        ON CONFLICT (code_name) DO UPDATE SET
            action_name = EXCLUDED.action_name,
            description = EXCLUDED.description,
            return_message = EXCLUDED.return_message,
            available_to_npc = EXCLUDED.available_to_npc,
            available_to_followers = EXCLUDED.available_to_followers,
            available_to_narrator = EXCLUDED.available_to_narrator,
            is_activated = EXCLUDED.is_activated,
            parameters_json = EXCLUDED.parameters_json,
            metadata = EXCLUDED.metadata,
            game_function = EXCLUDED.game_function,
            import_version = EXCLUDED.import_version,
            script_proxy_program = EXCLUDED.script_proxy_program,
            updated_at = NOW()
    ");

    dialecticActionCatalogResetCache();
    return $result !== false;
}

function dialecticActionCatalogDeleteCustomOverride($codeName)
{
    $codeName = trim(strval($codeName));
    if ($codeName === '' || !dialecticActionCatalogDbReady()) {
        return false;
    }

    $result = $GLOBALS["db"]->execQuery("
        DELETE FROM public.core_action_custom
        WHERE LOWER(code_name) = LOWER(" . dialecticActionCatalogSqlText($codeName) . ")
    ");

    dialecticActionCatalogResetCache();
    return $result !== false;
}

function dialecticActionCatalogUpsertCustomConfigValue($codeName, $configKey, $value)
{
    $codeName = trim(strval($codeName));
    $configKey = trim(strval($configKey));
    if ($codeName === '' || $configKey === '' || !dialecticActionCatalogDbReady()) {
        return false;
    }

    $literalCode = dialecticActionCatalogSqlText($codeName);
    $row = $GLOBALS["db"]->fetchOne("
        SELECT
            code_name,
            action_name,
            description,
            return_message,
            available_to_npc,
            available_to_followers,
            available_to_narrator,
            is_activated,
            parameters_json,
            metadata,
            game_function,
            import_version,
            script_proxy_program
        FROM public.combined_core_action
        WHERE code_name = {$literalCode}
        LIMIT 1
    ");

    if (!$row) {
        return false;
    }

    return dialecticActionCatalogUpsertCustomConfig($codeName, [$configKey => $value]);
}

function dialecticActionCatalogUpsertCustomConfig($codeName, $configValues)
{
    $codeName = trim(strval($codeName));
    if ($codeName === '' || !is_array($configValues) || !dialecticActionCatalogDbReady()) {
        return false;
    }

    $literalCode = dialecticActionCatalogSqlText($codeName);
    $row = $GLOBALS["db"]->fetchOne("
        SELECT
            code_name,
            action_name,
            description,
            return_message,
            available_to_npc,
            available_to_followers,
            available_to_narrator,
            is_activated,
            parameters_json,
            metadata,
            game_function,
            import_version,
            script_proxy_program
        FROM public.combined_core_action
        WHERE code_name = {$literalCode}
        LIMIT 1
    ");

    if (!$row) {
        return false;
    }

    $metadata = dialecticActionCatalogDecodeJson($row['metadata'] ?? [], []);
    if (!isset($metadata['custom_config']) || !is_array($metadata['custom_config'])) {
        $metadata['custom_config'] = [];
    }

    foreach ($configValues as $configKey => $configValue) {
        $configKey = trim(strval($configKey));
        if ($configKey === '') {
            continue;
        }
        $metadata['custom_config'][$configKey] = $configValue;
    }

    $actionName = dialecticNormalizeActionCatalogDisplayActionName(strval($row['action_name'] ?? ''));

    $result = $GLOBALS["db"]->execQuery("
        INSERT INTO public.core_action_custom (
            code_name,
            action_name,
            description,
            return_message,
            available_to_npc,
            available_to_followers,
            available_to_narrator,
            is_activated,
            parameters_json,
            metadata,
            game_function,
            import_version,
            script_proxy_program
        ) VALUES (
            " . dialecticActionCatalogSqlText($row['code_name'] ?? $codeName) . ",
            " . dialecticActionCatalogSqlText($actionName) . ",
            " . dialecticActionCatalogSqlText($row['description'] ?? '') . ",
            " . dialecticActionCatalogSqlText($row['return_message'] ?? '') . ",
            " . dialecticActionCatalogSqlBool(dialecticActionCatalogToBool($row['available_to_npc'] ?? false)) . ",
            " . dialecticActionCatalogSqlBool(dialecticActionCatalogToBool($row['available_to_followers'] ?? false)) . ",
            " . dialecticActionCatalogSqlBool(dialecticActionCatalogToBool($row['available_to_narrator'] ?? false)) . ",
            " . dialecticActionCatalogSqlBool(dialecticActionCatalogToBool($row['is_activated'] ?? false)) . ",
            " . dialecticActionCatalogSqlJson($row['parameters_json'] ?? []) . ",
            " . dialecticActionCatalogSqlJson($metadata) . ",
            " . dialecticActionCatalogSqlBool(dialecticActionCatalogToBool($row['game_function'] ?? false)) . ",
            " . dialecticActionCatalogNormalizeImportVersion($row['import_version'] ?? 0) . ",
            " . dialecticActionCatalogSqlJson($row['script_proxy_program'] ?? null, true) . "
        )
        ON CONFLICT (code_name) DO UPDATE SET
            action_name = EXCLUDED.action_name,
            description = EXCLUDED.description,
            return_message = EXCLUDED.return_message,
            available_to_npc = EXCLUDED.available_to_npc,
            available_to_followers = EXCLUDED.available_to_followers,
            available_to_narrator = EXCLUDED.available_to_narrator,
            is_activated = EXCLUDED.is_activated,
            parameters_json = EXCLUDED.parameters_json,
            metadata = EXCLUDED.metadata,
            game_function = EXCLUDED.game_function,
            import_version = EXCLUDED.import_version,
            script_proxy_program = EXCLUDED.script_proxy_program,
            updated_at = NOW()
    ");

    dialecticActionCatalogResetCache();
    return $result !== false;
}

function dialecticActionCatalogUpsertCustomParameters($codeName, $parameters)
{
    $codeName = trim(strval($codeName));
    if ($codeName === '' || !dialecticActionCatalogDbReady()) {
        return false;
    }

    $literalCode = dialecticActionCatalogSqlText($codeName);
    $row = $GLOBALS["db"]->fetchOne("
        SELECT
            code_name,
            action_name,
            description,
            return_message,
            available_to_npc,
            available_to_followers,
            available_to_narrator,
            is_activated,
            parameters_json,
            metadata,
            game_function,
            import_version,
            script_proxy_program
        FROM public.combined_core_action
        WHERE code_name = {$literalCode}
        LIMIT 1
    ");

    if (!$row) {
        return false;
    }

    $normalizedParameters = dialecticActionCatalogNormalizeParameterSchema(
        dialecticActionCatalogDecodeJson($parameters, [])
    );
    $actionName = dialecticNormalizeActionCatalogDisplayActionName(strval($row['action_name'] ?? ''));

    $result = $GLOBALS["db"]->execQuery("
        INSERT INTO public.core_action_custom (
            code_name,
            action_name,
            description,
            return_message,
            available_to_npc,
            available_to_followers,
            available_to_narrator,
            is_activated,
            parameters_json,
            metadata,
            game_function,
            import_version,
            script_proxy_program
        ) VALUES (
            " . dialecticActionCatalogSqlText($row['code_name'] ?? $codeName) . ",
            " . dialecticActionCatalogSqlText($actionName) . ",
            " . dialecticActionCatalogSqlText($row['description'] ?? '') . ",
            " . dialecticActionCatalogSqlText($row['return_message'] ?? '') . ",
            " . dialecticActionCatalogSqlBool(dialecticActionCatalogToBool($row['available_to_npc'] ?? false)) . ",
            " . dialecticActionCatalogSqlBool(dialecticActionCatalogToBool($row['available_to_followers'] ?? false)) . ",
            " . dialecticActionCatalogSqlBool(dialecticActionCatalogToBool($row['available_to_narrator'] ?? false)) . ",
            " . dialecticActionCatalogSqlBool(dialecticActionCatalogToBool($row['is_activated'] ?? false)) . ",
            " . dialecticActionCatalogSqlJson($normalizedParameters) . ",
            " . dialecticActionCatalogSqlJson($row['metadata'] ?? []) . ",
            " . dialecticActionCatalogSqlBool(dialecticActionCatalogToBool($row['game_function'] ?? false)) . ",
            " . dialecticActionCatalogNormalizeImportVersion($row['import_version'] ?? 0) . ",
            " . dialecticActionCatalogSqlJson($row['script_proxy_program'] ?? null, true) . "
        )
        ON CONFLICT (code_name) DO UPDATE SET
            action_name = EXCLUDED.action_name,
            description = EXCLUDED.description,
            return_message = EXCLUDED.return_message,
            available_to_npc = EXCLUDED.available_to_npc,
            available_to_followers = EXCLUDED.available_to_followers,
            available_to_narrator = EXCLUDED.available_to_narrator,
            is_activated = EXCLUDED.is_activated,
            parameters_json = EXCLUDED.parameters_json,
            metadata = EXCLUDED.metadata,
            game_function = EXCLUDED.game_function,
            import_version = EXCLUDED.import_version,
            script_proxy_program = EXCLUDED.script_proxy_program,
            updated_at = NOW()
    ");

    dialecticActionCatalogResetCache();
    return $result !== false;
}

function dialecticActionCatalogUpsertCustomTextFields($codeName, $fieldValues)
{
    $codeName = trim(strval($codeName));
    if ($codeName === '' || !is_array($fieldValues) || !dialecticActionCatalogDbReady()) {
        return false;
    }

    $literalCode = dialecticActionCatalogSqlText($codeName);
    $row = $GLOBALS["db"]->fetchOne("
        SELECT
            code_name,
            action_name,
            description,
            return_message,
            available_to_npc,
            available_to_followers,
            available_to_narrator,
            is_activated,
            parameters_json,
            metadata,
            game_function,
            import_version,
            script_proxy_program
        FROM public.combined_core_action
        WHERE code_name = {$literalCode}
        LIMIT 1
    ");

    if (!$row) {
        return false;
    }

    $actionName = dialecticNormalizeActionCatalogDisplayActionName(strval($fieldValues['action_name'] ?? ($row['action_name'] ?? '')));
    if ($actionName === '') {
        return false;
    }
    if (function_exists('dialecticFindActionCatalogActionNameConflict')) {
        $conflictingRow = dialecticFindActionCatalogActionNameConflict($actionName, $codeName);
        if (is_array($conflictingRow) && !empty($conflictingRow['code_name'])) {
            return false;
        }
    }

    $description = strval($fieldValues['description'] ?? ($row['description'] ?? ''));
    $returnMessage = strval($fieldValues['return_message'] ?? ($row['return_message'] ?? ''));

    $result = $GLOBALS["db"]->execQuery("
        INSERT INTO public.core_action_custom (
            code_name,
            action_name,
            description,
            return_message,
            available_to_npc,
            available_to_followers,
            available_to_narrator,
            is_activated,
            parameters_json,
            metadata,
            game_function,
            import_version,
            script_proxy_program
        ) VALUES (
            " . dialecticActionCatalogSqlText($row['code_name'] ?? $codeName) . ",
            " . dialecticActionCatalogSqlText($actionName) . ",
            " . dialecticActionCatalogSqlText($description) . ",
            " . dialecticActionCatalogSqlText($returnMessage) . ",
            " . dialecticActionCatalogSqlBool(dialecticActionCatalogToBool($row['available_to_npc'] ?? false)) . ",
            " . dialecticActionCatalogSqlBool(dialecticActionCatalogToBool($row['available_to_followers'] ?? false)) . ",
            " . dialecticActionCatalogSqlBool(dialecticActionCatalogToBool($row['available_to_narrator'] ?? false)) . ",
            " . dialecticActionCatalogSqlBool(dialecticActionCatalogToBool($row['is_activated'] ?? false)) . ",
            " . dialecticActionCatalogSqlJson($row['parameters_json'] ?? []) . ",
            " . dialecticActionCatalogSqlJson($row['metadata'] ?? []) . ",
            " . dialecticActionCatalogSqlBool(dialecticActionCatalogToBool($row['game_function'] ?? false)) . ",
            " . dialecticActionCatalogNormalizeImportVersion($row['import_version'] ?? 0) . ",
            " . dialecticActionCatalogSqlJson($row['script_proxy_program'] ?? null, true) . "
        )
        ON CONFLICT (code_name) DO UPDATE SET
            action_name = EXCLUDED.action_name,
            description = EXCLUDED.description,
            return_message = EXCLUDED.return_message,
            available_to_npc = EXCLUDED.available_to_npc,
            available_to_followers = EXCLUDED.available_to_followers,
            available_to_narrator = EXCLUDED.available_to_narrator,
            is_activated = EXCLUDED.is_activated,
            parameters_json = EXCLUDED.parameters_json,
            metadata = EXCLUDED.metadata,
            game_function = EXCLUDED.game_function,
            import_version = EXCLUDED.import_version,
            script_proxy_program = EXCLUDED.script_proxy_program,
            updated_at = NOW()
    ");

    dialecticActionCatalogResetCache();
    return $result !== false;
}

function dialecticActionCatalogUpsertCustomRow($row)
{
    if (!is_array($row) || !dialecticActionCatalogDbReady()) {
        return false;
    }

    $codeName = trim(strval($row['code_name'] ?? ''));
    $actionName = dialecticNormalizeActionCatalogDisplayActionName(trim(strval($row['action_name'] ?? '')));
    if ($codeName === '' || $actionName === '') {
        return false;
    }

    $parameters = dialecticActionCatalogNormalizeParameterSchema(
        dialecticActionCatalogDecodeJson($row['parameters_json'] ?? [], [])
    );

    $metadata = dialecticActionCatalogDecodeJson($row['metadata'] ?? [], []);
    if (!isset($metadata['dispatch']) || trim(strval($metadata['dispatch'])) === '') {
        $metadata['dispatch'] = !empty($row['script_proxy_program']) ? 'script_proxy' : 'plugin_command';
    }
    if (!array_key_exists('builtin', $metadata)) {
        $metadata['builtin'] = false;
    }
    if (!isset($metadata['status']) || trim(strval($metadata['status'])) === '') {
        $metadata['status'] = 'active';
    }
    if (!isset($metadata['source']) || trim(strval($metadata['source'])) === '') {
        $metadata['source'] = 'core_action_custom';
    }

    $scriptProxyProgram = $row['script_proxy_program'] ?? null;
    if ($scriptProxyProgram !== null) {
        $scriptProxyProgram = dialecticActionCatalogDecodeJson($scriptProxyProgram, []);
    }

    $gameFunction = array_key_exists('game_function', $row)
        ? dialecticActionCatalogToBool($row['game_function'])
        : dialecticActionCatalogIsGameFunction($metadata);
    $importVersion = dialecticActionCatalogNormalizeImportVersion($row['import_version'] ?? 0);

    $result = $GLOBALS["db"]->execQuery("
        INSERT INTO public.core_action_custom (
            code_name,
            action_name,
            description,
            return_message,
            available_to_npc,
            available_to_followers,
            available_to_narrator,
            is_activated,
            parameters_json,
            metadata,
            game_function,
            import_version,
            script_proxy_program
        ) VALUES (
            " . dialecticActionCatalogSqlText($codeName) . ",
            " . dialecticActionCatalogSqlText($actionName) . ",
            " . dialecticActionCatalogSqlText($row['description'] ?? '') . ",
            " . dialecticActionCatalogSqlText($row['return_message'] ?? '') . ",
            " . dialecticActionCatalogSqlBool(dialecticActionCatalogToBool($row['available_to_npc'] ?? false)) . ",
            " . dialecticActionCatalogSqlBool(dialecticActionCatalogToBool($row['available_to_followers'] ?? false)) . ",
            " . dialecticActionCatalogSqlBool(dialecticActionCatalogToBool($row['available_to_narrator'] ?? false)) . ",
            " . dialecticActionCatalogSqlBool(dialecticActionCatalogToBool($row['is_activated'] ?? true)) . ",
            " . dialecticActionCatalogSqlJson($parameters) . ",
            " . dialecticActionCatalogSqlJson($metadata) . ",
            " . dialecticActionCatalogSqlBool($gameFunction) . ",
            " . $importVersion . ",
            " . dialecticActionCatalogSqlJson($scriptProxyProgram, true) . "
        )
        ON CONFLICT (code_name) DO UPDATE SET
            action_name = EXCLUDED.action_name,
            description = EXCLUDED.description,
            return_message = EXCLUDED.return_message,
            available_to_npc = EXCLUDED.available_to_npc,
            available_to_followers = EXCLUDED.available_to_followers,
            available_to_narrator = EXCLUDED.available_to_narrator,
            is_activated = EXCLUDED.is_activated,
            parameters_json = EXCLUDED.parameters_json,
            metadata = EXCLUDED.metadata,
            game_function = EXCLUDED.game_function,
            import_version = EXCLUDED.import_version,
            script_proxy_program = EXCLUDED.script_proxy_program,
            updated_at = NOW()
    ");

    dialecticActionCatalogResetCache();
    return $result !== false;
}

function dialecticActionCatalogNormalizeRefId($value)
{
    $text = trim(strval($value));
    if ($text === '') {
        return '';
    }

    return stripos($text, '0x') === 0 ? $text : ('0x' . $text);
}

function dialecticActionCatalogResolveContextPath($context, $path)
{
    if (!is_array($context)) {
        return null;
    }

    $currentValue = $context;
    foreach (explode('.', strval($path)) as $segment) {
        if ($segment === '') {
            continue;
        }

        if (!is_array($currentValue) || !array_key_exists($segment, $currentValue)) {
            return null;
        }

        $currentValue = $currentValue[$segment];
    }

    return $currentValue;
}

function dialecticActionCatalogResolveTemplateString($value, $context)
{
    if (!is_string($value) || strpos($value, '{{') === false) {
        return $value;
    }

    if (preg_match('/^\{\{\s*([^}]+)\s*\}\}$/', $value, $matches)) {
        $resolved = dialecticActionCatalogResolveContextPath($context, trim($matches[1]));
        if (is_array($resolved)) {
            return dialecticActionCatalogJsonEncode($resolved);
        }
        return $resolved;
    }

    return preg_replace_callback('/\{\{\s*([^}]+)\s*\}\}/', function ($matches) use ($context) {
        $resolved = dialecticActionCatalogResolveContextPath($context, trim($matches[1]));
        if (is_array($resolved)) {
            return dialecticActionCatalogJsonEncode($resolved);
        }
        return strval($resolved ?? '');
    }, $value);
}

function dialecticActionCatalogResolveTemplateValue($value, $context)
{
    if (is_array($value)) {
        $resolved = [];
        foreach ($value as $key => $item) {
            $resolved[$key] = dialecticActionCatalogResolveTemplateValue($item, $context);
        }
        return $resolved;
    }

    return dialecticActionCatalogResolveTemplateString($value, $context);
}

function dialecticActionCatalogBuildScriptProxyContext($actionParts, $actionParts2, $parameterDataOverride = null)
{
    $actionCodeName = trim(strval($actionParts2[0] ?? ''));
    $rawParameter = strval($actionParts2[1] ?? '');
    $parameterData = is_array($parameterDataOverride) ? $parameterDataOverride : [];
    $trimmedParameter = trim($rawParameter);
    if (empty($parameterData) && $trimmedParameter !== '' && in_array(substr($trimmedParameter, 0, 1), ['{', '['], true)) {
        $parameterData = dialecticActionCatalogDecodeJson($trimmedParameter, []);
    }

    if (!is_array($parameterData)) {
        $parameterData = [];
    }

    $npcData = [];
    $npcMetadata = [];
    if (class_exists('NpcMaster')) {
        $npcMaster = new NpcMaster();
        $npcData = $npcMaster->getByName($actionParts[0]) ?: [];
        $npcMetadata = is_array($npcData) ? ($npcMaster->getMetadata($npcData) ?: []) : [];
    }

    $parameterTarget = strval($parameterData['target'] ?? $rawParameter);
    $actionRow = $actionCodeName !== '' ? dialecticGetActionCatalogRow($actionCodeName) : null;
    $resolvedConfig = $actionCodeName !== '' ? dialecticActionCatalogGetResolvedCustomConfig($actionCodeName, $actionRow) : [];

    return [
        'actor_name' => strval($actionParts[0] ?? ''),
        'actor_refid' => dialecticActionCatalogNormalizeRefId($npcData['refid'] ?? ''),
        'actor_furniture' => strval($npcMetadata['furniture'] ?? ''),
        'action_name' => $actionCodeName,
        'full_call' => implode('|', $actionParts),
        'parameter_raw' => $rawParameter,
        'parameter_target' => $parameterTarget,
        'parameters' => $parameterData,
        'config' => $resolvedConfig,
        'request_ts' => $GLOBALS["gameRequest"][1] ?? time(),
        'game_ts' => $GLOBALS["gameRequest"][2] ?? 0,
        'local_ts' => time(),
        'player_name' => strval($GLOBALS["PLAYER_NAME"] ?? 'Player'),
        'player_refid' => defined('PLAYER_REFID') ? strval(PLAYER_REFID) : '0x00000014',
        'cache_people_limited' => strval($GLOBALS["CACHE_PEOPLE_LIMITED"] ?? ''),
        'cache_location' => strval($GLOBALS["CACHE_LOCATION"] ?? ''),
        'cache_party' => strval($GLOBALS["CACHE_PARTY"] ?? ''),
        'local_ts_ms' => (int) round(microtime(true) * 1000),
    ];
}

function dialecticActionCatalogExecuteScriptProxyCommands($commands, $context)
{
    if (!is_array($commands) || count($commands) === 0) {
        return false;
    }

    $falloutCommandBuilder = new FalloutCommandBuilder();
    $executed = false;

    foreach ($commands as $command) {
        if (!is_array($command) || !isset($command['cmd_id'])) {
            continue;
        }

        $args = dialecticActionCatalogResolveTemplateValue($command['args'] ?? [], $context);
        if (!is_array($args)) {
            $args = [];
        }

        $delaySeconds = dialecticActionCatalogResolveTemplateValue($command['delay_seconds'] ?? 0, $context);
        $localTs = null;
        if (is_numeric($delaySeconds) && floatval($delaySeconds) > 0) {
            $localTs = time() + intval(ceil(floatval($delaySeconds)));
        }

        $json = $falloutCommandBuilder->build(intval($command['cmd_id']), $args);
        $falloutCommandBuilder->send($json, $localTs);
        $executed = true;
    }

    return $executed;
}

function dialecticActionCatalogExecuteScriptProxyDbInserts($dbInserts, $context)
{
    if (!is_array($dbInserts) || count($dbInserts) === 0) {
        return false;
    }

    $executed = false;
    foreach ($dbInserts as $dbInsert) {
        if (!is_array($dbInsert) || empty($dbInsert['table']) || !is_array($dbInsert['data'] ?? null)) {
            continue;
        }

        $data = dialecticActionCatalogResolveTemplateValue($dbInsert['data'], $context);
        if (!is_array($data)) {
            continue;
        }

        if (strcasecmp(strval($dbInsert['table']), 'actions_issued') === 0 && array_key_exists('original', $data)) {
            $data['original'] = dialecticActionCatalogApplyFollowupChainToActionsIssuedOriginal($data['original']);
        }

        $GLOBALS["db"]->insert($dbInsert['table'], $data);
        $executed = true;
    }

    return $executed;
}

function dialecticActionCatalogExecuteScriptProxyNpcMetadataUpdates($npcMetadataUpdates, $context)
{
    if (!is_array($npcMetadataUpdates) || count($npcMetadataUpdates) === 0) {
        return false;
    }

    $resolvedUpdates = dialecticActionCatalogResolveTemplateValue($npcMetadataUpdates, $context);
    if (!is_array($resolvedUpdates) || count($resolvedUpdates) === 0) {
        return false;
    }

    require_once __DIR__ . DIRECTORY_SEPARATOR . 'activity_status.php';
    return dialecticApplyNpcMetadataUpdatesByName(
        trim(strval($context['actor_name'] ?? '')),
        $resolvedUpdates
    );
}

function dialecticActionCatalogBuildScriptProxyReturnArguments($context)
{
    $arguments = is_array($context['parameters'] ?? null) ? $context['parameters'] : [];
    $parameterTarget = trim(strval($context['parameter_target'] ?? ''));
    $parameterRaw = trim(strval($context['parameter_raw'] ?? ''));

    if (!array_key_exists('target', $arguments) && $parameterTarget !== '') {
        $arguments['target'] = $parameterTarget;
    }
    if (!array_key_exists('location', $arguments) && array_key_exists('target', $arguments)) {
        $arguments['location'] = $arguments['target'];
    }
    if (count($arguments) === 0 && $parameterRaw !== '') {
        $arguments['target'] = $parameterRaw;
        $arguments['location'] = $parameterRaw;
    }

    return $arguments;
}

function dialecticActionCatalogBuildScriptProxyInfoActionMessage($codeName, $context, $row)
{
    $codeName = trim(strval($codeName));
    if ($codeName === '') {
        return '';
    }

    $arguments = dialecticActionCatalogBuildScriptProxyReturnArguments($context);
    $actorName = trim(strval($context['actor_name'] ?? ''));
    $hadDialecticName = array_key_exists('DIALECTIC_NAME', $GLOBALS);
    $previousDialecticName = $GLOBALS['DIALECTIC_NAME'] ?? null;

    if ($actorName !== '') {
        $GLOBALS['DIALECTIC_NAME'] = $actorName;
    }

    if (function_exists('dialecticBuildFuncretResultInfoActionMessage')) {
        $message = dialecticBuildFuncretResultInfoActionMessage($codeName, 'target', $arguments, '');
    } else {
        $template = is_array($row) ? trim(strval($row['return_message'] ?? '')) : '';
        $message = strtr($template, [
            '#TARGET#' => trim(strval($arguments['target'] ?? '')),
            '#ITEM#' => trim(strval($arguments['item'] ?? ($arguments['location'] ?? ''))),
            '#AMOUNT#' => trim(strval($arguments['amount'] ?? '')),
            '#LOCATION#' => trim(strval($arguments['location'] ?? ($arguments['item'] ?? ''))),
            '#DIALECTIC_NAME#' => strval($GLOBALS['DIALECTIC_NAME'] ?? 'NPC'),
            '#PLAYER_NAME#' => strval($GLOBALS['PLAYER_NAME'] ?? 'Player'),
        ]);
    }

    if ($hadDialecticName) {
        $GLOBALS['DIALECTIC_NAME'] = $previousDialecticName;
    } else {
        unset($GLOBALS['DIALECTIC_NAME']);
    }

    return trim(strval($message));
}

function dialecticActionCatalogShouldLogScriptProxyInfoAction($codeName, $row)
{
    $codeName = trim(strval($codeName));
    if ($codeName === '' || !is_array($row) || trim(strval($row['return_message'] ?? '')) === '') {
        return false;
    }

    if (function_exists('isNarratorPrivateActionName') && isNarratorPrivateActionName($codeName)) {
        return false;
    }

    $metadata = $row['metadata'] ?? [];
    if (!is_array($metadata)) {
        $metadata = dialecticActionCatalogDecodeJson($metadata, []);
    }

    return empty($metadata['suppress_placeholder_infoaction']);
}

function dialecticActionCatalogLogScriptProxyInfoAction($codeName, $context, $row)
{
    if (!function_exists('logEvent') || !dialecticActionCatalogShouldLogScriptProxyInfoAction($codeName, $row)) {
        return false;
    }

    $message = dialecticActionCatalogBuildScriptProxyInfoActionMessage($codeName, $context, $row);
    if ($message === '') {
        return false;
    }

    $gameRequestCopy = $GLOBALS['gameRequest'] ?? [];
    if (!is_array($gameRequestCopy)) {
        return false;
    }

    $gameRequestCopy[0] = 'infoaction';
    $gameRequestCopy[3] = $message;
    logEvent($gameRequestCopy);

    return true;
}

function dialecticActionCatalogRunScriptProxyProgram($program, $context)
{
    if (!is_array($program) || count($program) === 0) {
        return false;
    }

    $executed = false;

    if (isset($program['switch_on']) && is_array($program['cases'] ?? null)) {
        $switchValue = strval(dialecticActionCatalogResolveContextPath($context, $program['switch_on']) ?? '');
        $selectedProgram = $program['cases'][$switchValue] ?? ($program['cases']['__default'] ?? null);
        if (is_array($selectedProgram)) {
            $executed = dialecticActionCatalogRunScriptProxyProgram($selectedProgram, $context) || $executed;
        }
    }

    $executed = dialecticActionCatalogExecuteScriptProxyCommands($program['commands'] ?? [], $context) || $executed;
    $executed = dialecticActionCatalogExecuteScriptProxyDbInserts($program['db_inserts'] ?? [], $context) || $executed;
    $executed = dialecticActionCatalogExecuteScriptProxyNpcMetadataUpdates($program['npc_metadata_updates'] ?? [], $context) || $executed;

    return $executed;
}

function dialecticActionCatalogExecuteScriptProxyAction($action)
{
    if (!dialecticActionCatalogDbReady()) {
        return false;
    }

    require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'dialectic_command_payload.php';
    $decodedAction = dialecticDecodeActionLine(strval($action));
    $codeName = trim(strval($decodedAction['action'] ?? ''));
    if ($codeName === '') {
        return false;
    }

    $actor = trim(strval($decodedAction['actor'] ?? ''));
    $rawParameter = strval($decodedAction['parameter_string'] ?? '');
    $parameterArgs = array_values($decodedAction['parameter_args'] ?? []);
    $parameterData = is_array($decodedAction['parameter'] ?? null) && !array_is_list($decodedAction['parameter'])
        ? $decodedAction['parameter']
        : [];
    if (empty($parameterData) && count($parameterArgs) === 1) {
        $parameterData = ['target' => $parameterArgs[0]];
    }
    $actionParts = [$actor, 'command', dialecticEncodeCommandAction($codeName, $parameterArgs)];
    $actionParts2 = array_merge([$codeName], $parameterArgs);

    $row = dialecticGetActionCatalogRow($codeName);
    if (!is_array($row) || empty($row['script_proxy_program'])) {
        return false;
    }

    $context = dialecticActionCatalogBuildScriptProxyContext($actionParts, $actionParts2, $parameterData);
    $executed = dialecticActionCatalogRunScriptProxyProgram($row['script_proxy_program'], $context);
    if ($executed) {
        dialecticActionCatalogLogScriptProxyInfoAction($codeName, $context, $row);
        error_log("[ACTION CATALOG {$codeName}] Executed server-side via ScriptProxy");
    }

    return $executed;
}
