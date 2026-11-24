<?php

namespace A2uiLivewire\Transport;

use A2uiLivewire\Protocol\MessageParser;

/**
 * The agent's reply: agent-to-renderer A2UI messages, optional text, and the
 * updated transport session.
 */
final class AgentResponse
{
    /**
     * @param  iterable<array>  $messages
     * @param  array<string, mixed>  $session
     */
    public function __construct(
        public iterable $messages = [],
        public ?string $text = null,
        public array $session = [],
    ) {}

    /**
     * Normalises whatever a callback returned into a response.
     */
    public static function from(mixed $value, array $session = []): self
    {
        return match (true) {
            $value instanceof self => $value,
            $value === null => new self([], null, $session),
            $value instanceof \Generator => new self($value, null, $session),
            is_string($value), is_array($value) => new self(MessageParser::parse($value), null, $session),
            is_iterable($value) => new self($value, null, $session),
            default => new self([], null, $session),
        };
    }
}
