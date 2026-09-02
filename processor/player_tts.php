<?php

require_once(__DIR__ . DIRECTORY_SEPARATOR . ".." . DIRECTORY_SEPARATOR . "lib" . DIRECTORY_SEPARATOR . "player_tts_helpers.php");
require_once(__DIR__ . DIRECTORY_SEPARATOR . ".." . DIRECTORY_SEPARATOR . "lib" . DIRECTORY_SEPARATOR . "core" . DIRECTORY_SEPARATOR . "tts_filter_presets.php");

$cleaned_dialogue = dialecticExtractPlayerTtsDialogueLine($gameRequest[3] ?? '');

if (!function_exists('emitPlayerTextOnlySpeechLine')) {
    function emitPlayerTextOnlySpeechLine($line)
    {
        $subtitle = (string)$line;
        if (function_exists('formatPlayerSubtitleText')) {
            $subtitle = formatPlayerSubtitleText($subtitle, $GLOBALS["PLAYER_NAME"] ?? null);
        } else {
            $subtitle = str_replace(["\r", "\n", "|"], " ", $subtitle);
            $subtitle = trim(preg_replace('/\s+/', ' ', $subtitle));
        }

        if ($subtitle === '') {
            return;
        }

        dialectic_buffer_speech_response_line("Player", $subtitle, "", "__player_text_only", "", "", 1.0);
        $outputLine = json_encode([
            "speaker" => "Player",
            "action" => "say",
            "text" => $subtitle,
            "metadata" => [
                "listener" => "__player_text_only",
                "volume" => 1.0,
            ],
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL;
        $GLOBALS["DEBUG_DATA"]["OUTPUT_LOG"] = $outputLine;
        $outputToPluginLog = __DIR__ . DIRECTORY_SEPARATOR . ".." . DIRECTORY_SEPARATOR . "log" . DIRECTORY_SEPARATOR . "output_to_plugin.log";
        Logger::rotateLogIfTooLarge($outputToPluginLog);
        Logger::debug("[plugin-output] queued player text-only line" . Logger::formatContext([
            "chars" => strlen($subtitle),
        ]));
        @file_put_contents($outputToPluginLog, $outputLine, FILE_APPEND | LOCK_EX);
    }
}

if (!function_exists('generatePlayerTtsCacheOnly')) {
    function generatePlayerTtsCacheOnly($line)
    {
        $subtitle = function_exists('formatPlayerSubtitleText')
            ? formatPlayerSubtitleText($line, $GLOBALS["PLAYER_NAME"] ?? null)
            : trim(preg_replace('/\s+/', ' ', str_replace(["\r", "\n", "|"], " ", (string)$line)));

        if ($subtitle === '') {
            return false;
        }

        $cacheKey = function_exists('dialecticTtsCacheKeyForLine')
            ? dialecticTtsCacheKeyForLine("Player", $subtitle)
            : $subtitle;

        $ttsOutput = function_exists('callConfiguredTts')
            ? callConfiguredTts($line, "default", $cacheKey)
            : false;

        if ($ttsOutput) {
            $GLOBALS["TRACK"]["FILES_GENERATED"][] = $ttsOutput;
            Logger::info("[Player TTS] Generated cache-only player speech: {$ttsOutput}");
        } else {
            Logger::warn("[Player TTS] Cache-only player speech generation returned no output.");
        }

        if (function_exists('dialectic_tts_soundcache_path')) {
            $GLOBALS["PLAYER_TTS_LAST_CACHE_PATH"] = dialectic_tts_soundcache_path(dirname(__DIR__), "Player", $subtitle);
        }

        $expectedCachePath = trim(strval($GLOBALS["PLAYER_TTS_EXPECTED_CACHE_PATH"] ?? ''));
        $resolvedTtsOutput = is_string($ttsOutput) ? trim($ttsOutput) : '';
        if ($resolvedTtsOutput !== '' && !preg_match('/^[A-Za-z]:[\\\\\\/]/', $resolvedTtsOutput) && !str_starts_with($resolvedTtsOutput, DIRECTORY_SEPARATOR)) {
            $resolvedTtsOutput = dirname(__DIR__) . DIRECTORY_SEPARATOR . ltrim($resolvedTtsOutput, "\\/");
        }
        if ($expectedCachePath !== '' && $resolvedTtsOutput !== '' && is_file($resolvedTtsOutput) && filesize($resolvedTtsOutput) > 44) {
            $normalizedOutput = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $resolvedTtsOutput);
            $normalizedExpected = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $expectedCachePath);
            if (strcasecmp($normalizedOutput, $normalizedExpected) !== 0) {
                $expectedDir = dirname($expectedCachePath);
                if (!is_dir($expectedDir)) {
                    @mkdir($expectedDir, 0775, true);
                }
                if (@copy($resolvedTtsOutput, $expectedCachePath)) {
                    $GLOBALS["PLAYER_TTS_LAST_CACHE_PATH"] = $expectedCachePath;
                    Logger::info("[Player TTS] Copied generated player speech to expected cache path" . Logger::formatContext([
                        "generated" => $resolvedTtsOutput,
                        "expected" => $expectedCachePath,
                    ]));
                } else {
                    Logger::warn("[Player TTS] Could not copy generated player speech to expected cache path" . Logger::formatContext([
                        "generated" => $resolvedTtsOutput,
                        "expected" => $expectedCachePath,
                    ]));
                }
            }
        }

        return $ttsOutput;
    }
}

