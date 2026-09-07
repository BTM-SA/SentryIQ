<?php

declare(strict_types=1);

require_once __DIR__ . '/security_bootstrap.php';
sentryiq_security_bootstrap();

header('Content-Type: application/json; charset=utf-8');

function passkey_json(array $payload, int $status = 200): never
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

function passkey_b64url_encode(string $value): string
{
    return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
}

function passkey_b64url_decode(string $value): string|false
{
    if ($value === '' || !preg_match('/^[A-Za-z0-9_-]+$/', $value)) return false;
    $padding = strlen($value) % 4;
    if ($padding) $value .= str_repeat('=', 4 - $padding);
    return base64_decode(strtr($value, '-_', '+/'), true);
}

function passkey_config(): array
{
    $configFile = __DIR__ . '/sentryiq_config.php';
    $config = is_file($configFile) ? require $configFile : [];
    if (!is_array($config)) throw new RuntimeException('Invalid SentryIQ configuration.');
    return $config;
}

function passkey_origin_and_rp_id(): array
{
    $config = passkey_config();
    $baseUrl = trim((string)($config['base_url'] ?? ''));
    $parts = parse_url($baseUrl);
    if (!is_array($parts) || strtolower((string)($parts['scheme'] ?? '')) !== 'https' || empty($parts['host'])) {
        throw new RuntimeException('SentryIQ HTTPS origin is unavailable.');
    }
    $host = strtolower((string)$parts['host']);
    if (!preg_match('/^[a-z0-9.-]+$/', $host)) throw new RuntimeException('Invalid SentryIQ relying party identifier.');
    $port = isset($parts['port']) ? ':' . (int)$parts['port'] : '';
    return ['origin' => 'https://' . $host . $port, 'rp_id' => $host];
}

function passkey_store_path(): string
{
    return SENTRYIQ_DATA_DIR . '/passkeys.json';
}

function passkey_read_store(): array
{
    if (!ensure_sentryiq_data_directory() || !is_file(passkey_store_path()) || is_link(passkey_store_path())) return [];
    $raw = @file_get_contents(passkey_store_path());
    $decoded = is_string($raw) ? json_decode($raw, true) : null;
    return is_array($decoded) ? $decoded : [];
}

