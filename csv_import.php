<?php

/* CSV Import entry point - handles automatic CSV uploads from the Dialectic plugin. */

error_reporting(E_ALL);

$path = dirname(__FILE__) . DIRECTORY_SEPARATOR;
$GLOBALS["ENGINE_PATH"] = $path;
require_once($path . "lib" . DIRECTORY_SEPARATOR . "runtime_bootstrap.php");

$startTime = microtime(true);

header('Content-Type: application/json');

try {
    dialecticRuntimeBootstrap($path, [
        'load_general_settings' => true,
        'load_stt_connector' => false,
        'load_itt_connector' => false,
    ]);
    require_once($path . "lib" . DIRECTORY_SEPARATOR . "auditing.php");
    require_once($path . "lib" . DIRECTORY_SEPARATOR . "model_dynmodel.php");
    require_once($path . "lib" . DIRECTORY_SEPARATOR . "logger.php");
} catch (Throwable $e) {
    http_response_code(500);
    error_log("Dialectic CSV Import bootstrap failed: " . $e->getMessage());
    echo json_encode([
        'success' => false,
        'error' => 'Server bootstrap failed',
        'details' => $e->getMessage(),
    ]);
    exit;
}

Logger::info("Dialectic CSV Import endpoint started");
$GLOBALS["AUDIT_RUNID_REQUEST"] = "DIALECTIC_CSV_IMPORT";

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

$import_type = $_GET['type'] ?? '';
$timestamp = $_GET['ts'] ?? time();
$game_timestamp = $_GET['gamets'] ?? 0;
$filename = $_GET['filename'] ?? '';

if (!in_array($import_type, ['biography_import'], true)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid import type']);
    exit;
}

if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'No file uploaded or upload error']);
    exit;
}

$fileInfo = pathinfo($_FILES['file']['name']);
$fileExtension = strtolower($fileInfo['extension'] ?? '');
if ($fileExtension !== 'csv') {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Only CSV files are allowed']);
    exit;
}

$maxFileSize = 10 * 1024 * 1024;
if ($_FILES['file']['size'] > $maxFileSize) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'File too large (max 10MB)']);
    exit;
}

$uploadDir = __DIR__ . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'csv_imports' . DIRECTORY_SEPARATOR;
if (!is_dir($uploadDir)) {
    mkdir($uploadDir, 0755, true);
}

$storedFilename = $import_type . '_' . date('Y-m-d_H-i-s') . '_' . uniqid('', true) . '.csv';
$storedFilePath = $uploadDir . $storedFilename;

if (!move_uploaded_file($_FILES['file']['tmp_name'], $storedFilePath)) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Failed to save uploaded file']);
    exit;
}

Logger::info("Dialectic CSV file uploaded: {$storedFilePath}");

try {
    $GLOBALS['db'] = $GLOBALS['db'] ?? new sql();

    $_POST['csv_import'] = '1';
    $_POST['type'] = $import_type;
    $_POST['ts'] = $timestamp;
    $_POST['gamets'] = $game_timestamp;
    $_POST['filename'] = $filename;

    $_FILES['file'] = [
        'name' => $_FILES['file']['name'],
        'type' => 'text/csv',
        'size' => filesize($storedFilePath),
        'tmp_name' => $storedFilePath,
        'error' => UPLOAD_ERR_OK,
    ];

    ob_start();
    require(__DIR__ . DIRECTORY_SEPARATOR . "processor" . DIRECTORY_SEPARATOR . "import_files.php");
    ob_get_clean();

    echo json_encode([
        'success' => true,
        'message' => 'CSV import processed successfully',
        'file' => $storedFilename,
        'type' => $import_type,
        'processing_time' => round(microtime(true) - $startTime, 3),
    ]);
} catch (Throwable $e) {
    Logger::error("Dialectic CSV Import error: " . $e->getMessage());
    if (file_exists($storedFilePath)) {
        unlink($storedFilePath);
    }

    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Processing failed: ' . $e->getMessage(),
    ]);
}

?>
