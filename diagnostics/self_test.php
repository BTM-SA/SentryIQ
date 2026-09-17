<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/app/Security/SecurityBootstrap.php';
sentryiq_security_bootstrap();

$root = dirname(__DIR__);
$configFile = $root . '/sentryiq_config.php';
$installed = is_file($configFile) && !is_link($configFile);
$authenticated = isset($_SESSION['master_key']) && is_string($_SESSION['master_key']) && strlen($_SESSION['master_key']) === 32;

if ($installed && !$authenticated) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    exit('Authentication required. Unlock SentryIQ, then open this diagnostic again.');
}

header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

$results = [];
function add_check(string $group, string $name, bool $ok, string $detail = ''): void
{
    global $results;
    $results[] = ['group' => $group, 'name' => $name, 'ok' => $ok, 'detail' => $detail];
}
function safe_path_state(string $path): array
{
    return [
        'exists' => is_file($path) || is_dir($path),
        'file' => is_file($path),
        'dir' => is_dir($path),
        'link' => is_link($path),
        'readable' => is_readable($path),
        'size' => is_file($path) ? filesize($path) : null,
    ];
}

add_check('Environment', 'PHP version', PHP_VERSION_ID >= 80300, PHP_VERSION);
add_check('Environment', 'HTTPS request', PHP_SAPI === 'cli' || (($_SERVER['HTTPS'] ?? '') === 'on') || (string)($_SERVER['SERVER_PORT'] ?? '') === '443');
add_check('Environment', 'Session active', session_status() === PHP_SESSION_ACTIVE, session_status() === PHP_SESSION_ACTIVE ? 'active' : 'inactive');
add_check('Environment', 'Installed flag', $installed, $installed ? 'installed' : 'not installed');
add_check('Environment', 'Authenticated session', !$installed || $authenticated, $authenticated ? 'authenticated' : 'not authenticated');

$config = null;
$dataDir = '';
if ($installed && is_readable($configFile)) {
    try {
        $loaded = require $configFile;
        $config = is_array($loaded) ? $loaded : null;
    } catch (Throwable $e) {
        add_check('Runtime configuration', 'Configuration loads', false, $e::class);
    }
}
add_check('Runtime configuration', 'Configuration array', !$installed || is_array($config));
if (is_array($config)) {
    $dataDir = rtrim((string)($config['data_dir'] ?? ''), '/');
    $baseUrl = trim((string)($config['base_url'] ?? ''));
    add_check('Runtime configuration', 'Data directory configured', $dataDir !== '', $dataDir !== '' ? 'configured' : 'missing');
    add_check('Runtime configuration', 'HTTPS base URL configured', $baseUrl !== '' && str_starts_with($baseUrl, 'https://'), $baseUrl !== '' ? 'configured' : 'missing');
    if ($dataDir !== '') {
        add_check('Runtime storage', 'Runtime directory exists', is_dir($dataDir) && !is_link($dataDir));
        add_check('Runtime storage', 'Runtime directory is writable', is_dir($dataDir) && is_writable($dataDir));
        add_check('Runtime storage', 'Vault engine exists', is_file($dataDir . '/vault_engine.php') && !is_link($dataDir . '/vault_engine.php'));
        add_check('Runtime storage', 'Email template exists', is_file($dataDir . '/email_template.php') && !is_link($dataDir . '/email_template.php'));
        add_check('Runtime storage', 'Vault encrypted file exists', is_file($dataDir . '/passwords.enc') && !is_link($dataDir . '/passwords.enc'));
    }
}

