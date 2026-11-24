<?php

namespace A2uiLivewire\Events;


/**
 * Fired when a user triggers an agent action (`action` message) on a surface.
 * `$dataModel` is the a2uiRendererDataModel metadata (or null).
 */
final class ActionDispatched
{
    public function __construct(
        public readonly array $message,
        public readonly string $rendererId,
        public readonly ?array $dataModel = null,
    ) {}

    public function action(): array
    {
        return $this->message['action'];
    }
}
