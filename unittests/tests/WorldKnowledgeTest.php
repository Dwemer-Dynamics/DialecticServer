<?php declare(strict_types=1);

require_once 'DatabaseTestCase.php';
require_once 'CallableMock.php';

// setUp and tearDown for the test database are in DatabaseTestCase.php
final class WorldKnowledgeTest extends DatabaseTestCase
{
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
