<?php

declare(strict_types=1);

require_once __DIR__ . '/security_bootstrap.php';
sentryiq_security_bootstrap();

define('SENTRYIQ_CONFIG_FILE', __DIR__ . '/sentryiq_config.php');

if (!is_file(SENTRYIQ_CONFIG_FILE)) {
    if (is_file(__DIR__ . '/first_run.php')) {
        require __DIR__ . '/first_run.php';
        exit;
    }
    http_response_code(503);
    exit('SentryIQ installation is unavailable.');
}

$config = require SENTRYIQ_CONFIG_FILE;
if (!is_array($config)) {
    http_response_code(503);
    exit('SentryIQ configuration is unavailable.');
}

$dataDir = rtrim((string)($config['data_dir'] ?? ''), '/');
if ($dataDir === '' || !str_starts_with($dataDir, '/') || !is_dir($dataDir) || !is_file($dataDir . '/vault_engine.php') || !is_file($dataDir . '/email_template.php')) {
    http_response_code(503);
    exit('SentryIQ secure runtime is unavailable.');
}

require_once $dataDir . '/vault_engine.php';
require_once $dataDir . '/email_template.php';
require_once __DIR__ . '/auth_flow.php';

date_default_timezone_set('Africa/Johannesburg');

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['lock_vault'])) {
    sentryiq_require_csrf();
    log_security_event('VAULT_LOCKED', get_visitor_ip(), $_SESSION['app_username'] ?? 'unknown');
    sentryiq_lock_vault();
    header('Location: index.php');
    exit;
}

$vault_authenticated = isset($_SESSION['master_key']) && is_string($_SESSION['master_key']) && strlen($_SESSION['master_key']) === 32;
$passwords = [];
$vault_error = false;
if ($vault_authenticated) {
    $passwords = load_passwords();
    if ($passwords === false) {
        log_security_event('VAULT_READ_FAILURE', get_visitor_ip(), $_SESSION['app_username'] ?? 'unknown');
        sentryiq_lock_vault();
        $vault_authenticated = false;
        $vault_error = true;
    }
}

$systemConfig = [];
foreach (is_array($passwords) ? $passwords : [] as $entry) {
    if (($entry['type'] ?? '') === 'system_config') {
        $systemConfig = $entry;
        break;
    }
}

$sys_user = trim((string)($systemConfig['app_username'] ?? $_SESSION['app_username'] ?? ''));
$sys_email = trim((string)($systemConfig['2fa_email'] ?? TWO_FA_EMAIL));
$active_pane = (string)($_GET['pane'] ?? 'view');
if (!in_array($active_pane, ['view','add','settings','log','details','edit'], true)) $active_pane = 'view';
$csrf = sentryiq_csrf_token();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="csrf-token" content="<?php echo htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8'); ?>">
<title>SentryIQ</title>
<link rel="stylesheet" href="pm_style.css">
<script>
function switchVaultTab(tabName){document.querySelectorAll('.vault-panel').forEach(panel=>panel.classList.remove('active'));document.querySelectorAll('.tab-btn').forEach(btn=>btn.classList.remove('active'));var targetPanel=document.getElementById(tabName+'-panel');if(targetPanel)targetPanel.classList.add('active');var btnElement=document.getElementById(tabName+'-btn');if(btnElement)btnElement.classList.add('active');}
function toggleVaultMobileMenu(){var menu=document.getElementById('vault-mobile-menu');var toggle=document.querySelector('.vault-mobile-menu-toggle');if(!menu||!toggle)return;var isOpen=menu.classList.toggle('mobile-open');toggle.setAttribute('aria-expanded',isOpen?'true':'false');}
function parseVaultCsv(value){var fields=[],field='',quoted=false;for(var i=0;i<value.length;i++){var ch=value[i];if(ch==='"'){if(quoted&&value[i+1]==='"'){field+='"';i++;}else{quoted=!quoted;}}else if(ch===','&&!quoted){fields.push(field);field='';}else{field+=ch;}}fields.push(field);return fields;}
function viewRecordDetails(label,username,password,url,notes,id){if(Array.isArray(label)){var args=label;label=args[0]||'';username=args[1]||'';password=args[2]||'';url=args[3]||'';notes=args[4]||'';id=args[5]||'';}if(!username&&!password&&!url&&!notes&&typeof label==='string'){var packed=parseVaultCsv(label);if(packed.length>=5&&packed.length<=6){label=packed[0]||'';username=packed[1]||'';password=packed[2]||'';url=packed[3]||'';notes=packed[4]||'';if(!id&&packed[5])id=packed[5];}}document.getElementById('det-label').textContent=label;document.getElementById('det-username').textContent=username?username:'[None Stored]';document.getElementById('det-password').textContent=password;document.getElementById('det-notes').textContent=notes?notes:'[No Notes]';var urlLink=document.getElementById('det-url');try{var parsed=url?new URL(url,window.location.origin):null;if(parsed&&parsed.protocol==='https:'){urlLink.href=parsed.href;urlLink.textContent=parsed.href;urlLink.style.display='inline';urlLink.rel='noopener noreferrer';}else{throw new Error('Unsafe URL');}}catch(e){urlLink.textContent=url?'[Unsafe URL Blocked]':'[None Stored]';urlLink.removeAttribute('href');urlLink.style.display='inline';}var icon=document.getElementById('det-icon');if(id){icon.src='vault-icon.php?id='+encodeURIComponent(id);icon.style.display='block';icon.onerror=function(){this.style.display='none';};}else{icon.removeAttribute('src');icon.style.display='none';}document.getElementById('det-delete-id').value=id;switchVaultTab('details');}
</script>
</head>
<body>
<div class="box">
<?php if (!$vault_authenticated): ?>
    <img class="sentryiq-brand-banner" src="sentryiq-logo-wide.webp" width="1952" height="588" alt="SentryIQ" fetchpriority="high">
    <?php if ($vault_error): ?>
        <p class="error">The secure vault could not be opened. No changes have been made.</p>
    <?php elseif (!isset($_SESSION['pending_key'])): ?>
        <?php if ($decryption_failed): ?><p class="error">Unable to verify the master vault password.</p><?php endif; ?>
        <form method="POST" autocomplete="off">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8'); ?>">
            <div class="form-group"><label>Master Vault Password:</label><input type="password" name="master_password" class="input-field" autocomplete="current-password" required autofocus></div>
            <button type="submit" name="login_step_1" class="btn btn-primary">Unlock</button>
        </form>
    <?php else: ?>
        <h2>🔐 Verification</h2>
        <p>A temporary verification code has been sent to the configured 2FA email address.</p>
        <?php if ($error_step_2 !== ''): ?><p class="error"><?php echo htmlspecialchars($error_step_2, ENT_QUOTES, 'UTF-8'); ?></p><?php endif; ?>
        <form method="POST" autocomplete="off">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8'); ?>">
            <div class="form-group"><label>Enter 6-Digit Code:</label><input type="text" name="verification_code" maxlength="6" inputmode="numeric" pattern="[0-9]{6}" autocomplete="one-time-code" class="input-field" required autofocus></div>
            <button type="submit" name="login_step_2" class="btn btn-primary">Verify &amp; Unlock</button>
        </form>
    <?php endif; ?>
