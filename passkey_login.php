<?php

declare(strict_types=1);

require_once __DIR__ . '/security_bootstrap.php';
sentryiq_security_bootstrap();

if (isset($_SESSION['master_key']) && is_string($_SESSION['master_key']) && strlen($_SESSION['master_key']) === 32) {
    header('Location: index.php');
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="csrf-token" content="<?php echo htmlspecialchars(sentryiq_csrf_token(), ENT_QUOTES, 'UTF-8'); ?>">
<title>SentryIQ — Passkey</title>
<link rel="stylesheet" href="pm_style.css">
</head>
<body>
<div class="box">
    <img class="sentryiq-brand-banner" src="sentryiq-logo-wide.webp" width="1952" height="588" alt="SentryIQ" fetchpriority="high">
    <div style="max-width:460px;margin:20px auto;text-align:center;">
        <h2>🔐 Unlock SentryIQ</h2>
        <p>Use your passkey to unlock your secure vault.</p>
        <button type="button" id="passkey-login" class="btn btn-primary" style="width:100%;margin-top:12px;">Use Passkey / Face ID</button>
        <p id="passkey-status" class="error" style="display:none;"></p>
        <p style="margin-top:20px;font-size:13px;"><a href="index.php?password=1">Use the recovery login instead</a></p>
    </div>
</div>
<script>
(function () {
    function b64urlToBytes(value) {
        var base64 = value.replace(/-/g, '+').replace(/_/g, '/');
        while (base64.length % 4) base64 += '=';
        var raw = atob(base64), bytes = new Uint8Array(raw.length);
        for (var i = 0; i < raw.length; i++) bytes[i] = raw.charCodeAt(i);
        return bytes;
    }
    function bytesToB64url(buffer) {
        var bytes = new Uint8Array(buffer), binary = '';
        for (var i = 0; i < bytes.length; i++) binary += String.fromCharCode(bytes[i]);
        return btoa(binary).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/g, '');
    }
    function showError(message) {
        var node = document.getElementById('passkey-status');
        node.textContent = message;
        node.style.display = 'block';
    }
    async function login() {
        if (!window.PublicKeyCredential || !navigator.credentials) { showError('Passkeys are not available in this browser.'); return; }
        var button = document.getElementById('passkey-login');
        button.disabled = true;
        try {
            var optionsResponse = await fetch('passkey_auth.php?action=login-options', { credentials: 'same-origin', cache: 'no-store' });
            var options = await optionsResponse.json();
            if (!optionsResponse.ok || options.status !== 'ok') throw new Error(options.message || 'Unable to start passkey authentication.');
            var publicKey = options.options;
            publicKey.challenge = b64urlToBytes(publicKey.challenge);
            if (publicKey.extensions && publicKey.extensions.prf && publicKey.extensions.prf.eval) publicKey.extensions.prf.eval.first = b64urlToBytes(publicKey.extensions.prf.eval.first);
            var credential = await navigator.credentials.get({ publicKey: publicKey });
            if (!credential) throw new Error('No passkey was returned.');
            var response = credential.response;
            var extensions = credential.getClientExtensionResults ? credential.getClientExtensionResults() : {};
            var prf = extensions && extensions.prf && extensions.prf.results ? extensions.prf.results.first : null;
            if (!prf) throw new Error('This passkey does not provide the secure key needed to unlock this vault.');
            var resultResponse = await fetch('passkey_auth.php', { method:'POST', credentials:'same-origin', headers:{'Content-Type':'application/json'}, body:JSON.stringify({
                action:'login', rawId:bytesToB64url(credential.rawId), clientDataJSON:bytesToB64url(response.clientDataJSON), authenticatorData:bytesToB64url(response.authenticatorData), signature:bytesToB64url(response.signature), prf:bytesToB64url(prf)
            }) });
            var result = await resultResponse.json();
            if (!resultResponse.ok || result.status !== 'ok') throw new Error(result.message || 'Passkey authentication failed.');
            window.location.href = 'index.php';
        } catch (error) {
            showError(error && error.message ? error.message : 'Passkey authentication was cancelled or failed.');
            button.disabled = false;
        }
    }
    document.getElementById('passkey-login').addEventListener('click', login);
}());
</script>
</body>
</html>
