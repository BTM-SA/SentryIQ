<?php

declare(strict_types=1);

namespace SentryIQCloud\Gallery\Storage;

use RuntimeException;

final class PhotoMetadataStore
{
    public function __construct(private readonly string $file)
    {
        $directory = dirname($this->file);
        if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new RuntimeException('Unable to create gallery metadata directory.');
        }
    }

    public function add(string $photoId, string $originalName, string $contentHash, int $createdAt): void
    {
        if (!preg_match('/^[a-f0-9]{32}$/', $photoId) || !preg_match('/^[a-f0-9]{64}$/', $contentHash)) {
            throw new RuntimeException('Invalid gallery metadata.');
        }

        $metadata = $this->read();
        $metadata[$photoId] = [
            'original_name' => $this->sanitizeName($originalName),
            'content_hash' => $contentHash,
            'created_at' => $createdAt,
        ];
        $this->write($metadata);
    }

    public function find(string $photoId): ?array
    {
        if (!preg_match('/^[a-f0-9]{32}$/', $photoId)) {
            return null;
        }
        $metadata = $this->read();
        return isset($metadata[$photoId]) && is_array($metadata[$photoId]) ? $metadata[$photoId] : null;
    }

    public function remove(string $photoId): void
    {
        if (!preg_match('/^[a-f0-9]{32}$/', $photoId)) {
            throw new RuntimeException('Invalid gallery photo ID.');
        }
        $metadata = $this->read();
        if (!array_key_exists($photoId, $metadata)) return;
        unset($metadata[$photoId]);
        $this->write($metadata);
    }

    private function read(): array
    {
        if (!is_file($this->file)) return [];
        $json = file_get_contents($this->file);
        $decoded = is_string($json) ? json_decode($json, true) : null;
        if (!is_array($decoded)) throw new RuntimeException('Gallery metadata index is invalid.');
        return $decoded;
    }

    private function write(array $metadata): void
    {
        $json = json_encode($metadata, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($json === false) throw new RuntimeException('Unable to encode gallery metadata.');
        $temporary = $this->file . '.tmp-' . bin2hex(random_bytes(8));
        if (file_put_contents($temporary, $json . PHP_EOL, LOCK_EX) === false) {
            throw new RuntimeException('Unable to write gallery metadata.');
        }
        chmod($temporary, 0600);
        if (!rename($temporary, $this->file)) {
            @unlink($temporary);
            throw new RuntimeException('Unable to commit gallery metadata.');
        }
        chmod($this->file, 0600);
    }

    private function sanitizeName(string $name): string
    {
        $name = basename($name);
        $name = preg_replace('/[\x00-\x1F\x7F]/', '', $name) ?? '';
        return substr($name, 0, 255);
    }
}
