<?php
ini_set('session.cookie_path','/');ini_set('session.cookie_secure','1');ini_set('session.cookie_httponly','1');ini_set('session.cookie_samesite','Lax');session_start();date_default_timezone_set('Africa/Johannesburg');require_once __DIR__.'/first_run.php';$config=require SENTRYIQ_CONFIG_FILE;$dataDir=rtrim((string)($config['data_dir']??''),'/');require_once $dataDir.'/vault_engine.php';require_once $dataDir.'/email_template.php';if(isset($_GET['action'])&&$_GET['action']==='logout'){session_destroy();header('Location: index.php');exit;}require_once 'auth_controller.php';
?>
<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"><title>Vault Studio Manager</title><link rel="stylesheet" href="pm_style.css"><script>
function switchVaultTab(tabName){document.querySelectorAll('.vault-panel').forEach(panel=>panel.classList.remove('active'));document.querySelectorAll('.tab-btn').forEach(btn=>btn.classList.remove('active'));var targetPanel=document.getElementById(tabName+'-panel');if(targetPanel)targetPanel.classList.add('active');var btnElement=document.getElementById(tabName+'-btn');if(btnElement)btnElement.classList.add('active');}
function parseVaultCsv(value){var fields=[],field='',quoted=false;for(var i=0;i<value.length;i++){var ch=value[i];if(ch==='"'){if(quoted&&value[i+1]==='"'){field+='"';i++;}else{quoted=!quoted;}}else if(ch===','&&!quoted){fields.push(field);field='';}else{field+=ch;}}fields.push(field);return fields;}
function viewRecordDetails(label,username,password,url,notes,id){if(Array.isArray(label)){var args=label;label=args[0]||'';username=args[1]||'';password=args[2]||'';url=args[3]||'';notes=args[4]||'';id=args[5]||'';}if(!username&&!password&&!url&&!notes&&typeof label==='string'){var packed=parseVaultCsv(label);if(packed.length>=5&&packed.length<=6){label=packed[0]||'';username=packed[1]||'';password=packed[2]||'';url=packed[3]||'';notes=packed[4]||'';if(!id&&packed[5])id=packed[5];}}document.getElementById('det-label').textContent=label;document.getElementById('det-username').textContent=username?username:'[None Stored]';document.getElementById('det-password').textContent=password;document.getElementById('det-notes').textContent=notes?notes:'[No Notes]';var urlLink=document.getElementById('det-url');if(url){urlLink.href=url;urlLink.textContent=url;urlLink.style.display='inline';}else{urlLink.textContent='[None Stored]';urlLink.removeAttribute('href');}var icon=document.getElementById('det-icon');if(id){icon.src='vault-icon.php?id='+encodeURIComponent(id);icon.style.display='block';icon.onerror=function(){this.style.display='none';};}else{icon.removeAttribute('src');icon.style.display='none';}document.getElementById('det-delete-id').value=id;switchVaultTab('details');}
</script></head><body><div class="box">
<?php if(!$vault_authenticated): ?>
<?php if(!file_exists(DATA_FILE)||$vault_missing_error): ?><h2>⚙️ Create New Vault Store</h2><p class="error">No secure database file detected at your data file location.</p><form method="POST"><div class="form-group"><label>Set Master Vault Key:</label><input type="password" name="init_password" class="input-field" required autofocus></div><button type="submit" name="initialize_new_vault" class="btn btn-primary">Initialize Vault File</button></form>
<?php elseif(!isset($_SESSION['pending_key'])): ?><h2>🔒 Open Secure Vault</h2><?php if($decryption_failed)echo "<p class='error'>Incorrect master key. Decryption failed.</p>"; ?><form method="POST"><div class="form-group"><label>Master Vault Key:</label><input type="password" name="master_password" class="input-field" required autofocus></div><button type="submit" name="login_step_1" class="btn btn-primary">Decrypt Vault</button></form>
<?php else: ?><h2>Verification Checkpoint</h2><p>An encrypted execution tracking code has been dispatched to your master email account frame.</p><?php if(!empty($error_step_2))echo "<p class='error'>$error_step_2</p>"; ?><form method="POST"><div class="form-group"><label>Enter 6-Digit Code:</label><input type="text" name="verification_code" maxlength="6" autocomplete="off" class="input-field" required autofocus></div><button type="submit" name="login_step_2" class="btn btn-primary">Verify & Mount Data</button></form><?php endif; ?>
<?php else: ?><?php require_once 'dashboard_list.php'; ?><?php require_once 'dashboard_actions.php'; ?><script>
document.addEventListener('DOMContentLoaded', function () {
    document.querySelectorAll('form').forEach(function (form) {
        if (form.querySelector('button[name="edit_entry"]')) {
            form.action = 'record_actions.php';
            var action = document.createElement('input');
            action.type = 'hidden'; action.name = 'action'; action.value = 'edit';
            form.appendChild(action);
        }
        if (form.querySelector('button[name="delete_entry"]')) {
            form.action = 'record_actions.php';
            var action = document.createElement('input');
            action.type = 'hidden'; action.name = 'action'; action.value = 'delete';
            form.appendChild(action);
        }
    });
});
</script><?php endif; ?></div></body></html>