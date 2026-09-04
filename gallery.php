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

$csrf = sentryiq_csrf_token();
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
.gallery-page{max-width:900px;margin:0 auto}.gallery-header{display:flex;align-items:center;justify-content:space-between;gap:16px;flex-wrap:wrap}.gallery-header img{width:180px;height:auto}.gallery-upload{margin-top:24px;padding:24px;border:1px solid #e9ecef;border-radius:12px;background:#fff}.gallery-upload input[type=file]{display:block;width:100%;margin:14px 0}.gallery-results{margin-top:20px}.gallery-result{padding:10px 12px;border-radius:8px;margin:8px 0;background:#f8f9fa}.gallery-result.stored{border-left:4px solid #198754}.gallery-result.duplicate{border-left:4px solid #fd7e14}.gallery-result.rejected{border-left:4px solid #dc3545}.gallery-back{display:inline-block;margin-top:20px}.gallery-preview{display:grid;grid-template-columns:repeat(auto-fill,minmax(120px,1fr));gap:12px;margin-top:16px}.gallery-preview img{width:100%;aspect-ratio:1;object-fit:cover;border-radius:8px;border:1px solid #e9ecef}
</style>
</head>
<body>
<div class="box gallery-page">
    <div class="gallery-header">
        <div>
            <img src="sentryiq-logo-wide.webp" width="1952" height="588" alt="SentryIQ">
            <h2>Gallery</h2>
            <p>Private image storage using the SentryIQ security boundary.</p>
        </div>
        <a href="index.php" class="btn btn-primary" style="text-decoration:none;">Back to Vault</a>
    </div>

    <div class="gallery-upload">
        <form id="gallery-upload-form" method="POST" enctype="multipart/form-data" action="gallery_upload.php">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8'); ?>">
            <label for="photos"><strong>Select photos</strong></label>
            <input id="photos" type="file" name="photos[]" accept="image/jpeg,image/png,image/gif,image/webp" multiple required>
            <div id="gallery-preview" class="gallery-preview" aria-live="polite"></div>
            <button type="submit" class="btn btn-primary">Upload Photos</button>
        </form>
        <div id="gallery-results" class="gallery-results" aria-live="polite"></div>
    </div>

    <a href="index.php" class="gallery-back">← Return to SentryIQ</a>
</div>
<script>
(function () {
    const form = document.getElementById('gallery-upload-form');
    const input = document.getElementById('photos');
    const preview = document.getElementById('gallery-preview');
    const results = document.getElementById('gallery-results');

    input.addEventListener('change', function () {
        preview.replaceChildren();
        Array.from(input.files || []).forEach(function (file) {
            if (!file.type.startsWith('image/')) return;
            const image = document.createElement('img');
            image.alt = file.name;
            image.src = URL.createObjectURL(file);
            image.onload = function () { URL.revokeObjectURL(image.src); };
            preview.appendChild(image);
        });
    });

    form.addEventListener('submit', async function (event) {
        event.preventDefault();
        results.replaceChildren();
        const button = form.querySelector('button[type="submit"]');
        button.disabled = true;
        button.textContent = 'Uploading…';

        try {
            const response = await fetch(form.action, {
                method: 'POST',
                credentials: 'same-origin',
                body: new FormData(form),
                headers: {'Accept': 'application/json'}
            });
            const payload = await response.json();
            if (!response.ok) throw new Error(payload.message || 'Gallery upload failed.');

            (payload.results || []).forEach(function (item) {
                const row = document.createElement('div');
                row.className = 'gallery-result ' + (item.status || 'rejected');
                row.textContent = (item.status || 'rejected') + ': ' + (item.message || 'No result.');
                results.appendChild(row);
            });
            form.reset();
            preview.replaceChildren();
        } catch (error) {
            const row = document.createElement('div');
            row.className = 'gallery-result rejected';
            row.textContent = error.message || 'Gallery upload failed.';
            results.appendChild(row);
        } finally {
            button.disabled = false;
            button.textContent = 'Upload Photos';
        }
    });
}());
</script>
</body>
</html>
