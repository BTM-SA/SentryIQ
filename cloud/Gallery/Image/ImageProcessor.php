<?php

declare(strict_types=1);

namespace SentryIQCloud\Gallery\Image;

use RuntimeException;

final class ImageProcessor
{
    private const ALLOWED_MIME_TYPES = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];

    public function __construct(
        private readonly int $webpQuality = 85,
        private readonly bool $preserveTransparency = true,
    ) {
        if ($webpQuality < 1 || $webpQuality > 100) {
            throw new RuntimeException('Invalid WebP quality.');
        }
    }

    public function toWebp(string $input): string
    {
        if ($input === '') {
            throw new RuntimeException('Image is empty.');
        }

        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $mime = $finfo->buffer($input);
        if (!is_string($mime) || !in_array($mime, self::ALLOWED_MIME_TYPES, true)) {
            throw new RuntimeException('Unsupported or invalid image type.');
        }

        $imageInfo = @getimagesizefromstring($input);
        if ($imageInfo === false) {
            throw new RuntimeException('Image could not be decoded.');
        }

        [$width, $height] = $imageInfo;
        if ($width < 1 || $height < 1) {
            throw new RuntimeException('Image dimensions are invalid.');
        }

        $image = @imagecreatefromstring($input);
        if ($image === false) {
            throw new RuntimeException('Image could not be decoded.');
        }

        try {
            $canvas = imagecreatetruecolor($width, $height);
            if ($canvas === false) {
                throw new RuntimeException('Unable to allocate image canvas.');
            }
            try {
                imagealphablending($canvas, false);
                imagesavealpha($canvas, $this->preserveTransparency);
                if ($this->preserveTransparency) {
                    $transparent = imagecolorallocatealpha($canvas, 0, 0, 0, 127);
                    imagefilledrectangle($canvas, 0, 0, $width, $height, $transparent);
                } else {
                    $background = imagecolorallocate($canvas, 255, 255, 255);
                    imagefilledrectangle($canvas, 0, 0, $width, $height, $background);
                }
                imagecopy($canvas, $image, 0, 0, 0, 0, $width, $height);
                ob_start();
                if (!imagewebp($canvas, null, $this->webpQuality)) {
                    throw new RuntimeException('WebP conversion failed.');
                }
                $output = ob_get_clean();
                if (!is_string($output) || $output === '') {
                    throw new RuntimeException('WebP conversion produced no data.');
                }
                return $output;
            } finally {
                imagedestroy($canvas);
            }
        } finally {
            imagedestroy($image);
        }
    }

    public function contentHash(string $normalizedWebp): string
    {
        if ($normalizedWebp === '') {
            throw new RuntimeException('Cannot hash empty image data.');
        }
        return hash('sha256', $normalizedWebp);
    }
}
