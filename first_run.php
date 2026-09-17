<?php

if (is_file(__DIR__ . '/sentryiq_config.php')) {
    http_response_code(404);
    exit('Not found.');
}

require __DIR__ . '/app/Security/FirstRun.php';
