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
    if (!is_string($masterKey) || strlen($masterKey) !== 32) {
        return false;
    }

    try {
        $parts = vault_read_envelope();
        return vault_write_encrypted_records($records, $masterKey, $parts['kdf']);
    } catch (Throwable $exception) {
        error_log('SentryIQ category save failure: ' . $exception::class . ': ' . $exception->getMessage());
        return false;
    }
}

$action = (string)($_POST['action'] ?? '');

if ($action === 'create_folder') {
    $category = trim((string)($_POST['category'] ?? ''));
    $folder = trim((string)($_POST['folder'] ?? ''));

    if (
        $category === '' ||
        strlen($category) > 50 ||
        !preg_match('/^[\p{L}\p{N}][\p{L}\p{N} ._&()\-]{0,49}$/u', $category) ||
        $folder === '' ||
        strlen($folder) > 50 ||
        !preg_match('/^[\p{L}\p{N}][\p{L}\p{N} ._&()\-]{0,49}$/u', $folder)
    ) {
        header('Location: index.php?status=error&pane=records&vault_view=' . rawurlencode($category));
        exit;
    }

    $passwords = load_passwords();
    if ($passwords === false) {
        sentryiq_lock_vault();
        http_response_code(503);
        exit('Vault unavailable.');
    }

    $categoryKey = function_exists('mb_strtolower') ? mb_strtolower($category, 'UTF-8') : strtolower($category);
    $folderKey = function_exists('mb_strtolower') ? mb_strtolower($folder, 'UTF-8') : strtolower($folder);
    $foundConfig = false;
    $categoryExists = false;

    foreach ($passwords as $index => $entry) {
        if (($entry['type'] ?? '') !== 'system_config') {
            continue;
        }

        $foundConfig = true;
        $categories = is_array($entry['categories'] ?? null) ? $entry['categories'] : [];
        foreach ($categories as $existingCategory) {
            $existingCategoryKey = function_exists('mb_strtolower')
                ? mb_strtolower(trim((string)$existingCategory), 'UTF-8')
                : strtolower(trim((string)$existingCategory));
            if ($existingCategoryKey === $categoryKey) {
                $categoryExists = true;
                break;
            }
        }

        if (!$categoryExists) {
            header('Location: index.php?status=error&pane=records&vault_view=' . rawurlencode($category));
            exit;
        }

        $folders = is_array($entry['folders'] ?? null) ? $entry['folders'] : [];
        $categoryFolders = [];
        foreach ($folders as $folderCategory => $folderList) {
            if (is_string($folderCategory)) {
                $categoryFolders[$folderCategory] = is_array($folderList) ? array_values(array_filter(array_map(static fn($value): string => trim((string)$value), $folderList), static fn(string $value): bool => $value !== '')) : [];
            }
        }

        $storedCategory = $category;
        foreach (array_keys($categoryFolders) as $existingCategory) {
            $existingCategoryKey = function_exists('mb_strtolower')
                ? mb_strtolower(trim((string)$existingCategory), 'UTF-8')
                : strtolower(trim((string)$existingCategory));
            if ($existingCategoryKey === $categoryKey) {
                $storedCategory = $existingCategory;
                break;
            }
        }

        $existingFolders = $categoryFolders[$storedCategory] ?? [];
        foreach ($existingFolders as $existingFolder) {
            $existingFolderKey = function_exists('mb_strtolower')
                ? mb_strtolower($existingFolder, 'UTF-8')
                : strtolower($existingFolder);
            if ($existingFolderKey === $folderKey) {
                header('Location: index.php?status=error&pane=records&vault_view=' . rawurlencode($category));
                exit;
            }
        }

        $existingFolders[] = $folder;
        natcasesort($existingFolders);
        $categoryFolders[$storedCategory] = array_values($existingFolders);
        $passwords[$index]['folders'] = $categoryFolders;
        break;
    }

    if (!$foundConfig) {
        header('Location: index.php?status=error&pane=records&vault_view=' . rawurlencode($category));
        exit;
    }

    if (!save_category_vault($passwords)) {
        header('Location: index.php?status=error&pane=records&vault_view=' . rawurlencode($category));
        exit;
    }

    log_security_event(
        'VAULT_FOLDER_CREATED',
        get_visitor_ip(),
        $_SESSION['app_username'] ?? 'unknown',
        ['category' => $category, 'folder' => $folder]
    );

    header('Location: index.php?status=folder_added&pane=records&vault_view=' . rawurlencode($category));
    exit;
}

if ($action !== 'add_category') {
    header('Location: index.php?pane=view');
    exit;
}

$category = trim((string)($_POST['category'] ?? ''));
if (
    $category === '' ||
    strlen($category) > 50 ||
    !preg_match('/^[\p{L}\p{N}][\p{L}\p{N} ._&()\-]{0,49}$/u', $category)
) {
    header('Location: index.php?status=error&pane=view');
    exit;
}

$passwords = load_passwords();
if ($passwords === false) {
    sentryiq_lock_vault();
    http_response_code(503);
    exit('Vault unavailable.');
}

$categoryKey = function_exists('mb_strtolower')
    ? mb_strtolower($category, 'UTF-8')
    : strtolower($category);

$foundConfig = false;
foreach ($passwords as $index => $entry) {
    if (($entry['type'] ?? '') !== 'system_config') {
        continue;
    }

    $foundConfig = true;
    $existing = is_array($entry['categories'] ?? null) ? $entry['categories'] : [];
    $categories = array_values(array_unique(array_filter(
        array_map(static fn($value): string => trim((string)$value), $existing),
        static fn(string $value): bool => $value !== ''
    )));

    foreach ($categories as $existingCategory) {
        $existingKey = function_exists('mb_strtolower')
            ? mb_strtolower($existingCategory, 'UTF-8')
            : strtolower($existingCategory);

        if ($existingKey === $categoryKey) {
            header('Location: index.php?status=error&pane=view');
            exit;
        }
    }

    $categories[] = $category;
    natcasesort($categories);
    $passwords[$index]['categories'] = array_values($categories);
    break;
}

if (!$foundConfig) {
    $passwords[] = [
        'id' => 'sys_config_node',
        'type' => 'system_config',
        'categories' => [$category],
    ];
}

if (!save_category_vault($passwords)) {
    header('Location: index.php?status=error&pane=view');
    exit;
}

log_security_event(
    'VAULT_CATEGORY_CREATED',
    get_visitor_ip(),
    $_SESSION['app_username'] ?? 'unknown',
    ['category' => $category]
);

header('Location: index.php?status=category_added&pane=view');
exit;
