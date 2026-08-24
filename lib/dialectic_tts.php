<?php

require_once __DIR__ . DIRECTORY_SEPARATOR . "runtime_bootstrap.php";
require_once __DIR__ . DIRECTORY_SEPARATOR . "voice_clone_resolver.php";
require_once __DIR__ . DIRECTORY_SEPARATOR . "logger.php";

function dialectic_tts_cache_seed(string $root, string $speaker, string $text): string
{
    $speaker = trim($speaker);
    $text = trim($text);
    $ttsFunction = trim(strval($GLOBALS["TTSFUNCTION"] ?? ''));
    $voice = '';

    try {
        if (!isset($GLOBALS["TTSFUNCTION"])) {
            dialecticRuntimeBootstrapIfNeeded($root, [
                'load_tts_connector' => true,
                'run_db_updates' => false,
                'load_general_settings' => true,
            ]);
            $ttsFunction = trim(strval($GLOBALS["TTSFUNCTION"] ?? ''));
        }

        $currentSpeaker = trim(strval($GLOBALS["DIALECTIC_NAME"] ?? ''));
        if ($speaker !== '' && $currentSpeaker !== '' && strcasecmp($speaker, $currentSpeaker) === 0) {
            $voice = trim(strval($GLOBALS["PATCH_OVERRIDE_VOICE"] ?? ''));
            if ($voice === '') {
                $voice = trim(strval($GLOBALS["TTS_NPC_RESOLVED_VOICE_SAMPLE"] ?? ''));
            }
            if ($voice === '') {
                $voice = trim(strval($GLOBALS["TTS_NPC_RESOLVED_VOICE"] ?? ''));
            }
        }

        $db = $GLOBALS["db"] ?? null;
        if ($voice === '' && is_object($db)) {
            $voiceData = dialectic_tts_load_npc_voice_data($db, $speaker);
            $voice = trim(strval($voiceData['voiceid'] ?? ''));
            if ($voice !== '') {
                $streaming = function_exists('dialectic_response_stream_requested')
                    ? dialectic_response_stream_requested()
                    : (function_exists('dialectic_json_streaming_enabled') && dialectic_json_streaming_enabled());
                if (!$streaming) {
                    $voice = dialectic_resolve_tts_voice_reference($voice, [
                        'root' => $root,
                        'actor_name' => $speaker,
                        'voice_name' => $voiceData['voice_name'] ?? '',
                        'voice_formid' => $voiceData['voice_formid'] ?? '',
                    ]);
                }
            }
        }
    } catch (Throwable $e) {
        error_log("[Dialectic TTS] Could not resolve cache voice for {$speaker}: " . $e->getMessage());
    }

    $cacheVersion = "dialectic.tts.v4";
    if (strcasecmp($ttsFunction, 'inworld') === 0 && stripos($text, 'Dialectic') !== false) {
        // Invalidate only audio produced by the removed Inworld Dialectic pronunciation rewrite.
        $cacheVersion .= ".inworld-dialectic-v2";
    }

    return $cacheVersion . "\n" . $ttsFunction . "\n" . $speaker . "\n" . $voice . "\n" . $text;
}

function dialectic_tts_cache_key(string $root, string $speaker, string $text): string
{
    return md5(dialectic_tts_cache_seed($root, $speaker, $text));
}

function dialectic_tts_soundcache_path(string $root, string $speaker, string $text): string
{
    return $root . DIRECTORY_SEPARATOR . "soundcache" . DIRECTORY_SEPARATOR . dialectic_tts_cache_key($root, $speaker, $text) . ".wav";
}

function dialectic_tts_is_temporary_silent_voice(string $voiceId, string $voiceName = ''): bool
{
    if (function_exists('dialectic_is_temporary_silent_voice')) {
        return dialectic_is_temporary_silent_voice($voiceId, $voiceName);
    }

    $voiceKey = strtolower(preg_replace('/[^a-z0-9]+/i', '', $voiceId . ' ' . $voiceName));
    if ($voiceKey === '') {
        return false;
    }

    return str_contains($voiceKey, 'nodialogue') ||
        str_contains($voiceKey, 'donotrecord') ||
        str_contains($voiceKey, 'nvdlec01femaleunquenodialogue') ||
        str_contains($voiceKey, 'nvdlc01femaleunquenodialogue');
}

