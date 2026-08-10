<?php
session_start();

// Enable error reporting
error_reporting(E_ALL);
ini_set('display_errors', '1');

// Paths
$rootPath = __DIR__ . DIRECTORY_SEPARATOR . ".." . DIRECTORY_SEPARATOR;
require_once($rootPath . "conf" . DIRECTORY_SEPARATOR . "conf.php");
require_once($rootPath . "lib" . DIRECTORY_SEPARATOR . "logger.php");
require_once($rootPath . "lib" . DIRECTORY_SEPARATOR . "db_connection_settings.php");

$dbSettings = dialecticDbConnectionSettings('dialectic');
$host = $dbSettings['host'];
$port = $dbSettings['port'];
$dbname = $dbSettings['dbname'];
$schema = $dbSettings['schema'];
$username = $dbSettings['username'];
$password = $dbSettings['password'];

// Connect to database
$conn = pg_connect(dialecticPgConnectionString($dbSettings));
if (!$conn) {
    die("Failed to connect to database: " . pg_last_error());
}

try {
    // Start transaction
    pg_query($conn, "BEGIN");

    // First, truncate the worldknowledge table
    $truncateQuery = "TRUNCATE TABLE {$schema}.worldknowledge RESTART IDENTITY";
    $truncateResult = pg_query($conn, $truncateQuery);

    if (!$truncateResult) {
        throw new Exception("Error truncating table: " . pg_last_error($conn));
    }

    // Dialectic does not ship default worldknowledge content.
    // Factory reset intentionally leaves the table empty until the user uploads Fallout-specific rows.
    $countQuery = "SELECT COUNT(*) FROM $schema.worldknowledge";
    $countResult = pg_query($conn, $countQuery);
    if (!$countResult) {
        throw new Exception("Error checking row count: " . pg_last_error($conn));
    }
    
    $rowCount = pg_fetch_result($countResult, 0, 0);

    // Commit transaction
    pg_query($conn, "COMMIT");

    // Close database connection
    pg_close($conn);

    // Redirect back to worldknowledge_upload.php with success message
    header("Location: worldknowledge_upload.php?message=Factory+reset+completed+successfully.+WorldKnowledge+is+empty+and+ready+for+DIALECTIC-specific+uploads.");
    exit;

} catch (Exception $e) {
    // Rollback transaction on error
    pg_query($conn, "ROLLBACK");
    pg_close($conn);
    die("Reset failed: " . $e->getMessage());
}
?>
