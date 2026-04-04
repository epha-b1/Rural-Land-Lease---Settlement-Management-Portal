<?php
/**
 * Router script for PHP built-in server.
 * Serves static files directly, routes API requests through ThinkPHP.
 */

$uri = urldecode(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH));

// Serve existing static files directly
$staticPath = __DIR__ . $uri;
if ($uri !== '/' && file_exists($staticPath) && is_file($staticPath)) {
    return false;
}

// Route everything else through ThinkPHP
require __DIR__ . '/index.php';
