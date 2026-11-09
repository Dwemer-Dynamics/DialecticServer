<?php

/**
 * CHIM-compatible server extension hooks for DialecticServer.
 *
 * Named hook files under ext/<plugin>/ are discovered once per request and
 * required once each, in byte-sorted path order. See docs/plugin-runtime.md.
 */

function dialecticExtensionHookNames(): array
{
    return [
        'globals.php',
        'preprocessing.php',
        'prerequest.php',
        'dialogue_prompt.php',
        'prompts.php',
        'context_building.php',
        'json_response_custom.php',
        'context_pre.php',
        'context.php',
        'prepostrequest.php',
        'postrequest.php',
    ];
}

function dialecticExtensionRoot(): string
{
    $root = $GLOBALS['DIALECTIC_EXTENSION_ROOT'] ?? (dirname(__DIR__) . DIRECTORY_SEPARATOR . 'ext');
    return rtrim(strval($root), '/\\');
}

// Built-in directories with their own explicit callers. The dialogue pipeline
// includes ext/relationship_system at its established points; the generic
// loader must not run those files a second time or at different stages.
function dialecticExtensionReservedDirectories(): array
{
    return ['relationship_system'];
}

function dialecticExtensionTopLevelEnabled(string $name, string $path): bool
{
    if (in_array(strtolower($name), dialecticExtensionReservedDirectories(), true)) {
        return false;
    }
    if (str_ends_with(strtolower($name), '.disabled')) {
        return false;
    }
    return !file_exists($path . DIRECTORY_SEPARATOR . '.disabled');
}

function dialecticExtensionScanDirectory(string $dir, array &$index, int $depth): void
{
    $entries = @scandir($dir, SCANDIR_SORT_NONE);
    if (!is_array($entries)) {
        return;
    }
    sort($entries, SORT_STRING);

    foreach ($entries as $entry) {
        // Dot entries cover ., .., hidden files and package/VCS metadata.
        if ($entry === '' || $entry[0] === '.') {
            continue;
        }
        $path = $dir . DIRECTORY_SEPARATOR . $entry;
        if (is_link($path)) {
            continue;
        }
        if (is_dir($path)) {
            if ($depth === 0 && !dialecticExtensionTopLevelEnabled($entry, $path)) {
                continue;
            }
            if (in_array(strtolower($entry), ['private', 'staging'], true) || $depth >= 6) {
                continue;
            }
            dialecticExtensionScanDirectory($path, $index, $depth + 1);
            continue;
        }
        // Files directly under ext/ are not plugins.
        if ($depth > 0 && str_ends_with($entry, '.php') && is_file($path)) {
            $index[$entry][] = $path;
        }
    }
}

/**
 * One traversal per root per request; later hook stages reuse the index.
 * A folder other than the extension root is scanned as one plugin's folder
 * (CHIM's requireFilesRecursively(__DIR__, ...)): its own files count, and it
 * is skipped when its top-level ext/ folder is disabled or reserved.
 */
function dialecticExtensionHookIndex(?string $root = null): array
{
    static $cache = [];
    $extRoot = dialecticExtensionRoot();
    $root = rtrim($root ?? $extRoot, '/\\');
    $key = realpath($root) ?: $root;
    if (!isset($cache[$key])) {
        $index = [];
        $realExt = realpath($extRoot) ?: $extRoot;
        $startDepth = $key === $realExt ? 0 : 1;
        $enabled = true;
        if ($startDepth === 1 && str_starts_with($key, $realExt . DIRECTORY_SEPARATOR)) {
            $topLevel = explode(DIRECTORY_SEPARATOR, substr($key, strlen($realExt) + 1))[0];
            $enabled = dialecticExtensionTopLevelEnabled($topLevel, $realExt . DIRECTORY_SEPARATOR . $topLevel);
        }
        if ($enabled && $root !== '' && is_dir($root) && !is_link($root)) {
            dialecticExtensionScanDirectory($root, $index, $startDepth);
        }
        $cache[$key] = $index;
    }
    return $cache[$key];
}

function dialecticExtensionRequireOnce(string $file, string $hookName): bool
{
    static $loaded = [];
    $key = realpath($file) ?: $file;
    if (isset($loaded[$key])) {
        return false;
    }
    $loaded[$key] = true;

    // Same visible scope as CHIM's requireFilesRecursively(), without creating
    // a global $gameRequest on endpoints that have none.
    $gameRequest = null;
    if (array_key_exists('gameRequest', $GLOBALS)) {
        $gameRequest = &$GLOBALS['gameRequest'];
    }
    try {
        require_once $file;
    } catch (Throwable $exception) {
        error_log('[ExtensionHooks] ' . $hookName . ' failed in ' . $file . ': ' . $exception->getMessage());
    }
    return true;
}

