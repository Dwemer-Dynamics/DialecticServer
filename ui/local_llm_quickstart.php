<?php
/**
 * Quickstart partial: guided local-model (OpenAI-compatible) setup.
 *
 * Included from ui/quickstart.php inside the main form. Every control here is
 * id-only (no name attributes) so the existing Quickstart "Save and Continue"
 * FormData submission is untouched. Applying a local model is a separate
 * request to api/local_llm_setup.php.
 *
 * Backend helpers live in lib/core/local_llm_setup.php.
 */

$localLlmRootPath = __DIR__ . DIRECTORY_SEPARATOR . ".." . DIRECTORY_SEPARATOR;
$localLlmHelperPath = $localLlmRootPath . "lib" . DIRECTORY_SEPARATOR . "core" . DIRECTORY_SEPARATOR . "local_llm_setup.php";
if (is_file($localLlmHelperPath)) {
    try { require_once($localLlmHelperPath); } catch (Throwable $_e) {}
}

if (empty($_SESSION['local_llm_csrf_token'])) {
    $_SESSION['local_llm_csrf_token'] = bin2hex(random_bytes(32));
}
$localLlmCsrfToken = strval($_SESSION['local_llm_csrf_token']);

$localLlmHelpersReady = function_exists('dialecticLocalLlmCurrentSetup') && function_exists('dialecticLocalLlmServerCatalog');

$localLlmSetup = [];
$localLlmCatalog = [];
if ($localLlmHelpersReady) {
    try { $localLlmSetup = dialecticLocalLlmCurrentSetup(); } catch (Throwable $_e) { $localLlmSetup = []; }
    try { $localLlmCatalog = dialecticLocalLlmServerCatalog(); } catch (Throwable $_e) { $localLlmCatalog = []; }
    if (!is_array($localLlmSetup)) { $localLlmSetup = []; }
    if (!is_array($localLlmCatalog)) { $localLlmCatalog = []; }
}

if (!$localLlmHelpersReady || empty($localLlmCatalog)) {
    echo '<section class="qs-section qs-local-llm" id="qs_local_llm_section">
            <h2 class="qs-section-title">Local Model</h2>
            <p class="form-text">Local model setup is unavailable on this install because the setup helper is missing. Everything else on this page still works.</p>
          </section>';
    return;
}

$localLlmConnectorId = intval($localLlmSetup['connector_id'] ?? 0);
$localLlmServerType = trim(strval($localLlmSetup['server_type'] ?? ''));
if ($localLlmServerType === '' || !array_key_exists($localLlmServerType, $localLlmCatalog)) {
    $localLlmServerType = array_key_exists('lm_studio', $localLlmCatalog)
        ? 'lm_studio'
        : strval(array_keys($localLlmCatalog)[0]);
}
$localLlmUrl = trim(strval($localLlmSetup['url'] ?? ''));
$localLlmSavedUrl = $localLlmUrl;
$localLlmModel = trim(strval($localLlmSetup['model'] ?? ''));
$localLlmTimeout = intval($localLlmSetup['timeout'] ?? 30);
if ($localLlmTimeout < 5) { $localLlmTimeout = 5; }
if ($localLlmTimeout > 120) { $localLlmTimeout = 120; }
$localLlmDisableStreaming = !empty($localLlmSetup['disable_streaming']);
$localLlmScope = (strval($localLlmSetup['scope'] ?? '') === 'all') ? 'all' : 'conversations';
$localLlmHasApiKey = !empty($localLlmSetup['has_api_key']);
$localLlmHostIp = trim(strval($localLlmSetup['host_ip'] ?? ''));
$localLlmWslIp = trim(strval($localLlmSetup['wsl_ip'] ?? ''));

$localLlmEndpointPath = '/v1/chat/completions';

// Hosts we are willing to auto-generate preset endpoints for, most preferred first.
$localLlmPresetHosts = [];
if ($localLlmHostIp !== '') { $localLlmPresetHosts[] = $localLlmHostIp; }
$localLlmPresetHosts[] = '127.0.0.1';
if ($localLlmWslIp !== '') { $localLlmPresetHosts[] = $localLlmWslIp; }
$localLlmPresetHosts = array_values(array_unique($localLlmPresetHosts));

