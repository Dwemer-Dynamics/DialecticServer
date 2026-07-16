<?php

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/lib/wav_utils.php';

final class WavUtilsTest extends TestCase
{
    private function chunk(string $id, string $data): string
    {
        return $id . pack('V', strlen($data)) . $data . ((strlen($data) % 2) ? "\0" : '');
    }

    public function testExtractsPcmFromWaveWithNonstandardHeaderChunks(): void
    {
        $fmt = pack('vvVVvv', 1, 1, 22050, 44100, 2, 16);
        $pcm = pack('v*', 0, 1000, 64536, 2000);
        $body = 'WAVE'
            . $this->chunk('fmt ', $fmt)
            . $this->chunk('JUNK', 'metadata')
            . $this->chunk('data', $pcm);
        $wav = 'RIFF' . pack('V', strlen($body)) . $body;

        $result = dialecticWavExtractPcmData($wav);

        self::assertTrue($result['ok']);
        self::assertSame(22050, $result['sample_rate']);
        self::assertSame(1, $result['channels']);
        self::assertSame(16, $result['bits_per_sample']);
        self::assertSame($pcm, $result['data']);
    }

    public function testRejectsTruncatedWaveChunk(): void
    {
        $fmt = pack('vvVVvv', 1, 1, 22050, 44100, 2, 16);
        $body = 'WAVE' . $this->chunk('fmt ', $fmt) . 'data' . pack('V', 50) . str_repeat('x', 10);
        $wav = 'RIFF' . pack('V', strlen($body)) . $body;
        $result = dialecticWavExtractPcmData($wav);

        self::assertFalse($result['ok']);
        self::assertSame('truncated_wav_chunk', $result['error']);
    }
}
