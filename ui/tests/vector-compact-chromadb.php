<?php

require_once(__DIR__.DIRECTORY_SEPARATOR."../profile_loader.php");

$TITLE = "DIALECTIC - TTS Test - DIALECTIC Server";

ob_start();

include("../tmpl/head.html");

$debugPaneLink = false;
include("../tmpl/navbar.php");

// Add styles for command output
echo <<<HTML
<style>
pre.command-output {
 background-color: #2c2c2c; /* Site background color */
 border: 1px solid #444; /* Darker border to complement the dark background */
 padding: 15px;
 border-radius: 5px;
 white-space: pre-wrap; /* CSS3 - wrap lines */
 word-wrap: break-word; /* Internet Explorer 5.5+ */
 font-family: monospace;
 font-size: 0.9em;
 color: #ffffff; /* White text color */
}
</style>
HTML;

$startTime = microtime(true);

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

$embedding = $FEATURES["MEMORY_EMBEDDING"]["TEXT2VEC_PROVIDER"];

//Run the Compact Command
$memoryUtility = escapeshellarg($enginePath . "debug" . DIRECTORY_SEPARATOR . "util_memory_subsystem.php");
$phpBinary = escapeshellarg(PHP_BINARY ?: "php");
$commandcompact = "{$phpBinary} {$memoryUtility} compact";
$commandcompact = shell_exec($commandcompact);
echo '<link rel="stylesheet" type="text/css" href="../css/main.css">';
echo "<title> DIALECTIC - Compact Memories</title>";

echo '<div style="padding-top: 80px; padding-left: 20px; padding-right: 20px;">';

echo "<h1>Compact Memories</h1>";
echo "<pre class='command-output'>$commandcompact</pre>";

//Run the Sync Command
$commandsync = "{$phpBinary} {$memoryUtility} resync";
$outputsync = shell_exec($commandsync);
 echo "<br>";
 echo "<h1>Memory Sync for TXT2VEC</h1>";
 echo "<pre class='command-output'>$outputsync</pre>";


echo '</div>';
?>
