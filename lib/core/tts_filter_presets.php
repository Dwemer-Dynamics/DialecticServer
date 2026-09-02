<?php

const DIALECTIC_TTS_FILTER_PRESET_VERSION = 1;

/** Return the fixed server-owned voice-filter catalog. */
function dialecticTtsFilterPresetCatalog(): array
{
    return [
        'none' => ['label' => 'None (default)', 'description' => 'No additional filter. Speech uses the voice engine output.', 'filters' => []],
        'warm' => ['label' => 'Warm', 'description' => 'Adds subtle warmth and presence while keeping volume even.', 'filters' => [
            'highpass=f=70', 'lowpass=f=15000', 'equalizer=f=140:t=q:w=0.9:g=2.0',
            'equalizer=f=3000:t=q:w=1.0:g=1.0', 'acompressor=threshold=-20dB:ratio=2:attack=10:release=120:makeup=1.5',
            'loudnorm=I=-16:TP=-1.5:LRA=8', 'aresample=24000',
        ]],
        'deep' => ['label' => 'Deep', 'description' => 'Adds low-end weight and reduces harshness without changing speed.', 'filters' => [
            'highpass=f=55', 'lowpass=f=11500', 'equalizer=f=100:t=q:w=0.8:g=3.0',
            'equalizer=f=250:t=q:w=1.0:g=-1.0', 'equalizer=f=2200:t=q:w=1.0:g=-1.5',
            'acompressor=threshold=-20dB:ratio=3:attack=10:release=150:makeup=2', 'loudnorm=I=-16:TP=-1.5:LRA=7', 'aresample=24000',
        ]],
        'ethereal' => ['label' => 'Ethereal', 'description' => 'Adds airy presence with a soft, short double echo.', 'filters' => [
            'highpass=f=120', 'lowpass=f=12000', 'equalizer=f=3500:t=q:w=1.0:g=1.5',
            'acompressor=threshold=-22dB:ratio=2:attack=12:release=180:makeup=1.5',
            'aecho=0.8:0.88:45|90:0.18|0.08', 'loudnorm=I=-17:TP=-1.5:LRA=8', 'aresample=24000',
        ]],
        'sinister' => ['label' => 'Sinister', 'description' => 'Darkens the voice and adds a restrained echo.', 'filters' => [
            'highpass=f=60', 'lowpass=f=9500', 'equalizer=f=110:t=q:w=0.8:g=2.5',
            'equalizer=f=1800:t=q:w=1.0:g=-2.0', 'equalizer=f=4200:t=q:w=1.1:g=1.0',
            'acompressor=threshold=-20dB:ratio=2.8:attack=10:release=160:makeup=2',
            'aecho=1.0:0.90:65:0.12', 'loudnorm=I=-16:TP=-1.5:LRA=7', 'aresample=24000',
        ]],
        'automaton' => ['label' => 'Automaton', 'description' => 'Adds a band-limited mechanical tone with light digital texture.', 'filters' => [
            'highpass=f=240', 'lowpass=f=3800', 'equalizer=f=1200:t=q:w=0.8:g=3.0',
            'acompressor=threshold=-24dB:ratio=4:attack=5:release=90:makeup=2',
            'acrusher=bits=12:mix=0.18:mode=log', 'aecho=1.0:0.85:28:0.08',
            'loudnorm=I=-16:TP=-1.5:LRA=6', 'aresample=24000',
        ]],
        'radio' => ['label' => 'Radio', 'description' => 'Creates a compressed communications tone with light digital grit.', 'filters' => [
            'highpass=f=300', 'lowpass=f=3400', 'equalizer=f=1300:t=q:w=0.9:g=3',
            'acompressor=threshold=-24dB:ratio=4:attack=4:release=80:makeup=2',
            'acrusher=bits=13:mix=0.10:mode=log:samples=2', 'loudnorm=I=-16:TP=-1.5:LRA=6', 'aresample=24000',
        ]],
        'haunted' => ['label' => 'Haunted', 'description' => 'Darkens the voice with slow movement and a lingering double echo.', 'filters' => [
            'highpass=f=90', 'lowpass=f=10000', 'equalizer=f=1800:t=q:w=1.0:g=-1.5',
            'aphaser=in_gain=0.65:out_gain=0.75:delay=3:decay=0.35:speed=0.35:type=sinusoidal',
            'aecho=0.85:0.88:95|190:0.16|0.07', 'acompressor=threshold=-22dB:ratio=2.5:attack=10:release=160:makeup=1.5',
            'loudnorm=I=-17:TP=-1.5:LRA=8', 'aresample=24000',
        ]],
        'cavernous' => ['label' => 'Cavernous', 'description' => 'Adds body and a pair of long, spacious echoes.', 'filters' => [
            'highpass=f=75', 'lowpass=f=11500', 'equalizer=f=220:t=q:w=0.9:g=1.0',
            'acompressor=threshold=-22dB:ratio=2.5:attack=10:release=180:makeup=1.5',
            'aecho=0.80:0.82:180|360:0.20|0.09', 'loudnorm=I=-17:TP=-1.5:LRA=9', 'aresample=24000',
        ]],
        'underwater' => ['label' => 'Underwater', 'description' => 'Heavily muffles the voice and adds slow, fluid movement.', 'filters' => [
            'highpass=f=45', 'lowpass=f=1500', 'equalizer=f=280:t=q:w=0.8:g=3.0',
            'flanger=delay=2.5:depth=1.5:regen=5:width=22:speed=0.25:shape=sinusoidal:interp=quadratic',
            'acompressor=threshold=-22dB:ratio=3:attack=12:release=180:makeup=2',
            'loudnorm=I=-17:TP=-1.5:LRA=7', 'aresample=24000',
        ]],
        'quick' => ['label' => 'Quick', 'description' => 'Speeds up delivery slightly while preserving the original voice.', 'filters' => [
            'highpass=f=70', 'lowpass=f=15000', 'atempo=1.12',
            'acompressor=threshold=-20dB:ratio=2:attack=8:release=100:makeup=1.5',
            'loudnorm=I=-16:TP=-1.5:LRA=8', 'aresample=24000',
        ]],
        'drawling' => ['label' => 'Drawling', 'description' => 'Slows delivery slightly and adds a touch of warmth.', 'filters' => [
            'highpass=f=65', 'lowpass=f=14500', 'equalizer=f=140:t=q:w=0.9:g=1.0', 'atempo=0.88',
            'acompressor=threshold=-20dB:ratio=2:attack=10:release=140:makeup=1.5',
            'loudnorm=I=-16:TP=-1.5:LRA=8', 'aresample=24000',
        ]],
        'measured' => ['label' => 'Measured', 'description' => 'Makes delivery a little slower, steadier, and more even.', 'filters' => [
            'highpass=f=75', 'lowpass=f=14500', 'equalizer=f=2800:t=q:w=1.0:g=0.8', 'atempo=0.95',
            'acompressor=threshold=-21dB:ratio=2.4:attack=12:release=150:makeup=1.5',
            'loudnorm=I=-16:TP=-1.5:LRA=7', 'aresample=24000',
        ]],
        'soft_spoken' => ['label' => 'Soft-Spoken', 'description' => 'Softens harsh edges and lowers the voice slightly without changing its character.', 'filters' => [
            'highpass=f=85', 'lowpass=f=12000', 'equalizer=f=3200:t=q:w=1.0:g=-1.2',
            'acompressor=threshold=-24dB:ratio=1.8:attack=18:release=180:makeup=1',
            'loudnorm=I=-19:TP=-2:LRA=9', 'aresample=24000',
        ]],
        'crisp' => ['label' => 'Crisp', 'description' => 'Adds mild clarity and presence for cleaner everyday speech.', 'filters' => [
            'highpass=f=85', 'lowpass=f=15500', 'equalizer=f=300:t=q:w=1.0:g=-1.0',
            'equalizer=f=3400:t=q:w=0.9:g=2.0', 'acompressor=threshold=-21dB:ratio=2:attack=8:release=110:makeup=1.2',
            'loudnorm=I=-16:TP=-1.5:LRA=8', 'aresample=24000',
        ]],
        'commanding' => ['label' => 'Commanding', 'description' => 'Adds restrained weight and firmness while keeping the voice natural.', 'filters' => [
            'highpass=f=60', 'lowpass=f=13500', 'equalizer=f=120:t=q:w=0.8:g=1.8',
            'equalizer=f=2600:t=q:w=1.0:g=1.0', 'acompressor=threshold=-22dB:ratio=3:attack=8:release=130:makeup=2',
            'loudnorm=I=-16:TP=-1.5:LRA=6', 'aresample=24000',
        ]],
    ];
}

