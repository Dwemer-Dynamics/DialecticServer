<?php

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

date_default_timezone_set('UTC');
error_reporting(E_ALL);
ini_set('display_errors', '1');

$enginePath = dirname(__DIR__) . DIRECTORY_SEPARATOR;

require_once($enginePath . "lib" . DIRECTORY_SEPARATOR . "runtime_bootstrap.php");
require_once($enginePath . "lib" . DIRECTORY_SEPARATOR . "logger.php");
require_once($enginePath . "lib" . DIRECTORY_SEPARATOR . "background_processor.php");
require_once($enginePath . "lib" . DIRECTORY_SEPARATOR . "fallout_stats.php");

dialecticRuntimeBootstrap($enginePath, [
    'load_general_settings' => true,
    'load_stt_connector' => false,
]);

$scriptPath = $_SERVER['SCRIPT_NAME'] ?? '';
$uiPos = strpos($scriptPath, '/ui/');
if ($uiPos !== false) {
    $webRoot = substr($scriptPath, 0, $uiPos);
} else {
    $webRoot = '';
}
if ($webRoot === '/') {
    $webRoot = '';
}
$webRoot = rtrim($webRoot, '/');

require_once(__DIR__ . DIRECTORY_SEPARATOR . "profile_loader.php");

if (count($_GET) === 0 && function_exists('dialecticEnsureBackgroundProcessorRunning')) {
    dialecticEnsureBackgroundProcessorRunning(true);
}

$TITLE = "DIALECTIC Home";
$db = (isset($GLOBALS["db"]) && is_object($GLOBALS["db"])) ? $GLOBALS["db"] : new sql();
$GLOBALS["db"] = $db;

