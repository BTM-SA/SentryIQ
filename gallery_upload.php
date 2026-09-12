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

function gallery_ini_bytes(string $value): int
{
    $value = trim($value);
    if ($value === '') return 0;
    $last = strtolower(substr($value, -1));
    $number = (float)$value;
    return match ($last) {
        'g' => (int)round($number * 1024 * 1024 * 1024),
        'm' => (int)round($number * 1024 * 1024),
        'k' => (int)round($number * 1024),
        default => (int)round($number),
    };
}

function gallery_upload_log(string $message): void
{
    $dataDir = function_exists('sentryiq_data_dir') ? sentryiq_data_dir() : '';
    if ($dataDir === '' || !is_dir($dataDir) || is_link($dataDir)) return;

    $path = $dataDir . '/gallery_upload.log';
    $line = '[' . date('c') . '] ' . $message . PHP_EOL;
    @file_put_contents($path, $line, FILE_APPEND | LOCK_EX);
    @chmod($path, 0600);
}

$currentUploadName = null;
$currentUploadSize = null;
$currentUploadStage = 'request';

register_shutdown_function(static function () use (&$currentUploadName, &$currentUploadSize, &$currentUploadStage): void {
    $error = error_get_last();
    if ($error === null) return;

    $fatalTypes = [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR];
    if (!in_array((int)$error['type'], $fatalTypes, true)) return;

    $message = trim((string)($error['message'] ?? ''));
    $file = (string)($error['file'] ?? '-');
    $line = (int)($error['line'] ?? 0);

    gallery_upload_log(sprintf(
        'FATAL type=%d name=%s size=%s stage=%s message=%s at=%s:%d memory_limit=%s memory_usage=%d',
        (int)$error['type'],
        $currentUploadName ?? '-',
        $currentUploadSize === null ? '-' : (string)$currentUploadSize,
        $currentUploadStage,
        $message,
        $file,
        $line,
        (string)ini_get('memory_limit'),
        memory_get_usage(true),
    ));

    while (ob_get_level() > 0) {
        ob_end_clean();
    }

    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'status' => 'error',
        'message' => 'Upload failed due to a server error: ' . ($message !== '' ? $message : 'Unknown fatal PHP error.'),
        'error_type' => (int)$error['type'],
        'error_file' => $file,
        'error_line' => $line,
        'upload_name' => $currentUploadName,
        'upload_size' => $currentUploadSize,
        'upload_stage' => $currentUploadStage,
        'memory_limit' => (string)ini_get('memory_limit'),
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
});

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Allow: POST');
    gallery_upload_json(['status' => 'error', 'message' => 'POST required.'], 405);
}
sentryiq_require_auth();
sentryiq_require_csrf();

$postMaxSize = (string)ini_get('post_max_size');
$postMaxBytes = gallery_ini_bytes($postMaxSize);
$contentLength = isset($_SERVER['CONTENT_LENGTH']) ? (int)$_SERVER['CONTENT_LENGTH'] : 0;
gallery_upload_log(sprintf(
    'REQUEST content_length=%d post_max_size=%s upload_max_filesize=%s memory_limit=%s',
    $contentLength,
    $postMaxSize,
    (string)ini_get('upload_max_filesize'),
    (string)ini_get('memory_limit'),
));

if ($postMaxBytes > 0 && $contentLength > $postMaxBytes && empty($_FILES)) {
    gallery_upload_log(sprintf('REJECT reason=POST_TOO_LARGE content_length=%d post_max_bytes=%d', $contentLength, $postMaxBytes));
    gallery_upload_json([
        'status' => 'error',
        'message' => 'The upload is too large for the server. The current PHP POST limit is ' . $postMaxSize . '.',
        'error_code' => 'POST_TOO_LARGE',
    ], 413);
}

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

gallery_upload_log('FILES entries=' . count($errors));

try {
    $galleryRoot = $dataDir . '/gallery';
    $settings = GallerySettings::load($dataDir);
    gallery_upload_log(sprintf(
        'SETTINGS webp_quality=%d thumbnail_quality=%d thumbnail_max_dimension=%d preserve_transparency=%s',
        (int)$settings['webp_quality'],
        (int)$settings['thumbnail_quality'],
        (int)$settings['thumbnail_max_dimension'],
        $settings['preserve_transparency'] ? 'true' : 'false',
    ));

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
        $currentUploadName = is_array($names) ? (string)($names[$index] ?? '') : '';
        $tmpPath = is_string($tmpNames[$index] ?? null) ? (string)$tmpNames[$index] : '';
        $currentUploadSize = ($tmpPath !== '' && is_file($tmpPath)) ? (int)@filesize($tmpPath) : null;
        $currentUploadStage = 'before_processing';

        $dimensions = null;
        $mime = null;
        if ($error === UPLOAD_ERR_OK && $tmpPath !== '' && is_file($tmpPath) && !is_link($tmpPath)) {
            $finfo = new \finfo(FILEINFO_MIME_TYPE);
            $mimeValue = $finfo->file($tmpPath);
            $mime = is_string($mimeValue) ? $mimeValue : null;
            $sizeInfo = @getimagesize($tmpPath);
            if (is_array($sizeInfo) && isset($sizeInfo[0], $sizeInfo[1])) {
                $dimensions = (string)(int)$sizeInfo[0] . 'x' . (string)(int)$sizeInfo[1];
            }
        }

        gallery_upload_log(sprintf(
            'START index=%s name=%s upload_error=%s size=%s mime=%s dimensions=%s',
            (string)$index,
            $currentUploadName !== '' ? $currentUploadName : '-',
            (string)$error,
            $currentUploadSize === null ? '-' : (string)$currentUploadSize,
            $mime ?? '-',
            $dimensions ?? '-',
        ));

        $currentUploadStage = 'processing';
        $result = $service->upload([
            'name' => $currentUploadName,
            'tmp_name' => $tmpPath,
            'error' => $error,
        ]);
        $results[] = $result;
        $currentUploadStage = 'complete';

        gallery_upload_log(sprintf(
            'RESULT name=%s status=%s message=%s',
            $currentUploadName !== '' ? $currentUploadName : '-',
            (string)($result['status'] ?? '-'),
            trim((string)($result['message'] ?? '')),
        ));
    }

    if (function_exists('log_security_event') && function_exists('get_visitor_ip')) {
        try { log_security_event('GALLERY_UPLOAD', get_visitor_ip(), $_SESSION['app_username'] ?? 'unknown'); }
        catch (Throwable $exception) { error_log('SentryIQ Gallery audit logging failed: ' . $exception->getMessage()); }
    }
    gallery_upload_log('REQUEST_COMPLETE');
    gallery_upload_json(['status' => 'complete', 'results' => $results]);
} catch (Throwable $exception) {
    $exceptionClass = $exception::class;
    $exceptionMessage = trim($exception->getMessage());
    gallery_upload_log(sprintf(
        'EXCEPTION class=%s name=%s size=%s stage=%s message=%s',
        $exceptionClass,
        $currentUploadName ?? '-',
        $currentUploadSize === null ? '-' : (string)$currentUploadSize,
        $currentUploadStage,
        $exceptionMessage,
    ));
    error_log('SentryIQ Gallery upload failed: ' . $exceptionClass . ': ' . $exceptionMessage);

    $safeMessage = $exceptionMessage !== '' ? $exceptionMessage : 'An unexpected upload processing error occurred.';
    gallery_upload_json([
        'status' => 'error',
        'message' => 'Upload processing failed: ' . $safeMessage,
        'error_class' => $exceptionClass,
    ], 500);
}
