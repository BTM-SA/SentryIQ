<!-- Location: /home/bicheveb/public_html/pm/dashboard_actions.php -->

<!-- PANEL SECTION 2: DETAIL INSPECTOR -->
<div id="details-panel" class="vault-panel">
    <div class="detail-card">
        <h3 id="det-title" style="margin-top:0; border-bottom:2px solid #f1f3f5; padding-bottom:8px; color:#0066cc;">Vault Record Profile</h3>

        <div style="display:flex; justify-content:center; margin:8px 0 18px;">
            <img id="det-icon" src="" alt="Stored website icon" style="display:none; width:72px; height:72px; object-fit:contain; border-radius:12px; padding:8px; background:#f8f9fa; border:1px solid #e9ecef;">
        </div>

        <div class="detail-row"><span class="detail-label">Resource Label:</span><span id="det-label" class="detail-value"></span></div>
        <div class="detail-row"><span class="detail-label">Stored Username / User ID:</span><span id="det-username" class="detail-value"></span></div>
        <div class="detail-row">
            <span class="detail-label">Encrypted Password String:</span>
            <span id="det-password" class="detail-value secret-badge" style="cursor:pointer;" title="Click to copy to clipboard" onclick="copyVaultString(this, this.textContent)"></span>
        </div>
        <div class="detail-row"><span class="detail-label">Destination Web URL Link:</span><a id="det-url" href="#" target="_blank" class="detail-value" style="color:#0066cc; text-decoration:underline;"></a></div>
        <div class="detail-row" style="align-items:flex-start;"><span class="detail-label">Notes:</span><span id="det-notes" class="detail-value" style="white-space:pre-wrap;"></span></div>
        <div style="margin-top:20px; display:flex; gap:10px;">
            <button type="button" class="btn" style="background:#6c757d; color:#fff;" onclick="switchVaultTab('view')">← Back to List</button>
            <button type="button" class="btn" style="background:#fcc419; color:#212529; font-weight:bold;" onclick="prepareVaultEditNode()">✏️ Edit Record</button>
            <form method="POST" onsubmit="return confirm('Are you sure you want to delete this record completely out of your secure database?');" style="display:inline;">
                <input type="hidden" name="entry_id" id="det-delete-id" value="">
                <button type="submit" name="delete_entry" class="btn btn-danger">🗑️ Delete</button>
            </form>
        </div>
    </div>
</div>

<!-- PANEL SECTION 3: ADD NEW RECORD PANEL -->
<div id="add-panel" class="vault-panel <?php echo ($active_pane === 'add') ? 'active' : ''; ?>">
    <div class="form-box">
        <h3>Create New Vault Entry</h3>
        <form method="POST">
            <div class="form-group"><label>Resource Label:</label><input type="text" name="label" placeholder="e.g. Personal Email" class="input-field" required></div>
            <div class="form-group"><label>Username / Login ID:</label><input type="text" name="username" class="input-field"></div>
            <div class="form-group"><label>Resource Password:</label><input type="text" name="password" class="input-field" required></div>
            <div class="form-group"><label>Destination Web URL Address:</label><input type="text" name="url" placeholder="https://..." class="input-field"></div>
            <div class="form-group"><label>Notes:</label><textarea name="notes" class="input-field" rows="5" placeholder="Optional notes about this resource"></textarea></div>
            <button type="submit" name="add_entry" class="btn btn-primary" style="margin-top: 10px;">💾 Save Secure Entry</button>
        </form>
    </div>
</div>

<!-- PANEL SECTION 4: SYSTEM CONFIGURATION PANEL -->
<div id="settings-panel" class="vault-panel <?php echo ($active_pane === 'settings') ? 'active' : ''; ?>">
    <div class="form-box">
        <h3>⚙️ System Configuration Node</h3>
        <form method="POST">
            <div class="form-group"><label>Application Username:</label><input type="text" name="app_username" class="input-field" value="<?php echo htmlspecialchars($sys_user); ?>" required></div>
            <div class="form-group"><label>2FA Target Email Delivery Address:</label><input type="email" name="two_fa_email_field" class="input-field" value="<?php echo htmlspecialchars($sys_email); ?>" required></div>
            <div class="form-group"><label>IMAP Password (Optional Update):</label><input type="password" name="imap_password_field" class="input-field" placeholder="Leave blank to preserve current settings"></div>
            <button type="submit" name="save_vault_settings" class="btn btn-primary" style="margin-top: 10px;">🔄 Update Configuration</button>
        </form>
    </div>
