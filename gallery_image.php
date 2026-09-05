<?php

declare(strict_types=1);

require_once __DIR__ . '/security_bootstrap.php';
sentryiq_security_bootstrap();
sentryiq_require_auth();

$configFile = __DIR__ . '/sentryiq_config.php';
if (!is_file($configFile)) {
    http_response_code(503);
    exit('SentryIQ configuration is unavailable.');
}
$config = require $configFile;
if (!is_array($config)) {
    http_response_code(503);
    exit('SentryIQ configuration is unavailable.');
}
$dataDir = rtrim((string)($config['data_dir'] ?? ''), '/');
if ($dataDir === '' || !str_starts_with($dataDir, '/') || !is_dir($dataDir) || is_link($dataDir)) {
    http_response_code(503);
    exit('SentryIQ secure runtime is unavailable.');
}

$id = (string)($_GET['id'] ?? '');
if (!preg_match('/^[a-f0-9]{32}$/', $id)) {
    http_response_code(404);
    exit('Image not found.');
}

$root = $dataDir . '/gallery/thumbnails';
$path = null;
for ($bucket = 0; $bucket < 256; $bucket++) {
    $bucketName = str_pad(dechex($bucket), 2, '0', STR_PAD_LEFT);
    $candidate = $root . '/' . $bucketName . '/' . $id . '.webp';
    if (is_file($candidate)) {
        $path = $candidate;
        break;
    }
}

if ($path === null) {
    http_response_code(404);
    exit('Image not found.');
}

header('Content-Type: image/webp');
header('Content-Length: ' . (string) filesize($path));
header('Cache-Control: private, max-age=300');
header('X-Content-Type-Options: nosniff');
readfile($path);
