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

    public function testCombatBarkPromptContainsOnlyCurrentAlliesAndHostiles(): void
    {
        $event = dialectic_normalize_json_event([
            'schema' => 'dialectic.event.v1',
            'type' => 'combatbark',
            'payload' => [
                'schema' => 'dialectic.rpg_event.v1',
                'combat' => [
                    'allies_currently_fighting' => ['Graussy', 'Veronica'],
                    'hostile_combatants' => ['Giant Radscorpion'],
                ],
            ],
        ]);

        $this->assertSame(
            "<combat>\n# Allies Currently Fighting\n- Graussy\n- Veronica\n\n# Hostile Combatants\n- Giant Radscorpion\n</combat>",
            dialectic_build_combat_prompt_from_event($event)
        );
    }

    public function testExternalReactionRequestRequiresExactActorAndInstruction(): void
    {
        $request = dialectic_decode_external_actor_request(json_encode([
            'schema' => 'dialectic.external_request.v1',
            'request' => 'reaction',
            'npc' => 'Veronica',
            'npc_id' => '0x000e32a9',
            'instruction' => "  React to the explosion.  ",
            'game' => 'fnv',
        ], JSON_THROW_ON_ERROR), 'dialectic.external_request.v1', 'reaction');

        $this->assertTrue($request['ok']);
        $this->assertSame('0x000E32A9', $request['npc_id']);
        $this->assertSame('React to the explosion.', $request['instruction']);
    }

    public function testExternalRequestRejectsPlayerFormId(): void
    {
        $request = dialectic_decode_external_actor_request(json_encode([
            'schema' => 'dialectic.external_request.v1',
            'request' => 'comment',
            'npc' => 'Courier',
            'npc_id' => '0x00000014',
            'game' => 'fnv',
        ], JSON_THROW_ON_ERROR), 'dialectic.external_request.v1', 'comment');

        $this->assertFalse($request['ok']);
    }

    public function testExternalExactTtsPreservesInternalWhitespaceAndRejectsOverlengthText(): void
    {
        $valid = dialectic_decode_external_actor_request(json_encode([
            'schema' => 'dialectic.npc_tts.v1',
            'npc' => 'Veronica',
            'npc_id' => '0x000E32A9',
            'text' => "  Keep   this spacing.  ",
            'game' => 'fnv',
        ], JSON_THROW_ON_ERROR), 'dialectic.npc_tts.v1', 'tts');
        $this->assertTrue($valid['ok']);
        $this->assertSame('Keep   this spacing.', $valid['text']);

        $invalid = dialectic_decode_external_actor_request(json_encode([
            'schema' => 'dialectic.npc_tts.v1',
            'npc' => 'Veronica',
            'npc_id' => '0x000E32A9',
            'text' => str_repeat('x', 1001),
            'game' => 'fnv',
        ], JSON_THROW_ON_ERROR), 'dialectic.npc_tts.v1', 'tts');
        $this->assertFalse($invalid['ok']);
    }
}
