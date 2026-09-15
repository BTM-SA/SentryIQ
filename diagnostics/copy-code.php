<?php

declare(strict_types=1);

require_once __DIR__ . '/security_bootstrap.php';
sentryiq_security_bootstrap();

header('Content-Type: text/html; charset=UTF-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Referrer-Policy: no-referrer');

$inboundToken = (string)($_GET['t'] ?? '');
if (!preg_match('/^[a-f0-9]{64}$/i', $inboundToken)) {
    http_response_code(404);
    exit('Token unavailable.');
}

$configFile = __DIR__ . '/sentryiq_config.php';
$config = is_file($configFile) ? require $configFile : [];
$dataDir = is_array($config) ? rtrim((string)($config['data_dir'] ?? ''), '/') : '';
if ($dataDir === '' || !is_dir($dataDir)) {
    http_response_code(404);
    exit('Token unavailable.');
}

$tokenFile = $dataDir . '/token_' . strtolower($inboundToken) . '.json';
if (!is_file($tokenFile) || is_link($tokenFile)) {
    http_response_code(404);
    exit('Token unavailable.');
}

$claimedFile = $dataDir . '/consumed_' . bin2hex(random_bytes(8)) . '.token';
if (!@rename($tokenFile, $claimedFile)) {
    http_response_code(404);
    exit('Token unavailable or already consumed.');
}

try {
    $raw = @file_get_contents($claimedFile);
    $data = is_string($raw) ? json_decode($raw, true) : null;
    if (!is_array($data) || !isset($data['code'], $data['expires'])) {
        http_response_code(404);
        exit('Token unavailable.');
    }

    if (time() > (int)$data['expires']) {
        http_response_code(410);
        exit('Verification token expired.');
    }

    $verificationCode = (string)$data['code'];
    if (!preg_match('/^\d{6}$/', $verificationCode)) {
        http_response_code(404);
        exit('Token unavailable.');
    }
} finally {
    @unlink($claimedFile);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="referrer" content="no-referrer">
    <title>SentryIQ — Copy Verification Code</title>
    <style>
        body{font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif;background:#f1f3f5;padding:60px 20px;text-align:center;color:#212529}.card{max-width:450px;margin:0 auto;background:#fff;padding:40px 30px;border-radius:12px;box-shadow:0 4px 15px rgba(0,0,0,.06);border:1px solid #e9ecef}.code-display{font-size:36px;font-family:monospace;background:#212529;color:#ffec99;padding:15px 30px;letter-spacing:4px;border-radius:8px;display:inline-block;margin:20px 0;font-weight:700;min-width:200px}.btn-copy{background:#0066cc;color:#fff;border:0;padding:12px 24px;font-size:15px;font-weight:700;border-radius:6px;cursor:pointer;display:block;width:100%;margin:15px 0}.hint{font-size:13px;color:#6c757d;line-height:1.5;margin-top:20px}
    </style>
</head>
<body>
<div class="card">
    <h2 style="color:#0066cc;margin-top:0;font-size:22px;">🔒 SentryIQ Verification</h2>
    <p style="font-size:15px;color:#495057;">Your one-time verification code is ready:</p>
    <div id="auth-code" class="code-display"><?php echo htmlspecialchars($verificationCode, ENT_QUOTES, 'UTF-8'); ?></div>
    <button type="button" id="copy-btn" class="btn-copy" onclick="copyVerificationCode()">📋 Copy Verification Code</button>
    <p class="hint">The token has already been consumed and cannot be used again.</p>
</div>
<script>
function copyVerificationCode(){const code=document.getElementById('auth-code').textContent.trim(),btn=document.getElementById('copy-btn');if(!navigator.clipboard){alert('Clipboard access is unavailable in this browser.');return;}navigator.clipboard.writeText(code).then(()=>{btn.textContent='✅ Code Copied';setTimeout(()=>btn.textContent='📋 Copy Verification Code',1800);}).catch(()=>alert('Clipboard copy was rejected by the browser.'));}
</script>
</body>
</html>
