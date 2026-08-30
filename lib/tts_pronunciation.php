<?php

// Provide Fallout pronunciations when the database has not been migrated yet.
function dialecticDefaultTtsPronunciationEntries(): array
{
    return [
        ['source_text' => 'Caesar', 'spoken_text' => 'Kaiser', 'oghma_tags' => 'caesars_legion'],
        ['source_text' => 'Mojave', 'spoken_text' => 'Mo-hah-vee', 'oghma_tags' => ''],
        ['source_text' => 'Novac', 'spoken_text' => 'No-vack', 'oghma_tags' => ''],
        ['source_text' => 'NCR', 'spoken_text' => 'N C R', 'oghma_tags' => ''],
        ['source_text' => 'ED-E', 'spoken_text' => 'Eddie', 'oghma_tags' => ''],
        ['source_text' => 'Mr. House', 'spoken_text' => 'Mister House', 'oghma_tags' => ''],
    ];
}

// Create the dictionary table and add missing built-in entries without changing user data.
function dialecticEnsureTtsPronunciationDictionary(): bool
{
    if (!isset($GLOBALS['db']) || !$GLOBALS['db']) {
        return false;
    }

    $schemaPath = __DIR__ . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR
        . 'database_schema' . DIRECTORY_SEPARATOR . 'core_tts_pronunciation.sql';
    if (!is_readable($schemaPath) || $GLOBALS['db']->execQuery(file_get_contents($schemaPath)) === false) {
        return false;
    }

    foreach (dialecticDefaultTtsPronunciationEntries() as $entry) {
        $source = $GLOBALS['db']->escapeLiteral($entry['source_text']);
        $spoken = $GLOBALS['db']->escapeLiteral($entry['spoken_text']);
        $tags = $GLOBALS['db']->escapeLiteral($entry['oghma_tags']);
        $inserted = $GLOBALS['db']->execQuery(
            "INSERT INTO public.core_tts_pronunciation
                (source_text, spoken_text, npc_names, races, oghma_tags, is_builtin, enabled, updated_at)
             VALUES ({$source}, {$spoken}, '', '', {$tags}, TRUE, TRUE, CURRENT_TIMESTAMP)
             ON CONFLICT DO NOTHING"
        );
        if ($inserted === false) {
            return false;
        }
    }

    return true;
}

final class DialecticTtsPronunciationDictionary
{
    private const TABLE = 'core_tts_pronunciation';
    private ?bool $available = null;

    public function isAvailable(): bool
    {
        if ($this->available !== null) {
            return $this->available;
        }
        if (!isset($GLOBALS['db']) || !$GLOBALS['db']
            || !method_exists($GLOBALS['db'], 'escapeLiteral')
            || !method_exists($GLOBALS['db'], 'fetchOne')) {
            return $this->available = false;
        }

        $table = $GLOBALS['db']->escapeLiteral(self::TABLE);
        $row = $GLOBALS['db']->fetchOne(
            "SELECT 1 AS present
             FROM information_schema.tables
             WHERE table_schema = 'public' AND table_name = {$table}
             LIMIT 1"
        );

        return $this->available = is_array($row) && intval($row['present'] ?? 0) === 1;
    }

    public function getRows(string $tagFilter = ''): array
    {
        if (!$this->isAvailable()) {
            return array_map(static function (array $entry): array {
                return $entry + [
                    'id' => 0,
                    'npc_names' => '',
                    'races' => '',
                    'is_builtin' => true,
                    'enabled' => true,
                ];
            }, dialecticDefaultTtsPronunciationEntries());
        }

        $rows = $GLOBALS['db']->fetchAll(
            'SELECT id, source_text, spoken_text, npc_names, races, oghma_tags,
                    is_builtin, enabled, created_at, updated_at
             FROM public.' . self::TABLE . '
             ORDER BY is_builtin DESC, LOWER(source_text), id
             LIMIT 1024'
        );
        $rows = is_array($rows) ? $rows : [];
        $tagFilter = strtolower(trim($tagFilter));
        if ($tagFilter === '') {
            return $rows;
        }

        return array_values(array_filter($rows, static function (array $row) use ($tagFilter): bool {
            if (dialecticTtsPronunciationBoolean($row['is_builtin'] ?? false)) {
                return true;
            }
            return in_array($tagFilter, dialecticTtsPronunciationNormalizeTags($row['oghma_tags'] ?? ''), true);
        }));
    }

    public function getAvailableTags(): array
    {
        $tags = [];
        foreach ($this->getRows() as $row) {
            if (dialecticTtsPronunciationBoolean($row['is_builtin'] ?? false)) {
                continue;
            }
            foreach (dialecticTtsPronunciationNormalizeTags($row['oghma_tags'] ?? '') as $tag) {
                $tags[$tag] = $tag;
            }
        }
        natcasesort($tags);
        return array_values($tags);
    }

