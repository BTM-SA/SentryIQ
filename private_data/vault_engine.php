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
define('SENTRYIQ_KDF_MEMLIMIT', 64 * 1024 * 1024);
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

    if (function_exists('posix_geteuid')) {
        $owner = @fileowner($directory);
        $uid = @posix_geteuid();
        if ($owner === false || $uid === false || $owner !== $uid) return false;
    }

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
    if (!ensure_sentryiq_data_directory() || !is_file(DATA_FILE) || is_link(DATA_FILE)) return false;

    $raw = @file_get_contents(DATA_FILE);
    if (!is_string($raw) || $raw === '' || strlen($raw) > 32 * 1024 * 1024) return false;

    $envelope = json_decode($raw, true, 16, JSON_THROW_ON_ERROR);
    if (!is_array($envelope)) return false;
    if (($envelope['version'] ?? null) !== SENTRYIQ_VAULT_VERSION) return false;
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

    $kdf = [
        'name' => 'argon2id13',
        'opslimit' => (int)($envelope['kdf']['opslimit'] ?? 0),
        'memlimit' => (int)($envelope['kdf']['memlimit'] ?? 0),
        'salt' => base64_encode($salt),
    ];
    $aadExpected = vault_build_aad($kdf);
    if (!hash_equals($aadExpected, $aadStored)) return false;

    return [
        'envelope' => $envelope,
        'salt' => $salt,
        'kdf' => $kdf,
        'nonce' => $nonce,
        'tag' => $tag,
        'ciphertext' => $ciphertext,
        'aad' => $aadExpected,
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
    } catch (Throwable) {
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
    } catch (Throwable) {
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

    if (!@chmod($tmp, 0600)) return false;
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

function save_passwords(array $dataMatrix, ?string $explicitKey = null): bool
{
    $key = $explicitKey ?? ($_SESSION['master_key'] ?? null);
    if (!is_string($key) || strlen($key) !== 32) return false;

    $parts = vault_read_envelope();
    if ($parts === false) return false;

    return vault_write_encrypted_records($dataMatrix, $key, $parts['kdf']);
}

function cleanup_expired_tokens(): void
{
    if (!ensure_sentryiq_data_directory()) return;
    foreach (glob(SENTRYIQ_DATA_DIR . '/token_*.json') ?: [] as $file) {
        if (is_link($file)) { @unlink($file); continue; }
        $raw = @file_get_contents($file);
        $token = is_string($raw) ? json_decode($raw, true) : null;
        if (!is_array($token) || empty($token['expires']) || (int)$token['expires'] <= time()) @unlink($file);
    }
}

function cache_vault_icon(string $url, string $entryId): array
{
    $empty = ['icon_type'=>null,'icon_path'=>null,'icon_source'=>null,'icon_fetched_at'=>null];
    $url = vault_validate_url($url);
    if ($url === false || $url === '' || !function_exists('curl_init')) return $empty;

    $page = curl_vault_asset($url, false);
    if ($page === null) return $empty;

    $pageUrl = $page['final_url'] !== '' ? $page['final_url'] : $url;
    $pageUrl = vault_validate_url($pageUrl);
    if ($pageUrl === false || $pageUrl === '') return $empty;

    $pageParts = parse_url($pageUrl);
    $origin = 'https://' . strtolower((string)$pageParts['host']);
    $faviconUrl = null;
    $ogImageUrl = null;

    if (stripos($page['content_type'], 'text/html') !== false || stripos($page['body'], '<html') !== false) {
        libxml_use_internal_errors(true);
        $dom = new DOMDocument();
        if (@$dom->loadHTML($page['body'])) {
            foreach ($dom->getElementsByTagName('link') as $link) {
                $rel = strtolower(trim((string)$link->getAttribute('rel')));
                $href = trim((string)$link->getAttribute('href'));
                if ($href !== '' && (str_contains($rel, 'icon') || str_contains($rel, 'shortcut'))) {
                    $faviconUrl = resolve_vault_asset_url($href, $pageUrl, $origin);
                    if ($faviconUrl !== null) break;
                }
            }
            foreach ($dom->getElementsByTagName('meta') as $meta) {
                $property = strtolower(trim((string)$meta->getAttribute('property')));
                $name = strtolower(trim((string)$meta->getAttribute('name')));
                $content = trim((string)$meta->getAttribute('content'));
                if ($content !== '' && ($property === 'og:image' || $name === 'og:image')) {
                    $ogImageUrl = resolve_vault_asset_url($content, $pageUrl, $origin);
                    if ($ogImageUrl !== null) break;
                }
            }
        }
        libxml_clear_errors();
    }

    $candidates = [];
    if ($faviconUrl !== null) $candidates[] = ['type'=>'favicon','url'=>$faviconUrl];
    if ($ogImageUrl !== null) $candidates[] = ['type'=>'og_image','url'=>$ogImageUrl];
    $candidates[] = ['type'=>'favicon','url'=>$origin.'/favicon.ico'];

    foreach ($candidates as $candidate) {
        $candidateUrl = vault_validate_url($candidate['url']);
        if ($candidateUrl === false || $candidateUrl === '') continue;
        $image = curl_vault_asset($candidateUrl, true);
        if ($image === null) continue;

        $directory = SENTRYIQ_DATA_DIR . '/vault_icons';
        if (!is_dir($directory) && !@mkdir($directory, 0700, true) && !is_dir($directory)) return $empty;
        if (!sentryiq_is_trusted_data_directory(SENTRYIQ_DATA_DIR)) return $empty;

        $extension = detect_vault_image_extension($image['body'], $image['content_type']);
        if ($extension === null) continue;

        $filePath = $directory . '/' . hash('sha256', $entryId) . '.' . $extension;
        if (@file_put_contents($filePath, $image['body'], LOCK_EX) === false) continue;
        @chmod($filePath, 0600);

        return [
            'icon_type' => $candidate['type'],
            'icon_path' => $filePath,
            'icon_source' => $candidateUrl,
            'icon_fetched_at' => date('c'),
        ];
    }

    return $empty;
}

function curl_vault_asset(string $url, bool $imageOnly): ?array
{
    $url = vault_validate_url($url);
    if ($url === false || $url === '') return null;

    $ch = curl_init($url);
    if ($ch === false) return null;

    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_USERAGENT => 'SentryIQ/2.0',
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_HTTPHEADER => $imageOnly
            ? ['Accept: image/avif,image/webp,image/png,image/jpeg,image/gif,image/x-icon,*/*;q=0.8']
            : ['Accept: text/html,application/xhtml+xml;q=0.9,*/*;q=0.8'],
    ]);

    $body = curl_exec($ch);
    $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $contentType = strtolower((string)curl_getinfo($ch, CURLINFO_CONTENT_TYPE));
    $finalUrl = (string)curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
    $location = (string)curl_getinfo($ch, CURLINFO_REDIRECT_URL);
    curl_close($ch);

    if ($httpCode >= 300 && $httpCode < 400) {
        // Redirects are deliberately rejected rather than followed. This closes
        // the SSRF pivot from a public URL to private/internal destinations.
        return null;
    }

    if (!is_string($body) || $body === '' || $httpCode < 200 || $httpCode >= 400 || strlen($body) > 5 * 1024 * 1024) return null;
    if ($location !== '') return null;

    return ['body'=>$body,'content_type'=>$contentType,'final_url'=>$finalUrl];
}

