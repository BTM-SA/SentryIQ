<?php

declare(strict_types=1);

require_once __DIR__ . '/security_bootstrap.php';
sentryiq_security_bootstrap();
sentryiq_require_auth();

$configFile = __DIR__ . '/sentryiq_config.php';
if (!is_file($configFile)) { http_response_code(503); exit('SentryIQ configuration is unavailable.'); }
$config = require $configFile;
if (!is_array($config)) { http_response_code(503); exit('SentryIQ configuration is unavailable.'); }
$dataDir = rtrim((string)($config['data_dir'] ?? ''), '/');
if ($dataDir === '' || !str_starts_with($dataDir, '/') || !is_dir($dataDir) || is_link($dataDir)) { http_response_code(503); exit('SentryIQ secure runtime is unavailable.'); }
require_once __DIR__ . '/cloud/Gallery/Storage/PhotoMetadataStore.php';
require_once __DIR__ . '/cloud/Gallery/Image/GallerySettings.php';
require_once __DIR__ . '/cloud/Gallery/Image/ImageDerivativeGenerator.php';
use SentryIQCloud\Gallery\Storage\PhotoMetadataStore;
use SentryIQCloud\Gallery\Image\GallerySettings;
use SentryIQCloud\Gallery\Image\ImageDerivativeGenerator;

$id = (string)($_GET['id'] ?? '');
$thumbnail = isset($_GET['thumbnail']) && $_GET['thumbnail'] === '1';
if (!preg_match('/^[a-f0-9]{32}$/', $id)) { http_response_code(404); exit('Image not found.'); }
$galleryRoot = $dataDir . '/gallery';
$metadata = (new PhotoMetadataStore($galleryRoot . '/metadata.json'))->find($id);
$path = null;

if (is_array($metadata) && preg_match('/^img\d+\.webp$/', (string)($metadata['filename'] ?? '')) && preg_match('/^[a-f0-9]{64}$/', (string)($metadata['content_hash'] ?? ''))) {
    $bucket = substr($metadata['content_hash'], 0, 2);
    $storageDirectory = $thumbnail ? 'thumbnails' : 'photos';
    $candidate = $galleryRoot . '/' . $storageDirectory . '/' . $bucket . '/' . $metadata['filename'];
    if (is_file($candidate) && !is_link($candidate)) $path = $candidate;
}

if ($path === null) {
    $root = $galleryRoot . '/' . ($thumbnail ? 'thumbnails' : 'photos');
    for ($bucket = 0; $bucket < 256; $bucket++) {
        $bucketName = str_pad(dechex($bucket), 2, '0', STR_PAD_LEFT);
        $candidate = $root . '/' . $bucketName . '/' . $id . '.webp';
        if (is_file($candidate) && !is_link($candidate)) { $path = $candidate; break; }
    }
}

if ($path === null) { http_response_code(404); exit('Image not found.'); }

if (!$thumbnail) {
    $settings = GallerySettings::load($dataDir);
    $webp = @file_get_contents($path);
    if (!is_string($webp) || $webp === '') { http_response_code(404); exit('Image not found.'); }
    try {
        $preview = (new ImageDerivativeGenerator((int)$settings['preview_max_dimension'], (int)$settings['preview_quality'], (bool)$settings['preserve_transparency'], true))->fromWebp($webp);
        header('Content-Type: image/webp');
        header('Content-Length: ' . (string)strlen($preview));
        header('Cache-Control: private, max-age=300');
        header('X-Content-Type-Options: nosniff');
        echo $preview;
        exit;
    } catch (Throwable $exception) {
        // Fall back to the stored image if preview generation cannot be completed.
    }
}

header('Content-Type: image/webp');
header('Content-Length: ' . (string)filesize($path));
header('Cache-Control: private, max-age=300');
header('X-Content-Type-Options: nosniff');
readfile($path);
