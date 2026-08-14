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

/** Every stable Oghma parity status a stored trace can carry, in pipeline order. */
const WORLDKNOWLEDGE_AUDIT_STATUSES = [
    'grounded',
    'no_match',
    'fallback_succeeded',
    'fallback_unresolved',
    'fallback_failed',
    'fallback_disabled',
    'fallback_unconfigured',
    'disabled',
    'ineligible',
    'unavailable',
    'not_run',
    'legacy',
];

/**
 * Group the statuses into three read-at-a-glance tones: a pass, an outright
 * failure, and everything that simply did not run or did not match.
 */
function worldknowledgeAuditStatusTone(string $status): string
{
    if (in_array($status, ['grounded', 'fallback_succeeded'], true)) {
        return 'ok';
    }
    if (in_array($status, ['fallback_failed', 'unavailable'], true)) {
        return 'bad';
    }
    return 'idle';
}

/**
 * Compose the row filters. Matched-only and the status filter are ANDed, so
 * selecting a status the matched-only set excludes correctly yields no rows.
 * The status value is restricted to the allowlist above and then quoted by the
 * driver, because this connection exposes no bound-parameter fetch helper.
 */
function worldknowledgeAuditBuildWhereClause(bool $matchedOnly, string $status = '', mixed $db = null): string
{
    $clauses = [];
    if ($matchedOnly) {
        $clauses[] = "status IN ('grounded', 'fallback_succeeded')";
    }
    if ($status !== '' && in_array($status, WORLDKNOWLEDGE_AUDIT_STATUSES, true)) {
        $literal = ($db !== null && method_exists($db, 'escapeLiteral'))
            ? $db->escapeLiteral($status)
            : "'" . $status . "'";
        $clauses[] = 'status = ' . $literal;
    }
    if ($clauses === []) {
        return '';
    }
    return 'WHERE ' . implode(' AND ', $clauses);
}

function worldknowledgeAuditCountRows(bool $matchedOnly = false, string $status = ''): int
{
    $db = $GLOBALS['db'] ?? null;
    if (!$db) {
        return 0;
    }

    try {
        $whereSql = worldknowledgeAuditBuildWhereClause($matchedOnly, $status, $db);
        $row = $db->fetchOne('SELECT COUNT(*) AS total FROM public.worldknowledge_audit ' . $whereSql);
        return intval($row['total'] ?? 0);
    } catch (Throwable $exception) {
        Logger::warn("worldknowledge_audit count failed: " . $exception->getMessage());
        return 0;
    }
}

