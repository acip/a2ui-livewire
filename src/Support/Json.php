<?php

namespace A2uiLivewire\Support;

use A2uiLivewire\Exceptions\A2uiException;
use JsonException;

final class Json
{
    public const FLAGS = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR;

    public static function encode(mixed $value, int $extraFlags = 0): string
    {
        try {
            return json_encode($value, self::FLAGS | $extraFlags);
        } catch (JsonException) {
            return json_encode($value, (self::FLAGS & ~JSON_THROW_ON_ERROR) | JSON_INVALID_UTF8_SUBSTITUTE | $extraFlags) ?: 'null';
        }
    }

    public static function pretty(mixed $value): string
    {
        return self::encode($value, JSON_PRETTY_PRINT);
    }

    /**
     * Encodes a value that must be a JSON object on the wire (an empty PHP
     * array would otherwise serialise as `[]`).
     */
    public static function object(array $value): array|object
    {
        return $value === [] ? new \stdClass : $value;
    }

    /**
     * @throws A2uiException
     */
    public static function decode(string $json): mixed
    {
        try {
            return json_decode($json, true, 512, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING);
        } catch (JsonException $e) {
            throw A2uiException::validation('Malformed JSON: '.$e->getMessage());
        }
    }
}
