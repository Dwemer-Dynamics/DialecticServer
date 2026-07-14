<?php
if (!class_exists('DialecticTestRequestTerminated')) {
    final class DialecticTestRequestTerminated extends RuntimeException
    {
    }
}

function aiff_audit_end() {
    $endTime = microtime(true);
    $startTime = $GLOBALS["AUDIT_START_TIME"];
    $elapsedTime = $endTime - $startTime;

    if ($elapsedTime>1)
        Logger::trace("Audit {$GLOBALS["AUDIT_RUNID"]}, {$GLOBALS["AUDIT_RUNID_REQUEST"]}, elapsed time: " . $elapsedTime . " seconds");
}


function audit_log($fromFile='') {
    $endTime = microtime(true);
    $startTime = $GLOBALS["AUDIT_START_TIME"];
    $elapsedTime = $endTime - $startTime;

    
    Logger::trace("Audit {$GLOBALS["AUDIT_RUNID"]}, {$GLOBALS["AUDIT_RUNID_REQUEST"]}, $fromFile, elapsed time: " . $elapsedTime . " seconds");
}

function terminate() {
    if (function_exists('dialectic_buffer_response_close')) {
        dialectic_buffer_response_close();
    }

    if (!getenv("PHPUNIT_TEST")) {
        if (function_exists('dialectic_emit_buffered_json_response')) {
            dialectic_emit_buffered_json_response();
        }
        @flush();
    }
    
    $i_level = error_reporting(0);
    try {
        // Release any acquired semaphores
    foreach (["MAIN", "VSX"] as $semaphore_id) {
            if (isset($GLOBALS["SEMAPHORES"][$semaphore_id]) && $GLOBALS["SEMAPHORES"][$semaphore_id]) {
                @SemaphoreManager::release($semaphore_id);
            }
        }
    } finally {
        error_reporting($i_level);
    }

    if (getenv("PHPUNIT_TEST")) {
        throw new DialecticTestRequestTerminated();
    }

    die();
}


function close() {
    if (function_exists('dialectic_buffer_response_close')) {
        dialectic_buffer_response_close();
    }

    if (!getenv("PHPUNIT_TEST")) {
        if (function_exists('dialectic_emit_buffered_json_response')) {
            dialectic_emit_buffered_json_response();
        }
        @flush();
    }    
}

$GLOBALS["AUDIT_RUNID"] = strrev(uniqid("di_",true));
$GLOBALS["AUDIT_START_TIME"] = microtime(true);

register_shutdown_function('aiff_audit_end');

?>