// Every URL the presets could ever have produced. Anything outside this set is
// treated as hand-written and is never overwritten by a server change.
$localLlmPresetUrls = [];
foreach ($localLlmCatalog as $localLlmServerId => $localLlmServerMeta) {
    $localLlmPort = isset($localLlmServerMeta['port']) ? intval($localLlmServerMeta['port']) : 0;
    if ($localLlmPort <= 0) { continue; }
    foreach ($localLlmPresetHosts as $localLlmPresetHost) {
        $localLlmPresetUrls[] = 'http://' . $localLlmPresetHost . ':' . strval($localLlmPort) . $localLlmEndpointPath;
    }
}

// Offer a sensible endpoint when nothing has been saved yet.
if ($localLlmUrl === '') {
    $localLlmDefaultPort = intval($localLlmCatalog[$localLlmServerType]['port'] ?? 0);
    if ($localLlmDefaultPort <= 0) { $localLlmDefaultPort = 1234; }
    $localLlmDefaultHost = isset($localLlmPresetHosts[0]) ? $localLlmPresetHosts[0] : '127.0.0.1';
    $localLlmUrl = 'http://' . $localLlmDefaultHost . ':' . strval($localLlmDefaultPort) . $localLlmEndpointPath;
}

$localLlmPlayer2Active = !empty($player2ForceAllLlm);
$localLlmConfigured = ($localLlmConnectorId > 0);

