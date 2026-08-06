<?php declare(strict_types=1);

require_once 'DatabaseTestCase.php';
require_once 'CallableMock.php';

// setUp and tearDown for the test database are in DatabaseTestCase.php
final class MemoryTest extends DatabaseTestCase
{
    public function testIndividualMemoryUsesGlobalBankUntilScopedSummaryExists(): void
    {
        require_once dirname(__DIR__, 2) . '/lib/data_functions.php';

        $GLOBALS['db'] = new sql();
        $npcMaster = new NpcMaster();
        $this->assertTrue($npcMaster->create([
            'npc_name' => 'Scoped Memory Readiness Test',
            'individual_memory_enabled' => 1,
        ]));

        $this->assertSame(
            "(scope IS NULL OR scope='global')",
            dataGetMemoryScopeConditionSql('Scoped Memory Readiness Test')
        );

        $this->assertTrue($GLOBALS['db']->insert('memory_summary', [
            'gamets_truncated' => 100,
            'n' => 1,
            'packed_message' => 'Scoped memory source',
            'summary' => 'Scoped memory summary',
            'classifier' => 'individual',
            'uid' => 100,
            'scope' => 'Scoped Memory Readiness Test',
        ]));

        $this->assertSame(
            "scope='Scoped Memory Readiness Test'",
            dataGetMemoryScopeConditionSql('Scoped Memory Readiness Test')
        );
    }

    public function testMemory_WhenDumbContextAndNoMemoryExists_ContextShouldNotContainMemory(): void
    {
        // default test config
        require("conf.php");
        $GLOBALS["MINIME_T5"] = false;

        $GLOBALS["mockConnectorSend"]=$this->createMock(CallableMock::class);
        $GLOBALS["mockConnectorSend"]->expects($this->once())
        ->method('__invoke')
        ->with(
            $this->equalTo('https://openrouter.ai/api/v1/chat/completions'),
            $this->callback(function ($streamContext) {
                $options = stream_context_get_options($streamContext);
                $this->assertStringNotContainsString("remembers this:", $options['http']['content']);
                return true;
            })
        )
        ->willReturnCallback(function($url, $context) {
            return $this->defaultConnectorResponse($url, $context);
        });
        $this->setJsonRequest('inputtext', 100, 200, 'Hopefully we can buy some ale in town.');
        require(__DIR__.DIRECTORY_SEPARATOR."..".DIRECTORY_SEPARATOR."..".DIRECTORY_SEPARATOR."main.php");
    }

    public function testMemory_WhenDumbContextOffersNoMemory_ContextShouldNotContainMemory(): void
    {
        // default test config
        require("conf.php");
        $GLOBALS["MINIME_T5"] = false;

        // add summarized memory
        $this->insertStimpackMemory();

        $GLOBALS["mockConnectorSend"]=$this->createMock(CallableMock::class);
        $GLOBALS["mockConnectorSend"]->expects($this->once())
        ->method('__invoke')
        ->with(
            $this->equalTo('https://openrouter.ai/api/v1/chat/completions'),
            $this->callback(function ($streamContext) {
                $options = stream_context_get_options($streamContext);
                $this->assertStringNotContainsString("remembers this:", $options['http']['content']);
                return true;
            })
        )
        ->willReturnCallback(function($url, $context) {
            return $this->defaultConnectorResponse($url, $context);
        });
        $this->setJsonRequest('inputtext', 100, 200, 'Hopefully we can buy some ale in town.');
        require(__DIR__.DIRECTORY_SEPARATOR."..".DIRECTORY_SEPARATOR."..".DIRECTORY_SEPARATOR."main.php");
    }

