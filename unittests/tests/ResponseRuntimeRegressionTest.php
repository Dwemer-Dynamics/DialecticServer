<?php declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once(__DIR__ . "/../../lib/logger.php");

@define("MAXIMUM_SENTENCE_SIZE", 125);
@define("MINIMUM_SENTENCE_SIZE", 75);

require_once(__DIR__ . "/../../lib/chat_helper_functions.php");
require_once(__DIR__ . "/../../lib/power_awareness.php");

final class ResponseRuntimeRegressionTest extends TestCase
{
    protected function tearDown(): void
    {
        unset(
            $GLOBALS['db'],
            $GLOBALS['DIALECTIC_NAME'],
            $GLOBALS['DIALECTIC_CORE_CURRENT_NPC_DATA'],
            $GLOBALS['DIALECTIC_CORE_CURRENT_TTS_CONNECTOR_ID'],
            $GLOBALS['PATCH_OVERRIDE_VOICE'],
            $GLOBALS['PATCH_OVERRIDE_VOICE_ID'],
            $GLOBALS['TTS'],
            $GLOBALS['TTSFUNCTION'],
            $GLOBALS['TTS_FUNCTION']
        );
    }

    public function testNarratorVoiceSettingsLoadThroughCoreProfile(): void
    {
        $GLOBALS['db'] = new class {
            public function escape(string $value): string
            {
                return str_replace("'", "''", $value);
            }

            public function fetchOne(string $query): array
            {
                if (str_contains($query, "id = 'profile_id'")) {
                    return ['value' => '7'];
                }
                if (str_contains($query, 'FROM core_profiles WHERE id = 7')) {
                    return ['id' => 7, 'tts_connector_id' => 0];
                }
                if (str_contains($query, "id = 'voiceid'")) {
                    return ['value' => 'maleadult02'];
                }
                return [];
            }
        };
        $GLOBALS['DIALECTIC_NAME'] = 'Ranger Ghost';
        $GLOBALS['TTS'] = [];
        $GLOBALS['TTSFUNCTION'] = 'none';

        loadNarratorVoiceSettings();

        $this->assertSame('maleadult02', $GLOBALS['PATCH_OVERRIDE_VOICE']);
    }

    public function testPowerAwarenessReadsNpcMasterMetadata(): void
    {
        $GLOBALS['db'] = new class {
            public string $lastQuery = '';

            public function escape(string $value): string
            {
                return str_replace("'", "''", $value);
            }

            public function fetchOne(string $query): array
            {
                $this->lastQuery = $query;
                return ['metadata' => '{"stats":{"level":18}}'];
            }
        };

        $this->assertSame(18, getNpcLevel('Ranger Ghost'));
        $this->assertStringContainsString('FROM core_npc_master', $GLOBALS['db']->lastQuery);
    }

    public function testOnlyLegionNpcSpeechUsesKaiserPronunciation(): void
    {
        $GLOBALS['db'] = new class {};
        $GLOBALS['DIALECTIC_NAME'] = 'Vulpes Inculta';
        $legionNpc = [
            'npc_name' => 'Vulpes Inculta',
            'extended_data' => json_encode([
                'factions' => [
                    ['formid' => '0x000ee68a', 'rank' => 0],
                ],
            ]),
        ];
        $nonLegionNpc = [
            'npc_name' => 'Vulpes Inculta',
            'extended_data' => json_encode([
                'factions' => [
                    ['formid' => '000A46E7', 'rank' => 0],
                ],
            ]),
        ];

        $this->assertSame(
            "Kaiser's Legion obeys Kaiser.",
            dialecticApplyLegionTtsPronunciation("Caesar's Legion obeys Caesar.", $legionNpc)
        );
        $this->assertSame(
            "Caesar's Legion obeys Caesar.",
            dialecticApplyLegionTtsPronunciation("Caesar's Legion obeys Caesar.", $nonLegionNpc)
        );
        $this->assertSame(
            'The Courier opposes Caesar.',
            dialecticApplyLegionTtsPronunciation('The Courier opposes Caesar.', [])
        );
    }
}
