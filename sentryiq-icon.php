<?php

declare(strict_types=1);

header('Content-Type: image/png');
header('Cache-Control: public, max-age=31536000, immutable');
header('X-Content-Type-Options: nosniff');

$icon = __DIR__ . '/assets/images/sentryiq-icon.png';
if (!is_file($icon)) {
    http_response_code(500);
    exit;
}

readfile($icon);
