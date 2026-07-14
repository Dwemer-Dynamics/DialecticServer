<?php
session_start();

date_default_timezone_set('UTC');
// Enable error reporting (for development purposes)
error_reporting(E_ALL);
ini_set('display_errors', '1');

// Include game timestamp utilities
$rootPath = dirname(__DIR__) . DIRECTORY_SEPARATOR;
require_once($rootPath . "conf" . DIRECTORY_SEPARATOR . "conf.php");
require_once($rootPath . "lib" . DIRECTORY_SEPARATOR . "db_connection_settings.php");
require_once($rootPath . "lib" . DIRECTORY_SEPARATOR . "utils_game_timestamp.php");

$dbSettings = dialecticDbConnectionSettings('dialectic');
$host = $dbSettings['host'];
$port = $dbSettings['port'];
$dbname = $dbSettings['dbname'];
$schema = $dbSettings['schema'];
$username = $dbSettings['username'];
$password = $dbSettings['password'];

// Get the relative web path from document root to our application
$scriptPath = $_SERVER['SCRIPT_NAME'];
$webRoot = dirname(dirname($scriptPath)); // Go up two levels from the script location
if ($webRoot == '/') $webRoot = '';
$webRoot = rtrim($webRoot, '/');

require_once(__DIR__.DIRECTORY_SEPARATOR."profile_loader.php");

$TITLE = "DIALECTIC Diaries";
$isEmbed = (isset($_GET['embed']) && $_GET['embed'] == '1');

// Connect to the database
$conn = pg_connect(dialecticPgConnectionString($dbSettings));

if (!$conn) {
 echo "<div class='message'>Failed to connect to database: " . pg_last_error() . "</div>";
 exit;
}

// Handle diary entry updates via POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
 // Check for delete action
 if (isset($_POST['action']) && $_POST['action'] === 'delete') {
 $rowid = filter_input(INPUT_POST, 'rowid', FILTER_VALIDATE_INT);

 if (!$rowid) {
 http_response_code(400);
 echo json_encode(['error' => 'Invalid row ID']);
 exit;
 }

 // Delete the diary entry
 $query = "DELETE FROM {$schema}.diarylog WHERE rowid = $1";
 $result = pg_query_params($conn, $query, [$rowid]);

 if ($result) {
 http_response_code(200);
 echo json_encode(['success' => true]);
 } else {
 http_response_code(500);
 echo json_encode(['error' => 'Failed to delete entry: ' . pg_last_error($conn)]);
 }
 exit;
 }

 // Check for delete all action
 if (isset($_POST['action']) && $_POST['action'] === 'delete_all') {
 // Delete all diary entries
 $query = "DELETE FROM {$schema}.diarylog";
 $result = pg_query($conn, $query);

 if ($result) {
 $deletedCount = pg_affected_rows($result);
 http_response_code(200);
 echo json_encode(['success' => true, 'message' => "Successfully deleted {$deletedCount} diary entries"]);
 } else {
 http_response_code(500);
 echo json_encode(['error' => 'Failed to delete all entries: ' . pg_last_error($conn)]);
 }
 exit;
 }

 // Existing update logic
 $rowid = filter_input(INPUT_POST, 'rowid', FILTER_VALIDATE_INT);
 $topic = filter_input(INPUT_POST, 'topic', FILTER_SANITIZE_STRING);
 $content = filter_input(INPUT_POST, 'content', FILTER_SANITIZE_STRING);

 // Validate required fields
 if (!$rowid || !$topic || !$content) {
 http_response_code(400);
 echo json_encode(['error' => 'Missing required fields']);
 exit;
 }

 // Update the diary entry
 $query = "
 UPDATE {$schema}.diarylog
 SET
 topic = $1,
 content = $2
 WHERE rowid = $3
 ";

 $result = pg_query_params($conn, $query, [
 $topic,
 $content,
 $rowid
 ]);

 if ($result) {
 http_response_code(200);
 echo json_encode(['success' => true]);
 } else {
 http_response_code(500);
 echo json_encode(['error' => 'Failed to update entry: ' . pg_last_error($conn)]);
 }
 exit;
}

// Function to sanitize and validate integers
function sanitize_int($value, $default) {
 $value = filter_var($value, FILTER_VALIDATE_INT);
 return ($value !== false) ? $value : $default;
}

/**
 * Function to get list of people with their diary entry counts
 *
 * @param resource $conn Database connection
 * @param string $schema Database schema
 * @return array Array of people with their entry counts
 */
function getPeopleList($conn, $schema) {
 $query = "
 WITH split_people AS (
 SELECT
 d.rowid,
 trim(unnest(string_to_array(trim(d.people, '|'), '|'))) as person
 FROM {$schema}.diarylog d
 WHERE d.people IS NOT NULL AND d.people != ''
 AND d.topic NOT IN ('Sent Letter', 'Journal Note')
 )
 SELECT
 person,
 COUNT(DISTINCT rowid) as entry_count
 FROM split_people
 WHERE person != ''
 GROUP BY person
 ORDER BY entry_count DESC, person ASC
 ";

 $result = pg_query($conn, $query);
 $peopleList = [];

 if ($result) {
 while ($row = pg_fetch_assoc($result)) {
 $peopleList[] = $row;
 }
 }

 return $peopleList;
}

/**
 * Function to get diary entries by person
 *
 * @param resource $conn Database connection
 * @param string $schema Database schema
 * @param string $person Person name to filter by
 * @return array Array of diary entries
 */
function getEntriesByPerson($conn, $schema, $person) {
 // Debug log
 error_log("Searching for person: " . $person);

 $query = "
 SELECT rowid, topic, content, tags, people, location, localts, gamets
 FROM {$schema}.diarylog
 WHERE people LIKE $1
 AND topic NOT IN ('Sent Letter', 'Journal Note')
 ORDER BY localts DESC
 ";

 $result = pg_query_params($conn, $query, ['%' . $person . '%']);

 if (!$result) {
 error_log("Query error: " . pg_last_error($conn));
 return [];
 }

 $entries = [];
 while ($row = pg_fetch_assoc($result)) {
 error_log("Found entry with people: " . $row['people']);
 $entries[] = $row;
 }

 error_log("Found " . count($entries) . " entries for person: " . $person);
 return $entries;
}

