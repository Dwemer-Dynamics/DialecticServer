<?php

$enginePath = dirname(__DIR__) . DIRECTORY_SEPARATOR;

require_once($enginePath . "lib" . DIRECTORY_SEPARATOR . "runtime_bootstrap.php");
dialecticRuntimeBootstrap($enginePath, [
    'load_general_settings' => true,
    'load_player_name' => true,
    'load_narrator' => true,
]);

require_once($enginePath . "lib" . DIRECTORY_SEPARATOR . "logger.php");

function h(mixed $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function worldknowledgeAuditWebRoot(): string
{
    $scriptPath = strval($_SERVER['SCRIPT_NAME'] ?? '');
    $root = dirname(dirname($scriptPath));
    if ($root === '/' || $root === '\\') {
        $root = '';
    }
    return rtrim($root, '/');
}

function worldknowledgeAuditBuildWhereClause(bool $matchedOnly): string
{
    if ($matchedOnly) {
        return "WHERE status IN ('grounded', 'fallback_succeeded')";
    }
    return '';
}

function worldknowledgeAuditCountRows(bool $matchedOnly = false): int
{
    $db = $GLOBALS['db'] ?? null;
    if (!$db) {
        return 0;
    }

    try {
        $whereSql = worldknowledgeAuditBuildWhereClause($matchedOnly);
        $row = $db->fetchOne('SELECT COUNT(*) AS total FROM public.worldknowledge_audit ' . $whereSql);
        return intval($row['total'] ?? 0);
    } catch (Throwable $exception) {
        Logger::warn("worldknowledge_audit count failed: " . $exception->getMessage());
        return 0;
    }
}

function worldknowledgeAuditFetchRows(int $limit = 50, int $offset = 0, bool $matchedOnly = false): array
{
    $db = $GLOBALS['db'] ?? null;
    if (!$db) {
        return [];
    }

    $safeLimit = max(10, min(100, $limit));
    $safeOffset = max(0, $offset);
    try {
        $whereSql = worldknowledgeAuditBuildWhereClause($matchedOnly);
        return $db->fetchAll(
            'SELECT audit_id, created_at, algorithm_version, status, request_type, npc_name,
                    input_text, normalized_input, catalog_id, catalog_version, grounded_matches,
                    rejected_candidates, tag_decisions, fallback, forced_signals, access_decisions,
                    selected_articles, retrieval_elapsed_ms, elapsed_ms
             FROM public.worldknowledge_audit
             ' . $whereSql . '
             ORDER BY created_at DESC
             LIMIT ' . intval($safeLimit) . '
             OFFSET ' . intval($safeOffset)
        );
    } catch (Throwable $exception) {
        Logger::warn("worldknowledge_audit fetch failed: " . $exception->getMessage());
        return [];
    }
}

function worldknowledgeAuditBuildQuery(array $params): string
{
    $filtered = [];
    foreach ($params as $key => $value) {
        if ($value === null || $value === '') {
            continue;
        }
        $filtered[$key] = strval($value);
    }
    if (count($filtered) === 0) {
        return '';
    }
    return '?' . http_build_query($filtered);
}

function worldknowledgeAuditJson(mixed $value): array
{
    if (is_array($value)) {
        return $value;
    }
    $decoded = json_decode(strval($value), true);
    return is_array($decoded) ? $decoded : [];
}

$isEmbed = (isset($_GET['embed']) && strval($_GET['embed']) === '1');
$webRoot = worldknowledgeAuditWebRoot();
$matchedOnly = isset($_GET['matched']) && strval($_GET['matched']) === '1';
$page = max(1, intval($_GET['page'] ?? 1));
$perPageAllowed = [25, 50, 100];
$perPageRaw = intval($_GET['per_page'] ?? 50);
$perPage = in_array($perPageRaw, $perPageAllowed, true) ? $perPageRaw : 50;

$totalRows = worldknowledgeAuditCountRows($matchedOnly);
$totalPages = max(1, intval(ceil($totalRows / $perPage)));
if ($page > $totalPages) {
    $page = $totalPages;
}
$offset = ($page - 1) * $perPage;
$rows = worldknowledgeAuditFetchRows($perPage, $offset, $matchedOnly);

$baseParams = [];
if ($isEmbed) {
    $baseParams['embed'] = '1';
}
$baseParams['per_page'] = strval($perPage);
$paginationBaseParams = $baseParams;
if ($matchedOnly) {
    $paginationBaseParams['matched'] = '1';
}

$rangeStart = $totalRows > 0 ? ($offset + 1) : 0;
$rangeEnd = min($offset + $perPage, $totalRows);

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>WorldKnowledge Audit</title>
    <link rel="icon" type="image/x-icon" href="images/favicon.ico">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="css/main.css">
    <?php if (!$isEmbed): ?>
        <link rel="stylesheet" href="css/navbar.css">
    <?php endif; ?>
    <style>
        body { background:#1f1f1f; color:#e7e7e7; }
        main.page-wrap { padding: <?= $isEmbed ? '20px' : '110px' ?> 12px 32px; }
        .page-header, .audit-card {
            background: linear-gradient(180deg, rgba(42,42,42,.96), rgba(30,30,30,.98));
            border: 1px solid #3b3b3b;
            border-radius: 10px;
            box-shadow: 0 2px 8px rgba(0,0,0,.2);
        }
        .page-header { padding: 18px; margin-bottom: 18px; text-align: center; }
        .audit-card { padding: 14px; margin-bottom: 14px; }
        .meta-grid {
            display:grid;
            grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
            gap: 8px;
            margin-bottom: 10px;
        }
        .meta-pill {
            background: rgba(255,255,255,.03);
            border: 1px solid rgba(255, 182, 65,.22);
            border-radius: 8px;
            padding: 8px 10px;
        }
        .meta-label { color:rgb(255, 182, 65); font-size:.78rem; text-transform:uppercase; letter-spacing:.04em; }
        .meta-value { font-size:.92rem; word-break: break-word; }
        .section-label { color:rgb(255, 182, 65); font-weight:700; margin-bottom:4px; }
        .trace-box {
            background: rgba(0,0,0,.22);
            border: 1px solid rgba(255,255,255,.06);
            border-radius: 8px;
            padding: 10px;
            white-space: pre-wrap;
            word-break: break-word;
            font-family: Consolas, Monaco, monospace;
            font-size: .85rem;
        }
        .toolbar-wrap {
            display: grid;
            grid-template-columns: 1fr auto auto;
            gap: 10px;
            align-items: center;
            margin-bottom: 14px;
        }
        .search-input {
            width: 100%; background:#111; color:#f2f2f2; border:1px solid #4a4a4a; border-radius:8px; padding:10px 12px;
        }
        .quick-toggle {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            background: rgba(255,255,255,.03);
            border: 1px solid rgba(255, 182, 65,.25);
            border-radius: 8px;
            padding: 8px 10px;
            cursor: pointer;
            user-select: none;
        }
        .quick-toggle input { accent-color: rgb(255, 182, 65); }
        .pager-wrap {
            display: flex;
            gap: 8px;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 14px;
            flex-wrap: wrap;
        }
        .pager-meta { color: #b8b8b8; font-size: .9rem; }
        .pager-links {
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }
        .pager-link {
            display: inline-block;
            color: #efefef;
            text-decoration: none;
            border: 1px solid rgba(255, 182, 65,.28);
            border-radius: 8px;
            padding: 6px 10px;
            background: rgba(255,255,255,.02);
        }
        .pager-link[aria-disabled="true"] {
            opacity: .45;
            pointer-events: none;
        }
        .per-page-select {
            background:#111; color:#f2f2f2; border:1px solid #4a4a4a; border-radius:8px; padding:6px 8px;
        }
        .empty-state { padding: 20px; text-align:center; color:#aaa; }
        @media (max-width: 850px) {
            .toolbar-wrap { grid-template-columns: 1fr; }
        }
    </style>
</head>
<body>
<?php if (!$isEmbed): ?>
    <?php include(__DIR__ . DIRECTORY_SEPARATOR . 'tmpl' . DIRECTORY_SEPARATOR . 'navbar.php'); ?>
<?php endif; ?>
<main class="page-wrap container-fluid">
    <div class="page-header">
        <h1>WorldKnowledge Audit</h1>
        <div>Review deterministic matches, rejections, access decisions, forced context, and bounded fallback activity.</div>
    </div>

    <div class="toolbar-wrap">
        <div>
            <input id="auditSearch" class="search-input" type="text" placeholder="Filter current page by input, selected topic, signals, notes...">
        </div>
        <label class="quick-toggle" for="matchedOnlyToggle">
            <input id="matchedOnlyToggle" type="checkbox" <?= $matchedOnly ? 'checked' : '' ?>>
            <span>Only Matched</span>
        </label>
        <form method="get" action="" style="margin:0;">
            <?php if ($isEmbed): ?>
                <input type="hidden" name="embed" value="1">
            <?php endif; ?>
            <?php if ($matchedOnly): ?>
                <input type="hidden" name="matched" value="1">
            <?php endif; ?>
            <input type="hidden" name="page" value="1">
            <label style="display:inline-flex; align-items:center; gap:8px; margin:0;">
                <span style="font-size:.9rem; color:#b8b8b8;">Per page</span>
                <select class="per-page-select" name="per_page" onchange="this.form.submit()">
                    <?php foreach ($perPageAllowed as $option): ?>
                        <option value="<?= h(strval($option)) ?>" <?= $perPage === $option ? 'selected' : '' ?>><?= h(strval($option)) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
        </form>
    </div>

    <div class="pager-wrap">
        <div class="pager-meta">
            Showing <?= h(strval($rangeStart)) ?>-<?= h(strval($rangeEnd)) ?> of <?= h(strval($totalRows)) ?> rows
            <?php if ($matchedOnly): ?>
                (matched only)
            <?php endif; ?>
        </div>
        <div class="pager-links">
            <?php
                $prevParams = $paginationBaseParams;
                $prevParams['page'] = strval(max(1, $page - 1));
                $nextParams = $paginationBaseParams;
                $nextParams['page'] = strval(min($totalPages, $page + 1));
            ?>
            <a class="pager-link" href="<?= h(worldknowledgeAuditBuildQuery($prevParams)) ?>" aria-disabled="<?= $page <= 1 ? 'true' : 'false' ?>">Prev</a>
            <span class="pager-meta">Page <?= h(strval($page)) ?> / <?= h(strval($totalPages)) ?></span>
            <a class="pager-link" href="<?= h(worldknowledgeAuditBuildQuery($nextParams)) ?>" aria-disabled="<?= $page >= $totalPages ? 'true' : 'false' ?>">Next</a>
        </div>
    </div>

    <?php if (count($rows) === 0): ?>
        <div class="audit-card empty-state">No structured World Knowledge traces yet.</div>
    <?php else: ?>
        <?php foreach ($rows as $row): ?>
            <?php
                $input = strval($row['input_text'] ?? '');
                $normalizedInput = strval($row['normalized_input'] ?? '');
                $elapsed = strval($row['elapsed_ms'] ?? '');
                $created = strval($row['created_at'] ?? '');
                $status = strval($row['status'] ?? 'no_match');
                $npcName = strval($row['npc_name'] ?? '');
                $requestType = strval($row['request_type'] ?? '');
                $catalog = trim(strval($row['catalog_id'] ?? '') . '/' . strval($row['catalog_version'] ?? ''), '/');
                $matches = worldknowledgeAuditJson($row['grounded_matches'] ?? []);
                $rejections = worldknowledgeAuditJson($row['rejected_candidates'] ?? []);
                $tagDecisions = worldknowledgeAuditJson($row['tag_decisions'] ?? []);
                $fallback = worldknowledgeAuditJson($row['fallback'] ?? []);
                $forced = worldknowledgeAuditJson($row['forced_signals'] ?? []);
                $access = worldknowledgeAuditJson($row['access_decisions'] ?? []);
                $selected = worldknowledgeAuditJson($row['selected_articles'] ?? []);
                $jsonFlags = JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;
                $searchBlob = strtolower(implode(' ', [
                    $input, $normalizedInput, $status, $npcName, $requestType, $catalog,
                    json_encode([$matches, $rejections, $tagDecisions, $fallback, $forced, $access, $selected], $jsonFlags),
                ]));
            ?>
            <section class="audit-card" data-search="<?= h($searchBlob) ?>">
                <div class="meta-grid">
                    <div class="meta-pill"><div class="meta-label">Status</div><div class="meta-value"><?= h(ucwords(str_replace('_', ' ', $status))) ?></div></div>
                    <div class="meta-pill"><div class="meta-label">NPC</div><div class="meta-value"><?= h($npcName !== '' ? $npcName : '(unknown)') ?></div></div>
                    <div class="meta-pill"><div class="meta-label">Request</div><div class="meta-value"><?= h($requestType !== '' ? $requestType : '(unknown)') ?></div></div>
                    <div class="meta-pill"><div class="meta-label">Catalog</div><div class="meta-value"><?= h($catalog !== '' ? $catalog : '(custom only)') ?></div></div>
                    <div class="meta-pill"><div class="meta-label">Algorithm</div><div class="meta-value"><?= h($row['algorithm_version'] ?? '') ?></div></div>
                    <div class="meta-pill"><div class="meta-label">Audit ID</div><div class="meta-value"><?= h($row['audit_id'] ?? '') ?></div></div>
                    <div class="meta-pill"><div class="meta-label">Created</div><div class="meta-value"><?= h($created) ?></div></div>
                    <div class="meta-pill"><div class="meta-label">Elapsed</div><div class="meta-value"><?= h($elapsed) ?> ms</div></div>
                    <div class="meta-pill"><div class="meta-label">Retrieval</div><div class="meta-value"><?= h($row['retrieval_elapsed_ms'] ?? '0') ?> ms</div></div>
                </div>

                <div class="section-label">Input</div>
                <div class="trace-box"><?= h($input) ?></div>

                <?php foreach ([
                    'Selected Articles' => $selected,
                    'Grounded Matches' => $matches,
                    'Access Decisions' => $access,
                    'Rejected Candidates' => $rejections,
                    'Tag Decisions' => $tagDecisions,
                    'Forced Context' => $forced,
                    'Fallback' => $fallback,
                ] as $label => $payload): ?>
                    <div class="section-label" style="margin-top:10px;"><?= h($label) ?></div>
                    <pre class="trace-box"><?= h($payload ? json_encode($payload, $jsonFlags) : '(none)') ?></pre>
                <?php endforeach; ?>
            </section>
        <?php endforeach; ?>
    <?php endif; ?>
</main>
<script>
const matchedToggle = document.getElementById('matchedOnlyToggle');
if (matchedToggle) {
    matchedToggle.addEventListener('change', () => {
        const url = new URL(window.location.href);
        if (matchedToggle.checked) {
            url.searchParams.set('matched', '1');
        } else {
            url.searchParams.delete('matched');
        }
        url.searchParams.set('page', '1');
        window.location.href = url.toString();
    });
}

const searchInput = document.getElementById('auditSearch');
if (searchInput) {
  searchInput.addEventListener('input', () => {
    const needle = String(searchInput.value || '').trim().toLowerCase();
    document.querySelectorAll('[data-search]').forEach((card) => {
      const hay = String(card.getAttribute('data-search') || '').toLowerCase();
      card.style.display = (needle === '' || hay.includes(needle)) ? '' : 'none';
    });
  });
}
</script>
</body>
</html>
