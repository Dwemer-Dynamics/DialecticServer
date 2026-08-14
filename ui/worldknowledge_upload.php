<?php
// Get the relative web path from document root to our application
$scriptPath = $_SERVER['SCRIPT_NAME'];
$webRoot = dirname(dirname($scriptPath)); // Go up two levels from the script location
if ($webRoot == '/') $webRoot = '';
$webRoot = rtrim($webRoot, '/');

require_once(__DIR__.DIRECTORY_SEPARATOR."profile_loader.php");

$TITLE = "&#x1F4D9; DIALECTIC - World Knowledge";

ob_start();

include(__DIR__.DIRECTORY_SEPARATOR."tmpl/head.html");
$isEmbed = (isset($_GET['embed']) && $_GET['embed'] == '1');

$debugPaneLink = false;

// Enable error reporting (for development purposes)
error_reporting(E_ALL);
ini_set('display_errors', '1');

// Paths
$rootPath = dirname(__DIR__) . DIRECTORY_SEPARATOR;
$enginePath = dirname($rootPath) . DIRECTORY_SEPARATOR;
$configFilepath = $rootPath . "conf" . DIRECTORY_SEPARATOR;
require_once($configFilepath . "conf.php");
require_once($rootPath . "lib" . DIRECTORY_SEPARATOR . "db_connection_settings.php");
require_once($rootPath . "lib" . DIRECTORY_SEPARATOR . "worldknowledge_topic.php");
require_once($rootPath . "lib" . DIRECTORY_SEPARATOR . "worldknowledge_catalog.php");

$dbSettings = dialecticDbConnectionSettings('dialectic');
$host = $dbSettings['host'];
$port = $dbSettings['port'];
$dbname = $dbSettings['dbname'];
$schema = $dbSettings['schema'];
$username = $dbSettings['username'];
$password = $dbSettings['password'];


// Initialize message variable
$message = '';
if (isset($_GET['message']) && is_string($_GET['message'])) {
    $message .= '<p>' . htmlspecialchars($_GET['message']) . '</p>';
}
if (isset($_GET['error']) && is_string($_GET['error'])) {
    $message .= '<p>Factory catalog error: ' . htmlspecialchars($_GET['error']) . '</p>';
}

function worldknowledge_normalize_topic_key($value) {
    return dialecticWorldKnowledgeNormalizeCanonicalTopic($value);
}

function worldknowledge_has_description($topicDesc, $topicDescBasic) {
    return trim((string)$topicDesc) !== '' || trim((string)$topicDescBasic) !== '';
}

/** Format shared tier-conflict validation for create, CSV, and edit errors. */
function worldknowledge_access_rule_conflicts($advancedRule, $basicRule) {
    $conflicts = dialecticWorldKnowledgeAccessTierConflicts($advancedRule, $basicRule);

    $problems = [];
    if ($conflicts['duplicates']) {
        $problems[] = 'listed in both tiers: ' . implode(', ', $conflicts['duplicates']);
    }
    if ($conflicts['contradictions']) {
        $problems[] = 'allowed in one tier and denied in the other: '
            . implode(', ', $conflicts['contradictions']);
    }
    if (!$problems) {
        return '';
    }

    return 'Each knowledge class belongs to one tier only, so nothing was saved. Fix these classes ('
        . implode('; ', $problems) . ').';
}

/** Build an entries-table URL while preserving only its supported filters. */
function worldknowledge_entries_url(array $overrides = []) {
    $params = [];
    foreach (['cat', 'letter', 'search', 'order', 'per_page', 'page'] as $key) {
        if (isset($_GET[$key]) && trim((string)$_GET[$key]) !== '') {
            $params[$key] = (string)$_GET[$key];
        }
    }
    foreach ($overrides as $key => $value) {
        if ($value === null || $value === '') {
            unset($params[$key]);
        } else {
            $params[$key] = (string)$value;
        }
    }
    return '?' . http_build_query($params) . '#entries';
}

/**
 * Render a stored knowledge-class list as one flat set of chips.
 *
 * Oghma parity classes are a single comma-separated list with no operators: any
 * matching class grants that tier, and a matching !class denies it first. Each
 * class therefore gets its own chip and nothing is drawn between them. A denial
 * is a visibly distinct chip that reads "except raider" so the leading "!" never
 * has to be decoded by the reader.
 *
 * Chips always read as canonical plain ids, so a legacy namespaced value stored
 * as faction:ncr renders as ncr. The caller keeps the raw value for the edit
 * form, so legacy custom rows still round-trip unchanged.
 */
function worldknowledge_render_access_rule($rawRule, $variant = 'advanced') {
    $rawRule = trim((string)$rawRule);
    $rule = dialecticWorldKnowledgeParseAccessRule($rawRule);
    $tagClass = 'rule-tag' . ($variant === 'basic' ? ' rule-tag-basic' : '');

    $chips = [];
    foreach ($rule['allowed'] as $class) {
        $chips[] = '<span class="' . $tagClass . '">' . htmlspecialchars($class) . '</span>';
    }
    foreach ($rule['denied'] as $class) {
        $chips[] = '<span class="rule-tag rule-tag-deny">'
            . '<span class="rule-deny-word">except</span> ' . htmlspecialchars($class)
            . '</span>';
    }

    if (!$chips) {
        return $rawRule === ''
            ? '<span class="rule-none">Everyone</span>'
            : '<span class="rule-none">Everyone</span><small class="rule-note">No usable classes in this list.</small>';
    }

    return '<span class="rule-classes">' . implode('', $chips) . '</span>';
}

// Connect to the database
$conn = pg_connect(dialecticPgConnectionString($dbSettings));
if (!$conn) {
    echo "<div class='message'>Failed to connect to database: " . pg_last_error() . "</div>";
    exit;
}

function worldknowledge_filter_alias_input($conn, string $schema, string $topic, string $aliases): array {
    $rows = [];
    $result = pg_query($conn, "SELECT topic, coalesce(aliases, '') AS aliases FROM {$schema}.worldknowledge_effective");
    if ($result) {
        while ($row = pg_fetch_assoc($result)) {
            $rows[] = $row;
        }
    }
    return dialecticWorldKnowledgeFilterAliases($topic, $aliases, $rows);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'activate_catalog') {
    try {
        dialecticWorldKnowledgeActivateCatalog(
            $GLOBALS['db'],
            trim((string)($_POST['catalog_id'] ?? '')),
            trim((string)($_POST['catalog_version'] ?? ''))
        );
        $message .= '<p>Factory catalog activated successfully.</p>';
    } catch (Throwable $exception) {
        $message .= '<p>Catalog activation failed: ' . htmlspecialchars($exception->getMessage()) . '</p>';
    }
}

$activeCatalog = null;
$catalogResult = @pg_query(
    $conn,
    "SELECT catalog_id, catalog_version, display_name, checksum_sha256, row_count, activated_at
       FROM {$schema}.worldknowledge_catalogs
      WHERE is_active
      LIMIT 1"
);
if ($catalogResult) {
    $activeCatalog = pg_fetch_assoc($catalogResult) ?: null;
}
$installedCatalogs = [];
$installedCatalogResult = @pg_query(
    $conn,
    "SELECT catalog_id, catalog_version, display_name, checksum_sha256, row_count, is_active, installed_at, activated_at
       FROM {$schema}.worldknowledge_catalogs
      ORDER BY installed_at DESC, catalog_id, catalog_version"
);
if ($installedCatalogResult) {
    while ($installedCatalog = pg_fetch_assoc($installedCatalogResult)) {
        $installedCatalogs[] = $installedCatalog;
    }
}

/********************************************************************
 *  1) SINGLE TOPIC UPLOAD
 ********************************************************************/
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_individual'])) {
    // Store article text as entered and access permissions in canonical plain form.
    // Encoding here wrote &amp;, &#039; and friends into PostgreSQL, which
    // silently broke knowledge classes and put entities in article text.
    // The parameterized query keeps the write safe, and every render escapes
    // again at its own output boundary.
    $topic                = worldknowledge_normalize_topic_key($_POST['topic'] ?? '');
    $aliasesInput         = trim((string)($_POST['aliases'] ?? ''));
    $filteredAliases      = worldknowledge_filter_alias_input($conn, $schema, $topic, $aliasesInput);
    $aliases              = $filteredAliases['aliases'];
    foreach ($filteredAliases['rejected'] as $rejectedAlias) {
        $message .= '<p>Alias skipped: ' . htmlspecialchars($rejectedAlias['alias'])
            . ' (' . htmlspecialchars($rejectedAlias['reason']) . ')</p>';
    }
    $topic_desc           = (string)($_POST['topic_desc']            ?? '');
    $knowledge_class      = dialecticWorldKnowledgeNormalizeAccessRule($_POST['knowledge_class'] ?? '');
    $topic_desc_basic     = (string)($_POST['topic_desc_basic']      ?? '');
    $knowledge_class_basic= dialecticWorldKnowledgeNormalizeAccessRule($_POST['knowledge_class_basic'] ?? '');
    $tags                 = (string)($_POST['tags']                  ?? '');
    $category             = (string)($_POST['category']              ?? '');
    $canonicalTopic       = dialecticWorldKnowledgeCanonicalTopic($topic);
    $classConflict        = worldknowledge_access_rule_conflicts($knowledge_class, $knowledge_class_basic);

    if ($classConflict !== '') {
        $message .= '<p>' . htmlspecialchars($classConflict) . '</p>';
    } elseif (!empty($topic) && worldknowledge_has_description($topic_desc, $topic_desc_basic)) {
        $query = "
            INSERT INTO $schema.worldknowledge (
                topic,
                aliases,
                topic_desc,
                knowledge_class,
                topic_desc_basic,
                knowledge_class_basic,
                tags,
                category,
                canonical_topic,
                source_kind,
                is_active
            )
            VALUES ($1, $2, $3, $4, $5, $6, $7, $8, $9, 'custom', TRUE)
            ON CONFLICT (canonical_topic) WHERE source_kind='custom' AND is_active
            DO UPDATE SET
                topic                = EXCLUDED.topic,
                aliases              = EXCLUDED.aliases,
                topic_desc           = EXCLUDED.topic_desc,
                knowledge_class      = EXCLUDED.knowledge_class,
                topic_desc_basic     = EXCLUDED.topic_desc_basic,
                knowledge_class_basic= EXCLUDED.knowledge_class_basic,
                tags                 = EXCLUDED.tags,
                category             = EXCLUDED.category,
                updated_at           = CURRENT_TIMESTAMP
        ";
        $result = pg_query_params($conn, $query, [
            $topic,
            $aliases,
            $topic_desc,
            $knowledge_class,
            $topic_desc_basic,
            $knowledge_class_basic,
            $tags,
            $category,
            $canonicalTopic
        ]);

        if ($result) {
            $message .= "<p>Data inserted/updated successfully!</p>";

            // Update native_vector
            $update_query = "
                UPDATE $schema.worldknowledge
                SET native_vector = 
                      setweight(to_tsvector(coalesce(topic, '')), 'A')
                    || setweight(to_tsvector(coalesce(aliases, '')), 'A')
                    || setweight(to_tsvector(coalesce(topic_desc, '')), 'B')
                    || setweight(to_tsvector(coalesce(topic_desc_basic, '')), 'C')
                WHERE canonical_topic = $1 AND source_kind = 'custom'
            ";
            $update_result = pg_query_params($conn, $update_query, [$canonicalTopic]);

            if ($update_result) {
                $message .= "<p>Vectors updated successfully.</p>";
            } else {
                $message .= "<p>Error updating vectors: " . pg_last_error($conn) . "</p>";
            }
        } else {
            $message .= "<p>An error occurred while inserting/updating data: " . pg_last_error($conn) . "</p>";
        }
    } else {
        $message .= '<p>Please provide a topic and at least one description.</p>';
    }
}

