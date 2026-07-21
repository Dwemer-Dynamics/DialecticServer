<?php
// Define base paths if not already defined
if (!defined('BASE_PATH')) {
 define('BASE_PATH', dirname(dirname(__DIR__)));
}
if (!defined('UI_PATH')) {
 define('UI_PATH', dirname(__DIR__));
}

// Get the relative web path from document root to our application if not already defined
if (!isset($webRoot)) {
 $scriptPath = $_SERVER['SCRIPT_NAME'];
 $webRoot = dirname(dirname(dirname($scriptPath))); // Go up three levels from the script location
 if ($webRoot == '/') $webRoot = '';
 $webRoot = rtrim($webRoot, '/');
}

// Ensure runtime globals are available for UI chrome even when included directly.
if (defined('BASE_PATH') && (!isset($GLOBALS["DBDRIVER"]) || !isset($GLOBALS["db"]))) {
 $enginePath = BASE_PATH . DIRECTORY_SEPARATOR;
 @require_once($enginePath . "lib" . DIRECTORY_SEPARATOR . "runtime_bootstrap.php");
 if (function_exists('dialecticRuntimeBootstrapIfNeeded')) {
 @dialecticRuntimeBootstrapIfNeeded($enginePath, [
 'load_general_settings' => true,
 'load_stt_connector' => false,
 'load_tts_connector' => false,
 'load_player_name' => false,
 'load_narrator' => false,
 ]);
 }
}

// Function to validate plugin version format - just check it's not too long
function isValidPluginVersion($version) {
 // Simple validation: version should be 10 characters or less
 return strlen($version) <= 10;
}

$pluginVersionDisplay = 'N/A'; // Default value

// Attempt to use a global $db object if available and valid
if (isset($GLOBALS['db']) && is_object($GLOBALS['db'])) {
 try {
 if (method_exists($GLOBALS['db'], 'fetchOne')) {
 $pluginVersionRow = $GLOBALS['db']->fetchOne("SELECT value FROM conf_opts WHERE id='plugin_dll_version'");
 if ($pluginVersionRow && isset($pluginVersionRow['value']) && trim($pluginVersionRow['value']) !== '') {
 $version = trim($pluginVersionRow['value']);
 // Validate that the version follows the expected format
 if (isValidPluginVersion($version)) {
 $pluginVersionDisplay = htmlspecialchars($version, ENT_QUOTES, 'UTF-8');
 }
 }
 } elseif (method_exists($GLOBALS['db'], 'fetchAll')) {
 // Fallback to fetchAll on global $db if fetchOne not found
 $rows = $GLOBALS['db']->fetchAll("SELECT value FROM conf_opts WHERE id='plugin_dll_version' LIMIT 1");
 if ($rows && isset($rows[0]) && isset($rows[0]['value']) && trim($rows[0]['value']) !== '') {
 $version = trim($rows[0]['value']);
 // Validate that the version follows the expected format
 if (isValidPluginVersion($version)) {
 $pluginVersionDisplay = htmlspecialchars($version, ENT_QUOTES, 'UTF-8');
 }
 }
 }
 } catch (Exception $e) {
 // Just keep the default value and log the error
 error_log("Error fetching plugin version using global \$db: " . $e->getMessage());
 }
} else {
 // Only attempt to create a new DB connection if we don't already have a global one
 // and only if we have all the required components
 try {
 if (isset($GLOBALS["DBDRIVER"]) && !empty($GLOBALS["DBDRIVER"])) {
 $dbDriverFile = BASE_PATH . DIRECTORY_SEPARATOR . "lib" . DIRECTORY_SEPARATOR . $GLOBALS["DBDRIVER"] . ".class.php";

 // Only try to load the SQL class if it doesn't already exist
 if (!class_exists('sql') && file_exists($dbDriverFile)) {
 @require_once($dbDriverFile);
 }

 // Only create a new connection if the class was loaded successfully
 if (class_exists('sql')) {
 // Suppress warnings/errors in this section as it's purely for UI decoration
 @$localDb = new sql();

 if ($localDb && is_object($localDb)) {
 if (method_exists($localDb, 'fetchOne')) {
 $pluginVersionRow = @$localDb->fetchOne("SELECT value FROM conf_opts WHERE id='plugin_dll_version'");
 if ($pluginVersionRow && isset($pluginVersionRow['value']) && trim($pluginVersionRow['value']) !== '') {
 $version = trim($pluginVersionRow['value']);
 // Validate that the version follows the expected format
 if (isValidPluginVersion($version)) {
 $pluginVersionDisplay = htmlspecialchars($version, ENT_QUOTES, 'UTF-8');
 }
 }
 } elseif (method_exists($localDb, 'fetchAll')) {
 $rows = @$localDb->fetchAll("SELECT value FROM conf_opts WHERE id='plugin_dll_version' LIMIT 1");
 if ($rows && isset($rows[0]) && isset($rows[0]['value']) && trim($rows[0]['value']) !== '') {
 $version = trim($rows[0]['value']);
 // Validate that the version follows the expected format
 if (isValidPluginVersion($version)) {
 $pluginVersionDisplay = htmlspecialchars($version, ENT_QUOTES, 'UTF-8');
 }
 }
 }
 }
 }
 }
 } catch (Exception $e) {
 // Just continue with the default value
 error_log("Error in navbar fallback DB connection: " . $e->getMessage());
 }
}

