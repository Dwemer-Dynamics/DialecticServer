<?php
require_once __DIR__ . '/lib/dialectic_interaction.php';
require_once __DIR__ . '/lib/request.php';
$interactionRequest = dialectic_decode_json_body();
if (isset($interactionRequest['interaction_generation'])) {
    $GLOBALS['dialectic_interaction_generation'] = (int)$interactionRequest['interaction_generation'];
}
if (!empty($interactionRequest['interaction_passive'])) $_SERVER['HTTP_X_DIALECTIC_PASSIVE'] = '1';
if (dialecticInteractionIsTrigger(strval($interactionRequest['type'] ?? ''))) dialecticInteractionRequire();

require_once __DIR__ . "/lib/playthrough_guard.php";
pgr_http_preflight("main");

error_reporting(E_ALL);
ini_set("display_errors", "0");

$root = __DIR__;

require_once $root . DIRECTORY_SEPARATOR . "lib" . DIRECTORY_SEPARATOR . "logger.php";
require_once $root . DIRECTORY_SEPARATOR . "lib" . DIRECTORY_SEPARATOR . "background_processor.php";

Logger::bootstrapRequestId("main");
Logger::rotateKnownLogs($root);

// Game traffic is the authoritative self-healing path. This keeps automatic
// memories running even when the user never opens the Dialectic home page.
if (function_exists('dialecticEnsureBackgroundProcessorRunning')) {
    dialecticEnsureBackgroundProcessorRunning(true);
}

try {
    require $root . DIRECTORY_SEPARATOR . "main_dialectic_pipeline.php";
} catch (DialecticTestRequestTerminated $e) {
    if (!getenv("PHPUNIT_TEST")) {
        throw $e;
    }
}

?>
