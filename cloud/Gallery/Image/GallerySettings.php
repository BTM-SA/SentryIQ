<?php

declare(strict_types=1);

namespace SentryIQCloud\Gallery\Image;

use RuntimeException;

final class GallerySettings
{
    private const DEFAULTS = [
        'saved_quality' => 85,
        'saved_max_dimension' => 0,
        'thumbnail_quality' => 80,
        'thumbnail_max_dimension' => 600,
        'preview_quality' => 85,
        'preview_max_dimension' => 1600,
        'preserve_transparency' => true,
    ];

    public static function load(string $dataDir): array
    {
        $defaults = self::DEFAULTS;
        $path = rtrim($dataDir, '/') . '/gallery_settings.json';
        if (!is_file($path) || is_link($path)) return $defaults;

        $raw = @file_get_contents($path);
        $decoded = is_string($raw) ? json_decode($raw, true) : null;
        if (!is_array($decoded)) return $defaults;

        return [
            'saved_quality' => self::clampInt($decoded['saved_quality'] ?? $decoded['webp_quality'] ?? $defaults['saved_quality'], 1, 100, $defaults['saved_quality']),
            'saved_max_dimension' => self::clampInt($decoded['saved_max_dimension'] ?? $defaults['saved_max_dimension'], 0, 8000, $defaults['saved_max_dimension']),
            'thumbnail_quality' => self::clampInt($decoded['thumbnail_quality'] ?? $defaults['thumbnail_quality'], 1, 100, $defaults['thumbnail_quality']),
            'thumbnail_max_dimension' => self::clampInt($decoded['thumbnail_max_dimension'] ?? $defaults['thumbnail_max_dimension'], 100, 2000, $defaults['thumbnail_max_dimension']),
            'preview_quality' => self::clampInt($decoded['preview_quality'] ?? $defaults['preview_quality'], 1, 100, $defaults['preview_quality']),
            'preview_max_dimension' => self::clampInt($decoded['preview_max_dimension'] ?? $defaults['preview_max_dimension'], 200, 4000, $defaults['preview_max_dimension']),
            'preserve_transparency' => (bool)($decoded['preserve_transparency'] ?? $defaults['preserve_transparency']),
        ];
    }

    public static function save(string $dataDir, array $settings): void
    {
        $dataDir = rtrim($dataDir, '/');
        if ($dataDir === '' || !is_dir($dataDir) || is_link($dataDir)) {
            throw new RuntimeException('Gallery settings directory is unavailable.');
        }

        $normalized = [
            'saved_quality' => self::clampInt($settings['saved_quality'] ?? 85, 1, 100, 85),
            'saved_max_dimension' => self::clampInt($settings['saved_max_dimension'] ?? 0, 0, 8000, 0),
            'thumbnail_quality' => self::clampInt($settings['thumbnail_quality'] ?? 80, 1, 100, 80),
            'thumbnail_max_dimension' => self::clampInt($settings['thumbnail_max_dimension'] ?? 600, 100, 2000, 600),
            'preview_quality' => self::clampInt($settings['preview_quality'] ?? 85, 1, 100, 85),
            'preview_max_dimension' => self::clampInt($settings['preview_max_dimension'] ?? 1600, 200, 4000, 1600),
            'preserve_transparency' => (bool)($settings['preserve_transparency'] ?? true),
        ];

        $path = $dataDir . '/gallery_settings.json';
        $tmp = $path . '.tmp-' . bin2hex(random_bytes(8));
        $json = json_encode($normalized, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (!is_string($json) || @file_put_contents($tmp, $json . PHP_EOL, LOCK_EX) === false) {
            @unlink($tmp);
            throw new RuntimeException('Unable to save gallery settings.');
        }
        @chmod($tmp, 0600);
        if (!@rename($tmp, $path)) {
            @unlink($tmp);
            throw new RuntimeException('Unable to activate gallery settings.');
        }
        @chmod($path, 0600);
    }

    private static function clampInt(mixed $value, int $min, int $max, int $fallback): int
    {
        if (!is_int($value) && !is_string($value) && !is_float($value)) return $fallback;
        $value = filter_var($value, FILTER_VALIDATE_INT);
        if ($value === false || $value < $min || $value > $max) return $fallback;
        return (int)$value;
    }
}