/********************************************************************
 *  2) CSV UPLOAD (BATCH)
 ********************************************************************/
function worldknowledge_normalize_csv_header($value) {
    $value = preg_replace('/^\xEF\xBB\xBF/', '', (string)$value);
    $value = strtolower(trim($value));
    $value = preg_replace('/[^a-z0-9]+/', '_', $value);
    return trim($value, '_');
}

function worldknowledge_csv_value($row, $headerMap, $name, $fallbackIndex = null) {
    if (isset($headerMap[$name])) {
        return $row[$headerMap[$name]] ?? '';
    }
    if ($fallbackIndex !== null) {
        return $row[$fallbackIndex] ?? '';
    }
    return '';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_csv'])) {
    if (isset($_FILES['csv_file']) && $_FILES['csv_file']['error'] === UPLOAD_ERR_OK) {
        $fileTmpPath = $_FILES['csv_file']['tmp_name'];
        $fileName    = $_FILES['csv_file']['name'];

        $allowedfileExtensions = array('csv');
        $fileExtension = pathinfo($fileName, PATHINFO_EXTENSION);

        if (in_array($fileExtension, $allowedfileExtensions)) {
            if (($handle = fopen($fileTmpPath, 'r')) !== false) {
                $header = fgetcsv($handle, 0, ',');
                if ($header === false) {
                    $message .= '<p>CSV file is empty.</p>';
                    $header = [];
                }

                $headerMap = [];
                foreach ($header as $index => $columnName) {
                    $normalized = worldknowledge_normalize_csv_header($columnName);
                    if ($normalized !== '' && !isset($headerMap[$normalized])) {
                        $headerMap[$normalized] = $index;
                    }
                }

                $hasNamedColumns = isset($headerMap['topic']);
                $requiredColumns = ['topic', 'topic_desc_basic'];
                $missingColumns = array_values(array_filter($requiredColumns, static function ($column) use ($headerMap) {
                    return !isset($headerMap[$column]);
                }));
                if ($hasNamedColumns && !empty($missingColumns)) {
                    $message .= '<p>CSV warning: Missing expected header(s): ' . htmlspecialchars(implode(', ', $missingColumns)) . '. Missing optional fields will import as blank.</p>';
                }

                $rowCount = 0;
                $skippedCount = 0;
                $conflictCount = 0;
                while (($data = fgetcsv($handle, 0, ',')) !== false) {
                    if (count(array_filter($data, static function ($value) { return trim((string)$value) !== ''; })) === 0) {
                        continue;
                    }

                    $topic                = worldknowledge_normalize_topic_key(worldknowledge_csv_value($data, $headerMap, 'topic', 0));
                    $aliasesInput         = worldknowledge_csv_value($data, $headerMap, 'aliases', 1);
                    $filteredAliases      = worldknowledge_filter_alias_input($conn, $schema, $topic, $aliasesInput);
                    $aliases              = $filteredAliases['aliases'];
                    $topic_desc           = trim(worldknowledge_csv_value($data, $headerMap, 'topic_desc', 2));
                    $knowledge_class      = dialecticWorldKnowledgeNormalizeAccessRule(worldknowledge_csv_value($data, $headerMap, 'knowledge_class', 3));
                    $topic_desc_basic     = trim(worldknowledge_csv_value($data, $headerMap, 'topic_desc_basic', 4));
                    $knowledge_class_basic= dialecticWorldKnowledgeNormalizeAccessRule(worldknowledge_csv_value($data, $headerMap, 'knowledge_class_basic', 5));
                    $tags                 = trim(worldknowledge_csv_value($data, $headerMap, 'tags', 6));
                    $category             = trim(worldknowledge_csv_value($data, $headerMap, 'category', 7));
                    $canonicalTopic       = dialecticWorldKnowledgeCanonicalTopic($topic);
                    $classConflict        = worldknowledge_access_rule_conflicts($knowledge_class, $knowledge_class_basic);

                    if ($classConflict !== '') {
                        $message .= "<p>Row skipped for topic '" . htmlspecialchars($topic) . "': "
                            . htmlspecialchars($classConflict) . "</p>";
                        $conflictCount++;
                    } elseif (!empty($topic) && worldknowledge_has_description($topic_desc, $topic_desc_basic)) {
                        $query = "
                            INSERT INTO $schema.worldknowledge (
                                topic,
                                aliases,
                                topic_desc,
                                knowledge_class,
                                topic_desc_basic,
                                knowledge_class_basic,
                                tags,
                                category,
                                canonical_topic,
                                source_kind,
                                is_active
                            )
                            VALUES ($1, $2, $3, $4, $5, $6, $7, $8, $9, 'custom', TRUE)
                            ON CONFLICT (canonical_topic) WHERE source_kind='custom' AND is_active
                            DO UPDATE SET
                                topic                = EXCLUDED.topic,
                                aliases              = EXCLUDED.aliases,
                                topic_desc           = EXCLUDED.topic_desc,
                                knowledge_class      = EXCLUDED.knowledge_class,
                                topic_desc_basic     = EXCLUDED.topic_desc_basic,
                                knowledge_class_basic= EXCLUDED.knowledge_class_basic,
                                tags                 = EXCLUDED.tags,
                                category             = EXCLUDED.category,
                                updated_at           = CURRENT_TIMESTAMP
                        ";
                        $result = pg_query_params($conn, $query, [
                            $topic,
                            $aliases,
                            $topic_desc,
                            $knowledge_class,
                            $topic_desc_basic,
                            $knowledge_class_basic,
                            $tags,
                            $category,
                            $canonicalTopic
                        ]);

                        if ($result) {
                            $rowCount++;
                            // Update the native_vector for this single row
                            $update_query = "
                                UPDATE $schema.worldknowledge
                                SET native_vector = 
                                      setweight(to_tsvector(coalesce(topic, '')), 'A')
                                    || setweight(to_tsvector(coalesce(aliases, '')), 'A')
                                    || setweight(to_tsvector(coalesce(topic_desc, '')), 'B')
                                    || setweight(to_tsvector(coalesce(topic_desc_basic, '')), 'C')
                                WHERE canonical_topic = $1 AND source_kind = 'custom'
                            ";
                            pg_query_params($conn, $update_query, [$canonicalTopic]);
                        } else {
                            $message .= "<p>Error processing row with topic '$topic': " . pg_last_error($conn) . "</p>";
                        }
                    } else {
                        $skippedCount++;
                    }
                }
                fclose($handle);

                $message .= "<p>$rowCount records inserted/updated successfully from the CSV file.</p>";
                if ($skippedCount > 0) {
                    $message .= "<p>$skippedCount row(s) skipped because the topic or both descriptions were missing.</p>";
                }
                if ($conflictCount > 0) {
                    $message .= "<p>$conflictCount row(s) skipped because a knowledge class was used in both tiers.</p>";
                }
            } else {
                $message .= '<p>Error opening the CSV file.</p>';
            }
        } else {
            $message .= '<p>Upload failed. Allowed file types: ' . implode(',', $allowedfileExtensions) . '</p>';
        }
    } else {
        $message .= '<p>No file uploaded or there was an upload error.</p>';
    }
}

/********************************************************************
 *  3) DOWNLOAD EXAMPLE CSV
 ********************************************************************/
if (isset($_GET['action']) && $_GET['action'] === 'download_example') {
    $filePath = realpath(__DIR__ . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'worldknowledge_example.csv');
    if (file_exists($filePath)) {
        header('Content-Description: File Transfer');
        header('Content-Type: text/csv');
        header('Content-Disposition: attachment; filename="worldknowledge_example.csv"');
        header('Expires: 0');
        header('Cache-Control: must-revalidate');
        header('Pragma: public');
        header('Content-Length: ' . filesize($filePath));
        if (ob_get_length()) ob_end_clean();
        flush();
        readfile($filePath);
        exit;
    } else {
        $message .= '<p>Example CSV file not found.</p>';
    }
}

/********************************************************************
 *  3.5) EXPORT CUSTOM ENTRIES AS CSV
 ********************************************************************/
if (isset($_GET['action']) && $_GET['action'] === 'export_custom') {
    // Same eight-column Herika order the importer reads, so an
    // export can be edited and uploaded straight back. Factory rows are owned by
    // the catalog and are deliberately not exported here.
    $exportColumns = [
        'topic', 'aliases', 'topic_desc', 'knowledge_class', 'topic_desc_basic',
        'knowledge_class_basic', 'tags', 'category',
    ];
    $exportResult = @pg_query(
        $conn,
        "SELECT " . implode(', ', $exportColumns) . "
           FROM {$schema}.worldknowledge
          WHERE source_kind = 'custom' AND is_active
          ORDER BY canonical_topic"
    );

    if ($exportResult) {
        header('Content-Description: File Transfer');
        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="worldknowledge_custom_export.csv"');
        header('Expires: 0');
        header('Cache-Control: must-revalidate');
        header('Pragma: public');
        if (ob_get_length()) ob_end_clean();
        $output = fopen('php://output', 'w');
        fputcsv($output, $exportColumns);
        while ($exportRow = pg_fetch_assoc($exportResult)) {
            $line = [];
            foreach ($exportColumns as $column) {
                $line[] = (string)($exportRow[$column] ?? '');
            }
            fputcsv($output, $line);
        }
        fclose($output);
        exit;
    }

    $message .= '<p>Could not export custom entries: ' . htmlspecialchars(pg_last_error($conn)) . '</p>';
}

