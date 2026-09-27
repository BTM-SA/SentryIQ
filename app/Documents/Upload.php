<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/security_bootstrap.php';
sentryiq_security_bootstrap();
sentryiq_require_auth();
sentryiq_require_csrf();

$configFile = dirname(__DIR__, 2) . '/sentryiq_config.php';
$config = is_file($configFile) ? require $configFile : null;
if (!is_array($config)) { http_response_code(503); exit('SentryIQ configuration is unavailable.'); }
$dataDir = rtrim((string)($config['data_dir'] ?? ''), '/');
if ($dataDir === '' || !str_starts_with($dataDir, '/') || !is_dir($dataDir) || is_link($dataDir)) { http_response_code(503); exit('SentryIQ secure runtime is unavailable.'); }
$engine = $dataDir . '/vault_engine.php';
if (!is_file($engine) || is_link($engine)) { http_response_code(503); exit('SentryIQ secure runtime is unavailable.'); }
require_once $engine;
if (!function_exists('vault_encrypt_blob_with_key')) { http_response_code(503); exit('SentryIQ secure vault engine is out of date. Replace private_data/vault_engine.php with the current version.'); }
$masterKey = $_SESSION['master_key'] ?? null;
if (!is_string($masterKey) || strlen($masterKey) !== 32) { http_response_code(403); exit('Authentication required.'); }

require_once dirname(__DIR__, 2) . '/cloud/Documents/DocumentStore.php';
use SentryIQCloud\Documents\DocumentStore;

$root = $dataDir . '/documents';
$filesDir = $root . '/files';
if (!is_dir($filesDir) && !mkdir($filesDir, 0700, true) && !is_dir($filesDir)) { http_response_code(503); exit('Document storage is unavailable.'); }
$store = new DocumentStore($root . '/metadata.json');

$allowed = [
    'application/pdf' => 'pdf',
    'text/plain' => 'txt',
    'text/csv' => 'csv',
    'application/rtf' => 'rtf',
    'application/msword' => 'doc',
    'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
    'application/vnd.ms-excel' => 'xls',
    'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => 'xlsx',
    'application/vnd.ms-powerpoint' => 'ppt',
    'application/vnd.openxmlformats-officedocument.presentationml.presentation' => 'pptx',
    'application/vnd.oasis.opendocument.text' => 'odt',
    'application/vnd.oasis.opendocument.spreadsheet' => 'ods',
    'application/vnd.oasis.opendocument.presentation' => 'odp',
];

$uploaded = $_FILES['documents'] ?? null;
if (!is_array($uploaded) || !isset($uploaded['name'], $uploaded['tmp_name'], $uploaded['error'], $uploaded['size'])) { header('Location: documents.php?status=error'); exit; }
$names = is_array($uploaded['name']) ? $uploaded['name'] : [$uploaded['name']];
$tmpNames = is_array($uploaded['tmp_name']) ? $uploaded['tmp_name'] : [$uploaded['tmp_name']];
$errors = is_array($uploaded['error']) ? $uploaded['error'] : [$uploaded['error']];
$sizes = is_array($uploaded['size']) ? $uploaded['size'] : [$uploaded['size']];
$finfo = new finfo(FILEINFO_MIME_TYPE);
$success = 0;
$failed = 0;

foreach ($names as $i => $originalName) {
    $error = (int)($errors[$i] ?? UPLOAD_ERR_NO_FILE);
    $tmp = (string)($tmpNames[$i] ?? '');
    $size = (int)($sizes[$i] ?? 0);
    if ($error !== UPLOAD_ERR_OK || $tmp === '' || !is_uploaded_file($tmp) || $size <= 0 || $size > 100 * 1024 * 1024) { $failed++; continue; }
    $mime = (string)($finfo->file($tmp) ?: '');
    if (!isset($allowed[$mime])) { $failed++; continue; }
    $extension = $allowed[$mime];
    $id = bin2hex(random_bytes(16));
    $path = $filesDir . '/' . $id . '.' . $extension;
    $raw = @file_get_contents($tmp);
    if (!is_string($raw) || strlen($raw) !== $size) { $failed++; continue; }
    try {
        $encrypted = \vault_encrypt_blob_with_key($raw, 'document:' . $id, $masterKey);
        $temporary = $path . '.tmp-' . bin2hex(random_bytes(8));
        if (@file_put_contents($temporary, $encrypted, LOCK_EX) === false || !@rename($temporary, $path)) {
            @unlink($temporary);
            throw new RuntimeException('Unable to store encrypted document.');
        }
        @chmod($path, 0600);

        $safeName = trim(basename((string)$originalName));
        if ($safeName === '') $safeName = 'Document.' . $extension;
        $store->put($id, [
            'original_name' => $safeName,
            'filename' => $id . '.' . $extension,
            'mime' => $mime,
            'extension' => $extension,
            'size' => $size,
            'created_at' => time(),
        ]);
        $success++;
    } catch (Throwable $e) {
        @unlink($path);
        $failed++;
        $logPath = $dataDir . '/documents_upload.log';
        @file_put_contents($logPath, '[' . date('c') . '] name=' . basename((string)$originalName) . ' class=' . $e::class . ' message=' . trim($e->getMessage()) . PHP_EOL, FILE_APPEND | LOCK_EX);
        @chmod($logPath, 0600);
    }
}

$status = $success > 0 ? ($failed > 0 ? 'partial' : 'uploaded') : 'error';
header('Location: documents.php?status=' . rawurlencode($status) . '&count=' . $success);
exit;
