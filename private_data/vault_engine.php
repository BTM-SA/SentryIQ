<?php
/**
 * Vault Studio Manager - Core Security Engine
 */

// Base directory can be overridden by SENTRYIQ_DATA_DIR before this file is loaded.
// Defaults to the historical private_data directory for backwards compatibility.
define('SENTRYIQ_DATA_DIR', getenv('SENTRYIQ_DATA_DIR') ?: '/home/bicheveb/private_data');
define('DATA_FILE', SENTRYIQ_DATA_DIR . '/passwords.enc');
define('LOG_FILE', SENTRYIQ_DATA_DIR . '/security_audit.log');
define('TWO_FA_EMAIL', 'mail@adress.com');

function get_visitor_ip(): string {
    if (!empty($_SERVER['HTTP_CLIENT_IP'])) { $ip = $_SERVER['HTTP_CLIENT_IP']; }
    elseif (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) { $ip = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR'])[0]; }
    else { $ip = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1'; }
    return filter_var(trim($ip), FILTER_VALIDATE_IP) ?: '127.0.0.1';
}

function ensure_sentryiq_data_directory(): bool {
    return is_dir(SENTRYIQ_DATA_DIR) || (@mkdir(SENTRYIQ_DATA_DIR, 0700, true) && is_dir(SENTRYIQ_DATA_DIR));
}

function log_security_event(string $event_type, string $ip_address, ?string $username = null, array $context = []): void {
    if (!ensure_sentryiq_data_directory()) return;
    $entry = [
        'timestamp' => date('c'),
        'event' => $event_type,
        'username' => $username ?? ($_SESSION['app_username'] ?? 'unknown'),
        'ip' => $ip_address,
        'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'unknown',
        'session_id' => session_id() ?: null,
        'context' => $context,
    ];
    @file_put_contents(LOG_FILE, json_encode($entry, JSON_UNESCAPED_SLASHES) . PHP_EOL, FILE_APPEND | LOCK_EX);
}

function read_security_log(): array {
    if (!is_file(LOG_FILE)) return [];
    $lines = @file(LOG_FILE, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if (!$lines) return [];
    $events = [];
    foreach (array_reverse($lines) as $line) {
        $decoded = json_decode($line, true);
        if (is_array($decoded)) $events[] = $decoded;
    }
    return $events;
}

function load_passwords(?string $explicit_key = null): array|bool {
    $master_key = $explicit_key ?? ($_SESSION['master_key'] ?? null);
    if (!$master_key || !file_exists(DATA_FILE)) return $explicit_key ? false : [];
    $raw = @file_get_contents(DATA_FILE);
    if (empty($raw)) return [];
    $payload = json_decode($raw, true);
    if (!$payload || !isset($payload['ciphertext'], $payload['iv'], $payload['tag'])) return false;
    $decrypted = openssl_decrypt(base64_decode($payload['ciphertext']), 'aes-256-gcm', $master_key, OPENSSL_RAW_DATA, base64_decode($payload['iv']), base64_decode($payload['tag']));
    return $decrypted === false ? false : (json_decode($decrypted, true) ?? []);
}

function save_passwords(array $data_matrix): bool {
    $master_key = $_SESSION['master_key'] ?? null;
    if (!$master_key || !ensure_sentryiq_data_directory()) return false;
    $iv = openssl_random_pseudo_bytes(openssl_cipher_iv_length('aes-256-gcm'));
    $ciphertext = openssl_encrypt(json_encode($data_matrix), 'aes-256-gcm', $master_key, OPENSSL_RAW_DATA, $iv, $tag);
    return @file_put_contents(DATA_FILE, json_encode(['ciphertext' => base64_encode($ciphertext), 'iv' => base64_encode($iv), 'tag' => base64_encode($tag)]), LOCK_EX) !== false;
}