audit_log(__FILE__ . " " . __LINE__);
$playerTtsPhase = "player_tts:" . substr(md5((string)$cleaned_dialogue), 0, 8);
$playerTtsStatus = "started";
Logger::phaseStart($playerTtsPhase, [
    "chars" => strlen((string)$cleaned_dialogue),
    "write_output" => isset($GLOBALS["PLAYER_TTS_WRITE_OUTPUT"]) ? ((bool)$GLOBALS["PLAYER_TTS_WRITE_OUTPUT"] ? "true" : "false") : "default",
]);

if (!class_exists('Player')) {
    require_once(__DIR__ . DIRECTORY_SEPARATOR . ".." . DIRECTORY_SEPARATOR . "lib" . DIRECTORY_SEPARATOR . "core" . DIRECTORY_SEPARATOR . "player.class.php");
}
if (!class_exists('TTSConnector')) {
    require_once(__DIR__ . DIRECTORY_SEPARATOR . ".." . DIRECTORY_SEPARATOR . "lib" . DIRECTORY_SEPARATOR . "core" . DIRECTORY_SEPARATOR . "tts_connector.class.php");
}

$origTTS = $GLOBALS["TTSFUNCTION"] ?? '';
$hadTtsFunctionAlias = array_key_exists("TTS_FUNCTION", $GLOBALS);
$origTtsFunctionAlias = $GLOBALS["TTS_FUNCTION"] ?? null;
$hadCurrentTtsConnectorId = array_key_exists("DIALECTIC_CORE_CURRENT_TTS_CONNECTOR_ID", $GLOBALS);
$origCurrentTtsConnectorId = $GLOBALS["DIALECTIC_CORE_CURRENT_TTS_CONNECTOR_ID"] ?? null;
$origName = $GLOBALS["DIALECTIC_NAME"] ?? '';
$hadPatchOverrideVoice = array_key_exists("PATCH_OVERRIDE_VOICE", $GLOBALS);
$hadPatchOverrideVoiceId = array_key_exists("PATCH_OVERRIDE_VOICE_ID", $GLOBALS);
$hadPatchOverrideLanguage = array_key_exists("PATCH_OVERRIDE_TTS_LANGUAGE", $GLOBALS);
$hadPatchOverrideTtsOptions = array_key_exists("PATCH_OVERRIDE_TTS_OPTIONS", $GLOBALS);
$oldPatchOverrideVoice = $GLOBALS["PATCH_OVERRIDE_VOICE"] ?? null;
$oldPatchOverrideVoiceId = $GLOBALS["PATCH_OVERRIDE_VOICE_ID"] ?? null;
$oldPatchOverrideLanguage = $GLOBALS["PATCH_OVERRIDE_TTS_LANGUAGE"] ?? null;
$oldPatchOverrideTtsOptions = $GLOBALS["PATCH_OVERRIDE_TTS_OPTIONS"] ?? null;
$hadActiveTtsFilterPreset = array_key_exists('DIALECTIC_TTS_FILTER_PRESET_ID', $GLOBALS);
$oldActiveTtsFilterPreset = $GLOBALS['DIALECTIC_TTS_FILTER_PRESET_ID'] ?? null;

