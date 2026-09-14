<?php
$passwords = normalize_vault_records($passwords ?? []);
$vaultCategories = [];
foreach ($passwords as $vaultConfigRow) {
    if (($vaultConfigRow['type'] ?? '') === 'system_config' && is_array($vaultConfigRow['categories'] ?? null)) {
        $vaultCategories = array_values(array_unique(array_filter(array_map(static fn($value): string => trim((string)$value), $vaultConfigRow['categories']), static fn(string $value): bool => $value !== '')));
        break;
    }
}
$passwords = array_values(array_filter($passwords, static fn(array $row): bool => ($row['type'] ?? '') !== 'system_config'));
$activeVaultView = trim((string)($_GET['vault_view'] ?? 'records'));
if ($activeVaultView !== 'records' && !in_array($activeVaultView, $vaultCategories, true)) $activeVaultView = 'records';
?>
<!-- Location: /home/bicheveb/public_html/pm/dashboard_list.php -->
<div class="sentryiq-page-header">
    <div class="sentryiq-vault-brand-wrap"><img class="sentryiq-vault-banner" src="sentryiq-logo-wide.webp" width="1952" height="588" alt="SentryIQ"><span class="sentryiq-vault-status" data-vault-status><?php echo htmlspecialchars($activeVaultView === 'records' ? 'Records' : $activeVaultView, ENT_QUOTES, 'UTF-8'); ?></span></div>
    <div class="sentryiq-mobile-header-actions">
        <form method="POST" class="sentryiq-lock-form"><input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf ?? '', ENT_QUOTES, 'UTF-8'); ?>"><input type="hidden" name="lock_vault" value="1"><button type="submit" class="btn btn-primary sentryiq-lock-button">Lock Vault</button></form>
        <div class="vault-mobile-menu-bar"><button type="button" class="vault-mobile-menu-toggle" aria-expanded="false" aria-controls="vault-mobile-menu"><span class="vault-mobile-menu-icon" aria-hidden="true">☰</span><span id="vault-mobile-menu-label" class="visually-hidden">Menu</span></button></div>
    </div>
</div>

<?php if (isset($_GET['status']) && $_GET['status'] == 'saved') echo "<p class='success'>Entry stored successfully!</p>"; ?>
<?php if (isset($_GET['status']) && $_GET['status'] == 'updated') echo "<p class='success'>Entry updated successfully!</p>"; ?>
<?php if (isset($_GET['status']) && $_GET['status'] == 'deleted') echo "<p class='success'>Entry deleted safely from disk.</p>"; ?>
<?php if (isset($_GET['status']) && $_GET['status'] == 'category_added') echo "<p class='success'>Category added successfully.</p>"; ?>
<?php if (isset($_GET['status']) && $_GET['status'] == 'error') echo "<p class='error'>The requested vault operation could not be completed.</p>"; ?>
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

<?php if ($active_pane === 'view'): ?>
<div id="view-panel" class="vault-panel active">
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(190px,1fr));gap:12px;margin-top:8px;">
        <a href="index.php?pane=records" class="btn btn-primary" style="display:flex;align-items:center;justify-content:center;min-height:58px;text-decoration:none;font-size:16px;">📋 Records</a>
        <?php foreach ($vaultCategories as $category): ?>
            <a href="index.php?pane=records&amp;vault_view=<?php echo rawurlencode($category); ?>" class="btn" style="display:flex;align-items:center;justify-content:center;min-height:58px;text-decoration:none;background:#f1f3f5;color:#212529;border:1px solid #dee2e6;font-size:16px;">📁 <?php echo htmlspecialchars($category, ENT_QUOTES, 'UTF-8'); ?></a>
        <?php endforeach; ?>
        <div class="vault-add-category-wrap" style="display:flex;align-items:center;min-height:58px;">
            <button type="button" id="show-add-category" class="btn" onclick="showAddCategoryForm()" style="width:100%;min-height:42px;background:#f1f3f5;color:#212529;border:1px solid #dee2e6;font-size:16px;">＋ Add Category</button>
            <form id="add-category-form" method="POST" action="vault_category_actions.php" style="display:none;width:100%;align-items:center;gap:8px;margin:0;">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf ?? '', ENT_QUOTES, 'UTF-8'); ?>">
                <input type="hidden" name="action" value="add_category">
                <input type="text" id="new-category-name" name="category" class="input-field" placeholder="Category name" maxlength="50" required style="min-width:0;flex:1;margin:0;">
                <button type="submit" class="btn" style="height:42px;white-space:nowrap;background:#f1f3f5;color:#212529;border:1px solid #dee2e6;font-size:16px;">Add</button>
                <button type="button" class="btn" onclick="hideAddCategoryForm()" style="height:42px;white-space:nowrap;background:#fff;color:#495057;border:1px solid #dee2e6;font-size:16px;">Cancel</button>
            </form>
        </div>
    </div>
</div>
<?php endif; ?>

