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

$action = (string)($_POST['action'] ?? '');
$passwords = load_passwords();
if ($passwords === false) {
    sentryiq_lock_vault();
    http_response_code(503);
    exit('Vault unavailable.');
}

if ($action === 'add') {
    $label = trim((string)($_POST['label'] ?? ''));
    $username = trim((string)($_POST['username'] ?? ''));
    $password = (string)($_POST['password'] ?? '');
    $url = vault_validate_url((string)($_POST['url'] ?? ''));
    $notes = trim((string)($_POST['notes'] ?? ''));

    if ($label === '' || $password === '' || $url === false) {
        header('Location: index.php?status=error&pane=add');
        exit;
    }

    $entryId = bin2hex(random_bytes(16));
    $icon = cache_vault_icon($url, $entryId);
    $passwords[] = [
        'id' => $entryId,
        'label' => $label,
        'username' => $username,
        'password' => $password,
        'url' => $url,
        'notes' => $notes,
        'icon_type' => $icon['icon_type'],
        'icon_path' => $icon['icon_path'],
        'icon_source' => $icon['icon_source'],
        'icon_fetched_at' => $icon['icon_fetched_at'],
        'created_at' => date('c'),
        'updated_at' => null,
    ];

    if (!save_passwords($passwords)) {
        header('Location: index.php?status=error&pane=add');
        exit;
    }

    log_security_event('VAULT_RECORD_CREATED', get_visitor_ip(), $_SESSION['app_username'] ?? 'unknown', ['entry_id'=>$entryId]);
    header('Location: index.php?status=saved&pane=view');
    exit;
}

