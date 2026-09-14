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

        // EXIF normalization can require a second full-size GD image. Raise
        // PHP's request memory ceiling when the host permits it rather than
        // changing the uploaded image's dimensions or quality.
        $this->ensureGdMemoryForImage($width, $height);

        $image = @$loader($path);
        if ($image === false) {
            throw new RuntimeException('Image could not be decoded.');
        }

        if ($mime === 'image/jpeg') {
            $image = $this->applyJpegExifOrientation($image, $path);
        }

        return $this->encodeGdImage($image);
    }

    private function toWebpWithGd(string $input, int $width, int $height, string $mime): string
    {
        $this->ensureGdMemoryForImage($width, $height);

        $image = @imagecreatefromstring($input);
        if ($image === false) {
            throw new RuntimeException('Image could not be decoded.');
        }

        return $this->encodeGdImage($image, $width, $height);
    }

    private function ensureGdMemoryForImage(int $width, int $height): void
    {
        $currentLimit = ini_get('memory_limit');
        $currentBytes = is_string($currentLimit) ? $this->iniBytes($currentLimit) : 0;
        if ($currentBytes === 0) {
            return;
        }

        // Two full true-colour GD images plus PHP/application overhead. This
        // only raises the PHP memory ceiling; it never changes image geometry
        // or WebP quality settings.
        $requiredBytes = ($width * $height * 8) + (64 * 1024 * 1024);
        if ($requiredBytes <= $currentBytes) {
            return;
        }

        $targetBytes = max(256 * 1024 * 1024, $requiredBytes);
        $targetLimit = (int)ceil($targetBytes / (1024 * 1024)) . 'M';
        @ini_set('memory_limit', $targetLimit);
    }

    private function iniBytes(string $value): int
    {
        $value = trim($value);
        if ($value === '') {
            return 0;
        }

        $last = strtolower(substr($value, -1));
        $number = (float)$value;
        return match ($last) {
            'g' => (int)round($number * 1024 * 1024 * 1024),
            'm' => (int)round($number * 1024 * 1024),
            'k' => (int)round($number * 1024),
            default => (int)round($number),
        };
    }

    private function applyJpegExifOrientation(\GdImage $image, string $path): \GdImage
    {
        if (!function_exists('exif_read_data')) {
            return $image;
        }

        $exif = @exif_read_data($path, 'IFD0', true);
        $orientation = (int)($exif['IFD0']['Orientation'] ?? 1);

        if ($orientation === 1) {
            return $image;
        }

        $transformed = match ($orientation) {
            2 => $this->flipGdImage($image, IMG_FLIP_HORIZONTAL),
            3 => imagerotate($image, 180, 0),
            4 => $this->flipGdImage($image, IMG_FLIP_VERTICAL),
            5 => $this->transposeGdImage($image),
            6 => imagerotate($image, -90, 0),
            7 => $this->transverseGdImage($image),
            8 => imagerotate($image, 90, 0),
            default => $image,
        };

        if ($transformed === false) {
            return $image;
        }

        if ($transformed !== $image) {
            imagedestroy($image);
        }

        return $transformed;
    }

    private function flipGdImage(\GdImage $image, int $mode): \GdImage
    {
        if (function_exists('imageflip')) {
            imageflip($image, $mode);
            return $image;
        }

        throw new RuntimeException('The server cannot apply JPEG orientation.');
    }

    private function transposeGdImage(\GdImage $image): \GdImage
    {
        $rotated = imagerotate($image, -90, 0);
        if ($rotated === false) {
            throw new RuntimeException('The server cannot apply JPEG orientation.');
        }
        imageflip($rotated, IMG_FLIP_HORIZONTAL);
        return $rotated;
    }

    private function transverseGdImage(\GdImage $image): \GdImage
    {
        $rotated = imagerotate($image, 90, 0);
        if ($rotated === false) {
            throw new RuntimeException('The server cannot apply JPEG orientation.');
        }
        imageflip($rotated, IMG_FLIP_HORIZONTAL);
        return $rotated;
    }

    private function encodeGdImage(\GdImage $image, ?int $width = null, ?int $height = null): string
    {
        try {
            $width ??= imagesx($image);
            $height ??= imagesy($image);
            if ($width < 1 || $height < 1) {
                throw new RuntimeException('Image dimensions are invalid.');
            }

            // Avoid allocating a second full-size true-colour canvas for the
            // normal transparency-preserving path. On large phone photos that
            // extra allocation can exceed a 128 MB PHP memory limit even
            // though GD already holds the decoded source image in memory.
            if ($this->preserveTransparency) {
                imagesavealpha($image, true);
                ob_start();
                $quality = $this->webpQuality === 100 && defined('IMG_WEBP_LOSSLESS')
                    ? IMG_WEBP_LOSSLESS
                    : $this->webpQuality;
                if (!imagewebp($image, null, $quality)) {
                    throw new RuntimeException('WebP conversion failed.');
                }
                $output = ob_get_clean();
                if (!is_string($output) || $output === '') {
                    throw new RuntimeException('WebP conversion produced no data.');
                }
                return $output;
            }

            $canvas = imagecreatetruecolor($width, $height);
            if ($canvas === false) {
                throw new RuntimeException('Unable to allocate image canvas.');
            }
            try {
                imagealphablending($canvas, false);
                imagesavealpha($canvas, false);
                $background = imagecolorallocate($canvas, 255, 255, 255);
                imagefilledrectangle($canvas, 0, 0, $width, $height, $background);
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
