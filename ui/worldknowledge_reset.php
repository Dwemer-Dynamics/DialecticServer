<?php

declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$rootPath = dirname(__DIR__) . DIRECTORY_SEPARATOR;
require_once $rootPath . 'lib' . DIRECTORY_SEPARATOR . 'runtime_bootstrap.php';
require_once $rootPath . 'lib' . DIRECTORY_SEPARATOR . 'worldknowledge_catalog.php';

dialecticRuntimeBootstrap($rootPath, [
    'load_general_settings' => false,
    'load_stt_connector' => false,
]);

$redirect = static function (string $message, bool $error = false): never {
    $query = http_build_query([$error ? 'error' : 'message' => $message]);
    header('Location: worldknowledge_upload.php?' . $query);
    exit;
};

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    $redirect('Factory restore requires a POST request.', true);
}

try {
    $result = dialecticWorldKnowledgeInstallFactoryCatalog($GLOBALS['db'], $rootPath, true);
    $redirect(
        'Factory catalog restored: ' . $result['catalog_id'] . '/' . $result['catalog_version']
        . ' (' . $result['row_count'] . ' articles). Custom articles were preserved.'
    );
} catch (Throwable $exception) {
    $redirect('Factory restore failed: ' . $exception->getMessage(), true);
}
