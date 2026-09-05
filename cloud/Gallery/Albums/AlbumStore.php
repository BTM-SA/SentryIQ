<?php

declare(strict_types=1);

namespace SentryIQCloud\Gallery\Albums;

use RuntimeException;

final class AlbumStore
{
    public function __construct(private readonly string $file)
    {
        $directory = dirname($this->file);
        if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new RuntimeException('Unable to create gallery album directory.');
        }
    }

    public function albums(): array
    {
        $albums = $this->read();
        if (!isset($albums['Unassigned']) || !is_array($albums['Unassigned'])) {
            $albums['Unassigned'] = [];
            $this->write($albums);
        }
        return $albums;
    }

    public function create(string $name): void
    {
        $name = trim($name);
        if ($name === '' || strlen($name) > 80 || preg_match('/[\x00-\x1F\x7F]/', $name)) {
            throw new RuntimeException('Album name is invalid.');
        }
        if ($name === 'Unassigned') {
            throw new RuntimeException('Unassigned is a reserved album.');
        }

        $albums = $this->albums();
        if (isset($albums[$name])) {
            throw new RuntimeException('An album with that name already exists.');
        }

        $albums[$name] = [];
        ksort($albums, SORT_NATURAL | SORT_FLAG_CASE);
        $this->write($albums);
    }

    public function move(string $photoId, string $album): void
    {
        if (!preg_match('/^[a-f0-9]{32}$/', $photoId)) {
            throw new RuntimeException('Invalid photo ID.');
        }

        $album = trim($album);
        $albums = $this->albums();
        if (!array_key_exists($album, $albums) || !is_array($albums[$album])) {
            throw new RuntimeException('Album does not exist.');
        }

        foreach ($albums as $name => &$photos) {
            $photos = array_values(array_filter($photos, static fn(mixed $id): bool => $id !== $photoId));
        }
        unset($photos);

        $albums[$album][] = $photoId;
        $this->write($albums);
    }

    public function albumFor(string $photoId): string
    {
        foreach ($this->albums() as $album => $photos) {
            if (in_array($photoId, $photos, true)) {
                return $album;
            }
        }
        return 'Unassigned';
    }

    private function read(): array
    {
        if (!is_file($this->file)) {
            return ['Unassigned' => []];
        }

        $json = file_get_contents($this->file);
        $decoded = is_string($json) ? json_decode($json, true) : null;
        if (!is_array($decoded)) {
            throw new RuntimeException('Gallery album index is invalid.');
        }

        $albums = [];
        foreach ($decoded as $name => $photos) {
            if (!is_string($name) || !is_array($photos)) {
                continue;
            }
            $albums[$name] = array_values(array_filter($photos, static fn(mixed $id): bool => is_string($id) && preg_match('/^[a-f0-9]{32}$/', $id) === 1));
        }
        return $albums ?: ['Unassigned' => []];
    }

    private function write(array $albums): void
    {
        $json = json_encode($albums, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($json === false) {
            throw new RuntimeException('Unable to encode gallery albums.');
        }

        $temporary = $this->file . '.tmp';
        if (file_put_contents($temporary, $json . PHP_EOL, LOCK_EX) === false) {
            throw new RuntimeException('Unable to write gallery albums.');
        }
        chmod($temporary, 0600);
        if (!rename($temporary, $this->file)) {
            @unlink($temporary);
            throw new RuntimeException('Unable to commit gallery albums.');
        }
        chmod($this->file, 0600);
    }
}
