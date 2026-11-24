<?php

namespace A2uiLivewire\Transport;

/**
 * What the renderer sends to the agent in one turn: renderer-to-agent A2UI
 * messages (action, callAgentFunction, rendererFunctionResponse, error) and/or
 * a free-text user prompt, plus transport metadata
 * (`a2uiRendererCapabilities`, `a2uiRendererDataModel`).
 */
final class AgentRequest
{
    /**
     * @param  list<array>  $messages
     * @param  array<string, mixed>  $metadata
     * @param  array<string, mixed>  $session  transport state carried between turns
     */
    public function __construct(
        public readonly array $messages = [],
        public readonly ?string $text = null,
        public readonly array $metadata = [],
        public readonly array $session = [],
    ) {}

    public function isEmpty(): bool
    {
        return $this->messages === [] && ($this->text === null || $this->text === '');
    }

    /**
     * The first `action` message in the request, if any.
     */
    public function action(): ?array
    {
        foreach ($this->messages as $message) {
            if (isset($message['action'])) {
                return $message['action'];
            }
        }

        return null;
    }
}
