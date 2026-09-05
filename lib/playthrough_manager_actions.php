<?php
require_once __DIR__ . '/playthrough_schema.php';

function dpt_query($conn, string $sql, array $params = [])
{
    $result = @pg_query_params($conn, $sql, $params);
    if (!$result) {
        Logger::error('Storage snapshot query failed: ' . pg_last_error($conn));
        throw new RuntimeException('Playthrough operation failed. Your previous data has been kept.');
    }
    return $result;
}

// Capture data and its metadata in the caller's transaction, including refreshes before a switch.
function dpt_capture($conn, string $name, string $notes, ?array $existing = null, bool $active = false): void
{
    $schema = $existing['schema_name'] ?? pts_sanitize_profile_name($name);
    if (!str_starts_with($schema, 'dialectic_profile_')) throw new RuntimeException('Invalid playthrough schema.');
    if (!$existing && pts_schema_exists($conn, $schema)) throw new RuntimeException('A playthrough with this name already exists.');
    $clone = pts_clone_schema($conn, 'public', $schema);
    if (empty($clone['success'])) throw new RuntimeException('Could not save the current data. No switch was made.');
    $eventCount = 0; $knowledgeCount = 0; $gamets = 0;
    if (pg_fetch_result(dpt_query($conn, "SELECT to_regclass('public.eventlog') IS NOT NULL"), 0, 0) === 't') {
        $events = pg_fetch_assoc(dpt_query($conn, 'SELECT count(*) AS count, max(gamets) AS gamets FROM public.eventlog'));
        $eventCount = (int)$events['count']; $gamets = (int)$events['gamets'];
    }
    if (pg_fetch_result(dpt_query($conn, "SELECT to_regclass('public.worldknowledge') IS NOT NULL"), 0, 0) === 't') {
        $knowledgeCount = (int)pg_fetch_result(dpt_query($conn, 'SELECT count(*) FROM public.worldknowledge'), 0, 0);
    }
    $values = [$name, pts_get_schema_size($conn, $schema), $notes, $active ? 'true' : 'false',
        (string)($GLOBALS['PLAYER_NAME'] ?? 'Unknown'), $eventCount, $knowledgeCount, $gamets, $schema];
    if ($existing) {
        $values[] = (int)$existing['id'];
        dpt_query($conn, 'UPDATE dialectic_meta.playthrough_profiles SET name=$1,size_bytes=$2,notes=$3,is_active=$4,
            player_name=$5,eventlog_count=$6,worldknowledge_count=$7,last_gamets=$8,schema_name=$9 WHERE id=$10', $values);
    } else {
        dpt_query($conn, "INSERT INTO dialectic_meta.playthrough_profiles(name,size_bytes,notes,is_active,player_name,
            eventlog_count,worldknowledge_count,last_gamets,schema_name,storage_type,game)
            VALUES($1,$2,$3,$4,$5,$6,$7,$8,$9,'schema','Fallout')", $values);
    }
}

// Keep the stored schema, live schema and active marker consistent on every failed action.
function dpt_manage($conn, string $action, array $input): string
{
    if (!in_array($action, ['setup', 'create', 'switch', 'delete'], true)) throw new RuntimeException('Unknown playthrough action.');
    if (!pts_metadata_schema_ready($conn)) throw new RuntimeException('Run the DIALECTIC database update before managing playthroughs.');
    dpt_query($conn, 'BEGIN');
    try {
        dpt_query($conn, "SET LOCAL lock_timeout='2s'");
        if (pg_fetch_result(dpt_query($conn, "SELECT pg_try_advisory_xact_lock(hashtext('dialectic_storage_manager'))"), 0, 0) !== 't') {
            throw new RuntimeException('Another playthrough action is running. Try again shortly.');
        }
        if ($action === 'setup' || $action === 'create') {
            // The old page did this on GET. Capture the recovery point only after a confirmed write.
            $count = (int)pg_fetch_result(dpt_query($conn, 'SELECT count(*) FROM dialectic_meta.playthrough_profiles'), 0, 0);
            if ($count === 0) dpt_capture($conn, 'default', 'Initial recovery playthrough', null, true);
            if ($action === 'create') {
                $name = trim((string)($input['name'] ?? ''));
                if ($name === '' || strlen($name) > 160) throw new RuntimeException('Enter a playthrough name of 1–160 characters.');
                $hasActive = pg_fetch_result(dpt_query($conn, 'SELECT EXISTS(SELECT 1 FROM dialectic_meta.playthrough_profiles WHERE is_active)'), 0, 0) === 't';
                dpt_capture($conn, $name, trim((string)($input['notes'] ?? '')), null, !$hasActive);
            }
            $message = $action === 'setup' ? 'Initial recovery playthrough saved.' : 'Playthrough saved.';
        } else {
            $id = filter_var($input['profile_id'] ?? null, FILTER_VALIDATE_INT);
            if (!$id || $id < 1) throw new RuntimeException('Choose a valid playthrough.');
            $target = pg_fetch_assoc(dpt_query($conn, 'SELECT * FROM dialectic_meta.playthrough_profiles WHERE id=$1 FOR UPDATE', [$id]));
            if (!$target) throw new RuntimeException('That playthrough no longer exists.');
            if (in_array($target['is_active'], [true, 't', '1'], true)) throw new RuntimeException('This playthrough is already active.');
            if ($target['storage_type'] !== 'schema' || !str_starts_with((string)$target['schema_name'], 'dialectic_profile_')) {
                throw new RuntimeException('This playthrough has an unsupported storage format.');
            }
            if ($action === 'delete') {
                if (strtolower($target['name']) === 'default') throw new RuntimeException('The initial recovery playthrough cannot be deleted.');
                $drop = pts_drop_schema($conn, $target['schema_name']);
                if (empty($drop['success'])) throw new RuntimeException('Could not delete the playthrough. Nothing was removed.');
                dpt_query($conn, 'DELETE FROM dialectic_meta.playthrough_profiles WHERE id=$1', [$id]);
                $message = 'Playthrough deleted.';
            } else {
                if (!pts_schema_exists($conn, $target['schema_name'])) throw new RuntimeException('The saved playthrough schema is missing.');
                $current = pg_fetch_assoc(dpt_query($conn, 'SELECT * FROM dialectic_meta.playthrough_profiles WHERE is_active=true LIMIT 1 FOR UPDATE'));
                if (!$current) throw new RuntimeException('No active playthrough is recorded. Save a recovery playthrough before restoring.');
                dpt_capture($conn, $current['name'], $current['notes'] ?? '', $current, true);
                if (!pts_recreate_public_schema($conn)) throw new RuntimeException('Could not prepare the live database. Previous data was kept.');
                $clone = pts_clone_schema($conn, $target['schema_name'], 'public');
                if (empty($clone['success'])) throw new RuntimeException('Restore failed. Previous data was kept.');
                dpt_query($conn, 'UPDATE dialectic_meta.playthrough_profiles SET is_active=(id=$1)', [$id]);
                if (pg_fetch_result(dpt_query($conn, "SELECT to_regclass('public.database_versioning') IS NOT NULL"), 0, 0) === 't') {
                    dpt_query($conn, 'TRUNCATE public.database_versioning');
                }
                $message = 'Playthrough restored. Restart the DIALECTIC server and Fallout, then load the matching game save.';
            }
        }
        dpt_query($conn, 'COMMIT');
        return $message;
    } catch (Throwable $e) {
        pg_query($conn, 'ROLLBACK');
        throw $e;
    }
}
