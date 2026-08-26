<?php declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'DatabaseTestCase.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . 'lib' . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'npc_master.class.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . 'lib' . DIRECTORY_SEPARATOR . 'runtime_bootstrap.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . 'lib' . DIRECTORY_SEPARATOR . 'eventlog_helper.php';

final class NpcPersistenceEncodingTest extends DatabaseTestCase
{
    private NpcMaster $npcMaster;

    public function setUp(): void
    {
        parent::setUp();
        $GLOBALS['db'] = new sql();
        $this->npcMaster = new NpcMaster();
    }

    public function testDatabaseFixtureUsesUtf8(): void
    {
        $this->assertSame('UTF8', dialecticRuntimeDatabaseEncoding());
        $this->assertTrue(dialecticRuntimeDatabaseEncodingIsSupported());
    }

    public function testNpcWithoutKnowledgeTagsClearsPreviousActorsKnowall(): void
    {
        $GLOBALS['WORLDKNOWLEDGE'] = 'knowall';

        $this->npcMaster->setOldGlobalsFromCurrentNpcData([
            'npc_name' => 'No Knowledge Tags Test',
        ], false);

        $this->assertSame('', $GLOBALS['WORLDKNOWLEDGE']);

        $this->npcMaster->setOldGlobalsFromCurrentNpcData([
            'npc_name' => 'Tagged Knowledge Test',
            'worldknowledge_tags' => 'community:goodsprings,domain:medicine',
        ], false);

        $this->assertSame('community:goodsprings,domain:medicine', $GLOBALS['WORLDKNOWLEDGE']);
    }