try {
    $player = new Player();
    $ttsConnector = new TTSConnector();
    $writeOutput = isset($GLOBALS["PLAYER_TTS_WRITE_OUTPUT"]) ? (bool)$GLOBALS["PLAYER_TTS_WRITE_OUTPUT"] : true;

    $connectorId = intval($player->get('tts_connector_id') ?? 0);
    $currentConnector = $connectorId > 0 ? $ttsConnector->getById($connectorId) : null;
    $hasPlayerTtsConnector = $currentConnector && strtolower(trim(strval($currentConnector['driver'] ?? 'none'))) !== 'none';
    dialecticSetActiveTtsFilterPreset($player->get('tts_filter_preset') ?? 'none');

    if ($hasPlayerTtsConnector) {
        $ttsConnector->setOldGlobals($currentConnector);
        $GLOBALS["TTSFUNCTION_PLAYER"] = strval($currentConnector['driver'] ?? '');
        $playerDriver = strtolower(trim(strval($currentConnector['driver'] ?? '')));
        $GLOBALS["TTSFUNCTION"] = $playerDriver;
        $GLOBALS["TTS_FUNCTION"] = $playerDriver;

        $playerVoiceId = trim(strval($player->get('tts_voice_override') ?? ''));
        $voiceIdOverride = trim(strval($player->get('tts_voice_id_override') ?? ''));
        $languageOverride = trim(strval($player->get('tts_language_override') ?? ''));

        $GLOBALS["TTSFUNCTION_PLAYER_VOICE"] = $playerVoiceId;
        $GLOBALS["TTSFUNCTION_PLAYER_VOICE_ID"] = $voiceIdOverride;
        $GLOBALS["TTSFUNCTION_PLAYER_LANGUAGE"] = $languageOverride;

        if ($playerVoiceId !== '') {
            $GLOBALS["PATCH_OVERRIDE_VOICE"] = $playerVoiceId;
        } else {
            unset($GLOBALS["PATCH_OVERRIDE_VOICE"]);
        }

        if ($voiceIdOverride !== '') {
            $GLOBALS["PATCH_OVERRIDE_VOICE_ID"] = $voiceIdOverride;
        } else {
            unset($GLOBALS["PATCH_OVERRIDE_VOICE_ID"]);
        }

        if ($languageOverride !== '') {
            $GLOBALS["PATCH_OVERRIDE_TTS_LANGUAGE"] = $languageOverride;
        } else {
            unset($GLOBALS["PATCH_OVERRIDE_TTS_LANGUAGE"]);
        }

        if ($playerDriver === '11labs') {
            $playerElevenLabsOverrides = [];
            foreach ([
                'model_id' => 'tts_elevenlabs_model_id',
                'speed' => 'tts_elevenlabs_speed',
                'stability' => 'tts_elevenlabs_stability',
                'similarity_boost' => 'tts_elevenlabs_similarity_boost',
                'style' => 'tts_elevenlabs_style',
                'v3_audio_tags' => 'tts_elevenlabs_v3_audio_tags',
            ] as $metadataKey => $playerKey) {
                $rawValue = trim(strval($player->get($playerKey) ?? ''));
                if ($rawValue !== '') {
                    $playerElevenLabsOverrides[$metadataKey] = $rawValue;
                }
            }

            $speakerBoostValue = trim(strval($player->get('tts_elevenlabs_use_speaker_boost') ?? ''));
            if ($speakerBoostValue !== '') {
                $playerElevenLabsOverrides['use_speaker_boost'] = strtolower($speakerBoostValue) === 'true';
            }

            if (!empty($playerElevenLabsOverrides)) {
                $GLOBALS["PATCH_OVERRIDE_TTS_OPTIONS"] = [
                    'driver' => $playerDriver,
                    'metadata' => $playerElevenLabsOverrides,
                ];
            } else {
                unset($GLOBALS["PATCH_OVERRIDE_TTS_OPTIONS"]);
            }
        } else {
            unset($GLOBALS["PATCH_OVERRIDE_TTS_OPTIONS"]);
        }
    }

    $GLOBALS["PATCH_DONT_STORE_SPEECH_ON_DB"] = true;
    $GLOBALS["DIALECTIC_NAME"] = "Player";

    if ($cleaned_dialogue === '') {
        Logger::info("[Player TTS] Skipping empty player dialogue after normalization.");
        $playerTtsStatus = "empty";
        return;
    }

    if ($hasPlayerTtsConnector) {
        Logger::info("[Player TTS] Generating playable player speech via {$currentConnector['driver']}.");
        $playerTtsStatus = "generating";
        $hadScriptlineListener = array_key_exists("SCRIPTLINE_LISTENER", $GLOBALS);
        $hadScriptlineListenerAtomic = array_key_exists("SCRIPTLINE_LISTENER_ATOMIC", $GLOBALS);
        $oldScriptlineListener = $GLOBALS["SCRIPTLINE_LISTENER"] ?? null;
        $oldScriptlineListenerAtomic = $GLOBALS["SCRIPTLINE_LISTENER_ATOMIC"] ?? null;

        try {
            if (trim(strval($GLOBALS["SCRIPTLINE_LISTENER"] ?? '')) === '') {
                $GLOBALS["SCRIPTLINE_LISTENER"] = "__player_tts";
            }
            if (trim(strval($GLOBALS["SCRIPTLINE_LISTENER_ATOMIC"] ?? '')) === '') {
                $GLOBALS["SCRIPTLINE_LISTENER_ATOMIC"] = "__player_tts";
            }

            if ($writeOutput) {
                $ownspeech = returnlines([$cleaned_dialogue], true);
            } else {
                generatePlayerTtsCacheOnly($cleaned_dialogue);
            }

            if ($writeOutput && function_exists('formatPlayerSubtitleText') && function_exists('dialectic_tts_soundcache_path')) {
                $subtitle = formatPlayerSubtitleText($cleaned_dialogue, $GLOBALS["PLAYER_NAME"] ?? null);
                $GLOBALS["PLAYER_TTS_LAST_CACHE_PATH"] = dialectic_tts_soundcache_path(dirname(__DIR__), "Player", $subtitle);
            }
        } finally {
            if ($hadScriptlineListener) {
                $GLOBALS["SCRIPTLINE_LISTENER"] = $oldScriptlineListener;
            } else {
                unset($GLOBALS["SCRIPTLINE_LISTENER"]);
            }
            if ($hadScriptlineListenerAtomic) {
                $GLOBALS["SCRIPTLINE_LISTENER_ATOMIC"] = $oldScriptlineListenerAtomic;
            } else {
                unset($GLOBALS["SCRIPTLINE_LISTENER_ATOMIC"]);
            }
        }
    } elseif ($writeOutput) {
        Logger::info("[Player TTS] No player TTS connector configured; emitting text-only player line.");
        emitPlayerTextOnlySpeechLine($cleaned_dialogue);
        $playerTtsStatus = "text_only";
    }

    if ($playerTtsStatus === "generating") {
        $playerTtsStatus = "ok";
    }
} finally {
    if ($hadPatchOverrideVoice) {
        $GLOBALS["PATCH_OVERRIDE_VOICE"] = $oldPatchOverrideVoice;
    } else {
        unset($GLOBALS["PATCH_OVERRIDE_VOICE"]);
    }

    if ($hadPatchOverrideVoiceId) {
        $GLOBALS["PATCH_OVERRIDE_VOICE_ID"] = $oldPatchOverrideVoiceId;
    } else {
        unset($GLOBALS["PATCH_OVERRIDE_VOICE_ID"]);
    }

    if ($hadPatchOverrideLanguage) {
        $GLOBALS["PATCH_OVERRIDE_TTS_LANGUAGE"] = $oldPatchOverrideLanguage;
    } else {
        unset($GLOBALS["PATCH_OVERRIDE_TTS_LANGUAGE"]);
    }

    if ($hadPatchOverrideTtsOptions) {
        $GLOBALS["PATCH_OVERRIDE_TTS_OPTIONS"] = $oldPatchOverrideTtsOptions;
    } else {
        unset($GLOBALS["PATCH_OVERRIDE_TTS_OPTIONS"]);
    }

    if ($hadActiveTtsFilterPreset) {
        dialecticSetActiveTtsFilterPreset($oldActiveTtsFilterPreset);
    } else {
        dialecticClearActiveTtsFilterPreset();
    }

    $GLOBALS["TTSFUNCTION"] = $origTTS;
    if ($hadTtsFunctionAlias) {
        $GLOBALS["TTS_FUNCTION"] = $origTtsFunctionAlias;
    } else {
        unset($GLOBALS["TTS_FUNCTION"]);
    }
    if ($hadCurrentTtsConnectorId) {
        $GLOBALS["DIALECTIC_CORE_CURRENT_TTS_CONNECTOR_ID"] = $origCurrentTtsConnectorId;
    } else {
        unset($GLOBALS["DIALECTIC_CORE_CURRENT_TTS_CONNECTOR_ID"]);
    }
    unset($GLOBALS["SCRIPTLINE_ANIMATION_SENT"]);
    $GLOBALS["DIALECTIC_NAME"] = $origName;
    unset($GLOBALS["PATCH_DONT_STORE_SPEECH_ON_DB"]);
    Logger::phaseEnd($playerTtsPhase, [
        "status" => $playerTtsStatus,
        "cache_path" => $GLOBALS["PLAYER_TTS_LAST_CACHE_PATH"] ?? "",
    ], $playerTtsStatus === "ok" || $playerTtsStatus === "text_only" || $playerTtsStatus === "empty" ? "info" : "warn");
}

audit_log(__FILE__ . " " . __LINE__);
$startTimeAfterPlayerTTTS = microtime(true);

?>
