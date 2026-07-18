<?php

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$root = __DIR__;
$response = [
    'service' => 'DialecticServer',
    'status' => 'unhealthy',
    'version' => trim((string)@file_get_contents($root . DIRECTORY_SEPARATOR . '.version_number.txt')),
    'build' => trim((string)@file_get_contents($root . DIRECTORY_SEPARATOR . '.version.txt')),
    'database' => false,
    'database_encoding' => '',
    'database_encoding_supported' => false,
    'schema' => false,
    'background_processor' => false,
    'background_port' => 12347,
    'timestamp' => gmdate('c'),
];

try {
    require_once $root . DIRECTORY_SEPARATOR . 'lib' . DIRECTORY_SEPARATOR . 'runtime_bootstrap.php';
    require_once $root . DIRECTORY_SEPARATOR . 'lib' . DIRECTORY_SEPARATOR . 'dialectic_runtime.php';
    require_once $root . DIRECTORY_SEPARATOR . 'lib' . DIRECTORY_SEPARATOR . 'background_processor.php';

    dialecticRuntimeBootstrap($root, [
        'run_db_updates' => false,
        'load_general_settings' => false,
        'load_stt_connector' => false,
        'load_tts_connector' => false,
        'load_player_name' => false,
        'load_narrator' => false,
    ]);

    $db = $GLOBALS['db'] ?? null;
    $response['database'] = is_object($db) && (bool)$db->query('SELECT 1');
    $response['database_encoding'] = $response['database'] ? dialecticRuntimeDatabaseEncoding() : '';
    $response['database_encoding_supported'] = $response['database'] && dialecticRuntimeDatabaseEncodingIsSupported();
    $response['schema'] = $response['database']
        && $response['database_encoding_supported']
        && !dialecticRuntimeNeedsDbUpdates();
    $response['background_port'] = dialecticBackgroundProcessorPort();
    $response['background_processor'] = dialecticBackgroundProcessorIsRunning(0.2);
    $response['status'] = $response['schema'] ? 'ok' : 'degraded';
    if ($response['database'] && !$response['database_encoding_supported']) {
        $response['error'] = dialecticRuntimeDatabaseEncodingError();
    }
} catch (Throwable $e) {
    $response['error'] = $e->getMessage();
}

$healthy = $response['database'] && $response['schema'];
http_response_code($healthy ? 200 : 503);
echo json_encode($response, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
