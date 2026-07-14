<?php

/* Voice Sample Extractor */

$path = dirname((__FILE__)) . DIRECTORY_SEPARATOR;
$GLOBALS["ENGINE_PATH"]=$path;

require_once $path . "lib/runtime_bootstrap.php";
dialecticRuntimeBootstrap($path, [
    'load_general_settings' => true,
    'load_stt_connector' => false,
    'load_tts_connector' => 'pockettts',
    'load_player_name' => true,
]);
require_once $path . "lib/utils.php";
require_once $path . "lib/fuz_convert.php"; // API KEY must be there
require_once $path . "lib/auditing.php";
require_once $path . "lib/logger.php";
require_once $path . "lib/voice_clone_sync.php";

$db = $GLOBALS["db"] ?? new sql();
$GLOBALS["db"] = $db;

require_once $path . "lib/core/npc_master.class.php";
require_once $path . "lib/core/api_badge.class.php";
require_once $path . "lib/core/core_profiles.class.php";
require_once $path . "lib/core/llm_connector.class.php";
require_once $path . "lib/core/tts_connector.class.php";
require_once $path . "lib/semaphore_manager.class.php";

function dialecticVsxRespond(int $statusCode, bool $ok, string $message = '', array $extra = []): void
{
    if (!headers_sent()) {
        http_response_code($statusCode);
        header('Content-Type: application/json; charset=utf-8');
    }

    $payload = array_merge([
        'schema' => 'dialectic.voice_sample.response.v1',
        'request_id' => class_exists('Logger') ? Logger::getRequestId() : '',
        'ok' => $ok,
    ], $extra);

    if ($message !== '') {
        $payload[$ok ? 'message' : 'error'] = $message;
    }

    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL;
    exit;
}

function dialecticVsxDecodeMetadata(): array
{
    $method = strtoupper(strval($_SERVER['REQUEST_METHOD'] ?? 'GET'));
    if ($method !== 'POST') {
        dialecticVsxRespond(405, false, 'Method Not Allowed', [
            'method' => $method,
        ]);
    }

    $rawMetadata = trim(strval($_POST['metadata'] ?? ''));
    if ($rawMetadata === '') {
        dialecticVsxRespond(400, false, 'Missing voice sample metadata');
    }

    $metadata = json_decode($rawMetadata, true);
    if (!is_array($metadata)) {
        dialecticVsxRespond(400, false, 'Invalid voice sample metadata JSON');
    }

    $schema = trim(strval($metadata['schema'] ?? ''));
    if ($schema !== 'dialectic.voice_sample.v1') {
        dialecticVsxRespond(400, false, 'Unsupported voice sample metadata schema', [
            'schema' => $schema,
        ]);
    }

    $actorName = trim(strval($metadata['actor_name'] ?? ''));
    $sourcePath = trim(strval($metadata['original_name'] ?? ''));
    if ($actorName === '' || $sourcePath === '') {
        dialecticVsxRespond(400, false, 'Voice sample metadata requires actor_name and original_name');
    }

    return [
        'actor_name' => $actorName,
        'original_name' => $sourcePath,
        'reference_text' => trim(strval($metadata['reference_text'] ?? '')),
        'game' => trim(strval($metadata['game'] ?? 'fnv')),
    ];
}

function normalize_endpoint_url($url)
{
    // Remove trailing slashes
    $url = rtrim($url, '/');
    return $url;
}

