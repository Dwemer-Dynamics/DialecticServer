<?php

$enginePath = dirname(__DIR__) . DIRECTORY_SEPARATOR;

require_once($enginePath . 'lib' . DIRECTORY_SEPARATOR . 'runtime_bootstrap.php');
require_once($enginePath . 'lib' . DIRECTORY_SEPARATOR . 'logger.php');
require_once($enginePath . 'lib' . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'api_badge.class.php');
require_once($enginePath . 'lib' . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'itt_connector.class.php');

dialecticRuntimeBootstrap($enginePath, [
    'load_general_settings' => true,
    'load_stt_connector' => false,
    'load_itt_connector' => false,
]);

try {
    require_once($enginePath . 'debug' . DIRECTORY_SEPARATOR . 'db_updates.php');
} catch (Throwable $_e) {
}

$connector = new ITTConnector();
$isEmbed = strval($_GET['embed'] ?? '') === '1';

$scriptPath = $_SERVER['SCRIPT_NAME'] ?? '';
$uiPos = strpos($scriptPath, '/ui/');
$webRoot = ($uiPos !== false) ? substr($scriptPath, 0, $uiPos) : '';
if ($webRoot === '/') {
    $webRoot = '';
}
$webRoot = rtrim($webRoot, '/');

function ih($value): string
{
    return htmlspecialchars(strval($value), ENT_QUOTES, 'UTF-8');
}

function ittPageUrl(array $params = []): string
{
    global $isEmbed;
    if ($isEmbed && !isset($params['embed'])) {
        $params['embed'] = '1';
    }
    $query = http_build_query($params);
    return $query !== '' ? ('itt_connectors.php?' . $query) : 'itt_connectors.php';
}

function ittVisibleDriverOptions(ITTConnector $connector): array
{
    $options = $connector->getDriverOptions();
    if (empty($options)) {
        return ['openrouter', 'custom', 'openai', 'google_openai', 'llamacpp'];
    }
    return array_values(array_unique($options));
}

function ittGroupedDriverOptions(ITTConnector $connector, array $driverOptions): array
{
    $normalized = [];
    foreach ($driverOptions as $driverOption) {
        $driverValue = $connector->normalizeDriverValue($driverOption);
        if ($driverValue !== '') {
            $normalized[$driverValue] = true;
        }
    }

    $groups = [
        'Recommended' => ['openrouter'],
        'Local / Self-Hosted' => ['custom', 'llamacpp'],
        'Other Services' => ['openai', 'google_openai'],
    ];

    $output = [];
    foreach ($groups as $groupLabel => $groupDrivers) {
        $output[$groupLabel] = [];
        foreach ($groupDrivers as $groupDriver) {
            if (isset($normalized[$groupDriver])) {
                $output[$groupLabel][] = $groupDriver;
                unset($normalized[$groupDriver]);
            }
        }
    }
    if (!empty($normalized)) {
        $output['Other Services'] = array_merge($output['Other Services'] ?? [], array_keys($normalized));
    }
    return $output;
}

