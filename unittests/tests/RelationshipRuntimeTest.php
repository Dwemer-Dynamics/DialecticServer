<?php declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2).DIRECTORY_SEPARATOR.'lib'.DIRECTORY_SEPARATOR.'relationship_runtime.php';

final class RelationshipRuntimeTest extends TestCase
{
    private array $savedGlobals = [];

    protected function setUp(): void
    {
        foreach (['RELATIONSHIP_SYSTEM_ENABLED', 'RELLLM_CONNECTOR', 'RELATIONSHIP_UPDATE_CHANCE', 'PLAYER_NAME'] as $key) {
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

    public function testAutomaticEvaluationChanceUsesInclusiveBoundsAndSafeDefault(): void
    {
        $this->assertFalse(RelationshipManager::shouldRunAutomaticEvaluation(0, 1));
        $this->assertTrue(RelationshipManager::shouldRunAutomaticEvaluation(100, 100));
        $this->assertTrue(RelationshipManager::shouldRunAutomaticEvaluation(25, 25));
        $this->assertFalse(RelationshipManager::shouldRunAutomaticEvaluation(25, 26));
        $this->assertTrue(RelationshipManager::shouldRunAutomaticEvaluation('invalid', 50));
        $this->assertFalse(RelationshipManager::shouldRunAutomaticEvaluation('invalid', 51));
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

    public function testPlayerNotesSurviveAliasNormalizationAndAiRebuilds(): void
    {
        $note = "  Private reminder\nDo not share.  ";
        $existing = [
            'Courier' => ['aff' => 0, 'type' => 'neutral', 'custom_info' => $note],
            'Player' => ['aff' => 35, 'type' => 'platonic'],
            'Boone' => ['aff' => 20, 'type' => 'professional', 'custom_info' => '0'],
            'Cass' => ['aff' => 10, 'type' => 'platonic'],
        ];
        $incoming = [
            'Player' => ['aff' => 60, 'type' => 'romantic', 'custom_info' => 'AI overwrite'],
            'Rex' => ['aff' => 5, 'type' => 'neutral', 'custom_info' => 'AI invention'],
        ];
        $normalized = RelationshipManager::normalizeRelationshipMap($existing);
        $this->assertSame(35, $normalized['Player']['aff']);
        $this->assertSame($note, $normalized['Player']['custom_info']);

        foreach ([false, true] as $replaceExisting) {
            $merged = RelationshipManager::mergeAiRelationshipMap($existing, $incoming, $replaceExisting);
            $this->assertSame(60, $merged['Player']['aff']);
            $this->assertSame($note, $merged['Player']['custom_info']);
            $this->assertSame($existing['Boone'], $merged['Boone']);
            $this->assertArrayNotHasKey('custom_info', $merged['Rex']);
            $this->assertSame(!$replaceExisting, isset($merged['Cass']));
        }
        $stale = ['Player' => ['aff' => 40, 'type' => 'platonic', 'custom_info' => 'cleared by player']];
        $merged = RelationshipManager::mergeAiRelationshipMap(['Player' => ['aff' => 35]], $stale);
        $this->assertArrayNotHasKey('custom_info', $merged['Player']);
    }

    public function testPrivateNotesAreExcludedFromDialogueContext(): void
    {
        $relationships = ['Player' => ['aff' => 60, 'type' => 'platonic', 'custom_info' => 'PRIVATE_MARKER']];
        foreach ([false, true] as $tierOnly) {
            $context = RelationshipManager::buildContextFromRelationships('Veronica', $relationships, [], $tierOnly);
            $this->assertStringNotContainsString('PRIVATE_MARKER', $context);
        }
        $updated = RelationshipManager::applyChangeCommands($relationships, [
            'affinity' => [['target' => 'Player', 'delta' => 3]],
            'types' => [['target' => 'Player', 'type' => 'romantic']],
        ]);
        $this->assertSame(63, $updated['relationships']['Player']['aff']);
        $this->assertSame('PRIVATE_MARKER', $updated['relationships']['Player']['custom_info']);
    }

    public function testManualRelationshipSaveCanEditAndClearPrivateNotes(): void
    {
        require_once dirname(__DIR__, 2).'/ext/relationship_system/npc_save_handler.php';
        $post = [
            'extended_data' => json_encode(['relationships' => ['Player' => ['aff' => 5, 'custom_info' => 'old']]]),
            'relationships_locked' => '0',
        ];
        foreach (['new note', ''] as $note) {
            $post['relationships_jsonb'] = json_encode(['Player' => ['aff' => 5, 'custom_info' => $note]]);
            $this->assertTrue(dialecticMergePostedRelationshipsIntoExtendedData($post));
            $saved = json_decode($post['extended_data'], true);
            $this->assertSame($note, $saved['relationships']['Player']['custom_info'] ?? '');
            $this->assertFalse($saved['relationships_locked']);
        }
    }

    public function testCommandsAreExtractedAndAppliedWithBounds(): void
    {
        $commands = RelationshipManager::extractChangeCommands(
            'Fine. #REL:Player=+25# #TYPE:Player=Romance# #REL:Arcade=-150#'
        );
        $result = RelationshipManager::applyChangeCommands(
            ['Player' => ['aff' => 90, 'type' => 'neutral']],
            $commands
        );

        $this->assertTrue($result['changed']);
        $this->assertSame(100, $result['relationships']['Player']['aff']);
        $this->assertSame('romantic', $result['relationships']['Player']['type']);
        $this->assertSame(-100, $result['relationships']['Arcade']['aff']);
    }

    public function testInventedTypesAreRejectedButExistingCustomTypesRemainSelectable(): void
    {
        $relationships = [
            'Player' => ['aff' => 10, 'type' => 'trusted_ally'],
        ];

        $commands = RelationshipManager::extractChangeCommands(
            '#TYPE:Player=Soulmate# #TYPE:Arcade=Trusted_Ally#',
            $relationships
        );

        $this->assertCount(1, $commands['types']);
        $this->assertSame('Arcade', $commands['types'][0]['target']);
        $this->assertSame('trusted_ally', $commands['types'][0]['type']);
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

    public function testDedicatedRelationshipWorkerStartsOnlyAfterQueuedWork(): void
    {
        $root = dirname(__DIR__, 2).DIRECTORY_SEPARATOR.'ext'.DIRECTORY_SEPARATOR.'relationship_system';
        $contextSource = file_get_contents($root.DIRECTORY_SEPARATOR.'context_pre.php');
        $queueSource = file_get_contents($root.DIRECTORY_SEPARATOR.'async_queue.php');
        $workerSource = file_get_contents($root.DIRECTORY_SEPARATOR.'worker.php');

        $this->assertStringNotContainsString('_relEnsureWorkerRunning()', $contextSource);
        $this->assertStringContainsString('function _relEnsureWorkerRunning()', $queueSource);
        $this->assertSame(2, substr_count($queueSource, '_relEnsureWorkerRunning();'));
        $this->assertStringContainsString('_relProcessInitQueue(5)', $workerSource);
        $this->assertStringNotContainsString('DAEMON: Iteration', $workerSource);
    }

    public function testAutomaticEvaluationGatePrecedesConversationLookup(): void
    {
        $root = dirname(__DIR__, 2).DIRECTORY_SEPARATOR.'ext'.DIRECTORY_SEPARATOR.'relationship_system';
        $postRequestSource = file_get_contents($root.DIRECTORY_SEPARATOR.'postrequest.php');
        $contextSource = file_get_contents($root.DIRECTORY_SEPARATOR.'context_pre.php');

        $chanceGate = strpos($postRequestSource, 'RelationshipManager::shouldRunAutomaticEvaluation()');
        $listenerLookup = strpos($postRequestSource, '// Determine who the NPC was talking to');
        $this->assertIsInt($chanceGate);
        $this->assertIsInt($listenerLookup);
        $this->assertLessThan($listenerLookup, $chanceGate);
        $this->assertSame(1, substr_count($contextSource, 'RelationshipManager::getRelationships($npcName)'));
        $this->assertStringContainsString('RelationshipManager::buildContextFromRelationships(', $contextSource);
    }
}
