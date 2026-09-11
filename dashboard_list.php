<?php
$passwords = normalize_vault_records($passwords ?? []);
$passwords = array_values(array_filter($passwords, static fn(array $row): bool => ($row['type'] ?? '') !== 'system_config'));
?>
<!-- Location: /home/bicheveb/public_html/pm/dashboard_list.php -->
<div class="sentryiq-page-header">
    <div class="sentryiq-vault-brand-wrap"><img class="sentryiq-vault-banner" src="sentryiq-logo-wide.webp" width="1952" height="588" alt="SentryIQ"><span class="sentryiq-vault-status" data-vault-status>Records</span></div>
    <div class="sentryiq-mobile-header-actions">
        <form method="POST" class="sentryiq-lock-form"><input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf ?? '', ENT_QUOTES, 'UTF-8'); ?>"><input type="hidden" name="lock_vault" value="1"><button type="submit" class="btn btn-primary sentryiq-lock-button">Lock Vault</button></form>
        <div class="vault-mobile-menu-bar"><button type="button" class="vault-mobile-menu-toggle" aria-expanded="false" aria-controls="vault-mobile-menu"><span class="vault-mobile-menu-icon" aria-hidden="true">☰</span><span id="vault-mobile-menu-label" class="visually-hidden">Menu</span></button></div>
    </div>
</div>

<?php if (isset($_GET['status']) && $_GET['status'] == 'saved') echo "<p class='success'>Entry stored successfully!</p>"; ?>
<?php if (isset($_GET['status']) && $_GET['status'] == 'updated') echo "<p class='success'>Entry updated successfully!</p>"; ?>
<?php if (isset($_GET['status']) && $_GET['status'] == 'deleted') echo "<p class='success'>Entry deleted safely from disk.</p>"; ?>
<?php if (isset($_GET['status']) && $_GET['status'] == 'error') echo "<p class='error'>The requested vault record operation could not be completed.</p>"; ?>
<?php if (isset($_GET['status']) && $_GET['status'] === 'validation' && ($_GET['field'] ?? '') === 'url') echo "<p class='error'>Please enter a valid HTTPS URL, or leave the URL field blank.</p>"; ?>
<?php if (isset($_GET['status']) && $_GET['status'] === 'validation' && ($_GET['field'] ?? '') === 'required') echo "<p class='error'>Please complete the required fields before saving.</p>"; ?>

<div id="vault-mobile-menu" class="vault-tabs">
    <button id="view-btn" class="tab-btn active" type="button" onclick="window.location.href='index.php?pane=view'">📋 Vault</button>
    <button id="docs-btn" class="tab-btn" type="button" onclick="window.location.href='documents.php'">📄 Docs</button>
    <button id="gallery-btn" class="tab-btn" type="button" onclick="window.location.href='gallery.php'">🖼️ Gallery</button>
    <button id="settings-btn" class="tab-btn" type="button" onclick="window.location.href='index.php?pane=settings'">⚙️ System</button>
    <form method="POST" class="vault-menu-lock-form"><input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf ?? '', ENT_QUOTES, 'UTF-8'); ?>"><input type="hidden" name="lock_vault" value="1"><button type="submit" class="tab-btn vault-menu-lock-button">🔒 Lock Vault</button></form>
    <button id="details-btn" class="tab-btn" style="display:none;" data-vault-tab="details">👁️ Entry Inspection</button>
</div>

<div id="view-panel" class="vault-panel <?php echo ($active_pane === 'view') ? 'active' : ''; ?>">
    <div class="vault-add-record-wrap">
        <a href="index.php?pane=add" class="btn btn-primary vault-add-record-button"><span class="vault-add-record-icon" aria-hidden="true">+</span> Add Vault Record</a>
    </div>
    <?php if (empty($passwords)): ?>
        <p style="text-align:center; padding:20px; color:#777;">Secure vault database is currently empty.</p>
    <?php else: ?>
        <div class="vault-grid" style="display:grid; grid-template-columns:repeat(auto-fill,minmax(240px,1fr)); gap:20px; margin-top:15px;">
            <?php foreach ($passwords as $row):
                $label = $row['label'] ?? 'Vault';
                $hash = md5($label);
                $hue1 = hexdec(substr($hash, 0, 2)) % 360;
                $hue2 = ($hue1 + 90) % 360;
                $cardGradient = "linear-gradient(to bottom right, hsla(214.47, 42.86%, 39.85%, 1) 4.62%, hsla(229.79, 18.54%, 43.26%, 0.7))";
                $words = explode(' ', trim(preg_replace('/[^a-zA-Z0-9 ]/', '', $label)));
                $initials = strtoupper(substr($words[0] ?? 'V', 0, 1) . (isset($words[1]) ? substr($words[1], 0, 1) : ''));
                $hasStoredIcon = !empty($row['icon_path']) && !empty($row['id']);
                $inspectArgs = [$label,$row['username'] ?? '',$row['password'] ?? '',$row['url'] ?? '',$row['notes'] ?? '',(string)($row['id'] ?? '')];
                $inspectJson = htmlspecialchars(json_encode($inspectArgs, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT), ENT_QUOTES, 'UTF-8');
            ?>
                <div class="entry-card" tabindex="0" role="button" aria-label="Inspect <?php echo htmlspecialchars($label); ?>" onclick='viewRecordDetails(<?php echo $inspectJson; ?>)' onkeydown='if(event.key === "Enter" || event.key === " "){event.preventDefault();viewRecordDetails(<?php echo $inspectJson; ?>)}' style="background:#fff; border:1px solid #e9ecef; border-radius:12px; overflow:hidden; display:flex; flex-direction:column; justify-content:space-between; box-shadow:0 4px 6px rgba(0,0,0,.02); position:relative;">
                    <div class="og-preview-holder" style="height:100px; background:<?php echo $cardGradient; ?>; display:flex; align-items:center; justify-content:center; overflow:hidden; position:relative;">
                        <?php if ($hasStoredIcon): ?><img src="vault-icon.php?id=<?php echo rawurlencode((string)$row['id']); ?>" style="width:36px;height:36px;object-fit:contain;position:relative;z-index:2;filter:drop-shadow(0 4px 6px rgba(0,0,0,.15));" alt="Stored website icon" onerror="this.style.display='none';this.nextElementSibling.style.display='inline-block';"><span style="display:none;color:#fff;font-size:28px;font-weight:700;font-family:monospace;opacity:.3;" aria-hidden="true"><?php echo htmlspecialchars($initials); ?></span>
                        <?php else: ?><span style="color:#fff;font-size:28px;font-weight:700;font-family:monospace;opacity:.3;" aria-hidden="true"><?php echo htmlspecialchars($initials); ?></span><?php endif; ?>
                    </div>
                    <div class="entry-card-identity" style="padding:12px 15px 4px;"><span class="entry-label" style="font-weight:600;font-size:14px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;display:block;color:#212529;"><?php echo htmlspecialchars($label); ?></span><small style="color:#868e96;font-size:11px;display:block;overflow:hidden;text-overflow:ellipsis;"><?php echo htmlspecialchars($row['username'] ?? '[No Username]'); ?></small></div>
                    <div class="entry-card-actions" style="padding:12px;"><button type="button" class="btn btn-primary inspect-button" style="width:100%;padding:8px 0;font-size:12px;border-radius:6px;background:#0066cc;">👁️ Inspect</button></div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>

