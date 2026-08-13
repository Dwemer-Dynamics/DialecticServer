<?php

$enginePath = dirname(__DIR__) . DIRECTORY_SEPARATOR;

require_once(__DIR__.DIRECTORY_SEPARATOR."profile_loader.php");
require_once($enginePath . "lib" . DIRECTORY_SEPARATOR . "{$GLOBALS["DBDRIVER"]}.class.php");
require_once($enginePath . "lib" . DIRECTORY_SEPARATOR . "logger.php");
require_once($enginePath . "lib" . DIRECTORY_SEPARATOR . "utils_game_timestamp.php");
require_once($enginePath . "lib" . DIRECTORY_SEPARATOR . "playthrough_schema.php");
require_once($enginePath . "lib" . DIRECTORY_SEPARATOR . "db_connection_settings.php");

// Determine web root (match other core pages)
$scriptPath = $_SERVER['SCRIPT_NAME'];
$uiPos = strpos($scriptPath, '/ui/');
if ($uiPos !== false) {
 $webRoot = substr($scriptPath, 0, $uiPos);
} else {
 $webRoot = '';
}
if ($webRoot == '/') $webRoot = '';
$webRoot = rtrim($webRoot, '/');

$TITLE = " DIALECTIC - Playthrough Manager";
ob_start();
include(__DIR__.DIRECTORY_SEPARATOR."tmpl/head.html");
?><link rel="stylesheet" href="<?php echo $webRoot; ?>/ui/css/main.css"><?php
// Embed mode and navbar (match WorldKnowledge style)
$isEmbed = (isset($_GET['embed']) && $_GET['embed'] == '1');
$debugPaneLink = false;
if (!$isEmbed) {
 include(__DIR__.DIRECTORY_SEPARATOR."tmpl/navbar.php");
}

$dbSettings = dialecticDbConnectionSettings('dialectic');
$host = $dbSettings['host'];
$port = $dbSettings['port'];
$dbname = $dbSettings['dbname'];
$schema = $dbSettings['schema'];
$username = $dbSettings['username'];
$password = $dbSettings['password'];

// Use a direct connection for playthrough administration; bootstrap owns the schema.
$adminConn = pg_connect(dialecticPgConnectionString($dbSettings));
if (!$adminConn) {
 echo '<div class="message"><p><strong>Error:</strong> Failed to connect to database: '.htmlspecialchars(pg_last_error()).'</p></div>';
} else {
 $metaReady = pts_metadata_schema_ready($adminConn);
 if (!$metaReady) {
  echo '<div class="message"><p><strong>Error:</strong> Playthrough metadata schema is unavailable. Run the DIALECTIC database bootstrap and check the server log.</p></div>';
 }
 // Auto-capture current DB as 'default' profile if none exists yet (GET-only)
 $needsDefault = false;
 if ($metaReady && $_SERVER['REQUEST_METHOD'] === 'GET') {
 $cntRes = @pg_query($adminConn, "SELECT COUNT(*) AS c FROM dialectic_meta.playthrough_profiles");
 if ($cntRes && ($c = pg_fetch_assoc($cntRes)) && intval($c['c']) === 0) { $needsDefault = true; }
 // Guard against partial states: if a row named 'default' exists, skip
 $existsRes = @pg_query_params($adminConn, "SELECT 1 FROM dialectic_meta.playthrough_profiles WHERE name=$1 LIMIT 1", ['default']);
 if ($existsRes && pg_fetch_assoc($existsRes)) { $needsDefault = false; }
 }
 if ($needsDefault) {
 $playerName = (string)($GLOBALS['PLAYER_NAME'] ?? 'Unknown');
 $gameName = 'Fallout';
 $eventlogCount = 0; $worldknowledgeCount = 0; $lastGamets = 0;
 $r1 = @pg_query($adminConn, "SELECT COUNT(*) AS c FROM {$schema}.eventlog");
 if ($r1 && ($rr = pg_fetch_assoc($r1))) { $eventlogCount = intval($rr['c']); }
 $rex = @pg_query_params($adminConn, "SELECT 1 FROM information_schema.tables WHERE table_schema=$1 AND table_name='worldknowledge' LIMIT 1", [$schema]);
 $hasWorldKnowledge = ($rex && pg_fetch_assoc($rex)) ? true : false;
 if ($hasWorldKnowledge) {
 $r2 = @pg_query($adminConn, "SELECT COUNT(*) AS c FROM {$schema}.worldknowledge");
 if ($r2 && ($rr = pg_fetch_assoc($r2))) { $worldknowledgeCount = intval($rr['c']); }
 }
 $r3 = @pg_query($adminConn, "SELECT MAX(gamets) AS mx FROM {$schema}.eventlog");
 if ($r3 && ($rr = pg_fetch_assoc($r3)) && !is_null($rr['mx'])) { $lastGamets = intval($rr['mx']); }

 // Use new schema-based storage for default profile
 $schemaName = pts_sanitize_profile_name('default');
 $cloneResult = pts_clone_schema($adminConn, 'public', $schemaName);
 if ($cloneResult['success']) {
 $size = pts_get_schema_size($adminConn, $schemaName);
 @pg_query($adminConn, 'BEGIN');
 $q1 = @pg_query_params(
 $adminConn,
 "INSERT INTO dialectic_meta.playthrough_profiles (name, size_bytes, storage_type, notes, is_active, player_name, game, eventlog_count, worldknowledge_count, last_gamets, schema_name) VALUES ($1,$2,$3,$4,true,$5,$6,$7,$8,$9,$10)",
 ['default', (string)$size, 'schema', 'Auto-captured default profile', $playerName, $gameName, (string)$eventlogCount, (string)$worldknowledgeCount, (string)$lastGamets, $schemaName]
 );
 if ($q1) {
 @pg_query($adminConn, 'COMMIT');
 } else {
 @pg_query($adminConn, 'ROLLBACK');
 }
 }
 }
}

