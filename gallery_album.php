<?php

declare(strict_types=1);

require_once __DIR__ . '/security_bootstrap.php';
sentryiq_security_bootstrap();
sentryiq_require_auth();
sentryiq_require_csrf();

$configFile = __DIR__ . '/sentryiq_config.php';
$config = is_file($configFile) ? require $configFile : [];
$dataDir = is_array($config) ? rtrim((string)($config['data_dir'] ?? ''), '/') : '';
if ($dataDir === '' || !str_starts_with($dataDir, '/') || !is_dir($dataDir) || is_link($dataDir)) {
    http_response_code(503);
    exit('SentryIQ secure runtime is unavailable.');
}

require_once __DIR__ . '/cloud/Gallery/Albums/AlbumStore.php';

use SentryIQCloud\Gallery\Albums\AlbumStore;

header('Content-Type: application/json; charset=utf-8');

try {
    $store = new AlbumStore($dataDir . '/gallery/albums.json');
    $action = (string)($_POST['action'] ?? '');

    if ($action === 'create') {
        $store->create((string)($_POST['name'] ?? ''));
        log_security_event('GALLERY_ALBUM_CREATED', get_visitor_ip(), $_SESSION['app_username'] ?? 'unknown');
        echo json_encode(['status' => 'ok', 'albums' => $store->albums()], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($action === 'move') {
        $photoId = (string)($_POST['photo_id'] ?? '');
        $album = (string)($_POST['album'] ?? '');
        $thumbnail = $dataDir . '/gallery/thumbnails/' . substr($photoId, 0, 2) . '/' . $photoId . '.webp';
        if (!preg_match('/^[a-f0-9]{32}$/', $photoId) || !is_file($thumbnail) || is_link($thumbnail)) {
            throw new RuntimeException('Photo does not exist.');
        }
        $store->move($photoId, $album);
        log_security_event('GALLERY_PHOTO_MOVED', get_visitor_ip(), $_SESSION['app_username'] ?? 'unknown', ['photo_id' => $photoId, 'album' => $album]);
        echo json_encode(['status' => 'ok', 'album' => $store->albumFor($photoId)], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        exit;
    }

    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Invalid gallery action.']);
} catch (RuntimeException $exception) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => $exception->getMessage()], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
}