function dialecticVsxResolveCloneTtsRuntime(string $actorName): array
{
    $ttsConnector = new TTSConnector();
    $supportedCloneDrivers = ['xtts-fastapi', 'chatterbox', 'pockettts'];

    $fallbackDriver = $ttsConnector->normalizeDriverValue($GLOBALS["TTSFUNCTION"] ?? 'pockettts');
    if ($fallbackDriver === '') {
        $fallbackDriver = 'pockettts';
    }

    $selectedDriver = $fallbackDriver;
    $profileData = null;

    if ($actorName !== '') {
        $profile = new CoreProfile();

        if (strcasecmp($actorName, 'The Narrator') === 0) {
            require_once $GLOBALS["ENGINE_PATH"] . "lib/core/narrator.class.php";
            $narrator = new Narrator();
            $profileId = intval($narrator->getProfileId() ?? 0);
            if ($profileId > 0) {
                $profileData = $profile->getById($profileId);
            }
        } else {
            $npcMaster = new NpcMaster();
            $currentNpcData = $npcMaster->getByName($actorName);
            if ($currentNpcData) {
                $profileId = intval($currentNpcData['profile_id'] ?? 0);
                if ($profileId > 0) {
                    $profileData = $profile->getById($profileId);
                } else {
                    $profileData = $profile->getDefaultNpc();
                }
            }
        }

        if ($profileData) {
            $profileConnectorRow = $ttsConnector->ensureConnectorForProfile($profileData);
            $profileDriver = $ttsConnector->normalizeDriverValue($profileConnectorRow['driver'] ?? '');
            if ($profileConnectorRow && in_array($profileDriver, $supportedCloneDrivers, true)) {
                $GLOBALS["DIALECTIC_CORE_CURRENT_PROFILE_DATA"] = $profileData;
                $profile->setOldGlobals($profileData);
                $selectedDriver = $profileDriver;
            } elseif ($profileConnectorRow) {
                Logger::info("[vsx] Actor '{$actorName}' uses non-clone TTS driver '{$profileDriver}', falling back to {$fallbackDriver}");
            }
        }
    }

    $providerKey = $ttsConnector->getProviderKeyFromDriver($selectedDriver);
    $providerConfig = ($providerKey !== '' && isset($GLOBALS["TTS"][$providerKey]) && is_array($GLOBALS["TTS"][$providerKey]))
        ? $GLOBALS["TTS"][$providerKey]
        : [];

    $endpoint = trim(strval($providerConfig['endpoint'] ?? $providerConfig['url'] ?? $providerConfig['URL'] ?? ''));
    if ($endpoint === '' && $selectedDriver !== $fallbackDriver) {
        $fallbackProviderKey = $ttsConnector->getProviderKeyFromDriver($fallbackDriver);
        $fallbackConfig = ($fallbackProviderKey !== '' && isset($GLOBALS["TTS"][$fallbackProviderKey]) && is_array($GLOBALS["TTS"][$fallbackProviderKey]))
            ? $GLOBALS["TTS"][$fallbackProviderKey]
            : [];
        $endpoint = trim(strval($fallbackConfig['endpoint'] ?? $fallbackConfig['url'] ?? $fallbackConfig['URL'] ?? ''));
        $providerKey = $fallbackProviderKey;
        $providerConfig = $fallbackConfig;
        $selectedDriver = $fallbackDriver;
    }

    $voicelogic = trim(strval($providerConfig['voicelogic'] ?? ''));
    if ($voicelogic === '') {
        $voicelogic = 'voicetype';
    }

    return [
        'driver' => $selectedDriver,
        'provider_key' => $providerKey,
        'endpoint' => ($endpoint !== '') ? normalize_endpoint_url($endpoint) : '',
        'voicelogic' => $voicelogic,
    ];
}

$GLOBALS["AUDIT_RUNID_REQUEST"] = "vsx";

// Put info into DB asap
$voiceSampleMetadata = dialecticVsxDecodeMetadata();
$actorName = $voiceSampleMetadata['actor_name'];
$sourcePath = $voiceSampleMetadata['original_name'];

$vsxTtsRuntime = dialecticVsxResolveCloneTtsRuntime($actorName);
$voicelogic = $vsxTtsRuntime['voicelogic'];
$ttsEndpoint = $vsxTtsRuntime['endpoint'];
Logger::info("[vsx] Using clone driver '{$vsxTtsRuntime['driver']}' for actor '{$actorName}' with endpoint '{$ttsEndpoint}'");

// Lock
$semaphore_timeout = $GLOBALS["SEMAPHORES_TIMEOUT"] ?? 300;
if (!SemaphoreWait("VSX", $semaphore_timeout, 47, null)) {
    Logger::warn("[vsx] semaphore wait failed in " . __FILE__ . " " . __LINE__);
    terminate();
}

$sourceParts = preg_split('/[\\\\\\/]+/', $sourcePath);
$sourceVoiceId = "";
if (is_array($sourceParts) && count($sourceParts) >= 4) {
    $sourceVoiceId = strtolower(trim((string)$sourceParts[3]));
}
$codename = $sourceVoiceId !== "" ? $sourceVoiceId : npcNameToCodename($actorName);

$npcMaster      = new NpcMaster();
$currentNpcData = $npcMaster->getByName($actorName);
if ($currentNpcData) {
    if (empty($currentNpcData["voiceid"]) && $codename !== "") {
        $currentNpcData["voiceid"] = $codename;
    }

    $extended = $npcMaster->getExtendedData($currentNpcData);
    unset($extended["voice_refresh_requested_at"]);
    $extended["voice_refresh_last_result"] = "sample_uploaded";
    $extended["voice_refresh_last_resolved_at"] = time();
    $extended["voice_sample_source"] = $sourcePath;
    $currentNpcData = $npcMaster->setExtendedData($currentNpcData, $extended);
    $currentNpcData = $npcMaster->updateByArray($currentNpcData);
}

