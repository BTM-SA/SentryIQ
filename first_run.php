<?php
/**
 * SentryIQ first-run bootstrap.
 * The web-root config is only a non-secret pointer to the secure storage directory.
 * The secure directory contains the authoritative installation configuration.
 */
if (!defined('SENTRYIQ_CONFIG_FILE')) define('SENTRYIQ_CONFIG_FILE', __DIR__ . '/sentryiq_config.php');

$pointerConfig = is_file(SENTRYIQ_CONFIG_FILE) ? (require SENTRYIQ_CONFIG_FILE) : [];
$configuredDir = is_array($pointerConfig) ? trim((string)($pointerConfig['data_dir'] ?? '')) : '';

function sentryiq_reference_files_available(): bool {
    return is_file(__DIR__ . '/private_data/vault_engine.php') && is_file(__DIR__ . '/private_data/email_template.php');
}

function sentryiq_write_private_config(string $directory, string $username, string $email): bool {
    $config = "<?php\nreturn [\n    'installed' => true,\n    'username' => " . var_export($username, true) . ",\n    'two_fa_email' => " . var_export($email, true) . ",\n    'data_dir' => " . var_export(rtrim($directory, '/'), true) . ",\n    'two_fa_token_expiry' => 300,\n];\n";
    $path = rtrim($directory, '/') . '/sentryiq_config.php';
    if (@file_put_contents($path, $config, LOCK_EX) === false) return false;
    @chmod($path, 0600);
    return true;
}

function sentryiq_remove_reference_directory(): bool {
    $referenceDir = __DIR__ . '/private_data';
    if (!is_dir($referenceDir)) return true;
    $files = ['vault_engine.php', 'email_template.php'];
    foreach ($files as $file) {
        $path = $referenceDir . '/' . $file;
        if (is_file($path) && !@unlink($path)) return false;
    }
    // Remove the bundled directory only when it is empty. Never recurse or remove
    // anything other than the two known reference files.
    $remaining = @scandir($referenceDir);
    if ($remaining === false) return false;
    $remaining = array_values(array_diff($remaining, ['.', '..']));
    return count($remaining) === 0 ? @rmdir($referenceDir) : false;
}

// Existing installation: repair missing private runtime files and leave normal login alone.
if ($configuredDir !== '' && is_dir($configuredDir) && is_writable($configuredDir)) {
    $engineSource = __DIR__ . '/private_data/vault_engine.php';
    $templateSource = __DIR__ . '/private_data/email_template.php';
    $engineTarget = rtrim($configuredDir, '/') . '/vault_engine.php';
    $templateTarget = rtrim($configuredDir, '/') . '/email_template.php';
    if (!is_file($engineTarget) && is_file($engineSource)) @copy($engineSource, $engineTarget);
    if (!is_file($templateTarget) && is_file($templateSource)) @copy($templateSource, $templateTarget);
    @chmod($engineTarget, 0600);
    @chmod($templateTarget, 0600);
    if (is_file($engineTarget) && is_file($templateTarget)) {
        // If the secure runtime is already known-good, the web-root reference files
        // are no longer needed. Remove them only after both destination files exist.
        sentryiq_remove_reference_directory();
        return;
    }
}

