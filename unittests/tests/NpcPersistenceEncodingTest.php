<?php declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'DatabaseTestCase.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . 'lib' . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'npc_master.class.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . 'lib' . DIRECTORY_SEPARATOR . 'runtime_bootstrap.php';

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