/********************************************************************
 *  4) DELETE ALL
 ********************************************************************/
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete_all') {
    $truncateQuery = "DELETE FROM {$schema}.worldknowledge WHERE source_kind = 'custom'";
    $truncateResult = pg_query($conn, $truncateQuery);

    if ($truncateResult) {
        $message .= "<p style='color: #ff6464; font-weight: bold;'>All custom World Knowledge entries have been deleted. The factory catalog was preserved.</p>";
    } else {
        $message .= "<p>Error deleting entries: " . pg_last_error($conn) . "</p>";
    }
}

/********************************************************************
 *  4.5) DELETE SINGLE TOPIC
 ********************************************************************/
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete_single') {
    $topic = $_POST['topic'] ?? '';
    
    if (!empty($topic)) {
        $query = "DELETE FROM {$schema}.worldknowledge WHERE topic = $1 AND source_kind = 'custom'";
        $result = pg_query_params($conn, $query, [$topic]);

        if ($result) {
            $message .= "<p>Entry '$topic' has been deleted successfully.</p>";
            
            header('Location: ' . worldknowledge_entries_url());
            exit;
        } else {
            $message .= "<p>Error deleting entry: " . pg_last_error($conn) . "</p>";
        }
    } else {
        $message .= "<p>No topic specified for deletion.</p>";
    }
}

/********************************************************************
 * (A) UPDATE SINGLE ROW (SAVE after Edit)
 ********************************************************************/
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_single') {
    // Sanitize and read posted fields - use htmlspecialchars_decode to convert HTML entities back
    $topic_original       = $_POST['topic_original'] ?? '';
    $topic_new           = worldknowledge_normalize_topic_key(htmlspecialchars_decode($_POST['topic_new'] ?? ''));
    $aliases_input       = htmlspecialchars_decode($_POST['aliases_new'] ?? '');
    $filtered_aliases    = worldknowledge_filter_alias_input($conn, $schema, $topic_new, $aliases_input);
    $aliases_new         = $filtered_aliases['aliases'];
    foreach ($filtered_aliases['rejected'] as $rejectedAlias) {
        $message .= '<p>Alias skipped: ' . htmlspecialchars($rejectedAlias['alias'])
            . ' (' . htmlspecialchars($rejectedAlias['reason']) . ')</p>';
    }
    $topic_desc_new      = htmlspecialchars_decode($_POST['topic_desc_new'] ?? '');
    $knowledge_class_new = dialecticWorldKnowledgeNormalizeAccessRule(htmlspecialchars_decode($_POST['knowledge_class_new'] ?? ''));
    $topic_desc_basic_new = htmlspecialchars_decode($_POST['topic_desc_basic_new'] ?? '');
    $knowledge_class_basic_new = dialecticWorldKnowledgeNormalizeAccessRule(htmlspecialchars_decode($_POST['knowledge_class_basic_new'] ?? ''));
    $tags_new            = htmlspecialchars_decode($_POST['tags_new'] ?? '');
    $category_new        = htmlspecialchars_decode($_POST['category_new'] ?? '');
    $canonical_topic_new = dialecticWorldKnowledgeCanonicalTopic($topic_new);
    $class_conflict      = worldknowledge_access_rule_conflicts($knowledge_class_new, $knowledge_class_basic_new);

    if ($class_conflict !== '') {
        $message .= '<p>' . htmlspecialchars($class_conflict) . '</p>';
    } elseif (!empty($topic_new) && worldknowledge_has_description($topic_desc_new, $topic_desc_basic_new)) {
        // Perform the update
        $update_sql = "
            UPDATE $schema.worldknowledge
            SET 
                topic = $1,
                aliases = $2,
                topic_desc = $3,
                knowledge_class = $4,
                topic_desc_basic = $5,
                knowledge_class_basic = $6,
                tags = $7,
                category = $8,
                canonical_topic = $9,
                updated_at = CURRENT_TIMESTAMP
            WHERE topic = $10 AND source_kind = 'custom'
        ";

        $update_result = pg_query_params($conn, $update_sql, [
            $topic_new,
            $aliases_new,
            $topic_desc_new,
            $knowledge_class_new,
            $topic_desc_basic_new,
            $knowledge_class_basic_new,
            $tags_new,
            $category_new,
            $canonical_topic_new,
            $topic_original
        ]);

        if ($update_result) {
            $message .= "<p>Row updated successfully for topic <strong>$topic_original</strong>.</p>";

            // Update the native_vector
            $vector_sql = "
                UPDATE $schema.worldknowledge
                SET native_vector = 
                      setweight(to_tsvector(coalesce(topic, '')), 'A')
                    || setweight(to_tsvector(coalesce(aliases, '')), 'A')
                    || setweight(to_tsvector(coalesce(topic_desc, '')), 'B')
                    || setweight(to_tsvector(coalesce(topic_desc_basic, '')), 'C')
                WHERE canonical_topic = $1 AND source_kind = 'custom'
            ";
            pg_query_params($conn, $vector_sql, [$canonical_topic_new]);

            // Exit edit mode while retaining the current table filters and page.
            header('Location: ' . worldknowledge_entries_url());
            exit;
        } else {
            $message .= "<p>Error updating row: " . pg_last_error($conn) . "</p>";
        }
    } else {
        $message .= '<p>A topic and at least one description are required when saving.</p>';
    }
}

?>

