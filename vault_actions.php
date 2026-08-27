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

function vault_action_diagnostic(string $stage, array $details = []): void
{
    $dataFile = defined('LOG_FILE') ? LOG_FILE : '';
    $directory = defined('SENTRYIQ_DATA_DIR') ? SENTRYIQ_DATA_DIR : '';
    if ($directory === '' || $dataFile === '' || !is_dir($directory)) return;
    $record = ['timestamp'=>date('c'),'stage'=>$stage,'php_version'=>PHP_VERSION,'sapi'=>PHP_SAPI];
    foreach ($details as $key => $value) if (is_scalar($value) || $value === null) $record[$key] = $value;
    $line = json_encode($record, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if (is_string($line)) { @file_put_contents($dataFile, $line . PHP_EOL, FILE_APPEND | LOCK_EX); @chmod($dataFile, 0600); }
}

function save_passwords(array $records): bool
{
    $masterKey = $_SESSION['master_key'] ?? null;
    if (!is_string($masterKey) || strlen($masterKey) !== 32) {
        vault_action_diagnostic('VAULT_SAVE_FAILURE', ['reason'=>'master_key_unavailable','record_count'=>count($records)]);
        return false;
    }

    try {
        vault_action_diagnostic('VAULT_SAVE_STARTED', ['record_count'=>count($records),'data_file'=>DATA_FILE]);
        $parts = vault_read_envelope();
        vault_action_diagnostic('VAULT_SAVE_ENVELOPE_READ_OK', ['record_count'=>count($records)]);
        $saved = vault_write_encrypted_records($records, $masterKey, $parts['kdf']);
        if (!$saved) {
            vault_action_diagnostic('VAULT_SAVE_FAILURE', ['reason'=>'vault_write_encrypted_records_returned_false','record_count'=>count($records)]);
            return false;
        }
        vault_action_diagnostic('VAULT_SAVE_COMPLETED', ['record_count'=>count($records)]);
        return true;
    } catch (Throwable $exception) {
        vault_action_diagnostic('VAULT_SAVE_FAILURE', ['reason'=>'exception','exception_class'=>$exception::class,'exception_message'=>$exception->getMessage(),'line'=>$exception->getLine(),'record_count'=>count($records)]);
        error_log('SentryIQ vault save failure: ' . $exception::class . ': ' . $exception->getMessage());
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
    $passwords[] = ['id'=>$entryId,'label'=>$label,'username'=>$username,'password'=>$password,'url'=>$url,'notes'=>$notes,'icon_type'=>$icon['icon_type'],'icon_path'=>$icon['icon_path'],'icon_source'=>$icon['icon_source'],'icon_fetched_at'=>$icon['icon_fetched_at'],'created_at'=>date('c'),'updated_at'=>null];

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
    if ($entryId === '' || $label === '' || $password === '' || $url === false) { header('Location: index.php?status=error&pane=view'); exit; }

    $found = false;
    foreach ($passwords as $index => $item) {
        if (($item['id'] ?? '') !== $entryId) continue;
        $found = true;
        $oldUrl = (string)($item['url'] ?? '');
        $passwords[$index]['label']=$label; $passwords[$index]['username']=$username; $passwords[$index]['password']=$password; $passwords[$index]['url']=$url; $passwords[$index]['notes']=$notes;
        if ($oldUrl !== $url) {
            $oldIcon = (string)($item['icon_path'] ?? '');
            if ($oldIcon !== '' && is_file($oldIcon) && str_starts_with($oldIcon, SENTRYIQ_DATA_DIR . '/vault_icons/')) @unlink($oldIcon);
            $icon = cache_vault_icon($url, $entryId);
            $passwords[$index]['icon_type']=$icon['icon_type']; $passwords[$index]['icon_path']=$icon['icon_path']; $passwords[$index]['icon_source']=$icon['icon_source']; $passwords[$index]['icon_fetched_at']=$icon['icon_fetched_at'];
        }
        $passwords[$index]['updated_at']=date('c');
        break;
    }
    if (!$found || !save_passwords($passwords)) { header('Location: index.php?status=error&pane=view'); exit; }
    log_security_event('VAULT_RECORD_UPDATED', get_visitor_ip(), $_SESSION['app_username'] ?? 'unknown', ['entry_id'=>$entryId]);
    header('Location: index.php?status=updated&pane=view');
    exit;
}

if ($action === 'delete') {
    $entryId = trim((string)($_POST['entry_id'] ?? ''));
    if ($entryId === '') { header('Location: index.php?status=error&pane=view'); exit; }
    $found = false;
    foreach ($passwords as $index => $item) {
        if (($item['id'] ?? '') !== $entryId) continue;
        $found = true;
        $icon = (string)($item['icon_path'] ?? '');
        if ($icon !== '' && is_file($icon) && str_starts_with($icon, SENTRYIQ_DATA_DIR . '/vault_icons/')) @unlink($icon);
        unset($passwords[$index]);
        break;
    }
    if (!$found || !save_passwords(array_values($passwords))) { header('Location: index.php?status=error&pane=view'); exit; }
    log_security_event('VAULT_RECORD_DELETED', get_visitor_ip(), $_SESSION['app_username'] ?? 'unknown', ['entry_id'=>$entryId]);
    header('Location: index.php?status=deleted&pane=view');
    exit;
}

if ($action === 'save_settings') {
    sentryiq_require_fresh_auth();
    $username = trim((string)($_POST['app_username'] ?? ''));
    $email = filter_var(trim((string)($_POST['two_fa_email_field'] ?? '')), FILTER_VALIDATE_EMAIL);
    $imapPassword = (string)($_POST['imap_password_field'] ?? '');
    $requestedBaseUrl = rtrim(trim((string)($_POST['base_url_field'] ?? '')), '/');
    $requestedDirectory = rtrim(trim((string)($_POST['data_directory'] ?? '')), '/');
    $baseParts = parse_url($requestedBaseUrl);
    if (!preg_match('/^[A-Za-z0-9._-]{2,64}$/', $username) || !$email) { header('Location: index.php?status=error&pane=settings'); exit; }
    if (!filter_var($requestedBaseUrl, FILTER_VALIDATE_URL) || !is_array($baseParts) || strtolower((string)($baseParts['scheme'] ?? '')) !== 'https' || empty($baseParts['host']) || isset($baseParts['user']) || isset($baseParts['pass'])) { header('Location: index.php?status=error&pane=settings'); exit; }
    $updated = false;
    foreach ($passwords as $index => $entry) {
        if (($entry['type'] ?? '') !== 'system_config') continue;
        $passwords[$index]['app_username']=$username; $passwords[$index]['2fa_email']=$email; if ($imapPassword !== '') $passwords[$index]['imap_password']=$imapPassword; $updated=true; break;
    }
    if (!$updated) $passwords[]=['id'=>'sys_config_node','type'=>'system_config','app_username'=>$username,'2fa_email'=>$email,'imap_password'=>$imapPassword];
    if (!save_passwords($passwords)) { header('Location: index.php?status=error&pane=settings'); exit; }
    $_SESSION['app_username']=$username;
    $effectiveDirectory=SENTRYIQ_DATA_DIR; $movedDirectory=false;
    if ($requestedDirectory !== '' && $requestedDirectory !== SENTRYIQ_DATA_DIR) {
        try { $trustedRequestedDirectory=sentryiq_is_trusted_data_directory($requestedDirectory); } catch (Throwable $exception) { $trustedRequestedDirectory=false; }
        if (!$trustedRequestedDirectory && !is_dir($requestedDirectory) && @mkdir($requestedDirectory,0700,true)) @chmod($requestedDirectory,0700);
        try { $trustedRequestedDirectory=sentryiq_is_trusted_data_directory($requestedDirectory); } catch (Throwable $exception) { $trustedRequestedDirectory=false; }
        if ($trustedRequestedDirectory) {
            $newDir=rtrim($requestedDirectory,'/'); $ok=true;
            if (!@copy(DATA_FILE,$newDir.'/passwords.enc')) $ok=false;
            if (is_file(LOG_FILE) && !@copy(LOG_FILE,$newDir.'/security_audit.log')) $ok=false;
            @chmod($newDir.'/passwords.enc',0600); if (is_file($newDir.'/security_audit.log')) @chmod($newDir.'/security_audit.log',0600);
            foreach (['vault_engine.php','email_template.php','vault_icon_cache.php'] as $runtimeFile) { if (!@copy(SENTRYIQ_DATA_DIR.'/'.$runtimeFile,$newDir.'/'.$runtimeFile)) $ok=false; @chmod($newDir.'/'.$runtimeFile,0600); }
            $oldIconDir=SENTRYIQ_DATA_DIR.'/vault_icons'; $newIconDir=$newDir.'/vault_icons';
            if (is_dir($oldIconDir)) { if (!is_dir($newIconDir) && !@mkdir($newIconDir,0700,true)) $ok=false; foreach (@scandir($oldIconDir) ?: [] as $file) { if ($file==='.'||$file==='..') continue; $source=$oldIconDir.'/'.$file; $target=$newIconDir.'/'.$file; if (is_file($source)&&!@copy($source,$target)) $ok=false; if (is_file($target)) @chmod($target,0600); } } else { @mkdir($newIconDir,0700,true); }
            if ($ok) { $effectiveDirectory=$newDir; $movedDirectory=true; }
        }
    }
    $privateConfig="<?php\nreturn [\n    'installed' => true,\n    'username' => ".var_export($username,true).",\n    'two_fa_email' => ".var_export((string)$email,true).",\n    'base_url' => ".var_export($requestedBaseUrl,true).",\n    'data_dir' => ".var_export($effectiveDirectory,true).",\n    'two_fa_token_expiry' => 300,\n];\n";
    if (@file_put_contents($effectiveDirectory.'/sentryiq_config.php',$privateConfig,LOCK_EX)===false) { header('Location: index.php?status=error&pane=settings'); exit; }
    @chmod($effectiveDirectory.'/sentryiq_config.php',0600);
    $pointer="<?php\nreturn [\n    'data_dir' => ".var_export($effectiveDirectory,true).",\n    'base_url' => ".var_export($requestedBaseUrl,true).",\n];\n";
    $tmp=__DIR__.'/sentryiq_config.php.tmp-'.bin2hex(random_bytes(8));
    if (@file_put_contents($tmp,$pointer,LOCK_EX)===false || !@chmod($tmp,0600) || !@rename($tmp,$configFile)) { @unlink($tmp); header('Location: index.php?status=error&pane=settings'); exit; }
    if ($movedDirectory) log_security_event('DATA_DIRECTORY_CHANGED',get_visitor_ip(),$username,['from'=>SENTRYIQ_DATA_DIR,'to'=>$effectiveDirectory]);
    log_security_event('SYSTEM_SETTINGS_CHANGED',get_visitor_ip(),$username,['base_url'=>$requestedBaseUrl]);
    header('Location: index.php?status=saved&pane=settings'); exit;
}

http_response_code(400);
exit('Invalid vault action.');
