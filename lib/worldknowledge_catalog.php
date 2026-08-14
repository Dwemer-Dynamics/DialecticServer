<?php

declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'worldknowledge_topic.php';

const DIALECTIC_WORLDKNOWLEDGE_CATALOG_FIELDS = [
    'topic',
    'topic_desc',
    'knowledge_class',
    'topic_desc_basic',
    'knowledge_class_basic',
    'tags',
    'category',
    'setting',
    'region',
    'valid_from_year',
    'valid_to_year',
    'source_url',
    'source_revision',
    'editorial_note',
];

const DIALECTIC_WORLDKNOWLEDGE_CATEGORIES = [
    'artifact', 'armor', 'concept', 'creature', 'culture', 'event', 'faction',
    'flora', 'food_drink', 'history', 'item', 'location', 'medicine', 'organization',
    'person', 'robot', 'technology', 'vault', 'weapon',
];

const DIALECTIC_WORLDKNOWLEDGE_CLASSES = [
    'common', 'capital_wasteland', 'mojave', 'wastelander', 'historian', 'scientist',
    'doctor', 'engineer', 'merchant', 'caravaner', 'tribal', 'vault_dweller', 'ghoul',
    'super_mutant', 'robot', 'military', 'ncr', 'legion', 'brotherhood', 'enclave',
    'followers', 'great_khan', 'boomers', 'powder_ganger', 'raider', 'courier',
];

const DIALECTIC_WORLDKNOWLEDGE_ACCESS_NAMESPACES = [
    'person', 'region', 'community', 'place', 'faction', 'role', 'domain', 'race',
];

/** Load and checksum-validate the shipped factory catalog without touching the database. */
function dialecticWorldKnowledgeLoadFactoryCatalog(string $rootPath): array
{
    $dataPath = rtrim($rootPath, '/\\') . DIRECTORY_SEPARATOR . 'data';
    $manifestPath = $dataPath . DIRECTORY_SEPARATOR . 'fallout_worldknowledge_manifest.json';
    if (!is_readable($manifestPath)) {
        throw new RuntimeException("World Knowledge manifest is not readable: {$manifestPath}");
    }
    $manifest = json_decode(strval(file_get_contents($manifestPath)), true, 512, JSON_THROW_ON_ERROR);
    if (!is_array($manifest) || array_is_list($manifest)) {
        throw new RuntimeException('World Knowledge manifest must be a JSON object');
    }
    foreach (['catalog_id', 'catalog_version', 'display_name', 'csv_file', 'checksum_sha256', 'row_count'] as $field) {
        if (!array_key_exists($field, $manifest) || trim(strval($manifest[$field])) === '') {
            throw new RuntimeException("World Knowledge manifest is missing {$field}");
        }
    }
    if (strval($manifest['schema'] ?? '') !== 'dialectic.worldknowledge-catalog.v1'
        || strval($manifest['editorial_review']['status'] ?? '') !== 'approved') {
        throw new RuntimeException('World Knowledge manifest is not an approved parity-v1 catalog');
    }
    $catalogId = trim(strval($manifest['catalog_id']));
    $catalogVersion = trim(strval($manifest['catalog_version']));
    if (!preg_match('/^[a-z0-9][a-z0-9._-]{2,100}$/', $catalogId)
        || !preg_match('/^[a-z0-9][a-z0-9._-]{0,100}$/', $catalogVersion)) {
        throw new RuntimeException('World Knowledge catalog identity contains unsupported characters');
    }
    $csvFile = basename(strval($manifest['csv_file']));
    $csvPath = $dataPath . DIRECTORY_SEPARATOR . $csvFile;
    if (!is_readable($csvPath)) {
        throw new RuntimeException("World Knowledge catalog is not readable: {$csvPath}");
    }
    $actualChecksum = hash_file('sha256', $csvPath);
    if (!is_string($actualChecksum) || !hash_equals(strtolower(strval($manifest['checksum_sha256'])), $actualChecksum)) {
        throw new RuntimeException('World Knowledge catalog checksum does not match its manifest');
    }

    $handle = fopen($csvPath, 'rb');
    if ($handle === false) {
        throw new RuntimeException("Unable to open World Knowledge catalog: {$csvPath}");
    }
    $rows = [];
    $seenTopics = [];
    try {
        $header = fgetcsv($handle, 0, ',', '"', '\\');
        if ($header !== DIALECTIC_WORLDKNOWLEDGE_CATALOG_FIELDS) {
            throw new RuntimeException('World Knowledge catalog header does not match parity-v1');
        }
        while (($values = fgetcsv($handle, 0, ',', '"', '\\')) !== false) {
            if (count($values) !== count(DIALECTIC_WORLDKNOWLEDGE_CATALOG_FIELDS)) {
                throw new RuntimeException('World Knowledge catalog contains a malformed row');
            }
            $row = array_combine(DIALECTIC_WORLDKNOWLEDGE_CATALOG_FIELDS, $values);
            if (!is_array($row)) {
                throw new RuntimeException('Unable to map a World Knowledge catalog row');
            }
            $row = array_map(static fn(mixed $value): string => trim(strval($value)), $row);
            dialecticWorldKnowledgeValidateFactoryRow($row, $seenTopics);
            $row['canonical_topic'] = dialecticWorldKnowledgeCanonicalTopic($row['topic']);
            $row['content_hash'] = dialecticWorldKnowledgeContentHash($row);
            $seenTopics[$row['canonical_topic']] = true;
            $rows[] = $row;
        }
    } finally {
        fclose($handle);
    }
    if (count($rows) !== intval($manifest['row_count'])) {
        throw new RuntimeException('World Knowledge catalog row count does not match its manifest');
    }

    return [
        'manifest' => $manifest,
        'rows' => $rows,
        'csv_path' => $csvPath,
        'checksum_sha256' => $actualChecksum,
    ];
}

