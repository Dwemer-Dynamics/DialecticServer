<?php
// Compatibility endpoint retained for bookmarks and links from older DIALECTIC builds.
$legacyTab = strtolower(trim((string)($_GET['tab'] ?? 'diaries')));
$tabMap = [
    'diaries' => 'diaries',
    'adventure' => 'adventure',
    'gallery' => 'gallery',
];

$query = $_GET;
$query['tab'] = $tabMap[$legacyTab] ?? 'diaries';

header('Location: events-memories.php?' . http_build_query($query), true, 302);
exit;
