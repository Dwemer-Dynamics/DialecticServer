<?php

$enginePath =__DIR__.DIRECTORY_SEPARATOR."..".DIRECTORY_SEPARATOR."..".DIRECTORY_SEPARATOR;

require_once($enginePath."conf".DIRECTORY_SEPARATOR."conf.php");
require_once($enginePath."lib".DIRECTORY_SEPARATOR."db_connection_settings.php");

$dbSettings = dialecticDbConnectionSettings('dialectic');
$host = $dbSettings['host'];
$port = $dbSettings['port'];
$dbname = $dbSettings['dbname'];
$schema = $dbSettings['schema'];
$username = $dbSettings['username'];
$password = $dbSettings['password'];

$conn = pg_connect(dialecticPgConnectionString($dbSettings));

if (!$conn) {
    echo "Failed to connect to database.\n";
    die();
}

$encodingResult = pg_query($conn, 'SHOW server_encoding');
$encodingRow = $encodingResult ? pg_fetch_assoc($encodingResult) : [];
$databaseEncoding = strtoupper(trim(strval($encodingRow['server_encoding'] ?? '')));
if ($databaseEncoding !== 'UTF8') {
    echo "Database rebuild stopped: DIALECTIC requires UTF8, but '{$dbname}' uses {$databaseEncoding}.\n";
    echo "Run sudo bash /var/www/html/DialecticServer/tools/migrate-dialectic-db-utf8-wsl.sh first.\n";
    pg_close($conn);
    exit(1);
}

// A reinstall is destructive: remove the active, metadata, plugin, and snapshot schemas.
$snapshotSchemas = pg_query($conn, "
    SELECT schema_name
      FROM information_schema.schemata
     WHERE schema_name LIKE 'dialectic\_profile\_%' ESCAPE '\\'
");
if ($snapshotSchemas) {
    while ($snapshotSchema = pg_fetch_assoc($snapshotSchemas)) {
        $snapshotSchemaName = strval($snapshotSchema['schema_name'] ?? '');
        if ($snapshotSchemaName !== '') {
            $Q[] = "DROP SCHEMA IF EXISTS " . pg_escape_identifier($conn, $snapshotSchemaName) . " CASCADE";
        }
    }
}

$Q[]="DROP SCHEMA IF EXISTS $schema CASCADE";
$Q[]="DROP SCHEMA IF EXISTS plugins CASCADE";
$Q[]="DROP SCHEMA IF EXISTS dialectic_meta CASCADE";
$Q[]="DROP EXTENSION IF EXISTS vector CASCADE";
$Q[]="CREATE SCHEMA $schema";
$Q[]="CREATE SCHEMA plugins";
$Q[]="CREATE EXTENSION IF NOT EXISTS vector";
$Q[]="CREATE EXTENSION IF NOT EXISTS pg_trgm";

foreach ($Q as $QS) {
  $r = pg_query($conn, $QS);
  if (!$r) {
    echo pg_last_error($conn);
    die();
  } else {
    echo "$QS ok<br/>";
  }
  
}

// Path to SQL file to import
$sqlFile = $enginePath.'/data/database_default.sql';

// Command to import SQL file using psql
putenv("PGPASSWORD={$password}");
$psqlCommand = "psql -h " . escapeshellarg($host) .
    " -p " . escapeshellarg($port) .
    " -U " . escapeshellarg($username) .
    " -d " . escapeshellarg($dbname) .
    " -v ON_ERROR_STOP=1" .
    " -f " . escapeshellarg($sqlFile);

// Execute psql command
$output = [];
$returnVar = 0;
exec($psqlCommand, $output, $returnVar);

if ($returnVar !== 0) {
    echo "Failed to import SQL file.\n";
    echo implode("\n", $output) . "\n";
    exit;
}

echo "Baseline SQL imported successfully.\n";
echo implode("\n", $output) . "\n";

pg_close($conn);

putenv("DIALECTIC_DB_HOST={$host}");
putenv("DIALECTIC_DB_PORT={$port}");
putenv("DIALECTIC_DB_NAME={$dbname}");
putenv("DIALECTIC_DB_USER={$username}");
putenv("DIALECTIC_DB_PASSWORD={$password}");

$bootstrapScript = $enginePath . 'tools' . DIRECTORY_SEPARATOR . 'bootstrap-database.php';
$phpBinary = PHP_BINARY !== '' ? PHP_BINARY : 'php';
$bootstrapCommand = escapeshellarg($phpBinary) . ' ' . escapeshellarg($bootstrapScript) . ' 2>&1';
$bootstrapOutput = [];
$bootstrapReturnVar = 0;
exec($bootstrapCommand, $bootstrapOutput, $bootstrapReturnVar);

if ($bootstrapReturnVar !== 0) {
    echo "Failed to apply or verify DIALECTIC database migrations.\n";
    echo implode("\n", $bootstrapOutput) . "\n";
    exit;
}

echo "DIALECTIC database rebuilt and verified successfully.\n";
echo implode("\n", $bootstrapOutput) . "\n";



?>
