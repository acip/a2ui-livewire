<?php

namespace A2uiLivewire\Support;

use A2uiLivewire\Exceptions\A2uiException;

/**
 * RFC 6901 JSON Pointer helpers with A2UI semantics.
 *
 * - `""` and `"/"` address the whole document.
 * - Paths that do not start with `/` are relative and resolve against a base
 *   path (the collection scope of a list template).
 * - Writes auto-vivify missing containers: a numeric next segment creates a
 *   list, anything else creates an object.
 */
final class JsonPointer
{
    public const MAX_ARRAY_INDEX = 10_000;

    /** Sentinel returned for absent values. */
    public const MISSING = "\0__a2ui_missing__\0";

    /**
     * Splits a pointer into unescaped segments.
     *
     * @return list<string>
     */
    public static function segments(string $path): array
    {
        if ($path === '' || $path === '/') {
            return [];
        }

        // Repeated and trailing slashes collapse into canonical segments.
        $segments = [];
        foreach (explode('/', $path) as $raw) {
            if ($raw === '') {
                continue;
            }

            $segment = str_replace(['~1', '~0'], ['/', '~'], $raw);

            if (in_array($segment, self::FORBIDDEN, true)) {
                throw new A2uiException(A2uiException::DATA_ERROR, "Forbidden path segment '{$segment}' in '{$path}'.", null, $path);
            }

            $segments[] = $segment;
        }

        return $segments;
    }

    /** Segments rejected everywhere so payloads behave the same in every renderer (prototype pollution in JS). */
    public const FORBIDDEN = ['__proto__', 'constructor', 'prototype'];

    /**
     * Builds a pointer from raw segments.
     *
     * @param  list<string|int>  $segments
     */
    public static function build(array $segments): string
    {
        if ($segments === []) {
            return '/';
        }

        return '/'.implode('/', array_map(
            static fn ($segment) => str_replace(['~', '/'], ['~0', '~1'], (string) $segment),
            $segments,
        ));
    }

    public static function isAbsolute(string $path): bool
    {
        return str_starts_with($path, '/');
    }

    /**
     * Resolves a possibly-relative path against a base pointer.
     */
    public static function resolve(string $path, string $base = '/'): string
    {
        if (self::isAbsolute($path)) {
            return self::build(self::segments($path));
        }

        $relative = $path === '.' || $path === '' ? [] : self::segments($path);

        return self::build([...self::segments($base), ...$relative]);
    }

    public static function append(string $base, string|int $segment): string
    {
        return self::build([...self::segments($base), (string) $segment]);
    }

    public static function isIndex(string $segment): bool
    {
        return (bool) preg_match('/^(0|[1-9][0-9]*)$/', $segment);
    }

    /**
     * Reads the value at `$path`, or `$default` when it is absent.
     */
    public static function get(mixed $data, string $path, mixed $default = null): mixed
    {
        $value = self::find($data, $path);

        return $value === self::MISSING ? $default : $value;
    }

    public static function has(mixed $data, string $path): bool
    {
        return self::find($data, $path) !== self::MISSING;
    }

    /**
     * Returns the value at `$path` or the {@see self::MISSING} sentinel.
     */
    public static function find(mixed $data, string $path): mixed
    {
        $current = $data;

        foreach (self::segments($path) as $segment) {
            if (! is_array($current)) {
                return self::MISSING;
            }

            if (array_is_list($current) && $current !== []) {
                if (! self::isIndex($segment)) {
                    return self::MISSING;
                }
                $segment = (int) $segment;
            }

            if (! array_key_exists($segment, $current)) {
                return self::MISSING;
            }

            $current = $current[$segment];
        }

        return $current;
    }

    /**
     * Writes `$value` at `$path`, creating intermediate containers as needed.
     * Writing the root replaces the whole document.
     *
     * @throws A2uiException when the path crosses a primitive value.
     */
    public static function set(mixed $data, string $path, mixed $value): mixed
    {
        $segments = self::segments($path);

        if ($segments === []) {
            return $value;
        }

        if ($data === null) {
            $data = [];
        }

        if (! is_array($data)) {
            throw new A2uiException(A2uiException::DATA_ERROR, "Cannot set path '{$path}': the data model root is a primitive value.", null, $path);
        }

        $current = &$data;
        $last = count($segments) - 1;

        foreach ($segments as $i => $segment) {
            $key = self::keyFor($current, $segment, $path);

            if ($i === $last) {
                self::assign($current, $key, $value);
                break;
            }

            $existing = array_key_exists($key, $current) ? $current[$key] : null;

            if ($existing !== null && ! is_array($existing)) {
                throw new A2uiException(A2uiException::DATA_ERROR, "Cannot set path '{$path}': segment '{$segment}' is a primitive value.", null, $path);
            }

            if ($existing === null) {
                self::assign($current, $key, []);
            }

            $current = &$current[$key];
        }

        unset($current);

        return $data;
    }

    /**
     * Removes the value at `$path`. Removing the root resets it to an empty object.
     */
    public static function remove(mixed $data, string $path): mixed
    {
        $segments = self::segments($path);

        if ($segments === []) {
            return [];
        }

        if (! is_array($data)) {
            return $data;
        }

        $current = &$data;
        $last = count($segments) - 1;

        foreach ($segments as $i => $segment) {
            if (! is_array($current)) {
                return $data;
            }

            $isList = array_is_list($current) && $current !== [];

            if ($isList && ! self::isIndex($segment)) {
                return $data;
            }

            $key = $isList ? (int) $segment : $segment;

            if (! array_key_exists($key, $current)) {
                return $data;
            }

            if ($i === $last) {
                if ($isList) {
                    array_splice($current, $key, 1);
                } else {
                    unset($current[$key]);
                }
                break;
            }

            $current = &$current[$key];
        }

        unset($current);

        return $data;
    }

    private static function keyFor(array $container, string $segment, string $path): string|int
    {
        $isList = array_is_list($container) && $container !== [];

        if ($segment === '-' && ($isList || $container === [])) {
            return count($container);
        }

        if ($isList || ($container === [] && self::isIndex($segment))) {
            if (! self::isIndex($segment)) {
                if ($isList) {
                    throw new A2uiException(A2uiException::DATA_ERROR, "Cannot use non-numeric segment '{$segment}' on an array in path '{$path}'.", null, $path);
                }

                return $segment;
            }

            $index = (int) $segment;

            if ($index > self::MAX_ARRAY_INDEX) {
                throw new A2uiException(A2uiException::DATA_ERROR, "Cannot set path '{$path}': array index '{$segment}' exceeds maximum supported index (".self::MAX_ARRAY_INDEX.').', null, $path);
            }

            return $index;
        }

        return $segment;
    }

    /**
     * Assigns into a container, padding lists with nulls so they stay dense.
     */
    private static function assign(array &$container, string|int $key, mixed $value): void
    {
        if (is_int($key) && array_is_list($container)) {
            for ($i = count($container); $i < $key; $i++) {
                $container[$i] = null;
            }
        }

        $container[$key] = $value;
    }
}
