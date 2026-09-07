<?php

declare(strict_types=1);

const SENTRYIQ_IDLE_TIMEOUT = 900;
const SENTRYIQ_FRESH_AUTH_WINDOW = 300;

function sentryiq_data_dir(): string
{
    if (defined('SENTRYIQ_DATA_DIR') && is_dir(SENTRYIQ_DATA_DIR) && !is_link(SENTRYIQ_DATA_DIR)) {
        return SENTRYIQ_DATA_DIR;
    }

    $configFile = __DIR__ . '/sentryiq_config.php';
    if (!is_file($configFile) || is_link($configFile)) {
        return '';
    }

    $config = require $configFile;
    if (!is_array($config)) {
        return '';
    }

    $dataDir = rtrim((string)($config['data_dir'] ?? ''), '/');
    if ($dataDir === '' || !str_starts_with($dataDir, '/') || is_link($dataDir) || !is_dir($dataDir)) {
        return '';
    }

    if (!defined('SENTRYIQ_DATA_DIR')) {
        define('SENTRYIQ_DATA_DIR', $dataDir);
    }

    return SENTRYIQ_DATA_DIR;
}

function sentryiq_has_registered_passkey(): bool
{
    $dataDir = sentryiq_data_dir();
    if ($dataDir === '') return false;
    $path = $dataDir . '/passkeys.json';
    if (!is_file($path) || is_link($path)) return false;
    $raw = @file_get_contents($path);
    $records = is_string($raw) ? json_decode($raw, true) : null;
    if (!is_array($records)) return false;
    $credentials = $records['credentials'] ?? null;
    return is_array($credentials) && $credentials !== [];
}

function sentryiq_security_bootstrap(): void
{
    sentryiq_data_dir();

    if (PHP_SAPI !== 'cli' && (($_SERVER['HTTPS'] ?? '') !== 'on' && (string)($_SERVER['SERVER_PORT'] ?? '') !== '443')) {
        http_response_code(400);
        exit('SentryIQ requires HTTPS.');
    }

    if (session_status() !== PHP_SESSION_ACTIVE) {
        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_only_cookies', '1');
        ini_set('session.cookie_secure', '1');
        ini_set('session.cookie_httponly', '1');
        ini_set('session.cookie_samesite', 'Strict');
        ini_set('session.cookie_path', '/');

        session_set_cookie_params([
            'lifetime' => 0,
            'path' => '/',
            'secure' => true,
            'httponly' => true,
            'samesite' => 'Strict',
        ]);
        session_start();
    }

    set_exception_handler(static function (Throwable $exception): void {
        error_log('SentryIQ runtime failure: ' . $exception::class . ': ' . $exception->getMessage());
        http_response_code(500);
        exit('SentryIQ encountered a security failure.');
    });

    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    header('Expires: 0');
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');
    header('Referrer-Policy: no-referrer');
    header('Permissions-Policy: camera=(), microphone=(), geolocation=(), payment=()');
    header("Content-Security-Policy: default-src 'self'; object-src 'none'; base-uri 'self'; frame-ancestors 'none'; form-action 'self'; img-src 'self' data:; style-src 'self' 'unsafe-inline'; script-src 'self' 'unsafe-inline'");

    $script = basename((string)($_SERVER['SCRIPT_FILENAME'] ?? ''));
    $passkeyPath = $script === 'passkey.php' || $script === 'passkey_login.php' || $script === 'passkey_setup.php' || $script === 'passkey_auth.php';
    $passwordFallback = isset($_GET['password']) && $_GET['password'] === '1';

    if (!$passkeyPath && !$passwordFallback && $script === 'index.php') {
        $authenticated = isset($_SESSION['master_key']) && is_string($_SESSION['master_key']) && strlen($_SESSION['master_key']) === 32;
        if ($authenticated && !sentryiq_has_registered_passkey()) {
            header('Location: passkey_setup.php');
            exit;
        }
        if (!$authenticated && sentryiq_has_registered_passkey()) {
            header('Location: passkey_login.php');
            exit;
        }
    }

    if (isset($_SESSION['master_key'])) {
        $lastActivity = (int)($_SESSION['last_activity'] ?? 0);
        if ($lastActivity > 0 && (time() - $lastActivity) > SENTRYIQ_IDLE_TIMEOUT) {
            sentryiq_lock_vault();
            return;
        }
        $_SESSION['last_activity'] = time();
    }
}

function sentryiq_lock_vault(): void
{
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'] ?? '', (bool)$params['secure'], (bool)$params['httponly']);
    }
    if (session_status() === PHP_SESSION_ACTIVE) session_destroy();
}

function sentryiq_csrf_token(): string
{
    if (empty($_SESSION['csrf_token']) || !is_string($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function sentryiq_require_csrf(): void
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        exit('Method not allowed.');
    }

    $provided = (string)($_POST['csrf_token'] ?? '');
    $expected = (string)($_SESSION['csrf_token'] ?? '');
    if ($expected === '' || $provided === '' || !hash_equals($expected, $provided)) {
        http_response_code(403);
        exit('Security validation failed.');
    }
}

function sentryiq_require_auth(): void
{
    if (!isset($_SESSION['master_key']) || !is_string($_SESSION['master_key']) || strlen($_SESSION['master_key']) !== 32) {
        http_response_code(403);
        exit('Authentication required.');
    }
}

function sentryiq_require_fresh_auth(): void
{
    sentryiq_require_auth();
    $authenticatedAt = (int)($_SESSION['authenticated_at'] ?? 0);
    if ($authenticatedAt <= 0 || (time() - $authenticatedAt) > SENTRYIQ_FRESH_AUTH_WINDOW) {
        http_response_code(403);
        exit('Fresh authentication required. Lock and unlock the vault again.');
    }
}

function sentryiq_mark_authenticated(string $key, string $username): void
{
    if (strlen($key) !== 32) throw new RuntimeException('Invalid vault key.');
    session_regenerate_id(true);
    $_SESSION['master_key'] = $key;
    $_SESSION['app_username'] = $username;
    $_SESSION['authenticated_at'] = time();
    $_SESSION['last_activity'] = time();
    unset($_SESSION['pending_key'], $_SESSION['pending_username'], $_SESSION['two_fa_code'], $_SESSION['copy_code_token'], $_SESSION['pending_started_at'], $_SESSION['two_fa_attempts']);
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

function sentryiq_clear_pending_auth(): void
{
    unset($_SESSION['pending_key'], $_SESSION['pending_username'], $_SESSION['two_fa_code'], $_SESSION['copy_code_token'], $_SESSION['pending_started_at'], $_SESSION['two_fa_attempts']);
}

function sentryiq_pending_auth_expired(): bool
{
    $started = (int)($_SESSION['pending_started_at'] ?? 0);
    return $started <= 0 || (time() - $started) > 300;
}

function cleanup_expired_tokens(): void
{
    $dataDir = sentryiq_data_dir();
    if (!isset($_SESSION) || $dataDir === '') return;
    foreach (glob($dataDir . '/token_*.json') ?: [] as $file) {
        if (is_link($file)) continue;
        $raw = @file_get_contents($file);
        $token = is_string($raw) ? json_decode($raw, true) : null;
        if (!is_array($token) || (int)($token['expires'] ?? 0) <= time()) {
            @unlink($file);
        }
    }
}
