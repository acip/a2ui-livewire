<?php

namespace A2uiLivewire\Transport;

use A2uiLivewire\Exceptions\A2uiException;
use A2uiLivewire\Protocol\Envelope;
use A2uiLivewire\Protocol\MessageParser;
use A2uiLivewire\Support\Json;
use Generator;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Str;
use Throwable;

/**
 * A2A (Agent-to-Agent) JSON-RPC binding with the A2UI v1.0 extension.
 *
 * - Activates the extension with the `X-A2A-Extensions` header.
 * - Sends A2UI messages as a `DataPart` whose metadata.mimeType is
 *   `application/a2ui+json`, and the prompt as a text part.
 * - Puts `a2uiRendererCapabilities` / `a2uiRendererDataModel` in
 *   `message.metadata`.
 * - Extracts A2UI DataParts from the returned Message or Task (status message
 *   and artifacts), and keeps `contextId` / `taskId` in the session.
 *
 * With `stream: true` it calls `message/stream` and yields envelopes from
 * each SSE event as it arrives.
 */
final class A2aTransport implements AgentTransport
{
    /**
     * @param  array<string, string>  $headers
     */
    public function __construct(
        private readonly HttpFactory $http,
        private readonly string $url,
        private readonly array $headers = [],
        private readonly int $timeout = 120,
        private readonly bool $stream = false,
        /** "per_message" (one DataPart per message) or "list" (one DataPart with an array). */
        private readonly string $dataParts = 'per_message',
    ) {}

    public function send(AgentRequest $request): AgentResponse
    {
        $parts = [];

        if ($request->text !== null && $request->text !== '') {
            $parts[] = ['kind' => 'text', 'text' => $request->text];
        }

        if ($request->messages !== []) {
            // The official SDKs (a2ui-a2a for Python, @a2ui/agent for Node)
            // send and read one A2UI message per DataPart; "list" packs every
            // message into one DataPart whose data is an array.
            $chunks = $this->dataParts === 'list' ? [$request->messages] : $request->messages;

            foreach ($chunks as $data) {
                $parts[] = [
                    'kind' => 'data',
                    'data' => $data,
                    'metadata' => ['mimeType' => Envelope::MIME_TYPE],
                ];
            }
        }

        $message = [
            'kind' => 'message',
            'role' => 'user',
            'messageId' => (string) Str::uuid(),
            'parts' => $parts,
        ];

        if (isset($request->session['contextId'])) {
            $message['contextId'] = $request->session['contextId'];
        }

        if (isset($request->session['taskId'])) {
            $message['taskId'] = $request->session['taskId'];
        }

        if ($request->metadata !== []) {
            $message['metadata'] = $request->metadata;
        }

        $payload = [
            'jsonrpc' => '2.0',
            'id' => (string) Str::uuid(),
            'method' => $this->stream ? 'message/stream' : 'message/send',
            'params' => ['message' => $message],
        ];

        $pending = $this->http
            ->withHeaders([
                'X-A2A-Extensions' => Envelope::A2A_EXTENSION_URI,
                'Accept' => $this->stream ? 'text/event-stream' : 'application/json',
                ...$this->headers,
            ])
            ->timeout($this->timeout)
            ->withBody(Json::encode($payload), 'application/json');

        if ($this->stream) {
            $pending = $pending->withOptions(['stream' => true]);
        }

        try {
            $response = $pending->post($this->url)->throw();
        } catch (Throwable $e) {
            throw A2uiException::execution('A2A request failed: '.$e->getMessage(), null, $e);
        }

        if ($this->stream) {
            $agentResponse = new AgentResponse([], null, $request->session);
            $agentResponse->messages = $this->streamEnvelopes($response->toPsrResponse()->getBody(), $agentResponse);

            return $agentResponse;
        }

        $body = $response->json();

        if (! is_array($body)) {
            throw A2uiException::execution('A2A agent returned a non-JSON response.');
        }

        if (isset($body['error'])) {
            throw A2uiException::execution('A2A agent error: '.($body['error']['message'] ?? Json::encode($body['error'])));
        }

        $session = $request->session;
        [$messages, $text] = self::extract($body['result'] ?? [], $session);

        return new AgentResponse($messages, $text, $session);
    }