function worldknowledgeAuditFetchRows(int $limit = 50, int $offset = 0, bool $matchedOnly = false, ?bool &$failed = null, string $status = ''): array
{
    $failed = false;
    $db = $GLOBALS['db'] ?? null;
    if (!$db) {
        $failed = true;
        return [];
    }

    $safeLimit = max(10, min(100, $limit));
    $safeOffset = max(0, $offset);
    try {
        $whereSql = worldknowledgeAuditBuildWhereClause($matchedOnly, $status, $db);
        return $db->fetchAll(
            'SELECT audit_id, created_at, algorithm_version, status, request_type, npc_name,
                    input_text, normalized_input, catalog_id, catalog_version, catalog_checksum,
                    grounded_matches, rejected_candidates, tag_decisions, context_tags, fallback,
                    forced_signals, access_decisions, selected_articles, settings, prompt_hash,
                    retrieval_elapsed_ms, elapsed_ms
             FROM public.worldknowledge_audit
             ' . $whereSql . '
             ORDER BY created_at DESC
             LIMIT ' . intval($safeLimit) . '
             OFFSET ' . intval($safeOffset)
        );
    } catch (Throwable $exception) {
        Logger::warn("worldknowledge_audit fetch failed: " . $exception->getMessage());
        $failed = true;
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

/**
 * Build the display and search copy of the stored access decisions.
 *
 * A decision carries the resolved article body in `description`, which this
 * screen never needs to show or search. The body is dropped and every
 * access-control field a reviewer relies on is kept: the topic, the level that
 * was granted, why it was granted or denied, which flat knowledge classes
 * matched, and whether the topic came from the conversation or forced context.
 * Only the rendered copy changes; the persisted trace row is never rewritten.
 */
function worldknowledgeAuditAccessProjection(array $decisions): array
{
    $keep = ['topic', 'level', 'reason', 'matched', 'source'];

    $projected = [];
    foreach ($decisions as $decision) {
        if (!is_array($decision)) {
            continue;
        }
        $view = [];
        foreach ($keep as $field) {
            if (array_key_exists($field, $decision)) {
                $view[$field] = $decision[$field];
            }
        }
        // Record that a body was resolved, and how large it was, without
        // reproducing a single character of it.
        if (array_key_exists('description', $decision)) {
            $view['description_chars'] = strlen(strval($decision['description']));
        }
        $projected[] = $view;
    }
    return $projected;
}

/**
 * Flatten the stored settings payload into rows of setting, effective value,
 * and the configuration layer that supplied it.
 */
function worldknowledgeAuditSettingRows(array $settings): array
{
    $values = is_array($settings['values'] ?? null) ? $settings['values'] : [];
    $sources = is_array($settings['sources'] ?? null) ? $settings['sources'] : [];
    $rows = [];
    foreach ($values as $name => $value) {
        if (is_bool($value)) {
            $display = $value ? 'on' : 'off';
        } elseif (is_scalar($value)) {
            $display = trim(strval($value));
            if ($display === '') {
                $display = '(not set)';
            }
        } else {
            $display = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }
        $rows[] = [
            'name' => ucwords(str_replace('_', ' ', strval($name))),
            'value' => strval($display),
            'source' => str_replace('_', ' ', strval($sources[$name] ?? 'global')),
        ];
    }
    return $rows;
}

/**
 * Summarize the bounded connector fallback: whether the request was eligible,
 * whether one attempt was made, how it ended, and how long it took when the
 * payload recorded a duration.
 */
function worldknowledgeAuditFallbackSummary(array $fallback, string $status): array
{
    $eligible = !empty($fallback['eligible']);
    $attempted = !empty($fallback['attempted']);
    $error = trim(strval($fallback['error'] ?? ''));
    $suggestions = is_array($fallback['suggestions'] ?? null) ? $fallback['suggestions'] : [];
    $resolved = is_array($fallback['resolved_topics'] ?? null) ? $fallback['resolved_topics'] : [];

    if ($attempted && $error !== '') {
        $state = 'Attempted, failed';
    } elseif ($attempted && $resolved !== []) {
        $state = 'Attempted, resolved ' . count($resolved) . ' topic' . (count($resolved) === 1 ? '' : 's');
    } elseif ($attempted) {
        $state = 'Attempted, nothing resolved';
    } elseif ($status === 'fallback_disabled') {
        $state = 'Eligible, turned off';
    } elseif ($status === 'fallback_unconfigured') {
        $state = 'Eligible, no connector configured';
    } elseif ($eligible) {
        $state = 'Eligible, not attempted';
    } else {
        $state = 'Not eligible';
    }

    // The processor does not time the fallback separately today, so a duration
    // is shown only when a payload actually carries one.
    $elapsed = null;
    foreach (['elapsed_ms', 'duration_ms'] as $field) {
        if (isset($fallback[$field]) && is_numeric($fallback[$field])) {
            $elapsed = round(floatval($fallback[$field]), 3);
            break;
        }
    }

    return [
        'state' => $state,
        'error' => $error,
        'suggestions' => $suggestions,
        'resolved' => $resolved,
        'elapsed_ms' => $elapsed,
    ];
}

/** Shorten a checksum or prompt hash for the meta grid without losing the full value. */
function worldknowledgeAuditShortHash(string $hash): string
{
    $hash = trim($hash);
    return strlen($hash) > 12 ? substr($hash, 0, 12) . '...' : $hash;
}

/**
 * Flat lists of scalar tags read far better as chips than as pretty-printed
 * JSON. Anything richer, or long enough to stop being compact, returns null so
 * the caller keeps the JSON view.
 */
function worldknowledgeAuditTagChips(array $tags, int $limit = 60): ?array
{
    if ($tags === [] || count($tags) > $limit || array_keys($tags) !== range(0, count($tags) - 1)) {
        return null;
    }
    $chips = [];
    foreach ($tags as $tag) {
        if (!is_scalar($tag)) {
            return null;
        }
        $label = trim(strval($tag));
        if ($label !== '') {
            $chips[] = $label;
        }
    }
    return $chips === [] ? null : $chips;
}

function worldknowledgeAuditStatusLabel(string $status): string
{
    return ucwords(str_replace('_', ' ', $status));
}

$isEmbed = (isset($_GET['embed']) && strval($_GET['embed']) === '1');
$webRoot = worldknowledgeAuditWebRoot();
$matchedOnly = isset($_GET['matched']) && strval($_GET['matched']) === '1';
$statusRaw = trim(strval($_GET['status'] ?? ''));
$statusFilter = in_array($statusRaw, WORLDKNOWLEDGE_AUDIT_STATUSES, true) ? $statusRaw : '';
$page = max(1, intval($_GET['page'] ?? 1));
$perPageAllowed = [25, 50, 100];
$perPageRaw = intval($_GET['per_page'] ?? 50);
$perPage = in_array($perPageRaw, $perPageAllowed, true) ? $perPageRaw : 50;

$totalRows = worldknowledgeAuditCountRows($matchedOnly, $statusFilter);
$totalPages = max(1, intval(ceil($totalRows / $perPage)));
if ($page > $totalPages) {
    $page = $totalPages;
}
$offset = ($page - 1) * $perPage;
$fetchFailed = false;
$rows = worldknowledgeAuditFetchRows($perPage, $offset, $matchedOnly, $fetchFailed, $statusFilter);

$baseParams = [];
if ($isEmbed) {
    $baseParams['embed'] = '1';
}
$baseParams['per_page'] = strval($perPage);
if ($statusFilter !== '') {
    $baseParams['status'] = $statusFilter;
}
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
        /* Section labels are headings so the card structure is navigable, but
           they must keep the compact label look, not browser heading sizing. */
        .section-label {
            color:rgb(255, 182, 65);
            font-weight:700;
            font-size: .95rem;
            margin: 0 0 4px;
        }
        .section-note { color:#9d9d9d; font-weight:400; font-size:.8rem; }
        .sr-only {
            position:absolute;
            width:1px; height:1px;
            padding:0; margin:-1px;
            overflow:hidden;
            clip:rect(0 0 0 0);
            clip-path: inset(50%);
            white-space:nowrap;
            border:0;
        }
        .tag-chip-row {
            display:flex;
            flex-wrap:wrap;
            gap:6px;
            padding: 2px 0 2px;
        }
        .tag-chip {
            display:inline-block;
            background: rgba(255, 182, 65,.14);
            border: 1px solid rgba(255, 182, 65,.32);
            color: #ffd79a;
            border-radius: 999px;
            padding: 3px 10px;
            font-size: .82rem;
            word-break: break-word;
        }
        /* Status tone: a pass, an outright failure, and everything that simply
           did not run. Colour is a second cue only; the label always reads. */
        .status-value.is-ok { color:#9fdca4; }
        .status-value.is-bad { color:#ff9a9a; }
        .status-value.is-idle { color:#d8d8d8; }
        .class-chip {
            display:inline-block;
            background: rgba(255, 182, 65,.14);
            border: 1px solid rgba(255, 182, 65,.32);
            color: #ffd79a;
            border-radius: 4px;
            padding: 2px 8px;
            font-size: .8rem;
            word-break: break-word;
        }
        .class-chip.is-none { background:transparent; border-style:dashed; color:#a5a5a5; }
        /* One card per decision so topic, level, reason, and the flat matched
           classes stay aligned instead of hiding inside a JSON blob. */
        .decision-row {
            display:grid;
            grid-template-columns: minmax(140px, 1.2fr) minmax(90px, .6fr) minmax(150px, 1fr) minmax(160px, 1.4fr);
            gap: 8px;
            align-items: baseline;
            padding: 7px 10px;
            border: 1px solid rgba(255,255,255,.06);
            border-radius: 8px;
            background: rgba(0,0,0,.18);
            margin-bottom: 6px;
        }
        .decision-head {
            color:rgb(255, 182, 65);
            font-size:.72rem;
            text-transform:uppercase;
            letter-spacing:.04em;
            background: transparent;
            border-color: transparent;
            padding-bottom: 0;
        }
        .decision-topic { font-weight:600; word-break: break-word; }
        .decision-level.is-advanced { color:#9fdca4; }
        .decision-level.is-basic { color:#a8cdea; }
        .decision-level.is-denied { color:#ff9a9a; }
        .decision-meta { color:#c3c3c3; font-size:.85rem; word-break: break-word; }
        .settings-row {
            display:grid;
            grid-template-columns: minmax(160px, 1.2fr) minmax(110px, 1fr) minmax(110px, .7fr);
            gap:8px;
            padding: 6px 10px;
            border-bottom: 1px solid rgba(255,255,255,.05);
        }
        .settings-row:last-child { border-bottom:0; }
        .settings-table {
            border: 1px solid rgba(255,255,255,.06);
            border-radius: 8px;
            background: rgba(0,0,0,.18);
        }
        .settings-source { color:#9d9d9d; font-size:.85rem; text-transform:capitalize; }
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
        .field-label {
            display:block;
            color:rgb(255, 182, 65);
            font-size:.78rem;
            text-transform:uppercase;
            letter-spacing:.04em;
            margin-bottom:4px;
        }
        .field-hint { color:#9d9d9d; font-size:.82rem; margin-top:4px; }
        .filter-form {
            display:flex;
            gap:10px;
            align-items:flex-end;
            flex-wrap:wrap;
            margin:0;
        }
        .filter-form label { margin:0; }
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
        /* A disabled step renders as a span rather than a dimmed link, so it is
           neither focusable nor activatable. pointer-events alone still left it
           in the tab order and reachable with Enter. */
        .pager-link.is-disabled {
            opacity: .45;
            cursor: default;
        }
        .per-page-select {
            background:#111; color:#f2f2f2; border:1px solid #4a4a4a; border-radius:8px; padding:6px 8px;
        }
        .empty-state { padding: 20px; text-align:center; color:#aaa; }
        .error-state {
            border-color: rgba(198, 83, 83, .55);
            color: #efc9c9;
        }
        .error-state strong { display:block; margin-bottom:6px; color:#ff9a9a; }
        @media (max-width: 850px) {
            .toolbar-wrap { grid-template-columns: 1fr; }
            /* Below this width the decision and settings grids stop lining up,
               so the column header is dropped and each stacked field carries its
               own inline label instead. */
            .decision-row, .settings-row { grid-template-columns: 1fr; }
            .decision-row.decision-head { display:none; }
            .decision-row > [data-label]::before,
            .settings-row > [data-label]::before {
                content: attr(data-label) ": ";
                color: rgb(255, 182, 65);
                font-size: .72rem;
                text-transform: uppercase;
                letter-spacing: .04em;
            }
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
            <label class="field-label" for="auditSearch">Search this page</label>
            <input id="auditSearch" class="search-input" type="search"
                   aria-describedby="auditSearchHint"
                   placeholder="Input, selected topic, status, signals, notes...">
            <div class="field-hint" id="auditSearchHint">
                Filters only the <?= h(strval(count($rows))) ?> trace<?= count($rows) === 1 ? '' : 's' ?>
                loaded on this page. Use the status filter and pager to search the rest.
                <span id="auditVisibleCount" role="status"></span>
            </div>
        </div>
        <label class="quick-toggle" for="matchedOnlyToggle">
            <input id="matchedOnlyToggle" type="checkbox" <?= $matchedOnly ? 'checked' : '' ?>>
            <span>Only Matched</span>
        </label>
        <form method="get" action="" class="filter-form">
            <?php if ($isEmbed): ?>
                <input type="hidden" name="embed" value="1">
            <?php endif; ?>
            <?php if ($matchedOnly): ?>
                <input type="hidden" name="matched" value="1">
            <?php endif; ?>
            <input type="hidden" name="page" value="1">
            <label>
                <span class="field-label">Status</span>
                <select class="per-page-select" name="status" onchange="this.form.submit()">
                    <option value="" <?= $statusFilter === '' ? 'selected' : '' ?>>All statuses</option>
                    <?php foreach (WORLDKNOWLEDGE_AUDIT_STATUSES as $option): ?>
                        <option value="<?= h($option) ?>" <?= $statusFilter === $option ? 'selected' : '' ?>>
                            <?= h(worldknowledgeAuditStatusLabel($option)) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label>
                <span class="field-label">Per page</span>
                <select class="per-page-select" name="per_page" onchange="this.form.submit()">
                    <?php foreach ($perPageAllowed as $option): ?>
                        <option value="<?= h(strval($option)) ?>" <?= $perPage === $option ? 'selected' : '' ?>><?= h(strval($option)) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <noscript><button type="submit" class="pager-link">Apply</button></noscript>
        </form>
    </div>

    <nav class="pager-wrap" aria-label="Trace pagination">
        <div class="pager-meta" role="status">
            Showing <?= h(strval($rangeStart)) ?>-<?= h(strval($rangeEnd)) ?> of <?= h(strval($totalRows)) ?> rows
            <?php
                $activeFilters = [];
                if ($matchedOnly) {
                    $activeFilters[] = 'matched only';
                }
                if ($statusFilter !== '') {
                    $activeFilters[] = 'status: ' . worldknowledgeAuditStatusLabel($statusFilter);
                }
            ?>
            <?php if ($activeFilters !== []): ?>
                (<?= h(implode(', ', $activeFilters)) ?>)
            <?php endif; ?>
        </div>
        <div class="pager-links">
            <?php
                $prevParams = $paginationBaseParams;
                $prevParams['page'] = strval(max(1, $page - 1));
                $nextParams = $paginationBaseParams;
                $nextParams['page'] = strval(min($totalPages, $page + 1));
            ?>
            <?php if ($page > 1): ?>
                <a class="pager-link" href="<?= h(worldknowledgeAuditBuildQuery($prevParams)) ?>" rel="prev">Prev</a>
            <?php else: ?>
                <span class="pager-link is-disabled" aria-disabled="true">Prev</span>
            <?php endif; ?>
            <span class="pager-meta">Page <?= h(strval($page)) ?> / <?= h(strval($totalPages)) ?></span>
            <?php if ($page < $totalPages): ?>
                <a class="pager-link" href="<?= h(worldknowledgeAuditBuildQuery($nextParams)) ?>" rel="next">Next</a>
            <?php else: ?>
                <span class="pager-link is-disabled" aria-disabled="true">Next</span>
            <?php endif; ?>
        </div>
    </nav>

    <?php if ($fetchFailed): ?>
        <div class="audit-card empty-state error-state" role="alert">
            <strong>Could not load audit traces.</strong>
            <div>The trace query failed, so this page cannot show existing rows. If DIALECTIC was
                just updated, run the pending database update, then reload. Details are in the server log.</div>
        </div>
    <?php elseif (count($rows) === 0): ?>
        <?php
            // An empty query string would resolve back to the current URL, so an
            // unfiltered view needs an explicit "?".
            $clearHref = worldknowledgeAuditBuildQuery($isEmbed ? ['embed' => '1'] : []);
            $clearHref = $clearHref === '' ? '?' : $clearHref;
        ?>
        <?php if ($activeFilters !== []): ?>
            <div class="audit-card empty-state">
                No traces match the current filter (<?= h(implode(', ', $activeFilters)) ?>).
                <a class="pager-link" style="margin-left:8px;" href="<?= h($clearHref) ?>">Clear filters</a>
            </div>
        <?php else: ?>
            <div class="audit-card empty-state">No structured World Knowledge traces yet.</div>
        <?php endif; ?>
    <?php else: ?>
        <div id="auditNoResults" class="audit-card empty-state" hidden>
            No traces on this page match your search. Clear the box, change the status filter, or try another page.
        </div>
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
                $catalogChecksum = trim(strval($row['catalog_checksum'] ?? ''));
                $promptHash = trim(strval($row['prompt_hash'] ?? ''));
                $matches = worldknowledgeAuditJson($row['grounded_matches'] ?? []);
                $rejections = worldknowledgeAuditJson($row['rejected_candidates'] ?? []);
                // Retrieval-phrase decisions are the only entries the retriever
                // records here; they run solely when topic and alias matching abstains.
                $phraseDecisions = worldknowledgeAuditJson($row['tag_decisions'] ?? []);
                $contextTags = worldknowledgeAuditJson($row['context_tags'] ?? []);
                $fallback = worldknowledgeAuditJson($row['fallback'] ?? []);
                $fallbackSummary = worldknowledgeAuditFallbackSummary($fallback, $status);
                $forced = worldknowledgeAuditJson($row['forced_signals'] ?? []);
                // Article bodies stay out of both the rendered trace and the
                // search payload; the projection keeps the access evidence.
                $access = worldknowledgeAuditAccessProjection(worldknowledgeAuditJson($row['access_decisions'] ?? []));
                $selected = worldknowledgeAuditJson($row['selected_articles'] ?? []);
                $settings = worldknowledgeAuditJson($row['settings'] ?? []);
                $settingRows = worldknowledgeAuditSettingRows($settings);
                $contextTagChips = worldknowledgeAuditTagChips($contextTags);
                $jsonFlags = JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;
                $searchBlob = strtolower(implode(' ', [
                    $input, $normalizedInput, $status, $npcName, $requestType, $catalog,
                    $catalogChecksum, $promptHash, $fallbackSummary['state'],
                    json_encode([$matches, $rejections, $phraseDecisions, $contextTags, $fallback, $forced, $access, $selected, $settings], $jsonFlags),
                ]));
                $cardTitle = trim(sprintf(
                    '%s trace for %s at %s',
                    worldknowledgeAuditStatusLabel($status),
                    $npcName !== '' ? $npcName : '(unknown NPC)',
                    $created !== '' ? $created : '(unknown time)'
                ));
            ?>
            <section class="audit-card" data-search="<?= h($searchBlob) ?>" aria-label="<?= h($cardTitle) ?>">
                <h2 class="sr-only"><?= h($cardTitle) ?></h2>
                <div class="meta-grid">
                    <div class="meta-pill"><div class="meta-label">Status</div><div class="meta-value status-value is-<?= h(worldknowledgeAuditStatusTone($status)) ?>"><?= h(worldknowledgeAuditStatusLabel($status)) ?></div></div>
                    <div class="meta-pill"><div class="meta-label">NPC</div><div class="meta-value"><?= h($npcName !== '' ? $npcName : '(unknown)') ?></div></div>
                    <div class="meta-pill"><div class="meta-label">Request</div><div class="meta-value"><?= h($requestType !== '' ? $requestType : '(unknown)') ?></div></div>
                    <div class="meta-pill"><div class="meta-label">Catalog</div><div class="meta-value"><?= h($catalog !== '' ? $catalog : '(custom only)') ?></div></div>
                    <div class="meta-pill">
                        <div class="meta-label">Catalog Checksum</div>
                        <div class="meta-value"<?= $catalogChecksum !== '' ? ' title="' . h($catalogChecksum) . '"' : '' ?>><?= h($catalogChecksum !== '' ? worldknowledgeAuditShortHash($catalogChecksum) : '(none)') ?></div>
                    </div>
                    <div class="meta-pill">
                        <div class="meta-label">Prompt Hash</div>
                        <div class="meta-value"<?= $promptHash !== '' ? ' title="' . h($promptHash) . '"' : '' ?>><?= h($promptHash !== '' ? worldknowledgeAuditShortHash($promptHash) : '(no prompt emitted)') ?></div>
                    </div>
                    <div class="meta-pill"><div class="meta-label">Fallback</div><div class="meta-value"><?= h($fallbackSummary['state']) ?></div></div>
                    <div class="meta-pill"><div class="meta-label">Algorithm</div><div class="meta-value"><?= h($row['algorithm_version'] ?? '') ?></div></div>
                    <div class="meta-pill"><div class="meta-label">Audit ID</div><div class="meta-value"><?= h($row['audit_id'] ?? '') ?></div></div>
                    <div class="meta-pill"><div class="meta-label">Created</div><div class="meta-value"><?= h($created) ?></div></div>
                    <div class="meta-pill"><div class="meta-label">Elapsed</div><div class="meta-value"><?= h($elapsed) ?> ms</div></div>
                    <div class="meta-pill"><div class="meta-label">Retrieval</div><div class="meta-value"><?= h($row['retrieval_elapsed_ms'] ?? '0') ?> ms</div></div>
                    <?php if ($fallbackSummary['elapsed_ms'] !== null): ?>
                        <div class="meta-pill"><div class="meta-label">Fallback Time</div><div class="meta-value"><?= h(strval($fallbackSummary['elapsed_ms'])) ?> ms</div></div>
                    <?php endif; ?>
                </div>

                <h3 class="section-label">Input</h3>
                <div class="trace-box"><?= h($input) ?></div>

                <?php foreach ([
                    ['label' => 'Selected Articles', 'payload' => $selected],
                    ['label' => 'Grounded Matches', 'payload' => $matches],
                    ['label' => 'Access Decisions', 'payload' => $access, 'note' => 'article text omitted'],
                    ['label' => 'NPC Context Tags', 'payload' => $contextTags, 'chips' => $contextTagChips],
                    ['label' => 'Rejected Candidates', 'payload' => $rejections],
                    ['label' => 'Tag Decisions', 'payload' => $tagDecisions],
                    ['label' => 'Forced Context', 'payload' => $forced],
                    ['label' => 'Fallback', 'payload' => $fallback],
                ] as $section): ?>
                    <h3 class="section-label" style="margin-top:10px;">
                        <?= h($section['label']) ?>
                        <?php if (isset($section['note'])): ?>
                            <span class="section-note">(<?= h($section['note']) ?>)</span>
                        <?php endif; ?>
                    </h3>
                    <?php if (!empty($section['chips'])): ?>
                        <div class="tag-chip-row">
                            <?php foreach ($section['chips'] as $chip): ?>
                                <span class="tag-chip"><?= h($chip) ?></span>
                            <?php endforeach; ?>
                        </div>
                    <?php else: ?>
                        <pre class="trace-box"><?= h($section['payload'] ? json_encode($section['payload'], $jsonFlags) : '(none)') ?></pre>
                    <?php endif; ?>
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
  const cards = Array.from(document.querySelectorAll('[data-search]'));
  const countOutput = document.getElementById('auditVisibleCount');
  const noResults = document.getElementById('auditNoResults');

  const applyFilter = () => {
    const needle = String(searchInput.value || '').trim().toLowerCase();
    let visible = 0;
    cards.forEach((card) => {
      const hay = String(card.getAttribute('data-search') || '').toLowerCase();
      const shown = needle === '' || hay.includes(needle);
      card.hidden = !shown;
      card.style.display = shown ? '' : 'none';
      if (shown) {
        visible += 1;
      }
    });
    if (countOutput) {
      countOutput.textContent = needle === ''
        ? ''
        : ' Showing ' + visible + ' of ' + cards.length + ' on this page.';
    }
    if (noResults) {
      noResults.hidden = !(needle !== '' && visible === 0);
    }
  };

  searchInput.addEventListener('input', applyFilter);
  applyFilter();
}
</script>
</body>
</html>