    public function testNpcMetadataRoundTripsUnicode(): void
    {
        $created = $this->npcMaster->create([
            'npc_name' => 'Sunny Smiles Encoding Test',
            'core' => 'Goodsprings scout',
            'metadata' => [
                'note' => "Sunny's gecko lesson - cafe",
            ],
            'extended_data' => [
                'note' => "Sunny\u{2019}s gecko lesson \u{2600}",
            ],
        ]);

        $this->assertTrue($created);
        $row = $this->npcMaster->getByName('Sunny Smiles Encoding Test');
        $this->assertNotEmpty($row);

        $extended = json_decode((string)$row['extended_data'], true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame("Sunny\u{2019}s gecko lesson \u{2600}", $extended['note']);
        $this->assertStringContainsString("Sunny\u{2019}s", (string)$row['extended_data']);
        $this->assertStringNotContainsString('\\u2019', (string)$row['extended_data']);
    }

    public function testDetectedVoiceDoesNotReplaceSavedProfileVoice(): void
    {
        $this->assertTrue($this->npcMaster->create([
            'npc_name' => 'Sunny Manual Voice Test',
            'voiceid' => 'custom_sunny_voice',
            'refid' => '0x00123456',
        ]));

        dialectic_ensure_npc($GLOBALS['db'], 'Sunny Manual Voice Test', '0x00123456', [
            'voice' => 'femaleadult04',
            'voice_formid' => '0x0000ABCD',
            'voice_name' => 'FemaleAdult04',
        ]);

        $row = $this->npcMaster->getByName('Sunny Manual Voice Test');
        $extended = json_decode((string)$row['extended_data'], true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame('custom_sunny_voice', $row['voiceid']);
        $this->assertSame('femaleadult04', $extended['voice_metadata']['voiceid']);
        $this->assertSame('0x0000ABCD', $extended['voice_metadata']['voice_formid']);
    }

    public function testDetectedVoicePopulatesNewNpcProfile(): void
    {
        dialectic_ensure_npc($GLOBALS['db'], 'New Detected Voice Test', '0x00654321', [
            'voice' => 'maleadult03',
        ]);

        $row = $this->npcMaster->getByName('New Detected Voice Test');

        $this->assertNotEmpty($row);
        $this->assertSame('maleadult03', $row['voiceid']);
    }

    public function testResolvedVoiceMetadataUpdatesAtomicallyWithoutReplacingManualVoice(): void
    {
        $this->assertTrue($this->npcMaster->create([
            'npc_name' => 'Beatrix Voice Persistence Test',
            'voiceid' => '',
            'extended_data' => [],
        ]));
        $row = $this->npcMaster->getByName('Beatrix Voice Persistence Test');
        $GLOBALS['db']->execQuery("UPDATE public.core_npc_master SET extended_data = '\"legacy malformed value\"'::jsonb WHERE id = " . (int)$row['id']);

        $metadata = [
            'voiceid' => 'femalegenericghoul',
            'voice_formid' => '0x00061EA8',
            'voice_name' => 'FemaleGenericGhoul',
            'source' => 'fnv_snapshot',
            'updated_at' => time(),
        ];
        $this->assertTrue($this->npcMaster->updateResolvedVoiceMetadata((int)$row['id'], 'femalegenericghoul', $metadata));

        $resolved = $this->npcMaster->getByName('Beatrix Voice Persistence Test');
        $extended = json_decode((string)$resolved['extended_data'], true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('femalegenericghoul', $resolved['voiceid']);
        $this->assertEquals($metadata, $extended['voice_metadata']);
        $this->assertSame('metadata_resolved', $extended['voice_refresh_last_result']);

        $this->assertNotFalse($this->npcMaster->update((int)$resolved['id'], ['voiceid' => 'manual_beatrix_voice']));
        $this->assertTrue($this->npcMaster->updateResolvedVoiceMetadata((int)$resolved['id'], 'femaleadult02', [
            'voiceid' => 'femaleadult02',
        ]));

        $preserved = $this->npcMaster->getByName('Beatrix Voice Persistence Test');
        $this->assertSame('manual_beatrix_voice', $preserved['voiceid']);
        $this->assertEquals($metadata, json_decode((string)$preserved['extended_data'], true, 512, JSON_THROW_ON_ERROR)['voice_metadata']);
    }

    public function testStaleRuntimeSnapshotDoesNotClearResolvedVoice(): void
    {
        $this->assertTrue($this->npcMaster->create([
            'npc_name' => 'Ralph Stale Voice Test',
            'voiceid' => '',
            'extended_data' => ['inventory' => ['9mm pistol']],
        ]));
        $staleRow = $this->npcMaster->getByName('Ralph Stale Voice Test');
        $this->assertTrue($this->npcMaster->updateVoiceRefreshRequest((int)$staleRow['id'], time(), 1));
        $requested = $this->npcMaster->getByName('Ralph Stale Voice Test');
        $requestedExtended = json_decode((string)$requested['extended_data'], true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame(1, $requestedExtended['voice_refresh_attempts']);
        $this->assertSame('awaiting_plugin_profile', $requestedExtended['voice_refresh_last_result']);

        $metadata = [
            'voiceid' => 'maleadult05',
            'voice_formid' => '0x000717E1',
            'voice_name' => 'MaleAdult05',
            'source' => 'fnv_snapshot',
            'updated_at' => time(),
        ];
        $this->assertTrue($this->npcMaster->updateResolvedVoiceMetadata((int)$staleRow['id'], 'maleadult05', $metadata));
        $this->assertTrue($this->npcMaster->updateVoiceRefreshRequest((int)$staleRow['id'], time(), 2));

        $staleExtended = json_decode((string)$staleRow['extended_data'], true, 512, JSON_THROW_ON_ERROR);
        $staleExtended['voice_refresh_requested_at'] = time();
        $staleExtended['voice_refresh_attempts'] = 1;
        $staleExtended['voice_refresh_last_result'] = 'awaiting_plugin_profile';
        $staleRow['extended_data'] = $staleExtended;
        $this->assertNotFalse($this->npcMaster->updateByArray($staleRow));

        $preserved = $this->npcMaster->getByName('Ralph Stale Voice Test');
        $extended = json_decode((string)$preserved['extended_data'], true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('maleadult05', $preserved['voiceid']);
        $this->assertEquals($metadata, $extended['voice_metadata']);
        $this->assertSame('metadata_resolved', $extended['voice_refresh_last_result']);
        $this->assertArrayNotHasKey('voice_refresh_requested_at', $extended);
        $this->assertSame(['9mm pistol'], $extended['inventory']);

        $this->assertNotFalse($this->npcMaster->update((int)$preserved['id'], ['voiceid' => '']));
        $cleared = $this->npcMaster->getByName('Ralph Stale Voice Test');
        $this->assertSame('', trim((string)($cleared['voiceid'] ?? '')));
    }

    public function testUnrelatedStaleUpdatePreservesIndividualMemorySetting(): void
    {
        $this->assertTrue($this->npcMaster->create([
            'npc_name' => 'Sunny Individual Memory Test',
            'extended_data' => ['inventory' => ['Varmint rifle']],
        ]));
        $staleRow = $this->npcMaster->getByName('Sunny Individual Memory Test');

        $this->assertNotFalse($this->npcMaster->update((int)$staleRow['id'], [
            'individual_memory_enabled' => 1,
        ]));

        $staleRow['metadata'] = ['actor_profile_updated' => time()];
        $this->assertNotFalse($this->npcMaster->updateByArray($staleRow));

        $row = $this->npcMaster->getByName('Sunny Individual Memory Test');
        $extended = json_decode((string)$row['extended_data'], true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame(1, $extended['individual_memory_enabled']);
        $this->assertSame(['Varmint rifle'], $extended['inventory']);
    }

    public function testExplicitIndividualMemoryDisableRemovesSetting(): void
    {
        $this->assertTrue($this->npcMaster->create([
            'npc_name' => 'Sunny Disable Individual Memory Test',
            'individual_memory_enabled' => 1,
            'extended_data' => ['inventory' => ['Gecko meat']],
        ]));
        $row = $this->npcMaster->getByName('Sunny Disable Individual Memory Test');

        $this->assertNotFalse($this->npcMaster->update((int)$row['id'], [
            'individual_memory_enabled' => 0,
            'extended_data' => $row['extended_data'],
        ]));

        $updated = $this->npcMaster->getByName('Sunny Disable Individual Memory Test');
        $extended = json_decode((string)$updated['extended_data'], true, 512, JSON_THROW_ON_ERROR);
        $this->assertArrayNotHasKey('individual_memory_enabled', $extended);
    }

    public function testHistoryRestorePreservesCurrentIndividualMemorySetting(): void
    {
        $this->assertTrue($this->npcMaster->create([
            'npc_name' => 'Enable Memory After Snapshot Test',
            'extended_data' => ['inventory' => ['Varmint rifle']],
        ]));
        $this->assertTrue($this->npcMaster->create([
            'npc_name' => 'Disable Memory After Snapshot Test',
            'individual_memory_enabled' => 1,
            'extended_data' => ['inventory' => ['Gecko meat']],
        ]));

        $this->assertTrue($this->npcMaster->backupAllNpcs(100));

        $enabledRow = $this->npcMaster->getByName('Enable Memory After Snapshot Test');
        $disabledRow = $this->npcMaster->getByName('Disable Memory After Snapshot Test');
        $this->assertNotFalse($this->npcMaster->update((int)$enabledRow['id'], [
            'individual_memory_enabled' => 1,
            'extended_data' => [
                'inventory' => ['Current inventory should roll back'],
                'middle_term_enabled' => 1,
                'auto_diary_enabled' => 1,
                'auto_diary_wait_enabled' => 1,
                'relationships_locked' => true,
                'relationships' => [
                    'Player' => ['aff' => 42, 'type' => 'friend'],
                ],
            ],
        ]));
        $this->assertNotFalse($this->npcMaster->update((int)$disabledRow['id'], [
            'individual_memory_enabled' => 0,
        ]));

        $this->assertTrue($this->npcMaster->restoreNPC(100));

        $restoredEnabled = $this->npcMaster->getByName('Enable Memory After Snapshot Test');
        $enabledExtended = json_decode((string)$restoredEnabled['extended_data'], true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame(1, $enabledExtended['individual_memory_enabled']);
        $this->assertSame(1, $enabledExtended['middle_term_enabled']);
        $this->assertSame(1, $enabledExtended['auto_diary_enabled']);
        $this->assertSame(1, $enabledExtended['auto_diary_wait_enabled']);
        $this->assertTrue($enabledExtended['relationships_locked']);
        $this->assertSame(42, $enabledExtended['relationships']['Player']['aff']);
        $this->assertSame(['Varmint rifle'], $enabledExtended['inventory']);

        $restoredDisabled = $this->npcMaster->getByName('Disable Memory After Snapshot Test');
        $disabledExtended = json_decode((string)$restoredDisabled['extended_data'], true, 512, JSON_THROW_ON_ERROR);
        $this->assertArrayNotHasKey('individual_memory_enabled', $disabledExtended);
        $this->assertSame(['Gecko meat'], $disabledExtended['inventory']);
    }

    public function testRelationshipTimelineSnapshotsChangedStateAndRestoresByGameTime(): void
    {
        $this->assertTrue($this->npcMaster->create([
            'npc_name' => 'Veronica Relationship Timeline Test',
            'extended_data' => [
                'relationships' => ['Player' => ['aff' => 10, 'type' => 'platonic']],
            ],
        ]));
        $this->assertTrue($this->npcMaster->backupAllNpcs(100));

        $row = $this->npcMaster->getByName('Veronica Relationship Timeline Test');
        $this->assertNotFalse($this->npcMaster->update((int)$row['id'], [
            'extended_data' => [
                'relationships' => ['Player' => ['aff' => 35, 'type' => 'platonic']],
                'relationships_last_eval' => 'volatile timestamp',
            ],
        ]));

        $hadGameRequest = array_key_exists('gameRequest', $GLOBALS);
        $savedGameRequest = $GLOBALS['gameRequest'] ?? null;
        try {
            $GLOBALS['gameRequest'] = ['rechat', '', 110];
            $this->assertTrue(dialecticRelationshipTimelineStamp((int)$row['id'], 'relationship_evaluation'));

            $GLOBALS['gameRequest'] = ['rechat', '', 111];
            $this->assertTrue(dialecticRelationshipTimelineStamp((int)$row['id'], 'relationship_evaluation'));
        } finally {
            if ($hadGameRequest) {
                $GLOBALS['gameRequest'] = $savedGameRequest;
            } else {
                unset($GLOBALS['gameRequest']);
            }
        }

        $snapshotCount = $GLOBALS['db']->fetchOne(
            "SELECT COUNT(*)::int AS count
             FROM core_npc_master_history
             WHERE npc_id = " . (int)$row['id'] . "
               AND extended_data->>'_dialectic_history_source' = 'relationship_evaluation'"
        );
        $this->assertSame(1, (int)$snapshotCount['count']);

        $timelineRows = dialecticFetchRelationshipTimelineChanges($GLOBALS['db'], [
            'npc_id' => (int)$row['id'],
            'limit' => 10,
        ]);
        $this->assertCount(1, $timelineRows);
        $this->assertSame('relationship', $timelineRows[0]['type']);
        $this->assertSame('affinity', $timelineRows[0]['changes'][0]['kind']);
        $this->assertSame(10, $timelineRows[0]['changes'][0]['before_aff']);
        $this->assertSame(35, $timelineRows[0]['changes'][0]['after_aff']);
        $this->assertSame(0, $timelineRows[0]['rowid']);

        $this->assertTrue($this->npcMaster->restoreNPC(100));
        $atSave = $this->npcMaster->getByName('Veronica Relationship Timeline Test');
        $atSaveExtended = json_decode((string)$atSave['extended_data'], true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame(10, $atSaveExtended['relationships']['Player']['aff']);

        $this->assertTrue($this->npcMaster->restoreNPC(110));
        $afterChange = $this->npcMaster->getByName('Veronica Relationship Timeline Test');
        $afterChangeExtended = json_decode((string)$afterChange['extended_data'], true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame(35, $afterChangeExtended['relationships']['Player']['aff']);
        $this->assertArrayNotHasKey('_dialectic_history_source', $afterChangeExtended);
    }

    public function testRelationshipRestoreIndexExists(): void
    {
        $index = $GLOBALS['db']->fetchOne(
            "SELECT indexname FROM pg_indexes
             WHERE schemaname = 'public'
               AND indexname = 'idx_core_npc_master_history_restore'"
        );

        $this->assertSame('idx_core_npc_master_history_restore', $index['indexname'] ?? null);
    }

    public function testInvalidJsonDoesNotOverwriteExistingExtendedData(): void
    {
        $this->assertTrue($this->npcMaster->create([
            'npc_name' => 'Sunny Invalid JSON Test',
            'extended_data' => ['inventory' => ['Gecko meat']],
        ]));
        $before = $this->npcMaster->getByName('Sunny Invalid JSON Test');

        try {
            $this->npcMaster->update((int)$before['id'], ['extended_data' => '{"inventory":']);
            $this->fail('Malformed extended_data should have been rejected.');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('extended_data is invalid JSON', $e->getMessage());
        }

        $after = $this->npcMaster->getByName('Sunny Invalid JSON Test');
        $this->assertSame($before['extended_data'], $after['extended_data']);
    }

    public function testLegacyEmptyJsonListNormalizesToObject(): void
    {
        $this->assertSame(
            '{}',
            $this->npcMaster->encodeJsonObjectForPersistence('[]', 'extended_data')
        );
    }

    public function testMalformedStoredJsonScalarIsRepairedToObject(): void
    {
        $this->assertTrue($this->npcMaster->create([
            'npc_name' => 'Sunny Stored Scalar Test',
            'extended_data' => ['inventory' => ['Varmint rifle']],
        ]));
        $row = $this->npcMaster->getByName('Sunny Stored Scalar Test');
        $this->assertNotEmpty($row);

        $this->assertNotFalse($GLOBALS['db']->execQuery(
            "UPDATE core_npc_master SET extended_data='\"legacy scalar\"'::jsonb WHERE id=" . intval($row['id'])
        ));
        $corruptRow = $this->npcMaster->getById((int)$row['id']);

        $this->assertSame([], $this->npcMaster->getExtendedData($corruptRow));
        $repairedRow = $this->npcMaster->getById((int)$row['id']);
        $this->assertSame([], json_decode((string)$repairedRow['extended_data'], true, 512, JSON_THROW_ON_ERROR));
        $this->assertSame('object', $GLOBALS['db']->fetchOne(
            "SELECT jsonb_typeof(extended_data) AS value_type FROM core_npc_master WHERE id=" . intval($row['id'])
        )['value_type']);
    }

    public function testInvalidUtf8DoesNotOverwriteExistingExtendedData(): void
    {
        $this->assertTrue($this->npcMaster->create([
            'npc_name' => 'Sunny Invalid UTF8 Test',
            'extended_data' => ['inventory' => ['Varmint rifle']],
        ]));
        $before = $this->npcMaster->getByName('Sunny Invalid UTF8 Test');

        try {
            $this->npcMaster->update((int)$before['id'], [
                'extended_data' => ['note' => "bad\x92byte"],
            ]);
            $this->fail('Malformed UTF-8 should have been rejected.');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('contains invalid UTF-8', $e->getMessage());
        }

        $after = $this->npcMaster->getByName('Sunny Invalid UTF8 Test');
        $this->assertSame($before['extended_data'], $after['extended_data']);
    }

    public function testDatabaseUpdateFailureIsReturnedToCaller(): void
    {
        $result = $GLOBALS['db']->updateRow(
            'core_npc_master',
            ['column_that_does_not_exist' => 'value'],
            'id = 1'
        );

        $this->assertFalse($result);
    }
}
