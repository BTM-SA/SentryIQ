<?php

declare(strict_types=1);

session_start();

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: no-referrer');

?>
<!DOCTYPE html>
<html lang="en">

<head>

    <meta charset="UTF-8">

    <meta
        name="viewport"
        content="width=device-width, initial-scale=1.0"
    >

    <title>Vault Security Features</title>

    <style>

        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            padding: 30px 20px;

            font-family:
                -apple-system,
                BlinkMacSystemFont,
                "Segoe UI",
                sans-serif;

            background: #f4f6f9;
            color: #333;
            line-height: 1.6;
        }

        .container {
            max-width: 900px;
            margin: 0 auto;
        }

        .header {
            background: #fff;

            border: 1px solid #e1e4e8;
            border-radius: 10px;

            padding: 30px;

            margin-bottom: 20px;

            box-shadow:
                0 4px 12px
                rgba(0,0,0,0.05);
        }

        .header h1 {
            margin: 0 0 10px;

            color: #1a1a1a;

            font-size: 28px;
        }

        .header p {
            margin: 0;

            color: #666;

            font-size: 15px;
        }

        .security-status {
            margin-top: 20px;

            display: inline-flex;

            align-items: center;

            gap: 8px;

            padding: 8px 14px;

            border-radius: 20px;

            background: #e8f7ee;

            color: #19703a;

            font-size: 13px;

            font-weight: bold;
        }

        .grid {
            display: grid;

            grid-template-columns:
                repeat(2, 1fr);

            gap: 18px;
        }

        .card {
            background: #fff;

            border: 1px solid #e1e4e8;

            border-radius: 8px;

            padding: 22px;

            box-shadow:
                0 3px 8px
                rgba(0,0,0,0.04);
        }

        .card.full {
            grid-column: 1 / -1;
        }

        .icon {
            font-size: 25px;

            margin-bottom: 8px;
        }

        .card h2 {
            margin: 0 0 8px;

            font-size: 18px;

            color: #1a1a1a;
        }

        .card p {
            margin: 0 0 12px;

            font-size: 14px;

            color: #555;
        }

        .card ul {
            margin: 10px 0 0;

            padding-left: 20px;

            font-size: 14px;

            color: #555;
        }

        .card li {
            margin-bottom: 6px;
        }

        .badge {
            display: inline-block;

            padding: 3px 8px;

            margin-top: 5px;

            border-radius: 4px;

            background: #eef4ff;

            color: #2457a6;

            font-size: 11px;

            font-weight: bold;
        }

        .warning {
            background: #fff8e8;

            border-color: #f0d58a;
        }

        .warning h2 {
            color: #795900;
        }

        .important {
            background: #f4f0ff;

            border-color: #d8c8ff;
        }

        .important h2 {
            color: #53349a;
        }

        .code {
            background: #f1f2f4;

            border: 1px solid #e1e4e8;

            border-radius: 5px;

            padding: 12px;

            margin-top: 10px;

            font-family: monospace;

            font-size: 13px;

            overflow-x: auto;
        }

        .footer {
            margin-top: 20px;

            padding: 20px;

            text-align: center;

            color: #777;

            font-size: 12px;
        }

        .back {
            display: inline-block;

            margin-top: 20px;

            padding: 10px 18px;

            background: #0066cc;

            color: #fff;

            text-decoration: none;

            border-radius: 5px;

            font-size: 13px;

            font-weight: bold;
        }

        .back:hover {
            background: #0052a3;
        }

        @media (max-width: 700px) {

            body {
                padding: 15px 10px;
            }

            .grid {
                grid-template-columns: 1fr;
            }

            .card.full {
                grid-column: auto;
            }

            .header {
                padding: 22px;
            }

        }

    </style>

</head>

<body>

