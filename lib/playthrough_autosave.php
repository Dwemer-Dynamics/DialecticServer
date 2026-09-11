<?php

require_once(__DIR__ . DIRECTORY_SEPARATOR . 'utils_game_timestamp.php');
require_once(__DIR__ . DIRECTORY_SEPARATOR . 'logger.php');
require_once(__DIR__ . DIRECTORY_SEPARATOR . 'playthrough_schema.php');
require_once(__DIR__ . DIRECTORY_SEPARATOR . 'db_connection_settings.php');
require_once(__DIR__ . DIRECTORY_SEPARATOR . 'playthrough_retention.php');

/**
 * Timeline Break automatic playthrough save helper.
 * Saves a playthrough in dialectic_meta when a large rollback is detected.
 */

function timeline_break_is_enabled() {
	return ptp_runtime_backup_settings()['enabled'];
}

function timeline_break_min_days() {
	return ptp_runtime_backup_settings()['min_days'];
}

/**
 * Verify the versioned playthrough metadata schema is ready.
 */
function timeline_break_meta_schema_ready($adminConn) {
	return pts_metadata_schema_ready($adminConn);
}

/**
 * Create a playthrough profile using fast schema cloning.
 * Returns the created profile id, or existing id on name collision, or 0 on failure.
 */
function timeline_break_create_playthrough($name, $notes) {
	$dbSettings = dialecticDbConnectionSettings('dialectic');
	$adminConn = @pg_connect(dialecticPgConnectionString($dbSettings));
	if (!$adminConn) {
		Logger::error("TimelineBreak: Failed to connect to database for playthrough: " . @pg_last_error());
		return 0;
	}

	if (!timeline_break_meta_schema_ready($adminConn)) {
		Logger::error("TimelineBreak: Playthrough metadata schema is unavailable");
		return 0;
	}

	$lockKey = 'dialectic_meta_playthrough_retention';
	$lockResult = @pg_query_params($adminConn, 'SELECT pg_advisory_lock(hashtext($1))', [$lockKey]);
	if (!$lockResult) {
		Logger::error("TimelineBreak: Failed to acquire playthrough lock: " . pg_last_error($adminConn));
		return 0;
	}

	try {
		ptr_ensure_schema($adminConn);
		// Concurrent main/gamedata requests can observe the same rollback. Reuse the
		// first completed playthrough instead of cloning the same timeline repeatedly.
		$existsRes = @pg_query_params(
			$adminConn,
			'SELECT id FROM dialectic_meta.playthrough_profiles WHERE name=$1 LIMIT 1',
			[$name]
		);
		if ($existsRes && ($existing = pg_fetch_assoc($existsRes))) {
			$profileId = intval($existing['id'] ?? 0);
			return $profileId;
		}

		$sourceSchema = trim((string)($dbSettings['schema'] ?? 'public'));
		if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $sourceSchema)) {
			Logger::error("TimelineBreak: Invalid source schema '{$sourceSchema}'");
			return 0;
		}
		$sourceSchemaSql = pg_escape_identifier($adminConn, $sourceSchema);

		$schemaName = pts_sanitize_profile_name($name);
		if (pts_schema_exists($adminConn, $schemaName)) {
			Logger::warn("TimelineBreak: Schema {$schemaName} already exists without a matching profile, appending unique suffix");
			$schemaName .= '_' . substr(uniqid('', true), -6);
		}

		$cloneResult = pts_transfer_playthrough($adminConn, $schemaName);
		if (!$cloneResult['success']) {
			Logger::error("TimelineBreak: Failed to clone schema: " . $cloneResult['error']);
			return 0;
		}

		$eventlogCount = 0;
		$worldknowledgeCount = 0;
		$lastGamets = 0;
		$r1 = @pg_query($adminConn, "SELECT COUNT(*) AS c FROM {$sourceSchemaSql}.eventlog");
		if ($r1 && ($rr = pg_fetch_assoc($r1))) {
			$eventlogCount = intval($rr['c']);
		}
		$rex = @pg_query_params(
			$adminConn,
			"SELECT 1 FROM information_schema.tables WHERE table_schema=$1 AND table_name='worldknowledge' LIMIT 1",
			[$sourceSchema]
		);
		$hasWorldKnowledge = ($rex && pg_fetch_assoc($rex)) ? true : false;
		if ($hasWorldKnowledge) {
			$r2 = @pg_query($adminConn, "SELECT COUNT(*) AS c FROM {$sourceSchemaSql}.worldknowledge");
			if ($r2 && ($rr = pg_fetch_assoc($r2))) {
				$worldknowledgeCount = intval($rr['c']);
			}
		}
		$r3 = @pg_query($adminConn, "SELECT MAX(gamets) AS mx FROM {$sourceSchemaSql}.eventlog");
		if ($r3 && ($rr = pg_fetch_assoc($r3)) && !is_null($rr['mx'])) {
			$lastGamets = intval($rr['mx']);
		}

		$playerName = (string)($GLOBALS['PLAYER_NAME'] ?? 'Unknown');
		$gameName = 'Fallout';
		$size = pts_get_schema_size($adminConn, $schemaName);
		$q1 = @pg_query_params(
			$adminConn,
			"INSERT INTO dialectic_meta.playthrough_profiles (name, size_bytes, storage_type, notes, is_active, player_name, game, eventlog_count, worldknowledge_count, last_gamets, schema_name, retention_kind) VALUES ($1,$2,$3,$4,false,$5,$6,$7,$8,$9,$10,'dragon_break') ON CONFLICT (name) DO NOTHING RETURNING id",
			[$name, (string)$size, 'schema', $notes, $playerName, $gameName, (string)$eventlogCount, (string)$worldknowledgeCount, (string)$lastGamets, $schemaName]
		);
		$row = $q1 ? pg_fetch_assoc($q1) : false;
		if ($row) {
			$profileId = intval($row['id'] ?? 0);
			Logger::info("TimelineBreak: Schema-based playthrough created with id {$profileId} and name '{$name}'");
			return $profileId;
		}

		// Avoid retaining an untracked clone if the metadata insert fails.
		pts_drop_schema($adminConn, $schemaName);
		$existingAfterConflict = @pg_query_params(
			$adminConn,
			'SELECT id FROM dialectic_meta.playthrough_profiles WHERE name=$1 LIMIT 1',
			[$name]
		);
		if ($existingAfterConflict && ($existing = pg_fetch_assoc($existingAfterConflict))) {
			return $profileId = intval($existing['id'] ?? 0);
		}

		Logger::error("TimelineBreak: Failed to insert profile record: " . pg_last_error($adminConn));
		return 0;
	} finally {
		ptp_record_backup($adminConn, $profileId ?? 0, ($profileId ?? 0) > 0 ? 'Automatic Playthrough Save created.' : 'Automatic Playthrough Save failed. Check the server log.');
		@pg_query_params($adminConn, 'SELECT pg_advisory_unlock(hashtext($1))', [$lockKey]);
	}
}

/**
 * Compose a Timeline Break playthrough name and create it if not present.
 * Returns playthrough id (existing or newly created), or 0.
 */
function timeline_break_playthrough_if_needed($prevGamets, $incomingGamets) {
    require_once __DIR__ . '/playthrough_guard.php';
    return pgr_before_rollback((int)$prevGamets, (int)$incomingGamets);
}

?>


