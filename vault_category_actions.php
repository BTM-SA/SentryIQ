<?php

declare(strict_types=1);

require_once __DIR__ . '/security_bootstrap.php';
sentryiq_security_bootstrap();
sentryiq_require_auth();
sentryiq_require_csrf();

$configFile = __DIR__ . '/sentryiq_config.php';
$config = is_file($configFile) ? require $configFile : [];
$dataDir = is_array($config) ? rtrim((string)($config['data_dir'] ?? ''), '/') : '';
if ($dataDir === '' || !is_file($dataDir . '/vault_engine.php')) {
    http_response_code(503);
    exit('SentryIQ secure runtime is unavailable.');
}
require_once $dataDir . '/vault_engine.php';

function save_category_vault(array $records): bool
{
    $masterKey = $_SESSION['master_key'] ?? null;
    if (!is_string($masterKey) || strlen($masterKey) !== 32) return false;
    try {
        $parts = vault_read_envelope();
        return vault_write_encrypted_records($records, $masterKey, $parts['kdf']);
    } catch (Throwable $exception) {
        error_log('SentryIQ category save failure: ' . $exception::class . ': ' . $exception->getMessage());
        return false;
    }
}

function vault_name_key(string $value): string
{
    return function_exists('mb_strtolower') ? mb_strtolower($value, 'UTF-8') : strtolower($value);
}

function valid_vault_name(string $value): bool
{
    return $value !== '' && strlen($value) <= 50 && (bool)preg_match('/^[\p{L}\p{N}][\p{L}\p{N} ._&()\-]{0,49}$/u', $value);
}

function redirect_category(string $status, string $category = ''): never
{
    $url = 'index.php?status=' . rawurlencode($status) . '&pane=' . ($category === '' ? 'view' : 'records&vault_view=' . rawurlencode($category));
    header('Location: ' . $url);
    exit;
}

$action = (string)($_POST['action'] ?? '');
$passwords = load_passwords();
if ($passwords === false) {
    sentryiq_lock_vault();
    http_response_code(503);
    exit('Vault unavailable.');
}

if ($action === 'create_folder') {
    $category = trim((string)($_POST['category'] ?? ''));
    $folder = trim((string)($_POST['folder'] ?? ''));
    if (!valid_vault_name($category) || !valid_vault_name($folder)) redirect_category('error', $category);

    $categoryKey = vault_name_key($category);
    $folderKey = vault_name_key($folder);
    foreach ($passwords as $index => $entry) {
        if (($entry['type'] ?? '') !== 'system_config') continue;
        $categories = is_array($entry['categories'] ?? null) ? $entry['categories'] : [];
        $categoryExists = false;
        foreach ($categories as $existingCategory) if (vault_name_key(trim((string)$existingCategory)) === $categoryKey) { $categoryExists = true; break; }
        if (!$categoryExists) redirect_category('error', $category);

        $folders = is_array($entry['folders'] ?? null) ? $entry['folders'] : [];
        $storedCategory = $category;
        foreach (array_keys($folders) as $existingCategory) if (vault_name_key(trim((string)$existingCategory)) === $categoryKey) { $storedCategory = (string)$existingCategory; break; }
        $existingFolders = is_array($folders[$storedCategory] ?? null) ? array_values(array_filter(array_map('strval', $folders[$storedCategory]), static fn(string $v): bool => trim($v) !== '')) : [];
        foreach ($existingFolders as $existingFolder) if (vault_name_key(trim($existingFolder)) === $folderKey) redirect_category('error', $category);
        $existingFolders[] = $folder;
        natcasesort($existingFolders);
        $folders[$storedCategory] = array_values($existingFolders);
        $passwords[$index]['folders'] = $folders;
        if (!save_category_vault($passwords)) redirect_category('error', $category);
        log_security_event('VAULT_FOLDER_CREATED', get_visitor_ip(), $_SESSION['app_username'] ?? 'unknown', ['category'=>$category,'folder'=>$folder]);
        redirect_category('folder_added', $category);
    }
    redirect_category('error', $category);
}