$wideLogoPath = $root . '/assets/images/sentryiq-logo-wide.webp';
$wideLogoState = safe_path_state($wideLogoPath);
add_check(
    'Branding',
    'Wide logo file',
    $wideLogoState['file'] && !$wideLogoState['link'] && $wideLogoState['readable'],
    $wideLogoState['file'] && !$wideLogoState['link']
        ? 'file' . ($wideLogoState['size'] !== null ? ', ' . $wideLogoState['size'] . ' bytes' : '')
        : ($wideLogoState['link'] ? 'SYMLINK' : 'missing')
);
if ($wideLogoState['file'] && !$wideLogoState['link']) {
    $logoInfo = @getimagesize($wideLogoPath);
    $logoDimensionsOk = is_array($logoInfo) && strtolower((string)($logoInfo['mime'] ?? '')) === 'image/webp' && (int)($logoInfo[0] ?? 0) > 0 && (int)($logoInfo[1] ?? 0) > 0;
    $logoDimensionDetail = is_array($logoInfo)
        ? ((string)($logoInfo[0] ?? '?') . '×' . (string)($logoInfo[1] ?? '?') . ', ' . (string)($logoInfo['mime'] ?? 'unknown'))
        : 'unreadable image';
    add_check('Branding', 'Wide logo dimensions/type', $logoDimensionsOk, $logoDimensionDetail);
    $logoHash = @hash_file('sha256', $wideLogoPath);
    add_check('Branding', 'Wide logo SHA-256', is_string($logoHash) && preg_match('/^[a-f0-9]{64}$/', $logoHash) === 1, is_string($logoHash) ? $logoHash : 'hash unavailable');
}
$logoCssPath = $root . '/assets/css/pm_style.css';
$logoCss = is_readable($logoCssPath) ? @file_get_contents($logoCssPath) : false;
add_check('Branding', 'CSS logo reference', is_string($logoCss) && str_contains($logoCss, "../images/sentryiq-logo-wide.webp"), is_string($logoCss) && str_contains($logoCss, "../images/sentryiq-logo-wide.webp") ? 'assets/css/pm_style.css → ../images/sentryiq-logo-wide.webp' : 'expected reference missing');
$legacyWideLogoPath = $root . '/sentryiq-logo-wide.webp';
add_check('Branding', 'Legacy root logo absent', !is_file($legacyWideLogoPath) && !is_link($legacyWideLogoPath), (!is_file($legacyWideLogoPath) && !is_link($legacyWideLogoPath)) ? 'not present at root' : 'legacy root logo still present');

