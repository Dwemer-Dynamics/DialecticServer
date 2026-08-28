<?php

ob_start();

$enginePath = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR;
$GLOBALS["ENGINE_PATH"] = $enginePath;

require_once($enginePath . "lib" . DIRECTORY_SEPARATOR . "runtime_bootstrap.php");
dialecticRuntimeBootstrap($enginePath, [
    'load_general_settings' => true,
    'load_stt_connector' => false,
    'load_tts_connector' => false,
    'load_player_name' => true,
    'load_narrator' => true,
]);

require_once($enginePath . "lib" . DIRECTORY_SEPARATOR . "logger.php");
require_once($enginePath . "lib" . DIRECTORY_SEPARATOR . "core" . DIRECTORY_SEPARATOR . "core_profiles.class.php");
require_once($enginePath . "lib" . DIRECTORY_SEPARATOR . "core" . DIRECTORY_SEPARATOR . "llm_connector.class.php");
require_once($enginePath . "lib" . DIRECTORY_SEPARATOR . "core" . DIRECTORY_SEPARATOR . "tts_connector.class.php");
require_once($enginePath . "lib" . DIRECTORY_SEPARATOR . "core" . DIRECTORY_SEPARATOR . "api_badge.class.php");

if (!isset($GLOBALS["db"])) {
    $GLOBALS["db"] = new sql();
}

