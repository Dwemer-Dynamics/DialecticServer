<?php

require_once(__DIR__ . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'itt_connector.class.php');
require_once(__DIR__ . DIRECTORY_SEPARATOR . 'visual_context.php');

if (!function_exists('dialecticPipVisionDescribe')) {
    function dialecticPipVisionDescribe(string $imagePath, array $captureMetadata, ?array $connectorRow = null): array
    {
        if (!function_exists('curl_init')) {
            throw new RuntimeException('PHP cURL is required for PipVision');
        }

        if (!$connectorRow) {
            $connectorId = dialecticGetGeneralSettingInt('GLOBAL_ITT_CONNECTOR_ID', 0);
            $connectorRow = $connectorId > 0 ? (new ITTConnector())->getById($connectorId) : null;
        }
        if (!$connectorRow) {
            throw new RuntimeException('No PipVision connector is configured');
        }

        $driver = strtolower(trim(strval($connectorRow['driver'] ?? '')));
        $metadata = json_decode(strval($connectorRow['metadata'] ?? '{}'), true);
        if (!is_array($metadata)) {
            $metadata = [];
        }
        $connector = new ITTConnector();
        $url = trim(strval($connectorRow['url'] ?? ''));
        if ($url === '') {
            $url = $connector->getDefaultUrl($driver);
        }
        $model = trim(strval($metadata['model'] ?? ''));
        if ($model === '') {
            $model = $connector->getDefaultModel($driver);
        }
        if ($url === '' || $model === '') {
            throw new RuntimeException('PipVision connector requires both an endpoint URL and model');
        }

        $imageBytes = @file_get_contents($imagePath);
        if (!is_string($imageBytes) || $imageBytes === '') {
            throw new RuntimeException('PipVision could not read the normalized image');
        }
        $imageInfo = @getimagesize($imagePath);
        $imageMime = strtolower(trim(strval($imageInfo['mime'] ?? 'image/jpeg')));
        if (!in_array($imageMime, ['image/jpeg', 'image/png', 'image/webp', 'image/gif'], true)) {
            throw new RuntimeException('PipVision does not support this image format');
        }

        $location = trim(strval($captureMetadata['location'] ?? ''));
        $worldspace = trim(strval($captureMetadata['worldspace'] ?? ''));
        $subject = is_array($captureMetadata['subject'] ?? null) ? $captureMetadata['subject'] : [];
        $subjectName = trim(strval($subject['name'] ?? ''));
        $hints = [];
        if ($worldspace !== '') $hints[] = "Worldspace: {$worldspace}.";
        if ($location !== '') $hints[] = "Location: {$location}.";
        if ($subjectName !== '') $hints[] = "The player targeted {$subjectName}.";
        $nearby = $captureMetadata['nearby_actors'] ?? [];
        if (is_array($nearby) && $nearby) {
            $hints[] = 'Nearby actors reported by the game: ' . implode(', ', array_slice($nearby, 0, 20)) . '.';
        }

        $prompt = trim(strval($metadata['prompt'] ?? ''));
        if ($prompt === '') {
            $prompt = 'Describe this Fallout New Vegas or Tale of Two Wastelands scene factually. '
                . 'Identify visible people, creatures, objects, terrain, buildings, lighting, weather, and notable actions. '
                . 'Do not invent names or facts that are not visible or supplied by game metadata. '
                . 'Return only a concise visual description suitable for an AI roleplay context.';
        }
        if ($hints) {
            $prompt .= "\n\nGame metadata:\n" . implode("\n", $hints);
        }

        $payload = [
            'model' => $model,
            'messages' => [[
                'role' => 'user',
                'content' => [
                    ['type' => 'text', 'text' => $prompt],
                    ['type' => 'image_url', 'image_url' => ['url' => 'data:' . $imageMime . ';base64,' . base64_encode($imageBytes)]],
                ],
            ]],
            'max_tokens' => max(64, min(intval($metadata['max_tokens'] ?? 350), 1200)),
            'temperature' => max(0.0, min(floatval($metadata['temperature'] ?? 0.2), 2.0)),
        ];

        $headers = ['Content-Type: application/json', 'Accept: application/json'];
        $badgeId = intval($connectorRow['api_badge_id'] ?? 0);
        if ($badgeId > 0) {
            $badge = $GLOBALS['db']->fetchOne('SELECT api_key FROM public.core_api_badge WHERE id=' . $badgeId);
            $apiKey = trim(strval($badge['api_key'] ?? ''));
            if ($apiKey !== '') {
                $headers[] = 'Authorization: Bearer ' . $apiKey;
            }
        }
        if ($driver === 'openrouter') {
            $headers[] = 'HTTP-Referer: https://dwemerdynamics.com/';
            $headers[] = 'X-Title: Dialectic PipVision';
        }

        $timeout = max(10, min(dialecticGetGeneralSettingInt('PIPVISION_REQUEST_TIMEOUT_SECONDS', 60), 180));
        $curl = curl_init($url);
        curl_setopt_array($curl, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            CURLOPT_CONNECTTIMEOUT => min(15, $timeout),
            CURLOPT_TIMEOUT => $timeout,
        ]);
        $response = curl_exec($curl);
        $curlError = curl_error($curl);
        $status = intval(curl_getinfo($curl, CURLINFO_HTTP_CODE));
        curl_close($curl);

        if (!is_string($response) || $response === '' || $status < 200 || $status >= 300) {
            throw new RuntimeException(
                'PipVision connector request failed' . ($curlError !== '' ? ': ' . $curlError : " (HTTP {$status})")
            );
        }
        $decoded = json_decode($response, true);
        $content = $decoded['choices'][0]['message']['content'] ?? '';
        if (is_array($content)) {
            $parts = [];
            foreach ($content as $part) {
                if (is_array($part) && isset($part['text'])) $parts[] = strval($part['text']);
            }
            $content = implode("\n", $parts);
        }
        $description = trim(strval($content));
        if ($description === '') {
            throw new RuntimeException('PipVision connector returned an empty description');
        }

        return [
            'description' => dialecticVisualContextText($description, 12000),
            'provider' => $driver,
            'model' => $model,
        ];
    }
}
