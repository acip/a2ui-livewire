<?php

namespace A2uiLivewire\Transport;

use Closure;

/**
 * Invokes an in-process agent. The callback receives the {@see AgentRequest}
 * and may return an AgentResponse, a list of envelopes, a JSON/JSONL string,
 * or a generator yielding envelopes.
 */
final class CallbackTransport implements AgentTransport
{
    /**
     * @param  Closure(AgentRequest): mixed  $callback
     */
    public function __construct(private readonly Closure $callback) {}

    public function send(AgentRequest $request): AgentResponse
    {
        return AgentResponse::from(($this->callback)($request), $request->session);
    }
}