$db = new sql();
$message = '';

// Helpers
function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function formatFileSize($bytes) {
 if ($bytes == 0) return '0 Bytes';
 $k = 1024; $sizes = ['Bytes','KB','MB','GB','TB'];
 $i = floor(log($bytes) / log($k));
 return round($bytes / pow($k, $i), 2) . ' ' . $sizes[$i];
}

// Handle actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
 $action = $_POST['action'] ?? '';

 if ($action === 'create') {
 $name = trim((string)($_POST['name'] ?? ''));
 $notes = trim((string)($_POST['notes'] ?? ''));
 if ($name === '') {
 $message .= '<p><strong>Error:</strong> Name is required.</p>';
 } else if (!$adminConn) {
 $message .= '<p><strong>Error:</strong> DB connection not available.</p>';
 } else {
 // Use schema-based storage for instant snapshots
 $schemaName = pts_sanitize_profile_name($name);

 // Check if schema already exists
 if (pts_schema_exists($adminConn, $schemaName)) {
 $message .= '<p><strong>Error:</strong> A profile with this name already exists.</p>';
 } else {
 // Clone public schema to new profile schema
 $cloneResult = pts_clone_schema($adminConn, 'public', $schemaName);

 if (!$cloneResult['success']) {
 $message .= '<p><strong>Error:</strong> Failed to create snapshot.</p>';
 $message .= '<pre>'.h($cloneResult['error']).'</pre>';
 } else {
 // Collect metadata
 $playerName = (string)($GLOBALS['PLAYER_NAME'] ?? 'Unknown');
 $gameName = 'Fallout';
 $eventlogCount = 0; $worldknowledgeCount = 0; $lastGamets = 0;
 $r1 = @pg_query($adminConn, "SELECT COUNT(*) AS c FROM {$schema}.eventlog");
 if ($r1 && ($rr = pg_fetch_assoc($r1))) { $eventlogCount = intval($rr['c']); }
 $rex = @pg_query_params($adminConn, "SELECT 1 FROM information_schema.tables WHERE table_schema=$1 AND table_name='worldknowledge' LIMIT 1", [$schema]);
 $hasWorldKnowledge = ($rex && pg_fetch_assoc($rex)) ? true : false;
 if ($hasWorldKnowledge) {
 $r2 = @pg_query($adminConn, "SELECT COUNT(*) AS c FROM {$schema}.worldknowledge");
 if ($r2 && ($rr = pg_fetch_assoc($r2))) { $worldknowledgeCount = intval($rr['c']); }
 }
 $r3 = @pg_query($adminConn, "SELECT MAX(gamets) AS mx FROM {$schema}.eventlog");
 if ($r3 && ($rr = pg_fetch_assoc($r3)) && !is_null($rr['mx'])) { $lastGamets = intval($rr['mx']); }

 // Calculate schema size
 $size = pts_get_schema_size($adminConn, $schemaName);

 // Insert profile record
 @pg_query($adminConn, 'BEGIN');
 $q1 = @pg_query_params(
 $adminConn,
 "INSERT INTO dialectic_meta.playthrough_profiles (name, size_bytes, storage_type, notes, is_active, player_name, game, eventlog_count, worldknowledge_count, last_gamets, schema_name) VALUES ($1,$2,$3,$4,false,$5,$6,$7,$8,$9,$10)",
 [$name, (string)$size, 'schema', $notes, $playerName, $gameName, (string)$eventlogCount, (string)$worldknowledgeCount, (string)$lastGamets, $schemaName]
 );
 if ($q1) {
 @pg_query($adminConn, 'COMMIT');
 $message .= '<p><strong> Snapshot created:</strong> '.h($name).' ('.h(formatFileSize($size)).')</p>';
 } else {
 @pg_query($adminConn, 'ROLLBACK');
 $message .= '<p><strong>Error:</strong> Failed to save snapshot metadata.</p>';
 }
 }
 }
 }
 }

 if ($action === 'switch') {
 $profileId = intval($_POST['profile_id'] ?? 0);
 if ($profileId <= 0) {
 $message .= '<p><strong>Error:</strong> Invalid profile selected.</p>';
 } else if (!$adminConn) {
 $message .= '<p><strong>Error:</strong> DB connection not available.</p>';
 } else {
 // Fetch target profile info
 $targetRes = pg_query_params($adminConn, 'SELECT name, storage_type, schema_name FROM dialectic_meta.playthrough_profiles WHERE id=$1', [$profileId]);
 $targetRow = $targetRes ? pg_fetch_assoc($targetRes) : null;
 if (!$targetRow) {
 $message .= '<p><strong>Error:</strong> Profile not found.</p>';
 goto SWITCH_ABORT;
 }

 $targetName = $targetRow['name'];
 $targetStorageType = $targetRow['storage_type'] ?? 'schema';
 $targetSchemaName = $targetRow['schema_name'] ?? '';

 // 1) Auto-save current active profile BEFORE switching
 $curRes = pg_query($adminConn, "SELECT id, name, storage_type, schema_name FROM dialectic_meta.playthrough_profiles WHERE is_active = true LIMIT 1");
 $curRow = $curRes ? pg_fetch_assoc($curRes) : null;
 if ($curRow) {
 $curProfileId = intval($curRow['id']);
 $curStorageType = $curRow['storage_type'] ?? 'schema';
 $curSchemaName = $curRow['schema_name'] ?? '';

 // Gather live metadata
 $playerName = (string)($GLOBALS['PLAYER_NAME'] ?? 'Unknown');
 $gameName = 'Fallout';
 $eventlogCount = 0; $worldknowledgeCount = 0; $lastGamets = 0;
 $r1 = @pg_query($adminConn, "SELECT COUNT(*) AS c FROM {$schema}.eventlog");
 if ($r1 && ($rr = pg_fetch_assoc($r1))) { $eventlogCount = intval($rr['c']); }
 $rex = @pg_query_params($adminConn, "SELECT 1 FROM information_schema.tables WHERE table_schema=$1 AND table_name='worldknowledge' LIMIT 1", [$schema]);
 $hasWorldKnowledge = ($rex && pg_fetch_assoc($rex)) ? true : false;
 if ($hasWorldKnowledge) {
 $r2 = @pg_query($adminConn, "SELECT COUNT(*) AS c FROM {$schema}.worldknowledge");
 if ($r2 && ($rr = pg_fetch_assoc($r2))) { $worldknowledgeCount = intval($rr['c']); }
 }
 $r3 = @pg_query($adminConn, "SELECT MAX(gamets) AS mx FROM {$schema}.eventlog");
 if ($r3 && ($rr = pg_fetch_assoc($r3)) && !is_null($rr['mx'])) { $lastGamets = intval($rr['mx']); }

 if ($curStorageType === 'schema' && !empty($curSchemaName)) {
 // Schema-based: clone public back to profile schema
 $cloneResult = pts_clone_schema($adminConn, 'public', $curSchemaName);
 if (!$cloneResult['success']) {
 $message .= '<p><strong>Error:</strong> Failed to save current profile. Aborting switch.</p>';
 $message .= '<pre>'.h($cloneResult['error']).'</pre>';
 goto SWITCH_ABORT;
 }
 // Update metadata
 $size = pts_get_schema_size($adminConn, $curSchemaName);
 @pg_query_params(
 $adminConn,
 'UPDATE dialectic_meta.playthrough_profiles SET size_bytes=$2, player_name=$3, game=$4, eventlog_count=$5, worldknowledge_count=$6, last_gamets=$7 WHERE id=$1',
 [(string)$curProfileId, (string)$size, $playerName, $gameName, (string)$eventlogCount, (string)$worldknowledgeCount, (string)$lastGamets]
 );
 } else {
 $message .= '<p><strong>Error:</strong> Unsupported playthrough storage type for the current profile. Recreate this profile with schema storage.</p>';
 goto SWITCH_ABORT;
 }
 }

 // 2) Load target profile
 if ($targetStorageType === 'schema' && !empty($targetSchemaName)) {
 // Schema-based: fast switch
 if (!pts_schema_exists($adminConn, $targetSchemaName)) {
 $message .= '<p><strong>Error:</strong> Profile schema does not exist.</p>';
 goto SWITCH_ABORT;
 }

 // Recreate public schema
 if (!pts_recreate_public_schema($adminConn)) {
 $message .= '<p><strong>Error:</strong> Failed to recreate public schema.</p>';
 goto SWITCH_ABORT;
 }

 // Clone profile schema to public
 $cloneResult = pts_clone_schema($adminConn, $targetSchemaName, 'public');
 if (!$cloneResult['success']) {
 $message .= '<p><strong>Error:</strong> Failed to load profile.</p>';
 $message .= '<pre>'.h($cloneResult['error']).'</pre>';
 goto SWITCH_ABORT;
 }

 // Mark active
 pg_query($adminConn, 'BEGIN');
 pg_query($adminConn, 'UPDATE dialectic_meta.playthrough_profiles SET is_active = false');
 $resU = pg_query_params($adminConn, 'UPDATE dialectic_meta.playthrough_profiles SET is_active = true WHERE id=$1', [$profileId]);
 if ($resU) {
 pg_query($adminConn, 'COMMIT');
 pg_query($adminConn, 'TRUNCATE table public.database_versioning'); // So views and functiosn get recreated on next server starttup
 $message .= '<p><strong> Switched to profile:</strong> '.h($targetName).'</p>';
 $message .= '<div style="background:#4a1e0d; border:2px solid #dc2626; border-radius:8px; padding:15px; margin-top:15px;">';
 $message .= '<p style="color:#fbbf24; font-weight:bold; margin:0 0 10px 0;"> RESTART REQUIRED</p>';
 $message .= '<p style="margin:0 0 8px 0;">You must restart the DIALECTIC server for the switch to take effect:</p>';
 $message .= '<ol style="margin:5px 0; padding-left:20px;">';
 $message .= '<li>Shutdown Fallout</li>';
 $message .= '<li>Restart DIALECTIC Server</li>';
 $message .= '<li>Restart Fallout and load into the save you want to continue from</li>';
 $message .= '</ol>';
 $message .= '<p style="margin:8px 0 0 0; font-size:0.9em; color:#ccc;">Database connections were terminated during the schema switch.</p>';
 $message .= '</div>';
 } else {
 pg_query($adminConn, 'ROLLBACK');
 $message .= '<p><strong>Warning:</strong> Profile loaded but failed to mark active.</p>';
 }
 } else {
 $message .= '<p><strong>Error:</strong> Unsupported playthrough storage type for the selected profile. Recreate this profile with schema storage.</p>';
 goto SWITCH_ABORT;
 }
 }
 SWITCH_ABORT:;
 }

 if ($action === 'delete') {
 $profileId = intval($_POST['profile_id'] ?? 0);
 if ($profileId <= 0) {
 $message .= '<p><strong>Error:</strong> Invalid profile selected.</p>';
 } else if (!$adminConn) {
 $message .= '<p><strong>Error:</strong> DB connection not available.</p>';
 } else {
 $row = $db->fetchOne("SELECT is_active, name, storage_type, schema_name FROM dialectic_meta.playthrough_profiles WHERE id = ".$profileId);
 if (!$row) {
 $message .= '<p><strong>Error:</strong> Profile not found.</p>';
 } else if ((int)$row['is_active'] === 1) {
 $message .= '<p><strong>Error:</strong> Cannot delete the active profile.</p>';
 } else if (strtolower((string)$row['name']) === 'default') {
 $message .= '<p><strong>Error:</strong> Cannot delete the default profile.</p>';
 } else {
 $storageType = $row['storage_type'] ?? 'schema';
 $schemaName = $row['schema_name'] ?? '';

 // If schema-based, drop the schema first
 if ($storageType === 'schema' && !empty($schemaName)) {
 $dropResult = pts_drop_schema($adminConn, $schemaName);
 if (!$dropResult['success']) {
 $message .= '<p><strong>Warning:</strong> Failed to drop schema: '.h($dropResult['error']).'</p>';
 }
 }

 // Delete profile record.
 $ok = pg_query_params($adminConn, 'DELETE FROM dialectic_meta.playthrough_profiles WHERE id=$1', [$profileId]);
 if ($ok) {
 $message .= '<p><strong> Deleted:</strong> '.h($row['name']).'</p>';
 } else {
 $message .= '<p><strong>Error:</strong> Failed to delete profile.</p>';
 }
 }
 }
 }
}

