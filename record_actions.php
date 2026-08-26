<?php
/** SentryIQ - Vault record action endpoint */
ini_set('session.cookie_secure', '1');
ini_set('session.cookie_httponly', '1');
ini_set('session.cookie_samesite', 'Lax');
session_start();
date_default_timezone_set('Africa/Johannesburg');

$pointerConfigFile = __DIR__ . '/sentryiq_config.php';
if (!is_file($pointerConfigFile)) {
    header('Location: index.php?status=error&pane=view');
    exit;
}
$pointerConfig = require $pointerConfigFile;
$dataDir = is_array($pointerConfig) ? rtrim((string)($pointerConfig['data_dir'] ?? ''), '/') : '';
if ($dataDir === '' || !is_file($dataDir . '/vault_engine.php')) {
    header('Location: index.php?status=error&pane=view');
    exit;
}
require_once $dataDir . '/vault_engine.php';

if (!isset($_SESSION['master_key'])) {
    header('Location: index.php');
    exit;
}

$action = $_POST['action'] ?? '';
$entryId = trim((string)($_POST['entry_id'] ?? ''));
$passwords = normalize_vault_records(load_passwords());

if (!is_array($passwords) || $entryId === '') {
    header('Location: index.php?status=error&pane=view');
    exit;
}

if ($action === 'edit') {
    $label = trim((string)($_POST['label'] ?? ''));
    $username = trim((string)($_POST['username'] ?? ''));
    $password = trim((string)($_POST['password'] ?? ''));
    $url = trim((string)($_POST['url'] ?? ''));
    $notes = trim((string)($_POST['notes'] ?? ''));
    $found = false;

    if ($label !== '' && $password !== '') {
        foreach ($passwords as $index => $item) {
            if (($item['id'] ?? '') !== $entryId) continue;
            $found = true;
            $oldUrl = trim((string)($item['url'] ?? ''));
            $passwords[$index]['label'] = $label;
            $passwords[$index]['username'] = $username;
            $passwords[$index]['password'] = $password;
            $passwords[$index]['url'] = $url;
            $passwords[$index]['notes'] = $notes;
            if ($url !== $oldUrl && function_exists('cache_vault_icon')) {
                if (!empty($item['icon_path']) && is_file($item['icon_path'])) @unlink($item['icon_path']);
                $icon = cache_vault_icon($url, $entryId);
                $passwords[$index]['icon_type'] = $icon['icon_type'] ?? null;
                $passwords[$index]['icon_path'] = $icon['icon_path'] ?? null;
                $passwords[$index]['icon_source'] = $icon['icon_source'] ?? null;
                $passwords[$index]['icon_fetched_at'] = $icon['icon_fetched_at'] ?? null;
            }
            $passwords[$index]['updated_at'] = date('Y-m-d H:i:s');
            break;
        }
    }
    if ($found && save_passwords($passwords)) {
        log_security_event('VAULT_RECORD_UPDATED', get_visitor_ip(), $_SESSION['app_username'] ?? 'unknown', ['entry_id' => $entryId]);
        header('Location: index.php?status=updated&pane=view');
        exit;
    }
    header('Location: index.php?status=error&pane=view');
    exit;
}

if ($action === 'delete') {
    $found = false;
    foreach ($passwords as $index => $item) {
        if (($item['id'] ?? '') !== $entryId) continue;
        $found = true;
        if (!empty($item['icon_path']) && is_file($item['icon_path'])) @unlink($item['icon_path']);
        unset($passwords[$index]);
        break;
    }
    if ($found && save_passwords(array_values($passwords))) {
        log_security_event('VAULT_RECORD_DELETED', get_visitor_ip(), $_SESSION['app_username'] ?? 'unknown', ['entry_id' => $entryId]);
        header('Location: index.php?status=deleted&pane=view');
        exit;
    }
    header('Location: index.php?status=error&pane=view');
    exit;
}
header('Location: index.php?status=error&pane=view');
exit;
