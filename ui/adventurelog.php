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
require_once(dirname(__DIR__).DIRECTORY_SEPARATOR."lib/utils_game_timestamp.php");
require_once(__DIR__.DIRECTORY_SEPARATOR."..".DIRECTORY_SEPARATOR."lib".DIRECTORY_SEPARATOR."logger.php");

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

$TITLE = "DIALECTIC Adventure Log";
$isEmbed = (isset($_GET['embed']) && $_GET['embed'] == '1');

// Connect to the database
$conn = pg_connect(dialecticPgConnectionString($dbSettings));

if (!$conn) {
 echo "<div class='message'>Failed to connect to database: " . pg_last_error() . "</div>";
 exit;
}

// Function to sanitize and validate integers
function sanitize_int($value, $default) {
 $value = filter_var($value, FILTER_VALIDATE_INT);
 return ($value !== false) ? $value : $default;
}

/**
 * Function to process a single event row into formatted data.
 *
 * @param array $row The associative array representing a database row.
 * @param bool $for_csv Indicates whether the output is for CSV (true) or HTML (false).
 * @return array|null An associative array with keys: Context, Nearby People, Location & Fallout Time, Time(UTC).
 */
function process_event_row($row, $for_csv = false) {
 // **Format 'localts' into a readable UTC date format**
 $timestamp = (int)$row['localts'];

 if ($timestamp > 0) {
 // Using DateTime for better control
 $dt = new DateTime("@$timestamp"); // The @ symbol tells DateTime to interpret as Unix timestamp
 $dt->setTimezone(new DateTimeZone('UTC'));
 $timeDisplay = $dt->format('d-m-Y H:i:s');
 } else {
 $timeDisplay = $row['localts'];
 }

 // Add debug logging for gamets conversion
 if (isset($row['gamets']) && $row['gamets'] > 0) {
 error_log("Debug - Raw gamets: " . $row['gamets']);
 error_log("Debug - Converted time: " . convert_gamets2fallout_long_date2($row['gamets']));
 error_log("Debug - Raw location: " . $row['location']);
 }

 // **Step 1: Check the 'type' column**
 $type = $row['type'];

 // Define the allowed types
 $allowedTypes = ['im_alive', 'chat', 'infoaction', 'rpg_lvlup', 'rechat', 'quest', 'itemfound', 'inputtext', 'goodnight', 'goodmorning',  'death', 'combatendmighty', 'combatend', 'lockpicked', 'lockpicked'];

 // If the type is not in the allowed list, return null to skip
 if (!in_array($type, $allowedTypes)) {
 return null;
 }

 // **Raw values**
 $rawData = $row['data'];
 $rawPeople = $row['people'];
 $rawLocation = $row['location'];
 $rawLocalts = $row['localts']; // Original localts timestamp

 // Step 1: Clean the raw location by removing surrounding parentheses
 $cleanLocation = trim($rawLocation, "()");

 // Step 2: Initialize the variable to hold the combined display
 $locationDisplay = '';

 // Step 3: Extract the Date and Time
 // Updated regex to match 'current date' followed by multiple date components
 $datePattern = '/current date\s*([^,]+),\s*([^,]+),\s*([^,]+),\s*([^,]+)/i';
 if (preg_match($datePattern, $cleanLocation, $dateMatch)) {
 // Combine the captured groups to form the complete date string
 // $dateMatch[1] = Saturday
 // $dateMatch[2] = 11:15 PM
 // $dateMatch[3] = 14th of March
 // $dateMatch[4] = 202
 $dateDisplay = trim("{$dateMatch[1]}, {$dateMatch[2]}, {$dateMatch[3]}, {$dateMatch[4]}");
 } else {
 // Handle cases where date/time information is missing
 $dateDisplay = 'Unknown Date';
 }

 // Step 4: Extract the Location and Combine with Date/Time
 // Updated regex to match 'Context new location:'
 $locationPattern = '/Context new location:\s*([^,]+)/i';
 if (preg_match($locationPattern, $cleanLocation, $locationMatch)) {
 // Successfully matched 'Context new location'
 $location = trim($locationMatch[1]);
 $locationDisplay = "{$location} - {$dateDisplay}";
 } else {
 // Fallback to 'Region' if 'Context new location' is not found
 $regionPattern = '/Region:\s*([^,]+)/i';
 if (preg_match($regionPattern, $cleanLocation, $regionMatch)) {
 $region = trim($regionMatch[1]);
 $locationDisplay = "{$region} - {$dateDisplay}";
 } else {
 // Fallback to the entire cleanLocation if both extractions fail
 $locationDisplay = "{$cleanLocation} - {$dateDisplay}";
 }
 }

 // **Transform people**
 // Remove leading/trailing pipes and spaces, then split by '|'
 $cleanPeople = trim($rawPeople, "|() ");
 $peopleList = array_filter(explode("|", $cleanPeople), 'strlen');
 $people = implode(", ", $peopleList);

 // Get the speaker (first person in the list) for row grouping
 $speaker = !empty($peopleList) ? $peopleList[0] : 'Narrator';

 // Use raw data directly for Context, just remove location context if present
 $data = preg_replace('/\(Context location:[^)]+\)/i', '', $rawData);
 $data = trim($data);

 if (!$for_csv) {
 // **Escape HTML for safety only if not exporting to CSV**
 $data = htmlspecialchars($data, ENT_QUOTES, 'UTF-8');
 $people = htmlspecialchars($people, ENT_QUOTES, 'UTF-8');
 $locationDisplay = htmlspecialchars($locationDisplay, ENT_QUOTES, 'UTF-8');
 $timeDisplay = htmlspecialchars($timeDisplay, ENT_QUOTES, 'UTF-8');
 }

 // Return the processed data
 return [
 'Context' => $data,
 'Speaker' => $speaker, // Keep speaker for row grouping
 'Nearby People' => $people,
 'Location & Fallout Time' => $locationDisplay,
 'Time(UTC)' => $timeDisplay
 ];
}

