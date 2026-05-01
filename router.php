<?php
$uri = $_SERVER['REQUEST_URI'];
$path = parse_url($uri, PHP_URL_PATH);
if (str_starts_with($path, '/api/') || $path === '/api') {
    $_SERVER['PATH_INFO'] = substr($path, 4) ?: '/';
    require __DIR__ . '/api.php';
    return true;
}
if (preg_match('/\.(?:css|js|png|jpg|gif|ico|json)$/', $path)) {
    return false;
}
return false;
