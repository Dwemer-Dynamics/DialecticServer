<?php

require_once(__DIR__ . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'itt_connector.class.php');
require_once(__DIR__ . DIRECTORY_SEPARATOR . 'visual_context.php');

if (!function_exists('dialecticIttBuildHints')) {
    function dialecticIttBuildHints(array $captureMetadata): string
    {
        $hints = [];
        $worldspace = trim(strval($captureMetadata['worldspace'] ?? ''));
        $location = trim(strval($captureMetadata['location'] ?? ''));
        $subject = is_array($captureMetadata['subject'] ?? null) ? $captureMetadata['subject'] : [];
        $subjectName = trim(strval($subject['name'] ?? ''));

        if ($worldspace !== '') {
            $hints[] = "Worldspace: {$worldspace}.";
        }
        if ($location !== '') {
            $hints[] = "Location: {$location}.";
        }
        if ($subjectName !== '') {
            $hints[] = "The player targeted {$subjectName}.";
        }
        $nearby = $captureMetadata['nearby_actors'] ?? [];
        if (is_array($nearby) && $nearby) {
            $names = array_values(array_filter(array_map(static fn($value) => trim(strval($value)), $nearby)));
            if ($names) {
                $hints[] = 'Nearby actors reported by the game: ' . implode(', ', array_slice($names, 0, 20)) . '.';
            }
        }
        return implode("\n", $hints);
    }
}

if (!function_exists('dialecticIttImageData')) {
    function dialecticIttImageData(string $imagePath): array
    {
        $bytes = @file_get_contents($imagePath);
        if (!is_string($bytes) || $bytes === '') {
            throw new RuntimeException('PipVision could not read the normalized image');
        }
        $info = @getimagesize($imagePath);
        $mime = strtolower(trim(strval($info['mime'] ?? '')));
        if (!in_array($mime, ['image/jpeg', 'image/png', 'image/webp', 'image/gif'], true)) {
            throw new RuntimeException('PipVision does not support this image format');
        }
        return [$bytes, $mime];
    }
}

if (!function_exists('dialecticIttRequestJson')) {
    function dialecticIttRequestJson(string $url, array $payload, array $headers): array
    {
        if (!function_exists('curl_init')) {
            throw new RuntimeException('PHP cURL is required for PipVision');
        }
        if ($url === '') {
            throw new RuntimeException('ITT connector endpoint URL is empty');
        }

        $timeout = max(10, min(dialecticGetGeneralSettingInt('PIPVISION_REQUEST_TIMEOUT_SECONDS', 60), 180));
        $curl = curl_init($url);
        curl_setopt_array($curl, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => array_merge(['Content-Type: application/json', 'Accept: application/json'], $headers),
            CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            CURLOPT_CONNECTTIMEOUT => min(15, $timeout),
            CURLOPT_TIMEOUT => $timeout,
        ]);
        $raw = curl_exec($curl);
        $curlError = curl_error($curl);
        $status = intval(curl_getinfo($curl, CURLINFO_HTTP_CODE));
        curl_close($curl);

        if (!is_string($raw) || $raw === '' || $status < 200 || $status >= 300) {
            $detail = $curlError !== '' ? $curlError : ('HTTP ' . $status);
            if (is_string($raw) && trim($raw) !== '') {
                $detail .= ': ' . substr(trim(strip_tags($raw)), 0, 300);
            }
            throw new RuntimeException('ITT connector request failed: ' . $detail);
        }
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            throw new RuntimeException('ITT connector returned invalid JSON');
        }
        return $decoded;
    }
}

if (!function_exists('dialecticIttExtractContent')) {
    function dialecticIttExtractContent(array $response): string
    {
        $content = $response['choices'][0]['message']['content'] ?? ($response['content'] ?? '');
        if (is_array($content)) {
            $parts = [];
            foreach ($content as $part) {
                if (is_array($part) && isset($part['text'])) {
                    $parts[] = strval($part['text']);
                } elseif (is_string($part)) {
                    $parts[] = $part;
                }
            }
            $content = implode("\n", $parts);
        }
        return trim(strval($content));
    }
}

