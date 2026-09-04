<?php

declare(strict_types=1);

namespace SentryIQCloud\Gallery\Image;

use RuntimeException;

final class ThumbnailGenerator
{
    public function __construct(
        private readonly int $maxDimension = 480,
        private readonly int $webpQuality = 80,
    ) {
        if ($this->maxDimension < 1 || $this->webpQuality < 1 || $this->webpQuality > 100) {
            throw new RuntimeException('Invalid thumbnail configuration.');
        }
    }

    public function fromWebp(string $webp): string
    {
        if ($webp === '') {
            throw new RuntimeException('Cannot generate a thumbnail from empty image data.');
        }

        $image = @imagecreatefromstring($webp);
        if ($image === false) {
            throw new RuntimeException('Unable to decode normalized WebP image.');
        }

        try {
            $width = imagesx($image);
            $height = imagesy($image);
            if ($width < 1 || $height < 1) {
                throw new RuntimeException('Invalid image dimensions.');
            }

            $scale = min(1.0, $this->maxDimension / max($width, $height));
            $newWidth = max(1, (int) round($width * $scale));
            $newHeight = max(1, (int) round($height * $scale));

            $thumbnail = imagecreatetruecolor($newWidth, $newHeight);
            if ($thumbnail === false) {
                throw new RuntimeException('Unable to allocate thumbnail canvas.');
            }

            try {
                imagealphablending($thumbnail, false);
                imagesavealpha($thumbnail, true);
                $transparent = imagecolorallocatealpha($thumbnail, 0, 0, 0, 127);
                imagefilledrectangle($thumbnail, 0, 0, $newWidth, $newHeight, $transparent);

                if (!imagecopyresampled($thumbnail, $image, 0, 0, 0, 0, $newWidth, $newHeight, $width, $height)) {
                    throw new RuntimeException('Unable to resize image for thumbnail.');
                }

                ob_start();
                if (!imagewebp($thumbnail, null, $this->webpQuality)) {
                    throw new RuntimeException('Thumbnail WebP conversion failed.');
                }
                $output = ob_get_clean();
                if (!is_string($output) || $output === '') {
                    throw new RuntimeException('Thumbnail conversion produced no data.');
                }

                return $output;
            } finally {
                imagedestroy($thumbnail);
            }
        } finally {
            imagedestroy($image);
        }
    }
}
