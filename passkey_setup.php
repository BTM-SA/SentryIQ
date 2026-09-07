<?php

declare(strict_types=1);

require_once __DIR__ . '/security_bootstrap.php';
sentryiq_security_bootstrap();
sentryiq_require_auth();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="csrf-token" content="<?php echo htmlspecialchars(sentryiq_csrf_token(), ENT_QUOTES, 'UTF-8'); ?>">
<title>SentryIQ — Set Up Passkey</title>
<link rel="stylesheet" href="pm_style.css">
</head>
<body>
<div class="box">
    <img class="sentryiq-brand-banner" src="sentryiq-logo-wide.webp" width="1952" height="588" alt="SentryIQ" fetchpriority="high">
    <div style="max-width:520px;margin:20px auto;text-align:center;">
        <h2>🔑 Set Up Your Passkey</h2>
        <p>Your vault is ready. Set up a passkey so future unlocks can use Face ID, Touch ID, or your device's passkey authentication instead of email codes.</p>
        <button type="button" id="passkey-register" class="btn btn-primary" style="width:100%;margin-top:12px;">Set Up Passkey</button>
        <p id="passkey-status" class="error" style="display:none;"></p>
        <p style="margin-top:18px;font-size:13px;color:#666;">The first setup requires the current authenticated vault session. Once registered, the passkey becomes the normal unlock method.</p>
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
    async function register() {
        if (!window.PublicKeyCredential || !navigator.credentials) { showError('Passkeys are not available in this browser.'); return; }
        var button = document.getElementById('passkey-register'); button.disabled = true;
        try {
            var optionsResponse = await fetch('passkey_auth.php?action=register-options', { credentials:'same-origin', cache:'no-store' });
            var options = await optionsResponse.json();
            if (!optionsResponse.ok || options.status !== 'ok') throw new Error(options.message || 'Unable to start passkey registration.');
            var publicKey = options.options;
            publicKey.challenge = b64urlToBytes(publicKey.challenge); publicKey.user.id = b64urlToBytes(publicKey.user.id);
            if (publicKey.extensions && publicKey.extensions.prf && publicKey.extensions.prf.eval) publicKey.extensions.prf.eval.first = b64urlToBytes(publicKey.extensions.prf.eval.first);
            var credential = await navigator.credentials.create({ publicKey:publicKey }); if (!credential) throw new Error('No passkey was created.');
            var response = credential.response; var extensions = credential.getClientExtensionResults ? credential.getClientExtensionResults() : {};
            var prf = extensions && extensions.prf && extensions.prf.results ? extensions.prf.results.first : null;
            if (!prf) throw new Error('This device did not provide the secure passkey key material required by SentryIQ.');
            if (!response.getPublicKey) throw new Error('This browser cannot expose the passkey public key required by SentryIQ.');
            var publicKeyDer = response.getPublicKey(); if (!publicKeyDer) throw new Error('The passkey public key was unavailable.');
            var csrf = document.querySelector('meta[name="csrf-token"]').content;
            var resultResponse = await fetch('passkey_auth.php', { method:'POST', credentials:'same-origin', headers:{'Content-Type':'application/json'}, body:JSON.stringify({
                action:'register', csrf_token:csrf, rawId:bytesToB64url(credential.rawId), clientDataJSON:bytesToB64url(response.clientDataJSON), authenticatorData:bytesToB64url(response.getAuthenticatorData ? response.getAuthenticatorData() : response.authenticatorData), publicKey:bytesToB64url(publicKeyDer), prf:bytesToB64url(prf)
            }) });
            var result = await resultResponse.json(); if (!resultResponse.ok || result.status !== 'ok') throw new Error(result.message || 'Passkey registration failed.');
            window.location.href = 'index.php';
        } catch (error) { showError(error && error.message ? error.message : 'Passkey registration was cancelled or failed.'); button.disabled = false; }
    }
    document.getElementById('passkey-register').addEventListener('click', register);
}());
</script>
</body>
</html>
