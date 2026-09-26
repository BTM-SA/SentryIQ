<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/security_bootstrap.php';
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
    gallery_upload_log(sprintf('FATAL type=%d name=%s size=%s stage=%s message=%s at=%s:%d memory_limit=%s memory_usage=%d', (int)$error['type'], $currentUploadName ?? '-', $currentUploadSize === null ? '-' : (string)$currentUploadSize, $currentUploadStage, $message, $file, $line, (string)ini_get('memory_limit'), memory_get_usage(true)));
    while (ob_get_level() > 0) ob_end_clean();
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['status' => 'error', 'message' => 'Upload failed due to a server error: ' . ($message !== '' ? $message : 'Unknown fatal PHP error.'), 'error_type' => (int)$error['type'], 'error_file' => $file, 'error_line' => $line, 'upload_name' => $currentUploadName, 'upload_size' => $currentUploadSize, 'upload_stage' => $currentUploadStage, 'memory_limit' => (string)ini_get('memory_limit')], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
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
gallery_upload_log(sprintf('REQUEST content_length=%d post_max_size=%s upload_max_filesize=%s memory_limit=%s', $contentLength, $postMaxSize, (string)ini_get('upload_max_filesize'), (string)ini_get('memory_limit')));
if ($postMaxBytes > 0 && $contentLength > $postMaxBytes && empty($_FILES)) {
    gallery_upload_log(sprintf('REJECT reason=POST_TOO_LARGE content_length=%d post_max_bytes=%d', $contentLength, $postMaxBytes));
    gallery_upload_json(['status' => 'error', 'message' => 'The upload is too large for the server. The current PHP POST limit is ' . $postMaxSize . '.', 'error_code' => 'POST_TOO_LARGE'], 413);
}

$configFile = dirname(__DIR__, 2) . '/sentryiq_config.php';
if (!is_file($configFile)) gallery_upload_json(['status' => 'error', 'message' => 'SentryIQ configuration is unavailable.'], 503);
$config = require $configFile;
if (!is_array($config)) gallery_upload_json(['status' => 'error', 'message' => 'SentryIQ configuration is unavailable.'], 503);
$dataDir = rtrim((string)($config['data_dir'] ?? ''), '/');
if ($dataDir === '' || !str_starts_with($dataDir, '/') || !is_dir($dataDir) || is_link($dataDir)) gallery_upload_json(['status' => 'error', 'message' => 'SentryIQ secure runtime is unavailable.'], 503);

require_once dirname(__DIR__, 2) . '/cloud/Gallery/Image/GallerySettings.php';
require_once dirname(__DIR__, 2) . '/cloud/Gallery/Image/ImageProcessor.php';
require_once dirname(__DIR__, 2) . '/cloud/Gallery/Image/ImageDerivativeGenerator.php';
require_once dirname(__DIR__, 2) . '/cloud/Gallery/Image/ThumbnailGenerator.php';
require_once dirname(__DIR__, 2) . '/cloud/Gallery/Storage/DuplicateIndex.php';
require_once dirname(__DIR__, 2) . '/cloud/Gallery/Storage/PhotoMetadataStore.php';
require_once dirname(__DIR__, 2) . '/cloud/Gallery/Storage/PhotoNameAllocator.php';
require_once dirname(__DIR__, 2) . '/cloud/Gallery/Storage/PhotoStorage.php';
require_once dirname(__DIR__, 2) . '/cloud/Gallery/UploadService.php';

use SentryIQCloud\Gallery\Image\GallerySettings;
use SentryIQCloud\Gallery\Image\ImageProcessor;
use SentryIQCloud\Gallery\Image\ImageDerivativeGenerator;
use SentryIQCloud\Gallery\Image\ThumbnailGenerator;
use SentryIQCloud\Gallery\Storage\DuplicateIndex;
use SentryIQCloud\Gallery\Storage\PhotoMetadataStore;
use SentryIQCloud\Gallery\Storage\PhotoNameAllocator;
use SentryIQCloud\Gallery\Storage\PhotoStorage;
use SentryIQCloud\Gallery\UploadService;

$files = $_FILES['photos'] ?? ($_FILES['photo'] ?? null);

