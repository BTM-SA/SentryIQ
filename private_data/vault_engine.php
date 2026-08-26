<?php
/**
 * SentryIQ - Vault Security Engine
 */

$configFile = __DIR__ . '/sentryiq_config.php';
$config = is_file($configFile) ? require $configFile : [];
$configuredDataDir = is_array($config) ? trim((string) ($config['data_dir'] ?? '')) : '';

if ($configuredDataDir === '') {
    $pointerFile = defined('SENTRYIQ_CONFIG_FILE') ? SENTRYIQ_CONFIG_FILE : '';
    $pointer = ($pointerFile !== '' && is_file($pointerFile)) ? require $pointerFile : [];
    $configuredDataDir = is_array($pointer) ? trim((string) ($pointer['data_dir'] ?? '')) : '';
}

define('SENTRYIQ_DATA_DIR', $configuredDataDir !== '' ? rtrim($configuredDataDir, '/') : '/home/bicheveb/private_data');
define('DATA_FILE', SENTRYIQ_DATA_DIR . '/passwords.enc');
define('LOG_FILE', SENTRYIQ_DATA_DIR . '/security_audit.log');
define('TWO_FA_EMAIL', is_array($config) && !empty($config['two_fa_email']) ? trim((string) $config['two_fa_email']) : '');
define('TWO_FA_TOKEN_LIFETIME', is_array($config) && !empty($config['two_fa_token_expiry']) ? max(60, (int) $config['two_fa_token_expiry']) : 300);

function get_visitor_ip(): string
{
    if (!empty($_SERVER['HTTP_CLIENT_IP'])) $ip = $_SERVER['HTTP_CLIENT_IP'];
    elseif (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) $ip = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR'])[0];
    else $ip = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
    return filter_var(trim($ip), FILTER_VALIDATE_IP) ?: '127.0.0.1';
}

function ensure_sentryiq_data_directory(): bool
{
    return is_dir(SENTRYIQ_DATA_DIR) || (@mkdir(SENTRYIQ_DATA_DIR, 0700, true) && is_dir(SENTRYIQ_DATA_DIR));
}

function cleanup_expired_tokens(): void
{
    if (!ensure_sentryiq_data_directory()) return;
    foreach (glob(SENTRYIQ_DATA_DIR . '/token_*.json') ?: [] as $file) {
        $raw = @file_get_contents($file);
        $token = is_string($raw) ? json_decode($raw, true) : null;
        if (!is_array($token) || empty($token['expires']) || (int) $token['expires'] <= time()) @unlink($file);
    }
}

function log_security_event(string $eventType, string $ipAddress, ?string $username = null, array $context = []): void
{
    if (!ensure_sentryiq_data_directory()) return;
    $event = ['timestamp'=>date('c'),'event'=>$eventType,'username'=>$username ?? ($_SESSION['app_username'] ?? 'unknown'),'ip'=>$ipAddress,'user_agent'=>$_SERVER['HTTP_USER_AGENT'] ?? 'unknown','session_id'=>session_id() ?: null,'context'=>$context];
    @file_put_contents(LOG_FILE, json_encode($event, JSON_UNESCAPED_SLASHES) . PHP_EOL, FILE_APPEND | LOCK_EX);
}