function dialecticWorldKnowledgeValidateFactoryRow(array $row, array $seenTopics = []): void
{
    $topic = dialecticWorldKnowledgeNormalizeTopicList($row['topic'] ?? '');
    $canonical = dialecticWorldKnowledgeCanonicalTopic($topic);
    if ($canonical === '' || !preg_match('/^[a-z0-9_]+$/', $canonical)) {
        throw new RuntimeException('World Knowledge catalog contains an invalid canonical topic');
    }
    if (isset($seenTopics[$canonical])) {
        throw new RuntimeException("World Knowledge catalog contains duplicate topic {$canonical}");
    }
    $basicText = trim(strval($row['topic_desc_basic'] ?? ''));
    $allowsOmittedBasic = preg_match('/Access v2 \((?:secret|personal);/i', strval($row['editorial_note'] ?? '')) === 1;
    if ($basicText === '' && !$allowsOmittedBasic) {
        throw new RuntimeException("World Knowledge catalog is missing basic text for {$canonical}");
    }
    $advancedWordCount = count(preg_split(
        '/\s+/u',
        trim(strval($row['topic_desc'] ?? '')),
        -1,
        PREG_SPLIT_NO_EMPTY
    ));
    $basicWordCount = count(preg_split(
        '/\s+/u',
        $basicText,
        -1,
        PREG_SPLIT_NO_EMPTY
    ));
    if ($advancedWordCount < 70 || $advancedWordCount > 280
        || ($basicText !== '' && ($basicWordCount < 20 || $basicWordCount > 220))) {
        throw new RuntimeException("World Knowledge article lengths are outside reviewed bounds for {$canonical}");
    }
    if (trim(strval($row['topic_desc'] ?? '')) === '' && trim(strval($row['editorial_note'] ?? '')) === '') {
        throw new RuntimeException("Basic-only World Knowledge article {$canonical} requires an editorial note");
    }
    foreach (['category', 'setting', 'source_url', 'source_revision'] as $required) {
        if (trim(strval($row[$required] ?? '')) === '') {
            throw new RuntimeException("World Knowledge catalog is missing {$required} for {$canonical}");
        }
    }
    if (trim(strval($row['tags'] ?? '')) === '') {
        throw new RuntimeException("World Knowledge catalog is missing reviewed tags for {$canonical}");
    }
    if (!in_array(strtolower(strval($row['category'] ?? '')), DIALECTIC_WORLDKNOWLEDGE_CATEGORIES, true)) {
        throw new RuntimeException("World Knowledge catalog contains an unsupported category for {$canonical}");
    }
    $tags = array_values(array_unique(array_filter(array_map(
        static fn(string $value): string => strtolower(trim($value)),
        explode(',', strval($row['tags'] ?? ''))
    ))));
    if (count($tags) < 4 || count($tags) > 8) {
        throw new RuntimeException("World Knowledge catalog requires four to eight tags for {$canonical}");
    }
    foreach ($tags as $tag) {
        $words = preg_split('/\s+/u', $tag) ?: [];
        if (count($words) < 2 || count($words) > 5 || !preg_match('/^[\p{L}\p{N}][\p{L}\p{N} .\'-]*$/u', $tag)) {
            throw new RuntimeException("World Knowledge catalog contains an invalid tag for {$canonical}");
        }
    }
    foreach (['knowledge_class', 'knowledge_class_basic'] as $field) {
        $rule = dialecticWorldKnowledgeParseAccessRule(strval($row[$field] ?? ''));
        $classes = array_merge($rule['denied'], ...$rule['clauses']);
        foreach ($classes as $class) {
            $parts = explode(':', $class, 2);
            if (count($parts) === 1 && in_array($class, DIALECTIC_WORLDKNOWLEDGE_CLASSES, true)) {
                continue;
            }
            if (count($parts) !== 2
                || !in_array($parts[0], DIALECTIC_WORLDKNOWLEDGE_ACCESS_NAMESPACES, true)
                || !preg_match('/^[a-z0-9][a-z0-9_]{0,100}$/', $parts[1])) {
                throw new RuntimeException("World Knowledge catalog contains unsupported access tag {$class} for {$canonical}");
            }
        }
    }
    if (filter_var(strval($row['source_url'] ?? ''), FILTER_VALIDATE_URL) === false
        || !preg_match('/^\d+$/', strval($row['source_revision'] ?? ''))) {
        throw new RuntimeException("World Knowledge provenance is invalid for {$canonical}");
    }
    $from = dialecticWorldKnowledgeOptionalYear($row['valid_from_year'] ?? '');
    $to = dialecticWorldKnowledgeOptionalYear($row['valid_to_year'] ?? '');
    if ($from !== null && $to !== null && $from > $to) {
        throw new RuntimeException("World Knowledge chronology is inverted for {$canonical}");
    }
}

