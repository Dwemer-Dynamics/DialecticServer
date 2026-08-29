<?php

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/lib/background_processor.php';

final class BackgroundProcessorTest extends TestCase
{
    protected function tearDown(): void
    {
        putenv('DIALECTIC_BACKGROUND_PORT');
        putenv('DIALECTIC_BACKGROUND_STATE_DIR');
        putenv('DIALECTIC_BACKGROUND_START_SCRIPT');
    }

    public function testClosedPortProbeDoesNotRetryForSeveralSeconds(): void
    {
        putenv('DIALECTIC_BACKGROUND_PORT=65431');
        $startedAt = microtime(true);

        self::assertFalse(dialecticBackgroundProcessorIsRunning(0.1));
        self::assertLessThan(0.5, microtime(true) - $startedAt);
    }

    public function testMissingStartScriptReturnsWithoutCreatingStateFiles(): void
    {
        $stateDirectory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'dialectic_background_' . uniqid('', true);
        putenv('DIALECTIC_BACKGROUND_PORT=65431');
        putenv('DIALECTIC_BACKGROUND_STATE_DIR=' . $stateDirectory);
        putenv('DIALECTIC_BACKGROUND_START_SCRIPT=' . $stateDirectory . DIRECTORY_SEPARATOR . 'missing.sh');

        self::assertFalse(dialecticEnsureBackgroundProcessorRunning(false));
        self::assertDirectoryDoesNotExist($stateDirectory);
    }
}
