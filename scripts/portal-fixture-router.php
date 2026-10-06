#!/usr/bin/env php
<?php
/**
 * Router for `php -S` portal fixture smoke.
 * Maps /api/portal/v1/* → api/portal/v1/index.php and /portal/* → portal/dist when present.
 */
declare(strict_types=1);

$uri = urldecode(parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/');
$root = dirname(__DIR__);

if (preg_match('#^/api/portal/v1(?:/|$)#', $uri)) {
    require $root . '/api/portal/v1/index.php';
    return true;
}

$file = $root . $uri;
if ($uri !== '/' && is_file($file)) {
    return false; // let built-in server serve the file
}

$portalIndex = $root . '/portal/dist/index.html';
if (str_starts_with($uri, '/portal') && is_file($portalIndex)) {
    header('Content-Type: text/html; charset=utf-8');
    readfile($portalIndex);
    return true;
}

http_response_code(404);
header('Content-Type: text/plain; charset=utf-8');
echo "Not found: {$uri}\n";
return true;