    public function saveCustom(
        ?int $id,
        string $source,
        string $spoken,
        string $npcNames,
        string $races,
        string $oghmaTags,
        bool $enabled
    ): bool
    {
        if (!$this->isAvailable()) {
            return false;
        }

        $source = trim($source);
        $spoken = trim($spoken);
        if ($source === '' || $spoken === '' || strlen($source) > 120 || strlen($spoken) > 240) {
            return false;
        }

        $normalizedNames = implode(', ', array_slice(dialecticTtsPronunciationNormalizeScopeValues($npcNames), 0, 32));
        $normalizedRaces = implode(', ', array_slice(dialecticTtsPronunciationNormalizeScopeValues($races), 0, 32));
        $normalizedTags = implode(', ', array_slice(dialecticTtsPronunciationNormalizeTags($oghmaTags), 0, 32));
        $sourceValue = $GLOBALS['db']->escapeLiteral($source);
        $spokenValue = $GLOBALS['db']->escapeLiteral($spoken);
        $namesValue = $GLOBALS['db']->escapeLiteral(substr($normalizedNames, 0, 512));
        $racesValue = $GLOBALS['db']->escapeLiteral(substr($normalizedRaces, 0, 512));
        $tagsValue = $GLOBALS['db']->escapeLiteral(substr($normalizedTags, 0, 512));
        $enabledValue = $enabled ? 'TRUE' : 'FALSE';

        if ($id !== null && $id > 0) {
            return $GLOBALS['db']->execQuery(
                "UPDATE public." . self::TABLE . "
                 SET source_text = {$sourceValue}, spoken_text = {$spokenValue},
                     npc_names = {$namesValue}, races = {$racesValue}, oghma_tags = {$tagsValue},
                     enabled = {$enabledValue}, updated_at = CURRENT_TIMESTAMP
                 WHERE id = " . intval($id) . " AND is_builtin = FALSE"
            ) !== false;
        }

        return $GLOBALS['db']->execQuery(
            "INSERT INTO public." . self::TABLE . "
                (source_text, spoken_text, npc_names, races, oghma_tags, is_builtin, enabled, updated_at)
             VALUES ({$sourceValue}, {$spokenValue}, {$namesValue}, {$racesValue}, {$tagsValue},
                     FALSE, {$enabledValue}, CURRENT_TIMESTAMP)"
        ) !== false;
    }

    public function setEnabled(int $id, bool $enabled): bool
    {
        if ($id <= 0 || !$this->isAvailable()) {
            return false;
        }

        return $GLOBALS['db']->execQuery(
            'UPDATE public.' . self::TABLE . '
             SET enabled = ' . ($enabled ? 'TRUE' : 'FALSE') . ', updated_at = CURRENT_TIMESTAMP
             WHERE id = ' . intval($id)
        ) !== false;
    }

    public function deleteCustom(int $id): bool
    {
        if ($id <= 0 || !$this->isAvailable()) {
            return false;
        }

        return $GLOBALS['db']->execQuery(
            'DELETE FROM public.' . self::TABLE . '
             WHERE id = ' . intval($id) . ' AND is_builtin = FALSE'
        ) !== false;
    }
}

function dialecticTtsPronunciationBoolean($value): bool
{
    if (is_bool($value)) {
        return $value;
    }
    return in_array(strtolower(trim(strval($value))), ['1', 't', 'true', 'yes', 'on'], true);
}

function dialecticTtsPronunciationNormalizeTags($tags): array
{
    $normalized = [];
    foreach (explode(',', strval($tags)) as $tag) {
        $tag = strtolower(trim($tag));
        if ($tag === '' || strlen($tag) > 64) {
            continue;
        }
        $normalized[$tag] = $tag;
    }
    return array_values($normalized);
}

function dialecticTtsPronunciationNormalizeScopeValues($values): array
{
    $normalized = [];
    foreach (explode(',', strval($values)) as $value) {
        $value = trim($value);
        if ($value === '' || strlen($value) > 120) {
            continue;
        }
        $key = function_exists('mb_strtolower')
            ? mb_strtolower($value, 'UTF-8')
            : strtolower($value);
        $normalized[$key] = $value;
    }
    return array_values($normalized);
}

function dialecticTtsPronunciationValueMatches(string $value, array $allowedValues): bool
{
    $value = function_exists('mb_strtolower')
        ? mb_strtolower(trim($value), 'UTF-8')
        : strtolower(trim($value));
    if ($value === '') {
        return false;
    }

    foreach ($allowedValues as $allowedValue) {
        $allowedValue = function_exists('mb_strtolower')
            ? mb_strtolower(trim(strval($allowedValue)), 'UTF-8')
            : strtolower(trim(strval($allowedValue)));
        if ($value === $allowedValue) {
            return true;
        }
    }
    return false;
}

