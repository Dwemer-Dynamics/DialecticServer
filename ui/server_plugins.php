<?php

$enginePath = dirname(__DIR__) . DIRECTORY_SEPARATOR;
require_once $enginePath . 'lib' . DIRECTORY_SEPARATOR . 'runtime_bootstrap.php';
dialecticRuntimeBootstrap($enginePath, [
    'load_general_settings' => true,
    'load_player_name' => true,
    'load_narrator' => true,
]);

$scriptPath = strval($_SERVER['SCRIPT_NAME'] ?? '');
$uiPos = strpos($scriptPath, '/ui/');
$webRoot = $uiPos !== false ? substr($scriptPath, 0, $uiPos) : '';
$webRoot = rtrim($webRoot === '/' ? '' : $webRoot, '/');
$isEmbedded = isset($_GET['embed']) && strval($_GET['embed']) === '1';

$TITLE = 'DIALECTIC - Server Plugins';
$BODY_CLASS = 'dialectic-server-plugins';
ob_start();
include __DIR__ . DIRECTORY_SEPARATOR . 'tmpl' . DIRECTORY_SEPARATOR . 'head.html';
if (!$isEmbedded) {
    include __DIR__ . DIRECTORY_SEPARATOR . 'tmpl' . DIRECTORY_SEPARATOR . 'navbar.php';
}
?>

<link rel="stylesheet" href="<?php echo htmlspecialchars($webRoot, ENT_QUOTES, 'UTF-8'); ?>/ui/css/main.css">
<style>
main { padding: <?php echo $isEmbedded ? '24px' : '80px 12px 32px'; ?>; }
.plugin-shell { max-width: 1080px; margin: 0 auto; }
.plugin-heading { margin-bottom: 18px; border-bottom: 1px solid #3a3a3a; padding-bottom: 14px; }
.plugin-heading h1 { margin: 0 0 6px; color: rgb(255, 182, 65) !important; font-family: 'Gothic821', sans-serif; font-weight: normal; font-size: 1.8rem; }
.plugin-heading p { margin: 0; color: #bdbdbd; }
.plugin-toolbar { display: flex; align-items: center; justify-content: space-between; gap: 12px; margin-bottom: 12px; }
.plugin-toolbar h2 { margin: 0; font-family: 'Gothic821', sans-serif; font-weight: normal; font-size: 1.15rem; }
.plugin-refresh { border: 1px solid rgb(255, 182, 65) !important; background: rgb(255, 182, 65) !important; color: #171717 !important; padding: 8px 12px; border-radius: 5px; cursor: pointer; }
.plugin-refresh:hover { background: rgb(224, 151, 40) !important; border-color: rgb(224, 151, 40) !important; }
.plugin-status { min-height: 54px; border: 1px solid #3a3a3a; background: #242424; border-radius: 6px; padding: 14px; color: #ddd; }
.plugin-list { display: grid; gap: 8px; }
.plugin-row { display: grid; grid-template-columns: minmax(180px, 1fr) auto auto; gap: 18px; align-items: center; border: 1px solid #3a3a3a; background: #242424; border-radius: 6px; padding: 12px 14px; }
.plugin-name { color: #fff; font-weight: 600; }
.plugin-version { color: rgb(255, 182, 65); white-space: nowrap; }
.plugin-date { color: #aaa; white-space: nowrap; font-size: 0.9rem; }
.plugin-error { color: #ff8d8d; }
@media (max-width: 700px) { .plugin-row { grid-template-columns: 1fr; gap: 5px; } .plugin-date { white-space: normal; } }
</style>

<main>
    <div class="plugin-shell">
        <header class="plugin-heading">
            <h1>Server Plugins</h1>
            <p>Dialectic installs bundled server-plugin packages when the game loads. Existing settings and package data are preserved during updates.</p>
        </header>
        <div class="plugin-toolbar">
            <h2>Installed Packages</h2>
            <button id="refresh-packages" class="plugin-refresh" type="button">Refresh Status</button>
        </div>
        <div id="plugin-status" class="plugin-status" role="status" aria-live="polite">Loading installed packages...</div>
    </div>
</main>

<script>
(function () {
    const endpoint = <?php echo json_encode($webRoot . '/ui/api/plugin_packages.php?action=packages', JSON_UNESCAPED_SLASHES); ?>;
    const status = document.getElementById('plugin-status');
    const refresh = document.getElementById('refresh-packages');

    function text(value) {
        return String(value == null ? '' : value);
    }

    function render(packages) {
        if (!Array.isArray(packages) || packages.length === 0) {
            status.className = 'plugin-status';
            status.textContent = 'No bundled server plugins are installed.';
            return;
        }

        const list = document.createElement('div');
        list.className = 'plugin-list';
        packages.forEach(function (plugin) {
            const row = document.createElement('div');
            row.className = 'plugin-row';
            const name = document.createElement('div');
            name.className = 'plugin-name';
            name.textContent = text(plugin.name);
            const version = document.createElement('div');
            version.className = 'plugin-version';
            version.textContent = 'v' + text(plugin.version);
            const date = document.createElement('div');
            date.className = 'plugin-date';
            date.textContent = plugin.installed_at ? new Date(plugin.installed_at).toLocaleString() : 'Installed';
            row.append(name, version, date);
            list.appendChild(row);
        });
        status.className = 'plugin-status';
        status.replaceChildren(list);
    }

    async function loadPackages() {
        refresh.disabled = true;
        status.className = 'plugin-status';
        status.textContent = 'Loading installed packages...';
        try {
            const response = await fetch(endpoint, { cache: 'no-store' });
            const payload = await response.json();
            if (!response.ok || !payload.ok) {
                throw new Error(payload.error || 'Package status request failed.');
            }
            render(payload.packages);
        } catch (error) {
            status.className = 'plugin-status plugin-error';
            status.textContent = error instanceof Error ? error.message : 'Package status request failed.';
        } finally {
            refresh.disabled = false;
        }
    }

    refresh.addEventListener('click', loadPackages);
    loadPackages();
})();
</script>

<?php
$buffer = ob_get_contents();
ob_end_clean();
echo $buffer;
?>
