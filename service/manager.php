<?php 

@ob_end_clean();

$GLOBALS["ENGINE_ROOT"] = __DIR__.DIRECTORY_SEPARATOR."..".DIRECTORY_SEPARATOR;

error_reporting(E_ALL);
echo "[MANAGER] START".PHP_EOL;


require_once("{$GLOBALS["ENGINE_ROOT"]}/service/lib/core_utils.php");
require_once("{$GLOBALS["ENGINE_ROOT"]}/lib/runtime_bootstrap.php");
dialecticRuntimeBootstrap($GLOBALS["ENGINE_ROOT"], [
    'load_general_settings' => true,
    'load_stt_connector' => false,
    'load_player_name' => true,
    'load_narrator' => true,
]);
require_once("{$GLOBALS["ENGINE_ROOT"]}/lib/logger.php");

if (isset($argv) && is_array($argv)) {
    $GLOBALS["argv"] = $argv;
}

Logger::setCustomLog($GLOBALS["ENGINE_ROOT"]."log/manager.log");
Logger::deleteLogIfTooLarge($GLOBALS["ENGINE_ROOT"]."log/manager.log");

Logger::info("[SERVICE MANAGER] Run started / ".date("Y-m-d H:i:s"));

requireFilesRecursivelyByPattern($GLOBALS["ENGINE_ROOT"]."/service/processors/", '/^entrypoint\.php$/');

// Helper function to execute task in forked process
function executeTaskAsync($taskname, $task) {
    if (!function_exists('pcntl_fork')) {
        echo "[SYNC-$taskname] pcntl_fork unavailable; running task inline".PHP_EOL;
        Logger::info("pcntl_fork unavailable; running task inline: $taskname");
        unset($GLOBALS["db"]);
        try {
            $task["fn"]();
        } catch (Throwable $e) {
            Logger::error("Error while executing task $taskname inline: " . $e->getMessage());
            echo "[SYNC-$taskname] Error: " . $e->getMessage() . PHP_EOL;
            return -1;
        }
        Logger::info("Completed inline task execution: $taskname");
        echo "[SYNC-$taskname] Task completed".PHP_EOL;
        return 0;
    }

    $pid = pcntl_fork();
    
    if ($pid == -1) {
        // Fork failed
        Logger::error("Failed to fork process for task: $taskname");
        echo "Failed to fork process for task $taskname ".PHP_EOL;
        return -1;
    } else if ($pid == 0) {
        // Child process - execute the task
        echo "[CHILD-$taskname] Starting task execution".PHP_EOL;
        
        unset($GLOBALS["db"]); // Ensure child process has its own DB connection

        Logger::info("Starting task execution in child process: $taskname (PID: " . posix_getpid() . ")");
        try {
            $task["fn"]();
        } catch (Throwable $e) {
            Logger::error("Error while executing task $taskname: " . $e->getMessage());
            echo "[CHILD-$taskname] Error: " . $e->getMessage() . PHP_EOL;
            exit(1);
        }
        Logger::info("Completed task execution in child process: $taskname");
        echo "[CHILD-$taskname] Task completed".PHP_EOL;
        exit(0); // Exit child process
    } else {
        // Parent process - return immediately
        echo "[PARENT] Forked task $taskname with PID: $pid".PHP_EOL;
        Logger::info("Forked task $taskname with PID: $pid");
        return $pid;
    }
}

$exitCode = 0;
if (isset($argv[1])) {
    $taskname=$argv[1];
    Logger::debug("Attempting to run task: $taskname");
    if (isset($GLOBALS["TASKS"][$taskname])) {
        $task=$GLOBALS["TASKS"][$taskname];
        echo "Running task $taskname asynchronously".PHP_EOL;
        Logger::info("Starting async task execution: $taskname");
        $pid = executeTaskAsync($taskname, $task);
        if ($pid > 0) {
            Logger::info("Task forked: $taskname");
            echo "Task forked $taskname ".PHP_EOL;
            // Explicit callers must receive the worker's result before draining its commands.
            $waited = pcntl_waitpid($pid, $status);
            $exitCode = ($waited === $pid && pcntl_wifexited($status))
                ? pcntl_wexitstatus($status)
                : 1;
            Logger::info("Child process $taskname (PID: $pid) exited with status: $exitCode");
        } elseif ($pid < 0) {
            $exitCode = 1;
        } else {
            Logger::info("Task completed inline: $taskname");
            echo "Task completed inline $taskname ".PHP_EOL;
        }
    } else {
        Logger::error("Task not found: $taskname");
        echo "Task not found $taskname ".PHP_EOL;
        $exitCode = 1;
    }

} else {
    Logger::debug("No specific task requested, running all tasks asynchronously");
    $child_pids = [];
    foreach ($GLOBALS["TASKS"] as $taskname=>$task)  {
        if (!empty($task["explicit_only"])) {
            Logger::debug("Skipping explicit-only task during scheduled run: $taskname");
            continue;
        }
        echo "[MANAGER] Forking task $taskname ".PHP_EOL;
        Logger::info("Forking task: $taskname");
        $pid = executeTaskAsync($taskname, $task);
        if ($pid > 0) {
            $child_pids[$taskname] = $pid;
        }
    }
    
    // Wait for all child processes to complete
    echo "[MANAGER] Waiting for all child processes to complete...".PHP_EOL;
    Logger::info("Waiting for " . count($child_pids) . " child processes");
    foreach ($child_pids as $taskname => $pid) {
        pcntl_waitpid($pid, $status);
        if (pcntl_wifexited($status)) {
            Logger::info("Child process $taskname (PID: $pid) exited with status: " . pcntl_wexitstatus($status));
            echo "[MANAGER] Task $taskname completed".PHP_EOL;
        }
    }

}
echo "[MANAGER] END".PHP_EOL;
exit($exitCode);
?>
