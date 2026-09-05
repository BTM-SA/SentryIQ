<?php

declare(strict_types=1);

require_once __DIR__ . '/security_bootstrap.php';
sentryiq_security_bootstrap();
sentryiq_require_auth();
sentryiq_require_csrf();

$configFile = __DIR__ . '/sentryiq_config.php';
$config = is_file($configFile) ? require $configFile : [];
$dataDir = is_array($config) ? rtrim((string)($config['data_dir'] ?? ''), '/') : '';
if ($dataDir === '' || !str_starts_with($dataDir, '/') || !is_dir($dataDir) || is_link($dataDir)) {
    http_response_code(503);
    exit('SentryIQ secure runtime is unavailable.');
}

require_once __DIR__ . '/cloud/Gallery/Storage/PhotoMetadataStore.php';
use SentryIQCloud\Gallery\Storage\PhotoMetadataStore;

header('Content-Type: application/json; charset=utf-8');

$photoId = (string)($_POST['photo_id'] ?? '');
if (!preg_match('/^[a-f0-9]{32}$/', $photoId)) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Invalid photo ID.']);
    exit;
}

$galleryRoot = $dataDir . '/gallery';
$thumbnailRoot = $galleryRoot . '/thumbnails';
$photoRoot = $galleryRoot . '/photos';
$foundBucket = null;

for ($bucket = 0; $bucket < 256; $bucket++) {
    $bucketName = str_pad(dechex($bucket), 2, '0', STR_PAD_LEFT);
    $thumbnail = $thumbnailRoot . '/' . $bucketName . '/' . $photoId . '.webp';
    if (is_file($thumbnail) && !is_link($thumbnail)) {
        $foundBucket = $bucketName;
        break;
    }
}

if ($foundBucket === null) {
    http_response_code(404);
    echo json_encode(['status' => 'error', 'message' => 'Photo does not exist.']);
    exit;
}

$original = $photoRoot . '/' . $foundBucket . '/' . $photoId . '.webp';
$thumbnail = $thumbnailRoot . '/' . $foundBucket . '/' . $photoId . '.webp';
if (is_link($original) || is_link($thumbnail) || !is_file($original)) {
    http_response_code(404);
    echo json_encode(['status' => 'error', 'message' => 'Photo does not exist.']);
    exit;
}

$albumsFile = $galleryRoot . '/albums.json';
$albums = null;
if (is_file($albumsFile)) {
    $json = @file_get_contents($albumsFile);
    $albums = is_string($json) ? json_decode($json, true) : null;
    if (!is_array($albums)) {
        http_response_code(500);
        echo json_encode(['status' => 'error', 'message' => 'Gallery album index is invalid.']);
        exit;
    }
}

$duplicateFile = $galleryRoot . '/duplicate-index.json';
$index = null;
if (is_file($duplicateFile)) {
    $json = @file_get_contents($duplicateFile);
    $index = is_string($json) ? json_decode($json, true) : null;
    if (!is_array($index)) {
        http_response_code(500);
        echo json_encode(['status' => 'error', 'message' => 'Duplicate index is invalid.']);
        exit;
    }
}

if (!@unlink($original)) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Unable to delete gallery photo.']);
    exit;
}

if (!@unlink($thumbnail)) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Photo was deleted but its thumbnail could not be removed.']);
    exit;
}

if (is_array($albums)) {
    $changed = false;
    foreach ($albums as &$photos) {
        if (!is_array($photos)) continue;
        $filtered = array_values(array_filter($photos, static fn(mixed $id): bool => $id !== $photoId));
        if ($filtered !== $photos) $changed = true;
        $photos = $filtered;
    }
    unset($photos);

    if ($changed) {
        $encoded = json_encode($albums, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $temporary = $albumsFile . '.tmp-' . bin2hex(random_bytes(8));
        if ($encoded === false || @file_put_contents($temporary, $encoded . PHP_EOL, LOCK_EX) === false || !@rename($temporary, $albumsFile)) {
            @unlink($temporary);
            http_response_code(500);
            echo json_encode(['status' => 'error', 'message' => 'Photo deleted but album index cleanup failed.']);
            exit;
        }
        @chmod($albumsFile, 0600);
    }
}

if (is_array($index)) {
    $changed = false;
    foreach ($index as $hash => $indexedPhotoId) {
        if ($indexedPhotoId === $photoId) {
            unset($index[$hash]);
            $changed = true;
        }
    }

    if ($changed) {
        $encoded = json_encode($index, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        $temporary = $duplicateFile . '.tmp-' . bin2hex(random_bytes(8));
        if ($encoded === false || @file_put_contents($temporary, $encoded . PHP_EOL, LOCK_EX) === false || !@rename($temporary, $duplicateFile)) {
            @unlink($temporary);
            http_response_code(500);
            echo json_encode(['status' => 'error', 'message' => 'Photo deleted but duplicate index cleanup failed.']);
            exit;
        }
        @chmod($duplicateFile, 0600);
    }
}

try {
    (new PhotoMetadataStore($galleryRoot . '/metadata.json'))->remove($photoId);
} catch (RuntimeException $exception) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Photo deleted but metadata cleanup failed.']);
    exit;
}

log_security_event('GALLERY_PHOTO_DELETED', get_visitor_ip(), $_SESSION['app_username'] ?? 'unknown', ['photo_id' => $photoId]);

echo json_encode(['status' => 'ok', 'photo_id' => $photoId], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
