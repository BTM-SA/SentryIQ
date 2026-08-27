<?php

declare(strict_types=1);

require_once __DIR__ . '/security_bootstrap.php';
sentryiq_security_bootstrap();

$configFile = __DIR__ . '/sentryiq_config.php';
if (is_file($configFile)) {
    http_response_code(404);
    exit('Not found.');
}

function first_run_data_dir(): string
{
    return rtrim(dirname(dirname(__DIR__)), '/') . '/private_data';
}

function first_run_base_url(): string
{
    $host = trim((string)($_SERVER['SERVER_NAME'] ?? ''));
    if ($host === '' || !preg_match('/^[A-Za-z0-9.-]+$/', $host)) return '';
    if (PHP_SAPI !== 'cli' && (($_SERVER['HTTPS'] ?? '') !== 'on' && (string)($_SERVER['SERVER_PORT'] ?? '') !== '443')) return '';
    $path = rtrim(str_replace('\\', '/', dirname((string)($_SERVER['SCRIPT_NAME'] ?? ''))), '/');
    return 'https://' . $host . ($path === '/' ? '' : $path);
}

function first_run_log(string $stage, array $details = []): void
{
    $dir = first_run_data_dir();
    if (!is_dir($dir)) return;
    $record = ['timestamp' => date('c'), 'stage' => $stage, 'php_version' => PHP_VERSION, 'sapi' => PHP_SAPI];
    foreach ($details as $key => $value) if (is_scalar($value) || $value === null) $record[$key] = $value;
    $path = $dir . '/install_debug.log';
    $line = json_encode($record, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if (is_string($line)) { @file_put_contents($path, $line . PHP_EOL, FILE_APPEND | LOCK_EX); @chmod($path, 0600); }
}

function first_run_prepare_dir(string $dir): bool
{
    if ($dir === '' || !str_starts_with($dir, '/')) return false;
    if (preg_match('#/(public_html|htdocs|www)(/|$)#i', $dir)) return false;
    if (is_link($dir)) return false;
    if (!is_dir($dir) && !@mkdir($dir, 0700, true)) return false;
    @chmod($dir, 0700);
    clearstatcache(true, $dir);
    $perms = @fileperms($dir);
    if (!is_dir($dir) || $perms === false || (($perms & 0x01ff) !== 0700)) return false;
    $probe = $dir . '/.sentryiq_probe_' . bin2hex(random_bytes(8));
    if (@file_put_contents($probe, 'ok', LOCK_EX) !== 2) { @unlink($probe); return false; }
    @unlink($probe);
    return true;
}

function first_run_write_config(string $path, string $username, string $email, string $baseUrl, string $dir): bool
{
    $config = "<?php\nreturn [\n" .
        "    'installed' => true,\n" .
        "    'username' => " . var_export($username, true) . ",\n" .
        "    'two_fa_email' => " . var_export($email, true) . ",\n" .
        "    'base_url' => " . var_export($baseUrl, true) . ",\n" .
        "    'data_dir' => " . var_export($dir, true) . ",\n" .
        "    'two_fa_token_expiry' => 300,\n];\n";
    return @file_put_contents($path, $config, LOCK_EX) !== false && @chmod($path, 0600);
}

function first_run_initialize(string $password, string $dataFile): void
{
    first_run_log('VAULT_INITIALIZATION_STARTED', ['data_file' => $dataFile]);
    $salt = random_bytes(SODIUM_CRYPTO_PWHASH_SALTBYTES);
    $key = vault_derive_key($password, $salt, SENTRYIQ_KDF_OPSLIMIT, SENTRYIQ_KDF_MEMLIMIT);
    $kdf = vault_kdf_metadata($salt, SENTRYIQ_KDF_OPSLIMIT, SENTRYIQ_KDF_MEMLIMIT);
    $aad = vault_build_aad($kdf);
    $records = [[
        'id' => 'sys_config_node',
        'type' => 'system_config',
        'app_username' => (string)($_POST['setup_username'] ?? ''),
        '2fa_email' => (string)($_POST['setup_email'] ?? ''),
        'imap_password' => '',
    ]];
    $plaintext = json_encode($records, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    $nonce = random_bytes(SENTRYIQ_GCM_NONCE_BYTES);
    $tag = '';
    $ciphertext = openssl_encrypt($plaintext, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $nonce, $tag, $aad, SENTRYIQ_GCM_TAG_BYTES);
    if ($ciphertext === false || strlen($tag) !== SENTRYIQ_GCM_TAG_BYTES) throw new RuntimeException('gcm_encrypt_failed');

    $envelope = [
        'version' => SENTRYIQ_VAULT_VERSION,
        'kdf' => $kdf,
        'cipher' => ['name' => 'aes-256-gcm', 'nonce_bytes' => SENTRYIQ_GCM_NONCE_BYTES, 'tag_bytes' => SENTRYIQ_GCM_TAG_BYTES],
        'aad' => base64_encode($aad),
        'nonce' => base64_encode($nonce),
        'tag' => base64_encode($tag),
        'ciphertext' => base64_encode($ciphertext),
    ];
    $encoded = json_encode($envelope, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    $tmp = $dataFile . '.tmp-' . bin2hex(random_bytes(12));
    $handle = @fopen($tmp, 'xb');
    if ($handle === false) throw new RuntimeException('vault_temp_create_failed');
    try {
        @chmod($tmp, 0600);
        if (fwrite($handle, $encoded) !== strlen($encoded)) throw new RuntimeException('vault_write_failed');
        if (function_exists('fflush')) fflush($handle);
        if (function_exists('fsync')) @fsync($handle);
    } finally { fclose($handle); }
    @chmod($tmp, 0600);
    if (!@rename($tmp, $dataFile)) { @unlink($tmp); throw new RuntimeException('vault_rename_failed'); }
    @chmod($dataFile, 0600);
    first_run_log('VAULT_FILE_WRITE_COMPLETED', ['file_permissions' => decoct((int)(fileperms($dataFile) & 0x01ff))]);
}

function first_run_cleanup(): bool
{
    $dir = __DIR__ . '/private_data';
    if (!is_dir($dir)) return true;
    foreach (['vault_engine.php', 'email_template.php'] as $name) {
        $path = $dir . '/' . $name;
        if (is_file($path) && !@unlink($path)) return false;
    }
    $remaining = array_values(array_diff(@scandir($dir) ?: [], ['.', '..']));
    return $remaining === [] ? @rmdir($dir) : false;
}

$directory = first_run_data_dir();
$baseUrl = first_run_base_url();
$error = '';
first_run_log('FIRST_RUN_PAGE_LOADED', ['data_dir' => $directory, 'base_url_detected' => $baseUrl !== '']);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['complete_first_run'])) {
    sentryiq_require_csrf();
    $username = trim((string)($_POST['setup_username'] ?? ''));
    $email = trim((string)($_POST['setup_email'] ?? ''));
    $password = (string)($_POST['setup_password'] ?? '');
    $confirm = (string)($_POST['setup_password_confirm'] ?? '');

    if (!preg_match('/^[A-Za-z0-9._-]{2,64}$/', $username)) $error = 'Please enter a valid administrator username.';
    elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) $error = 'Please enter a valid 2FA email address.';
    elseif ($baseUrl === '') $error = 'SentryIQ could not determine its HTTPS application URL.';
    elseif (!first_run_prepare_dir($directory)) $error = 'SentryIQ could not prepare secure storage.';
    elseif (strlen($password) < 12) $error = 'The master vault password must be at least 12 characters long.';
    elseif ($password !== $confirm) $error = 'The master vault passwords do not match.';
    elseif (!is_file(__DIR__ . '/private_data/vault_engine.php') || !is_file(__DIR__ . '/private_data/email_template.php')) $error = 'SentryIQ installation files are incomplete.';
    else {
        $engineTarget = $directory . '/vault_engine.php';
        $templateTarget = $directory . '/email_template.php';
        $secureConfig = $directory . '/sentryiq_config.php';
        $pointerConfig = "<?php\nreturn [\n    'data_dir' => " . var_export($directory, true) . ",\n    'base_url' => " . var_export($baseUrl, true) . ",\n];\n";
        try {
            @unlink($engineTarget); @unlink($templateTarget);
            if (!@copy(__DIR__ . '/private_data/vault_engine.php', $engineTarget)) throw new RuntimeException('runtime_copy_failed');
            if (!@copy(__DIR__ . '/private_data/email_template.php', $templateTarget)) throw new RuntimeException('template_copy_failed');
            @chmod($engineTarget, 0600); @chmod($templateTarget, 0600);
            if (!first_run_write_config($secureConfig, $username, $email, $baseUrl, $directory)) throw new RuntimeException('secure_config_failed');

            clearstatcache(true, $engineTarget);
            if (function_exists('opcache_invalidate')) {
                @opcache_invalidate($engineTarget, true);
                first_run_log('RUNTIME_OPCACHE_INVALIDATED', ['available' => true]);
            } else {
                first_run_log('RUNTIME_OPCACHE_INVALIDATE_UNAVAILABLE', ['available' => false]);
            }

            require_once $engineTarget;
            first_run_initialize($password, $directory . '/passwords.enc');
            $verified = vault_unlock($password);
            if ($verified === false) throw new RuntimeException('vault_verification_failed');
            first_run_log('VAULT_RUNTIME_VERIFY_COMPLETED');
            if (@file_put_contents($configFile, $pointerConfig, LOCK_EX) === false || !@chmod($configFile, 0600)) throw new RuntimeException('pointer_config_failed');
            if (!first_run_cleanup()) throw new RuntimeException('first_run_cleanup_failed');
            first_run_log('INSTALL_SUCCESS');
            @unlink($directory . '/install_debug.log');
            unset($_SESSION['csrf_token']);
            @unlink(__FILE__);
            header('Location: index.php?setup=complete');
            exit;
        } catch (Throwable $exception) {
            first_run_log('INSTALL_FAILED', ['exception_class' => $exception::class, 'failure' => $exception->getMessage(), 'line' => $exception->getLine()]);
            @unlink($secureConfig);
            $error = 'SentryIQ could not initialize the encrypted vault. [' . $exception->getMessage() . ']';
        }
    }
}

