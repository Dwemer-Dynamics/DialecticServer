<?php declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2).DIRECTORY_SEPARATOR.'lib'.DIRECTORY_SEPARATOR.'response.php';
require_once dirname(__DIR__, 2).DIRECTORY_SEPARATOR.'lib'.DIRECTORY_SEPARATOR.'logger.php';

final class JsonSchemaCatalogTest extends TestCase
{
    private function schemaDir(): string
    {
        return dirname(__DIR__, 2).DIRECTORY_SEPARATOR.'docs'.DIRECTORY_SEPARATOR.'schemas';
    }

    public function testAllSchemaFilesAreValidJsonAndHaveUniqueIds(): void
    {
        $ids = [];
        foreach (glob($this->schemaDir().DIRECTORY_SEPARATOR.'*.schema.json') ?: [] as $schemaFile) {
            $raw = file_get_contents($schemaFile);
            $this->assertIsString($raw, $schemaFile);

            $decoded = json_decode($raw, true);
            $this->assertIsArray($decoded, $schemaFile.' failed to decode: '.json_last_error_msg());
            $this->assertArrayHasKey('$schema', $decoded, $schemaFile);
            $this->assertArrayHasKey('$id', $decoded, $schemaFile);

            $id = (string)$decoded['$id'];
            $this->assertStringStartsWith('dialectic.', $id, $schemaFile);
            $this->assertArrayNotHasKey($id, $ids, "Duplicate schema id {$id}");
            $ids[$id] = $schemaFile;
        }

        $this->assertNotEmpty($ids);
    }

    public function testLocalSchemaReferencesResolveToTrackedFiles(): void
    {
        foreach (glob($this->schemaDir().DIRECTORY_SEPARATOR.'*.schema.json') ?: [] as $schemaFile) {
            $raw = (string)file_get_contents($schemaFile);
            preg_match_all('/"\\$ref"\\s*:\\s*"([^"]+)"/', $raw, $matches);

            foreach ($matches[1] ?? [] as $ref) {
                if (preg_match('/^https?:\\/\\//', $ref)) {
                    continue;
                }

                $refFile = explode('#', $ref, 2)[0];
                if ($refFile === '') {
                    continue;
                }

                $resolved = $this->schemaDir().DIRECTORY_SEPARATOR.$refFile;
                $this->assertFileExists($resolved, "{$schemaFile} references missing schema {$ref}");
            }
        }
    }

    public function testResponseLinesCarrySchemaAndTopLevelCommandArgs(): void
    {
        $GLOBALS['DIALECTIC_RESPONSE_FORMAT'] = 'json';
        $GLOBALS['DIALECTIC_RESPONSE_STREAMING'] = false;
        $GLOBALS['DIALECTIC_JSON_RESPONSE_LINES'] = [];

        dialectic_buffer_command_response_line('Veronica', dialecticEncodeCommandAction('Follow', ['Graussy']));

        $this->assertCount(1, $GLOBALS['DIALECTIC_JSON_RESPONSE_LINES']);
        $line = $GLOBALS['DIALECTIC_JSON_RESPONSE_LINES'][0];
        $this->assertSame('dialectic.response.line.v1', $line['schema']);
        $this->assertSame('rolecommand', $line['action']);
        $this->assertSame('Follow', $line['command_name']);
        $this->assertSame(['Graussy'], $line['command_args']);
    }

    public function testRechatSpeechCarriesExactFacingTargetFormId(): void
    {
        $GLOBALS['DIALECTIC_RESPONSE_FORMAT'] = 'json';
        $GLOBALS['DIALECTIC_RESPONSE_STREAMING'] = false;
        $GLOBALS['DIALECTIC_JSON_RESPONSE_LINES'] = [];
        $GLOBALS['gameRequest'] = ['rechat'];
        $GLOBALS['RECHAT_REQUEST_PAYLOAD'] = [
            'speaker' => 'Veronica',
            'speaker_formid' => '0x000E32A9',
        ];

        dialectic_buffer_speech_response_line(
            'Deputy Weld',
            'Hello, Veronica.',
            '',
            'Veronica',
            '',
            '',
            1.0,
            'Veronica',
            'utt_rechat_facing'
        );

        $this->assertCount(1, $GLOBALS['DIALECTIC_JSON_RESPONSE_LINES']);
        $line = $GLOBALS['DIALECTIC_JSON_RESPONSE_LINES'][0];
        $this->assertSame('0x000E32A9', $line['listener_formid']);
        $this->assertSame('0x000E32A9', $line['rechat_target_formid']);
    }