function dialectic_tts_find_actor_voiceid_from_local_samples(string $speaker): string
{
    $speakerKey = function_exists('dialectic_voice_clone_normalize_key')
        ? dialectic_voice_clone_normalize_key($speaker)
        : strtolower(preg_replace('/[^a-z0-9]+/i', '', $speaker));
    if ($speakerKey === '' || strlen($speakerKey) < 4) {
        return '';
    }

    $voiceDir = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'voices';
    if (!is_dir($voiceDir)) {
        return '';
    }

    $matches = [];
    foreach (glob($voiceDir . DIRECTORY_SEPARATOR . '*.wav') ?: [] as $path) {
        if (!is_file($path) || @filesize($path) <= 44) {
            continue;
        }
        $voiceId = pathinfo($path, PATHINFO_FILENAME);
        $voiceKey = function_exists('dialectic_voice_clone_normalize_key')
            ? dialectic_voice_clone_normalize_key($voiceId)
            : strtolower(preg_replace('/[^a-z0-9]+/i', '', $voiceId));
        if ($voiceKey !== '' && str_contains($voiceKey, $speakerKey)) {
            $matches[$voiceId] = true;
        }
    }

    return count($matches) === 1 ? (string)array_key_first($matches) : '';
}

function dialectic_tts_load_npc_voice(object $db, string $speaker): string
{
    $voiceData = dialectic_tts_load_npc_voice_data($db, $speaker);
    return trim(strval($voiceData['voiceid'] ?? ''));
}

function dialectic_tts_load_npc_voice_data(object $db, string $speaker): array
{
    if ($speaker === '' || !method_exists($db, 'fetchOne')) {
        return [];
    }

    $escaped = method_exists($db, 'escape') ? $db->escape($speaker) : str_replace("'", "''", $speaker);
    $row = $db->fetchOne("SELECT voiceid, extended_data FROM public.core_npc_master WHERE npc_name='{$escaped}' LIMIT 1");
    if (!is_array($row)) {
        return [];
    }

    $voiceData = [
        'voiceid' => trim(strval($row['voiceid'] ?? '')),
        'voice_name' => '',
        'voice_formid' => '',
    ];

    $extended = $row['extended_data'] ?? null;
    if (is_string($extended) && trim($extended) !== '') {
        $decoded = json_decode($extended, true);
        $extended = is_array($decoded) ? $decoded : [];
    }

    if (is_array($extended)) {
        $metadata = $extended['voice_metadata'] ?? [];
        if (is_array($metadata)) {
            $voiceData['voice_name'] = trim(strval($metadata['voice_name'] ?? ''));
            $voiceData['voice_formid'] = trim(strval($metadata['voice_formid'] ?? ''));
            if ($voiceData['voiceid'] === '') {
                $voiceData['voiceid'] = trim(strval($metadata['voiceid'] ?? $metadata['voice_id'] ?? ''));
            }
        }
    }

    if (dialectic_tts_is_temporary_silent_voice($voiceData['voiceid'], $voiceData['voice_name'])) {
        $voiceData['voiceid'] = '';
        $voiceData['voice_name'] = '';
        $voiceData['voice_formid'] = '';
    }

    if ($voiceData['voiceid'] === '') {
        $fallbackVoiceId = dialectic_tts_find_actor_voiceid_from_local_samples($speaker);
        if ($fallbackVoiceId !== '') {
            $voiceData['voiceid'] = $fallbackVoiceId;
        }
    }

    return $voiceData;
}