// The Gallery UI uses a raw binary XHR upload so PHP's multipart parser cannot
// discard the file before the application sees it. Build a normal upload shape
// directly from php://input when that request format is used.
$rawUploadPath = null;
if ((!is_array($files) || !isset($files['tmp_name'], $files['error']))
    && isset($_SERVER['HTTP_X_SENTRYIQ_UPLOAD'], $_SERVER['CONTENT_TYPE'])
    && strtolower((string)$_SERVER['HTTP_X_SENTRYIQ_UPLOAD']) === 'binary') {
    $rawBody = file_get_contents('php://input');
    if (is_string($rawBody) && $rawBody !== '') {
        $rawUploadPath = tempnam(sys_get_temp_dir(), 'sentryiq-upload-');
        if ($rawUploadPath !== false && @file_put_contents($rawUploadPath, $rawBody) !== false) {
            $rawName = basename(str_replace('\\\\', '/', rawurldecode((string)($_SERVER['HTTP_X_SENTRYIQ_FILENAME'] ?? 'upload'))));
            $rawType = strtolower(trim((string)($_SERVER['CONTENT_TYPE'] ?? 'application/octet-stream')));
            $files = [
                'name' => [$rawName !== '' ? $rawName : 'upload'],
                'type' => [$rawType],
                'tmp_name' => [$rawUploadPath],
                'error' => [UPLOAD_ERR_OK],
                'size' => [strlen($rawBody)],
            ];
            gallery_upload_log(sprintf('RAW_BINARY_UPLOAD name=%s size=%d type=%s', $rawName, strlen($rawBody), $rawType));
        } elseif ($rawUploadPath !== false) {
            @unlink($rawUploadPath);
            $rawUploadPath = null;
        }
    }
}

if (!is_array($files) || !isset($files['tmp_name'], $files['error'])) {
    foreach ($_FILES as $candidate) {
        if (is_array($candidate) && isset($candidate['tmp_name'], $candidate['error'])) {
            $files = $candidate;
            break;
        }
    }
}

// Some cPanel/PHP configurations can leave $_FILES empty for an XMLHttpRequest
// multipart upload even though the raw multipart body is present. Recover the
// uploaded file(s) directly from the request body in that case.
$manualUploadPaths = [];
if (!is_array($files) || !isset($files['tmp_name'], $files['error'])) {
    $contentType = (string)($_SERVER['CONTENT_TYPE'] ?? '');
    if (preg_match('/^multipart\\/form-data;\\s*boundary=(?:"([^"]+)"|([^;]+))/i', $contentType, $boundaryMatch)) {
        $boundary = $boundaryMatch[1] !== '' ? $boundaryMatch[1] : trim($boundaryMatch[2]);
        $body = file_get_contents('php://input');
        if (is_string($body) && $body !== '') {
            $parsedNames = [];
            $parsedTypes = [];
            $parsedTmp = [];
            $parsedErrors = [];
            $parsedSizes = [];
            $delimiter = '--' . $boundary;
            foreach (explode($delimiter, $body) as $part) {
                $part = ltrim($part, "\r\n");
                if ($part === '' || $part === '--' || str_starts_with($part, '--\r\n')) continue;
                $headerEnd = strpos($part, "\r\n\r\n");
                if ($headerEnd === false) continue;
                $headers = substr($part, 0, $headerEnd);
                $payload = substr($part, $headerEnd + 4);
                $payload = preg_replace("/\r\n--$/", '', $payload);
                $payload = preg_replace("/\r\n$/", '', $payload);
                if (!is_string($payload)) continue;
                if (!preg_match('/name="([^"]+)"/i', $headers, $nameMatch)) continue;
                if (!preg_match('/filename="([^"]*)"/i', $headers, $fileMatch) || $fileMatch[1] === '') continue;
                $tmpPath = tempnam(sys_get_temp_dir(), 'sentryiq-upload-');
                if ($tmpPath === false || @file_put_contents($tmpPath, $payload) === false) {
                    if ($tmpPath !== false) @unlink($tmpPath);
                    continue;
                }
                $manualUploadPaths[] = $tmpPath;
                $parsedNames[] = basename(str_replace("\\\\", '/', $fileMatch[1]));
                $parsedTypes[] = preg_match('/(?:^|\\r\\n)Content-Type:\\s*([^\\r\\n]+)/i', $headers, $typeMatch) ? trim($typeMatch[1]) : 'application/octet-stream';
                $parsedTmp[] = $tmpPath;
                $parsedErrors[] = UPLOAD_ERR_OK;
                $parsedSizes[] = strlen($payload);
            }
            if ($parsedTmp !== []) {
                $files = [
                    'name' => $parsedNames,
                    'type' => $parsedTypes,
                    'tmp_name' => $parsedTmp,
                    'error' => $parsedErrors,
                    'size' => $parsedSizes,
                ];
                gallery_upload_log('FILES fallback parsed multipart body entries=' . count($parsedTmp));
            }
        }
    }
}
if (!is_array($files) || !isset($files['tmp_name'], $files['error'])) gallery_upload_json(['status' => 'error', 'message' => 'No photos were supplied.'], 400);
// The Gallery UI uploads one file per XHR so that each photo can have its own progress.
// Accept both the original photos[] field and the single-photo field used by that flow.
if (isset($_FILES['photo']) && !isset($_FILES['photos']) && empty($manualUploadPaths)) {
    $files = [
        'name' => [(string)($_FILES['photo']['name'] ?? '')],
        'type' => [(string)($_FILES['photo']['type'] ?? '')],
        'tmp_name' => [(string)($_FILES['photo']['tmp_name'] ?? '')],
        'error' => [(int)($_FILES['photo']['error'] ?? UPLOAD_ERR_NO_FILE)],
        'size' => [(int)($_FILES['photo']['size'] ?? 0)],
    ];
}
$tmpNames = $files['tmp_name']; $errors = $files['error']; $names = $files['name'] ?? [];
if (!is_array($tmpNames)) $tmpNames = [$tmpNames];
if (!is_array($errors)) $errors = [$errors];
if (!is_array($names)) $names = [$names];

