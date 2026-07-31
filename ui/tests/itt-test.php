<?php

$enginePath = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR;
require_once($enginePath . 'lib' . DIRECTORY_SEPARATOR . 'runtime_bootstrap.php');
dialecticRuntimeBootstrap($enginePath, [
    'load_general_settings' => true,
    'load_stt_connector' => false,
    'load_itt_connector' => false,
]);
require_once($enginePath . 'lib' . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'itt_connector.class.php');
require_once($enginePath . 'lib' . DIRECTORY_SEPARATOR . 'itt_service.php');

$connector = new ITTConnector();
$activeId = dialecticGetGeneralSettingInt('GLOBAL_ITT_CONNECTOR_ID', 0);
$activeConnector = $activeId > 0 ? $connector->getById($activeId) : null;
$activeDriver = $connector->normalizeDriverValue($activeConnector['driver'] ?? '');
$description = '';
$error = '';
$elapsed = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (!$activeConnector) {
            throw new RuntimeException('No active ITT connector is configured.');
        }
        $file = $_FILES['test_image'] ?? null;
        if (!is_array($file) || intval($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            throw new RuntimeException('Choose a JPG, PNG, WEBP, or GIF image.');
        }

        $startedAt = microtime(true);
        $result = dialecticIttDescribe(strval($file['tmp_name']), [
            'location' => 'PipVision connector test',
            'worldspace' => 'Fallout',
            'subject' => ['name' => 'Test image'],
        ], $activeConnector);
        $elapsed = microtime(true) - $startedAt;
        $description = trim(strval($result['description'] ?? ''));
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

$TITLE = 'Dialectic ITT Test';
$webRoot = '';
$scriptPath = $_SERVER['SCRIPT_NAME'] ?? '';
$uiPos = strpos($scriptPath, '/ui/');
if ($uiPos !== false) {
    $webRoot = rtrim(substr($scriptPath, 0, $uiPos), '/');
}
ob_start();
include(dirname(__DIR__) . DIRECTORY_SEPARATOR . 'tmpl' . DIRECTORY_SEPARATOR . 'head.html');
?>
<link rel="stylesheet" href="<?php echo htmlspecialchars($webRoot, ENT_QUOTES, 'UTF-8'); ?>/ui/css/main.css">
<style>
body { background: #1d1d1d; color: #f5f5f5; }
main { padding: 24px; max-width: 920px; margin: 0 auto; }
.test-header { margin-bottom: 18px; padding-right: 80px; }
.test-header h1 { color: rgb(255,182,65); font-size: 1.7rem; margin: 0 0 6px; }
.status, .test-panel, .result { background: #272727; border: 1px solid #474747; border-radius: 7px; padding: 13px; }
.status { margin-bottom: 12px; color: #b8c8da; }
.status strong { color: #fff; }
.test-panel { margin-top: 16px; }
.test-panel label { display: block; margin-bottom: 7px; }
.test-panel input[type=file] { width: 100%; box-sizing: border-box; background: #181818; color: #fff; border: 1px solid #4f4f4f; border-radius: 5px; padding: 9px; }
.test-button { margin-top: 12px; border: 1px solid #d16905; background: #b95a00; color: #fff; border-radius: 6px; padding: 8px 14px; cursor: pointer; }
.result { margin-top: 16px; white-space: pre-wrap; line-height: 1.5; }
.error { color: #ff8f8f; border-color: #8b4444; }
.elapsed { color: #9eb1c9; margin-top: 9px; font-size: .9rem; }
</style>
<main>
    <div class="test-header">
        <h1>Dialectic Image-to-Text Test</h1>
        <div>Upload an image to test the currently saved global ITT connector.</div>
    </div>
    <div class="status"><strong>Active ITT:</strong> <?php echo htmlspecialchars($activeDriver !== '' ? $connector->getDisplayName($activeDriver) : 'None', ENT_QUOTES, 'UTF-8'); ?></div>
    <div class="status"><strong>Connector:</strong> <?php echo htmlspecialchars(strval($activeConnector['label'] ?? 'Not configured'), ENT_QUOTES, 'UTF-8'); ?></div>

    <form class="test-panel" method="post" enctype="multipart/form-data">
        <label for="test_image">Test image</label>
        <input id="test_image" type="file" name="test_image" accept="image/jpeg,image/png,image/webp,image/gif" required>
        <button class="test-button" type="submit">Run Test</button>
    </form>

    <?php if ($description !== ''): ?>
        <div class="result"><?php echo htmlspecialchars($description, ENT_QUOTES, 'UTF-8'); ?></div>
        <?php if ($elapsed !== null): ?><div class="elapsed">Completed in <?php echo number_format($elapsed, 2); ?> seconds.</div><?php endif; ?>
    <?php elseif ($error !== ''): ?>
        <div class="result error"><?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></div>
    <?php endif; ?>
</main>
<?php
include(dirname(__DIR__) . DIRECTORY_SEPARATOR . 'tmpl' . DIRECTORY_SEPARATOR . 'footer.html');
$buffer = ob_get_clean();
$buffer = preg_replace('/(<title>)(.*?)(<\/title>)/i', '$1' . $TITLE . '$3', $buffer);
echo $buffer;
