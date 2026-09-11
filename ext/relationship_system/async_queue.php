<?php
/**
 * RELATIONSHIP SYSTEM - Async Queue
 *
 * Implements deferred relationship evaluation to prevent blocking the main request.
 *
 * FLOW:
 * 1. postrequest.php queues evaluation data (non-blocking)
 * 2. A background worker processes queued evaluations and initializations
 * 3. Later prompts read the latest persisted relationship state
 *
 * Queue storage: Database table for persistence across requests
 */

// Ensure Logger is available
require_once $GLOBALS["ENGINE_PATH"] . "lib/logger.php";

/**
 * Start the detached relationship worker only when there is queued work.
 */
function _relEnsureWorkerRunning() {
    static $checked = false;
    if ($checked) return;
    $checked = true;

    $pidFile = $GLOBALS["ENGINE_PATH"] . 'log/relationship_worker.pid';
    $workerPath = __DIR__ . '/worker.php';
    $logPath = $GLOBALS["ENGINE_PATH"] . 'log/relationship_worker.log';

    Logger::trace("[REL-WORKER-START] Checking worker status...");

    if (file_exists($pidFile)) {
        $pid = trim(file_get_contents($pidFile));
        if (!empty($pid) && _relWorkerProcessRunning((int)$pid)) {
            return;
        }
        Logger::debug("[REL-WORKER-START] Stale PID file, process {$pid} not running, deleting");
        @unlink($pidFile);
    }

    if (!file_exists($logPath)) {
        @touch($logPath);
    } elseif (!is_writable($logPath)) {
        @unlink($logPath);
        @touch($logPath);
    }

    $logTarget = is_writable($logPath) ? $logPath : (DIRECTORY_SEPARATOR === '\\' ? 'NUL' : '/dev/null');
    $phpBinary = defined('PHP_BINARY') && PHP_BINARY !== '' ? PHP_BINARY : 'php';
    if (DIRECTORY_SEPARATOR === '\\') {
        $cmd = 'start "" /B ' . _relWorkerCmdQuote($phpBinary) . ' ' . _relWorkerCmdQuote($workerPath) . ' --daemon';
        $proc = @popen($cmd, 'r');
        if (is_resource($proc)) {
            pclose($proc);
        } else {
            Logger::error("[REL-WORKER-START] Failed to launch relationship worker");
            return;
        }
    } else {
        $cmd = escapeshellarg($phpBinary) . ' ' . escapeshellarg($workerPath) . ' --daemon';
        $descriptors = [
            0 => ['file', '/dev/null', 'r'],
            1 => ['file', $logTarget, 'a'],
            2 => ['file', $logTarget, 'a'],
        ];
        $proc = proc_open($cmd, $descriptors, $pipes, dirname($workerPath));
        if (!is_resource($proc)) {
            Logger::error("[REL-WORKER-START] Failed to launch relationship worker");
            return;
        }
        proc_close($proc);
    }

    Logger::info("[REL-WORKER-START] Worker daemon launch requested for queued work");
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

/**
 * Queue a relationship evaluation for async processing
 *
 * @param array $evalData Array containing:
 *   - npc_id: The NPC ID
 *   - npc_name: The NPC name
 *   - npc_response: What the NPC said
 *   - context: Additional context (events, player action, etc)
 *   - is_npc2npc: Whether this is NPC-to-NPC conversation
 *   - listener_npc_id: For NPC-to-NPC conversations
 *   - listener_name: For NPC-to-NPC conversations
 */
function _relQueueEvaluation($evalData) {
    if (!isset($GLOBALS['db']) || !$GLOBALS['db']) {
        Logger::warn("[REL-ASYNC] Cannot queue: no database connection");
        return false;
    }

    $npcId = $evalData['npc_id'] ?? 0;
    $npcName = $evalData['npc_name'] ?? 'Unknown';
    $listenerNpcId = $evalData['listener_npc_id'] ?? null;
    $listenerName = $evalData['listener_name'] ?? null;

    // Track whether this evaluation has player action (for smart upsert priority)
    $hasPlayerAction = !empty($evalData['has_player_action']);

    $queueData = [
        'npc_id' => $npcId,
        'npc_name' => $npcName,
        'dialogue' => $evalData['npc_response'] ?? '',
        'context' => $evalData['context'] ?? [],
        'is_npc2npc' => $evalData['is_npc2npc'] ?? false,
        'listener_npc_id' => $listenerNpcId,
        'listener_name' => $listenerName,
        'has_player_action' => $hasPlayerAction,
        'queued_at' => date('Y-m-d H:i:s')
    ];

    $jsonData = json_encode($queueData, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    // Use a simple key-value approach in a cache table or create our own
    // For simplicity, use the existing infrastructure
    try {
        $escapedJson = $GLOBALS['db']->escape($jsonData);
        $escapedNpcId = intval($npcId);
        $hasPlayerActionSql = $hasPlayerAction ? 'true' : 'false';

        // SMART UPSERT: Player action evaluations have priority over NPC-initiated ones
        // - If new request has player action: ALWAYS replace (player is interacting)
        // - If existing request has player action: DON'T replace (preserve player interaction)
        // - If neither has player action: Replace with new data (latest NPC-initiated)
        // This prevents NPC chatter from overwriting pending Player relationship evaluations
        $GLOBALS['db']->query(
            "INSERT INTO relationship_eval_queue (npc_id, eval_data, created_at)
             VALUES ({$escapedNpcId}, '{$escapedJson}', NOW())
             ON CONFLICT (npc_id) DO UPDATE SET
                eval_data = CASE
                    WHEN {$hasPlayerActionSql} THEN '{$escapedJson}'
                    WHEN (relationship_eval_queue.eval_data->>'has_player_action')::boolean IS NOT TRUE THEN '{$escapedJson}'
                    ELSE relationship_eval_queue.eval_data
                END,
                created_at = CASE
                    WHEN {$hasPlayerActionSql} THEN NOW()
                    WHEN (relationship_eval_queue.eval_data->>'has_player_action')::boolean IS NOT TRUE THEN NOW()
                    ELSE relationship_eval_queue.created_at
                END"
        );

        Logger::info("[REL-ASYNC] Queued evaluation for {$npcName} (NPC {$npcId})" .
                  ($listenerNpcId ? " + NPC-to-NPC with {$listenerName}" : ""));
        _relEnsureWorkerRunning();
        return true;

    } catch (Exception $e) {
        Logger::error("[REL-ASYNC] Failed to queue: " . $e->getMessage());
        return false;
    }
}

/**
 * Process all pending evaluations in the queue
 * Called by the detached relationship worker.
 *
 * @param int $limit Max number of evaluations to process (default 5)
 * @return array Results of processing
 */
function _relProcessQueue($limit = 5) {
    if (!isset($GLOBALS['db']) || !$GLOBALS['db']) {
        return ['processed' => 0, 'error' => 'no database'];
    }

    $results = ['processed' => 0, 'errors' => [], 'retried' => 0, 'abandoned' => 0];

    try {
        // Get pending evaluations (oldest first, prioritize items with fewer retries)
        $rows = $GLOBALS['db']->fetchAll(
            "SELECT id, npc_id, eval_data, COALESCE(retry_count, 0) as retry_count
             FROM relationship_eval_queue
             ORDER BY COALESCE(retry_count, 0) ASC, created_at ASC
             LIMIT {$limit}"
        );

        if (empty($rows)) {
            return $results;
        }

        require_once __DIR__ . "/relationship_llm.php";
        $relLLM = new RelationshipLLM();

        if (!$relLLM->isAvailable()) {
            Logger::warn("[REL-ASYNC] LLM not available, skipping queue processing");
            return ['processed' => 0, 'error' => 'LLM not available'];
        }

        $successIds = [];      // Fully processed - delete
        $retryIds = [];        // Failed but will retry - increment retry_count
        $abandonIds = [];      // Exceeded max retries - delete with warning

        // Track which NPCs we've already lazy-initialized this session
        static $lazyInitChecked = [];

        foreach ($rows as $row) {
            $data = json_decode($row['eval_data'], true);
            $retryCount = intval($row['retry_count']);

            if (!$data) {
                $successIds[] = $row['id']; // Invalid data, just delete
                continue;
            }

            try {
                // Lazy init for speaker NPC if not already done
                if (!isset($lazyInitChecked[$data['npc_id']])) {
                    $initResult = $relLLM->analyzeNpc($data['npc_id'], false);
                    if (!empty($initResult['ok']) && empty($initResult['skipped'])) {
                        Logger::info("[REL-ASYNC] Lazy-initialized {$data['npc_name']}");
                    }
                    $lazyInitChecked[$data['npc_id']] = true;
                }

                // Lazy init for listener NPC if applicable
                if (!empty($data['listener_npc_id']) && !isset($lazyInitChecked[$data['listener_npc_id']])) {
                    $initResult = $relLLM->analyzeNpc($data['listener_npc_id'], false);
                    if (!empty($initResult['ok']) && empty($initResult['skipped'])) {
                        Logger::info("[REL-ASYNC] Lazy-initialized {$data['listener_name']}");
                    }
                    $lazyInitChecked[$data['listener_npc_id']] = true;
                }

                // Check if this is NPC-to-NPC conversation
                $isNpcToNpc = !empty($data['is_npc2npc']) || !empty($data['listener_npc_id']);

                // Check if Player was involved in this conversation
                // Player is involved if:
                // 1. Player explicitly said/did something (player_action set), OR
                // 2. NPC was talking TO the Player (not NPC-to-NPC)
                // This ensures NPCs form opinions about the Player even for NPC-initiated greetings
                $playerInvolved = !empty($data['context']['player_action']) || !$isNpcToNpc;

                // Only evaluate NPC->Player if Player was involved in the conversation
                if ($playerInvolved && !$isNpcToNpc) {
                    $evalResult = $relLLM->evaluateContext(
                        $data['npc_id'],
                        $data['dialogue'],
                        $data['context']
                    );

                    if ($evalResult['ok'] && !empty($evalResult['changes'])) {
                        Logger::info("[REL-ASYNC] Processed {$data['npc_name']}: " .
                                  count($evalResult['changes']) . " changes");
                    }
                } else if ($isNpcToNpc) {
                    Logger::debug("[REL-ASYNC] Skipping Player eval for NPC-to-NPC: {$data['npc_name']} -> {$data['listener_name']}");
                }

                // NPC-to-NPC evaluation
                if ($isNpcToNpc) {
                    $npcToNpcResult = $relLLM->evaluateNpcToNpcContext(
                        $data['npc_id'],
                        $data['listener_npc_id'],
                        $data['dialogue'],
                        $data['context']
                    );

                    if ($npcToNpcResult['ok']) {
                        $changes = count($npcToNpcResult['speaker']['changes'] ?? []) +
                                   count($npcToNpcResult['listener']['changes'] ?? []);
                        if ($changes > 0) {
                            Logger::info("[REL-ASYNC] NPC-to-NPC {$data['npc_name']} <-> {$data['listener_name']}: {$changes} changes");
                        }
                    }
                }

                // Success! Delete from queue
                $successIds[] = $row['id'];
                $results['processed']++;

            } catch (Exception $e) {
                $errorMsg = $e->getMessage();
                $results['errors'][] = "NPC {$data['npc_id']}: " . $errorMsg;

                // RETRY LOGIC: Don't delete on first error!
                // Only delete after REL_QUEUE_MAX_RETRIES attempts
                $maxRetries = defined('REL_QUEUE_MAX_RETRIES') ? REL_QUEUE_MAX_RETRIES : 3;

                if ($retryCount >= $maxRetries) {
                    // Exceeded max retries - log critical event and abandon
                    Logger::error("[REL-ASYNC] ABANDONED after {$retryCount} retries: NPC {$data['npc_name']} - {$errorMsg}");
                    $abandonIds[] = $row['id'];
                    $results['abandoned']++;
                } else {
                    // Increment retry count for next attempt
                    $retryIds[] = ['id' => $row['id'], 'error' => substr($errorMsg, 0, 500)];
                    $results['retried']++;
                    Logger::warn("[REL-ASYNC] Retry {$retryCount}/" . $maxRetries . " for NPC {$data['npc_name']}: {$errorMsg}");
                }
            }
        }

        // Delete successfully processed entries
        if (!empty($successIds)) {
            $idList = implode(',', array_map('intval', $successIds));
            $GLOBALS['db']->query("DELETE FROM relationship_eval_queue WHERE id IN ({$idList})");
        }

        // Delete abandoned entries (exceeded max retries)
        if (!empty($abandonIds)) {
            $idList = implode(',', array_map('intval', $abandonIds));
            $GLOBALS['db']->query("DELETE FROM relationship_eval_queue WHERE id IN ({$idList})");
        }

        // Increment retry count for failed entries (will try again later)
        foreach ($retryIds as $retry) {
            $id = intval($retry['id']);
            $escapedError = $GLOBALS['db']->escape($retry['error']);
            $GLOBALS['db']->query(
                "UPDATE relationship_eval_queue
                 SET retry_count = COALESCE(retry_count, 0) + 1,
                     last_error = '{$escapedError}'
                 WHERE id = {$id}"
            );
        }

    } catch (Exception $e) {
        Logger::error("[REL-ASYNC] Queue processing error: " . $e->getMessage());
    }

    return $results;
}
// Maximum retries before deleting a failed queue item
define('REL_QUEUE_MAX_RETRIES', 3);

/**
 * Get queue status (for debugging)
 */
function _relGetQueueStatus() {
    try {
        $count = $GLOBALS['db']->fetchOne(
            "SELECT COUNT(*) as cnt FROM relationship_eval_queue"
        );
        return ['pending' => intval($count['cnt'] ?? 0)];
    } catch (Exception $e) {
        return ['pending' => 0, 'error' => $e->getMessage()];
    }
}

/**
 * Queue an NPC for relationship initialization (TEXT->JSONB parsing)
     * Called from profile import/update paths to avoid blocking map load
 *
 * @param int $npcId The NPC ID
 * @param string $npcName The NPC name
 */
function _relQueueNpcInit($npcId, $npcName) {
    if (!isset($GLOBALS['db']) || !$GLOBALS['db']) {
        return false;
    }

    $queueData = [
        'npc_id' => $npcId,
        'npc_name' => $npcName,
        'type' => 'init',  // Mark as init-only (no conversation to evaluate)
        'queued_at' => date('Y-m-d H:i:s')
    ];

    $jsonData = json_encode($queueData, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    try {
        $escapedJson = $GLOBALS['db']->escape($jsonData);
        $escapedNpcId = intval($npcId);

        // Use relationship_init_queue for init requests (separate from eval queue)
        $GLOBALS['db']->query(
            "INSERT INTO relationship_init_queue (npc_id, init_data, created_at)
             VALUES ({$escapedNpcId}, '{$escapedJson}', NOW())
             ON CONFLICT (npc_id) DO NOTHING"  // Don't replace - first request wins
        );

        _relEnsureWorkerRunning();
        return true;

    } catch (Exception $e) {
        Logger::error("[REL-ASYNC] Failed to queue initialization: " . $e->getMessage());
        return false;
    }
}

/**
 * Process pending NPC inits from queue
 * Called by the detached relationship worker.
 *
 * @param int $limit Max number to process per request
 */
function _relProcessInitQueue($limit = 5) {
    if (!isset($GLOBALS['db']) || !$GLOBALS['db']) {
        return ['processed' => 0];
    }

    $results = ['processed' => 0, 'retried' => 0, 'abandoned' => 0];

    try {
        $rows = $GLOBALS['db']->fetchAll(
            "SELECT id, npc_id, init_data, COALESCE(retry_count, 0) as retry_count
             FROM relationship_init_queue
             ORDER BY COALESCE(retry_count, 0) ASC, created_at ASC
             LIMIT {$limit}"
        );

        if (empty($rows)) {
            return $results;
        }

        require_once __DIR__ . "/relationship_llm.php";
        $relLLM = new RelationshipLLM();

        if (!$relLLM->isAvailable()) {
            return ['processed' => 0, 'error' => 'LLM not available'];
        }

        $successIds = [];
        $retryIds = [];
        $abandonIds = [];

        foreach ($rows as $row) {
            $data = json_decode($row['init_data'], true);
            $retryCount = intval($row['retry_count']);

            if (!$data) {
                $successIds[] = $row['id'];
                continue;
            }

            try {
                // Analyze available relationship source data into JSONB.
                $initResult = $relLLM->analyzeNpc($data['npc_id'], false);
                if (!empty($initResult['ok']) && empty($initResult['skipped'])) {
                    Logger::info("[REL-ASYNC] Initialized relationships for {$data['npc_name']}");
                }
                $successIds[] = $row['id'];
                $results['processed']++;
            } catch (Exception $e) {
                $errorMsg = $e->getMessage();
                $maxRetries = defined('REL_QUEUE_MAX_RETRIES') ? REL_QUEUE_MAX_RETRIES : 3;

                if ($retryCount >= $maxRetries) {
                    Logger::error("[REL-ASYNC] ABANDONED init after {$retryCount} retries: {$data['npc_name']} - {$errorMsg}");
                    $abandonIds[] = $row['id'];
                    $results['abandoned']++;
                } else {
                    $retryIds[] = ['id' => $row['id'], 'error' => substr($errorMsg, 0, 500)];
                    $results['retried']++;
                    Logger::warn("[REL-ASYNC] Init retry {$retryCount}/" . $maxRetries . " for {$data['npc_name']}: {$errorMsg}");
                }
            }
        }

        if (!empty($successIds)) {
            $idList = implode(',', array_map('intval', $successIds));
            $GLOBALS['db']->query("DELETE FROM relationship_init_queue WHERE id IN ({$idList})");
        }

        if (!empty($abandonIds)) {
            $idList = implode(',', array_map('intval', $abandonIds));
            $GLOBALS['db']->query("DELETE FROM relationship_init_queue WHERE id IN ({$idList})");
        }

        foreach ($retryIds as $retry) {
            $id = intval($retry['id']);
            $escapedError = $GLOBALS['db']->escape($retry['error']);
            $GLOBALS['db']->query(
                "UPDATE relationship_init_queue
                 SET retry_count = COALESCE(retry_count, 0) + 1,
                     last_error = '{$escapedError}'
                 WHERE id = {$id}"
            );
        }

    } catch (Exception $e) {
        Logger::error("[REL-ASYNC] Init queue error: " . $e->getMessage());
    }

    return $results;
}
