<?php

namespace A2uiLivewire\Transport;

use A2uiLivewire\Protocol\MessageParser;
use Generator;
use Psr\Http\Message\StreamInterface;

/**
 * Incrementally parses JSONL and SSE response bodies into envelopes.
 */
final class StreamReader
{
    /**
     * @return Generator<int, array>
     */
    public static function envelopes(StreamInterface $body, string $contentType = ''): Generator
    {
        $sse = str_contains($contentType, 'text/event-stream');
        $buffer = '';
        $event = [];

        while (! $body->eof()) {
            $chunk = $body->read(8192);

            if ($chunk === '') {
                continue;
            }

            $buffer .= $chunk;

            while (($pos = strpos($buffer, "\n")) !== false) {
                $line = rtrim(substr($buffer, 0, $pos), "\r");
                $buffer = substr($buffer, $pos + 1);

                if ($sse) {
                    if ($line === '') {
                        yield from self::flushEvent($event);
                    } elseif (str_starts_with($line, 'data:')) {
                        $event[] = ltrim(substr($line, 5), ' ');
                    }
                } elseif (trim($line) !== '') {
                    yield from MessageParser::parse($line);
                }
            }
        }

        if ($sse) {
            if (trim($buffer) !== '' && str_starts_with($buffer, 'data:')) {
                $event[] = ltrim(substr($buffer, 5), ' ');
            }
            yield from self::flushEvent($event);
        } elseif (trim($buffer) !== '') {
            yield from MessageParser::parse($buffer);
        }
    }

    /**
     * @param  list<string>  $event
     * @return Generator<int, array>
     */
    private static function flushEvent(array &$event): Generator
    {
        $data = trim(implode("\n", $event));
        $event = [];

        if ($data !== '' && $data !== '[DONE]') {
            yield from MessageParser::parse($data);
        }
    }
}
