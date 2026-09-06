<?php

declare(strict_types=1);

namespace SentryIQCloud\Gallery\Storage;

use RuntimeException;

final class PhotoNameAllocator
{
    public function __construct(
        private readonly string $galleryRoot,
        private readonly string $lockFile,
    ) {
        if ($this->galleryRoot === '' || !str_starts_with($this->galleryRoot, '/')) {
            throw new RuntimeException('Gallery storage root must be an absolute path.');
        }
        $directory = dirname($this->lockFile);
        if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new RuntimeException('Unable to create gallery filename lock directory.');
        }
    }

    public function next(): string
    {
        $handle = @fopen($this->lockFile, 'c+');
        if ($handle === false) throw new RuntimeException('Unable to open gallery filename lock.');

        try {
            if (!flock($handle, LOCK_EX)) throw new RuntimeException('Unable to lock gallery filename index.');

            $used = [];
            $photosRoot = rtrim($this->galleryRoot, '/') . '/photos';
            if (is_dir($photosRoot)) {
                foreach (scandir($photosRoot) ?: [] as $bucket) {
                    if ($bucket === '.' || $bucket === '..' || !preg_match('/^[a-f0-9]{2}$/', $bucket)) continue;
                    $directory = $photosRoot . '/' . $bucket;
                    foreach (scandir($directory) ?: [] as $file) {
                        if (preg_match('/^img(\d+)\.webp$/', $file, $match)) $used[(int)$match[1]] = true;
                    }
                }
            }

            $number = 1;
            while (isset($used[$number])) $number++;
            $filename = sprintf('img%04d.webp', $number);
            flock($handle, LOCK_UN);
            return $filename;
        } finally {
            fclose($handle);
        }
    }
}
