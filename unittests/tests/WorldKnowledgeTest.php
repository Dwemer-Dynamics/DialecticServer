<?php declare(strict_types=1);

require_once 'DatabaseTestCase.php';
require_once 'CallableMock.php';

// setUp and tearDown for the test database are in DatabaseTestCase.php
final class WorldKnowledgeTest extends DatabaseTestCase
{
    public function testShippedFalloutWorldKnowledgeDatasetContract(): void
    {
        $root = dirname(__DIR__, 2);
        $csvPath = $root.DIRECTORY_SEPARATOR.'data'.DIRECTORY_SEPARATOR.'fallout_worldknowledge_basic.csv';
        $sourcesPath = $root.DIRECTORY_SEPARATOR.'data'.DIRECTORY_SEPARATOR.'fallout_worldknowledge_sources.jsonl';
        $handle = fopen($csvPath, 'rb');
        $this->assertNotFalse($handle);

        $expectedHeader = [
            'topic',
            'topic_desc',
            'knowledge_class',
            'topic_desc_basic',
            'knowledge_class_basic',
            'tags',
            'category',
        ];
        $this->assertSame($expectedHeader, fgetcsv($handle, 0, ',', '"', '\\'));

        $topics = [];
        $categoryCounts = [];
        while (($row = fgetcsv($handle, 0, ',', '"', '\\')) !== false) {
            $this->assertCount(count($expectedHeader), $row);
            $data = array_combine($expectedHeader, $row);
            $this->assertIsArray($data);
            $topic = strval($data['topic']);
            $this->assertMatchesRegularExpression('/^[a-z0-9_]+$/', $topic);
            $this->assertArrayNotHasKey($topic, $topics);
            $topics[$topic] = true;
            $this->assertSame('', $data['topic_desc']);
            $this->assertSame('', $data['knowledge_class']);
            $this->assertSame('', $data['knowledge_class_basic']);
            $this->assertSame('', $data['tags']);
            $wordCount = count(preg_split('/\s+/', trim(strval($data['topic_desc_basic']))));
            $this->assertGreaterThanOrEqual(40, $wordCount);
            $this->assertLessThanOrEqual(260, $wordCount);
            $category = strval($data['category']);
            $categoryCounts[$category] = intval($categoryCounts[$category] ?? 0) + 1;
        }
        fclose($handle);

        $this->assertCount(350, $topics);
        $this->assertSame(
            ['creature' => 40, 'event' => 30, 'faction' => 45, 'location' => 110, 'person' => 125],
            $categoryCounts
        );

        $sourceTopics = [];
        foreach (file($sourcesPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            $source = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
            $sourceTopics[strval($source['topic'] ?? '')] = true;
            $this->assertNotEmpty($source['source_url'] ?? '');
            $this->assertNotEmpty($source['revision_id'] ?? '');
        }
        $this->assertSame(array_keys($topics), array_keys($sourceTopics));
    }

    public function testWorldKnowledgeSchemaSupportsUniqueBasicOnlyTopics(): void
    {
        $testDb = new sql();
        $column = $testDb->fetchOne("
            SELECT is_nullable
              FROM information_schema.columns
             WHERE table_schema = 'public'
               AND table_name = 'worldknowledge'
               AND column_name = 'topic_desc'
        ");
        $uniqueIndex = $testDb->fetchOne("
            SELECT COUNT(*) AS total
              FROM pg_indexes
             WHERE schemaname = 'public'
               AND tablename = 'worldknowledge'
               AND indexdef ILIKE 'CREATE UNIQUE INDEX% (topic)'
        ");
        $firstImport = $testDb->execQuery("
            INSERT INTO worldknowledge (topic, topic_desc_basic, category)
            VALUES ('megaton', 'Megaton is a fortified Capital Wasteland settlement.', 'location')
            ON CONFLICT (topic) DO UPDATE
                SET topic_desc_basic = EXCLUDED.topic_desc_basic,
                    category = EXCLUDED.category
        ");
        $secondImport = $testDb->execQuery("
            INSERT INTO worldknowledge (topic, topic_desc_basic, category)
            VALUES ('megaton', 'Megaton is built around an undetonated atomic bomb.', 'location')
            ON CONFLICT (topic) DO UPDATE
                SET topic_desc_basic = EXCLUDED.topic_desc_basic,
                    category = EXCLUDED.category
        ");
        $imported = $testDb->fetchOne("
            SELECT COUNT(*) AS total, MAX(topic_desc_basic) AS topic_desc_basic
              FROM worldknowledge
             WHERE topic = 'megaton'
        ");
        $testDb->close();

        $this->assertSame('YES', $column['is_nullable'] ?? null);
        $this->assertSame(1, intval($uniqueIndex['total'] ?? 0));
        $this->assertNotFalse($firstImport);
        $this->assertNotFalse($secondImport);
        $this->assertSame(1, intval($imported['total'] ?? 0));
        $this->assertSame(
            'Megaton is built around an undetonated atomic bomb.',
            $imported['topic_desc_basic'] ?? null
        );
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
                $this->assertStringNotContainsString("World Knowledge", $options['http']['content']);
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
                $this->assertStringContainsString("World Knowledge (You have advanced knowledge on this subject", $options['http']['content']);
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
        $testDb->execQuery("DELETE FROM worldknowledge WHERE topic = 'new_california_republic'");
        $testDb->insert(
            'worldknowledge',
            array(
                'topic' => 'new_california_republic',
                'topic_desc_basic' => 'The New California Republic, commonly called the NCR, is a large republic expanding east from California.'
            )
        );
        $testDb->execQuery("
            UPDATE worldknowledge
               SET native_vector =
                     setweight(to_tsvector(coalesce(topic, '')), 'A')
                  || setweight(to_tsvector(coalesce(topic_desc, '')), 'B')
                  || setweight(to_tsvector(coalesce(topic_desc_basic, '')), 'C')
             WHERE topic = 'new_california_republic'
        ");
        $testDb->close();

        $GLOBALS["mockConnectorSend"] = $this->createMock(CallableMock::class);
        $GLOBALS["mockConnectorSend"]->expects($this->once())
            ->method('__invoke')
            ->with(
                $this->equalTo('https://openrouter.ai/api/v1/chat/completions'),
                $this->callback(function ($streamContext) {
                    $options = stream_context_get_options($streamContext);
                    $this->assertStringContainsString('World Knowledge (You only have basic knowledge on this subject', $options['http']['content']);
                    $this->assertStringContainsString('The New California Republic, commonly called the NCR', $options['http']['content']);
                    $this->assertStringNotContainsString('World Knowledge (You have advanced knowledge on this subject', $options['http']['content']);
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
            'Tell me about the New California Republic.',
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
                $this->assertStringContainsString("World Knowledge (You have advanced knowledge on this subject", $options['http']['content']);
                $this->assertStringContainsString("The stimpack vendor is a wasteland medic who buys and sells stimpaks", $options['http']['content']);
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
                $this->assertStringNotContainsString("World Knowledge", $options['http']['content']);
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
                $this->assertStringContainsString("World Knowledge (You have advanced knowledge on this subject", $options['http']['content']);
                $this->assertStringContainsString("The stimpack vendor is a wasteland medic who buys and sells stimpaks", $options['http']['content']);
                return true;
            })
        )
        ->willReturnCallback(function($url, $context) {
            return $this->defaultConnectorResponse($url, $context);
        });
        $this->setJsonRequest('inputtext', 100, 200, 'I carried the Platinum Chip. Surely I must know something.');
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
                $this->assertStringContainsString("World Knowledge (You have advanced knowledge on this subject", $options['http']['content']);
                $this->assertStringContainsString("The stimpack vendor is a wasteland medic who buys and sells stimpaks", $options['http']['content']);
                return true;
            })
        )
        ->willReturnCallback(function($url, $context) {
            return $this->defaultConnectorResponse($url, $context);
        });
        $this->setJsonRequest('inputtext', 100, 200, 'I carried the Platinum Chip. Surely I must know something.');
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
                'topic_desc' => 'The stimpack vendor is a wasteland medic who buys and sells stimpaks, doctor\'s bags, and basic chems. He reserves his best supplies for customers with caps or serious injuries. He respects caravan guards because they keep trade routes open.',
                'native_vector' => "'wasteland':4B 'medic':5B 'buy':8B 'sell':10B 'stimpack':1A,11B 'doctor':12B 'bag':13B 'chem':16B 'reserve':18B 'caps':26B 'injuri':29B 'caravan':33B 'guard':34B 'route':38B 'vendor':2A,3B"
            )
        );
        $testDb->close();
    }
}
