<?php

require_once __DIR__ . '/llm_connector.class.php';
require_once dirname(__DIR__) . '/settings.php';

function dialecticLocalLlmServerCatalog(): array
{
    return [
        'lm_studio' => ['label' => 'LM Studio', 'port' => 1234],
        'ollama' => ['label' => 'Ollama', 'port' => 11434],
        'llama_cpp' => ['label' => 'llama.cpp', 'port' => 8080],
        'koboldcpp' => ['label' => 'KoboldCPP', 'port' => 5001],
        'other' => ['label' => 'Other OpenAI-compatible server', 'port' => null],
    ];
}

function dialecticLocalLlmBoolish($value): bool
{
    return in_array(strtolower(trim(strval($value))), ['1', 'true', 'on', 'yes'], true);
}

// Literal private addresses avoid DNS rebinding; exclude link-local metadata services.
function dialecticLocalLlmValidateUrl(string $rawUrl): string
{
    $url = trim($rawUrl);
    $parts = @parse_url($url);
    if ($url === '' || strlen($url) > 2048 || preg_match('/[\x00-\x20\x7f\\\\]/', $url)
        || !is_array($parts)) {
        throw new InvalidArgumentException('Enter a valid local server URL.');
    }
    $scheme = strtolower(strval($parts['scheme'] ?? ''));
    $host = strtolower(trim(strval($parts['host'] ?? ''), '[]'));
    if (!in_array($scheme, ['http', 'https'], true) || $host === ''
        || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])
        || (isset($parts['port']) && intval($parts['port']) < 1)) {
        throw new InvalidArgumentException('Use an HTTP or HTTPS endpoint without credentials, query, or fragment.');
    }
    if ($host === 'localhost') {
        $host = '127.0.0.1';
    }
    $packed = @inet_pton($host);
    $allowed = false;
    if ($packed !== false && strlen($packed) === 4) {
        $octets = array_values(unpack('C4', $packed));
        $allowed = $octets[0] === 127 || $octets[0] === 10
            || ($octets[0] === 172 && $octets[1] >= 16 && $octets[1] <= 31)
            || ($octets[0] === 192 && $octets[1] === 168);
    } elseif ($packed !== false && strlen($packed) === 16) {
        $allowed = $packed === str_repeat("\0", 15) . "\1" || (ord($packed[0]) & 0xfe) === 0xfc;
    }
    if (!$allowed) {
        throw new InvalidArgumentException('Use localhost or a private LAN IP for a local model server.');
    }
    $path = strval($parts['path'] ?? '');
    if (!preg_match('~/chat/completions$~', $path)) {
        throw new InvalidArgumentException('Enter the full chat endpoint, normally /v1/chat/completions.');
    }
    $host = strlen($packed ?: '') === 16 ? '[' . $host . ']' : $host;
    return $scheme . '://' . $host . (isset($parts['port']) ? ':' . intval($parts['port']) : '') . $path;
}

function dialecticLocalLlmNormalizeSetup(array $raw): array
{
    $serverType = strval($raw['server_type'] ?? 'lm_studio');
    $scope = strval($raw['scope'] ?? 'conversations');
    $catalog = dialecticLocalLlmServerCatalog();
    if (!isset($catalog[$serverType]) || !in_array($scope, ['conversations', 'all'], true)) {
        throw new InvalidArgumentException('Choose a supported server and routing option.');
    }
    $model = trim(strval($raw['model'] ?? ''));
    if ($model === '' || strlen($model) > 255 || preg_match('/[\x00-\x1f\x7f]/', $model)) {
        throw new InvalidArgumentException('Enter the model name loaded by your server (up to 255 bytes).');
    }
    $key = trim(strval($raw['api_key'] ?? ''));
    if (strlen($key) > 8192 || preg_match('/[\x00-\x1f\x7f]/', $key)) {
        throw new InvalidArgumentException('The API key is too long or contains unsupported characters.');
    }
    $timeout = filter_var($raw['timeout'] ?? 30, FILTER_VALIDATE_INT);
    if ($timeout === false || $timeout < 5 || $timeout > 120) {
        throw new InvalidArgumentException('Use a timeout from 5 to 120 seconds.');
    }
    return [
        'server_type' => $serverType, 'server_label' => $catalog[$serverType]['label'],
        'url' => dialecticLocalLlmValidateUrl(strval($raw['url'] ?? '')), 'model' => $model,
        'api_key' => $key, 'clear_api_key' => dialecticLocalLlmBoolish($raw['clear_api_key'] ?? false),
        'disable_streaming' => dialecticLocalLlmBoolish($raw['disable_streaming'] ?? false),
        'timeout' => $timeout, 'scope' => $scope,
    ];
}

