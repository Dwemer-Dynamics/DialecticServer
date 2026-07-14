<?php

require_once(__DIR__.DIRECTORY_SEPARATOR."../profile_loader.php");

$TITLE = "DIALECTIC - TTS Test - DIALECTIC Server";

ob_start();

include("../tmpl/head.html");

$debugPaneLink = false;
include("../tmpl/navbar.php");

$enginePath = dirname(__FILE__) . DIRECTORY_SEPARATOR . ".." . DIRECTORY_SEPARATOR . ".." . DIRECTORY_SEPARATOR;

require_once($enginePath . "lib" . DIRECTORY_SEPARATOR . "runtime_bootstrap.php");
dialecticRuntimeBootstrapIfNeeded($enginePath, [
    'load_general_settings' => true,
    'load_stt_connector' => false,
    'load_tts_connector' => false,
    'load_player_name' => true,
    'load_narrator' => true,
]);
require_once($enginePath . "lib" . DIRECTORY_SEPARATOR . "model_dynmodel.php");
require_once($enginePath . "lib" . DIRECTORY_SEPARATOR . "data_functions.php");
require_once($enginePath . "lib" . DIRECTORY_SEPARATOR . "chat_helper_functions.php");

error_reporting(E_ALL);

echo '<link rel="stylesheet" type="text/css" href="../css/main.css">';
echo '<div style="padding-top: 80px; padding-left: 20px; padding-right: 20px;">';

try {
 $GLOBALS["db"]->execQuery("DELETE FROM public.memory_summary");
 echo "<h1>All entries in the memory summary table have been deleted successfully.</h1>";
} catch (Throwable $e) {
 echo "<h1>Error deleting entries from memory summary: " . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8') . "</h1>";
}
echo '</div>';
?>
