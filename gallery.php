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
<meta name="csrf-token" content="<?php echo htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8'); ?>">
<title>SentryIQ Gallery</title><link rel="stylesheet" href="pm_style.css">
<style>
.gallery-header{display:flex;align-items:center;justify-content:space-between;gap:16px;flex-wrap:wrap}.gallery-tools{display:flex;gap:8px;flex-wrap:wrap;margin-top:18px}.gallery-filter{border:1px solid #d9dee5;background:#fff;border-radius:8px;padding:8px 12px;cursor:pointer}.gallery-filter.active{background:#0066cc;color:#fff;border-color:#0066cc}.gallery-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(180px,1fr));gap:16px;margin-top:20px}.gallery-card{background:#fff;border:1px solid #e9ecef;border-radius:12px;overflow:hidden;box-shadow:0 4px 12px rgba(0,0,0,.04)}.gallery-card img{display:block;width:100%;aspect-ratio:1/1;object-fit:cover;background:#f4f5f7}.gallery-card p{margin:0;padding:8px 12px 2px;font-size:12px;color:#6c757d;word-break:break-word}.gallery-card .gallery-meta{padding-top:2px;font-size:11px}.gallery-card select{width:calc(100% - 24px);margin:6px 12px 8px;padding:7px;border:1px solid #d9dee5;border-radius:6px;background:#fff}.gallery-delete{width:calc(100% - 24px);margin:0 12px 12px;padding:7px;border:1px solid #dc3545;border-radius:6px;background:#fff;color:#dc3545;cursor:pointer}.gallery-delete:hover{background:#dc3545;color:#fff}.gallery-upload{margin-top:18px;padding:18px;border:1px solid #e9ecef;border-radius:12px;background:#fafbfc}.gallery-file-row{display:flex;align-items:center;gap:12px;flex-wrap:wrap;margin:8px 0 12px}.gallery-file-input{position:absolute;width:1px;height:1px;padding:0;margin:-1px;overflow:hidden;clip:rect(0,0,0,0);white-space:nowrap;border:0}.gallery-file-label{display:inline-flex;align-items:center;gap:7px;padding:9px 14px;border:1px solid #0066cc;border-radius:8px;background:#0066cc;color:#fff;font-size:14px;font-weight:600;line-height:1.2;cursor:pointer;transition:background .15s ease,border-color .15s ease,box-shadow .15s ease}.gallery-file-label:hover{background:#0056ad;border-color:#0056ad}.gallery-file-label:active{background:#004b96}.gallery-file-label:focus-within{box-shadow:0 0 0 3px rgba(0,102,204,.18)}.gallery-file-name{font-size:13px;color:#6c757d;min-width:0;word-break:break-word}.gallery-upload-button{margin-top:0}.gallery-message{margin-top:12px}.gallery-empty{text-align:center;padding:40px 20px;color:#777}.gallery-albums{margin-top:18px;padding:18px;border:1px solid #e9ecef;border-radius:12px;background:#fff}.gallery-album-form{display:flex;gap:8px;max-width:520px}.gallery-album-form input{flex:1;min-width:0}
@media (max-width: 600px){.gallery-album-form{flex-direction:column;align-items:stretch;max-width:none}.gallery-album-form input,.gallery-album-form button{width:100%;box-sizing:border-box}.gallery-album-form button{margin-top:2px}.gallery-file-row{align-items:stretch;flex-direction:column}.gallery-file-label,.gallery-upload-button{width:100%;box-sizing:border-box;justify-content:center}.gallery-file-name{text-align:center}}
</style>
</head>
<body><div class="box">
<div class="gallery-header"><div class="sentryiq-vault-brand-wrap"><img class="sentryiq-vault-banner" src="sentryiq-logo-wide.webp" width="1952" height="588" alt="SentryIQ"><span class="sentryiq-vault-status">Gallery</span></div><a href="index.php" class="btn btn-primary" style="text-decoration:none;">Back to Vault</a></div>
<div class="gallery-upload"><h3 style="margin-top:0;">Upload Photos</h3><form id="gallery-upload-form" method="POST" action="gallery_upload.php" enctype="multipart/form-data"><input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8'); ?>"><div class="gallery-file-row"><label class="gallery-file-label" for="gallery-photo-input">📷 Choose Photos</label><input class="gallery-file-input" id="gallery-photo-input" type="file" name="photos[]" accept="image/jpeg,image/png,image/gif,image/webp" multiple required><span id="gallery-file-name" class="gallery-file-name">No photos selected</span><button type="submit" class="btn btn-primary gallery-upload-button">Upload Photos</button></div></form><div id="gallery-message" class="gallery-message" aria-live="polite"></div></div>
<div class="gallery-albums"><h3 style="margin-top:0;">Albums</h3><form id="album-form" class="gallery-album-form" method="POST" action="gallery_album.php"><input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8'); ?>"><input type="hidden" name="action" value="create"><input class="input-field" type="text" name="name" maxlength="80" placeholder="New album name" required><button type="submit" class="btn btn-primary">Create Album</button></form><div id="album-message" class="gallery-message" aria-live="polite"></div></div>
<div class="gallery-tools" aria-label="Gallery album filter"><button type="button" class="gallery-filter active" data-album="all">All (<?php echo count($photos); ?>)</button><?php foreach ($albums as $album => $_members): ?><button type="button" class="gallery-filter" data-album="<?php echo htmlspecialchars($album, ENT_QUOTES, 'UTF-8'); ?>"><?php $count=count(array_filter($photoAlbums, static fn(string $current): bool => $current === $album)); echo htmlspecialchars($album); ?> (<?php echo $count; ?>)</button><?php endforeach; ?></div>
<div id="gallery-grid" class="gallery-grid"><?php if ($photos === []): ?><div class="gallery-empty" style="grid-column:1/-1;">No photos in the gallery yet.</div><?php else: foreach ($photos as $photo): $currentAlbum=$photoAlbums[$photo['id']]??'Unassigned'; $metadata=$photoMetadata[$photo['id']]??null; $filename=is_array($metadata)?(string)($metadata['filename']??''):''; $createdAt=is_array($metadata)?(int)($metadata['created_at']??0):0; ?>
<div class="gallery-card" data-photo-card data-album="<?php echo htmlspecialchars($currentAlbum,ENT_QUOTES,'UTF-8'); ?>"><img src="gallery_image.php?id=<?php echo rawurlencode($photo['id']); ?>" loading="lazy" alt="<?php echo htmlspecialchars($filename!==''?$filename:'Gallery photo',ENT_QUOTES,'UTF-8'); ?>"><p class="gallery-album-label"><?php echo htmlspecialchars($currentAlbum); ?></p><?php if($filename!==''): ?><p title="<?php echo htmlspecialchars($filename,ENT_QUOTES,'UTF-8'); ?>"><?php echo htmlspecialchars($filename); ?></p><?php endif; ?><?php if($createdAt>0): ?><p class="gallery-meta"><?php echo htmlspecialchars(date('Y-m-d H:i',$createdAt)); ?></p><?php endif; ?><select class="gallery-move" data-photo-id="<?php echo htmlspecialchars($photo['id'],ENT_QUOTES,'UTF-8'); ?>" aria-label="Move photo to album"><?php foreach($albums as $album=>$_members): ?><option value="<?php echo htmlspecialchars($album,ENT_QUOTES,'UTF-8'); ?>" <?php echo $album===$currentAlbum?'selected':''; ?>><?php echo htmlspecialchars($album); ?></option><?php endforeach; ?></select><button type="button" class="gallery-delete" data-photo-id="<?php echo htmlspecialchars($photo['id'],ENT_QUOTES,'UTF-8'); ?>">Delete Photo</button></div>
<?php endforeach; endif; ?></div></div>
<script>
const csrf=<?php echo json_encode($csrf,JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_AMP|JSON_HEX_QUOT); ?>;
async function postAlbumForm(form){const response=await fetch('gallery_album.php',{method:'POST',body:new FormData(form),credentials:'same-origin',headers:{Accept:'application/json'}});const text=await response.text();let data;try{data=JSON.parse(text);}catch(_){throw new Error('The album request returned an invalid response.');}if(!response.ok||data.status!=='ok')throw new Error(data.message||'Gallery operation failed.');return data;}
async function responseJson(response,fallback){const text=await response.text();let data;try{data=JSON.parse(text);}catch(_){throw new Error(fallback);}return data;}
const galleryPhotoInput=document.getElementById('gallery-photo-input');const galleryFileName=document.getElementById('gallery-file-name');galleryPhotoInput.addEventListener('change',function(){const count=this.files?.length||0;if(count===0){galleryFileName.textContent='No photos selected';}else if(count===1){galleryFileName.textContent=this.files[0].name;}else{galleryFileName.textContent=`${count} photos selected`;}});
document.getElementById('gallery-upload-form').addEventListener('submit',async function(event){event.preventDefault();const form=this;const input=form.querySelector('input[type="file"][name="photos[]"]');const message=document.getElementById('gallery-message');const files=Array.from(input?.files||[]);if(files.length===0){message.textContent='Please select at least one photo.';return;}const button=form.querySelector('button[type="submit"]');button.disabled=true;input.disabled=true;const totals={stored:0,duplicate:0,rejected:0};const failures=[];try{for(let index=0;index<files.length;index++){const file=files[index];message.textContent=`Uploading ${index+1} of ${files.length}: ${file.name}`;const uploadData=new FormData();uploadData.append('csrf_token',csrf);uploadData.append('photos[]',file,file.name);try{const response=await fetch(form.action,{method:'POST',body:uploadData,credentials:'same-origin',headers:{Accept:'application/json'}});const data=await responseJson(response,'The upload returned an invalid response.');if(!response.ok||!Array.isArray(data.results)){totals.rejected++;failures.push(`${file.name}: ${data.message||'Upload failed.'}`);continue;}const result=data.results[0];if(result?.status==='stored')totals.stored++;else if(result?.status==='duplicate')totals.duplicate++;else{totals.rejected++;failures.push(`${file.name}: ${result?.message||'Upload rejected.'}`);}}catch(error){totals.rejected++;failures.push(`${file.name}: ${error.message||'Upload failed.'}`);}}message.textContent=`Upload complete: ${totals.stored} stored, ${totals.duplicate} duplicate, ${totals.rejected} rejected.`;if(failures.length){const details=document.createElement('div');details.style.marginTop='8px';details.style.color='#dc3545';details.textContent=failures.join(' | ');message.appendChild(details);}if(totals.stored>0)setTimeout(()=>window.location.reload(),1200);}catch(error){message.textContent=error.message||'Upload failed.';}finally{button.disabled=false;input.disabled=false;}});
document.getElementById('album-form').addEventListener('submit',async function(event){event.preventDefault();const message=document.getElementById('album-message');message.textContent='Creating album…';try{await postAlbumForm(this);message.textContent='Album created.';window.location.reload();}catch(error){message.textContent=error.message||'Unable to create album.';}});
document.querySelectorAll('.gallery-move').forEach(function(select){select.dataset.previous=select.value;select.addEventListener('change',async function(){const previous=this.dataset.previous||this.value;const form=new FormData();form.append('csrf_token',csrf);form.append('action','move');form.append('photo_id',this.dataset.photoId);form.append('album',this.value);this.disabled=true;try{const response=await fetch('gallery_album.php',{method:'POST',body:form,credentials:'same-origin',headers:{Accept:'application/json'}});const data=await responseJson(response,'The album request returned an invalid response.');if(!response.ok||data.status!=='ok')throw new Error(data.message||'Unable to move photo.');this.dataset.previous=data.album;const card=this.closest('[data-photo-card]');card.dataset.album=data.album;card.querySelector('.gallery-album-label').textContent=data.album;applyFilter(document.querySelector('.gallery-filter.active')?.dataset.album||'all');}catch(error){this.value=previous;alert(error.message||'Unable to move photo.');}finally{this.disabled=false;}});});
document.querySelectorAll('.gallery-delete').forEach(function(button){button.addEventListener('click',async function(){if(!window.confirm('Delete this photo permanently?'))return;const card=this.closest('[data-photo-card]');this.disabled=true;try{const form=new FormData();form.append('csrf_token',csrf);form.append('photo_id',this.dataset.photoId);const response=await fetch('gallery_delete.php',{method:'POST',body:form,credentials:'same-origin',headers:{Accept:'application/json'}});const data=await responseJson(response,'The delete request returned an invalid response.');if(!response.ok||data.status!=='ok')throw new Error(data.message||'Unable to delete photo.');card.remove();applyFilter(document.querySelector('.gallery-filter.active')?.dataset.album||'all');}catch(error){alert(error.message||'Unable to delete photo.');this.disabled=false;}});});
function applyFilter(album){document.querySelectorAll('[data-photo-card]').forEach(function(card){card.style.display=album==='all'||card.dataset.album===album?'':'none';});document.querySelectorAll('.gallery-filter').forEach(function(button){button.classList.toggle('active',button.dataset.album===album);});}document.querySelectorAll('.gallery-filter').forEach(function(button){button.addEventListener('click',function(){applyFilter(this.dataset.album);});});
</script></body></html>