function profileConnectorTestsRespond(array $payload, int $statusCode = 200): void
{
    while (ob_get_level() > 0) {
        ob_end_clean();
    }

    http_response_code($statusCode);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

function profileConnectorTestsString($value): string
{
    return trim(strval($value ?? ''));
}

function profileConnectorTestsBoolish($value): bool
{
    if (is_bool($value)) {
        return $value;
    }

    $normalized = strtolower(trim(strval($value ?? '')));
    return $normalized !== '' && $normalized !== '0' && $normalized !== 'false' && $normalized !== 'no' && $normalized !== 'off';
}

function profileConnectorTestsDecodeMetadata($raw): array
{
    if (is_array($raw)) {
        return $raw;
    }

    $decoded = json_decode(strval($raw ?? '{}'), true);
    return is_array($decoded) ? $decoded : [];
}

function profileConnectorTestsConnectorLabel(array $row, string $fallback): string
{
    foreach (['label', 'model', 'driver'] as $field) {
        $value = profileConnectorTestsString($row[$field] ?? '');
        if ($value !== '') {
            return $value;
        }
    }

    return $fallback;
}

function profileConnectorTestsApiBadgeStatus($apiBadgeId): array
{
    $id = intval($apiBadgeId ?? 0);
    if ($id <= 0) {
        return [
            'status' => 'missing',
            'message' => 'No API key badge selected',
        ];
    }

    $apiBadge = new ApiBadge();
    $row = $apiBadge->getById($id);
    if (!is_array($row)) {
        return [
            'status' => 'missing',
            'message' => "API key badge #{$id} was not found",
        ];
    }

    $label = profileConnectorTestsString($row['label'] ?? ("Badge #{$id}"));
    $apiKey = profileConnectorTestsString($row['api_key'] ?? '');
    if ($apiKey === '') {
        return [
            'status' => 'empty',
            'message' => "API key badge '{$label}' has no key configured",
            'label' => $label,
        ];
    }

    return [
        'status' => 'ok',
        'message' => "API key badge '{$label}' is configured",
        'label' => $label,
    ];
}

function profileConnectorTestsLlmRequiresApiKey(array $row): bool
{
    $driver = strtolower(profileConnectorTestsString($row['driver'] ?? ''));
    $service = strtolower(profileConnectorTestsString($row['service'] ?? ''));
    $provider = strtolower(profileConnectorTestsString($row['provider'] ?? ''));
    $url = strtolower(profileConnectorTestsString($row['url'] ?? ''));

    if ($driver === 'openaijson') {
        require_once dirname(__DIR__, 2) . '/lib/core/local_llm_setup.php';
        try {
            dialecticLocalLlmValidateUrl($url);
            return false;
        } catch (InvalidArgumentException $e) {
            // Public providers still require a configured API key.
        }
    }

    $remoteDrivers = [
        'anthropic',
        'google_openaijson',
        'groqjson',
        'openai',
        'openaijson',
        'openrouter',
        'openrouterjson',
    ];

    if (in_array($driver, $remoteDrivers, true)) {
        return true;
    }

    foreach (['anthropic', 'google', 'groq', 'openai', 'openrouter', 'mistral'] as $needle) {
        if (strpos($service, $needle) !== false || strpos($provider, $needle) !== false || strpos($url, $needle) !== false) {
            return true;
        }
    }

    return false;
}

function profileConnectorTestsProblemResult(string $type, int $id, string $status, string $message, array $details = []): array
{
    return [
        'job_key' => $type . ':' . $id,
        'type' => $type,
        'id' => $id,
        'status' => $status,
        'message' => $message,
        'details' => $details,
        'elapsed_ms' => 0,
    ];
}

function profileConnectorTestsRunWithCapturedErrors(callable $callback): array
{
    $errors = [];
    $previousHandler = set_error_handler(function ($errno, $errstr, $errfile, $errline) use (&$errors) {
        $errors[] = [
            'level' => intval($errno),
            'message' => strval($errstr),
            'file' => strval($errfile),
            'line' => intval($errline),
        ];
        return true;
    });

    try {
        $value = $callback();
    } catch (Throwable $e) {
        $errors[] = [
            'level' => E_ERROR,
            'message' => $e->getMessage(),
            'file' => $e->getFile(),
            'line' => $e->getLine(),
        ];
        $value = null;
    } finally {
        if ($previousHandler !== null) {
            set_error_handler($previousHandler);
        } else {
            restore_error_handler();
        }
    }

    return [
        'value' => $value,
        'errors' => $errors,
    ];
}

function profileConnectorTestsFirstErrorMessage(array $errors): string
{
    if (empty($errors)) {
        return '';
    }

    $first = $errors[0];
    $message = profileConnectorTestsString($first['message'] ?? '');
    if ($message === '') {
        $message = 'Unknown connector error';
    }

    return $message;
}

function profileConnectorTestsEnsureOmniVoiceLanguage(string $endpoint, array $metadata, string $scope, array $voices = []): array
{
    $language = strtolower(trim(strval($metadata['language'] ?? '')));
    $endpoint = rtrim(trim($endpoint), '/');
    if ($endpoint === '' || $language === '') {
        return ['ok' => false, 'status' => 'skipped', 'error' => 'OmniVoice endpoint or language is empty.'];
    }

    $payload = [
        'language' => $language,
        'scope' => $scope,
        'voices' => array_values(array_filter(array_map('strval', $voices), function ($voice) {
            return trim($voice) !== '';
        })),
        'make_active' => true,
        'start' => true,
    ];

    $ch = curl_init($endpoint . '/ensure_language');
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json', 'Accept: application/json']);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 2);
    curl_setopt($ch, CURLOPT_TIMEOUT, 5);
    $response = curl_exec($ch);
    $httpCode = intval(curl_getinfo($ch, CURLINFO_HTTP_CODE));
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($response === false || $curlError !== '') {
        return ['ok' => false, 'status' => 'unreachable', 'error' => $curlError ?: 'Unable to reach OmniVoice.'];
    }

    $decoded = json_decode(strval($response), true);
    if (!is_array($decoded)) {
        return ['ok' => false, 'status' => 'bad_response', 'error' => 'OmniVoice returned a non-JSON response.', 'http_code' => $httpCode];
    }
    $decoded['http_code'] = $httpCode;
    return $decoded;
}

function profileConnectorTestsBuildPlan(): array
{
    $slotDefinitions = [
        ['field' => 'tts_connector_id', 'type' => 'tts', 'label' => 'TTS Connector', 'required' => false],
        ['field' => 'llm_primary_id', 'type' => 'llm', 'label' => 'Standard LLM', 'required' => true],
        ['field' => 'llm_secondary_id', 'type' => 'llm', 'label' => 'Fast LLM', 'required' => false],
        ['field' => 'llm_tertiary_id', 'type' => 'llm', 'label' => 'Powerful LLM', 'required' => false],
        ['field' => 'llm_quaternary_id', 'type' => 'llm', 'label' => 'Experimental LLM', 'required' => false],
        ['field' => 'diary_connector_id', 'type' => 'llm', 'label' => 'Diary LLM', 'required' => false],
        ['field' => 'llm_formatter_id', 'type' => 'llm', 'label' => 'Formatter LLM', 'required' => false],
        ['field' => 'llm_fallback_id', 'type' => 'llm', 'label' => 'Fallback LLM', 'required' => false],
    ];

    $profiles = (new CoreProfile())->readAll();
    $jobs = [];
    $profileRows = [];

    foreach ($profiles as $profile) {
        $profileId = intval($profile['id'] ?? 0);
        $slots = [];
        foreach ($slotDefinitions as $definition) {
            $rawConnectorId = $profile[$definition['field']] ?? null;
            $connectorId = intval($rawConnectorId ?? 0);

            if ($connectorId <= 0) {
                $slots[] = [
                    'field' => $definition['field'],
                    'type' => $definition['type'],
                    'label' => $definition['label'],
                    'required' => $definition['required'],
                    'connector_id' => null,
                    'job_key' => null,
                    'status' => 'skipped',
                    'message' => 'No connector selected',
                ];
                continue;
            }

            $jobKey = $definition['type'] . ':' . $connectorId;
            $jobs[$jobKey] = [
                'job_key' => $jobKey,
                'type' => $definition['type'],
                'id' => $connectorId,
            ];
            $slots[] = [
                'field' => $definition['field'],
                'type' => $definition['type'],
                'label' => $definition['label'],
                'required' => $definition['required'],
                'connector_id' => $connectorId,
                'job_key' => $jobKey,
                'status' => 'pending',
                'message' => 'Waiting to test',
            ];
        }

        $profileRows[] = [
            'id' => $profileId,
            'label' => profileConnectorTestsString($profile['label'] ?? ("Profile #{$profileId}")),
            'default_npc' => profileConnectorTestsBoolish($profile['default_npc'] ?? false),
            'default_narrator' => profileConnectorTestsBoolish($profile['default_narrator'] ?? false),
            'slots' => $slots,
        ];
    }

    return [
        'profiles' => $profileRows,
        'jobs' => array_values($jobs),
    ];
}

function profileConnectorTestsGlobalValue(string $fieldName, $default = '')
{
    $schemaDefault = $default;
    if (function_exists('dialecticGetSchemaDefinition')) {
        $definition = dialecticGetSchemaDefinition($fieldName);
        if (array_key_exists('default', $definition)) {
            $schemaDefault = $definition['default'];
        }
    }

    if (function_exists('dialecticGetGeneralSetting')) {
        $serializedDefault = function_exists('dialecticSettingsStringifyValue')
            ? dialecticSettingsStringifyValue($schemaDefault)
            : strval($schemaDefault);
        return dialecticGetGeneralSetting($fieldName, $serializedDefault);
    }

    return $GLOBALS[$fieldName] ?? $schemaDefault;
}

function profileConnectorTestsBuildGlobalPlan(): array
{
    $slotDefinitions = [
        ['field' => 'CORE_CONNECTOR_PLAYER', 'type' => 'llm', 'label' => 'Player Respeech'],
        ['field' => 'CORE_CONNECTOR_SUMMARY', 'type' => 'llm', 'label' => 'Summaries'],
        ['field' => 'CORE_CONNECTOR_MEDIUMTERM', 'type' => 'llm', 'label' => 'Middle Term Memory'],
        ['field' => 'CORE_CONNECTOR_SCENECLASSIFIER', 'type' => 'llm', 'label' => 'Scene Classifier', 'enabled_by' => 'SCENE_CLASSIFIER_ENABLED', 'enabled_label' => 'Scene Classifier'],
        ['field' => 'CORE_CONNECTOR_PROFILES', 'type' => 'llm', 'label' => 'Dynamic Profile'],
        ['field' => 'CORE_CONNECTOR_DIRECTOR', 'type' => 'llm', 'label' => 'Director Mode'],
        ['field' => 'RELLLM_CONNECTOR', 'type' => 'llm', 'label' => 'Relationship Management', 'enabled_by' => 'RELATIONSHIP_SYSTEM_ENABLED', 'enabled_label' => 'Relationship Management'],
        ['field' => 'CORE_CONNECTOR_WORLDKNOWLEDGE_CUSTOM', 'type' => 'llm', 'label' => 'Custom WorldKnowledge LLM', 'enabled_by' => 'WORLDKNOWLEDGE_CUSTOM', 'enabled_label' => 'Custom WorldKnowledge LLM'],
    ];

    $jobs = [];
    $slots = [];

    foreach ($slotDefinitions as $definition) {
        $enabledBy = profileConnectorTestsString($definition['enabled_by'] ?? '');
        if ($enabledBy !== '' && !profileConnectorTestsBoolish(profileConnectorTestsGlobalValue($enabledBy, false))) {
            $slots[] = [
                'field' => $definition['field'],
                'type' => $definition['type'],
                'label' => $definition['label'],
                'required' => false,
                'connector_id' => null,
                'job_key' => null,
                'status' => 'skipped',
                'message' => profileConnectorTestsString($definition['enabled_label'] ?? $definition['label']) . ' is disabled',
            ];
            continue;
        }

        $connectorId = intval(profileConnectorTestsGlobalValue($definition['field'], 0) ?? 0);
        if ($connectorId <= 0) {
            $slots[] = [
                'field' => $definition['field'],
                'type' => $definition['type'],
                'label' => $definition['label'],
                'required' => false,
                'connector_id' => null,
                'job_key' => null,
                'status' => 'skipped',
                'message' => 'No connector selected',
            ];
            continue;
        }

        $jobKey = $definition['type'] . ':' . $connectorId;
        $jobs[$jobKey] = [
            'job_key' => $jobKey,
            'type' => $definition['type'],
            'id' => $connectorId,
        ];
        $slots[] = [
            'field' => $definition['field'],
            'type' => $definition['type'],
            'label' => $definition['label'],
            'required' => false,
            'connector_id' => $connectorId,
            'job_key' => $jobKey,
            'status' => 'pending',
            'message' => 'Waiting to test',
        ];
    }

    return [
        'scope' => 'global',
        'profiles' => [[
            'id' => 'global',
            'label' => 'Global Connectors',
            'default_npc' => false,
            'default_narrator' => false,
            'slots' => $slots,
        ]],
        'jobs' => array_values($jobs),
    ];
}

function profileConnectorTestsTestLlm(int $connectorId): array
{
    $started = microtime(true);
    $llm = new LLMConnector();
    $connector = $llm->getById($connectorId);
    if (!is_array($connector) || intval($connector['id'] ?? 0) <= 0) {
        return profileConnectorTestsProblemResult('llm', $connectorId, 'fail', 'LLM connector was not found');
    }

    $driver = profileConnectorTestsString($connector['driver'] ?? '');
    $label = profileConnectorTestsConnectorLabel($connector, "LLM connector #{$connectorId}");
    $details = [
        'label' => $label,
        'driver' => $driver,
        'model' => profileConnectorTestsString($connector['model'] ?? ''),
        'url' => profileConnectorTestsString($connector['url'] ?? ''),
    ];

    if ($driver === '') {
        return profileConnectorTestsProblemResult('llm', $connectorId, 'fail', 'LLM connector has no driver selected', $details);
    }
    if (!preg_match('/^[A-Za-z0-9_]+$/', $driver)) {
        return profileConnectorTestsProblemResult('llm', $connectorId, 'fail', 'LLM connector driver has an invalid name', $details);
    }
    if (!file_exists($GLOBALS["ENGINE_PATH"] . "connector" . DIRECTORY_SEPARATOR . $driver . ".php")) {
        return profileConnectorTestsProblemResult('llm', $connectorId, 'fail', "LLM driver file '{$driver}.php' was not found", $details);
    }

    $model = profileConnectorTestsString($connector['model'] ?? '');
    if ($model === '') {
        return profileConnectorTestsProblemResult('llm', $connectorId, 'fail', 'LLM connector has no model configured', $details);
    }

    if (profileConnectorTestsLlmRequiresApiKey($connector)) {
        $badge = profileConnectorTestsApiBadgeStatus($connector['api_badge_id'] ?? 0);
        $details['api_badge'] = $badge['label'] ?? '';
        if ($badge['status'] !== 'ok') {
            return profileConnectorTestsProblemResult('llm', $connectorId, 'fail', $badge['message'], $details);
        }
    }

    // Connector response parsing uses the shared mood, animation, and
    // expression helpers that are present during normal request handling.
    require_once($GLOBALS["ENGINE_PATH"] . "lib" . DIRECTORY_SEPARATOR . "data_functions.php");

    $run = profileConnectorTestsRunWithCapturedErrors(function () use ($llm, $connector, $driver) {
        $GLOBALS["DIALECTIC_NAME"] = 'DIALECTIC Profile Test';
        $GLOBALS["PLAYER_NAME"] = $GLOBALS["PLAYER_NAME"] ?? 'Courier';
        $GLOBALS["DEBUG_DATA"] = [];
        $GLOBALS["FUNCTIONS_ARE_ENABLED"] = false;
        $GLOBALS["PATCH_PROMPT_ENFORCE_ACTIONS"] = false;
        $GLOBALS["COMMAND_PROMPT"] = '';
        $GLOBALS["COMMAND_PROMPT_ENFORCE_ACTIONS"] = '';

        $llm->setOldGlobals($connector);
        require_once($GLOBALS["ENGINE_PATH"] . "connector" . DIRECTORY_SEPARATOR . $driver . ".php");
        $handler = new $driver();
        $contextData = [
            ['role' => 'system', 'content' => 'You are a connection health check. Reply with OK.'],
            ['role' => 'user', 'content' => 'Reply with exactly OK.'],
        ];

        $handler->open($contextData, []);
        $accumulated = '';
        $iterations = 0;
        while (!$handler->isDone() && $iterations < 2000) {
            $chunk = $handler->process();
            if ($chunk === -1) {
                break;
            }
            $accumulated .= strval($chunk);
            $iterations++;
        }

        $closed = $handler->close('profile_connector_test');
        return trim(strval($closed !== '' ? $closed : $accumulated));
    });

    $elapsedMs = intval(round((microtime(true) - $started) * 1000));
    $response = profileConnectorTestsString($run['value'] ?? '');
    if ($response === '') {
        $message = profileConnectorTestsFirstErrorMessage($run['errors']);
        if ($message === '') {
            $message = 'LLM test returned an empty response';
        }

        return [
            'job_key' => 'llm:' . $connectorId,
            'type' => 'llm',
            'id' => $connectorId,
            'status' => 'fail',
            'message' => $message,
            'details' => $details + ['errors' => $run['errors']],
            'elapsed_ms' => $elapsedMs,
        ];
    }

    return [
        'job_key' => 'llm:' . $connectorId,
        'type' => 'llm',
        'id' => $connectorId,
        'status' => empty($run['errors']) ? 'pass' : 'warn',
        'message' => empty($run['errors']) ? 'LLM responded successfully' : profileConnectorTestsFirstErrorMessage($run['errors']),
        'details' => $details + ['response_preview' => mb_substr($response, 0, 180), 'errors' => $run['errors']],
        'elapsed_ms' => $elapsedMs,
    ];
}

function profileConnectorTestsTestTts(int $connectorId): array
{
    $started = microtime(true);
    $tts = new TTSConnector();
    $connector = $tts->getById($connectorId);
    if (!is_array($connector) || intval($connector['id'] ?? 0) <= 0) {
        return profileConnectorTestsProblemResult('tts', $connectorId, 'fail', 'TTS connector was not found');
    }

    $driver = $tts->normalizeDriverValue($connector['driver'] ?? '');
    $label = profileConnectorTestsConnectorLabel($connector, "TTS connector #{$connectorId}");
    $details = [
        'label' => $label,
        'driver' => $driver,
        'url' => $tts->resolveConnectorUrl($connector),
    ];

    if ($driver === '' || $driver === 'none') {
        return profileConnectorTestsProblemResult('tts', $connectorId, 'skipped', 'TTS connector is disabled', $details);
    }

    if ($tts->driverUsesApiBadge($driver)) {
        $badge = profileConnectorTestsApiBadgeStatus($connector['api_badge_id'] ?? 0);
        $details['api_badge'] = $badge['label'] ?? '';
        if ($badge['status'] !== 'ok') {
            return profileConnectorTestsProblemResult('tts', $connectorId, 'fail', $badge['message'], $details);
        }
    }

    if ($tts->driverSupportsEditableUrl($driver) && $details['url'] === '') {
        return profileConnectorTestsProblemResult('tts', $connectorId, 'fail', 'TTS connector has no endpoint URL configured', $details);
    }

    if ($driver === 'omnivoice') {
        $metadata = profileConnectorTestsDecodeMetadata($connector['metadata'] ?? '{}');
        $language = strtolower(profileConnectorTestsString($metadata['language'] ?? ''));
        $details['language'] = $language;
        $ensure = profileConnectorTestsEnsureOmniVoiceLanguage($details['url'], $metadata, 'voice_set', [
            strval($metadata['fallback_male'] ?? ''),
            strval($metadata['fallback_female'] ?? ''),
            'TheNarrator',
        ]);
        $details['omnivoice_prepare'] = $ensure;
        $ensureStatus = strtolower(profileConnectorTestsString($ensure['status'] ?? ''));
        if (!($ensure['ok'] ?? false)) {
            return profileConnectorTestsProblemResult('tts', $connectorId, 'warn', 'OmniVoice language preparation could not be checked: ' . profileConnectorTestsString($ensure['error'] ?? 'unknown error'), $details);
        }
        if ($ensureStatus !== 'ready') {
            return profileConnectorTestsProblemResult('tts', $connectorId, 'warn', 'OmniVoice ' . ($language !== '' ? $language : 'language') . ' is preparing; test again after the background job finishes.', $details);
        }
    }

    require_once($GLOBALS["ENGINE_PATH"] . "lib" . DIRECTORY_SEPARATOR . "model_dynmodel.php");
    require_once($GLOBALS["ENGINE_PATH"] . "lib" . DIRECTORY_SEPARATOR . "data_functions.php");
    require_once($GLOBALS["ENGINE_PATH"] . "lib" . DIRECTORY_SEPARATOR . "chat_helper_functions.php");
    require_once($GLOBALS["ENGINE_PATH"] . "prompt.includes.php");

    $run = profileConnectorTestsRunWithCapturedErrors(function () use ($tts, $connector) {
        $originalTtsFunction = $GLOBALS["TTSFUNCTION"] ?? null;
        $originalName = $GLOBALS["DIALECTIC_NAME"] ?? null;
        $originalTrack = $GLOBALS["TRACK"] ?? [];

        try {
            $tts->setOldGlobals($connector);
            $GLOBALS["DIALECTIC_NAME"] = 'The Narrator';
            $GLOBALS["AVOID_TTS_CACHE"] = true;
            $GLOBALS["TTS_FFMPEG_FILTERS"] = [];
            $GLOBALS["DIALECTIC_ANIMATIONS"] = false;
            $GLOBALS["SCRIPTLINE_LISTENER"] = '';
            $GLOBALS["SCRIPTLINE_EXPRESSION"] = '';
            $GLOBALS["DEBUG_DATA"] = [];
            $GLOBALS["FEATURES"] = $GLOBALS["FEATURES"] ?? [];
            $GLOBALS["FEATURES"]["MISC"] = $GLOBALS["FEATURES"]["MISC"] ?? [];
            $GLOBALS["FEATURES"]["MISC"]["TTS_RANDOM_PITCH"] = false;
            $GLOBALS["PATCH_DONT_STORE_SPEECH_ON_DB"] = true;
            $GLOBALS["PATCH_OVERRIDE_VOICE"] = 'TheNarrator';
            unset($GLOBALS["PATCH_OVERRIDE_VOICE_ID"], $GLOBALS["PATCH_OVERRIDE_TTS_LANGUAGE"]);

            returnLines(['DIALECTIC profile connector test.'], false);

            $generated = $GLOBALS["TRACK"]["FILES_GENERATED"][0] ?? '';
            return profileConnectorTestsString($generated);
        } finally {
            if ($originalTtsFunction === null) {
                unset($GLOBALS["TTSFUNCTION"]);
            } else {
                $GLOBALS["TTSFUNCTION"] = $originalTtsFunction;
            }
            if ($originalName === null) {
                unset($GLOBALS["DIALECTIC_NAME"]);
            } else {
                $GLOBALS["DIALECTIC_NAME"] = $originalName;
            }
            $GLOBALS["TRACK"] = $originalTrack;
            unset($GLOBALS["PATCH_DONT_STORE_SPEECH_ON_DB"], $GLOBALS["PATCH_OVERRIDE_VOICE"], $GLOBALS["SCRIPTLINE_ANIMATION_SENT"]);
        }
    });

    $elapsedMs = intval(round((microtime(true) - $started) * 1000));
    $generated = profileConnectorTestsString($run['value'] ?? '');
    if ($generated === '') {
        $message = profileConnectorTestsFirstErrorMessage($run['errors']);
        if ($message === '') {
            $message = 'TTS test did not produce audio';
        }

        return [
            'job_key' => 'tts:' . $connectorId,
            'type' => 'tts',
            'id' => $connectorId,
            'status' => 'fail',
            'message' => $message,
            'details' => $details + ['errors' => $run['errors']],
            'elapsed_ms' => $elapsedMs,
        ];
    }

    return [
        'job_key' => 'tts:' . $connectorId,
        'type' => 'tts',
        'id' => $connectorId,
        'status' => empty($run['errors']) ? 'pass' : 'warn',
        'message' => empty($run['errors']) ? 'TTS produced audio successfully' : profileConnectorTestsFirstErrorMessage($run['errors']),
        'details' => $details + ['generated_file' => basename($generated), 'errors' => $run['errors']],
        'elapsed_ms' => $elapsedMs,
    ];
}

$action = profileConnectorTestsString($_GET['action'] ?? $_POST['action'] ?? 'plan');
$scope = profileConnectorTestsString($_GET['scope'] ?? $_POST['scope'] ?? 'profiles');

try {
    if ($action === 'plan') {
        profileConnectorTestsRespond([
            'ok' => true,
            'plan' => $scope === 'global' ? profileConnectorTestsBuildGlobalPlan() : profileConnectorTestsBuildPlan(),
        ]);
    }

    if ($action === 'test') {
        $type = strtolower(profileConnectorTestsString($_GET['type'] ?? $_POST['type'] ?? ''));
        $id = intval($_GET['id'] ?? $_POST['id'] ?? 0);
        if (!in_array($type, ['llm', 'tts'], true) || $id <= 0) {
            profileConnectorTestsRespond([
                'ok' => false,
                'error' => 'Invalid connector test request',
            ], 400);
        }

        if ($type === 'llm') {
            $result = profileConnectorTestsTestLlm($id);
        } else {
            $result = profileConnectorTestsTestTts($id);
        }

        profileConnectorTestsRespond([
            'ok' => true,
            'result' => $result,
        ]);
    }

    profileConnectorTestsRespond([
        'ok' => false,
        'error' => 'Unknown action',
    ], 400);
} catch (Throwable $e) {
    profileConnectorTestsRespond([
        'ok' => false,
        'error' => $e->getMessage(),
    ], 500);
}
