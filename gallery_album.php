<?php

declare(strict_types=1);
require_once __DIR__.'/security_bootstrap.php'; sentryiq_security_bootstrap(); sentryiq_require_auth(); sentryiq_require_csrf();
$configFile=__DIR__.'/sentryiq_config.php'; $config=is_file($configFile)?require $configFile:[]; $dataDir=is_array($config)?rtrim((string)($config['data_dir']??''),'/'):'';
if($dataDir===''||!str_starts_with($dataDir,'/')||!is_dir($dataDir)||is_link($dataDir)){http_response_code(503);exit('SentryIQ secure runtime is unavailable.');}
$vaultEngine=$dataDir.'/vault_engine.php'; if(!is_file($vaultEngine)||is_link($vaultEngine)){http_response_code(503);exit('SentryIQ secure logging runtime is unavailable.');} require_once $vaultEngine;
require_once __DIR__.'/cloud/Gallery/Albums/AlbumStore.php'; require_once __DIR__.'/cloud/Gallery/Storage/PhotoMetadataStore.php';
use SentryIQCloud\Gallery\Albums\AlbumStore; use SentryIQCloud\Gallery\Storage\PhotoMetadataStore;
header('Content-Type: application/json; charset=utf-8');
try{
 $galleryRoot=$dataDir.'/gallery'; $store=new AlbumStore($galleryRoot.'/albums.json'); $action=(string)($_POST['action']??'');
 if($action==='create'){$store->create((string)($_POST['name']??''));log_security_event('GALLERY_ALBUM_CREATED',get_visitor_ip(),$_SESSION['app_username']??'unknown');echo json_encode(['status'=>'ok','albums'=>$store->albums()],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);exit;}
 if($action==='move'){
  $photoId=(string)($_POST['photo_id']??''); $album=(string)($_POST['album']??''); if(!preg_match('/^[a-f0-9]{32}$/',$photoId))throw new RuntimeException('Invalid photo ID.');
  $metadata=(new PhotoMetadataStore($galleryRoot.'/metadata.json'))->find($photoId); $found=false;
  if(is_array($metadata)&&preg_match('/^img\d+\.webp$/',(string)($metadata['filename']??''))&&preg_match('/^[a-f0-9]{64}$/',(string)($metadata['content_hash']??''))){$bucket=substr($metadata['content_hash'],0,2);$thumbnail=$galleryRoot.'/thumbnails/'.$bucket.'/'.$metadata['filename'];$found=is_file($thumbnail)&&!is_link($thumbnail);}
  if(!$found){$thumbnailRoot=$galleryRoot.'/thumbnails';for($bucket=0;$bucket<256;$bucket++){$bucketName=str_pad(dechex($bucket),2,'0',STR_PAD_LEFT);$thumbnail=$thumbnailRoot.'/'.$bucketName.'/'.$photoId.'.webp';if(is_file($thumbnail)&&!is_link($thumbnail)){$found=true;break;}}}
  if(!$found)throw new RuntimeException('Photo does not exist.');
  $store->move($photoId,$album); log_security_event('GALLERY_PHOTO_MOVED',get_visitor_ip(),$_SESSION['app_username']??'unknown',['photo_id'=>$photoId,'album'=>$album]); echo json_encode(['status'=>'ok','album'=>$store->albumFor($photoId)],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);exit;
 }
 http_response_code(400);echo json_encode(['status'=>'error','message'=>'Invalid gallery action.']);
}catch(RuntimeException $exception){http_response_code(400);echo json_encode(['status'=>'error','message'=>$exception->getMessage()],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);}