// Fetch profiles
$profiles = $db->fetchAll("SELECT id, name, created_at, size_bytes, storage_type, schema_name, notes, is_active, player_name, game, eventlog_count, worldknowledge_count, last_gamets FROM dialectic_meta.playthrough_profiles ORDER BY COALESCE(last_gamets,0) DESC, created_at DESC");

// Live stats for currently loaded (active) database; do not rely on metadata
$activeProfileName = '';
if ($adminConn) {
 $apr = @pg_query($adminConn, "SELECT name FROM dialectic_meta.playthrough_profiles WHERE is_active = true LIMIT 1");
 if ($apr && ($ar = pg_fetch_assoc($apr))) { $activeProfileName = (string)$ar['name']; }
}
$livePlayerName = (string)($GLOBALS['PLAYER_NAME'] ?? 'Unknown');
$liveGameName = 'Fallout';
// Use metadata for initial render (fast), refresh via AJAX later
$liveEventlogCount = 0; $liveWorldKnowledgeCount = 0; $liveLastGamets = 0;
foreach ($profiles as $p) {
 $nameStr = (string)($p['name'] ?? '');
 $isActive = ((int)($p['is_active'] ?? 0) === 1) || (strcasecmp($nameStr, (string)$activeProfileName) === 0);
 if ($isActive) {
 $liveEventlogCount = intval($p['eventlog_count'] ?? 0);
 $liveWorldKnowledgeCount = intval($p['worldknowledge_count'] ?? 0);
 $liveLastGamets = intval($p['last_gamets'] ?? 0);
 break;
 }
}
$liveFalloutDate = ($liveLastGamets > 0) ? convert_gamets2fallout_long_date($liveLastGamets) : '';