function dialectic_tts_generate_for_response(string $root, string $speaker, string $text): bool
{
    $text = trim($text);
    if ($text === '') {
        return false;
    }

    $phaseName = "npc_tts_direct:" . substr(md5($speaker . "|" . $text), 0, 8);
    Logger::phaseStart($phaseName, [
        "speaker" => $speaker,
        "chars" => strlen($text),
    ]);

    try {
        dialecticRuntimeBootstrapIfNeeded($root, [
            'load_tts_connector' => true,
            'run_db_updates' => false,
            'load_general_settings' => true,
        ]);

        require_once $root . DIRECTORY_SEPARATOR . "lib" . DIRECTORY_SEPARATOR . "chat_helper_functions.php";

        if (!isset($GLOBALS["TTS_FFMPEG_FILTERS"]) || !is_array($GLOBALS["TTS_FFMPEG_FILTERS"])) {
            $GLOBALS["TTS_FFMPEG_FILTERS"] = [];
        }

        $oldName = $GLOBALS["DIALECTIC_NAME"] ?? null;
        $oldVoice = $GLOBALS["PATCH_OVERRIDE_VOICE"] ?? null;
        $hadName = array_key_exists("DIALECTIC_NAME", $GLOBALS);
        $hadVoice = array_key_exists("PATCH_OVERRIDE_VOICE", $GLOBALS);

        $GLOBALS["DIALECTIC_NAME"] = $speaker;

        $cacheSeed = dialectic_tts_cache_seed($root, $speaker, $text);
        $cacheKey = md5($cacheSeed);
        $cachePath = $root . DIRECTORY_SEPARATOR . "soundcache" . DIRECTORY_SEPARATOR . $cacheKey . ".wav";
        $cacheReady = is_file($cachePath) && filesize($cachePath) > 44;

        if (!$cacheReady) {
            $db = $GLOBALS["db"] ?? null;
            if (is_object($db)) {
                $voiceData = dialectic_tts_load_npc_voice_data($db, $speaker);
                $voice = trim(strval($voiceData['voiceid'] ?? ''));
                if ($voice !== '') {
                    $GLOBALS["PATCH_OVERRIDE_VOICE"] = dialectic_resolve_tts_voice_reference($voice, [
                        'root' => $root,
                        'actor_name' => $speaker,
                        'voice_name' => $voiceData['voice_name'] ?? '',
                        'voice_formid' => $voiceData['voice_formid'] ?? '',
                    ]);
                }
            }

            $result = callConfiguredTts($text, "default", $cacheSeed);
        } else {
            $result = $cachePath;
        }

        if ($hadName) {
            $GLOBALS["DIALECTIC_NAME"] = $oldName;
        } else {
            unset($GLOBALS["DIALECTIC_NAME"]);
        }

        if ($hadVoice) {
            $GLOBALS["PATCH_OVERRIDE_VOICE"] = $oldVoice;
        } else {
            unset($GLOBALS["PATCH_OVERRIDE_VOICE"]);
        }

        $ok = is_file($cachePath) && filesize($cachePath) > 44;
        if (!$ok) {
            Logger::warn("[Dialectic TTS] TTS generation did not create expected WAV" . Logger::formatContext([
                "speaker" => $speaker,
                "result" => strval($result),
                "cache_path" => $cachePath,
            ]));
        }

        Logger::phaseEnd($phaseName, [
            "status" => $ok ? "ok" : "failed",
            "speaker" => $speaker,
            "connector" => $GLOBALS["TTSFUNCTION"] ?? "",
            "cache_ready_before" => $cacheReady ? "true" : "false",
            "cache_path" => $cachePath,
        ], $ok ? "info" : "warn");

        return $ok;
    } catch (Throwable $e) {
        Logger::error("[Dialectic TTS] TTS generation failed" . Logger::formatContext([
            "speaker" => $speaker,
            "error" => $e->getMessage(),
        ]));
        Logger::phaseEnd($phaseName, [
            "status" => "error",
            "speaker" => $speaker,
            "error" => $e->getMessage(),
        ], "error");
        return false;
    }
}

?>
