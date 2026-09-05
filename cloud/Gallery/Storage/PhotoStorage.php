<?php

declare(strict_types=1);

namespace SentryIQCloud\Gallery\Storage;

use RuntimeException;

final class PhotoStorage
{
    public function __construct(private readonly string $root)
    {
        if ($this->root === '' || !str_starts_with($this->root, '/')) {
            throw new RuntimeException('Gallery storage root must be an absolute path.');
        }
    }

    public function store(string $webp, string $thumbnail, string $contentHash): array
    {
        if ($webp === '' || $thumbnail === '' || !preg_match('/^[a-f0-9]{64}$/', $contentHash)) {
            throw new RuntimeException('Invalid image data or content hash.');
        }
        $photoId = bin2hex(random_bytes(16));
        $bucket = substr($contentHash, 0, 2);
        $directory = rtrim($this->root, '/') . '/photos/' . $bucket;
        $thumbDirectory = rtrim($this->root, '/') . '/thumbnails/' . $bucket;
        $this->ensureDirectory($directory);
        $this->ensureDirectory($thumbDirectory);
        $path = $directory . '/' . $photoId . '.webp';
        $thumbnailPath = $thumbDirectory . '/' . $photoId . '.webp';
        if (file_put_contents($path, $webp, LOCK_EX) === false) {
            throw new RuntimeException('Unable to store gallery image.');
        }
        if (file_put_contents($thumbnailPath, $thumbnail, LOCK_EX) === false) {
            @unlink($path);
            throw new RuntimeException('Unable to store gallery thumbnail.');
        }
        @chmod($path, 0640);
        @chmod($thumbnailPath, 0640);
        return ['photo_id' => $photoId, 'path' => $path, 'thumbnail_path' => $thumbnailPath, 'created_at' => time()];
    }

    private function ensureDirectory(string $directory): void
    {
        if (is_dir($directory)) return;
        if (!mkdir($directory, 0750, true) && !is_dir($directory)) {
            throw new RuntimeException('Unable to create gallery storage directory.');
        }
        @chmod($directory, 0750);
    }
}
