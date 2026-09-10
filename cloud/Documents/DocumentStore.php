<?php

declare(strict_types=1);

namespace SentryIQCloud\Documents;

final class DocumentStore
{
    public function __construct(private readonly string $path)
    {
        $directory = dirname($this->path);
        if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new \RuntimeException('Document storage directory could not be created.');
        }
    }

    public function all(): array
    {
        if (!is_file($this->path) || is_link($this->path)) return [];
        $raw = @file_get_contents($this->path);
        $data = is_string($raw) ? json_decode($raw, true) : null;
        return is_array($data) ? $data : [];
    }

    public function put(string $id, array $metadata): void
    {
        if (!preg_match('/^[a-f0-9]{32}$/', $id)) throw new \InvalidArgumentException('Invalid document identifier.');
        $data = $this->all();
        $data[$id] = $metadata;
        $this->write($data);
    }

    public function remove(string $id): void
    {
        $data = $this->all();
        unset($data[$id]);
        $this->write($data);
    }

    private function write(array $data): void
    {
        $temp = $this->path . '.tmp-' . bin2hex(random_bytes(8));
        $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        if (@file_put_contents($temp, $json, LOCK_EX) === false || !@rename($temp, $this->path)) {
            @unlink($temp);
            throw new \RuntimeException('Document metadata could not be saved.');
        }
        @chmod($this->path, 0600);
    }
}
