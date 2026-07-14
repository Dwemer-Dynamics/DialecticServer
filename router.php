<?php

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$root = dirname(__DIR__);

if ($path === '/' || $path === '') {
    header('Location: ui/core/config_hub.php');
    return true;
}

$file = realpath($root . str_replace('/', DIRECTORY_SEPARATOR, rawurldecode($path)));
if ($file !== false && str_starts_with($file, $root) && is_file($file)) {
    return false;
}

return false;