function dialecticWorldKnowledgeOptionalYear(mixed $value): ?int
{
    $value = trim(strval($value));
    if ($value === '') {
        return null;
    }
    if (!preg_match('/^\d{1,4}$/', $value)) {
        throw new RuntimeException("Invalid World Knowledge year: {$value}");
    }
    return intval($value);
}

function dialecticWorldKnowledgeContentHash(array $row): string
{
    $payload = [];
    foreach (DIALECTIC_WORLDKNOWLEDGE_CATALOG_FIELDS as $field) {
        $payload[$field] = trim(strval($row[$field] ?? ''));
    }
    return hash('sha256', json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
}

/** Remove only byte-equivalent legacy seed rows so they do not masquerade as custom overrides. */
function dialecticWorldKnowledgeRemoveLegacyFactorySeed(object $db, string $rootPath): int
{
    $path = rtrim($rootPath, '/\\') . DIRECTORY_SEPARATOR . 'data'
        . DIRECTORY_SEPARATOR . 'fallout_worldknowledge_basic.csv';
    if (!is_readable($path)) {
        return 0;
    }
    $handle = fopen($path, 'rb');
    if ($handle === false) {
        return 0;
    }
    $removed = 0;
    try {
        $header = fgetcsv($handle, 0, ',', '"', '\\');
        $expected = ['topic', 'topic_desc', 'knowledge_class', 'topic_desc_basic', 'knowledge_class_basic', 'tags', 'category'];
        if ($header !== $expected) {
            return 0;
        }
        while (($values = fgetcsv($handle, 0, ',', '"', '\\')) !== false) {
            if (count($values) !== count($expected)) {
                continue;
            }
            $row = array_combine($expected, $values);
            if (!is_array($row)) {
                continue;
            }
            $canonical = dialecticWorldKnowledgeCanonicalTopic(strval($row['topic'] ?? ''));
            $predicate = ' WHERE source_kind=\'custom\' AND is_active'
                . ' AND canonical_topic=' . $db->escapeLiteral($canonical)
                . ' AND topic=' . $db->escapeLiteral(trim(strval($row['topic'] ?? '')))
                . ' AND coalesce(topic_desc,\'\')=' . $db->escapeLiteral(trim(strval($row['topic_desc'] ?? '')))
                . ' AND coalesce(knowledge_class,\'\')=' . $db->escapeLiteral(trim(strval($row['knowledge_class'] ?? '')))
                . ' AND coalesce(topic_desc_basic,\'\')=' . $db->escapeLiteral(trim(strval($row['topic_desc_basic'] ?? '')))
                . ' AND coalesce(knowledge_class_basic,\'\')=' . $db->escapeLiteral(trim(strval($row['knowledge_class_basic'] ?? '')))
                . ' AND coalesce(tags,\'\')=' . $db->escapeLiteral(trim(strval($row['tags'] ?? '')))
                . ' AND coalesce(category,\'\')=' . $db->escapeLiteral(trim(strval($row['category'] ?? '')));
            $sql = 'DELETE FROM public.worldknowledge' . $predicate;
            $before = $db->fetchOne(
                'SELECT entry_id FROM public.worldknowledge' . $predicate . ' LIMIT 1'
            );
            if (is_array($before) && $before !== [] && $db->execQuery($sql)) {
                $removed++;
            }
        }
    } finally {
        fclose($handle);
    }
    return $removed;
}

/** Seed controlled factory NPC knowledge tags without replacing user-authored template values. */
function dialecticWorldKnowledgeInstallNpcAccessTags(object $db, string $rootPath): int
{
    $path = rtrim($rootPath, '/\\') . DIRECTORY_SEPARATOR . 'data'
        . DIRECTORY_SEPARATOR . 'fallout_worldknowledge_npc_tags.csv';
    if (!is_readable($path)) {
        throw new RuntimeException("Fallout NPC World Knowledge tags are not readable: {$path}");
    }
    $handle = fopen($path, 'rb');
    if ($handle === false) {
        throw new RuntimeException('Unable to open Fallout NPC World Knowledge tags');
    }
    $valuesSql = [];
    try {
        if (fgetcsv($handle, 0, ',', '"', '\\') !== ['npc_name', 'worldknowledge_tags']) {
            throw new RuntimeException('Fallout NPC World Knowledge tag header is invalid');
        }
        while (($row = fgetcsv($handle, 0, ',', '"', '\\')) !== false) {
            if (count($row) !== 2) {
                throw new RuntimeException('Fallout NPC World Knowledge tag row is malformed');
            }
            $npcName = trim(strval($row[0]));
            $tags = array_values(array_unique(array_filter(array_map(
                'dialecticWorldKnowledgeNormalizeAccessTag',
                explode(',', strval($row[1]))
            ))));
            if ($npcName === '' || $tags === []) {
                throw new RuntimeException('Fallout NPC World Knowledge tag row is empty');
            }
            foreach ($tags as $tag) {
                $parts = explode(':', $tag, 2);
                if (count($parts) !== 2
                    || !in_array($parts[0], DIALECTIC_WORLDKNOWLEDGE_ACCESS_NAMESPACES, true)) {
                    throw new RuntimeException("Fallout NPC template {$npcName} contains unsupported access tag {$tag}");
                }
            }
            $valuesSql[] = '(' . $db->escapeLiteral($npcName) . ','
                . $db->escapeLiteral(implode(',', $tags)) . ')';
        }
    } finally {
        fclose($handle);
    }
    if ($valuesSql === []) {
        return 0;
    }
    $result = $db->fetchOne(
        'WITH seed(npc_name,tags) AS (VALUES ' . implode(',', $valuesSql) . '), updated AS ('
        . 'UPDATE public.bio_templates AS template SET worldknowledge_tags=seed.tags FROM seed'
        . ' WHERE template.npc_name=seed.npc_name'
        . " AND coalesce(btrim(template.worldknowledge_tags),'')='' RETURNING 1)"
        . ' SELECT count(*) AS updated FROM updated'
    );
    if (!is_array($result) || !array_key_exists('updated', $result)) {
        throw new RuntimeException('Unable to install Fallout NPC World Knowledge tags');
    }
    return intval($result['updated'] ?? 0);
}

/** Install one immutable factory version, then atomically make it the effective catalog. */
function dialecticWorldKnowledgeInstallFactoryCatalog(object $db, string $rootPath, bool $activate = true): array
{
    if (!method_exists($db, 'execQuery') || !method_exists($db, 'fetchOne')
        || !method_exists($db, 'fetchAll') || !method_exists($db, 'escapeLiteral')) {
        throw new InvalidArgumentException('World Knowledge catalog installation requires the PostgreSQL database adapter');
    }
    $catalog = dialecticWorldKnowledgeLoadFactoryCatalog($rootPath);
    $manifest = $catalog['manifest'];
    $catalogId = trim(strval($manifest['catalog_id']));
    $catalogVersion = trim(strval($manifest['catalog_version']));
    $displayName = trim(strval($manifest['display_name']));
    $checksum = strval($catalog['checksum_sha256']);
    $manifestJson = json_encode($manifest, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

    $existing = $db->fetchOne(
        'SELECT checksum_sha256, row_count FROM public.worldknowledge_catalogs'
        . ' WHERE catalog_id=' . $db->escapeLiteral($catalogId)
        . ' AND catalog_version=' . $db->escapeLiteral($catalogVersion)
        . ' LIMIT 1'
    );
    if (is_array($existing) && $existing !== []
        && (!hash_equals(strval($existing['checksum_sha256'] ?? ''), $checksum)
            || intval($existing['row_count'] ?? -1) !== count($catalog['rows']))) {
        throw new RuntimeException("Installed World Knowledge catalog {$catalogId}/{$catalogVersion} has different immutable content");
    }

    $replaceRows = true;
    if (is_array($existing) && $existing !== []) {
        $installedRows = $db->fetchAll(
            'SELECT canonical_topic, content_hash FROM public.worldknowledge WHERE source_kind=\'factory\''
            . ' AND catalog_id=' . $db->escapeLiteral($catalogId)
            . ' AND catalog_version=' . $db->escapeLiteral($catalogVersion)
            . ' ORDER BY canonical_topic'
        );
        $installedHashes = [];
        foreach ((array)$installedRows as $installedRow) {
            $installedHashes[strval($installedRow['canonical_topic'] ?? '')] = strval($installedRow['content_hash'] ?? '');
        }
        $expectedHashes = [];
        foreach ($catalog['rows'] as $row) {
            $expectedHashes[$row['canonical_topic']] = $row['content_hash'];
        }
        ksort($installedHashes);
        ksort($expectedHashes);
        $replaceRows = $installedHashes !== $expectedHashes;
    }

    $transactionOpen = false;
    try {
        if (!$db->execQuery('BEGIN')) {
            throw new RuntimeException('Unable to begin World Knowledge catalog installation');
        }
        $transactionOpen = true;
        $legacySeedRemoved = dialecticWorldKnowledgeRemoveLegacyFactorySeed($db, $rootPath);
        $catalogSql = 'INSERT INTO public.worldknowledge_catalogs '
            . '(catalog_id,catalog_version,display_name,checksum_sha256,row_count,manifest,is_active) VALUES ('
            . implode(',', [
                $db->escapeLiteral($catalogId),
                $db->escapeLiteral($catalogVersion),
                $db->escapeLiteral($displayName),
                $db->escapeLiteral($checksum),
                strval(count($catalog['rows'])),
                $db->escapeLiteral($manifestJson) . '::jsonb',
                'FALSE',
            ])
            . ') ON CONFLICT (catalog_id,catalog_version) DO NOTHING';
        if (!$db->execQuery($catalogSql)) {
            throw new RuntimeException('Unable to record World Knowledge catalog manifest');
        }
        if ($replaceRows && !$db->execQuery(
            'DELETE FROM public.worldknowledge WHERE source_kind=\'factory\''
            . ' AND catalog_id=' . $db->escapeLiteral($catalogId)
            . ' AND catalog_version=' . $db->escapeLiteral($catalogVersion)
        )) {
            throw new RuntimeException('Unable to replace staged World Knowledge factory rows');
        }

        foreach ($replaceRows ? $catalog['rows'] : [] as $row) {
            $values = [
                $db->escapeLiteral($row['topic']),
                $db->escapeLiteral($row['canonical_topic']),
                dialecticWorldKnowledgeSqlNullable($db, $row['topic_desc']),
                dialecticWorldKnowledgeSqlNullable($db, $row['knowledge_class']),
                $db->escapeLiteral($row['topic_desc_basic']),
                dialecticWorldKnowledgeSqlNullable($db, $row['knowledge_class_basic']),
                $db->escapeLiteral($row['tags']),
                $db->escapeLiteral($row['category']),
                $db->escapeLiteral('factory'),
                $db->escapeLiteral($catalogId),
                $db->escapeLiteral($catalogVersion),
                $db->escapeLiteral($row['content_hash']),
                $db->escapeLiteral($row['source_url']),
                $db->escapeLiteral($row['source_revision']),
                $db->escapeLiteral($row['setting']),
                dialecticWorldKnowledgeSqlNullable($db, $row['region']),
                dialecticWorldKnowledgeSqlNullableInteger($row['valid_from_year']),
                dialecticWorldKnowledgeSqlNullableInteger($row['valid_to_year']),
                dialecticWorldKnowledgeSqlNullable($db, $row['editorial_note']),
            ];
            $insertSql = 'INSERT INTO public.worldknowledge ('
                . 'topic,canonical_topic,topic_desc,knowledge_class,topic_desc_basic,knowledge_class_basic,'
                . 'tags,category,source_kind,catalog_id,catalog_version,content_hash,source_url,source_revision,'
                . 'setting,region,valid_from_year,valid_to_year,editorial_note,native_vector'
                . ') VALUES (' . implode(',', $values) . ','
                . "setweight(to_tsvector('simple',coalesce(" . $db->escapeLiteral($row['topic']) . ",'')),'A')"
                . "||setweight(to_tsvector('simple',coalesce(" . dialecticWorldKnowledgeSqlNullable($db, $row['topic_desc']) . ",'')),'B')"
                . "||setweight(to_tsvector('simple',coalesce(" . $db->escapeLiteral($row['topic_desc_basic']) . ",'')),'C')"
                . "||setweight(to_tsvector('simple',coalesce(" . $db->escapeLiteral($row['tags']) . ",'')),'B')"
                . "||setweight(to_tsvector('simple',coalesce(" . $db->escapeLiteral($row['category']) . ",'')),'C'))";
            if (!$db->execQuery($insertSql)) {
                throw new RuntimeException("Unable to install World Knowledge article {$row['canonical_topic']}");
            }
        }

        $npcTemplateTagsInstalled = dialecticWorldKnowledgeInstallNpcAccessTags($db, $rootPath);

        if ($activate) {
            if (!$db->execQuery(
                'UPDATE public.worldknowledge_catalogs SET is_active=FALSE, activated_at=NULL WHERE is_active'
                . ' AND NOT (catalog_id=' . $db->escapeLiteral($catalogId)
                . ' AND catalog_version=' . $db->escapeLiteral($catalogVersion) . ')'
            )) {
                throw new RuntimeException('Unable to deactivate the previous World Knowledge catalog');
            }
            if (!$db->execQuery(
                'UPDATE public.worldknowledge_catalogs SET is_active=TRUE, activated_at=CURRENT_TIMESTAMP'
                . ' WHERE catalog_id=' . $db->escapeLiteral($catalogId)
                . ' AND catalog_version=' . $db->escapeLiteral($catalogVersion)
                . ' AND NOT is_active'
            )) {
                throw new RuntimeException('Unable to activate the new World Knowledge catalog');
            }
        }
        if (!$db->execQuery('COMMIT')) {
            throw new RuntimeException('Unable to commit World Knowledge catalog installation');
        }
        $transactionOpen = false;
    } catch (Throwable $exception) {
        if ($transactionOpen) {
            $db->execQuery('ROLLBACK');
        }
        throw $exception;
    }

    return [
        'catalog_id' => $catalogId,
        'catalog_version' => $catalogVersion,
        'checksum_sha256' => $checksum,
        'row_count' => count($catalog['rows']),
        'active' => $activate,
        'legacy_seed_rows_removed' => $legacySeedRemoved ?? 0,
        'npc_template_tags_installed' => $npcTemplateTagsInstalled ?? 0,
    ];
}

function dialecticWorldKnowledgeActivateCatalog(object $db, string $catalogId, string $catalogVersion): void
{
    $target = $db->fetchOne(
        'SELECT catalog_id FROM public.worldknowledge_catalogs'
        . ' WHERE catalog_id=' . $db->escapeLiteral($catalogId)
        . ' AND catalog_version=' . $db->escapeLiteral($catalogVersion)
        . ' LIMIT 1'
    );
    if (!is_array($target) || $target === []) {
        throw new RuntimeException("World Knowledge catalog {$catalogId}/{$catalogVersion} is not installed");
    }
    $transactionOpen = false;
    try {
        if (!$db->execQuery('BEGIN')) {
            throw new RuntimeException('Unable to begin World Knowledge catalog activation');
        }
        $transactionOpen = true;
        if (!$db->execQuery(
            'UPDATE public.worldknowledge_catalogs SET is_active=FALSE, activated_at=NULL WHERE is_active'
            . ' AND NOT (catalog_id=' . $db->escapeLiteral($catalogId)
            . ' AND catalog_version=' . $db->escapeLiteral($catalogVersion) . ')'
        )) {
            throw new RuntimeException('Unable to deactivate the current World Knowledge catalog');
        }
        if (!$db->execQuery(
            'UPDATE public.worldknowledge_catalogs SET is_active=TRUE, activated_at=CURRENT_TIMESTAMP'
            . ' WHERE catalog_id=' . $db->escapeLiteral($catalogId)
            . ' AND catalog_version=' . $db->escapeLiteral($catalogVersion)
            . ' AND NOT is_active'
        )) {
            throw new RuntimeException('Unable to activate the requested World Knowledge catalog');
        }
        if (!$db->execQuery('COMMIT')) {
            throw new RuntimeException('Unable to commit World Knowledge catalog activation');
        }
        $transactionOpen = false;
    } catch (Throwable $exception) {
        if ($transactionOpen) {
            $db->execQuery('ROLLBACK');
        }
        throw $exception;
    }
}

function dialecticWorldKnowledgeSqlNullable(object $db, mixed $value): string
{
    $value = trim(strval($value));
    return $value === '' ? 'NULL' : $db->escapeLiteral($value);
}

function dialecticWorldKnowledgeSqlNullableInteger(mixed $value): string
{
    $year = dialecticWorldKnowledgeOptionalYear($value);
    return $year === null ? 'NULL' : strval($year);
}
