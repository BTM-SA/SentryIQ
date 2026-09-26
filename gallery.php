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
require_once __DIR__ . '/cloud/Gallery/Storage/PhotoMetadataStore.php';
use SentryIQCloud\Gallery\Albums\AlbumStore;
use SentryIQCloud\Gallery\Storage\PhotoMetadataStore;
$galleryRoot = $dataDir . '/gallery';
$albumStore = new AlbumStore($galleryRoot . '/albums.json');
$metadataStore = new PhotoMetadataStore($galleryRoot . '/metadata.json');
$albums = $albumStore->albums();
$csrf = sentryiq_csrf_token();

function gallery_collect_thumbnails(string $root, array $metadata): array
{
    $directory = $root . '/thumbnails';
    if (!is_dir($directory)) return [];
    $filenameToId = [];
    foreach ($metadata as $id => $item) {
        if (preg_match('/^[a-f0-9]{32}$/', (string)$id) && is_array($item) && preg_match('/^img\d+\.webp$/', (string)($item['filename'] ?? ''))) {
            $filenameToId[$item['filename']] = $id;
        }
    }
    $items = [];
    foreach (scandir($directory) ?: [] as $bucket) {
        if ($bucket === '.' || $bucket === '..' || !preg_match('/^[a-f0-9]{2}$/', $bucket)) continue;
        $bucketPath = $directory . '/' . $bucket;
        foreach (scandir($bucketPath) ?: [] as $file) {
            $id = null;
            if (preg_match('/^([a-f0-9]{32})\.webp$/', $file, $match)) $id = $match[1];
            elseif (isset($filenameToId[$file])) $id = $filenameToId[$file];
            if ($id === null) continue;
            $items[] = ['id' => $id, 'modified' => (int)(@filemtime($bucketPath . '/' . $file) ?: 0)];
        }
    }
    usort($items, static fn(array $a, array $b): int => $b['modified'] <=> $a['modified']);
    return $items;
}

