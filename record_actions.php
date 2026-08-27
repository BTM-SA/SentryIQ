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
require_once $dataDir . '/vault_icon_cache.php';

function record_action_save_passwords(array $records): bool
{
    $masterKey = $_SESSION['master_key'] ?? null;
    if (!is_string($masterKey) || strlen($masterKey) !== 32) return false;
    try {
        $parts = vault_read_envelope();
        return vault_write_encrypted_records($records, $masterKey, $parts['kdf']);
    } catch (Throwable $exception) {
        error_log('SentryIQ record save failure: ' . $exception::class . ': ' . $exception->getMessage());
        return false;
    }
}

$action = (string)($_POST['action'] ?? '');
$passwords = load_passwords();
if ($passwords === false) {
    sentryiq_lock_vault();
    http_response_code(503);
    exit('Vault unavailable.');
}

$entryId = trim((string)($_POST['entry_id'] ?? ''));

if ($action === 'edit') {
    $label = trim((string)($_POST['label'] ?? ''));
    $username = trim((string)($_POST['username'] ?? ''));
    $password = (string)($_POST['password'] ?? '');
    $url = vault_validate_url((string)($_POST['url'] ?? ''));
    $notes = trim((string)($_POST['notes'] ?? ''));
    $found = false;

    if ($entryId !== '' && $label !== '' && $password !== '' && $url !== false) {
        foreach ($passwords as $index => $item) {
            if (($item['id'] ?? '') !== $entryId) continue;
            $found = true;
            $oldUrl = (string)($item['url'] ?? '');
            $passwords[$index]['label'] = $label;
            $passwords[$index]['username'] = $username;
            $passwords[$index]['password'] = $password;
            $passwords[$index]['url'] = $url;
            $passwords[$index]['notes'] = $notes;
            if ($url !== $oldUrl) {
                $oldIcon = (string)($item['icon_path'] ?? '');
                if ($oldIcon !== '' && is_file($oldIcon) && str_starts_with($oldIcon, SENTRYIQ_DATA_DIR . '/vault_icons/')) @unlink($oldIcon);
                $icon = cache_vault_icon($url, $entryId);
                $passwords[$index]['icon_type'] = $icon['icon_type'];
                $passwords[$index]['icon_path'] = $icon['icon_path'];
                $passwords[$index]['icon_source'] = $icon['icon_source'];
                $passwords[$index]['icon_fetched_at'] = $icon['icon_fetched_at'];
            }
            $passwords[$index]['updated_at'] = date('c');
            break;
        }
    }

    if (!$found || !record_action_save_passwords($passwords)) {
        header('Location: index.php?status=error&pane=view');
        exit;
    }

    log_security_event('VAULT_RECORD_UPDATED', get_visitor_ip(), $_SESSION['app_username'] ?? 'unknown', ['entry_id'=>$entryId]);
    header('Location: index.php?status=updated&pane=view');
    exit;
}

if ($action === 'delete') {
    if ($entryId === '') {
        header('Location: index.php?status=error&pane=view');
        exit;
    }

    $found = false;
    foreach ($passwords as $index => $item) {
        if (($item['id'] ?? '') !== $entryId) continue;
        $found = true;
        $icon = (string)($item['icon_path'] ?? '');
        if ($icon !== '' && is_file($icon) && str_starts_with($icon, SENTRYIQ_DATA_DIR . '/vault_icons/')) @unlink($icon);
        unset($passwords[$index]);
        break;
    }

    if (!$found || !record_action_save_passwords(array_values($passwords))) {
        header('Location: index.php?status=error&pane=view');
        exit;
    }

    log_security_event('VAULT_RECORD_DELETED', get_visitor_ip(), $_SESSION['app_username'] ?? 'unknown', ['entry_id'=>$entryId]);
    header('Location: index.php?status=deleted&pane=view');
    exit;
}

http_response_code(400);
exit('Invalid record action.');
