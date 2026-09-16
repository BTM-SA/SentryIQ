<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/security_bootstrap.php';
sentryiq_security_bootstrap();
sentryiq_require_auth();

$configFile = dirname(__DIR__, 2) . '/sentryiq_config.php';
$config = is_file($configFile) ? require $configFile : [];
$dataDir = is_array($config) ? rtrim((string)($config['data_dir'] ?? ''), '/') : '';
if ($dataDir === '' || !is_file($dataDir . '/vault_engine.php')) {
    http_response_code(503);
    exit('SentryIQ secure runtime is unavailable.');
}
require_once $dataDir . '/vault_engine.php';

$passwords = load_passwords();
if ($passwords === false) {
    http_response_code(503);
    exit('Vault unavailable.');
}

$systemConfig = [];
foreach ($passwords as $entry) {
    if (($entry['type'] ?? '') === 'system_config') {
        $systemConfig = $entry;
        break;
    }
}

$recordsLabel = trim((string)($systemConfig['records_label'] ?? 'Records'));
$recordsDeleted = $recordsLabel === '';
if ($recordsLabel === '') $recordsLabel = 'Uncategorized';

$folderMap = [];
foreach ($passwords as $entry) {
    if (($entry['type'] ?? '') === 'system_config') continue;
    $id = trim((string)($entry['id'] ?? ''));
    if ($id === '') continue;
    $folderMap[$id] = [
        'category' => trim((string)($entry['category'] ?? '')),
        'folder' => trim((string)($entry['folder'] ?? '')),
    ];
}

header('Content-Type: application/json; charset=utf-8');
echo json_encode([
    'status' => 'ok',
    'records_label' => $recordsLabel,
    'records_deleted' => $recordsDeleted,
    'records' => $folderMap,
], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
