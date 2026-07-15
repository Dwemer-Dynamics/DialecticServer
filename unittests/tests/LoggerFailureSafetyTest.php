<?php

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/lib/logger.php';

final class LoggerFailureSafetyTest extends TestCase
{
    protected function tearDown(): void
    {
        Logger::unsetCustomLog();
    }

    public function testUnwritableDestinationDoesNotRecurse(): void
    {
        $directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'dialectic_logger_' . uniqid('', true);
        self::assertTrue(mkdir($directory));

        // A directory cannot be opened as an appendable log file on Windows or Linux.
        Logger::setCustomLog($directory);
        Logger::info('first failed write');
        Logger::warn('second failed write');

        self::assertDirectoryExists($directory);
        rmdir($directory);
    }
}
