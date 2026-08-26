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
    $script = str_replace('\\', '/', (string)($_SERVER['SCRIPT_NAME'] ?? ''));
    $path = rtrim(str_replace('\\', '/', dirname($script)), '/');
    return 'https://' . $host . ($path === '/' ? '' : $path);
}

function first_run_diag(string $stage, array $details = []): void
{
    $path = first_run_data_dir() . '/install_debug.log';
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
    if (!is_string($line)) return;
    if (@file_put_contents($path, $line . PHP_EOL, FILE_APPEND | LOCK_EX) !== false) {
        @chmod($path, 0600);
    }
}

function first_run_prepare_dir(string $dir): bool
{
    if ($dir === '' || !str_starts_with($dir, '/')) return false;
    if (preg_match('#/(public_html|htdocs|www)(/|$)#i', $dir)) return false;
    if (is_link($dir)) return false;
    if (!is_dir($dir) && !@mkdir($dir, 0700, true)) return false;
    @chmod($dir, 0700);
    $perms = @fileperms($dir);
    if (!is_dir($dir) || $perms === false || (($perms & 0x01ff) !== 0700)) return false;
    $probe = $dir . '/.sentryiq_probe_' . bin2hex(random_bytes(8));
    if (@file_put_contents($probe, 'ok', LOCK_EX) !== 2) return false;
    @unlink($probe);
    return true;
}

function first_run_write_secure_config(string $path, string $username, string $email, string $baseUrl, string $dir): bool
{
    $config = "<?php\nreturn [\n" .
        "    'installed' => true,\n" .
        "    'username' => " . var_export($username, true) . ",\n" .
        "    'two_fa_email' => " . var_export($email, true) . ",\n" .
        "    'base_url' => " . var_export($baseUrl, true) . ",\n" .
        "    'data_dir' => " . var_export($dir, true) . ",\n" .
        "    'two_fa_token_expiry' => 300,\n" .
        "];\n";
    return @file_put_contents($path, $config, LOCK_EX) !== false && @chmod($path, 0600);
}

