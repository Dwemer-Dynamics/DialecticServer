<?php

ob_start();

$enginePath = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR;
$GLOBALS["ENGINE_PATH"] = $enginePath;

require_once($enginePath . "lib" . DIRECTORY_SEPARATOR . "runtime_bootstrap.php");
dialecticRuntimeBootstrap($enginePath, [
    'load_general_settings' => true,
    'load_stt_connector' => false,
    'load_tts_connector' => false,
    'load_player_name' => true,
    'load_narrator' => true,
]);

require_once($enginePath . "lib" . DIRECTORY_SEPARATOR . "logger.php");
require_once($enginePath . "lib" . DIRECTORY_SEPARATOR . "auditing.php");
require_once($enginePath . "lib" . DIRECTORY_SEPARATOR . "dialectic_tts.php");
require_once($enginePath . "lib" . DIRECTORY_SEPARATOR . "model_dynmodel.php");
require_once($enginePath . "lib" . DIRECTORY_SEPARATOR . "data_functions.php");
require_once($enginePath . "lib" . DIRECTORY_SEPARATOR . "chat_helper_functions.php");
require_once($enginePath . "lib" . DIRECTORY_SEPARATOR . "core" . DIRECTORY_SEPARATOR . "player.class.php");
require_once($enginePath . "lib" . DIRECTORY_SEPARATOR . "core" . DIRECTORY_SEPARATOR . "tts_connector.class.php");
require_once($enginePath . "prompt.includes.php");

function dialecticPlayerTtsPreviewRespond(array $payload, int $statusCode = 200): void
{
    while (ob_get_level() > 0) {
        ob_end_clean();
    }

    http_response_code($statusCode);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    $player = new Player();
    $ttsConnector = new TTSConnector();
    $connectorId = intval($player->get('tts_connector_id') ?? 0);
    $connector = $connectorId > 0 ? $ttsConnector->getById($connectorId) : null;

    if (!$connector || strtolower(trim(strval($connector['driver'] ?? 'none'))) === 'none') {
        dialecticPlayerTtsPreviewRespond([
            'status' => 'error',
            'message' => 'Player TTS is disabled. Select and save a Player TTS connector first.',
        ], 400);
    }

    $testText = 'Patrolling the Mojave almost makes you wish for a Nuclear Winter.';
    $playerName = trim(strval($GLOBALS["PLAYER_NAME"] ?? 'Player'));
    if ($playerName === '') {
        $playerName = 'Player';
    }

    $GLOBALS["gameRequest"] = [
        "player_tts_preview",
        strval(time()),
        strval(time()),
        $playerName . ": " . $testText,
    ];
    $gameRequest = &$GLOBALS["gameRequest"];
    $GLOBALS["PLAYER_TTS_WRITE_OUTPUT"] = false;
    $GLOBALS["AVOID_TTS_CACHE"] = false;
    $GLOBALS["TTS_FFMPEG_FILTERS"] = [];
    $GLOBALS["SCRIPTLINE_LISTENER"] = "";
    $GLOBALS["SCRIPTLINE_LISTENER_ATOMIC"] = "";
    $GLOBALS["SCRIPTLINE_EXPRESSION"] = "";
    $GLOBALS["SCRIPTLINE_ANIMATION"] = "";
    $GLOBALS["DIALECTIC_ANIMATIONS"] = false;
    $GLOBALS["DEBUG_DATA"] = [];
    $GLOBALS["TRACK"] = [];
    $GLOBALS["FEATURES"] = $GLOBALS["FEATURES"] ?? [];
    $GLOBALS["FEATURES"]["MISC"] = $GLOBALS["FEATURES"]["MISC"] ?? [];
    $GLOBALS["FEATURES"]["MISC"]["TTS_RANDOM_PITCH"] = false;
    $GLOBALS["PATCH_DONT_STORE_SPEECH_ON_DB"] = true;

    require($enginePath . "processor" . DIRECTORY_SEPARATOR . "player_tts.php");

    $generated = trim(strval($GLOBALS["TRACK"]["FILES_GENERATED"][0] ?? ''));
    if ($generated === '' || !file_exists($generated)) {
        $cachePath = trim(strval($GLOBALS["PLAYER_TTS_LAST_CACHE_PATH"] ?? ''));
        if ($cachePath !== '' && file_exists($cachePath)) {
            $generated = $cachePath;
        }
    }
    if ($generated === '' || !file_exists($generated)) {
        dialecticPlayerTtsPreviewRespond([
            'status' => 'error',
            'message' => 'Player TTS did not produce a WAV file.',
        ], 500);
    }

    $scriptPath = $_SERVER['SCRIPT_NAME'] ?? '';
    $uiPos = strpos($scriptPath, '/ui/');
    $webRoot = $uiPos !== false ? substr($scriptPath, 0, $uiPos) : '';
    if ($webRoot === '/') {
        $webRoot = '';
    }
    $webRoot = rtrim($webRoot, '/');

    dialecticPlayerTtsPreviewRespond([
        'status' => 'success',
        'text' => $testText,
        'url' => $webRoot . '/soundcache/' . rawurlencode(basename($generated)) . '?ts=' . filemtime($generated),
    ]);
} catch (Throwable $e) {
    dialecticPlayerTtsPreviewRespond([
        'status' => 'error',
        'message' => $e->getMessage(),
    ], 500);
}

?>
