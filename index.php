<?php

declare(strict_types=1);

require_once __DIR__ . '/security_bootstrap.php';
sentryiq_security_bootstrap();

define('SENTRYIQ_CONFIG_FILE', __DIR__ . '/sentryiq_config.php');

if (!is_file(SENTRYIQ_CONFIG_FILE)) {
    if (is_file(__DIR__ . '/first_run.php')) { require __DIR__ . '/first_run.php'; exit; }
    http_response_code(503); exit('SentryIQ installation is unavailable.');
}
$config = require SENTRYIQ_CONFIG_FILE;
if (!is_array($config)) { http_response_code(503); exit('SentryIQ configuration is unavailable.'); }
$dataDir = rtrim((string)($config['data_dir'] ?? ''), '/');
if ($dataDir === '' || !str_starts_with($dataDir, '/') || !is_dir($dataDir) || !is_file($dataDir . '/vault_engine.php') || !is_file($dataDir . '/email_template.php')) { http_response_code(503); exit('SentryIQ secure runtime is unavailable.'); }
require_once $dataDir . '/vault_engine.php';
require_once $dataDir . '/email_template.php';
require_once __DIR__ . '/auth_flow.php';
date_default_timezone_set('Africa/Johannesburg');
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['lock_vault'])) { sentryiq_require_csrf(); log_security_event('VAULT_LOCKED', get_visitor_ip(), $_SESSION['app_username'] ?? 'unknown'); sentryiq_lock_vault(); header('Location: index.php'); exit; }
$vault_authenticated = isset($_SESSION['master_key']) && is_string($_SESSION['master_key']) && strlen($_SESSION['master_key']) === 32;
$passwords = [];
$vault_error = false;
if ($vault_authenticated) { $passwords = load_passwords(); if ($passwords === false) { log_security_event('VAULT_READ_FAILURE', get_visitor_ip(), $_SESSION['app_username'] ?? 'unknown'); sentryiq_lock_vault(); $vault_authenticated = false; $vault_error = true; } }
$systemConfig = [];
foreach (is_array($passwords) ? $passwords : [] as $entry) { if (($entry['type'] ?? '') === 'system_config') { $systemConfig = $entry; break; } }
$sys_user = trim((string)($systemConfig['app_username'] ?? $_SESSION['app_username'] ?? ''));
$sys_email = trim((string)($systemConfig['2fa_email'] ?? TWO_FA_EMAIL));
$active_pane = (string)($_GET['pane'] ?? 'view');
if (!in_array($active_pane, ['view','records','add','settings','details','edit'], true)) $active_pane = 'view';
$csrf = sentryiq_csrf_token();
?>
<!DOCTYPE html>