function first_run_initialize_vault(string $password, string $dataFile): bool
{
    first_run_diag('VAULT_INITIALIZATION_STARTED', ['data_file' => $dataFile]);

    if (!function_exists('sodium_crypto_pwhash') || !defined('SODIUM_CRYPTO_PWHASH_SALTBYTES')) {
        first_run_diag('KDF_DEPENDENCY_FAILED', ['reason' => 'sodium_missing']);
        throw new RuntimeException('sodium_missing');
    }
    if (!function_exists('openssl_encrypt') || !in_array('aes-256-gcm', openssl_get_cipher_methods(), true)) {
        first_run_diag('GCM_DEPENDENCY_FAILED', ['reason' => 'aes_256_gcm_unavailable']);
        throw new RuntimeException('aes_256_gcm_unavailable');
    }

    $salt = random_bytes(SODIUM_CRYPTO_PWHASH_SALTBYTES);
    $opslimit = 3;
    $memlimit = 32 * 1024 * 1024;
    first_run_diag('KDF_STARTED', ['opslimit' => $opslimit, 'memlimit' => $memlimit]);
    $key = sodium_crypto_pwhash(32, $password, $salt, $opslimit, $memlimit, SODIUM_CRYPTO_PWHASH_ALG_ARGON2ID13);
    if (!is_string($key) || strlen($key) !== 32) {
        first_run_diag('KDF_FAILED', ['result_length' => is_string($key) ? strlen($key) : -1]);
        throw new RuntimeException('kdf_failed');
    }
    first_run_diag('KDF_COMPLETED');

    $kdf = [
        'name' => 'argon2id13',
        'opslimit' => $opslimit,
        'memlimit' => $memlimit,
        'salt' => base64_encode($salt),
    ];

    $aad = (string)json_encode([
        'version' => 2,
        'kdf' => $kdf,
        'cipher' => [
            'name' => 'aes-256-gcm',
            'nonce_bytes' => 12,
            'tag_bytes' => 16,
        ],
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

    $records = [[
        'id' => 'sys_config_node',
        'type' => 'system_config',
        'app_username' => $_POST['setup_username'] ?? '',
        '2fa_email' => $_POST['setup_email'] ?? '',
        'imap_password' => '',
    ]];
    $plaintext = json_encode($records, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    $nonce = random_bytes(12);
    $tag = '';
    first_run_diag('GCM_ENCRYPT_STARTED');
    $ciphertext = openssl_encrypt($plaintext, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $nonce, $tag, $aad, 16);
    if ($ciphertext === false || strlen($tag) !== 16) {
        $lastError = error_get_last();
        first_run_diag('GCM_ENCRYPT_FAILED', ['php_error' => is_array($lastError) ? (string)($lastError['message'] ?? '') : '']);
        throw new RuntimeException('gcm_encrypt_failed');
    }
    first_run_diag('GCM_ENCRYPT_COMPLETED', ['ciphertext_length' => strlen($ciphertext), 'tag_length' => strlen($tag)]);

    $envelope = [
        'version' => 2,
        'kdf' => $kdf,
        'cipher' => [
            'name' => 'aes-256-gcm',
            'nonce_bytes' => 12,
            'tag_bytes' => 16,
        ],
        'aad' => base64_encode($aad),
        'nonce' => base64_encode($nonce),
        'tag' => base64_encode($tag),
        'ciphertext' => base64_encode($ciphertext),
    ];

    $encoded = json_encode($envelope, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    $tmp = $dataFile . '.tmp-' . bin2hex(random_bytes(12));
    first_run_diag('VAULT_FILE_WRITE_STARTED');
    $handle = @fopen($tmp, 'xb');
    if ($handle === false) {
        $lastError = error_get_last();
        first_run_diag('VAULT_FILE_WRITE_FAILED', ['reason' => 'temp_create_failed', 'php_error' => is_array($lastError) ? (string)($lastError['message'] ?? '') : '']);
        throw new RuntimeException('vault_temp_create_failed');
    }
    try {
        @chmod($tmp, 0600);
        if (fwrite($handle, $encoded) !== strlen($encoded)) throw new RuntimeException('vault_write_failed');
        if (function_exists('fflush')) fflush($handle);
        if (function_exists('fsync')) @fsync($handle);
    } finally {
        fclose($handle);
    }
    @chmod($tmp, 0600);
    if (!@rename($tmp, $dataFile)) {
        @unlink($tmp);
        first_run_diag('VAULT_FILE_WRITE_FAILED', ['reason' => 'rename_failed']);
        throw new RuntimeException('vault_rename_failed');
    }
    @chmod($dataFile, 0600);
    first_run_diag('VAULT_FILE_WRITE_COMPLETED', [
        'file_exists' => is_file($dataFile),
        'file_size' => is_file($dataFile) ? filesize($dataFile) : -1,
        'file_permissions' => is_file($dataFile) ? decoct((int)(fileperms($dataFile) & 0x01ff)) : 'unknown',
    ]);
    return true;
}

function first_run_verify_written_envelope(string $dataFile): void
{
    first_run_diag('VAULT_ENVELOPE_VERIFY_STARTED');
    $raw = @file_get_contents($dataFile);
    if (!is_string($raw) || $raw === '') throw new RuntimeException('vault_file_read_failed');
    try {
        $envelope = json_decode($raw, true, 16, JSON_THROW_ON_ERROR);
    } catch (Throwable $exception) {
        first_run_diag('VAULT_ENVELOPE_VERIFY_FAILED', ['reason' => 'json_decode_failed', 'exception' => $exception::class]);
        throw new RuntimeException('vault_envelope_json_failed');
    }
    if (!is_array($envelope)) throw new RuntimeException('vault_envelope_invalid');
    foreach (['version', 'kdf', 'cipher', 'aad', 'nonce', 'tag', 'ciphertext'] as $field) {
        if (!array_key_exists($field, $envelope)) throw new RuntimeException('vault_envelope_missing_' . $field);
    }
    first_run_diag('VAULT_ENVELOPE_VERIFY_COMPLETED', [
        'version' => $envelope['version'],
        'kdf_name' => (string)($envelope['kdf']['name'] ?? ''),
        'kdf_opslimit' => (int)($envelope['kdf']['opslimit'] ?? 0),
        'kdf_memlimit' => (int)($envelope['kdf']['memlimit'] ?? 0),
        'cipher_name' => (string)($envelope['cipher']['name'] ?? ''),
        'ciphertext_b64_length' => strlen((string)$envelope['ciphertext']),
        'tag_b64_length' => strlen((string)$envelope['tag']),
        'nonce_b64_length' => strlen((string)$envelope['nonce']),
    ]);
}

function first_run_remove_sources(): bool
{
    $dir = __DIR__ . '/private_data';
    if (!is_dir($dir)) return true;
    foreach (['vault_engine.php', 'email_template.php'] as $name) {
        $path = $dir . '/' . $name;
        if (is_file($path) && !@unlink($path)) return false;
    }
    $remaining = array_values(array_diff(@scandir($dir) ?: [], ['.', '..']));
    return count($remaining) === 0 ? @rmdir($dir) : false;
}

$directory = first_run_data_dir();
$baseUrl = first_run_base_url();
$error = '';
first_run_diag('FIRST_RUN_PAGE_LOADED', ['data_dir' => $directory, 'base_url_detected' => $baseUrl !== '']);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['complete_first_run'])) {
    sentryiq_require_csrf();
    first_run_diag('FIRST_RUN_STARTED', ['data_dir' => $directory, 'base_url_detected' => $baseUrl !== '']);
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
            if (!is_file($engineTarget) && !@copy(__DIR__ . '/private_data/vault_engine.php', $engineTarget)) throw new RuntimeException('runtime_copy_failed');
            if (!is_file($templateTarget) && !@copy(__DIR__ . '/private_data/email_template.php', $templateTarget)) throw new RuntimeException('template_copy_failed');
            @chmod($engineTarget, 0600);
            @chmod($templateTarget, 0600);
            first_run_diag('RUNTIME_FILES_READY', ['engine_exists' => is_file($engineTarget), 'template_exists' => is_file($templateTarget)]);
            if (!first_run_write_secure_config($secureConfig, $username, $email, $baseUrl, $directory)) throw new RuntimeException('secure_config_failed');
            first_run_diag('SECURE_CONFIG_WRITTEN', ['config_exists' => is_file($secureConfig)]);
            first_run_initialize_vault($password, $directory . '/passwords.enc');
            first_run_verify_written_envelope($directory . '/passwords.enc');
            require_once $engineTarget;
            first_run_diag('VAULT_RUNTIME_VERIFY_STARTED');
            if (vault_unlock($password) === false) {
                first_run_diag('VAULT_RUNTIME_VERIFY_FAILED', ['reason' => 'vault_unlock_returned_false']);
                throw new RuntimeException('vault_verification_failed');
            }
            first_run_diag('VAULT_RUNTIME_VERIFY_COMPLETED');
            if (@file_put_contents($configFile, $pointerConfig, LOCK_EX) === false || !@chmod($configFile, 0600)) throw new RuntimeException('pointer_config_failed');
            first_run_diag('INSTALL_POINTER_WRITTEN');
            if (!first_run_remove_sources()) throw new RuntimeException('first_run_cleanup_failed');
            first_run_diag('INSTALL_SUCCESS');
            @unlink(first_run_data_dir() . '/install_debug.log');
            unset($_SESSION['csrf_token']);
            @unlink(__FILE__);
            header('Location: index.php?setup=complete');
            exit;
        } catch (Throwable $exception) {
            first_run_diag('INSTALL_FAILED', ['exception_class' => $exception::class, 'failure' => $exception->getMessage(), 'line' => $exception->getLine()]);
            @unlink($secureConfig);
            $error = 'SentryIQ could not initialize the encrypted vault. [' . $exception->getMessage() . ']';
        }
    }
}