// Add link to navbar CSS
echo '<link rel="stylesheet" href="' . $webRoot . '/ui/css/navbar.css">';
$themeCssPath = __DIR__ . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . 'css' . DIRECTORY_SEPARATOR . 'dialectic-theme.css';
$themeCssVersion = file_exists($themeCssPath) ? strval(filemtime($themeCssPath)) : strval(time());
echo '<link rel="stylesheet" href="' . $webRoot . '/ui/css/dialectic-theme.css?v=' . rawurlencode($themeCssVersion) . '">';

// Add custom CSS for centered navbar layout
echo '<style>
.dialectic-navbar .container-fluid {
 display: flex !important;
 justify-content: space-between;
 align-items: center;
 width: 100%;
}

/* Fixed navbar height */
.dialectic-navbar {
 height: 64px;
}
.dialectic-navbar .container-fluid > * {
 align-items: center;
}
.dialectic-navbar .navbar-brand,
.dialectic-navbar .navbar-center button.navbar-brand {
 padding: 0;
 line-height: 1;
}

.server-version-info {
 display: flex;
 align-items: center;
 color: #6c757d;
 font-size: 0.75em;
 font-family: Arial, sans-serif;
 width: 120px;
 flex-shrink: 0;
}

.navbar-content-wrapper {
 display: flex;
 justify-content: center;
 align-items: center;
 flex: 1;
 max-width: 1000px;
 margin: 0 auto;
}

.social-links {
 display: flex;
 align-items: center;
 gap: 10px;
 width: 120px;
 flex-shrink: 0;
 justify-content: flex-end;
}

.social-link img {
 width: 24px;
 height: 24px;
 transition: transform 0.3s ease;
}

.social-link:hover img {
 transform: scale(1.1);
}

.navbar-center {
 display: flex;
 justify-content: center;
 flex: 0 0 auto;
 margin: 0 20px;
}

.navbar-center .navbar-brand {
 margin: 0;
 padding: 0;
}

/* Dropdown positioning */
.nav-item.dropdown .dropdown-menu {
 min-width: 280px;
}

@media (max-width: 992px) {
 .container-fluid {
 flex-direction: column;
 gap: 10px;
 align-items: center;
 }

 .server-version-info,
 .social-links {
 order: 2;
 width: auto;
 }

 .navbar-content-wrapper {
 flex-direction: column;
 gap: 10px;
 order: 1;
 }

 .navbar-center {
 order: -1;
 margin: 0;
 }

 /* Center dropdowns on mobile */
 .dropdown-menu {
 left: 50%;
 transform: translateX(-50%);
 }
}
</style>';

// Determine whether to show the secondary status navbar
$currentPageName = basename($_SERVER['PHP_SELF'] ?? '');
$SHOW_STATUS_NAV = false;