/**
 * Runs one named hook stage.
 *
 * @return string[] files executed by this call
 */
function dialecticRunExtensionHook(string $hookName, ?string $root = null): array
{
    $ran = [];
    foreach (dialecticExtensionHookIndex($root)[$hookName] ?? [] as $file) {
        if (dialecticExtensionRequireOnce($file, $hookName)) {
            $ran[] = $file;
        }
    }
    return $ran;
}

if (!function_exists('requireFilesRecursively')) {
    // CHIM signature. Uses the same cached index, exclusions and include-once
    // rules; a plugin's own folder also includes its directly contained files.
    function requireFilesRecursively($dir, $name)
    {
        return dialecticRunExtensionHook(strval($name), strval($dir));
    }
}

/**
 * Post-response stages for the JSON path, which exits after the response is
 * emitted. Hook output is discarded instead of reaching the client body.
 */
function dialecticRunPostResponseExtensionHooks(): void
{
    ob_start();
    try {
        dialecticRunExtensionHook('prepostrequest.php');
        dialecticRunExtensionHook('postrequest.php');
    } finally {
        ob_end_clean();
    }
}

/*
 * External actions: ExtCmd<Bridge>_<Action>. Only codes registered by a loaded
 * plugin join the canonical action set, FUNCTIONS, the response schema and
 * dialectic.action.v1 dispatch.
 */
function dialecticRegisterExtensionAction(string $codeName, string $description, array $options = []): bool
{
    $codeName = trim($codeName);
    if (strlen($codeName) > 64 || preg_match('/^ExtCmd[A-Za-z][A-Za-z0-9]*_[A-Za-z][A-Za-z0-9_]*$/', $codeName) !== 1) {
        error_log('[ExtensionActions] Rejected invalid action code: ' . substr($codeName, 0, 80));
        return false;
    }
    $description = trim(preg_replace('/\s+/', ' ', $description) ?? '');
    $target = strtolower(trim(strval($options['target'] ?? 'optional')));
    if ($description === '' || !in_array($target, ['none', 'optional', 'required'], true)) {
        error_log('[ExtensionActions] Rejected incomplete action definition: ' . $codeName);
        return false;
    }
    $followup = dialecticExtensionActionNormalizeFollowup($options['followup'] ?? null);
    if ($followup === null) {
        error_log('[ExtensionActions] Rejected invalid follow-up options: ' . $codeName);
        return false;
    }
    foreach (array_keys(dialecticExtensionActionRegistry()) as $existingCode) {
        if (strcasecmp($existingCode, $codeName) === 0 && $existingCode !== $codeName) {
            return false;
        }
    }

    $GLOBALS['DIALECTIC_EXTENSION_ACTIONS'][$codeName] = [
        'code' => $codeName,
        'description' => substr($description, 0, 400),
        'target' => $target,
        'followup' => $followup,
    ];
    return true;
}

/**
 * Optional 'followup' registration option, using the action catalog's keys.
 * Absent or disabled means no follow-up model call. Returns null when invalid.
 */
function dialecticExtensionActionNormalizeFollowup($followup): ?array
{
    if ($followup === null) {
        return [];
    }
    if (!is_array($followup) || array_diff(array_keys($followup), ['enabled', 'prompt', 'arg_name', 'use_functions_again']) !== []) {
        return null;
    }
    foreach (['enabled', 'use_functions_again'] as $flag) {
        if (array_key_exists($flag, $followup) && !is_bool($followup[$flag])) {
            return null;
        }
    }
    $prompt = $followup['prompt'] ?? '';
    $argName = $followup['arg_name'] ?? 'target';
    if (!is_string($prompt) || strlen($prompt) > 1000 || !is_string($argName)
        || preg_match('/^[A-Za-z][A-Za-z0-9_]{0,31}$/', $argName) !== 1) {
        return null;
    }
    if (($followup['enabled'] ?? false) !== true) {
        return [];
    }
    $prompt = trim(preg_replace('/\s+/', ' ', $prompt) ?? '');
    if ($prompt === '') {
        return null;
    }
    return [
        'enabled' => true,
        'prompt' => $prompt,
        'arg_name' => $argName,
        'use_functions_again' => ($followup['use_functions_again'] ?? false) === true,
    ];
}

/**
 * Catalog-shaped row for a registered action, read by the existing follow-up
 * resolver. Registration is authoritative over any catalog row with the code.
 */
