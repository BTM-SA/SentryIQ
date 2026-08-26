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

/** Convert every historical/intermediate record shape into canonical associative form. */
function sentryiq_normalize_action_records($records): array {
    if (!is_array($records)) return [];
    $isList = array_keys($records) === range(0, count($records) - 1);
    if ($isList && count($records) >= 5 && count($records) <= 6 && !is_array($records[0])) $records = [$records];
    $out = [];
    foreach ($records as $item) {
        if (is_string($item)) {
            $parts = str_getcsv($item);
            if (count($parts) >= 5) $item = $parts;
        }
        if (!is_array($item)) continue;
        $assoc = array_keys($item) !== range(0, count($item) - 1);
        if ($assoc && (($item['type'] ?? '') === 'system_config')) {
            $out[] = $item;
            continue;
        }
        if (!$assoc && count($item) >= 5) {
            $out[] = [
                'label' => (string)($item[0] ?? ''),
                'username' => (string)($item[1] ?? ''),
                'password' => (string)($item[2] ?? ''),
                'url' => (string)($item[3] ?? ''),
                'notes' => (string)($item[4] ?? ''),
                'id' => (string)($item[5] ?? bin2hex(random_bytes(8))),
            ];
            continue;
        }
        if ($assoc && isset($item['label'])) {
            $label = (string)$item['label'];
            $parts = str_getcsv($label);
            if (count($parts) >= 5) {
                $hasSeparateFields = false;
                foreach (['username','password','url','notes'] as $field) {
                    if (isset($item[$field]) && (string)$item[$field] !== '') {
                        $hasSeparateFields = true;
                        break;
                    }
                }
                if (!$hasSeparateFields) {
                    $out[] = [
                        'label' => (string)($parts[0] ?? ''),
                        'username' => (string)($parts[1] ?? ''),
                        'password' => (string)($parts[2] ?? ''),
                        'url' => (string)($parts[3] ?? ''),
                        'notes' => (string)($parts[4] ?? ''),
                        'id' => (string)($parts[5] ?? ($item['id'] ?? bin2hex(random_bytes(8)))),
                    ];
                    continue;
                }
            }
            if (empty($item['id'])) $item['id'] = bin2hex(random_bytes(8));
            $out[] = $item;
        }
    }
    return $out;
}

$action = $_POST['action'] ?? '';
$entryId = trim((string)($_POST['entry_id'] ?? ''));
$passwords = sentryiq_normalize_action_records(load_passwords());

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
            if ($url !== $oldUrl) {
                if (!empty($item['icon_path']) && is_file($item['icon_path'])) @unlink($item['icon_path']);
                $icon = cache_vault_icon($url, $entryId);
                $passwords[$index]['icon_type'] = $icon['icon_type'];
                $passwords[$index]['icon_path'] = $icon['icon_path'];
                $passwords[$index]['icon_source'] = $icon['icon_source'];
                $passwords[$index]['icon_fetched_at'] = $icon['icon_fetched_at'];
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
