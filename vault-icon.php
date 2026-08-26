<?php

declare(strict_types=1);

require_once __DIR__ . '/security_bootstrap.php';
sentryiq_security_bootstrap();
sentryiq_require_auth();

$configFile = __DIR__ . '/sentryiq_config.php';
$config = is_file($configFile) ? require $configFile : [];
$dataDir = is_array($config) ? rtrim((string)($config['data_dir'] ?? ''), '/') : '';
if ($dataDir === '' || !is_file($dataDir . '/vault_engine.php')) {
    http_response_code(503);
    exit;
}
require_once $dataDir . '/vault_engine.php';

$id = trim((string)($_GET['id'] ?? ''));
if ($id === '' || !preg_match('/^[a-f0-9]{32}$/i', $id)) {
    http_response_code(404);
    exit;
}

$passwords = load_passwords();
if (!is_array($passwords)) {
    http_response_code(404);
    exit;
}

foreach ($passwords as $entry) {
    if (($entry['id'] ?? '') !== $id) continue;

    $path = (string)($entry['icon_path'] ?? '');
    $iconRoot = realpath(SENTRYIQ_DATA_DIR . '/vault_icons');
    $realPath = is_file($path) ? realpath($path) : false;
    if ($iconRoot === false || $realPath === false || !str_starts_with($realPath, rtrim($iconRoot, '/') . '/') || !is_readable($realPath)) {
        http_response_code(404);
        exit;
    }

    $mime = mime_content_type($realPath) ?: 'application/octet-stream';
    $allowed = ['image/png','image/jpeg','image/gif','image/webp','image/avif','image/x-icon','image/vnd.microsoft.icon'];
    if (!in_array(strtolower($mime), $allowed, true)) {
        http_response_code(404);
        exit;
    }

    header('Content-Type: ' . $mime);
    header('Content-Length: ' . (string)filesize($realPath));
    header('Cache-Control: private, max-age=86400');
    header('X-Content-Type-Options: nosniff');
    readfile($realPath);
    exit;
}

http_response_code(404);
