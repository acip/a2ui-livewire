<?php

namespace A2uiLivewire\Events;

use A2uiLivewire\Processing\ProcessResult;

/**
 * Fired after a batch of agent-to-renderer messages has been applied.
 */
final class MessagesProcessed
{
    /**
     * @param  list<array>  $messages
     */
    public function __construct(
        public readonly array $messages,
        public readonly ProcessResult $result,
        public readonly string $rendererId,
    ) {}
}