function dialectic_home_h($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function dialectic_home_table_exists($db, string $table): bool
{
    try {
        $safeTable = str_replace("'", "''", $table);
        $row = $db->fetchOne("SELECT EXISTS (SELECT 1 FROM information_schema.tables WHERE table_schema='public' AND table_name='{$safeTable}') AS exists");
        return isset($row['exists']) && ($row['exists'] === true || $row['exists'] === 't' || $row['exists'] === '1' || $row['exists'] === 1);
    } catch (Throwable $e) {
        return false;
    }
}

function dialectic_home_table_columns($db, string $table): array
{
    try {
        $safeTable = str_replace("'", "''", $table);
        $rows = $db->fetchAll("SELECT column_name FROM information_schema.columns WHERE table_schema='public' AND table_name='{$safeTable}'");
        if (!is_array($rows)) {
            return [];
        }
        return array_map(static fn($row) => (string)($row['column_name'] ?? ''), $rows);
    } catch (Throwable $e) {
        if (class_exists('Logger')) {
            Logger::warn("Home dashboard column query failed: " . $e->getMessage());
        }
    }
    return [];
}

function dialectic_home_value($db, string $query, string $key, $fallback = null)
{
    try {
        $row = $db->fetchOne($query);
        if (is_array($row) && array_key_exists($key, $row)) {
            return $row[$key];
        }
    } catch (Throwable $e) {
        if (class_exists('Logger')) {
            Logger::warn("Home dashboard query failed: " . $e->getMessage());
        }
    }
    return $fallback;
}

function dialectic_home_rows($db, string $query): array
{
    try {
        $rows = $db->fetchAll($query);
        return is_array($rows) ? $rows : [];
    } catch (Throwable $e) {
        if (class_exists('Logger')) {
            Logger::warn("Home dashboard list query failed: " . $e->getMessage());
        }
    }
    return [];
}

function dialectic_home_number($value): string
{
    if ($value === null || $value === '') {
        return '0';
    }
    return number_format((int)$value);
}

function dialectic_home_timestamp($value): string
{
    if ($value === null || $value === '') {
        return 'No events yet';
    }
    $timestamp = (int)$value;
    if ($timestamp <= 0) {
        return 'No events yet';
    }
    return date('d-m-Y H:i:s', $timestamp);
}

function dialectic_home_json_object($value): array
{
    if (is_array($value)) {
        return $value;
    }
    $decoded = json_decode((string)$value, true);
    return is_array($decoded) ? $decoded : [];
}

function dialectic_home_people($value): string
{
    $people = array_values(array_filter(array_map('trim', explode('|', (string)$value)), static fn($name) => $name !== ''));
    return empty($people) ? 'No actors reported' : implode(', ', array_unique($people));
}

function dialectic_home_game_date(array $gameTime): string
{
    $year = (int)($gameTime['year'] ?? 0);
    $month = (int)($gameTime['month'] ?? 0);
    $day = (int)($gameTime['day'] ?? 0);
    if ($year <= 0 || $month < 1 || $month > 12 || $day <= 0) {
        return 'Not reported';
    }
    $months = [1 => 'January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'];
    return $months[$month] . ' ' . $day . ', ' . $year;
}

function dialectic_home_game_time(array $gameTime): string
{
    if (!isset($gameTime['hour']) || !is_numeric($gameTime['hour'])) {
        return 'Not reported';
    }
    $rawHour = max(0.0, min(23.999, (float)$gameTime['hour']));
    $hour = (int)floor($rawHour);
    $minute = (int)floor(($rawHour - $hour) * 60.0);
    $suffix = $hour >= 12 ? 'PM' : 'AM';
    $displayHour = $hour % 12;
    if ($displayHour === 0) {
        $displayHour = 12;
    }
    return sprintf('%d:%02d %s', $displayHour, $minute, $suffix);
}

function dialectic_home_llm_stats(array $row): string
{
    $success = (int)($row['success'] ?? 0);
    $total = (int)($row['total'] ?? 0);
    $percent = $total > 0 ? (int)round(($success / $total) * 100) : 0;
    return number_format($success) . '/' . number_format($total) . ' (' . $percent . '%)';
}

function dialectic_home_event_excerpt(?string $text): string
{
    $clean = trim((string)$text);
    if ($clean === '') {
        return 'No event data recorded yet.';
    }
    $clean = preg_replace('/\s+/', ' ', $clean);
    if (strlen($clean) > 180) {
        return substr($clean, 0, 177) . '...';
    }
    return $clean;
}

function dialectic_home_word_cloud_words(array $rows): array
{
    $processedText = [];
    $stopWords = [
        'the', 'and', 'you', 'that', 'for', 'with', 'this', 'have', 'but', 'not', 'are', 'your', 'just', 'from',
        'they', 'was', 'what', 'all', 'can', 'out', 'about', 'there', 'will', 'would', 'could', 'should', 'when',
        'where', 'which', 'their', 'them', 'then', 'than', 'into', 'onto', 'over', 'under', 'back', 'been', 'were',
        'his', 'her', 'she', 'him', 'our', 'who', 'why', 'how', 'did', 'does', 'dont', 'cant', 'wont', 'im', 'ive',
        'its', 'thats', 'youre', 'lets', 'okay', 'yeah', 'yes', 'no', 'hey', 'hello', 'hi', 'hmm', 'uh', 'um',
        'context', 'location', 'background', 'chat', 'talking', 'graussy', 'player', 'npc', 'said', 'says'
    ];

    foreach ($rows as $row) {
        $text = html_entity_decode((string)($row['data'] ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace('/\{.*?\}/', ' ', $text);
        $text = preg_replace('/\(Context location:.*?\)/i', ' ', $text);
        $text = preg_replace('/\([^)]*talking to[^)]*\)/i', ' ', $text);
        $text = preg_replace('/[^a-zA-Z\'\s]/', ' ', $text);
        $text = strtolower($text ?? '');
        $words = preg_split('/\s+/', $text, -1, PREG_SPLIT_NO_EMPTY);
        foreach ($words as $word) {
            $word = trim($word, "'");
            if (strlen($word) > 2 && !in_array($word, $stopWords, true)) {
                $processedText[] = $word;
            }
        }
    }

    if (empty($processedText)) {
        return [];
    }

    $wordFrequencies = array_count_values($processedText);
    arsort($wordFrequencies);
    $wordFrequencies = array_slice($wordFrequencies, 0, 100, true);

    $words = [];
    foreach ($wordFrequencies as $word => $count) {
        $words[] = [
            'text' => $word,
            'size' => log(max(1, $count) * 5) * 8 + 20,
            'count' => $count,
        ];
    }
    return $words;
}

$hasEventlog = dialectic_home_table_exists($db, 'eventlog');
$hasNpcMaster = dialectic_home_table_exists($db, 'core_npc_master');
$hasDiary = dialectic_home_table_exists($db, 'diarylog');
$hasMemorySummary = dialectic_home_table_exists($db, 'memory_summary');
$hasConfOpts = dialectic_home_table_exists($db, 'conf_opts');
$hasTtsConnector = dialectic_home_table_exists($db, 'core_tts_connector');
$hasLlmConnector = dialectic_home_table_exists($db, 'core_llm_connector');
$hasLocations = dialectic_home_table_exists($db, 'locations');
$hasGamePlugins = dialectic_home_table_exists($db, 'game_plugins');
$hasQuests = dialectic_home_table_exists($db, 'quests');
$hasAuditRequest = dialectic_home_table_exists($db, 'audit_request');

$eventCount = $hasEventlog ? dialectic_home_value($db, "SELECT COUNT(*) AS total FROM eventlog", 'total', 0) : 0;
$chatCount = $hasEventlog ? dialectic_home_value($db, "SELECT COUNT(*) AS total FROM eventlog WHERE type='chat'", 'total', 0) : 0;
$npcCount = $hasNpcMaster ? dialectic_home_value($db, "SELECT COUNT(*) AS total FROM core_npc_master", 'total', 0) : 0;
$diaryCount = $hasDiary ? dialectic_home_value($db, "SELECT COUNT(*) AS total FROM diarylog", 'total', 0) : 0;
$memoryCount = $hasMemorySummary ? dialectic_home_value($db, "SELECT COUNT(*) AS total FROM memory_summary", 'total', 0) : 0;

$latestEventRow = $hasEventlog ? dialectic_home_rows($db, "SELECT type, data, people, location, gamets, localts, ts FROM eventlog ORDER BY ts DESC LIMIT 1") : [];
$latestEvent = $latestEventRow[0] ?? [];
$recentDialogue = $hasEventlog ? dialectic_home_rows($db, "SELECT type, data, people, location, gamets, localts, ts FROM eventlog WHERE type IN ('chat', 'inputtext', 'backgroundchat') ORDER BY ts DESC LIMIT 5") : [];
$eventTypeRows = $hasEventlog ? dialectic_home_rows($db, "SELECT type, COUNT(*) AS event_count FROM eventlog GROUP BY type ORDER BY COUNT(*) DESC, type ASC") : [];
$wordSourceRows = $hasEventlog ? dialectic_home_rows($db, "SELECT data FROM eventlog WHERE type IN ('chat', 'inputtext', 'backgroundchat') ORDER BY ts DESC LIMIT 500") : [];
$wordCloudWords = dialectic_home_word_cloud_words($wordSourceRows);
$worldRows = $hasEventlog ? dialectic_home_rows($db, "SELECT data, location, party, gamets, localts, ts FROM eventlog WHERE type='world_context' ORDER BY gamets DESC, ts DESC LIMIT 1") : [];
$worldContext = isset($worldRows[0]) ? dialectic_home_json_object($worldRows[0]['party'] ?? '') : [];
$worldGameTime = dialectic_home_json_object($worldContext['game_time'] ?? []);
$activeQuests = $hasQuests ? dialectic_home_rows($db, "SELECT name, id_quest, briefing, briefing2, status FROM quests ORDER BY CASE WHEN status='selected' THEN 0 ELSE 1 END, gamets DESC LIMIT 6") : [];
$latestDiaryRows = $hasDiary ? dialectic_home_rows($db, "SELECT rowid, topic, content, people, location, localts, gamets FROM diarylog ORDER BY gamets DESC, rowid DESC LIMIT 1") : [];
$latestDiary = $latestDiaryRows[0] ?? [];
$llmStats = [
    '24h' => ['success' => 0, 'total' => 0],
    '72h' => ['success' => 0, 'total' => 0],
    '7d' => ['success' => 0, 'total' => 0],
    'lifetime' => ['success' => 0, 'total' => 0],
];
if ($hasAuditRequest) {
    foreach ([
        '24h' => "created_at >= NOW() - INTERVAL '24 HOURS'",
        '72h' => "created_at >= NOW() - INTERVAL '72 HOURS'",
        '7d' => "created_at >= NOW() - INTERVAL '7 DAYS'",
        'lifetime' => 'TRUE',
    ] as $period => $condition) {
        $rows = dialectic_home_rows($db, "SELECT COUNT(*) FILTER (WHERE result='Ok') AS success, COUNT(*) AS total FROM audit_request WHERE {$condition}");
        if (isset($rows[0])) {
            $llmStats[$period] = $rows[0];
        }
    }
}
$locationRows = [];
$locationCount = 0;
if ($hasLocations) {
    $locationColumns = dialectic_home_table_columns($db, 'locations');
    $locationSelect = ['name', 'formid'];
    if (in_array('worldspace', $locationColumns, true)) {
        $locationSelect[] = 'worldspace';
    }
    if (in_array('updated_at', $locationColumns, true)) {
        $locationSelect[] = 'updated_at';
    }
    $locationCount = dialectic_home_value($db, "SELECT COUNT(*) AS total FROM locations", 'total', 0);
    $locationRows = dialectic_home_rows($db, "SELECT " . implode(', ', $locationSelect) . " FROM locations ORDER BY name ASC LIMIT 250");
}
$gamePluginRows = [];
$gamePluginCount = 0;
if ($hasGamePlugins) {
    $gamePluginColumns = dialectic_home_table_columns($db, 'game_plugins');
    $gamePluginSelect = [];
    foreach (['plugin_name', 'is_light', 'compile_index', 'small_file_compile_index', 'formid_prefix'] as $columnName) {
        if (in_array($columnName, $gamePluginColumns, true)) {
            $gamePluginSelect[] = $columnName;
        }
    }
    if (empty($gamePluginSelect)) {
        $gamePluginSelect[] = '*';
    }
    $gamePluginCount = dialectic_home_value($db, "SELECT COUNT(*) AS total FROM game_plugins", 'total', 0);
    $gamePluginOrder = in_array('plugin_name', $gamePluginColumns, true) ? " ORDER BY plugin_name ASC" : "";
    $gamePluginRows = dialectic_home_rows($db, "SELECT " . implode(', ', $gamePluginSelect) . " FROM game_plugins" . $gamePluginOrder . " LIMIT 250");
}

$playerName = isset($GLOBALS['PLAYER_NAME']) && trim((string)$GLOBALS['PLAYER_NAME']) !== '' ? (string)$GLOBALS['PLAYER_NAME'] : 'Player';
try {
    require_once($enginePath . "lib" . DIRECTORY_SEPARATOR . "core" . DIRECTORY_SEPARATOR . "player.class.php");
    $playerHelper = new Player();
    $storedPlayerName = $playerHelper->get('player_name');
    if ($storedPlayerName !== null && trim((string)$storedPlayerName) !== '') {
        $playerName = (string)$storedPlayerName;
    }
} catch (Throwable $e) {
    // Keep runtime fallback.
}

$currentMode = $hasConfOpts ? dialectic_home_value($db, "SELECT value FROM conf_opts WHERE id='dialectic_mode' LIMIT 1", 'value', 'STANDARD') : 'STANDARD';
$currentModelSlot = $hasConfOpts ? (int)dialectic_home_value($db, "SELECT value FROM conf_opts WHERE id='dialectic_profile_model' LIMIT 1", 'value', 1) : 1;
$modelLabels = [1 => 'Standard', 2 => 'Fast', 3 => 'Powerful', 4 => 'Experimental'];
$currentModel = $modelLabels[$currentModelSlot] ?? 'Standard';
$ttsConnectorCount = $hasTtsConnector ? dialectic_home_value($db, "SELECT COUNT(*) AS total FROM core_tts_connector", 'total', 0) : 0;
$llmConnectorCount = $hasLlmConnector ? dialectic_home_value($db, "SELECT COUNT(*) AS total FROM core_llm_connector", 'total', 0) : 0;
$currentModeLabel = ucwords(strtolower(str_replace('_', ' ', (string)$currentMode)));
$worldLocation = trim((string)($worldContext['location'] ?? ''));
$worldspace = trim((string)($worldContext['worldspace'] ?? ''));
$worldWeather = trim((string)($worldContext['weather'] ?? ''));
$worldState = empty($worldContext) ? 'Not reported' : (!empty($worldContext['is_interior']) ? 'Interior' : 'Exterior');
$worldDate = dialectic_home_game_date($worldGameTime);
$worldTime = dialectic_home_game_time($worldGameTime);
$latestLocalTimestamp = $latestEvent['localts'] ?? ($latestEvent['ts'] ?? null);
$falloutStatCategories = dialecticFalloutStatCategories();
$falloutStats = [];
if ($hasConfOpts) {
    $statIds = array_map(static function (string $statName) use ($db): string {
        return "'" . $db->escape($statName) . "'";
    }, dialecticFalloutStatNames());
    if ($statIds !== []) {
        foreach (dialectic_home_rows($db, "SELECT id, value FROM conf_opts WHERE id IN (" . implode(',', $statIds) . ")") as $statRow) {
            $statName = (string)($statRow['id'] ?? '');
            if ($statName !== '' && is_numeric($statRow['value'] ?? null)) {
                $falloutStats[$statName] = max(0, (int)$statRow['value']);
            }
        }
    }
}

ob_start();
include(__DIR__ . DIRECTORY_SEPARATOR . "tmpl" . DIRECTORY_SEPARATOR . "head.html");
?>
<link rel="stylesheet" href="<?php echo dialectic_home_h($webRoot); ?>/ui/css/main.css">
<style>
    :root {
        --dialectic-accent: rgb(255, 182, 65);
        --dialectic-accent-soft: rgba(255, 182, 65, 0.14);
        --dialectic-surface: #2d2d2d;
        --dialectic-surface-dark: #1a1a1a;
        --dialectic-surface-soft: #242424;
        --dialectic-border: #3a3a3a;
        --dialectic-muted: #9b9b9b;
    }

    body {
        background: #2c2c2c;
        color: #f2f2f2;
    }

    .dashboard-shell {
        padding: 18px 10px 52px;
    }

    .home-version-info {
        display: flex;
        flex-wrap: wrap;
        gap: 4px 18px;
        margin: 0 0 6px;
        color: var(--dialectic-muted);
        font-size: 0.9rem;
    }

    .home-heading {
        display: flex;
        justify-content: space-between;
        align-items: flex-end;
        gap: 16px;
        margin: 8px 0 12px;
    }

    .home-heading h1 {
        margin: 0;
        color: #f8f9fa;
        font-size: 2rem;
        font-weight: 400;
        line-height: 1.1;
    }

    .player-pill {
        display: inline-flex;
        align-items: center;
        gap: 8px;
        padding: 8px 12px;
        border: 1px solid var(--dialectic-border);
        background: var(--dialectic-surface);
        color: #f7f7f7;
        border-radius: 6px;
        white-space: nowrap;
    }

    .player-pill i {
        color: var(--dialectic-accent);
    }

    .dashboard-container {
        display: grid;
        grid-template-columns: repeat(2, minmax(0, 1fr));
        gap: 20px;
        padding: 0;
        align-items: start;
    }

    .widget {
        background: var(--dialectic-surface);
        border-radius: 8px;
        box-shadow: 0 2px 4px rgba(0, 0, 0, 0.2);
        overflow: hidden;
        border: 1px solid rgba(255, 255, 255, 0.04);
    }

    .widget-wide {
        grid-column: 1 / -1;
    }

    .widget-header {
        background: var(--dialectic-surface-dark);
        padding: 14px 15px;
        border-bottom: 1px solid var(--dialectic-border);
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 8px;
    }

    .widget-header h3 {
        margin: 0;
        color: #f8f9fa;
        font-size: 1.05rem;
        font-weight: 400;
        line-height: 1.2;
    }

    .widget-header i {
        color: var(--dialectic-accent);
    }

    .widget-content {
        padding: 15px;
        color: #d4d4d4;
    }

    .widget-stats {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(145px, 1fr));
        gap: 15px;
    }

    .stat-card {
        background: var(--dialectic-surface-dark);
        padding: 15px;
        border-radius: 4px;
        text-align: center;
        border: 1px solid rgba(255, 255, 255, 0.04);
    }

    .stat-card.action-card {
        cursor: pointer;
        transition: border-color 120ms ease, background 120ms ease, transform 120ms ease;
    }

    .stat-card.action-card:hover,
    .stat-card.action-card:focus {
        background: #242424;
        border-color: rgba(255, 182, 65, 0.45);
        outline: none;
        transform: translateY(-1px);
    }

    .stat-value {
        font-size: 1.55rem;
        font-weight: 400;
        color: var(--dialectic-accent);
        line-height: 1.1;
    }

    .stat-label {
        font-size: 0.86rem;
        color: var(--dialectic-muted);
        margin-top: 6px;
    }

    .fallout-stats-grid {
        display: grid;
        grid-template-columns: repeat(3, minmax(0, 1fr));
        gap: 12px;
    }

    .fallout-stats-category {
        background: var(--dialectic-surface-dark);
        border: 1px solid rgba(255, 255, 255, 0.05);
        border-radius: 6px;
        padding: 13px;
    }

    .fallout-stats-category h4 {
        color: var(--dialectic-accent);
        font-size: 0.94rem;
        font-weight: 400;
        margin: 0 0 9px;
    }

    .fallout-stat-row {
        display: grid;
        grid-template-columns: minmax(0, 1fr) auto;
        align-items: baseline;
        gap: 12px;
        padding: 5px 0;
        border-top: 1px solid rgba(255, 255, 255, 0.045);
        color: #d4d4d4;
        font-size: 0.84rem;
    }

    .fallout-stat-row:first-of-type {
        border-top: 0;
    }

    .fallout-stat-row strong {
        color: #f7f7f7;
        font-weight: 400;
        font-variant-numeric: tabular-nums;
    }

    .widget-table {
        width: 100%;
        border-collapse: collapse;
        margin: 0;
    }

    .widget-table th,
    .widget-table td {
        padding: 8px;
        text-align: left;
        border-bottom: 1px solid var(--dialectic-border);
        vertical-align: top;
        color: #d4d4d4;
    }

    .widget-table th {
        background: var(--dialectic-surface-dark);
        color: #f8f9fa;
        font-weight: 400;
    }

    .widget-table tr:last-child td {
        border-bottom: 0;
    }

    .table-wrap {
        width: 100%;
        overflow-x: auto;
    }

    .section-label {
        margin: 0 0 10px;
        color: var(--dialectic-accent);
        font-size: 0.95rem;
        font-weight: 400;
    }

    .quest-list {
        margin-top: 18px;
        padding-top: 16px;
        border-top: 1px solid var(--dialectic-border);
    }

    .quest-status {
        display: inline-flex;
        align-items: center;
        gap: 6px;
        color: #d4d4d4;
        white-space: nowrap;
    }

    .quest-status::before {
        content: '';
        width: 7px;
        height: 7px;
        border-radius: 50%;
        background: #777;
    }

    .quest-status.is-selected::before {
        background: var(--dialectic-accent);
        box-shadow: 0 0 0 3px var(--dialectic-accent-soft);
    }

    .dialogue-line {
        line-height: 1.45;
        min-width: 260px;
    }

    .dialogue-meta {
        margin-top: 4px;
        color: var(--dialectic-muted);
        font-size: 0.8rem;
    }

    .dialogue-time {
        white-space: nowrap;
    }

    .diary-entry {
        background: var(--dialectic-surface-dark);
        border-left: 3px solid var(--dialectic-accent);
        border-radius: 4px;
        padding: 18px 20px;
    }

    .diary-entry-header {
        display: flex;
        justify-content: space-between;
        gap: 16px;
        color: var(--dialectic-muted);
        font-size: 0.82rem;
        margin-bottom: 12px;
    }

    .diary-entry h4 {
        color: var(--dialectic-accent);
        font-size: 1rem;
        font-weight: 400;
        margin: 0 0 10px;
    }

    .diary-entry p {
        color: #e2e2e2;
        line-height: 1.55;
        margin: 0;
        white-space: pre-wrap;
    }

    .latest-diary-audio-controls {
        align-items: center;
        display: flex;
        gap: 12px;
        justify-content: center;
        padding-top: 14px;
    }

    body .latest-diary-audio-button {
        background: var(--dialectic-accent) !important;
        border-color: rgb(218, 145, 28) !important;
        color: #111 !important;
    }

    body .latest-diary-audio-button:hover:not(:disabled) {
        background: rgb(218, 145, 28) !important;
    }

    body .latest-diary-audio-button:disabled {
        cursor: wait;
        opacity: 0.7;
    }

    .latest-diary-audio-status {
        color: var(--dialectic-muted);
        font-size: 0.9rem;
    }

    .stat-card.stat-period {
        cursor: pointer;
        grid-column: span 2;
    }

    .stat-period-value[hidden],
    .stat-period-label[hidden] {
        display: none;
    }

    .event-type {
        display: inline-flex;
        align-items: center;
        border: 1px solid rgba(255, 182, 65, 0.28);
        background: var(--dialectic-accent-soft);
        color: #ffd58a;
        border-radius: 6px;
        padding: 2px 7px;
        font-size: 0.78rem;
    }

    .muted {
        color: var(--dialectic-muted);
    }

    .empty-state {
        color: var(--dialectic-muted);
        border: 1px dashed rgba(255, 255, 255, 0.12);
        border-radius: 6px;
        padding: 18px;
        margin: 0;
    }

    .word-cloud-container {
        background: var(--dialectic-surface-dark);
        border: 1px solid rgba(255, 255, 255, 0.05);
        border-radius: 8px;
        padding: 16px;
        position: relative;
    }

    .word-count-display {
        text-align: center;
        padding: 6px 10px 14px;
        font-size: 1.1rem;
        color: var(--dialectic-accent);
        min-height: 42px;
        font-weight: 400;
    }

    #dialectic-word-cloud {
        width: 100%;
        height: 360px;
        display: block;
    }

    .word-cloud-text {
        font-family: 'Monofonto', Arial, sans-serif;
        cursor: pointer;
        transition: opacity 120ms ease;
    }

    .word-cloud-text:hover {
        opacity: 0.72;
    }

    .dashboard-modal {
        display: none;
        position: fixed;
        inset: 0;
        z-index: 1000;
        background: rgba(0, 0, 0, 0.72);
        padding: 7vh 20px;
        overflow: auto;
    }

    .dashboard-modal.is-open {
        display: block;
    }

    .dashboard-modal-content {
        width: min(980px, 100%);
        margin: 0 auto;
        background: var(--dialectic-surface);
        border: 1px solid var(--dialectic-border);
        border-radius: 8px;
        box-shadow: 0 18px 48px rgba(0, 0, 0, 0.45);
    }

    .dashboard-modal-header {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 12px;
        padding: 14px 16px;
        background: var(--dialectic-surface-dark);
        border-bottom: 1px solid var(--dialectic-border);
    }

    .dashboard-modal-header h3 {
        margin: 0;
        font-size: 1.05rem;
        color: #f8f9fa;
    }

    .dashboard-modal-close {
        border: 1px solid var(--dialectic-border);
        background: #242424;
        color: #f8f9fa;
        border-radius: 6px;
        min-width: 34px;
        height: 34px;
        line-height: 1;
    }

    .dashboard-modal-body {
        padding: 15px;
        max-height: 70vh;
        overflow: auto;
    }

    @media (max-width: 720px) {
        .home-heading {
            align-items: flex-start;
            flex-direction: column;
        }

        .dashboard-container {
            grid-template-columns: minmax(0, 1fr);
            gap: 14px;
        }

        .widget-wide {
            grid-column: auto;
        }

        .widget-stats {
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 10px;
        }

        .fallout-stats-grid {
            grid-template-columns: minmax(0, 1fr);
        }

        .stat-card {
            padding: 12px 8px;
        }

        .diary-entry-header {
            flex-direction: column;
            gap: 4px;
        }

        #dialectic-word-cloud {
            height: 300px;
        }
    }