    public function testStreamRequestedIsIndependentFromJsonEnvelopeMode(): void
    {
        $GLOBALS['DIALECTIC_RESPONSE_FORMAT'] = 'legacy';
        $GLOBALS['DIALECTIC_RESPONSE_STREAMING'] = true;

        $this->assertTrue(dialectic_response_stream_requested());
        $this->assertFalse(dialectic_json_streaming_enabled());
        $this->assertFalse(dialectic_should_generate_npc_tts_before_emit());
    }

    public function testStreamingResponseLineEmitsImmediateEnvelopeAndTimingMarker(): void
    {
        $logFile = tempnam(sys_get_temp_dir(), 'dialectic_stream_');
        $this->assertIsString($logFile);
        $scriptFile = tempnam(sys_get_temp_dir(), 'dialectic_stream_script_');
        $this->assertIsString($scriptFile);

        $script = <<<'PHP'
<?php
require_once __RESPONSE_PATH__;
require_once __LOGGER_PATH__;
Logger::setCustomLog(__LOG_PATH__);
Logger::setRequestId('unit-stream-test');
$GLOBALS['DIALECTIC_RESPONSE_FORMAT'] = 'json';
$GLOBALS['DIALECTIC_RESPONSE_STREAMING'] = true;
$GLOBALS['DIALECTIC_JSON_RESPONSE_LINES'] = [];
$GLOBALS['DIALECTIC_TURN_START_TIME'] = microtime(true) - 0.25;
dialectic_buffer_speech_response_line(
    'Veronica',
    'Fast streamed line.',
    '',
    'Graussy',
    '',
    '',
    1.0,
    'Graussy',
    'utt_unit_stream'
);
PHP;
        $script = strtr($script, [
            '__RESPONSE_PATH__' => var_export(dirname(__DIR__, 2).DIRECTORY_SEPARATOR.'lib'.DIRECTORY_SEPARATOR.'response.php', true),
            '__LOGGER_PATH__' => var_export(dirname(__DIR__, 2).DIRECTORY_SEPARATOR.'lib'.DIRECTORY_SEPARATOR.'logger.php', true),
            '__LOG_PATH__' => var_export($logFile, true),
        ]);
        file_put_contents($scriptFile, $script);

        $output = (string)shell_exec(escapeshellarg(PHP_BINARY).' '.escapeshellarg($scriptFile));
        @unlink($scriptFile);

        $decoded = json_decode(trim($output), true);
        $this->assertIsArray($decoded);
        $this->assertSame('dialectic.response.v1', $decoded['schema']);
        $this->assertCount(1, $decoded['lines']);
        $this->assertSame('Veronica', $decoded['lines'][0]['speaker']);
        $this->assertSame('Fast streamed line.', $decoded['lines'][0]['text']);

        $log = (string)file_get_contents($logFile);
        @unlink($logFile);
        $this->assertStringContainsString('[plugin-response] streamed JSON envelope', $log);
        $this->assertStringContainsString('speaker=Veronica', $log);
        $this->assertStringContainsString('utterance_id=utt_unit_stream', $log);
    }

    public function testStreamingFinalEnvelopeUsesEmptyLinesArrayAndCloseMarker(): void
    {
        $scriptFile = tempnam(sys_get_temp_dir(), 'dialectic_stream_final_');
        $this->assertIsString($scriptFile);

        $script = <<<'PHP'
<?php
require_once __RESPONSE_PATH__;
$GLOBALS['DIALECTIC_RESPONSE_FORMAT'] = 'json';
$GLOBALS['DIALECTIC_RESPONSE_STREAMING'] = true;
$GLOBALS['DIALECTIC_JSON_RESPONSE_LINES'] = [];
dialectic_buffer_response_close();
$GLOBALS['DIALECTIC_JSON_RESPONSE_STREAM_FINAL_EMITTED'] = false;
dialectic_emit_buffered_json_response();
PHP;
        $script = strtr($script, [
            '__RESPONSE_PATH__' => var_export(dirname(__DIR__, 2).DIRECTORY_SEPARATOR.'lib'.DIRECTORY_SEPARATOR.'response.php', true),
        ]);
        file_put_contents($scriptFile, $script);

        $output = trim((string)shell_exec(escapeshellarg(PHP_BINARY).' '.escapeshellarg($scriptFile)));
        @unlink($scriptFile);

        $decoded = json_decode($output, true);
        $this->assertIsArray($decoded);
        $this->assertSame('dialectic.response.v1', $decoded['schema']);
        $this->assertSame([], $decoded['lines']);
        $this->assertTrue($decoded['close']);
        $this->assertStringContainsString('"lines":[]', $output);
        $this->assertStringContainsString('"close":true', $output);
    }
}