/**
 * Function to process a single diary row into formatted data.
 *
 * @param array $row The associative array representing a database row.
 * @param bool $for_csv Indicates whether the output is for CSV (true) or HTML (false).
 * @return array|null An associative array with formatted data.
 */
function process_diary_row($row, $for_csv = false) {
 // Format 'localts' into a readable UTC date format
 $timestamp = (int)$row['localts'];

 if ($timestamp > 0) {
 $dt = new DateTime("@$timestamp");
 $dt->setTimezone(new DateTimeZone('UTC'));
 $timeDisplay = $dt->format('d-m-Y H:i:s');
 } else {
 $timeDisplay = $row['localts'];
 }

 // Process tags
 $tags = !empty($row['tags']) ? trim($row['tags'], '|') : '';
 $tagsList = !empty($tags) ? explode('|', $tags) : [];
 $formattedTags = implode(', ', array_filter($tagsList));

 // Process people
 $people = !empty($row['people']) ? trim($row['people'], '|') : '';
 $peopleList = !empty($people) ? explode('|', $people) : [];
 $formattedPeople = implode(', ', array_filter($peopleList));

 // Clean and format location
 $location = trim($row['location'], "()");

 if ($for_csv) {
 // For CSV, decode HTML entities and clean up the content
 $topic = html_entity_decode($row['topic'], ENT_QUOTES | ENT_HTML5, 'UTF-8');
 $content = html_entity_decode($row['content'], ENT_QUOTES | ENT_HTML5, 'UTF-8');
 $formattedTags = html_entity_decode($formattedTags, ENT_QUOTES | ENT_HTML5, 'UTF-8');
 $formattedPeople = html_entity_decode($formattedPeople, ENT_QUOTES | ENT_HTML5, 'UTF-8');
 $location = html_entity_decode($location, ENT_QUOTES | ENT_HTML5, 'UTF-8');
 } else {
 // For HTML display, escape HTML entities
 $topic = htmlspecialchars($row['topic'], ENT_QUOTES, 'UTF-8');
 $content = htmlspecialchars($row['content'], ENT_QUOTES, 'UTF-8');
 $formattedTags = htmlspecialchars($formattedTags, ENT_QUOTES, 'UTF-8');
 $formattedPeople = htmlspecialchars($formattedPeople, ENT_QUOTES, 'UTF-8');
 $location = htmlspecialchars($location, ENT_QUOTES, 'UTF-8');
 }

 // Return the processed data with separate Topic and Content
 return [
 'Topic' => $topic,
 'Content' => $content,
 'Nearby People' => $formattedPeople,
 'Location & Fallout Time' => $location,
 'Time(UTC)' => $timeDisplay
 ];
}

// Function to handle CSV export
function handle_csv_export($conn, $schema) {
 // Define Fallout month mapping
 $falloutMonths = [
 1 => 'January',
 2 => "February",
 3 => 'March',
 4 => "April",
 5 => 'May',
 6 => 'June',
 7 => "July",
 8 => 'August',
 9 => 'September',
 10 => 'October',
 11 => "November",
 12 => 'December'
 ];

 if (isset($_GET['export'])) {
 $exportType = $_GET['export'];

 if ($exportType === 'csv' || $exportType === 'all_csv') {
 // Clear any existing output buffer
 while (ob_get_level()) {
 ob_end_clean();
 }

 // Build the query based on the current view
 if ($exportType === 'csv') {
 // Determine which view we're in
 $isPersonFilter = isset($_GET['filter']) && $_GET['filter'] === 'people' && isset($_GET['person']);
 $isFalloutView = isset($_GET['fallout']) && $_GET['fallout'] === 'true';
 $isRegularCalendar = isset($_GET['date']);

 if ($isPersonFilter) {
 // People filter mode - get entries for specific person
 $person = urldecode($_GET['person']);
 $query = "
 SELECT rowid, topic, content, tags, people, location, localts, gamets
 FROM {$schema}.diarylog
 WHERE people LIKE '%' || $1 || '%'
 AND topic NOT IN ('Sent Letter', 'Journal Note')
 ORDER BY localts DESC
 ";
 $result = pg_query_params($conn, $query, [$person]);
 } elseif ($isFalloutView && isset($_GET['month']) && isset($_GET['year']) && isset($_GET['day'])) {
 // Fallout calendar mode
 $query = "
 SELECT rowid, topic, content, tags, people, location, localts, gamets
 FROM {$schema}.diarylog
 WHERE gamets > 0
 AND topic NOT IN ('Sent Letter', 'Journal Note')
 ORDER BY gamets ASC
 ";
 $result = pg_query($conn, $query);
 } elseif ($isRegularCalendar) {
 // Regular calendar mode
 $selectedDate = $_GET['date'];
 $dtSelected = new DateTime($selectedDate . ' 00:00:00', new DateTimeZone('UTC'));
 $startOfDay = $dtSelected->getTimestamp();
 $dtSelectedEnd = clone $dtSelected;
 $dtSelectedEnd->modify('+1 day')->modify('-1 second');
 $endOfDay = $dtSelectedEnd->getTimestamp();

 $query = "
 SELECT rowid, topic, content, tags, people, location, localts, gamets
 FROM {$schema}.diarylog
 WHERE localts >= $1 AND localts <= $2
 AND topic NOT IN ('Sent Letter', 'Journal Note')
 ORDER BY localts ASC
 ";
 $result = pg_query_params($conn, $query, [$startOfDay, $endOfDay]);
 } else {
 // No valid view selected
 header("HTTP/1.1 400 Bad Request");
 echo "Please select a date, person, or Fallout date to view entries.";
 exit;
 }
 } else {
 // Export all entries
 $query = "
 SELECT rowid, topic, content, tags, people, location, localts, gamets
 FROM {$schema}.diarylog
 WHERE topic NOT IN ('Sent Letter', 'Journal Note')
 ORDER BY localts ASC
 ";
 $result = pg_query($conn, $query);
 }

 if (!$result) {
 header("HTTP/1.1 500 Internal Server Error");
 echo "Error fetching data: " . pg_last_error($conn);
 exit;
 }

 // Set headers to prompt file download
 header('Content-Type: text/csv; charset=utf-8');
 if ($exportType === 'csv') {
 if (isset($_GET['filter']) && $_GET['filter'] === 'people' && isset($_GET['person'])) {
 $filename = 'diary_log_' . urlencode($_GET['person']) . '.csv';
 } else if (isset($_GET['fallout']) && $_GET['fallout'] === 'true') {
 $filename = sprintf('diary_log_%dth_%s_%d.csv',
 intval($_GET['day']),
 $falloutMonths[intval($_GET['month'])],
 intval($_GET['year'])
 );
 } else if (isset($_GET['date'])) {
 $filename = 'diary_log_' . $_GET['date'] . '.csv';
 } else {
 $filename = 'diary_log_current.csv';
 }
 } else {
 $filename = 'diary_log_full.csv';
 }
 header('Content-Disposition: attachment; filename=' . $filename);

 // Add BOM for Excel compatibility
 fprintf($output = fopen('php://output', 'w'), chr(0xEF).chr(0xBB).chr(0xBF));

 // Open the output stream
 $output = fopen('php://output', 'w');

 // Output the column headings matching the table
 fputcsv($output, ['Author', 'Content', 'Fallout Time', 'Time(UTC)']);

 // Initialize previous location for tracking changes
 $previousLocation = null;

 // Fetch and process each row, then write to the CSV
 while ($row = pg_fetch_assoc($result)) {
 $processed_row = process_diary_row($row, true); // true indicates CSV context
 if ($processed_row !== null) {
 // For Fallout mode, filter entries to match the selected date
 if (isset($_GET['fallout']) && $_GET['fallout'] === 'true' &&
 isset($_GET['month']) && isset($_GET['year']) && isset($_GET['day'])) {
 if (isset($row['gamets']) && $row['gamets'] > 0) {
 $falloutDate = convert_gamets2fallout_long_date_no_time($row['gamets']);
 if (preg_match('/(\d+)th of ([^,]+) , (\d+)/', $falloutDate, $matches)) {
 $eventDay = intval($matches[1]);
 $eventMonth = $matches[2];
 $eventYear = intval($matches[3]);

 // Skip entries that don't match the current Fallout date
 if ($eventMonth !== $falloutMonths[intval($_GET['month'])] ||
 $eventYear !== intval($_GET['year']) ||
 $eventDay !== intval($_GET['day'])) {
 continue;
 }
 }
 }
 }

 // Check for location change
 if ($previousLocation !== null && $previousLocation !== $processed_row['Location & Fallout Time']) {
 // Extract just the location name without date/time
 $locationPattern = '/Context new location:\s*([^,]+)/i';
 $cleanLocation = trim($row['location'], "()");
 if (preg_match($locationPattern, $cleanLocation, $locationMatch)) {
 $locationName = trim($locationMatch[1]);
 } else {
 $regionPattern = '/Region:\s*([^,]+)/i';
 if (preg_match($regionPattern, $cleanLocation, $regionMatch)) {
 $locationName = trim($regionMatch[1]);
 } else {
 $locationName = $cleanLocation;
 }
 }
 // Write location change as a special row
 fputcsv($output, ['Location Change:', $locationName, '', '']);
 }

 // Update previous location
 $previousLocation = $processed_row['Location & Fallout Time'];

 // Convert gamets to Fallout time for the CSV
 $falloutTime = "";
 if (isset($row['gamets']) && $row['gamets'] > 0) {
 $falloutTime = convert_gamets2fallout_long_date2($row['gamets']);
 }

 // Write the actual event row with reordered columns
 fputcsv($output, [
 $processed_row['Nearby People'],
 $processed_row['Content'],
 $falloutTime,
 $processed_row['Time(UTC)']
 ]);
 }
 }

 fclose($output);
 exit; // Terminate the script after exporting CSV
 }
 }
}

