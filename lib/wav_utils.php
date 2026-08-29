<?php

if (!function_exists('dialecticWavExtractPcmData')) {
    function dialecticWavExtractPcmData(string $wavData): array
    {
        $length = strlen($wavData);
        if ($length < 44 || substr($wavData, 0, 4) !== 'RIFF' || substr($wavData, 8, 4) !== 'WAVE') {
            return ['ok' => false, 'error' => 'invalid_riff_wave'];
        }

        $format = null;
        $pcmData = '';
        $offset = 12;
        while ($offset + 8 <= $length) {
            $chunkId = substr($wavData, $offset, 4);
            $chunkSizeData = unpack('Vsize', substr($wavData, $offset + 4, 4));
            $chunkSize = intval($chunkSizeData['size'] ?? 0);
            $chunkStart = $offset + 8;
            $chunkEnd = $chunkStart + $chunkSize;
            if ($chunkSize < 0 || $chunkEnd > $length) {
                return ['ok' => false, 'error' => 'truncated_wav_chunk'];
            }

            if ($chunkId === 'fmt ' && $chunkSize >= 16) {
                $fmt = unpack(
                    'vformat/vchannels/Vsample_rate/Vbyte_rate/vblock_align/vbits_per_sample',
                    substr($wavData, $chunkStart, 16)
                );
                $format = is_array($fmt) ? $fmt : null;
            } elseif ($chunkId === 'data') {
                $pcmData .= substr($wavData, $chunkStart, $chunkSize);
            }

            $offset = $chunkEnd + ($chunkSize % 2);
        }

        if (!is_array($format) || $pcmData === '') {
            return ['ok' => false, 'error' => 'missing_fmt_or_data'];
        }
        if (intval($format['format'] ?? 0) !== 1 || intval($format['bits_per_sample'] ?? 0) !== 16) {
            return ['ok' => false, 'error' => 'unsupported_wav_format'] + $format;
        }

        return [
            'ok' => true,
            'data' => $pcmData,
            'format' => intval($format['format']),
            'channels' => intval($format['channels']),
            'sample_rate' => intval($format['sample_rate']),
            'byte_rate' => intval($format['byte_rate']),
            'block_align' => intval($format['block_align']),
            'bits_per_sample' => intval($format['bits_per_sample']),
        ];
    }
}
