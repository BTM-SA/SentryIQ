<?php

declare(strict_types=1);

namespace SentryIQCloud\Gallery\Image;

use RuntimeException;

final class ImageDerivativeGenerator
{
    public function __construct(
        private readonly int $maxDimension,
        private readonly int $webpQuality,
        private readonly bool $preserveTransparency = true,
        private readonly bool $forceEncode = false,
    ) {
        if ($this->maxDimension < 0 || $this->webpQuality < 1 || $this->webpQuality > 100) {
            throw new RuntimeException('Invalid image derivative configuration.');
        }
    }

    public function fromWebp(string $webp): string
    {
        if ($webp === '') throw new RuntimeException('Cannot generate a derivative from empty image data.');
        $image = @imagecreatefromstring($webp);
        if ($image === false) throw new RuntimeException('Unable to decode normalized WebP image.');

        try {
            $width = imagesx($image);
            $height = imagesy($image);
            if ($width < 1 || $height < 1) throw new RuntimeException('Invalid image dimensions.');
            $needsResize = $this->maxDimension > 0 && max($width, $height) > $this->maxDimension;
            if (!$needsResize && !$this->forceEncode) return $webp;

            if ($needsResize) {
                $scale = $this->maxDimension / max($width, $height);
                $newWidth = max(1, (int)round($width * $scale));
                $newHeight = max(1, (int)round($height * $scale));
            } else {
                $newWidth = $width;
                $newHeight = $height;
            }

            $canvas = imagecreatetruecolor($newWidth, $newHeight);
            if ($canvas === false) throw new RuntimeException('Unable to allocate image canvas.');
            try {
                imagealphablending($canvas, false);
                imagesavealpha($canvas, $this->preserveTransparency);
                if ($this->preserveTransparency) {
                    $transparent = imagecolorallocatealpha($canvas, 0, 0, 0, 127);
                    imagefilledrectangle($canvas, 0, 0, $newWidth, $newHeight, $transparent);
                } else {
                    $background = imagecolorallocate($canvas, 255, 255, 255);
                    imagefilledrectangle($canvas, 0, 0, $newWidth, $newHeight, $background);
                }
                if (!imagecopyresampled($canvas, $image, 0, 0, 0, 0, $newWidth, $newHeight, $width, $height)) {
                    throw new RuntimeException('Unable to resize image derivative.');
                }
                ob_start();
                $quality = $this->webpQuality === 100 && defined('IMG_WEBP_LOSSLESS') ? IMG_WEBP_LOSSLESS : $this->webpQuality;
                if (!imagewebp($canvas, null, $quality)) throw new RuntimeException('WebP derivative conversion failed.');
                $output = ob_get_clean();
                if (!is_string($output) || $output === '') throw new RuntimeException('WebP derivative produced no data.');
                return $output;
            } finally {
                imagedestroy($canvas);
            }
        } finally {
            imagedestroy($image);
        }
    }
}
