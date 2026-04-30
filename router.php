<?php
$uri = $_SERVER['REQUEST_URI'];
$path = parse_url($uri, PHP_URL_PATH);
if (preg_match('#^/api(/.*)?$#', $path, $m)) {
    $_SERVER['PATH_INFO'] = $m[1] ?? '';
    $_SERVER['ORIG_PATH_INFO'] = $m[1] ?? '';
    include __DIR__ . '/api.php';
    return true;
}
return false;