if ($action === 'rename_folder') {
    $category = trim((string)($_POST['category'] ?? ''));
    $folder = trim((string)($_POST['folder'] ?? ''));
    $newFolder = trim((string)($_POST['new_folder'] ?? ''));
    if (!valid_vault_name($category) || !valid_vault_name($folder) || !valid_vault_name($newFolder)) redirect_category('error', $category);
    $categoryKey = vault_name_key($category);
    $folderKey = vault_name_key($folder);
    $newFolderKey = vault_name_key($newFolder);
    foreach ($passwords as $index => $entry) {
        if (($entry['type'] ?? '') !== 'system_config') continue;
        $folders = is_array($entry['folders'] ?? null) ? $entry['folders'] : [];
        $storedCategory = null;
        foreach (array_keys($folders) as $existingCategory) if (vault_name_key(trim((string)$existingCategory)) === $categoryKey) { $storedCategory = (string)$existingCategory; break; }
        if ($storedCategory === null) redirect_category('error', $category);
        $existingFolders = is_array($folders[$storedCategory] ?? null) ? array_values(array_map('strval', $folders[$storedCategory])) : [];
        $found = false;
        foreach ($existingFolders as $existingFolder) {
            if (vault_name_key(trim($existingFolder)) === $newFolderKey && vault_name_key(trim($existingFolder)) !== $folderKey) redirect_category('error', $category);
        }
        foreach ($existingFolders as $folderIndex => $existingFolder) {
            if (vault_name_key(trim($existingFolder)) !== $folderKey) continue;
            $existingFolders[$folderIndex] = $newFolder;
            $found = true;
            break;
        }
        if (!$found) redirect_category('error', $category);
        natcasesort($existingFolders);
        $folders[$storedCategory] = array_values($existingFolders);
        $passwords[$index]['folders'] = $folders;
        foreach ($passwords as $recordIndex => $record) {
            if (($record['type'] ?? '') === 'system_config') continue;
            if (vault_name_key(trim((string)($record['category'] ?? ''))) === vault_name_key($storedCategory) && vault_name_key(trim((string)($record['folder'] ?? ''))) === $folderKey) {
                $passwords[$recordIndex]['folder'] = $newFolder;
            }
        }
        if (!save_category_vault($passwords)) redirect_category('error', $category);
        log_security_event('VAULT_FOLDER_RENAMED', get_visitor_ip(), $_SESSION['app_username'] ?? 'unknown', ['category'=>$category,'folder'=>$folder,'new_folder'=>$newFolder]);
        redirect_category('folder_renamed', $category);
    }
    redirect_category('error', $category);
}

if ($action === 'delete_folder') {
    $category = trim((string)($_POST['category'] ?? ''));
    $folder = trim((string)($_POST['folder'] ?? ''));
    if (!valid_vault_name($category) || !valid_vault_name($folder)) redirect_category('error', $category);
    $categoryKey = vault_name_key($category);
    $folderKey = vault_name_key($folder);
    foreach ($passwords as $index => $entry) {
        if (($entry['type'] ?? '') !== 'system_config') continue;
        $folders = is_array($entry['folders'] ?? null) ? $entry['folders'] : [];
        $storedCategory = null;
        foreach (array_keys($folders) as $existingCategory) if (vault_name_key(trim((string)$existingCategory)) === $categoryKey) { $storedCategory = (string)$existingCategory; break; }
        if ($storedCategory === null) redirect_category('error', $category);
        $existingFolders = is_array($folders[$storedCategory] ?? null) ? array_values(array_map('strval', $folders[$storedCategory])) : [];
        $remaining = [];
        $found = false;
        foreach ($existingFolders as $existingFolder) {
            if (vault_name_key(trim($existingFolder)) === $folderKey) { $found = true; continue; }
            $remaining[] = $existingFolder;
        }
        if (!$found) redirect_category('error', $category);
        if ($remaining === []) unset($folders[$storedCategory]); else $folders[$storedCategory] = array_values($remaining);
        $passwords[$index]['folders'] = $folders;
        // Preserve records: deleting a folder moves its records to the category level.
        foreach ($passwords as $recordIndex => $record) {
            if (($record['type'] ?? '') === 'system_config') continue;
            if (vault_name_key(trim((string)($record['category'] ?? ''))) === vault_name_key($storedCategory) && vault_name_key(trim((string)($record['folder'] ?? ''))) === $folderKey) $passwords[$recordIndex]['folder'] = '';
        }
        if (!save_category_vault($passwords)) redirect_category('error', $category);
        log_security_event('VAULT_FOLDER_DELETED', get_visitor_ip(), $_SESSION['app_username'] ?? 'unknown', ['category'=>$category,'folder'=>$folder]);
        redirect_category('folder_deleted', $category);
    }
    redirect_category('error', $category);
}

