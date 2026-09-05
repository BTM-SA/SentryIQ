<?php

declare(strict_types=1);

require_once __DIR__ . '/security_bootstrap.php';
sentryiq_security_bootstrap();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['status' => 'error', 'message' => 'POST required.']);
    exit;
}

sentryiq_require_auth();
sentryiq_require_csrf();

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

require_once __DIR__ . '/cloud/Gallery/Image/ImageProcessor.php';
require_once __DIR__ . '/cloud/Gallery/Image/ThumbnailGenerator.php';
require_once __DIR__ . '/cloud/Gallery/Storage/DuplicateIndex.php';
require_once __DIR__ . '/cloud/Gallery/Storage/PhotoStorage.php';
require_once __DIR__ . '/cloud/Gallery/UploadService.php';

use SentryIQCloud\Gallery\Image\ImageProcessor;
use SentryIQCloud\Gallery\Image\ThumbnailGenerator;
use SentryIQCloud\Gallery\Storage\DuplicateIndex;
use SentryIQCloud\Gallery\Storage\PhotoStorage;
use SentryIQCloud\Gallery\UploadService;

$files = $_FILES['photos'] ?? null;
if (!is_array($files) || !isset($files['tmp_name'], $files['error'])) {
    http_response_code(400);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['status' => 'error', 'message' => 'No photos were supplied.']);
    exit;
}

$tmpNames = $files['tmp_name'];
$errors = $files['error'];
$names = $files['name'] ?? [];
if (!is_array($tmpNames) || !is_array($errors)) {
    http_response_code(400);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['status' => 'error', 'message' => 'Invalid photo upload data.']);
    exit;
}

$galleryRoot = $dataDir . '/gallery';
$service = new UploadService(
    new ImageProcessor(),
    new ThumbnailGenerator(),
    new DuplicateIndex($galleryRoot . '/duplicate-index.json'),
    new PhotoStorage($galleryRoot),
);

$results = [];
foreach ($errors as $index => $error) {
    $results[] = $service->upload([
        'name' => is_array($names) ? ($names[$index] ?? '') : '',
        'tmp_name' => $tmpNames[$index] ?? '',
        'error' => $error,
    ]);
}

log_security_event('GALLERY_UPLOAD', get_visitor_ip(), $_SESSION['app_username'] ?? 'unknown');

header('Content-Type: application/json; charset=utf-8');
echo json_encode(['status' => 'complete', 'results' => $results], JSON_UNESCAPED_SLASHES);
