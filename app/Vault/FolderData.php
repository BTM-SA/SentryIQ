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
    sentryiq_lock_vault();
    http_response_code(503);
    exit('Vault unavailable.');
}

$folders = [];
$categories = [];
$records = [];
foreach ($passwords as $entry) {
    if (($entry['type'] ?? '') === 'system_config') {
        if (is_array($entry['categories'] ?? null)) {
            foreach ($entry['categories'] as $category) {
                $category = trim((string)$category);
                if ($category !== '') $categories[] = $category;
            }
            $categories = array_values(array_unique($categories));
        }
        if (is_array($entry['folders'] ?? null)) {
            foreach ($entry['folders'] as $category => $folderList) {
                if (!is_string($category) || !is_array($folderList)) continue;
                $clean = [];
                foreach ($folderList as $folder) {
                    $folder = trim((string)$folder);
                    if ($folder !== '') $clean[] = $folder;
                }
                if ($clean !== []) $folders[$category] = array_values(array_unique($clean));
            }
        }
        continue;
    }
    $id = trim((string)($entry['id'] ?? ''));
    if ($id === '') continue;
    $records[$id] = [
        'category' => trim((string)($entry['category'] ?? '')),
        'folder' => trim((string)($entry['folder'] ?? '')),
    ];
}

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: private, no-store, max-age=0');
echo json_encode(['categories' => $categories, 'folders' => $folders, 'records' => $records], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
