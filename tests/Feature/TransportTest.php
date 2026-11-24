<?php

namespace A2uiLivewire\Tests\Feature;

use A2uiLivewire\Protocol\Envelope;
use A2uiLivewire\Tests\TestCase;
use A2uiLivewire\Transport\A2aTransport;
use A2uiLivewire\Transport\AgentRequest;
use A2uiLivewire\Transport\HttpTransport;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

class TransportTest extends TestCase
{
    private const CREATE = ['version' => '1.0', 'createSurface' => ['surfaceId' => 's', 'catalogId' => 'basic']];

    public function test_http_transport_posts_messages_and_parses_json(): void
    {
        Http::fake(['agent.test/*' => Http::response(['messages' => [self::CREATE], 'text' => 'Here you go', 'session' => ['thread' => 't1']])]);

        $response = (new HttpTransport(app(Factory::class), 'https://agent.test/a2ui'))->send(
            new AgentRequest([Envelope::action('go', 's', 'btn')], 'hello', ['a2uiRendererCapabilities' => ['1.0' => ['supportedCatalogIds' => ['x']]]]),
        );

        $this->assertSame([self::CREATE], iterator_to_array($response->messages));
        $this->assertSame('Here you go', $response->text);
        $this->assertSame(['thread' => 't1'], $response->session);

        Http::assertSent(function (Request $request) {
            return $request['messages'][0]['action']['name'] === 'go'
                && $request['text'] === 'hello'
                && isset($request['metadata']['a2uiRendererCapabilities']['1.0']);
        });
    }

    public function test_http_transport_parses_jsonl_and_sse_bodies(): void
    {
        $line = json_encode(self::CREATE);

        Http::fake(['agent.test/jsonl' => Http::response($line."\n".$line, 200, ['Content-Type' => 'application/x-ndjson'])]);
        $response = (new HttpTransport(app(Factory::class), 'https://agent.test/jsonl'))->send(new AgentRequest);
        $this->assertCount(2, iterator_to_array($response->messages, false));

        Http::fake(['agent.test/sse' => Http::response("data: {$line}\n\ndata: [DONE]\n\n", 200, ['Content-Type' => 'text/event-stream'])]);
        $response = (new HttpTransport(app(Factory::class), 'https://agent.test/sse'))->send(new AgentRequest);
        $this->assertCount(1, iterator_to_array($response->messages, false));
    }

    public function test_a2a_transport_uses_extension_and_data_parts(): void
    {
        Http::fake(['a2a.test/*' => Http::response([
            'jsonrpc' => '2.0',
            'id' => '1',
            'result' => [
                'kind' => 'task',
                'id' => 'task-1',
                'contextId' => 'ctx-9',
                'status' => [
                    'state' => 'input-required',
                    'message' => ['role' => 'agent', 'parts' => [
                        ['kind' => 'text', 'text' => 'Pick a time'],
                        ['kind' => 'data', 'data' => [self::CREATE], 'metadata' => ['mimeType' => Envelope::MIME_TYPE]],
                    ]],
                ],
            ],
        ])]);

        $transport = new A2aTransport(app(Factory::class), 'https://a2a.test/rpc');
        $response = $transport->send(new AgentRequest([Envelope::action('book', 's', 'btn')], 'Book a table', ['a2uiRendererCapabilities' => ['1.0' => ['supportedCatalogIds' => ['x']]]]));

        $this->assertSame([self::CREATE], iterator_to_array($response->messages));
        $this->assertSame('Pick a time', $response->text);
        $this->assertSame(['contextId' => 'ctx-9', 'taskId' => 'task-1'], $response->session);

        Http::assertSent(function (Request $request) {
            $message = $request['params']['message'];

            return $request->hasHeader('X-A2A-Extensions', Envelope::A2A_EXTENSION_URI)
                && $request['method'] === 'message/send'
                && $message['role'] === 'user'
                && $message['parts'][0] === ['kind' => 'text', 'text' => 'Book a table']
                && $message['parts'][1]['metadata']['mimeType'] === Envelope::MIME_TYPE
                && $message['parts'][1]['data']['action']['name'] === 'book'
                && isset($message['metadata']['a2uiRendererCapabilities']['1.0']);
        });

        // The next turn continues the same context and task.
        $transport->send(new AgentRequest([], 'Tomorrow 7pm', [], $response->session));
        Http::assertSent(fn (Request $request) => ($request['params']['message']['contextId'] ?? null) === 'ctx-9'
            && ($request['params']['message']['taskId'] ?? null) === 'task-1');
    }

    public function test_a2a_reads_this_turns_agent_messages_from_task_history(): void
    {
        $part = fn (string $id) => ['kind' => 'data', 'data' => ['version' => '1.0', 'createSurface' => ['surfaceId' => $id, 'catalogId' => 'basic']], 'metadata' => ['mimeType' => Envelope::MIME_TYPE]];

        Http::fake(['a2a.test/*' => Http::response(['jsonrpc' => '2.0', 'id' => '1', 'result' => [
            'kind' => 'task', 'id' => 't', 'contextId' => 'c',
            'history' => [
                ['role' => 'user', 'messageId' => 'u1', 'parts' => [['kind' => 'text', 'text' => 'first']]],
                ['role' => 'agent', 'messageId' => 'a1', 'parts' => [$part('old')]],
                ['role' => 'user', 'messageId' => 'u2', 'parts' => [['kind' => 'text', 'text' => 'second']]],
                ['role' => 'agent', 'messageId' => 'a2', 'parts' => [$part('new1')]],
                ['role' => 'agent', 'messageId' => 'a3', 'parts' => [$part('new2')]],
            ],
            'status' => ['state' => 'completed', 'message' => ['role' => 'agent', 'messageId' => 'a3', 'parts' => [$part('new2')]]],
        ]])]);

        $response = (new A2aTransport(app(Factory::class), 'https://a2a.test/rpc'))->send(new AgentRequest([], 'second'));
        $ids = array_map(fn ($m) => $m['createSurface']['surfaceId'], iterator_to_array($response->messages, false));

        $this->assertSame(['new1', 'new2'], $ids);
    }

    public function test_config_driven_transports_resolve(): void
    {
        config()->set('a2ui.transports.agent', ['driver' => 'http', 'url' => 'https://agent.test/a2ui']);
        config()->set('a2ui.transports.remote', ['driver' => 'a2a', 'url' => 'https://a2a.test/rpc']);
        $this->app->forgetInstance(\A2uiLivewire\A2ui::class);

        $this->assertInstanceOf(HttpTransport::class, $this->a2ui()->transport('agent'));
        $this->assertInstanceOf(A2aTransport::class, $this->a2ui()->transport('remote'));
    }
}
