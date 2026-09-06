<?php

declare(strict_types=1);

require_once __DIR__ . '/security_bootstrap.php';
sentryiq_security_bootstrap();
sentryiq_require_auth();
sentryiq_require_csrf();

$configFile = __DIR__ . '/sentryiq_config.php';
$config = is_file($configFile) ? require $configFile : [];
$dataDir = is_array($config) ? rtrim((string)($config['data_dir'] ?? ''), '/') : '';
if ($dataDir === '' || !str_starts_with($dataDir, '/') || !is_dir($dataDir) || is_link($dataDir)) { http_response_code(503); exit('SentryIQ secure runtime is unavailable.'); }
require_once __DIR__ . '/cloud/Gallery/Storage/PhotoMetadataStore.php';
use SentryIQCloud\Gallery\Storage\PhotoMetadataStore;
header('Content-Type: application/json; charset=utf-8');

$photoId = (string)($_POST['photo_id'] ?? '');
if (!preg_match('/^[a-f0-9]{32}$/', $photoId)) { http_response_code(400); echo json_encode(['status'=>'error','message'=>'Invalid photo ID.']); exit; }
$galleryRoot=$dataDir.'/gallery'; $metadataStore=new PhotoMetadataStore($galleryRoot.'/metadata.json'); $metadata=$metadataStore->find($photoId);
$original=null; $thumbnail=null; $foundBucket=null;

if (is_array($metadata) && preg_match('/^img\d+\.webp$/',(string)($metadata['filename']??'')) && preg_match('/^[a-f0-9]{64}$/',(string)($metadata['content_hash']??''))) {
    $bucket=substr($metadata['content_hash'],0,2); $filename=$metadata['filename'];
    $candidateOriginal=$galleryRoot.'/photos/'.$bucket.'/'.$filename; $candidateThumbnail=$galleryRoot.'/thumbnails/'.$bucket.'/'.$filename;
    if (is_file($candidateOriginal) && !is_link($candidateOriginal) && is_file($candidateThumbnail) && !is_link($candidateThumbnail)) { $foundBucket=$bucket; $original=$candidateOriginal; $thumbnail=$candidateThumbnail; }
}

// Backward compatibility for legacy random-ID filenames.
if ($original===null || $thumbnail===null) {
    for($bucket=0;$bucket<256;$bucket++){$bucketName=str_pad(dechex($bucket),2,'0',STR_PAD_LEFT);$candidate=$galleryRoot.'/thumbnails/'.$bucketName.'/'.$photoId.'.webp';if(is_file($candidate)&&!is_link($candidate)){$foundBucket=$bucketName;$thumbnail=$candidate;$original=$galleryRoot.'/photos/'.$bucketName.'/'.$photoId.'.webp';break;}}
}
if($foundBucket===null || $original===null || $thumbnail===null || is_link($original) || is_link($thumbnail) || !is_file($original)){http_response_code(404);echo json_encode(['status'=>'error','message'=>'Photo does not exist.']);exit;}

$albumsFile=$galleryRoot.'/albums.json'; $albums=null;
if(is_file($albumsFile)){ $json=@file_get_contents($albumsFile); $albums=is_string($json)?json_decode($json,true):null; if(!is_array($albums)){http_response_code(500);echo json_encode(['status'=>'error','message'=>'Gallery album index is invalid.']);exit;} }
$duplicateFile=$galleryRoot.'/duplicate-index.json'; $index=null;
if(is_file($duplicateFile)){ $json=@file_get_contents($duplicateFile); $index=is_string($json)?json_decode($json,true):null; if(!is_array($index)){http_response_code(500);echo json_encode(['status'=>'error','message'=>'Duplicate index is invalid.']);exit;} }
if(!@unlink($original)){http_response_code(500);echo json_encode(['status'=>'error','message'=>'Unable to delete gallery photo.']);exit;}
if(!@unlink($thumbnail)){http_response_code(500);echo json_encode(['status'=>'error','message'=>'Photo was deleted but its thumbnail could not be removed.']);exit;}

if(is_array($albums)){ $changed=false; foreach($albums as &$photos){if(!is_array($photos))continue;$filtered=array_values(array_filter($photos,static fn(mixed $id):bool=>$id!==$photoId));if($filtered!==$photos)$changed=true;$photos=$filtered;}unset($photos);if($changed){$encoded=json_encode($albums,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);$temporary=$albumsFile.'.tmp-'.bin2hex(random_bytes(8));if($encoded===false||@file_put_contents($temporary,$encoded.PHP_EOL,LOCK_EX)===false||!@rename($temporary,$albumsFile)){@unlink($temporary);http_response_code(500);echo json_encode(['status'=>'error','message'=>'Photo deleted but album index cleanup failed.']);exit;}@chmod($albumsFile,0600);}}
if(is_array($index)){ $changed=false; foreach($index as $hash=>$indexedPhotoId){if($indexedPhotoId===$photoId){unset($index[$hash]);$changed=true;}}if($changed){$encoded=json_encode($index,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES);$temporary=$duplicateFile.'.tmp-'.bin2hex(random_bytes(8));if($encoded===false||@file_put_contents($temporary,$encoded.PHP_EOL,LOCK_EX)===false||!@rename($temporary,$duplicateFile)){@unlink($temporary);http_response_code(500);echo json_encode(['status'=>'error','message'=>'Photo deleted but duplicate index cleanup failed.']);exit;}@chmod($duplicateFile,0600);}}
try{$metadataStore->remove($photoId);}catch(RuntimeException $exception){http_response_code(500);echo json_encode(['status'=>'error','message'=>'Photo deleted but metadata cleanup failed.']);exit;}
try{if(function_exists('log_security_event')&&function_exists('get_visitor_ip'))log_security_event('GALLERY_PHOTO_DELETED',get_visitor_ip(),$_SESSION['app_username']??'unknown',['photo_id'=>$photoId]);}catch(Throwable $exception){error_log('SentryIQ Gallery delete audit logging failed: '.$exception->getMessage());}
echo json_encode(['status'=>'ok','photo_id'=>$photoId],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
