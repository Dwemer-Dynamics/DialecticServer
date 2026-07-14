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
}
