<?php
/**
 * Vault Studio Manager - Core Security Engine
 * Location: /home/bicheveb/private_data/vault_engine.php
 */

$configFile = '/home/bicheveb/public_html/pm/sentryiq_config.php';
$config = is_file($configFile) ? (require $configFile) : [];
$configuredDataDir = is_array($config) ? trim((string)($config['data_dir'] ?? '')) : '';

define('SENTRYIQ_DATA_DIR', $configuredDataDir !== '' ? rtrim($configuredDataDir, '/') : '/home/bicheveb/private_data');
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

/**
 * Convert the original six-field comma-separated vault records into the
 * structured record format used by the current UI. Existing structured
 * records and system configuration records are left untouched.
 */
function normalize_vault_records(array $records): array {
    $normalized = [];
    $changed = false;

    foreach ($records as $record) {
        if (is_array($record)) {
            $normalized[] = $record;
            continue;
        }

        if (!is_string($record)) {
            $normalized[] = $record;
            continue;
        }

        $parts = str_getcsv($record, ',', '"', '\\');
        if (count($parts) < 6) {
            $normalized[] = $record;
            continue;
        }

        // Legacy order: label, username, password, url, notes, id
        $normalized[] = [
            'id' => trim((string)$parts[5]),
            'label' => trim((string)$parts[0]),
            'username' => trim((string)$parts[1]),
            'password' => trim((string)$parts[2]),
            'url' => trim((string)$parts[3]),
            'notes' => trim((string)$parts[4]),
            'created_at' => null,
            'updated_at' => null,
            'icon_type' => null,
            'icon_path' => null,
            'icon_source' => null,
            'icon_fetched_at' => null,
        ];
        $changed = true;
    }

    return [$normalized, $changed];
}

function load_passwords(?string $explicit_key = null): array|bool {
    $master_key = $explicit_key ?? ($_SESSION['master_key'] ?? null);
    if (!$master_key || !file_exists(DATA_FILE)) return $explicit_key ? false : [];
    $raw = @file_get_contents(DATA_FILE);
    if (empty($raw)) return [];
    $payload = json_decode($raw, true);
    if (!$payload || !isset($payload['ciphertext'], $payload['iv'], $payload['tag'])) return false;
    $decrypted = openssl_decrypt(base64_decode($payload['ciphertext']), 'aes-256-gcm', $master_key, OPENSSL_RAW_DATA, base64_decode($payload['iv']), base64_decode($payload['tag']));
    if ($decrypted === false) return false;

    $records = json_decode($decrypted, true);
    if (!is_array($records)) return [];

    [$normalized, $changed] = normalize_vault_records($records);

    // Persist the migration once an authenticated session is available.
    // This keeps old vaults compatible without requiring a separate migration step.
    if ($changed && $explicit_key === null && isset($_SESSION['master_key'])) {
        save_passwords($normalized);
    }

    return $normalized;
}

function save_passwords(array $data_matrix): bool {
    $master_key = $_SESSION['master_key'] ?? null;
    if (!$master_key || !ensure_sentryiq_data_directory()) return false;
    $iv = openssl_random_pseudo_bytes(openssl_cipher_iv_length('aes-256-gcm'));
    $ciphertext = openssl_encrypt(json_encode($data_matrix), 'aes-256-gcm', $master_key, OPENSSL_RAW_DATA, $iv, $tag);
    return @file_put_contents(DATA_FILE, json_encode(['ciphertext' => base64_encode($ciphertext), 'iv' => base64_encode($iv), 'tag' => base64_encode($tag)]), LOCK_EX) !== false;
}
