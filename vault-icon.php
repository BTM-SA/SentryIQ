<?php

declare(strict_types=1);

ini_set('session.cookie_httponly', '1');
ini_set('session.cookie_samesite', 'Strict');
session_start();

if (!isset($_SESSION['master_key'])) {
    http_response_code(403);
    exit;
}

require_once '/home/bicheveb/private_data/vault_engine.php';

$id = trim((string)($_GET['id'] ?? ''));
if ($id === '') {
    http_response_code(404);
    exit;
}

$passwords = load_passwords();
foreach ($passwords as $entry) {
    if (($entry['id'] ?? '') !== $id) {
        continue;
    }

    $path = $entry['icon_path'] ?? '';
    if ($path === '' || !is_file($path) || !is_readable($path)) {
        http_response_code(404);
        exit;
    }

    $mime = mime_content_type($path) ?: 'application/octet-stream';
    if (!str_starts_with(strtolower($mime), 'image/')) {
        http_response_code(404);
        exit;
    }

    header('Content-Type: ' . $mime);
    header('Content-Length: ' . (string)filesize($path));
    header('Cache-Control: private, max-age=86400');
    header('X-Content-Type-Options: nosniff');
    readfile($path);
    exit;
}

http_response_code(404);
