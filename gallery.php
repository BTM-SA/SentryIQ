<?php

declare(strict_types=1);

require_once __DIR__ . '/security_bootstrap.php';
sentryiq_security_bootstrap();
sentryiq_require_auth();

$configFile = __DIR__ . '/sentryiq_config.php';
if (!is_file($configFile)) { http_response_code(503); exit('SentryIQ configuration is unavailable.'); }
$config = require $configFile;
if (!is_array($config)) { http_response_code(503); exit('SentryIQ configuration is unavailable.'); }
$dataDir = rtrim((string)($config['data_dir'] ?? ''), '/');
if ($dataDir === '' || !str_starts_with($dataDir, '/') || !is_dir($dataDir) || is_link($dataDir)) { http_response_code(503); exit('SentryIQ secure runtime is unavailable.'); }
require_once __DIR__ . '/cloud/Gallery/Albums/AlbumStore.php';
use SentryIQCloud\Gallery\Albums\AlbumStore;
$galleryRoot = $dataDir . '/gallery';
$albumStore = new AlbumStore($galleryRoot . '/albums.json');
$albums = $albumStore->albums();
$csrf = sentryiq_csrf_token();

function gallery_collect_thumbnails(string $root): array
{
    $directory = $root . '/thumbnails';
    if (!is_dir($directory)) return [];
    $items = [];
    foreach (scandir($directory) ?: [] as $bucket) {
        if ($bucket === '.' || $bucket === '..' || !preg_match('/^[a-f0-9]{2}$/', $bucket)) continue;
        foreach (scandir($directory . '/' . $bucket) ?: [] as $file) {
            if (!preg_match('/^([a-f0-9]{32})\.webp$/', $file, $match)) continue;
            $id = $match[1];
            $items[] = ['id' => $id, 'modified' => (int) (@filemtime($directory . '/' . $bucket . '/' . $file) ?: 0)];
        }
    }
    usort($items, static fn(array $a, array $b): int => $b['modified'] <=> $a['modified']);
    return $items;
}

