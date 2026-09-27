<?php

declare(strict_types=1);

require_once __DIR__ . '/security_bootstrap.php';
sentryiq_security_bootstrap();
sentryiq_require_auth();

header('Content-Type: application/json; charset=utf-8');

$configFile = __DIR__ . '/sentryiq_config.php';
$config = is_file($configFile) ? require $configFile : [];
$dataDir = is_array($config) ? rtrim((string)($config['data_dir'] ?? ''), '/') : '';
if ($dataDir === '' || !str_starts_with($dataDir, '/') || !is_dir($dataDir) || is_link($dataDir)) {
    http_response_code(503);
    echo json_encode(['status' => 'error', 'message' => 'SentryIQ secure runtime is unavailable.']);
    exit;
}

require_once __DIR__ . '/app/Updates/UpdateManager.php';

try {
    $manager = new SentryIQ\Updates\UpdateManager(__DIR__, $dataDir);
    echo json_encode(['status' => 'ok'] + $manager->checkLatest(false), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
} catch (Throwable $exception) {
    http_response_code(503);
    echo json_encode(['status' => 'error', 'message' => $exception->getMessage()]);
}