<link rel="stylesheet" href="<?php echo $webRoot; ?>/ui/css/main.css">
<style>
    /* Override main container styles */
    main {
        padding-top: 20px; /* Top padding */
        padding-bottom: 40px; /* Reduced space for footer */
        padding-left: 10%;
        padding-right: 10%;
        width: 100%;
        margin: 0;
    }
    
    /* Override footer styles */
    footer {
        position: fixed;
        bottom: 0;
        width: 100%;
        height: 20px; /* Reduced footer height */
        background: #031633;
        z-index: 100;
    }

    /* Tab Navigation */
    .tab-navigation {
        display: flex;
        border-bottom: 2px solid #4a4a4a;
        margin-bottom: 28px;
        background: linear-gradient(180deg, rgba(42, 42, 42, 0.95), rgba(34, 34, 34, 0.98));
        border-radius: 10px 10px 0 0;
        border: 1px solid #3a3a3a;
        border-bottom: none;
        box-shadow: 0 2px 4px rgba(0, 0, 0, 0.15);
    }

    .tab-button {
        flex: 1;
        padding: 16px 20px;
        background: transparent;
        color: rgb(255, 182, 65);
        border: none;
        cursor: pointer;
        font-family: 'Gothic821', serif;
        font-size: 17px;
        font-weight: bold;
        word-spacing: 8px;
        transition: all 0.3s ease;
        border-radius: 10px 10px 0 0;
        text-shadow: 1px 1px 2px rgba(0,0,0,0.5);
        position: relative;
    }

    .tab-button:first-child {
        border-right: 1px solid rgba(74, 74, 74, 0.5);
    }

    .tab-button.active {
        background: linear-gradient(180deg, rgba(255, 182, 65, 0.15), rgba(255, 182, 65, 0.05));
        color: rgb(255, 182, 65);
        font-weight: bold;
        text-shadow: 1px 1px 3px rgba(255, 182, 65, 0.3);
        box-shadow: inset 0 -3px 0 rgb(255, 182, 65);
    }

    .tab-button:hover:not(.active) {
        background: rgba(74, 74, 74, 0.3);
        color: rgb(255, 140, 30);
    }

    /* Tab Content */
    .tab-content {
        display: none;
        animation: fadeIn 0.3s ease-in;
    }

    .tab-content.active {
        display: block;
    }

    @keyframes fadeIn {
        from { opacity: 0; }
        to { opacity: 1; }
    }

    /* Content Layout Improvements */
    .content-grid {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 30px;
        margin-bottom: 30px;
    }

    .content-section {
        background: linear-gradient(180deg, rgba(42, 42, 42, 0.95), rgba(34, 34, 34, 0.98));
        padding: 22px;
        border-radius: 10px;
        border: 1px solid #3a3a3a;
        box-shadow: 0 2px 8px rgba(0, 0, 0, 0.15),
                    inset 0 1px rgba(255, 255, 255, 0.03);
        transition: border-color 0.2s ease;
    }

    .content-section:hover {
        border-color: #4a4a4a;
    }

    .content-section h2 {
        font-family: 'Gothic821', serif;
        color: rgb(255, 182, 65);
        text-shadow: 1px 1px 2px rgba(0,0,0,0.5);
        word-spacing: 6px;
        margin-bottom: 18px;
        font-size: 1.35em;
        padding-bottom: 12px;
        border-bottom: 1px solid rgba(255, 182, 65, 0.2);
    }

    .full-width-section {
        grid-column: 1 / -1;
    }

    .full-width-section h2 {
        font-family: 'Gothic821', serif;
        color: rgb(255, 182, 65);
        text-shadow: 1px 1px 2px rgba(0,0,0,0.5);
        word-spacing: 6px;
        margin-bottom: 18px;
        font-size: 1.5em;
        text-align: center;
        padding-bottom: 14px;
        border-bottom: 1px solid rgba(255, 182, 65, 0.2);
    }

    /* Form Improvements */
    .form-container {
        background: linear-gradient(180deg, rgba(42, 42, 42, 0.95), rgba(34, 34, 34, 0.98));
        padding: 22px;
        border-radius: 10px;
        border: 1px solid #3a3a3a;
        margin-bottom: 20px;
        box-shadow: 0 2px 8px rgba(0, 0, 0, 0.15),
                    inset 0 1px rgba(255, 255, 255, 0.03);
    }

    .content-section label,
    .form-container label {
        display: block;
        font-size: 13px;
        color: rgb(255, 182, 65);
        font-weight: 600;
        margin-bottom: 8px;
        margin-top: 14px;
    }

    .content-section label:first-of-type,
    .form-container label:first-of-type {
        margin-top: 0;
    }

    .content-section input[type="text"],
    .content-section input[type="file"],
    .content-section textarea,
    .form-container input[type="text"],
    .form-container input[type="file"],
    .form-container textarea {
        background-color: rgba(26, 26, 26, 0.8);
        color: #e9efff;
        border: 1px solid #3a3a3a;
        padding: 10px 12px;
        border-radius: 6px;
        width: 100%;
        margin-bottom: 8px;
        transition: all 0.2s ease;
    }

    .content-section input:focus,
    .content-section textarea:focus,
    .form-container input:focus,
    .form-container textarea:focus {
        border-color: rgba(255, 182, 65, 0.5);
        outline: none;
        box-shadow: 0 0 0 3px rgba(255, 182, 65, 0.1);
    }

    .content-section p {
        color: #aaa;
        font-size: 0.95em;
        line-height: 1.5;
        margin: 8px 0;
    }

    .content-section code {
        background: rgba(26, 26, 26, 0.8);
        padding: 2px 6px;
        border-radius: 3px;
        color: #ffeb3b;
        font-family: 'Courier New', monospace;
        font-size: 0.9em;
    }

    .button-group {
        display: flex;
        gap: 15px;
        margin-top: 15px;
        flex-wrap: wrap;
    }

    /* Font Face Declaration */
    @font-face {
        font-family: 'Gothic821';
        src: url('<?php echo $webRoot; ?>/ui/css/font/Gothic821CondensedRegular.otf') format('opentype');
        font-weight: normal;
        font-style: normal;
    }

    /* Header Styling */
    .page-header {
        text-align: center;
        margin-bottom: 28px;
        padding: 24px 20px;
        background: linear-gradient(180deg, rgba(42, 42, 42, 0.95), rgba(28, 28, 28, 0.98));
        border-radius: 10px;
        border: 1px solid #3a3a3a;
        box-shadow: 0 2px 8px rgba(0, 0, 0, 0.2);
    }

    .page-header h1 {
        margin-bottom: 10px;
        font-family: 'Gothic821', serif;
        word-spacing: 8px;
        font-size: 2em;
        color: rgb(255, 182, 65);
        text-shadow: 2px 2px 4px rgba(0,0,0,0.5);
    }

    .page-header p {
        color: #aaa;
        font-size: 0.95em;
        margin: 8px 0;
        line-height: 1.5;
    }

    /* Compact header intro */
    #worldknowledge-header-content > p {
        max-width: 720px;
        margin-left: auto;
        margin-right: auto;
    }

    #title-text {
        font-family: 'Gothic821', serif;
    }

    /* Header content transitions */
    #header-content > div {
        transition: opacity 0.3s ease-in-out;
        opacity: 1;
    }

    #title-text {
        transition: all 0.3s ease-in-out;
    }

    /* Compact collapsed help. Used for the header explainer, the batch upload
       tips, and the installed factory catalog list. */
    .header-note {
        max-width: 720px;
        margin: 12px auto 0;
        padding: 8px 12px;
        text-align: left;
        background: rgba(26, 26, 26, 0.6);
        border: 1px solid #3a3a3a;
        border-left: 3px solid rgb(255, 182, 65);
        border-radius: 4px;
    }

    .content-section .header-note {
        max-width: none;
        margin: 12px 0 0;
    }

    .header-note > summary {
        cursor: pointer;
        color: rgb(255, 182, 65);
        font-size: 0.95em;
    }

    .header-note > summary:focus-visible {
        outline: 2px solid rgb(255, 182, 65);
        outline-offset: 2px;
    }

    .header-note p {
        margin: 8px 0 0;
        color: #aaa;
        font-size: 0.95em;
        line-height: 1.5;
    }

    .header-note strong {
        color: rgb(255, 182, 65);
    }

    .header-note code {
        background: rgba(26, 26, 26, 0.8);
        padding: 2px 6px;
        border-radius: 3px;
        color: #ffeb3b;
        font-family: 'Courier New', monospace;
        font-size: 0.9em;
        overflow-wrap: break-word;
    }

    /* One installed factory catalog version, with its activation control. */
    .catalog-row {
        display: flex;
        align-items: center;
        justify-content: space-between;
        flex-wrap: wrap;
        gap: 10px;
        margin: 8px 0 0;
        padding: 8px 10px;
        background: rgba(26, 26, 26, 0.8);
        border: 1px solid #3a3a3a;
        border-radius: 4px;
        color: #aaa;
        font-size: 0.95em;
    }

    .catalog-row form {
        margin: 0;
    }

    /* Modal specific overrides */
    .modal-backdrop {
        overflow-y: auto !important;
        padding: 20px 0;
    }

    .modal-container {
        position: relative !important;
        top: auto !important;
        left: auto !important;
        transform: none !important;
        margin: 80px auto 40px auto !important;
        max-width: 800px !important;
        width: 90% !important;
        background: linear-gradient(180deg, rgba(42, 42, 42, 0.98), rgba(34, 34, 34, 0.98));
        border-radius: 12px;
        border: 1px solid #3a3a3a;
        box-shadow: 0 8px 32px rgba(0, 0, 0, 0.4);
    }

    .modal-header {
        padding: 20px 24px;
        border-bottom: 1px solid rgba(255, 182, 65, 0.2);
    }

    .modal-title {
        color: rgb(255, 182, 65);
        font-family: 'Gothic821', serif;
        font-size: 1.4em;
        margin: 0;
        text-shadow: 1px 1px 2px rgba(0, 0, 0, 0.5);
    }

    .modal-body {
        max-height: calc(100vh - 300px);
        overflow-y: auto;
        padding: 20px 24px;
        padding-right: 20px;
    }

    /* Form field spacing */
    .modal-body label {
        display: block;
        margin-top: 16px;
        color: rgb(255, 182, 65);
        font-weight: 600;
        font-size: 13px;
    }

    .modal-body label:first-of-type {
        margin-top: 0;
    }

    .modal-body small {
        display: block;
        color: #999;
        margin-bottom: 6px;
        font-size: 12px;
        line-height: 1.4;
    }

    .modal-body input[type="text"],
    .modal-body input[type="number"],
    .modal-body textarea {
        width: 100%;
        margin-bottom: 12px;
        background-color: rgba(26, 26, 26, 0.8);
        color: #e9efff;
        border: 1px solid #3a3a3a;
        padding: 10px 12px;
        border-radius: 6px;
        transition: all 0.2s ease;
    }

    .modal-body input:focus,
    .modal-body textarea:focus {
        border-color: rgba(255, 182, 65, 0.5);
        outline: none;
        box-shadow: 0 0 0 3px rgba(255, 182, 65, 0.1);
    }

    .modal-footer {
        position: sticky;
        bottom: 0;
        background: rgba(42, 42, 42, 0.98);
        padding: 16px 24px;
        margin-top: 20px;
        border-top: 1px solid rgba(255, 182, 65, 0.2);
        border-radius: 0 0 12px 12px;
        display: flex;
        gap: 10px;
        justify-content: flex-end;
    }

    /* Table container height adjustment. The surface is a flat panel so the many
       tinted cues inside it (group headers, rule chips) stay legible. */
    .table-container {
        max-height: calc(100vh - 450px) !important;
        margin-top: 20px;
        width: 100%;
        overflow-x: auto;
        background: rgba(34, 34, 34, 0.98);
        border-radius: 10px;
        border: 1px solid #3a3a3a;
        box-shadow: 0 2px 8px rgba(0, 0, 0, 0.15);
        padding: 10px;
    }

    /* Table styling improvements */
    .table-container table {
        width: 100%;
        table-layout: fixed;
        border-collapse: collapse;
    }

    .table-container th {
        padding: 9px 8px;
        font-weight: bold;
        text-align: left;
        vertical-align: top;
        color: rgb(255, 182, 65);
        background: rgba(26, 26, 26, 0.6);
        border-bottom: 2px solid rgba(255, 182, 65, 0.3);
        font-size: 0.95em;
    }

    .table-container td {
        word-wrap: break-word;
        overflow-wrap: break-word;
        hyphens: auto;
        vertical-align: top;
        padding: 7px 8px;
        line-height: 1.45;
        border-bottom: 1px solid rgba(74, 74, 74, 0.3);
        color: #d0d0d0;
    }

    .table-container tr:hover td {
        background: rgba(255, 182, 65, 0.05);
    }

    /* Column widths come from <colgroup> because the two-row header means
       nth-child rules no longer line up with a single cell per column.
       min-width keeps all eleven columns readable and lets .table-container
       scroll horizontally instead of crushing them. */
    .table-container table {
        min-width: 1300px;
    }

    .wk-col-topic      { width: 9%; }
    .wk-col-aliases    { width: 9%; }
    .wk-col-adv-desc   { width: 18%; }
    .wk-col-adv-rule   { width: 10%; }
    .wk-col-basic-desc { width: 15%; }
    .wk-col-basic-rule { width: 10%; }
    .wk-col-tags       { width: 6%; }
    .wk-col-category   { width: 6%; }
    .wk-col-action     { width: 5%; }

    .entries-pager {
        display: flex;
        flex-wrap: wrap;
        justify-content: space-between;
        align-items: center;
        gap: 10px;
        margin: 12px 0;
        color: #c6c6c6;
    }

    .entries-pager-controls {
        display: inline-flex;
        align-items: center;
        gap: 8px;
    }

    .entries-pager-link,
    .entries-pager-disabled {
        display: inline-block;
        border: 1px solid rgba(255, 182, 65, 0.35);
        border-radius: 6px;
        padding: 6px 10px;
        color: #f1f1f1;
        text-decoration: none;
        background: rgba(255, 255, 255, 0.03);
    }

    .entries-pager-disabled {
        opacity: 0.45;
    }

    .entries-per-page {
        background: #2b2b2b;
        color: #f8f9fa;
        border: 1px solid #555;
        border-radius: 5px;
        padding: 5px 7px;
    }

    .factory-read-only {
        display: inline-block;
        color: #b9b9b9;
        border: 1px solid #555;
        border-radius: 999px;
        padding: 3px 8px;
        white-space: nowrap;
        font-size: 0.82em;
    }

    .visually-hidden {
        position: absolute !important;
        width: 1px !important;
        height: 1px !important;
        padding: 0 !important;
        margin: -1px !important;
        overflow: hidden !important;
        clip: rect(0, 0, 0, 0) !important;
        white-space: nowrap !important;
        border: 0 !important;
    }

    /* Grouped header banding: Advanced and Basic are the same pair of columns
       (article + access rule), so they are labelled once and tinted apart. */
    .table-container th.wk-group {
        text-align: center;
        letter-spacing: 0.03em;
    }

    .table-container th.wk-group-advanced {
        color: rgb(255, 182, 65);
        background: rgba(255, 182, 65, 0.12);
    }

    .table-container th.wk-group-basic {
        color: #a8cdea;
        background: rgba(93, 145, 189, 0.14);
    }

    .table-container th.wk-sub {
        font-size: 0.9em;
        font-weight: 600;
    }

    /* Vertical rules mark where each knowledge group starts and ends. */
    .table-container th.wk-divide,
    .table-container td.wk-divide {
        border-left: 1px solid rgba(255, 255, 255, 0.12);
    }

    /* The topic cell is a row header for screen readers but must still read as
       a body cell, so the column-header chrome is undone here. */
    .table-container tbody th[scope="row"] {
        background: transparent;
        border-bottom: 1px solid rgba(74, 74, 74, 0.3);
        color: #f0e0c0;
        font-size: 1em;
        font-weight: 600;
        word-wrap: break-word;
        overflow-wrap: break-word;
    }

    .table-container tbody tr:hover th[scope="row"] {
        background: rgba(255, 182, 65, 0.05);
    }

    /* Knowledge-class chips. Classes are one flat any-of list, so the chips just
       wrap next to each other with no operator drawn between them. */
    .rule-classes {
        display: inline-flex;
        flex-wrap: wrap;
        align-items: center;
        gap: 2px;
        vertical-align: middle;
    }

    .rule-tag {
        display: inline-block;
        background: rgba(255, 182, 65, 0.18);
        border: 1px solid rgba(255, 182, 65, 0.35);
        color: rgb(255, 182, 65);
        padding: 2px 7px;
        margin: 2px;
        border-radius: 4px;
        font-size: 0.85em;
        font-weight: 500;
    }

    /* Basic classes use the cool accent so the two class columns never read alike. */
    .rule-tag-basic {
        background: rgba(93, 145, 189, 0.18);
        border-color: rgba(93, 145, 189, 0.45);
        color: #a8cdea;
    }

    /* A denial is one chip that reads "except raider": red, dashed, and never
       mistakable for the positive chips beside it. */
    .rule-tag-deny {
        background: rgba(255, 100, 100, 0.15);
        border-color: rgba(255, 100, 100, 0.55);
        border-style: dashed;
        color: #ff9a9a;
    }

    .rule-deny-word {
        font-style: italic;
        text-transform: lowercase;
        opacity: 0.9;
    }

    .rule-none {
        color: #b0b0b0;
        font-style: italic;
    }

    .rule-note {
        display: block;
        margin-top: 4px;
        color: #ffb0b0;
        font-size: 0.8em;
    }

    .scope-empty {
        color: #b0b0b0;
        font-style: italic;
    }

    /* Legend above the table explaining the two access modes. */
    .access-legend {
        display: flex;
        flex-wrap: wrap;
        gap: 14px;
        margin: 0 0 12px;
        padding: 12px 14px;
        background: rgba(26, 26, 26, 0.6);
        border: 1px solid #3a3a3a;
        border-radius: 8px;
    }

    .access-legend-item {
        flex: 1 1 260px;
        color: #c9c9c9;
        font-size: 0.88em;
        line-height: 1.5;
    }

    .access-legend-item b {
        display: block;
        margin-bottom: 2px;
    }

    .access-legend-advanced b { color: rgb(255, 182, 65); }
    .access-legend-basic b { color: #a8cdea; }

    .access-legend code {
        background: rgba(26, 26, 26, 0.9);
        padding: 1px 5px;
        border-radius: 3px;
        color: #ffeb3b;
        font-family: 'Courier New', monospace;
        font-size: 0.9em;
    }

    /* Status messages can carry several lines; keep the paragraph breaks. */
    .toast-notification .message {
        white-space: pre-line;
    }

    /* The entries table scrolls sideways, so it must be reachable by keyboard. */
    .table-container:focus-visible {
        outline: 2px solid rgba(255, 182, 65, 0.7);
        outline-offset: 2px;
    }

    /* Advanced / Basic field groups inside the entry modals. main.css resets
       fieldset chrome globally, so it is restored explicitly here. */
    .modal-body .access-group {
        margin: 18px 0;
        padding: 14px 16px 16px;
        background: rgba(26, 26, 26, 0.45);
        border: 1px solid #3a3a3a;
        border-left-width: 3px;
        border-radius: 8px;
    }

    .modal-body .access-group > legend {
        float: none;
        width: auto;
        padding: 0 8px;
        margin-bottom: 4px;
        font-family: 'Gothic821', serif;
        font-size: 1.05em;
        font-weight: bold;
        letter-spacing: 0.03em;
    }

    .modal-body .access-group-advanced {
        border-left-color: rgb(255, 182, 65);
    }

    .modal-body .access-group-advanced > legend {
        color: rgb(255, 182, 65);
    }

    .modal-body .access-group-basic {
        border-left-color: #5d91bd;
    }

    .modal-body .access-group-basic > legend {
        color: #a8cdea;
    }

    .modal-body .access-group-hint {
        margin: 0 0 14px;
        color: #b5b5b5;
        font-size: 12px;
        line-height: 1.5;
    }

    /* Responsive table for smaller screens. Below the table's min-width the
       container scrolls horizontally, so only density is adjusted here. */
    @media (max-width: 1200px) {
        .table-container {
            font-size: 0.9em;
        }
    }

    @media (max-width: 900px) {
        .table-container {
            font-size: 0.8em;
        }

        .table-container th,
        .table-container td {
            padding: 6px 4px;
        }

        .table-container table {
            min-width: 1160px;
        }
    }

    /* Filter improvements */
    .filter-section {
        background: linear-gradient(180deg, rgba(42, 42, 42, 0.95), rgba(34, 34, 34, 0.98));
        padding: 20px;
        border-radius: 10px;
        border: 1px solid #3a3a3a;
        margin-bottom: 20px;
        box-shadow: 0 2px 8px rgba(0, 0, 0, 0.15),
                    inset 0 1px rgba(255, 255, 255, 0.03);
    }

    .filter-section strong {
        color: rgb(255, 182, 65);
        font-size: 1.05em;
    }

    .action-container {
        display: flex;
        justify-content: space-between;
        align-items: center;
        flex-wrap: wrap;
        gap: 15px;
        margin-bottom: 20px;
        padding: 16px;
        background: linear-gradient(180deg, rgba(42, 42, 42, 0.95), rgba(34, 34, 34, 0.98));
        border-radius: 10px;
        border: 1px solid #3a3a3a;
        box-shadow: 0 2px 8px rgba(0, 0, 0, 0.15);
    }

    .search-container {
        display: flex;
        gap: 10px;
        min-width: 300px;
    }

    .search-container input[type="text"] {
        flex-grow: 1;
        padding: 10px 12px;
        border-radius: 6px;
        border: 1px solid #3a3a3a;
        background-color: rgba(26, 26, 26, 0.8);
        color: #e9efff;
        transition: all 0.2s ease;
    }

    .search-container input:focus {
        border-color: rgba(255, 182, 65, 0.5);
        outline: none;
        box-shadow: 0 0 0 3px rgba(255, 182, 65, 0.1);
    }

    /* Responsive Design */
    @media (max-width: 768px) {
        main {
            padding-left: 5%;
            padding-right: 5%;
        }
        
        .content-grid {
            grid-template-columns: 1fr;
        }
        
        .tab-button {
            padding: 12px 15px;
            font-size: 16px;
            color: rgb(255, 182, 65);
        }
        
        .search-container {
            min-width: 200px;
        }
        
        .action-container {
            flex-direction: column;
            align-items: stretch;
        }
        
        .page-header {
            padding: 15px;
        }
        
        .content-section {
            padding: 15px;
        }
        
        .header-note {
            padding: 8px 10px;
        }
    }

    @media (max-width: 480px) {
        main {
            padding-left: 2%;
            padding-right: 2%;
        }
        
        .page-header h1 {
            font-size: 1.5em;
        }
        
        .tab-button {
            padding: 10px 12px;
            font-size: 15px;
            color: rgb(255, 182, 65);
        }
        
        .header-note {
            padding: 8px;
            margin-top: 10px;
        }

        .catalog-row {
            flex-direction: column;
            align-items: stretch;
        }
    }
</style>

<?php if ($isEmbed): ?>
<style>
    /* Embedded in hub: remove extra top padding since navbar is hidden */
    main { padding-top: 20px; }
</style>
<?php endif; ?>

<main>
    <div id="toast" class="toast-notification" role="status" aria-live="polite">
        <span class="message"></span>
    </div>

    <div class="page-header">
        <h1 id="page-title">
            <img src="<?php echo $webRoot; ?>/ui/images/worldknowledge_infinium.png" alt="World Knowledge" style="vertical-align:bottom;" width="32" height="32"> 
            <span id="title-text">World Knowledge</span>
        </h1>
        
        <div id="header-content">
            <!-- Regular WorldKnowledge Content -->
            <div id="worldknowledge-header-content">
                <p>World Knowledge matches conversation topics to DIALECTIC's Fallout articles. NPCs receive the most detailed version their knowledge classes allow; if no version matches, they know nothing about the topic.</p>

                <details class="header-note">
                    <summary>How article search works</summary>
                    <p><strong>1. Grounded retrieval.</strong> Canonical topics and aliases are checked first, followed by compact and guarded speech matches.</p>
                    <p><strong>2. Advanced access check.</strong> <code>knowledge_class</code> controls expert or involved access. Write one comma-separated list of classes: any matching class grants that tier. A matching <code>!class</code> denies it first, and a blank list is unrestricted.</p>
                    <p><strong>3. Basic access check.</strong> <code>knowledge_class_basic</code> controls average-person access in the appropriate region or community, using the same flat class list.</p>
                    <p><strong>4. Bounded fallback.</strong> Only explicit unmatched lore requests may use one configured connector fallback, and suggestions must resolve back to this catalog.</p>
                </details>
            </div>
            
        </div>
    </div>

    <!-- Tab Navigation -->
    <div class="tab-navigation">
        <button class="tab-button active" onclick="switchTab('worldknowledge-tab')">
            &#x1F4DA; World Knowledge
        </button>
    </div>

    <!-- Regular WorldKnowledge Tab -->
    <div id="worldknowledge-tab" class="tab-content active">
        <div class="content-grid">
            <div class="content-section">
                <h2>Batch Upload</h2>
                <form action="" method="post" enctype="multipart/form-data">
                    <div>
                        <label for="csv_file">Select .csv file to upload:</label>
                        <input type="file" name="csv_file" id="csv_file" accept=".csv" required style="margin-top: 10px;">
                    </div>
                    <div class="button-group">
                        <input type="submit" name="submit_csv" value="Upload CSV" class="action-button upload-csv">
                        <a href="?action=download_example" class="action-button download-csv">Download Example CSV</a>
                        <a href="?action=export_custom" class="action-button download-csv">Export Custom Entries</a>
                    </div>
                </form>

                <p style="margin-top: 15px;">Uploads are saved as custom articles. A custom canonical topic safely overrides the active factory article without modifying factory data.</p>

                <details class="header-note">
                    <summary>Article editing tips</summary>
                    <p>Use lowercase topic titles with underscores instead of spaces &mdash; "Fishy Stick" becomes <code>fishy_stick</code>.</p>
                    <p>Columns are matched by header name:
                        <code>topic</code>, <code>aliases</code>, <code>topic_desc</code>, <code>knowledge_class</code>,
                        <code>topic_desc_basic</code>, <code>knowledge_class_basic</code>,
                        <code>tags</code>, <code>category</code>.
                        Export writes the same columns back, so an export can be edited and uploaded again.</p>
                </details>
            </div>

            <div class="content-section">
                <h2>Database Management</h2>
                <?php if ($activeCatalog || $installedCatalogs): ?>
                    <details class="header-note">
                        <summary>Factory catalog details</summary>
                        <?php if ($activeCatalog): ?>
                            <p><b>Active:</b>
                                <?php echo htmlspecialchars($activeCatalog['display_name'] ?? 'DIALECTIC Fallout'); ?>
                                <code><?php echo htmlspecialchars(($activeCatalog['catalog_id'] ?? '') . '/' . ($activeCatalog['catalog_version'] ?? '')); ?></code><br>
                                <?php echo intval($activeCatalog['row_count'] ?? 0); ?> articles &middot;
                                SHA-256 <code><?php echo htmlspecialchars(substr((string)($activeCatalog['checksum_sha256'] ?? ''), 0, 12)); ?>&hellip;</code>
                            </p>
                        <?php else: ?>
                            <p style="color:#ffb641;"><b>No active factory catalog.</b> Use Restore Factory Catalog.</p>
                        <?php endif; ?>
                        <?php foreach ($installedCatalogs as $catalog): ?>
                            <?php $catalogIsActive = in_array(strtolower((string)($catalog['is_active'] ?? '')), ['1', 't', 'true', 'yes', 'on'], true); ?>
                            <div class="catalog-row">
                                <span>
                                    <b><?php echo htmlspecialchars($catalog['catalog_id'] . '/' . $catalog['catalog_version']); ?></b>
                                    &middot; <?php echo intval($catalog['row_count']); ?> articles
                                    <?php if ($catalogIsActive): ?>
                                        &middot; Active
                                    <?php endif; ?>
                                </span>
                                <?php if (!$catalogIsActive): ?>
                                    <form method="post" style="margin:0;">
                                        <input type="hidden" name="action" value="activate_catalog">
                                        <input type="hidden" name="catalog_id" value="<?php echo htmlspecialchars($catalog['catalog_id']); ?>">
                                        <input type="hidden" name="catalog_version" value="<?php echo htmlspecialchars($catalog['catalog_version']); ?>">
                                        <button class="action-button" type="submit" onclick="return confirm('Activate this installed factory catalog?');">Activate</button>
                                    </form>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    </details>
                <?php else: ?>
                    <p style="color:#ffb641;"><b>No active factory catalog.</b> Use Restore Factory Catalog.</p>
                <?php endif; ?>
                <p>Verify uploads: <br><b>Server Actions &rarr; Database Manager &rarr; dialectic &rarr; public &rarr; worldknowledge</b></p>
                <p>View retrieval decisions: <br><a href="<?php echo $webRoot; ?>/ui/worldknowledge_audit.php">World Knowledge Audit</a></p>
                
                <div class="button-group" style="margin-top: 20px;">
                    <form action="" method="post" style="display: inline;">
                        <input type="hidden" name="action" value="delete_all">
                        <input type="submit" class="btn-danger" value="Delete Custom Entries"
                               onclick="return confirm('Delete all custom World Knowledge entries? The factory catalog will be preserved.');">
                    </form>
                    
                    <form action="<?php echo $webRoot; ?>/ui/worldknowledge_reset.php" method="post" style="display: inline;">
                        <input type="submit" class="action-button" value="Restore Factory Catalog"
                    onclick="return confirm('Restore and activate the shipped DIALECTIC factory catalog? Custom articles will be preserved.');">
                    </form>
                </div>
            </div>
        </div>
        <div class="full-width-section">
            <?php
            /********************************************************************
             *  5) DISPLAY THE WORLDKNOWLEDGE ENTRIES
             ********************************************************************/
            // Fetch categories
            $catQuery = "SELECT DISTINCT category FROM $schema.worldknowledge_effective WHERE category IS NOT NULL AND category <> '' ORDER BY category";
            $catResult = pg_query($conn, $catQuery);
            $categories = [];
            if ($catResult) {
                while ($row = pg_fetch_assoc($catResult)) {
                    $categories[] = $row['category'];
                }
            }

            // Grab filters
            $selectedCategory = $_GET['cat']   ?? '';
            $letter          = strtoupper($_GET['letter'] ?? '');
            $searchTerm      = trim((string)($_GET['search'] ?? ''));
            $perPageAllowed  = [25, 50, 100];
            $perPageRaw      = intval($_GET['per_page'] ?? 50);
            $perPage         = in_array($perPageRaw, $perPageAllowed, true) ? $perPageRaw : 50;
            $page            = max(1, intval($_GET['page'] ?? 1));

            // Sorting
            $order = 'ASC';
            if (isset($_GET['order'])) {
                $requestedOrder = strtolower($_GET['order']);
                if ($requestedOrder === 'asc' || $requestedOrder === 'desc') {
                    $order = strtoupper($requestedOrder);
                }
            }
            ?>
            
            <h2 id="entries">&#x1F4CB; World Knowledge Entries</h2>
            
            <div class="action-container">
                <button onclick="openNewEntryModal()" class="action-button add-new">Add New Entry</button>
                <div class="search-container">
                    <label for="searchBox" class="visually-hidden">Search World Knowledge topics, aliases, and tags</label>
                    <input type="text" id="searchBox" value="<?php echo htmlspecialchars($searchTerm, ENT_QUOTES, 'UTF-8'); ?>" placeholder="Search topics, aliases, or tags..." style="flex-grow: 1; padding: 8px; border-radius: 4px; border: 1px solid #555555; background-color: #4a4a4a; color: #f8f9fa;">
                    <button onclick="applySearch()" class="action-button edit">Search</button>
                </div>
            </div>

            <div class="filter-section">
                <div style="margin-bottom: 15px;">
                    <strong>Filter by Category:</strong><br>
                    <div class="filter-buttons" style="margin-top: 10px;">
                        <a class="alphabet-button" href="<?php echo htmlspecialchars(worldknowledge_entries_url([
                            'cat' => null,
                            'letter' => null,
                            'search' => null,
                            'order' => null,
                            'per_page' => null,
                            'page' => null,
                        ]), ENT_QUOTES, 'UTF-8'); ?>">All Categories</a>
                        <?php
                        foreach ($categories as $cat) {
                            $style = ($selectedCategory === $cat) ? 'style="background-color:#0056b3;"' : '';
                            $categoryUrl = worldknowledge_entries_url([
                                'cat' => $cat,
                                'letter' => null,
                                'search' => null,
                                'order' => null,
                                'per_page' => null,
                                'page' => null,
                            ]);
                            echo '<a class="alphabet-button" ' . $style . ' href="'
                                . htmlspecialchars($categoryUrl, ENT_QUOTES, 'UTF-8') . '">'
                                . htmlspecialchars($cat) . '</a>';
                        }
                        ?>
                    </div>
                </div>
                
                <div>
                    <strong>Sort Order:</strong><br>
                    <div style="margin-top: 10px;">
                        <a class="alphabet-button" href="<?php echo htmlspecialchars(worldknowledge_entries_url(['order' => 'asc', 'page' => 1]), ENT_QUOTES, 'UTF-8'); ?>">&#x1F53C; Ascending</a>
                        <a class="alphabet-button" href="<?php echo htmlspecialchars(worldknowledge_entries_url(['order' => 'desc', 'page' => 1]), ENT_QUOTES, 'UTF-8'); ?>">&#x1F53D; Descending</a>
                    </div>
                </div>
            </div>

            <?php
            // Count and fetch one bounded page using exactly the same filters.
            $conditions = [];
            $params = [];
            if ($selectedCategory) {
                $params[] = $selectedCategory;
                $conditions[] = 'category = $' . count($params);
            }
            if ($letter) {
                $params[] = $letter . '%';
                $conditions[] = 'topic ILIKE $' . count($params);
            }
            if ($searchTerm) {
                $params[] = '%' . $searchTerm . '%';
                $conditions[] = '(topic ILIKE $' . count($params)
                    . ' OR aliases ILIKE $' . count($params)
                    . ' OR tags ILIKE $' . count($params)
                    . ')';
            }
            $whereSql = $conditions ? ' WHERE ' . implode(' AND ', $conditions) : '';
            $countResult = pg_query_params(
                $conn,
                "SELECT COUNT(*) AS total FROM $schema.worldknowledge_effective" . $whereSql,
                $params
            );
            $totalEntries = $countResult ? intval(pg_fetch_result($countResult, 0, 'total')) : 0;
            $totalPages = max(1, intval(ceil($totalEntries / $perPage)));
            $page = min($page, $totalPages);
            $offset = ($page - 1) * $perPage;
            $rangeStart = $totalEntries > 0 ? $offset + 1 : 0;
            $rangeEnd = min($offset + $perPage, $totalEntries);

            $query = "SELECT topic, aliases, topic_desc, knowledge_class, topic_desc_basic,
                             knowledge_class_basic, tags, category, source_kind
                        FROM $schema.worldknowledge_effective"
                . $whereSql
                . " ORDER BY topic $order LIMIT " . intval($perPage) . " OFFSET " . intval($offset);

            $result = pg_query_params($conn, $query, $params);

            echo '<a id="entries"></a>';
            ?>
            <div class="entries-pager" aria-label="World Knowledge pagination">
                <div>
                    Showing <?php echo intval($rangeStart); ?>-<?php echo intval($rangeEnd); ?>
                    of <?php echo intval($totalEntries); ?> entries
                </div>
                <form method="get" action="" class="entries-pager-controls">
                    <?php foreach (['cat', 'letter', 'search', 'order'] as $filterKey): ?>
                        <?php if (isset($_GET[$filterKey]) && trim((string)$_GET[$filterKey]) !== ''): ?>
                            <input type="hidden" name="<?php echo htmlspecialchars($filterKey); ?>" value="<?php echo htmlspecialchars((string)$_GET[$filterKey], ENT_QUOTES, 'UTF-8'); ?>">
                        <?php endif; ?>
                    <?php endforeach; ?>
                    <input type="hidden" name="page" value="1">
                    <label for="entriesPerPage">Per page</label>
                    <select id="entriesPerPage" class="entries-per-page" name="per_page" onchange="this.form.submit()">
                        <?php foreach ($perPageAllowed as $option): ?>
                            <option value="<?php echo intval($option); ?>" <?php echo $perPage === $option ? 'selected' : ''; ?>><?php echo intval($option); ?></option>
                        <?php endforeach; ?>
                    </select>
                </form>
                <div class="entries-pager-controls">
                    <?php if ($page > 1): ?>
                        <a class="entries-pager-link" href="<?php echo htmlspecialchars(worldknowledge_entries_url(['page' => $page - 1]), ENT_QUOTES, 'UTF-8'); ?>">Previous</a>
                    <?php else: ?>
                        <span class="entries-pager-disabled" aria-disabled="true">Previous</span>
                    <?php endif; ?>
                    <span>Page <?php echo intval($page); ?> / <?php echo intval($totalPages); ?></span>
                    <?php if ($page < $totalPages): ?>
                        <a class="entries-pager-link" href="<?php echo htmlspecialchars(worldknowledge_entries_url(['page' => $page + 1]), ENT_QUOTES, 'UTF-8'); ?>">Next</a>
                    <?php else: ?>
                        <span class="entries-pager-disabled" aria-disabled="true">Next</span>
                    <?php endif; ?>
                </div>
            </div>
            <?php
            // tabindex makes the horizontally scrolling table reachable by keyboard.
            echo '<div class="table-container" tabindex="0" role="region" aria-label="World Knowledge entries">';
            echo '<table>';
            echo '<colgroup>
                    <col class="wk-col-topic">
                    <col class="wk-col-aliases">
                    <col class="wk-col-adv-desc">
                    <col class="wk-col-adv-rule">
                    <col class="wk-col-basic-desc">
                    <col class="wk-col-basic-rule">
                    <col class="wk-col-tags">
                    <col class="wk-col-category">
                    <col class="wk-col-action">
                  </colgroup>';
            echo '<thead>
                  <tr>
                    <th scope="col">Topic</th>
                    <th scope="col">Aliases</th>
                    <th scope="col">Topic Description (Advanced)</th>
                    <th scope="col">Knowledge Class (Advanced)</th>
                    <th scope="col">Topic Description (Basic)</th>
                    <th scope="col">Knowledge Class (Basic)</th>
                    <th scope="col">Tags</th>
                    <th scope="col">Category</th>
                    <th scope="col">Action</th>
                  </tr>
                  </thead>';
            echo '<tbody>';

            if ($result) {
                $rowCount = 0;
                while ($row = pg_fetch_assoc($result)) {
                    $topic                = htmlspecialchars($row['topic']                ?? '');
                    $aliases              = htmlspecialchars($row['aliases']              ?? '');
                    $topic_desc           = htmlspecialchars($row['topic_desc']           ?? '');
                    $knowledge_class      = htmlspecialchars($row['knowledge_class']      ?? '');
                    $topic_desc_basic     = htmlspecialchars($row['topic_desc_basic']     ?? '');
                    $knowledge_class_basic= htmlspecialchars($row['knowledge_class_basic']?? '');
                    $tags                 = htmlspecialchars($row['tags']                 ?? '');
                    $category             = htmlspecialchars($row['category']             ?? '');
                    $sourceKind           = strtolower(trim((string)($row['source_kind'] ?? 'custom')));
                    // Raw (unescaped) values for chip rendering; the escaped copies above feed the edit modal.
                    $knowledgeClassRaw    = (string)($row['knowledge_class']       ?? '');
                    $knowledgeClassBasicRaw = (string)($row['knowledge_class_basic'] ?? '');

                    // Normal row display
                    echo '<tr>';
                    echo '<th scope="row">' . $topic . '</th>';
                    echo '<td>' . ($aliases !== '' ? $aliases : '<span class="scope-empty">None</span>') . '</td>';
                    echo '<td class="wk-divide">' . nl2br($topic_desc) . '</td>';

                    // Advanced knowledge classes, one flat any-of list of chips.
                    echo '<td>';
                    echo worldknowledge_render_access_rule($knowledgeClassRaw, 'advanced');
                    echo '</td>';

                    echo '<td class="wk-divide">' . nl2br($topic_desc_basic) . '</td>';

                    // Basic knowledge classes, one flat any-of list of chips.
                    echo '<td>';
                    echo worldknowledge_render_access_rule($knowledgeClassBasicRaw, 'basic');
                    echo '</td>';

                    echo '<td>' . nl2br($tags) . '</td>';
                    echo '<td>' . nl2br($category) . '</td>';

                    // Action column
                    echo '<td style="white-space: nowrap;">';
                    echo '<div style="display: flex; gap: 4px;">';
                    
                    if ($sourceKind === 'custom') {
                        echo '<button onclick="openEditModal(' .
                            htmlspecialchars(json_encode([
                                'topic' => $topic,
                                'aliases' => $aliases,
                                'topic_desc' => $topic_desc,
                                'knowledge_class' => $knowledge_class,
                                'topic_desc_basic' => $topic_desc_basic,
                                'knowledge_class_basic' => $knowledge_class_basic,
                                'tags' => $tags,
                                'category' => $category
                            ]), ENT_QUOTES, 'UTF-8') .
                            ')" class="action-button edit">Edit</button>';
                    } else {
                        echo '<span class="factory-read-only" title="Factory catalog articles cannot be edited here">Read-only</span>';
                    }
                    
                    echo '</div>';
                    echo '</td>';
                    echo '</tr>';

                    $rowCount++;
                }

                echo '</tbody>';
                echo '</table>';
                echo '</div>';

                if ($rowCount === 0) {
                    echo '<p>No entries found.</p>';
                }
            } else {
                // Close the table markup opened above before reporting the failure.
                echo '</tbody>';
                echo '</table>';
                echo '</div>';
                echo '<p>Error fetching WorldKnowledge entries: ' . htmlspecialchars(pg_last_error($conn)) . '</p>';
            }
            ?>
        </div>
    </div>

