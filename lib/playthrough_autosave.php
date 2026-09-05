<?php

require_once(__DIR__ . DIRECTORY_SEPARATOR . 'utils_game_timestamp.php');
require_once(__DIR__ . DIRECTORY_SEPARATOR . 'logger.php');
require_once(__DIR__ . DIRECTORY_SEPARATOR . 'playthrough_schema.php');
require_once(__DIR__ . DIRECTORY_SEPARATOR . 'db_connection_settings.php');

/**
 * Timeline Break automatic playthrough save helper.
 * Saves a playthrough in dialectic_meta when a large rollback is detected.
 */

function timeline_break_is_enabled() {
	if (!isset($GLOBALS["TIMELINE_BREAK_AUTO_PLAYTHROUGH"])) {
		$GLOBALS["TIMELINE_BREAK_AUTO_PLAYTHROUGH"] = true;
	}
	return !!$GLOBALS["TIMELINE_BREAK_AUTO_PLAYTHROUGH"];
}

function timeline_break_min_days() {
	if (!isset($GLOBALS["TIMELINE_BREAK_MIN_DAYS"])) {
		$GLOBALS["TIMELINE_BREAK_MIN_DAYS"] = 3;
	}
	return intval($GLOBALS["TIMELINE_BREAK_MIN_DAYS"]);
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

	$lockKey = 'dialectic.timeline_break_playthrough';
	$lockResult = @pg_query_params($adminConn, 'SELECT pg_advisory_lock(hashtext($1))', [$lockKey]);
	if (!$lockResult) {
		Logger::error("TimelineBreak: Failed to acquire playthrough lock: " . pg_last_error($adminConn));
		return 0;
	}

	try {
		// Concurrent main/gamedata requests can observe the same rollback. Reuse the
		// first completed playthrough instead of cloning the same timeline repeatedly.
		$existsRes = @pg_query_params(
			$adminConn,
			'SELECT id FROM dialectic_meta.playthrough_profiles WHERE name=$1 LIMIT 1',
			[$name]
		);
		if ($existsRes && ($existing = pg_fetch_assoc($existsRes))) {
			return intval($existing['id'] ?? 0);
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

		$cloneResult = pts_clone_schema($adminConn, $sourceSchema, $schemaName);
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
			"INSERT INTO dialectic_meta.playthrough_profiles (name, size_bytes, storage_type, notes, is_active, player_name, game, eventlog_count, worldknowledge_count, last_gamets, schema_name) VALUES ($1,$2,$3,$4,false,$5,$6,$7,$8,$9,$10) ON CONFLICT (name) DO NOTHING RETURNING id",
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
			return intval($existing['id'] ?? 0);
		}

		Logger::error("TimelineBreak: Failed to insert profile record: " . pg_last_error($adminConn));
		return 0;
	} finally {
		@pg_query_params($adminConn, 'SELECT pg_advisory_unlock(hashtext($1))', [$lockKey]);
	}
}

/**
 * Compose a Timeline Break playthrough name and create it if not present.
 * Returns playthrough id (existing or newly created), or 0.
 */
function timeline_break_playthrough_if_needed($prevGamets, $incomingGamets) {
	if (!timeline_break_is_enabled()) {
		return 0;
	}
	$prev = intval($prevGamets);
	$incoming = intval($incomingGamets);
	if ($prev <= 0 || $incoming <= 0) {
		return 0;
	}
	if ($incoming >= $prev) {
		return 0;
	}
	$daysRollback = gamets2days_between($incoming, $prev);
	if ($daysRollback < timeline_break_min_days()) {
		return 0;
	}
	$dateNew = convert_gamets2fallout_long_date_no_time($incoming);
	$dateOld = convert_gamets2fallout_long_date_no_time($prev);
	$name = "Timeline Break (" . $dateOld . " -> " . $dateNew . ")";
	$notes = "Automatic playthrough save due to rollback of {$daysRollback} in-game days ({$incoming} -> {$prev}).";
	return timeline_break_create_playthrough($name, $notes);
}

?>