// Handle CSV export if requested - do this before any output buffering
handle_csv_export($conn, $schema);

// Start output buffering after CSV handling
ob_start();

include(__DIR__.DIRECTORY_SEPARATOR."tmpl/head.html");
?><!-- Ensure main.css is loaded after any reboot.css --><link rel="stylesheet" href="<?php echo $webRoot; ?>/ui/css/main.css"><link rel="stylesheet" href="<?php echo $webRoot; ?>/ui/css/diary_adventure.css"><style>
 @font-face {
 font-family: 'Gothic821';
 src: url('<?php echo $webRoot; ?>/ui/css/font/Gothic821CondensedRegular.otf') format('opentype');
 font-weight: normal;
 font-style: normal;
 }
 .page-header {
 text-align: center;
 margin-bottom: 30px;
 }
 .page-header h1 {
 margin-bottom: 10px;
 font-family: 'Gothic821', serif;
 word-spacing: 8px;
 font-size: 2.2em;
 color: rgb(255, 182, 65);
 text-shadow: 2px 2px 4px rgba(0,0,0,0.5);
 }
 .page-header h3 {
 margin: 0;
 }
 <?php if ($isEmbed): ?>
 main { padding-top: 20px; }
 <?php endif; ?>
 @media (max-width: 480px) {
 .page-header h1 { font-size: 1.6em; }
 }
</style><?php if ($isEmbed): ?><style>
 /* Embedded in container: reduce top padding since navbar is hidden */
 main { padding-top: 20px; }
</style><?php endif; ?><?php

$debugPaneLink = false;

// Determine the month and year to display
$month = isset($_GET['month']) ? sanitize_int($_GET['month'], date('n')) : date('n');
$year = isset($_GET['year']) ? sanitize_int($_GET['year'], date('Y')) : date('Y');

// Add Fallout mode toggle
$useFalloutTime = isset($_GET['fallout']) && $_GET['fallout'] === 'true';

// Set default values for Fallout mode
if ($useFalloutTime) {
 if (!isset($_GET['month']) || !isset($_GET['year'])) {
 // Default to November 30, 2281 when first switching to Fallout
 $month = 11; // November
 $year = 2281; // 2281
 }
}

// Define Fallout month mapping
$falloutMonths = [
 1 => 'January',
 2 => "February",
 3 => 'March',
 4 => "April",
 5 => 'May',
 6 => 'June',
 7 => "July",
 8 => 'August',
 9 => 'September',
 10 => 'October',
 11 => "November",
 12 => 'December'
];

// Define Fallout month lengths
$falloutMonthLengths = [
 1 => 31, // January
 2 => 28, // February
 3 => 31, // March
 4 => 30, // April
 5 => 31, // May
 6 => 30, // June
 7 => 31, // July
 8 => 31, // August
 9 => 30, // September
 10 => 31, // October
 11 => 30, // November
 12 => 31 // December
];

$falloutMonthToNumber = array_flip($falloutMonths);