function dialecticTtsFilterPresetOptions(bool $exposedOnly = true): array
{
    $options = [];
    foreach (dialecticTtsFilterPresetCatalog() as $id => $preset) {
        $options[$id] = ['id' => $id] + $preset;
    }
    return $options;
}

function dialecticNormalizeTtsFilterPresetId(mixed $value): string
{
    $id = strtolower(trim(strval($value)));
    return isset(dialecticTtsFilterPresetCatalog()[$id]) ? $id : 'none';
}

function dialecticTtsFilterPresetGraph(mixed $value): string
{
    $preset = dialecticTtsFilterPresetCatalog()[dialecticNormalizeTtsFilterPresetId($value)] ?? [];
    return implode(',', is_array($preset['filters'] ?? null) ? $preset['filters'] : []);
}

function dialecticSetActiveTtsFilterPreset(mixed $value): string
{
    $id = dialecticNormalizeTtsFilterPresetId($value);
    if ($id === 'none') {
        unset($GLOBALS['DIALECTIC_TTS_FILTER_PRESET_ID']);
    } else {
        $GLOBALS['DIALECTIC_TTS_FILTER_PRESET_ID'] = $id;
    }
    return $id;
}

function dialecticClearActiveTtsFilterPreset(): void
{
    unset($GLOBALS['DIALECTIC_TTS_FILTER_PRESET_ID']);
}

