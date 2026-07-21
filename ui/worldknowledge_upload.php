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

$dbSettings = dialecticDbConnectionSettings('dialectic');
$host = $dbSettings['host'];
$port = $dbSettings['port'];
$dbname = $dbSettings['dbname'];
$schema = $dbSettings['schema'];
$username = $dbSettings['username'];
$password = $dbSettings['password'];


// Initialize message variable
$message = '';

function worldknowledge_normalize_topic_key($value) {
    return strtolower(trim((string)$value));
}

function worldknowledge_has_description($topicDesc, $topicDescBasic) {
    return trim((string)$topicDesc) !== '' || trim((string)$topicDescBasic) !== '';
}

// Connect to the database
$conn = pg_connect(dialecticPgConnectionString($dbSettings));
if (!$conn) {
    echo "<div class='message'>Failed to connect to database: " . pg_last_error() . "</div>";
    exit;
}

/********************************************************************
 *  1) SINGLE TOPIC UPLOAD
 ********************************************************************/
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_individual'])) {
    // Collect and sanitize form inputs
    $topic                = worldknowledge_normalize_topic_key($_POST['topic'] ?? '');
    $topic_desc           = htmlspecialchars($_POST['topic_desc']           ?? '');
    $knowledge_class      = htmlspecialchars($_POST['knowledge_class']      ?? '');
    $topic_desc_basic     = htmlspecialchars($_POST['topic_desc_basic']     ?? '');
    $knowledge_class_basic= htmlspecialchars($_POST['knowledge_class_basic']?? '');
    $tags                 = htmlspecialchars($_POST['tags']                 ?? '');
    $category             = htmlspecialchars($_POST['category']             ?? '');

    if (!empty($topic) && worldknowledge_has_description($topic_desc, $topic_desc_basic)) {
        $query = "
            INSERT INTO $schema.worldknowledge (
                topic, 
                topic_desc, 
                knowledge_class, 
                topic_desc_basic, 
                knowledge_class_basic, 
                tags, 
                category
            )
            VALUES ($1, $2, $3, $4, $5, $6, $7)
            ON CONFLICT (topic)
            DO UPDATE SET
                topic_desc           = EXCLUDED.topic_desc,
                knowledge_class      = EXCLUDED.knowledge_class,
                topic_desc_basic     = EXCLUDED.topic_desc_basic,
                knowledge_class_basic= EXCLUDED.knowledge_class_basic,
                tags                 = EXCLUDED.tags,
                category             = EXCLUDED.category
        ";
        $result = pg_query_params($conn, $query, [
            $topic,
            $topic_desc,
            $knowledge_class,
            $topic_desc_basic,
            $knowledge_class_basic,
            $tags,
            $category
        ]);

        if ($result) {
            $message .= "<p>Data inserted/updated successfully!</p>";

            // Update native_vector
            $update_query = "
                UPDATE $schema.worldknowledge
                SET native_vector = 
                      setweight(to_tsvector(coalesce(topic, '')), 'A')
                    || setweight(to_tsvector(coalesce(topic_desc, '')), 'B')
                    || setweight(to_tsvector(coalesce(topic_desc_basic, '')), 'C')
                WHERE topic = $1
            ";
            $update_result = pg_query_params($conn, $update_query, [$topic]);

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
                while (($data = fgetcsv($handle, 0, ',')) !== false) {
                    if (count(array_filter($data, static function ($value) { return trim((string)$value) !== ''; })) === 0) {
                        continue;
                    }

                    $topic                = worldknowledge_normalize_topic_key(worldknowledge_csv_value($data, $headerMap, 'topic', 0));
                    $topic_desc           = trim(worldknowledge_csv_value($data, $headerMap, 'topic_desc', 1));
                    $knowledge_class      = trim(worldknowledge_csv_value($data, $headerMap, 'knowledge_class', 2));
                    $topic_desc_basic     = trim(worldknowledge_csv_value($data, $headerMap, 'topic_desc_basic', 3));
                    $knowledge_class_basic= trim(worldknowledge_csv_value($data, $headerMap, 'knowledge_class_basic', 4));
                    $tags                 = trim(worldknowledge_csv_value($data, $headerMap, 'tags', 5));
                    $category             = trim(worldknowledge_csv_value($data, $headerMap, 'category', 6));

                    if (!empty($topic) && worldknowledge_has_description($topic_desc, $topic_desc_basic)) {
                        $query = "
                            INSERT INTO $schema.worldknowledge (
                                topic,
                                topic_desc,
                                knowledge_class,
                                topic_desc_basic,
                                knowledge_class_basic,
                                tags,
                                category
                            )
                            VALUES ($1, $2, $3, $4, $5, $6, $7)
                            ON CONFLICT (topic)
                            DO UPDATE SET
                                topic_desc           = EXCLUDED.topic_desc,
                                knowledge_class      = EXCLUDED.knowledge_class,
                                topic_desc_basic     = EXCLUDED.topic_desc_basic,
                                knowledge_class_basic= EXCLUDED.knowledge_class_basic,
                                tags                 = EXCLUDED.tags,
                                category             = EXCLUDED.category
                        ";
                        $result = pg_query_params($conn, $query, [
                            $topic,
                            $topic_desc,
                            $knowledge_class,
                            $topic_desc_basic,
                            $knowledge_class_basic,
                            $tags,
                            $category
                        ]);

                        if ($result) {
                            $rowCount++;
                            // Update the native_vector for this single row
                            $update_query = "
                                UPDATE $schema.worldknowledge
                                SET native_vector = 
                                      setweight(to_tsvector(coalesce(topic, '')), 'A')
                                    || setweight(to_tsvector(coalesce(topic_desc, '')), 'B')
                                    || setweight(to_tsvector(coalesce(topic_desc_basic, '')), 'C')
                                WHERE topic = $1
                            ";
                            pg_query_params($conn, $update_query, [$topic]);
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
 *  4) DELETE ALL
 ********************************************************************/
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete_all') {
    $truncateQuery = "TRUNCATE TABLE {$schema}.worldknowledge RESTART IDENTITY";
    $truncateResult = pg_query($conn, $truncateQuery);

    if ($truncateResult) {
        $message .= "<p style='color: #ff6464; font-weight: bold;'>All WorldKnowledge entries have been deleted successfully.</p>";
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
        $query = "DELETE FROM {$schema}.worldknowledge WHERE topic = $1";
        $result = pg_query_params($conn, $query, [$topic]);

        if ($result) {
            $message .= "<p>Entry '$topic' has been deleted successfully.</p>";
            
            // Redirect to maintain filters
            $redirectUrl = '?' . http_build_query([
                'cat' => $_GET['cat'] ?? '',
                'letter' => $_GET['letter'] ?? '',
                'order' => $_GET['order'] ?? 'asc'
            ]) . '#entries';
            header('Location: ' . $redirectUrl);
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
    $topic_desc_new      = htmlspecialchars_decode($_POST['topic_desc_new'] ?? '');
    $knowledge_class_new = htmlspecialchars_decode($_POST['knowledge_class_new'] ?? '');
    $topic_desc_basic_new = htmlspecialchars_decode($_POST['topic_desc_basic_new'] ?? '');
    $knowledge_class_basic_new = htmlspecialchars_decode($_POST['knowledge_class_basic_new'] ?? '');
    $tags_new            = htmlspecialchars_decode($_POST['tags_new'] ?? '');
    $category_new        = htmlspecialchars_decode($_POST['category_new'] ?? '');

    if (!empty($topic_new) && worldknowledge_has_description($topic_desc_new, $topic_desc_basic_new)) {
        // Perform the update
        $update_sql = "
            UPDATE $schema.worldknowledge
            SET 
                topic = $1,
                topic_desc = $2,
                knowledge_class = $3,
                topic_desc_basic = $4,
                knowledge_class_basic = $5,
                tags = $6,
                category = $7
            WHERE topic = $8
        ";

        $update_result = pg_query_params($conn, $update_sql, [
            $topic_new,
            $topic_desc_new,
            $knowledge_class_new,
            $topic_desc_basic_new,
            $knowledge_class_basic_new,
            $tags_new,
            $category_new,
            $topic_original
        ]);

        if ($update_result) {
            $message .= "<p>Row updated successfully for topic <strong>$topic_original</strong>.</p>";

            // Update the native_vector
            $vector_sql = "
                UPDATE $schema.worldknowledge
                SET native_vector = 
                      setweight(to_tsvector(coalesce(topic, '')), 'A')
                    || setweight(to_tsvector(coalesce(topic_desc, '')), 'B')
                    || setweight(to_tsvector(coalesce(topic_desc_basic, '')), 'C')
                WHERE topic = $1
            ";
            pg_query_params($conn, $vector_sql, [$topic_new]);

            // Redirect to exit edit mode while maintaining filters
            $redirectUrl = '?' . http_build_query([
                'cat' => $_GET['cat'] ?? '',
                'letter' => $_GET['letter'] ?? '',
                'order' => $_GET['order'] ?? 'asc'
            ]) . '#entries';
            header('Location: ' . $redirectUrl);
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

    .page-header h3 {
        color: rgb(255, 182, 65);
        font-size: 1.1em;
        margin-top: 20px;
        margin-bottom: 8px;
    }

    .page-header h4 {
        color: #ccc;
        font-size: 1em;
        margin-bottom: 12px;
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

    /* Logic Section Styling */
    .logic-section {
        margin: 25px 0;
        padding: 22px;
        background: linear-gradient(135deg, rgba(26, 26, 26, 0.95), rgba(20, 20, 20, 0.98));
        border-radius: 10px;
        border: 1px solid rgba(255, 182, 65, 0.3);
        box-shadow: 0 4px 12px rgba(0,0,0,0.3),
                    inset 0 1px rgba(255, 182, 65, 0.05);
    }

    .logic-title {
        text-align: center;
        color: rgb(255, 182, 65);
        margin-bottom: 20px;
        font-size: 1.25em;
        font-weight: bold;
        text-shadow: 1px 1px 2px rgba(0,0,0,0.5);
        font-family: 'Gothic821', serif;
        word-spacing: 6px;
    }

    .logic-steps {
        display: grid;
        gap: 12px;
    }

    .logic-step {
        display: flex;
        align-items: flex-start;
        gap: 15px;
        padding: 16px;
        background: rgba(42, 42, 42, 0.8);
        border-radius: 8px;
        border-left: 4px solid rgb(255, 182, 65);
        transition: all 0.2s ease;
        box-shadow: 0 2px 4px rgba(0, 0, 0, 0.2);
    }

    .logic-step:hover {
        transform: translateX(4px);
        box-shadow: 0 4px 12px rgba(255, 182, 65, 0.25),
                    0 2px 8px rgba(0, 0, 0, 0.3);
    }

    .step-number {
        flex-shrink: 0;
        width: 32px;
        height: 32px;
        background: linear-gradient(135deg, rgb(255, 182, 65), rgb(212, 94, 0));
        color: #000;
        border-radius: 50%;
        display: flex;
        align-items: center;
        justify-content: center;
        font-weight: bold;
        font-size: 15px;
        box-shadow: 0 2px 6px rgba(255, 182, 65, 0.4),
                    inset 0 1px rgba(255, 255, 255, 0.3);
    }

    .step-content {
        flex: 1;
    }

    .step-content strong {
        color: rgb(255, 182, 65);
        display: block;
        margin-bottom: 6px;
        font-size: 1.05em;
    }

    .step-content p {
        margin: 0;
        line-height: 1.5;
        color: #d0d0d0;
    }

    .step-content code {
        background: rgba(74, 74, 74, 0.8);
        padding: 3px 7px;
        border-radius: 4px;
        color: #ffeb3b;
        font-family: 'Courier New', monospace;
        font-size: 0.9em;
        border: 1px solid rgba(255, 235, 59, 0.2);
    }

    .step-content em {
        color: #81c784;
        font-style: italic;
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

    /* Table container height adjustment */
    .table-container {
        max-height: calc(100vh - 450px) !important;
        margin-top: 20px;
        width: 100%;
        overflow-x: auto;
        background: linear-gradient(180deg, rgba(42, 42, 42, 0.95), rgba(34, 34, 34, 0.98));
        border-radius: 10px;
        border: 1px solid #3a3a3a;
        box-shadow: 0 2px 8px rgba(0, 0, 0, 0.15),
                    inset 0 1px rgba(255, 255, 255, 0.03);
        padding: 12px;
    }

    /* Table styling improvements */
    .table-container table {
        width: 100%;
        table-layout: fixed;
        border-collapse: collapse;
    }

    .table-container th {
        padding: 12px 10px;
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
        padding: 10px;
        line-height: 1.5;
        border-bottom: 1px solid rgba(74, 74, 74, 0.3);
        color: #d0d0d0;
    }

    .table-container tr:hover td {
        background: rgba(255, 182, 65, 0.05);
    }

    /* Column width optimization */
    .table-container th:nth-child(1), /* Topic */
    .table-container td:nth-child(1) {
        width: 12%;
        min-width: 120px;
    }

    .table-container th:nth-child(2), /* Topic Description */
    .table-container td:nth-child(2) {
        width: 25%;
        min-width: 200px;
    }

    .table-container th:nth-child(3), /* Knowledge Class */
    .table-container td:nth-child(3) {
        width: 12%;
        min-width: 120px;
    }

    .table-container th:nth-child(4), /* Topic Description (Basic) */
    .table-container td:nth-child(4) {
        width: 20%;
        min-width: 180px;
    }

    .table-container th:nth-child(5), /* Knowledge Class (Basic) */
    .table-container td:nth-child(5) {
        width: 12%;
        min-width: 120px;
    }

    .table-container th:nth-child(6), /* Tags */
    .table-container td:nth-child(6) {
        width: 8%;
        min-width: 80px;
    }

    .table-container th:nth-child(7), /* Category */
    .table-container td:nth-child(7) {
        width: 8%;
        min-width: 80px;
    }

    .table-container th:nth-child(8), /* Action */
    .table-container td:nth-child(8) {
        width: 8%;
        min-width: 80px;
    }

    /* Text wrapping and overflow handling */
    .table-container td {
        word-wrap: break-word;
        overflow-wrap: break-word;
        hyphens: auto;
        vertical-align: top;
        padding: 10px;
        line-height: 1.5;
        border-bottom: 1px solid rgba(74, 74, 74, 0.3);
        color: #d0d0d0;
    }

    .table-container th {
        padding: 12px 10px;
        font-weight: bold;
        text-align: left;
        vertical-align: top;
        color: rgb(255, 182, 65);
        background: rgba(26, 26, 26, 0.6);
        border-bottom: 2px solid rgba(255, 182, 65, 0.3);
        font-size: 0.95em;
    }

    /* Responsive table for smaller screens */
    @media (max-width: 1200px) {
        .table-container {
            font-size: 0.9em;
        }
        
        .table-container th:nth-child(2), /* Topic Description */
        .table-container td:nth-child(2) {
            width: 30%;
        }
        
        .table-container th:nth-child(4), /* Topic Description (Basic) */
        .table-container td:nth-child(4) {
            width: 25%;
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
        
        .logic-section {
            padding: 15px;
            margin: 15px 0;
        }
        
        .logic-step {
            padding: 12px;
            gap: 12px;
        }
        
        .step-number {
            width: 25px;
            height: 25px;
            font-size: 12px;
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
        
        .logic-section {
            padding: 10px;
            margin: 10px 0;
        }
        
        .logic-step {
            padding: 10px;
            gap: 10px;
            flex-direction: column;
            text-align: center;
        }
        
        .step-number {
            align-self: center;
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
    <div id="toast" class="toast-notification">
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
                <p>The <b>World Knowledge</b> is a "Fallout Encyclopedia" that AI NPC's will use to help them roleplay.</p>
                <p>This is done by detecting topics during conversations, and injecting the appropriate information into the AI's prompt.</p>
                
                <h3><strong>Ensure all topic titles are lowercase and spaces are replaced with underscores (_).</strong></h3>
                <h4>Example: "Fishy Stick" becomes "fishy_stick"</h4>
            <p>Knowledge classes control which uploaded Fallout world knowledge entries a character can access.</p>
                
                <div class="logic-section">
                    <h3 class="logic-title">&#x1F50D; Article Search Logic</h3>
                    <div class="logic-steps">
                        <div class="logic-step">
                            <div class="step-number">1</div>
                            <div class="step-content">
                                <strong>Keyword Search</strong>
                                <p>NPC searches for worldknowledge article based on most relevant keyword during conversations.</p>
                            </div>
                        </div>
                        <div class="logic-step">
                            <div class="step-number">2</div>
                            <div class="step-content">
                                <strong>Advanced Access Check</strong>
                                <p>Check <code>knowledge_class</code> to see if they have access to the advanced article (<code>topic_desc</code>)</p>
                            </div>
                        </div>
                        <div class="logic-step">
                            <div class="step-number">3</div>
                            <div class="step-content">
                                <strong>Basic Access Check</strong>
                                <p>Check <code>knowledge_class_basic</code> to see if they have access to the basic article (<code>topic_desc_basic</code>)</p>
                            </div>
                        </div>
                        <div class="logic-step">
                            <div class="step-number">4</div>
                            <div class="step-content">
                                <strong>Fallback Response</strong>
                                <p>If all above fails, send <em>"You do not know about X"</em> to the prompt</p>
                            </div>
                        </div>
                    </div>
                </div>
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
                    </div>
                </form>
                
                <p style="margin-top: 15px;">All uploaded topics will be saved into the <code>worldknowledge</code> table. This overwrites any existing entries with the same topic.</p>
            </div>

            <div class="content-section">
                <h2>Database Management</h2>
                <p>Verify uploads: <br><b>Server Actions &rarr; Database Manager &rarr; dialectic &rarr; public &rarr; worldknowledge</b></p>
                <p>View conversation usage: <br><b>Server Actions &rarr; Database Manager &rarr; dialectic &rarr; public &rarr; audit_memory</b></p>
                
                <div class="button-group" style="margin-top: 20px;">
                    <form action="" method="post" style="display: inline;">
                        <input type="hidden" name="action" value="delete_all">
                        <input type="submit" class="btn-danger" value="Delete All Entries" 
                               onclick="return confirm('Are you sure you want to delete ALL entries? This cannot be undone!');">
                    </form>
                    
                    <form action="<?php echo $webRoot; ?>/ui/worldknowledge_reset.php" method="post" style="display: inline;">
                        <input type="submit" class="btn-danger" value="Factory Reset Database" 
                    onclick="return confirm('Are you sure you want to reset the WorldKnowledge database to factory settings? This will delete all current entries and leave WorldKnowledge empty until you upload Dialectic-specific rows.');">
                    </form>
                </div>
                
                <p style="margin-top: 15px;">Download backup: <a href="https://discord.gg/NDn9qud2ug" target="_blank" rel="noopener" style="color: yellow;">Discord CSV files channel</a></p>
            </div>
        </div>
        <div class="full-width-section">
            <?php
            /********************************************************************
             *  5) DISPLAY THE WORLDKNOWLEDGE ENTRIES
             ********************************************************************/
            // Fetch categories
            $catQuery = "SELECT DISTINCT category FROM $schema.worldknowledge WHERE category IS NOT NULL AND category <> '' ORDER BY category";
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
                    <input type="text" id="searchBox" placeholder="Search topics..." style="flex-grow: 1; padding: 8px; border-radius: 4px; border: 1px solid #555555; background-color: #4a4a4a; color: #f8f9fa;">
                    <button onclick="applySearch()" class="action-button edit">Search</button>
                </div>
            </div>

            <div class="filter-section">
                <div style="margin-bottom: 15px;">
                    <strong>Filter by Category:</strong><br>
                    <div class="filter-buttons" style="margin-top: 10px;">
                        <a class="alphabet-button" href="?#entries">All Categories</a>
                        <?php
                        foreach ($categories as $cat) {
                            $catEncoded = urlencode($cat);
                            $style = ($selectedCategory === $cat) ? 'style="background-color:#0056b3;"' : '';
                            echo "<a class=\"alphabet-button\" $style href=\"?cat=$catEncoded#entries\">" . htmlspecialchars($cat) . "</a>";
                        }
                        ?>
                    </div>
                </div>
                
                <div>
                    <strong>Sort Order:</strong><br>
                    <?php
                    $baseUrl = '?';
                    if ($selectedCategory) $baseUrl .= 'cat=' . urlencode($selectedCategory) . '&';
                    if ($letter) $baseUrl .= 'letter=' . urlencode($letter) . '&';
                    ?>
                    <div style="margin-top: 10px;">
                        <a class="alphabet-button" href="<?php echo $baseUrl; ?>order=asc#entries">&#x1F53C; Ascending</a>
                        <a class="alphabet-button" href="<?php echo $baseUrl; ?>order=desc#entries">&#x1F53D; Descending</a>
                    </div>
                </div>
            </div>

            <?php
            // Build query
            $searchTerm = isset($_GET['search']) ? $_GET['search'] : '';

            if ($selectedCategory && $letter && $searchTerm) {
                $query = "
                    SELECT topic, topic_desc, knowledge_class, topic_desc_basic,
                           knowledge_class_basic, tags, category
                    FROM $schema.worldknowledge
                    WHERE category = $1
                      AND topic ILIKE $2
                      AND topic ILIKE $3
                    ORDER BY topic $order
                ";
                $params = [$selectedCategory, $letter . '%', '%' . $searchTerm . '%'];
            } elseif ($selectedCategory && $searchTerm) {
                $query = "
                    SELECT topic, topic_desc, knowledge_class, topic_desc_basic,
                           knowledge_class_basic, tags, category
                    FROM $schema.worldknowledge
                    WHERE category = $1
                      AND topic ILIKE $2
                    ORDER BY topic $order
                ";
                $params = [$selectedCategory, '%' . $searchTerm . '%'];
            } elseif ($letter && $searchTerm) {
                $query = "
                    SELECT topic, topic_desc, knowledge_class, topic_desc_basic,
                           knowledge_class_basic, tags, category
                    FROM $schema.worldknowledge
                    WHERE topic ILIKE $1
                      AND topic ILIKE $2
                    ORDER BY topic $order
                ";
                $params = [$letter . '%', '%' . $searchTerm . '%'];
            } elseif ($searchTerm) {
                $query = "
                    SELECT topic, topic_desc, knowledge_class, topic_desc_basic,
                           knowledge_class_basic, tags, category
                    FROM $schema.worldknowledge
                    WHERE topic ILIKE $1
                    ORDER BY topic $order
                ";
                $params = ['%' . $searchTerm . '%'];
            } elseif ($selectedCategory && $letter) {
                $query = "
                    SELECT topic, topic_desc, knowledge_class, topic_desc_basic,
                           knowledge_class_basic, tags, category
                    FROM $schema.worldknowledge
                    WHERE category = $1
                      AND topic ILIKE $2
                    ORDER BY topic $order
                ";
                $params = [$selectedCategory, $letter . '%'];
            } elseif ($selectedCategory) {
                $query = "
                    SELECT topic, topic_desc, knowledge_class, topic_desc_basic,
                           knowledge_class_basic, tags, category
                    FROM $schema.worldknowledge
                    WHERE category = $1
                    ORDER BY topic $order
                ";
                $params = [$selectedCategory];
            } elseif ($letter) {
                $query = "
                    SELECT topic, topic_desc, knowledge_class, topic_desc_basic,
                           knowledge_class_basic, tags, category
                    FROM $schema.worldknowledge
                    WHERE topic ILIKE $1
                    ORDER BY topic $order
                ";
                $params = [$letter . '%'];
            } else {
                $query = "
                    SELECT topic, topic_desc, knowledge_class, topic_desc_basic,
                           knowledge_class_basic, tags, category
                    FROM $schema.worldknowledge
                    ORDER BY topic $order
                ";
                $params = [];
            }

            $result = pg_query_params($conn, $query, $params);

            echo '<a id="entries"></a>';
            echo '<div class="table-container">';
            echo '<table>';
            echo '<tr>
                    <th>Topic</th>
                    <th>Topic Description (Advanced)</th>
                    <th>Knowledge Class (Advanced)</th>
                    <th>Topic Description (Basic)</th>
                    <th>Knowledge Class (Basic)</th>
                    <th>Tags</th>
                    <th>Category</th>
                    <th>Action</th> 
                  </tr>';

            if ($result) {
                $rowCount = 0;
                while ($row = pg_fetch_assoc($result)) {
                    $topic                = htmlspecialchars($row['topic']                ?? '');
                    $topic_desc           = htmlspecialchars($row['topic_desc']           ?? '');
                    $knowledge_class      = htmlspecialchars($row['knowledge_class']      ?? '');
                    $topic_desc_basic     = htmlspecialchars($row['topic_desc_basic']     ?? '');
                    $knowledge_class_basic= htmlspecialchars($row['knowledge_class_basic']?? '');
                    $tags                 = htmlspecialchars($row['tags']                 ?? '');
                    $category             = htmlspecialchars($row['category']             ?? '');

                    // Normal row display
                    echo '<tr>';
                    echo '<td>' . $topic . '</td>';
                    echo '<td>' . nl2br($topic_desc) . '</td>';
                    
                    // Knowledge Class column with badge styling
                    echo '<td style="font-size: 1.5em; line-height: 1.4;">';
                    if (!empty(trim($knowledge_class))) {
                        $knowledgeClasses = array_map('trim', explode(',', $knowledge_class));
                        foreach ($knowledgeClasses as $class) {
                            if (!empty($class)) {
                                echo '<span style="display: inline-block; background: rgba(255, 182, 65, 0.2); color: rgb(255, 182, 65); padding: 3px 8px; margin: 2px; border-radius: 4px; font-size: 0.85em; font-weight: 500;">' . htmlspecialchars($class) . '</span>';
                            }
                        }
                    } else {
                        echo '<span style="color: #888; font-style: italic;">Everyone</span>';
                    }
                    echo '</td>';
                    
                    echo '<td>' . nl2br($topic_desc_basic) . '</td>';
                    
                    // Knowledge Class Basic column with badge styling
                    echo '<td style="font-size: 1.5em; line-height: 1.4;">';
                    if (!empty(trim($knowledge_class_basic))) {
                        $knowledgeClassesBasic = array_map('trim', explode(',', $knowledge_class_basic));
                        foreach ($knowledgeClassesBasic as $class) {
                            if (!empty($class)) {
                                echo '<span style="display: inline-block; background: rgba(255, 182, 65, 0.15); color: rgb(255, 182, 65); padding: 3px 8px; margin: 2px; border-radius: 4px; font-size: 0.85em; font-weight: 400;">' . htmlspecialchars($class) . '</span>';
                            }
                        }
                    } else {
                        echo '<span style="color: #888; font-style: italic;">Everyone</span>';
                    }
                    echo '</td>';
                    
                    echo '<td>' . nl2br($tags) . '</td>';
                    echo '<td>' . nl2br($category) . '</td>';

                    // Action column
                    echo '<td style="white-space: nowrap;">';
                    echo '<div style="display: flex; gap: 4px;">';
                    
                    // Edit button only
                    echo '<button onclick="openEditModal(' . 
                        htmlspecialchars(json_encode([
                            'topic' => $topic,
                            'topic_desc' => $topic_desc,
                            'knowledge_class' => $knowledge_class,
                            'topic_desc_basic' => $topic_desc_basic,
                            'knowledge_class_basic' => $knowledge_class_basic,
                            'tags' => $tags,
                            'category' => $category
                        ]), ENT_QUOTES, 'UTF-8') . 
                        ')" class="action-button edit">Edit</button>';
                    
                    echo '</div>';
                    echo '</td>';
                    echo '</tr>';

                    $rowCount++;
                }

                echo '</table>';
                echo '</div>';

                if ($rowCount === 0) {
                    echo '<p>No entries found.</p>';
                }
            } else {
                echo '<p>Error fetching WorldKnowledge entries: ' . pg_last_error($conn) . '</p>';
            }
            ?>
        </div>
    </div>

<div id="editModal" class="modal-backdrop">
    <div class="modal-container">
        <div class="modal-header">
            <h2 class="modal-title">Edit WorldKnowledge Entry</h2>
        </div>
        <div class="modal-body">
            <form action="" method="post">
                <input type="hidden" name="action" value="update_single">
                <input type="hidden" name="topic_original" id="edit_topic_original">

                <label for="edit_topic">Topic:</label>
                <small>Topic name for keyword searching.</small>
                <input type="text" name="topic_new" id="edit_topic" required>
                

                <label for="edit_topic_desc">Topic Description:</label>
                <small>Advanced knowledge information on the subject.</small>
                <textarea name="topic_desc_new" id="edit_topic_desc" rows="8" required></textarea>
                

                <label for="edit_knowledge_class">Knowledge Class:</label>
                <small>Who should have access to this advanced knowledge. Separate tags by commas. <a href="https://docs.google.com/spreadsheets/d/1dcfctU-iOqprwy2BOc7___4Awteczgdlv8886KalPsQ/edit?pli=1&gid=338893641" style="color: yellow;" target="_blank" rel="noopener noreferrer"> More information can be found here</a>.</small>
                <input type="text" name="knowledge_class_new" id="edit_knowledge_class">

                <label for="edit_topic_desc_basic">Topic Description (Basic):</label>
                <small>Who should have basic information on the subject.</small>
                <textarea name="topic_desc_basic_new" id="edit_topic_desc_basic" rows="8"></textarea>
                

                <label for="edit_knowledge_class_basic">Knowledge Class (Basic):</label>
                <small>Who should have access to this basic knowledge. Leave empty to allow all NPCs to know this. Separate tags by commas. <a href="https://docs.google.com/spreadsheets/d/1dcfctU-iOqprwy2BOc7___4Awteczgdlv8886KalPsQ/edit?pli=1&gid=338893641" style="color: yellow;" target="_blank" rel="noopener noreferrer"> More information can be found here</a>.</small>
                <input type="text" name="knowledge_class_basic_new" id="edit_knowledge_class_basic">

                <label for="edit_tags">Tags:</label>
                <small>Not currently in use.</small>
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

<div id="newEntryModal" class="modal-backdrop">
    <div class="modal-container">
        <div class="modal-header">
            <h2 class="modal-title">Add New WorldKnowledge Entry</h2>
        </div>
        <div class="modal-body">
            <form action="" method="post">
                <input type="hidden" name="submit_individual" value="1">

                <label for="topic">Topic (required):</label>
                <small>Topic name for keyword searching.</small>
                <input type="text" name="topic" id="topic" required>

                <label for="topic_desc">Topic Description (required):</label>
                <small>Advanced knowledge information on the subject.</small>
                <textarea name="topic_desc" id="topic_desc" rows="5" required></textarea>

                <label for="knowledge_class">Knowledge Class:</label>
                <small>Who should have access to this advanced knowledge. Separate tags by commas. <a href="https://docs.google.com/spreadsheets/d/1dcfctU-iOqprwy2BOc7___4Awteczgdlv8886KalPsQ/edit?pli=1&gid=338893641" style="color: yellow;" target="_blank" rel="noopener noreferrer"> More information can be found here</a>.</small>
                <input type="text" name="knowledge_class" id="knowledge_class">

                <label for="topic_desc_basic">Topic Description (Basic):</label>
                <small>Who should have basic information on the subject.</small>
                <textarea name="topic_desc_basic" id="topic_desc_basic" rows="5"></textarea>

                <label for="knowledge_class_basic">Knowledge Class (Basic):</label>
                <small>Who should have access to this basic knowledge. Leave empty to allow all NPCs to know this. It is recommended for most basic articles to leave it blank. Separate tags by commas. <a href="https://docs.google.com/spreadsheets/d/1dcfctU-iOqprwy2BOc7___4Awteczgdlv8886KalPsQ/edit?pli=1&gid=338893641" style="color: yellow;" target="_blank" rel="noopener noreferrer"> More information can be found here</a>.</small>
                <input type="text" name="knowledge_class_basic" id="knowledge_class_basic">

                <label for="tags">Tags:</label>
                <small>Not currently in use.</small>
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

function openEditModal(data) {
    try {
        const decodeHTML = (html) => {
            const txt = document.createElement('textarea');
            txt.innerHTML = html;
            return txt.value;
        };

        document.getElementById("edit_topic_original").value = decodeHTML(data.topic);
        document.getElementById("edit_topic").value = decodeHTML(data.topic);
        document.getElementById("edit_topic_desc").value = decodeHTML(data.topic_desc);
        document.getElementById("edit_knowledge_class").value = decodeHTML(data.knowledge_class);
        document.getElementById("edit_topic_desc_basic").value = decodeHTML(data.topic_desc_basic);
        document.getElementById("edit_knowledge_class_basic").value = decodeHTML(data.knowledge_class_basic);
        document.getElementById("edit_tags").value = decodeHTML(data.tags);
        document.getElementById("edit_category").value = decodeHTML(data.category);
        
        document.getElementById("editModal").style.display = "block";
        document.body.style.overflow = "hidden";
    } catch (error) {
        console.error('Error in openEditModal:', error);
        alert('There was an error opening the edit form. Please try again.');
    }
}

function closeEditModal() {
    document.getElementById("editModal").style.display = "none";
    document.body.style.overflow = "auto";
}

function openNewEntryModal() {
    document.getElementById("newEntryModal").style.display = "block";
    document.body.style.overflow = "hidden";
}

function closeNewEntryModal() {
    document.getElementById("newEntryModal").style.display = "none";
    document.body.style.overflow = "auto";
}

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
    let url = new URL(window.location.href);
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
document.addEventListener('DOMContentLoaded', function() {
    showToast(<?php echo json_encode(strip_tags($message)); ?>);
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