<div id="editModal" class="modal-backdrop" role="dialog" aria-modal="true" aria-labelledby="editModalTitle">
    <div class="modal-container">
        <div class="modal-header">
            <h2 class="modal-title" id="editModalTitle">Edit WorldKnowledge Entry</h2>
        </div>
        <div class="modal-body">
            <form action="" method="post" class="worldknowledge-entry-form">
                <input type="hidden" name="action" value="update_single">
                <input type="hidden" name="topic_original" id="edit_topic_original">

                <label for="edit_topic">Topic:</label>
                <small>Canonical lowercase topic identifier.</small>
                <input type="text" name="topic_new" id="edit_topic" required>

                <label for="edit_aliases">Aliases:</label>
                <small>Alternate names that should find this article. Separate aliases with commas.</small>
                <input type="text" name="aliases_new" id="edit_aliases">

                <fieldset class="access-group access-group-advanced">
                    <legend>Advanced knowledge</legend>
                    <p class="access-group-hint">The expert or personally-involved version of this article, and who may receive it.</p>

                    <label for="edit_topic_desc">Article:</label>
                    <small>The detailed, insider account of the subject.</small>
                    <textarea name="topic_desc_new" id="edit_topic_desc" rows="8"></textarea>

                    <label for="edit_knowledge_class">Knowledge classes:</label>
                    <small>One comma-separated list of plain lowercase classes, for example <code>doctor,medicine,doc_mitchell</code>. Any matching class grants advanced knowledge. A matching <code>!class</code> such as <code>!raider</code> denies it first. Leave blank to let every NPC receive it.</small>
                    <input type="text" name="knowledge_class_new" id="edit_knowledge_class">
                </fieldset>

                <fieldset class="access-group access-group-basic">
                    <legend>Basic knowledge</legend>
                    <p class="access-group-hint">The common-knowledge version of this article, and who may receive it.</p>

                    <label for="edit_topic_desc_basic">Article:</label>
                    <small>What an ordinary person in the right place would know about the subject.</small>
                    <textarea name="topic_desc_basic_new" id="edit_topic_desc_basic" rows="8"></textarea>

                    <label for="edit_knowledge_class_basic">Knowledge classes:</label>
                    <small>Same flat list. Limit average-person knowledge to the appropriate audience, for example <code>common,mojave</code>. Leave blank only when every NPC should know it.</small>
                    <input type="text" name="knowledge_class_basic_new" id="edit_knowledge_class_basic">
                </fieldset>

                <label for="edit_tags">Tags:</label>
                <small>Lowercase descriptive tags that support ranking and relationships after a topic is identified. Tags never acquire a topic on their own.</small>
                <input type="text" name="tags_new" id="edit_tags">

                <label for="edit_category">Category:</label>
                <small>Category for database searching.</small>
                <input type="text" name="category_new" id="edit_category">

                <div class="modal-footer">
                    <button type="submit" name="submit" value="update" class="btn-save">Save Changes</button>
                    <button type="button" onclick="deleteEntry()" class="btn-danger">Delete</button>
                    <button type="button" onclick="closeEditModal()" class="btn-base btn-cancel">Cancel</button>
                </div>
            </form>
        </div>
    </div>