<?php if ($active_pane === 'records'): ?>
<div id="records-panel" class="vault-panel active" data-vault-title="<?php echo htmlspecialchars($activeVaultView === 'records' ? 'Records' : $activeVaultView, ENT_QUOTES, 'UTF-8'); ?>">
    <div style="display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap;margin-bottom:16px;">
        <a href="index.php?pane=view" class="btn" style="background:#f1f3f5;color:#212529;border:1px solid #dee2e6;text-decoration:none;">← Vault</a>
        <h3 style="margin:0;color:#212529;">📁 <?php echo htmlspecialchars($activeVaultView === 'records' ? 'Records' : $activeVaultView, ENT_QUOTES, 'UTF-8'); ?></h3>
        <a href="index.php?pane=add&amp;vault_view=<?php echo rawurlencode($activeVaultView); ?>" class="btn btn-primary vault-add-record-button" style="text-decoration:none;"><span class="vault-add-record-icon" aria-hidden="true">+</span> Add Vault Record</a>
    </div>
    <?php $visiblePasswords = $activeVaultView === 'records' ? $passwords : array_values(array_filter($passwords, static fn(array $row): bool => trim((string)($row['category'] ?? '')) === $activeVaultView)); ?>
    <?php if (empty($visiblePasswords)): ?>
        <p id="vault-empty-message" style="text-align:center;padding:20px;color:#777;"><?php echo $activeVaultView === 'records' ? 'Secure vault database is currently empty.' : 'No records in this category yet.'; ?></p>
    <?php else: ?>
        <div id="vault-record-grid" class="vault-grid" style="display:grid;grid-template-columns:repeat(auto-fill,minmax(240px,1fr));gap:20px;margin-top:15px;">
            <?php foreach ($visiblePasswords as $row):
                $label = $row['label'] ?? 'Vault';
                $category = trim((string)($row['category'] ?? ''));
                $hash = md5($label);
                $cardGradient = "linear-gradient(to bottom right, hsla(214.47, 42.86%, 39.85%, 1) 4.62%, hsla(229.79, 18.54%, 43.26%, 0.7))";
                $words = explode(' ', trim(preg_replace('/[^a-zA-Z0-9 ]/', '', $label)));
                $initials = strtoupper(substr($words[0] ?? 'V', 0, 1) . (isset($words[1]) ? substr($words[1], 0, 1) : ''));
                $hasStoredIcon = !empty($row['icon_path']) && !empty($row['id']);
                $inspectArgs = [$label,$row['username'] ?? '',$row['password'] ?? '',$row['url'] ?? '',$row['notes'] ?? '',(string)($row['id'] ?? ''),$category];
                $inspectJson = htmlspecialchars(json_encode($inspectArgs, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT), ENT_QUOTES, 'UTF-8');
            ?>
                <div class="entry-card vault-record-card" data-vault-category="<?php echo htmlspecialchars($category, ENT_QUOTES, 'UTF-8'); ?>" tabindex="0" role="button" aria-label="Inspect <?php echo htmlspecialchars($label); ?>" onclick='viewRecordDetails(<?php echo $inspectJson; ?>)' onkeydown='if(event.key === "Enter" || event.key === " "){event.preventDefault();viewRecordDetails(<?php echo $inspectJson; ?>)}' style="background:#fff;border:1px solid #e9ecef;border-radius:12px;overflow:hidden;display:flex;flex-direction:column;justify-content:space-between;box-shadow:0 4px 6px rgba(0,0,0,.02);position:relative;">
                    <div class="og-preview-holder" style="height:100px;background:<?php echo $cardGradient; ?>;display:flex;align-items:center;justify-content:center;overflow:hidden;position:relative;">
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
<?php endif; ?>

<script>
(function () {
    function getMenu(){return document.getElementById('vault-mobile-menu');}
    function getToggle(){return document.querySelector('.vault-mobile-menu-toggle');}
    window.toggleVaultMobileMenu=function(){var menu=getMenu(),toggle=getToggle();if(!menu||!toggle)return;var open=menu.classList.toggle('mobile-open');toggle.setAttribute('aria-expanded',open?'true':'false');};
    window.showAddCategoryForm=function(){
        var button=document.getElementById('show-add-category');
        var form=document.getElementById('add-category-form');
        var input=document.getElementById('new-category-name');
        if(!button||!form)return;
        button.style.display='none';
        form.style.display='flex';
        if(input){input.focus();}
    };
    window.hideAddCategoryForm=function(){
        var button=document.getElementById('show-add-category');
        var form=document.getElementById('add-category-form');
        var input=document.getElementById('new-category-name');
        if(!button||!form)return;
        form.style.display='none';
        button.style.display='flex';
        if(input){input.value='';}
    };
    document.addEventListener('DOMContentLoaded',function(){
        var toggle=getToggle(),menu=getMenu();
        if(toggle&&menu){toggle.addEventListener('click',window.toggleVaultMobileMenu);menu.querySelectorAll('[data-vault-tab]').forEach(function(button){button.addEventListener('click',function(){var tab=button.getAttribute('data-vault-tab');if(typeof switchVaultTab==='function')switchVaultTab(tab);menu.classList.remove('mobile-open');toggle.setAttribute('aria-expanded','false');});});}
    });
}());
</script>