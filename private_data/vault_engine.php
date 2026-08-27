<?php
/**
 * SentryIQ - Vault Security Engine
 *
 * Fresh-install vault format only. Legacy vault migration is intentionally not
 * supported by this release.
 */

declare(strict_types=1);

$configFile = __DIR__ . '/sentryiq_config.php';
$config = is_file($configFile) ? require $configFile : [];
if (!is_array($config)) {
    throw new RuntimeException('Invalid SentryIQ secure configuration.');
}

$configuredDataDir = trim((string)($config['data_dir'] ?? ''));
if ($configuredDataDir === '' || !str_starts_with($configuredDataDir, '/')) {
    throw new RuntimeException('SentryIQ secure data directory is not configured.');
}

define('SENTRYIQ_DATA_DIR', rtrim($configuredDataDir, '/'));
define('DATA_FILE', SENTRYIQ_DATA_DIR . '/passwords.enc');
define('LOG_FILE', SENTRYIQ_DATA_DIR . '/security_audit.log');
define('TWO_FA_EMAIL', trim((string)($config['two_fa_email'] ?? '')));
define('TWO_FA_TOKEN_LIFETIME', 300);
define('SENTRYIQ_VAULT_VERSION', 2);
define('SENTRYIQ_KDF_OPSLIMIT', 3);
define('SENTRYIQ_KDF_MEMLIMIT', 32 * 1024 * 1024);
define('SENTRYIQ_GCM_NONCE_BYTES', 12);
define('SENTRYIQ_GCM_TAG_BYTES', 16);

function sentryiq_is_trusted_data_directory(?string $directory = null): bool
{
    $directory ??= SENTRYIQ_DATA_DIR;
    $directory = rtrim($directory, '/');

    if ($directory === '' || !str_starts_with($directory, '/')) return false;
    if (!is_dir($directory)) return false;
    if (is_link($directory)) return false;

    $real = realpath($directory);
    if ($real === false || rtrim($real, '/') !== $directory) return false;

    $perms = @fileperms($directory);
    if ($perms === false || (($perms & 0x0077) !== 0)) return false;

    return is_writable($directory);
}

function ensure_sentryiq_data_directory(): bool
{
    return sentryiq_is_trusted_data_directory();
}

function get_visitor_ip(): string
{
    $ip = $_SERVER['REMOTE_ADDR'] ?? '';
    return filter_var(trim($ip), FILTER_VALIDATE_IP) ?: '0.0.0.0';
}