$photos = gallery_collect_thumbnails($galleryRoot);
$photoAlbums = [];
foreach ($photos as $photo) $photoAlbums[$photo['id']] = $albumStore->albumFor($photo['id']);
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
.gallery-header{display:flex;align-items:center;justify-content:space-between;gap:16px;flex-wrap:wrap}.gallery-tools{display:flex;gap:8px;flex-wrap:wrap;margin-top:18px}.gallery-filter{border:1px solid #d9dee5;background:#fff;border-radius:8px;padding:8px 12px;cursor:pointer}.gallery-filter.active{background:#0066cc;color:#fff;border-color:#0066cc}.gallery-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(180px,1fr));gap:16px;margin-top:20px}.gallery-card{background:#fff;border:1px solid #e9ecef;border-radius:12px;overflow:hidden;box-shadow:0 4px 12px rgba(0,0,0,.04)}.gallery-card img{display:block;width:100%;aspect-ratio:1/1;object-fit:cover;background:#f4f5f7}.gallery-card p{margin:0;padding:10px 12px 4px;font-size:12px;color:#6c757d}.gallery-card select{width:calc(100% - 24px);margin:4px 12px 12px;padding:7px;border:1px solid #d9dee5;border-radius:6px;background:#fff}.gallery-upload{margin-top:18px;padding:18px;border:1px solid #e9ecef;border-radius:12px;background:#fafbfc}.gallery-upload input[type=file]{width:100%;margin:8px 0 12px}.gallery-message{margin-top:12px}.gallery-empty{text-align:center;padding:40px 20px;color:#777}.gallery-albums{margin-top:18px;padding:18px;border:1px solid #e9ecef;border-radius:12px;background:#fff}.gallery-album-form{display:flex;gap:8px;max-width:520px}.gallery-album-form input{flex:1;min-width:0}
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

    <div class="gallery-albums">
        <h3 style="margin-top:0;">Albums</h3>
        <form id="album-form" class="gallery-album-form" method="POST" action="gallery_album.php">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8'); ?>">
            <input type="hidden" name="action" value="create">
            <input class="input-field" type="text" name="name" maxlength="80" placeholder="New album name" required>
            <button type="submit" class="btn btn-primary">Create Album</button>
        </form>
        <div id="album-message" class="gallery-message" aria-live="polite"></div>
    </div>

    <div class="gallery-tools" aria-label="Gallery album filter">
        <button type="button" class="gallery-filter active" data-album="all">All (<?php echo count($photos); ?>)</button>
        <?php foreach ($albums as $album => $_members): ?>
            <?php $count = count(array_filter($photoAlbums, static fn(string $current): bool => $current === $album)); ?>
            <button type="button" class="gallery-filter" data-album="<?php echo htmlspecialchars($album, ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($album); ?> (<?php echo $count; ?>)</button>
        <?php endforeach; ?>
    </div>

    <div id="gallery-grid" class="gallery-grid">
        <?php if ($photos === []): ?>
            <div class="gallery-empty" style="grid-column:1/-1;">No photos in the gallery yet.</div>
        <?php else: ?>
            <?php foreach ($photos as $photo): ?>
                <?php $currentAlbum = $photoAlbums[$photo['id']] ?? 'Unassigned'; ?>
                <div class="gallery-card" data-photo-card data-album="<?php echo htmlspecialchars($currentAlbum, ENT_QUOTES, 'UTF-8'); ?>">
                    <img src="gallery_image.php?id=<?php echo rawurlencode($photo['id']); ?>" loading="lazy" alt="Gallery photo">
                    <p class="gallery-album-label"><?php echo htmlspecialchars($currentAlbum); ?></p>
                    <select class="gallery-move" data-photo-id="<?php echo htmlspecialchars($photo['id'], ENT_QUOTES, 'UTF-8'); ?>" aria-label="Move photo to album">
                        <?php foreach ($albums as $album => $_members): ?>
                            <option value="<?php echo htmlspecialchars($album, ENT_QUOTES, 'UTF-8'); ?>" <?php echo $album === $currentAlbum ? 'selected' : ''; ?>><?php echo htmlspecialchars($album); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
</div>
<script>
const csrf = <?php echo json_encode($csrf, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT); ?>;
async function postAlbumForm(form) {
    const response = await fetch(form.action, {method:'POST', body:new FormData(form), credentials:'same-origin', headers:{Accept:'application/json'}});
    const data = await response.json();
    if (!response.ok || data.status !== 'ok') throw new Error(data.message || 'Gallery operation failed.');
    return data;
}
document.getElementById('gallery-upload-form').addEventListener('submit', async function(event){
    event.preventDefault();
    const message = document.getElementById('gallery-message'); message.textContent = 'Uploading…';
    try {
        const response = await fetch(this.action, {method:'POST', body:new FormData(this), credentials:'same-origin', headers:{Accept:'application/json'}});
        const data = await response.json();
        if (!response.ok) throw new Error(data.message || 'Upload failed.');
        const results = data.results || [];
        const stored = results.filter(item => item.status === 'stored').length;
        const duplicates = results.filter(item => item.status === 'duplicate').length;
        const rejected = results.filter(item => item.status === 'rejected').length;
        message.textContent = `Upload complete: ${stored} stored, ${duplicates} duplicate, ${rejected} rejected.`;
        if (stored > 0) window.location.reload();
    } catch (error) { message.textContent = error.message || 'Upload failed.'; }
});
document.getElementById('album-form').addEventListener('submit', async function(event){
    event.preventDefault();
    const message = document.getElementById('album-message'); message.textContent = 'Creating album…';
    try { await postAlbumForm(this); message.textContent = 'Album created.'; window.location.reload(); }
    catch (error) { message.textContent = error.message || 'Unable to create album.'; }
});
document.querySelectorAll('.gallery-move').forEach(function(select){
    select.dataset.previous = select.value;
    select.addEventListener('change', async function(){
        const previous = this.dataset.previous || this.value;
        const form = new FormData(); form.append('csrf_token', csrf); form.append('action', 'move'); form.append('photo_id', this.dataset.photoId); form.append('album', this.value);
        this.disabled = true;
        try {
            const response = await fetch('gallery_album.php', {method:'POST', body:form, credentials:'same-origin', headers:{Accept:'application/json'}});
            const data = await response.json();
            if (!response.ok || data.status !== 'ok') throw new Error(data.message || 'Unable to move photo.');
            this.dataset.previous = data.album;
            const card = this.closest('[data-photo-card]'); card.dataset.album = data.album; card.querySelector('.gallery-album-label').textContent = data.album;
            applyFilter(document.querySelector('.gallery-filter.active')?.dataset.album || 'all');
        } catch (error) { this.value = previous; alert(error.message || 'Unable to move photo.'); }
        finally { this.disabled = false; }
    });
});
function applyFilter(album) {
    document.querySelectorAll('[data-photo-card]').forEach(function(card){ card.style.display = album === 'all' || card.dataset.album === album ? '' : 'none'; });
    document.querySelectorAll('.gallery-filter').forEach(function(button){ button.classList.toggle('active', button.dataset.album === album); });
}
document.querySelectorAll('.gallery-filter').forEach(function(button){ button.addEventListener('click', function(){ applyFilter(this.dataset.album); }); });
</script>
</body>
</html>