gallery_upload_log('FILES entries=' . count($errors));

try {
    $galleryRoot = $dataDir . '/gallery';
    $settings = GallerySettings::load($dataDir);
    gallery_upload_log(sprintf('SETTINGS saved_quality=%d saved_max_dimension=%d thumbnail_quality=%d thumbnail_max_dimension=%d preview_quality=%d preview_max_dimension=%d preserve_transparency=%s', (int)$settings['saved_quality'], (int)$settings['saved_max_dimension'], (int)$settings['thumbnail_quality'], (int)$settings['thumbnail_max_dimension'], (int)$settings['preview_quality'], (int)$settings['preview_max_dimension'], $settings['preserve_transparency'] ? 'true' : 'false'));

    $service = new UploadService(
        new ImageProcessor($settings['saved_quality'], $settings['preserve_transparency']),
        new ImageDerivativeGenerator($settings['saved_max_dimension'], $settings['saved_quality'], $settings['preserve_transparency']),
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
            if (is_array($sizeInfo) && isset($sizeInfo[0], $sizeInfo[1])) $dimensions = (string)(int)$sizeInfo[0] . 'x' . (string)(int)$sizeInfo[1];
        }
        gallery_upload_log(sprintf('START index=%s name=%s upload_error=%s size=%s mime=%s dimensions=%s', (string)$index, $currentUploadName !== '' ? $currentUploadName : '-', (string)$error, $currentUploadSize === null ? '-' : (string)$currentUploadSize, $mime ?? '-', $dimensions ?? '-'));
        $currentUploadStage = 'processing';
        $result = $service->upload(['name' => $currentUploadName, 'tmp_name' => $tmpPath, 'error' => $error]);
        $results[] = $result;
        $currentUploadStage = 'complete';
        gallery_upload_log(sprintf('RESULT name=%s status=%s message=%s', $currentUploadName !== '' ? $currentUploadName : '-', (string)($result['status'] ?? '-'), trim((string)($result['message'] ?? ''))));
    }
    if (function_exists('log_security_event') && function_exists('get_visitor_ip')) {
        try { log_security_event('GALLERY_UPLOAD', get_visitor_ip(), $_SESSION['app_username'] ?? 'unknown'); }
        catch (Throwable $exception) { error_log('SentryIQ Gallery audit logging failed: ' . $exception->getMessage()); }
    }
    foreach ($manualUploadPaths as $manualPath) { @unlink($manualPath); }\n    if ($rawUploadPath !== null) @unlink($rawUploadPath);
    gallery_upload_log('REQUEST_COMPLETE');
    gallery_upload_json(['status' => 'complete', 'results' => $results]);
} catch (Throwable $exception) {
    foreach ($manualUploadPaths as $manualPath) { @unlink($manualPath); }
    $exceptionClass = $exception::class;
    $exceptionMessage = trim($exception->getMessage());
    gallery_upload_log(sprintf('EXCEPTION class=%s name=%s size=%s stage=%s message=%s', $exceptionClass, $currentUploadName ?? '-', $currentUploadSize === null ? '-' : (string)$currentUploadSize, $currentUploadStage, $exceptionMessage));
    error_log('SentryIQ Gallery upload failed: ' . $exceptionClass . ': ' . $exceptionMessage);
    $safeMessage = $exceptionMessage !== '' ? $exceptionMessage : 'An unexpected upload processing error occurred.';
    gallery_upload_json(['status' => 'error', 'message' => 'Upload processing failed: ' . $safeMessage, 'error_class' => $exceptionClass], 500);
}