$csrf = sentryiq_csrf_token();
?>
<!DOCTYPE html>
<html lang="en">
<head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"><title>SentryIQ — First Run</title><link rel="stylesheet" href="pm_style.css"></head>
<body><div class="box"><h2>🛡️ Create Your SentryIQ Vault</h2><p>Configure the administrator account before using SentryIQ.</p>
<?php if ($error !== ''): ?><p class="error"><?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></p><?php endif; ?>
<form method="POST" autocomplete="off"><input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8'); ?>">
<div class="form-group"><label>Administrator Username:</label><input type="text" name="setup_username" class="input-field" maxlength="64" required></div>
<div class="form-group"><label>2FA Email Address:</label><input type="email" name="setup_email" class="input-field" required></div>
<div class="form-group"><label>Secure Storage:</label><input type="text" class="input-field" value="<?php echo htmlspecialchars($directory, ENT_QUOTES, 'UTF-8'); ?>" readonly></div>
<div class="form-group"><label>Application HTTPS URL:</label><input type="text" class="input-field" value="<?php echo htmlspecialchars($baseUrl, ENT_QUOTES, 'UTF-8'); ?>" readonly></div>
<div class="form-group"><label>Master Vault Password:</label><input type="password" name="setup_password" class="input-field" minlength="12" required></div>
<div class="form-group"><label>Confirm Master Vault Password:</label><input type="password" name="setup_password_confirm" class="input-field" minlength="12" required></div>
<button type="submit" name="complete_first_run" class="btn btn-primary">Create Secure Vault</button></form></div></body></html>