// Require every populated speaker filter while allowing alternatives within each filter.
function dialecticTtsPronunciationEntryAllows(
    array $entry,
    ?array $knowledgeTags = null,
    string $npcName = '',
    string $race = ''
): bool
{
    $entryNames = dialecticTtsPronunciationNormalizeScopeValues($entry['npc_names'] ?? '');
    if (!empty($entryNames) && !dialecticTtsPronunciationValueMatches($npcName, $entryNames)) {
        return false;
    }

    $entryRaces = dialecticTtsPronunciationNormalizeScopeValues($entry['races'] ?? '');
    if (!empty($entryRaces) && !dialecticTtsPronunciationValueMatches($race, $entryRaces)) {
        return false;
    }

    $entryTags = dialecticTtsPronunciationNormalizeTags($entry['oghma_tags'] ?? '');
    if (empty($entryTags)) {
        return true;
    }

    $knowledgeTags = array_values(array_unique(array_map(
        static fn($tag): string => strtolower(trim(strval($tag))),
        $knowledgeTags ?? []
    )));

    return in_array('knowall', $knowledgeTags, true)
        || !empty(array_intersect($entryTags, $knowledgeTags));
}

// Resolve active rows with custom scoped entries taking priority over global defaults.
function dialecticTtsPronunciationEntries(
    ?array $rows = null,
    ?array $knowledgeTags = null,
    string $npcName = '',
    string $race = ''
): array
{
    if ($rows === null) {
        static $cachedRows = null;
        if ($cachedRows === null) {
            $cachedRows = (new DialecticTtsPronunciationDictionary())->getRows();
        }
        $rows = $cachedRows;
    }

    $resolved = [];
    foreach (array_slice($rows, 0, 1024) as $row) {
        if (!dialecticTtsPronunciationBoolean($row['enabled'] ?? true)
            || !dialecticTtsPronunciationEntryAllows($row, $knowledgeTags, $npcName, $race)) {
            continue;
        }

        $source = trim(strval($row['source_text'] ?? $row['source'] ?? ''));
        $spoken = trim(strval($row['spoken_text'] ?? $row['spoken'] ?? ''));
        if ($source === '' || $spoken === '' || strlen($source) > 120 || strlen($spoken) > 240) {
            continue;
        }

        $normalizedSource = function_exists('mb_strtolower')
            ? mb_strtolower($source, 'UTF-8')
            : strtolower($source);
        $isBuiltin = dialecticTtsPronunciationBoolean($row['is_builtin'] ?? false);
        $specificity = 0;
        $specificity += !empty(dialecticTtsPronunciationNormalizeScopeValues($row['npc_names'] ?? '')) ? 1 : 0;
        $specificity += !empty(dialecticTtsPronunciationNormalizeScopeValues($row['races'] ?? '')) ? 1 : 0;
        $specificity += !empty(dialecticTtsPronunciationNormalizeTags($row['oghma_tags'] ?? '')) ? 1 : 0;
        $priority = ($isBuiltin ? 0 : 10) + $specificity;
        if (isset($resolved[$normalizedSource]) && $resolved[$normalizedSource]['priority'] > $priority) {
            continue;
        }

        $resolved[$normalizedSource] = [
            'source' => $source,
            'spoken' => $spoken,
            'priority' => $priority,
        ];
    }

    $entries = array_values($resolved);
    usort($entries, static function (array $left, array $right): int {
        return strlen($right['source']) <=> strlen($left['source']);
    });
    return array_slice($entries, 0, 256);
}

function dialecticApplyTtsPronunciationDictionary(
    string $text,
    ?array $rows = null,
    ?array $knowledgeTags = null,
    string $npcName = '',
    string $race = ''
): string {
    if ($text === '' || !empty($GLOBALS['DIALECTIC_TTS_PRONUNCIATION_BYPASS'])) {
        return $text;
    }

    $entries = dialecticTtsPronunciationEntries($rows, $knowledgeTags, $npcName, $race);
    if (empty($entries)) {
        return $text;
    }

    $replacements = [];
    $patterns = [];
    foreach ($entries as $entry) {
        $source = strval($entry['source'] ?? '');
        if ($source === '') {
            continue;
        }
        $normalized = function_exists('mb_strtolower')
            ? mb_strtolower($source, 'UTF-8')
            : strtolower($source);
        $replacements[$normalized] = strval($entry['spoken'] ?? '');
        $patterns[] = preg_quote($source, '~');
    }
    if (empty($patterns)) {
        return $text;
    }

    $pattern = '~(?<![\p{L}\p{N}_])(?:' . implode('|', $patterns) . ')(?![\p{L}\p{N}_])~iu';
    $replaced = preg_replace_callback($pattern, static function (array $match) use ($replacements): string {
        $matched = strval($match[0] ?? '');
        $normalized = function_exists('mb_strtolower')
            ? mb_strtolower($matched, 'UTF-8')
            : strtolower($matched);
        return $replacements[$normalized] ?? $matched;
    }, $text);

    return is_string($replaced) ? $replaced : $text;
}