function ittCreateDefaultConnector(ITTConnector $connector): int
{
    $options = ittVisibleDriverOptions($connector);
    $defaultDriver = $connector->normalizeDriverValue($options[0] ?? 'openrouter');
    if ($defaultDriver === '') {
        $defaultDriver = 'openrouter';
    }

    return $connector->create([
        'driver' => $defaultDriver,
        'label' => 'Global ITT Connector',
        'metadata' => json_encode($connector->getDefaultMetadataForDriver($defaultDriver), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        'api_badge_id' => $connector->getDefaultApiBadgeIdForDriver($defaultDriver),
        'url' => $connector->getDefaultUrlForDriver($defaultDriver),
    ]);
}

function ittEnsureActiveConnectorId(ITTConnector $connector): int
{
    $activeId = dialecticGetGeneralSettingInt('GLOBAL_ITT_CONNECTOR_ID', 0);
    if ($activeId > 0 && $connector->getById($activeId)) {
        return $activeId;
    }

    $rows = $connector->readAll();
    if (!empty($rows)) {
        $activeId = intval($rows[0]['id'] ?? 0);
    } else {
        $activeId = ittCreateDefaultConnector($connector);
    }

    if ($activeId > 0) {
        dialecticSetGeneralSetting('GLOBAL_ITT_CONNECTOR_ID', $activeId, dialecticGetSchemaDescription('GLOBAL_ITT_CONNECTOR_ID'));
    }
    return $activeId;
}

function ittFieldLabel(string $fieldName): string
{
    $special = [
        'API_KEY' => 'API Key',
        'URL' => 'URL',
        'url' => 'URL',
        'AI_PROMPT' => 'Prompt',
        'AI_VISION_PROMPT' => 'Vision Prompt',
    ];
    return $special[$fieldName] ?? ucwords(str_replace(['_', '-'], ' ', strtolower(trim($fieldName))));
}

function ittShouldRenderField(string $fieldName, $definition): bool
{
    if ($fieldName === '_title' || !is_array($definition)) {
        return false;
    }
    return !in_array($fieldName, ['API_KEY', 'url', 'URL', 'endpoint'], true);
}

function ittParseMetadataFromPost(array $source, string $driver, array $existingMetadata, ITTConnector $connector): array
{
    $metadata = $existingMetadata;
    foreach ($connector->getProviderFieldSchema($driver) as $fieldName => $definition) {
        if (!ittShouldRenderField($fieldName, $definition)) {
            continue;
        }

        $postKey = 'meta__' . $driver . '__' . $fieldName;
        $type = $definition['type'] ?? 'string';
        if ($type !== 'boolean' && !array_key_exists($postKey, $source)) {
            continue;
        }

        if ($type === 'boolean') {
            $metadata[$fieldName] = strval($source[$postKey] ?? '') === 'true';
        } elseif ($type === 'integer' || $type === 'int') {
            $raw = trim(strval($source[$postKey] ?? ''));
            $metadata[$fieldName] = $raw === '' ? 0 : intval($raw);
        } elseif ($type === 'number') {
            $raw = trim(strval($source[$postKey] ?? ''));
            $metadata[$fieldName] = $raw === '' ? 0 : floatval($raw);
        } elseif ($type === 'selectmultiple') {
            $metadata[$fieldName] = is_array($source[$postKey] ?? null) ? array_values($source[$postKey]) : [];
        } else {
            $metadata[$fieldName] = is_array($source[$postKey] ?? null) ? [] : trim(strval($source[$postKey] ?? ''));
        }
    }
    return $metadata;
}

function ittApiBadgeHasConfiguredKey($value): bool
{
    $raw = trim(strval($value));
    if ($raw === '' || preg_match('/^(?:\*+|null|none|n\/a)$/i', $raw)) {
        return false;
    }
    return !preg_match('/^[^A-Za-z0-9]+$/', $raw);
}

$activeConnectorId = ittEnsureActiveConnectorId($connector);
$editingRow = $activeConnectorId > 0 ? $connector->getById($activeConnectorId) : [];
$notice = trim(strval($_GET['notice'] ?? ''));
$saved = strval($_GET['saved'] ?? '') === '1';
$driverOptions = ittVisibleDriverOptions($connector);
$groupedDriverOptions = ittGroupedDriverOptions($connector, $driverOptions);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_connector'])) {
    $editId = intval($_POST['id'] ?? 0);
    $driver = $connector->normalizeDriverValue($_POST['driver'] ?? 'openrouter');
    $allowedDrivers = [];
    foreach ($driverOptions as $driverOption) {
        $allowedDrivers[$connector->normalizeDriverValue($driverOption)] = true;
    }
    if (!isset($allowedDrivers[$driver])) {
        $driver = $connector->normalizeDriverValue($driverOptions[0] ?? 'openrouter');
    }

    $existing = $editId > 0 ? $connector->getById($editId) : null;
    $existingDriver = $connector->normalizeDriverValue($existing['driver'] ?? '');
    $existingMetadata = ($existing && $existingDriver === $driver)
        ? $connector->decodeMetadata($existing['metadata'] ?? '{}')
        : $connector->getDefaultMetadataForDriver($driver);
    $metadata = ittParseMetadataFromPost($_POST, $driver, $existingMetadata, $connector);

    $label = trim(strval($_POST['label'] ?? ''));
    if ($label === '') {
        $label = 'Global ' . $connector->getDisplayName($driver);
    }

    $apiBadgeId = null;
    if ($connector->driverUsesApiBadge($driver)) {
        $postedApiBadgeId = intval($_POST['api_badge_id'] ?? 0);
        $apiBadgeId = $postedApiBadgeId > 0 ? $postedApiBadgeId : $connector->getDefaultApiBadgeIdForDriver($driver);
        if ($apiBadgeId <= 0) {
            $apiBadgeId = null;
        }
    }

    $url = null;
    if ($connector->driverSupportsEditableUrl($driver)) {
        $url = trim(strval($_POST['url'] ?? ''));
        if ($url === '') {
            $url = $connector->getDefaultUrlForDriver($driver);
        }
    }

    $payload = [
        'driver' => $driver,
        'label' => $label,
        'metadata' => json_encode($metadata, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        'api_badge_id' => $apiBadgeId,
        'url' => $url,
    ];
    $savedId = $editId > 0 ? ($connector->update($editId, $payload) ? $editId : 0) : $connector->create($payload);
    if ($savedId > 0) {
        dialecticSetGeneralSetting('GLOBAL_ITT_CONNECTOR_ID', $savedId, dialecticGetSchemaDescription('GLOBAL_ITT_CONNECTOR_ID'));
        header('Location: ' . ittPageUrl(['saved' => '1']));
        exit;
    }
    $notice = 'ITT connector could not be saved.';
}

$activeConnectorId = ittEnsureActiveConnectorId($connector);
$editingRow = $activeConnectorId > 0 ? $connector->getById($activeConnectorId) : [];
if (!$editingRow) {
    $createdId = ittCreateDefaultConnector($connector);
    if ($createdId > 0) {
        dialecticSetGeneralSetting('GLOBAL_ITT_CONNECTOR_ID', $createdId, dialecticGetSchemaDescription('GLOBAL_ITT_CONNECTOR_ID'));
        $activeConnectorId = $createdId;
        $editingRow = $connector->getById($createdId);
    }
}

$currentDriver = $connector->normalizeDriverValue($editingRow['driver'] ?? 'openrouter') ?: 'openrouter';
$currentMetadata = array_replace(
    $connector->getDefaultMetadataForDriver($currentDriver),
    $connector->decodeMetadata($editingRow['metadata'] ?? '{}')
);
$apiRows = $GLOBALS['db']->fetchAll('SELECT id, label, api_key FROM public.core_api_badge ORDER BY LOWER(label) ASC');

if (!$isEmbed) {
    require_once(__DIR__ . DIRECTORY_SEPARATOR . 'profile_loader.php');
}

$TITLE = 'ITT Connector';
$BODY_CLASS = 'hub-page';
ob_start();
include(__DIR__ . DIRECTORY_SEPARATOR . 'tmpl' . DIRECTORY_SEPARATOR . 'head.html');
if (!$isEmbed) {
    include(__DIR__ . DIRECTORY_SEPARATOR . 'tmpl' . DIRECTORY_SEPARATOR . 'navbar.php');
}
?>

<link rel="stylesheet" href="<?php echo ih($webRoot); ?>/ui/css/main.css">
<style>
main { padding: <?php echo $isEmbed ? '20px 5px 5px' : '30px 5px 5px'; ?>; }
.page-shell { max-width: 1450px; margin: 0 auto; }
.page-header, .left-col, .right-col { background: #242424; border: 1px solid #414141; border-radius: 8px; }
.page-header { padding: 20px; text-align: center; margin-bottom: 30px; }
h1.api-title { margin: 0 0 8px; font-size: 2.2em; color: rgb(255,182,65); text-align: center; }
.page-subtitle { color: #bbb; font-size: 1.1em; margin: 0; }
.notice { margin-bottom: 14px; padding: 10px 12px; border-radius: 6px; border: 1px solid rgba(255,182,65,.28); background: #282828; color: #f6ddb2; }
.layout { display: grid; grid-template-columns: minmax(280px,340px) 1fr; gap: 18px; align-items: start; }
.left-col, .right-col { padding: 14px; }
.left-col { position: sticky; top: 90px; max-height: calc(100vh - 110px); overflow: hidden; }
.btn-row { display: flex; flex-wrap: wrap; gap: 8px; margin-bottom: 12px; }
.btn-save, .btn-primary { border-radius: 6px; padding: 8px 14px; cursor: pointer; border: 1px solid #52606f; text-decoration: none; display: inline-flex; align-items: center; gap: 6px; color: #fff; }
.btn-save { background: #287a4a; }
.btn-save:hover { background: #31915a; }
.btn-primary { background: #b95a00; }
.btn-primary:hover { background: #d16905; }
.list-wrap { display: flex; flex-direction: column; gap: 10px; overflow: auto; max-height: calc(100vh - 250px); padding-right: 4px; }
.group-title { color: rgb(255,182,65); font-size: 1.05em; margin: 8px 0 0; }
.conn-card { border: 1px solid #444; border-radius: 7px; background: #292929; padding: 12px; cursor: pointer; transition: background-color .18s ease,border-color .18s ease,transform .18s ease; }
.conn-card:hover { background: #323232; transform: translateY(-1px); border-color: #5a5a5a; }
.conn-card.active { outline: 2px solid rgb(255,182,65); background: #342d24; box-shadow: 0 3px 10px rgba(255,182,65,.2); }
.conn-head { display: flex; justify-content: space-between; gap: 8px; align-items: flex-start; }
.conn-name { color: #f4f4f4; font-size: 1.05em; }
.conn-badge { color: #b9c9dc; font-size: 11px; border: 1px solid #515151; border-radius: 999px; padding: 2px 8px; }
.conn-sub { color: #aebed0; font-size: 12px; margin-top: 4px; overflow-wrap: anywhere; }
.summary-note, .orm-note, .settings-empty-note { padding: 10px 12px; border: 1px dashed #48505a; border-radius: 6px; background: #101318; color: #aebed0; font-size: 12px; line-height: 1.5; }
.summary-note, .orm-note { margin-bottom: 12px; }
.editor-grid, .inline-two { display: grid; grid-template-columns: 1fr 1fr; gap: 14px; }
.field-block { margin-bottom: 12px; min-width: 0; }
.field-block label { display: block; color: #fff; margin-bottom: 6px; }
.field-block input[type=text], .field-block input[type=url], .field-block input[type=number], .field-block select, .field-block textarea { width: 100%; box-sizing: border-box; background: #191919; color: #f4f4f4; border: 1px solid #4a4a4a; border-radius: 5px; padding: 10px 12px; }
.field-block input:focus, .field-block select:focus, .field-block textarea:focus { outline: none; border-color: rgba(255,182,65,.65); box-shadow: 0 0 0 3px rgba(255,182,65,.1); }
.field-block textarea { min-height: 90px; resize: vertical; }
.field-help { color: #9eb1c9; font-size: 12px; margin-top: 5px; line-height: 1.45; }
.api-key-notice { margin-top: 6px; font-size: 12px; }
.api-key-notice.warn { color: #ffb862; }
.api-key-notice.ok { color: #6dd19c; }
.meta-group { display: none; border-top: 1px solid rgba(255,182,65,.16); margin-top: 8px; padding-top: 16px; }
.meta-group.active { display: block; }
.meta-group h3 { font-size: 1.2em; color: rgb(255,182,65); margin: 0 0 14px; }
#itt_test_modal { position: fixed; inset: 0; display: none; align-items: center; justify-content: center; background: rgba(0,0,0,.7); z-index: 9999; }
#itt_test_modal .inner { width: min(1100px,94vw); height: min(820px,92vh); background: #161616; border: 1px solid #4a4a4a; border-radius: 8px; position: relative; overflow: hidden; }
#itt_test_modal iframe { width: 100%; height: 100%; border: 0; background: #181818; }
#itt_test_close { position: absolute; top: 10px; right: 10px; z-index: 2; }
@media (max-width: 980px) {
    .layout { grid-template-columns: 1fr; }
    .left-col { position: relative; top: auto; max-height: none; }
    .list-wrap { max-height: 420px; }
    .editor-grid, .inline-two { grid-template-columns: 1fr; }
}
</style>

<main>
    <div class="page-shell">
        <div class="page-header">
            <h1 class="api-title">ITT Connector</h1>
            <p class="page-subtitle">Image-to-Text Setup Options.</p>
        </div>

        <?php if ($saved): ?>
            <div class="notice">ITT connector saved.</div>
        <?php elseif ($notice !== ''): ?>
            <div class="notice"><?php echo ih($notice); ?></div>
        <?php endif; ?>

        <div class="layout">
            <div class="left-col">
                <div class="summary-note">This page edits the single global ITT connector. It controls which vision-capable backend DIALECTIC Server uses for PipVision screenshots and image analysis.</div>
                <div class="list-wrap" id="itt_driver_list">
                    <?php foreach ($groupedDriverOptions as $groupLabel => $groupDrivers): ?>
                        <?php if (empty($groupDrivers)) { continue; } ?>
                        <div class="group-title"><?php echo ih($groupLabel); ?></div>
                        <?php foreach ($groupDrivers as $driverValue): ?>
                            <div class="conn-card<?php echo $currentDriver === $driverValue ? ' active' : ''; ?>" data-driver-card="<?php echo ih($driverValue); ?>">
                                <div class="conn-head">
                                    <div class="conn-name"><?php echo ih($connector->getDisplayName($driverValue)); ?></div>
                                    <div class="conn-badge"><?php echo ih($connector->getProviderKeyFromDriver($driverValue)); ?></div>
                                </div>
                                <div class="conn-sub"><?php echo ih($connector->getProviderTitle($driverValue)); ?></div>
                            </div>
                        <?php endforeach; ?>
                    <?php endforeach; ?>
                </div>
            </div>

            <div class="right-col">
                <?php if (!$editingRow): ?>
                    <div class="settings-empty-note">No ITT connector is configured yet.</div>
                <?php else: ?>
                    <form method="post" action="<?php echo ih(ittPageUrl()); ?>" id="itt_connector_form">
                        <input type="hidden" name="id" value="<?php echo ih($editingRow['id'] ?? ''); ?>">
                        <input type="hidden" name="save_connector" value="1">

                        <div class="btn-row">
                            <button type="submit" class="btn-save">Save</button>
                            <button type="button" class="btn-primary" id="btn_test_connector_inline">Test</button>
                        </div>
                        <div class="orm-note">Testing saves the current connector first so the modal uses the latest settings.</div>
                        <div id="custom_note" class="orm-note" style="<?php echo $currentDriver === 'custom' ? '' : 'display:none;'; ?>">Custom is for local or unsupported OpenAI-compatible vision services. Set the service URL directly and only choose an API badge if that backend requires authentication.</div>

                        <div class="editor-grid">
                            <div class="field-block">
                                <label for="label">Name</label>
                                <input type="text" id="label" name="label" value="<?php echo ih($editingRow['label'] ?? ''); ?>">
                                <div class="field-help">This label is retained for migration and future profile wiring, even though only one ITT connector is used globally.</div>
                            </div>
                            <div class="field-block">
                                <label for="driver">Service</label>
                                <select id="driver" name="driver">
                                    <?php foreach ($groupedDriverOptions as $groupLabel => $groupDrivers): ?>
                                        <?php if (empty($groupDrivers)) { continue; } ?>
                                        <optgroup label="<?php echo ih($groupLabel); ?>">
                                            <?php foreach ($groupDrivers as $driverValue): ?>
                                                <option value="<?php echo ih($driverValue); ?>" <?php echo $currentDriver === $driverValue ? 'selected' : ''; ?>><?php echo ih($connector->getDisplayName($driverValue)); ?></option>
                                            <?php endforeach; ?>
                                        </optgroup>
                                    <?php endforeach; ?>
                                </select>
                                <div class="field-help">Choose the image-to-text backend DIALECTIC Server should load globally.</div>
                            </div>

                            <div class="field-block" id="url_block" style="<?php echo $connector->driverSupportsEditableUrl($currentDriver) ? '' : 'display:none;'; ?>">
                                <label for="url">URL</label>
                                <input type="url" id="url" name="url" value="<?php echo ih($editingRow['url'] ?? $connector->getDefaultUrlForDriver($currentDriver)); ?>">
                                <div class="field-help">Endpoint used for ITT providers with configurable HTTP URLs.</div>
                            </div>

                            <div class="field-block" id="api_badge_block" style="<?php echo $connector->driverUsesApiBadge($currentDriver) ? '' : 'display:none;'; ?>">
                                <label for="api_badge_id">API Badge</label>
                                <?php
                                $selectedApi = $editingRow['api_badge_id'] ?? '';
                                $withKey = [];
                                $noKey = [];
                                foreach ($apiRows as $apiRow) {
                                    if (ittApiBadgeHasConfiguredKey($apiRow['api_key'] ?? '')) {
                                        $withKey[] = $apiRow;
                                    } else {
                                        $noKey[] = $apiRow;
                                    }
                                }
                                ?>
                                <select id="api_badge_id" name="api_badge_id">
                                    <option value="">-- None --</option>
                                    <?php foreach ($withKey as $apiRow): ?>
                                        <?php $apiRowId = intval($apiRow['id'] ?? 0); ?>
                                        <option value="<?php echo ih($apiRowId); ?>" data-empty="0" <?php echo strval($selectedApi) === strval($apiRowId) ? 'selected' : ''; ?>><?php echo ih('Configured: ' . strval($apiRow['label'] ?? ('Key #' . $apiRowId))); ?></option>
                                    <?php endforeach; ?>
                                    <?php if (!empty($noKey)): ?>
                                        <option value="" disabled>Missing Key</option>
                                        <?php foreach ($noKey as $apiRow): ?>
                                            <?php $apiRowId = intval($apiRow['id'] ?? 0); ?>
                                            <option value="<?php echo ih($apiRowId); ?>" data-empty="1" <?php echo strval($selectedApi) === strval($apiRowId) ? 'selected' : ''; ?>><?php echo ih(strval($apiRow['label'] ?? ('Key #' . $apiRowId)) . ' - No key'); ?></option>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </select>
                                <div id="api_key_notice" class="api-key-notice"></div>
                                <div class="field-help">Cloud ITT services require an API key from the API Keys page.</div>
                            </div>
                        </div>

                        <?php foreach ($driverOptions as $driverOption): ?>
                            <?php
                            $groupDriver = $connector->normalizeDriverValue($driverOption);
                            $groupSchema = $connector->getProviderFieldSchema($groupDriver);
                            $visibleFieldNames = [];
                            foreach ($groupSchema as $fieldName => $definition) {
                                if (ittShouldRenderField($fieldName, $definition)) {
                                    $visibleFieldNames[] = $fieldName;
                                }
                            }
                            ?>
                            <div class="meta-group<?php echo $groupDriver === $currentDriver ? ' active' : ''; ?>" data-driver-fields="<?php echo ih($groupDriver); ?>">
                                <h3><?php echo ih($connector->getProviderTitle($groupDriver)); ?> Settings</h3>
                                <?php if (empty($visibleFieldNames)): ?>
                                    <div class="settings-empty-note">This ITT provider does not have connector-level settings to configure here.</div>
                                <?php else: ?>
                                    <div class="inline-two">
                                        <?php foreach ($groupSchema as $fieldName => $definition): ?>
                                            <?php if (!ittShouldRenderField($fieldName, $definition)) { continue; } ?>
                                            <?php
                                            $fieldType = $definition['type'] ?? 'string';
                                            $fieldValue = $groupDriver === $currentDriver
                                                ? ($currentMetadata[$fieldName] ?? ($definition['default'] ?? ''))
                                                : ($definition['default'] ?? '');
                                            $fieldKey = 'meta__' . $groupDriver . '__' . $fieldName;
                                            ?>
                                            <div class="field-block">
                                                <label for="<?php echo ih($fieldKey); ?>"><?php echo ih(ittFieldLabel($fieldName)); ?></label>
                                                <?php if ($fieldType === 'boolean'): ?>
                                                    <select id="<?php echo ih($fieldKey); ?>" name="<?php echo ih($fieldKey); ?>">
                                                        <option value="true" <?php echo $fieldValue ? 'selected' : ''; ?>>Enabled</option>
                                                        <option value="false" <?php echo !$fieldValue ? 'selected' : ''; ?>>Disabled</option>
                                                    </select>
                                                <?php elseif ($fieldType === 'integer' || $fieldType === 'int'): ?>
                                                    <input type="number" step="1" id="<?php echo ih($fieldKey); ?>" name="<?php echo ih($fieldKey); ?>" value="<?php echo ih($fieldValue); ?>">
                                                <?php elseif ($fieldType === 'number'): ?>
                                                    <input type="number" step="0.01" id="<?php echo ih($fieldKey); ?>" name="<?php echo ih($fieldKey); ?>" value="<?php echo ih($fieldValue); ?>">
                                                <?php elseif ($fieldType === 'longstring'): ?>
                                                    <textarea id="<?php echo ih($fieldKey); ?>" name="<?php echo ih($fieldKey); ?>"><?php echo ih($fieldValue); ?></textarea>
                                                <?php elseif ($fieldType === 'select'): ?>
                                                    <select id="<?php echo ih($fieldKey); ?>" name="<?php echo ih($fieldKey); ?>">
                                                        <?php foreach (($definition['values'] ?? []) as $valueOption): ?>
                                                            <option value="<?php echo ih($valueOption); ?>" <?php echo strval($fieldValue) === strval($valueOption) ? 'selected' : ''; ?>><?php echo ih($valueOption); ?></option>
                                                        <?php endforeach; ?>
                                                    </select>
                                                <?php elseif ($fieldType === 'selectmultiple'): ?>
                                                    <select id="<?php echo ih($fieldKey); ?>" name="<?php echo ih($fieldKey); ?>[]" multiple>
                                                        <?php $selectedValues = is_array($fieldValue) ? $fieldValue : []; ?>
                                                        <?php foreach (($definition['values'] ?? []) as $valueOption): ?>
                                                            <option value="<?php echo ih($valueOption); ?>" <?php echo in_array($valueOption, $selectedValues, true) ? 'selected' : ''; ?>><?php echo ih($valueOption); ?></option>
                                                        <?php endforeach; ?>
                                                    </select>
                                                <?php else: ?>
                                                    <input type="text" id="<?php echo ih($fieldKey); ?>" name="<?php echo ih($fieldKey); ?>" value="<?php echo ih($fieldValue); ?>">
                                                <?php endif; ?>
                                                <?php if (!empty($definition['description'])): ?>
                                                    <div class="field-help"><?php echo ih($definition['description']); ?></div>
                                                <?php endif; ?>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    </form>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <div id="itt_test_modal">
        <div class="inner">
            <button type="button" class="btn-primary" id="itt_test_close">Close</button>
            <iframe id="itt_test_iframe" src="about:blank"></iframe>
        </div>
    </div>
</main>

<script>
(function(){
    const form = document.getElementById('itt_connector_form');
    const driverSelect = document.getElementById('driver');
    const driverCards = document.querySelectorAll('[data-driver-card]');
    const apiBadgeBlock = document.getElementById('api_badge_block');
    const apiBadgeSelect = document.getElementById('api_badge_id');
    const apiKeyNotice = document.getElementById('api_key_notice');
    const urlBlock = document.getElementById('url_block');
    const urlInput = document.getElementById('url');
    const modal = document.getElementById('itt_test_modal');
    const iframe = document.getElementById('itt_test_iframe');
    const closeBtn = document.getElementById('itt_test_close');
    const customNote = document.getElementById('custom_note');
    const testButton = document.getElementById('btn_test_connector_inline');

    const apiDrivers = <?php echo json_encode(array_values(array_filter($driverOptions, fn($driverOption) => $connector->driverUsesApiBadge($driverOption))), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE); ?>;
    const urlDrivers = <?php echo json_encode(array_values(array_filter($driverOptions, fn($driverOption) => $connector->driverSupportsEditableUrl($driverOption))), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE); ?>;
    const defaultApiBadgeIds = <?php
        $defaultApiBadgeIds = [];
        foreach ($driverOptions as $driverOption) {
            $normalizedDriver = $connector->normalizeDriverValue($driverOption);
            $defaultApiBadgeIds[$normalizedDriver] = $connector->getDefaultApiBadgeIdForDriver($normalizedDriver);
        }
        echo json_encode($defaultApiBadgeIds, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    ?>;
    const defaultUrls = <?php
        $defaultUrls = [];
        foreach ($driverOptions as $driverOption) {
            $normalizedDriver = $connector->normalizeDriverValue($driverOption);
            $defaultUrls[$normalizedDriver] = $connector->getDefaultUrlForDriver($normalizedDriver);
        }
        echo json_encode($defaultUrls, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    ?>;
    let previousDriver = driverSelect ? String(driverSelect.value || '') : '';

    function updateApiBadgeNotice() {
        if (!apiBadgeSelect || !apiKeyNotice || !apiBadgeBlock) return;
        if (apiBadgeBlock.style.display === 'none') {
            apiKeyNotice.textContent = '';
            apiKeyNotice.className = 'api-key-notice';
            return;
        }
        const selectedOption = apiBadgeSelect.options[apiBadgeSelect.selectedIndex];
        if (!selectedOption || String(apiBadgeSelect.value || '') === '') {
            apiKeyNotice.className = 'api-key-notice warn';
            apiKeyNotice.textContent = 'No API key selected. Some ITT services require one.';
        } else if (selectedOption.getAttribute('data-empty') === '1') {
            apiKeyNotice.className = 'api-key-notice warn';
            apiKeyNotice.textContent = 'Selected API badge does not have a configured key yet.';
        } else {
            apiKeyNotice.className = 'api-key-notice ok';
            apiKeyNotice.textContent = 'Selected API badge is configured.';
        }
    }

    function syncDriverFields() {
        if (!driverSelect) return;
        const selected = String(driverSelect.value || '');
        document.querySelectorAll('[data-driver-fields]').forEach(function(group){
            group.classList.toggle('active', group.getAttribute('data-driver-fields') === selected);
        });
        driverCards.forEach(function(card){
            card.classList.toggle('active', card.getAttribute('data-driver-card') === selected);
        });

        if (apiBadgeBlock) {
            const usesApiBadge = apiDrivers.indexOf(selected) >= 0;
            apiBadgeBlock.style.display = usesApiBadge ? '' : 'none';
            if (apiBadgeSelect) {
                const currentValue = String(apiBadgeSelect.value || '').trim();
                const previousDefault = String(defaultApiBadgeIds[previousDriver] || '').trim();
                const nextDefault = String(defaultApiBadgeIds[selected] || '').trim();
                if (usesApiBadge && (currentValue === '' || currentValue === previousDefault)) {
                    apiBadgeSelect.value = nextDefault;
                } else if (!usesApiBadge && currentValue === previousDefault) {
                    apiBadgeSelect.value = '';
                }
            }
        }

        if (urlBlock) {
            const supportsUrl = urlDrivers.indexOf(selected) >= 0;
            urlBlock.style.display = supportsUrl ? '' : 'none';
            if (urlInput) {
                const currentValue = String(urlInput.value || '').trim();
                const previousDefault = String(defaultUrls[previousDriver] || '').trim();
                const nextDefault = String(defaultUrls[selected] || '').trim();
                if (currentValue === '' || currentValue === previousDefault) {
                    urlInput.value = nextDefault;
                }
            }
        }
        if (customNote) customNote.style.display = selected === 'custom' ? '' : 'none';
        previousDriver = selected;
        updateApiBadgeNotice();
    }

    async function saveBeforeTest() {
        if (!form) return false;
        try {
            const response = await fetch(form.getAttribute('action') || 'itt_connectors.php', { method: 'POST', body: new FormData(form) });
            return response.ok;
        } catch (_error) {
            return false;
        }
    }

    function closeModal() {
        if (!modal || !iframe) return;
        modal.style.display = 'none';
        iframe.src = 'about:blank';
    }

    if (driverSelect) {
        driverSelect.addEventListener('change', syncDriverFields);
        syncDriverFields();
    }
    driverCards.forEach(function(card){
        card.addEventListener('click', function(){
            if (!driverSelect) return;
            driverSelect.value = card.getAttribute('data-driver-card') || '';
            syncDriverFields();
        });
    });
    if (apiBadgeSelect) apiBadgeSelect.addEventListener('change', updateApiBadgeNotice);
    if (testButton) {
        testButton.addEventListener('click', async function(){
            if (!await saveBeforeTest() || !modal || !iframe) return;
            iframe.src = <?php echo json_encode($webRoot . '/ui/tests/itt-test.php?embed=1&cb='); ?> + Date.now();
            modal.style.display = 'flex';
        });
    }
    if (closeBtn) closeBtn.addEventListener('click', closeModal);
    if (modal) {
        modal.addEventListener('click', function(event){ if (event.target === modal) closeModal(); });
    }
})();
</script>

<?php
include(__DIR__ . DIRECTORY_SEPARATOR . 'tmpl' . DIRECTORY_SEPARATOR . 'footer.html');
$buffer = ob_get_clean();
$buffer = preg_replace('/(<title>)(.*?)(<\/title>)/i', '$1' . $TITLE . '$3', $buffer);
echo $buffer;
