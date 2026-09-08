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
</head>
<body>
<div class="box">
    <img class="sentryiq-brand-banner" src="sentryiq-logo-wide.webp" width="1952" height="588" alt="SentryIQ" fetchpriority="high">
    <div style="max-width:620px;margin:20px auto;">
        <div style="display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap;">
            <div>
                <h2 style="margin-bottom:6px;">🔑 Passkeys</h2>
                <p style="margin-top:0;color:#666;">Manage the devices that can unlock your SentryIQ vault with Face ID, Touch ID, or passkey authentication.</p>
            </div>
            <a href="index.php" class="btn" style="text-decoration:none;">Back to Vault</a>
        </div>

        <div id="passkey-message" style="display:none;"></div>

        <div style="margin:20px 0;">
            <?php if (empty($credentials)): ?>
                <div style="padding:18px;border:1px solid #e9ecef;border-radius:10px;text-align:center;color:#666;">No passkeys are registered yet.</div>
            <?php else: ?>
                <?php foreach ($credentials as $index => $credential):
                    $created = (int)($credential['created_at'] ?? 0);
                    $label = 'Passkey ' . ($index + 1);
                    $id = (string)($credential['credential_id'] ?? '');
                ?>
                    <div style="border:1px solid #e9ecef;border-radius:10px;padding:16px;margin-bottom:12px;display:flex;justify-content:space-between;align-items:center;gap:16px;flex-wrap:wrap;">
                        <div style="min-width:0;">
                            <strong><?php echo htmlspecialchars($label, ENT_QUOTES, 'UTF-8'); ?></strong>
                            <div style="font-size:13px;color:#777;margin-top:4px;">Added <?php echo $created > 0 ? htmlspecialchars(date('j M Y, H:i', $created), ENT_QUOTES, 'UTF-8') : 'date unavailable'; ?></div>
                            <div style="font-size:11px;color:#aaa;margin-top:3px;overflow:hidden;text-overflow:ellipsis;max-width:430px;">Credential: <?php echo htmlspecialchars(substr($id, 0, 18) . (strlen($id) > 18 ? '…' : ''), ENT_QUOTES, 'UTF-8'); ?></div>
                        </div>
                        <button type="button" class="btn btn-primary remove-passkey" data-credential="<?php echo htmlspecialchars($id, ENT_QUOTES, 'UTF-8'); ?>" <?php echo count($credentials) <= 1 ? 'disabled title="Keep at least one passkey registered"' : ''; ?>>Remove</button>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>

        <div style="padding:18px;border-radius:10px;background:#f8f9fa;border:1px solid #e9ecef;">
            <h3 style="margin-top:0;">Add another device</h3>
            <p style="font-size:14px;color:#666;">Register another passkey on your phone, Mac, tablet, or another trusted device. Each passkey gets its own protected copy of your existing vault key.</p>
            <a href="passkey_setup.php?add=1" class="btn btn-primary" style="display:inline-block;text-decoration:none;">＋ Add Another Passkey</a>
        </div>

        <p style="font-size:13px;color:#777;margin-top:18px;">SentryIQ will not let you remove the last registered passkey. If you lose access to a device, use the password recovery route to regain access and register a replacement passkey.</p>
    </div>
</div>
<script>
(function () {
    var csrf = document.querySelector('meta[name="csrf-token"]').content;
    var message = document.getElementById('passkey-message');
    function showMessage(text, ok) {
        message.textContent = text;
        message.className = ok ? 'success' : 'error';
        message.style.display = 'block';
    }
    document.querySelectorAll('.remove-passkey').forEach(function (button) {
        button.addEventListener('click', async function () {
            if (button.disabled) return;
            if (!window.confirm('Remove this passkey? This device will no longer be able to unlock SentryIQ.')) return;
            button.disabled = true;
            try {
                var response = await fetch('passkey_auth.php', {
                    method: 'POST', credentials: 'same-origin',
                    headers: {'Content-Type': 'application/json'},
                    body: JSON.stringify({action:'delete', csrf_token:csrf, credential_id:button.dataset.credential})
                });
                var result = await response.json();
                if (!response.ok || result.status !== 'ok') throw new Error(result.message || 'Unable to remove the passkey.');
                window.location.reload();
            } catch (error) {
                showMessage(error && error.message ? error.message : 'Unable to remove the passkey.', false);
                button.disabled = false;
            }
        });
    });
}());
</script>
</body>
</html>
