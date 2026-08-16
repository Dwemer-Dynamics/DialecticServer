<?php declare(strict_types=1);

require_once 'DatabaseTestCase.php';
require_once 'CallableMock.php';
require_once dirname(__DIR__, 2).DIRECTORY_SEPARATOR.'lib'.DIRECTORY_SEPARATOR.'worldknowledge_topic.php';
require_once dirname(__DIR__, 2).DIRECTORY_SEPARATOR.'lib'.DIRECTORY_SEPARATOR.'worldknowledge_catalog.php';

// setUp and tearDown for the test database are in DatabaseTestCase.php
final class WorldKnowledgeTest extends DatabaseTestCase
{
    public function testShippedFalloutWorldKnowledgeDatasetContract(): void
    {
        $root = dirname(__DIR__, 2);
        $sourcesPath = $root.DIRECTORY_SEPARATOR.'data'.DIRECTORY_SEPARATOR.'fallout_worldknowledge_sources.jsonl';
        $curationPath = $root.DIRECTORY_SEPARATOR.'data'.DIRECTORY_SEPARATOR.'fallout_worldknowledge_editorial_curation.json';
        $catalog = dialecticWorldKnowledgeLoadFactoryCatalog($root);
        $topics = [];
        $aliasCount = 0;
        $categoryCounts = [];
        $nameOwners = [];
        foreach ($catalog['rows'] as $data) {
            $topic = strval($data['topic'] ?? '');
            $this->assertMatchesRegularExpression('/^[a-z0-9_]+$/', $topic);
            $this->assertArrayNotHasKey($topic, $topics);
            $topics[$topic] = true;
            $topicKey = dialecticWorldKnowledgeComparableTopic($topic);
            $this->assertArrayNotHasKey($topicKey, $nameOwners);
            $nameOwners[$topicKey] = $topic;
            $aliases = dialecticWorldKnowledgeSplitAliases($data['aliases'] ?? '');
            $aliasCount += count($aliases);
            foreach ($aliases as $alias) {
                $aliasKey = dialecticWorldKnowledgeComparableTopic($alias);
                $this->assertArrayNotHasKey($aliasKey, $nameOwners);
                $nameOwners[$aliasKey] = $topic;
            }
            $this->assertNotEmpty($data['topic_desc']);
            $this->assertStringNotContainsString('&', strval($data['knowledge_class'] ?? ''));
            $this->assertStringNotContainsString('|', strval($data['knowledge_class'] ?? ''));
            $this->assertStringNotContainsString('&', strval($data['knowledge_class_basic'] ?? ''));
            $this->assertStringNotContainsString('|', strval($data['knowledge_class_basic'] ?? ''));
            $tierConflicts = dialecticWorldKnowledgeAccessTierConflicts(
                $data['knowledge_class'] ?? '',
                $data['knowledge_class_basic'] ?? ''
            );
            $this->assertSame([], $tierConflicts['duplicates'], $topic);
            $this->assertSame([], $tierConflicts['contradictions'], $topic);
            $this->assertGreaterThanOrEqual(4, count(array_filter(array_map('trim', explode(',', $data['tags'])))));
            $category = strval($data['category']);
            $categoryCounts[$category] = intval($categoryCounts[$category] ?? 0) + 1;
        }

        $this->assertSame(1169, count($topics));
        $this->assertGreaterThanOrEqual(100, $aliasCount);
        $this->assertGreaterThanOrEqual(10, count(array_filter($categoryCounts)));
        $this->assertSame('approved', $catalog['manifest']['editorial_review']['status'] ?? null);
        $this->assertContains('Fallout 3', $catalog['manifest']['coverage']['games'] ?? []);
        $this->assertContains('Fallout: New Vegas', $catalog['manifest']['coverage']['games'] ?? []);

        $curation = json_decode(file_get_contents($curationPath), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame(
            hash_file('sha256', $curationPath),
            $catalog['manifest']['generation']['editorial_curation_checksum_sha256'] ?? null
        );
        foreach ($curation['exclusions'] ?? [] as $excludedTopics) {
            foreach ($excludedTopics as $excludedTopic) {
                $this->assertArrayNotHasKey($excludedTopic, $topics);
            }
        }
        foreach ($curation['merges'] ?? [] as $targetTopic => $merge) {
            $this->assertArrayHasKey($targetTopic, $topics);
            foreach ($merge['sources'] ?? [] as $sourceTopic) {
                if ($sourceTopic !== $targetTopic) {
                    $this->assertArrayNotHasKey($sourceTopic, $topics);
                }
            }
        }

        $sourceTopics = [];
        foreach (file($sourcesPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            $source = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
            $sourceTopics[dialecticWorldKnowledgeCanonicalTopic($source['topic'] ?? '')] = true;
            $this->assertNotEmpty($source['source_url'] ?? '');
            $this->assertNotEmpty($source['revision_id'] ?? '');
        }
        $this->assertSame(array_keys($topics), array_keys($sourceTopics));
    }

    public function testKnowledgeClassesCannotConflictAcrossTiers(): void
    {
        $conflicts = dialecticWorldKnowledgeAccessTierConflicts('historian,!raider', 'historian,raider');
        $this->assertSame(['historian'], $conflicts['duplicates']);
        $this->assertSame(['raider'], $conflicts['contradictions']);

        $this->expectException(RuntimeException::class);
        dialecticWorldKnowledgeValidateFactoryRow([
            'topic' => 'tier_conflict',
            'aliases' => '',
            'topic_desc' => str_repeat('Advanced reviewed knowledge. ', 10),
            'knowledge_class' => 'historian',
            'topic_desc_basic' => str_repeat('Basic public knowledge. ', 10),
            'knowledge_class_basic' => 'historian',
            'tags' => 'fallout history,public record,regional history,wasteland account',
            'category' => 'history',
        ]);
    }

    public function testWorldKnowledgeSchemaSupportsCustomOverridesWithoutChangingFactoryRows(): void
    {
        $testDb = new sql();
        $uniqueIndex = $testDb->fetchOne("
            SELECT COUNT(*) AS total
              FROM pg_indexes
             WHERE schemaname = 'public'
               AND tablename = 'worldknowledge'
               AND indexname = 'worldknowledge_custom_topic_unique_idx'
        ");
        $legacyUniqueIndexes = $testDb->fetchOne("
            SELECT COUNT(*) AS total
              FROM pg_indexes
             WHERE schemaname = 'public'
               AND indexname IN ('worldknowledge_topic_unique_idx', 'worldknowledge_canonical_topic_unique_idx')
        ");
        $factoryBefore = $testDb->fetchOne("
            SELECT COUNT(*) AS total
              FROM worldknowledge
             WHERE source_kind = 'factory'
        ");
        $firstImport = $testDb->execQuery("
            INSERT INTO worldknowledge (topic, aliases, canonical_topic, topic_desc_basic, category, source_kind, is_active)
            VALUES ('megaton', 'The Town of Megaton', 'megaton', 'Megaton is a fortified Capital Wasteland settlement.', 'location', 'custom', TRUE)
            ON CONFLICT (canonical_topic) WHERE source_kind='custom' AND is_active DO UPDATE
                SET topic = EXCLUDED.topic,
                    topic_desc_basic = EXCLUDED.topic_desc_basic,
                    category = EXCLUDED.category
        ");
        $secondImport = $testDb->execQuery("
            INSERT INTO worldknowledge (topic, aliases, canonical_topic, topic_desc_basic, category, source_kind, is_active)
            VALUES ('megaton', 'Atom Town', 'megaton', 'Megaton is built around an undetonated atomic bomb.', 'location', 'custom', TRUE)
            ON CONFLICT (canonical_topic) WHERE source_kind='custom' AND is_active DO UPDATE
                SET topic_desc_basic = EXCLUDED.topic_desc_basic,
                    topic = EXCLUDED.topic,
                    aliases = EXCLUDED.aliases,
                    category = EXCLUDED.category
        ");
        $imported = $testDb->fetchOne("
            SELECT COUNT(*) AS total, MAX(topic) AS topic, MAX(aliases) AS aliases,
                   MAX(topic_desc_basic) AS topic_desc_basic,
                   MAX(source_kind) AS source_kind
              FROM worldknowledge_effective
             WHERE canonical_topic = 'megaton'
        ");
        $factoryAfter = $testDb->fetchOne("
            SELECT COUNT(*) AS total
              FROM worldknowledge
             WHERE source_kind = 'factory'
        ");
        $testDb->close();

        $this->assertSame(1, intval($uniqueIndex['total'] ?? 0));
        $this->assertSame(0, intval($legacyUniqueIndexes['total'] ?? -1));
        $this->assertNotFalse($firstImport);
        $this->assertNotFalse($secondImport);
        $this->assertSame(1, intval($imported['total'] ?? 0));
        $this->assertSame('megaton', $imported['topic'] ?? null);
        $this->assertSame('Atom Town', $imported['aliases'] ?? null);
        $this->assertSame(
            'Megaton is built around an undetonated atomic bomb.',
            $imported['topic_desc_basic'] ?? null
        );
        $this->assertSame('custom', $imported['source_kind'] ?? null);
        $this->assertSame(intval($factoryBefore['total'] ?? 0), intval($factoryAfter['total'] ?? -1));
    }

    public function testFactoryCatalogReprovisionIsIdempotentAndPreservesCustomArticles(): void
    {
        $root = dirname(__DIR__, 2);
        $testDb = new sql();
        $testDb->execQuery("
            INSERT INTO worldknowledge
                (topic, aliases, canonical_topic, topic_desc_basic, category, source_kind, is_active)
            VALUES
                ('codex_custom_lore', 'Codex Custom Lore', 'codex_custom_lore',
                 'A deliberately user-authored article that must survive factory reprovisioning.',
                 'history', 'custom', TRUE)
        ");
        $before = $testDb->fetchAll("
            SELECT canonical_topic, content_hash
              FROM worldknowledge
             WHERE source_kind = 'factory'
             ORDER BY canonical_topic
        ");
        $testDb->execQuery("
            UPDATE bio_templates
               SET worldknowledge_tags = CASE npc_name
                   WHEN 'doc_mitchell' THEN 'domain:user_authored'
                   WHEN 'sunny_smiles' THEN 'community:goodsprings,domain:survival,domain:wildlife,person:sunny_smiles,place:goodsprings,race:human,region:mojave,role:hunter,role:soldier,role:survivalist'
                   ELSE worldknowledge_tags
               END
             WHERE npc_name IN ('doc_mitchell', 'sunny_smiles')
        ");

        $first = dialecticWorldKnowledgeInstallFactoryCatalog($testDb, $root);
        $second = dialecticWorldKnowledgeInstallFactoryCatalog($testDb, $root);
        $after = $testDb->fetchAll("
            SELECT canonical_topic, content_hash
              FROM worldknowledge
             WHERE source_kind = 'factory'
             ORDER BY canonical_topic
        ");
        $catalogCount = $testDb->fetchOne('SELECT COUNT(*) AS total FROM worldknowledge_catalogs');
        $custom = $testDb->fetchOne("
            SELECT topic_desc_basic
              FROM worldknowledge_effective
             WHERE canonical_topic = 'codex_custom_lore'
        ");
        $templateTags = $testDb->fetchAll("
            SELECT npc_name, worldknowledge_tags
              FROM bio_templates
             WHERE npc_name IN ('doc_mitchell', 'sunny_smiles')
             ORDER BY npc_name
        ");
        $testDb->close();

        $this->assertSame($before, $after);
        $this->assertSame($first['checksum_sha256'], $second['checksum_sha256']);
        $this->assertSame(1, intval($catalogCount['total'] ?? 0));
        $this->assertSame(
            'A deliberately user-authored article that must survive factory reprovisioning.',
            $custom['topic_desc_basic'] ?? null
        );
        $this->assertSame('domain:user_authored', $templateTags[0]['worldknowledge_tags'] ?? null);
        $this->assertStringContainsString('goodsprings', $templateTags[1]['worldknowledge_tags'] ?? '');
        $this->assertStringContainsString('survivalist', $templateTags[1]['worldknowledge_tags'] ?? '');
        $this->assertStringNotContainsString(':', $templateTags[1]['worldknowledge_tags'] ?? '');
    }

    public function testSyncRemovesObsoleteCatalogMetadata(): void
    {
        $root = dirname(__DIR__, 2);
        $catalog = dialecticWorldKnowledgeLoadFactoryCatalog($root);
        $catalogId = strval($catalog['manifest']['catalog_id']);
        $catalogVersion = strval($catalog['manifest']['catalog_version']);
        $testDb = new sql();
        $testDb->execQuery("
            INSERT INTO worldknowledge_catalogs
                (catalog_id, catalog_version, display_name, checksum_sha256, row_count, manifest, is_active)
            VALUES
                ('{$catalogId}', 'incomplete-fixture', 'Incomplete fixture', '" . str_repeat('0', 64) . "', 1, '{}'::jsonb, FALSE)
        ");

        dialecticWorldKnowledgeInstallFactoryCatalog($testDb, $root);
        $stillActive = $testDb->fetchOne("
            SELECT catalog_version FROM worldknowledge_catalogs WHERE is_active LIMIT 1
        ");
        $effectiveFactory = $testDb->fetchOne("
            SELECT COUNT(*) AS total FROM worldknowledge_effective WHERE source_kind = 'factory'
        ");
        $installedVersions = $testDb->fetchOne("
            SELECT COUNT(*) AS total FROM worldknowledge_catalogs WHERE catalog_id = '{$catalogId}'
        ");
        $testDb->close();

        $this->assertSame($catalogVersion, $stillActive['catalog_version'] ?? null);
        $this->assertSame(1169, intval($effectiveFactory['total'] ?? -1));
        $this->assertSame(1, intval($installedVersions['total'] ?? 0));
    }

    public function testLegacySeedCleanupRemovesOnlyUneditedSeedRows(): void
    {
        $root = dirname(__DIR__, 2);
        $handle = fopen($root.DIRECTORY_SEPARATOR.'data'.DIRECTORY_SEPARATOR.'fallout_worldknowledge_basic.csv', 'rb');
        $this->assertNotFalse($handle);
        $header = fgetcsv($handle, 0, ',', '"', '\\');
        $values = fgetcsv($handle, 0, ',', '"', '\\');
        fclose($handle);
        $this->assertIsArray($header);
        $this->assertIsArray($values);
        $seed = array_combine($header, $values);
        $this->assertIsArray($seed);
        $canonical = dialecticWorldKnowledgeCanonicalTopic(strval($seed['topic']));

        $testDb = new sql();
        dialecticWorldKnowledgeRemoveLegacyFactorySeed($testDb, $root);
        $testDb->execQuery("DELETE FROM worldknowledge WHERE source_kind='custom' AND canonical_topic=" . $testDb->escapeLiteral($canonical));
        $columns = ['topic', 'aliases', 'canonical_topic', 'topic_desc', 'knowledge_class', 'topic_desc_basic', 'knowledge_class_basic', 'tags', 'category'];
        $valuesSql = [
            $testDb->escapeLiteral(trim(strval($seed['topic']))),
            $testDb->escapeLiteral(trim(strval($seed['aliases']))),
            $testDb->escapeLiteral($canonical),
            $testDb->escapeLiteral(trim(strval($seed['topic_desc']))),
            $testDb->escapeLiteral(trim(strval($seed['knowledge_class']))),
            $testDb->escapeLiteral(trim(strval($seed['topic_desc_basic']))),
            $testDb->escapeLiteral(trim(strval($seed['knowledge_class_basic']))),
            $testDb->escapeLiteral(trim(strval($seed['tags']))),
            $testDb->escapeLiteral(trim(strval($seed['category']))),
        ];
        $testDb->execQuery('INSERT INTO worldknowledge (' . implode(',', $columns) . ') VALUES (' . implode(',', $valuesSql) . ')');
        $this->assertSame(1, dialecticWorldKnowledgeRemoveLegacyFactorySeed($testDb, $root));

        $valuesSql[5] = $testDb->escapeLiteral(trim(strval($seed['topic_desc_basic'])) . ' User edit.');
        $testDb->execQuery('INSERT INTO worldknowledge (' . implode(',', $columns) . ') VALUES (' . implode(',', $valuesSql) . ')');
        $this->assertSame(0, dialecticWorldKnowledgeRemoveLegacyFactorySeed($testDb, $root));
        $preserved = $testDb->fetchOne(
            'SELECT topic_desc_basic FROM worldknowledge WHERE source_kind=\'custom\' AND canonical_topic='
            . $testDb->escapeLiteral($canonical)
        );
        $testDb->close();

        $this->assertStringEndsWith('User edit.', strval($preserved['topic_desc_basic'] ?? ''));
    }

    public function testWorldKnowledge_WhenNoKeywordMatch_ContextShouldNotContainLore(): void
    {
        // default test config
        require("conf.php");

        $this->insertStimpackLore();
        
        $GLOBALS["mockMinimeTopic"] = function($text) {
            return '{"input_text": "'.$text.'", "generated_tags": "", "elapsed_time": "0.05 seconds"}';
        };
        
        // input topic = 0
        // worldknowledge topic = 0
        // location = 0
        // context = 0

        $GLOBALS["mockConnectorSend"]=$this->createMock(CallableMock::class);
        $GLOBALS["mockConnectorSend"]->expects($this->once())
        ->method('__invoke')
        ->with(
            $this->equalTo('https://openrouter.ai/api/v1/chat/completions'),
            $this->callback(function ($streamContext) {
                $options = stream_context_get_options($streamContext);
                $this->assertStringNotContainsString('<knowledge>', $options['http']['content']);
                return true;
            })
        )
        ->willReturnCallback(function($url, $context) {
            return $this->defaultConnectorResponse($url, $context);
        });
        $this->setJsonRequest('inputtext', 100, 200, 'What\'s going on around here?');
        require(__DIR__.DIRECTORY_SEPARATOR."..".DIRECTORY_SEPARATOR."..".DIRECTORY_SEPARATOR."main.php");
    }

    public function testWorldKnowledge_WhenInputTopicKeywordsFound_ContextShouldContainLore(): void
    {
        // default test config
        require("conf.php");

        $this->insertStimpackLore();
        
        // input topic = 6.9
        // worldknowledge topic = 0
        // location = 0
        // context = 0

        $GLOBALS["mockConnectorSend"]=$this->createMock(CallableMock::class);
        $GLOBALS["mockConnectorSend"]->expects($this->once())
        ->method('__invoke')
        ->with(
            $this->equalTo('https://openrouter.ai/api/v1/chat/completions'),
            $this->callback(function ($streamContext) {
                $options = stream_context_get_options($streamContext);
                $this->assertStringContainsString('topic=\\"stimpack_seller\\" level=\\"advanced\\"', $options['http']['content']);
                $this->assertStringContainsString("The stimpack vendor is a wasteland medic who buys and sells stimpaks", $options['http']['content']);
                return true;
            })
        )
        ->willReturnCallback(function($url, $context) {
            return $this->defaultConnectorResponse($url, $context);
        });
        $this->setJsonRequest('inputtext', 100, 200, 'Tell me about the stimpack vendor.');
        require(__DIR__.DIRECTORY_SEPARATOR."..".DIRECTORY_SEPARATOR."..".DIRECTORY_SEPARATOR."main.php");
    }

    public function testWorldKnowledge_WhenOnlyBasicDescriptionExists_ContextShouldContainBasicLore(): void
    {
        require("conf.php");

        $testDb = new sql();
        $testDb->execQuery("DELETE FROM worldknowledge WHERE canonical_topic = 'new_california_republic' AND source_kind = 'custom'");
        $testDb->insert(
            'worldknowledge',
            array(
                'topic' => 'new_california_republic',
                'aliases' => 'NCR',
                'canonical_topic' => 'new_california_republic',
                'source_kind' => 'custom',
                'topic_desc_basic' => 'The New California Republic, commonly called the NCR, is a large republic expanding east from California.'
            )
        );
        $testDb->execQuery("
            UPDATE worldknowledge
               SET native_vector =
                     setweight(to_tsvector(coalesce(topic, '')), 'A')
                  || setweight(to_tsvector(coalesce(aliases, '')), 'A')
                  || setweight(to_tsvector(coalesce(topic_desc, '')), 'B')
                  || setweight(to_tsvector(coalesce(topic_desc_basic, '')), 'C')
             WHERE topic = 'new_california_republic'
        ");
        $testDb->close();

        $GLOBALS["mockMinimeTopic"] = static function ($text) {
            return json_encode([
                'input_text' => $text,
                'generated_tags' => 'NCR',
                'elapsed_time' => '0.05 seconds',
            ]);
        };

        $GLOBALS["mockConnectorSend"] = $this->createMock(CallableMock::class);
        $GLOBALS["mockConnectorSend"]->expects($this->once())
            ->method('__invoke')
            ->with(
                $this->equalTo('https://openrouter.ai/api/v1/chat/completions'),
                $this->callback(function ($streamContext) {
                    $options = stream_context_get_options($streamContext);
                    $this->assertStringContainsString('topic=\\"new_california_republic\\" level=\\"basic\\"', $options['http']['content']);
                    $this->assertStringContainsString('The New California Republic, commonly called the NCR', $options['http']['content']);
                    $this->assertStringNotContainsString('new_california_republic,NCR', $options['http']['content']);
                    $this->assertStringNotContainsString('topic=\\"new_california_republic\\" level=\\"advanced\\"', $options['http']['content']);
                    return true;
                })
            )
            ->willReturnCallback(function ($url, $context) {
                return $this->defaultConnectorResponse($url, $context);
            });

        $this->setJsonRequest(
            'inputtext',
            100,
            200,
            'Tell me about NCR.',
            ['skip_player_tts' => true]
        );
        require(__DIR__.DIRECTORY_SEPARATOR."..".DIRECTORY_SEPARATOR."..".DIRECTORY_SEPARATOR."main.php");
    }

    public function testWorldKnowledge_WhenWorldKnowledgeTopicKeywordsFound_ContextShouldContainLore(): void
    {
        // default test config
        require("conf.php");

        $this->insertStimpackLore();

        $testDb = new sql();
        $testDb->insert(
            'conf_opts',
            array(
                'id' => 'current_worldknowledge_topic',
                'value' => 'Stimpack Vendor'
            )
        );
        $testDb->close();
        
        // input topic = 0
        // worldknowledge topic = 3.4
        // location = 0
        // context = 0

        $GLOBALS["mockConnectorSend"]=$this->createMock(CallableMock::class);
        $GLOBALS["mockConnectorSend"]->expects($this->once())
        ->method('__invoke')
        ->with(
            $this->equalTo('https://openrouter.ai/api/v1/chat/completions'),
            $this->callback(function ($streamContext) {
                $options = stream_context_get_options($streamContext);
                $this->assertStringNotContainsString('topic=\\"stimpack_seller\\"', $options['http']['content']);
                return true;
            })
        )
        ->willReturnCallback(function($url, $context) {
            return $this->defaultConnectorResponse($url, $context);
        });
        $this->setJsonRequest('inputtext', 100, 200, 'I carried the Platinum Chip. Surely I must know something.');
        require(__DIR__.DIRECTORY_SEPARATOR."..".DIRECTORY_SEPARATOR."..".DIRECTORY_SEPARATOR."main.php");
    }

    public function testWorldKnowledge_WhenInsufficientWorldKnowledgeTopicKeywordsFound_ContextShouldNotContainLore(): void
    {
        // default test config
        require("conf.php");

        $this->insertStimpackLore();

        $testDb = new sql();
        $testDb->insert(
            'conf_opts',
            array(
                'id' => 'current_worldknowledge_topic',
                'value' => 'Alchemist'
            )
        );
        $testDb->close();
        
        // input topic = 0
        // worldknowledge topic = 1.2
        // location = 0
        // context = 0

        $GLOBALS["mockConnectorSend"]=$this->createMock(CallableMock::class);
        $GLOBALS["mockConnectorSend"]->expects($this->once())
        ->method('__invoke')
        ->with(
            $this->equalTo('https://openrouter.ai/api/v1/chat/completions'),
            $this->callback(function ($streamContext) {
                $options = stream_context_get_options($streamContext);
                $this->assertStringNotContainsString('topic=\\"stimpack_seller\\"', $options['http']['content']);
                $this->assertStringNotContainsString('The stimpack vendor is a wasteland medic', $options['http']['content']);
                return true;
            })
        )
        ->willReturnCallback(function($url, $context) {
            return $this->defaultConnectorResponse($url, $context);
        });
        $this->setJsonRequest('inputtext', 100, 200, 'I carried the Platinum Chip. Surely I must know something.');
        require(__DIR__.DIRECTORY_SEPARATOR."..".DIRECTORY_SEPARATOR."..".DIRECTORY_SEPARATOR."main.php");
    }

    public function testWorldKnowledge_WhenWorldKnowledgeTopicPlusLocationKeywordsFound_ContextShouldContainLore(): void
    {
        // default test config
        require("conf.php");

        $this->insertStimpackLore();
        $testDb = new sql();
        $testDb->insert(
            'conf_opts',
            array(
                'id' => 'current_worldknowledge_topic',
                'value' => 'Stimpack'
            )
        );
        $testDb->insert(
            'eventlog',
            array(
                'ts' => "0",
                'gamets' => "0",
                'type' => "world_context",
                'data' => "world context: Lair of the Stimpack Vendor",
                'sess' => 'pending',
                'localts' => 0,
                'location'=> "Lair of the Stimpack Vendor",
                'party'=> '{"type":"world_context","location":"Lair of the Stimpack Vendor","worldspace":"Seller Of Stimpaks","game_time":{"year":2281,"month":11,"day":30,"hour":14.3333}}'
            )
        );
        $testDb->close();
        
        // input topic = 0
        // worldknowledge topic = 3.6 (stimpack_of_pickpocketing wins without the eventlog entry)
        // location = 1.3
        // context = 0

        $GLOBALS["mockConnectorSend"]=$this->createMock(CallableMock::class);
        $GLOBALS["mockConnectorSend"]->expects($this->once())
        ->method('__invoke')
        ->with(
            $this->equalTo('https://openrouter.ai/api/v1/chat/completions'),
            $this->callback(function ($streamContext) {
                $options = stream_context_get_options($streamContext);
                $this->assertStringContainsString('topic=\\"stimpack_seller\\" level=\\"advanced\\"', $options['http']['content']);
                $this->assertStringContainsString('The stimpack vendor is a wasteland medic', $options['http']['content']);
                return true;
            })
        )
        ->willReturnCallback(function($url, $context) {
            return $this->defaultConnectorResponse($url, $context);
        });
        $this->setJsonRequest('inputtext', 100, 200, 'What do you know about this place?');
        require(__DIR__.DIRECTORY_SEPARATOR."..".DIRECTORY_SEPARATOR."..".DIRECTORY_SEPARATOR."main.php");
    }

    public function testWorldKnowledge_WhenWorldKnowledgeTopicPlusLocationPlusContextKeywordsFound_ContextShouldContainLore(): void
    {
        // default test config
        require("conf.php");

        $this->insertStimpackLore();
        $testDb = new sql();
        $testDb->insert(
            'conf_opts',
            array(
                'id' => 'current_worldknowledge_topic',
                'value' => 'Doctor'
            )
        );
        $testDb->insert(
            'eventlog',
            array(
                'ts' => "0",
                'gamets' => "0",
                'type' => "world_context",
                'data' => "world context: Lair of the Stimpack Vendor",
                'sess' => 'pending',
                'localts' => 0,
                'location'=> "Lair of the Stimpack Vendor",
                'party'=> '{"type":"world_context","location":"Lair of the Stimpack Vendor","worldspace":"Stimpack Vendor\'s Lair","game_time":{"year":2281,"month":11,"day":30,"hour":14.3333}}'
            )
        );
        $testDb->insert(
            'speech',
            array(
                'sess' => 'pending',
                'speaker' => 'Courier',
                'speech' => "tell me about the stimpack vendor.",
                'location' => "Freeside",
                'listener' => "The Narrator",
                'localts' => 0,
                'gamets' => 0
            )
        );
        $testDb->insert(
            'speech',
            array(
                'sess' => 'pending',
                'speaker' => 'The Narrator',
                // manipulating the speech so that the tags are scored highly enough to pull in the worldknowledge topic
                'speech' => "Wasteland medic buys sells stimpaks doctors bags basic chems reserves supplies caps injuries caravan guards trade routes",
                'location' => "Freeside",
                'listener' => "Courier",
                'localts' => 10,
                'gamets' => 10
            )
        );
        $testDb->close();
        
        $GLOBALS["mockMinimeTopic"] = function($text) {
            return '{"input_text": "'.$text.'", "generated_tags": "stimpack vendor", "elapsed_time": "0.05 seconds"}';
        };
        
        // input topic = 0
        // worldknowledge topic = 1.5
        // location = 1.4
        // context = 0.4

        $GLOBALS["mockConnectorSend"]=$this->createMock(CallableMock::class);
        $GLOBALS["mockConnectorSend"]->expects($this->once())
        ->method('__invoke')
        ->with(
            $this->equalTo('https://openrouter.ai/api/v1/chat/completions'),
            $this->callback(function ($streamContext) {
                $options = stream_context_get_options($streamContext);
                $this->assertStringContainsString('topic=\\"stimpack_seller\\" level=\\"advanced\\"', $options['http']['content']);
                $this->assertStringContainsString('The stimpack vendor is a wasteland medic', $options['http']['content']);
                return true;
            })
        )
        ->willReturnCallback(function($url, $context) {
            return $this->defaultConnectorResponse($url, $context);
        });
        $this->setJsonRequest('inputtext', 100, 200, 'What do you know about this place?');
        require(__DIR__.DIRECTORY_SEPARATOR."..".DIRECTORY_SEPARATOR."..".DIRECTORY_SEPARATOR."main.php");
    }

    private function expectPromptInContext($streamContext, $expectedPrompt) {
        $options = stream_context_get_options($streamContext);
        $content = json_decode($options['http']['content']);
        $found=false;
        foreach ($content->messages as $message) {
            if (json_encode($message) === json_encode($expectedPrompt)) {
                $found = true;
                break;
            }
        }

        $this->assertTrue($found);
    }

    private function expectPromptNotInContext($streamContext, $expectedPrompt) {
        $options = stream_context_get_options($streamContext);
        $content = json_decode($options['http']['content']);
        $found=false;
        foreach ($content->messages as $message) {
            if (json_encode($message) === json_encode($expectedPrompt)) {
                $found = true;
                break;
            }
        }

        $this->assertFalse($found);
    }
    
    private function defaultConnectorResponse($url, $context) {
        $response = 'data: {"choices":[{"delta":{"content": "{\"character\": \"The Narrator\", \"listener\": \"Courier\", \"message\": \"Unit test message\", \"mood\": \"default\", \"action\": \"Talk\", \"target\": \"Courier\"}"}}]}';
        $resourceMock = fopen('php://temp', 'r+');
        fwrite($resourceMock, $response);
        rewind($resourceMock);
        return $resourceMock;
    }

    private function insertStimpackLore() {
        $testDb = new sql();
        $testDb->insert(
            'worldknowledge',
            array(
                'topic' => 'stimpack_seller',
                'aliases' => 'stimpack vendor,Lair of the Stimpack Vendor',
                'canonical_topic' => 'stimpack_seller',
                'source_kind' => 'custom',
                'topic_desc' => 'The stimpack vendor is a wasteland medic who buys and sells stimpaks, doctor\'s bags, and basic chems. He reserves his best supplies for customers with caps or serious injuries. He respects caravan guards because they keep trade routes open.',
                'native_vector' => "'wasteland':4B 'medic':5B 'buy':8B 'sell':10B 'stimpack':1A,11B 'doctor':12B 'bag':13B 'chem':16B 'reserve':18B 'caps':26B 'injuri':29B 'caravan':33B 'guard':34B 'route':38B 'vendor':2A,3B"
            )
        );
        $testDb->close();
    }
}