</div>

<div id="newEntryModal" class="modal-backdrop" role="dialog" aria-modal="true" aria-labelledby="newEntryModalTitle">
    <div class="modal-container">
        <div class="modal-header">
            <h2 class="modal-title" id="newEntryModalTitle">Add New WorldKnowledge Entry</h2>
        </div>
        <div class="modal-body">
            <form action="" method="post" class="worldknowledge-entry-form">
                <input type="hidden" name="submit_individual" value="1">

                <label for="topic">Topic (required):</label>
                <small>Canonical lowercase topic identifier.</small>
                <input type="text" name="topic" id="topic" required>

                <label for="aliases">Aliases:</label>
                <small>Alternate names that should find this article. Separate aliases with commas.</small>
                <input type="text" name="aliases" id="aliases">

                <fieldset class="access-group access-group-advanced">
                    <legend>Advanced knowledge</legend>
                    <p class="access-group-hint">The expert or personally-involved version of this article, and who may receive it.</p>

                    <label for="topic_desc">Article:</label>
                    <small>The detailed, insider account of the subject.</small>
                    <textarea name="topic_desc" id="topic_desc" rows="5"></textarea>

                    <label for="knowledge_class">Knowledge classes:</label>
                    <small>One comma-separated list of plain lowercase classes, for example <code>doctor,medicine,doc_mitchell</code>. Any matching class grants advanced knowledge. A matching <code>!class</code> such as <code>!raider</code> denies it first. Leave blank to let every NPC receive it.</small>
                    <input type="text" name="knowledge_class" id="knowledge_class">
                </fieldset>

                <fieldset class="access-group access-group-basic">
                    <legend>Basic knowledge</legend>
                    <p class="access-group-hint">The common-knowledge version of this article, and who may receive it.</p>

                    <label for="topic_desc_basic">Article:</label>
                    <small>What an ordinary person in the right place would know about the subject.</small>
                    <textarea name="topic_desc_basic" id="topic_desc_basic" rows="5"></textarea>

                    <label for="knowledge_class_basic">Knowledge classes:</label>
                    <small>Same flat list. Limit average-person knowledge to the appropriate audience, for example <code>common,capital_wasteland</code>. Leave blank only when every NPC should know it.</small>
                    <input type="text" name="knowledge_class_basic" id="knowledge_class_basic">
                </fieldset>

                <label for="tags">Tags:</label>
                <small>Lowercase descriptive tags that support ranking and relationships after a topic is identified. Tags never acquire a topic on their own.</small>
                <input type="text" name="tags" id="tags">

                <label for="category">Category:</label>
                <small>Category for database searching.</small>
                <input type="text" name="category" id="category">

                <div class="modal-footer">
                    <button type="submit" class="btn-save">Save</button>
                    <button type="button" onclick="closeNewEntryModal()" class="btn-base btn-cancel">Cancel</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
