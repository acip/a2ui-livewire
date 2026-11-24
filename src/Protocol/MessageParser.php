<?php

namespace A2uiLivewire\Protocol;

use A2uiLivewire\Exceptions\A2uiException;
use A2uiLivewire\Support\Json;

/**
 * Turns transport payloads into a list of A2UI message envelopes.
 *
 * Accepts: a PHP list of envelopes, a single envelope, `{"messages": [...]}`,
 * a JSON array, JSONL (one envelope per line), Server-Sent Events
 * (`data:` lines) and LLM output wrapped in Markdown code fences.
 */
final class MessageParser
{
    /**
     * @return list<array>
     *
     * @throws A2uiException on malformed JSON
     */
    public static function parse(mixed $payload): array
    {
        if ($payload === null || $payload === '' || $payload === []) {
            return [];
        }

        if (is_array($payload)) {
            return self::fromArray($payload);
        }

        if (! is_string($payload)) {
            throw A2uiException::validation('A2UI payload must be a JSON string or an array.');
        }

        $text = self::stripCodeFences(trim($payload));

        if ($text === '') {
            return [];
        }

        if (self::looksLikeSse($text)) {
            return self::parseSse($text);
        }

        if ($text[0] === '[' || $text[0] === '{') {
            $decoded = json_decode($text, true, 512, JSON_BIGINT_AS_STRING);

            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                return self::fromArray($decoded);
            }
        }

        $messages = [];
        foreach (preg_split('/\r\n|\r|\n/', $text) ?: [] as $line) {
            $line = trim($line);

            if ($line === '' || $line === '[' || $line === ']') {
                continue;
            }

            $line = rtrim($line, ',');
            array_push($messages, ...self::fromArray(Json::decode($line)));
        }

        return $messages;
    }

    /**
     * Parses a Server-Sent Events body. Each event's `data:` lines are joined
     * and parsed as JSON (an envelope, a list, or JSONL).
     *
     * @return list<array>
     */
    public static function parseSse(string $body): array
    {
        $messages = [];
        $data = [];

        $flush = static function () use (&$data, &$messages): void {
            if ($data === []) {
                return;
            }

            $chunk = trim(implode("\n", $data));
            $data = [];

            if ($chunk === '' || $chunk === '[DONE]') {
                return;
            }

            array_push($messages, ...self::parse($chunk));
        };

        foreach (preg_split('/\r\n|\r|\n/', $body) ?: [] as $line) {
            if ($line === '') {
                $flush();

                continue;
            }

            if (str_starts_with($line, ':')) {
                continue;
            }

            if (str_starts_with($line, 'data:')) {
                $data[] = ltrim(substr($line, 5), ' ');
            }
        }

        $flush();

        return $messages;
    }

    /**
     * @return list<array>
     */
    private static function fromArray(array $payload): array
    {
        if (array_is_list($payload)) {
            $messages = [];
            foreach ($payload as $item) {
                if (is_array($item)) {
                    array_push($messages, ...(array_is_list($item) ? self::fromArray($item) : self::fromObject($item)));
                }
            }

            return $messages;
        }

        return self::fromObject($payload);
    }

    /**
     * @return list<array>
     */
    private static function fromObject(array $object): array
    {
        if (isset($object['messages']) && is_array($object['messages']) && ! Envelope::isEnvelope($object)) {
            return self::fromArray($object['messages']);
        }

        return [$object];
    }

    private static function looksLikeSse(string $text): bool
    {
        return (bool) preg_match('/^(data|event|id|retry):/m', $text) && ! in_array($text[0], ['{', '['], true);
    }

    private static function stripCodeFences(string $text): string
    {
        if (preg_match('/^```[a-zA-Z0-9_+-]*\s*\n(.*?)\n?```\s*$/s', $text, $match)) {
            return trim($match[1]);
        }

        return $text;
    }
}