    public function testMemory_WhenDumbContextOffersMemory_ContextShouldContainMemory(): void
    {
        // default test config
        require("conf.php");
        $GLOBALS["MINIME_T5"] = false;

        // add summarized memory
        $this->insertStimpackMemory();

        $GLOBALS["mockConnectorSend"]=$this->createMock(CallableMock::class);
        $GLOBALS["mockConnectorSend"]->expects($this->once())
        ->method('__invoke')
        ->with(
            $this->equalTo('https://openrouter.ai/api/v1/chat/completions'),
            $this->callback(function ($streamContext) {
                $expectedPrompt = ["role"=>"user", "content"=>"##\nMEMORY\n: The Narrator remembers this: [0 hours ago ... #Summary: Courier attempted to buy strong stimpaks from a merchant but was rudely turned away.\\n\\n]\n##\n"];
                $this->expectPromptInContext($streamContext, $expectedPrompt);
                return true;
            })
        )
        ->willReturnCallback(function($url, $context) {
            return $this->defaultConnectorResponse($url, $context);
        });
        $this->setJsonRequest('inputtext', 100, 200, 'Hopefully we can buy some stimpaks in town.');
        require(__DIR__.DIRECTORY_SEPARATOR."..".DIRECTORY_SEPARATOR."..".DIRECTORY_SEPARATOR."main.php");
    }

    public function testMemory_WhenMinimeT5AndNoMemoryExists_ContextShouldNotContainMemory(): void
    {
        // default test config
        require("conf.php");
        
        $GLOBALS["mockMinimeExtract"] = function($text) {
            return '{"is_memory_recall": "Yes", "generated_tags": "Ale|Town", "elapsed_time": "0.05 seconds"}';
        };

        $GLOBALS["mockConnectorSend"]=$this->createMock(CallableMock::class);
        $GLOBALS["mockConnectorSend"]->expects($this->once())
        ->method('__invoke')
        ->with(
            $this->equalTo('https://openrouter.ai/api/v1/chat/completions'),
            $this->callback(function ($streamContext) {
                $options = stream_context_get_options($streamContext);
                $this->assertStringNotContainsString("remembers this:", $options['http']['content']);
                return true;
            })
        )
        ->willReturnCallback(function($url, $context) {
            return $this->defaultConnectorResponse($url, $context);
        });
        $this->setJsonRequest('inputtext', 100, 200, 'Hopefully we can buy some ale in town.');
        require(__DIR__.DIRECTORY_SEPARATOR."..".DIRECTORY_SEPARATOR."..".DIRECTORY_SEPARATOR."main.php");
    }

    public function testMemory_WhenMinimeT5OffersNoMemory_ContextShouldNotContainMemory(): void
    {
        // default test config
        require("conf.php");

        // add summarized memory
        $this->insertStimpackMemory();
        
        $GLOBALS["mockMinimeExtract"] = function($text) {
            return '{"is_memory_recall": "Yes", "generated_tags": "Ale|Town", "elapsed_time": "0.05 seconds"}';
        };

        $GLOBALS["mockConnectorSend"]=$this->createMock(CallableMock::class);
        $GLOBALS["mockConnectorSend"]->expects($this->once())
        ->method('__invoke')
        ->with(
            $this->equalTo('https://openrouter.ai/api/v1/chat/completions'),
            $this->callback(function ($streamContext) {
                $options = stream_context_get_options($streamContext);
                $this->assertStringNotContainsString("remembers this:", $options['http']['content']);
                return true;
            })
        )
        ->willReturnCallback(function($url, $context) {
            return $this->defaultConnectorResponse($url, $context);
        });
        $this->setJsonRequest('inputtext', 100, 200, 'Hopefully we can buy some ale in town.');
        require(__DIR__.DIRECTORY_SEPARATOR."..".DIRECTORY_SEPARATOR."..".DIRECTORY_SEPARATOR."main.php");
    }