// Prepare timeline items based on last_gamets
$timelineItems = [];
foreach ($profiles as $p) {
 $nameStr = (string)($p['name'] ?? '');
 $isActive = ((int)($p['is_active'] ?? 0) === 1) || (strcasecmp($nameStr, (string)$activeProfileName) === 0);
 $lgMeta = isset($p['last_gamets']) ? intval($p['last_gamets']) : 0;
 $lg = $lgMeta;
 if ($lg <= 0 && $isActive && $liveLastGamets > 0) { $lg = $liveLastGamets; }
 if ($lg <= 0) { continue; }
 $timelineItems[] = [
 'id' => (int)$p['id'],
 'name' => $nameStr,
 'last_gamets' => $lg,
 'fallout_date' => convert_gamets2fallout_long_date($lg),
 'created_at' => (string)$p['created_at'],
 'size' => formatFileSize((int)$p['size_bytes']),
 'is_active' => $isActive
 ];
}

// Timeline ticks (static notches with labels)
$timelineTicks = [];
if (!empty($timelineItems)) {
 $values = array_map(function($i){ return (int)$i['last_gamets']; }, $timelineItems);
 $minGamets = min($values);
 $maxGamets = max($values);
 $segments = min(max(count($timelineItems) - 1, 4), 12); // 4..12 ticks based on data
 if ($maxGamets === $minGamets) {
 // Degenerate: place a center tick
 $timelineTicks[] = [
 'gamets' => $minGamets,
 'date' => convert_gamets2fallout_long_date($minGamets)
 ];
 } else {
 for ($s = 0; $s <= $segments; $s++) {
 $g = (int)round($minGamets + ($s * ($maxGamets - $minGamets) / $segments));
 $timelineTicks[] = [
 'gamets' => $g,
 'date' => convert_gamets2fallout_long_date($g)
 ];
 }
 }
}

