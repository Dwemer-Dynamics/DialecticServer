<?php

ob_start();
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

function dialecticPresetsRespond(array $payload, int $status = 200): void
{
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if (!in_array($method, ['GET', 'POST'], true)) {
    header('Allow: GET, POST');
    dialecticPresetsRespond(['ok' => false, 'error' => 'Use GET or POST.'], 405);
}
if (empty($_SESSION['global_settings_presets_csrf'])) {
    $_SESSION['global_settings_presets_csrf'] = bin2hex(random_bytes(32));
}
$token = $_SESSION['global_settings_presets_csrf'];
$request = [];
if ($method === 'POST') {
    $raw = file_get_contents('php://input', false, null, 0, 65537);
    if ($raw === false || strlen($raw) > 65536) {
        dialecticPresetsRespond(['ok' => false, 'error' => 'Preset request is too large.'], 413);
    }
    $request = json_decode($raw, true, 32);
    if (!is_array($request) || !is_string($request['csrf_token'] ?? null)
        || !hash_equals($token, $request['csrf_token'])) {
        dialecticPresetsRespond(['ok' => false, 'error' => 'Reload Global Settings and try again.'], 403);
    }
    if (!is_string($request['action'] ?? null)
        || !in_array($request['action'], ['save', 'overwrite', 'apply'], true)) {
        dialecticPresetsRespond(['ok' => false, 'error' => 'Unknown preset action.'], 400);
    }
    // Bound repeated submissions in this admin session; storage also caps saved presets globally.
    $recent = array_filter($_SESSION['global_settings_presets_requests'] ?? [], static fn($time) => $time > time() - 60);
    if (count($recent) >= 30) {
        dialecticPresetsRespond(['ok' => false, 'error' => 'Too many requests. Try again in a minute.'], 429);
    }
    $recent[] = time();
    $_SESSION['global_settings_presets_requests'] = array_values($recent);
}
session_write_close();

$enginePath = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR;
require_once $enginePath . 'lib/runtime_bootstrap.php';
require_once $enginePath . 'lib/core/settings_presets.php';
try {
    dialecticRuntimeBootstrap($enginePath, ['load_general_settings' => false, 'load_stt_connector' => false]);
    if ($method === 'GET') {
        dialecticPresetsRespond(['ok' => true, 'presets' => dialecticSettingsPresetCatalog(),
            'csrf_token' => $token, 'setting_ids' => array_keys(dialecticSettingsPresetDefaults())]);
    }
    if (isset($request['preset_id']) && !is_string($request['preset_id'])) {
        throw new InvalidArgumentException('Invalid preset.');
    }
    if ($request['action'] === 'apply') {
        $result = dialecticSettingsPresetApply($request['preset_id'] ?? '');
        dialecticPresetsRespond(['ok' => true] + $result);
    }
    if (!is_array($request['settings'] ?? null) || !is_array($request['prompt_context_options'] ?? null)
        || (isset($request['name']) && !is_string($request['name']))) {
        throw new InvalidArgumentException('Invalid preset settings.');
    }
    if ($request['action'] === 'overwrite' && empty($request['preset_id'])) {
        throw new InvalidArgumentException('Select a saved custom preset.');
    }
    $preset = dialecticSettingsPresetSave($request['name'] ?? '', [
        'version' => 1, 'settings' => $request['settings'], 'prompt_context_options' => $request['prompt_context_options'],
    ], $request['action'] === 'overwrite' ? $request['preset_id'] : '');
    dialecticPresetsRespond(['ok' => true, 'preset' => $preset, 'presets' => dialecticSettingsPresetCatalog()]);
} catch (InvalidArgumentException $error) {
    dialecticPresetsRespond(['ok' => false, 'error' => $error->getMessage()], 400);
} catch (Throwable $error) {
    Logger::error('Global settings preset operation failed: ' . $error->getMessage());
    dialecticPresetsRespond(['ok' => false, 'error' => 'Could not update presets. Check the server log and try again.'], 500);
}
