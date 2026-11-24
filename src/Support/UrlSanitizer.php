<?php

/*
 * Portions of this file are ported from the A2UI project
 * (https://github.com/a2ui-project/a2ui), typescript/core/web_core/src/common/sanitize_media_url.ts,
 * Copyright 2024 Google LLC, licensed under the Apache License, Version 2.0.
 * Modified: translated from TypeScript to PHP and adapted to this package.
 */

namespace A2uiLivewire\Support;

/**
 * URL validation required by the Basic Catalog implementation guide.
 */
final class UrlSanitizer
{
    /**
     * Validates an agent-supplied media URL (Image.url, Video.url,
     * Video.posterUrl, AudioPlayer.url). Returns the trimmed URL, or null when
     * it must not be loaded.
     *
     * Rules: trim whitespace/control characters; allow only http:, https:,
     * data: and relative paths; http(s) must be written as scheme://host;
     * protocol-relative (//host, \\host) paths are rejected; tab, CR and LF are
     * ignored when inspecting the scheme the way browser URL parsers do. Only a
     * short prefix is inspected so large data: URLs stay cheap.
     */
    public static function media(mixed $url): ?string
    {
        if (! is_string($url)) {
            return null;
        }

        $trimmed = preg_replace('/^[\x00-\x20\x7F]+|[\x00-\x20\x7F]+$/', '', $url) ?? '';

        if ($trimmed === '') {
            return null;
        }

        $probe = str_replace(["\t", "\n", "\r"], '', substr($trimmed, 0, 512));

        if (preg_match('/^([a-zA-Z][a-zA-Z0-9+.\-]*):/', $probe, $match)) {
            $scheme = strtolower($match[1]);

            if ($scheme === 'data') {
                return $trimmed;
            }

            if ($scheme === 'http' || $scheme === 'https') {
                return preg_match('#^https?:[/\\\\]{2}[^/\\\\?\#\s]+#i', $probe) ? $trimmed : null;
            }

            return null;
        }

        $normalized = str_replace('\\', '/', $probe);

        if (str_starts_with($normalized, '//')) {
            return null;
        }

        return $trimmed;
    }

    /**
     * Validates a URL for the `openUrl` function. Relative URLs are resolved
     * against `$base`. Returns the absolute URL or null when the scheme is not
     * allowed.
     *
     * @param  list<string>  $allowedSchemes
     */
    public static function navigation(mixed $url, ?string $base = null, array $allowedSchemes = ['http', 'https']): ?string
    {
        if (! is_string($url)) {
            return null;
        }

        $trimmed = preg_replace('/^[\x00-\x20\x7F]+|[\x00-\x20\x7F]+$/', '', $url) ?? '';
        $trimmed = str_replace(["\t", "\n", "\r"], '', $trimmed);

        if ($trimmed === '') {
            return null;
        }

        if (preg_match('/^([a-zA-Z][a-zA-Z0-9+.\-]*):/', $trimmed, $match)) {
            $scheme = strtolower($match[1]);

            if (! in_array($scheme, $allowedSchemes, true)) {
                return null;
            }

            if (in_array($scheme, ['http', 'https'], true) && ! preg_match('#^https?://[^/\\\\?\#\s]+#i', $trimmed)) {
                return null;
            }

            return $trimmed;
        }

        if ($base === null || ! in_array('https', $allowedSchemes, true) && ! in_array('http', $allowedSchemes, true)) {
            return null;
        }

        $normalized = str_replace('\\', '/', $trimmed);

        if (str_starts_with($normalized, '//')) {
            $scheme = parse_url($base, PHP_URL_SCHEME) ?: 'https';

            return in_array($scheme, $allowedSchemes, true) ? $scheme.':'.$normalized : null;
        }

        $parts = parse_url($base);

        if (! isset($parts['scheme'], $parts['host'])) {
            return null;
        }

        $origin = $parts['scheme'].'://'.$parts['host'].(isset($parts['port']) ? ':'.$parts['port'] : '');

        if (str_starts_with($normalized, '/')) {
            return $origin.$normalized;
        }

        $basePath = $parts['path'] ?? '/';
        $dir = str_ends_with($basePath, '/') ? $basePath : (dirname($basePath) === '\\' ? '/' : rtrim(dirname($basePath), '/').'/');

        return $origin.$dir.$normalized;
    }
}
