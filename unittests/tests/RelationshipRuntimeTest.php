<?php declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2).DIRECTORY_SEPARATOR.'lib'.DIRECTORY_SEPARATOR.'relationship_runtime.php';

final class RelationshipRuntimeTest extends TestCase
{
    private array $savedGlobals = [];

    protected function setUp(): void
    {
        foreach (['RELATIONSHIP_SYSTEM_ENABLED', 'RELLLM_CONNECTOR', 'PLAYER_NAME'] as $key) {
            $this->savedGlobals[$key] = [
                'exists' => array_key_exists($key, $GLOBALS),
                'value' => $GLOBALS[$key] ?? null,
            ];
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->savedGlobals as $key => $saved) {
            if ($saved['exists']) {
                $GLOBALS[$key] = $saved['value'];
            } else {
                unset($GLOBALS[$key]);
            }
        }
    }

    public function testEnabledSettingHandlesStoredBooleanRepresentations(): void
    {
        foreach ([true, 1, '1', 'true', 'YES', 'on'] as $enabled) {
            $this->assertTrue(dialecticRelationshipSettingEnabled($enabled));
        }
        foreach ([false, 0, '0', 'false', 'no', 'off', '', null] as $disabled) {
            $this->assertFalse(dialecticRelationshipSettingEnabled($disabled));
        }
    }

    public function testDedicatedConnectorStillRequiresMasterToggle(): void
    {
        $GLOBALS['RELATIONSHIP_SYSTEM_ENABLED'] = false;
        $this->assertFalse(dialecticRelationshipUsesDedicatedConnector(5));

        $GLOBALS['RELATIONSHIP_SYSTEM_ENABLED'] = true;
        $this->assertTrue(dialecticRelationshipUsesDedicatedConnector(5));
        $this->assertFalse(dialecticRelationshipUsesDedicatedConnector(0));
    }

    public function testContextIncludesPlayerAndKnownNearbyNpcOnly(): void
    {
        $GLOBALS['PLAYER_NAME'] = 'Graussy';
        $context = RelationshipManager::buildContextFromRelationships(
            'Veronica',
            [
                'Player' => ['aff' => 35, 'type' => 'platonic'],
                'Arcade' => ['aff' => -8, 'type' => 'rival'],
            ],
            ['Arcade', 'Unknown Settler'],
            false
        );

        $this->assertStringContainsString("[Veronica's RELATIONSHIPS]", $context);
        $this->assertStringContainsString('Graussy: +35', $context);
        $this->assertStringContainsString('Arcade: -8', $context);
        $this->assertStringNotContainsString('Unknown Settler', $context);
    }

    public function testTierOnlyContextOmitsNumericAffinity(): void
    {
        $GLOBALS['PLAYER_NAME'] = 'Courier';
        $context = RelationshipManager::buildContextFromRelationships(
            'Veronica',
            ['Player' => ['aff' => 60, 'type' => 'platonic']],
            [],
            true
        );

        $this->assertStringContainsString('Courier: Fond', $context);
        $this->assertStringNotContainsString('+60', $context);
    }

    public function testCommandsAreExtractedAndAppliedWithBounds(): void
    {
        $commands = RelationshipManager::extractChangeCommands(
            'Fine. #REL:Player=+25# #TYPE:Player=Friend# #REL:Arcade=-150#'
        );
        $result = RelationshipManager::applyChangeCommands(
            ['Player' => ['aff' => 90, 'type' => 'neutral']],
            $commands
        );

        $this->assertTrue($result['changed']);
        $this->assertSame(100, $result['relationships']['Player']['aff']);
        $this->assertSame('friend', $result['relationships']['Player']['type']);
        $this->assertSame(-100, $result['relationships']['Arcade']['aff']);
    }

    public function testCompletedStreamIsParsedOnceAfterAllLinesArrive(): void
    {
        $GLOBALS['RELATIONSHIP_SYSTEM_ENABLED'] = true;
        $GLOBALS['RELLLM_CONNECTOR'] = 0;
        $calls = [];

        $cleaned = dialecticRelationshipProcessInlineCompletedResponse(
            ['First streamed line.', '', 'Second line. #REL:Player=+5#'],
            'Veronica',
            static function (string $response, string $npc) use (&$calls): string {
                $calls[] = [$response, $npc];
                return str_replace('#REL:Player=+5#', '', $response);
            }
        );

        $this->assertCount(1, $calls);
        $this->assertSame('First streamed line. Second line. #REL:Player=+5#', $calls[0][0]);
        $this->assertSame('Veronica', $calls[0][1]);
        $this->assertSame('First streamed line. Second line. ', $cleaned);
    }

    public function testDisabledAndDedicatedModesDoNotRunInlineParser(): void
    {
        $calls = 0;
        $parser = static function () use (&$calls): string {
            ++$calls;
            return '';
        };

        $GLOBALS['RELATIONSHIP_SYSTEM_ENABLED'] = false;
        $GLOBALS['RELLLM_CONNECTOR'] = 0;
        dialecticRelationshipProcessInlineCompletedResponse(['Hello'], 'Veronica', $parser);

        $GLOBALS['RELATIONSHIP_SYSTEM_ENABLED'] = true;
        $GLOBALS['RELLLM_CONNECTOR'] = 5;
        dialecticRelationshipProcessInlineCompletedResponse(['Hello'], 'Veronica', $parser);

        $this->assertSame(0, $calls);
    }

    public function testMainPipelineRunsHooksAroundTheCompleteLlmTurn(): void
    {
        $source = file_get_contents(dirname(__DIR__, 2).DIRECTORY_SEPARATOR.'main_dialectic_pipeline.php');
        $contextHook = strpos($source, 'relationship_system" . DIRECTORY_SEPARATOR . "context_pre.php"');
        $llmCall = strpos($source, '$outputWasValid = call_llm();');
        $postHook = strpos($source, 'relationship_system" . DIRECTORY_SEPARATOR . "postrequest.php"');

        $this->assertIsInt($contextHook);
        $this->assertIsInt($llmCall);
        $this->assertIsInt($postHook);
        $this->assertLessThan($llmCall, $contextHook);
        $this->assertGreaterThan($llmCall, $postHook);
    }

    public function testDedicatedRelationshipWorkerWasRestored(): void
    {
        $root = dirname(__DIR__, 2).DIRECTORY_SEPARATOR.'ext'.DIRECTORY_SEPARATOR.'relationship_system';
        $this->assertFileExists($root.DIRECTORY_SEPARATOR.'async_queue.php');
        $this->assertFileExists($root.DIRECTORY_SEPARATOR.'relationship_llm.php');
        $this->assertFileExists($root.DIRECTORY_SEPARATOR.'worker.php');
    }
}
