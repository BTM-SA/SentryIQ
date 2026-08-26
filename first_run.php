<?php

declare(strict_types=1);

require_once __DIR__ . '/security_bootstrap.php';
sentryiq_security_bootstrap();

$configFile = __DIR__ . '/sentryiq_config.php';
if (is_file($configFile)) {
    http_response_code(404);
    exit('Not found.');
}

function first_run_validate_directory(string $directory): bool
{
    if ($directory === '' || !str_starts_with($directory, '/')) return false;
    if (preg_match('#/(public_html|htdocs|www)(/|$)#i', $directory)) return false;
    if (is_link($directory)) return false;
    if (!is_dir($directory) && !@mkdir($directory, 0700, true)) return false;
    if (!is_dir($directory) || !is_writable($directory) || is_link($directory)) return false;
    $real = realpath($directory);
    if ($real === false || rtrim($real, '/') !== rtrim($directory, '/')) return false;
    $perms = @fileperms($directory);
    if ($perms === false || (($perms & 0x0077) !== 0)) return false;
    if (function_exists('posix_geteuid')) {
        if (@fileowner($directory) !== @posix_geteuid()) return false;
    }
    return true;
}

function first_run_write_config(string $path, string $username, string $email, string $baseUrl, string $directory): bool
{
    $config = "<?php\nreturn [\n" .
        "    'installed' => true,\n" .
        "    'username' => " . var_export($username, true) . ",\n" .
        "    'two_fa_email' => " . var_export($email, true) . ",\n" .
        "    'base_url' => " . var_export($baseUrl, true) . ",\n" .
        "    'data_dir' => " . var_export(rtrim($directory, '/'), true) . ",\n" .
        "    'two_fa_token_expiry' => 300,\n" .
        "];\n";
    return @file_put_contents($path, $config, LOCK_EX) !== false && @chmod($path, 0600);
}

function first_run_remove_reference_files(): bool
{
    $directory = __DIR__ . '/private_data';
    if (!is_dir($directory)) return true;
    foreach (['vault_engine.php', 'email_template.php'] as $name) {
        $path = $directory . '/' . $name;
        if (is_file($path) && !@unlink($path)) return false;
    }
    $remaining = array_values(array_diff(@scandir($directory) ?: [], ['.', '..']));
    return count($remaining) === 0 ? @rmdir($directory) : false;
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['complete_first_run'])) {
    sentryiq_require_csrf();

    $username = trim((string)($_POST['setup_username'] ?? ''));
    $email = trim((string)($_POST['setup_email'] ?? ''));
    $baseUrl = rtrim(trim((string)($_POST['setup_base_url'] ?? '')), '/');
    $directory = rtrim(trim((string)($_POST['setup_directory'] ?? '')), '/');
    $password = (string)($_POST['setup_password'] ?? '');
    $confirm = (string)($_POST['setup_password_confirm'] ?? '');
    $parts = parse_url($baseUrl);

    if (!preg_match('/^[A-Za-z0-9._-]{2,64}$/', $username)) $error = 'Please enter a valid administrator username.';
    elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) $error = 'Please enter a valid 2FA email address.';
    elseif (!filter_var($baseUrl, FILTER_VALIDATE_URL) || !is_array($parts) || strtolower((string)($parts['scheme'] ?? '')) !== 'https' || empty($parts['host']) || isset($parts['user']) || isset($parts['pass'])) $error = 'The application URL must be a valid HTTPS URL.';
    elseif (!first_run_validate_directory($directory)) $error = 'Secure storage must be an application-owned 0700 directory outside the web root.';
    elseif (strlen($password) < 12) $error = 'The master vault password must be at least 12 characters long.';
    elseif ($password !== $confirm) $error = 'The master vault passwords do not match.';
    elseif (!is_file(__DIR__ . '/private_data/vault_engine.php') || !is_file(__DIR__ . '/private_data/email_template.php')) $error = 'SentryIQ installation files are incomplete.';
    else {
        $engineSource = __DIR__ . '/private_data/vault_engine.php';
        $templateSource = __DIR__ . '/private_data/email_template.php';
        $engineTarget = $directory . '/vault_engine.php';
        $templateTarget = $directory . '/email_template.php';
        $secureConfig = $directory . '/sentryiq_config.php';
        $pointerConfig = "<?php\nreturn [\n    'data_dir' => " . var_export($directory, true) . ",\n    'base_url' => " . var_export($baseUrl, true) . ",\n];\n";

        if (!@copy($engineSource, $engineTarget) || !@copy($templateSource, $templateTarget)) {
            $error = 'SentryIQ could not create its secure runtime files.';
        } elseif (!@chmod($engineTarget, 0600) || !@chmod($templateTarget, 0600)) {
            $error = 'SentryIQ could not secure its runtime files.';
        } elseif (!first_run_write_config($secureConfig, $username, $email, $baseUrl, $directory)) {
            $error = 'SentryIQ could not create its secure configuration.';
        } else {
            require_once $engineTarget;
            $systemRecord = [[
                'id' => 'sys_config_node',
                'type' => 'system_config',
                'app_username' => $username,
                '2fa_email' => $email,
                'base_url' => $baseUrl,
                'imap_password' => '',
            ]];

            if (!vault_initialize($password, $systemRecord)) {
                @unlink($secureConfig);
                $error = 'SentryIQ could not initialize the encrypted vault.';
            } else {
                $verified = vault_unlock($password);
                if ($verified === false) {
                    @unlink(DATA_FILE);
                    @unlink($secureConfig);
                    $error = 'SentryIQ could not verify the initialized vault.';
                } elseif (@file_put_contents($configFile, $pointerConfig, LOCK_EX) === false || !@chmod($configFile, 0600)) {
                    @unlink(DATA_FILE);
                    @unlink($secureConfig);
                    $error = 'SentryIQ could not create the installation pointer.';
                } elseif (!first_run_remove_reference_files()) {
                    $error = 'The vault was created, but the temporary installation files could not be removed. Installation has been stopped for safety.';
                } else {
                    unset($_SESSION['csrf_token']);
                    @unlink(__FILE__);
                    header('Location: index.php?setup=complete');
                    exit;
                }
            }
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
    <p>Configure the administrator account and secure storage location before using SentryIQ.</p>
    <?php if ($error !== ''): ?><p class="error"><?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></p><?php endif; ?>
    <form method="POST" autocomplete="off">
        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8'); ?>">
        <div class="form-group"><label>Administrator Username:</label><input type="text" name="setup_username" class="input-field" maxlength="64" required></div>
        <div class="form-group"><label>2FA Email Address:</label><input type="email" name="setup_email" class="input-field" required></div>
        <div class="form-group"><label>Application HTTPS URL:</label><input type="url" name="setup_base_url" class="input-field" placeholder="https://vault.example.com" required><small style="display:block;margin-top:6px;color:#777;">This trusted URL is used for security-sensitive email links.</small></div>
        <div class="form-group"><label>Secure Storage Directory:</label><input type="text" name="setup_directory" class="input-field" placeholder="/home/username/private_data" required><small style="display:block;margin-top:6px;color:#777;">Must be outside the public web root.</small></div>
        <div class="form-group"><label>Master Vault Password:</label><input type="password" name="setup_password" class="input-field" minlength="12" required></div>
        <div class="form-group"><label>Confirm Master Vault Password:</label><input type="password" name="setup_password_confirm" class="input-field" minlength="12" required></div>
        <button type="submit" name="complete_first_run" class="btn btn-primary">Create Secure Vault</button>
    </form>
</div>
</body>
</html>
