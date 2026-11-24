<?php

namespace A2uiLivewire\Transport;

/**
 * A transport with no agent: actions are only surfaced as Livewire/Laravel
 * events, which the host application can handle itself.
 */
final class NullTransport implements AgentTransport
{
    public function send(AgentRequest $request): AgentResponse
    {
        return new AgentResponse([], null, $request->session);
    }
}