$localLlmJsConfig = json_encode([
    'csrfToken' => $localLlmCsrfToken,
    'endpoint' => 'api/local_llm_setup.php',
    'endpointPath' => $localLlmEndpointPath,
    'catalog' => $localLlmCatalog,
    'presetHosts' => $localLlmPresetHosts,
    'presetUrls' => $localLlmPresetUrls,
    'savedUrl' => $localLlmSavedUrl,
    'hasApiKey' => $localLlmHasApiKey,
    'player2Active' => $localLlmPlayer2Active,
], JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
if ($localLlmJsConfig === false) { $localLlmJsConfig = '{}'; }

$localLlmServerOptions = '';
foreach ($localLlmCatalog as $localLlmServerId => $localLlmServerMeta) {
    $localLlmOptionLabel = trim(strval($localLlmServerMeta['label'] ?? ''));
    if ($localLlmOptionLabel === '') { $localLlmOptionLabel = strval($localLlmServerId); }
    $localLlmServerOptions .= '<option value="' . htmlspecialchars(strval($localLlmServerId), ENT_QUOTES) . '"'
        . (strval($localLlmServerId) === $localLlmServerType ? ' selected' : '') . '>'
        . htmlspecialchars($localLlmOptionLabel, ENT_QUOTES) . '</option>';
}

$localLlmHostHintParts = [];
if ($localLlmHostIp !== '') { $localLlmHostHintParts[] = 'Windows host: ' . $localLlmHostIp; }
if ($localLlmWslIp !== '') { $localLlmHostHintParts[] = 'This server: ' . $localLlmWslIp; }
$localLlmHostHint = '';
foreach ($localLlmHostHintParts as $localLlmHostHintPart) {
    if ($localLlmHostHint !== '') { $localLlmHostHint .= ' &middot; '; }
    $localLlmHostHint .= htmlspecialchars($localLlmHostHintPart, ENT_QUOTES);
}

$localLlmKeyPlaceholder = $localLlmHasApiKey
    ? 'Leave blank to keep the saved key'
    : 'Leave blank if your server needs no key';
$localLlmKeyHelp = $localLlmHasApiKey
    ? 'A key is saved. Blank keeps it, as long as the endpoint stays the same.'
    : 'No key is saved. Most local servers do not need one.';

echo '<section class="qs-section qs-local-llm" id="qs_local_llm_section">
        <details class="qs-local-llm-panel" id="qs_local_llm_details"' . ($localLlmConfigured ? ' open' : '') . '>
            <summary class="qs-local-llm-summary">
                <span class="qs-local-llm-heading">Local Model</span>
                <span class="qs-local-llm-chip' . ($localLlmConfigured ? ' is-on' : '') . '" id="qs_local_llm_chip">' . ($localLlmConfigured ? 'Configured' : 'Not set up') . '</span>
            </summary>
            <div class="qs-local-llm-body">
                <p class="form-text qs-local-llm-lead">Point Dialectic at a model running on your own machine through an OpenAI-compatible server.</p>';

if ($localLlmPlayer2Active) {
    echo '      <div class="qs-status err qs-local-llm-blocker" id="qs_local_llm_player2_warning">
                    <strong>Player2 is handling every LLM call.</strong> Turn off &ldquo;Use Player 2 for LLMs&rdquo; above, press <em>Save and Continue</em>, then come back here. Dialectic will not change that setting for you.
                </div>';
}

echo '          <div class="qs-local-llm-grid">
                    <div class="form-group qs-field">
                        <label for="qs_local_llm_server_type">Server</label>
                        <select class="form-control" id="qs_local_llm_server_type" aria-describedby="qs_local_llm_server_help">' . $localLlmServerOptions . '</select>
                        <small class="form-text" id="qs_local_llm_server_help">Fills in the usual port. Custom endpoints are kept.</small>
                    </div>
                    <div class="form-group qs-field">
                        <label for="qs_local_llm_model">Model name</label>
                        <input type="text" class="form-control" id="qs_local_llm_model" value="' . htmlspecialchars($localLlmModel, ENT_QUOTES) . '" autocomplete="off" spellcheck="false" placeholder="e.g. qwen3-8b-instruct" aria-describedby="qs_local_llm_model_help">
                        <small class="form-text" id="qs_local_llm_model_help">Must match the model your server has loaded.</small>
                    </div>
                </div>

                <div class="form-group qs-field">
                    <label for="qs_local_llm_url">Endpoint URL</label>
                    <input type="url" class="form-control" id="qs_local_llm_url" value="' . htmlspecialchars($localLlmUrl, ENT_QUOTES) . '" autocomplete="off" spellcheck="false" inputmode="url" placeholder="http://127.0.0.1:1234' . htmlspecialchars($localLlmEndpointPath, ENT_QUOTES) . '" aria-describedby="qs_local_llm_url_help qs_local_llm_url_hint">
                    <small class="form-text" id="qs_local_llm_url_help">Full chat-completions endpoint, ending in <code>' . htmlspecialchars($localLlmEndpointPath, ENT_QUOTES) . '</code>. Here <code>localhost</code> means the machine running Dialectic (the PHP/WSL host), not Windows.'
                    . ($localLlmHostHint !== '' ? ' <span class="qs-local-llm-ips">' . $localLlmHostHint . '</span>' : '') . '</small>
                    <p class="qs-local-llm-hint" id="qs_local_llm_url_hint" role="status" aria-live="polite"></p>
                </div>

                <details class="qs-local-llm-help">
                    <summary>Which address should I use?</summary>
                    <div class="qs-local-llm-help-body">
                        <ul>
                            <li><strong>Server on this same host:</strong> <code>http://127.0.0.1:&lt;port&gt;' . htmlspecialchars($localLlmEndpointPath, ENT_QUOTES) . '</code> works as-is.</li>
                            <li><strong>Server in Windows</strong> (LM Studio, Ollama or koboldcpp on the desktop) while Dialectic runs under WSL: use your Windows private IP'
                            . ($localLlmHostIp !== '' ? ', probably <code>' . htmlspecialchars($localLlmHostIp, ENT_QUOTES) . '</code>' : '') . '.</li>
                            <li>That Windows app also has to listen on the network (bind to <code>0.0.0.0</code> instead of localhost only) and Windows Firewall has to allow the port. Set both up yourself &mdash; Dialectic changes no firewall or binding settings.</li>
                        </ul>
                    </div>
                </details>

                <div class="form-group qs-field">
                    <label for="qs_local_llm_api_key">API key <span class="qs-local-llm-optional">(optional)</span></label>
                    <input type="password" class="form-control" id="qs_local_llm_api_key" value="" autocomplete="new-password" spellcheck="false" placeholder="' . htmlspecialchars($localLlmKeyPlaceholder, ENT_QUOTES) . '" aria-describedby="qs_local_llm_api_key_help qs_local_llm_key_hint">
                    <small class="form-text" id="qs_local_llm_api_key_help">' . htmlspecialchars($localLlmKeyHelp, ENT_QUOTES) . '</small>
                    <p class="qs-local-llm-hint" id="qs_local_llm_key_hint" role="status" aria-live="polite"></p>
                    <div class="qs-local-llm-check">
                        <input type="checkbox" class="form-check-input" id="qs_local_llm_clear_api_key" value="1" aria-describedby="qs_local_llm_clear_help">
                        <label class="form-check-label" for="qs_local_llm_clear_api_key">Clear saved key</label>
                    </div>
                    <small class="form-text" id="qs_local_llm_clear_help">Disconnects the saved key on Apply. Test sends no key while checked.</small>
                </div>

                <div class="form-group qs-field">
                    <label for="qs_local_llm_scope">Apply to</label>
                    <select class="form-control" id="qs_local_llm_scope" aria-describedby="qs_local_llm_scope_help qs_local_llm_scope_warning">
                        <option value="conversations"' . ($localLlmScope === 'conversations' ? ' selected' : '') . '>Default conversations</option>
                        <option value="all"' . ($localLlmScope === 'all' ? ' selected' : '') . '>Conversations and background tasks</option>
                    </select>
                    <small class="form-text" id="qs_local_llm_scope_help">Default conversations swaps the four conversation LLM slots on the default NPC and narrator profiles only.</small>
                    <div class="qs-status err qs-local-llm-warning" id="qs_local_llm_scope_warning" role="status" aria-live="polite"' . ($localLlmScope === 'all' ? '' : ' hidden') . '>
                        <strong>Global change.</strong> Also changes Player Respeech, Summaries, Middle Term Memory, Scene Classifier, Dynamic Profile, Director Mode, Relationships and Custom WorldKnowledge &mdash; plus the diary and formatter slots on the default profiles. Features remain enabled or disabled as before.
                    </div>
                </div>

                <details class="qs-local-llm-help">
                    <summary>What stays untouched?</summary>
                    <div class="qs-local-llm-help-body">
                        <ul>
                            <li>Custom profile assignments stay unchanged. Any profile already using this local connector receives its new URL and model.</li>
                            <li>Speech (TTS and STT) and image recognition are never changed here.</li>
                            <li>Your OpenRouter key stays saved, so you can switch back at any time.</li>
                            <li>Applying Default conversations does not undo earlier background routing. Change those connectors in Global Settings if needed.</li>
                        </ul>
                    </div>
                </details>

                <details class="qs-local-llm-help">
                    <summary>Advanced</summary>
                    <div class="qs-local-llm-help-body qs-local-llm-advanced">
                        <div class="form-group qs-field">
                            <label for="qs_local_llm_timeout">Timeout (seconds)</label>
                            <input type="number" class="form-control" id="qs_local_llm_timeout" min="5" max="120" step="1" inputmode="numeric" value="' . htmlspecialchars(strval($localLlmTimeout), ENT_QUOTES) . '" aria-describedby="qs_local_llm_timeout_help">
                            <small class="form-text" id="qs_local_llm_timeout_help">5&ndash;120 seconds for connector requests and this test. WorldKnowledge keeps its own shorter extraction timeout.</small>
                        </div>
                        <div class="form-group qs-field">
                            <div class="qs-local-llm-check">
                                <input type="checkbox" class="form-check-input" id="qs_local_llm_disable_streaming" value="1"' . ($localLlmDisableStreaming ? ' checked' : '') . ' aria-describedby="qs_local_llm_stream_help">
                                <label class="form-check-label" for="qs_local_llm_disable_streaming">Disable streaming</label>
                            </div>
                            <small class="form-text" id="qs_local_llm_stream_help">Wait for the whole reply instead. Helps with servers whose streaming is unreliable.</small>
                        </div>
                    </div>
                </details>

                <div class="qs-local-llm-actions">
                    <button type="button" class="btn-primary qs-local-llm-test" id="qs_local_llm_test_btn">Test connection</button>
                    <button type="button" class="btn-primary qs-local-llm-apply" id="qs_local_llm_apply_btn"' . ($localLlmPlayer2Active ? ' disabled aria-describedby="qs_local_llm_player2_warning"' : '') . '>Apply local model</button>
                </div>
                <small class="form-text qs-local-llm-actions-help">Test sends one small prompt and saves nothing &mdash; it can take a while if your server still has to load the model. Apply is separate from this page&rsquo;s Save and Continue button.</small>
                <div class="qs-status qs-local-llm-status" id="qs_local_llm_status" role="status" aria-live="polite" aria-atomic="true">Not tested yet.</div>
            </div>
        </details>
      </section>';

echo '<style>
    .qs-local-llm .qs-local-llm-panel {
        margin: 0;
    }

    .qs-local-llm .qs-local-llm-summary {
        display: flex;
        align-items: center;
        gap: 10px;
        cursor: pointer;
        list-style: none;
        padding: 2px 0;
    }

    .qs-local-llm .qs-local-llm-summary::-webkit-details-marker {
        display: none;
    }

    .qs-local-llm .qs-local-llm-summary::after {
        content: "";
        width: 8px;
        height: 8px;
        margin-left: auto;
        border-right: 2px solid rgb(255, 182, 65);
        border-bottom: 2px solid rgb(255, 182, 65);
        transform: rotate(45deg);
        transition: transform 0.15s ease;
        flex: none;
    }

    .qs-local-llm .qs-local-llm-panel[open] > .qs-local-llm-summary::after {
        transform: rotate(-135deg);
    }

    .qs-local-llm .qs-local-llm-summary:focus-visible {
        outline: 2px solid rgb(255, 182, 65);
        outline-offset: 3px;
        border-radius: 6px;
    }

    .qs-local-llm .qs-local-llm-heading {
        font-family: "Gothic821", serif;
        color: rgb(255, 182, 65);
        text-shadow: 1px 1px 2px rgba(0,0,0,0.5);
        word-spacing: 6px;
        font-size: 1.4em;
    }

    .qs-local-llm .qs-local-llm-chip {
        font-size: 11px;
        letter-spacing: 0.04em;
        text-transform: uppercase;
        color: #cbd3df;
        border: 1px solid #4a4a4a;
        background: rgba(20, 20, 20, 0.65);
        border-radius: 999px;
        padding: 2px 9px;
        white-space: nowrap;
    }

    .qs-local-llm .qs-local-llm-chip.is-on {
        color: #ffd79a;
        border-color: rgba(255, 182, 65, 0.55);
        background: rgba(120, 78, 16, 0.28);
    }

    .qs-local-llm .qs-local-llm-body {
        margin-top: 12px;
        border-top: 1px solid #3b3b3b;
        padding-top: 12px;
    }

    .qs-local-llm .qs-local-llm-lead {
        margin: 0 0 12px 0;
    }

    .qs-local-llm .qs-local-llm-grid {
        display: grid;
        grid-template-columns: repeat(2, minmax(0, 1fr));
        gap: 12px;
    }

    .qs-local-llm .form-group {
        margin-bottom: 12px;
    }

    .qs-local-llm label {
        color: #e0e0e0;
        font-weight: 500;
        display: block;
        margin-bottom: 4px;
    }

    .qs-local-llm .qs-local-llm-optional {
        color: #9ca3af;
        font-weight: 400;
        font-size: 12px;
    }

    .qs-local-llm .form-text {
        display: block;
        margin-top: 6px;
        font-size: 12px;
        line-height: 1.45;
    }

    .qs-local-llm code {
        color: #ffd79a;
        background: rgba(20, 20, 20, 0.7);
        border-radius: 4px;
        padding: 0 4px;
        font-size: 11.5px;
        word-break: break-all;
    }

    .qs-local-llm .qs-local-llm-ips {
        display: block;
        margin-top: 3px;
        color: #9fb1c9;
    }

    .qs-local-llm .qs-local-llm-hint {
        margin: 6px 0 0 0;
        font-size: 12px;
        line-height: 1.45;
        color: #ffd79a;
    }

    .qs-local-llm .qs-local-llm-hint:empty {
        display: none;
    }

    .qs-local-llm .qs-local-llm-check {
        display: flex;
        align-items: center;
        gap: 8px;
        margin-top: 8px;
    }

    .qs-local-llm .qs-local-llm-check input[type="checkbox"] {
        width: 16px;
        height: 16px;
        margin: 0;
        flex: none;
        accent-color: rgb(255, 182, 65);
    }

    .qs-local-llm .qs-local-llm-check label {
        margin: 0;
        font-weight: 400;
        font-size: 13px;
    }

    .qs-local-llm .qs-local-llm-help {
        margin: 0 0 12px 0;
        border: 1px solid #3b3b3b;
        border-radius: 8px;
        background: rgba(20, 20, 20, 0.55);
    }

    .qs-local-llm .qs-local-llm-help > summary {
        cursor: pointer;
        padding: 8px 10px;
        color: #ffd79a;
        font-size: 13px;
    }

    .qs-local-llm .qs-local-llm-help > summary:focus-visible {
        outline: 2px solid rgb(255, 182, 65);
        outline-offset: -2px;
        border-radius: 8px;
    }

    .qs-local-llm .qs-local-llm-help-body {
        padding: 0 10px 10px 10px;
        color: #b9c4d6;
        font-size: 12px;
        line-height: 1.5;
    }

    .qs-local-llm .qs-local-llm-help-body ul {
        margin: 0;
        padding-left: 18px;
    }

    .qs-local-llm .qs-local-llm-help-body li {
        margin-bottom: 5px;
    }

    .qs-local-llm .qs-local-llm-advanced {
        display: grid;
        grid-template-columns: repeat(2, minmax(0, 1fr));
        gap: 12px;
        align-items: start;
        padding-top: 4px;
    }

    .qs-local-llm .qs-local-llm-advanced .form-group {
        margin-bottom: 0;
    }

    .qs-local-llm .qs-local-llm-warning,
    .qs-local-llm .qs-local-llm-blocker {
        font-size: 12px;
        line-height: 1.5;
    }

    .qs-local-llm .qs-local-llm-blocker {
        margin-bottom: 12px;
    }

    .qs-local-llm [hidden] {
        display: none !important;
    }

    .qs-local-llm .qs-local-llm-actions {
        display: flex;
        flex-wrap: wrap;
        justify-content: flex-end;
        gap: 4px;
        margin-top: 4px;
    }

    .qs-local-llm .qs-local-llm-actions-help {
        text-align: right;
        margin-top: 2px;
    }

    .qs-local-llm .btn-primary.qs-local-llm-apply {
        background-color: rgb(150, 96, 16) !important;
        border-color: rgba(255, 182, 65, 0.75) !important;
    }

    .qs-local-llm .btn-primary.qs-local-llm-apply:hover:not(:disabled) {
        background-color: rgb(126, 80, 12) !important;
    }

    .qs-local-llm .btn-primary:disabled {
        opacity: 0.5 !important;
        cursor: not-allowed !important;
    }

    .qs-local-llm .btn-primary:focus-visible {
        outline: 2px solid rgb(255, 182, 65) !important;
        outline-offset: 2px !important;
    }

    .qs-local-llm .qs-local-llm-status {
        margin-top: 10px;
        font-size: 12px;
        line-height: 1.5;
    }

    @media (max-width: 640px) {
        .qs-local-llm .qs-local-llm-grid,
        .qs-local-llm .qs-local-llm-advanced {
            grid-template-columns: 1fr;
        }

        .qs-local-llm .qs-local-llm-actions {
            justify-content: stretch;
        }

        .qs-local-llm .qs-local-llm-actions .btn-primary {
            flex: 1 1 100%;
        }

        .qs-local-llm .qs-local-llm-actions-help {
            text-align: left;
        }
    }
</style>';

echo '<script>
(function(){
  var CFG = ' . $localLlmJsConfig . ';
  var byId = function(id){ return document.getElementById(id); };

  var serverSelect = byId("qs_local_llm_server_type");
  var urlInput = byId("qs_local_llm_url");
  var modelInput = byId("qs_local_llm_model");
  var keyInput = byId("qs_local_llm_api_key");
  var keyHelp = byId("qs_local_llm_api_key_help");
  var clearKeyBox = byId("qs_local_llm_clear_api_key");
  var scopeSelect = byId("qs_local_llm_scope");
  var scopeWarning = byId("qs_local_llm_scope_warning");
  var timeoutInput = byId("qs_local_llm_timeout");
  var streamBox = byId("qs_local_llm_disable_streaming");
  var testBtn = byId("qs_local_llm_test_btn");
  var applyBtn = byId("qs_local_llm_apply_btn");
  var statusBox = byId("qs_local_llm_status");
  var urlHint = byId("qs_local_llm_url_hint");
  var keyHint = byId("qs_local_llm_key_hint");
  var chip = byId("qs_local_llm_chip");
  var blocker = byId("qs_local_llm_player2_warning");

  if (!serverSelect || !urlInput || !modelInput || !statusBox || !testBtn || !applyBtn) { return; }

  var busy = false;
  var hasApiKey = !!CFG.hasApiKey;
  var savedUrl = String(CFG.savedUrl || "");
  var presetUrls = Array.isArray(CFG.presetUrls) ? CFG.presetUrls : [];
  var presetHosts = Array.isArray(CFG.presetHosts) ? CFG.presetHosts : ["127.0.0.1"];
  var blockerHtml = blocker ? blocker.innerHTML : "";

  function normalizeUrl(value){
    var text = String(value == null ? "" : value).trim();
    try {
      var parsed = new URL(text);
      if (parsed.hostname === "localhost") { parsed.hostname = "127.0.0.1"; }
      return parsed.href;
    } catch (_error) { return text; }
  }

  function isPresetUrl(value){
    var target = normalizeUrl(value);
    if (target === "") { return true; }
    for (var i = 0; i < presetUrls.length; i++) {
      if (normalizeUrl(presetUrls[i]) === target) { return true; }
    }
    return false;
  }

  function presetUrlFor(serverId){
    var meta = CFG.catalog ? CFG.catalog[serverId] : null;
    if (!meta) { return ""; }
    var port = Number(meta.port || 0);
    if (!(port > 0)) { return ""; }
    return "http://" + (presetHosts[0] || "127.0.0.1") + ":" + String(port) + String(CFG.endpointPath || "");
  }

  function serverLabel(serverId){
    var meta = CFG.catalog ? CFG.catalog[serverId] : null;
    return (meta && meta.label) ? String(meta.label) : String(serverId);
  }

  function setStatus(message, kind){
    statusBox.classList.remove("ok", "err");
    if (kind) { statusBox.classList.add(kind); }
    statusBox.textContent = message;
  }

  function setHint(node, message){
    if (node) { node.textContent = message || ""; }
  }

  function clampTimeout(){
    if (!timeoutInput) { return 30; }
    var value = parseInt(timeoutInput.value, 10);
    if (!isFinite(value)) { value = 30; }
    if (value < 5) { value = 5; }
    if (value > 120) { value = 120; }
    timeoutInput.value = String(value);
    return value;
  }

  function refreshKeyHint(){
    if (!hasApiKey || (clearKeyBox && clearKeyBox.checked)) { setHint(keyHint, ""); return; }
    var changed = normalizeUrl(urlInput.value) !== normalizeUrl(savedUrl);
    if (changed && keyInput && keyInput.value === "") {
      setHint(keyHint, "Endpoint changed. Re-enter the API key, or tick Clear saved key if this server needs none.");
    } else {
      setHint(keyHint, "");
    }
  }

  function setBusy(state, label){
    busy = state;
    testBtn.disabled = state;
    applyBtn.disabled = state || !!CFG.player2Active;
    [serverSelect, urlInput, modelInput, keyInput, clearKeyBox, scopeSelect, timeoutInput, streamBox].forEach(function(node){
      if (node) { node.disabled = state; }
    });
    if (!state && keyInput && clearKeyBox) { keyInput.disabled = clearKeyBox.checked; }
    statusBox.setAttribute("aria-busy", state ? "true" : "false");
    if (state && label) { setStatus(label, ""); }
  }

  function validate(){
    var url = urlInput.value.trim();
    if (url === "" || !/^https?:\/\//i.test(url)) {
      setStatus("Enter a full endpoint URL starting with http:// or https://.", "err");
      urlInput.focus();
      return null;
    }
    if (modelInput.value.trim() === "") {
      setStatus("Enter the model name your server has loaded.", "err");
      modelInput.focus();
      return null;
    }
    return {
      csrf_token: String(CFG.csrfToken || ""),
      server_type: serverSelect.value,
      url: url,
      model: modelInput.value.trim(),
      api_key: (keyInput && !keyInput.disabled) ? keyInput.value : "",
      clear_api_key: !!(clearKeyBox && clearKeyBox.checked),
      timeout: clampTimeout(),
      disable_streaming: !!(streamBox && streamBox.checked),
      scope: scopeSelect ? scopeSelect.value : "conversations"
    };
  }

  function describe(result, fallback){
    var message = (result && result.message) ? String(result.message) : fallback;
    var elapsed = Number(result && result.elapsed_ms ? result.elapsed_ms : 0);
    if (elapsed > 0) { message += " (" + String(elapsed) + " ms)"; }
    return message;
  }

  async function submit(action){
    if (busy) { return; }
    var payload = validate();
    if (!payload) { return; }
    payload.action = action;

    setBusy(true, action === "test" ? "Testing your local server. This can take a while if the model still has to load." : "Applying local model...");
    try {
      var response = await fetch(String(CFG.endpoint), {
        method: "POST",
        headers: { "Content-Type": "application/json", "Accept": "application/json" },
        body: JSON.stringify(payload),
        cache: "no-store",
        credentials: "same-origin"
      });
      var result = null;
      try { result = await response.json(); } catch (_parseError) { result = null; }
      if (!result) {
        setStatus("The server returned an unreadable response (HTTP " + String(response.status) + ").", "err");
        return;
      }
      if (!response.ok || result.ok !== true) {
        setStatus(describe(result, "Request failed (HTTP " + String(response.status) + ")."), "err");
        return;
      }

      if (action === "save") {
        savedUrl = String(result.url || payload.url);
        hasApiKey = result.has_api_key === true;
        if (keyInput) { keyInput.value = ""; keyInput.disabled = false; }
        if (clearKeyBox) { clearKeyBox.checked = false; }
        if (keyInput) {
          keyInput.placeholder = hasApiKey ? "Leave blank to keep the saved key" : "Leave blank if your server needs no key";
        }
        if (keyHelp) {
          keyHelp.textContent = hasApiKey
            ? "A key is saved. Blank keeps it, as long as the endpoint stays the same."
            : "No key is saved. Most local servers do not need one.";
        }
        if (chip) { chip.textContent = "Configured"; chip.classList.add("is-on"); }
        refreshKeyHint();
      }

      setStatus(describe(result, action === "test" ? "Local server responded." : "Local model applied."), "ok");
    } catch (_error) {
      setStatus("Could not reach the Dialectic server to run that request.", "err");
    } finally {
      setBusy(false);
    }
  }

  serverSelect.addEventListener("change", function(){
    var next = presetUrlFor(serverSelect.value);
    var label = serverLabel(serverSelect.value);
    if (next === "") {
      setHint(urlHint, "No standard port for " + label + ". Enter the endpoint yourself.");
    } else if (isPresetUrl(urlInput.value)) {
      urlInput.value = next;
      setHint(urlHint, "Endpoint set to the " + label + " default.");
    } else {
      setHint(urlHint, "Endpoint left as-is because it looks custom. Update it by hand if needed.");
    }
    refreshKeyHint();
  });

  urlInput.addEventListener("input", refreshKeyHint);
  if (keyInput) { keyInput.addEventListener("input", refreshKeyHint); }

  if (clearKeyBox && keyInput) {
    clearKeyBox.addEventListener("change", function(){
      keyInput.disabled = clearKeyBox.checked;
      if (clearKeyBox.checked) { keyInput.value = ""; }
      refreshKeyHint();
    });
  }

  if (scopeSelect && scopeWarning) {
    scopeSelect.addEventListener("change", function(){
      scopeWarning.hidden = (scopeSelect.value !== "all");
    });
  }

  if (timeoutInput) { timeoutInput.addEventListener("change", clampTimeout); }

  if (CFG.player2Active && blocker) {
    var player2Toggle = byId("qs_player2_force_all_llm");
    if (player2Toggle) {
      player2Toggle.addEventListener("change", function(){
        blocker.innerHTML = player2Toggle.checked
          ? blockerHtml
          : "<strong>Almost there.</strong> Press Save and Continue to store that Player2 change, then reopen this panel to apply a local model.";
      });
    }
  }

  testBtn.addEventListener("click", function(){ submit("test"); });
  applyBtn.addEventListener("click", function(){ submit("save"); });

  refreshKeyHint();
})();
</script>';
