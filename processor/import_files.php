<?php

/* CSV Import Processor - Called by csv_import.php endpoint.
 * Handles automatic plugin imports:
 * - biography_import: NPC character data from Data/Dialectic/*_bios.csv
 */

function dialecticLogCsvImportAuditEvent($eventType, $message, $timestamp, $game_timestamp): void
{
    global $db;

    $normalizedEventType = strtolower(trim(strval($eventType)));
    $normalizedMessage = trim(strval($message));
    if ($normalizedEventType === '' || $normalizedMessage === '') {
        return;
    }

    $db->insert('eventlog', [
        'ts' => $timestamp,
        'gamets' => $game_timestamp,
        'type' => 'status_msg',
        'data' => "csv_import@{$normalizedEventType}@{$normalizedMessage}",
        'sess' => 'web',
        'localts' => time(),
        'people' => '',
        'location' => '',
        'party' => '',
    ]);
}

if (!function_exists('dialecticNormalizeBiographyRelationshipSeed')) {
    function dialecticNormalizeBiographyRelationshipSeed($value, &$errorMessage = '')
    {
        $errorMessage = '';

        if ($value === null) {
            return null;
        }

        if (is_array($value)) {
            return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }

        $trimmed = trim((string)$value);
        if ($trimmed === '') {
            return null;
        }

        if ($trimmed[0] !== '{') {
            $errorMessage = 'expected a JSON object with per-target relationship seeds';
            return false;
        }

        $decoded = json_decode($trimmed, true);
        if (!is_array($decoded)) {
            $errorMessage = 'invalid JSON object';
            return false;
        }

        return json_encode($decoded, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}

function dialecticHandleBiographyImport($csvData, $timestamp, $game_timestamp): bool
{
    global $db;

    Logger::info("Dialectic Biography Import: STARTED - Processing CSV data upload");

    $processedCount = 0;
    $errorCount = 0;
    $tempFile = null;

    try {
        if (strpos($csvData, "\x00") !== false) {
            $bom = substr($csvData, 0, 2);
            if ($bom === "\xFF\xFE") {
                $csvData = mb_convert_encoding(substr($csvData, 2), 'UTF-8', 'UTF-16LE');
            } elseif ($bom === "\xFE\xFF") {
                $csvData = mb_convert_encoding(substr($csvData, 2), 'UTF-8', 'UTF-16BE');
            } else {
                $csvData = mb_convert_encoding($csvData, 'UTF-8', 'UTF-16');
            }
        }

        if (substr($csvData, 0, 3) === "\xEF\xBB\xBF") {
            $csvData = substr($csvData, 3);
        }

        if (!mb_check_encoding($csvData, 'UTF-8')) {
            $csvData = mb_convert_encoding($csvData, 'UTF-8', 'Windows-1252');
        }

        $tempFile = tempnam(sys_get_temp_dir(), 'dialectic_biography_import_');
        file_put_contents($tempFile, $csvData);

        $handle = fopen($tempFile, 'r');
        if ($handle === false) {
            Logger::error("Dialectic Biography Import: Could not open temporary CSV file");
            return false;
        }

        $header = fgetcsv($handle, 0, ',');
        if ($header === false || empty($header)) {
            Logger::error("Dialectic Biography Import: Invalid CSV header");
            fclose($handle);
            return false;
        }

        $headerMap = [];
        foreach ($header as $i => $colName) {
            $colNameSafe = preg_replace('/[\x{FEFF}\x{FFFE}\x{00A0}]/u', ' ', (string)$colName);
            $colNameSafe = preg_replace('/\s+/', ' ', $colNameSafe);
            $normalized = strtolower(trim($colNameSafe));
            if ($normalized !== '') {
                $headerMap[$normalized] = $i;
            }
        }

        while (($data = fgetcsv($handle, 0, ',')) !== false) {
            if (empty($data) || count($data) < 2) {
                continue;
            }

            $getValue = function($key) use ($headerMap, $data) {
                if (isset($headerMap[$key]) && isset($data[$headerMap[$key]])) {
                    $temp = trim((string)$data[$headerMap[$key]]);
                    return ($temp !== '') ? $temp : null;
                }
                return null;
            };

            $npc_name = isset($headerMap['npc_name'], $data[$headerMap['npc_name']])
                ? strtolower(trim((string)$data[$headerMap['npc_name']]))
                : '';
            $core = $getValue('core') ?? $getValue('npc_pers') ?? '';

            if ($npc_name === '' || $core === '') {
                Logger::warn("Dialectic Biography Import: Skipping row with missing npc_name or core");
                $errorCount++;
                continue;
            }

            if (strlen($npc_name) > 128) {
                $npc_name = substr($npc_name, 0, 128);
            }

            $worldknowledge_tags =
                $getValue('worldknowledge_tags') ??
                $getValue('knowledge_tags') ??
                $getValue('npc_misc') ??
                '';

            $relationships = $getValue('relationships') ?? $getValue('npc_relationships');
            $relationshipError = '';
            $relationships = dialecticNormalizeBiographyRelationshipSeed($relationships, $relationshipError);
            if ($relationships === false) {
                Logger::error("Dialectic Biography Import: NPC '{$npc_name}' has invalid relationships field: {$relationshipError}");
                $errorCount++;
                continue;
            }

            $ok = $db->upsertRowOnConflict(
                'bio_templates_custom',
                [
                    'npc_name' => $npc_name,
                    'core' => $core,
                    'worldknowledge_tags' => $worldknowledge_tags,
                    'npc_static_bio' => $getValue('npc_static_bio') ?? $getValue('npc_background'),
                    'appearance' => $getValue('appearance') ?? $getValue('npc_appearance'),
                    'personality' => $getValue('personality') ?? $getValue('npc_personality'),
                    'relationships' => $relationships,
                    'occupation' => $getValue('occupation') ?? $getValue('npc_occupation'),
                    'skills' => $getValue('skills') ?? $getValue('npc_skills'),
                    'speechstyle' => $getValue('speechstyle') ?? $getValue('npc_speechstyle'),
                    'goals' => $getValue('goals') ?? $getValue('npc_goals'),
                    'voiceid' => $getValue('voiceid'),
                    'gender' => $getValue('gender'),
                    'race' => $getValue('race'),
                    'refid' => $getValue('refid'),
                ],
                'npc_name'
            );

            if ($ok === false) {
                $errorCount++;
            } else {
                $processedCount++;
                Logger::info("Dialectic Biography Import: Successfully processed NPC: {$npc_name}");
            }
        }

        fclose($handle);
        Logger::info("Dialectic Biography Import: Processing complete. {$processedCount} records processed, {$errorCount} errors");

        dialecticLogCsvImportAuditEvent(
            'biography_import',
            "{$processedCount} records processed, {$errorCount} errors",
            $timestamp,
            $game_timestamp
        );
    } catch (Throwable $e) {
        Logger::error("Dialectic Biography Import: Fatal error processing CSV: " . $e->getMessage());
        dialecticLogCsvImportAuditEvent(
            'biography_import',
            "failed: " . $e->getMessage(),
            $timestamp,
            $game_timestamp
        );
    } finally {
        if ($tempFile !== null && file_exists($tempFile)) {
            unlink($tempFile);
        }
    }

    return true;
}

// Dispatch only after conditional helper declarations have executed.
if (isset($_POST['csv_import']) && $_POST['csv_import'] == '1' && isset($_POST['type'])) {
    $import_type = $_POST['type'];
    $timestamp = $_POST['ts'] ?? time();
    $game_timestamp = $_POST['gamets'] ?? 0;

    if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
        Logger::error("Dialectic CSV Import ({$import_type}): No file uploaded or upload error occurred");
        return false;
    }

    $csvData = file_get_contents($_FILES['file']['tmp_name']);
    if (empty($csvData)) {
        Logger::error("Dialectic CSV Import ({$import_type}): Empty CSV file uploaded");
        return false;
    }

    switch ($import_type) {
        case 'biography_import':
            dialecticHandleBiographyImport($csvData, $timestamp, $game_timestamp);
            break;
        default:
            Logger::error("Dialectic CSV Import: Unknown import type: {$import_type}");
            return false;
    }
}

?>
