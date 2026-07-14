<?php declare(strict_types=1);

require_once 'DatabaseTestCase.php';
require_once 'CallableMock.php';

final class CommTest extends DatabaseTestCase
{
    public function testInputTextSendsAuthenticatedLlmRequest(): void
    {
        require('conf.php');

        $GLOBALS['mockConnectorSend'] = $this->createMock(CallableMock::class);
        $GLOBALS['mockConnectorSend']->expects($this->once())
            ->method('__invoke')
            ->with(
                $this->equalTo('https://openrouter.ai/api/v1/chat/completions'),
                $this->callback(function ($streamContext): bool {
                    $options = stream_context_get_options($streamContext);
                    $this->assertSame('POST', $options['http']['method']);

                    $headers = explode("\r\n", $options['http']['header']);
                    $this->assertContains('Content-Type: application/json', $headers);
                    $this->assertContains('Authorization: Bearer openrouterjson_key', $headers);
                    return true;
                })
            )
            ->willReturnCallback(fn($url, $context) => $this->defaultConnectorResponse());

        $this->setJsonRequest('inputtext', 100, 200, 'Hey Narrator, attack that monster!');
        require dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'main.php';
    }

    public function testInputTextBuildsCurrentStructuredPromptAndSchema(): void
    {
        require('conf.php');

        $testDb = new sql();
        $expectedConnector = $testDb->fetchOne('SELECT * FROM core_llm_connector WHERE id=1');
        $testDb->close();

        $GLOBALS['mockConnectorSend'] = $this->createMock(CallableMock::class);
        $GLOBALS['mockConnectorSend']->expects($this->once())
            ->method('__invoke')
            ->with(
                $this->equalTo('https://openrouter.ai/api/v1/chat/completions'),
                $this->callback(function ($streamContext) use ($expectedConnector): bool {
                    $options = stream_context_get_options($streamContext);
                    $payload = json_decode($options['http']['content'], true, 512, JSON_THROW_ON_ERROR);
                    $messages = $payload['messages'] ?? [];

                    $this->assertSame($expectedConnector['model'], $payload['model']);
                    $this->assertTrue($payload['stream']);
                    $this->assertSame((int)$expectedConnector['max_tokens'], $payload['max_tokens']);
                    $this->assertSame('json_schema', $payload['response_format']['type']);
                    $this->assertSame('response', $payload['response_format']['json_schema']['name']);

                    $systemPrompt = (string)($messages[0]['content'] ?? '');
                    $this->assertSame('system', $messages[0]['role'] ?? null);
                    $this->assertStringContainsString('<roleplay_instructions>', $systemPrompt);
                    $this->assertStringContainsString('<character>', $systemPrompt);
                    $this->assertStringContainsString('Roleplay as The Narrator', $systemPrompt);

                    $combinedContent = implode("\n", array_map(
                        static fn(array $message): string => (string)($message['content'] ?? ''),
                        $messages
                    ));
                    $this->assertStringContainsString(
                        'Prisoner: Hey Narrator, attack that monster! (Talking to The Narrator)',
                        $combinedContent
                    );
                    $this->assertStringContainsString("Write The Narrator's next dialogue line.", $combinedContent);
                    $this->assertStringContainsString('Use ONLY this JSON object to give your answer.', $combinedContent);
                    $this->assertStringContainsString('Read_Quests', $combinedContent);
                    $this->assertStringContainsString('Talk', $combinedContent);
                    return true;
                })
            )
            ->willReturnCallback(fn($url, $context) => $this->defaultConnectorResponse());

        $this->setJsonRequest('inputtext', 100, 200, 'Hey Narrator, attack that monster!');
        require dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'main.php';
    }

    public function testLlmResponseIsPersistedAsEmittedChat(): void
    {
        require('conf.php');

        $this->setJsonRequest('inputtext', 100, 200, 'Hey Narrator, attack that monster!');
        require dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'main.php';

        $testDb = new sql();
        $rows = $testDb->fetchAll('SELECT * FROM eventlog ORDER BY rowid DESC LIMIT 1;');
        $testDb->close();

        $this->assertSame('chat', $rows[0]['type']);
        $this->assertSame('The Narrator: Unit test message (talking to Prisoner)', $rows[0]['data']);
        $this->assertSame('emitted', $rows[0]['delivery_state']);
    }

    public function testSpeechAbortMarksEmittedChatAsAborted(): void
    {
        require('conf.php');

        $testDb = new sql();
        $testDb->insert('eventlog', [
            'ts' => '100',
            'gamets' => '200',
            'type' => 'chat',
            'data' => 'Veronica: Hold there. (talking to Prisoner)',
            'sess' => 'pending',
            'localts' => 10,
            'people' => '|Veronica|Prisoner|',
            'location' => '',
            'party' => '[]',
            'delivery_state' => 'emitted',
            'utterance_id' => 'utt_abort_1',
        ]);
        $testDb->close();

        $this->setJsonRequest('_speech_abort', 101, 201, '{"utterance_ids":["utt_abort_1"]}');
        require dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'main.php';

        $testDb = new sql();
        $rows = $testDb->fetchAll(
            "SELECT delivery_state FROM eventlog WHERE utterance_id='utt_abort_1' ORDER BY rowid DESC LIMIT 1;"
        );
        $testDb->close();

        $this->assertSame('aborted', $rows[0]['delivery_state']);
    }

    private function defaultConnectorResponse()
    {
        $response = 'data: {"choices":[{"delta":{"content": "{\\"character\\": \\"The Narrator\\", \\"listener\\": \\"Prisoner\\", \\"message\\": \\"Unit test message\\", \\"mood\\": \\"default\\", \\"action\\": \\"Talk\\", \\"target\\": \\"Prisoner\\"}"}}]}';
        $resource = fopen('php://temp', 'r+');
        fwrite($resource, $response);
        rewind($resource);
        return $resource;
    }
}
