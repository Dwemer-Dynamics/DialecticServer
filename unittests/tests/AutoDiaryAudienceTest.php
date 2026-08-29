<?php declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../lib/dynamic_update_util.php';
require_once __DIR__ . '/../../lib/core/core_profiles.class.php';

final class AutoDiaryAudienceTest extends TestCase
{
    protected function setUp(): void
    {
        $GLOBALS['PLAYER_NAME'] = 'Graussy';
    }

    public function testUsesFreshEventAudienceAndExcludesPlayerAndInvalidActors(): void
    {
        $request = [
            'goodnight',
            '1',
            '2',
            '{}',
            json_encode([
                'people' => '|Graussy|Veronica|Dead Raider (dead)|Veronica|',
                'actors' => [
                    ['name' => 'Doc Mitchell', 'eligible' => true],
                    ['name' => 'Rex', 'eligible' => false],
                    ['name' => 'Disabled NPC (disabled)', 'eligible' => true],
                ],
            ]),
        ];

        $result = dialecticAutoDiaryAudienceNames($request);

        self::assertSame('event_snapshot', $result['source']);
        self::assertSame(['Veronica', 'Doc Mitchell'], $result['names']);
    }

    public function testNormalizesStatusSuffixesAndNamesCaseInsensitively(): void
    {
        $request = [
            'waitstart',
            '1',
            '2',
            '{}',
            json_encode(['people' => '|Veronica (can hear you)|veronica|Chet|']),
        ];

        $result = dialecticAutoDiaryAudienceNames($request);

        self::assertSame(['Veronica', 'Chet'], $result['names']);
    }

    public function testNewProfilesExposeDiaryDefaultsWithoutEnablingAutoDiary(): void
    {
        $metadata = CoreProfile::defaultMetadata();

        self::assertFalse($metadata['AUTO_DIARY_ENABLED']);
        self::assertTrue($metadata['AUTO_DIARY_WAIT_ENABLED']);
        self::assertSame(120, $metadata['DIARY_COOLDOWN']);
        self::assertSame(100, $metadata['CONTEXT_HISTORY_DIARY']);
        self::assertStringContainsString("#DIALECTIC_NAME#'s diary", $metadata['DIARY_PROMPT']);
    }
}