// Function to get days in a Fallout month
function get_fallout_days_in_month($month) {
 global $falloutMonthLengths;
 return $falloutMonthLengths[$month] ?? 30;
}

// Get current game timestamp if in Fallout mode
$currentGameDate = null;
$currentFalloutMonth = $falloutMonths[$month] ?? 'November';
$currentFalloutYear = $year;
$currentFalloutDay = isset($_GET['day']) ? sanitize_int($_GET['day'], 30) : 30;

// Initialize the events array
$allEventDates = [];

if ($useFalloutTime) {
 // If we have month and year parameters, use them to set the current Fallout month
 if (isset($_GET['month']) && isset($_GET['year'])) {
 $currentFalloutMonth = $falloutMonths[$month] ?? 'January';
 $currentFalloutYear = $year;
 error_log("Debug - Using URL parameters: month={$currentFalloutMonth}, year={$currentFalloutYear}");
 }
}

// Get all diary entries for the current month to highlight calendar days
$allEventDates = array();
if ($useFalloutTime) {
 $sql = "SELECT DISTINCT
 EXTRACT(DAY FROM gamets) as day,
 EXTRACT(MONTH FROM gamets) as month,
 EXTRACT(YEAR FROM gamets) as year
 FROM {$schema}.diarylog
 WHERE EXTRACT(MONTH FROM gamets) = $month
 AND EXTRACT(YEAR FROM gamets) = $year
 AND topic NOT IN ('Sent Letter', 'Journal Note')";
} else {
 $sql = "SELECT DISTINCT
 TO_CHAR(localts AT TIME ZONE 'UTC', 'YYYY-MM-DD') as date
 FROM {$schema}.diarylog
 WHERE EXTRACT(MONTH FROM localts AT TIME ZONE 'UTC') = $month
 AND EXTRACT(YEAR FROM localts AT TIME ZONE 'UTC') = $year
 AND topic NOT IN ('Sent Letter', 'Journal Note')";
}

// Prepare the SQL query with explicit casting to double precision
$allDatesQuery = "
 SELECT DISTINCT
 rowid,
 gamets,
 localts,
 topic,
 content,
 tags,
 people,
 location,
 to_char(to_timestamp(CAST(localts AS bigint)), 'YYYY-MM-DD') as date,
 CASE
 WHEN " . ($useFalloutTime ? 'true' : 'false') . " THEN
 gamets
 ELSE
 localts
 END as sort_field
 FROM {$schema}.diarylog
 WHERE gamets > 0
 AND topic NOT IN ('Sent Letter', 'Journal Note')
 ORDER BY sort_field ASC
";

error_log("Debug - SQL Query: {$allDatesQuery}");

$allDatesResult = pg_query($conn, $allDatesQuery);

if ($allDatesResult) {
 error_log("Debug - Processing events for month: {$falloutMonths[$month]}");
 error_log("Debug - Looking for events in year: {$year}");

 while ($dateRow = pg_fetch_assoc($allDatesResult)) {
 if (!$useFalloutTime) {
 // Regular calendar mode - use localts
 if (isset($dateRow['localts']) && $dateRow['localts'] > 0) {
 $eventDate = new DateTime("@" . $dateRow['localts']);
 $eventDate->setTimezone(new DateTimeZone('UTC'));
 $eventMonth = (int)$eventDate->format('n');
 $eventYear = (int)$eventDate->format('Y');
 $eventDay = (int)$eventDate->format('j');

 if ($eventMonth == $month && $eventYear == $year) {
 $allEventDates[] = [
 'date' => $dateRow['date'],
 'day' => $eventDay,
 'localts' => $dateRow['localts'],
 'people' => $dateRow['people'],
 'location' => $dateRow['location']
 ];
 }
 }
 } else {
 // Fallout calendar mode - use gamets
 if (isset($dateRow['gamets']) && $dateRow['gamets'] > 0) {
 $gamets = floatval($dateRow['gamets']);
            $fallout_start_timestamp = fallout_calendar_timestamp('2281-10-19 00:00:00');
 $f_seconds = $gamets * 0.00864;
 $ts_time = $fallout_start_timestamp + intval($f_seconds);

 $eventDay = intval(gmdate('d', $ts_time));
 $eventMonth = intval(gmdate('m', $ts_time));
 $eventYear = intval(ltrim(gmdate('Y', $ts_time), '0'));

 //error_log("Debug - Event found: Month={$eventMonth}, Year={$eventYear}, Day={$eventDay}");
 //error_log("Debug - Looking for: Month={$month}, Year={$year}");

 if ($eventMonth == $month && $eventYear == $year) {
 error_log("Debug - Adding event for day {$eventDay}");
 $allEventDates[] = [
 'fallout_date' => convert_gamets2fallout_long_date_no_time($gamets),
 'fallout_month' => $falloutMonths[$eventMonth],
 'gamets' => $gamets,
 'localts' => $dateRow['localts'],
 'day' => $eventDay,
 'people' => $dateRow['people'],
 'location' => $dateRow['location']
 ];
 }
 }
 }
 }
} else {
 echo "<div class='message'>Error fetching event dates: " . pg_last_error($conn) . "</div>";
}

/**
 * Function to render a calendar for a given month and year, highlighting dates with events.
 *
 * @param int $month The month for the calendar (1-12).
 * @param int $year The year for the calendar (e.g., 2024).
 * @param array $eventDates Array of dates that have events.
 * @param bool $useFalloutTime Whether to use Fallout time.
 * @param string|null $currentGameDate The current game date in Fallout format.
 * @return string HTML string representing the calendar.
 */