</style>
<?php
$debugPaneLink = false;
include(__DIR__ . DIRECTORY_SEPARATOR . "tmpl" . DIRECTORY_SEPARATOR . "navbar.php");
?>
<main class="container dashboard-shell">
    <div class="home-version-info" aria-label="Dialectic versions">
        <span>Server: <?php echo dialectic_home_h($serverVersionDisplay ?? 'N/A'); ?></span>
        <span>Plugin: <?php echo dialectic_home_h($pluginVersionDisplay ?? 'N/A'); ?></span>
    </div>
    <div class="home-heading">
        <h1>Dialectic Dashboard</h1>
        <div class="player-pill">
            <i class="bi bi-person-circle"></i>
            <span><?php echo dialectic_home_h($playerName); ?></span>
        </div>
    </div>

    <section class="dashboard-container" aria-label="Dialectic dashboard">
        <article class="widget">
            <div class="widget-header">
                <h3><i class="bi bi-info-circle"></i> Current Playthrough</h3>
            </div>
            <div class="widget-content">
                <h4 class="section-label">World Information</h4>
                <div class="table-wrap">
                    <table class="widget-table">
                        <tr><th>State</th><th>Value</th></tr>
                        <tr><td>Player Name</td><td><?php echo dialectic_home_h($playerName); ?></td></tr>
                        <tr><td>Last Played (UTC)</td><td><?php echo dialectic_home_h(dialectic_home_timestamp($latestLocalTimestamp)); ?></td></tr>
                        <tr><td>In-Game Date</td><td><?php echo dialectic_home_h($worldDate); ?></td></tr>
                        <tr><td>In-Game Time</td><td><?php echo dialectic_home_h($worldTime); ?></td></tr>
                        <tr><td>Location</td><td><?php echo dialectic_home_h($worldLocation !== '' ? $worldLocation : 'Not reported'); ?></td></tr>
                        <?php if ($worldspace !== ''): ?>
                            <tr><td>Worldspace</td><td><?php echo dialectic_home_h($worldspace); ?></td></tr>
                        <?php endif; ?>
                        <tr><td>Area</td><td><?php echo dialectic_home_h($worldState); ?></td></tr>
                        <?php if ($worldWeather !== ''): ?>
                            <tr><td>Weather</td><td><?php echo dialectic_home_h($worldWeather); ?></td></tr>
                        <?php endif; ?>
                        <tr><td>Dialectic Mode</td><td><?php echo dialectic_home_h($currentModeLabel); ?></td></tr>
                        <tr><td>Active Model</td><td><?php echo dialectic_home_h($currentModel); ?></td></tr>
                    </table>
                </div>
                <div class="quest-list">
                    <h4 class="section-label">Current Quests</h4>
                    <div class="table-wrap">
                        <table class="widget-table">
                            <tr><th>Quest</th><th>Current Objective</th><th>Status</th></tr>
                            <?php if (empty($activeQuests)): ?>
                                <tr><td colspan="3" class="muted">No active quests reported.</td></tr>
                            <?php else: ?>
                                <?php foreach ($activeQuests as $quest): ?>
                                    <?php
                                    $questStatus = strtolower(trim((string)($quest['status'] ?? 'active')));
                                    $objective = trim((string)($quest['briefing2'] ?? ''));
                                    if ($objective === '') {
                                        $objective = trim((string)($quest['briefing'] ?? ''));
                                    }
                                    ?>
                                    <tr>
                                        <td><?php echo dialectic_home_h($quest['name'] ?? 'Unnamed Quest'); ?></td>
                                        <td><?php echo dialectic_home_h($objective !== '' ? $objective : 'No current objective reported.'); ?></td>
                                        <td><span class="quest-status<?php echo $questStatus === 'selected' ? ' is-selected' : ''; ?>"><?php echo dialectic_home_h(ucfirst($questStatus ?: 'active')); ?></span></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </table>
                    </div>
                </div>
            </div>
        </article>

        <article class="widget">
            <div class="widget-header">
                <h3><i class="bi bi-chat-left-text"></i> Recent Dialogue</h3>
            </div>
            <div class="widget-content">
                <?php if (empty($recentDialogue)): ?>
                    <p class="empty-state">No dialogue has been recorded yet.</p>
                <?php else: ?>
                    <div class="table-wrap">
                        <table class="widget-table">
                            <tr><th>Dialogue</th><th>People</th><th class="dialogue-time">Time (UTC)</th></tr>
                            <?php foreach ($recentDialogue as $event): ?>
                                <tr>
                                    <td class="dialogue-line">
                                        <?php echo dialectic_home_h(dialectic_home_event_excerpt($event['data'] ?? '')); ?>
                                        <?php if (!empty($event['location'])): ?>
                                            <div class="dialogue-meta"><?php echo dialectic_home_h($event['location']); ?></div>
                                        <?php endif; ?>
                                    </td>
                                    <td><?php echo dialectic_home_h(dialectic_home_people($event['people'] ?? '')); ?></td>
                                    <td class="dialogue-time"><?php echo dialectic_home_h(dialectic_home_timestamp($event['localts'] ?? ($event['ts'] ?? null))); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </article>

        <article class="widget widget-wide">
            <div class="widget-header">
                <h3><i class="bi bi-bar-chart"></i> Dialectic Stats</h3>
            </div>
            <div class="widget-content">
                <div class="widget-stats">
                    <div class="stat-card action-card" role="button" tabindex="0" data-dashboard-modal-target="eventTypesModal">
                        <div class="stat-value"><?php echo dialectic_home_number($eventCount); ?></div>
                        <div class="stat-label">Total Events</div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-value"><?php echo dialectic_home_number($chatCount); ?></div>
                        <div class="stat-label">Chat Entries</div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-value"><?php echo dialectic_home_number($npcCount); ?></div>
                        <div class="stat-label">NPC Profiles</div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-value"><?php echo dialectic_home_number($memoryCount); ?></div>
                        <div class="stat-label">Memory Summaries</div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-value"><?php echo dialectic_home_number($diaryCount); ?></div>
                        <div class="stat-label">Diary Entries</div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-value"><?php echo dialectic_home_number(((int)$ttsConnectorCount + (int)$llmConnectorCount)); ?></div>
                        <div class="stat-label"><?php echo dialectic_home_number($llmConnectorCount); ?> LLM / <?php echo dialectic_home_number($ttsConnectorCount); ?> TTS</div>
                    </div>
                    <div class="stat-card action-card" role="button" tabindex="0" data-dashboard-modal-target="locationsModal">
                        <div class="stat-value"><?php echo dialectic_home_number($locationCount); ?></div>
                        <div class="stat-label">Travel To Locations</div>
                    </div>
                    <div class="stat-card action-card" role="button" tabindex="0" data-dashboard-modal-target="gamePluginsModal">
                        <div class="stat-value"><?php echo dialectic_home_number($gamePluginCount); ?></div>
                        <div class="stat-label">Detected Mods</div>
                    </div>
                    <div id="llmStatsCard" class="stat-card stat-period" role="button" tabindex="0" aria-label="Cycle LLM request periods">
                        <?php foreach ([
                            '24h' => 'LLM Requests Success Rate (24h)',
                            '72h' => 'LLM Requests Success Rate (72h)',
                            '7d' => 'LLM Requests Success Rate (7 days)',
                            'lifetime' => 'LLM Requests Success Rate (lifetime)',
                        ] as $period => $label): ?>
                            <div class="stat-value stat-period-value" data-period="<?php echo dialectic_home_h($period); ?>"<?php echo $period !== '24h' ? ' hidden' : ''; ?>><?php echo dialectic_home_h(dialectic_home_llm_stats($llmStats[$period])); ?></div>
                            <div class="stat-label stat-period-label" data-period="<?php echo dialectic_home_h($period); ?>"<?php echo $period !== '24h' ? ' hidden' : ''; ?>><?php echo dialectic_home_h($label); ?></div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
        </article>

        <article class="widget widget-wide">
            <div class="widget-header">
                <h3><i class="bi bi-journal-text"></i> Latest Diary Entry</h3>
            </div>
            <div class="widget-content">
                <?php if (empty($latestDiary)): ?>
                    <p class="empty-state">No diary entries have been created yet.</p>
                <?php else: ?>
                    <div class="diary-entry">
                        <div class="diary-entry-header">
                            <span><?php echo dialectic_home_h(dialectic_home_people($latestDiary['people'] ?? '')); ?></span>
                            <span><?php echo dialectic_home_h(dialectic_home_timestamp($latestDiary['localts'] ?? null)); ?></span>
                        </div>
                        <h4><?php echo dialectic_home_h($latestDiary['topic'] ?? 'Untitled Entry'); ?></h4>
                        <p><?php echo dialectic_home_h($latestDiary['content'] ?? ''); ?></p>
                    </div>
                    <div class="latest-diary-audio-controls">
                        <button type="button" id="latestDiaryAudioButton" class="latest-diary-audio-button" onclick="toggleLatestDiaryAudio(this, <?php echo (int)($latestDiary['rowid'] ?? 0); ?>)">&#9654; Play Audio</button>
                        <span id="latestDiaryAudioStatus" class="latest-diary-audio-status" aria-live="polite"></span>
                    </div>
                <?php endif; ?>
            </div>
        </article>

        <article class="widget widget-wide">
            <div class="widget-header">
                <h3><i class="bi bi-cloud"></i> Recent Most Used Words</h3>
            </div>
            <div class="widget-content">
                <?php if (empty($wordCloudWords)): ?>
                    <p class="empty-state">No recent dialogue words are available yet.</p>
                <?php else: ?>
                    <div class="word-cloud-container">
                        <div id="dialectic-word-count-display" class="word-count-display"></div>
                        <svg id="dialectic-word-cloud" role="img" aria-label="Recent most used words"></svg>
                    </div>
                    <script src="https://d3js.org/d3.v7.min.js"></script>
                    <script src="https://cdn.jsdelivr.net/gh/jasondavies/d3-cloud/build/d3.layout.cloud.js"></script>
                    <script>
                        (() => {
                            const words = <?php echo json_encode($wordCloudWords, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>;
                            const svg = document.getElementById('dialectic-word-cloud');
                            const display = document.getElementById('dialectic-word-count-display');
                            if (!svg || !display || !window.d3 || !d3.layout || !d3.layout.cloud || !words.length) return;
                            const colors = d3.scaleOrdinal().range([
                                'rgb(255, 182, 65)', 'rgb(255, 199, 104)', 'rgb(235, 169, 71)',
                                'rgb(255, 221, 154)', 'rgb(214, 214, 214)'
                            ]);
                            const drawCloud = () => {
                                const width = Math.max(280, svg.clientWidth || 280);
                                const height = window.innerWidth <= 720 ? 300 : 360;
                                svg.innerHTML = '';
                                d3.layout.cloud()
                                    .size([width, height])
                                    .words(words)
                                    .padding(5)
                                    .rotate(() => 0)
                                    .font('Monofonto')
                                    .fontSize(d => d.size)
                                    .on('end', drawnWords => {
                                        d3.select(svg)
                                            .attr('viewBox', '0 0 ' + width + ' ' + height)
                                            .append('g')
                                            .attr('transform', 'translate(' + width / 2 + ',' + height / 2 + ')')
                                            .selectAll('text')
                                            .data(drawnWords)
                                            .enter()
                                            .append('text')
                                            .attr('class', 'word-cloud-text')
                                            .style('font-size', d => d.size + 'px')
                                            .style('fill', (d, i) => colors(i % 5))
                                            .attr('text-anchor', 'middle')
                                            .attr('transform', d => 'translate(' + [d.x, d.y] + ')')
                                            .text(d => d.text)
                                            .on('mouseover', function(event, d) {
                                                display.textContent = d.text + ' [' + d.count + ']';
                                                d3.select(this).style('opacity', 0.72);
                                            })
                                            .on('mouseout', function() {
                                                display.textContent = '';
                                                d3.select(this).style('opacity', 1);
                                            });
                                    })
                                    .start();
                            };
                            drawCloud();
                            window.addEventListener('resize', () => {
                                window.clearTimeout(window.dialecticWordCloudResize);
                                window.dialecticWordCloudResize = window.setTimeout(drawCloud, 150);
                            });
                        })();
                    </script>
                <?php endif; ?>
            </div>
        </article>

        <article class="widget widget-wide">
            <div class="widget-header">
                <h3><i class="bi bi-person-vcard"></i> Fallout Stats</h3>
            </div>
            <div class="widget-content">
                <?php if ($falloutStats === []): ?>
                    <p class="empty-state">Load a Fallout save to import player statistics.</p>
                <?php else: ?>
                    <div class="fallout-stats-grid">
                        <?php foreach ($falloutStatCategories as $categoryName => $categoryStats): ?>
                            <section class="fallout-stats-category">
                                <h4><?php echo dialectic_home_h($categoryName); ?></h4>
                                <?php foreach ($categoryStats as $statName): ?>
                                    <div class="fallout-stat-row">
                                        <span><?php echo dialectic_home_h($statName); ?></span>
                                        <strong><?php echo dialectic_home_h(number_format((int)($falloutStats[$statName] ?? 0))); ?></strong>
                                    </div>
                                <?php endforeach; ?>
                            </section>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </article>
    </section>

    <div id="eventTypesModal" class="dashboard-modal" aria-hidden="true">
        <div class="dashboard-modal-content" role="dialog" aria-modal="true" aria-labelledby="eventTypesModalTitle">
            <div class="dashboard-modal-header">
                <h3 id="eventTypesModalTitle">Event Types</h3>
                <button type="button" class="dashboard-modal-close" data-dashboard-modal-close="eventTypesModal" aria-label="Close event types">&times;</button>
            </div>
            <div class="dashboard-modal-body">
                <?php if (empty($eventTypeRows)): ?>
                    <p class="empty-state">No event types have been recorded yet.</p>
                <?php else: ?>
                    <table class="widget-table">
                        <tr><th>Event Type</th><th>Count</th></tr>
                        <?php foreach ($eventTypeRows as $eventType): ?>
                            <tr>
                                <td><?php echo dialectic_home_h($eventType['type'] ?? 'event'); ?></td>
                                <td><?php echo dialectic_home_number($eventType['event_count'] ?? 0); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </table>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <div id="locationsModal" class="dashboard-modal" aria-hidden="true">
        <div class="dashboard-modal-content" role="dialog" aria-modal="true" aria-labelledby="locationsModalTitle">
            <div class="dashboard-modal-header">
                <h3 id="locationsModalTitle">Available Locations</h3>
                <button type="button" class="dashboard-modal-close" data-dashboard-modal-close="locationsModal" aria-label="Close locations">&times;</button>
            </div>
            <div class="dashboard-modal-body">
                <?php if (!$hasLocations): ?>
                    <p class="empty-state">The locations table is not installed yet.</p>
                <?php elseif (empty($locationRows)): ?>
                    <p class="empty-state">No locations have been synced from the game yet.</p>
                <?php else: ?>
                    <table class="widget-table">
                        <tr>
                            <th>Name</th>
                            <th>Form ID</th>
                            <th>Worldspace</th>
                            <th>Updated</th>
                        </tr>
                        <?php foreach ($locationRows as $location): ?>
                            <tr>
                                <td><?php echo dialectic_home_h($location['name'] ?? ''); ?></td>
                                <td><?php echo dialectic_home_h($location['formid'] ?? ''); ?></td>
                                <td><?php echo dialectic_home_h($location['worldspace'] ?? ''); ?></td>
                                <td><?php echo dialectic_home_h($location['updated_at'] ?? ''); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </table>
                    <?php if ((int)$locationCount > count($locationRows)): ?>
                        <p class="muted" style="margin:12px 0 0;">Showing <?php echo dialectic_home_number(count($locationRows)); ?> of <?php echo dialectic_home_number($locationCount); ?> synced locations.</p>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <div id="gamePluginsModal" class="dashboard-modal" aria-hidden="true">
        <div class="dashboard-modal-content" role="dialog" aria-modal="true" aria-labelledby="gamePluginsModalTitle">
            <div class="dashboard-modal-header">
                <h3 id="gamePluginsModalTitle">Detected Mods</h3>
                <button type="button" class="dashboard-modal-close" data-dashboard-modal-close="gamePluginsModal" aria-label="Close detected mods">&times;</button>
            </div>
            <div class="dashboard-modal-body">
                <?php if (!$hasGamePlugins): ?>
                    <p class="empty-state">The game_plugins table is not installed yet.</p>
                <?php elseif (empty($gamePluginRows)): ?>
                    <p class="empty-state">No detected mods have been synced from the game yet.</p>
                <?php else: ?>
                    <table class="widget-table">
                        <tr>
                            <th>Plugin</th>
                            <th>Light</th>
                            <th>Index</th>
                            <th>Small Index</th>
                            <th>Form Prefix</th>
                        </tr>
                        <?php foreach ($gamePluginRows as $plugin): ?>
                            <tr>
                                <td><?php echo dialectic_home_h($plugin['plugin_name'] ?? ''); ?></td>
                                <td><?php echo dialectic_home_h(isset($plugin['is_light']) ? ((string)$plugin['is_light']) : ''); ?></td>
                                <td><?php echo dialectic_home_h($plugin['compile_index'] ?? ''); ?></td>
                                <td><?php echo dialectic_home_h($plugin['small_file_compile_index'] ?? ''); ?></td>
                                <td><?php echo dialectic_home_h($plugin['formid_prefix'] ?? ''); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </table>
                    <?php if ((int)$gamePluginCount > count($gamePluginRows)): ?>
                        <p class="muted" style="margin:12px 0 0;">Showing <?php echo dialectic_home_number(count($gamePluginRows)); ?> of <?php echo dialectic_home_number($gamePluginCount); ?> detected mods.</p>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <script>
        const latestDiaryAudioEndpoint = <?php echo json_encode($webRoot . '/ui/api/dialectic_diary_audio.php'); ?>;
        const latestDiaryAudio = new Audio();
        let latestDiaryAudioEntryId = null;
        let latestDiaryAudioRequest = null;

        async function toggleLatestDiaryAudio(button, entryId) {
            if (!entryId) return;
            const status = document.getElementById('latestDiaryAudioStatus');
            if (latestDiaryAudioEntryId === entryId && latestDiaryAudio.src) {
                if (latestDiaryAudio.paused) {
                    await latestDiaryAudio.play();
                    button.innerHTML = '&#10074;&#10074; Pause';
                    if (status) status.textContent = 'Playing';
                } else {
                    latestDiaryAudio.pause();
                    button.innerHTML = '&#9654; Play Audio';
                    if (status) status.textContent = 'Paused';
                }
                return;
            }

            if (latestDiaryAudioRequest) latestDiaryAudioRequest.abort();
            latestDiaryAudio.pause();
            latestDiaryAudio.removeAttribute('src');
            latestDiaryAudio.load();
            latestDiaryAudioEntryId = entryId;
            latestDiaryAudioRequest = new AbortController();
            button.disabled = true;
            button.textContent = 'Generating...';
            if (status) status.textContent = 'Generating audio with the NPC voice...';

            try {
                const response = await fetch(`${latestDiaryAudioEndpoint}?entry=${encodeURIComponent(entryId)}`, {
                    cache: 'no-store',
                    signal: latestDiaryAudioRequest.signal
                });
                const result = await response.json();
                if (!response.ok || !result.success || !result.audio_url) {
                    throw new Error(result.error || 'Diary audio could not be generated.');
                }
                latestDiaryAudioRequest = null;
                latestDiaryAudio.src = result.audio_url;
                await latestDiaryAudio.play();
                button.disabled = false;
                button.innerHTML = '&#10074;&#10074; Pause';
                if (status) status.textContent = `Playing ${result.author || 'NPC'} with ${result.connector || 'configured TTS'}`;
            } catch (error) {
                latestDiaryAudioRequest = null;
                if (error.name === 'AbortError') return;
                console.error('Latest diary audio failed:', error);
                button.disabled = false;
                button.innerHTML = '&#9654; Play Audio';
                if (status) status.textContent = error.message || 'Diary audio failed.';
                latestDiaryAudioEntryId = null;
            }
        }

        latestDiaryAudio.addEventListener('ended', () => {
            const button = document.getElementById('latestDiaryAudioButton');
            const status = document.getElementById('latestDiaryAudioStatus');
            if (button) button.innerHTML = '&#9654; Play Audio';
            if (status) status.textContent = '';
            latestDiaryAudioEntryId = null;
        });

        function dialecticOpenDashboardModal(id) {
            const modal = document.getElementById(id);
            if (!modal) return;
            modal.classList.add('is-open');
            modal.setAttribute('aria-hidden', 'false');
        }

        function dialecticCloseDashboardModal(id) {
            const modal = document.getElementById(id);
            if (!modal) return;
            modal.classList.remove('is-open');
            modal.setAttribute('aria-hidden', 'true');
        }

        document.querySelectorAll('[data-dashboard-modal-target]').forEach(trigger => {
            const openTarget = () => dialecticOpenDashboardModal(trigger.dataset.dashboardModalTarget);
            trigger.addEventListener('click', openTarget);
            trigger.addEventListener('keydown', event => {
                if (event.key === 'Enter' || event.key === ' ') {
                    event.preventDefault();
                    openTarget();
                }
            });
        });

        document.querySelectorAll('[data-dashboard-modal-close]').forEach(trigger => {
            trigger.addEventListener('click', () => dialecticCloseDashboardModal(trigger.dataset.dashboardModalClose));
        });

        (() => {
            const card = document.getElementById('llmStatsCard');
            if (!card) return;
            const periods = ['24h', '72h', '7d', 'lifetime'];
            let currentIndex = 0;
            const cyclePeriod = () => {
                currentIndex = (currentIndex + 1) % periods.length;
                card.querySelectorAll('[data-period]').forEach(element => {
                    element.hidden = element.dataset.period !== periods[currentIndex];
                });
            };
            card.addEventListener('click', cyclePeriod);
            card.addEventListener('keydown', event => {
                if (event.key === 'Enter' || event.key === ' ') {
                    event.preventDefault();
                    cyclePeriod();
                }
            });
        })();

        document.addEventListener('click', event => {
            if (event.target && event.target.classList && event.target.classList.contains('dashboard-modal')) {
                dialecticCloseDashboardModal(event.target.id);
            }
        });

        document.addEventListener('keydown', event => {
            if (event.key !== 'Escape') return;
            document.querySelectorAll('.dashboard-modal.is-open').forEach(modal => dialecticCloseDashboardModal(modal.id));
        });
    </script>
<?php
include(__DIR__ . DIRECTORY_SEPARATOR . "tmpl" . DIRECTORY_SEPARATOR . "footer.html");

$buffer = ob_get_clean();
$buffer = preg_replace('/<title>.*?<\/title>/i', '<title>' . dialectic_home_h($TITLE) . '</title>', $buffer, 1);
echo $buffer;
