<?php

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

$versionFile = dirname(__DIR__, 2) . '/.version_number.txt';
$serverVersionRaw = '0.6.5'; // Keep fallback aligned with navbar.php.

if (is_file($versionFile)) {
    $versionContent = trim((string) file_get_contents($versionFile));
    if ($versionContent !== '') {
        $serverVersionRaw = $versionContent;
    }
}

echo json_encode([
    'serverVersion' => $serverVersionRaw,
], JSON_UNESCAPED_SLASHES);