// Define webRoot for JavaScript
var webRoot = '<?php echo $webRoot; ?>';

// Tab switching functionality
function switchTab(tabId) {
    // Hide all tab contents
    const tabContents = document.querySelectorAll('.tab-content');
    tabContents.forEach(tab => {
        tab.classList.remove('active');
    });
    
    // Remove active class from all tab buttons
    const tabButtons = document.querySelectorAll('.tab-button');
    tabButtons.forEach(button => {
        button.classList.remove('active');
    });
    
    // Show selected tab content
    const selectedTab = document.getElementById(tabId);
    if (selectedTab) {
        selectedTab.classList.add('active');
    }
    
    // Add active class to clicked button
    const clickedButton = event.target;
    clickedButton.classList.add('active');
    
    // Update header content based on active tab
    updateHeaderContent(tabId);
    
    // Store active tab in localStorage
    localStorage.setItem('activeWorldKnowledgeTab', tabId);
}

// Function to update header content based on tab
function updateHeaderContent(tabId) {
    const titleText = document.getElementById('title-text');
    const worldknowledgeContent = document.getElementById('worldknowledge-header-content');
    
    // Fade out current content
    worldknowledgeContent.style.opacity = '0';
    
    setTimeout(() => {
        titleText.textContent = 'World Knowledge';
        worldknowledgeContent.style.display = 'block';
        setTimeout(() => {
            worldknowledgeContent.style.opacity = '1';
        }, 50);
    }, 150);
}

