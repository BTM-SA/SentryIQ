<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/security_bootstrap.php';
sentryiq_security_bootstrap();
sentryiq_require_auth();
require_once dirname(__DIR__, 2) . '/cloud/Documents/DocumentStore.php';
use SentryIQCloud\Documents\DocumentStore;

$configFile = dirname(__DIR__, 2) . '/sentryiq_config.php';
$config = is_file($configFile) ? require $configFile : null;
if (!is_array($config)) { http_response_code(503); exit('SentryIQ configuration is unavailable.'); }
$dataDir = rtrim((string)($config['data_dir'] ?? ''), '/');
if ($dataDir === '' || !str_starts_with($dataDir, '/') || !is_dir($dataDir) || is_link($dataDir)) { http_response_code(503); exit('SentryIQ secure runtime is unavailable.'); }
$engine = $dataDir . '/vault_engine.php';
if (!is_file($engine) || is_link($engine)) { http_response_code(503); exit('SentryIQ secure runtime is unavailable.'); }
require_once $engine;
$masterKey = $_SESSION['master_key'] ?? null;
if (!is_string($masterKey) || strlen($masterKey) !== 32) { http_response_code(403); exit('Authentication required.'); }

$id = (string)($_GET['id'] ?? '');
if (!preg_match('/^[a-f0-9]{32}$/', $id)) { http_response_code(404); exit('Document not found.'); }
$store = new DocumentStore($dataDir . '/documents/metadata.json');
$metadata = $store->all()[$id] ?? null;
if (!is_array($metadata)) { http_response_code(404); exit('Document not found.'); }
$filename = (string)($metadata['filename'] ?? '');
$path = $dataDir . '/documents/files/' . $filename;
if ($filename === '' || !preg_match('/^[a-f0-9]{32}\.[a-z0-9]+$/', $filename) || !is_file($path) || is_link($path)) { http_response_code(404); exit('Document not found.'); }

$mime = (string)($metadata['mime'] ?? 'application/octet-stream');
$downloadName = (string)($metadata['original_name'] ?? 'Document');
$downloadName = preg_replace('/[\x00-\x1F\x7F]+/', '', $downloadName) ?: 'Document';
$disposition = in_array($mime, ['application/pdf', 'text/plain', 'text/csv'], true) ? 'inline' : 'attachment';

$encrypted = @file_get_contents($path);
if (!is_string($encrypted) || $encrypted === '') { http_response_code(404); exit('Document not found.'); }
$contents = \vault_decrypt_blob_with_key($encrypted, 'document:' . $id, $masterKey);
if ($contents === false) {
    http_response_code(404);
    exit('Document could not be decrypted.');
}

header('Content-Type: ' . $mime);
header('Content-Length: ' . (string)strlen($contents));
header('Content-Disposition: ' . $disposition . '; filename="' . addcslashes($downloadName, "\\\"") . '"');
header('Cache-Control: private, no-store');
header('X-Content-Type-Options: nosniff');
echo $contents;
exit;