function dialecticExtensionActionCatalogRow(string $codeName): ?array
{
    $spec = dialecticExtensionActionRegistry()[$codeName] ?? null;
    if (!is_array($spec)) {
        return null;
    }
    $followup = $spec['followup'] ?? [];
    return ['code_name' => $codeName, 'metadata' => $followup === [] ? [] : ['followup' => $followup]];
}

/**
 * Provenance gate for an opted-in ExtCmd follow-up, using only eventlog and
 * actions_issued. Within the last 300 seconds it needs:
 * - a completed client result for this bridge with a request ID above 0, from
 *   the bound NPC (name and speaker_refid) about the target issued to it;
 * - an actions_issued row for the code, NPC and that target;
 * - exactly one logged funcret for this action and request ID. The current
 *   event is logged before this runs, so a repeated delivery fails closed.
 * Logged rows are matched in the client's compact JSON text and decoded in PHP;
 * malformed rows never match. Too many candidates fail closed. This bounds
 * model calls; it does not authenticate the local HTTP caller.
 */
function dialecticExtensionActionFollowupAllowed(string $codeName, $payload): bool
{
    $spec = dialecticExtensionActionRegistry()[$codeName] ?? null;
    $requestId = is_array($payload) ? ($payload['request_id'] ?? null) : null;
    $speaker = trim(strval($GLOBALS['DIALECTIC_NAME'] ?? ''));
    // Form IDs as the client parses them: 0x-prefixed or 8 characters is hex, other digits decimal.
    $formId = static function ($value): int {
        $value = is_string($value) ? trim($value) : '';
        if (preg_match('/^(?:0[xX])?([0-9A-Fa-f]{1,8})$/', $value, $hex) === 1 && (stripos($value, '0x') === 0 || strlen($value) === 8)) {
            return intval(hexdec($hex[1]));
        }
        return preg_match('/^[0-9]{1,10}$/', $value) === 1 && intval($value) <= 0xFFFFFFFF ? intval($value) : 0;
    };
    $speakerRefid = $speaker === '' ? 0 : $formId(dialecticExtensionActionSpeakerRefid($speaker));
    $reason = '';
    if (!is_array($spec) || empty($spec['followup']['enabled'])) {
        $reason = 'not registered with a follow-up';
    } elseif (!is_array($payload) || ($payload['schema'] ?? '') !== 'dialectic.action_result.v1' || ($payload['status'] ?? '') !== 'completed'
        || strcasecmp(strval($payload['bridge'] ?? ''), substr(explode('_', $codeName, 2)[0], 6)) !== 0
        || !is_int($requestId) || $requestId <= 0) {
        $reason = 'result is not a completed client result';
    } elseif ($speakerRefid === 0 || !is_string($payload['speaker'] ?? null) || strcasecmp(trim($payload['speaker']), $speaker) !== 0
        || $formId($payload['speaker_refid'] ?? null) !== $speakerRefid || !is_string($payload['target'] ?? null)) {
        $reason = 'result is not from the bound NPC';
    } elseif (!isset($GLOBALS['db']) || !is_object($GLOBALS['db'])) {
        $reason = 'no database';
    } else {
        $since = time() - 300;
        $limit = 16;
        // The client reports the issued parameter, or the speaker when there is none, with @, | and whitespace runs collapsed.
        $label = static fn(string $value): string => trim(preg_replace('/[\s@|]+/', ' ', $value) ?? '');
        $issued = $GLOBALS['db']->fetchOne(
            "WITH c AS (SELECT rowid, fullcall FROM actions_issued WHERE action = $1 AND localts >= $2 AND lower(actorname) IN (lower($3), '*'))
            SELECT (SELECT COUNT(*) FROM c) AS candidates, (SELECT json_agg(fullcall ORDER BY rowid DESC) FROM (SELECT rowid, fullcall FROM c ORDER BY rowid DESC LIMIT {$limit}) s) AS rows",
            [$codeName, $since, $speaker]
        );
        $issuedMatch = false;
        require_once __DIR__ . DIRECTORY_SEPARATOR . 'dialectic_command_payload.php';
        foreach (json_decode(strval($issued['rows'] ?? ''), true) ?: [] as $fullcall) {
            $line = is_string($fullcall) ? dialecticDecodeActionLine($fullcall) : [];
            $parameter = strval($line['parameter_string'] ?? '');
            if (($line['action'] ?? '') === $codeName && strcasecmp(strval($line['actor'] ?? ''), $speaker) === 0
                && $label($payload['target']) === $label($parameter === '' ? $speaker : $parameter)) {
                $issuedMatch = true;
                break;
            }
        }
        if (!$issuedMatch) {
            $reason = intval($issued['candidates'] ?? 0) > $limit ? 'too many recent issued actions' : 'no matching issued action';
        } else {
            // Candidates by text; deliveries are counted only after decoding.
            $logged = $GLOBALS['db']->fetchOne(
                "WITH c AS (SELECT rowid, data FROM eventlog WHERE type = 'funcret' AND localts >= $1 AND strpos(data, $2) > 0 AND (strpos(data, $3) > 0 OR strpos(data, $4) > 0))
                SELECT (SELECT COUNT(*) FROM c) AS candidates, (SELECT json_agg(data ORDER BY rowid) FROM (SELECT rowid, data FROM c ORDER BY rowid LIMIT {$limit}) s) AS rows",
                [$since, "\"action\":\"{$codeName}\"", "\"request_id\":{$requestId}}", "\"request_id\":{$requestId},"]
            );
            $candidates = intval($logged['candidates'] ?? 0);
            $deliveries = [];
            foreach (json_decode(strval($logged['rows'] ?? ''), true) ?: [] as $data) {
                $row = is_string($data) ? json_decode($data, true) : null;
                if (is_array($row) && ($row['action'] ?? null) === $codeName && ($row['request_id'] ?? null) === $requestId) {
                    $deliveries[] = $row;
                }
            }
            if ($candidates > $limit) {
                $reason = "{$candidates} logged candidates for request {$requestId}";
            } elseif (count($deliveries) !== 1) {
                $reason = count($deliveries) . " logged deliveries of request {$requestId}";
            } elseif (($deliveries[0]['speaker_refid'] ?? null) !== $payload['speaker_refid'] || ($deliveries[0]['target'] ?? null) !== $payload['target']) {
                $reason = "logged delivery of request {$requestId} does not match this result";
            }
        }
    }
    if ($reason !== '') {
        error_log("[ExtensionActions] No follow-up for {$codeName}: {$reason}");
        return false;
    }
    return true;
}

