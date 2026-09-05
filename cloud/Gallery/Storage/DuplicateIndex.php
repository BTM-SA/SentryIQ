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

    public function find(string $hash): ?string
    {
        $entries = $this->read();
        $photoId = $entries[$hash] ?? null;
        return is_string($photoId) ? $photoId : null;
    }

    public function contains(string $hash): bool
    {
        return $this->find($hash) !== null;
    }

    public function add(string $hash, string $photoId): void
    {
        if (!preg_match('/^[a-f0-9]{64}$/', $hash) || !preg_match('/^[a-f0-9]{32}$/', $photoId)) {
            throw new RuntimeException('Invalid duplicate index entry.');
        }
        $entries = $this->read();
        $entries[$hash] = $photoId;
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
        @chmod($this->indexFile, 0600);
    }

    private function read(): array
    {
        if (!is_file($this->indexFile)) {
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
            if (is_string($hash) && preg_match('/^[a-f0-9]{64}$/', $hash) && is_string($photoId) && preg_match('/^[a-f0-9]{32}$/', $photoId)) {
                $entries[$hash] = $photoId;
            }
        }
        return $entries;
    }
}
