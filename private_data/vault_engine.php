<?php
/**
 * Vault Studio Manager - Core Security Engine
 * Location: /home/bicheveb/private_data/vault_engine.php
 */
define('DATA_FILE', '/home/bicheveb/private_data/passwords.enc');
define('LOG_FILE', '/home/bicheveb/private_data/security_audit.log');
define('TWO_FA_EMAIL', 'mail@adress.com'); // Put your real email string here!

function get_visitor_ip(): string {
    if (!empty($_SERVER['HTTP_CLIENT_IP'])) { $ip = $_SERVER['HTTP_CLIENT_IP']; }
    elseif (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) { $ip = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR'])[0]; }
    else { $ip = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1'; }
    return filter_var(trim($ip), FILTER_VALIDATE_IP) ?: '127.0.0.1';
}

function log_security_event(string $event_type, string $ip_address): void {
    $timestamp = date('Y-m-d H:i:s');
    $log_entry = "[{$timestamp}] [IP: {$ip_address}] EVENT: {$event_type}" . PHP_EOL;
    @file_put_contents(LOG_FILE, $log_entry, FILE_APPEND | LOCK_EX);
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
    if (!$master_key) return false;
    $iv = openssl_random_pseudo_bytes(openssl_cipher_iv_length('aes-256-gcm'));
    $ciphertext = openssl_encrypt(json_encode($data_matrix), 'aes-256-gcm', $master_key, OPENSSL_RAW_DATA, $iv, $tag);
    return @file_put_contents(DATA_FILE, json_encode(['ciphertext' => base64_encode($ciphertext), 'iv' => base64_encode($iv), 'tag' => base64_encode($tag)]), LOCK_EX) !== false;
}

