<?php

declare(strict_types=1);

namespace SentryIQCloud\Gallery\Storage;

use RuntimeException;

final class DuplicateIndex
{
    public function __construct(private readonly string $indexFile)
    {
        $directory = dirname($this->indexFile);
        if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new RuntimeException('Unable to create duplicate index directory.');
        }
    }

    public function contains(string $hash): bool
    {
        return array_key_exists($hash, $this->read());
    }

    public function add(string $hash, string $photoId): void
    {
        if ($hash === '' || $photoId === '') {
            throw new RuntimeException('Duplicate index entries require a hash and photo ID.');
        }

        $entries = $this->read();
        $entries[$hash] = $photoId;
        $this->write($entries);
    }

    public function find(string $hash): ?string
    {
        $entries = $this->read();
        $photoId = $entries[$hash] ?? null;
        return is_string($photoId) ? $photoId : null;
    }

    private function read(): array
    {
        if (!file_exists($this->indexFile)) {
            return [];
        }

        $json = file_get_contents($this->indexFile);
        if ($json === false || $json === '') {
            return [];
        }

        $decoded = json_decode($json, true);
        if (!is_array($decoded)) {
            throw new RuntimeException('Duplicate index is invalid.');
        }

        $entries = [];
        foreach ($decoded as $hash => $photoId) {
            if (is_string($hash) && is_string($photoId)) {
                $entries[$hash] = $photoId;
            }
        }
        return $entries;
    }

    private function write(array $entries): void
    {
        $json = json_encode($entries, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            throw new RuntimeException('Unable to encode duplicate index.');
        }

        $temporary = $this->indexFile . '.tmp';
        if (file_put_contents($temporary, $json . PHP_EOL, LOCK_EX) === false) {
            throw new RuntimeException('Unable to write duplicate index.');
        }

        chmod($temporary, 0600);
        if (!rename($temporary, $this->indexFile)) {
            @unlink($temporary);
            throw new RuntimeException('Unable to commit duplicate index.');
        }
    }
}
