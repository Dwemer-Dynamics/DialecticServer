<?php 
require_once(__DIR__ . '/../../../../lib/logger.php');
require_once(__DIR__ . '/../../../../lib/dialectic_command_payload.php');

if (!empty($GLOBALS["argv"][3])) {
    $speech=$GLOBALS["db"]->escape($GLOBALS["argv"][3]);
} else {
    Logger::error("No speech parameter provided for impersonation command");
    die("No speech");
}

Logger::info("Processing impersonation command with speech: " . $speech);

dialecticQueueCommandResponse(
    "rolemaster",
    "ImpersonatePlayer",
    ["speech" => $speech, "request_type" => "inputtext"]
);

Logger::info("Successfully logged impersonation command to responselog");
?>