    /**
     * Extracts A2UI envelopes and text from an A2A Message, Task or task
     * update event, updating the session (contextId / taskId).
     *
     * @return array{0: list<array>, 1: ?string}
     */
    public static function extract(array $result, array &$session): array
    {
        if (isset($result['contextId']) && is_string($result['contextId'])) {
            $session['contextId'] = $result['contextId'];
        }

        $state = $result['status']['state'] ?? null;
        $taskId = ($result['kind'] ?? null) === 'task' ? ($result['id'] ?? null) : ($result['taskId'] ?? null);

        if (is_string($taskId) && in_array($state, ['input-required', 'auth-required'], true)) {
            $session['taskId'] = $taskId;
        } elseif (is_string($state)) {
            unset($session['taskId']);
        }

        $partLists = [];
        $seen = [];

        if (isset($result['parts'])) {
            $partLists[] = $result['parts'];
        }

        // Agents that publish intermediate status updates (as the official
        // samples do) leave this turn's earlier agent messages in the task
        // history: take the agent messages after the last user message.
        if (is_array($result['history'] ?? null)) {
            $turn = [];
            foreach ($result['history'] as $entry) {
                if (! is_array($entry)) {
                    continue;
                }
                if (($entry['role'] ?? null) === 'user') {
                    $turn = [];
                } elseif (($entry['role'] ?? null) === 'agent' && isset($entry['parts'])) {
                    $turn[] = $entry;
                }
            }
            foreach ($turn as $entry) {
                $seen[$entry['messageId'] ?? spl_object_id((object) $entry)] = true;
                $partLists[] = $entry['parts'];
            }
        }

        if (isset($result['status']['message']['parts']) && ! isset($seen[$result['status']['message']['messageId'] ?? "\0"])) {
            $partLists[] = $result['status']['message']['parts'];
        }

        if (isset($result['artifact']['parts'])) {
            $partLists[] = $result['artifact']['parts'];
        }

        foreach ($result['artifacts'] ?? [] as $artifact) {
            if (isset($artifact['parts'])) {
                $partLists[] = $artifact['parts'];
            }
        }

        $messages = [];
        $texts = [];

        foreach ($partLists as $parts) {
            foreach (is_array($parts) ? $parts : [] as $part) {
                if (! is_array($part)) {
                    continue;
                }

                $isData = ($part['kind'] ?? null) === 'data' || array_key_exists('data', $part);
                $mime = $part['metadata']['mimeType'] ?? $part['mimeType'] ?? null;

                if ($isData && ($mime === Envelope::MIME_TYPE || $mime === 'application/json+a2ui' || ($mime === null && is_array($part['data'] ?? null)))) {
                    $data = $part['data'];
                    if (is_array($data)) {
                        array_push($messages, ...MessageParser::parse($data));
                    }
                } elseif (isset($part['text']) && is_string($part['text'])) {
                    $texts[] = $part['text'];
                }
            }
        }

        return [$messages, $texts === [] ? null : implode("\n\n", $texts)];
    }

    /**
     * @return Generator<int, array>
     */
    private function streamEnvelopes(\Psr\Http\Message\StreamInterface $body, AgentResponse $response): Generator
    {
        $buffer = '';
        $event = [];

        $flush = function () use (&$event, $response): array {
            $data = trim(implode("\n", $event));
            $event = [];

            if ($data === '') {
                return [];
            }

            $decoded = json_decode($data, true);

            if (! is_array($decoded) || ! is_array($decoded['result'] ?? null)) {
                return [];
            }

            [$messages, $text] = self::extract($decoded['result'], $response->session);

            if ($text !== null) {
                $response->text = trim(($response->text ?? '')."\n\n".$text);
            }

            return $messages;
        };

        while (! $body->eof()) {
            $buffer .= $body->read(8192);

            while (($pos = strpos($buffer, "\n")) !== false) {
                $line = rtrim(substr($buffer, 0, $pos), "\r");
                $buffer = substr($buffer, $pos + 1);

                if ($line === '') {
                    yield from $flush();
                } elseif (str_starts_with($line, 'data:')) {
                    $event[] = ltrim(substr($line, 5), ' ');
                }
            }
        }

        if (str_starts_with(trim($buffer), 'data:')) {
            $event[] = ltrim(substr(trim($buffer), 5), ' ');
        }

        yield from $flush();
    }
}