// Release lock, this is the time consuming part, we have the needed data into the database

audit_log("vsx.php data available for $codename");

SemaphoreManager::release("VSX");

$ext = strtolower(pathinfo(str_replace('\\', '/', $sourcePath), PATHINFO_EXTENSION));
if (!in_array($ext, ['fuz', 'xwm', 'wav', 'ogg'], true)) {
    dialecticVsxRespond(400, false, 'Unsupported voice sample extension', [
        'extension' => $ext,
    ]);
}

if (empty($_FILES["file"]["tmp_name"]) || !is_file($_FILES["file"]["tmp_name"])) {
    dialecticVsxRespond(400, false, 'No voice sample uploaded');
}

$already   = ($ttsEndpoint !== '') ? file_exists($ttsEndpoint . "/sample/$codename.wav") : false;
$finalName = __DIR__ . DIRECTORY_SEPARATOR . "soundcache/_vsx_" . md5($_FILES["file"]["tmp_name"]) . ".$ext";
@copy($_FILES["file"]["tmp_name"], $finalName);

if (! $already) {

    if (file_exists($path . "data/voices/$codename.wav")) {
        // File exists in data/voices. Do not convert again.
        $finalFile = $path . "data/voices/$codename.wav";

    } else {

        if (filesize($_FILES["file"]["tmp_name"]) == 0) {
            Logger::error("Empty file {$_FILES["file"]["tmp_name"]}");
            dialecticVsxRespond(400, false, 'Uploaded voice sample was empty');
        }

        Logger::info("Received sample: {$sourcePath}");

        if ($ext === "fuz") {
            $finalFile = fuzToWav($finalName);

        } else if ($ext === "xwm") {

            $finalFile = xwmToWav($finalName);

        } else if ($ext === "wav") {

            $finalFile = wavToWav($finalName);
        } else if ($ext === "ogg") {

            $finalFile = oggToWav($finalName);
        }
    }
    if ($ttsEndpoint === '') {
        dialecticVsxRespond(500, false, 'No clone TTS endpoint configured');
    }

} else {
    Logger::info("Empty file {$_FILES["file"]["tmp_name"]} already exists at {$ttsEndpoint}/sample/$codename.wav");

}

if ($already) {
    dialecticVsxRespond(200, true, 'Voice sample already available', [
        'codename' => $codename,
        'already_available' => true,
    ]);
}

if (empty($finalFile) || !file_exists($finalFile) || filesize($finalFile) <= 0) {
    Logger::error("[vsx] Failed to create converted voice sample for {$codename} from {$sourcePath}");
    dialecticVsxRespond(500, false, 'Voice sample conversion failed', [
        'codename' => $codename,
    ]);
}

// Lets store voice files
$voiceCacheFile = $path . "data/voices/$codename.wav";
$finalRealPath = realpath($finalFile);
$cacheRealPath = realpath($voiceCacheFile);
$cacheAlreadyIsFinalFile = $finalRealPath !== false
    && $cacheRealPath !== false
    && $finalRealPath === $cacheRealPath;
$cacheCopyOk = $cacheAlreadyIsFinalFile || @copy($finalFile, $voiceCacheFile);
if (!$cacheCopyOk || !file_exists($voiceCacheFile) || filesize($voiceCacheFile) <= 0) {
    Logger::error("[vsx] Failed to copy converted voice sample to {$voiceCacheFile}");
    dialecticVsxRespond(500, false, 'Voice sample cache copy failed', [
        'codename' => $codename,
    ]);
}

$url  = $ttsEndpoint . '/upload_sample';
$curl = curl_init();

// Set cURL options
curl_setopt_array($curl, [
    CURLOPT_URL            => $url,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => [
        'wavFile' => new CURLFile($finalFile, 'audio/wav', "$codename.wav"),
    ],
    CURLOPT_HTTPHEADER     => [
        'Content-Type: multipart/form-data',
    ],
]);

// Execute cURL request and get response
$response = curl_exec($curl);
dialectic_sync_voice_clone_sample($codename, $finalFile, [
    'root' => $path,
    'actor_name' => $actorName,
    'driver' => $vsxTtsRuntime['driver'] ?? '',
]);

audit_log("vsx.php voice available for {$actorName}");
dialecticVsxRespond(200, true, 'Voice sample uploaded', [
    'codename' => $codename,
    'driver' => $vsxTtsRuntime['driver'] ?? '',
]);
