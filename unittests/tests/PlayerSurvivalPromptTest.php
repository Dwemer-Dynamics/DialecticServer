<?php declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . 'lib' . DIRECTORY_SEPARATOR . 'data_functions.php';

final class PlayerSurvivalPromptTest extends TestCase
{
    private function survivalState(array $overrides = []): array
    {
        return array_replace_recursive([
            'hardcore_enabled' => true,
            'needs' => [
                'hunger' => ['stage' => 2],
                'dehydration' => ['stage' => 3],
                'sleep_deprivation' => ['stage' => 0],
            ],
            'radiation' => ['stage' => 1],
            'updated_at' => 1_000,
        ], $overrides);
    }

    public function testFreshnessUsesTheChimParityWindow(): void
    {
        $state = $this->survivalState();

        $this->assertTrue(dialecticIsPlayerSurvivalStateFresh($state, 180, 1_180));
        $this->assertFalse(dialecticIsPlayerSurvivalStateFresh($state, 180, 1_181));
    }

    public function testConditionBlockUsesOnlyActiveNeeds(): void
    {
        $GLOBALS['PLAYER_NAME'] = 'Graussy';

        $this->assertSame(
            "\n\n<condition>\n#Graussy's Condition\n- Hunger: Hungry\n- Thirst: Severely dehydrated\n- Radiation: Minor radiation poisoning\n</condition>\n",
            dialecticBuildPlayerSurvivalConditionBlock($this->survivalState())
        );
    }

    public function testHardcoreNeedsAreHiddenWhenHardcoreIsDisabled(): void
    {
        $state = $this->survivalState(['hardcore_enabled' => false]);

        $this->assertSame(
            'minor radiation poisoning',
            dialecticDescribePlayerSurvivalState($state)
        );
    }

    public function testHealthyStateProducesNoPromptText(): void
    {
        $state = $this->survivalState([
            'needs' => [
                'hunger' => ['stage' => 0],
                'dehydration' => ['stage' => 0],
                'sleep_deprivation' => ['stage' => 0],
            ],
            'radiation' => ['stage' => 0],
        ]);

        $this->assertSame('', dialecticDescribePlayerSurvivalState($state));
        $this->assertSame('', dialecticBuildPlayerSurvivalConditionBlock($state));
    }
}