function dialecticExtensionActionRegistry(): array
{
    $registry = $GLOBALS['DIALECTIC_EXTENSION_ACTIONS'] ?? [];
    return is_array($registry) ? $registry : [];
}

function dialecticExtensionActionFunctionEntry(array $spec): array
{
    $properties = [];
    if ($spec['target'] !== 'none') {
        $properties['target'] = [
            'type' => 'string',
            'description' => 'Target actor or argument passed to the client bridge',
        ];
    }
    return [
        'name' => $spec['code'],
        'description' => $spec['description'],
        'parameters' => [
            'type' => 'object',
            'properties' => $properties,
            'required' => $spec['target'] === 'required' ? ['target'] : [],
        ],
    ];
}

/**
 * Called by functions/functions.php after the canonical filter. The model
 * sees the code name itself; no display alias can shadow a native action.
 */
function dialecticApplyExtensionActionsToRuntimeFunctions(): void
{
    foreach (dialecticExtensionActionRegistry() as $codeName => $spec) {
        $GLOBALS['F_NAMES'][$codeName] = $codeName;
        $GLOBALS['F_DESCRIPTIONS'][$codeName] = $spec['description'];
        $GLOBALS['FUNCTIONS'][] = dialecticExtensionActionFunctionEntry($spec);
        if (!in_array($codeName, $GLOBALS['ENABLED_FUNCTIONS'] ?? [], true)) {
            $GLOBALS['ENABLED_FUNCTIONS'][] = $codeName;
        }
    }
}

/**
 * The client dispatches ExtCmd lines only to an exact speaker reference and
 * never resolves the speaker by name. Returns '' when the speaker is not the
 * active NPC with a known reference, so the client rejects the command.
 */
function dialecticExtensionActionSpeakerRefid(string $speaker): string
{
    $speaker = trim($speaker);
    if ($speaker === '' || strcasecmp($speaker, trim(strval($GLOBALS['DIALECTIC_NAME'] ?? ''))) !== 0) {
        return '';
    }
    $responseFormId = trim(strval($GLOBALS['DIALECTIC_RESPONSE_SPEAKER_FORMID'] ?? ''));
    if ($responseFormId !== '') {
        return $responseFormId;
    }
    $npcData = $GLOBALS['DIALECTIC_CORE_CURRENT_NPC_DATA'] ?? null;
    if (is_array($npcData) && strcasecmp(trim(strval($npcData['npc_name'] ?? '')), $speaker) === 0) {
        return trim(strval($npcData['refid'] ?? ''));
    }
    return '';
}
