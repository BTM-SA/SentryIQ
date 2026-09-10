<?php

declare(strict_types=1);

require_once __DIR__ . '/security_bootstrap.php';
sentryiq_security_bootstrap();
sentryiq_require_auth();
require_once __DIR__ . '/cloud/Documents/DocumentStore.php';
use SentryIQCloud\Documents\DocumentStore;

$config = is_file(__DIR__ . '/sentryiq_config.php') ? require __DIR__ . '/sentryiq_config.php' : null;
if (!is_array($config)) { http_response_code(503); exit('SentryIQ configuration is unavailable.'); }
$dataDir = rtrim((string)($config['data_dir'] ?? ''), '/');
if ($dataDir === '' || !str_starts_with($dataDir, '/') || !is_dir($dataDir) || is_link($dataDir)) { http_response_code(503); exit('SentryIQ secure runtime is unavailable.'); }

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

header('Content-Type: ' . $mime);
header('Content-Length: ' . (string)filesize($path));
header('Content-Disposition: ' . $disposition . '; filename="' . addcslashes($downloadName, "\\\"") . '"');
header('X-Content-Type-Options: nosniff');
readfile($path);
exit;