function dialecticGetActiveTtsFilterPresetId(): string
{
    return dialecticNormalizeTtsFilterPresetId($GLOBALS['DIALECTIC_TTS_FILTER_PRESET_ID'] ?? 'none');
}

/** Apply the active preset to a connector-generated WAV in DialecticServer's sound cache. */
function dialecticApplyActiveTtsFilterPresetToOutput(mixed $ttsOutput): mixed
{
    $presetId = dialecticGetActiveTtsFilterPresetId();
    if ($presetId === 'none' || !is_string($ttsOutput) || trim($ttsOutput) === '') {
        return $ttsOutput;
    }

    $root = dirname(__DIR__, 2);
    $cacheRoot = realpath($root . DIRECTORY_SEPARATOR . 'soundcache');
    if ($cacheRoot === false) {
        return $ttsOutput;
    }
    $relative = ltrim(str_replace(['/', '\\'], DIRECTORY_SEPARATOR, trim($ttsOutput)), DIRECTORY_SEPARATOR);
    $audioPath = null;
    foreach ([trim($ttsOutput), $root . DIRECTORY_SEPARATOR . $relative] as $candidate) {
        $resolved = is_file($candidate) ? realpath($candidate) : false;
        if ($resolved !== false && strncasecmp($resolved, rtrim($cacheRoot, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR, strlen(rtrim($cacheRoot, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR)) === 0) {
            $audioPath = $resolved;
            break;
        }
    }
    $graph = dialecticTtsFilterPresetGraph($presetId);
    if ($audioPath === null || $graph === '') {
        return $ttsOutput;
    }

    try {
        $nonce = bin2hex(random_bytes(6));
    } catch (Throwable) {
        $nonce = str_replace('.', '', uniqid('', true));
    }
    $temporaryPath = $audioPath . '.voicefilter.' . $nonce . '.wav';
    $ffmpeg = trim(strval($GLOBALS['FFMPEG_BINARY'] ?? 'ffmpeg')) ?: 'ffmpeg';
    $command = escapeshellarg($ffmpeg) . ' -hide_banner -loglevel error -y -i ' . escapeshellarg($audioPath)
        . ' -af ' . escapeshellarg($graph) . ' ' . escapeshellarg($temporaryPath) . ' 2>&1';
    $output = [];
    $exitCode = 1;
    try {
        exec($command, $output, $exitCode);
    } catch (Throwable $error) {
        @unlink($temporaryPath);
        if (class_exists('Logger')) {
            Logger::error("[TTS FILTER] FFmpeg could not run for preset '{$presetId}': {$error->getMessage()}");
        }
        return $ttsOutput;
    }
    if ($exitCode !== 0 || !is_file($temporaryPath) || filesize($temporaryPath) <= 44 || !@rename($temporaryPath, $audioPath)) {
        @unlink($temporaryPath);
        if (class_exists('Logger')) {
            Logger::error("[TTS FILTER] FFmpeg failed for preset '{$presetId}' (exit {$exitCode}).");
        }
        return $ttsOutput;
    }
    return $ttsOutput;
}
