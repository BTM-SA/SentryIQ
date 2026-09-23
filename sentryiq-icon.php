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

$size = filter_input(INPUT_GET, 'size', FILTER_VALIDATE_INT);
if ($size === false || $size === null || $size < 16 || $size > 1024) {
    readfile($icon);
    exit;
}

if (!extension_loaded('gd')) {
    readfile($icon);
    exit;
}

$source = @imagecreatefrompng($icon);
if ($source === false) {
    readfile($icon);
    exit;
}

$width = imagesx($source);
$height = imagesy($source);
$background = imagecolorat($source, 0, 0);
$red = ($background >> 16) & 0xff;
$green = ($background >> 8) & 0xff;
$blue = $background & 0xff;

$canvas = imagecreatetruecolor($size, $size);
$fill = imagecolorallocate($canvas, $red, $green, $blue);
imagefill($canvas, 0, 0, $fill);

$scale = min($size / $width, $size / $height);
$newWidth = max(1, (int)round($width * $scale));
$newHeight = max(1, (int)round($height * $scale));
$dstX = (int)floor(($size - $newWidth) / 2);
$dstY = (int)floor(($size - $newHeight) / 2);

imagecopyresampled($canvas, $source, $dstX, $dstY, 0, 0, $newWidth, $newHeight, $width, $height);
imagepng($canvas, null, 9);

imagedestroy($source);
imagedestroy($canvas);
