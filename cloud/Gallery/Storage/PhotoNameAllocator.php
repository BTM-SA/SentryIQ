<?php

declare(strict_types=1);

namespace SentryIQCloud\Gallery\Storage;

use RuntimeException;

final class PhotoNameAllocator
{
    public function __construct(private readonly string $file)
    {
        $directory = dirname($this->file);
        if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new RuntimeException('Unable to create gallery filename directory.');
        }
    }

    public function next(): string
    {
        $handle = @fopen($this->file, 'c+');
        if ($handle === false) {
            throw new RuntimeException('Unable to open gallery filename index.');
        }

        try {
            if (!flock($handle, LOCK_EX)) {
                throw new RuntimeException('Unable to lock gallery filename index.');
            }

            rewind($handle);
            $json = stream_get_contents($handle);
            $data = $json === false || trim($json) === '' ? ['next' => 1] : json_decode($json, true);
            if (!is_array($data)) {
                throw new RuntimeException('Gallery filename index is invalid.');
            }

            $next = $data['next'] ?? 1;
            if (!is_int($next) && !ctype_digit((string)$next)) {
                throw new RuntimeException('Gallery filename index is invalid.');
            }
            $next = (int)$next;
            if ($next < 1) {
                throw new RuntimeException('Gallery filename index is invalid.');
            }

            $filename = sprintf('img%04d.webp', $next);
            $data = ['next' => $next + 1];
            $encoded = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
            if ($encoded === false) {
                throw new RuntimeException('Unable to encode gallery filename index.');
            }

            rewind($handle);
            if (!ftruncate($handle, 0) || fwrite($handle, $encoded . PHP_EOL) === false || !fflush($handle)) {
                throw new RuntimeException('Unable to update gallery filename index.');
            }
            @chmod($this->file, 0600);
            flock($handle, LOCK_UN);
            return $filename;
        } finally {
            fclose($handle);
        }
    }
}