// Restore active tab on page load
document.addEventListener('DOMContentLoaded', function() {
    const savedTab = localStorage.getItem('activeWorldKnowledgeTab');
    if (savedTab === 'worldknowledge-tab') {
        // Manually switch to saved tab
        switchTabDirectly(savedTab);
    } else {
        // Default to worldknowledge tab
        updateHeaderContent('worldknowledge-tab');
    }
});

// Function to switch tab without event dependency
function switchTabDirectly(tabId) {
    if (tabId !== 'worldknowledge-tab') {
        tabId = 'worldknowledge-tab';
    }
    // Hide all tab contents
    const tabContents = document.querySelectorAll('.tab-content');
    tabContents.forEach(tab => {
        tab.classList.remove('active');
    });
    
    // Remove active class from all tab buttons
    const tabButtons = document.querySelectorAll('.tab-button');
    tabButtons.forEach(button => {
        button.classList.remove('active');
    });
    
    // Show selected tab content
    const selectedTab = document.getElementById(tabId);
    if (selectedTab) {
        selectedTab.classList.add('active');
    }
    
    // Find and activate the corresponding button
    const buttons = document.querySelectorAll('.tab-button');
    buttons.forEach(button => {
        if (button.getAttribute('onclick') && button.getAttribute('onclick').includes(tabId)) {
            button.classList.add('active');
        }
    });
    
    // Update header content
    updateHeaderContent(tabId);
}

// Remembers which control opened the current modal so focus can go back to it.
let modalReturnFocus = null;

function openModal(modalId) {
    modalReturnFocus = document.activeElement;
    const modal = document.getElementById(modalId);
    modal.style.display = "block";
    document.body.style.overflow = "hidden";
    const firstField = modal.querySelector('input:not([type="hidden"]), textarea');
    if (firstField) {
        firstField.focus();
    }
}

function closeModal(modalId) {
    document.getElementById(modalId).style.display = "none";
    document.body.style.overflow = "auto";
    if (modalReturnFocus && typeof modalReturnFocus.focus === 'function') {
        modalReturnFocus.focus();
    }
    modalReturnFocus = null;
}

function openEditModal(data) {
    try {
        const decodeHTML = (html) => {
            const txt = document.createElement('textarea');
            txt.innerHTML = html;
            return txt.value;
        };

        document.getElementById("edit_topic_original").value = decodeHTML(data.topic);
        document.getElementById("edit_topic").value = decodeHTML(data.topic);
        document.getElementById("edit_aliases").value = decodeHTML(data.aliases || '');
        document.getElementById("edit_topic_desc").value = decodeHTML(data.topic_desc);
        document.getElementById("edit_knowledge_class").value = decodeHTML(data.knowledge_class);
        document.getElementById("edit_topic_desc_basic").value = decodeHTML(data.topic_desc_basic);
        document.getElementById("edit_knowledge_class_basic").value = decodeHTML(data.knowledge_class_basic);
        document.getElementById("edit_tags").value = decodeHTML(data.tags);
        document.getElementById("edit_category").value = decodeHTML(data.category);

        openModal("editModal");
    } catch (error) {
        console.error('Error in openEditModal:', error);
        alert('There was an error opening the edit form. Please try again.');
    }
}

function closeEditModal() {
    closeModal("editModal");
}

function openNewEntryModal() {
    openModal("newEntryModal");
}

function closeNewEntryModal() {
    closeModal("newEntryModal");
}

// The server accepts an advanced article, a basic article, or both. Enforce
// that same cross-field rule without incorrectly requiring either one alone.
document.querySelectorAll('.worldknowledge-entry-form').forEach(function(form) {
    const advanced = form.querySelector('textarea[name="topic_desc"], textarea[name="topic_desc_new"]');
    const basic = form.querySelector('textarea[name="topic_desc_basic"], textarea[name="topic_desc_basic_new"]');
    if (!advanced || !basic) {
        return;
    }
    const clearArticleError = function() {
        advanced.setCustomValidity('');
    };
    advanced.addEventListener('input', clearArticleError);
    basic.addEventListener('input', clearArticleError);
    form.addEventListener('submit', function(event) {
        clearArticleError();
        if (advanced.value.trim() === '' && basic.value.trim() === '') {
            event.preventDefault();
            advanced.setCustomValidity('Enter an advanced article, a basic article, or both.');
            advanced.reportValidity();
            advanced.focus();
        }
    });
});

// Escape closes whichever entry modal is currently open.
document.addEventListener('keydown', function(event) {
    if (event.key !== 'Escape') {
        return;
    }
    ['editModal', 'newEntryModal'].forEach(function(modalId) {
        const modal = document.getElementById(modalId);
        if (modal && modal.style.display === 'block') {
            closeModal(modalId);
        }
    });
});

function deleteEntry() {
    const topic = document.getElementById('edit_topic_original').value;
    if (confirm("Are you sure you want to delete: " + topic + "?")) {
        const form = document.createElement('form');
        form.method = 'POST';
        const currentCategory = new URLSearchParams(window.location.search).get('cat');
        form.action = currentCategory ? `?cat=${currentCategory}#entries` : '?#entries';
        form.innerHTML = `
            <input type="hidden" name="action" value="delete_single">
            <input type="hidden" name="topic" value="${topic}">
        `;
        document.body.appendChild(form);
        form.submit();
    }
}

function applySearch() {
    const searchTerm = document.getElementById("searchBox").value.trim();
    const urlParams = new URLSearchParams(window.location.search);
    
    // Update or add search parameter
    if (searchTerm) {
        urlParams.set("search", searchTerm);
    } else {
        urlParams.delete("search");
    }
    
    // Preserve existing parameters if they exist
    const currentCategory = urlParams.get("cat");
    const currentLetter = urlParams.get("letter");
    const currentOrder = urlParams.get("order");
    
    if (currentCategory) urlParams.set("cat", currentCategory);
    if (currentLetter) urlParams.set("letter", currentLetter);
    if (currentOrder) urlParams.set("order", currentOrder);
    urlParams.set("page", "1");
    
    // Create the new URL
    window.location.href = "?" + urlParams.toString() + "#entries";
}

// Add enter key support for the search box
document.getElementById("searchBox").addEventListener("keypress", function(e) {
    if (e.key === "Enter") {
        e.preventDefault();
        applySearch();
    }
});

// Set initial search box value from URL
window.addEventListener("load", function() {
    const urlParams = new URLSearchParams(window.location.search);
    const searchTerm = urlParams.get("search");
    if (searchTerm) {
        document.getElementById("searchBox").value = searchTerm;
    }
});

// Add toast notification JavaScript function
function showToast(message, duration = 5000) {
    const toast = document.getElementById('toast');
    const messageSpan = toast.querySelector('.message');
    messageSpan.textContent = message;
    toast.classList.add('show');
    
    setTimeout(() => {
        toast.classList.remove('show');
    }, duration);
}

// Update PHP message handling
<?php if (!empty($message)): ?>
<?php
// $message is a run of <p> chunks; strip_tags alone would glue them into one
// unreadable line, so paragraph breaks are preserved as newlines first.
$toastText = strip_tags(str_replace('</p>', "\n", $message));
$toastText = trim(preg_replace("/\n{2,}/", "\n", $toastText));
?>
document.addEventListener('DOMContentLoaded', function() {
    showToast(<?php echo json_encode($toastText); ?>);
});
<?php endif; ?>

</script>
</main>

<?php
include(__DIR__.DIRECTORY_SEPARATOR."tmpl/footer.html");

$buffer = ob_get_contents();
ob_end_clean();
$title = $TITLE;
$buffer = preg_replace('/(<title>)(.*?)(<\/title>)/i', '$1' . $title . '$3', $buffer);
echo $buffer;
?>
