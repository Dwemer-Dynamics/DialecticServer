<?php

/**
 * Plugin parity probe for DialecticServer. CLI only; uses no database or provider.
 *
 *   php examples/plugin-parity/probe.php [--scratch=<empty-or-new-dir>]
 *
 * Copies the example plugins plus small fixtures into a scratch ext/ root and
 * runs the real loader, prompt injection API, functions.php action catalog,
 * json_response.php schema and action dispatch encoder against it.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$probeFailures = 0;
$probeWarnings = [];
set_error_handler(static function (int $severity, string $message, string $file, int $line) use (&$probeWarnings): bool {
    if ((error_reporting() & $severity) !== 0) {
        $probeWarnings[] = "{$message} ({$file}:{$line})";
    }
    return true;
});

function probeCheck(bool $condition, string $label, $detail = null): void
{
    global $probeFailures;
    echo ($condition ? 'PASS ' : 'FAIL ') . $label;
    if (!$condition && $detail !== null) {
        echo ' :: ' . json_encode($detail, JSON_UNESCAPED_SLASHES);
    }
    echo PHP_EOL;
    if (!$condition) {
        $probeFailures++;
    }
}

function probeWrite(string $path, string $contents): void
{
    if (!is_dir(dirname($path))) {
        mkdir(dirname($path), 0777, true);
    }
    file_put_contents($path, $contents);
}

function probeRemoveTree(string $path): void
{
    if (is_link($path) || is_file($path)) {
        @unlink($path);
        return;
    }
    if (!is_dir($path)) {
        return;
    }
    foreach (scandir($path) as $entry) {
        if ($entry !== '.' && $entry !== '..') {
            probeRemoveTree($path . DIRECTORY_SEPARATOR . $entry);
        }
    }
    @rmdir($path);
}

$serverRoot = dirname(__DIR__, 2);
$scratchArg = '';
foreach (array_slice($argv, 1) as $argument) {
    if (str_starts_with($argument, '--scratch=')) {
        $scratchArg = substr($argument, 10);
    }
}
$scratch = $scratchArg !== '' ? $scratchArg : sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'dialectic-plugin-parity-' . bin2hex(random_bytes(4));
if (is_dir($scratch) && count(scandir($scratch)) > 2) {
    fwrite(STDERR, "Scratch directory must be new or empty: {$scratch}\n");
    exit(2);
}
$extRoot = $scratch . DIRECTORY_SEPARATOR . 'ext';
$trace = static fn(string $label): string => "<?php\n\$GLOBALS['PLUGIN_PARITY_TRACE'][] = '{$label}';\n";

foreach (['parity_probe', 'parity_probe_order'] as $plugin) {
    foreach (glob(__DIR__ . "/ext/{$plugin}/*.php") as $source) {
        probeWrite("{$extRoot}/{$plugin}/" . basename($source), file_get_contents($source));
    }
}
// Fixtures that must never execute.
probeWrite("{$extRoot}/relationship_system/context_pre.php", $trace('LEGACY relationship context_pre'));
probeWrite("{$extRoot}/relationship_system/postrequest.php", $trace('LEGACY relationship postrequest'));
probeWrite("{$extRoot}/disabled_marker/globals.php", $trace('DISABLED marker'));
probeWrite("{$extRoot}/disabled_marker/.disabled", '');
probeWrite("{$extRoot}/renamed.disabled/globals.php", $trace('DISABLED suffix'));
probeWrite("{$extRoot}/.hidden_plugin/globals.php", $trace('HIDDEN'));
probeWrite("{$extRoot}/parity_probe/private/globals.php", $trace('PRIVATE'));
probeWrite("{$extRoot}/parity_probe/staging/globals.php", $trace('STAGING'));
probeWrite("{$extRoot}/globals.php", $trace('ROOT FILE'));
// A failing plugin is logged and skipped; later plugins still load.
probeWrite("{$extRoot}/broken_plugin/globals.php", "<?php\n\$GLOBALS['PLUGIN_PARITY_TRACE'][] = 'broken_plugin:globals';\nthrow new RuntimeException('fixture failure');\n");
probeWrite("{$extRoot}/parity_probe_order/json_response_custom.php", "<?php\n\$GLOBALS['PLUGIN_PARITY_TRACE'][] = 'parity_probe_order:json_response_custom';\n\$GLOBALS['HOOKS']['JSON_TEMPLATE'][] = static function (): void { \$GLOBALS['responseTemplate']['parity_probe'] = 'optional marker'; };\n");
// Required-target action for dispatch validation.
probeWrite("{$extRoot}/parity_probe_order/prompts.php", "<?php\ndialecticRegisterExtensionAction('ExtCmdParityProbe_Required', 'Needs a target.', ['target' => 'required']);\ndialecticRegisterExtensionAction('ExtCmdParityProbe_NoTarget', 'Takes no target.', ['target' => 'none']);\n");
$symlinkFixture = 'skipped: symlink() unavailable';
probeWrite("{$scratch}/outside/globals.php", $trace('SYMLINK'));
if (function_exists('symlink') && @symlink("{$scratch}/outside", "{$extRoot}/linked_plugin")) {
    $symlinkFixture = 'created';
}

$GLOBALS['DIALECTIC_EXTENSION_ROOT'] = $extRoot;
$GLOBALS['PLUGIN_PARITY_TRACE'] = [];
ini_set('error_log', $scratch . DIRECTORY_SEPARATOR . 'probe-error.log');
require_once $serverRoot . '/lib/logger.php';
Logger::setLevel('error');
Logger::setCustomLog($scratch . DIRECTORY_SEPARATOR . 'dialectic.log');
require_once $serverRoot . '/lib/prompt_injections.php';
require_once $serverRoot . '/lib/extension_hooks.php';

echo "Scratch ext root: {$extRoot}\nSymlink fixture: {$symlinkFixture}\n";

// Loader: discovery, order, include-once, exclusions.
$hookIndex = dialecticExtensionHookIndex();
$ran = dialecticRunExtensionHook('globals.php');
$relative = array_map(static fn(string $path): string => str_replace('\\', '/', substr($path, strlen($extRoot) + 1)), $ran);
probeCheck($relative === ['broken_plugin/globals.php', 'parity_probe/globals.php', 'parity_probe_order/globals.php'], 'globals.php files in byte-sorted order', $relative);
probeCheck($GLOBALS['PLUGIN_PARITY_TRACE'] === ['broken_plugin:globals', 'parity_probe:globals', 'parity_probe_order:globals'], 'two plugins ran after a failing plugin', $GLOBALS['PLUGIN_PARITY_TRACE']);
probeCheck(dialecticRunExtensionHook('globals.php') === [] && requireFilesRecursively($extRoot . '/', 'globals.php') === [], 'second run and CHIM requireFilesRecursively() are include-once');
$excluded = array_filter($GLOBALS['PLUGIN_PARITY_TRACE'], static fn(string $item): bool => preg_match('/^[A-Z]/', $item) === 1);
probeCheck(count($excluded) === 0, 'disabled, hidden, private, staging, root and symlink fixtures excluded', $excluded);
probeCheck(($hookIndex['context_pre.php'] ?? []) === [] && ($hookIndex['postrequest.php'] ?? []) === [], 'ext/relationship_system left to its explicit pipeline includes');
probeWrite("{$extRoot}/late_plugin/context.php", $trace('LATE'));
$gameRequest = ['inputtext', '1', '2', 'Courier: hi'];
$GLOBALS['gameRequest'] = &$gameRequest;
$contextRan = dialecticRunExtensionHook('context.php');
probeCheck(count($contextRan) === 1 && !in_array('LATE', $GLOBALS['PLUGIN_PARITY_TRACE'], true), 'later stages reuse the one-time directory index');
probeCheck(in_array('parity_probe:context:inputtext', $GLOBALS['PLUGIN_PARITY_TRACE'], true), '$gameRequest visible to context.php');
probeCheck(dialecticRunExtensionHook('globals.php', $scratch . '/missing-ext') === [], 'missing ext root is a no-op');

// Prompt injection and enrichment API (CHIM names wrap dialectic* names).
$characterBottom = chimRenderPromptInjections('character_bottom', ['dialectic_name' => 'Veronica']);
probeCheck($characterBottom === dialecticRenderPromptInjections('character_bottom') && str_contains($characterBottom, '<parity_probe>'), 'character_bottom renders through both names');
probeCheck(strpos($characterBottom, '<parity_probe_order/>') < strpos($characterBottom, '<parity_probe>'), 'injection priority (10 before 90) overrides load order');
probeCheck(chimRenderPromptInjections('prompt_bottom', ['dialectic_name' => 'Veronica']) === "\n<parity_probe_footer>Prompt built for Veronica.</parity_probe_footer>", 'prompt_bottom callback receives context');
probeCheck(chimRenderPromptInjections('missing_slot') === '' && chimRegisterPromptInjection(' ', 'x', 'y') === false, 'empty slots and invalid keys fail cleanly');
probeCheck(chimBuildActorProfileEnrichmentText('Boone', 'npc') === 'Parity probe sees Boone', 'actor profile enricher via CHIM name');

// Registration validation.
$invalidCodes = ['ExtCmd_Ping', 'Ping', 'ExtCmdA_B C', 'ExtCmdParity', 'WebCmdParity_Ping'];
probeCheck(count(array_filter($invalidCodes, static fn(string $code): bool => dialecticRegisterExtensionAction($code, 'x'))) === 0 && !dialecticRegisterExtensionAction('ExtCmdParity_NoDescription', ' '), 'invalid codes and empty descriptions rejected');
probeCheck(!dialecticRegisterExtensionAction('EXTCMDPARITYPROBE_PING', 'case clash'), 'case-insensitive duplicate code rejected');

// Real action catalog, schema and dispatch encoder (database not configured).
dialecticRunExtensionHook('prompts.php');
$GLOBALS['PLAYER_NAME'] = 'Courier';
$GLOBALS['DIALECTIC_NAME'] = 'Veronica';
$GLOBALS['FUNCTIONS_ARE_ENABLED'] = true;
$startTime = microtime(true);
require_once $serverRoot . '/functions/functions.php';
probeCheck(isset(dialecticCanonicalActionCodeSet()['ExtCmdParityProbe_Ping']) && !isset(dialecticCanonicalActionCodeSet()['ExtCmdUnknown_Thing']), 'canonical action set admits only registered ExtCmd codes');
probeCheck(in_array('ExtCmdParityProbe_Ping', $GLOBALS['ENABLED_FUNCTIONS'], true) && getFunctionCodeName('ExtCmdParityProbe_Ping') === 'ExtCmdParityProbe_Ping', 'registered action enabled with code-name F_NAMES entry');
// GiveCapsTo reads caps from the database while building the action list; keep one native action.
$GLOBALS['ENABLED_FUNCTIONS'] = ['Attack', 'ExtCmdParityProbe_Ping', 'ExtCmdParityProbe_Required', 'ExtCmdParityProbe_NoTarget'];
require_once $serverRoot . '/functions/json_response.php';
$actionEnum = $GLOBALS['structuredOutputTemplate']['json_schema']['schema']['properties']['action']['enum'] ?? [];
probeCheck(in_array('ExtCmdParityProbe_Ping', $actionEnum, true) && in_array('Attack', $actionEnum, true), 'structured action enum includes registered and native actions', $actionEnum);
probeCheck(str_contains($GLOBALS['PROMPT_ACTIONS_LIST'] ?? '', 'AVAILABLE ACTION: ExtCmdParityProbe_Ping (Send a harmless parity ping'), 'action guidance lists the registered action');
probeCheck(($GLOBALS['responseTemplate']['parity_probe'] ?? '') === 'optional marker' && in_array('parity_probe_order:json_response_custom', $GLOBALS['PLUGIN_PARITY_TRACE'], true), 'json_response_custom.php + HOOKS JSON_TEMPLATE applied');

$dispatch = static function (array $response): array {
    $buffer = [];
    $sent = [];
    $context = buildFunctionExecutionContextFromResponse($response);
    $queued = queueFunctionExecutionCommand($buffer, $sent, $context, 'parity-probe', 'Veronica');
    return [$queued, $buffer === [] ? null : json_decode(trim($buffer[0]), true)];
};
[$queued, $line] = $dispatch(['action' => 'ExtCmdParityProbe_Ping', 'target' => 'Boone']);
probeCheck($queued && ($line['action'] ?? '') === 'ExtCmdParityProbe_Ping' && ($line['actor'] ?? '') === 'Veronica' && ($line['parameter'] ?? '') === 'Boone', 'registered action dispatches as dialectic.action.v1 with exact actor', $line);
probeCheck($dispatch(['action' => 'ExtCmdUnknown_Thing', 'target' => 'Boone'])[0] === false, 'unregistered ExtCmd action is not dispatched');
probeCheck($dispatch(['action' => 'ExtCmdParityProbe_Required', 'target' => ''])[0] === false, 'required target enforced');
[$queuedNoTarget, $lineNoTarget] = $dispatch(['action' => 'ExtCmdParityProbe_NoTarget', 'target' => 'ignored']);
probeCheck($queuedNoTarget && ($lineNoTarget['parameter'] ?? null) === '', 'target "none" sends an empty parameter', $lineNoTarget);

// Exact speaker reference for ExtCmd response lines.
$GLOBALS['DIALECTIC_CORE_CURRENT_NPC_DATA'] = ['npc_name' => 'Veronica', 'refid' => '0x0001A2B3'];
probeCheck(dialecticExtensionActionSpeakerRefid('Veronica') === '0x0001A2B3' && dialecticExtensionActionSpeakerRefid('Boone') === '', 'speaker_refid comes only from the active NPC record');
$GLOBALS['DIALECTIC_RESPONSE_SPEAKER_FORMID'] = '0x00ABCDEF';
probeCheck(dialecticExtensionActionSpeakerRefid('veronica') === '0x00ABCDEF', 'external-request speaker form ID takes precedence');
unset($GLOBALS['DIALECTIC_RESPONSE_SPEAKER_FORMID'], $GLOBALS['DIALECTIC_CORE_CURRENT_NPC_DATA']);
probeCheck(dialecticExtensionActionSpeakerRefid('Veronica') === '', 'no known reference leaves speaker_refid empty');

// Observer: Dialectic client funcret through the real prerequest hook, pluginevent, malformed payloads.
$gameRequest = ['funcret', '1', '2', json_encode(['schema' => 'dialectic.action_result.v1', 'action' => 'ExtCmdParityProbe_Ping', 'speaker' => 'Veronica', 'speaker_refid' => '0x0001A2B3', 'target' => 'Boone', 'result' => 'Pong from Veronica to [Boone] talking=0', 'status' => 'completed', 'bridge' => 'ParityProbe', 'request_id' => 7])];
dialecticRunExtensionHook('prerequest.php');
probeCheck(in_array('parity_probe:completion:Boone', $GLOBALS['PLUGIN_PARITY_TRACE'], true), 'completion observer saw client funcret through prerequest.php');
$pluginEvent = parityProbeObserveEvent(['pluginevent', '1', '2', json_encode(['schema' => 'dialectic.plugin_event.v1', 'bridge' => 'ParityProbe', 'name' => 'state', 'data' => 'ready', 'actor' => 'Veronica', 'actor_refid' => '0x0001A2B3'])]);
probeCheck(($pluginEvent['argument'] ?? '') === 'state' && ($pluginEvent['result'] ?? '') === 'ready', 'pluginevent dialectic.plugin_event.v1 observed');
$malformed = [null, [], ['funcret'], ['funcret', '', '', ['array']], ['funcret', '', '', 'command@ExtCmdParityProbe_Ping@x@y'], ['funcret', '', '', '{"schema":"dialectic.action_result.v1","action":"ExtCmdOther_Ping"}'], ['funcret', '', '', '{"schema":"other","action":"ExtCmdParityProbe_Ping"}'], ['pluginevent', '', '', '{"schema":"dialectic.plugin_event.v1","bridge":"Other","name":"x"}'], ['inputtext', '', '', '{"schema":"dialectic.action_result.v1","action":"ExtCmdParityProbe_Ping"}'], ['funcret', '', '', '{not json'], ['funcret', '', '', str_repeat('a', 9000)]];
probeCheck(count(array_filter(array_map('parityProbeObserveEvent', $malformed))) === 0, 'malformed or foreign events ignored');

probeCheck($probeWarnings === [], 'no PHP warnings or notices', $probeWarnings);
$errorLog = is_file($scratch . '/probe-error.log') ? file_get_contents($scratch . '/probe-error.log') : '';
probeCheck(str_contains($errorLog, 'fixture failure') && str_contains($errorLog, '[parity_probe] completion: completed Pong from Veronica to [Boone] talking=0'), 'plugin failure and completion were logged');

if ($scratchArg === '') {
    probeRemoveTree($scratch);
}
echo $probeFailures === 0 ? "RESULT PASS\n" : "RESULT FAIL ({$probeFailures})\n";
exit($probeFailures === 0 ? 0 : 1);
