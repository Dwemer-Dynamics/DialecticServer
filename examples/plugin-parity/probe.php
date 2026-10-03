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
probeWrite("{$extRoot}/parity_probe_order/prompts.php", "<?php\ndialecticRegisterExtensionAction('ExtCmdParityProbe_Required', 'Needs a target.', ['target' => 'required']);\ndialecticRegisterExtensionAction('ExtCmdParityProbe_NoTarget', 'Takes no target.', ['target' => 'none']);\n"
    // Opt-in follow-up fixture; the client's example ParityProbe script only completes Ping.
    . "dialecticRegisterExtensionAction('ExtCmdParityProbe_Report', 'Report probe status.', ['followup' => ['enabled' => true, 'prompt' => 'Reply with one short line about the report.', 'use_functions_again' => true]]);\n");
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
// CHIM requireFilesRecursively(__DIR__, ...) from inside one plugin folder.
probeWrite("{$extRoot}/scoped_plugin/scoped_helper.php", "<?php\n\$GLOBALS['PLUGIN_PARITY_TRACE'][] = 'scoped:direct';\n");
probeWrite("{$extRoot}/scoped_plugin/lib/scoped_helper.php", "<?php\n\$GLOBALS['PLUGIN_PARITY_TRACE'][] = 'scoped:nested';\n");
probeWrite("{$extRoot}/scoped_plugin/private/scoped_helper.php", $trace('SCOPED private'));
probeWrite("{$extRoot}/disabled_marker/scoped_helper.php", $trace('SCOPED disabled'));
requireFilesRecursively("{$extRoot}/scoped_plugin", 'scoped_helper.php');
requireFilesRecursively("{$extRoot}/disabled_marker", 'scoped_helper.php');
$scopedTrace = array_values(array_filter($GLOBALS['PLUGIN_PARITY_TRACE'], static fn(string $item): bool => stripos($item, 'scoped') !== false));
probeCheck($scopedTrace === ['scoped:nested', 'scoped:direct'] && requireFilesRecursively("{$extRoot}/scoped_plugin", 'scoped_helper.php') === [], 'plugin-folder requireFilesRecursively() loads its own and nested helpers once; private and disabled skipped', $scopedTrace);

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
probeCheck(dialecticRegisterExtensionAction('ExtCmdParityProbe_Do_Thing', 'Underscore action.') && !dialecticRegisterExtensionAction('ExtCmd9Probe_Ping', 'x') && !dialecticRegisterExtensionAction('ExtCmdParityProbe__Ping', 'x'), 'action names may contain underscores; bridge and action still start with a letter');
unset($GLOBALS['DIALECTIC_EXTENSION_ACTIONS']['ExtCmdParityProbe_Do_Thing']);

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

// Opt-in follow-ups: registration validation, existing resolver and processor/funcret.php.
$invalidFollowups = ['yes', ['enabled' => 'true', 'prompt' => 'x'], ['enabled' => true], ['enabled' => true, 'prompt' => ' '], ['enabled' => true, 'prompt' => str_repeat('x', 1001)], ['enabled' => true, 'prompt' => 'x', 'arg_name' => 'bad name'], ['enabled' => true, 'prompt' => 'x', 'use_functions_again' => 1], ['enabled' => true, 'prompt' => 'x', 'chain_limit' => 5]];
$acceptedInvalid = array_filter($invalidFollowups, static fn($followup): bool => dialecticRegisterExtensionAction('ExtCmdParityProbe_Invalid', 'x', ['followup' => $followup]));
probeCheck($acceptedInvalid === [] && !isset(dialecticExtensionActionRegistry()['ExtCmdParityProbe_Invalid']), 'invalid follow-up options reject the registration', array_keys($acceptedInvalid));
probeCheck(dialecticRegisterExtensionAction('ExtCmdParityProbe_Quiet', 'x', ['followup' => ['enabled' => false, 'prompt' => 'unused']]) && dialecticActionCatalogGetResolvedFollowupConfig('ExtCmdParityProbe_Quiet') === [], 'explicitly disabled follow-up resolves to none');
unset($GLOBALS['DIALECTIC_EXTENSION_ACTIONS']['ExtCmdParityProbe_Quiet']);
probeCheck(dialecticActionCatalogGetResolvedFollowupConfig('ExtCmdParityProbe_Ping') === [], 'existing registration without options has no follow-up');
$reportConfig = dialecticActionCatalogGetResolvedFollowupConfig('ExtCmdParityProbe_Report');
probeCheck($reportConfig === ['enabled' => true, 'prompt' => 'Reply with one short line about the report.', 'arg_name' => 'target', 'use_functions_again' => true], 'opt-in follow-up resolves through the action catalog resolver', $reportConfig);

