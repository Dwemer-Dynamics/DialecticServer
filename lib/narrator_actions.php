<?php

require_once __DIR__ . DIRECTORY_SEPARATOR . 'dialectic_command_payload.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'game_plugins.php';

function dialecticNarratorActionRequestIsActive(): bool
{
    if (!empty($GLOBALS['DIRECT_NARRATOR_DIALOGUE'])) {
        return true;
    }

    $requestType = strtolower(trim(strval($GLOBALS['gameRequest'][0] ?? '')));
    return in_array($requestType, ['narrator_inputtext', 'narration', 'narrator_welcome', 'narrator_quest_comment'], true);
}

function dialecticNarratorActionParameterArray(array $decodedAction): array
{
    $parameter = $decodedAction['parameter'] ?? null;
    if (is_array($parameter)) {
        return $parameter;
    }

    $parameterString = trim(strval($decodedAction['parameter_string'] ?? ''));
    if ($parameterString !== '' && $parameterString[0] === '{') {
        $decoded = json_decode($parameterString, true);
        if (is_array($decoded)) {
            return $decoded;
        }
    }

    if ($parameterString !== '') {
        return ['target' => $parameterString];
    }

    return [];
}

function dialecticNarratorPlayerName(): string
{
    $playerName = trim(strval($GLOBALS['PLAYER_NAME'] ?? ''));
    return $playerName !== '' ? $playerName : 'Player';
}

function dialecticNarratorTargetIsPlayer($target): bool
{
    $target = strtolower(trim(strval($target)));
    if ($target === '') {
        return true;
    }

    if (in_array($target, ['player', '#player_name#', 'me', 'courier', 'lone wanderer'], true)) {
        return true;
    }

    return $target === strtolower(dialecticNarratorPlayerName());
}

function dialecticNarratorNormalizeTarget($target, bool $defaultToPlayer = true): array
{
    $target = trim(strval($target));
    if (($target === '' && $defaultToPlayer) || dialecticNarratorTargetIsPlayer($target)) {
        return [
            'name' => dialecticNarratorPlayerName(),
            'refid' => '0x00000014',
            'is_player' => true,
        ];
    }

    return [
        'name' => $target,
        'refid' => '',
        'is_player' => false,
    ];
}

function dialecticNarratorClampAmount($value, int $default, int $maximum): int
{
    if (!is_numeric($value)) {
        return $default;
    }

    return max(1, min($maximum, intval(round(floatval($value)))));
}

function dialecticNarratorResolveItem(string $requestedName): ?array
{
    $requestedName = trim($requestedName);
    if ($requestedName === '' || !isset($GLOBALS['db'])) {
        return null;
    }

    $db = $GLOBALS['db'];
    $escapedName = method_exists($db, 'escape')
        ? $db->escape($requestedName)
        : str_replace("'", "''", $requestedName);

    try {
        $rows = $db->fetchAll(
            "SELECT plugin, baseid, name, " .
            "CASE WHEN lower(COALESCE(name, '')) = lower('{$escapedName}') THEN 1.0 " .
            "ELSE similarity(COALESCE(name, ''), '{$escapedName}') END AS score, " .
            "CASE WHEN lower(COALESCE(name, '')) = lower('{$escapedName}') THEN 1 ELSE 0 END AS exact_match " .
            "FROM public.combined_descriptions " .
            "WHERE COALESCE(name, '') <> '' " .
            "ORDER BY exact_match DESC, score DESC, name ASC LIMIT 2"
        );
    } catch (Throwable $e) {
        if (class_exists('Logger')) {
            Logger::warn('[narrator-actions] Item lookup failed: ' . $e->getMessage());
        }
        return null;
    }

    if (!is_array($rows) || count($rows) === 0) {
        return null;
    }

    $best = $rows[0];
    $exact = !empty($best['exact_match']);
    $score = floatval($best['score'] ?? 0);
    if (!$exact && $score < 0.30) {
        return null;
    }

    if (!$exact && isset($rows[1])) {
        $secondScore = floatval($rows[1]['score'] ?? 0);
        if ($secondScore > 0 && abs($score - $secondScore) < 0.03) {
            return null;
        }
    }

    $plugin = dialecticNormalizePluginName($best['plugin'] ?? '');
    $baseId = dialecticNormalizeLocalFormId($best['baseid'] ?? '');
    $stableKey = dialecticBuildStableFormReference($plugin, $baseId);
    $runtimeFormId = $stableKey !== ''
        ? dialecticResolveStableFormReferenceToRuntimeFormId($stableKey)
        : dialecticNormalizeRuntimeFormId($best['baseid'] ?? '');

    if ($runtimeFormId === null || $runtimeFormId === '') {
        return null;
    }

    return [
        'name' => trim(strval($best['name'] ?? $requestedName)),
        'plugin' => $plugin,
        'local_formid' => $baseId,
        'stable_key' => $stableKey,
        'runtime_formid' => '0x' . strtoupper($runtimeFormId),
    ];
}

