<?php 

header("Cache-Control: no-cache, no-store, must-revalidate"); // HTTP 1.1
header("Pragma: no-cache"); // HTTP 1.0
header("Expires: 0"); // Proxies

error_reporting(E_ERROR);
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!function_exists('pg_connect')) {
    require_once(__DIR__ . DIRECTORY_SEPARATOR . "dialectic_setup.php");
    exit;
}

ob_start();

$url = 'core/config_hub.php';
$rootPath = __DIR__.DIRECTORY_SEPARATOR."..".DIRECTORY_SEPARATOR;
require_once($rootPath . "lib" .DIRECTORY_SEPARATOR."runtime_bootstrap.php");
dialecticRuntimeBootstrapIfNeeded($rootPath, [
    'load_general_settings' => true,
    'load_stt_connector' => false,
    'load_tts_connector' => false,
    'load_player_name' => true,
    'load_narrator' => true,
]);

require_once($rootPath . "lib" .DIRECTORY_SEPARATOR."model_dynmodel.php");

require_once(__DIR__.DIRECTORY_SEPARATOR."..".DIRECTORY_SEPARATOR."conf".DIRECTORY_SEPARATOR.'conf_loader.php');

dialecticRuntimeApplyBootstrapOptions($rootPath, [
    'load_general_settings' => true,
    'load_stt_connector' => false,
    'load_tts_connector' => false,
    'load_player_name' => true,
    'load_narrator' => true,
]);

// Initialize automatic backup system (after profiles are loaded)
require_once($rootPath . "lib" . DIRECTORY_SEPARATOR . "automatic_backup.php");
require_once($rootPath . "lib" . DIRECTORY_SEPARATOR . "log_trim.php");
