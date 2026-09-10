<?php

declare(strict_types=1);

require_once __DIR__ . '/security_bootstrap.php';
sentryiq_security_bootstrap();
header('Content-Type: application/json; charset=utf-8');

function gallery_upload_json(array $payload, int $status = 200): never
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { header('Allow: POST'); gallery_upload_json(['status' => 'error', 'message' => 'POST required.'], 405); }
sentryiq_require_auth();
sentryiq_require_csrf();

$configFile = __DIR__ . '/sentryiq_config.php';
if (!is_file($configFile)) gallery_upload_json(['status' => 'error', 'message' => 'SentryIQ configuration is unavailable.'], 503);
$config = require $configFile;
if (!is_array($config)) gallery_upload_json(['status' => 'error', 'message' => 'SentryIQ configuration is unavailable.'], 503);
$dataDir = rtrim((string)($config['data_dir'] ?? ''), '/');
if ($dataDir === '' || !str_starts_with($dataDir, '/') || !is_dir($dataDir) || is_link($dataDir)) gallery_upload_json(['status' => 'error', 'message' => 'SentryIQ secure runtime is unavailable.'], 503);

require_once __DIR__ . '/cloud/Gallery/Image/GallerySettings.php';
require_once __DIR__ . '/cloud/Gallery/Image/ImageProcessor.php';
require_once __DIR__ . '/cloud/Gallery/Image/ThumbnailGenerator.php';
require_once __DIR__ . '/cloud/Gallery/Storage/DuplicateIndex.php';
require_once __DIR__ . '/cloud/Gallery/Storage/PhotoMetadataStore.php';
require_once __DIR__ . '/cloud/Gallery/Storage/PhotoNameAllocator.php';
require_once __DIR__ . '/cloud/Gallery/Storage/PhotoStorage.php';
require_once __DIR__ . '/cloud/Gallery/UploadService.php';

use SentryIQCloud\Gallery\Image\GallerySettings;
use SentryIQCloud\Gallery\Image\ImageProcessor;
use SentryIQCloud\Gallery\Image\ThumbnailGenerator;
use SentryIQCloud\Gallery\Storage\DuplicateIndex;
use SentryIQCloud\Gallery\Storage\PhotoMetadataStore;
use SentryIQCloud\Gallery\Storage\PhotoNameAllocator;
use SentryIQCloud\Gallery\Storage\PhotoStorage;
use SentryIQCloud\Gallery\UploadService;

$files = $_FILES['photos'] ?? null;
if (!is_array($files) || !isset($files['tmp_name'], $files['error'])) gallery_upload_json(['status' => 'error', 'message' => 'No photos were supplied.'], 400);
$tmpNames = $files['tmp_name']; $errors = $files['error']; $names = $files['name'] ?? [];
if (!is_array($tmpNames) || !is_array($errors)) gallery_upload_json(['status' => 'error', 'message' => 'Invalid photo upload data.'], 400);

try {
    $galleryRoot = $dataDir . '/gallery';
    $settings = GallerySettings::load($dataDir);
    $service = new UploadService(
        new ImageProcessor($settings['webp_quality'], $settings['preserve_transparency']),
        new ThumbnailGenerator($settings['thumbnail_max_dimension'], $settings['thumbnail_quality'], $settings['preserve_transparency']),
        new DuplicateIndex($galleryRoot . '/duplicate-index.json'),
        new PhotoStorage($galleryRoot),
        new PhotoMetadataStore($galleryRoot . '/metadata.json'),
        new PhotoNameAllocator($galleryRoot, $galleryRoot . '/photo-name.lock'),
    );
    $results = [];
    foreach ($errors as $index => $error) {
        $results[] = $service->upload(['name' => is_array($names) ? ($names[$index] ?? '') : '', 'tmp_name' => $tmpNames[$index] ?? '', 'error' => $error]);
    }

    if (function_exists('log_security_event') && function_exists('get_visitor_ip')) {
        try { log_security_event('GALLERY_UPLOAD', get_visitor_ip(), $_SESSION['app_username'] ?? 'unknown'); }
        catch (Throwable $exception) { error_log('SentryIQ Gallery audit logging failed: ' . $exception->getMessage()); }
    }
    gallery_upload_json(['status' => 'complete', 'results' => $results]);
} catch (Throwable $exception) {
    $exceptionClass = $exception::class;
    $exceptionMessage = trim($exception->getMessage());
    error_log('SentryIQ Gallery upload failed: ' . $exceptionClass . ': ' . $exceptionMessage);

    $safeMessage = $exceptionMessage !== '' ? $exceptionMessage : 'An unexpected upload processing error occurred.';
    gallery_upload_json([
        'status' => 'error',
        'message' => 'Upload processing failed: ' . $safeMessage,
        'error_class' => $exceptionClass,
    ], 500);
}