function passkey_write_store(array $store): void
{
    if (!ensure_sentryiq_data_directory()) throw new RuntimeException('Secure passkey storage is unavailable.');
    $path = passkey_store_path();
    $tmp = $path . '.tmp-' . bin2hex(random_bytes(12));
    $json = json_encode($store, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    $handle = @fopen($tmp, 'xb');
    if ($handle === false) throw new RuntimeException('Unable to create secure passkey storage.');
    try {
        @chmod($tmp, 0600);
        if (fwrite($handle, $json) !== strlen($json)) throw new RuntimeException('Unable to write secure passkey storage.');
        fflush($handle);
        if (function_exists('fsync')) @fsync($handle);
    } finally {
        fclose($handle);
    }
    if (!@rename($tmp, $path)) { @unlink($tmp); throw new RuntimeException('Unable to activate secure passkey storage.'); }
    @chmod($path, 0600);
}

function passkey_validate_client_data(string $clientDataJson, string $expectedType, string $challenge, string $origin): array
{
    try { $client = json_decode($clientDataJson, true, 16, JSON_THROW_ON_ERROR); }
    catch (Throwable) { throw new RuntimeException('Invalid WebAuthn client data.'); }
    if (!is_array($client)) throw new RuntimeException('Invalid WebAuthn client data.');
    if (($client['type'] ?? '') !== $expectedType) throw new RuntimeException('Invalid WebAuthn ceremony type.');
    if (($client['origin'] ?? '') !== $origin) throw new RuntimeException('WebAuthn origin validation failed.');
    if (!is_string($client['challenge'] ?? null) || !hash_equals($challenge, (string)$client['challenge'])) throw new RuntimeException('WebAuthn challenge validation failed.');
    if (($client['crossOrigin'] ?? false) === true) throw new RuntimeException('Cross-origin WebAuthn is not permitted.');
    return $client;
}

function passkey_validate_authenticator_data(string $authData, string $rpId, bool $registration): array
{
    if (strlen($authData) < 37) throw new RuntimeException('Invalid WebAuthn authenticator data.');
    $expectedRpHash = hash('sha256', $rpId, true);
    if (!hash_equals($expectedRpHash, substr($authData, 0, 32))) throw new RuntimeException('WebAuthn relying party validation failed.');
    $flags = ord($authData[32]);
    if (($flags & 0x01) === 0 || ($flags & 0x04) === 0) throw new RuntimeException('User verification was not performed.');
    $counter = unpack('Ncounter', substr($authData, 33, 4))['counter'];
    $result = ['flags' => $flags, 'sign_count' => (int)$counter];

    if ($registration) {
        if (($flags & 0x40) === 0 || strlen($authData) < 55) throw new RuntimeException('Passkey attested credential data is missing.');
        $credentialLength = unpack('nlength', substr($authData, 53, 2))['length'];
        if ($credentialLength < 16 || strlen($authData) < 55 + $credentialLength) throw new RuntimeException('Invalid passkey credential identifier.');
        $result['credential_id'] = substr($authData, 55, $credentialLength);
    } elseif (($flags & 0x40) !== 0) {
        throw new RuntimeException('Invalid WebAuthn assertion data.');
    }
    return $result;
}

function passkey_derive_wrap_key(string $prf, string $salt): string
{
    if (strlen($prf) !== 32 || strlen($salt) !== 32) throw new RuntimeException('Invalid passkey key material.');
    return hash_hkdf('sha256', $prf, 32, 'SentryIQ passkey vault key', $salt);
}

function passkey_wrap_master_key(string $masterKey, string $prf, string $salt, string $credentialId): array
{
    if (strlen($masterKey) !== 32) throw new RuntimeException('Invalid vault key.');
    $key = passkey_derive_wrap_key($prf, $salt);
    $nonce = random_bytes(SENTRYIQ_GCM_NONCE_BYTES);
    $tag = '';
    $ciphertext = openssl_encrypt($masterKey, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $nonce, $tag, $credentialId, SENTRYIQ_GCM_TAG_BYTES);
    if ($ciphertext === false || strlen($tag) !== SENTRYIQ_GCM_TAG_BYTES) throw new RuntimeException('Unable to protect vault key with passkey.');
    return ['nonce' => passkey_b64url_encode($nonce), 'tag' => passkey_b64url_encode($tag), 'ciphertext' => passkey_b64url_encode($ciphertext)];
}

function passkey_unwrap_master_key(array $record, string $prf, string $salt, string $credentialId): string|false
{
    $nonce = passkey_b64url_decode((string)($record['nonce'] ?? ''));
    $tag = passkey_b64url_decode((string)($record['tag'] ?? ''));
    $ciphertext = passkey_b64url_decode((string)($record['ciphertext'] ?? ''));
    if ($nonce === false || $tag === false || $ciphertext === false || strlen($nonce) !== SENTRYIQ_GCM_NONCE_BYTES || strlen($tag) !== SENTRYIQ_GCM_TAG_BYTES) return false;
    $key = passkey_derive_wrap_key($prf, $salt);
    $plaintext = openssl_decrypt($ciphertext, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $nonce, $tag, $credentialId);
    return is_string($plaintext) && strlen($plaintext) === 32 ? $plaintext : false;
}

function passkey_options(): array
{
    $rp = passkey_origin_and_rp_id();
    $challenge = random_bytes(32);
    $user = (string)($_SESSION['app_username'] ?? '');
    if ($user === '') throw new RuntimeException('Authenticated username is unavailable.');
    $userId = hash('sha256', 'sentryiq-user|' . $user, true);
    $salt = random_bytes(32);
    $_SESSION['passkey_register_challenge'] = passkey_b64url_encode($challenge);
    $_SESSION['passkey_register_salt'] = passkey_b64url_encode($salt);
    return [
        'challenge' => passkey_b64url_encode($challenge),
        'rp' => ['id' => $rp['rp_id'], 'name' => 'SentryIQ'],
        'user' => ['id' => passkey_b64url_encode($userId), 'name' => $user, 'displayName' => 'SentryIQ Vault'],
        'pubKeyCredParams' => [['type' => 'public-key', 'alg' => -7]],
        'authenticatorSelection' => ['residentKey' => 'required', 'requireResidentKey' => true, 'userVerification' => 'required'],
        'timeout' => 60000,
        'attestation' => 'none',
        'extensions' => ['prf' => ['eval' => ['first' => $salt]]],
    ];
}

function passkey_request_options(): array
{
    $rp = passkey_origin_and_rp_id();
    $challenge = random_bytes(32);
    $_SESSION['passkey_login_challenge'] = passkey_b64url_encode($challenge);
    $store = passkey_read_store();
    $salt = null;
    foreach ($store as $record) {
        if (is_array($record) && isset($record['credential_id'], $record['prf_salt'])) { $salt = (string)$record['prf_salt']; break; }
    }
    if ($salt === null) throw new RuntimeException('No passkey is registered.');
    return [
        'challenge' => passkey_b64url_encode($challenge),
        'rpId' => $rp['rp_id'],
        'userVerification' => 'required',
        'timeout' => 60000,
        'extensions' => ['prf' => ['eval' => ['first' => passkey_b64url_decode($salt)]]],
    ];
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    try {
        $action = (string)($_GET['action'] ?? '');
        if ($action === 'register-options') {
            sentryiq_require_auth();
            passkey_json(['status' => 'ok', 'options' => passkey_options()]);
        }
        if ($action === 'login-options') {
            passkey_json(['status' => 'ok', 'options' => passkey_request_options()]);
        }
        passkey_json(['status' => 'error', 'message' => 'Unknown passkey operation.'], 400);
    } catch (Throwable $exception) {
        error_log('SentryIQ passkey options failure: ' . $exception::class . ': ' . $exception->getMessage());
        passkey_json(['status' => 'error', 'message' => $exception->getMessage()], 400);
    }
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') passkey_json(['status' => 'error', 'message' => 'POST required.'], 405);

try {
    $body = file_get_contents('php://input');
    $request = is_string($body) ? json_decode($body, true, 32, JSON_THROW_ON_ERROR) : null;
    if (!is_array($request)) throw new RuntimeException('Invalid passkey request.');
    $action = (string)($request['action'] ?? '');

    if ($action === 'register') {
        sentryiq_require_auth();
        sentryiq_require_csrf();
        $challenge = passkey_b64url_decode((string)($_SESSION['passkey_register_challenge'] ?? ''));
        $salt = passkey_b64url_decode((string)($_SESSION['passkey_register_salt'] ?? ''));
        $origin = passkey_origin_and_rp_id()['origin'];
        if ($challenge === false || strlen($challenge) !== 32 || $salt === false || strlen($salt) !== 32) throw new RuntimeException('Passkey registration session expired.');

        $rawId = passkey_b64url_decode((string)($request['rawId'] ?? ''));
        $clientData = passkey_b64url_decode((string)($request['clientDataJSON'] ?? ''));
        $authData = passkey_b64url_decode((string)($request['authenticatorData'] ?? ''));
        $publicKey = passkey_b64url_decode((string)($request['publicKey'] ?? ''));
        $prf = passkey_b64url_decode((string)($request['prf'] ?? ''));
        if ($rawId === false || $clientData === false || $authData === false || $publicKey === false || $prf === false) throw new RuntimeException('Incomplete passkey registration response.');
        if (strlen($rawId) < 16 || strlen($rawId) > 1024 || strlen($prf) !== 32) throw new RuntimeException('Invalid passkey registration response.');
        passkey_validate_client_data($clientData, 'webauthn.create', passkey_b64url_encode($challenge), $origin);
        $auth = passkey_validate_authenticator_data($authData, passkey_origin_and_rp_id()['rp_id'], true);
        if (!hash_equals($rawId, $auth['credential_id'])) throw new RuntimeException('Passkey credential identifier mismatch.');
        $keyResource = @openssl_pkey_get_public($publicKey);
        if ($keyResource === false) throw new RuntimeException('Invalid passkey public key.');
        $keyDetails = openssl_pkey_get_details($keyResource);
        if (!is_array($keyDetails) || (int)($keyDetails['type'] ?? -1) !== OPENSSL_KEYTYPE_EC || ($keyDetails['ec']['curve_name'] ?? '') !== 'prime256v1') throw new RuntimeException('SentryIQ requires an ES256 passkey.');

        $credentialId = passkey_b64url_encode($rawId);
        $wrapped = passkey_wrap_master_key((string)$_SESSION['master_key'], $prf, $salt, $credentialId);
        $store = passkey_read_store();
        $store[] = [
            'credential_id' => $credentialId,
            'public_key' => passkey_b64url_encode($publicKey),
            'username' => (string)$_SESSION['app_username'],
            'prf_salt' => passkey_b64url_encode($salt),
            'wrap' => $wrapped,
            'sign_count' => $auth['sign_count'],
            'created_at' => time(),
        ];
        passkey_write_store($store);
        unset($_SESSION['passkey_register_challenge'], $_SESSION['passkey_register_salt']);
        log_security_event('PASSKEY_REGISTERED', get_visitor_ip(), $_SESSION['app_username'] ?? 'unknown');
        passkey_json(['status' => 'ok', 'message' => 'Passkey registered successfully.']);
    }

    if ($action === 'login') {
        $challenge = passkey_b64url_decode((string)($_SESSION['passkey_login_challenge'] ?? ''));
        $origin = passkey_origin_and_rp_id()['origin'];
        if ($challenge === false || strlen($challenge) !== 32) throw new RuntimeException('Passkey login session expired.');
        $rawId = passkey_b64url_decode((string)($request['rawId'] ?? ''));
        $clientData = passkey_b64url_decode((string)($request['clientDataJSON'] ?? ''));
        $authData = passkey_b64url_decode((string)($request['authenticatorData'] ?? ''));
        $signature = passkey_b64url_decode((string)($request['signature'] ?? ''));
        $prf = passkey_b64url_decode((string)($request['prf'] ?? ''));
        if ($rawId === false || $clientData === false || $authData === false || $signature === false || $prf === false || strlen($prf) !== 32) throw new RuntimeException('Incomplete passkey authentication response.');
        passkey_validate_client_data($clientData, 'webauthn.get', passkey_b64url_encode($challenge), $origin);
        $auth = passkey_validate_authenticator_data($authData, passkey_origin_and_rp_id()['rp_id'], false);

        $store = passkey_read_store();
        $found = null;
        foreach ($store as $index => $record) {
            if (is_array($record) && isset($record['credential_id']) && hash_equals((string)$record['credential_id'], passkey_b64url_encode($rawId))) {
                $found = [$index, $record];
                break;
            }
        }
        if ($found === null) throw new RuntimeException('Unknown passkey.');
        [$index, $record] = $found;
        $publicKey = passkey_b64url_decode((string)($record['public_key'] ?? ''));
        $salt = passkey_b64url_decode((string)($record['prf_salt'] ?? ''));
        if ($publicKey === false || $salt === false || strlen($salt) !== 32) throw new RuntimeException('Stored passkey data is invalid.');
        $keyResource = @openssl_pkey_get_public($publicKey);
        if ($keyResource === false) throw new RuntimeException('Stored passkey public key is invalid.');
        $clientHash = hash('sha256', $clientData, true);
        $signedData = $authData . $clientHash;
        if (openssl_verify($signedData, $signature, $keyResource, OPENSSL_ALGO_SHA256) !== 1) throw new RuntimeException('Passkey signature verification failed.');

        $previousCounter = (int)($record['sign_count'] ?? 0);
        if ($auth['sign_count'] !== 0 && $previousCounter !== 0 && $auth['sign_count'] <= $previousCounter) throw new RuntimeException('Passkey signature counter validation failed.');
        $masterKey = passkey_unwrap_master_key((array)($record['wrap'] ?? []), $prf, $salt, (string)$record['credential_id']);
        if ($masterKey === false) throw new RuntimeException('Passkey could not unlock the vault.');
        $store[$index]['sign_count'] = max($previousCounter, $auth['sign_count']);
        passkey_write_store($store);
        unset($_SESSION['passkey_login_challenge']);
        sentryiq_mark_authenticated($masterKey, (string)$record['username']);
        log_security_event('SUCCESSFUL_VAULT_LOGIN_PASSKEY', get_visitor_ip(), (string)$record['username'], ['stage' => 'passkey']);
        passkey_json(['status' => 'ok']);
    }

    throw new RuntimeException('Unknown passkey operation.');
} catch (Throwable $exception) {
    error_log('SentryIQ passkey operation failed: ' . $exception::class . ': ' . $exception->getMessage());
    passkey_json(['status' => 'error', 'message' => $exception->getMessage()], 400);
}
