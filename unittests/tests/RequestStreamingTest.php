<?php declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2).DIRECTORY_SEPARATOR.'lib'.DIRECTORY_SEPARATOR.'request.php';

final class RequestStreamingTest extends TestCase
{
    public function testPluginEventStreamsByDefaultWithoutHeaders(): void
    {
        $event = dialectic_normalize_json_event([
            'schema' => 'dialectic.event.v1',
            'type' => 'inputtext',
            'ts' => 100,
            'gamets' => 200,
            'payload' => [
                'schema' => 'dialectic.input.v1',
                'npc' => 'Veronica',
                'player' => 'Graussy',
                'text' => 'hello',
            ],
        ]);

        $this->assertTrue(dialectic_should_stream_json_response($event));
    }

    public function testPluginEventCanExplicitlyRequestBufferedResponse(): void
    {
        $event = dialectic_normalize_json_event([
            'schema' => 'dialectic.event.v1',
            'type' => 'inputtext',
            'response_streaming' => false,
            'payload' => [
                'schema' => 'dialectic.input.v1',
                'npc' => 'Veronica',
                'player' => 'Graussy',
                'text' => 'hello',
            ],
        ]);

        $this->assertFalse(dialectic_should_stream_json_response($event));
    }

    public function testNonPluginJsonRequiresStreamingHeader(): void
    {
        $event = dialectic_normalize_json_event([
            'schema' => 'dialectic.ui.request.v1',
            'type' => 'status',
            'payload' => [],
        ]);

        $this->assertFalse(dialectic_should_stream_json_response($event));
        $this->assertTrue(dialectic_should_stream_json_response($event, 'application/x-ndjson', ''));
        $this->assertTrue(dialectic_should_stream_json_response($event, '', '1'));
    }
}