function dialecticLocalLlmManagedConnector(): ?array
{
    $row = $GLOBALS['db']->fetchOne("SELECT * FROM core_llm_connector WHERE metadata @> " .
        "'{\"quickstart_managed\":true}'::jsonb ORDER BY id LIMIT 1");
    return is_array($row) && intval($row['id'] ?? 0) > 0 ? $row : null;
}

function dialecticLocalLlmCurrentSetup(): array
{
    $setup = [
        'server_type' => 'lm_studio', 'url' => 'http://127.0.0.1:1234/v1/chat/completions',
        'model' => '', 'timeout' => 30, 'disable_streaming' => false, 'scope' => 'conversations',
        'connector_id' => 0, 'has_api_key' => false, 'host_ip' => '', 'wsl_ip' => '',
    ];
    foreach (['Network/HOST_IP' => 'host_ip', 'Network/WSL_IP' => 'wsl_ip'] as $id => $field) {
        $row = $GLOBALS['db']->fetchOne('SELECT value FROM conf_opts WHERE id=' . $GLOBALS['db']->escapeLiteral($id));
        $setup[$field] = trim(strval($row['value'] ?? ''));
    }
    if ($setup['host_ip'] !== '') {
        try {
            $setup['url'] = dialecticLocalLlmValidateUrl('http://' . $setup['host_ip'] . ':1234/v1/chat/completions');
        } catch (InvalidArgumentException $e) {
            // Stale network discovery must not create an unsafe suggested endpoint.
        }
    }
    $connector = dialecticLocalLlmManagedConnector();
    if ($connector !== null) {
        $metadata = json_decode(strval($connector['metadata'] ?? '{}'), true);
        $metadata = is_array($metadata) ? $metadata : [];
        $setup['server_type'] = isset(dialecticLocalLlmServerCatalog()[$metadata['quickstart_server_type'] ?? ''])
            ? $metadata['quickstart_server_type'] : 'other';
        $setup['url'] = strval($connector['url'] ?? '');
        $setup['model'] = strval($connector['model'] ?? '');
        $setup['timeout'] = max(5, min(120, intval($metadata['quickstart_timeout'] ?? 30)));
        $setup['disable_streaming'] = dialecticLocalLlmBoolish($metadata['disable_streaming'] ?? false);
        $setup['scope'] = ($metadata['quickstart_scope'] ?? '') === 'all' ? 'all' : 'conversations';
        $setup['connector_id'] = intval($connector['id']);
        $setup['has_api_key'] = intval($connector['api_badge_id'] ?? 0) > 0;
    }
    return $setup;
}

// A blank key may reuse a saved credential only for its original endpoint.
function dialecticLocalLlmReusableBadge(?array $existing, array $setup): ?int
{
    if ($existing === null || $setup['clear_api_key']) {
        return null;
    }
    try {
        if (dialecticLocalLlmValidateUrl(strval($existing['url'] ?? '')) !== $setup['url']) {
            return null;
        }
    } catch (InvalidArgumentException $e) {
        return null;
    }
    $badgeId = intval($existing['api_badge_id'] ?? 0);
    return $badgeId > 0 ? $badgeId : null;
}