function dialecticNarratorActionFailure(string $action, string $reason): void
{
    if (class_exists('Logger')) {
        Logger::warn('[narrator-actions] Rejected narrator action' . Logger::formatContext([
            'action' => $action,
            'reason' => $reason,
        ]));
    }

    if (function_exists('dialectic_buffer_command_response_line')) {
        $command = dialecticEncodeCommandAction('DebugNotification', [
            'message' => "Narrator action {$action} failed: {$reason}.",
        ]);
        dialectic_buffer_command_response_line('The Narrator', $command, [
            'message' => "Narrator action {$action} failed: {$reason}.",
        ]);
    }
}

function dialecticPrepareNarratorPluginAction(string $action, array $parameters): ?array
{
    $parameters['action_source'] = 'narrator';
    $parameters['authority'] = 'narrator';

    if ($action === 'ReadQuests') {
        return $parameters;
    }

    if ($action === 'SpawnCaps') {
        $target = dialecticNarratorNormalizeTarget($parameters['target'] ?? '', true);
        $parameters['target'] = $target['name'];
        if ($target['refid'] !== '') {
            $parameters['target_refid'] = $target['refid'];
        }
        $parameters['amount'] = dialecticNarratorClampAmount($parameters['amount'] ?? null, 1, 1000000);
        return $parameters;
    }

    if ($action === 'SpawnItem') {
        $item = dialecticNarratorResolveItem(trim(strval($parameters['item'] ?? '')));
        if ($item === null) {
            dialecticNarratorActionFailure($action, 'item could not be resolved uniquely from World Descriptions');
            return null;
        }

        $target = dialecticNarratorNormalizeTarget($parameters['target'] ?? '', true);
        $parameters['target'] = $target['name'];
        if ($target['refid'] !== '') {
            $parameters['target_refid'] = $target['refid'];
        }
        $parameters['item'] = $item['name'];
        $parameters['item_baseid'] = $item['runtime_formid'];
        $parameters['item_plugin'] = $item['plugin'];
        $parameters['item_stable_key'] = $item['stable_key'];
        $parameters['amount'] = dialecticNarratorClampAmount($parameters['amount'] ?? null, 1, 100);
        return $parameters;
    }

    if ($action === 'TeleportActor') {
        $destinationName = trim(strval($parameters['location'] ?? ($parameters['item'] ?? '')));
        $destination = function_exists('dialecticResolveLocationForTravelAction')
            ? dialecticResolveLocationForTravelAction($destinationName)
            : null;
        if (!is_array($destination)) {
            dialecticNarratorActionFailure($action, 'destination could not be resolved from synchronized locations');
            return null;
        }

        $target = dialecticNarratorNormalizeTarget($parameters['target'] ?? '', true);
        $parameters['target'] = $target['name'];
        if ($target['refid'] !== '') {
            $parameters['target_refid'] = $target['refid'];
        }
        $parameters['location'] = $destination['name'];
        $parameters['location_refid'] = $destination['formid_hex'];
        return $parameters;
    }

    if ($action === 'KillTarget') {
        $target = dialecticNarratorNormalizeTarget($parameters['target'] ?? '', false);
        if ($target['name'] === '') {
            dialecticNarratorActionFailure($action, 'target is required');
            return null;
        }
        if ($target['is_player']) {
            dialecticNarratorActionFailure($action, 'the player is protected');
            return null;
        }
        $parameters['target'] = $target['name'];
        return $parameters;
    }

    return null;
}

