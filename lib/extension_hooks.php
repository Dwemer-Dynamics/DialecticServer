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
 */
function dialecticExtensionHookIndex(?string $root = null): array
{
    static $cache = [];
    $root = rtrim($root ?? dialecticExtensionRoot(), '/\\');
    $key = realpath($root) ?: $root;
    if (!isset($cache[$key])) {
        $index = [];
        if ($root !== '' && is_dir($root) && !is_link($root)) {
            dialecticExtensionScanDirectory($root, $index, 0);
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
    // CHIM signature. Uses the same cached index, exclusions and include-once rules.
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
    if (strlen($codeName) > 64 || preg_match('/^ExtCmd[A-Za-z][A-Za-z0-9]*_[A-Za-z][A-Za-z0-9]*$/', $codeName) !== 1) {
        error_log('[ExtensionActions] Rejected invalid action code: ' . substr($codeName, 0, 80));
        return false;
    }
    $description = trim(preg_replace('/\s+/', ' ', $description) ?? '');
    $target = strtolower(trim(strval($options['target'] ?? 'optional')));
    if ($description === '' || !in_array($target, ['none', 'optional', 'required'], true)) {
        error_log('[ExtensionActions] Rejected incomplete action definition: ' . $codeName);
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
    ];
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
