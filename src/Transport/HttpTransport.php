<?php

namespace A2uiLivewire\Transport;

use A2uiLivewire\Exceptions\A2uiException;
use A2uiLivewire\Protocol\Envelope;
use A2uiLivewire\Protocol\MessageParser;
use A2uiLivewire\Support\Json;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\RequestException;
use Throwable;

/**
 * Plain HTTP binding: POSTs `{"messages": [...], "text": "...", "metadata": {...}}`
 * to an agent endpoint and accepts a JSON array, `{"messages": [...]}`,
 * JSONL or an SSE body in reply.
 *
 * With `stream: true` the response body is read incrementally and envelopes
 * are yielded as soon as each line/event is complete.
 */
final class HttpTransport implements AgentTransport
{
    /**
     * @param  array<string, string>  $headers
     */
    public function __construct(
        private readonly HttpFactory $http,
        private readonly string $url,
        private readonly array $headers = [],
        private readonly int $timeout = 60,
        private readonly bool $stream = false,
    ) {}

    public function send(AgentRequest $request): AgentResponse
    {
        $body = ['messages' => $request->messages];

        if ($request->text !== null) {
            $body['text'] = $request->text;
        }

        if ($request->metadata !== []) {
            $body['metadata'] = $request->metadata;
        }

        if ($request->session !== []) {
            $body['session'] = $request->session;
        }

        $pending = $this->http
            ->withHeaders(['Accept' => Envelope::MIME_TYPE.', application/x-ndjson, text/event-stream, application/json', ...$this->headers])
            ->timeout($this->timeout)
            ->withBody(Json::encode($body), 'application/json');

        if ($this->stream) {
            $pending = $pending->withOptions(['stream' => true]);
        }

        try {
            $response = $pending->post($this->url)->throw();
        } catch (RequestException $e) {
            throw A2uiException::execution('Agent request failed with HTTP '.$e->response->status().'.', null, $e);
        } catch (Throwable $e) {
            throw A2uiException::execution('Agent request failed: '.$e->getMessage(), null, $e);
        }

        $session = $request->session;
        $sessionHeader = $response->header('X-A2UI-Session');
        if ($sessionHeader !== '') {
            $decoded = json_decode($sessionHeader, true);
            if (is_array($decoded)) {
                $session = $decoded;
            }
        }

        if ($this->stream) {
            return new AgentResponse(StreamReader::envelopes($response->toPsrResponse()->getBody(), (string) $response->header('Content-Type')), null, $session);
        }

        $payload = $response->body();
        $decoded = json_decode($payload, true);

        if (is_array($decoded) && ! array_is_list($decoded) && ! Envelope::isEnvelope($decoded)) {
            if (is_array($decoded['session'] ?? null)) {
                $session = $decoded['session'];
            }

            return new AgentResponse(MessageParser::parse($decoded['messages'] ?? []), is_string($decoded['text'] ?? null) ? $decoded['text'] : null, $session);
        }

        return new AgentResponse(MessageParser::parse($payload), null, $session);
    }
}