$setupError = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['complete_first_run'])) {
    $username = trim((string)($_POST['setup_username'] ?? ''));
    $email = trim((string)($_POST['setup_email'] ?? ''));
    $directory = rtrim(trim((string)($_POST['setup_directory'] ?? '')), '/');
    $password = (string)($_POST['setup_password'] ?? '');
    $confirm = (string)($_POST['setup_password_confirm'] ?? '');

    if (!preg_match('/^[A-Za-z0-9._-]{2,64}$/', $username)) $setupError = 'Please enter a valid username (2–64 letters, numbers, dots, underscores or hyphens).';
    elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) $setupError = 'Please enter a valid 2FA email address.';
    elseif ($directory === '' || $directory[0] !== '/' || preg_match('#/(public_html|htdocs|www)(/|$)#i', $directory)) $setupError = 'Secure storage must be an absolute directory outside the web root.';
    elseif (strlen($password) < 12) $setupError = 'The master vault password must be at least 12 characters long.';
    elseif ($password !== $confirm) $setupError = 'The master vault passwords do not match.';
    elseif (!sentryiq_reference_files_available()) $setupError = 'The bundled private_data reference files are missing.';
    elseif (!is_dir($directory) && !@mkdir($directory, 0700, true)) $setupError = 'SentryIQ could not create the selected secure storage directory.';
    elseif (!is_dir($directory) || !is_writable($directory)) $setupError = 'The selected secure storage directory is not writable.';
    else {
        $engineSource = __DIR__ . '/private_data/vault_engine.php';
        $templateSource = __DIR__ . '/private_data/email_template.php';
        $engineTarget = $directory . '/vault_engine.php';
        $templateTarget = $directory . '/email_template.php';
        if ((!is_file($engineTarget) && !@copy($engineSource, $engineTarget)) || (!is_file($templateTarget) && !@copy($templateSource, $templateTarget))) {
            $setupError = 'SentryIQ could not create its private vault files.';
        } elseif (!is_file($engineTarget) || !is_file($templateTarget)) {
            $setupError = 'SentryIQ could not verify that its private vault files were created successfully.';
        } elseif (!sentryiq_write_private_config($directory, $username, $email)) {
            $setupError = 'SentryIQ could not save the private installation configuration.';
        } else {
            @chmod($engineTarget, 0600); @chmod($templateTarget, 0600);
            if (!is_dir($directory . '/vault_icons') && !@mkdir($directory . '/vault_icons', 0700, true) && !is_dir($directory . '/vault_icons')) {
                $setupError = 'SentryIQ could not create its private icon directory.';
            } elseif (!@file_put_contents(SENTRYIQ_CONFIG_FILE, "<?php\nreturn ['data_dir' => " . var_export($directory, true) . "];\n", LOCK_EX)) {
                $setupError = 'SentryIQ could not save its installation pointer.';
            } else {
                @chmod(SENTRYIQ_CONFIG_FILE, 0600);
                require_once $engineTarget;
                $_SESSION['master_key'] = hash('sha256', $password, true);
                $systemRecord = [[
                    'id' => 'sys_config_node', 'type' => 'system_config', 'app_username' => $username,
                    '2fa_email' => $email, 'imap_password' => '', 'two_fa_token_expiry' => 300,
                ]];
                if (!save_passwords($systemRecord) || !is_file(DATA_FILE)) {
                    @unlink(SENTRYIQ_CONFIG_FILE);
                    $setupError = 'SentryIQ could not initialize and verify the encrypted vault file.';
                } else {
                    unset($_SESSION['master_key']);
                    // Final safety gate: only remove the bundled reference directory
                    // after the secure directory, runtime files, configuration and
                    // encrypted vault have all been successfully created and verified.
                    if (!sentryiq_remove_reference_directory()) {
                        $setupError = 'SentryIQ was initialized successfully, but could not remove the bundled private_data reference directory.';
                    } else {
                        header('Location: index.php?setup=complete');
                        exit;
                    }
                }
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"><title>SentryIQ — First Run</title><link rel="stylesheet" href="pm_style.css"></head>
<body><div class="box"><h2>🛡️ Create Your SentryIQ Vault</h2><p>Configure the administrator account and secure storage location before using SentryIQ.</p>
<?php if ($setupError !== ''): ?><p class="error"><?php echo htmlspecialchars($setupError, ENT_QUOTES, 'UTF-8'); ?></p><?php endif; ?>
<form method="POST" autocomplete="off">
<div class="form-group"><label>Username:</label><input type="text" name="setup_username" class="input-field" maxlength="64" required value="<?php echo htmlspecialchars($_POST['setup_username'] ?? '', ENT_QUOTES, 'UTF-8'); ?>"></div>
<div class="form-group"><label>2FA Email Address:</label><input type="email" name="setup_email" class="input-field" required value="<?php echo htmlspecialchars($_POST['setup_email'] ?? '', ENT_QUOTES, 'UTF-8'); ?>"></div>
<div class="form-group"><label>Secure Storage Directory:</label><input type="text" name="setup_directory" class="input-field" placeholder="/home/username/private_data" required value="<?php echo htmlspecialchars($_POST['setup_directory'] ?? '', ENT_QUOTES, 'UTF-8'); ?>"><small style="display:block;margin-top:6px;color:#777;">Must be outside the public web root. SentryIQ creates it if needed.</small></div>
<div class="form-group"><label>Master Vault Password:</label><input type="password" name="setup_password" class="input-field" minlength="12" required></div>
<div class="form-group"><label>Confirm Master Vault Password:</label><input type="password" name="setup_password_confirm" class="input-field" minlength="12" required></div>
<button type="submit" name="complete_first_run" class="btn btn-primary">Create Secure Vault</button>
</form></div></body></html><?php exit; ?>