// Connector, credential association, and routing must become visible together.
function dialecticLocalLlmApplySetup(array $raw): array
{
    $setup = dialecticLocalLlmNormalizeSetup($raw);
    $db = $GLOBALS['db'];
    if (!$db->query('BEGIN')) {
        throw new RuntimeException('Could not begin local model setup.');
    }
    try {
        // Serialize this wizard only, including concurrent requests from separate sessions.
        if (!$db->query("SELECT pg_advisory_xact_lock(hashtext('dialectic_local_llm_setup'))")) {
            throw new RuntimeException('Could not lock local model setup.');
        }
        $player2 = $db->fetchOne("SELECT value FROM conf_opts WHERE id='PLAYER2_FORCE_ALL_LLM'");
        if (dialecticLocalLlmBoolish($player2['value'] ?? false)) {
            throw new InvalidArgumentException('Turn off Use Player 2 for LLMs and save Quickstart before applying local routing.');
        }
        $profiles = $db->fetchAll("SELECT id FROM core_profiles WHERE default_npc='1' OR default_narrator='1' FOR UPDATE");
        if (!$profiles) {
            throw new InvalidArgumentException('No default NPC or narrator profile exists. Configure a default profile first.');
        }
        $existing = dialecticLocalLlmManagedConnector();
        $badgeId = dialecticLocalLlmReusableBadge($existing, $setup);
        $savedBadge = $badgeId !== null ? (new ApiBadge())->getById($badgeId) : null;
        if ($setup['api_key'] !== '' && !$setup['clear_api_key']
            && !hash_equals(strval($savedBadge['api_key'] ?? ''), $setup['api_key'])) {
            // Never overwrite a badge that another connector may share.
            $badgeId = intval((new ApiBadge())->create([
                'label' => 'Quickstart Local LLM ' . bin2hex(random_bytes(4)), 'api_key' => $setup['api_key'],
            ]));
            if ($badgeId <= 0) {
                throw new RuntimeException('Could not create the local model credential.');
            }
        }
        $metadata = json_decode(strval($existing['metadata'] ?? '{}'), true);
        $metadata = is_array($metadata) ? $metadata : [];
        $metadata = array_merge($metadata, [
            'quickstart_managed' => true, 'quickstart_server_type' => $setup['server_type'],
            'quickstart_scope' => $setup['scope'], 'quickstart_timeout' => $setup['timeout'],
            'disable_streaming' => $setup['disable_streaming'],
            'lmstudio_compat' => $setup['server_type'] === 'lm_studio',
        ]);
        $payload = [
            'label' => 'Local LLM - ' . $setup['server_label'],
            'metadata' => json_encode($metadata, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            'url' => $setup['url'], 'model' => $setup['model'], 'provider' => 'local', 'service' => 'custom',
            'driver' => 'openaijson', 'api_badge_id' => $badgeId,
        ];
        $connectors = new LLMConnector();
        $id = intval($existing['id'] ?? 0);
        if ($id > 0) {
            if (!$connectors->update($id, $payload)) {
                throw new RuntimeException('Could not update the local model connector.');
            }
        } else {
            $id = intval($connectors->create($payload + [
                'reasoning_model' => 0, 'max_tokens' => 512, 'enforce_json' => 1,
                'prefill_json' => 0, 'json_schema' => 0, 'temperature' => 0.7,
            ]));
            if ($id <= 0) {
                throw new RuntimeException('Could not create the local model connector.');
            }
        }
        $fields = ['llm_primary_id', 'llm_secondary_id', 'llm_tertiary_id', 'llm_quaternary_id'];
        if ($setup['scope'] === 'all') {
            $fields[] = 'diary_connector_id';
            $fields[] = 'llm_formatter_id';
        }
        if (!$db->updateRow('core_profiles', array_fill_keys($fields, $id),
            'id IN (' . implode(',', array_map(static fn($row) => intval($row['id']), $profiles)) . ')')) {
            throw new RuntimeException('Could not route default profile connectors.');
        }
        if ($setup['scope'] === 'all') {
            foreach (['CORE_CONNECTOR_PLAYER', 'CORE_CONNECTOR_SUMMARY', 'CORE_CONNECTOR_MEDIUMTERM',
                'CORE_CONNECTOR_SCENECLASSIFIER', 'CORE_CONNECTOR_PROFILES', 'CORE_CONNECTOR_DIRECTOR',
                'RELLLM_CONNECTOR', 'CORE_CONNECTOR_WORLDKNOWLEDGE_CUSTOM'] as $field) {
                if (!dialecticSetGeneralSetting($field, $id)) {
                    throw new RuntimeException('Could not route background task connectors.');
                }
            }
        }
        if (!$db->query('COMMIT')) {
            throw new RuntimeException('Could not commit local model setup.');
        }
        return ['ok' => true, 'message' => 'Local model setup saved. Custom profile assignments and speech settings are unchanged.',
            'connector_id' => $id, 'url' => $setup['url'], 'has_api_key' => $badgeId !== null];
    } catch (Throwable $e) {
        $db->query('ROLLBACK');
        throw $e;
    }
}

// One bounded draft request; no database writes or transaction around HTTP.
function dialecticLocalLlmTestDraft(array $raw): array
{
    $setup = dialecticLocalLlmNormalizeSetup($raw);
    if ($setup['clear_api_key']) {
        $setup['api_key'] = '';
    } elseif ($setup['api_key'] === '') {
        $badgeId = dialecticLocalLlmReusableBadge(dialecticLocalLlmManagedConnector(), $setup);
        $badge = $badgeId !== null ? (new ApiBadge())->getById($badgeId) : null;
        $setup['api_key'] = strval($badge['api_key'] ?? '');
    }
    if (!function_exists('curl_init')) {
        throw new InvalidArgumentException('The PHP cURL extension is required to test a local model.');
    }
    $headers = ['Content-Type: application/json', 'Accept: application/json'];
    if ($setup['api_key'] !== '') {
        $headers[] = 'Authorization: Bearer ' . $setup['api_key'];
    }
    $started = microtime(true);
    $responseBody = '';
    $responseTooLarge = false;
    $ch = curl_init($setup['url']);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode([
            'model' => $setup['model'], 'messages' => [['role' => 'user', 'content' => 'Reply with OK.']],
            'max_tokens' => 16, 'temperature' => 0, 'stream' => false,
        ], JSON_THROW_ON_ERROR),
        CURLOPT_HTTPHEADER => $headers, CURLOPT_RETURNTRANSFER => false,
        CURLOPT_CONNECTTIMEOUT => 5, CURLOPT_TIMEOUT => $setup['timeout'],
        CURLOPT_FOLLOWLOCATION => false, CURLOPT_MAXREDIRS => 0,
        CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
        CURLOPT_PROXY => '',
        CURLOPT_WRITEFUNCTION => static function ($handle, string $chunk) use (&$responseBody, &$responseTooLarge): int {
            if (strlen($responseBody) + strlen($chunk) > 262144) {
                $responseTooLarge = true;
                return 0;
            }
            $responseBody .= $chunk;
            return strlen($chunk);
        },
    ]);
    $response = curl_exec($ch);
    $httpCode = intval(curl_getinfo($ch, CURLINFO_HTTP_CODE));
    curl_close($ch);
    $elapsed = intval(round((microtime(true) - $started) * 1000));
    $decoded = json_decode($responseBody, true);
    $content = $decoded['choices'][0]['message']['content'] ?? null;
    if ($responseTooLarge) {
        $message = 'The local model response was too large.';
    } elseif ($response === false) {
        $message = 'Could not reach the local model. Check the URL, server binding, firewall, and timeout.';
    } elseif ($httpCode < 200 || $httpCode >= 300) {
        $message = 'The local model returned HTTP ' . $httpCode . '.';
    } elseif (!is_string($content) || trim($content) === '') {
        $message = 'The local model returned an empty or unsupported chat response.';
    } else {
        return ['ok' => true, 'message' => 'Local model responded successfully. Nothing was saved.', 'elapsed_ms' => $elapsed];
    }
    return ['ok' => false, 'message' => $message, 'elapsed_ms' => $elapsed];
}
