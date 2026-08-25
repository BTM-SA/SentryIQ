<?php
/**
 * SentryIQ first-run bootstrap.
 * Creates the private engine/template in the administrator-selected directory.
 */
if (!defined('SENTRYIQ_CONFIG_FILE')) define('SENTRYIQ_CONFIG_FILE', __DIR__ . '/sentryiq_config.php');
$config = is_file(SENTRYIQ_CONFIG_FILE) ? (require SENTRYIQ_CONFIG_FILE) : [];
$configuredDir = is_array($config) ? trim((string)($config['data_dir'] ?? '')) : '';
$engineTarget = $configuredDir !== '' ? rtrim($configuredDir, '/') . '/vault_engine.php' : '';
$templateTarget = $configuredDir !== '' ? rtrim($configuredDir, '/') . '/email_template.php' : '';

// Existing configuration: repair/create the two private reference files automatically.
if ($configuredDir !== '' && is_dir($configuredDir) && is_writable($configuredDir)) {
    $engineSource = __DIR__ . '/private_data/vault_engine.php';
    $templateSource = __DIR__ . '/private_data/email_template.php';
    if (!is_file($engineTarget) && is_file($engineSource)) @copy($engineSource, $engineTarget);
    if (!is_file($templateTarget) && is_file($templateSource)) @copy($templateSource, $templateTarget);
    @chmod($engineTarget, 0600);
    @chmod($templateTarget, 0600);
    if (is_file($engineTarget) && is_file($templateTarget)) return;
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
    elseif (!is_dir($directory) && !@mkdir($directory, 0700, true)) $setupError = 'SentryIQ could not create the selected secure storage directory.';
    elseif (!is_dir($directory) || !is_writable($directory)) $setupError = 'The selected secure storage directory is not writable.';
    else {
        $engineSource = __DIR__ . '/private_data/vault_engine.php';
        $templateSource = __DIR__ . '/private_data/email_template.php';
        $engineTarget = $directory . '/vault_engine.php';
        $templateTarget = $directory . '/email_template.php';
        if (!is_file($engineSource) || !is_file($templateSource)) $setupError = 'The bundled private_data reference files are missing.';
        elseif ((!is_file($engineTarget) && !@copy($engineSource, $engineTarget)) || (!is_file($templateTarget) && !@copy($templateSource, $templateTarget))) $setupError = 'SentryIQ could not create its private vault files.';
        else {
            @chmod($engineTarget, 0600); @chmod($templateTarget, 0600);
            if (!is_dir($directory . '/vault_icons')) @mkdir($directory . '/vault_icons', 0700, true);
            $configBody = "<?php\nreturn [\n    'data_dir' => " . var_export($directory, true) . ",\n];\n";
            if (@file_put_contents(SENTRYIQ_CONFIG_FILE, $configBody, LOCK_EX) === false) $setupError = 'SentryIQ could not save its secure storage configuration.';
            else {
                @chmod(SENTRYIQ_CONFIG_FILE, 0600);
                require_once $engineTarget;
                $_SESSION['master_key'] = hash('sha256', $password, true);
                $systemRecord = [[
                    'id' => 'sys_config_node', 'type' => 'system_config', 'app_username' => $username,
                    '2fa_email' => $email, 'imap_password' => '', 'two_fa_token_expiry' => 300,
                ]];
                if (!save_passwords($systemRecord)) { @unlink(SENTRYIQ_CONFIG_FILE); $setupError = 'SentryIQ could not initialize the encrypted vault file.'; }
                else { unset($_SESSION['master_key']); header('Location: index.php?setup=complete'); exit; }
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