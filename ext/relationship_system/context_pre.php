<?php
/**
 * RELATIONSHIP SYSTEM - Context Injection
 *
 * This file is automatically loaded by main.php at line 1792:
 *   requireFilesRecursively(__DIR__."/ext/","context.php");
 *
 * It injects relationship context into the AI prompt.
 *
 * ASYNC QUEUE PROCESSING:
 * Before injecting context, we process any pending relationship evaluations
 * that were queued by the PREVIOUS request. This ensures relationship data
 * is up-to-date before building the prompt, without blocking the response.
 *
 * TWO MODES:
 * 1. RELLLM_CONNECTOR set: Token-efficient mode
 *    - Only injects tier labels (Fond, Wary, etc.)
 *    - NO #REL: command instructions (RelationshipLLM handles scoring)
 *
 * 2. RELLLM_CONNECTOR not set: Full mode
 *    - Injects numbers and tiers
 *    - Adds #REL: command instructions for conversation model
 */

$relationshipContextStartTime = $GLOBALS["startTime"] ?? microtime(true);
error_log("TRACE:\t".__LINE__. "\t".__FILE__.":\t".(microtime(true) - $relationshipContextStartTime));

require_once $GLOBALS["ENGINE_PATH"] . "lib/relationship_runtime.php";

// Master toggle - if disabled, skip everything in this file
if (!dialecticRelationshipSettingEnabled()) {
    return;
}

require_once $GLOBALS["ENGINE_PATH"] . "lib/logger.php";

// AUTO-START WORKER DAEMON
// When RELLLM_CONNECTOR is set, ensure the background worker is running
// Uses proc_open for proper detachment - no cron, no sudo, no permissions needed
$useRelLLM = dialecticRelationshipUsesDedicatedConnector();
if ($useRelLLM) {
    _relEnsureWorkerRunning();
}

error_log("TRACE:\t".__LINE__. "\t".__FILE__.":\t".(microtime(true) - $relationshipContextStartTime));

/**
 * Ensure the relationship worker daemon is running
 * Spawns it in background if not already active - instant, non-blocking
 * Uses proc_open for proper process detachment (Apache can't kill it)
 */
function _relEnsureWorkerRunning() {
    static $checked = false;
    if ($checked) return; // Only check once per PHP request
    $checked = true;

    $pidFile = $GLOBALS["ENGINE_PATH"] . 'log/relationship_worker.pid';
    $workerPath = __DIR__ . '/worker.php';
    $logPath = $GLOBALS["ENGINE_PATH"] . 'log/relationship_worker.log';

    Logger::trace("[REL-WORKER-START] Checking worker status...");

    // Check PID file first (fast path)
    if (file_exists($pidFile)) {
        $pid = trim(file_get_contents($pidFile));
        Logger::trace("[REL-WORKER-START] PID file exists with PID: {$pid}");
        if (!empty($pid) && _relWorkerProcessRunning((int)$pid)) {
            Logger::trace("[REL-WORKER-START] Worker already running at PID {$pid}");
            return; // Worker is running
        }
        // Stale PID file - worker died, clean up
        Logger::debug("[REL-WORKER-START] Stale PID file, process {$pid} not running, deleting");
        @unlink($pidFile);
    }

    Logger::info("[REL-WORKER-START] No worker running, spawning new one...");

    // Worker not running - spawn it using proc_open for full detachment
    // This creates a process that survives Apache request termination

    // Ensure log file exists and is writable
    if (!file_exists($logPath)) {
        @touch($logPath);
        Logger::debug("[REL-WORKER-START] Created log file: {$logPath}");
    } elseif (!is_writable($logPath)) {
        @unlink($logPath);
        @touch($logPath);
        Logger::debug("[REL-WORKER-START] Recreated log file (was not writable)");
    }

    $logTarget = is_writable($logPath) ? $logPath : (DIRECTORY_SEPARATOR === '\\' ? 'NUL' : '/dev/null');
    Logger::debug("[REL-WORKER-START] Log target: {$logTarget}");

    $phpBinary = defined('PHP_BINARY') && PHP_BINARY !== '' ? PHP_BINARY : 'php';
    if (DIRECTORY_SEPARATOR === '\\') {
        $cmd = 'start "" /B ' . _relWorkerCmdQuote($phpBinary) . ' ' . _relWorkerCmdQuote($workerPath) . ' --daemon';
        Logger::debug("[REL-WORKER-START] Windows command: {$cmd}");
        $proc = @popen($cmd, 'r');
        if (is_resource($proc)) {
            pclose($proc);
        } else {
            Logger::error("[REL-WORKER-START] popen FAILED!");
            return;
        }
    } else {
        $cmd = escapeshellarg($phpBinary) . ' ' . escapeshellarg($workerPath) . ' --daemon';
        Logger::debug("[REL-WORKER-START] Command: {$cmd}");

        $descriptors = [
            0 => ['file', '/dev/null', 'r'],
            1 => ['file', $logTarget, 'a'],
            2 => ['file', $logTarget, 'a'],
        ];

        $proc = proc_open($cmd, $descriptors, $pipes, dirname($workerPath));
        if (is_resource($proc)) {
            Logger::debug("[REL-WORKER-START] proc_open succeeded, closing handle");
            proc_close($proc);
        } else {
            Logger::error("[REL-WORKER-START] proc_open FAILED!");
            return;
        }
    }

    // Do not wait/verify in the prompt hot path. The worker is best-effort
    // background maintenance; response latency should not depend on tasklist
    // checks or PID-file creation timing.
    Logger::info("[REL-WORKER-START] Worker daemon launch requested");
}

