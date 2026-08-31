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

function record_action_diagnostic(string $stage, array $details = []): void
{
    $directory = defined('SENTRYIQ_DATA_DIR') ? SENTRYIQ_DATA_DIR : '';
    $logFile = defined('SENTRYIQ_DIAGNOSTIC_LOG') ? SENTRYIQ_DIAGNOSTIC_LOG : '';
    if ($directory === '' || $logFile === '' || !is_dir($directory)) return;

    $record = [
        'timestamp' => date('c'),
        'stage' => $stage,
        'php_version' => PHP_VERSION,
        'sapi' => PHP_SAPI,
    ];
    foreach ($details as $key => $value) {
        if (is_scalar($value) || $value === null) $record[$key] = $value;
    }

    $line = json_encode($record, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if (is_string($line)) {
        @file_put_contents($logFile, $line . PHP_EOL, FILE_APPEND | LOCK_EX);
        @chmod($logFile, 0600);
    }
}

function record_action_save_passwords(array $records): bool
{
    $masterKey = $_SESSION['master_key'] ?? null;
    if (!is_string($masterKey) || strlen($masterKey) !== 32) {
        record_action_diagnostic('RECORD_SAVE_FAILURE', ['reason' => 'master_key_unavailable', 'record_count' => count($records)]);
        return false;
    }
    try {
        record_action_diagnostic('RECORD_SAVE_STARTED', ['record_count' => count($records), 'data_file' => DATA_FILE]);
        $parts = vault_read_envelope();
        record_action_diagnostic('RECORD_SAVE_ENVELOPE_READ_OK', ['record_count' => count($records)]);
        $saved = vault_write_encrypted_records($records, $masterKey, $parts['kdf']);
        if (!$saved) {
            record_action_diagnostic('RECORD_SAVE_FAILURE', ['reason' => 'vault_write_returned_false', 'record_count' => count($records)]);
            return false;
        }
        record_action_diagnostic('RECORD_SAVE_COMPLETED', ['record_count' => count($records)]);
        return true;
    } catch (Throwable $exception) {
        record_action_diagnostic('RECORD_SAVE_FAILURE', [
            'reason' => 'exception',
            'exception_class' => $exception::class,
            'exception_message' => $exception->getMessage(),
            'line' => $exception->getLine(),
            'record_count' => count($records),
        ]);
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
    $rawUrl = trim((string)($_POST['url'] ?? ''));
    $notes = trim((string)($_POST['notes'] ?? ''));
    $found = false;

    record_action_diagnostic('RECORD_EDIT_STARTED', [
        'entry_id_present' => $entryId !== '',
        'label_present' => $label !== '',
        'username_present' => $username !== '',
        'password_present' => $password !== '',
        'url_present' => $rawUrl !== '',
        'notes_present' => $notes !== '',
        'record_count_before' => count($passwords),
    ]);

    if ($entryId !== '' && $label !== '' && $password !== '') {
        foreach ($passwords as $index => $item) {
            if (($item['id'] ?? '') !== $entryId) continue;
            $found = true;
            $oldUrl = (string)($item['url'] ?? '');
            $newUrl = $rawUrl === '' ? $oldUrl : vault_validate_url($rawUrl);
            if ($newUrl === false) {
                record_action_diagnostic('RECORD_EDIT_FAILURE', ['reason' => 'invalid_url', 'index' => $index]);
                header('Location: index.php?status=error&pane=view');
                exit;
            }

            $passwords[$index]['label'] = $label;
            $passwords[$index]['username'] = $username;
            $passwords[$index]['password'] = $password;
            $passwords[$index]['url'] = $newUrl;
            $passwords[$index]['notes'] = $notes;
            record_action_diagnostic('RECORD_EDIT_MATCHED', [
                'index' => $index,
                'url_changed' => $newUrl !== $oldUrl,
            ]);

            if ($newUrl !== $oldUrl) {
                $oldIcon = (string)($item['icon_path'] ?? '');
                if ($oldIcon !== '' && is_file($oldIcon) && str_starts_with($oldIcon, SENTRYIQ_DATA_DIR . '/vault_icons/')) @unlink($oldIcon);
                $icon = cache_vault_icon($newUrl, $entryId);
                $passwords[$index]['icon_type'] = $icon['icon_type'];
                $passwords[$index]['icon_path'] = $icon['icon_path'];
                $passwords[$index]['icon_source'] = $icon['icon_source'];
                $passwords[$index]['icon_fetched_at'] = $icon['icon_fetched_at'];
            }
            $passwords[$index]['updated_at'] = date('c');
            break;
        }
    }

    if (!$found) {
        record_action_diagnostic('RECORD_EDIT_FAILURE', ['reason' => 'record_not_found']);
        header('Location: index.php?status=error&pane=view');
        exit;
    }

    $saveOk = record_action_save_passwords($passwords);
    if (!$saveOk) {
        record_action_diagnostic('RECORD_EDIT_FAILURE', ['reason' => 'save_failed']);
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
