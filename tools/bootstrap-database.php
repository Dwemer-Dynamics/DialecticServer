<?php

$root = dirname(__DIR__);

require_once $root . DIRECTORY_SEPARATOR . "lib" . DIRECTORY_SEPARATOR . "runtime_bootstrap.php";
require_once $root . DIRECTORY_SEPARATOR . "lib" . DIRECTORY_SEPARATOR . "dialectic_runtime.php";

dialecticRuntimeBootstrap($root, [
    'run_db_updates' => true,
    'load_general_settings' => true,
    'load_stt_connector' => true,
    'load_tts_connector' => false,
    'load_player_name' => true,
    'load_narrator' => true,
]);

$db = $GLOBALS["db"] ?? null;
if (!$db) {
    fwrite(STDERR, "Dialectic database bootstrap failed: no database connection was created.\n");
    exit(1);
}

if (!dialecticRuntimeDatabaseEncodingIsSupported()) {
    fwrite(STDERR, dialecticRuntimeDatabaseEncodingError() . "\n");
    exit(1);
}

if (dialecticRuntimeNeedsDbUpdates()) {
    fwrite(STDERR, "Dialectic database bootstrap failed: required schema objects or migration versions are still missing.\n");
    exit(1);
}

$versionRows = $db->fetchAll("SELECT COUNT(*) AS count FROM public.database_versioning");
$seedRows = $db->fetchAll("
    SELECT 'core_action' AS seed_name, COUNT(*) AS row_count, COUNT(DISTINCT code_name) AS unique_count
      FROM public.core_action
    UNION ALL
    SELECT 'prompts', COUNT(*), COUNT(DISTINCT prompt_key)
      FROM public.prompts
    UNION ALL
    SELECT 'bio_templates', COUNT(*), COUNT(DISTINCT lower(npc_name))
      FROM public.bio_templates
    UNION ALL
    SELECT 'descriptions', COUNT(*), COUNT(DISTINCT lower(plugin || ':' || baseid))
      FROM public.descriptions
    UNION ALL
    SELECT 'general_settings', COUNT(*), COUNT(DISTINCT id)
      FROM public.general_settings
");

$seedMinimums = [
    'core_action' => 26,
    'prompts' => 47,
    'bio_templates' => 1250,
    'descriptions' => 1828,
    'general_settings' => count(array_unique(dialecticGetManagedGeneralSettingIds())),
];
$seedCounts = [];
foreach ($seedRows as $seedRow) {
    $seedName = strval($seedRow['seed_name'] ?? '');
    $rowCount = intval($seedRow['row_count'] ?? 0);
    $uniqueCount = intval($seedRow['unique_count'] ?? 0);
    if ($seedName === '' || $rowCount !== $uniqueCount || $rowCount < intval($seedMinimums[$seedName] ?? 1)) {
        fwrite(STDERR, "Dialectic database bootstrap failed: seed verification failed for {$seedName}.\n");
        exit(1);
    }
    $seedCounts[$seedName] = $rowCount;
}

foreach ($seedMinimums as $seedName => $minimum) {
    if (!isset($seedCounts[$seedName])) {
        fwrite(STDERR, "Dialectic database bootstrap failed: seed verification did not return {$seedName}.\n");
        exit(1);
    }
}

$versionCount = intval($versionRows[0]["count"] ?? 0);

echo "Dialectic database bootstrap complete.\n";
echo "database_versioning rows: {$versionCount}\n";
echo "schema verification: complete\n";
foreach ($seedCounts as $seedName => $seedCount) {
    echo "{$seedName} rows: {$seedCount}\n";
}