<script>
(function () {
    function getMenu(){return document.getElementById('vault-mobile-menu');}
    function getToggle(){return document.querySelector('.vault-mobile-menu-toggle');}
    window.toggleVaultMobileMenu=function(){var menu=getMenu(),toggle=getToggle();if(!menu||!toggle)return;var open=menu.classList.toggle('mobile-open');toggle.setAttribute('aria-expanded',open?'true':'false');};
    function setupSystemOptions(){
        var panel=document.getElementById('settings-panel');
        if(!panel||panel.dataset.optionsReady==='1')return;
        var boxes=panel.querySelectorAll(':scope > .form-box');
        if(boxes.length<3)return;
        var params=new URLSearchParams(window.location.search);
        var application=params.get('tool')==='application';
        boxes[0].style.display=application?'block':'none';
        boxes[1].style.display='none';
        boxes[2].style.display='none';
        if(application){panel.dataset.optionsReady='1';return;}
        var options=document.createElement('div');options.className='form-box system-options-panel';options.style.marginTop='0';
        options.innerHTML='<h3>System Tools</h3>'+
            '<a href="index.php?pane=settings&tool=application" class="system-option-link" style="display:block;padding:16px;margin-top:12px;border:1px solid #e9ecef;border-radius:10px;text-decoration:none;color:#212529;background:#fff;"><strong>⚙️ Application Settings</strong><span style="display:block;margin-top:4px;color:#777;font-size:13px;">Manage the application username, 2FA email, HTTPS URL, vault data directory, and IMAP settings.</span></a>'+
            '<a href="gallery_settings.php" class="system-option-link" style="display:block;padding:16px;margin-top:12px;border:1px solid #e9ecef;border-radius:10px;text-decoration:none;color:#212529;background:#fff;"><strong>🖼️ Gallery Settings</strong><span style="display:block;margin-top:4px;color:#777;font-size:13px;">Control WebP quality, thumbnail quality, thumbnail size, and transparency handling.</span></a>'+
            '<a href="passkeys.php" class="system-option-link" style="display:block;padding:16px;margin-top:12px;border:1px solid #e9ecef;border-radius:10px;text-decoration:none;color:#212529;background:#fff;"><strong>🔑 Passkeys</strong><span style="display:block;margin-top:4px;color:#777;font-size:13px;">Manage the devices that can unlock your SentryIQ vault.</span></a>'+
            '<a href="system_log.php" class="system-option-link" style="display:block;padding:16px;margin-top:12px;border:1px solid #e9ecef;border-radius:10px;text-decoration:none;color:#212529;background:#fff;"><strong>🔐 System Log</strong><span style="display:block;margin-top:4px;color:#777;font-size:13px;">View security and authentication events recorded by SentryIQ.</span></a>';
        panel.appendChild(options);panel.dataset.optionsReady='1';
    }
    document.addEventListener('DOMContentLoaded',function(){
        var toggle=getToggle(),menu=getMenu();
        if(toggle&&menu){toggle.addEventListener('click',window.toggleVaultMobileMenu);menu.querySelectorAll('[data-vault-tab]').forEach(function(button){button.addEventListener('click',function(){var tab=button.getAttribute('data-vault-tab');if(typeof switchVaultTab==='function')switchVaultTab(tab);menu.classList.remove('mobile-open');toggle.setAttribute('aria-expanded','false');if(tab==='settings')setupSystemOptions();});});}
        setupSystemOptions();
    });
}());
</script>