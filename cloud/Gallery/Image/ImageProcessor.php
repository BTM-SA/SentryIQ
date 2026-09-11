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

        if (class_exists('\\Imagick')) {
            try {
                return $this->toWebpWithImagick($input);
            } catch (RuntimeException $exception) {
                // Fall back to GD so environments with a partially supported
                // ImageMagick/WebP build can still process supported images.
            }
        }

        return $this->toWebpWithGd($input, $width, $height);
    }

    private function toWebpWithImagick(string $input): string
    {
        $image = new \Imagick();
        try {
            if (!$image->readImageBlob($input)) {
                throw new RuntimeException('Image could not be decoded.');
            }

            if ($image->getNumberImages() !== 1) {
                throw new RuntimeException('Animated or multi-frame images are not supported.');
            }

            $image->setIteratorIndex(0);
            $image->setImageFormat('webp');

            if ($this->webpQuality === 100) {
                $image->setOption('webp:lossless', 'true');
            } else {
                $image->setOption('webp:lossless', 'false');
                $image->setImageCompressionQuality($this->webpQuality);
            }

            if (!$this->preserveTransparency) {
                $image->setImageAlphaChannel(\Imagick::ALPHACHANNEL_REMOVE);
                $image->setImageBackgroundColor('white');
            }

            $output = $image->getImagesBlob();
            if (!is_string($output) || $output === '') {
                throw new RuntimeException('WebP conversion produced no data.');
            }

            return $output;
        } catch (\ImagickException $exception) {
            throw new RuntimeException('Image could not be processed.', 0, $exception);
        } finally {
            $image->clear();
            $image->destroy();
        }
    }

    private function toWebpWithGd(string $input, int $width, int $height): string
    {
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
                $quality = $this->webpQuality === 100 && defined('IMG_WEBP_LOSSLESS')
                    ? IMG_WEBP_LOSSLESS
                    : $this->webpQuality;
                if (!imagewebp($canvas, null, $quality)) {
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
