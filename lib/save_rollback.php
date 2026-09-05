<?php

require_once(__DIR__ . DIRECTORY_SEPARATOR . "utils_game_timestamp.php");
require_once(__DIR__ . DIRECTORY_SEPARATOR . "playthrough_autosave.php");
require_once(__DIR__ . DIRECTORY_SEPARATOR . "logger.php");

if (!function_exists('dialecticRollbackNormalizeGamets')) {
    function dialecticRollbackNormalizeGamets($gamets): int
    {
        $value = intval($gamets);
        return $value > 0 ? $value : 0;
    }
}

if (!function_exists('dialecticRollbackTableExists')) {
    function dialecticRollbackTableExists(string $table): bool
    {
        static $cache = [];
        if (isset($cache[$table])) {
            return $cache[$table];
        }

        if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $table)) {
            $cache[$table] = false;
            return false;
        }

        try {
            $db = $GLOBALS["db"] ?? null;
            if (!$db) {
                $cache[$table] = false;
                return false;
            }

            $escaped = $db->escape($table);
            $rows = $db->fetchAll("SELECT 1 FROM information_schema.tables WHERE table_schema='public' AND table_name='{$escaped}' LIMIT 1");
            $cache[$table] = is_array($rows) && count($rows) > 0;
            return $cache[$table];
        } catch (Throwable $e) {
            Logger::warn("[SAVE_ROLLBACK] Table existence check failed for {$table}: " . $e->getMessage());
            $cache[$table] = false;
            return false;
        }
    }
}

if (!function_exists('dialecticRollbackDelete')) {
    function dialecticRollbackDelete(string $table, string $where, array &$stats): void
    {
        if (!dialecticRollbackTableExists($table)) {
            return;
        }

        $db = $GLOBALS["db"] ?? null;
        if (!$db) {
            return;
        }

        $count = 0;
        try {
            $rows = $db->fetchAll("SELECT COUNT(*) AS n FROM {$table} WHERE {$where}");
            if (is_array($rows) && isset($rows[0]["n"])) {
                $count = intval($rows[0]["n"]);
            }
        } catch (Throwable $e) {
            Logger::warn("[SAVE_ROLLBACK] Count failed for {$table}: " . $e->getMessage());
        }

        if ($count <= 0) {
            $stats[$table] = ($stats[$table] ?? 0);
            return;
        }

        try {
            if ($db->delete($table, $where)) {
                $stats[$table] = ($stats[$table] ?? 0) + $count;
            }
        } catch (Throwable $e) {
            Logger::warn("[SAVE_ROLLBACK] Delete failed for {$table}: " . $e->getMessage());
        }
    }
}

if (!function_exists('dialecticRollbackClearConfOpt')) {
    function dialecticRollbackClearConfOpt(string $id, array &$stats): void
    {
        if (!dialecticRollbackTableExists('conf_opts')) {
            return;
        }

        $db = $GLOBALS["db"] ?? null;
        if (!$db) {
            return;
        }

        $escaped = $db->escape($id);
        dialecticRollbackDelete('conf_opts', "id='{$escaped}'", $stats);
    }
}

if (!function_exists('dialecticRollbackPruneFutureData')) {
    function dialecticRollbackPruneFutureData(int $targetGamets, string $source = 'unknown', bool $force = false): array
    {
        $targetGamets = dialecticRollbackNormalizeGamets($targetGamets);
        if ($targetGamets <= 0) {
            return [
                'rolled_back' => false,
                'reason' => 'missing_gamets',
                'target_gamets' => $targetGamets,
            ];
        }

        $previousMaxGamets = function_exists('DataLastKnownGameTS') ? intval(DataLastKnownGameTS()) : 0;
        if (!$force && ($previousMaxGamets <= 0 || $targetGamets >= $previousMaxGamets)) {
            return [
                'rolled_back' => false,
                'reason' => 'not_older',
                'previous_max_gamets' => $previousMaxGamets,
                'target_gamets' => $targetGamets,
            ];
        }

        $stats = [];
        $playthroughId = 0;
        try {
            $playthroughId = function_exists('timeline_break_playthrough_if_needed')
                ? intval(timeline_break_playthrough_if_needed($previousMaxGamets, $targetGamets))
                : 0;
        } catch (Throwable $e) {
            Logger::warn("[SAVE_ROLLBACK] Timeline Break playthrough failed: " . $e->getMessage());
        }

        foreach ([
            'eventlog',
            'speech',
            'diarylog',
            'actions_issued',
            'moods_issued',
            'quests',
        ] as $table) {
            dialecticRollbackDelete($table, "gamets>={$targetGamets}", $stats);
        }

        dialecticRollbackDelete('memory_summary', "gamets_truncated>{$targetGamets}", $stats);
        dialecticRollbackDelete('memory', "gamets>{$targetGamets}", $stats);

        dialecticRollbackDelete('responselog', "1=1", $stats);
        dialecticRollbackDelete('rolemaster', "1=1", $stats);
        dialecticRollbackDelete('relationship_eval_queue', "1=1", $stats);
        dialecticRollbackDelete('relationship_init_queue', "1=1", $stats);

        dialecticRollbackClearConfOpt('COMBAT_BARK_LAST_TIMESTAMP', $stats);
        dialecticRollbackClearConfOpt('last_narrator_welcome', $stats);

        Logger::info("[SAVE_ROLLBACK] Pruned future Dialectic data" . Logger::formatContext([
            'source' => $source,
            'previous_max_gamets' => $previousMaxGamets,
            'target_gamets' => $targetGamets,
            'playthrough_id' => $playthroughId,
            'deleted' => $stats,
        ]));

        return [
            'rolled_back' => true,
            'previous_max_gamets' => $previousMaxGamets,
            'target_gamets' => $targetGamets,
            'playthrough_id' => $playthroughId,
            'deleted' => $stats,
        ];
    }
}

if (!function_exists('dialecticMaybeHandleIncomingGametsRollback')) {
    function dialecticMaybeHandleIncomingGametsRollback($incomingGamets, string $source = 'unknown', bool $force = false): array
    {
        $targetGamets = dialecticRollbackNormalizeGamets($incomingGamets);
        if ($targetGamets <= 0) {
            return ['rolled_back' => false, 'reason' => 'missing_gamets'];
        }

        if ($force) {
            return dialecticRollbackPruneFutureData($targetGamets, $source, true);
        }

        $previousMaxGamets = function_exists('DataLastKnownGameTS') ? intval(DataLastKnownGameTS()) : 0;
        if ($previousMaxGamets <= 0) {
            return [
                'rolled_back' => false,
                'reason' => 'empty_history',
                'target_gamets' => $targetGamets,
            ];
        }

        // Passive guards should not treat small out-of-order telemetry as a loaded save.
        $rollbackThreshold = intval($GLOBALS["DIALECTIC_ROLLBACK_GAMETS_THRESHOLD"] ?? 1000000);
        if ($targetGamets >= ($previousMaxGamets - max(1, $rollbackThreshold))) {
            return [
                'rolled_back' => false,
                'reason' => 'within_threshold',
                'previous_max_gamets' => $previousMaxGamets,
                'target_gamets' => $targetGamets,
            ];
        }

        return dialecticRollbackPruneFutureData($targetGamets, $source, false);
    }
}

?>