if (!function_exists('dialecticIttOpenAiCompatible')) {
    function dialecticIttOpenAiCompatible(string $provider, string $imagePath, string $hints, bool $openRouter = false): string
    {
        $config = is_array($GLOBALS['ITT'][$provider] ?? null) ? $GLOBALS['ITT'][$provider] : [];
        $url = trim(strval($config['url'] ?? ($config['URL'] ?? '')));
        $model = trim(strval($config['model'] ?? ''));
        if ($url === '' || $model === '') {
            throw new RuntimeException('ITT connector requires both an endpoint URL and model');
        }

        [$bytes, $mime] = dialecticIttImageData($imagePath);
        $prompt = trim(strval($config['AI_VISION_PROMPT'] ?? ''));
        if ($hints !== '') {
            $prompt .= ($prompt !== '' ? "\n\nGame metadata:\n" : '') . $hints;
        }
        $imageUrl = ['url' => 'data:' . $mime . ';base64,' . base64_encode($bytes)];
        $detail = strtolower(trim(strval($config['detail'] ?? 'low')));
        if (in_array($detail, ['low', 'high'], true)) {
            $imageUrl['detail'] = $detail;
        }

        $headers = [];
        $apiKey = trim(strval($config['API_KEY'] ?? ''));
        if ($apiKey !== '') {
            $headers[] = 'Authorization: Bearer ' . $apiKey;
        }
        if ($openRouter) {
            $headers[] = 'HTTP-Referer: https://dwemerdynamics.com/';
            $headers[] = 'X-Title: DIALECTIC PipVision';
        }

        $response = dialecticIttRequestJson($url, [
            'model' => $model,
            'temperature' => 0.0,
            'messages' => [[
                'role' => 'user',
                'content' => [
                    ['type' => 'text', 'text' => $prompt],
                    ['type' => 'image_url', 'image_url' => $imageUrl],
                ],
            ]],
            'max_tokens' => max(64, min(intval($config['max_tokens'] ?? 1024), 4096)),
        ], $headers);

        $description = dialecticIttExtractContent($response);
        if ($description === '') {
            throw new RuntimeException('ITT connector returned an empty description');
        }
        return $description;
    }
}

if (!function_exists('dialecticIttLlamaCpp')) {
    function dialecticIttLlamaCpp(string $imagePath, string $hints): string
    {
        $config = is_array($GLOBALS['ITT']['llamacpp'] ?? null) ? $GLOBALS['ITT']['llamacpp'] : [];
        $baseUrl = rtrim(trim(strval($config['URL'] ?? ($config['url'] ?? ''))), '/');
        if ($baseUrl === '') {
            throw new RuntimeException('llama.cpp ITT connector URL is empty');
        }
        [$bytes] = dialecticIttImageData($imagePath);
        $prompt = trim(strval($config['AI_VISION_PROMPT'] ?? ''));
        if ($hints !== '') {
            $prompt .= ($prompt !== '' ? "\n\nGame metadata:\n" : '') . $hints;
        }
        $response = dialecticIttRequestJson($baseUrl . '/completion', [
            'prompt' => $prompt . "\nASSISTANT:",
            'n_predict' => 1024,
            'image_data' => [['data' => base64_encode($bytes), 'id' => 1]],
            'ignore_eos' => false,
            'temperature' => 0.0,
        ], []);
        $description = dialecticIttExtractContent($response);
        if ($description === '') {
            throw new RuntimeException('llama.cpp ITT connector returned an empty description');
        }
        return $description;
    }
}

if (!function_exists('dialecticIttDescribe')) {
    function dialecticIttDescribe(string $imagePath, array $captureMetadata, ?array $connectorRow = null): array
    {
        $connector = new ITTConnector();
        if (!$connectorRow) {
            $connectorId = dialecticGetGeneralSettingInt('GLOBAL_ITT_CONNECTOR_ID', 0);
            $connectorRow = $connectorId > 0 ? $connector->getById($connectorId) : null;
        }
        if (!$connectorRow) {
            throw new RuntimeException('No PipVision ITT connector is configured');
        }

        $driver = $connector->normalizeDriverValue($connectorRow['driver'] ?? '');
        if ($driver === '') {
            throw new RuntimeException('PipVision ITT connector driver is unsupported');
        }
        $connector->setOldGlobals($connectorRow);
        $providerFile = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'itt' . DIRECTORY_SEPARATOR . 'itt-' . $driver . '.php';
        if (!is_file($providerFile)) {
            throw new RuntimeException('PipVision ITT provider is unavailable');
        }
        require_once($providerFile);
        if (!function_exists('itt')) {
            throw new RuntimeException('PipVision ITT provider did not register');
        }

        $description = trim(strval(itt($imagePath, dialecticIttBuildHints($captureMetadata))));
        if ($description === '') {
            throw new RuntimeException('PipVision ITT connector returned an empty description');
        }
        $metadata = $connector->decodeMetadata($connectorRow['metadata'] ?? '{}');
        return [
            'description' => dialecticVisualContextText($description, 12000),
            'provider' => $driver,
            'model' => trim(strval($metadata['model'] ?? ($driver === 'llamacpp' ? 'llama.cpp' : ''))),
        ];
    }
}