    public function testMemory_WhenMinimeT5OffersMemory_ContextShouldContainMemory(): void
    {
        // default test config
        require("conf.php");

        // add summarized memory
        $this->insertStimpackMemory();
        
        $GLOBALS["mockMinimeExtract"] = function($text) {
            return '{"is_memory_recall": "Yes", "generated_tags": "Stimpaks|Town", "elapsed_time": "0.05 seconds"}';
        };

        $GLOBALS["mockConnectorSend"]=$this->createMock(CallableMock::class);
        $GLOBALS["mockConnectorSend"]->expects($this->once())
        ->method('__invoke')
        ->with(
            $this->equalTo('https://openrouter.ai/api/v1/chat/completions'),
            $this->callback(function ($streamContext) {
                $expectedPrompt = ["role"=>"user", "content"=>"##\nMEMORY\n: The Narrator remembers this: [0 hours ago ... #Summary: Courier attempted to buy strong stimpaks from a merchant but was rudely turned away.\\n\\n]\n##\n"];
                $this->expectPromptInContext($streamContext, $expectedPrompt);
                return true;
            })
        )
        ->willReturnCallback(function($url, $context) {
            return $this->defaultConnectorResponse($url, $context);
        });
        $this->setJsonRequest('inputtext', 100, 200, 'Hopefully we can buy some stimpaks in town.');
        require(__DIR__.DIRECTORY_SEPARATOR."..".DIRECTORY_SEPARATOR."..".DIRECTORY_SEPARATOR."main.php");
    }

    public function testMemory_WhenNotPlayerInput_ContextShouldNotContainMemory(): void
    {
        // default test config
        require("conf.php");

        // add summarized memory
        $this->insertStimpackMemory();
        
        $GLOBALS["DIALECTIC_NAME"]="Unit Test";
        $GLOBALS["DIALECTIC_PERS"]="You are a Unit Test.";

        // should not be used, but if it is then generate tags
        $GLOBALS["mockMinimeExtract"] = function($text) {
            return '{"is_memory_recall": "Yes", "generated_tags": "Stimpaks|Town", "elapsed_time": "0.05 seconds"}';
        };

        $GLOBALS["mockConnectorSend"]=$this->createMock(CallableMock::class);
        $GLOBALS["mockConnectorSend"]->expects($this->once())
        ->method('__invoke')
        ->with(
            $this->equalTo('https://openrouter.ai/api/v1/chat/completions'),
            $this->callback(function ($streamContext) {
                $options = stream_context_get_options($streamContext);
                $this->assertStringNotContainsString("remembers this:", $options['http']['content']);
                return true;
            })
        )
        ->willReturnCallback(function($url, $context) {
            $response = 'data: {"choices":[{"delta":{"content": "{\"character\": \"Unit Test\", \"listener\": \"Courier\", \"message\": \"You should have tried buying some weaker stimpaks\", \"mood\": \"default\", \"action\": \"Talk\", \"target\": \"Courier\"}"}}]}';
            $resourceMock = fopen('php://temp', 'r+');
            fwrite($resourceMock, $response);
            rewind($resourceMock);
            return $resourceMock;
        });
        $this->setJsonRequest('bored', 100, 200, 'Unit Test');
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

    private function insertStimpackMemory() {
        $testDb = new sql();
        $testDb->insert(
            'memory_summary',
            array(
                'gamets_truncated' => 0,
                'n' => 0,
                'packed_message' => '(Context Location:Freeside) Courier: Stimpack vendor. I need your strongest stimpaks.\n'.
                    '(Context Location:Freeside) Stimpack Vendor: You can\'t handle my strongest stimpaks, traveler.',
                'summary' => '#Summary: Courier attempted to buy strong stimpaks from a merchant but was rudely turned away.\n\n'.
                    '#Tags: #StimpackSeller #Stimpaks',
                'uid' => 0,
                'companions' => 'Unit Test,Stimpack Vendor',
                'tags' => '#StimpackSeller #Stimpaks',
                'native_vec'=> "'stimpack':2A,9B,22B 'seller':1A,21B"
            )
        );
        $testDb->close();
    }
}