function renderCalendar($month, $year, $allEventDates, $useFalloutTime, $falloutMonths) {
 $calendar = array();

 // Get the first day of the month
 if ($useFalloutTime) {
 // For Fallout calendar, we calculate based on August 17th being Sunday
 $daysInMonth = get_fallout_days_in_month($month);
 $currentMonthName = $falloutMonths[$month] ?? 'August';

 // Calculate days since August 17th
 $daysSinceAnchor = 0;
 if ($month == 8) { // August
 $firstDay = 5; // 1st of August is always Friday
 } else {
 // For other months, calculate based on August
 if ($month > 8) {
 // Count forward from August
 for ($i = 8; $i < $month; $i++) {
 $daysSinceAnchor += get_fallout_days_in_month($i);
 }
 } else {
 // Count backward from August
 for ($i = 8; $i > $month; $i--) {
 $daysSinceAnchor -= get_fallout_days_in_month($i);
 }
 }
 // Add the offset from August 1st (which is Friday)
 $firstDay = ($daysSinceAnchor + 5) % 7;
 if ($firstDay < 0) $firstDay += 7;
 }
 } else {
 $firstDay = date('w', strtotime("$year-$month-01"));
 $daysInMonth = date('t', strtotime("$year-$month-01"));
 }

 // Create the calendar array
 $dayCount = 1;
 $weekCount = 0;

 while ($dayCount <= $daysInMonth) {
 for ($i = 0; $i < 7; $i++) {
 if ($weekCount === 0 && $i < $firstDay) {
 $calendar[$weekCount][$i] = "";
 } elseif ($dayCount <= $daysInMonth) {
 // Generate the date string and URL parameters
 if ($useFalloutTime) {
 $dateStr = sprintf("%dth of %s , %d", $dayCount, $currentMonthName, $year);
 $urlParams = sprintf("fallout=true&month=%d&year=%d&day=%d",
 $month,
 $year,
 $dayCount
 );
 } else {
 $dateStr = sprintf("%04d-%02d-%02d", $year, $month, $dayCount);
 $urlParams = sprintf("date=%s&month=%d&year=%d",
 $dateStr,
 $month,
 $year
 );
 }

 // Check if there are events for this day
 $hasEvents = false;
 $eventCount = 0;
 foreach ($allEventDates as $eventDate) {
 if ($useFalloutTime) {
 // Compare Fallout dates
 $eventDay = isset($eventDate['day']) ? $eventDate['day'] : null;
 if ($eventDay == $dayCount) {
 //error_log("Debug - Found event for day {$dayCount}");
 $hasEvents = true;
 $eventCount++;
 }
 } else {
 // Compare Gregorian dates
 if (isset($eventDate['localts'])) {
 $eventDateTime = new DateTime("@{$eventDate['localts']}");
 $eventDateTime->setTimezone(new DateTimeZone('UTC'));
 $eventDateStr = $eventDateTime->format('Y-m-d');

 if ($eventDateStr === $dateStr) {
 $hasEvents = true;
 $eventCount++;
 //error_log("Debug - Found event for date {$dateStr}");
 }
 }
 }
 }

 // Create the calendar cell with appropriate styling
 $calendar[$weekCount][$i] = array(
 'day' => $dayCount,
 'url' => "?$urlParams",
 'hasEvents' => $hasEvents,
 'eventCount' => $eventCount
 );

 $dayCount++;
 } else {
 $calendar[$weekCount][$i] = "";
 }
 }
 $weekCount++;
 }

 return $calendar;
}

// Days of the week arrays
$gregorianDays = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];
$falloutDays = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];

function renderCalendarHTML($calendar, $useFalloutTime) {
 global $gregorianDays, $falloutDays;

 $daysOfWeek = $useFalloutTime ? $falloutDays : $gregorianDays;
 $html = "<table class='calendar'>";

 // Render header
 $html .= "<tr>";
 foreach ($daysOfWeek as $day) {
 $html .= "<th>{$day}</th>";
 }
 $html .= "</tr>";

 // Render calendar body
 foreach ($calendar as $week) {
 $html .= "<tr>";
 foreach ($week as $day) {
 if (empty($day)) {
 $html .= "<td></td>";
 } else {
 $class = $day['hasEvents'] ? 'has-event' : '';
 $dayNum = $day['day'];
 if ($day['hasEvents']) {
 $html .= "<td class='{$class}'><a href='{$day['url']}#event-table' data-event-count='{$day['eventCount']}'>{$dayNum}</a></td>";
 } else {
 $html .= "<td class='{$class}'><span>{$dayNum}</span></td>";
 }
 }
 }
 $html .= "</tr>";
 }

 $html .= "</table>";
 return $html;
}

// Get the selected date from the URL parameter, default to no date
$selectedDate = null;
if (isset($_GET['date'])) {
 $selectedDate = $_GET['date'];

 if ($useFalloutTime) {
 // For Fallout dates, we'll use the anchor date and calculate the offset
            $fallout_start_timestamp = fallout_calendar_timestamp('2281-10-19 00:00:00');
 $selectedDate = gmdate('Y-m-d', $fallout_start_timestamp);
 } else {
 // Validate the selected date format (YYYY-MM-DD) for Gregorian dates
 if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $selectedDate)) {
 $selectedDate = null;
 }
 }
}

// Only proceed with event fetching if we have a selected date or specific Fallout parameters
$shouldFetchEvents = $selectedDate !== null ||
 ($useFalloutTime && isset($_GET['month']) && isset($_GET['year']) && isset($_GET['day']));