// Function to handle CSV export
function handle_csv_export($conn, $schema) {
 if (isset($_GET['export'])) {
 $exportType = $_GET['export'];

 if ($exportType === 'csv' || $exportType === 'all_csv') {
 // Clear any existing output buffer
 while (ob_get_level()) {
 ob_end_clean();
 }

 $is_specific_date = ($exportType === 'csv');

 if ($is_specific_date) {
 // Get the selected date from URL or latest date if not specified
 if (isset($_GET['date'])) {
 $selectedDate = $_GET['date'];
 // Validate the selected date format (YYYY-MM-DD)
 if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $selectedDate)) {
 // Invalid date format
 header("HTTP/1.1 400 Bad Request");
 echo "Invalid date format.";
 exit;
 }
 } else {
 // Get the most recent date from the eventlog
 $latestDateQuery = "
 SELECT to_char(to_timestamp(localts::double precision) AT TIME ZONE 'UTC', 'YYYY-MM-DD') as event_date
 FROM {$schema}.eventlog
 WHERE type IN ('im_alive', 'chat','infoaction', 'rpg_lvlup', 'rechat', 'quest', 'itemfound', 'inputtext', 'goodnight', 'goodmorning',  'death', 'combatendmighty', 'combatend', 'lockpicked')
 ORDER BY localts DESC
 LIMIT 1
 ";

 $latestDateResult = pg_query($conn, $latestDateQuery);
 if (!$latestDateResult) {
 header("HTTP/1.1 500 Internal Server Error");
 echo "Error fetching latest date: " . pg_last_error($conn);
 exit;
 }

 $latestDateRow = pg_fetch_assoc($latestDateResult);
 if (!$latestDateRow) {
 header("HTTP/1.1 404 Not Found");
 echo "No events found in the adventure log.";
 exit;
 }

 $selectedDate = $latestDateRow['event_date'];
 }

 // Calculate the start and end timestamps for the selected day in UTC
 $dtSelected = new DateTime($selectedDate . ' 00:00:00', new DateTimeZone('UTC'));
 $startOfDay = $dtSelected->getTimestamp();
 $dtSelectedEnd = clone $dtSelected;
 $dtSelectedEnd->modify('+1 day')->modify('-1 second');
 $endOfDay = $dtSelectedEnd->getTimestamp();

 // Prepare the SQL query with explicit casting
 $query = "
 SELECT type, data, people, location, localts, gamets
 FROM {$schema}.eventlog
 WHERE type IN ('im_alive', 'chat','infoaction', 'rpg_lvlup', 'rechat', 'quest', 'itemfound', 'inputtext', 'goodnight', 'goodmorning',  'death', 'combatendmighty', 'combatend', 'lockpicked')
 AND to_timestamp(localts::double precision) BETWEEN to_timestamp($startOfDay) AND to_timestamp($endOfDay)
 ORDER BY localts ASC
 ";
 } elseif ($exportType === 'all_csv') {
 // Export CSV for all data without date filtering
 $query = "
 SELECT type, data, people, location, localts, gamets
 FROM {$schema}.eventlog
 WHERE type IN ('im_alive', 'chat','infoaction', 'rpg_lvlup', 'rechat', 'quest', 'itemfound', 'inputtext', 'goodnight', 'goodmorning',  'death', 'combatendmighty', 'combatend', 'lockpicked')
 ORDER BY localts ASC
 ";
 }

 $result = pg_query($conn, $query);

 if (!$result) {
 header("HTTP/1.1 500 Internal Server Error");
 echo "Error fetching data: " . pg_last_error($conn);
 exit;
 }

 // Set headers to prompt file download
 header('Content-Type: text/csv; charset=utf-8');
 if ($is_specific_date) {
 if (isset($_GET['date'])) {
 header('Content-Disposition: attachment; filename=adventure_log_' . $selectedDate . '.csv');
 } else {
 header('Content-Disposition: attachment; filename=adventure_log_latest.csv');
 }
 } else {
 header('Content-Disposition: attachment; filename=adventure_log_full.csv');
 }

 // Add BOM for Excel compatibility
 fprintf($output = fopen('php://output', 'w'), chr(0xEF).chr(0xBB).chr(0xBF));

 // Open the output stream
 $output = fopen('php://output', 'w');

 // Output the column headings matching the table
 fputcsv($output, ['Context', 'Nearby People', 'Location & Fallout Time', 'Time(UTC)']);

 // Initialize previous location for tracking changes
 $previousLocation = null;

 // Fetch and process each row, then write to the CSV
 while ($row = pg_fetch_assoc($result)) {
 $processed_row = process_event_row($row, true); // true indicates CSV context
 if ($processed_row !== null) {
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
 fputcsv($output, ['', '', 'Location Change: ' . $locationName, '']);
 }

 // Update previous location
 $previousLocation = $processed_row['Location & Fallout Time'];

 // Write the actual event row
 fputcsv($output, [
 $processed_row['Context'],
 $processed_row['Nearby People'],
 $processed_row['Location & Fallout Time'],
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
?><!-- Ensure main.css is loaded after any reboot.css --><link rel="stylesheet" href="<?php echo $webRoot; ?>/ui/css/main.css"><link rel="stylesheet" href="<?php echo $webRoot; ?>/ui/css/diary_adventure.css?v=<?php echo (int) @filemtime(__DIR__ . '/css/diary_adventure.css'); ?>"><style>
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

// Prepare the SQL query with explicit casting to double precision
$allDatesQuery = "
 SELECT DISTINCT
 gamets,
 localts,
 type,
 data,
 people,
 location,
 to_char(to_timestamp(CAST(localts AS bigint)), 'YYYY-MM-DD') as date,
 CASE
 WHEN " . ($useFalloutTime ? 'true' : 'false') . " THEN
 gamets
 ELSE
 localts
 END as sort_field
 FROM {$schema}.eventlog
 WHERE type IN ('im_alive', 'chat', 'infoaction', 'rpg_lvlup', 'rechat', 'quest', 'itemfound', 'inputtext', 'goodnight', 'goodmorning',  'death', 'combatendmighty', 'combatend', 'lockpicked')
 AND gamets > 0
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
 $eventDate->format('Y-m-d');

 if ($eventDate->format('n') == $month && $eventDate->format('Y') == $year) {
 $allEventDates[] = [
 'date' => $dateRow['date'],
 'day' => $eventDate->format('j'),
 'localts' => $dateRow['localts'],
 'type' => $dateRow['type'],
 'data' => $dateRow['data'],
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

 error_log("Debug - Event found: Month={$eventMonth}, Year={$eventYear}, Day={$eventDay}");
 error_log("Debug - Looking for: Month={$month}, Year={$year}");

 if ($eventMonth == $month && $eventYear == $year) {
 error_log("Debug - Adding event for day {$eventDay}");
 $allEventDates[] = [
 'fallout_date' => convert_gamets2fallout_long_date_no_time($gamets),
 'fallout_month' => $falloutMonths[$eventMonth],
 'gamets' => $gamets,
 'localts' => $dateRow['localts'],
 'day' => $eventDay,
 'type' => $dateRow['type'],
 'data' => $dateRow['data'],
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
 error_log("Debug - Selected date: " . $selectedDate);
 error_log("Debug - Start of day: " . $startOfDay);
 error_log("Debug - End of day: " . $endOfDay);

 $query = "
 SELECT type, data, people, location, localts, gamets
 FROM {$schema}.eventlog
 WHERE type IN ('im_alive', 'chat', 'infoaction', 'rpg_lvlup', 'rechat', 'quest', 'itemfound', 'inputtext', 'goodnight', 'goodmorning',  'death', 'combatendmighty', 'combatend', 'lockpicked')
 AND (
 CASE
 WHEN " . ($useFalloutTime ? 'true' : 'false') . " THEN
 -- For Fallout mode, we'll filter in PHP instead of SQL
 gamets > 0
 ELSE
 -- For Gregorian mode, use localts with proper timestamp conversion
 localts >= " . (isset($startOfDay) ? $startOfDay : 0) . "
 AND localts < " . (isset($endOfDay) ? $endOfDay : 0) . "
 END
 )
 ORDER BY localts ASC
 ";

 error_log("Debug - SQL Query: " . $query);
 $result = pg_query($conn, $query);

 if (!$result) {
 echo "<div class='message'>Query error: " . pg_last_error($conn) . "</div>";
 exit;
 }

 // Log the number of rows returned
 $numRows = pg_num_rows($result);
 error_log("Debug - Number of rows returned: " . $numRows);
} else {
 $result = false;
}
?><!DOCTYPE html><html><head><link rel="icon" type="image/x-icon" href="<?php echo $webRoot; ?>/ui/images/favicon.ico"><title>Adventure Log</title></head><body><main class="container"><div class="page-header"><h1>Adventure Log</h1></div><?php
 function renderHeader() {
 echo "<div class='csv-buttons'>";

 $currentCsvParams = [];
 if (isset($_GET['date'])) {
 $currentCsvParams['date'] = $_GET['date'];
 }
 $currentCsvParams['export'] = 'csv';
 if (isset($_GET['month'])) {
 $currentCsvParams['month'] = $_GET['month'];
 }
 if (isset($_GET['year'])) {
 $currentCsvParams['year'] = $_GET['year'];
 }
 $currentCsvQuery = http_build_query($currentCsvParams);

 // Form for current date download
 echo "<form method='get' style='display: inline;'>";
 foreach ($currentCsvParams as $key => $value) {
 echo "<input type='hidden' name='" . htmlspecialchars($key) . "' value='" . htmlspecialchars($value) . "'>";
 }
        echo "<button type='submit' class='btn-base btn-save log-action-button'>Download Current Date</button>";
 echo "</form>";

 $allCsvParams = ['export' => 'all_csv'];
 if (isset($_GET['month'])) {
 $allCsvParams['month'] = $_GET['month'];
 }
 if (isset($_GET['year'])) {
 $allCsvParams['year'] = $_GET['year'];
 }
 $allCsvQuery = http_build_query($allCsvParams);

 // Form for all data download
 echo "<form method='get' style='display: inline;'>";
 foreach ($allCsvParams as $key => $value) {
 echo "<input type='hidden' name='" . htmlspecialchars($key) . "' value='" . htmlspecialchars($value) . "'>";
 }
    echo "<button type='submit' class='btn-base btn-save log-action-button'>Download Entire Adventure Log</button>";
 echo "</form>";

 echo "</div>";
 }

 /**
 * Function to render calendar mode toggle buttons
 * @param bool $useFalloutTime Whether Fallout time is currently active
 * @return void
 */
 function renderCalendarModeButtons($useFalloutTime) {
 echo '<div class="calendar-mode-toggle">';

 // Regular Calendar button - always goes to base URL
 echo '<form method="get" style="display: inline; margin-right: 10px;">';
 echo '<button type="submit" class="btn-base ' . (!$useFalloutTime ? 'btn-primary' : 'btn-secondary') . '">Regular Calendar</button>';
 echo '</form>';

 // Fallout Calendar button - just adds fallout=true
 echo '<form method="get" style="display: inline;">';
 echo '<input type="hidden" name="fallout" value="true">';
 echo '<button type="submit" class="btn-base ' . ($useFalloutTime ? 'btn-primary' : 'btn-secondary') . '">Fallout Calendar</button>';
 echo '</form>';

 echo '</div>';
 }

 // Render Combined CSV Download Buttons at the Top
 renderHeader();
 ?><!-- Add the toggle buttons before the calendar navigation --><?php renderCalendarModeButtons($useFalloutTime); ?><!-- Calendar Navigation --><div class="calendar-navigation"><?php
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
 ?><!-- Event Table --><table class="event-table" id="event-table"><colgroup><col class="col-context"><col class="col-people"><col class="col-gamets"><col class="col-time"></colgroup><tr><th>Context</th><th>Nearby People</th><th><a href="https://fallout.fandom.com/wiki/Timeline" target="_blank" style="color: yellow;">Fallout Time</a></th><th>Time (UTC)</th></tr><?php
 if ($shouldFetchEvents && $result) {
 // Reset the result pointer to the beginning for table rendering
 pg_result_seek($result, 0);

 // Initialize variables
 $hasEvents = false;
 $currentSpeaker = null;
 $speakerGroup = 0;
 $previousLocation = null;
 $locationHeader = '';

 // Get the first row to check initial location
 $firstRow = pg_fetch_assoc($result);
 if ($firstRow) {
 $firstProcessedRow = process_event_row($firstRow, false);
 if ($firstProcessedRow !== null) {
 // Extract just the location name without date/time
 $locationPattern = '/Context new location:\s*([^,]+)/i';
 $cleanLocation = trim($firstRow['location'], "()");
 if (preg_match($locationPattern, $cleanLocation, $locationMatch)) {
 $initialLocation = trim($locationMatch[1]);
 } else {
 $regionPattern = '/Region:\s*([^,]+)/i';
 if (preg_match($regionPattern, $cleanLocation, $regionMatch)) {
 $initialLocation = trim($regionMatch[1]);
 } else {
 $initialLocation = $cleanLocation;
 }
 }
 $locationHeader = "<tr class='location-change-row'><td colspan='4'>Current Location: {$initialLocation}</td></tr>";
 }
 // Reset the result pointer again for the main loop
 pg_result_seek($result, 0);
 }

 // Buffer the output
 ob_start();

 // Fetch and display each row in the table
 while ($row = pg_fetch_assoc($result)) {
 $processed_row = process_event_row($row, false);
 if ($processed_row === null) {
 continue;
 }

 // Extract processed data
 $data = $processed_row['Context'];
 $speaker = $processed_row['Speaker'];
 $people = $processed_row['Nearby People'];
 $location = $processed_row['Location & Fallout Time'];
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
 if (!$hasEvents) {
 $hasEvents = true;
 // Output the location header only when we have events
 echo $locationHeader;
 }

 // Check for location change
 if ($previousLocation !== null && $previousLocation !== $location) {
 // Extract just the location name without date/time for the divider
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
 // Output location change row with simplified location
 echo "<tr class='location-change-row'><td colspan='4'>Location Change: {$locationName}</td></tr>";
 }

 // Update previous location
 $previousLocation = $location;

 // Check if speaker changed
 // Extract speaker from the data content
 $speakerFromData = '';
 if (preg_match('/^([^:]+):/', $data, $matches)) {
 $speakerFromData = trim($matches[1]);
 }

 // Use extracted speaker if available, otherwise use the one from people list
 $effectiveSpeaker = $speakerFromData ?: $speaker;

 if ($currentSpeaker !== $effectiveSpeaker) {
 $currentSpeaker = $effectiveSpeaker;
 $speakerGroup++;
 }

 // Convert timestamp to game time
 $gameTimeDisplay = "";
 if (isset($row['gamets']) && $row['gamets'] > 0) {
 $gameTimeDisplay = convert_gamets2fallout_long_date2($row['gamets']);
 }

 // Output the table row with speaker-based styling
 $rowClass = ($speakerGroup % 2 === 0) ? 'speaker-even' : 'speaker-odd';
 echo "<tr class='{$rowClass}'><td>{$data}</td><td>{$people}</td><td>{$gameTimeDisplay}</td><td>{$timeDisplay}</td></tr>";
 }

 // If no events were found, display a message
 if (!$hasEvents) {
 echo "<tr><td colspan='4' style='text-align: center; padding: 20px;'>No events found for this date.</td></tr>";
 }

 // Get the buffered content
 $tableContent = ob_get_clean();
 echo $tableContent;
 } else {
 echo "<tr><td colspan='4' style='text-align: center; padding: 20px;'>Select a date to view events.</td></tr>";
 }
 ?></table><?php
 // **Close Database Connection**
 pg_close($conn);
 ?></main></body><?php
include(__DIR__.DIRECTORY_SEPARATOR."tmpl/footer.html");

$buffer = ob_get_contents();
ob_end_clean();
$title = $TITLE;
$buffer = preg_replace('/(<title>)(.*?)(<\/title>)/i', '$1' . $title . '$3', $buffer);
echo $buffer;
?></html>
