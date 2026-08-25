<?php
/**
 * Vault Studio Manager - 2FA Link Interception Node
 * Location: /home/bicheveb/public_html/pm/copy-code.php
 */
header("Content-Type: text/html; charset=UTF-8");

// 1. Verify URL token parameters exist
if (!isset($_GET['t']) || empty($_GET['t'])) {
    die("<h3 style='color:#dc3545; font-family:sans-serif; text-align:center; margin-top:50px;'>❌ Security Alert: Missing tracking parameter.</h3>");
}

// Sanitize token string strictly to match valid token filenames
$inbound_token = preg_replace('/[^a-f0-9]/i', '', trim($_GET['t']));
$token_file = '/home/bicheveb/private_data/token_' . $inbound_token . '.json';

// 2. Locate the specific token file on disk
if (!file_exists($token_file) || !is_readable($token_file)) {
    die("<h3 style='color:#dc3545; font-family:sans-serif; text-align:center; margin-top:50px;'>❌ Authentication Error: Token signature invalid, expired, or already consumed.</h3>");
}

$file_contents = trim(file_get_contents($token_file));
$data = json_decode($file_contents, true);

if (!$data || !isset($data['code'], $data['expires'])) {
    die("<h3 style='color:#dc3545; font-family:sans-serif; text-align:center; margin-top:50px;'>❌ System Fault: Checkpoint record corrupted or unreadable.</h3>");
}

// 3. Validate Token Expiry (5 Minute Lifespan)
if (time() > $data['expires']) {
    @unlink($token_file); // Clean up expired token file safely from disk
    die("<h3 style='color:#dc3545; font-family:sans-serif; text-align:center; margin-top:50px;'>⏰ Token Expired: Secure authorization windows close after 5 minutes.</h3>");
}

// 4. Output the validation UI with native clipboard execution
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Secure Token Retrieval Node</title>
    <style>
        body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; background: #f1f3f5; padding: 60px 20px; text-align: center; color: #212529; }
        .card { max-width: 450px; margin: 0 auto; background: white; padding: 40px 30px; border-radius: 12px; box-shadow: 0 4px 15px rgba(0,0,0,0.06); border: 1px solid #e9ecef; }
        .code-display { font-size: 36px; font-family: monospace; background: #212529; color: #ffec99; padding: 15px 30px; letter-spacing: 4px; border-radius: 8px; display: inline-block; margin: 20px 0; font-weight: bold; min-width: 200px; text-align: center; }
        .btn-copy { background: #0066cc; color: #ffffff; border: none; padding: 12px 24px; font-size: 15px; font-weight: bold; border-radius: 6px; cursor: pointer; display: block; width: 100%; margin: 15px 0; box-sizing: border-box; transition: background 0.15s ease; }
        .btn-copy:hover { background: #0052a3; }
        .hint { font-size: 13px; color: #6c757d; line-height: 1.5; margin-top: 20px; }
    </style>
</head>
<body>
    <div class="card">
        <h2 style="color: #0066cc; margin-top: 0; font-size: 22px;">🔒 2FA Access Authorized</h2>
        <p style="font-size: 15px; color: #495057;">Your verification session is ready. Click the button below to copy the code instantly:</p>
        
        <!-- Target string value block container -->
        <div id="auth-code" class="code-display"><?php echo htmlspecialchars($data['code']); ?></div>
        
        <!-- 🔥 ONE-CLICK COPY OPERATION INTERFACE -->
        <button type="button" id="copy-btn" class="btn-copy" onclick="copyVerificationCode()">
            📋 Copy Verification Code
        </button>
        
        <p class="hint">Return to your initial login terminal framework page and paste this sequence into the 6-digit checkpoint input field.</p>
    </div>

    <script>
    function copyVerificationCode() {
        const codeText = document.getElementById('auth-code').textContent.trim();
        const btn = document.getElementById('copy-btn');

        if (!navigator.clipboard) {
            // Native fallback sequence if browser security context restricts direct API access
            alert("Clipboard management unsupported by this browser instance context layout.");
            return;
        }

        navigator.clipboard.writeText(codeText)
            .then(() => {
                btn.innerHTML = "✅ Code Copied!";
                btn.style.background = "#2b8a3e";
                
                // Revert interface styling back smoothly after 2 seconds
                setTimeout(() => {
                    btn.innerHTML = "📋 Copy Verification Code";
                    btn.style.background = "#0066cc";
                }, 2000);
            })
            .catch(err => {
                console.error("Failed to copy credential string execution block:", err);
                alert("Clipboard copy transaction rejected by active security parameters.");
            });
    }
    </script>
</body>
</html>