if ($shouldFetchEvents) {
 // Create DateTime objects in UTC for the selected day
 if ($selectedDate !== null) {
 $dtSelected = new DateTime($selectedDate . ' 00:00:00', new DateTimeZone('UTC'));
 $startOfDay = $dtSelected->getTimestamp();
 $dtSelectedEnd = clone $dtSelected;
 $dtSelectedEnd->modify('+1 day')->modify('-1 second');
 $endOfDay = $dtSelectedEnd->getTimestamp();
 }

 // Modify the SQL query to fetch records for the selected day with explicit casting
 $query = "
 SELECT rowid, topic, content, tags, people, location, localts, gamets
 FROM {$schema}.diarylog
 WHERE (
 CASE
 WHEN " . ($useFalloutTime ? 'true' : 'false') . " THEN
 -- For Fallout mode, we'll filter in PHP instead of SQL
 gamets > 0
 ELSE
 -- For Gregorian mode, use localts
 localts >= " . (isset($startOfDay) ? $startOfDay : 0) . " AND localts <= " . (isset($endOfDay) ? $endOfDay : 0) . "
 END
 )
 AND topic NOT IN ('Sent Letter', 'Journal Note')
 ORDER BY localts ASC
 ";

 $result = pg_query($conn, $query);

 if (!$result) {
 echo "<div class='message'>Query error: " . pg_last_error($conn) . "</div>";
 exit;
 }
} else {
 $result = false;
}
?><!DOCTYPE html><html><head><link rel="icon" type="image/x-icon" href="<?php echo $webRoot; ?>/ui/images/favicon.ico"><title>Diary Log</title></head><body><main class="container"><div class="page-header"><h1>Diary Log</h1></div><?php
 function renderHeader() {
 echo "<div class='csv-buttons'>";

 // Preserve all current GET parameters for the current view download
 $currentCsvParams = $_GET;
 $currentCsvParams['export'] = 'csv';

 // Form for current date/view download
 echo "<form method='get' style='display: inline;'>";
 foreach ($currentCsvParams as $key => $value) {
 echo "<input type='hidden' name='" . htmlspecialchars($key) . "' value='" . htmlspecialchars($value) . "'>";
 }
 echo "<button type='submit' class='btn-save'>Download Current Diaries</button>";
 echo "</form>";

 // For all entries, only preserve month and year if they exist
 $allCsvParams = ['export' => 'all_csv'];
 if (isset($_GET['month'])) {
 $allCsvParams['month'] = $_GET['month'];
 }
 if (isset($_GET['year'])) {
 $allCsvParams['year'] = $_GET['year'];
 }

 // Form for all data download
 echo "<form method='get' style='display: inline;'>";
 foreach ($allCsvParams as $key => $value) {
 echo "<input type='hidden' name='" . htmlspecialchars($key) . "' value='" . htmlspecialchars($value) . "'>";
 }
 echo "<button type='submit' class='btn-save'>Download All Diary Entries</button>";
 echo "</form>";

 // Delete all button
 echo "<button onclick='deleteAllEntries()' class='btn-danger' style='margin-left: 10px;'>Delete All Diary Entries</button>";

 echo "</div>";
 }

 /**
 * Function to render calendar mode toggle buttons
 * @param bool $useFalloutTime Whether Fallout time is currently active
 * @return void
 */
 function renderCalendarModeButtons($useFalloutTime) {
 $filterMode = isset($_GET['filter']) && $_GET['filter'] === 'people';

 echo '<div class="calendar-mode-toggle">';

 // Regular Calendar button
 echo '<form method="get" style="display: inline; margin-right: 10px;">';
 echo '<button type="submit" class="btn-base ' . (!$useFalloutTime && !$filterMode ? 'btn-primary' : 'btn-secondary') . '">Regular Calendar</button>';
 echo '</form>';

 // Fallout Calendar button
 echo '<form method="get" style="display: inline; margin-right: 10px;">';
 echo '<input type="hidden" name="fallout" value="true">';
 echo '<button type="submit" class="btn-base ' . ($useFalloutTime && !$filterMode ? 'btn-primary' : 'btn-secondary') . '">Fallout Calendar</button>';
 echo '</form>';

 // Filter by Person button
 echo '<form method="get" style="display: inline;">';
 echo '<input type="hidden" name="filter" value="people">';
 echo '<button type="submit" class="btn-base ' . ($filterMode ? 'btn-primary' : 'btn-secondary') . '">Filter by Person</button>';
 echo '</form>';

 echo '</div>';
 }

 // Render Combined CSV Download Buttons at the Top
 renderHeader();
 ?><!-- Add the toggle buttons before the calendar navigation --><?php renderCalendarModeButtons($useFalloutTime); ?><!-- Add People List Section --><?php if (isset($_GET['filter']) && $_GET['filter'] === 'people'): ?><div class="people-list active"><input type="text" class="people-search" placeholder="Search people..." onkeyup="filterPeople(this.value)"><?php
 $peopleList = getPeopleList($conn, $schema);
 if (!empty($peopleList)) {
 foreach ($peopleList as $person) {
 $encodedPerson = urlencode($person['person']);
 echo "<form method='get' style='margin: 0;'>";
 echo "<input type='hidden' name='filter' value='people'>";
 echo "<input type='hidden' name='person' value='{$encodedPerson}'>";
 echo "<button type='submit' class='people-item'>";
 echo "<span>" . htmlspecialchars($person['person']) . "</span>";
 echo "<span class='people-count'>" . $person['entry_count'] . "</span>";
 echo "</button>";
 echo "</form>";
 }
 } else {
 echo "<p>No people found in diary entries.</p>";
 }
 ?></div><?php if (isset($_GET['person'])): ?><div style="max-width: 400px; margin: 12px auto 0; display: flex; justify-content: center;"><a class="pdf-export-btn" target="_blank" title="Open as book (print to PDF)" href="<?php echo $webRoot; ?>/ui/diary_book.php?person=<?php echo urlencode(urldecode($_GET['person'])); ?>"><span></span><span>Open The Diary of <?php echo htmlspecialchars(urldecode($_GET['person'])); ?></span></a></div><?php endif; ?><script>
 function filterPeople(searchText) {
 const peopleItems = document.querySelectorAll('.people-item');
 searchText = searchText.toLowerCase();

 peopleItems.forEach(item => {
 const personName = item.querySelector('span').textContent.toLowerCase();
 const form = item.closest('form');
 if (personName.includes(searchText)) {
 form.style.display = '';
 } else {
 form.style.display = 'none';
 }
 });
 }
 </script><?php endif; ?><!-- Calendar Container --><div class="calendar-container <?php echo (isset($_GET['filter']) && $_GET['filter'] === 'people') ? 'hidden' : ''; ?>"><!-- Calendar Navigation --><div class="calendar-navigation"><?php
 // Calculate previous and next month and year
 if ($useFalloutTime) {
 // For Fallout mode, we need to handle the month names
 $currentMonthNum = array_search($currentFalloutMonth, $falloutMonths) ?: 8;

 // Calculate previous month
 $prevMonthNum = $currentMonthNum - 1;
 if ($prevMonthNum < 1) {
 $prevMonthNum = 12;
 $prevYear = $currentFalloutYear - 1;
 } else {
 $prevYear = $currentFalloutYear;
 }
 $prevMonthName = $falloutMonths[$prevMonthNum];

 // Calculate next month
 $nextMonthNum = $currentMonthNum + 1;
 if ($nextMonthNum > 12) {
 $nextMonthNum = 1;
 $nextYear = $currentFalloutYear + 1;
 } else {
 $nextYear = $currentFalloutYear;
 }
 $nextMonthName = $falloutMonths[$nextMonthNum];

 // Link to previous month
 echo "<a href='?month={$prevMonthNum}&year={$prevYear}&fallout=true' class='btn-primary'>&laquo; {$prevMonthName}</a>";

 // Display current month and year
 echo "<span><b>{$currentFalloutMonth} , {$currentFalloutYear}</b></span>";

 // Link to next month
 echo "<a href='?month={$nextMonthNum}&year={$nextYear}&fallout=true' class='btn-primary'>{$nextMonthName} &raquo;</a>";
 } else {
 // Original Gregorian calendar navigation
 $prevMonth = $month - 1;
 $prevYear = $year;
 if ($prevMonth < 1) {
 $prevMonth = 12;
 $prevYear--;
 }

 $nextMonth = $month + 1;
 $nextYear = $year;
 if ($nextMonth > 12) {
 $nextMonth = 1;
 $nextYear++;
 }

 // Get month names for navigation
 $prevMonthName = date('F', strtotime("$prevYear-$prevMonth-01 UTC"));
 $nextMonthName = date('F', strtotime("$nextYear-$nextMonth-01 UTC"));
 $currentMonthName = date('F', strtotime("$year-$month-01 UTC"));

 echo "<a href='?month={$prevMonth}&year={$prevYear}' class='btn-primary'>&laquo; {$prevMonthName}</a>";
 echo "<span><b>{$currentMonthName} {$year}</b></span>";
 echo "<a href='?month={$nextMonth}&year={$nextYear}' class='btn-primary'>{$nextMonthName} &raquo;</a>";
 }
 ?></div><!-- Render the Calendar --><?php
 $calendarArray = renderCalendar($month, $year, $allEventDates, $useFalloutTime, $falloutMonths);
 echo renderCalendarHTML($calendarArray, $useFalloutTime);
 ?></div><!-- Event Table --><table class="event-table" id="event-table"><colgroup><col class="col-people"><col class="col-content"><col class="col-gamets"><col class="col-time"><col class="col-actions"></colgroup><tr><th>Author</th><th>Content</th><th><a href="https://fallout.fandom.com/wiki/Timeline" target="_blank" style="color: yellow;">Fallout Time</a></th><th>Time (UTC)</th><th>Actions</th></tr><?php
 if (isset($_GET['filter']) && $_GET['filter'] === 'people' && isset($_GET['person'])) {
 //error_log("Filtering by person: " . urldecode($_GET['person']));

 // Get entries for the selected person
 $entries = getEntriesByPerson($conn, $schema, urldecode($_GET['person']));
 //error_log("Retrieved entries: " . print_r($entries, true));

 if (!empty($entries)) {
 // Sort entries by localts in descending order
 usort($entries, function($a, $b) {
 return $b['localts'] - $a['localts'];
 });

 foreach ($entries as $row) {
 $processed_row = process_diary_row($row, false);
 if ($processed_row === null) {
 //error_log("Skipping null processed row");
 continue;
 }

 $topic = htmlspecialchars_decode($row['topic']);
 $rawContent = $row['content']; // Store raw content without nl2br
 $displayContent = nl2br($row['content']); // Format for display
 $people = $processed_row['Nearby People'];
 $timeDisplay = $processed_row['Time(UTC)'];

 // Convert timestamp to game time
 $gameTimeDisplay = "";
 if (isset($row['gamets']) && $row['gamets'] > 0) {
 $gameTimeDisplay = convert_gamets2fallout_long_date2($row['gamets']);
 }

 echo "<tr data-rowid='{$row['rowid']}'><td>{$people}</td><td class='entry-cell' onclick='openEntryModal(" . json_encode([
 'rowid' => $row['rowid'],
 'topic' => $topic,
 'content' => $displayContent
 ], JSON_HEX_APOS | JSON_HEX_QUOT) . ")'>{$displayContent}</td><td>{$gameTimeDisplay}</td><td>{$timeDisplay}</td><td><button onclick='openEditModal(" . json_encode([
 'rowid' => $row['rowid'],
 'topic' => $topic,
 'content' => $rawContent
 ], JSON_HEX_APOS | JSON_HEX_QUOT) . ")' class='action-button edit'>Edit</button><button onclick='if(confirm(\"Are you sure you want to delete this entry?\")) { deleteEntry(" . $row['rowid'] . "); }' class='btn-danger'>Delete</button></td></tr>";
 }
 } else {
 //error_log("No entries found for person");
 echo "<tr><td colspan='5' style='text-align: center; padding: 20px;'>No diary entries found for this person.</td></tr>";
 }
 } elseif ($shouldFetchEvents && $result) {
 // Reset the result pointer to the beginning for table rendering
 pg_result_seek($result, 0);

 // Initialize variables
 $hasEvents = false;

 // Buffer the output
 ob_start();

 // Fetch and display each row in the table
 while ($row = pg_fetch_assoc($result)) {
 $processed_row = process_diary_row($row, false);
 if ($processed_row === null) {
 continue;
 }

 // Extract processed data
 $topic = htmlspecialchars_decode($row['topic']);
 $rawContent = $row['content']; // Store raw content without nl2br
 $displayContent = nl2br($row['content']); // Format for display
 $people = $processed_row['Nearby People'];
 $timeDisplay = $processed_row['Time(UTC)'];

 // For Fallout mode, check if the event matches the selected date
 if ($useFalloutTime && isset($row['gamets']) && $row['gamets'] > 0) {
 $falloutDate = convert_gamets2fallout_long_date_no_time($row['gamets']);
 if (preg_match('/(\d+)th of ([^,]+) , (\d+)/', $falloutDate, $matches)) {
 $eventDay = intval($matches[1]);
 $eventMonth = $matches[2];
 $eventYear = intval($matches[3]);

 // Only show events that match the current Fallout date
 if ($eventMonth !== $falloutMonths[$month] || $eventYear !== $year || $eventDay !== intval($_GET['day'] ?? 0)) {
 continue;
 }
 }
 }

 // We have at least one event to display
 $hasEvents = true;

 // Convert timestamp to game time
 $gameTimeDisplay = "";
 if (isset($row['gamets']) && $row['gamets'] > 0) {
 $gameTimeDisplay = convert_gamets2fallout_long_date2($row['gamets']);
 }

 // Output the table row with clickable cells for both topic and content
 echo "<tr data-rowid='{$row['rowid']}'><td>{$people}</td><td class='entry-cell' onclick='openEntryModal(" . json_encode([
 'rowid' => $row['rowid'],
 'topic' => $topic,
 'content' => $displayContent
 ], JSON_HEX_APOS | JSON_HEX_QUOT) . ")'>{$displayContent}</td><td>{$gameTimeDisplay}</td><td>{$timeDisplay}</td><td><button onclick='openEditModal(" . json_encode([
 'rowid' => $row['rowid'],
 'topic' => $topic,
 'content' => $rawContent
 ], JSON_HEX_APOS | JSON_HEX_QUOT) . ")' class='action-button edit'>Edit</button><button onclick='if(confirm(\"Are you sure you want to delete this entry?\")) { deleteEntry(" . $row['rowid'] . "); }' class='btn-danger'>Delete</button></td></tr>";
 }

 // If no events were found, display a message
 if (!$hasEvents) {
 echo "<tr><td colspan='5' style='text-align: center; padding: 20px;'>No diary entries found for this date.</td></tr>";
 }

 // Get the buffered content
 $tableContent = ob_get_clean();
 echo $tableContent;
 } else {
 echo "<tr><td colspan='5' style='text-align: center; padding: 20px;'>Select a date to view diary entries.</td></tr>";
 }
 ?></table><?php
 // **Close Database Connection**
 pg_close($conn);
 ?><!-- Edit Modal --><div id="editModal" class="modal-backdrop"><div class="modal-container"><h2>Edit Entry</h2><form id="editForm" onsubmit="return saveEntry(event)"><div class="modal-body"><input type="hidden" id="editRowId" name="rowid"><input type="hidden" id="editTopic" name="topic"><label for="editContent">Content:</label><small>Edit the content of the diary entry below.</small><textarea id="editContent" name="content"></textarea></div><div class="modal-footer"><div class="button-group"><button type="submit" class="btn-save">Save Changes</button><button type="button" onclick="closeEditModal()" class="btn-cancel">Cancel</button></div></div></form></div></div><!-- Entry View Modal --><div id="entryModal" class="modal-backdrop"><div class="modal-container"><div id="entryModalContent" class="entry-content"></div></div></div><script>
 // Debug function to help us see what data we're receiving
 function debugLog(data) {
 console.log('Data received:', data);
 }

 function openEntryModal(data) {
 debugLog(data);
 const modal = document.getElementById('entryModal');
 const content = document.getElementById('entryModalContent');

 if (!modal || !content) {
 console.error('Required modal elements not found');
 return;
 }

 try {
 const entryData = typeof data === 'string' ? JSON.parse(data) : data;
 content.innerHTML = entryData.content || '';
 modal.style.display = 'block';
 document.body.classList.add('modal-open');
 // Focus on the modal content
 content.focus();
 } catch (error) {
 console.error('Error opening entry modal:', error);
 }
 }

 function closeEntryModal() {
 const modal = document.getElementById('entryModal');
 if (modal) {
 modal.style.display = 'none';
 document.body.classList.remove('modal-open');
 }
 }

 function openEditModal(data) {
 debugLog(data);
 const modal = document.getElementById('editModal');
 const rowIdInput = document.getElementById('editRowId');
 const topicInput = document.getElementById('editTopic');
 const contentInput = document.getElementById('editContent');

 if (!modal || !rowIdInput || !topicInput || !contentInput) {
 console.error('Required modal elements not found');
 return;
 }

 try {
 const entryData = typeof data === 'string' ? JSON.parse(data) : data;

 rowIdInput.value = entryData.rowid;
 topicInput.value = entryData.topic || '';
 contentInput.value = entryData.content || '';

 modal.style.display = 'block';
 document.body.classList.add('modal-open');
 // Focus on the content textarea
 contentInput.focus();
 } catch (error) {
 console.error('Error opening edit modal:', error);
 }
 }

 function closeEditModal() {
 const modal = document.getElementById('editModal');
 if (modal) {
 modal.style.display = 'none';
 document.body.classList.remove('modal-open');
 }
 }

 async function saveEntry(event) {
 event.preventDefault();
 const form = event.target;
 const formData = new FormData(form);

 try {
 const response = await fetch(window.location.href, {
 method: 'POST',
 body: formData
 });

 const result = await response.json();
 if (result.success) {
 closeEditModal();
 window.location.reload();
 } else {
 alert('Failed to save changes: ' + (result.error || 'Unknown error'));
 }
 } catch (error) {
 console.error('Error saving entry:', error);
 alert('Failed to save changes. Please try again.');
 }
 return false;
 }

 async function deleteEntry(rowid) {
 try {
 const formData = new FormData();
 formData.append('action', 'delete');
 formData.append('rowid', rowid);

 const response = await fetch(window.location.href, {
 method: 'POST',
 body: formData
 });

 const result = await response.json();
 if (result.success) {
 window.location.reload();
 } else {
 alert('Failed to delete entry: ' + (result.error || 'Unknown error'));
 }
 } catch (error) {
 console.error('Error deleting entry:', error);
 alert('Failed to delete entry. Please try again.');
 }
 }

 async function deleteAllEntries() {
 const confirmMessage = 'Are you sure you want to delete ALL diary entries? This action cannot be undone.\n\nType "DELETE ALL" to confirm:';
 const userInput = prompt(confirmMessage);

 if (userInput !== 'DELETE ALL') {
 if (userInput !== null) {
 alert('Deletion cancelled. You must type "DELETE ALL" exactly to confirm.');
 }
 return;
 }

 try {
 const formData = new FormData();
 formData.append('action', 'delete_all');

 const response = await fetch(window.location.href, {
 method: 'POST',
 body: formData
 });

 const result = await response.json();
 if (result.success) {
 alert(result.message || 'All diary entries have been deleted successfully.');
 window.location.reload();
 } else {
 alert('Failed to delete all entries: ' + (result.error || 'Unknown error'));
 }
 } catch (error) {
 console.error('Error deleting all entries:', error);
 alert('Failed to delete all entries. Please try again.');
 }
 }

 // Close modals when clicking outside
 window.onclick = function(event) {
 const editModal = document.getElementById('editModal');
 const entryModal = document.getElementById('entryModal');
 if (event.target === editModal) {
 closeEditModal();
 } else if (event.target === entryModal) {
 closeEntryModal();
 }
 }

 // Add this to check if the script is loaded
 console.log('Modal script loaded');
 </script></main></body><?php
include(__DIR__.DIRECTORY_SEPARATOR."tmpl/footer.html");

$buffer = ob_get_contents();
ob_end_clean();
$title = $TITLE;
$buffer = preg_replace('/(<title>)(.*?)(<\/title>)/i', '$1' . $title . '$3', $buffer);
echo $buffer;
?></html>
