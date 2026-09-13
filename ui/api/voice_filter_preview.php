<?php

ob_start();
session_start();

$enginePath = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR;
$GLOBALS['ENGINE_PATH'] = $enginePath;

require_once($enginePath . 'lib' . DIRECTORY_SEPARATOR . 'runtime_bootstrap.php');
dialecticRuntimeBootstrap($enginePath, [
    'load_general_settings' => true,
    'load_stt_connector' => false,
    'load_tts_connector' => false,
    'load_player_name' => true,
    'load_narrator' => true,
]);
require_once($enginePath . 'lib' . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'tts_filter_presets.php');
require_once($enginePath . 'lib' . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'tts_connector.class.php');
require_once($enginePath . 'lib' . DIRECTORY_SEPARATOR . 'chat_helper_functions.php');

function dialecticVoiceFilterPreviewRespond(array $payload, int $status = 200): void
{
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
    dialecticVoiceFilterPreviewRespond(['ok' => true], 204);
}
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    dialecticVoiceFilterPreviewRespond(['ok' => false, 'error' => 'Use POST to generate a voice preview.'], 405);
}

$now = time();
$recent = array_values(array_filter(
    is_array($_SESSION['dialectic_voice_filter_previews'] ?? null) ? $_SESSION['dialectic_voice_filter_previews'] : [],
    static fn($timestamp) => intval($timestamp) >= $now - 30
));
if (count($recent) >= 8) {
    dialecticVoiceFilterPreviewRespond(['ok' => false, 'error' => 'Too many voice previews. Wait a moment and try again.'], 429);
}
$recent[] = $now;
$_SESSION['dialectic_voice_filter_previews'] = $recent;
session_write_close();

$scope = strtolower(trim(strval($_POST['scope'] ?? 'npc')));
if (!in_array($scope, ['npc', 'narrator', 'player'], true)) {
    dialecticVoiceFilterPreviewRespond(['ok' => false, 'error' => 'That voice preview scope is not available.'], 400);
}
$presetRaw = $_POST['filter'] ?? ($_POST['tts_filter_preset'] ?? 'none');
$presetId = strtolower(trim(strval($presetRaw)));
$catalog = dialecticTtsFilterPresetOptions(true);
if (!isset($catalog[$presetId])) {
    dialecticVoiceFilterPreviewRespond(['ok' => false, 'error' => 'That voice filter is not available.'], 400);
}

$voice = trim(strval($_POST['voice'] ?? ($_POST['voiceid'] ?? '')));
$profileId = intval($_POST['profile_id'] ?? 0);
$connectorId = intval($_POST['connector_id'] ?? ($_POST['tts_connector_id'] ?? 0));
if ($scope !== 'player') {
    if ($profileId <= 0 || $voice === '') {
        dialecticVoiceFilterPreviewRespond(['ok' => false, 'error' => 'Choose a profile and enter a Voice ID before playing a preview.'], 400);
    }
    $profile = $GLOBALS['db']->fetchOne('SELECT tts_connector_id FROM core_profiles WHERE id = ' . $profileId . ' LIMIT 1');
    $connectorId = intval(is_array($profile) ? ($profile['tts_connector_id'] ?? 0) : 0);
}
if ($connectorId <= 0) {
    dialecticVoiceFilterPreviewRespond(['ok' => false, 'error' => 'Choose a TTS connector before playing a preview.'], 400);
}

$ttsConnector = new TTSConnector();
$connector = $ttsConnector->getById($connectorId);
if (!is_array($connector) || strtolower(trim(strval($connector['driver'] ?? 'none'))) === 'none') {
    dialecticVoiceFilterPreviewRespond(['ok' => false, 'error' => 'The selected TTS connector is unavailable.'], 404);
}

try {
    $ttsConnector->setOldGlobals($connector);
    $GLOBALS['TTSFUNCTION'] = strtolower(trim(strval($connector['driver'] ?? '')));
    $GLOBALS['TTS_FUNCTION'] = $GLOBALS['TTSFUNCTION'];
    $GLOBALS['TTS_FFMPEG_FILTERS'] = [];
    $GLOBALS['DIALECTIC_NAME'] = 'Voice Filter Preview';
    $GLOBALS['PATCH_DONT_STORE_SPEECH_ON_DB'] = true;
    if ($voice !== '') {
        $GLOBALS['PATCH_OVERRIDE_VOICE'] = $voice;
    } else {
        unset($GLOBALS['PATCH_OVERRIDE_VOICE']);
    }
    unset($GLOBALS['PATCH_OVERRIDE_VOICE_ID'], $GLOBALS['PATCH_OVERRIDE_TTS_LANGUAGE']);
    dialecticSetActiveTtsFilterPreset($presetId);

    $sample = 'Patrolling the Mojave almost makes you wish for a nuclear winter, but a steady voice still carries across the desert.';
    $cacheSeed = $sample . '|dialectic-voice-filter-preview|' . bin2hex(random_bytes(8));
    $output = callConfiguredTts($sample, 'default', $cacheSeed);
    $audioPath = is_string($output) ? trim($output) : '';
    if ($audioPath !== '' && !preg_match('/^[A-Za-z]:[\\\\\/]/', $audioPath) && !str_starts_with($audioPath, DIRECTORY_SEPARATOR)) {
        $audioPath = $enginePath . ltrim($audioPath, '\\/');
    }
    if ($audioPath === '' || !is_file($audioPath) || filesize($audioPath) <= 44) {
        dialecticVoiceFilterPreviewRespond(['ok' => false, 'error' => 'Voice preview could not be generated with these settings.'], 500);
    }

    $scriptPath = strval($_SERVER['SCRIPT_NAME'] ?? '');
    $uiPosition = strpos($scriptPath, '/ui/');
    $webRoot = $uiPosition !== false ? substr($scriptPath, 0, $uiPosition) : '';
    if ($webRoot === '/') {
        $webRoot = '';
    }
    dialecticVoiceFilterPreviewRespond([
        'ok' => true,
        'audio_url' => rtrim($webRoot, '/') . '/soundcache/' . rawurlencode(basename($audioPath)) . '?ts=' . filemtime($audioPath),
    ]);
} catch (Throwable $exception) {
    if (class_exists('Logger')) {
        Logger::error('[Voice Filter Preview] ' . $exception->getMessage());
    }
    dialecticVoiceFilterPreviewRespond(['ok' => false, 'error' => 'Voice preview could not be generated with these settings.'], 500);
}
