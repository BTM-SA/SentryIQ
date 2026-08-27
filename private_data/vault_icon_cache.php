<?php

declare(strict_types=1);

/**
 * Fetch and persist a site's favicon, falling back to its Open Graph image.
 * Stored assets remain inside SENTRYIQ_DATA_DIR and are never served directly.
 */
function cache_vault_icon(string $url, string $entryId): array
{
    $empty = [
        'icon_type' => null,
        'icon_path' => null,
        'icon_source' => null,
        'icon_fetched_at' => null,
    ];

    if ($url === '' || !function_exists('curl_init')) {
        return $empty;
    }

    $parts = parse_url($url);
    if (!is_array($parts) || strtolower((string)($parts['scheme'] ?? '')) !== 'https' || empty($parts['host'])) {
        return $empty;
    }

    $host = strtolower((string)$parts['host']);
    $origin = 'https://' . $host;

    $page = curl_vault_asset($url, false);
    if ($page === null) {
        return $empty;
    }

    $pageUrl = $page['final_url'] !== '' ? $page['final_url'] : $url;
    $baseParts = parse_url($pageUrl);
    if (!is_array($baseParts) || strtolower((string)($baseParts['scheme'] ?? '')) !== 'https' || empty($baseParts['host'])) {
        return $empty;
    }
    $baseOrigin = 'https://' . strtolower((string)$baseParts['host']);

    $faviconUrl = null;
    $ogImageUrl = null;

    if (stripos($page['content_type'], 'text/html') !== false || stripos($page['body'], '<html') !== false) {
        if (class_exists('DOMDocument')) {
            libxml_use_internal_errors(true);
            $dom = new DOMDocument();

            if (@$dom->loadHTML($page['body'])) {
                foreach ($dom->getElementsByTagName('link') as $link) {
                    $rel = strtolower(trim((string)$link->getAttribute('rel')));
                    $href = trim((string)$link->getAttribute('href'));
                    if ($href !== '' && (str_contains($rel, 'icon') || str_contains($rel, 'shortcut'))) {
                        $candidate = resolve_vault_asset_url($href, $pageUrl, $baseOrigin);
                        if ($candidate !== null) {
                            $faviconUrl = $candidate;
                            break;
                        }
                    }
                }

                foreach ($dom->getElementsByTagName('meta') as $meta) {
                    $property = strtolower(trim((string)$meta->getAttribute('property')));
                    $name = strtolower(trim((string)$meta->getAttribute('name')));
                    $content = trim((string)$meta->getAttribute('content'));
                    if ($content !== '' && ($property === 'og:image' || $name === 'og:image')) {
                        $candidate = resolve_vault_asset_url($content, $pageUrl, $baseOrigin);
                        if ($candidate !== null) {
                            $ogImageUrl = $candidate;
                            break;
                        }
                    }
                }
            }

            libxml_clear_errors();
        }
    }

    $candidates = [];
    if ($faviconUrl !== null) $candidates[] = ['type' => 'favicon', 'url' => $faviconUrl];
    if ($ogImageUrl !== null) $candidates[] = ['type' => 'og_image', 'url' => $ogImageUrl];
    $candidates[] = ['type' => 'favicon', 'url' => $origin . '/favicon.ico'];

    foreach ($candidates as $candidate) {
        $image = curl_vault_asset($candidate['url'], true);
        if ($image === null) continue;

        $directory = rtrim(SENTRYIQ_DATA_DIR, '/') . '/vault_icons';
        if (!is_dir($directory) && !@mkdir($directory, 0700, true) && !is_dir($directory)) {
            return $empty;
        }
        @chmod($directory, 0700);

        $extension = detect_vault_image_extension($image['body'], $image['content_type']);
        if ($extension === null) continue;

        $filePath = $directory . '/' . hash('sha256', $entryId) . '.' . $extension;
        if (@file_put_contents($filePath, $image['body'], LOCK_EX) === false) continue;
        @chmod($filePath, 0600);

        return [
            'icon_type' => $candidate['type'],
            'icon_path' => $filePath,
            'icon_source' => $candidate['url'],
            'icon_fetched_at' => date('c'),
        ];
    }

    return $empty;
}

function curl_vault_asset(string $url, bool $imageOnly): ?array
{
    $parts = parse_url($url);
    if (!is_array($parts) || strtolower((string)($parts['scheme'] ?? '')) !== 'https' || empty($parts['host'])) {
        return null;
    }

    $ch = curl_init($url);
    if ($ch === false) return null;

    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 5,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_USERAGENT => 'SentryIQ/1.0',
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_HTTPHEADER => $imageOnly
            ? ['Accept: image/avif,image/webp,image/png,image/jpeg,image/gif,image/x-icon,*/*;q=0.8']
            : ['Accept: text/html,application/xhtml+xml;q=0.9,*/*;q=0.8'],
    ]);

    $body = curl_exec($ch);
    $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $contentType = strtolower((string)curl_getinfo($ch, CURLINFO_CONTENT_TYPE));
    $finalUrl = (string)curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
    curl_close($ch);

    $finalParts = parse_url($finalUrl !== '' ? $finalUrl : $url);
    if (!is_array($finalParts) || strtolower((string)($finalParts['scheme'] ?? '')) !== 'https') return null;

    if (!is_string($body) || $body === '' || $httpCode < 200 || $httpCode >= 400 || strlen($body) > 5 * 1024 * 1024) {
        return null;
    }

    return [
        'body' => $body,
        'content_type' => $contentType,
        'final_url' => $finalUrl,
    ];
}

function resolve_vault_asset_url(string $assetUrl, string $pageUrl, string $origin): ?string
{
    $assetUrl = trim(html_entity_decode($assetUrl, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    if ($assetUrl === '' || str_starts_with(strtolower($assetUrl), 'data:') || str_starts_with(strtolower($assetUrl), 'javascript:')) return null;

    if (str_starts_with($assetUrl, '//')) {
        return 'https:' . $assetUrl;
    }

    if (preg_match('#^https://#i', $assetUrl)) return $assetUrl;
    if (str_starts_with($assetUrl, 'http://')) return null;
    if (str_starts_with($assetUrl, '/')) return rtrim($origin, '/') . $assetUrl;

    $path = parse_url($pageUrl, PHP_URL_PATH) ?: '/';
    $directory = rtrim(str_replace('\\', '/', dirname($path)), '/');
    return rtrim($origin, '/') . ($directory !== '' ? $directory : '') . '/' . ltrim($assetUrl, '/');
}

function detect_vault_image_extension(string $body, string $contentType): ?string
{
    $mime = '';
    $imageInfo = @getimagesizefromstring($body);
    if ($imageInfo !== false && !empty($imageInfo['mime'])) {
        $mime = strtolower((string)$imageInfo['mime']);
    } elseif ($contentType !== '') {
        $mime = strtolower(trim(explode(';', $contentType)[0]));
    }

    return match ($mime) {
        'image/png' => 'png',
        'image/jpeg', 'image/jpg' => 'jpg',
        'image/gif' => 'gif',
        'image/webp' => 'webp',
        'image/avif' => 'avif',
        'image/x-icon', 'image/vnd.microsoft.icon' => 'ico',
        default => null,
    };
}