$allMetadata = $metadataStore->all();
$photos = gallery_collect_thumbnails($galleryRoot, $allMetadata);
$photoAlbums = [];
$photoMetadata = [];
foreach ($photos as $photo) {
    $photoAlbums[$photo['id']] = $albumStore->albumFor($photo['id']);
    $photoMetadata[$photo['id']] = $allMetadata[$photo['id']] ?? null;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
<link rel="icon" type="image/png" sizes="32x32" href="sentryiq-icon.php?size=32">
<link rel="icon" type="image/png" sizes="16x16" href="sentryiq-icon.php?size=16">
<link rel="apple-touch-icon" sizes="180x180" href="sentryiq-icon.php?size=180">
<meta property="og:site_name" content="SentryIQ">
<meta property="og:type" content="website">
<meta property="og:title" content="SentryIQ Gallery">
<meta property="og:description" content="SentryIQ Gallery — private personal photo and media gallery.">
<meta property="og:image" content="https://bichet.co.za/SentryIQ/sentryiq-og.php">
<meta property="og:image:type" content="image/png">
<meta property="og:image:width" content="1200">
<meta property="og:image:height" content="1200">
<meta property="og:image:alt" content="SentryIQ">
<meta name="twitter:card" content="summary">
<meta name="twitter:title" content="SentryIQ Gallery">
<meta name="twitter:description" content="SentryIQ Gallery — private personal photo and media gallery.">
<meta name="twitter:image" content="https://bichet.co.za/SentryIQ/sentryiq-og.php">
<meta name="csrf-token" content="<?php echo htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8'); ?>">
<title>SentryIQ Gallery</title><link rel="stylesheet" href="assets/css/sentryiq.css">
<style>
.gallery-section-heading{margin:22px 0 8px;font-size:20px;line-height:1.25}.gallery-tools{display:flex;gap:8px;flex-wrap:wrap;margin-top:18px}.gallery-tools-toggle{display:block;width:100%;margin-top:18px;padding:12px 16px;border:1px solid #d9dee5;border-radius:10px;background:#fff;color:#212529;font-size:15px;font-weight:700;text-align:center;cursor:pointer}.gallery-tools-toggle:hover{background:#f7f9fb}.gallery-tools-toggle[aria-expanded="true"]{border-bottom-left-radius:6px;border-bottom-right-radius:6px}.gallery-tools-panel{margin-top:18px;padding:14px;border:1px solid #e9ecef;border-radius:12px;background:#fafbfc}.gallery-tools-title{margin:0 0 12px;font-size:18px;line-height:1.3}.gallery-tools-group{padding:10px 0;border-top:1px solid #e9ecef}.gallery-tools-group:first-of-type{border-top:0;padding-top:0}.gallery-tools-group h3{margin:0 0 8px;font-size:14px}.gallery-tools-buttons{display:flex;gap:8px;flex-wrap:wrap}.gallery-tools-action,.gallery-tools-buttons .gallery-action-toggle{min-height:42px;padding:9px 13px;border:1px solid #d9dee5;border-radius:8px;background:#fff;color:#212529;font-size:14px;font-weight:600;cursor:pointer}.gallery-tools-action:hover:not(:disabled),.gallery-tools-buttons .gallery-action-toggle:hover{background:#f2f4f7}.gallery-tools-action:disabled{opacity:.5;cursor:not-allowed}.gallery-filter{border:1px solid #d9dee5;background:#fff;border-radius:8px;padding:8px 12px;cursor:pointer}.gallery-filter.active{background:#0066cc;color:#fff;border-color:#0066cc}.gallery-selection-tools{display:flex;align-items:center;gap:8px;flex-wrap:wrap;margin-top:14px;padding:10px 12px;border:1px solid #e9ecef;border-radius:10px;background:#fafbfc}.gallery-selection-tools button{border:1px solid #d9dee5;background:#fff;color:#212529;border-radius:8px;padding:8px 12px;cursor:pointer;font-weight:600}.gallery-selection-tools button:hover{background:#f2f4f7}.gallery-selection-tools button:disabled{opacity:.5;cursor:not-allowed}.gallery-selection-count{font-size:13px;color:#6c757d;margin-left:auto}.gallery-selection-options{position:relative;display:flex;align-items:center;gap:8px;flex:0 0 auto}.gallery-selection-options-button{min-height:38px;padding:7px 12px;border:1px solid #dee2e6;border-radius:10px;background:#fff;color:#212529;font-weight:600;box-shadow:0 3px 8px rgba(0,0,0,.10);cursor:pointer}.gallery-selection-options-button:hover{background:#f7f9fb}.gallery-selection-options-menu{display:none;position:absolute;right:0;top:calc(100% + 8px);z-index:100;min-width:230px;padding:7px;border:1px solid #dee2e6;border-radius:12px;background:#fff;box-shadow:0 8px 24px rgba(0,0,0,.16)}.gallery-selection-options-menu.open{display:block}.gallery-selection-menu-item{display:block;width:100%;padding:11px 12px;text-align:left;border:0;border-radius:10px;background:transparent;color:#212529;font-size:15px;cursor:pointer}.gallery-selection-menu-item:hover:not(:disabled){background:rgba(0,0,0,.05)}.gallery-selection-menu-item:disabled{opacity:.5;cursor:not-allowed}.gallery-selection-menu-item.gallery-bulk-delete{color:#b02a37}.gallery-bulk-move-album{width:100%;min-height:42px;padding:9px 38px 9px 13px;border:1px solid #d9dee5;border-radius:8px;background:#fff;color:#212529;font-family:Arial,sans-serif;font-size:14px;font-weight:600;line-height:1.35;box-sizing:border-box;cursor:pointer;appearance:none;-webkit-appearance:none;background-image:linear-gradient(45deg,transparent 50%,#212529 50%),linear-gradient(135deg,#212529 50%,transparent 50%);background-position:calc(100% - 17px) 18px,calc(100% - 12px) 18px;background-size:5px 5px,5px 5px;background-repeat:no-repeat;box-shadow:none;transition:background-color .15s ease,border-color .15s ease,box-shadow .15s ease}.gallery-bulk-move-album:hover{background-color:#f2f4f7;border-color:#d9dee5}.gallery-bulk-move-album:focus{outline:none;border-color:#0066cc;box-shadow:0 0 0 3px rgba(0,102,204,.14)}.gallery-bulk-move-album:active{background-color:#f2f4f7}.gallery-selection-menu-group{padding:4px 5px 7px}.gallery-selection-menu-divider{height:1px;margin:4px 0 2px;background:#eef1f4}.gallery-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(180px,1fr));gap:16px;margin-top:20px}.gallery-card{position:relative;background:#fff;border:1px solid #e9ecef;border-radius:12px;overflow:hidden;box-shadow:0 4px 12px rgba(0,0,0,.04)}.gallery-card img{display:block;width:100%;aspect-ratio:1/1;object-fit:cover;background:#f4f5f7;cursor:pointer}.gallery-card.gallery-selected{border-color:#0066cc;box-shadow:0 0 0 2px rgba(0,102,204,.2),0 4px 12px rgba(0,102,204,.04)}.gallery-photo-check{position:absolute;top:9px;left:9px;z-index:2;width:22px;height:22px;margin:0;accent-color:#0066cc;cursor:pointer}.gallery-card p{margin:0;padding:8px 12px 2px;font-size:12px;color:#6c757d;word-break:break-word}.gallery-card .gallery-meta{padding-top:2px;font-size:11px}.gallery-card select{width:calc(100% - 24px);margin:6px 12px 8px;padding:7px;border:1px solid #d9dee5;border-radius:6px;background:#fff}.gallery-delete{width:calc(100% - 24px);margin:0 12px 12px;padding:7px;border:1px solid #dc3545;border-radius:6px;background:#fff;color:#dc3545;cursor:pointer}.gallery-delete:hover{background:#dc3545;color:#fff}.gallery-upload{margin-top:18px;padding:18px;border:1px solid #e9ecef;border-radius:12px;background:#fafbfc}.gallery-file-row{display:flex;align-items:center;gap:12px;flex-wrap:wrap;margin:8px 0 12px}.gallery-file-input{position:absolute;width:1px;height:1px;padding:0;margin:-1px;overflow:hidden;clip:rect(0,0,0,0);white-space:nowrap;border:0}.gallery-file-label{display:inline-flex;align-items:center;gap:7px;padding:9px 14px;border:1px solid #0066cc;border-radius:8px;background:#0066cc;color:#fff;font-size:14px;font-weight:600;line-height:1.2;cursor:pointer;transition:background .15s ease,border-color .15s ease,box-shadow .15s ease}.gallery-file-label:hover{background:#0056ad;border-color:#0056ad}.gallery-file-label:active{background:#004b96}.gallery-file-label:focus-within{box-shadow:0 0 0 3px rgba(0,102,204,.18)}.gallery-file-name{font-size:13px;color:#6c757d;min-width:0;word-break:break-word}.gallery-upload-button{margin-top:0}.gallery-message{margin-top:12px}.gallery-upload-progress{display:none!important;margin-top:12px;padding:12px;border:1px solid #e9ecef;border-radius:10px;background:#fff}.gallery-upload-progress.active{display:block!important}.gallery-upload-progress-file{font-size:13px;font-weight:600;color:#212529;word-break:break-word}.gallery-upload-progress-status{margin-top:5px;font-size:12px;color:#6c757d}.gallery-upload-progress-track{width:100%;height:9px;margin-top:9px;overflow:hidden;border-radius:999px;background:#e9ecef}.gallery-upload-progress-bar{width:0;height:100%;border-radius:999px;background:#0066cc;transition:width .12s ease}.gallery-upload-progress-percent{margin-top:5px;font-size:11px;color:#6c757d;text-align:right}.gallery-empty{text-align:center;padding:40px 20px;color:#777}.gallery-albums{margin-top:18px;padding:18px;border:1px solid #e9ecef;border-radius:12px;background:#fff}.gallery-album-form{display:flex;gap:8px;max-width:520px}.gallery-album-form input{flex:1;min-width:0}.gallery-viewer{position:fixed;inset:0;z-index:1000;display:none;align-items:center;justify-content:center;background:rgba(0,0,0,.88);padding:20px;box-sizing:border-box}.gallery-viewer.open{display:flex}.gallery-viewer-image{max-width:100%;max-height:calc(100vh - 150px);width:auto;height:auto;object-fit:contain;border-radius:8px}.gallery-viewer-top{position:absolute;top:16px;left:16px;right:16px;display:flex;justify-content:space-between;align-items:center;gap:12px}.gallery-viewer-close,.gallery-viewer-options{border:0;border-radius:8px;padding:10px 14px;background:rgba(255,255,255,.95);color:#212529;font-size:14px;font-weight:600;cursor:pointer}.gallery-viewer-options{background:#0066cc;color:#fff}.gallery-viewer-options-panel{position:absolute;left:16px;right:16px;bottom:16px;display:none;padding:14px;border-radius:12px;background:rgba(255,255,255,.98);box-shadow:0 8px 30px rgba(0,0,0,.3)}.gallery-viewer-options-panel.open{display:block}.gallery-viewer-options-panel select{width:100%;padding:10px;border:1px solid #d9dee5;border-radius:7px;background:#fff}.gallery-viewer-delete{width:100%;margin-top:10px;padding:10px;border:1px solid #dc3545;border-radius:7px;background:#fff;color:#dc3545;font-weight:600;cursor:pointer}.gallery-viewer-delete:hover{background:#dc3545;color:#fff}
@media (max-width: 600px){.gallery-tools-toggle{margin-top:18px;width:100%}.gallery-tools-panel{margin-top:4px}.gallery-tools-buttons{display:grid;grid-template-columns:minmax(0,1fr);gap:8px}.gallery-tools-action,.gallery-tools-buttons .gallery-action-toggle{width:100%;box-sizing:border-box;justify-content:center}.gallery-action-panels{display:grid;grid-template-columns:minmax(0,1fr) minmax(0,1fr);gap:8px;clear:both;margin-top:18px}.gallery-action-toggle{display:block;width:100%;min-height:48px;padding:10px 8px;border:1px solid #d9dee5;border-radius:10px;background:#fff;color:#212529;font-size:14px;font-weight:600;box-sizing:border-box;cursor:pointer;grid-row:1}.gallery-action-content{display:none;grid-column:1 / -1;width:100%;box-sizing:border-box;padding:14px;border:1px solid #e9ecef;border-radius:12px;background:#fafbfc;grid-row:2}.gallery-action-content.open{display:block}.gallery-tools,.gallery-selection-tools,.gallery-grid{clear:both}.gallery-tools{margin-top:30px}.gallery-album-form{flex-direction:column;align-items:stretch;max-width:none}.gallery-album-form input,.gallery-album-form button{width:100%;box-sizing:border-box}.gallery-album-form button{margin-top:2px}.gallery-file-row{align-items:stretch;flex-direction:column}.gallery-file-label,.gallery-upload-button{width:100%;box-sizing:border-box;justify-content:center}.gallery-file-name{text-align:center}.gallery-grid{grid-template-columns:repeat(3,minmax(0,1fr));gap:4px;margin-top:12px}.gallery-card{border:0;border-radius:4px;box-shadow:none;background:transparent}.gallery-card.gallery-selected{border:1px solid #0066cc;box-shadow:0 0 0 1px rgba(0,102,204,.18)}.gallery-card img{aspect-ratio:1/1;border-radius:4px}.gallery-card p,.gallery-card select,.gallery-card .gallery-delete{display:none}.gallery-photo-check{top:5px;left:5px;width:20px;height:20px}.gallery-selection-tools{padding:8px}.gallery-selection-count{width:100%;margin-left:0}.gallery-selection-tools > #gallery-select-visible,.gallery-selection-tools > #gallery-clear-selection{flex:1;min-width:0}.gallery-selection-options{width:100%}.gallery-selection-options-button{width:100%;box-sizing:border-box}.gallery-selection-options-menu{left:0;right:0;min-width:0}.gallery-selection-menu-item{width:100%}.gallery-viewer{padding:12px}.gallery-viewer-image{max-width:100%;max-height:calc(100vh - 130px)}.gallery-viewer-top{top:12px;left:12px;right:12px}.gallery-viewer-options-panel{left:12px;right:12px;bottom:12px}}

.gallery-action-toolbar{display:flex;align-items:center;gap:10px;margin-top:14px;padding:12px;border:1px solid #e9ecef;border-radius:12px;background:#fafbfc}
.gallery-action-toolbar-buttons{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:8px;flex:1 1 auto}
.gallery-action-toolbar button{min-height:42px;padding:9px 13px;border:1px solid #d9dee5;border-radius:8px;background:#fff;color:#212529;font-size:14px;font-weight:600;cursor:pointer;box-sizing:border-box}
.gallery-action-toolbar button:hover:not(:disabled){background:#f2f4f7}
.gallery-action-toolbar button:disabled{opacity:.5;cursor:not-allowed}
.gallery-action-toolbar .gallery-action-danger{color:#b02a37}
.gallery-selection-count{flex:0 0 auto;margin-left:0;font-size:13px;color:#6c757d;white-space:nowrap}
.gallery-modal{position:fixed;inset:0;z-index:2000;display:flex;align-items:center;justify-content:center;padding:18px;box-sizing:border-box}
.gallery-modal[hidden]{display:none}
.gallery-modal-backdrop{position:absolute;inset:0;background:rgba(15,23,42,.48)}
.gallery-modal-card{position:relative;z-index:1;width:min(560px,100%);max-height:calc(100vh - 36px);overflow:auto;padding:20px;border-radius:18px;background:var(--neo-surface,#e7ebf1);box-shadow:12px 12px 28px rgba(0,0,0,.22),-8px -8px 20px rgba(255,255,255,.72);box-sizing:border-box}
.gallery-modal-header{display:flex;align-items:center;justify-content:space-between;gap:12px;margin-bottom:14px}
.gallery-modal-header h2{margin:0;font-size:20px;line-height:1.25}
.gallery-modal-close{width:38px!important;min-height:38px!important;padding:6px!important;border-radius:10px!important;font-size:22px!important;line-height:1!important}
.gallery-modal-actions{display:flex;justify-content:flex-end;gap:8px;flex-wrap:wrap;margin-top:14px}
.gallery-modal-actions .btn{min-height:42px}
.gallery-modal-field{display:flex;flex-direction:column;gap:8px}
.gallery-modal-field label{font-size:13px;font-weight:700;color:#697586}
.gallery-modal-status{margin-top:10px;font-size:13px;color:#697586}
.gallery-modal-status:empty{display:none}
.gallery-modal .gallery-upload-progress{margin-top:14px}
.gallery-modal-open{overflow:hidden}
@media(max-width:700px){
 .gallery-action-toolbar{display:block}
 .gallery-action-toolbar-buttons{grid-template-columns:repeat(2,minmax(0,1fr));margin-bottom:10px}
 .gallery-selection-count{display:block;text-align:center}
}
@media(max-width:600px){
 .gallery-modal{padding:10px}
 .gallery-modal-card{max-height:calc(100vh - 20px);padding:16px;border-radius:16px}
 .gallery-action-toolbar button{width:100%}
}
</style>
</head><body><div class="box">
<div class="sentryiq-page-header"><div class="sentryiq-vault-brand-wrap"><img class="sentryiq-vault-banner" src="assets/images/sentryiq-logo-wide.webp" width="1952" height="588" alt="SentryIQ"><span class="sentryiq-vault-status">Gallery</span></div><div class="sentryiq-mobile-header-actions"><form method="POST" class="sentryiq-lock-form"><input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8'); ?>"><input type="hidden" name="lock_vault" value="1"><button type="submit" class="btn btn-primary sentryiq-lock-button">Lock Vault</button></form><div class="vault-mobile-menu-bar"><button type="button" class="vault-mobile-menu-toggle" aria-expanded="false" aria-controls="vault-mobile-menu"><span class="vault-mobile-menu-icon" aria-hidden="true">☰</span><span id="vault-mobile-menu-label" class="visually-hidden">Menu</span></button></div></div></div>
<div id="vault-mobile-menu" class="vault-tabs"><button id="view-btn" class="tab-btn" type="button" onclick="window.location.href='index.php?pane=view'">📋 Vault</button><button id="docs-btn" class="tab-btn" type="button" onclick="window.location.href='documents.php'">📄 Docs</button><button id="gallery-btn" class="tab-btn active" type="button">🖼️ Gallery</button><button id="settings-btn" class="tab-btn" type="button" onclick="window.location.href='index.php?pane=settings'">⚙️ System</button><form method="POST" class="vault-menu-lock-form"><input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8'); ?>"><input type="hidden" name="lock_vault" value="1"><button type="submit" class="tab-btn vault-menu-lock-button">🔒 Lock Vault</button></form></div>
<h2 class="gallery-section-heading">Albums</h2><div class="gallery-tools" aria-label="Gallery album filter"><button type="button" class="gallery-filter active" data-album="all">All (<?php echo count($photos); ?>)</button><?php foreach ($albums as $album => $_members): ?><button type="button" class="gallery-filter" data-album="<?php echo htmlspecialchars($album, ENT_QUOTES, 'UTF-8'); ?>"><?php $count=count(array_filter($photoAlbums, static fn(string $current): bool => $current === $album)); echo htmlspecialchars($album); ?> (<?php echo $count; ?>)</button><?php endforeach; ?></div>
<div class="gallery-action-toolbar" aria-label="Gallery actions">
    <div class="gallery-action-toolbar-buttons">
        <button type="button" data-gallery-modal-open="gallery-upload-modal">📷 Add Photo</button>
        <button type="button" data-gallery-modal-open="gallery-album-modal">📁 Create Album</button>
        <button type="button" id="gallery-bulk-move" disabled>↔️ Move</button>
        <button type="button" id="gallery-bulk-delete" class="gallery-action-danger" disabled>🗑️ Delete</button>
        <button type="button" id="gallery-select-visible">☑️ Select All</button>
        <button type="button" id="gallery-clear-selection">Clear Selection</button>
    </div>
    <span id="gallery-selection-count" class="gallery-selection-count">0 selected</span>
</div>

<div id="gallery-upload-modal" class="gallery-modal" hidden>
    <div class="gallery-modal-backdrop" data-gallery-modal-close></div>
    <section class="gallery-modal-card" role="dialog" aria-modal="true" aria-labelledby="gallery-upload-modal-title">
        <div class="gallery-modal-header">
            <h2 id="gallery-upload-modal-title">📷 Add Photo</h2>
            <button type="button" class="gallery-modal-close" data-gallery-modal-close aria-label="Close">×</button>
        </div>
        <form id="gallery-upload-form" method="POST" action="gallery_upload.php" enctype="multipart/form-data">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8'); ?>">
            <div class="gallery-file-row">
                <label class="gallery-file-label" for="gallery-photo-input">📷 Choose Photos</label>
                <input class="gallery-file-input" id="gallery-photo-input" type="file" name="photos[]" accept="image/jpeg,image/png,image/gif,image/webp" multiple required>
                <span id="gallery-file-name" class="gallery-file-name">No photos selected</span>
            </div>
            <div class="gallery-modal-actions">
                <button type="button" class="btn" data-gallery-modal-close>Cancel</button>
                <button type="submit" class="btn btn-primary gallery-upload-button">Upload Photos</button>
            </div>
        </form>
        <div id="gallery-message" class="gallery-message" aria-live="polite"></div>
        <div id="gallery-upload-progress" class="gallery-upload-progress" aria-live="polite">
            <div id="gallery-upload-progress-file" class="gallery-upload-progress-file"></div>
            <div id="gallery-upload-progress-status" class="gallery-upload-progress-status"></div>
            <div class="gallery-upload-progress-track" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="0">
                <div id="gallery-upload-progress-bar" class="gallery-upload-progress-bar"></div>
            </div>
            <div id="gallery-upload-progress-percent" class="gallery-upload-progress-percent">0%</div>
        </div>
    </section>
</div>

<div id="gallery-album-modal" class="gallery-modal" hidden>
    <div class="gallery-modal-backdrop" data-gallery-modal-close></div>
    <section class="gallery-modal-card" role="dialog" aria-modal="true" aria-labelledby="gallery-album-modal-title">
        <div class="gallery-modal-header">
            <h2 id="gallery-album-modal-title">📁 Create Album</h2>
            <button type="button" class="gallery-modal-close" data-gallery-modal-close aria-label="Close">×</button>
        </div>
        <form id="album-form" class="gallery-album-form" method="POST" action="gallery_album.php">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8'); ?>">
            <input type="hidden" name="action" value="create">
            <div class="gallery-modal-field">
                <label for="gallery-album-name">Album name</label>
                <input id="gallery-album-name" class="input-field" type="text" name="name" maxlength="80" placeholder="Enter album name" required>
            </div>
            <div class="gallery-modal-actions">
                <button type="button" class="btn" data-gallery-modal-close>Cancel</button>
                <button type="submit" class="btn btn-primary">Create Album</button>
            </div>
        </form>
        <div id="album-message" class="gallery-modal-status" aria-live="polite"></div>
    </section>
</div>

<div id="gallery-move-modal" class="gallery-modal" hidden>
    <div class="gallery-modal-backdrop" data-gallery-modal-close></div>
    <section class="gallery-modal-card" role="dialog" aria-modal="true" aria-labelledby="gallery-move-modal-title">
        <div class="gallery-modal-header">
            <h2 id="gallery-move-modal-title">↔️ Move Photos</h2>
            <button type="button" class="gallery-modal-close" data-gallery-modal-close aria-label="Close">×</button>
        </div>
        <div class="gallery-modal-field">
            <label for="gallery-move-album">Move selected photos to</label>
            <select id="gallery-move-album" class="gallery-bulk-move-album" aria-label="Choose destination album">
                <option value="">Choose an album…</option>
                <?php foreach($albums as $album=>$_members): ?>
                    <option value="<?php echo htmlspecialchars($album, ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($album); ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div id="gallery-move-message" class="gallery-modal-status" aria-live="polite"></div>
        <div class="gallery-modal-actions">
            <button type="button" class="btn" data-gallery-modal-close>Cancel</button>
            <button type="button" id="gallery-move-submit" class="btn btn-primary" disabled>Move Photos</button>
        </div>
    </section>
</div>

<div id="gallery-grid" class="gallery-grid"><?php if ($photos === []): ?><div class="gallery-empty" style="grid-column:1/-1;">No photos in the gallery yet.</div><?php else: foreach ($photos as $photoIndex => $photo): $currentAlbum=$photoAlbums[$photo['id']]??'Unassigned'; $metadata=$photoMetadata[$photo['id']]??null; $filename=is_array($metadata)?(string)($metadata['filename']??''):''; $createdAt=is_array($metadata)?(int)($metadata['created_at']??0):0; ?>
<div class="gallery-card" data-photo-card data-photo-id="<?php echo htmlspecialchars($photo['id'],ENT_QUOTES,'UTF-8'); ?>" data-album="<?php echo htmlspecialchars($currentAlbum,ENT_QUOTES,'UTF-8'); ?>"><input type="checkbox" class="gallery-photo-check" data-photo-id="<?php echo htmlspecialchars($photo['id'],ENT_QUOTES,'UTF-8'); ?>" aria-label="Select photo"><img <?php if($photoIndex<6): ?>src="gallery_image.php?id=<?php echo rawurlencode($photo['id']); ?>&thumbnail=1" loading="eager"<?php else: ?>data-src="gallery_image.php?id=<?php echo rawurlencode($photo['id']); ?>&thumbnail=1" loading="lazy"<?php endif; ?> data-full-src="gallery_image.php?id=<?php echo rawurlencode($photo['id']); ?>" alt="<?php echo htmlspecialchars($filename!==''?$filename:'Gallery photo',ENT_QUOTES,'UTF-8'); ?>"><p class="gallery-album-label"><?php echo htmlspecialchars($currentAlbum); ?></p><?php if($filename!==''): ?><p title="<?php echo htmlspecialchars($filename,ENT_QUOTES,'UTF-8'); ?>"><?php echo htmlspecialchars($filename); ?></p><?php endif; ?><?php if($createdAt>0): ?><p class="gallery-meta"><?php echo htmlspecialchars(date('Y-m-d H:i',$createdAt)); ?></p><?php endif; ?><select class="gallery-move" data-photo-id="<?php echo htmlspecialchars($photo['id'],ENT_QUOTES,'UTF-8'); ?>" aria-label="Move photo to album"><?php foreach($albums as $album=>$_members): ?><option value="<?php echo htmlspecialchars($album,ENT_QUOTES,'UTF-8'); ?>" <?php echo $album===$currentAlbum?'selected':''; ?>><?php echo htmlspecialchars($album); ?></option><?php endforeach; ?></select><button type="button" class="gallery-delete" data-photo-id="<?php echo htmlspecialchars($photo['id'],ENT_QUOTES,'UTF-8'); ?>">Delete Photo</button></div>
<?php endforeach; endif; ?></div>
<div id="gallery-viewer" class="gallery-viewer" role="dialog" aria-modal="true" aria-label="Photo viewer"><div class="gallery-viewer-top"><button type="button" id="gallery-viewer-close" class="gallery-viewer-close">✕ Close</button><button type="button" id="gallery-viewer-options" class="gallery-viewer-options">Options</button></div><img id="gallery-viewer-image" class="gallery-viewer-image" src="" alt=""><div id="gallery-viewer-options-panel" class="gallery-viewer-options-panel"><select id="gallery-viewer-album" aria-label="Move photo to album"><?php foreach($albums as $album=>$_members): ?><option value="<?php echo htmlspecialchars($album,ENT_QUOTES,'UTF-8'); ?>"><?php echo htmlspecialchars($album); ?></option><?php endforeach; ?></select><button type="button" id="gallery-viewer-delete" class="gallery-viewer-delete">Delete Photo</button></div></div>
</div>
<script>
const csrf=<?php echo json_encode($csrf,JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_AMP|JSON_HEX_QUOT); ?>;
(function(){const toggle=document.querySelector('.vault-mobile-menu-toggle');const menu=document.getElementById('vault-mobile-menu');if(toggle&&menu){toggle.addEventListener('click',function(){const open=menu.classList.toggle('mobile-open');toggle.setAttribute('aria-expanded',open?'true':'false');});}}());
async function postAlbumForm(form){const response=await fetch('gallery_album.php',{method:'POST',body:new FormData(form),credentials:'same-origin',headers:{Accept:'application/json'}});const text=await response.text();let data;try{data=JSON.parse(text);}catch(_){throw new Error(`The album request returned an invalid response (HTTP ${response.status}): ${text.slice(0,500)}`);}if(!response.ok||data.status!=='ok')throw new Error(data.message||`Gallery operation failed (HTTP ${response.status}).`);return data;}
async function responseJson(response,fallback){const text=await response.text();let data;try{data=JSON.parse(text);}catch(_){console.error('SentryIQ Gallery non-JSON response',{status:response.status,statusText:response.statusText,url:response.url,body:text});throw new Error(`${fallback} (HTTP ${response.status}): ${text.trim().slice(0,1000)||'[empty response]'}`);}return data;}
const galleryPhotoInput=document.getElementById('gallery-photo-input');const galleryFileName=document.getElementById('gallery-file-name');galleryPhotoInput.addEventListener('change',function(){const count=this.files?.length||0;if(count===0){galleryFileName.textContent='No photos selected';}else if(count===1){galleryFileName.textContent=this.files[0].name;}else{galleryFileName.textContent=`${count} photos selected`;} });
document.getElementById('gallery-photo-input').addEventListener('change',function(){
  const files=Array.from(this.files||[]);
  const label=document.getElementById('gallery-file-name');
  if(label)label.textContent=files.length?files.map(function(file){return file.name;}).join(', '):'No photos selected';
});
function uploadGalleryPhoto(form,file,onProgress){return new Promise(function(resolve,reject){const uploadData=new FormData();uploadData.append('csrf_token',csrf);uploadData.append('photo',file,file.name);const xhr=new XMLHttpRequest();let displayedPercent=2;let progressTimer=null;let lastEventTime=Date.now();function setProgress(percent){const next=Math.max(displayedPercent,Math.min(90,percent));if(next===displayedPercent)return;displayedPercent=next;if(typeof onProgress==='function'){window.requestAnimationFrame(function(){onProgress(displayedPercent);});}}function startProgress(){setProgress(2);progressTimer=window.setInterval(function(){if(displayedPercent<88){const elapsed=Date.now()-lastEventTime;const step=elapsed>1500?2:1;setProgress(displayedPercent+step);}},180);}function stopProgress(){if(progressTimer!==null){window.clearInterval(progressTimer);progressTimer=null;}}xhr.open('POST',form.action,true);xhr.withCredentials=true;xhr.setRequestHeader('Accept','application/json');xhr.setRequestHeader('X-CSRF-Token',csrf);startProgress();xhr.upload.addEventListener('progress',function(event){lastEventTime=Date.now();if(event.lengthComputable&&event.total>0){const percent=Math.min(88,Math.max(2,Math.round((event.loaded/event.total)*88)));setProgress(percent);}});xhr.addEventListener('load',function(){stopProgress();if(typeof onProgress==='function'){window.requestAnimationFrame(function(){onProgress(92);});}let data;try{data=JSON.parse(xhr.responseText);}catch(_){const serverMessage=String(xhr.responseText||'').replace(/<[^>]*>/g,' ').replace(/\s+/g,' ').trim().slice(0,500);reject(new Error(serverMessage||`The upload returned an invalid response (HTTP ${xhr.status}).`));return;}if(xhr.status<200||xhr.status>=300){reject(new Error(data.message||`Upload failed (HTTP ${xhr.status}).`));return;}if(!Array.isArray(data.results)){reject(new Error(data.message||'The upload returned an unexpected response.'));return;}resolve(data);});xhr.addEventListener('error',function(){stopProgress();reject(new Error('Network error while uploading the photo.'));});xhr.addEventListener('abort',function(){stopProgress();reject(new Error('Upload was cancelled.'));});xhr.send(uploadData);});}
document.getElementById('gallery-upload-form').addEventListener('submit',async function(event){event.preventDefault();const form=this;const input=form.querySelector('input[type="file"][name="photos[]"]');const message=document.getElementById('gallery-message');const progress=document.getElementById('gallery-upload-progress');const progressFile=document.getElementById('gallery-upload-progress-file');const progressStatus=document.getElementById('gallery-upload-progress-status');const progressBar=document.getElementById('gallery-upload-progress-bar');const progressPercent=document.getElementById('gallery-upload-progress-percent');const progressTrack=progress?.querySelector('[role="progressbar"]');const files=Array.from(input?.files||[]);if(files.length===0){message.textContent='Please select at least one photo.';return;}uploadBusy=true;const button=form.querySelector('button[type="submit"]');button.disabled=true;input.disabled=true;if(progress){progress.classList.add('active');progress.style.display='block';progress.offsetHeight;}if(progressBar)progressBar.style.width='2%';if(progressPercent)progressPercent.textContent='2%';if(progressTrack)progressTrack.setAttribute('aria-valuenow','2');if(progressStatus)progressStatus.textContent='Starting upload…';const totals={stored:0,duplicate:0,rejected:0};const failures=[];try{for(let index=0;index<files.length;index++){const file=files[index];if(progressFile)progressFile.textContent=`Photo ${index+1} of ${files.length}: ${file.name}`;if(progressStatus)progressStatus.textContent='Uploading…';if(progressBar)progressBar.style.width='2%';if(progressPercent)progressPercent.textContent='2%';if(progressTrack)progressTrack.setAttribute('aria-valuenow','2');message.textContent=`Uploading photo ${index+1} of ${files.length}…`;try{const data=await uploadGalleryPhoto(form,file,function(percent){if(progressBar)progressBar.style.width=percent+'%';if(progressPercent)progressPercent.textContent=percent+'%';if(progressTrack)progressTrack.setAttribute('aria-valuenow',String(percent));});const result=data.results[0];if(result?.status==='stored'){totals.stored++;if(progressStatus)progressStatus.textContent='Uploaded and processed successfully.';}else if(result?.status==='duplicate'){totals.duplicate++;if(progressStatus)progressStatus.textContent='Already in the gallery (duplicate).';}else{totals.rejected++;failures.push(`${file.name}: ${result?.message||'Upload rejected.'}`);if(progressStatus)progressStatus.textContent='Upload rejected.';}if(progressBar)progressBar.style.width='100%';if(progressPercent)progressPercent.textContent='100%';if(progressTrack)progressTrack.setAttribute('aria-valuenow','100');}catch(error){totals.rejected++;failures.push(`${file.name}: ${error.message||'Upload failed.'}`);if(progressStatus)progressStatus.textContent='Upload failed.';if(progressBar)progressBar.style.width='100%';if(progressPercent)progressPercent.textContent='Failed';}}message.textContent=`Upload complete: ${totals.stored} stored, ${totals.duplicate} duplicate, ${totals.rejected} rejected.`;if(failures.length){const details=document.createElement('div');details.style.marginTop='8px';details.style.color='#dc3545';details.textContent=failures.join(' | ');message.appendChild(details);}if(totals.stored>0)setTimeout(()=>window.location.reload(),1200);}catch(error){message.textContent=error.message||'Upload failed.';}finally{button.disabled=false;input.disabled=false;}});
document.getElementById('album-form').addEventListener('submit',async function(event){
  event.preventDefault();
  const form=this;
  const button=form.querySelector('button[type="submit"]');
  const message=document.getElementById('album-message');
  button.disabled=true;
  message.textContent='Creating album…';
  try{
    await postAlbumForm(form);
    message.textContent='Album created.';
    window.location.reload();
  }catch(error){
    message.textContent=error.message||'Unable to create album.';
    button.disabled=false;
  }
});
const selectionCount=document.getElementById('gallery-selection-count');
const bulkDeleteButton=document.getElementById('gallery-bulk-delete');
const bulkMoveButton=document.getElementById('gallery-bulk-move');
const selectVisibleButton=document.getElementById('gallery-select-visible');
const clearSelectionButton=document.getElementById('gallery-clear-selection');
const moveModal=document.getElementById('gallery-move-modal');
const moveAlbum=document.getElementById('gallery-move-album');
const moveSubmit=document.getElementById('gallery-move-submit');
const moveMessage=document.getElementById('gallery-move-message');
const modalUpload=document.getElementById('gallery-upload-modal');
const modalAlbum=document.getElementById('gallery-album-modal');
let uploadBusy=false;

function visiblePhotoCards(){return Array.from(document.querySelectorAll('[data-photo-card]')).filter(card=>card.style.display!=='none');}
function selectedCheckboxes(){return Array.from(document.querySelectorAll('.gallery-photo-check:checked'));}

function updateSelectionUi(){
  const selected=selectedCheckboxes();
  selectionCount.textContent=`${selected.length} selected`;
  bulkDeleteButton.disabled=selected.length===0;
  bulkMoveButton.disabled=selected.length===0;
  document.querySelectorAll('[data-photo-card]').forEach(function(card){
    const checkbox=card.querySelector('.gallery-photo-check');
    card.classList.toggle('gallery-selected',!!checkbox?.checked);
  });
}

function clearSelection(){
  document.querySelectorAll('.gallery-photo-check').forEach(function(checkbox){checkbox.checked=false;});
  updateSelectionUi();
}

selectVisibleButton.addEventListener('click',function(){
  visiblePhotoCards().forEach(function(card){
    const checkbox=card.querySelector('.gallery-photo-check');
    if(checkbox)checkbox.checked=true;
  });
  updateSelectionUi();
});
clearSelectionButton.addEventListener('click',clearSelection);

document.querySelectorAll('.gallery-photo-check').forEach(function(checkbox){
  checkbox.addEventListener('click',function(event){event.stopPropagation();});
  checkbox.addEventListener('change',updateSelectionUi);
});

function openGalleryModal(id){
  const modal=document.getElementById(id);
  if(!modal)return;
  modal.hidden=false;
  document.body.classList.add('gallery-modal-open');
  const focusable=modal.querySelector('input:not([type="hidden"]),select,button:not([data-gallery-modal-close])');
  if(focusable)window.setTimeout(function(){focusable.focus();},30);
}

function closeGalleryModal(modal){
  if(!modal)return;
  if(modal===modalUpload&&uploadBusy)return;
  modal.hidden=true;
  if(!document.querySelector('.gallery-modal:not([hidden])'))document.body.classList.remove('gallery-modal-open');
}

document.querySelectorAll('[data-gallery-modal-open]').forEach(function(button){
  button.addEventListener('click',function(){
    openGalleryModal(button.getAttribute('data-gallery-modal-open'));
  });
});

document.querySelectorAll('[data-gallery-modal-close]').forEach(function(button){
  button.addEventListener('click',function(){
    closeGalleryModal(button.closest('.gallery-modal'));
  });
});

function selectedPhotoIds(){return selectedCheckboxes().map(function(checkbox){return checkbox.dataset.photoId;}).filter(Boolean);}

bulkMoveButton.addEventListener('click',function(){
  const selected=selectedCheckboxes();
  if(selected.length===0)return;
  moveMessage.textContent=`${selected.length} photo${selected.length===1?'':'s'} selected.`;
  moveAlbum.value='';
  moveSubmit.disabled=true;
  openGalleryModal('gallery-move-modal');
});

moveAlbum.addEventListener('change',function(){
  moveSubmit.disabled=moveAlbum.value==='';
});

moveSubmit.addEventListener('click',async function(){
  const ids=selectedPhotoIds();
  const album=moveAlbum.value;
  if(ids.length===0||album==='')return;
  moveSubmit.disabled=true;
  bulkMoveButton.disabled=true;
  bulkDeleteButton.disabled=true;
  moveMessage.textContent=`Moving ${ids.length} photo${ids.length===1?'':'s'}…`;
  try{
    const form=new FormData();
    form.append('csrf_token',csrf);
    form.append('action','bulk_move');
    form.append('album',album);
    ids.forEach(function(id){form.append('photo_ids[]',id);});
    const response=await fetch('gallery_album.php',{method:'POST',body:form,credentials:'same-origin',headers:{Accept:'application/json'}});
    const data=await responseJson(response,'The move request returned an invalid response.');
    if(!response.ok||data.status!=='ok')throw new Error(data.message||'Unable to move selected photos.');
    ids.forEach(function(id){
      const card=document.querySelector(`[data-photo-card][data-photo-id="${CSS.escape(id)}"]`);
      if(!card)return;
      card.dataset.album=data.album;
      const label=card.querySelector('.gallery-album-label');
      if(label)label.textContent=data.album;
      const select=card.querySelector('.gallery-move');
      if(select)select.value=data.album;
    });
    clearSelection();
    closeGalleryModal(moveModal);
    applyFilter(document.querySelector('.gallery-filter.active')?.dataset.album||'all');
  }catch(error){
    moveMessage.textContent=error.message||'Unable to move selected photos.';
    moveSubmit.disabled=false;
    updateSelectionUi();
  }
});

async function bulkDeleteSelected(){
  const ids=selectedPhotoIds();
  if(ids.length===0)return;
  if(!window.confirm(`Delete ${ids.length} selected photo${ids.length===1?'':'s'} permanently?`))return;
  bulkDeleteButton.disabled=true;
  selectVisibleButton.disabled=true;
  clearSelectionButton.disabled=true;
  bulkMoveButton.disabled=true;
  try{
    const form=new FormData();
    form.append('csrf_token',csrf);
    ids.forEach(function(id){form.append('photo_ids[]',id);});
    const response=await fetch('gallery_bulk_delete.php',{method:'POST',body:form,credentials:'same-origin',headers:{Accept:'application/json'}});
    const data=await responseJson(response,'The delete request returned an invalid response.');
    if(!response.ok||!['ok','partial'].includes(data.status))throw new Error(data.message||'Unable to delete selected photos.');
    if(data.status==='partial'&&Array.isArray(data.failed)){
      const failed=data.failed.map(item=>item?.photo_id?`${item.photo_id}: ${item.message}`:item.message).filter(Boolean);
      alert(`Deleted ${data.deleted_count||0} photo(s). Some items could not be cleaned up.${failed.length?'\\n\\n'+failed.join('\\n'):''}`);
    }
    window.location.reload();
  }catch(error){
    alert(error.message||'Unable to delete selected photos.');
    updateSelectionUi();
    selectVisibleButton.disabled=false;
    clearSelectionButton.disabled=false;
  }
}
bulkDeleteButton.addEventListener('click',bulkDeleteSelected);

function applyFilter(album){document.querySelectorAll('[data-photo-card]').forEach(function(card){card.style.display=album==='all'||card.dataset.album===album?'':'none';});document.querySelectorAll('.gallery-filter').forEach(function(button){button.classList.toggle('active',button.dataset.album===album);});clearSelection();}
document.querySelectorAll('.gallery-filter').forEach(function(button){button.addEventListener('click',function(){applyFilter(this.dataset.album);});});
updateSelectionUi();
document.querySelectorAll('.gallery-move').forEach(function(select){select.dataset.previous=select.value;select.addEventListener('change',async function(){const previous=this.dataset.previous||this.value;const form=new FormData();form.append('csrf_token',csrf);form.append('action','move');form.append('photo_id',this.dataset.photoId);form.append('album',this.value);this.disabled=true;try{const response=await fetch('gallery_album.php',{method:'POST',body:form,credentials:'same-origin',headers:{Accept:'application/json'}});const data=await responseJson(response,'The album request returned an invalid response.');if(!response.ok||data.status!=='ok')throw new Error(data.message||'Unable to move photo.');this.dataset.previous=data.album;const card=this.closest('[data-photo-card]');card.dataset.album=data.album;card.querySelector('.gallery-album-label').textContent=data.album;window.location.reload();}catch(error){this.value=previous;alert(error.message||'Unable to move photo.');}finally{this.disabled=false;}});});
document.querySelectorAll('.gallery-delete').forEach(function(button){button.addEventListener('click',async function(){if(!window.confirm('Delete this photo permanently?'))return;const card=this.closest('[data-photo-card]');this.disabled=true;try{const form=new FormData();form.append('csrf_token',csrf);form.append('photo_id',this.dataset.photoId);const response=await fetch('gallery_delete.php',{method:'POST',body:form,credentials:'same-origin',headers:{Accept:'application/json'}});const data=await responseJson(response,'The delete request returned an invalid response.');if(!response.ok||data.status!=='ok')throw new Error(data.message||'Unable to delete photo.');card.remove();updateSelectionUi();applyFilter(document.querySelector('.gallery-filter.active')?.dataset.album||'all');}catch(error){alert(error.message||'Unable to delete photo.');this.disabled=false;}});});

const galleryLazyImages=Array.from(document.querySelectorAll('.gallery-card img[data-src]'));
const galleryLazyBatchSize=6;
let galleryLazyCursor=0;
function loadNextGalleryBatch(){
  const batch=galleryLazyImages.slice(galleryLazyCursor,galleryLazyCursor+galleryLazyBatchSize);
  if(batch.length===0)return false;
  batch.forEach(function(image){image.src=image.dataset.src;image.removeAttribute('data-src');image.setAttribute('loading','lazy');});
  galleryLazyCursor+=batch.length;
  return true;
}
if(galleryLazyImages.length){
  const firstDeferred=galleryLazyImages[0];
  if('IntersectionObserver' in window){
    const galleryLazyObserver=new IntersectionObserver(function(entries,observer){
      entries.forEach(function(entry){
        if(!entry.isIntersecting)return;
        if(loadNextGalleryBatch()){
          const next=galleryLazyImages[galleryLazyCursor];
          if(next)observer.observe(next);
        }
        observer.unobserve(entry.target);
      });
    },{root:null,rootMargin:'300px 0px',threshold:0.01});
    galleryLazyObserver.observe(firstDeferred);
  }else{
    loadNextGalleryBatch();
    window.addEventListener('scroll',function(){loadNextGalleryBatch();},{passive:true});
  }
}

document.addEventListener('keydown',function(event){
  if(event.key!=='Escape')return;
  const openModal=document.querySelector('.gallery-modal:not([hidden])');
  if(openModal)closeGalleryModal(openModal);
  if(viewer&&viewer.classList.contains('open'))closeGalleryViewer();
});

const viewer=document.getElementById('gallery-viewer');const viewerImage=document.getElementById('gallery-viewer-image');const viewerOptions=document.getElementById('gallery-viewer-options');const viewerClose=document.getElementById('gallery-viewer-close');const viewerPanel=document.getElementById('gallery-viewer-options-panel');const viewerAlbum=document.getElementById('gallery-viewer-album');const viewerDelete=document.getElementById('gallery-viewer-delete');let viewerPhotoId=null;
function openGalleryViewer(card){viewerPhotoId=card.dataset.photoId;const image=card.querySelector('img');if(image?.dataset.src&&!image.src){image.src=image.dataset.src;image.removeAttribute('data-src');}viewerImage.src=image.dataset.fullSrc||image.src;viewerImage.alt=image.alt||'Gallery photo';viewerAlbum.value=card.dataset.album||'Unassigned';viewerPanel.classList.remove('open');viewer.classList.add('open');document.body.style.overflow='hidden';}
function closeGalleryViewer(){viewer.classList.remove('open');viewerPanel.classList.remove('open');viewerImage.src='';viewerPhotoId=null;document.body.style.overflow='';}
document.querySelectorAll('.gallery-card img').forEach(function(image){image.addEventListener('click',function(event){event.stopPropagation();openGalleryViewer(this.closest('[data-photo-card]'));});});
viewerClose.addEventListener('click',closeGalleryViewer);viewer.addEventListener('click',function(event){if(event.target===viewer)closeGalleryViewer();});viewerOptions.addEventListener('click',function(){viewerPanel.classList.toggle('open');});
viewerAlbum.addEventListener('change',async function(){if(!viewerPhotoId)return;const value=this.value;const card=document.querySelector(`[data-photo-card][data-photo-id="${CSS.escape(viewerPhotoId)}"]`);const form=new FormData();form.append('csrf_token',csrf);form.append('action','move');form.append('photo_id',viewerPhotoId);form.append('album',value);this.disabled=true;try{const response=await fetch('gallery_album.php',{method:'POST',body:form,credentials:'same-origin',headers:{Accept:'application/json'}});const data=await responseJson(response,'The album request returned an invalid response.');if(!response.ok||data.status!=='ok')throw new Error(data.message||'Unable to move photo.');if(card){card.dataset.album=data.album;const label=card.querySelector('.gallery-album-label');if(label)label.textContent=data.album;const desktopSelect=card.querySelector('.gallery-move');if(desktopSelect)desktopSelect.value=data.album;}viewerAlbum.value=data.album;window.location.reload();}catch(error){alert(error.message||'Unable to move photo.');}finally{this.disabled=false;}});
viewerDelete.addEventListener('click',async function(){if(!viewerPhotoId||!window.confirm('Delete this photo permanently?'))return;const photoId=viewerPhotoId;this.disabled=true;try{const form=new FormData();form.append('csrf_token',csrf);form.append('photo_id',photoId);const response=await fetch('gallery_delete.php',{method:'POST',body:form,credentials:'same-origin',headers:{Accept:'application/json'}});const data=await responseJson(response,'The delete request returned an invalid response.');if(!response.ok||data.status!=='ok')throw new Error(data.message||'Unable to delete photo.');const card=document.querySelector(`[data-photo-card][data-photo-id="${CSS.escape(photoId)}"]`);if(card)card.remove();closeGalleryViewer();updateSelectionUi();applyFilter(document.querySelector('.gallery-filter.active')?.dataset.album||'all');}catch(error){alert(error.message||'Unable to delete photo.');}finally{this.disabled=false;}});
document.addEventListener('keydown',function(event){if(event.key==='Escape'&&viewer.classList.contains('open'))closeGalleryViewer();});
</script></body></html>
