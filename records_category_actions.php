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

function records_category_save(array $records): bool
{
    $masterKey = $_SESSION['master_key'] ?? null;
    if (!is_string($masterKey) || strlen($masterKey) !== 32) return false;
    try {
        $parts = vault_read_envelope();
        return vault_write_encrypted_records($records, $masterKey, $parts['kdf']);
    } catch (Throwable $exception) {
        error_log('SentryIQ Records category save failure: ' . $exception::class . ': ' . $exception->getMessage());
        return false;
    }
}

function records_category_valid_name(string $value): bool
{
    return $value !== '' && strlen($value) <= 50 && (bool)preg_match('/^[\p{L}\p{N}][\p{L}\p{N} ._&()\-]{0,49}$/u', $value);
}

function records_category_key(string $value): string
{
    return function_exists('mb_strtolower') ? mb_strtolower(trim($value), 'UTF-8') : strtolower(trim($value));
}

function records_category_redirect(string $status, string $pane = 'view'): never
{
    header('Location: index.php?status=' . rawurlencode($status) . '&pane=' . rawurlencode($pane) . ($pane === 'records' ? '&vault_view=records' : ''));
    exit;
}

$action = (string)($_POST['action'] ?? '');
$passwords = load_passwords();
if ($passwords === false) {
    sentryiq_lock_vault();
    http_response_code(503);
    exit('Vault unavailable.');
}

$configIndex = null;
foreach ($passwords as $index => $entry) {
    if (($entry['type'] ?? '') === 'system_config') {
        $configIndex = $index;
        break;
    }
}

if ($configIndex === null) {
    $passwords[] = ['id' => 'sys_config_node', 'type' => 'system_config', 'records_label' => 'Records'];
    $configIndex = array_key_last($passwords);
}

$currentLabel = trim((string)($passwords[$configIndex]['records_label'] ?? 'Records'));
if ($currentLabel === '') $currentLabel = 'Records';

if ($action === 'rename_records') {
    $newLabel = trim((string)($_POST['new_label'] ?? ''));
    if (!records_category_valid_name($newLabel)) records_category_redirect('error', 'records');

    $newKey = records_category_key($newLabel);
    foreach ($passwords as $entry) {
        if (($entry['type'] ?? '') !== 'system_config' || !is_array($entry['categories'] ?? null)) continue;
        foreach ($entry['categories'] as $category) {
            if (records_category_key((string)$category) === $newKey) records_category_redirect('error', 'records');
        }
    }

    $passwords[$configIndex]['records_label'] = $newLabel;
    if (!records_category_save($passwords)) records_category_redirect('error', 'records');
    log_security_event('VAULT_RECORDS_CATEGORY_RENAMED', get_visitor_ip(), $_SESSION['app_username'] ?? 'unknown', ['category'=>$currentLabel,'new_category'=>$newLabel]);
    records_category_redirect('records_renamed', 'records');
}

if ($action === 'delete_records') {
    $passwords[$configIndex]['records_label'] = '';
    if (!records_category_save($passwords)) records_category_redirect('error', 'records');
    log_security_event('VAULT_RECORDS_CATEGORY_DELETED', get_visitor_ip(), $_SESSION['app_username'] ?? 'unknown', ['category'=>$currentLabel]);
    records_category_redirect('records_deleted', 'view');
}

http_response_code(400);
exit('Invalid Records category action.');
