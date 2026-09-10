<?php

declare(strict_types=1);

require_once __DIR__ . '/security_bootstrap.php';
sentryiq_security_bootstrap();
sentryiq_require_auth();

$dataDir = sentryiq_data_dir();
if ($dataDir !== '' && is_file($dataDir . '/vault_engine.php') && !is_link($dataDir . '/vault_engine.php')) {
    require_once $dataDir . '/vault_engine.php';
}

$events = function_exists('read_security_log') ? read_security_log() : [];
$csrf = sentryiq_csrf_token();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="csrf-token" content="<?php echo htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8'); ?>">
<title>SentryIQ — System Log</title>
<link rel="stylesheet" href="pm_style.css">
<style>
.system-page-header{display:flex;align-items:center;justify-content:space-between;gap:16px;flex-wrap:wrap}.system-page-actions{display:flex;align-items:center;gap:10px}.system-mobile-menu{display:none}.system-log-table{overflow-x:auto}.system-log-list{display:none}
@media (max-width:600px){.system-page-header{align-items:center;flex-wrap:nowrap}.system-page-header .sentryiq-vault-brand-wrap{min-width:0;flex:1}.system-page-header .sentryiq-vault-banner{max-width:190px}.system-page-actions .system-back{display:none}.system-mobile-menu{display:block}.system-mobile-menu-toggle{border:0;border-radius:8px;padding:9px 12px;background:#0066cc;color:#fff;font-size:14px;font-weight:600;cursor:pointer}.system-mobile-menu-panel{display:none;margin-top:10px;padding:8px;border:1px solid #e9ecef;border-radius:10px;background:#fff;box-shadow:0 4px 12px rgba(0,0,0,.06)}.system-mobile-menu-panel.open{display:block}.system-mobile-menu-panel a,.system-mobile-menu-panel button{display:block;width:100%;box-sizing:border-box;padding:11px 12px;margin:2px 0;border:0;border-radius:7px;background:transparent;color:#212529;text-align:left;text-decoration:none;font:inherit;cursor:pointer}.system-mobile-menu-panel a:hover,.system-mobile-menu-panel button:hover{background:#f1f3f5}.system-log-table{display:none}.system-log-list{display:block}.security-log-card{border:1px solid #e9ecef;border-radius:10px;padding:12px;margin-bottom:10px;background:#fff}.security-log-card-header{display:flex;justify-content:space-between;gap:10px;align-items:flex-start;margin-bottom:10px}.security-log-event{font-weight:700;word-break:break-word}.security-log-time{font-size:11px;color:#777;text-align:right;white-space:nowrap}.security-log-field{display:flex;gap:10px;padding:6px 0;border-top:1px solid #f1f3f5}.security-log-label{width:82px;flex:0 0 82px;font-size:11px;color:#777}.security-log-value{font-size:12px;word-break:break-word}}
</style>
</head>
<body>
<div class="box">
    <div class="system-page-header">
        <div class="sentryiq-vault-brand-wrap">
            <img class="sentryiq-vault-banner" src="sentryiq-logo-wide.webp" width="1952" height="588" alt="SentryIQ">
            <span class="sentryiq-vault-status">System Log</span>
        </div>
        <div class="system-page-actions">
            <a href="index.php" class="btn system-back" style="text-decoration:none;">Back to Vault</a>
            <div class="system-mobile-menu">
                <button type="button" class="system-mobile-menu-toggle" id="system-menu-toggle" aria-expanded="false" aria-controls="system-menu-panel">☰ Menu</button>
            </div>
        </div>
    </div>
    <div id="system-menu-panel" class="system-mobile-menu-panel">
        <a href="index.php">📋 View Stored Entries</a>
        <a href="gallery.php">🖼️ Gallery</a>
        <a href="passkeys.php">🔑 Passkeys</a>
        <a href="index.php?pane=settings">⚙️ System</a>
        <form method="POST" style="margin:0;">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8'); ?>">
            <input type="hidden" name="lock_vault" value="1">
            <button type="submit">🔒 Lock Vault</button>
        </form>
    </div>

    <div style="max-width:900px;margin:20px auto;">
        <div style="display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap;">
            <div>
                <h2 style="margin-bottom:6px;">🔐 System Log</h2>
                <p style="margin-top:0;color:#666;">Security and authentication events recorded by SentryIQ.</p>
            </div>
            <a href="index.php?pane=settings" class="btn" style="text-decoration:none;">Back to System</a>
        </div>

        <div class="form-box" style="margin-top:20px;">
            <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:15px;gap:10px;flex-wrap:wrap;">
                <h3 style="margin:0;">Security Events</h3>
                <span style="font-size:12px;color:#777;"><?php echo count($events); ?> events</span>
            </div>
            <?php if (empty($events)): ?>
                <p style="text-align:center;padding:20px;color:#777;">No security events have been recorded yet.</p>
            <?php else: ?>
                <div class="system-log-table">
                    <table style="width:100%;border-collapse:collapse;font-size:12px;">
                        <thead><tr style="background:#e9ecef;text-align:left;"><th style="padding:9px;">Date / Time</th><th style="padding:9px;">Event</th><th style="padding:9px;">User</th><th style="padding:9px;">IP Address</th><th style="padding:9px;">User Agent</th></tr></thead>
                        <tbody><?php foreach ($events as $event): ?><tr style="border-bottom:1px solid #eee;"><td style="padding:9px;white-space:nowrap;"><?php echo htmlspecialchars((string)($event['timestamp'] ?? '')); ?></td><td style="padding:9px;font-weight:bold;"><?php echo htmlspecialchars((string)($event['event'] ?? '')); ?></td><td style="padding:9px;"><?php echo htmlspecialchars((string)($event['username'] ?? 'unknown')); ?></td><td style="padding:9px;"><?php echo htmlspecialchars((string)($event['ip'] ?? 'unknown')); ?></td><td style="padding:9px;max-width:240px;word-break:break-word;color:#666;"><?php echo htmlspecialchars((string)($event['user_agent'] ?? 'unknown')); ?></td></tr><?php endforeach; ?></tbody>
                    </table>
                </div>
                <div class="system-log-list"><?php foreach ($events as $event): ?><div class="security-log-card"><div class="security-log-card-header"><span class="security-log-event"><?php echo htmlspecialchars((string)($event['event'] ?? '')); ?></span><span class="security-log-time"><?php echo htmlspecialchars((string)($event['timestamp'] ?? '')); ?></span></div><div class="security-log-field"><span class="security-log-label">User</span><span class="security-log-value"><?php echo htmlspecialchars((string)($event['username'] ?? 'unknown')); ?></span></div><div class="security-log-field"><span class="security-log-label">IP Address</span><span class="security-log-value"><?php echo htmlspecialchars((string)($event['ip'] ?? 'unknown')); ?></span></div><div class="security-log-field"><span class="security-log-label">User Agent</span><span class="security-log-value"><?php echo htmlspecialchars((string)($event['user_agent'] ?? 'unknown')); ?></span></div></div><?php endforeach; ?></div>
            <?php endif; ?>
        </div>
    </div>
</div>
<script>
(function(){var toggle=document.getElementById('system-menu-toggle');var panel=document.getElementById('system-menu-panel');if(!toggle||!panel)return;toggle.addEventListener('click',function(){var open=panel.classList.toggle('open');toggle.setAttribute('aria-expanded',open?'true':'false');});}());
</script>
</body>
</html>
