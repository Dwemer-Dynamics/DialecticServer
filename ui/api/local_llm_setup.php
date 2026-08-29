<?php

ob_start();
session_start();

function dialecticLocalLlmRespond(array $payload, int $status = 200): void
{
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Allow: POST');
    dialecticLocalLlmRespond(['ok' => false, 'message' => 'Use POST for local model setup.'], 405);
}
if (strtolower(trim(explode(';', $_SERVER['CONTENT_TYPE'] ?? '')[0])) !== 'application/json') {
    dialecticLocalLlmRespond(['ok' => false, 'message' => 'Send a JSON request.'], 415);
}
$body = file_get_contents('php://input', false, null, 0, 16385);
$raw = strlen($body) <= 16384 ? json_decode($body, true) : null;
if (!is_array($raw)) {
    dialecticLocalLlmRespond(['ok' => false, 'message' => 'Invalid or oversized setup request.'], 400);
}
$expected = $_SESSION['local_llm_csrf_token'] ?? '';
$token = $raw['csrf_token'] ?? '';
if (!is_string($expected) || $expected === '' || !is_string($token) || !hash_equals($expected, $token)) {
    dialecticLocalLlmRespond(['ok' => false, 'message' => 'Reload Quickstart before trying again.'], 403);
}
$action = $raw['action'] ?? '';
if (!in_array($action, ['test', 'save'], true)) {
    dialecticLocalLlmRespond(['ok' => false, 'message' => 'Unknown setup action.'], 400);
}
foreach (['server_type', 'url', 'model', 'api_key', 'timeout', 'scope', 'clear_api_key', 'disable_streaming'] as $field) {
    if (isset($raw[$field]) && !is_scalar($raw[$field])) {
        dialecticLocalLlmRespond(['ok' => false, 'message' => 'Invalid setup field.'], 400);
    }
}
// Session-local submission cap, independent of the browser's disabled buttons.
$now = time();
$rateKey = 'local_llm_next_' . $action;
$interval = $action === 'test' ? 3 : 1;
if ($now < intval($_SESSION[$rateKey] ?? 0)) {
    header('Retry-After: ' . $interval);
    dialecticLocalLlmRespond(['ok' => false, 'message' => 'Wait a few seconds before trying again.'], 429);
}
$_SESSION[$rateKey] = $now + $interval;
session_write_close();

try {
    $enginePath = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR;
    require_once $enginePath . 'lib/runtime_bootstrap.php';
    dialecticRuntimeBootstrap($enginePath, ['load_general_settings' => false, 'load_stt_connector' => false]);
    require_once $enginePath . 'lib/core/local_llm_setup.php';
    $result = $action === 'test' ? dialecticLocalLlmTestDraft($raw) : dialecticLocalLlmApplySetup($raw);
    dialecticLocalLlmRespond($result);
} catch (InvalidArgumentException $e) {
    dialecticLocalLlmRespond(['ok' => false, 'message' => $e->getMessage()], 400);
} catch (Throwable $e) {
    // Avoid returning provider responses, submitted credentials, or internal SQL.
    error_log('[LOCAL LLM SETUP] ' . get_class($e) . ': setup operation failed.');
    dialecticLocalLlmRespond(['ok' => false, 'message' => 'Local model setup failed. Check the server logs.'], 500);
}