?><style>
 main { padding-top: 80px; padding-bottom: 40px; padding-left: 10%; padding-right: 10%; width: 100%; margin: 0; }
 footer { position: fixed; bottom: 0; width: 100%; height: 20px; background: #031633; z-index: 100; }

 .page-header {
 text-align: center;
 margin-bottom: 30px;
 padding: 20px;
 background: linear-gradient(180deg, rgba(42, 42, 42, 0.95), rgba(34, 34, 34, 0.98));
 border-radius: 10px;
 border: 1px solid #3a3a3a;
 box-shadow: 0 2px 8px rgba(0, 0, 0, 0.15), inset 0 1px rgba(255, 255, 255, 0.03);
 }

 .page-header h1 {
 margin-bottom: 8px;
 font-family: 'Gothic821', serif;
 word-spacing: 8px;
 font-size: 2.0em;
 color: rgb(255, 182, 65);
 text-shadow: 2px 2px 4px rgba(0,0,0,0.5);
 }

 .content-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 30px; margin-bottom: 30px; }

 .content-section {
 background: linear-gradient(135deg, rgba(42, 42, 42, 0.95), rgba(34, 34, 34, 0.98));
 padding: 25px;
 border-radius: 10px;
 border: 1px solid #3a3a3a;
 box-shadow: 0 2px 8px rgba(0, 0, 0, 0.15), inset 0 1px rgba(255, 255, 255, 0.03);
 transition: border-color 0.3s ease, box-shadow 0.3s ease;
 }

 .content-section:hover {
 border-color: rgba(255, 182, 65, 0.3);
 box-shadow: 0 4px 16px rgba(0, 0, 0, 0.25), inset 0 1px rgba(255, 255, 255, 0.05);
 }

 .content-section h2 {
 font-family: 'Gothic821', serif;
 color: rgb(255, 182, 65);
 text-shadow: 1px 1px 2px rgba(0,0,0,0.5);
 word-spacing: 6px;
 margin-bottom: 15px;
 font-size: 1.4em;
 }

 .full-width-section { grid-column: 1 / -1; }
 .button-group { display: flex; gap: 15px; margin-top: 15px; flex-wrap: wrap; }
 @font-face { font-family: 'Gothic821'; src: url('<?php echo $webRoot; ?>/ui/css/font/Gothic821CondensedRegular.otf') format('opentype'); font-weight: normal; font-style: normal; }
 @media (max-width: 768px) { main { padding-left: 5%; padding-right: 5%; } .content-grid { grid-template-columns: 1fr; } .content-section { padding: 15px; } }
 /* Timeline */
 .timeline { position: relative; padding: 28px 8px 30px 8px; }
 .timeline-title { text-align:center; color:#e0e0e0; font-size: 13px; margin-bottom: 12px; }
 .timeline-track { position: relative; height: 4px; background: linear-gradient(90deg, rgba(138,155,182,0.5), rgba(255, 182, 65,0.6)); border-radius: 2px; }
 .timeline-nodes { position: relative; height: 0; }
 .timeline-node { position: absolute; top: -8px; width: 16px; height: 16px; border-radius: 50%; background: #ffb862; border: 2px solid #1a1a1a; box-shadow: 0 0 0 2px rgba(255,255,255,0.08); transform: translateX(-50%); cursor: pointer; }
 .timeline-node.active { background: #2ea8ff; box-shadow: 0 0 0 2px rgba(46,168,255,0.25), 0 0 12px rgba(46,168,255,0.35); }
 .timeline-tooltip { position: absolute; display: none; max-width: 280px; background: #111; border: 1px solid rgba(138,155,182,0.4); color: #e0e0e0; padding: 8px 10px; border-radius: 6px; font-size: 12px; z-index: 20; pointer-events: none; box-shadow: 0 8px 24px rgba(0,0,0,0.4); }
 .timeline-tooltip .name { color: #ffb862; font-weight: bold; }
 .timeline-legend { display:flex; justify-content:space-between; font-size: 12px; color:#9fb1c9; margin-top: 8px; }
 .timeline-notches { position: relative; height: 0; }
 .timeline-notch { position: absolute; top: -12px; width: 2px; height: 10px; background: #9fb1c9; opacity: 0.7; transform: translateX(-50%); }
 .timeline-notch.major { height: 14px; background:#e0e0e0; opacity: 0.9; }
 .timeline-tick-label { position: absolute; top: -30px; transform: translateX(-50%); color:#9fb1c9; font-size: 11px; white-space: nowrap; pointer-events: none; }
 .timeline-label { position: absolute; top: -28px; transform: translateX(-50%); color:#9fb1c9; font-size: 11px; white-space: nowrap; pointer-events: none; }
 .timeline-label.active { color:#eaee05; }
 /* Timeline Break styling */
 .backup-item.timelinebreak { background-color: #1e2a3a; }
 .backup-item.timelinebreak:hover { background-color: #223044; }
 /* Switch overlay */
 #switch-overlay { position: fixed; inset: 0; background: rgba(0,0,0,0.65); z-index: 2000; display: none; align-items: center; justify-content: center; }
 #switch-overlay .loading-modal { background:#111; border:1px solid #444; border-radius:8px; padding:20px 24px; width: 340px; color:#e0e0e0; text-align:center; box-shadow: 0 12px 36px rgba(0,0,0,0.5); }
 #switch-overlay .loading-title { font-family: 'Gothic821', serif; color: rgb(255, 182, 65); margin-bottom: 8px; font-size: 1.2em; word-spacing: 6px; }
 #switch-overlay .loading-sub { font-size: 12px; color:#9fb1c9; margin-top: 6px; }
 .lds-ring { display:inline-block; position:relative; width:64px; height:64px; margin: 6px 0 2px 0; }
 .lds-ring div { box-sizing:border-box; display:block; position:absolute; width:51px; height:51px; margin:6px; border:6px solid #ffb862; border-radius:50%; animation: lds-ring 1.2s cubic-bezier(0.5, 0, 0.5, 1) infinite; border-color:#ffb862 transparent transparent transparent; }
 .lds-ring div:nth-child(1) { animation-delay:-0.45s; }
 .lds-ring div:nth-child(2) { animation-delay:-0.3s; }
 .lds-ring div:nth-child(3) { animation-delay:-0.15s; }
 @keyframes lds-ring { 0% { transform: rotate(0deg);} 100% { transform: rotate(360deg);} }
</style><?php if ($isEmbed): ?><style> main { padding-top: 20px; } </style><?php endif; ?><main><div id="toast" class="toast-notification"><span class="message"></span></div><div id="switch-overlay" role="alert" aria-live="assertive" aria-modal="true"><div class="loading-modal"><div class="loading-title" id="loading-title">Restoring Snapshot</div><div class="lds-ring"><div></div><div></div><div></div><div></div></div><div class="loading-sub">This can take a few minutes. Please keep this tab open.</div></div></div><div class="page-header"><h1>Playthrough Manager</h1><div style="font-size: 0.95em; color: #ccc; margin-bottom: 15px;">Create, switch, and manage playthrough snapshots using instant schema cloning.</div><div style="background: rgba(0,0,0,0.3); padding: 15px; border-radius: 8px; border: 1px solid #444; margin-top: 15px;"><div style="font-size: 0.9em; color: #e0e0e0; line-height: 1.6;"><strong style="color: #4ade80;">How it works:</strong><br><strong>Public Schema</strong> = Your active database schema where your current playthrough is stored.<br><strong>Stored Snapshots</strong> = Backups in separate schemas<br><strong>Switching</strong> = Copies a snapshot INTO public (auto-saves current public first)<br><strong>Timeline Breaks</strong> = Auto-snapshots when you load a save 3+ days behind<br></div></div></div><div class="content-section full-width-section" style="background: linear-gradient(135deg, #1a3a2a 0%, #2a2a2a 100%); border: 2px solid #4ade80;"><div style="display: flex; align-items: center; gap: 15px; margin-bottom: 15px;"><div style="font-size: 2.5em;"></div><div style="flex: 1;"><h2 style="margin: 0;">Active Database</h2><div style="font-size: 0.9em; color: #9fb1c9; margin-top: 4px;">
 This is the live database used by DIALECTIC. It's on the Public Schema.
 </div></div></div><div style="background: rgba(0,0,0,0.3); padding: 15px; border-radius: 6px; border-left: 4px solid #4ade80;"><div style="display:flex; gap:12px; flex-wrap:wrap; font-size: 14px; color:#ccc; align-items: center;"><div style="font-size: 1.1em; color: #4ade80; font-weight: bold;">
 Last copied from: <?php echo h($activeProfileName !== '' ? $activeProfileName : '(unknown)'); ?></div><div style="border-left: 2px solid #444; padding-left: 12px; margin-left: 6px;"><strong style="color:#f8f9fa;">Player:</strong><span id="live-player"><?php echo h($livePlayerName); ?></span></div><div><strong style="color:#f8f9fa;">Game:</strong><span id="live-game"><?php echo h($liveGameName); ?></span></div><div><strong style="color:#f8f9fa;">Events:</strong><span id="live-eventlog"><?php echo intval($liveEventlogCount); ?></span></div><div><strong style="color:#f8f9fa;">WorldKnowledge:</strong><span id="live-worldknowledge"><?php echo intval($liveWorldKnowledgeCount); ?></span></div><div><strong style="color:#f8f9fa;">Last in-game date:</strong><span id="live-last"><?php echo h($liveFalloutDate !== '' ? $liveFalloutDate : 'n/a'); ?></span></div></div></div><?php if (!empty($timelineItems)) { ?><div class="timeline" id="pt-timeline"><div class="timeline-title" id="pt-title"></div><div class="timeline-track"></div><div class="timeline-notches" id="pt-timeline-notches"></div><div class="timeline-nodes" id="pt-timeline-nodes"></div><div class="timeline-legend"><span id="pt-min"></span><span id="pt-max"></span></div><div class="timeline-tooltip" id="pt-tooltip"></div></div><?php } ?></div><?php if (!empty($message)) { echo '<div class="content-section">'.$message.'</div>'; } ?><div class="content-grid"><div class="content-section"><h2> Save Current Public Database</h2><div style="font-size: 0.9em; color: #9fb1c9; margin-bottom: 12px;">
 Creates a snapshot of what's currently in the public schema.
 </div><form method="post" class="create-form"><input type="hidden" name="action" value="create"><label for="name">Snapshot name</label><br><input type="text" id="name" name="name" required style="width: 100%; margin: 6px 0;" placeholder="e.g., Before Quest X"><label for="notes">Notes (optional)</label><br><input type="text" id="notes" name="notes" style="width: 100%; margin: 6px 0;" placeholder="e.g., Level 25, just finished main quest"><div class="button-group"><button type="submit" class="button" style="background-color: rgb(1 53 166 / 90%); color: #fff;"> Save Snapshot</button></div></form></div><div class="content-section"><h2> Stored Snapshots</h2><div style="font-size: 0.9em; color: #9fb1c9; margin-bottom: 12px;">
 These snapshots are stored in separate database schemas. They are NOT actively used.<br>
 Click "Copy to Public" to replace your active database with a snapshot.
 </div><?php if (empty($profiles)) { ?><div style="text-align:center; color:#ccc; padding: 12px;">No snapshots yet. Create one from the left panel.</div><?php } else { ?><div class="backup-list" style="max-height: 420px; overflow-y:auto; padding: 0; margin: 0; border: 1px solid #333333; border-radius: 8px; background-color: #1a1a1a;"><?php foreach ($profiles as $p) {
 $nm = strtolower((string)($p['name'] ?? ''));
 $nt = strtolower((string)($p['notes'] ?? ''));
 $isTimeline = (strpos($nm,'timeline') !== false) || (strpos($nt,'timeline') !== false);
 ?><div class="backup-item<?php echo $isTimeline ? ' timelinebreak' : ''; ?>" style="padding: 12px; border-bottom: 1px solid #333333; <?php if ((int)$p['is_active'] === 1) { echo 'background: rgba(74, 222, 128, 0.1); border-left: 4px solid #4ade80;'; } ?>"><div style="display:flex; justify-content:space-between; gap: 10px;"><div style="flex:1; min-width:0;"><div style="font-weight:bold; font-size: 14px; word-break: break-all;"><?php if ((int)$p['is_active'] === 1) { ?><span style="background:#4ade80; color:#000; padding:2px 8px; border-radius:4px; font-size:11px; font-weight:bold; margin-right:6px;"> SOURCE OF PUBLIC</span><?php } ?><?php echo h($p['name']); ?><?php
 $lg = isset($p['last_gamets']) ? intval($p['last_gamets']) : 0;
 if ($liveLastGamets > 0 && $lg > 0) {
 if ($lg === $liveLastGamets) {
 echo '<span style="color:#9fb1c9; margin-left:6px;">| Current Time</span>';
 } elseif ($lg < $liveLastGamets) {
 $d = gamets2days_between($lg, $liveLastGamets);
 $txt = $d.' '.($d===1?'day':'days').' behind';
 echo '<span style="color:#dc2626; margin-left:6px;">| '.h($txt).'</span>';
 } else {
 $d = gamets2days_between($liveLastGamets, $lg);
 $txt = $d.' '.($d===1?'day':'days').' ahead';
 echo '<span style="color:#16a34a; margin-left:6px;">| '.h($txt).'</span>'; #16a34a
 }
 }
 ?></div><div style="font-size: 12px; color:#ccc; display:flex; gap:10px; flex-wrap:wrap;"><span><?php echo h($p['created_at']); ?></span><span><?php echo h(formatFileSize((int)$p['size_bytes'])); ?></span><span> Player: <?php echo h($p['player_name'] ?? ''); ?></span><span> Game: <?php echo h($p['game'] ?? 'Fallout'); ?></span><span> eventlog: <?php echo intval($p['eventlog_count'] ?? 0); ?></span><span> worldknowledge: <?php echo intval($p['worldknowledge_count'] ?? 0); ?></span><?php
 $lg = isset($p['last_gamets']) ? intval($p['last_gamets']) : 0;
 $skDate = $lg > 0 ? convert_gamets2fallout_long_date($lg) : '';
 ?><span> last in-game: <?php echo h($skDate); ?></span></div><?php if (!empty($p['notes'])) { ?><div style="font-size: 12px; color:#9fb1c9; margin-top: 4px; word-break: break-all;"><?php echo h($p['notes']); ?></div><?php } ?></div><div style="display:flex; gap:6px; align-items:flex-start;"><?php if ((int)$p['is_active'] !== 1) { ?><form method="post" class="switch-form"><input type="hidden" name="action" value="switch"><input type="hidden" name="profile_id" value="<?php echo (int)$p['id']; ?>"><button type="submit" class="button" style="background-color: rgb(1 53 166 / 90%); color:#fff; padding:6px 10px;">Copy to Public</button></form><?php } else { ?><button class="button" style="background-color: #333; color:#999; padding:6px 10px; cursor: not-allowed;" disabled>Already Active</button><?php } ?><?php $isDefault = (strtolower((string)$p['name']) === 'default'); ?><?php if (!$isDefault) { ?><form method="post" onsubmit="return confirm('Delete this snapshot? This action cannot be undone.');"><input type="hidden" name="action" value="delete"><input type="hidden" name="profile_id" value="<?php echo (int)$p['id']; ?>"><button type="submit" class="button" style="background-color: rgba(166, 53, 63, 0.9); color:#fff; padding:6px 10px;" <?php echo ((int)$p['is_active']===1? 'disabled':''); ?>>Delete</button></form><?php } ?></div></div></div><?php } ?></div><?php } ?></div></div></main><?php
$buffer = ob_get_contents();
ob_end_clean();
$title = $TITLE;
$buffer = preg_replace('/(<title>)(.*?)(<\/title>)/i', '$1' . $title . '$3', $buffer);
echo $buffer;
?><script>
(function(){
 const overlay = document.getElementById('switch-overlay');
 const overlayTitle = document.getElementById('loading-title');
 function showOverlay(){ if (overlay) overlay.style.display='flex'; }
 function hideOverlay(){ if (overlay) overlay.style.display='none'; }
 // Intercept switch submit to show confirm + overlay
 document.addEventListener('submit', function(e){
 const form = e.target;
 if (form && form.classList && form.classList.contains('switch-form')) {
 const ok = window.confirm('This will:\n\n1. Auto-save your current PUBLIC database to a snapshot\n2. Copy this snapshot INTO the PUBLIC schema\n3. You MUST restart the DIALECTIC server after\n\nThe snapshot itself stays in its schema.\n\nContinue?');
 if (!ok) { e.preventDefault(); return false; }
 if (overlayTitle) overlayTitle.textContent = 'Loading Snapshot';
 showOverlay();
 }
 if (form && form.classList && form.classList.contains('create-form')) {
 if (overlayTitle) overlayTitle.textContent = 'Creating Snapshot';
 showOverlay();
 }
 }, true);

 const items = <?php echo json_encode($timelineItems, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE); ?>;
 const ticks = <?php echo json_encode($timelineTicks, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE); ?>;
 if (!items || !items.length) return;
 const nodesEl = document.getElementById('pt-timeline-nodes');
 const notchesEl = document.getElementById('pt-timeline-notches');
 const trackEl = document.querySelector('#pt-timeline .timeline-track');
 const tooltip = document.getElementById('pt-tooltip');
 const minEl = document.getElementById('pt-min');
 const maxEl = document.getElementById('pt-max');
 const titleEl = document.getElementById('pt-title');
 if (!nodesEl || !trackEl) return;

 const values = items.map(i => i.last_gamets);
 const min = Math.min.apply(null, values);
 const max = Math.max.apply(null, values);
 const minItem = items.find(i => i.last_gamets === min);
 const maxItem = items.find(i => i.last_gamets === max);
 const minLabel = minItem ? minItem.fallout_date : String(min);
 const maxLabel = maxItem ? maxItem.fallout_date : String(max);
 minEl && (minEl.textContent = 'Earliest: ' + minLabel);
 maxEl && (maxEl.textContent = 'Latest: ' + maxLabel);

 function pct(x){
 if (max === min) return 50; // collapse to center if identical
 return ((x - min) / (max - min)) * 100;
 }

 function showTip(e, html){
 if (!tooltip) return;
 tooltip.innerHTML = html;
 tooltip.style.display = 'block';
 const rect = nodesEl.getBoundingClientRect();
 const x = e.clientX - rect.left + 10;
 const y = e.clientY - rect.top + 14;
 tooltip.style.left = x + 'px';
 tooltip.style.top = y + 'px';
 }
 function hideTip(){ if (tooltip) tooltip.style.display = 'none'; }

 items.sort((a,b) => a.last_gamets - b.last_gamets);
 items.forEach(it => {
 const node = document.createElement('div');
 node.className = 'timeline-node' + (it.is_active ? ' active' : '');
 node.style.left = pct(it.last_gamets) + '%';
 node.setAttribute('role','button');
 node.setAttribute('tabindex','0');
 const tip = `<div class="name">${escapeHtml(it.name)}</div><div>Fallout date: ${escapeHtml(it.fallout_date)}</div><div>Created: ${escapeHtml(it.created_at)}</div><div>Size: ${escapeHtml(it.size)}</div>`;
 node.addEventListener('mouseenter', (e)=>showTip(e, tip));
 node.addEventListener('mousemove', (e)=>showTip(e, tip));
 node.addEventListener('mouseleave', hideTip);
 nodesEl.appendChild(node);
 });

 // Position "You are here" marker at the active profile's position
 // Blue active node styling remains; no arrow/label needed

 // Static ticks (major/minor) with labels aligned to gamets scale
 if (notchesEl && ticks && ticks.length) {
 const values = items.map(i => i.last_gamets);
 const min = Math.min.apply(null, values);
 const max = Math.max.apply(null, values);
 const isDegenerate = (max === min);
 const pct = (x) => isDegenerate ? 50 : ((x - min) / (max - min)) * 100;
 ticks.forEach((t, idx) => {
 const notch = document.createElement('div');
 notch.className = 'timeline-notch' + ((idx % 2 === 0) ? ' major' : '');
 notch.style.left = pct(t.gamets) + '%';
 notchesEl.appendChild(notch);
 // Add labels for major interior ticks only (skip first and last)
 if (idx % 2 === 0 && idx > 0 && idx < (ticks.length - 1)) {
 const lbl = document.createElement('div');
 lbl.className = 'timeline-tick-label';
 lbl.style.left = pct(t.gamets) + '%';
 lbl.textContent = t.date;
 notchesEl.appendChild(lbl);
 }
 });
 }

 document.addEventListener('scroll', hideTip, { passive:true });
 window.addEventListener('resize', hideTip, { passive:true });

 function escapeHtml(s){
 return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;').replace(/'/g,'&#039;');
 }
 // Async refresh of live stats to avoid heavy COUNT(*) on render
 try {
 fetch('<?php echo $webRoot; ?>/ui/playthrough_stats.php', { credentials:'same-origin' })
 .then(r => r.ok ? r.json() : null)
 .then(j => {
 if (!j) return;
 const ev = document.getElementById('live-eventlog');
 const og = document.getElementById('live-worldknowledge');
 const la = document.getElementById('live-last');
 if (typeof j.eventlog === 'number' && ev) ev.textContent = String(j.eventlog);
 if (typeof j.worldknowledge === 'number' && og) og.textContent = String(j.worldknowledge);
 if (typeof j.last_fallout_date === 'string' && la) la.textContent = j.last_fallout_date || 'n/a';
 })
 .catch(()=>{});
 } catch (_e) {}
})();
</script>


