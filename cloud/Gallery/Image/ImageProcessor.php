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

        return $this->toWebpWithGd($input, $width, $height, $mime);
    }

    public function toWebpFromFile(string $path): string
    {
        if ($path === '' || !is_file($path) || is_link($path) || !is_readable($path)) {
            throw new RuntimeException('Image upload source is unavailable.');
        }

        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $mime = $finfo->file($path);
        if (!is_string($mime) || !in_array($mime, self::ALLOWED_MIME_TYPES, true)) {
            throw new RuntimeException('Unsupported or invalid image type.');
        }

        $imageInfo = @getimagesize($path);
        if ($imageInfo === false) {
            throw new RuntimeException('Image could not be decoded.');
        }

        [$width, $height] = $imageInfo;
        if ($width < 1 || $height < 1) {
            throw new RuntimeException('Image dimensions are invalid.');
        }

        if (class_exists('\\Imagick')) {
            try {
                return $this->toWebpWithImagickFile($path);
            } catch (RuntimeException $exception) {
                // Fall back to GD for servers where ImageMagick cannot decode
                // this particular file or cannot emit WebP.
            }
        }

        return $this->toWebpWithGdFile($path, $width, $height, $mime);
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
            $this->normalizeImagickOrientation($image);
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

    private function toWebpWithImagickFile(string $path): string
    {
        $image = new \Imagick();
        try {
            if (!$image->readImage($path)) {
                throw new RuntimeException('Image could not be decoded.');
            }

            if ($image->getNumberImages() !== 1) {
                throw new RuntimeException('Animated or multi-frame images are not supported.');
            }

            $image->setIteratorIndex(0);
            $this->normalizeImagickOrientation($image);
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

    private function normalizeImagickOrientation(\Imagick $image): void
    {
        try {
            if (method_exists($image, 'autoOrientImage')) {
                $image->autoOrientImage();
            }
            if (defined('Imagick::ORIENTATION_TOPLEFT')) {
                $image->setImageOrientation(\Imagick::ORIENTATION_TOPLEFT);
            }
            // Apply the camera orientation to the pixels once, then remove EXIF
            // orientation metadata so the browser cannot rotate the WebP again.
            $image->profileImage('exif', '');
        } catch (\ImagickException $exception) {
            throw new RuntimeException('Image orientation could not be normalized.', 0, $exception);
        }
    }

    private function toWebpWithGdFile(string $path, int $width, int $height, string $mime): string
    {
        $loader = match ($mime) {
            'image/jpeg' => 'imagecreatefromjpeg',
            'image/png' => 'imagecreatefrompng',
            'image/gif' => 'imagecreatefromgif',
            'image/webp' => 'imagecreatefromwebp',
            default => null,
        };

        if ($loader === null || !function_exists($loader)) {
            throw new RuntimeException('The server cannot decode this image type.');
        }

        $image = @$loader($path);
        if ($image === false) {
            throw new RuntimeException('Image could not be decoded.');
        }

        if ($mime === 'image/jpeg') {
            $this->normalizeGdJpegOrientation($image, $path);
        }

        return $this->encodeGdImage($image);
    }

    private function toWebpWithGd(string $input, int $width, int $height, string $mime): string
    {
        $image = @imagecreatefromstring($input);
        if ($image === false) {
            throw new RuntimeException('Image could not be decoded.');
        }

        return $this->encodeGdImage($image, $width, $height);
    }

    private function normalizeGdJpegOrientation(\GdImage &$image, string $path): void
    {
        if (!function_exists('exif_read_data')) {
            return;
        }

        $exif = @exif_read_data($path, null, true);
        $orientation = is_array($exif) ? (int)($exif['IFD0']['Orientation'] ?? $exif['Orientation'] ?? 1) : 1;

        switch ($orientation) {
            case 2:
                imageflip($image, IMG_FLIP_HORIZONTAL);
                break;
            case 3:
                $rotated = imagerotate($image, 180, 0);
                if ($rotated !== false) { imagedestroy($image); $image = $rotated; }
                break;
            case 4:
                imageflip($image, IMG_FLIP_VERTICAL);
                break;
            case 5:
                imageflip($image, IMG_FLIP_HORIZONTAL);
                $rotated = imagerotate($image, 90, 0);
                if ($rotated !== false) { imagedestroy($image); $image = $rotated; }
                break;
            case 6:
                $rotated = imagerotate($image, -90, 0);
                if ($rotated !== false) { imagedestroy($image); $image = $rotated; }
                break;
            case 7:
                imageflip($image, IMG_FLIP_HORIZONTAL);
                $rotated = imagerotate($image, -90, 0);
                if ($rotated !== false) { imagedestroy($image); $image = $rotated; }
                break;
            case 8:
                $rotated = imagerotate($image, 90, 0);
                if ($rotated !== false) { imagedestroy($image); $image = $rotated; }
                break;
        }
    }

    private function encodeGdImage(\GdImage $image, ?int $width = null, ?int $height = null): string
    {
        try {
            $width ??= imagesx($image);
            $height ??= imagesy($image);
            if ($width < 1 || $height < 1) {
                throw new RuntimeException('Image dimensions are invalid.');
            }

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