function read_security_log(): array
{
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

/** Convert every historical/intermediate vault record into one canonical associative shape. */
function normalize_vault_records(array $records): array
{
    $normalized = [];
    $keys = array_keys($records);
    $isSequential = ($keys === range(0, count($records) - 1));

    if ($isSequential && count($records) >= 5 && !is_array($records[0] ?? null)) $records = [$records];

    foreach ($records as $record) {
        if (is_string($record)) {
            $parts = str_getcsv($record);
            if (count($parts) >= 5) {
                $normalized[] = [
                    'id'=>trim((string)($parts[5] ?? bin2hex(random_bytes(8)))),
                    'label'=>trim((string)($parts[0] ?? '')),
                    'username'=>trim((string)($parts[1] ?? '')),
                    'password'=>trim((string)($parts[2] ?? '')),
                    'url'=>trim((string)($parts[3] ?? '')),
                    'notes'=>trim((string)($parts[4] ?? '')),
                    'created_at'=>null,'updated_at'=>null,'icon_type'=>null,'icon_path'=>null,'icon_source'=>null,'icon_fetched_at'=>null,
                ];
            }
            continue;
        }

        if (!is_array($record)) continue;
        $recordKeys = array_keys($record);
        $isSequentialRecord = ($recordKeys === range(0, count($record) - 1));

        if (($record['type'] ?? '') === 'system_config') {
            $normalized[] = $record;
            continue;
        }

        if ($isSequentialRecord && count($record) >= 5) {
            $normalized[] = [
                'id'=>trim((string)($record[5] ?? bin2hex(random_bytes(8)))),
                'label'=>trim((string)($record[0] ?? '')),
                'username'=>trim((string)($record[1] ?? '')),
                'password'=>trim((string)($record[2] ?? '')),
                'url'=>trim((string)($record[3] ?? '')),
                'notes'=>trim((string)($record[4] ?? '')),
                'created_at'=>null,'updated_at'=>null,'icon_type'=>null,'icon_path'=>null,'icon_source'=>null,'icon_fetched_at'=>null,
            ];
            continue;
        }

        if (array_key_exists('label', $record)) {
            if (is_array($record['label']) && count($record['label']) >= 5) {
                $parts = array_values($record['label']);
                $normalized[] = [
                    'id'=>trim((string)($parts[5] ?? $record['id'] ?? bin2hex(random_bytes(8)))),
                    'label'=>trim((string)($parts[0] ?? '')),
                    'username'=>trim((string)($parts[1] ?? $record['username'] ?? '')),
                    'password'=>trim((string)($parts[2] ?? $record['password'] ?? '')),
                    'url'=>trim((string)($parts[3] ?? $record['url'] ?? '')),
                    'notes'=>trim((string)($parts[4] ?? $record['notes'] ?? '')),
                    'created_at'=>$record['created_at'] ?? null,
                    'updated_at'=>$record['updated_at'] ?? null,
                    'icon_type'=>$record['icon_type'] ?? null,
                    'icon_path'=>$record['icon_path'] ?? null,
                    'icon_source'=>$record['icon_source'] ?? null,
                    'icon_fetched_at'=>$record['icon_fetched_at'] ?? null,
                ];
                continue;
            }

            $parts = str_getcsv((string)($record['label'] ?? ''));
            $looksLikePackedRecord = count($parts) >= 6 && trim((string)($parts[5] ?? '')) !== '';

            if ($looksLikePackedRecord) {
                $normalized[] = [
                    'id'=>trim((string)$parts[5]),
                    'label'=>trim((string)($parts[0] ?? '')),
                    'username'=>trim((string)($parts[1] ?? '')),
                    'password'=>trim((string)($parts[2] ?? '')),
                    'url'=>trim((string)($parts[3] ?? '')),
                    'notes'=>trim((string)($parts[4] ?? '')),
                    'created_at'=>$record['created_at'] ?? null,
                    'updated_at'=>$record['updated_at'] ?? null,
                    'icon_type'=>$record['icon_type'] ?? null,
                    'icon_path'=>$record['icon_path'] ?? null,
                    'icon_source'=>$record['icon_source'] ?? null,
                    'icon_fetched_at'=>$record['icon_fetched_at'] ?? null,
                ];
                continue;
            }

            if (empty($record['id'])) $record['id'] = bin2hex(random_bytes(8));
            $normalized[] = $record;
        }
    }

    return array_values($normalized);
}

function load_passwords(?string $explicitKey = null): array|bool
{
    $masterKey = $explicitKey ?? ($_SESSION['master_key'] ?? null);
    if (!$masterKey || !file_exists(DATA_FILE)) return $explicitKey ? false : [];
    $raw = @file_get_contents(DATA_FILE);
    if (empty($raw)) return [];
    $payload = json_decode($raw, true);
    if (!is_array($payload) || !isset($payload['ciphertext'],$payload['iv'],$payload['tag'])) return false;
    $decrypted = openssl_decrypt(base64_decode($payload['ciphertext']), 'aes-256-gcm', $masterKey, OPENSSL_RAW_DATA, base64_decode($payload['iv']), base64_decode($payload['tag']));
    if ($decrypted === false) return false;
    $records = json_decode($decrypted, true);
    if (!is_array($records)) return [];
    $normalized = normalize_vault_records($records);
    if ($normalized !== $records && isset($_SESSION['master_key'])) save_passwords($normalized);
    return $normalized;
}

function save_passwords(array $dataMatrix): bool
{
    $masterKey = $_SESSION['master_key'] ?? null;
    if (!$masterKey || !ensure_sentryiq_data_directory()) return false;
    $iv = openssl_random_pseudo_bytes(openssl_cipher_iv_length('aes-256-gcm'));
    $ciphertext = openssl_encrypt(json_encode($dataMatrix), 'aes-256-gcm', $masterKey, OPENSSL_RAW_DATA, $iv, $tag);
    if ($ciphertext === false) return false;
    return @file_put_contents(DATA_FILE, json_encode(['ciphertext'=>base64_encode($ciphertext),'iv'=>base64_encode($iv),'tag'=>base64_encode($tag)]), LOCK_EX) !== false;
}

cleanup_expired_tokens();
