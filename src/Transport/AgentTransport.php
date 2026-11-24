<?php

namespace A2uiLivewire\Transport;

/**
 * Carries renderer-to-agent messages to an agent and returns its reply.
 *
 * Implementations must deliver messages in order and may stream the reply:
 * `AgentResponse::$messages` can be a generator that yields envelopes as
 * they arrive.
 */
interface AgentTransport
{
    public function send(AgentRequest $request): AgentResponse;
}
