<?php declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . 'lib' . DIRECTORY_SEPARATOR . 'settings.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . 'lib' . DIRECTORY_SEPARATOR . 'data_functions.php';

final class NearbyActorContextOptionsTest extends TestCase
{
    private const EXPECTED_OPTIONS = [
        'basic_summary',
        'appearance',
        'equipment',
        'equipment_descriptions',
        'current_activity',
        'power_awareness',
        'factions',
        'custom_state',
    ];

    public function testNearbyActorCatalogMatchesSupportedFalloutContext(): void
    {
        $catalog = dialecticGetPromptContextOptionCatalog();

        $this->assertArrayHasKey('enabled_nearby_actor_subsections', $catalog);
        $this->assertSame(
            self::EXPECTED_OPTIONS,
            array_keys($catalog['enabled_nearby_actor_subsections'])
        );
    }

    public function testLegacySavedContextDefaultsNewNearbyActorControlsToEnabled(): void
    {
        $options = dialecticNormalizePromptContextOptions([
            'enabled_sections' => ['nearby_actors'],
        ]);

        $this->assertSame(
            self::EXPECTED_OPTIONS,
            $options['enabled_nearby_actor_subsections']
        );
    }

    public function testNearbyActorControlsCanBeDisabledIndependently(): void
    {
        $options = dialecticNormalizePromptContextOptions([
            'enabled_nearby_actor_subsections' => ['basic_summary', 'equipment'],
        ]);

        $this->assertSame(
            ['basic_summary', 'equipment'],
            $options['enabled_nearby_actor_subsections']
        );
    }

    public function testEquipmentDescriptionsRemainSeparateFromEquipmentPresence(): void
    {
        $metadata = [
            'equipment_structured' => [
                'weapon' => [
                    'name' => '9mm Pistol',
                    'baseid' => '0x000E3778',
                    'condition' => 0.8,
                ],
            ],
        ];

        $withoutDescriptions = dialecticBuildEquipmentLinesFromMetadata($metadata);
        $withDescriptions = dialecticBuildEquipmentLinesFromMetadata(
            $metadata,
            static fn(string $name, string $baseid): string => 'A compact semi-automatic handgun.'
        );

        $this->assertSame('- Weapon: 9mm Pistol (condition 80%)', $withoutDescriptions[0]);
        $this->assertSame(
            '- Weapon: 9mm Pistol (condition 80%; A compact semi-automatic handgun.)',
            $withDescriptions[0]
        );
    }

    public function testAttackTargetResolutionDoesNotGuessAnotherNearbyActor(): void
    {
        $previousDb = $GLOBALS['db'] ?? null;
        $GLOBALS['db'] = new class {
            public function fetchAll(string $query): array
            {
                return [[
                    'party' => json_encode([
                        'player' => 'Courier',
                        'actors' => [
                            ['name' => 'Swank', 'scene_eligible' => true],
                            ['name' => 'Chairman', 'scene_eligible' => true],
                        ],
                    ]),
                ]];
            }
        };

        try {
            $this->assertSame('Swank', FindClosestNPCName('swank'));
            $this->assertSame('Benny', FindClosestNPCName('Benny'));
        } finally {
            if ($previousDb === null) {
                unset($GLOBALS['db']);
            } else {
                $GLOBALS['db'] = $previousDb;
            }
        }
    }

    public function testRadioPromptIncludesStationAndSongForCurrentFollower(): void
    {
        $previousName = $GLOBALS['DIALECTIC_NAME'] ?? null;
        $GLOBALS['DIALECTIC_NAME'] = 'Veronica';

        try {
            $prompt = buildRadioPrompt(
                ['radio' => [
                    'active' => true,
                    'station' => 'Radio <New Vegas>',
                    'station_formid' => '0x0016C0B2',
                    'song' => 'Big & Iron',
                ]],
                ['party_members' => [['name' => 'Veronica', 'refid' => '0x000E32A9']]]
            );

            $this->assertStringContainsString('<radio>', $prompt);
            $this->assertStringContainsString('<station>Radio &lt;New Vegas&gt;</station>', $prompt);
            $this->assertStringContainsString('<song>Big &amp; Iron</song>', $prompt);
        } finally {
            if ($previousName === null) {
                unset($GLOBALS['DIALECTIC_NAME']);
            } else {
                $GLOBALS['DIALECTIC_NAME'] = $previousName;
            }
        }
    }

    public function testRadioPromptUsesLiveTeammateFlagAndRejectsNonFollower(): void
    {
        $previousName = $GLOBALS['DIALECTIC_NAME'] ?? null;
        $nearby = ['actors' => [
            ['name' => 'Veronica', 'refid' => '0x000E32A9', 'is_player_teammate' => true],
            ['name' => 'Sunny Smiles', 'refid' => '0x00104C6B', 'is_player_teammate' => false],
        ]];
        $world = ['radio' => [
            'active' => true,
            'station' => 'Mojave Music Radio',
            'station_formid' => '0x0016C0B3',
            'song' => 'Blue Moon',
        ]];

        try {
            $GLOBALS['DIALECTIC_NAME'] = 'Veronica';
            $this->assertStringContainsString('<radio>', buildRadioPrompt($world, $nearby));

            $GLOBALS['DIALECTIC_NAME'] = 'Sunny Smiles';
            $this->assertSame('', buildRadioPrompt($world, $nearby));
        } finally {
            if ($previousName === null) {
                unset($GLOBALS['DIALECTIC_NAME']);
            } else {
                $GLOBALS['DIALECTIC_NAME'] = $previousName;
            }
        }
    }

    public function testRadioPromptOmitsInactiveRadioAndOptionalSong(): void
    {
        $previousName = $GLOBALS['DIALECTIC_NAME'] ?? null;
        $GLOBALS['DIALECTIC_NAME'] = 'Veronica';
        $nearby = ['party_members' => [['name' => 'Veronica']]];

        try {
            $this->assertSame('', buildRadioPrompt(
                ['radio' => ['active' => false, 'station' => '', 'station_formid' => '', 'song' => '']],
                $nearby
            ));

            $stationOnly = buildRadioPrompt(
                ['radio' => [
                    'active' => true,
                    'station' => 'Radio New Vegas',
                    'station_formid' => '0x0016C0B2',
                    'song' => '',
                ]],
                $nearby
            );
            $this->assertStringContainsString('<station>Radio New Vegas</station>', $stationOnly);
            $this->assertStringNotContainsString('<song>', $stationOnly);
        } finally {
            if ($previousName === null) {
                unset($GLOBALS['DIALECTIC_NAME']);
            } else {
                $GLOBALS['DIALECTIC_NAME'] = $previousName;
            }
        }
    }
}