$csrf = sentryiq_csrf_token();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>SentryIQ — First Run</title>
    <link rel="stylesheet" href="pm_style.css">
</head>
<body>
<div class="box">
    <h2>🛡️ Create Your SentryIQ Vault</h2>
    <p>Configure the administrator account before using SentryIQ.</p>
    <?php if ($error !== ''): ?><p class="error"><?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></p><?php endif; ?>
    <form method="POST" autocomplete="off">
        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8'); ?>">
        <div class="form-group"><label>Administrator Username:</label><input type="text" name="setup_username" class="input-field" maxlength="64" required></div>
        <div class="form-group"><label>2FA Email Address:</label><input type="email" name="setup_email" class="input-field" required></div>
        <div class="form-group"><label>Secure Storage:</label><input type="text" class="input-field" value="<?php echo htmlspecialchars($directory, ENT_QUOTES, 'UTF-8'); ?>" readonly></div>
        <div class="form-group"><label>Application HTTPS URL:</label><input type="text" class="input-field" value="<?php echo htmlspecialchars($baseUrl, ENT_QUOTES, 'UTF-8'); ?>" readonly></div>
        <div class="form-group"><label>Master Vault Password:</label><input type="password" name="setup_password" class="input-field" minlength="12" required></div>
        <div class="form-group"><label>Confirm Master Vault Password:</label><input type="password" name="setup_password_confirm" class="input-field" minlength="12" required></div>
        <button type="submit" name="complete_first_run" class="btn btn-primary">Create Secure Vault</button>
    </form>
</div>
</body>
</html>
