<?php
/**
 * Vault Studio Manager - Application Controller
 * Location: /home/bicheveb/public_html/pm/auth_controller.php
 */

$decryption_failed = false;
$vault_missing_error = false;
$error_step_2 = "";

// Step 1: Master Password Processing Node
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['login_step_1'])) {
    if (!empty($_POST['master_password'])) {
        $temporary_key = hash('sha256', $_POST['master_password'], true);
        $ip = get_visitor_ip();

        if (file_exists(DATA_FILE)) {
            $test_load = load_passwords($temporary_key);

            if ($test_load === false) {
                $decryption_failed = true;
                log_security_event("FAILED_LOGIN_STEP_1", $ip);
            } else {
                $two_fa_code = str_pad((string)random_int(100000, 999999), 6, '0', STR_PAD_LEFT);
                $copy_token = bin2hex(random_bytes(32));

                $_SESSION['pending_key'] = $temporary_key;
                $_SESSION['two_fa_code'] = $two_fa_code;
                $_SESSION['copy_code_token'] = $copy_token;

                // Write out unique single-use token file above the web root
                $secure_payload = [
                    'code' => $two_fa_code,
                    'expires' => time() + 300
                ];
                file_put_contents('/home/bicheveb/private_data/token_' . $copy_token . '.json', json_encode($secure_payload));

                $base_path = rtrim(dirname($_SERVER['PHP_SELF']), '/');
                $copy_url = 'https://' . $_SERVER['HTTP_HOST'] . $base_path . '/copy-code.php?t=' . urlencode($copy_token);

                // Inline HTML Message definition ensures zero dynamic path or variable compilation crashes
                $html_message = "
                <html>
                <body style='font-family:sans-serif; background:#f1f3f5; padding:30px; text-align:center;'>
                    <div style='max-width:500px; margin:0 auto; background:#fff; padding:30px; border-radius:8px; border:1px solid #e9ecef;'>
                        <h2 style='color:#0066cc;'>🔒 Vault Access Code</h2>
                        <p>Your 6-digit system mounting verification key is:</p>
                        <div style='font-size:32px; font-weight:bold; background:#212529; color:#ffec99; padding:10px; border-radius:6px; letter-spacing:4px; margin:20px 0;'>".$two_fa_code."</div>
                        <p><a href='".$copy_url."' style='display:inline-block; background:#0066cc; color:#fff; padding:10px 20px; text-decoration:none; border-radius:5px; font-weight:bold;'>📋 Open Copy Link Webpage</a></p>
                    </div>
                </body>
                </html>";

                // PASSWORDLESS EMAIL PIPELINE: Automatically sets up headers using your domain name
                $sender_email = "security@" . $_SERVER['HTTP_HOST'];
                $headers = "MIME-Version: 1.0\r\n";
                $headers .= "Content-Type: text/html; charset=UTF-8\r\n";
                $headers .= "From: Vault Security <" . $sender_email . ">\r\n";
                $headers .= "Reply-To: " . $sender_email . "\r\n";
                $headers .= "X-Mailer: PHP/" . phpversion() . "\r\n";
                
                // Prioritize the explicit constant first
                $target_delivery_email = defined('TWO_FA_EMAIL') ? TWO_FA_EMAIL : '';

                // Overwrite with dynamic configurations ONLY if a valid system layout row exists
                if (is_array($test_load) && !empty($test_load)) {
                    foreach ($test_load as $entry) {
                        if (isset($entry['type']) && $entry['type'] === 'system_config' && !empty($entry['2fa_email'])) {
                            $target_delivery_email = trim($entry['2fa_email']);
                            break;
                        }
                    }
                }

                $clean_email = filter_var(trim($target_delivery_email), FILTER_VALIDATE_EMAIL);

                if (!$clean_email) {
                    log_security_event("EMAIL_ABORT_INVALID_TARGET", $ip);
                    $decryption_failed = true;
                } else {
                    // Triggers the passwordless mail function and logs whether it passes or fails on the server
                    if (mail($clean_email, "🔒 Vault Access: 2FA Security Code", $html_message, $headers)) {
                        log_security_event("STEP_1_SUCCESS_2FA_SENT", $ip);
                    } else {
                        log_security_event("EMAIL_SERVER_REJECTED_SEND", $ip);
                        error_log("PHP mail() failed to accept the message for delivery on this server.");
                        $decryption_failed = true;
                    }
                }
            }
        } else {
            $vault_missing_error = true;
        }
    }
}

// Handle First-Time Vault Initialization
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['initialize_new_vault'])) {
    if (!empty($_POST['init_password'])) {
        $temporary_key = hash('sha256', $_POST['init_password'], true);
        $_SESSION['master_key'] = $temporary_key;
        save_passwords([]);
        log_security_event("VAULT_INITIALIZED", get_visitor_ip());
        header("Location: index.php");
        exit;
    }
}