$files = [
    'Public entry points' => [
        'index.php' => 'index.php',
        'gallery.php' => 'gallery.php',
        'documents.php' => 'documents.php',
        'first_run.php' => 'first_run.php',
        'sentryiq-icon.php' => 'sentryiq-icon.php',
        'vault-icon.php' => 'vault-icon.php',
        'auth_flow.php bridge' => 'auth_flow.php',
        'security_bootstrap.php bridge' => 'security_bootstrap.php',
        'passkey_setup.php' => 'passkey_setup.php',
        'passkey_login.php' => 'passkey_login.php',
        'passkey_auth.php' => 'passkey_auth.php',
        'passkeys.php' => 'passkeys.php',
        'document_upload.php' => 'document_upload.php',
        'document_download.php' => 'document_download.php',
        'document_delete.php' => 'document_delete.php',
        'gallery_album.php' => 'gallery_album.php',
        'gallery_bulk_delete.php' => 'gallery_bulk_delete.php',
        'gallery_delete.php' => 'gallery_delete.php',
        'gallery_image.php' => 'gallery_image.php',
        'gallery_settings.php' => 'gallery_settings.php',
        'gallery_upload.php' => 'gallery_upload.php',
        'record_actions.php' => 'record_actions.php',
        'records_category_actions.php' => 'records_category_actions.php',
        'records_view_data.php' => 'records_view_data.php',
        'vault_actions.php' => 'vault_actions.php',
        'vault_category_actions.php' => 'vault_category_actions.php',
        'vault_folder_data.php' => 'vault_folder_data.php',
        'security-features.php' => 'security-features.php',
        'security_log.php' => 'security_log.php',
        'system_log.php' => 'system_log.php',
    ],
    'Application implementations' => [
        'app/Auth/PasskeySetup.php' => 'app/Auth/PasskeySetup.php',
        'app/Auth/PasskeyLogin.php' => 'app/Auth/PasskeyLogin.php',
        'app/Auth/PasskeyAuth.php' => 'app/Auth/PasskeyAuth.php',
        'app/Auth/Passkeys.php' => 'app/Auth/Passkeys.php',
        'app/Auth/AuthFlow.php' => 'app/Auth/AuthFlow.php',
        'app/Documents/Upload.php' => 'app/Documents/Upload.php',
        'app/Documents/Download.php' => 'app/Documents/Download.php',
        'app/Documents/Delete.php' => 'app/Documents/Delete.php',
        'app/Gallery/AlbumActions.php' => 'app/Gallery/AlbumActions.php',
        'app/Gallery/BulkDelete.php' => 'app/Gallery/BulkDelete.php',
        'app/Gallery/Delete.php' => 'app/Gallery/Delete.php',
        'app/Gallery/Image.php' => 'app/Gallery/Image.php',
        'app/Gallery/Settings.php' => 'app/Gallery/Settings.php',
        'app/Gallery/Upload.php' => 'app/Gallery/Upload.php',
        'app/Vault/Actions.php' => 'app/Vault/Actions.php',
        'app/Vault/CategoryActions.php' => 'app/Vault/CategoryActions.php',
        'app/Vault/FolderData.php' => 'app/Vault/FolderData.php',
        'app/Vault/RecordActions.php' => 'app/Vault/RecordActions.php',
        'app/Vault/RecordsCategoryActions.php' => 'app/Vault/RecordsCategoryActions.php',
        'app/Vault/RecordsViewData.php' => 'app/Vault/RecordsViewData.php',
        'app/Vault/DashboardActions.php' => 'app/Vault/DashboardActions.php',
        'app/Vault/DashboardList.php' => 'app/Vault/DashboardList.php',
        'app/Security/SecurityBootstrap.php' => 'app/Security/SecurityBootstrap.php',
        'app/Security/SecurityFeatures.php' => 'app/Security/SecurityFeatures.php',
        'app/Security/SecurityLog.php' => 'app/Security/SecurityLog.php',
        'app/Security/SystemLog.php' => 'app/Security/SystemLog.php',
        'app/Security/FirstRun.php' => 'app/Security/FirstRun.php',
    ],
    'Static assets' => [
        'assets/css/pm_style.css' => 'assets/css/pm_style.css',
        'assets/js/vault_folders.js' => 'assets/js/vault_folders.js',
        'assets/js/records_view.js' => 'assets/js/records_view.js',
        'assets/js/safari.js' => 'assets/js/safari.js',
        'assets/images/sentryiq-logo-wide.webp' => 'assets/images/sentryiq-logo-wide.webp',
        '.htaccess' => '.htaccess',
    ],
];
foreach ($files as $group => $groupFiles) {
    foreach ($groupFiles as $label => $relative) {
        $state = safe_path_state($root . '/' . $relative);
        add_check($group, $label, $state['file'] || $state['dir'], $state['link'] ? 'SYMLINK' : ($state['file'] ? ('file' . ($state['size'] !== null ? ', ' . $state['size'] . ' bytes' : '')) : 'directory'));
    }
}

$assetUrls = [
    'CSS' => ['url' => 'assets/css/pm_style.css', 'expectedStatus' => 200, 'expectedContentType' => 'text/css'],
    'Vault folders JS' => ['url' => 'assets/js/vault_folders.js', 'expectedStatus' => 200, 'expectedContentType' => 'text/javascript'],
    'Records JS' => ['url' => 'assets/js/records_view.js', 'expectedStatus' => 200, 'expectedContentType' => 'text/javascript'],
    'Safari JS' => ['url' => 'assets/js/safari.js', 'expectedStatus' => 200, 'expectedContentType' => 'text/javascript'],
    'Wide logo' => ['url' => 'assets/images/sentryiq-logo-wide.webp', 'expectedStatus' => 200, 'expectedContentType' => 'image/webp'],
    'Favicon/icon endpoint' => ['url' => 'sentryiq-icon.php', 'expectedStatus' => 200, 'expectedContentType' => 'image/png'],
];

