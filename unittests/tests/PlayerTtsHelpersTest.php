<?php declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once(__DIR__ . DIRECTORY_SEPARATOR . ".." . DIRECTORY_SEPARATOR . ".." . DIRECTORY_SEPARATOR . "lib" . DIRECTORY_SEPARATOR . "player_tts_helpers.php");
require_once(__DIR__ . DIRECTORY_SEPARATOR . ".." . DIRECTORY_SEPARATOR . ".." . DIRECTORY_SEPARATOR . "lib" . DIRECTORY_SEPARATOR . "dialectic_runtime.php");
require_once(__DIR__ . DIRECTORY_SEPARATOR . ".." . DIRECTORY_SEPARATOR . ".." . DIRECTORY_SEPARATOR . "lib" . DIRECTORY_SEPARATOR . "request.php");
require_once(__DIR__ . DIRECTORY_SEPARATOR . ".." . DIRECTORY_SEPARATOR . ".." . DIRECTORY_SEPARATOR . "lib" . DIRECTORY_SEPARATOR . "chat_helper_functions.php");
require_once(__DIR__ . DIRECTORY_SEPARATOR . ".." . DIRECTORY_SEPARATOR . ".." . DIRECTORY_SEPARATOR . "lib" . DIRECTORY_SEPARATOR . "dialectic_tts.php");
require_once(__DIR__ . DIRECTORY_SEPARATOR . ".." . DIRECTORY_SEPARATOR . ".." . DIRECTORY_SEPARATOR . "lib" . DIRECTORY_SEPARATOR . "response.php");

final class PlayerTtsHelpersTest extends TestCase
{
    protected function tearDown(): void
    {
        unset($GLOBALS['DIALECTIC_JSON_RESPONSE_LINES']);
    }

    public function testExtractsTextFromJsonPayload(): void
    {
        $payload = json_encode([
            'type' => 'inputtext',
            'text' => 'Hello there',
            'payload' => [
                'text' => 'Nested wins',
            ],
        ]);

        $this->assertSame(
            'Nested wins',
            dialecticExtractPlayerTtsDialogueLine($payload)
        );
    }

    public function testStripsPlayerPrefixAndUnsafeSeparators(): void
    {
        $this->assertSame(
            'Hello there friend',
            dialecticExtractPlayerTtsDialogueLine("Graussy: Hello|there\r\nfriend")
        );
    }

    public function testExtractsTextFromDialecticInputEnvelopeWithTargetSuffix(): void
    {
        $payload = json_encode([
            'schema' => 'dialectic.input.v1',
            'npc' => 'Doc Mitchell',
            'npc_id' => '0x00104c0f',
            'player' => 'Graussy',
            'text' => 'hello',
            'skip_player_tts' => true,
            'dialectic_mode' => 'STANDARD',
            'game' => 'fnv',
        ]);

        $this->assertSame('hello', dialecticExtractPlayerTtsDialogueLine($payload));
        $this->assertTrue(dialecticShouldSkipPlayerTtsForRequest('inputtext', $payload));
    }

    public function testAdaptsStructuredInputForDialoguePipeline(): void
    {
        $GLOBALS['PLAYER_NAME'] = 'Graussy';
        unset(
            $GLOBALS['DIALECTIC_STRUCTURED_INPUT_FIELDS'],
            $GLOBALS['DIALECTIC_PLAYER_INPUT_TEXT'],
            $GLOBALS['DIALECTIC_INPUT_TARGET'],
            $GLOBALS['DIALECTIC_SKIP_PLAYER_TTS']
        );

        $payload = json_encode([
            'schema' => 'dialectic.input.v1',
            'npc' => 'Doc Mitchell',
            'npc_id' => '0x00104c0f',
            'player' => 'Graussy',
            'text' => 'hello',
            'skip_player_tts' => true,
            'audience_snapshot' => [
                'people' => '|Doc Mitchell|Graussy|Veronica|',
            ],
            'game' => 'fnv',
        ]);
        $gameRequest = ['inputtext', '1', '2', $payload];

        $result = dialectic_adapt_json_input_payload_for_pipeline($gameRequest);

        $this->assertTrue($result['changed']);
        $this->assertSame('Graussy: hello (Talking to Doc Mitchell)', $gameRequest[3]);
        $this->assertSame('hello', $GLOBALS['DIALECTIC_PLAYER_INPUT_TEXT']);
        $this->assertTrue($GLOBALS['DIALECTIC_SKIP_PLAYER_TTS']);
        $this->assertSame('{"people":"|Doc Mitchell|Graussy|Veronica|"}', $gameRequest[4]);
    }

    public function testRuntimePlayerTextExtractionRequiresJsonPayload(): void
    {
        $this->assertSame('', dialectic_extract_player_text('npc=Doc%20Mitchell&player=Graussy&text=hello'));

        $payload = json_encode([
            'schema' => 'dialectic.input.v1',
            'player' => 'Graussy',
            'text' => 'hello',
        ]);

        $this->assertSame('hello', dialectic_extract_player_text($payload));
    }