if (!class_exists('sql')) {
    class sql
    {
        public array $issued = [];
        public array $eventlog = [];
        public function fetchAll($query)
        {
            return str_contains($query, 'actions_issued') ? $this->issued : $this->eventlog;
        }
        // Emulates the follow-up gate's two bound queries: filter, count, then bound the returned rows.
        public function fetchOne($query, array $params = [])
        {
            $issued = str_contains($query, 'actions_issued');
            $rows = array_values(array_filter($issued ? $this->issued : $this->eventlog, static fn(array $row): bool => $issued
                ? $row['action'] === $params[0] && $row['localts'] >= $params[1] && in_array(strtolower($row['actorname']), [strtolower($params[2]), '*'], true)
                : $row['localts'] >= $params[0] && str_contains($row['data'], $params[1]) && (str_contains($row['data'], $params[2]) || str_contains($row['data'], $params[3]))));
            usort($rows, static fn(array $a, array $b): int => $issued ? $b['rowid'] <=> $a['rowid'] : $a['rowid'] <=> $b['rowid']);
            $bounded = array_column(array_slice($rows, 0, 16), $issued ? 'fullcall' : 'data');
            return ['candidates' => strval(count($rows)), 'rows' => $bounded === [] ? null : json_encode($bounded)];
        }
        public function escape($value)
        {
            return addslashes(strval($value));
        }
    }
}
if (!function_exists('terminate')) {
    function terminate()
    {
        throw new RuntimeException('terminate');
    }
}
probeWrite("{$scratch}/processor/funcret.php", file_get_contents($serverRoot . '/processor/funcret.php'));
probeWrite("{$scratch}/log/.keep", '');
$GLOBALS['db'] = new sql();
$funcretRun = 0;
// $prior: funcret data logged before this delivery (arrays override the payload); the delivery itself is logged unless $logged is false.
$runFuncret = static function (array $changes, array $issued = [], array $prior = [], bool $logged = true) use ($scratch, &$funcretRun): array {
    // The issued-action map is cached per NPC name. The NPC record's refid is decimal; the client reports hex.
    $npc = $GLOBALS['DIALECTIC_NAME'] = 'FollowupNpc' . (++$funcretRun);
    $GLOBALS['DIALECTIC_CORE_CURRENT_NPC_DATA'] = ['npc_name' => $npc, 'refid' => '107187'];
    $payload = array_merge(['schema' => 'dialectic.action_result.v1', 'action' => 'ExtCmdParityProbe_Report', 'speaker' => $npc, 'speaker_refid' => '0x0001A2B3', 'target' => 'Boone "B"', 'result' => 'Report ok', 'status' => 'completed', 'bridge' => 'ParityProbe', 'request_id' => 9], $changes);
    $issued = array_map(static fn(array $row): array => array_merge(['rowid' => 1, 'action' => 'ExtCmdParityProbe_Report', 'actorname' => $npc, 'localts' => time() - 5, 'original' => '', 'fullcall' => dialecticEncodeActionLine($npc, 'ExtCmdParityProbe_Report', 'Boone "B"')], $row), array_is_list($issued) && $issued !== [] ? $issued : [$issued]);
    $GLOBALS['db']->issued = $issued;
    $rows = array_merge($prior, $logged ? [[]] : []);
    $GLOBALS['db']->eventlog = array_map(static fn($data, int $i): array => ['rowid' => $i + 1, 'localts' => time() - 1, 'data' => is_array($data) ? json_encode(array_merge($payload, $data)) : $data], $rows, array_keys($rows));
    $GLOBALS['FUNCTIONS_ARE_ENABLED'] = 'unchanged';
    unset($GLOBALS['FOLLOWUP_CHAIN_NEXT_DEPTH']);
    $gameRequest = ['funcret', '1', '2', json_encode($payload)];
    $request = 'Courier: test';
    $head = $contextDataFull = [];
    $LAST_ROLE = 'user';
    try {
        require "{$scratch}/processor/funcret.php";
    } catch (RuntimeException $exception) {
        return ['followup' => false, 'functions' => $GLOBALS['FUNCTIONS_ARE_ENABLED']];
    }
    return ['followup' => true, 'functions' => $GLOBALS['FUNCTIONS_ARE_ENABLED'], 'depth' => $GLOBALS['FOLLOWUP_CHAIN_NEXT_DEPTH'] ?? null, 'request' => $request, 'arguments' => $functionCalled[0]['tool_calls'][0]['function']['arguments'] ?? ''];
};
$completed = $runFuncret([], [], [['request_id' => 8], ['request_id' => 90, 'target' => 'Cass'], ['speaker_refid' => '0x0001A2B4', 'request_id' => 10]]);
probeCheck($completed['followup'] && $completed['functions'] === true && $completed['depth'] === 1 && str_starts_with($completed['request'], '(Reply with one short line about the report.)') && json_decode($completed['arguments'], true) === ['target' => 'Boone "B"'], 'completed opt-in result continues to one follow-up with functions at chain depth 1', $completed);
$chained = $runFuncret([], ['original' => dialecticActionCatalogEncodeActionsIssuedOriginalValue('', 1)]);
probeCheck($chained['followup'] && $chained['functions'] === false && !isset($chained['depth']), 'chain limit 1: a follow-up-issued action gets a text-only follow-up', $chained);
// Twelve decodable distractors (other request IDs, speakers, targets, actions) and malformed rows carrying this request's text.
$distractors = array_merge(array_map(static fn(int $id): array => ['request_id' => $id], range(10, 15)), [['request_id' => 99], ['request_id' => 19], ['action' => 'ExtCmdParityProbe_Ping'], ['speaker_refid' => '0x0001A2B4', 'request_id' => 11], ['target' => 'Cass', 'request_id' => 12], ['status' => 'failed', 'request_id' => 13]]);
$malformedRows = ['{"action":"ExtCmdParityProbe_Report","request_id":9}}', '{"action":"ExtCmdParityProbe_Report","speaker":"x\\","request_id":9,', 'truncated "action":"ExtCmdParityProbe_Report" "request_id":9}'];
$accepted = [
    'after 12 distractors and malformed rows' => $runFuncret([], [], array_merge($distractors, $malformedRows)),
    'second request ID for the same action and target' => $runFuncret(['request_id' => 10], [], [['request_id' => 9]]),
    'target "none" reports the speaker' => $runFuncret(['target' => 'FollowupNpc' . ($funcretRun + 1)], ['fullcall' => dialecticEncodeActionLine('FollowupNpc' . ($funcretRun + 1), 'ExtCmdParityProbe_Report', '')]),
    'matching issue behind newer issues' => $runFuncret([], [['rowid' => 1], ['rowid' => 2, 'fullcall' => dialecticEncodeActionLine('FollowupNpc' . ($funcretRun + 1), 'ExtCmdParityProbe_Report', 'Cass')]]),
];
probeCheck(array_filter($accepted, static fn(array $run): bool => !$run['followup']) === [], 'exact delivery accepted despite distractors, malformed rows and other request IDs', array_map(static fn(array $run): bool => $run['followup'], $accepted));
$blocked = [
    'default action' => $runFuncret(['action' => 'ExtCmdParityProbe_Ping']),
    'unregistered (disabled plugin)' => $runFuncret(['action' => 'ExtCmdParityProbe_Gone', 'bridge' => 'ParityProbe']),
    'failed' => $runFuncret(['status' => 'failed', 'result' => 'ExtCmdParityProbe_Report failed because timed_out.']),
    'rejected before acceptance' => $runFuncret(['status' => 'failed', 'request_id' => 0]),
    'request_id 0' => $runFuncret(['request_id' => 0]),
    'request_id string' => $runFuncret(['request_id' => '9']),
    'bridge mismatch' => $runFuncret(['bridge' => 'Other']),
    'no schema' => $runFuncret(['schema' => null]),
    'never issued' => $runFuncret(['action' => 'ExtCmdParityProbe_Report'], ['action' => 'Other']),
    'stale issue' => $runFuncret([], ['localts' => time() - 400]),
    'duplicate delivery' => $runFuncret([], [], [[]]),
    'duplicate after 12 distractors' => $runFuncret([], [], array_merge($distractors, [[]], $distractors)),
    'same request ID, other speaker' => $runFuncret([], [], [['speaker_refid' => '0x0001A2B4']]),
    'over 16 candidate rows' => $runFuncret([], [], array_fill(0, 16, $malformedRows[0])),
    'result not logged' => $runFuncret([], [], [], false),
    'wrong speaker name' => $runFuncret(['speaker' => 'Boone']),
    'wrong speaker_refid' => $runFuncret(['speaker_refid' => '0x0001A2B4']),
    'target not issued' => $runFuncret(['target' => 'Cass']),
    'issued to another NPC' => $runFuncret([], ['actorname' => 'Boone', 'fullcall' => dialecticEncodeActionLine('Boone', 'ExtCmdParityProbe_Report', 'Boone "B"')]),
];
$leaked = array_filter($blocked, static fn(array $run): bool => $run['followup'] || $run['functions'] !== 'unchanged');
probeCheck($leaked === [], 'no follow-up model call for default, unregistered, failed, unaccepted, mismatched, stale, duplicate, conflicting, over-bound, unlogged, wrong-speaker or wrong-target results', array_keys($leaked));
unset($GLOBALS['db'], $GLOBALS['DIALECTIC_CORE_CURRENT_NPC_DATA']);

probeCheck($probeWarnings === [], 'no PHP warnings or notices', $probeWarnings);
$errorLog = is_file($scratch . '/probe-error.log') ? file_get_contents($scratch . '/probe-error.log') : '';
probeCheck(str_contains($errorLog, 'fixture failure') && str_contains($errorLog, '[parity_probe] completion: completed Pong from Veronica to [Boone] talking=0'), 'plugin failure and completion were logged');

if ($scratchArg === '') {
    probeRemoveTree($scratch);
}
echo $probeFailures === 0 ? "RESULT PASS\n" : "RESULT FAIL ({$probeFailures})\n";
exit($probeFailures === 0 ? 0 : 1);