$browserTests = [];
foreach ($assetUrls as $label => $spec) {
    $browserTests[] = ['type' => 'asset', 'label' => $label, 'url' => $spec['url'], 'expectedStatus' => $spec['expectedStatus'], 'expectedContentType' => $spec['expectedContentType']];
}
$endpointTests = [
    'index.php', 'documents.php', 'gallery.php', 'passkey_setup.php', 'passkey_login.php', 'passkey_auth.php', 'passkeys.php',
    'document_upload.php', 'document_download.php', 'document_delete.php',
    'gallery_album.php', 'gallery_bulk_delete.php', 'gallery_delete.php', 'gallery_image.php', 'gallery_settings.php', 'gallery_upload.php',
    'record_actions.php', 'records_category_actions.php', 'records_view_data.php', 'vault_actions.php', 'vault_category_actions.php', 'vault_folder_data.php',
    'security-features.php', 'security_log.php', 'system_log.php'
];
foreach ($endpointTests as $url) $browserTests[] = ['type' => 'endpoint', 'label' => $url, 'url' => $url];

$csrf = function_exists('sentryiq_csrf_token') ? sentryiq_csrf_token() : '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['diagnostic_log']) && $dataDir !== '' && is_dir($dataDir) && !is_link($dataDir)) {
    $raw = (string)($_POST['diagnostic_log'] ?? '');
    $decoded = json_decode($raw, true);
    $safe = is_array($decoded) ? $decoded : [];
    $record = [
        'timestamp' => date('c'),
        'stage' => 'SELF_TEST_BROWSER_RESULTS',
        'php_version' => PHP_VERSION,
        'browser' => substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 300),
        'results' => $safe,
    ];
    $log = $dataDir . '/diagnostic_self_test.log';
    $line = json_encode($record, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if (is_string($line)) {
        @file_put_contents($log, $line . PHP_EOL, FILE_APPEND | LOCK_EX);
        @chmod($log, 0600);
    }
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => true]);
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="csrf-token" content="<?= htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8') ?>">
<title>SentryIQ Self-Test</title>
<style>
body{font-family:system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;background:#f4f6f8;color:#20252b;margin:0;padding:24px}.wrap{max-width:1050px;margin:auto}.card{background:#fff;border:1px solid #dfe4ea;border-radius:10px;padding:20px;margin-bottom:18px;box-shadow:0 2px 8px rgba(0,0,0,.04)}h1{margin:0 0 6px;font-size:24px}h2{font-size:17px;margin:22px 0 10px}.summary{font-size:15px;margin:12px 0}.ok{color:#137333;font-weight:600}.bad{color:#b3261e;font-weight:600}.warn{color:#8a5a00;font-weight:600}table{width:100%;border-collapse:collapse;font-size:13px}th,td{padding:9px 8px;border-bottom:1px solid #edf0f3;text-align:left;vertical-align:top}th{font-weight:650}pre{white-space:pre-wrap;word-break:break-word;background:#f7f8fa;border:1px solid #e5e8ec;padding:12px;border-radius:7px;max-height:420px;overflow:auto}.muted{color:#6b7280}button{border:0;border-radius:7px;padding:10px 14px;font-weight:600;cursor:pointer}#run{background:#1f6feb;color:#fff}#copy{background:#e9eef5;margin-left:8px}.status-dot{font-weight:700}.footer{font-size:12px;color:#6b7280}.danger{background:#fff4f2;border-color:#f1c7c1}
</style>
</head>
<body>
<div class="wrap">
<div class="card">
<h1>SentryIQ Self-Test</h1>
<div class="summary">This diagnostic checks the deployed filesystem/configuration and then tests the real browser URLs from this session.</div>
<button id="run" type="button">Run browser checks</button><button id="copy" type="button">Copy report</button>
</div>
<div class="card">
<h2>Server-side checks</h2>
<table><thead><tr><th>Group</th><th>Check</th><th>Status</th><th>Detail</th></tr></thead><tbody>
<?php foreach ($results as $row): ?>
<tr><td><?= htmlspecialchars($row['group'], ENT_QUOTES, 'UTF-8') ?></td><td><?= htmlspecialchars($row['name'], ENT_QUOTES, 'UTF-8') ?></td><td class="<?= $row['ok'] ? 'ok' : 'bad' ?>"><?= $row['ok'] ? 'PASS' : 'FAIL' ?></td><td><?= htmlspecialchars($row['detail'], ENT_QUOTES, 'UTF-8') ?></td></tr>
<?php endforeach; ?>
</tbody></table>
</div>
<div class="card">
<h2>Browser URL checks</h2>
<div id="browser-summary" class="muted">Not run yet.</div>
<table id="browser-table"><thead><tr><th>Type</th><th>URL</th><th>Status</th><th>HTTP</th><th>Content type</th><th>Final URL</th></tr></thead><tbody></tbody></table>
</div>
<div class="card danger">
<h2>Report</h2>
<pre id="report">Run the browser checks. The full JSON report will appear here.</pre>
<div class="footer">No passwords, vault keys, tokens, encrypted payloads, or credential contents are included in this report.</div>
</div>
</div>
<script>
const tests=<?= json_encode($browserTests, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE) ?>;
const csrf=<?= json_encode($csrf) ?>;
let reportData={serverChecks:<?= json_encode($results, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE) ?>,browserChecks:[]};
function esc(v){return String(v??'').replace(/[&<>"']/g,m=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[m]));}
async function checkOne(test){const started=performance.now();try{const r=await fetch(test.url,{credentials:'same-origin',cache:'no-store',redirect:'follow',headers:{'Accept':'*/*'}});const ms=Math.round(performance.now()-started);const contentType=r.headers.get('content-type')||'';const statusOk=Number.isInteger(test.expectedStatus)?r.status===test.expectedStatus:r.status!==404;const contentTypeOk=!test.expectedContentType||contentType.toLowerCase().startsWith(test.expectedContentType.toLowerCase());return {...test,ok:statusOk&&contentTypeOk,status:r.status,statusText:r.statusText,finalUrl:r.url,timeMs:ms,contentType};}catch(e){return {...test,ok:false,status:0,statusText:String(e&&e.message||e),finalUrl:'',timeMs:Math.round(performance.now()-started),contentType:''};}}
async function runChecks(){const tbody=document.querySelector('#browser-table tbody');tbody.innerHTML='';document.querySelector('#browser-summary').textContent='Running checks…';const out=[];for(const test of tests){const item=await checkOne(test);out.push(item);const tr=document.createElement('tr');tr.innerHTML=`<td>${esc(item.type)}</td><td><code>${esc(item.url)}</code></td><td class="${item.ok?'ok':'bad'}">${item.ok?'PASS':'FAIL'}</td><td>${esc(item.status)} ${esc(item.statusText)}</td><td>${esc(item.contentType)}</td><td>${esc(item.finalUrl)}</td>`;tbody.appendChild(tr);}reportData.browserChecks=out;const failed=out.filter(x=>!x.ok);document.querySelector('#browser-summary').innerHTML=failed.length?`<span class="bad">${failed.length} browser check(s) failed.</span>`:`<span class="ok">All ${out.length} browser URL checks passed.</span>`;document.querySelector('#report').textContent=JSON.stringify(reportData,null,2);try{await fetch(location.pathname,{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:new URLSearchParams({diagnostic_log:JSON.stringify(reportData),csrf_token:csrf}).toString()});}catch(e){}}

document.querySelector('#run').addEventListener('click',runChecks);document.querySelector('#copy').addEventListener('click',async()=>{try{await navigator.clipboard.writeText(document.querySelector('#report').textContent);document.querySelector('#copy').textContent='Copied';setTimeout(()=>document.querySelector('#copy').textContent='Copy report',1400);}catch(e){}});
</script>
</body>
</html>