<div class="container">

    <!-- ==========================================
         HEADER
         ========================================== -->

    <div class="header">

        <h1>
            🔐 Vault Security
        </h1>

        <p>
            Security features and protection details
            for the Encrypted Locked Box.
        </p>

        <div class="security-status">
            🛡️ Security protections enabled
        </div>

    </div>


    <div class="grid">


        <!-- ==========================================
             ENCRYPTION
             ========================================== -->

        <div class="card">

            <div class="icon">
                🔐
            </div>

            <h2>
                AES-256-GCM Encryption
            </h2>

            <p>
                Vault records are encrypted before they
                are written to disk.
            </p>

            <ul>

                <li>
                    Uses AES-256-GCM authenticated encryption.
                </li>

                <li>
                    A fresh random initialization vector
                    is generated for each vault save.
                </li>

                <li>
                    Authentication prevents undetected
                    modification of encrypted data.
                </li>

            </ul>

            <span class="badge">
                AES-256-GCM
            </span>

        </div>


        <!-- ==========================================
             MASTER PASSWORD
             ========================================== -->

        <div class="card">

            <div class="icon">
                🔑
            </div>

            <h2>
                Master Password
            </h2>

            <p>
                The master password is used to derive the
                encryption key used by the vault.
            </p>

            <ul>

                <li>
                    The master password itself is not
                    stored in the vault file.
                </li>

                <li>
                    The encryption key exists only in the
                    authenticated session.
                </li>

                <li>
                    Without the correct password, the
                    encrypted vault cannot be decrypted.
                </li>

            </ul>

        </div>


        <!-- ==========================================
             TWO FACTOR AUTHENTICATION
             ========================================== -->

        <div class="card">

            <div class="icon">
                📧
            </div>

            <h2>
                Two-Factor Authentication
            </h2>

            <p>
                Unlocking the vault requires both the
                master password and a temporary email code.
            </p>

            <ul>

                <li>
                    Six-digit verification codes are
                    generated using a cryptographically
                    secure random generator.
                </li>

                <li>
                    Codes expire after
                    <strong>2½ minutes</strong>.
                </li>

                <li>
                    Successful authentication triggers
                    a security notification email.
                </li>

            </ul>

            <span class="badge">
                2FA
            </span>

        </div>


        <!-- ==========================================
             2FA COPY
             ========================================== -->

        <div class="card">

            <div class="icon">
                📋
            </div>

            <h2>
                Secure Code Copy
            </h2>

            <p>
                The verification email provides a
                convenient way to copy the temporary code.
            </p>

            <ul>

                <li>
                    The code itself is not placed in the
                    copy-link URL.
                </li>

                <li>
                    The copy link uses a random temporary
                    authorization token.
                </li>

                <li>
                    The token expires with the
                    authentication window.
                </li>

            </ul>

        </div>


        <!-- ==========================================
             BRUTE FORCE
             ========================================== -->

        <div class="card">

            <div class="icon">
                🚫
            </div>

            <h2>
                Brute-Force Protection
            </h2>

            <p>
                Repeated authentication failures are
                deliberately limited.
            </p>

            <ul>

                <li>
                    Master-password authentication is
                    limited to five failed attempts.
                </li>

                <li>
                    2FA verification is limited to five
                    incorrect attempts.
                </li>

                <li>
                    Excessive failures trigger a
                    temporary lockout.
                </li>

                <li>
                    Security events are recorded in the
                    security log.
                </li>

            </ul>

        </div>


        <!-- ==========================================
             CSRF
             ========================================== -->

        <div class="card">

            <div class="icon">
                🛡️
            </div>

            <h2>
                CSRF Protection
            </h2>

            <p>
                State-changing forms contain a unique
                session security token.
            </p>

            <ul>

                <li>
                    Login verification is protected.
                </li>

                <li>
                    Adding credentials is protected.
                </li>

                <li>
                    Editing credentials is protected.
                </li>

                <li>
                    Deleting credentials is protected.
                </li>

            </ul>

            <span class="badge">
                CSRF
            </span>

        </div>


        <!-- ==========================================
             SESSION SECURITY
             ========================================== -->

        <div class="card">

            <div class="icon">
                🍪
            </div>

            <h2>
                Session Protection
            </h2>

            <p>
                The vault session is hardened against
                common browser-side attacks.
            </p>

            <ul>

                <li>
                    HttpOnly session cookies.
                </li>

                <li>
                    SameSite=Strict cookies.
                </li>

                <li>
                    Secure cookies when HTTPS is active.
                </li>

                <li>
                    Session ID regeneration after
                    successful authentication.
                </li>

            </ul>

        </div>


        <!-- ==========================================
             BROWSER SECURITY
             ========================================== -->

        <div class="card">

            <div class="icon">
                🌐
            </div>

            <h2>
                Browser Security Headers
            </h2>

            <p>
                The vault instructs the browser to apply
                additional security restrictions.
            </p>

            <ul>

                <li>
                    Prevents MIME-type sniffing.
                </li>

                <li>
                    Prevents the vault from being
                    embedded in a frame.
                </li>

                <li>
                    Restricts referrer information.
                </li>

                <li>
                    Prevents normal browser caching
                    of vault pages.
                </li>

            </ul>

        </div>


        <!-- ==========================================
             PASSWORD VISIBILITY
             ========================================== -->

        <div class="card">

            <div class="icon">
                👁️
            </div>

            <h2>
                Hidden Password Display
            </h2>

            <p>
                Stored passwords are not displayed openly
                when the vault is first loaded.
            </p>

            <ul>

                <li>
                    Passwords are masked by default.
                </li>

                <li>
                    A user must explicitly select
                    "Show" to reveal one.
                </li>

                <li>
                    Passwords can be copied without
                    permanently displaying them.
                </li>

            </ul>

        </div>


        <!-- ==========================================
             DELETE PROTECTION
             ========================================== -->

        <div class="card">

            <div class="icon">
                🗑️
            </div>

            <h2>
                Protected Deletion
            </h2>

            <p>
                Credential deletion is treated as a
                state-changing operation.
            </p>

            <ul>

                <li>
                    Delete operations use POST requests.
                </li>

                <li>
                    CSRF validation is required.
                </li>

                <li>
                    A confirmation prompt is shown
                    before deletion.
                </li>

            </ul>

        </div>


        <!-- ==========================================
             FILE PERMISSIONS
             ========================================== -->

        <div class="card">

            <div class="icon">
                📁
            </div>

            <h2>
                Restricted Vault File
            </h2>

            <p>
                The encrypted data file is intended to
                live outside the publicly accessible web
                directory.
            </p>

            <ul>

                <li>
                    The vault attempts to apply
                    restrictive filesystem permissions.
                </li>

                <li>
                    The encrypted file is written with
                    exclusive locking.
                </li>

                <li>
                    The application does not expose the
                    vault contents through a public file.
                </li>

            </ul>

            <span class="badge">
                0600
            </span>

        </div>


        <!-- ==========================================
             SECURITY LOGGING
             ========================================== -->

        <div class="card">

            <div class="icon">
                📝
            </div>

            <h2>
                Security Event Logging
            </h2>

            <p>
                Important authentication events are
                recorded for auditing.
            </p>

            <ul>

                <li>
                    Failed master-password attempts.
                </li>

                <li>
                    Failed 2FA attempts.
                </li>

                <li>
                    Expired verification attempts.
                </li>

                <li>
                    Successful vault unlocks.
                </li>

                <li>
                    Vault encryption migrations.
                </li>

            </ul>

        </div>


        <!-- ==========================================
             LEGACY MIGRATION
             ========================================== -->

        <div class="card full">

            <div class="icon">
                🔄
            </div>

            <h2>
                Automatic Encryption Upgrade
            </h2>

            <p>
                Existing vaults created using the older
                AES-256-CBC storage format can be migrated
                automatically.
            </p>

            <ul>

                <li>
                    The existing master password remains
                    the source of the encryption key.
                </li>

                <li>
                    The old encrypted data is decrypted
                    only after successful authentication.
                </li>

                <li>
                    It is then rewritten using
                    AES-256-GCM authenticated encryption.
                </li>

                <li>
                    No plaintext password records are
                    intentionally written to disk during
                    the migration.
                </li>

            </ul>

        </div>


        <!-- ==========================================
             IMPORTANT SECURITY NOTES
             ========================================== -->

        <div class="card full important">

            <div class="icon">
                ⚠️
            </div>

            <h2>
                Important Security Notes
            </h2>

            <p>
                Encryption protects the vault, but the
                security of the complete system also
                depends on the environment in which it
                runs.
            </p>

            <ul>

                <li>
                    <strong>
                        Use HTTPS.
                    </strong>
                    Never operate the vault over an
                    unencrypted HTTP connection.
                </li>

                <li>
                    <strong>
                        Protect the server.
                    </strong>
                    Anyone with sufficient server-level
                    access may potentially access the
                    application or its runtime environment.
                </li>

                <li>
                    <strong>
                        Protect your email account.
                    </strong>
                    The email account receiving 2FA codes
                    is part of the authentication chain.
                </li>

                <li>
                    <strong>
                        Use a strong master password.
                    </strong>
                    The vault cannot compensate for a
                    weak or compromised master password.
                </li>

                <li>
                    <strong>
                        Keep backups secure.
                    </strong>
                    An encrypted vault backup should be
                    protected just as carefully as the
                    live vault.
                </li>

            </ul>

        </div>


        <!-- ==========================================
             WHAT THIS DOES NOT CLAIM
             ========================================== -->

        <div class="card full warning">

            <div class="icon">
                ℹ️
            </div>

            <h2>
                Security Scope
            </h2>

            <p>
                These protections significantly improve
                the security of the vault, but they do
                not make it invulnerable.
            </p>

            <ul>

                <li>
                    The vault is not a replacement for
                    operating-system security.
                </li>

                <li>
                    It does not protect a device that is
                    already compromised.
                </li>

                <li>
                    It does not protect a compromised
                    email account.
                </li>

                <li>
                    It does not protect a master password
                    that has been disclosed.
                </li>

                <li>
                    Server administrators with sufficient
                    privileges may have access to the
                    application environment.
                </li>

            </ul>

        </div>


    </div>


    <div class="footer">

        Encrypted Locked Box<br>

        Security documentation

        <br>

        <a
            href="javascript:history.back()"
            class="back"
        >
            ← Back to Vault
        </a>

    </div>

</div>

</body>

</html>