$topNavSection = $currentPageName === 'home.php' ? 'home' : '';
$roleplayPages = [
 'events-memories.php', 'ai-response.php', 'adventurelog.php', 'diarylog.php',
 'image_gallery.php', 'diary_book.php', 'eventlog.php',
];
$configurationPages = [
 'config_hub.php', 'npc_master.php', 'player_management.php', 'narrator_management.php',
 'core_profiles.php', 'llm_connectors.php', 'tts_connectors.php', 'stt_connectors.php',
 'api_badge.php', 'global_settings.php', 'description_upload.php', 'npc_upload.php',
 'worldknowledge_upload.php', 'function_editor.php', 'xtts_clone.php', 'prompts_manager.php',
 'dialectic_setup.php', 'quickstart.php',
];
$controlPanelPages = [
 'control_panel.php', 'request_logs.php', 'worldknowledge_audit.php', 'audit.php',
 'relationship_logs.php', 'playthrough_manager.php', 'cache_browser.php',
 'response_queue.php', 'index.php', 'tts-test.php', 'stt-test.php',
];

if (in_array($currentPageName, $roleplayPages, true)) {
 $topNavSection = 'roleplay';
} elseif (in_array($currentPageName, $configurationPages, true)) {
 $topNavSection = 'configuration';
} elseif (in_array($currentPageName, $controlPanelPages, true)) {
 $topNavSection = 'control';
}

// Server version and dev-build detection
// Read version from .version_number.txt
$versionFile = dirname(__DIR__, 2) . '/.version_number.txt';
$serverVersionRaw = '0.5.7'; // fallback
if (file_exists($versionFile)) {
 $versionContent = trim(file_get_contents($versionFile));
 if ($versionContent !== '') {
 $serverVersionRaw = $versionContent;
 }
}
$isDevBuild = (stripos($serverVersionRaw, 'dev') !== false);
$serverVersionDisplay = trim(str_ireplace('dev', '', $serverVersionRaw));
$serverLogoFile = $isDevBuild ? 'serverlogodev.png' : 'serverlogo.png';

?>
<div class="dialectic-navbar-wrapper">
 <nav class="navbar navbar-expand-lg dialectic-navbar">
  <div class="container-fluid mx-1">
   <div class="navbar-content-wrapper">
    <div class="navbar-center dropdown">
     <button class="navbar-brand Title btn btn-link p-0 dropdown-toggle" type="button" data-bs-toggle="dropdown" data-bs-auto-close="true" data-bs-display="static" aria-expanded="false" title="Open menu" style="text-decoration: none;">
      <img src="<?php echo $webRoot; ?>/ui/images/DwemerDynamics.png" alt="DIALECTIC Server" style="vertical-align:bottom;"/>
      <img src="<?php echo $webRoot; ?>/ui/images/<?php echo htmlspecialchars($serverLogoFile, ENT_QUOTES, 'UTF-8'); ?>" alt="DIALECTIC Server" style="vertical-align:bottom;"/>
     </button>
     <ul class="dropdown-menu brand-menu">
      <li><a class="dropdown-item<?php echo $topNavSection === 'home' ? ' active' : ''; ?>" href="<?php echo $webRoot; ?>/ui/home.php"<?php echo $topNavSection === 'home' ? ' aria-current="page"' : ''; ?>>Home</a></li>
      <li><a class="dropdown-item<?php echo $topNavSection === 'roleplay' ? ' active' : ''; ?>" href="<?php echo $webRoot; ?>/ui/events-memories.php"<?php echo $topNavSection === 'roleplay' ? ' aria-current="page"' : ''; ?>>Roleplay</a></li>
      <li><a class="dropdown-item<?php echo $topNavSection === 'configuration' ? ' active' : ''; ?>" href="<?php echo $webRoot; ?>/ui/core/config_hub.php"<?php echo $topNavSection === 'configuration' ? ' aria-current="page"' : ''; ?>>Configuration</a></li>
      <li><a class="dropdown-item<?php echo $topNavSection === 'control' ? ' active' : ''; ?>" href="<?php echo $webRoot; ?>/ui/control_panel.php"<?php echo $topNavSection === 'control' ? ' aria-current="page"' : ''; ?>>Control Panel</a></li>
      <li><a class="dropdown-item" href="/Dwemer-Dashboard/index.php">DwemerDistro Home</a></li>
     </ul>
    </div>
   </div>
  </div>
 </nav>
</div>
<div id="toast-notification" class="toast-notification"><span class="message"></span></div>
<script>
function showToast(message, duration = 3000) {
 const toast = document.getElementById('toast-notification');
 if (!toast) return;
 toast.querySelector('.message').textContent = message;
 toast.classList.add('show');
 setTimeout(() => { toast.classList.remove('show'); }, duration);
}
</script>
