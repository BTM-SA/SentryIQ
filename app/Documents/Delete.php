<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/security_bootstrap.php';
sentryiq_security_bootstrap();
sentryiq_require_auth();
sentryiq_require_csrf();
require_once dirname(__DIR__, 2) . '/cloud/Documents/DocumentStore.php';
use SentryIQCloud\Documents\DocumentStore;

$configFile = dirname(__DIR__, 2) . '/sentryiq_config.php';
$config = is_file($configFile) ? require $configFile : null;
if (!is_array($config)) { http_response_code(503); exit('SentryIQ configuration is unavailable.'); }
$dataDir = rtrim((string)($config['data_dir'] ?? ''), '/');
if ($dataDir === '' || !str_starts_with($dataDir, '/') || !is_dir($dataDir) || is_link($dataDir)) { http_response_code(503); exit('SentryIQ secure runtime is unavailable.'); }

$id = (string)($_POST['id'] ?? '');
if (!preg_match('/^[a-f0-9]{32}$/', $id)) { header('Location: documents.php?status=error'); exit; }
$store = new DocumentStore($dataDir . '/documents/metadata.json');
$metadata = $store->all()[$id] ?? null;
if (!is_array($metadata)) { header('Location: documents.php?status=error'); exit; }
$filename = (string)($metadata['filename'] ?? '');
if (!preg_match('/^[a-f0-9]{32}\.[a-z0-9]+$/', $filename)) { header('Location: documents.php?status=error'); exit; }
$path = $dataDir . '/documents/files/' . $filename;
if (is_file($path) && !is_link($path)) @unlink($path);
$store->remove($id);
header('Location: documents.php?status=deleted');
exit;