// Step 2: Multi-Factor Authentication Code Verification
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['login_step_2'])) {
    $ip = get_visitor_ip();
    if (isset($_SESSION['pending_key'], $_SESSION['two_fa_code'])) {
        if (trim($_POST['verification_code']) === $_SESSION['two_fa_code']) {
            $_SESSION['master_key'] = $_SESSION['pending_key'];
            unset($_SESSION['pending_key'], $_SESSION['two_fa_code']);
            log_security_event("SUCCESSFUL_VAULT_LOGIN", $ip);
            header("Location: index.php");
            exit;
        } else {
            log_security_event("FAILED_2FA_CODE_ATTEMPT", $ip);
            $error_step_2 = "Invalid validation token.";
        }
    } else {
        $error_step_2 = "Session parameters dropped. Restart login procedure.";
    }
}
// Handle Record Additions, Modifications & Removals
$passwords = [];
$vault_authenticated = isset($_SESSION['master_key']);

if ($vault_authenticated) {
    $passwords = load_passwords();
    
    // Save Vault System Settings
    if (isset($_POST['save_vault_settings'])) {
        $current_vault_data = load_passwords();
        $settings_updated = false;
        foreach ($current_vault_data as $idx => $entry) {
            if (isset($entry['type']) && $entry['type'] === 'system_config') {
                $current_vault_data[$idx]['app_username'] = trim($_POST['app_username']);
                $current_vault_data[$idx]['2fa_email'] = filter_var($_POST['two_fa_email_field'], FILTER_SANITIZE_EMAIL);
                if (!empty($_POST['imap_password_field'])) {
                    $current_vault_data[$idx]['imap_password'] = trim($_POST['imap_password_field']);
                }
                $settings_updated = true;
                break;
            }
        }
        
        if (!$settings_updated) {
            $current_vault_data[] = [
                'id' => 'sys_config_node',
                'type' => 'system_config',
                'app_username' => trim($_POST['app_username']),
                '2fa_email' => filter_var($_POST['two_fa_email_field'], FILTER_SANITIZE_EMAIL),
                'imap_password' => trim($_POST['imap_password_field'])
            ];
        }
        
        save_passwords($current_vault_data);
        header("Location: index.php?status=saved&pane=settings");
        exit;
    }

    // Handle Record Additions
    if (isset($_POST['add_entry'])) {
        $label = trim($_POST['label']);
        $password = trim($_POST['password']);
        $url = trim($_POST['url']);
        
        if (!empty($label) && !empty($password)) {
            $passwords[] = [
                'id' => uniqid(), 
                'label' => $label, 
                'username' => trim($_POST['username']), 
                'password' => $password, 
                'url' => $url, 
                'og_image' => null,
                'created_at' => date('Y-m-d H:i:s')
            ];
            save_passwords($passwords);
            header("Location: index.php?status=saved&pane=view");
            exit;
        }
    }

    // NEW BACKEND EDIT BLOCK: Updates existing fields securely based on matching record ID
    if (isset($_POST['edit_entry'])) {
        $entry_id = trim($_POST['entry_id']);
        $label = trim($_POST['label']);
        $password = trim($_POST['password']);
        $url = trim($_POST['url']);
        $username = trim($_POST['username']);
        
        if (!empty($entry_id) && !empty($label) && !empty($password)) {
            foreach ($passwords as $index => $item) {
                if ($item['id'] === $entry_id) {
                    $passwords[$index]['label'] = $label;
                    $passwords[$index]['username'] = $username;
                    $passwords[$index]['password'] = $password;
                    $passwords[$index]['url'] = $url;
                    $passwords[$index]['updated_at'] = date('Y-m-d H:i:s');
                    break;
                }
            }
            save_passwords($passwords);
            header("Location: index.php?status=updated&pane=view");
            exit;
        }
    }
    
    // Handle Record Deletions (Modified text confirmation from Wipe to Delete)
    if (isset($_POST['delete_entry'])) {
        foreach ($passwords as $index => $item) {
            if ($item['id'] === $_POST['entry_id']) { 
                unset($passwords[$index]); 
                break; 
            }
        }
        save_passwords($passwords);
        header("Location: index.php?status=deleted&pane=view");
        exit;
    }
    
    $sys_user = 'admin';
    $sys_email = defined('TWO_FA_EMAIL') ? TWO_FA_EMAIL : '';
    $sys_imap_pass = '';
    
    foreach ($passwords as $idx => $entry) {
        if (isset($entry['type']) && $entry['type'] === 'system_config') {
            $sys_user = $entry['app_username'];
            $sys_email = $entry['2fa_email'];
            $sys_imap_pass = isset($entry['imap_password']) ? $entry['imap_password'] : '';
            unset($passwords[$idx]);
            break;
        }
    }
}

$active_pane = isset($_GET['pane']) ? $_GET['pane'] : 'view';

function clean_domain(string $url) {
    if (empty($url)) return '';
    if (!preg_match('#^http(s)?://#i', $url)) { $url = 'http://' . $url; }
    $urlParts = parse_url($url);
    $host = isset($urlParts['host']) ? $urlParts['host'] : '';
    return preg_replace('/^www\./i', '', $host);
}
