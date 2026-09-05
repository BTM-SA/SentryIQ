<?php

declare(strict_types=1);

require_once __DIR__ . '/security_bootstrap.php';
sentryiq_security_bootstrap();
sentryiq_require_auth();

$configFile = __DIR__ . '/sentryiq_config.php';
if (!is_file($configFile)) {
    http_response_code(503);
    exit('SentryIQ configuration is unavailable.');
}

$config = require $configFile;
if (!is_array($config)) {
    http_response_code(503);
    exit('SentryIQ configuration is unavailable.');
}

$dataDir = rtrim((string)($config['data_dir'] ?? ''), '/');
if ($dataDir === '' || !str_starts_with($dataDir, '/') || !is_dir($dataDir) || is_link($dataDir)) {
    http_response_code(503);
    exit('SentryIQ secure runtime is unavailable.');
}

$galleryRoot = $dataDir . '/gallery';
$csrf = sentryiq_csrf_token();

function gallery_collect_thumbnails(string $root): array
{
    $directory = $root . '/thumbnails';
    if (!is_dir($directory)) {
        return [];
    }

    $items = [];
    $buckets = scandir($directory);
    if ($buckets === false) {
        return [];
    }

    foreach ($buckets as $bucket) {
        if ($bucket === '.' || $bucket === '..' || !preg_match('/^[a-f0-9]{2}$/', $bucket)) {
            continue;
        }
        $files = scandir($directory . '/' . $bucket);
        if ($files === false) {
            continue;
        }
        foreach ($files as $file) {
            if (preg_match('/^[a-f0-9]{32}\.webp$/', $file)) {
                $items[] = [
                    'id' => substr($file, 0, -5),
                    'bucket' => $bucket,
                    'modified' => (int) (@filemtime($directory . '/' . $bucket . '/' . $file) ?: 0),
                ];
            }
        }
    }

    usort($items, static fn(array $a, array $b): int => $b['modified'] <=> $a['modified']);
    return $items;
}

$photos = gallery_collect_thumbnails($galleryRoot);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="csrf-token" content="<?php echo htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8'); ?>">
<title>SentryIQ Gallery</title>
<link rel="stylesheet" href="pm_style.css">
<style>
.gallery-header{display:flex;align-items:center;justify-content:space-between;gap:16px;flex-wrap:wrap}.gallery-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(180px,1fr));gap:16px;margin-top:20px}.gallery-card{background:#fff;border:1px solid #e9ecef;border-radius:12px;overflow:hidden;box-shadow:0 4px 12px rgba(0,0,0,.04)}.gallery-card img{display:block;width:100%;aspect-ratio:1/1;object-fit:cover;background:#f4f5f7}.gallery-card p{margin:0;padding:10px 12px;font-size:12px;color:#6c757d}.gallery-upload{margin-top:18px;padding:18px;border:1px solid #e9ecef;border-radius:12px;background:#fafbfc}.gallery-upload input[type=file]{width:100%;margin:8px 0 12px}.gallery-message{margin-top:12px}.gallery-empty{text-align:center;padding:40px 20px;color:#777}
</style>
</head>
<body>
<div class="box">
    <div class="gallery-header">
        <div class="sentryiq-vault-brand-wrap"><img class="sentryiq-vault-banner" src="sentryiq-logo-wide.webp" width="1952" height="588" alt="SentryIQ"><span class="sentryiq-vault-status">Gallery</span></div>
        <a href="index.php" class="btn btn-primary" style="text-decoration:none;">Back to Vault</a>
    </div>

    <div class="gallery-upload">
        <h3 style="margin-top:0;">Upload Photos</h3>
        <form id="gallery-upload-form" method="POST" action="gallery_upload.php" enctype="multipart/form-data">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8'); ?>">
            <input type="file" name="photos[]" accept="image/jpeg,image/png,image/gif,image/webp" multiple required>
            <button type="submit" class="btn btn-primary">Upload Photos</button>
        </form>
        <div id="gallery-message" class="gallery-message" aria-live="polite"></div>
    </div>

    <div id="gallery-grid" class="gallery-grid">
        <?php if ($photos === []): ?>
            <div class="gallery-empty" style="grid-column:1/-1;">No photos in the gallery yet.</div>
        <?php else: ?>
            <?php foreach ($photos as $photo): ?>
                <div class="gallery-card">
                    <img src="gallery_image.php?id=<?php echo rawurlencode($photo['id']); ?>" loading="lazy" alt="Gallery photo">
                    <p>Unassigned</p>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
</div>
<script>
document.getElementById('gallery-upload-form').addEventListener('submit', async function(event){
    event.preventDefault();
    const message = document.getElementById('gallery-message');
    message.textContent = 'Uploading…';
    try {
        const response = await fetch(this.action, {method:'POST', body:new FormData(this), credentials:'same-origin', headers:{Accept:'application/json'}});
        const data = await response.json();
        if (!response.ok) throw new Error(data.message || 'Upload failed.');
        const stored = (data.results || []).filter(item => item.status === 'stored').length;
        const duplicates = (data.results || []).filter(item => item.status === 'duplicate').length;
        const rejected = (data.results || []).filter(item => item.status === 'rejected').length;
        message.textContent = `Upload complete: ${stored} stored, ${duplicates} duplicate, ${rejected} rejected.`;
        if (stored > 0) window.location.reload();
    } catch (error) {
        message.textContent = error.message || 'Upload failed.';
    }
});
</script>
</body>
</html>