    public function testAudienceSnapshotPeopleCanUseJsonActorArray(): void
    {
        $payload = json_encode([
            'people' => [
                ['name' => 'Graussy'],
                ['actor_name' => 'Doc Mitchell'],
                ['display_name' => 'Veronica'],
                '|Graussy|',
            ],
        ]);

        $this->assertSame('|Graussy|Doc Mitchell|Veronica|', dialecticDecodeAudienceSnapshotField($payload));
    }

    public function testRechatPayloadPreservesActorFormIds(): void
    {
        $payload = dialecticParseServerSideRechatPayload(json_encode([
            'speaker' => 'Veronica',
            'listener_hint' => 'Deputy Weld',
            'rechat_target_hint' => 'Deputy Weld',
            'speaker_formid' => '0x000E32A9',
            'listener_formid' => '0x0002F586',
            'target_formid' => '0x0002F586',
        ]));

        $this->assertSame('0x000E32A9', $payload['speaker_formid']);
        $this->assertSame('0x0002F586', $payload['listener_formid']);
        $this->assertSame('0x0002F586', $payload['target_formid']);
    }

    public function testRechatActivityBlocksFreshUnconsciousAndSleepingBystanders(): void
    {
        $now = (int)round(microtime(true) * 1000);
        $unconscious = ['metadata' => json_encode([
            'activity_status' => ['is_unconscious' => true, 'timestamp' => $now],
        ])];
        $sleeping = ['metadata' => json_encode([
            'activity_status' => ['is_sleeping' => true, 'timestamp' => $now],
        ])];

        $this->assertSame(
            'fresh activity status marks actor unconscious',
            dialecticRechatActivityBlockReason($unconscious, true)
        );
        $this->assertSame(
            'fresh activity status marks actor sleeping',
            dialecticRechatActivityBlockReason($sleeping, false)
        );
        $this->assertSame('', dialecticRechatActivityBlockReason($sleeping, true));
    }

    public function testRechatActivityFailsOpenForMissingOrStaleStatus(): void
    {
        $stale = ['metadata' => [
            'activity_status' => [
                'is_unconscious' => true,
                'timestamp' => (int)round(microtime(true) * 1000) - 60000,
            ],
        ]];

        $this->assertSame('', dialecticRechatActivityBlockReason(null));
        $this->assertSame('', dialecticRechatActivityBlockReason(['metadata' => []]));
        $this->assertSame('', dialecticRechatActivityBlockReason($stale));
    }

    public function testTextOnlyPlayerLineHasNoTtsCacheKey(): void
    {
        $GLOBALS['PLAYER_NAME'] = 'Graussy';
        $GLOBALS['DIALECTIC_JSON_RESPONSE_LINES'] = [];

        emitPlayerMenuTextOnlyLine('hello there');

        $this->assertCount(1, $GLOBALS['DIALECTIC_JSON_RESPONSE_LINES']);
        $line = $GLOBALS['DIALECTIC_JSON_RESPONSE_LINES'][0];
        $this->assertSame('Player', $line['speaker']);
        $this->assertSame('hello there', $line['text']);
        $this->assertSame('__player_text_only', $line['listener']);
        $this->assertArrayNotHasKey('tts_cache_key', $line);
        $this->assertArrayNotHasKey('tts_text', $line);
    }

    public function testInworldDialecticLinesUseRevisedCacheVersion(): void
    {
        $oldTtsFunction = $GLOBALS['TTSFUNCTION'] ?? null;
        $hadTtsFunction = array_key_exists('TTSFUNCTION', $GLOBALS);
        $oldDialecticName = $GLOBALS['DIALECTIC_NAME'] ?? null;
        $hadDialecticName = array_key_exists('DIALECTIC_NAME', $GLOBALS);

        try {
            $GLOBALS['DIALECTIC_NAME'] = 'Player';
            $GLOBALS['TTSFUNCTION'] = 'inworld';

            $affectedSeed = dialectic_tts_cache_seed(dirname(__DIR__, 2), 'Player', 'What is Dialectic anyway?');
            $unaffectedSeed = dialectic_tts_cache_seed(dirname(__DIR__, 2), 'Player', 'What is synthesis anyway?');

            $GLOBALS['TTSFUNCTION'] = 'pockettts';
            $otherConnectorSeed = dialectic_tts_cache_seed(dirname(__DIR__, 2), 'Player', 'What is Dialectic anyway?');

            $this->assertStringStartsWith("dialectic.tts.v4.inworld-dialectic-v2\n", $affectedSeed);
            $this->assertStringStartsWith("dialectic.tts.v4\ninworld\n", $unaffectedSeed);
            $this->assertStringStartsWith("dialectic.tts.v4\npockettts\n", $otherConnectorSeed);
        } finally {
            if ($hadTtsFunction) {
                $GLOBALS['TTSFUNCTION'] = $oldTtsFunction;
            } else {
                unset($GLOBALS['TTSFUNCTION']);
            }
            if ($hadDialecticName) {
                $GLOBALS['DIALECTIC_NAME'] = $oldDialecticName;
            } else {
                unset($GLOBALS['DIALECTIC_NAME']);
            }
        }
    }
}