</div>

<!-- PANEL SECTION 5: NEW EDIT RECORD WORKSPACE PANEL -->
<div id="edit-panel" class="vault-panel">
    <div class="form-box">
        <h3>📝 Modify Vault Entry Data</h3>
        <form method="POST">
            <input type="hidden" name="entry_id" id="edit-entry-id">
            <div class="form-group"><label>Resource Label:</label><input type="text" name="label" id="edit-label" class="input-field" required></div>
            <div class="form-group"><label>Username / Login ID:</label><input type="text" name="username" id="edit-username" class="input-field"></div>
            <div class="form-group"><label>Resource Password:</label><input type="text" name="password" id="edit-password" class="input-field" required></div>
            <div class="form-group"><label>Destination Web URL Address:</label><input type="text" name="url" id="edit-url" class="input-field"></div>
            <div class="form-group"><label>Notes:</label><textarea name="notes" id="edit-notes" class="input-field" rows="5" placeholder="Optional notes about this resource"></textarea></div>
            <div style="margin-top: 15px; display:flex; gap:10px;">
                <button type="submit" name="edit_entry" class="btn btn-primary">🔄 Update Record</button>
                <button type="button" class="btn" style="background:#6c757d; color:#fff;" onclick="switchVaultTab('details')">Cancel</button>
            </div>
        </form>
    </div>
</div>

<!-- NATIVE ASYNCHRONOUS CLIPBOARD MANAGEMENT ENGINE -->
<script>
function prepareVaultEditNode() {
    document.getElementById('edit-entry-id').value = document.getElementById('det-delete-id').value;
    document.getElementById('edit-label').value = document.getElementById('det-label').textContent;

    const unparsedUser = document.getElementById('det-username').textContent;
    document.getElementById('edit-username').value = (unparsedUser === '[None Stored]') ? '' : unparsedUser;

    document.getElementById('edit-password').value = document.getElementById('det-password').textContent;

    const urlReference = document.getElementById('det-url');
    document.getElementById('edit-url').value = urlReference.hasAttribute('href') ? urlReference.href : '';
    document.getElementById('edit-notes').value = document.getElementById('det-notes').textContent;

    switchVaultTab('edit');
}

function copyVaultString(buttonElement, targetSecretText) {
    if (!navigator.clipboard) {
        alert("Clipboard API unsupported by your active browser connection environment.");
        return;
    }

    navigator.clipboard.writeText(targetSecretText)
        .then(() => {
            const isButton = buttonElement.tagName.toLowerCase() === 'button';
            const defaultButtonTextHtml = buttonElement.innerHTML;
            if (isButton) {
                buttonElement.innerHTML = "✅ Copied!";
                buttonElement.style.background = "#2b8a3e";
                buttonElement.style.color = "#ffffff";
                buttonElement.style.borderColor = "#2b8a3e";
                setTimeout(() => {
                    buttonElement.innerHTML = defaultButtonTextHtml;
                    buttonElement.style.background = "#e3faf2";
                    buttonElement.style.color = "#0ca678";
                    buttonElement.style.borderColor = "#c3fae8";
                }, 1500);
            } else {
                const defaultBg = buttonElement.style.background;
                buttonElement.style.background = "#2b8a3e";
                setTimeout(() => { buttonElement.style.background = defaultBg; }, 800);
            }
        })
        .catch(err => {
            console.error("Secure data copy operational failure:", err);
            alert("Copy transaction aborted by browser security profile restrictions.");
        });
}

window.addEventListener('DOMContentLoaded', () => {
    switchVaultTab('<?php echo $active_pane; ?>');
});
</script>
