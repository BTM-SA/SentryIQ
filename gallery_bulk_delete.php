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

$photoIds = $_POST['photo_ids'] ?? [];
if (!is_array($photoIds)) {
    $photoIds = [$photoIds];
}

$photoIds = array_values(array_unique(array_filter(
    array_map(static fn(mixed $id): string => is_string($id) ? $id : '', $photoIds),
    static fn(string $id): bool => preg_match('/^[a-f0-9]{32}$/', $id) === 1,
)));

if ($photoIds === []) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'No valid photos were selected.']);
    exit;
}

if (count($photoIds) > 500) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'You can delete up to 500 photos at once.']);
    exit;
}

$galleryRoot = $dataDir . '/gallery';
$metadataStore = new PhotoMetadataStore($galleryRoot . '/metadata.json');

$deleted = [];
$failed = [];

foreach ($photoIds as $photoId) {
    $metadata = $metadataStore->find($photoId);
    $original = null;
    $thumbnail = null;

    if (is_array($metadata)
        && preg_match('/^img\d+\.webp$/', (string)($metadata['filename'] ?? ''))
        && preg_match('/^[a-f0-9]{64}$/', (string)($metadata['content_hash'] ?? ''))) {
        $bucket = substr((string)$metadata['content_hash'], 0, 2);
        $filename = (string)$metadata['filename'];
        $candidateOriginal = $galleryRoot . '/photos/' . $bucket . '/' . $filename;
        $candidateThumbnail = $galleryRoot . '/thumbnails/' . $bucket . '/' . $filename;
        if (is_file($candidateOriginal) && !is_link($candidateOriginal) && is_file($candidateThumbnail) && !is_link($candidateThumbnail)) {
            $original = $candidateOriginal;
            $thumbnail = $candidateThumbnail;
        }
    }

    // Backward compatibility for legacy random-ID filenames.
    if ($original === null || $thumbnail === null) {
        for ($bucket = 0; $bucket < 256; $bucket++) {
            $bucketName = str_pad(dechex($bucket), 2, '0', STR_PAD_LEFT);
            $candidateThumbnail = $galleryRoot . '/thumbnails/' . $bucketName . '/' . $photoId . '.webp';
            $candidateOriginal = $galleryRoot . '/photos/' . $bucketName . '/' . $photoId . '.webp';
            if (is_file($candidateOriginal) && !is_link($candidateOriginal) && is_file($candidateThumbnail) && !is_link($candidateThumbnail)) {
                $thumbnail = $candidateThumbnail;
                $original = $candidateOriginal;
                break;
            }
        }
    }

    if ($original === null || $thumbnail === null || is_link($original) || is_link($thumbnail)) {
        $failed[] = ['photo_id' => $photoId, 'message' => 'Photo does not exist.'];
        continue;
    }

    if (!@unlink($original)) {
        $failed[] = ['photo_id' => $photoId, 'message' => 'Unable to delete gallery photo.'];
        continue;
    }

    if (!@unlink($thumbnail)) {
        $failed[] = ['photo_id' => $photoId, 'message' => 'Photo was deleted but its thumbnail could not be removed.'];
        continue;
    }

    try {
        $metadataStore->remove($photoId);
    } catch (RuntimeException $exception) {
        $failed[] = ['photo_id' => $photoId, 'message' => 'Photo deleted but metadata cleanup failed.'];
        continue;
    }

    $deleted[] = $photoId;
}

// Remove deleted IDs from all album memberships in one atomic index update.
$albumsFile = $galleryRoot . '/albums.json';
if ($deleted !== [] && is_file($albumsFile)) {
    $json = @file_get_contents($albumsFile);
    $albums = is_string($json) ? json_decode($json, true) : null;
    if (is_array($albums)) {
        $deletedSet = array_fill_keys($deleted, true);
        $changed = false;
        foreach ($albums as &$photos) {
            if (!is_array($photos)) continue;
            $filtered = array_values(array_filter($photos, static fn(mixed $id): bool => !isset($deletedSet[$id])));
            if ($filtered !== $photos) $changed = true;
            $photos = $filtered;
        }
        unset($photos);
        if ($changed) {
            $encoded = json_encode($albums, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            $temporary = $albumsFile . '.tmp-' . bin2hex(random_bytes(8));
            if ($encoded === false || @file_put_contents($temporary, $encoded . PHP_EOL, LOCK_EX) === false || !@rename($temporary, $albumsFile)) {
                @unlink($temporary);
                $failed[] = ['message' => 'Photos were deleted but album index cleanup failed.'];
            } else {
                @chmod($albumsFile, 0600);
            }
        }
    } else {
        $failed[] = ['message' => 'Photos were deleted but the album index is invalid.'];
    }
}

// Remove deleted IDs from the duplicate index in one atomic update.
$duplicateFile = $galleryRoot . '/duplicate-index.json';
if ($deleted !== [] && is_file($duplicateFile)) {
    $json = @file_get_contents($duplicateFile);
    $index = is_string($json) ? json_decode($json, true) : null;
    if (is_array($index)) {
        $deletedSet = array_fill_keys($deleted, true);
        $changed = false;
        foreach ($index as $hash => $indexedPhotoId) {
            if (isset($deletedSet[$indexedPhotoId])) {
                unset($index[$hash]);
                $changed = true;
            }
        }
        if ($changed) {
            $encoded = json_encode($index, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
            $temporary = $duplicateFile . '.tmp-' . bin2hex(random_bytes(8));
            if ($encoded === false || @file_put_contents($temporary, $encoded . PHP_EOL, LOCK_EX) === false || !@rename($temporary, $duplicateFile)) {
                @unlink($temporary);
                $failed[] = ['message' => 'Photos were deleted but duplicate index cleanup failed.'];
            } else {
                @chmod($duplicateFile, 0600);
            }
        }
    } else {
        $failed[] = ['message' => 'Photos were deleted but the duplicate index is invalid.'];
    }
}

try {
    if ($deleted !== [] && function_exists('log_security_event') && function_exists('get_visitor_ip')) {
        log_security_event(
            'GALLERY_PHOTOS_BULK_DELETED',
            get_visitor_ip(),
            $_SESSION['app_username'] ?? 'unknown',
            ['photo_ids' => $deleted, 'count' => count($deleted)],
        );
    }
} catch (Throwable $exception) {
    error_log('SentryIQ Gallery bulk delete audit logging failed: ' . $exception->getMessage());
}

if ($deleted === []) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'No selected photos could be deleted.', 'failed' => $failed], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

$status = $failed === [] ? 'ok' : 'partial';
if ($status === 'partial') {
    http_response_code(207);
}

echo json_encode([
    'status' => $status,
    'deleted' => $deleted,
    'deleted_count' => count($deleted),
    'failed' => $failed,
], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
