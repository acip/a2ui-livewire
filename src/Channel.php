<?php

namespace A2uiLivewire;

use A2uiLivewire\Protocol\MessageParser;
use Illuminate\Contracts\Cache\Repository;

/**
 * A trusted, server-side mailbox of A2UI messages.
 *
 * Producers push message batches; each renderer remembers the last sequence
 * number it consumed and pulls newer batches. Batches are immutable cache
 * entries keyed by a monotonically increasing sequence, so no locking is needed.
 */
final class Channel
{
    public function __construct(
        private readonly Repository $cache,
        public readonly string $name,
        private readonly int $ttl = 3600,
    ) {}

    /**
     * Appends messages (array, JSON, JSONL or SSE text). Returns the batch sequence.
     */
    public function push(mixed $messages): int
    {
        $parsed = MessageParser::parse($messages);

        if ($parsed === []) {
            return $this->sequence();
        }

        $key = $this->key('seq');
        if (! $this->cache->has($key)) {
            $this->cache->add($key, 0, $this->ttl);
        }

        $sequence = (int) $this->cache->increment($key);
        $this->cache->put($this->key((string) $sequence), $parsed, $this->ttl);

        return $sequence;
    }

    public function sequence(): int
    {
        return (int) $this->cache->get($this->key('seq'), 0);
    }

    /**
     * Messages pushed after `$after`.
     *
     * @return array{0: list<array>, 1: int} [messages, new cursor]
     */
    public function since(int $after): array
    {
        $current = $this->sequence();
        $messages = [];

        // A fresh renderer (cursor 0) replays what is still cached.
        for ($i = $after + 1; $i <= $current; $i++) {
            $batch = $this->cache->get($this->key((string) $i));

            if (is_array($batch)) {
                array_push($messages, ...$batch);
            }
        }

        return [$messages, max($after, $current)];
    }

    public function clear(): void
    {
        $current = $this->sequence();

        for ($i = 1; $i <= $current; $i++) {
            $this->cache->forget($this->key((string) $i));
        }

        $this->cache->forget($this->key('seq'));
    }

    private function key(string $suffix): string
    {
        return 'a2ui:channel:'.hash('sha256', $this->name).':'.$suffix;
    }
}
