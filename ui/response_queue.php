<?php

$enginePath = dirname(__DIR__) . DIRECTORY_SEPARATOR;

require_once($enginePath . "lib" . DIRECTORY_SEPARATOR . "runtime_bootstrap.php");
dialecticRuntimeBootstrap($enginePath, [
    'load_general_settings' => true,
    'load_player_name' => true,
    'load_narrator' => true,
]);

require_once($enginePath . "lib" . DIRECTORY_SEPARATOR . "logger.php");
require_once(__DIR__ . DIRECTORY_SEPARATOR . "profile_loader.php");

function dialecticResponseQueueH($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8");
}

function dialecticResponseQueueFetchAll(sql $db, string $query): array
{
    try {
        $rows = $db->fetchAll($query);
        return is_array($rows) ? $rows : [];
    } catch (Throwable $exception) {
        Logger::warn("response_queue fetchAll failed: " . $exception->getMessage());
        return [];
    }
}

function dialecticResponseQueueFetchOne(sql $db, string $query): array
{
    try {
        $row = $db->fetchOne($query);
        return is_array($row) ? $row : [];
    } catch (Throwable $exception) {
        Logger::warn("response_queue fetchOne failed: " . $exception->getMessage());
        return [];
    }
}

function dialecticResponseQueueFormatTime($value): string
{
    $timestamp = intval($value);
    if ($timestamp <= 0) {
        return "";
    }

    return date("d-m-Y H:i:s", $timestamp);
}

function dialecticResponseQueueShorten(string $value, int $maxLen = 220): string
{
    $value = trim(preg_replace('/\s+/', ' ', $value) ?? $value);
    if ($value === "") {
        return "";
    }

    if (function_exists("mb_strlen") && function_exists("mb_substr")) {
        if (mb_strlen($value, "UTF-8") > $maxLen) {
            return mb_substr($value, 0, $maxLen, "UTF-8") . "...";
        }
        return $value;
    }

    if (strlen($value) > $maxLen) {
        return substr($value, 0, $maxLen) . "...";
    }

    return $value;
}

function dialecticResponseQueueUrl(array $overrides = []): string
{
    $params = array_merge([
        "embed" => isset($_GET["embed"]) ? "1" : null,
        "limit" => isset($_GET["limit"]) ? max(10, intval($_GET["limit"])) : 100,
    ], $overrides);

    foreach ($params as $key => $value) {
        if ($value === null || $value === "") {
            unset($params[$key]);
        }
    }

    return "response_queue.php?" . http_build_query($params);
}

$embedded = isset($_GET["embed"]) && $_GET["embed"];
$limit = isset($_GET["limit"]) ? max(10, min(500, intval($_GET["limit"]))) : 100;
$db = new sql();
$GLOBALS["db"] = $db;

$summary = dialecticResponseQueueFetchOne(
    $db,
    "SELECT
        COUNT(*) AS total,
        SUM(CASE WHEN sent = 0 THEN 1 ELSE 0 END) AS pending,
        SUM(CASE WHEN sent <> 0 THEN 1 ELSE 0 END) AS delivered,
        MAX(localts) AS latest_localts
     FROM responselog"
);

$rows = dialecticResponseQueueFetchAll(
    $db,
    "SELECT rowid, localts, sent, actor, action, tag, text
     FROM responselog
     ORDER BY rowid DESC
     LIMIT " . intval($limit)
);

$pending = intval($summary["pending"] ?? 0);
$delivered = intval($summary["delivered"] ?? 0);
$total = intval($summary["total"] ?? 0);
$latestLocalTs = intval($summary["latest_localts"] ?? 0);

