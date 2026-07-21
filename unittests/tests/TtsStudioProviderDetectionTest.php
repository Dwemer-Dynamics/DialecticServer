<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../lib/core/tts_studio_provider_detection.php';

final class TtsStudioProviderDetectionTest extends TestCase
{
    private function probe(int $status, $decoded = []): array
    {
        return [
            'response' => $status > 0 ? json_encode($decoded) : false,
            'decoded' => $decoded,
            'http_code' => $status,
            'curl_error' => $status > 0 ? '' : 'Connection refused',
        ];
    }

    public function testDetectsAudioCppFromCapabilitiesWithoutUsingThePort(): void
    {
        $runtime = dialecticTtsStudioClassifyPocketTtsRuntime(
            'http://127.0.0.1:9000',
            [],
            $this->probe(200, ['status' => 'ok']),
            $this->probe(200, ['data' => []]),
            $this->probe(404)
        );

        $this->assertTrue($runtime['reachable']);
        $this->assertSame('audio_cpp', $runtime['mode']);
    }

    public function testDetectsStandardApiFromSpeakersEndpoint(): void
    {
        $runtime = dialecticTtsStudioClassifyPocketTtsRuntime(
            'http://127.0.0.1:8024',
            ['api_format' => 'audio_cpp'],
            $this->probe(404),
            $this->probe(404),
            $this->probe(200, ['voices' => ['sample']])
        );

        $this->assertTrue($runtime['reachable']);
        $this->assertSame('standard', $runtime['mode']);
    }

    public function testFallsBackToConfiguredModeWhenServiceIsOffline(): void
    {
        $runtime = dialecticTtsStudioClassifyPocketTtsRuntime(
            'http://127.0.0.1:8086',
            [],
            $this->probe(0),
            $this->probe(0),
            $this->probe(0)
        );

        $this->assertFalse($runtime['reachable']);
        $this->assertSame('audio_cpp', $runtime['mode']);
    }

    public function testNormalizesDedicatedServiceIdentities(): void
    {
        $this->assertSame('chatterbox', dialecticTtsStudioNormalizeProviderIdentity('Chatterbox'));
        $this->assertSame('pockettts', dialecticTtsStudioNormalizeProviderIdentity('pocket_tts'));
        $this->assertSame('xtts-fastapi', dialecticTtsStudioNormalizeProviderIdentity('xtts'));
        $this->assertSame('', dialecticTtsStudioNormalizeProviderIdentity('unknown'));
    }
}