function log_security_event(string $eventType, string $ipAddress, ?string $username = null, array $context = []): void
{
    if (!ensure_sentryiq_data_directory()) return;

    $event = [
        'timestamp' => date('c'),
        'event' => $eventType,
        'username' => $username ?? ($_SESSION['app_username'] ?? 'unknown'),
        'ip' => $ipAddress,
        'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'unknown',
        'context' => $context,
    ];

    @file_put_contents(
        LOG_FILE,
        json_encode($event, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL,
        FILE_APPEND | LOCK_EX
    );
    @chmod(LOG_FILE, 0600);
}

function read_security_log(): array
{
    if (!is_file(LOG_FILE) || is_link(LOG_FILE)) return [];
    $lines = @file(LOG_FILE, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if (!$lines) return [];

    $events = [];
    foreach (array_reverse($lines) as $line) {
        $decoded = json_decode($line, true);
        if (is_array($decoded)) $events[] = $decoded;
    }
    return $events;
}

function vault_validate_url(string $url): string|false
{
    $url = trim($url);
    if ($url === '') return '';
    if (!filter_var($url, FILTER_VALIDATE_URL)) return false;

    $parts = parse_url($url);
    if (!is_array($parts) || strtolower((string)($parts['scheme'] ?? '')) !== 'https') return false;
    if (empty($parts['host'])) return false;
    if (isset($parts['user']) || isset($parts['pass'])) return false;

    return $url;
}

function normalize_vault_records(array $records): array
{
    $normalized = [];

    foreach ($records as $record) {
        if (!is_array($record)) continue;

        if (($record['type'] ?? '') === 'system_config') {
            $normalized[] = [
                'id' => 'sys_config_node',
                'type' => 'system_config',
                'app_username' => trim((string)($record['app_username'] ?? '')),
                '2fa_email' => trim((string)($record['2fa_email'] ?? '')),
                'imap_password' => (string)($record['imap_password'] ?? ''),
            ];
            continue;
        }

        if (!array_key_exists('label', $record)) continue;
        $id = isset($record['id']) && is_scalar($record['id']) ? trim((string)$record['id']) : '';
        if ($id === '') $id = bin2hex(random_bytes(16));

        $url = vault_validate_url((string)($record['url'] ?? ''));
        if ($url === false) $url = '';

        $normalized[] = [
            'id' => $id,
            'label' => trim((string)($record['label'] ?? '')),
            'username' => trim((string)($record['username'] ?? '')),
            'password' => (string)($record['password'] ?? ''),
            'url' => $url,
            'notes' => trim((string)($record['notes'] ?? '')),
            'created_at' => $record['created_at'] ?? null,
            'updated_at' => $record['updated_at'] ?? null,
            'icon_type' => $record['icon_type'] ?? null,
            'icon_path' => $record['icon_path'] ?? null,
            'icon_source' => $record['icon_source'] ?? null,
            'icon_fetched_at' => $record['icon_fetched_at'] ?? null,
        ];
    }

    return array_values($normalized);
}

function vault_kdf_metadata(string $salt, int $opslimit = SENTRYIQ_KDF_OPSLIMIT, int $memlimit = SENTRYIQ_KDF_MEMLIMIT): array
{
    return [
        'name' => 'argon2id13',
        'opslimit' => $opslimit,
        'memlimit' => $memlimit,
        'salt' => base64_encode($salt),
    ];
}

function vault_derive_key(string $password, string $salt, int $opslimit, int $memlimit): string
{
    if (!function_exists('sodium_crypto_pwhash')) {
        throw new RuntimeException('Sodium is required for SentryIQ vault key derivation.');
    }
    if (strlen($salt) !== SODIUM_CRYPTO_PWHASH_SALTBYTES) {
        throw new RuntimeException('Invalid vault KDF salt.');
    }
    if ($opslimit < SODIUM_CRYPTO_PWHASH_OPSLIMIT_INTERACTIVE || $memlimit < 19 * 1024 * 1024) {
        throw new RuntimeException('Vault KDF parameters are too weak.');
    }

    $key = sodium_crypto_pwhash(
        32,
        $password,
        $salt,
        $opslimit,
        $memlimit,
        SODIUM_CRYPTO_PWHASH_ALG_ARGON2ID13
    );

    if (!is_string($key) || strlen($key) !== 32) {
        throw new RuntimeException('Vault key derivation failed.');
    }

    return $key;
}

function vault_build_aad(array $kdf): string
{
    $metadata = [
        'version' => SENTRYIQ_VAULT_VERSION,
        'kdf' => $kdf,
        'cipher' => [
            'name' => 'aes-256-gcm',
            'nonce_bytes' => SENTRYIQ_GCM_NONCE_BYTES,
            'tag_bytes' => SENTRYIQ_GCM_TAG_BYTES,
        ],
    ];

    return (string)json_encode($metadata, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
}

function vault_decode_base64(string $value, int $exactLength = 0): string|false
{
    $decoded = base64_decode($value, true);
    if ($decoded === false) return false;
    if ($exactLength > 0 && strlen($decoded) !== $exactLength) return false;
    return $decoded;
}

function vault_read_envelope(): array|false
{
    clearstatcache(true, DATA_FILE);

    if (!ensure_sentryiq_data_directory() || !is_file(DATA_FILE) || is_link(DATA_FILE)) return false;

    $raw = @file_get_contents(DATA_FILE);
    if (!is_string($raw) || $raw === '' || strlen($raw) > 32 * 1024 * 1024) return false;

    try {
        $envelope = json_decode($raw, true, 16, JSON_THROW_ON_ERROR);
    } catch (Throwable) {
        return false;
    }
    if (!is_array($envelope)) return false;
    if (($envelope['version'] ?? null) !== SENTRYIQ_VAULT_VERSION) return false;
    if (!isset($envelope['kdf']) || !is_array($envelope['kdf'])) return false;
    if (($envelope['kdf']['name'] ?? '') !== 'argon2id13') return false;
    if (($envelope['cipher']['name'] ?? '') !== 'aes-256-gcm') return false;
    if (($envelope['cipher']['nonce_bytes'] ?? null) !== SENTRYIQ_GCM_NONCE_BYTES) return false;
    if (($envelope['cipher']['tag_bytes'] ?? null) !== SENTRYIQ_GCM_TAG_BYTES) return false;

    $salt = vault_decode_base64((string)($envelope['kdf']['salt'] ?? ''), SODIUM_CRYPTO_PWHASH_SALTBYTES);
    $nonce = vault_decode_base64((string)($envelope['nonce'] ?? ''), SENTRYIQ_GCM_NONCE_BYTES);
    $tag = vault_decode_base64((string)($envelope['tag'] ?? ''), SENTRYIQ_GCM_TAG_BYTES);
    $ciphertext = vault_decode_base64((string)($envelope['ciphertext'] ?? ''));
    $aadStored = vault_decode_base64((string)($envelope['aad'] ?? ''));

    if ($salt === false || $nonce === false || $tag === false || $ciphertext === false || $aadStored === false) return false;

    $kdfName = (string)($envelope['kdf']['name'] ?? '');
    $opslimit = (int)($envelope['kdf']['opslimit'] ?? 0);
    $memlimit = (int)($envelope['kdf']['memlimit'] ?? 0);
    if ($kdfName !== 'argon2id13' || $opslimit < SODIUM_CRYPTO_PWHASH_OPSLIMIT_INTERACTIVE || $memlimit < 19 * 1024 * 1024) return false;

    $kdf = [
        'name' => $kdfName,
        'opslimit' => $opslimit,
        'memlimit' => $memlimit,
        'salt' => base64_encode($salt),
    ];

    return [
        'envelope' => $envelope,
        'salt' => $salt,
        'kdf' => $kdf,
        'nonce' => $nonce,
        'tag' => $tag,
        'ciphertext' => $ciphertext,
        'aad' => $aadStored,
    ];
}

function vault_unlock(string $password): array|false
{
    $parts = vault_read_envelope();
    if ($parts === false) return false;

    try {
        $key = vault_derive_key(
            $password,
            $parts['salt'],
            (int)$parts['kdf']['opslimit'],
            (int)$parts['kdf']['memlimit']
        );
    } catch (Throwable $exception) {
        error_log('SentryIQ vault unlock KDF failure: ' . $exception->getMessage());
        return false;
    }

    $plaintext = openssl_decrypt(
        $parts['ciphertext'],
        'aes-256-gcm',
        $key,
        OPENSSL_RAW_DATA,
        $parts['nonce'],
        $parts['tag'],
        $parts['aad']
    );
    if ($plaintext === false) return false;

    $records = json_decode($plaintext, true);
    if (!is_array($records)) return false;

    return [
        'key' => $key,
        'records' => normalize_vault_records($records),
        'kdf' => $parts['kdf'],
    ];
}

function vault_initialize(string $password, array $records = []): bool
{
    if (strlen($password) < 12) return false;
    if (!ensure_sentryiq_data_directory()) return false;

    try {
        $salt = random_bytes(SODIUM_CRYPTO_PWHASH_SALTBYTES);
        $key = vault_derive_key($password, $salt, SENTRYIQ_KDF_OPSLIMIT, SENTRYIQ_KDF_MEMLIMIT);
        $kdf = vault_kdf_metadata($salt, SENTRYIQ_KDF_OPSLIMIT, SENTRYIQ_KDF_MEMLIMIT);
        return vault_write_encrypted_records(normalize_vault_records($records), $key, $kdf);
    } catch (Throwable $exception) {
        error_log('SentryIQ vault initialization failure: ' . $exception::class . ': ' . $exception->getMessage());
        return false;
    }
}

function vault_write_encrypted_records(array $dataMatrix, string $masterKey, array $kdf): bool
{
    if (strlen($masterKey) !== 32 || !ensure_sentryiq_data_directory()) return false;
    if (!isset($kdf['salt'], $kdf['opslimit'], $kdf['memlimit'])) return false;

    $aad = vault_build_aad($kdf);
    $nonce = random_bytes(SENTRYIQ_GCM_NONCE_BYTES);
    $plaintext = json_encode(normalize_vault_records($dataMatrix), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    $tag = '';

    $ciphertext = openssl_encrypt(
        $plaintext,
        'aes-256-gcm',
        $masterKey,
        OPENSSL_RAW_DATA,
        $nonce,
        $tag,
        $aad,
        SENTRYIQ_GCM_TAG_BYTES
    );
    if ($ciphertext === false || strlen($tag) !== SENTRYIQ_GCM_TAG_BYTES) return false;

    $envelope = [
        'version' => SENTRYIQ_VAULT_VERSION,
        'kdf' => $kdf,
        'cipher' => [
            'name' => 'aes-256-gcm',
            'nonce_bytes' => SENTRYIQ_GCM_NONCE_BYTES,
            'tag_bytes' => SENTRYIQ_GCM_TAG_BYTES,
        ],
        'aad' => base64_encode($aad),
        'nonce' => base64_encode($nonce),
        'tag' => base64_encode($tag),
        'ciphertext' => base64_encode($ciphertext),
    ];

    $encoded = json_encode($envelope, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    $tmp = DATA_FILE . '.tmp-' . bin2hex(random_bytes(12));

    if (is_link(DATA_FILE)) return false;
    $handle = @fopen($tmp, 'xb');
    if ($handle === false) return false;

    try {
        @chmod($tmp, 0600);
        $written = fwrite($handle, $encoded);
        if ($written !== strlen($encoded)) return false;
        if (function_exists('fflush')) fflush($handle);
        if (function_exists('fsync')) @fsync($handle);
    } finally {
        fclose($handle);
    }

    @chmod($tmp, 0600);
    if (!@rename($tmp, DATA_FILE)) {
        @unlink($tmp);
        return false;
    }
    @chmod(DATA_FILE, 0600);
    return true;
}

function load_passwords(?string $explicitKey = null): array|bool
{
    $key = $explicitKey ?? ($_SESSION['master_key'] ?? null);
    if (!is_string($key) || strlen($key) !== 32) return false;

    $parts = vault_read_envelope();
    if ($parts === false) return false;

    $plaintext = openssl_decrypt(
        $parts['ciphertext'],
        'aes-256-gcm',
        $key,
        OPENSSL_RAW_DATA,
        $parts['nonce'],
        $parts['tag'],
        $parts['aad']
    );
    if ($plaintext === false) return false;

    $records = json_decode($plaintext, true);
    if (!is_array($records)) return false;

    return normalize_vault_records($records);
}
