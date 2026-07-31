<?php

$enginePath = dirname(__DIR__) . DIRECTORY_SEPARATOR;
require_once($enginePath . 'lib' . DIRECTORY_SEPARATOR . 'runtime_bootstrap.php');
dialecticRuntimeBootstrap($enginePath, [
    'load_general_settings' => true,
    'load_stt_connector' => false,
    'load_itt_connector' => false,
]);
require_once($enginePath . 'lib' . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'itt_connector.class.php');
require_once($enginePath . 'lib' . DIRECTORY_SEPARATOR . 'pipvision_service.php');

try {
    require_once($enginePath . 'debug' . DIRECTORY_SEPARATOR . 'db_updates.php');
} catch (Throwable $e) {
}

$isEmbed = strval($_GET['embed'] ?? '') === '1';
$scriptPath = $_SERVER['SCRIPT_NAME'] ?? '';
$uiPos = strpos($scriptPath, '/ui/');
$webRoot = ($uiPos !== false) ? substr($scriptPath, 0, $uiPos) : '';
if ($webRoot === '/') {
    $webRoot = '';
}
$webRoot = rtrim($webRoot, '/');
$connector = new ITTConnector();
$message = '';
$error = '';

function pipVisionH($value): string
{
    return htmlspecialchars(strval($value), ENT_QUOTES, 'UTF-8');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = strval($_POST['action'] ?? '');
    try {
        if ($action === 'save') {
            $driver = strtolower(trim(strval($_POST['driver'] ?? '')));
            $metadata = [
                'model' => trim(strval($_POST['model'] ?? '')),
                'max_tokens' => intval($_POST['max_tokens'] ?? 350),
                'temperature' => floatval($_POST['temperature'] ?? 0.2),
                'prompt' => trim(strval($_POST['prompt'] ?? '')),
            ];
            $payload = [
                'driver' => $driver,
                'label' => trim(strval($_POST['label'] ?? '')),
                'url' => trim(strval($_POST['url'] ?? '')),
                'api_badge_id' => intval($_POST['api_badge_id'] ?? 0),
                'metadata' => $metadata,
            ];
            $id = intval($_POST['id'] ?? 0);
            if ($id > 0) {
                if (!$connector->update($id, $payload)) throw new RuntimeException('Connector update failed');
            } else {
                $id = $connector->create($payload);
                if ($id < 1) throw new RuntimeException('Connector creation failed');
            }
            if (!empty($_POST['make_active'])) {
                dialecticSetGeneralSetting('GLOBAL_ITT_CONNECTOR_ID', $id, dialecticGetSchemaDescription('GLOBAL_ITT_CONNECTOR_ID'));
            }
            $message = 'PipVision connector saved.';
        } elseif ($action === 'activate') {
            $id = intval($_POST['id'] ?? 0);
            if (!$connector->getById($id)) throw new RuntimeException('Connector was not found');
            dialecticSetGeneralSetting('GLOBAL_ITT_CONNECTOR_ID', $id, dialecticGetSchemaDescription('GLOBAL_ITT_CONNECTOR_ID'));
            $message = 'Active PipVision connector updated.';
        } elseif ($action === 'delete') {
            $id = intval($_POST['id'] ?? 0);
            if ($id === dialecticGetGeneralSettingInt('GLOBAL_ITT_CONNECTOR_ID', 0)) {
                dialecticSetGeneralSetting('GLOBAL_ITT_CONNECTOR_ID', 0, dialecticGetSchemaDescription('GLOBAL_ITT_CONNECTOR_ID'));
            }
            if (!$connector->delete($id)) throw new RuntimeException('Connector deletion failed');
            $message = 'PipVision connector deleted.';
        } elseif ($action === 'test') {
            $id = intval($_POST['id'] ?? 0);
            $row = $connector->getById($id);
            if (!$row) throw new RuntimeException('Save the connector before testing it');
            $file = $_FILES['test_image'] ?? null;
            if (!is_array($file) || intval($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
                throw new RuntimeException('Choose a JPG or PNG test image');
            }
            $result = dialecticPipVisionDescribe(strval($file['tmp_name']), [
                'location' => 'PipVision connector test',
                'worldspace' => 'Fallout',
                'subject' => ['name' => 'Test image'],
            ], $row);
            $message = 'Test description: ' . strval($result['description'] ?? '');
        }
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

$activeId = dialecticGetGeneralSettingInt('GLOBAL_ITT_CONNECTOR_ID', 0);
$rows = $connector->readAll();
$badges = $GLOBALS['db']->fetchAll('SELECT id, label FROM public.core_api_badge ORDER BY LOWER(label)');
$drivers = $connector->getDriverOptions();
$editId = intval($_GET['edit'] ?? 0);
$editing = $editId > 0 ? $connector->getById($editId) : null;
$editMetadata = $editing ? json_decode(strval($editing['metadata'] ?? '{}'), true) : [];
if (!is_array($editMetadata)) $editMetadata = [];
$selectedDriver = strval($editing['driver'] ?? 'openrouter');
$defaultUrl = $editing['url'] ?? $connector->getDefaultUrl($selectedDriver);
$defaultModel = $editMetadata['model'] ?? $connector->getDefaultModel($selectedDriver);

$TITLE = 'PipVision';
$BODY_CLASS = 'hub-page';
ob_start();
include(__DIR__ . DIRECTORY_SEPARATOR . 'tmpl' . DIRECTORY_SEPARATOR . 'head.html');
if (!$isEmbed) include(__DIR__ . DIRECTORY_SEPARATOR . 'tmpl' . DIRECTORY_SEPARATOR . 'navbar.php');
?>
<link rel="stylesheet" href="css/main.css">
<style>
body{background:#1d1d1d;color:#f5f5f5}.pipvision-page{padding:18px;max-width:1180px;margin:0 auto}.pv-header{display:flex;align-items:center;justify-content:space-between;gap:16px;margin-bottom:14px}.pv-header h1{font-size:1.7rem;margin:0;color:rgb(255,182,65)}.pv-grid{display:grid;grid-template-columns:minmax(320px,1fr) minmax(360px,1.3fr);gap:14px}.pv-panel,.pv-card{background:#292929;border:1px solid #474747;border-radius:6px;padding:14px}.pv-card{margin-bottom:10px}.pv-card.active{border-color:rgb(255,182,65)}.pv-card-head{display:flex;justify-content:space-between;gap:10px;align-items:center}.pv-badge{color:#151515;background:rgb(255,182,65);padding:3px 7px;border-radius:4px;font-size:.78rem}.pv-form label{display:block;margin:10px 0 4px;color:#ddd}.pv-form input,.pv-form select,.pv-form textarea{width:100%;box-sizing:border-box;background:#181818;color:#fff;border:1px solid #555;border-radius:4px;padding:8px}.pv-form textarea{min-height:100px;resize:vertical}.pv-actions{display:flex;flex-wrap:wrap;gap:7px;margin-top:12px}.pv-button{border:1px solid #6b6b6b;background:#353535;color:#fff;border-radius:4px;padding:7px 11px;cursor:pointer;text-decoration:none}.pv-button.primary{background:rgb(255,182,65);border-color:rgb(255,182,65);color:#151515}.pv-message{padding:10px;border-radius:4px;margin-bottom:12px;background:#213a29;border:1px solid #3e8050}.pv-error{background:#4a2222;border-color:#9b4949}.pv-muted{color:#aaa;font-size:.88rem;overflow-wrap:anywhere}@media(max-width:820px){.pv-grid{grid-template-columns:1fr}.pv-header{align-items:flex-start;flex-direction:column}}
</style>
<main class="pipvision-page">
  <div class="pv-header"><div><h1>PipVision</h1><div class="pv-muted">Configure the image model used to interpret Fallout screenshots.</div></div><a class="pv-button" href="itt_connectors.php<?= $isEmbed ? '?embed=1' : '' ?>">New Connector</a></div>
  <?php if ($message !== ''): ?><div class="pv-message"><?= pipVisionH($message) ?></div><?php endif; ?>
  <?php if ($error !== ''): ?><div class="pv-message pv-error"><?= pipVisionH($error) ?></div><?php endif; ?>
  <div class="pv-grid">
    <section>
      <?php if (!$rows): ?><div class="pv-panel">No PipVision connectors configured.</div><?php endif; ?>
      <?php foreach ($rows as $row): $rowId=intval($row['id']); $meta=json_decode(strval($row['metadata'] ?? '{}'),true); if(!is_array($meta))$meta=[]; ?>
      <article class="pv-card <?= $rowId === $activeId ? 'active' : '' ?>">
        <div class="pv-card-head"><strong><?= pipVisionH($row['label'] ?? $row['driver']) ?></strong><?php if($rowId===$activeId):?><span class="pv-badge">ACTIVE</span><?php endif;?></div>
        <div class="pv-muted"><?= pipVisionH($drivers[$row['driver']] ?? $row['driver']) ?> | <?= pipVisionH($meta['model'] ?? 'No model') ?></div>
        <div class="pv-muted"><?= pipVisionH($row['url'] ?? '') ?></div>
        <div class="pv-actions">
          <a class="pv-button" href="?<?= http_build_query(array_filter(['embed'=>$isEmbed?'1':null,'edit'=>$rowId])) ?>">Edit</a>
          <?php if($rowId!==$activeId):?><form method="post"><input type="hidden" name="action" value="activate"><input type="hidden" name="id" value="<?= $rowId ?>"><button class="pv-button" type="submit">Use</button></form><?php endif;?>
          <form method="post" onsubmit="return confirm('Delete this PipVision connector?');"><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= $rowId ?>"><button class="pv-button" type="submit">Delete</button></form>
        </div>
      </article>
      <?php endforeach; ?>
    </section>
    <section class="pv-panel">
      <form class="pv-form" method="post">
        <input type="hidden" name="action" value="save"><input type="hidden" name="id" value="<?= intval($editing['id'] ?? 0) ?>">
        <label for="driver">Provider</label><select id="driver" name="driver"><?php foreach($drivers as $value=>$label):?><option value="<?= pipVisionH($value) ?>" <?= $selectedDriver===$value?'selected':'' ?>><?= pipVisionH($label) ?></option><?php endforeach;?></select>
        <label for="label">Name</label><input id="label" name="label" value="<?= pipVisionH($editing['label'] ?? '') ?>" placeholder="PipVision OpenRouter">
        <label for="url">Endpoint URL</label><input id="url" name="url" value="<?= pipVisionH($defaultUrl) ?>">
        <label for="model">Vision model</label><input id="model" name="model" value="<?= pipVisionH($defaultModel) ?>">
        <label for="api_badge_id">API key</label><select id="api_badge_id" name="api_badge_id"><option value="0">None / local service</option><?php foreach($badges as $badge):?><option value="<?= intval($badge['id']) ?>" <?= intval($editing['api_badge_id'] ?? 0)===intval($badge['id'])?'selected':'' ?>><?= pipVisionH($badge['label']) ?></option><?php endforeach;?></select>
        <label for="max_tokens">Maximum tokens</label><input id="max_tokens" type="number" min="64" max="1200" name="max_tokens" value="<?= intval($editMetadata['max_tokens'] ?? 350) ?>">
        <label for="temperature">Temperature</label><input id="temperature" type="number" min="0" max="2" step="0.1" name="temperature" value="<?= pipVisionH($editMetadata['temperature'] ?? 0.2) ?>">
        <label for="prompt">Visual description instruction</label><textarea id="prompt" name="prompt" placeholder="Leave empty for the Fallout-aware default."><?= pipVisionH($editMetadata['prompt'] ?? '') ?></textarea>
        <label><input type="checkbox" name="make_active" value="1" <?= !$editing || intval($editing['id'] ?? 0)===$activeId?'checked':'' ?> style="width:auto"> Make active</label>
        <div class="pv-actions"><button class="pv-button primary" type="submit">Save Connector</button></div>
      </form>
      <?php if($editing): ?><hr style="border-color:#444;margin:18px 0"><form class="pv-form" method="post" enctype="multipart/form-data"><input type="hidden" name="action" value="test"><input type="hidden" name="id" value="<?= intval($editing['id']) ?>"><label for="test_image">Test image</label><input id="test_image" type="file" name="test_image" accept="image/jpeg,image/png" required><div class="pv-actions"><button class="pv-button" type="submit">Test Connector</button></div></form><?php endif; ?>
    </section>
  </div>
</main>
<script>
(function(){const driver=document.getElementById('driver'),url=document.getElementById('url'),model=document.getElementById('model');if(!driver)return;const defaults={openrouter:['https://openrouter.ai/api/v1/chat/completions','google/gemini-2.5-flash'],openai:['https://api.openai.com/v1/chat/completions','gpt-4.1-mini'],google_openai:['https://generativelanguage.googleapis.com/v1beta/openai/chat/completions','gemini-2.5-flash'],llamacpp:['http://127.0.0.1:8080/v1/chat/completions',''],custom:['','']};driver.addEventListener('change',function(){const value=defaults[driver.value]||['',''];url.value=value[0];model.value=value[1];});})();
</script>
<?php
include(__DIR__ . DIRECTORY_SEPARATOR . 'tmpl' . DIRECTORY_SEPARATOR . 'footer.html');
$buffer = ob_get_clean();
$buffer = preg_replace('/(<title>)(.*?)(<\/title>)/i', '$1' . $TITLE . '$3', $buffer);
echo $buffer;