<?php else: ?>
    <?php require_once __DIR__ . '/dashboard_list.php'; ?>
    <?php require_once __DIR__ . '/dashboard_actions.php'; ?>
<?php endif; ?>
</div>
<script>
document.addEventListener('DOMContentLoaded',function(){
    var csrf=document.querySelector('meta[name="csrf-token"]');
    var token=csrf?csrf.content:'';
    document.querySelectorAll('form[method="POST"]').forEach(function(form){
        if(!form.querySelector('input[name="csrf_token"]')&&token){var input=document.createElement('input');input.type='hidden';input.name='csrf_token';input.value=token;form.appendChild(input);}
        if(form.querySelector('button[name="add_entry"]')){form.action='vault_actions.php';var action=document.createElement('input');action.type='hidden';action.name='action';action.value='add';form.appendChild(action);}
        if(form.querySelector('button[name="save_vault_settings"]')){form.action='vault_actions.php';var action2=document.createElement('input');action2.type='hidden';action2.name='action';action2.value='save_settings';form.appendChild(action2);}
        if(form.querySelector('button[name="edit_entry"]')){form.action='vault_actions.php';var action3=document.createElement('input');action3.type='hidden';action3.name='action';action3.value='edit';form.appendChild(action3);}
        if(form.querySelector('button[name="delete_entry"]')){form.action='vault_actions.php';var action4=document.createElement('input');action4.type='hidden';action4.name='action';action4.value='delete';form.appendChild(action4);}
    });
    document.querySelectorAll('a[target="_blank"]').forEach(function(a){a.rel='noopener noreferrer';});
    var lock=document.querySelector('a[href="?action=logout"]');
    if(lock){var form=document.createElement('form');form.method='POST';form.style.display='inline';var tokenInput=document.createElement('input');tokenInput.type='hidden';tokenInput.name='csrf_token';tokenInput.value=token;var actionInput=document.createElement('input');actionInput.type='hidden';actionInput.name='lock_vault';actionInput.value='1';var button=document.createElement('button');button.type='submit';button.className=lock.className;button.style.cssText=lock.getAttribute('style')||'';button.textContent=lock.textContent;form.appendChild(tokenInput);form.appendChild(actionInput);form.appendChild(button);lock.replaceWith(form);}
    switchVaultTab('<?php echo htmlspecialchars($active_pane, ENT_QUOTES, 'UTF-8'); ?>');
});
</script>
</body>
</html>
