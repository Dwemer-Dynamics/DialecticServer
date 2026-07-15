<?php

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/lib/logger.php';
require_once dirname(__DIR__, 2) . '/lib/voice_clone_resolver.php';
require_once dirname(__DIR__, 2) . '/lib/core/npc_master.class.php';

final class NpcVoiceFallbackTest extends TestCase
{
    protected function tearDown(): void
    {
        unset($GLOBALS['TTSFUNCTION'], $GLOBALS['TTS_FUNCTION']);
    }

    public function testSampleProviderUsesConfiguredFallbackWhenCloneIsMissing(): void
    {
        $GLOBALS['TTSFUNCTION'] = 'pockettts';
        $npcMaster = (new ReflectionClass(NpcMaster::class))->newInstanceWithoutConstructor();
        $method = new ReflectionMethod(NpcMaster::class, 'withResolvedVoiceSample');
        $method->setAccessible(true);

        $result = $method->invoke($npcMaster, [
            'original_voice' => 'DefinitelyMissingFalloutVoice',
            'fallback_voice' => 'default_female',
            'resolved_voice' => 'DefinitelyMissingFalloutVoice',
            'used_fallback' => false,
        ], [
            'npc_name' => 'Test NPC',
            'gender' => 'Female',
            'extended_data' => '{}',
        ]);

        self::assertSame('default_female', $result['resolved_voice']);
        self::assertSame('default_female', $result['resolved_voice_reference']);
        self::assertSame('sample_missing', $result['fallback_reason']);
        self::assertTrue($result['used_fallback']);
    }

    public function testNamedVoiceProviderKeepsOriginalVoiceWithoutLocalSample(): void
    {
        $GLOBALS['TTSFUNCTION'] = 'inworld';
        $npcMaster = (new ReflectionClass(NpcMaster::class))->newInstanceWithoutConstructor();
        $method = new ReflectionMethod(NpcMaster::class, 'withResolvedVoiceSample');
        $method->setAccessible(true);

        $result = $method->invoke($npcMaster, [
            'original_voice' => 'workspace__voice',
            'fallback_voice' => 'default_male',
            'resolved_voice' => 'workspace__voice',
            'used_fallback' => false,
        ], [
            'npc_name' => 'Test NPC',
            'gender' => 'Male',
            'extended_data' => '{}',
        ]);

        self::assertSame('workspace__voice', $result['resolved_voice']);
        self::assertSame('workspace__voice', $result['resolved_voice_reference']);
        self::assertFalse($result['used_fallback']);
    }
}