if ($action === 'rename_category') {
    $category = trim((string)($_POST['category'] ?? ''));
    $newCategory = trim((string)($_POST['new_category'] ?? ''));
    if (!valid_vault_name($category) || !valid_vault_name($newCategory)) redirect_category('error');
    $categoryKey = vault_name_key($category);
    $newCategoryKey = vault_name_key($newCategory);
    $found = false;
    foreach ($passwords as $index => $entry) {
        if (($entry['type'] ?? '') !== 'system_config') continue;
        $categories = is_array($entry['categories'] ?? null) ? array_values(array_map('strval', $entry['categories'])) : [];
        foreach ($categories as $existingCategory) {
            if (vault_name_key(trim($existingCategory)) === $newCategoryKey && vault_name_key(trim($existingCategory)) !== $categoryKey) redirect_category('error');
        }
        foreach ($categories as $categoryIndex => $existingCategory) if (vault_name_key(trim($existingCategory)) === $categoryKey) { $categories[$categoryIndex] = $newCategory; $found = true; break; }
        if (!$found) redirect_category('error');
        natcasesort($categories);
        $passwords[$index]['categories'] = array_values($categories);
        $folders = is_array($entry['folders'] ?? null) ? $entry['folders'] : [];
        foreach (array_keys($folders) as $folderCategory) if (vault_name_key(trim((string)$folderCategory)) === $categoryKey) { $folders[$newCategory] = $folders[$folderCategory]; unset($folders[$folderCategory]); break; }
        $passwords[$index]['folders'] = $folders;
        break;
    }
    if (!$found) redirect_category('error');
    foreach ($passwords as $recordIndex => $record) {
        if (($record['type'] ?? '') === 'system_config') continue;
        if (vault_name_key(trim((string)($record['category'] ?? ''))) === $categoryKey) $passwords[$recordIndex]['category'] = $newCategory;
    }
    if (!save_category_vault($passwords)) redirect_category('error');
    log_security_event('VAULT_CATEGORY_RENAMED', get_visitor_ip(), $_SESSION['app_username'] ?? 'unknown', ['category'=>$category,'new_category'=>$newCategory]);
    redirect_category('category_renamed');
}

if ($action === 'delete_category') {
    $category = trim((string)($_POST['category'] ?? ''));
    if (!valid_vault_name($category)) redirect_category('error');
    $categoryKey = vault_name_key($category);
    $found = false;
    foreach ($passwords as $index => $entry) {
        if (($entry['type'] ?? '') !== 'system_config') continue;
        $categories = is_array($entry['categories'] ?? null) ? array_values(array_map('strval', $entry['categories'])) : [];
        $remaining = [];
        foreach ($categories as $existingCategory) {
            if (vault_name_key(trim($existingCategory)) === $categoryKey) { $found = true; continue; }
            $remaining[] = $existingCategory;
        }
        if (!$found) redirect_category('error');
        $passwords[$index]['categories'] = array_values($remaining);
        $folders = is_array($entry['folders'] ?? null) ? $entry['folders'] : [];
        foreach (array_keys($folders) as $folderCategory) if (vault_name_key(trim((string)$folderCategory)) === $categoryKey) unset($folders[$folderCategory]);
        $passwords[$index]['folders'] = $folders;
        break;
    }
    if (!$found) redirect_category('error');
    // Preserve credentials: deleting a category makes its records uncategorised.
    foreach ($passwords as $recordIndex => $record) {
        if (($record['type'] ?? '') === 'system_config') continue;
        if (vault_name_key(trim((string)($record['category'] ?? ''))) === $categoryKey) {
            $passwords[$recordIndex]['category'] = '';
            $passwords[$recordIndex]['folder'] = '';
        }
    }
    if (!save_category_vault($passwords)) redirect_category('error');
    log_security_event('VAULT_CATEGORY_DELETED', get_visitor_ip(), $_SESSION['app_username'] ?? 'unknown', ['category'=>$category]);
    redirect_category('category_deleted');
}

if ($action !== 'add_category') redirect_category('view');

$category = trim((string)($_POST['category'] ?? ''));
if (!valid_vault_name($category)) redirect_category('error');
$categoryKey = vault_name_key($category);
$foundConfig = false;
foreach ($passwords as $index => $entry) {
    if (($entry['type'] ?? '') !== 'system_config') continue;
    $foundConfig = true;
    $existing = is_array($entry['categories'] ?? null) ? $entry['categories'] : [];
    $categories = array_values(array_unique(array_filter(array_map(static fn($value): string => trim((string)$value), $existing), static fn(string $value): bool => $value !== '')));
    foreach ($categories as $existingCategory) if (vault_name_key($existingCategory) === $categoryKey) redirect_category('error');
    $categories[] = $category;
    natcasesort($categories);
    $passwords[$index]['categories'] = array_values($categories);
    break;
}
if (!$foundConfig) $passwords[] = ['id'=>'sys_config_node','type'=>'system_config','categories'=>[$category]];
if (!save_category_vault($passwords)) redirect_category('error');
log_security_event('VAULT_CATEGORY_CREATED', get_visitor_ip(), $_SESSION['app_username'] ?? 'unknown', ['category'=>$category]);
redirect_category('category_added');