if ($action === 'edit') {
    $entryId = trim((string)($_POST['entry_id'] ?? ''));
    $label = trim((string)($_POST['label'] ?? ''));
    $username = trim((string)($_POST['username'] ?? ''));
    $password = (string)($_POST['password'] ?? '');
    $url = vault_validate_url((string)($_POST['url'] ?? ''));
    $notes = trim((string)($_POST['notes'] ?? ''));

    if ($entryId === '' || $label === '' || $password === '' || $url === false) {
        header('Location: index.php?status=error&pane=view');
        exit;
    }

    $found = false;
    foreach ($passwords as $index => $item) {
        if (($item['id'] ?? '') !== $entryId) continue;
        $found = true;
        $oldUrl = (string)($item['url'] ?? '');
        $passwords[$index]['label'] = $label;
        $passwords[$index]['username'] = $username;
        $passwords[$index]['password'] = $password;
        $passwords[$index]['url'] = $url;
        $passwords[$index]['notes'] = $notes;
        if ($oldUrl !== $url) {
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

    if (!$found || !save_passwords($passwords)) {
        header('Location: index.php?status=error&pane=view');
        exit;
    }

    log_security_event('VAULT_RECORD_UPDATED', get_visitor_ip(), $_SESSION['app_username'] ?? 'unknown', ['entry_id'=>$entryId]);
    header('Location: index.php?status=updated&pane=view');
    exit;
}

if ($action === 'delete') {
    $entryId = trim((string)($_POST['entry_id'] ?? ''));
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

    if (!$found || !save_passwords(array_values($passwords))) {
        header('Location: index.php?status=error&pane=view');
        exit;
    }

    log_security_event('VAULT_RECORD_DELETED', get_visitor_ip(), $_SESSION['app_username'] ?? 'unknown', ['entry_id'=>$entryId]);
    header('Location: index.php?status=deleted&pane=view');
    exit;
}

if ($action === 'save_settings') {
    sentryiq_require_fresh_auth();

    $username = trim((string)($_POST['app_username'] ?? ''));
    $email = filter_var(trim((string)($_POST['two_fa_email_field'] ?? '')), FILTER_VALIDATE_EMAIL);
    $imapPassword = (string)($_POST['imap_password_field'] ?? '');
    $requestedDirectory = rtrim(trim((string)($_POST['data_directory'] ?? '')), '/');

    if (!preg_match('/^[A-Za-z0-9._-]{2,64}$/', $username) || !$email) {
        header('Location: index.php?status=error&pane=settings');
        exit;
    }

    $updated = false;
    foreach ($passwords as $index => $entry) {
        if (($entry['type'] ?? '') !== 'system_config') continue;
        $passwords[$index]['app_username'] = $username;
        $passwords[$index]['2fa_email'] = $email;
        if ($imapPassword !== '') $passwords[$index]['imap_password'] = $imapPassword;
        $updated = true;
        break;
    }
    if (!$updated) {
        $passwords[] = [
            'id'=>'sys_config_node',
            'type'=>'system_config',
            'app_username'=>$username,
            '2fa_email'=>$email,
            'imap_password'=>$imapPassword,
        ];
    }

    if (!save_passwords($passwords)) {
        header('Location: index.php?status=error&pane=settings');
        exit;
    }

    $_SESSION['app_username'] = $username;

    if ($requestedDirectory !== '' && $requestedDirectory !== SENTRYIQ_DATA_DIR) {
        if (!sentryiq_is_trusted_data_directory($requestedDirectory)) {
            if (!is_dir($requestedDirectory) && @mkdir($requestedDirectory, 0700, true)) {
                @chmod($requestedDirectory, 0700);
            }
        }

        if (sentryiq_is_trusted_data_directory($requestedDirectory)) {
            $newDir = rtrim($requestedDirectory, '/');
            $ok = true;
            if (!@copy(DATA_FILE, $newDir . '/passwords.enc')) $ok = false;
            if (is_file(LOG_FILE) && !@copy(LOG_FILE, $newDir . '/security_audit.log')) $ok = false;
            @chmod($newDir . '/passwords.enc', 0600);
            if (is_file($newDir . '/security_audit.log')) @chmod($newDir . '/security_audit.log', 0600);

            foreach (['vault_engine.php','email_template.php'] as $runtimeFile) {
                if (!@copy(SENTRYIQ_DATA_DIR . '/' . $runtimeFile, $newDir . '/' . $runtimeFile)) $ok = false;
                @chmod($newDir . '/' . $runtimeFile, 0600);
            }

            $oldIconDir = SENTRYIQ_DATA_DIR . '/vault_icons';
            $newIconDir = $newDir . '/vault_icons';
            if (is_dir($oldIconDir)) {
                if (!is_dir($newIconDir) && !@mkdir($newIconDir, 0700, true)) $ok = false;
                foreach (@scandir($oldIconDir) ?: [] as $file) {
                    if ($file === '.' || $file === '..') continue;
                    $source = $oldIconDir . '/' . $file;
                    $target = $newIconDir . '/' . $file;
                    if (is_file($source) && !@copy($source, $target)) $ok = false;
                    if (is_file($target)) @chmod($target, 0600);
                }
            } else {
                @mkdir($newIconDir, 0700, true);
            }

            $privateConfig = "<?php\nreturn [\n    'installed' => true,\n    'username' => " . var_export($username, true) . ",\n    'two_fa_email' => " . var_export((string)$email, true) . ",\n    'base_url' => " . var_export((string)($config['base_url'] ?? ''), true) . ",\n    'data_dir' => " . var_export($newDir, true) . ",\n    'two_fa_token_expiry' => 300,\n];\n";
            if (!$ok || @file_put_contents($newDir . '/sentryiq_config.php', $privateConfig, LOCK_EX) === false) $ok = false;
            @chmod($newDir . '/sentryiq_config.php', 0600);

            if ($ok) {
                $pointer = "<?php\nreturn [\n    'data_dir' => " . var_export($newDir, true) . ",\n    'base_url' => " . var_export((string)($config['base_url'] ?? ''), true) . ",\n];\n";
                $tmp = __DIR__ . '/sentryiq_config.php.tmp-' . bin2hex(random_bytes(8));
                if (@file_put_contents($tmp, $pointer, LOCK_EX) === false || !@chmod($tmp, 0600) || !@rename($tmp, $configFile)) $ok = false;
            }

            if ($ok) log_security_event('DATA_DIRECTORY_CHANGED', get_visitor_ip(), $username, ['from'=>SENTRYIQ_DATA_DIR,'to'=>$newDir]);
        }
    }

    header('Location: index.php?status=saved&pane=settings');
    exit;
}

http_response_code(400);
exit('Invalid vault action.');
