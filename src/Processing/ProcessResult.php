<?php

namespace A2uiLivewire\Processing;

use A2uiLivewire\Exceptions\A2uiException;

/**
 * The outcome of processing a batch of agent-to-renderer messages.
 */
final class ProcessResult
{
    /** @var list<array> renderer-to-agent replies (rendererFunctionResponse, error) */
    public array $outbound = [];

    /** @var list<A2uiException> */
    public array $errors = [];

    /** @var list<array> client-side effects */
    public array $effects = [];

    /** @var list<string> */
    public array $created = [];

    /** @var list<string> */
    public array $deleted = [];

    /** @var list<string> */
    public array $updated = [];

    public int $processed = 0;

    public function merge(self $other): self
    {
        array_push($this->outbound, ...$other->outbound);
        array_push($this->errors, ...$other->errors);
        array_push($this->effects, ...$other->effects);
        array_push($this->created, ...$other->created);
        array_push($this->deleted, ...$other->deleted);
        array_push($this->updated, ...$other->updated);
        $this->processed += $other->processed;

        return $this;
    }

    public function hasErrors(): bool
    {
        return $this->errors !== [];
    }
}
