<?php
$passwords = normalize_vault_records($passwords ?? []);
$passwords = array_values(array_filter($passwords, static fn(array $row): bool => ($row['type'] ?? '') !== 'system_config'));
?>
<!-- Location: /home/bicheveb/public_html/pm/dashboard_list.php -->
<div style="display:flex; justify-content:space-between; align-items:center; gap:12px; flex-wrap:wrap;">
    <h2>🛡️ SentryIQ</h2>
    <a href="?action=logout" class="btn btn-danger" style="background:#6c757d; text-decoration:none;">Lock Vault</a>
</div>

<?php if (isset($_GET['status']) && $_GET['status'] == 'saved') echo "<p class='success'>Entry stored successfully!</p>"; ?>
<?php if (isset($_GET['status']) && $_GET['status'] == 'updated') echo "<p class='success'>Entry updated successfully!</p>"; ?>
<?php if (isset($_GET['status']) && $_GET['status'] == 'deleted') echo "<p class='success'>Entry deleted safely from disk.</p>"; ?>
<?php if (isset($_GET['status']) && $_GET['status'] == 'error') echo "<p class='error'>The requested vault record operation could not be completed.</p>"; ?>
<?php if (isset($_GET['status']) && $_GET['status'] === 'validation' && ($_GET['field'] ?? '') === 'url') echo "<p class='error'>Please enter a valid HTTPS URL, or leave the URL field blank.</p>"; ?>
<?php if (isset($_GET['status']) && $_GET['status'] === 'validation' && ($_GET['field'] ?? '') === 'required') echo "<p class='error'>Please complete the required fields before saving.</p>"; ?>

<div class="vault-mobile-menu-bar">
    <button type="button" class="vault-mobile-menu-toggle" onclick="toggleVaultMobileMenu()" aria-expanded="false" aria-controls="vault-mobile-menu">
        <span class="vault-mobile-menu-icon" aria-hidden="true">☰</span>
        <span id="vault-mobile-menu-label">SentryIQ Menu</span>
    </button>
</div>

<div id="vault-mobile-menu" class="vault-tabs">
    <button id="view-btn" class="tab-btn <?php echo ($active_pane === 'view') ? 'active' : ''; ?>" onclick="switchVaultTab('view')">📋 View Stored Entries</button>
    <button id="add-btn" class="tab-btn <?php echo ($active_pane === 'add') ? 'active' : ''; ?>" onclick="switchVaultTab('add')">➕ Add New Entry</button>
    <button id="settings-btn" class="tab-btn <?php echo ($active_pane === 'settings') ? 'active' : ''; ?>" onclick="switchVaultTab('settings')">⚙️ System</button>
    <button id="log-btn" class="tab-btn <?php echo ($active_pane === 'log') ? 'active' : ''; ?>" onclick="switchVaultTab('log')">🔐 Log</button>
    <button id="details-btn" class="tab-btn" style="display:none;" onclick="switchVaultTab('details')">👁️ Entry Inspection</button>
</div>

<div id="view-panel" class="vault-panel <?php echo ($active_pane === 'view') ? 'active' : ''; ?>">
    <?php if (empty($passwords)): ?>
        <p style="text-align:center; padding:20px; color:#777;">Secure vault database is currently empty.</p>
    <?php else: ?>
        <div class="vault-grid" style="display:grid; grid-template-columns:repeat(auto-fill,minmax(240px,1fr)); gap:20px; margin-top:15px;">
            <?php foreach ($passwords as $row):
                $label = $row['label'] ?? 'Vault';
                $hash = md5($label);
                $hue1 = hexdec(substr($hash, 0, 2)) % 360;
                $hue2 = ($hue1 + 90) % 360;
                $cardGradient = "linear-gradient(135deg, hsl({$hue1}, 60%, 40%) 0%, hsl({$hue2}, 65%, 25%) 100%)";
                $words = explode(' ', trim(preg_replace('/[^a-zA-Z0-9 ]/', '', $label)));
                $initials = strtoupper(substr($words[0] ?? 'V', 0, 1) . (isset($words[1]) ? substr($words[1], 0, 1) : ''));
                $hasStoredIcon = !empty($row['icon_path']) && !empty($row['id']);
                $inspectArgs = [$label,$row['username'] ?? '',$row['password'] ?? '',$row['url'] ?? '',$row['notes'] ?? '',(string)($row['id'] ?? '')];
                $inspectJson = htmlspecialchars(json_encode($inspectArgs, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT), ENT_QUOTES, 'UTF-8');
            ?>
                <div class="entry-card" tabindex="0" role="button" aria-label="Inspect <?php echo htmlspecialchars($label); ?>" onclick='viewRecordDetails(<?php echo $inspectJson; ?>)' onkeydown='if(event.key === "Enter" || event.key === " "){event.preventDefault();viewRecordDetails(<?php echo $inspectJson; ?>)}' style="background:#fff; border:1px solid #e9ecef; border-radius:12px; overflow:hidden; display:flex; flex-direction:column; justify-content:space-between; box-shadow:0 4px 6px rgba(0,0,0,.02); position:relative;">
                    <div class="og-preview-holder" style="height:100px; background:<?php echo $cardGradient; ?>; display:flex; align-items:center; justify-content:center; overflow:hidden; position:relative;">
                        <?php if ($hasStoredIcon): ?><img src="vault-icon.php?id=<?php echo rawurlencode((string)$row['id']); ?>" style="width:36px;height:36px;object-fit:contain;position:relative;z-index:2;filter:drop-shadow(0 4px 6px rgba(0,0,0,.15));" alt="Stored website icon" onerror="this.style.display='none';"><?php endif; ?>
                        <span style="position:absolute;color:#fff;font-size:28px;font-weight:700;font-family:monospace;opacity:.3;z-index:1;"><?php echo htmlspecialchars($initials); ?></span>
                    </div>
                    <div style="padding:12px 15px 4px;"><span class="entry-label" style="font-weight:600;font-size:14px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;display:block;color:#212529;"><?php echo htmlspecialchars($label); ?></span><small style="color:#868e96;font-size:11px;display:block;overflow:hidden;text-overflow:ellipsis;"><?php echo htmlspecialchars($row['username'] ?? '[No Username]'); ?></small></div>
                    <div style="padding:12px;"><button type="button" class="btn btn-primary inspect-button" style="width:100%;padding:8px 0;font-size:12px;border-radius:6px;background:#0066cc;" onclick='event.stopPropagation();viewRecordDetails(<?php echo $inspectJson; ?>)'>👁️ Inspect</button></div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>