function dialecticInvokeNarratorRolemasterInstruction(string $instruction): bool
{
    if (!function_exists('exec')) {
        return false;
    }

    $managerPath = realpath(dirname(__DIR__) . DIRECTORY_SEPARATOR . 'service' . DIRECTORY_SEPARATOR . 'manager.php');
    if ($managerPath === false || !is_file($managerPath)) {
        return false;
    }

    $phpCandidates = [];
    $phpBinDir = rtrim(strval(defined('PHP_BINDIR') ? PHP_BINDIR : ''), "/\\");
    if ($phpBinDir !== '') {
        $phpCandidates[] = $phpBinDir . DIRECTORY_SEPARATOR . (stripos(PHP_OS, 'WIN') === 0 ? 'php.exe' : 'php');
    }

    $phpBinary = trim(strval(defined('PHP_BINARY') ? PHP_BINARY : ''));
    if ($phpBinary !== '' && strpos(strtolower(basename(str_replace('\\', '/', $phpBinary))), 'php') === 0) {
        $phpCandidates[] = $phpBinary;
    }
    $phpCandidates[] = stripos(PHP_OS, 'WIN') === 0 ? 'php.exe' : 'php';

    $attempts = [];
    foreach (array_values(array_unique($phpCandidates)) as $candidate) {
        $output = [];
        $returnCode = 0;
        $command = escapeshellarg($candidate) . ' ' . escapeshellarg($managerPath)
            . ' rolemaster instruction ' . escapeshellarg($instruction) . ' notify 2>&1';
        exec($command, $output, $returnCode);
        if ($returnCode === 0) {
            return true;
        }
        $attempts[] = 'php=' . $candidate . ' rc=' . $returnCode . ' output=' . implode(' || ', $output);
    }

    if (class_exists('Logger')) {
        Logger::error('[narrator-actions] Director rolemaster launch failed: ' . implode(' ### ', $attempts));
    }
    return false;
}

function dialecticExecuteNarratorDirectorCommand(array $parameters): bool
{
    $instruction = trim(strval($parameters['instruction'] ?? ($parameters['target'] ?? '')));
    if ($instruction === '') {
        dialecticNarratorActionFailure('DirectorCommand', 'instruction is required');
        return false;
    }

    if (!dialecticInvokeNarratorRolemasterInstruction($instruction)) {
        dialecticNarratorActionFailure('DirectorCommand', 'director service returned an error');
        return false;
    }

    if (isset($GLOBALS['db'], $GLOBALS['gameRequest']) && is_object($GLOBALS['db'])) {
        $GLOBALS['db']->insert('actions_issued', [
            'action' => 'DirectorCommand',
            'fullcall' => dialecticEncodeActionLine('The Narrator', 'DirectorCommand', $parameters),
            'actorname' => 'The Narrator',
            'ts' => $GLOBALS['gameRequest'][1] ?? time(),
            'gamets' => $GLOBALS['gameRequest'][2] ?? 0,
            'localts' => time(),
            'original' => $instruction,
        ]);
    }

    return true;
}

function dialecticPostProcessNarratorActions(array $actions): array
{
    if (!dialecticNarratorActionRequestIsActive()) {
        return $actions;
    }

    $processed = [];
    foreach ($actions as $key => $actionLine) {
        $decoded = dialecticDecodeActionLine(strval($actionLine));
        $action = trim(strval($decoded['action'] ?? ''));
        if ($action === '') {
            $processed[$key] = $actionLine;
            continue;
        }

        $parameters = dialecticNarratorActionParameterArray($decoded);
        if ($action === 'DirectorCommand') {
            dialecticExecuteNarratorDirectorCommand($parameters);
            continue;
        }

        if (!in_array($action, ['ReadQuests', 'SpawnCaps', 'SpawnItem', 'TeleportActor', 'KillTarget'], true)) {
            $processed[$key] = $actionLine;
            continue;
        }

        $prepared = dialecticPrepareNarratorPluginAction($action, $parameters);
        if ($prepared === null) {
            continue;
        }

        $actor = trim(strval($decoded['actor'] ?? ''));
        if ($actor === '' || strcasecmp($actor, 'The Narrator') !== 0) {
            $actor = 'The Narrator';
        }
        $processed[$key] = dialecticEncodeActionLine($actor, $action, $prepared);
    }

    return $processed;
}
