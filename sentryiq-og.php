<?php

declare(strict_types=1);

header('Content-Type: image/png');
header('Cache-Control: public, max-age=86400');
header('X-Content-Type-Options: nosniff');

$source = __DIR__ . '/assets/images/sentryiq-icon.png';
if (!is_file($source) || !extension_loaded('gd')) {
    http_response_code(500);
    exit;
}

$image = @imagecreatefrompng($source);
if ($image === false) {
    http_response_code(500);
    exit;
}

$width = imagesx($image);
$height = imagesy($image);
$size = 1200;
$background = imagecolorat($image, 0, 0);

$canvas = imagecreatetruecolor($size, $size);
imagealphablending($canvas, true);
imagesavealpha($canvas, true);

$red = ($background >> 16) & 0xff;
$green = ($background >> 8) & 0xff;
$blue = $background & 0xff;
$fill = imagecolorallocate($canvas, $red, $green, $blue);
imagefill($canvas, 0, 0, $fill);

$scale = min($size / $width, $size / $height);
$newWidth = max(1, (int)round($width * $scale));
$newHeight = max(1, (int)round($height * $scale));
$dstX = (int)floor(($size - $newWidth) / 2);
$dstY = (int)floor(($size - $newHeight) / 2);

imagealphablending($image, true);
imagecopyresampled($canvas, $image, $dstX, $dstY, 0, 0, $newWidth, $newHeight, $width, $height);

imagepng($canvas, null, 9);
imagedestroy($image);
imagedestroy($canvas);
