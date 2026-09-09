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
</head>
<body>
<div class="box">
    <img class="sentryiq-brand-banner" src="sentryiq-logo-wide.webp" width="1952" height="588" alt="SentryIQ" fetchpriority="high">
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
                <div class="security-log-table" style="overflow-x:auto;">
                    <table style="width:100%;border-collapse:collapse;font-size:12px;">
                        <thead><tr style="background:#e9ecef;text-align:left;"><th style="padding:9px;">Date / Time</th><th style="padding:9px;">Event</th><th style="padding:9px;">User</th><th style="padding:9px;">IP Address</th><th style="padding:9px;">User Agent</th></tr></thead>
                        <tbody><?php foreach ($events as $event): ?><tr style="border-bottom:1px solid #eee;"><td style="padding:9px;white-space:nowrap;"><?php echo htmlspecialchars((string)($event['timestamp'] ?? '')); ?></td><td style="padding:9px;font-weight:bold;"><?php echo htmlspecialchars((string)($event['event'] ?? '')); ?></td><td style="padding:9px;"><?php echo htmlspecialchars((string)($event['username'] ?? 'unknown')); ?></td><td style="padding:9px;"><?php echo htmlspecialchars((string)($event['ip'] ?? 'unknown')); ?></td><td style="padding:9px;max-width:240px;word-break:break-word;color:#666;"><?php echo htmlspecialchars((string)($event['user_agent'] ?? 'unknown')); ?></td></tr><?php endforeach; ?></tbody>
                    </table>
                </div>
                <div class="security-log-list"><?php foreach ($events as $event): ?><div class="security-log-card"><div class="security-log-card-header"><span class="security-log-event"><?php echo htmlspecialchars((string)($event['event'] ?? '')); ?></span><span class="security-log-time"><?php echo htmlspecialchars((string)($event['timestamp'] ?? '')); ?></span></div><div class="security-log-field"><span class="security-log-label">User</span><span class="security-log-value"><?php echo htmlspecialchars((string)($event['username'] ?? 'unknown')); ?></span></div><div class="security-log-field"><span class="security-log-label">IP Address</span><span class="security-log-value"><?php echo htmlspecialchars((string)($event['ip'] ?? 'unknown')); ?></span></div><div class="security-log-field"><span class="security-log-label">User Agent</span><span class="security-log-value"><?php echo htmlspecialchars((string)($event['user_agent'] ?? 'unknown')); ?></span></div></div><?php endforeach; ?></div>
            <?php endif; ?>
        </div>
    </div>
</div>
</body>
</html>
