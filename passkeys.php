<?php

declare(strict_types=1);

require_once __DIR__ . '/security_bootstrap.php';
sentryiq_security_bootstrap();
sentryiq_require_auth();

$dataDir = sentryiq_data_dir();
$store = [];
if ($dataDir !== '') {
    $path = $dataDir . '/passkeys.json';
    if (is_file($path) && !is_link($path)) {
        $raw = @file_get_contents($path);
        $decoded = is_string($raw) ? json_decode($raw, true) : null;
        if (is_array($decoded)) $store = $decoded;
    }
}
$credentials = is_array($store['credentials'] ?? null) ? $store['credentials'] : [];
$csrf = sentryiq_csrf_token();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="csrf-token" content="<?php echo htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8'); ?>">
<title>SentryIQ — Passkeys</title>
<link rel="stylesheet" href="pm_style.css">
<style>
.sentryiq-page-header{display:flex;align-items:center;justify-content:space-between;gap:16px;flex-wrap:wrap}.sentryiq-page-actions{display:flex;align-items:center;gap:10px}.sentryiq-mobile-menu{display:none}.sentryiq-mobile-menu-toggle{border:0;border-radius:8px;padding:9px 12px;background:#0066cc;color:#fff;font-size:14px;font-weight:600;cursor:pointer}.sentryiq-mobile-menu-panel{display:none;margin-top:10px;padding:8px;border:1px solid #e9ecef;border-radius:10px;background:#fff;box-shadow:0 4px 12px rgba(0,0,0,.06)}.sentryiq-mobile-menu-panel.open{display:block}.sentryiq-mobile-menu-panel a,.sentryiq-mobile-menu-panel button{display:block;width:100%;box-sizing:border-box;padding:11px 12px;margin:2px 0;border:0;border-radius:7px;background:transparent;color:#212529;text-align:left;text-decoration:none;font:inherit;cursor:pointer}
.passkeys-content{font-family:Arial,sans-serif;font-size:14px;font-weight:400;line-height:1.5;max-width:900px;margin:20px auto}.passkeys-heading{display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap}.passkeys-heading h2{font-size:20px;line-height:1.25;margin:0 0 6px;font-weight:600}.passkeys-heading p{margin:0;color:#666;font-size:14px;font-weight:400}.passkey-list{margin-top:20px}.passkey-empty{padding:20px;text-align:center;color:#777}.passkey-row{display:flex;align-items:center;justify-content:space-between;gap:12px;padding:12px 15px;margin-bottom:8px;background:#fff;border:1px solid #e3e6f0;border-radius:6px}.passkey-identity{min-width:0}.passkey-identity strong{display:block;color:#2c3e50;font-size:14px;font-weight:600}.passkey-added{margin-top:2px;color:#666;font-size:13px;font-weight:400}.passkey-credential{margin-top:2px;color:#888;font-size:12px;font-weight:400;overflow-wrap:anywhere;word-break:break-word}.passkey-add-box{margin-top:20px;background:#f8f9fa;padding:20px;border-radius:6px;border:1px solid #e3e6f0}.passkey-add-box h3{font-size:16px;line-height:1.3;margin:0 0 6px;font-weight:600}.passkey-add-box p{margin:0 0 14px;color:#666;font-size:14px;font-weight:400}.passkey-note{margin-top:15px;color:#777;font-size:13px;font-weight:400}.passkeys-content .btn{font-size:14px}.passkeys-content .success,.passkeys-content .error{font-size:14px}
@media (max-width:600px){.sentryiq-page-header{align-items:center;flex-wrap:nowrap}.sentryiq-page-header .sentryiq-vault-brand-wrap{min-width:0;flex:1}.sentryiq-page-header .sentryiq-vault-banner{max-width:190px}.sentryiq-page-actions .sentryiq-back{display:none}.sentryiq-mobile-menu{display:block}.passkeys-content{margin:16px auto;font-size:14px}.passkeys-heading{align-items:flex-start}.passkeys-heading h2{font-size:20px}.passkeys-heading p{font-size:14px}.passkeys-back{display:none}.passkey-row{align-items:flex-start}.passkey-add-box{padding:14px}.passkey-add-box h3{font-size:16px}.passkey-add-box p{font-size:14px}}
</style>
</head>
<body>
<div class="box">
    <div class="sentryiq-page-header">
        <div class="sentryiq-vault-brand-wrap"><img class="sentryiq-vault-banner" src="sentryiq-logo-wide.webp" width="1952" height="588" alt="SentryIQ"><span class="sentryiq-vault-status">Passkeys</span></div>
        <div class="sentryiq-page-actions"><a href="index.php" class="btn sentryiq-back" style="text-decoration:none;">Back to Vault</a><div class="sentryiq-mobile-menu"><button type="button" class="sentryiq-mobile-menu-toggle" id="passkeys-menu-toggle" aria-expanded="false" aria-controls="passkeys-menu-panel">☰ Menu</button></div></div>
    </div>
    <div id="passkeys-menu-panel" class="sentryiq-mobile-menu-panel">
        <a href="index.php?pane=view">📋 Vault</a>
        <a href="documents.php">📄 Docs</a>
        <a href="gallery.php">🖼️ Gallery</a>
        <a href="index.php?pane=settings">⚙️ System</a>
        <form method="POST" style="margin:0;"><input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8'); ?>"><input type="hidden" name="lock_vault" value="1"><button type="submit">🔒 Lock Vault</button></form>
    </div>
    <div class="passkeys-content">
        <div class="passkeys-heading"><div><h2>🔑 Passkeys</h2><p>Manage the devices that can unlock your SentryIQ vault with Face ID, Touch ID, or passkey authentication.</p></div><a href="index.php" class="btn passkeys-back">Back to Vault</a></div>
        <div id="passkey-message" style="display:none;"></div>
        <div class="passkey-list">
            <?php if (empty($credentials)): ?><div class="passkey-empty">No passkeys are registered yet.</div>
            <?php else: ?><?php foreach ($credentials as $index => $credential): $created=(int)($credential['created_at']??0);$label='Passkey '.($index+1);$id=(string)($credential['credential_id']??''); ?><div class="passkey-row"><div class="passkey-identity"><strong><?php echo htmlspecialchars($label,ENT_QUOTES,'UTF-8'); ?></strong><div class="passkey-added">Added <?php echo $created>0?htmlspecialchars(date('j M Y, H:i',$created),ENT_QUOTES,'UTF-8'):'date unavailable'; ?></div><div class="passkey-credential">Credential: <?php echo htmlspecialchars(substr($id,0,18).(strlen($id)>18?'…':''),ENT_QUOTES,'UTF-8'); ?></div></div><button type="button" class="btn btn-primary remove-passkey" data-credential="<?php echo htmlspecialchars($id,ENT_QUOTES,'UTF-8'); ?>" <?php echo count($credentials)<=1?'disabled title="Keep at least one passkey registered"':''; ?>>Remove</button></div><?php endforeach; ?><?php endif; ?>
        </div>
        <div class="passkey-add-box"><h3>Add another device</h3><p>Register another passkey on your phone, Mac, tablet, or another trusted device. Each passkey gets its own protected copy of your existing vault key.</p><a href="passkey_setup.php?add=1" class="btn btn-primary">＋ Add Another Passkey</a></div>
        <p class="passkey-note">SentryIQ will not let you remove the last registered passkey. If you lose access to a device, use the password recovery route to regain access and register a replacement passkey.</p>
    </div>
</div>
<script>
(function(){var toggle=document.getElementById('passkeys-menu-toggle'),panel=document.getElementById('passkeys-menu-panel');if(toggle&&panel)toggle.addEventListener('click',function(){var open=panel.classList.toggle('open');toggle.setAttribute('aria-expanded',open?'true':'false');});var csrf=document.querySelector('meta[name="csrf-token"]').content,message=document.getElementById('passkey-message');function showMessage(text,ok){message.textContent=text;message.className=ok?'success':'error';message.style.display='block';}document.querySelectorAll('.remove-passkey').forEach(function(button){button.addEventListener('click',async function(){if(button.disabled)return;if(!window.confirm('Remove this passkey? This device will no longer be able to unlock SentryIQ.'))return;button.disabled=true;try{var response=await fetch('passkey_auth.php',{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json'},body:JSON.stringify({action:'delete',csrf_token:csrf,credential_id:button.dataset.credential})});var result=await response.json();if(!response.ok||result.status!=='ok')throw new Error(result.message||'Unable to remove the passkey.');window.location.reload();}catch(error){showMessage(error&&error.message?error.message:'Unable to remove the passkey.',false);button.disabled=false;}});});}());
</script>
</body>
</html>