function resolve_vault_asset_url(string $assetUrl, string $pageUrl, string $origin): ?string
{
    $assetUrl = trim(html_entity_decode($assetUrl, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    if ($assetUrl === '' || str_starts_with(strtolower($assetUrl), 'data:') || str_starts_with(strtolower($assetUrl), 'javascript:')) return null;

    if (preg_match('#^https://#i', $assetUrl)) return vault_validate_url($assetUrl) ?: null;
    if (str_starts_with($assetUrl, '//')) return vault_validate_url('https:' . $assetUrl) ?: null;
    if (str_starts_with($assetUrl, '/')) return vault_validate_url(rtrim($origin, '/') . $assetUrl) ?: null;

    $path = parse_url($pageUrl, PHP_URL_PATH) ?: '/';
    $directory = rtrim(str_replace('\\', '/', dirname($path)), '/');
    return vault_validate_url(rtrim($origin, '/') . ($directory !== '' ? $directory : '') . '/' . ltrim($assetUrl, '/')) ?: null;
}

function detect_vault_image_extension(string $body, string $contentType): ?string
{
    $mime = '';
    $imageInfo = @getimagesizefromstring($body);
    if ($imageInfo !== false && !empty($imageInfo['mime'])) $mime = strtolower($imageInfo['mime']);
    elseif ($contentType !== '') $mime = strtolower(trim(explode(';', $contentType)[0]));

    return match ($mime) {
        'image/png' => 'png',
        'image/jpeg', 'image/jpg' => 'jpg',
        'image/gif' => 'gif',
        'image/webp' => 'webp',
        'image/avif' => 'avif',
        'image/x-icon', 'image/vnd.microsoft.icon' => 'ico',
        default => null,
    };
}

cleanup_expired_tokens();