function _relWorkerProcessRunning(int $pid): bool {
    if ($pid <= 0) {
        return false;
    }

    if (DIRECTORY_SEPARATOR === '\\') {
        $output = [];
        @exec('tasklist /FI "PID eq ' . $pid . '" /FO CSV /NH', $output);
        foreach ($output as $line) {
            if (preg_match('/^"[^"]+","' . preg_quote((string)$pid, '/') . '"/', trim($line)) === 1) {
                return true;
            }
        }
        return false;
    }

    if (function_exists('posix_kill')) {
        return @posix_kill($pid, 0);
    }

    return file_exists("/proc/{$pid}");
}

function _relWorkerCmdQuote(string $value): string {
    return '"' . str_replace('"', '\"', $value) . '"';
}

if (!function_exists('_relSplitPeopleList')) {
    function _relSplitPeopleList($people): array {
        $raw = trim(strval($people));
        if ($raw === '') {
            return [];
        }

        $parts = preg_split('/[|,]+/', $raw);
        $names = [];
        foreach ($parts as $part) {
            $name = trim($part);
            if ($name !== '' && strcasecmp($name, 'The Narrator') !== 0) {
                $names[strtolower($name)] = $name;
            }
        }

        return array_values($names);
    }
}

// Get the current NPC name
$npcName = $GLOBALS["DIALECTIC_NAME"] ?? null;

Logger::info("[REL-CONTEXT] npcName=" . ($npcName ?? 'NULL') . ", CACHE_PEOPLE=" . substr($GLOBALS["CACHE_PEOPLE"] ?? 'NULL', 0, 100));

if ($npcName && strcasecmp(trim((string)$npcName), 'The Narrator') !== 0) {
    // Parse nearby NPCs from CACHE_PEOPLE
    $nearbyNpcs = [];
    if (!empty($GLOBALS["CACHE_PEOPLE"])) {
        // Dialectic usually stores nearby people as |Name|Name|, but older paths may use commas.
        $nearbyNpcs = _relSplitPeopleList($GLOBALS["CACHE_PEOPLE"]);
    }

    // Also include NPCs mentioned in recent dialogue
    // This ensures relationships are shown for NPCs being discussed, not just physically present
    $mentionedNpcs = [];
    if (!empty($GLOBALS["DIALECTIC_CONTEXT"])) {
        // Get this NPC's known relationships to check for mentions
        $knownRels = RelationshipManager::getRelationships($npcName);
        $knownNames = array_keys($knownRels);

        // Scan recent context for mentions of known NPCs
        $contextLower = strtolower($GLOBALS["DIALECTIC_CONTEXT"]);
        foreach ($knownNames as $knownNpc) {
            if ($knownNpc === 'Player') continue; // Player always included
            if (stripos($contextLower, strtolower($knownNpc)) !== false) {
                $mentionedNpcs[] = $knownNpc;
            }
        }
    }

    // Merge nearby + mentioned, remove duplicates
    $relevantNpcs = array_values(array_filter(
        array_unique(array_merge($nearbyNpcs, $mentionedNpcs)),
        static function ($name) use ($npcName) {
            $name = trim((string)$name);
            return $name !== ''
                && strcasecmp($name, (string)$npcName) !== 0
                && strcasecmp($name, 'The Narrator') !== 0;
        }
    ));

    // Build the relationship context block
    // This automatically uses tier-only mode if RELLLM_CONNECTOR is set
    $relationshipContext = RelationshipManager::buildContext($npcName, $relevantNpcs);

    Logger::debug("[REL-CONTEXT] buildContext returned " . strlen($relationshipContext) . " chars for " . $npcName);

    // Inject into the character section of the prompt
    // We append to DIALECTIC_PERS which gets included in the <character> block
    if (!empty($relationshipContext)) {
        $GLOBALS["DIALECTIC_PERS"] .= "\n\n" . $relationshipContext;
        Logger::debug("[REL-CONTEXT] Injected " . strlen($relationshipContext) . " chars for {$npcName}");
    } else {
        Logger::warn("[REL-CONTEXT] No context to inject for {$npcName}");
    }

    // Only add #REL: command instructions if NOT using dedicated RelationshipLLM
    // When RELLLM_CONNECTOR is set, the relationship model handles all scoring
    // and the conversation model doesn't need to embed commands
    $useRelLLM = dialecticRelationshipUsesDedicatedConnector();

    if (!$useRelLLM) {
        // Add the relationship system instructions to COMMAND_PROMPT
        // This teaches the AI how to use #REL: and #TYPE: commands
        $relationshipInstructions = RelationshipManager::getSystemPromptAddition();
        if (!empty($GLOBALS["COMMAND_PROMPT"])) {
            $GLOBALS["COMMAND_PROMPT"] .= "\n\n" . $relationshipInstructions;
        }
    }
}

error_log("TRACE:\t".__LINE__. "\t".__FILE__.":\t".(microtime(true) - $GLOBALS["startTime"]));

?>