?><!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta http-equiv="refresh" content="5">
    <title>Dialectic Response Queue</title>
    <link rel="stylesheet" href="css/main.css">
    <style>
        body {
            background: #1f1f1f;
            color: #f8f9fa;
            margin: 0;
            font-family: Arial, sans-serif;
        }

        main {
            padding: <?php echo $embedded ? "18px" : "90px 18px 18px"; ?>;
        }

        .queue-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            flex-wrap: wrap;
            margin-bottom: 16px;
        }

        h1 {
            margin: 0;
            color: rgb(255, 182, 65);
            font-size: 1.7rem;
        }

        .meta {
            color: #c8c8c8;
            font-size: 0.92rem;
            margin-top: 4px;
        }

        .stats {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
            gap: 10px;
            margin-bottom: 16px;
        }

        .stat {
            border: 1px solid #3a3a3a;
            border-left: 4px solid rgb(255, 182, 65);
            background: #2a2a2a;
            border-radius: 6px;
            padding: 12px;
        }

        .stat-label {
            color: #b8b8b8;
            font-size: 0.82rem;
            text-transform: uppercase;
        }

        .stat-value {
            margin-top: 4px;
            color: #fff;
            font-size: 1.35rem;
            font-weight: 700;
        }

        .queue-actions {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
        }

        .btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-height: 34px;
            padding: 7px 12px;
            border-radius: 6px;
            border: 1px solid rgba(255, 182, 65, 0.55);
            background: rgba(255, 182, 65, 0.12);
            color: rgb(255, 182, 65);
            text-decoration: none;
            cursor: pointer;
            font-size: 0.92rem;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            background: #2a2a2a;
            border: 1px solid #3a3a3a;
        }

        th,
        td {
            padding: 9px 10px;
            border-bottom: 1px solid #3a3a3a;
            vertical-align: top;
            text-align: left;
            font-size: 0.88rem;
        }

        th {
            background: #171717;
            color: #fff;
            position: sticky;
            top: 0;
            z-index: 1;
        }

        tr:nth-child(even) td {
            background: #303030;
        }

        .status {
            display: inline-flex;
            align-items: center;
            min-width: 76px;
            justify-content: center;
            padding: 3px 8px;
            border-radius: 999px;
            font-size: 0.78rem;
            font-weight: 700;
        }

        .status-pending {
            background: rgba(255, 182, 65, 0.18);
            color: rgb(255, 182, 65);
        }

        .status-delivered {
            background: rgba(88, 214, 141, 0.16);
            color: #58d68d;
        }

        .mono {
            font-family: Consolas, "Courier New", monospace;
        }

        .empty {
            border: 1px solid #3a3a3a;
            background: #2a2a2a;
            border-radius: 6px;
            padding: 18px;
            color: #c8c8c8;
        }
    </style>
</head>
<body>
<main>
    <div class="queue-header">
        <div>
            <h1>Response Queue</h1>
            <div class="meta">
                Live view of <span class="mono">responselog</span>. Pending rows are delivered to the plugin by <span class="mono">DataDequeue()</span>.
            </div>
        </div>
        <div class="queue-actions">
            <a class="btn" href="<?php echo dialecticResponseQueueH(dialecticResponseQueueUrl(["limit" => $limit])); ?>">Refresh</a>
            <a class="btn" href="<?php echo dialecticResponseQueueH(dialecticResponseQueueUrl(["limit" => 300])); ?>">Show 300</a>
            <a class="btn" href="index.php?table=responselog<?php echo $embedded ? "&embed=1" : ""; ?>">Raw Table</a>
        </div>
    </div>

    <section class="stats" aria-label="Response queue status">
        <div class="stat">
            <div class="stat-label">Pending</div>
            <div class="stat-value"><?php echo $pending; ?></div>
        </div>
        <div class="stat">
            <div class="stat-label">Delivered</div>
            <div class="stat-value"><?php echo $delivered; ?></div>
        </div>
        <div class="stat">
            <div class="stat-label">Total</div>
            <div class="stat-value"><?php echo $total; ?></div>
        </div>
        <div class="stat">
            <div class="stat-label">Latest</div>
            <div class="stat-value" style="font-size: 1rem;"><?php echo dialecticResponseQueueH(dialecticResponseQueueFormatTime($latestLocalTs)); ?></div>
        </div>
    </section>

    <?php if (empty($rows)): ?>
        <div class="empty">No response queue rows found.</div>
    <?php else: ?>
        <table>
            <thead>
                <tr>
                    <th>Row</th>
                    <th>Status</th>
                    <th>Time</th>
                    <th>Actor</th>
                    <th>Action</th>
                    <th>Tag</th>
                    <th>Payload</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($rows as $row): ?>
                <?php $isPending = intval($row["sent"] ?? 0) === 0; ?>
                <tr>
                    <td class="mono"><?php echo dialecticResponseQueueH($row["rowid"] ?? ""); ?></td>
                    <td>
                        <span class="status <?php echo $isPending ? "status-pending" : "status-delivered"; ?>">
                            <?php echo $isPending ? "Pending" : "Delivered"; ?>
                        </span>
                    </td>
                    <td><?php echo dialecticResponseQueueH(dialecticResponseQueueFormatTime($row["localts"] ?? 0)); ?></td>
                    <td><?php echo dialecticResponseQueueH($row["actor"] ?? ""); ?></td>
                    <td><?php echo dialecticResponseQueueH($row["action"] ?? ""); ?></td>
                    <td><?php echo dialecticResponseQueueH($row["tag"] ?? ""); ?></td>
                    <td class="mono"><?php echo dialecticResponseQueueH(dialecticResponseQueueShorten((string)($row["text"] ?? ""))); ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</main>
</body>
</html>
