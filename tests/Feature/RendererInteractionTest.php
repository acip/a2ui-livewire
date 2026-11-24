<?php

namespace A2uiLivewire\Tests\Feature;

use A2uiLivewire\Catalog\Basic\BasicCatalog;
use A2uiLivewire\Catalog\FunctionDefinition;
use A2uiLivewire\Events\ActionDispatched;
use A2uiLivewire\Livewire\A2uiRenderer;
use A2uiLivewire\Tests\TestCase;
use A2uiLivewire\Transport\AgentRequest;
use Illuminate\Support\Facades\Event;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

class RendererInteractionTest extends TestCase
{
    /** @var list<AgentRequest> */
    private array $requests = [];

    /**
     * Registers an in-process agent that records requests and replies with
     * whatever `$reply` returns.
     */
    private function agent(callable $reply): string
    {
        $this->a2ui()->extendTransport('test-agent', function (AgentRequest $request) use ($reply) {
            $this->requests[] = $request;

            return $reply($request);
        });

        return 'test-agent';
    }

    private static function messages(array $components, array $dataModel = [], string $id = 'main', bool $sendDataModel = false): array
    {
        return [[
            'version' => '1.0',
            'createSurface' => array_filter([
                'surfaceId' => $id,
                'catalogId' => BasicCatalog::ID,
                'components' => $components,
                'dataModel' => $dataModel ?: null,
                'sendDataModel' => $sendDataModel ?: null,
            ], fn ($v) => $v !== null),
        ]];
    }

    private static function inputToken(Testable $component, string $pointer): string
    {
        foreach ($component->get('a2uiInputMap') as $token => $binding) {
            if ($binding['pointer'] === $pointer) {
                return $token;
            }
        }

        throw new \RuntimeException("No input bound to {$pointer}");
    }

    private static function actionToken(Testable $component, string $componentId, ?string $basePath = null): string
    {
        foreach ($component->get('a2uiActions') as $token => $target) {
            if ($target['componentId'] === $componentId && ($basePath === null || $target['basePath'] === $basePath)) {
                return $token;
            }
        }

        throw new \RuntimeException("No action for {$componentId}");
    }

    public function test_two_way_binding_updates_data_model_and_dependent_text(): void
    {
        $component = Livewire::test(A2uiRenderer::class, ['messages' => self::messages([
            ['id' => 'root', 'component' => 'Column', 'children' => ['name_field', 'greeting']],
            ['id' => 'name_field', 'component' => 'TextField', 'label' => 'Name', 'value' => ['@path' => '/user/name']],
            ['id' => 'greeting', 'component' => 'Text', 'text' => ['@call' => 'formatString', 'args' => ['value' => 'Hello, ${/user/name}!']]],
        ], ['user' => ['name' => 'Ada']])]);

        $component->assertSee('Hello, Ada!');
        $token = self::inputToken($component, '/user/name');

        $component->set("a2uiInputs.{$token}", 'Grace')->assertSee('Hello, Grace!');

        $this->assertSame('Grace', $component->get('group')->get('main')->getData('/user/name'));
    }

    public function test_inputs_create_missing_paths_and_convert_types(): void
    {
        $component = Livewire::test(A2uiRenderer::class, ['messages' => self::messages([
            ['id' => 'root', 'component' => 'Column', 'children' => ['agree', 'qty', 'volume', 'when', 'pick']],
            ['id' => 'agree', 'component' => 'CheckBox', 'label' => 'Agree', 'value' => ['@path' => '/form/agree']],
            ['id' => 'qty', 'component' => 'TextField', 'label' => 'Qty', 'variant' => 'number', 'value' => ['@path' => '/form/qty']],
            ['id' => 'volume', 'component' => 'Slider', 'max' => 10, 'value' => ['@path' => '/form/volume']],
            ['id' => 'when', 'component' => 'DateTimeInput', 'enableDate' => true, 'enableTime' => true, 'value' => ['@path' => '/form/when']],
            ['id' => 'pick', 'component' => 'ChoicePicker', 'options' => [['label' => 'A', 'value' => 'a'], ['label' => 'B', 'value' => 'b']], 'value' => ['@path' => '/form/pick']],
        ])]);

        $component
            ->set('a2uiInputs.'.self::inputToken($component, '/form/agree'), true)
            ->set('a2uiInputs.'.self::inputToken($component, '/form/qty'), '12.5')
            ->set('a2uiInputs.'.self::inputToken($component, '/form/volume'), '7')
            ->set('a2uiInputs.'.self::inputToken($component, '/form/when'), '2026-03-04T09:30')
            ->set('a2uiInputs.'.self::inputToken($component, '/form/pick'), 'b');

        $this->assertSame([
            'agree' => true,
            'qty' => 12.5,
            'volume' => 7,
            'when' => '2026-03-04T09:30:00',
            'pick' => ['b'],
        ], $component->get('group')->get('main')->getData('/form'));
    }

    public function test_button_action_sends_resolved_context_and_applies_reply(): void
    {
        Event::fake([ActionDispatched::class]);

        $transport = $this->agent(fn (AgentRequest $request) => [[
            'version' => '1.0',
            'updateDataModel' => ['surfaceId' => 'main', 'path' => '/status', 'value' => 'Saved '.$request->action()['context']['email']],
        ]]);

        $component = Livewire::test(A2uiRenderer::class, ['transport' => $transport, 'messages' => self::messages([
            ['id' => 'root', 'component' => 'Column', 'children' => ['email', 'submit', 'status']],
            ['id' => 'email', 'component' => 'TextField', 'label' => 'Email', 'value' => ['@path' => '/email']],
            ['id' => 'submit_label', 'component' => 'Text', 'text' => 'Send'],
            ['id' => 'submit', 'component' => 'Button', 'child' => 'submit_label', 'variant' => 'primary', 'action' => ['event' => [
                'name' => 'subscribe',
                'userMessage' => ['@call' => 'formatString', 'args' => ['value' => 'Subscribe ${/email}']],
                'context' => ['email' => ['@path' => '/email'], 'source' => 'footer'],
            ]]],
            ['id' => 'status', 'component' => 'Text', 'text' => ['@path' => '/status']],
        ], ['email' => 'ada@example.com'], sendDataModel: true)]);

        $component->call('a2uiAction', self::actionToken($component, 'submit'))->assertSeeText('Saved ada@example.com');

        $this->assertCount(1, $this->requests);
        $action = $this->requests[0]->action();
        $this->assertSame('subscribe', $action['name']);
        $this->assertSame('main', $action['surfaceId']);
        $this->assertSame('submit', $action['sourceComponentId']);
        $this->assertSame(['email' => 'ada@example.com', 'source' => 'footer'], $action['context']);
        $this->assertSame('Subscribe ada@example.com', $action['userMessage']);
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{3}Z$/', $action['timestamp']);

        $metadata = $this->requests[0]->metadata;
        $this->assertContains(BasicCatalog::ID, $metadata['a2uiRendererCapabilities']['1.0']['supportedCatalogIds']);
        $this->assertSame(['email' => 'ada@example.com'], $metadata['a2uiRendererDataModel']['surfaces']['main']);

        $component->assertDispatched('a2ui-action');
        Event::assertDispatched(ActionDispatched::class);
    }

    public function test_failing_checks_disable_the_button_and_block_the_action(): void
    {
        $transport = $this->agent(fn () => []);

        $component = Livewire::test(A2uiRenderer::class, ['transport' => $transport, 'messages' => self::messages([
            ['id' => 'root', 'component' => 'Column', 'children' => ['email', 'go']],
            ['id' => 'email', 'component' => 'TextField', 'label' => 'Email', 'value' => ['@path' => '/email'],
                '@checks' => [['condition' => ['@call' => 'email', 'args' => ['value' => ['@path' => '/email']]], 'message' => 'Enter a valid email.']]],
            ['id' => 'go_label', 'component' => 'Text', 'text' => 'Go'],
            ['id' => 'go', 'component' => 'Button', 'child' => 'go_label', 'action' => ['event' => ['name' => 'go']],
                '@checks' => [['condition' => ['@call' => 'email', 'args' => ['value' => ['@path' => '/email']]], 'message' => 'Email first.']]],
        ], ['email' => 'nope'])]);

        // Validation messages appear only once the field is touched.
        $component->assertDontSee('Enter a valid email.');
        $this->assertMatchesRegularExpression('/\sdisabled(=|\s|>)/', $this->buttonHtml($component->html()));

        $component->call('a2uiAction', self::actionToken($component, 'go'));
        $this->assertCount(0, $this->requests);

        $component->set('a2uiInputs.'.self::inputToken($component, '/email'), 'ok@example.com');
        $this->assertDoesNotMatchRegularExpression('/\sdisabled(=|\s|>)/', $this->buttonHtml($component->html()));

        $component->call('a2uiAction', self::actionToken($component, 'go'));
        $this->assertCount(1, $this->requests);
    }

    private function buttonHtml(string $html): string
    {
        preg_match('/<button[^>]*data-a2ui-id="go"[^>]*>/', $html, $m);

        return $m[0] ?? '';
    }

    public function test_touched_field_shows_check_message(): void
    {
        $component = Livewire::test(A2uiRenderer::class, ['messages' => self::messages([
            ['id' => 'root', 'component' => 'TextField', 'label' => 'Email', 'value' => ['@path' => '/email'],
                '@checks' => [['condition' => ['@call' => 'required', 'args' => ['value' => ['@path' => '/email']]], 'message' => 'Email is required.']]],
        ])]);

        $component->assertDontSee('Email is required.');
        $component->set('a2uiInputs.'.self::inputToken($component, '/email'), '')->assertSee('Email is required.');
    }

    public function test_list_templates_resolve_relative_paths_and_index(): void
    {
        $transport = $this->agent(fn () => []);

        $component = Livewire::test(A2uiRenderer::class, ['transport' => $transport, 'messages' => self::messages([
            ['id' => 'root', 'component' => 'List', 'children' => ['path' => '/items', 'componentId' => 'row']],
            ['id' => 'row', 'component' => 'Row', 'children' => ['pos', 'title', 'remove']],
            ['id' => 'pos', 'component' => 'Text', 'text' => ['@call' => 'formatString', 'args' => ['value' => '#${@index(offset: 1)} of ${/count}']]],
            ['id' => 'title', 'component' => 'Text', 'text' => ['@path' => 'title']],
            ['id' => 'remove_label', 'component' => 'Text', 'text' => 'Remove'],
            ['id' => 'remove', 'component' => 'Button', 'child' => 'remove_label', 'action' => ['event' => ['name' => 'remove', 'context' => ['title' => ['@path' => 'title'], 'index' => ['@call' => '@index']]]]],
        ], ['count' => 2, 'items' => [['title' => 'Milk'], ['title' => 'Bread']]])]);

        $component->assertSeeInOrder(['#1 of 2', 'Milk', '#2 of 2', 'Bread']);

        $component->call('a2uiAction', self::actionToken($component, 'remove', '/items/1'));
        $this->assertSame(['title' => 'Bread', 'index' => 1], $this->requests[0]->action()['context']);
    }

    public function test_open_url_function_call_runs_client_side(): void
    {
        $component = Livewire::test(A2uiRenderer::class, ['messages' => self::messages([
            ['id' => 'label', 'component' => 'Text', 'text' => 'Docs'],
            ['id' => 'root', 'component' => 'Button', 'child' => 'label', 'action' => ['functionCall' => ['@call' => 'openUrl', 'args' => ['url' => ['@path' => '/url']]]]],
        ], ['url' => 'https://a2ui.org'])]);

        $component->call('a2uiAction', self::actionToken($component, 'root'))->assertDispatched('a2ui-open-url', url: 'https://a2ui.org');
        $this->assertSame([], $component->get('a2uiErrors'));
    }

    public function test_open_url_rejects_unsafe_schemes(): void
    {
        $component = Livewire::test(A2uiRenderer::class, ['messages' => self::messages([
            ['id' => 'label', 'component' => 'Text', 'text' => 'Click'],
            ['id' => 'root', 'component' => 'Button', 'child' => 'label', 'action' => ['functionCall' => ['@call' => 'openUrl', 'args' => ['url' => 'javascript:alert(1)']]]],
        ])]);

        $component->call('a2uiAction', self::actionToken($component, 'root'))->assertNotDispatched('a2ui-open-url');
        $this->assertSame('EXECUTION_ERROR', $component->get('a2uiErrors')[0]['code']);
    }

    public function test_agent_functions_round_trip_after_render(): void
    {
        $this->a2ui()->extendCatalog(BasicCatalog::ID, fn ($c) => $c->withAgentFunction('quote'));

        $transport = $this->agent(function (AgentRequest $request) {
            $call = $request->messages[0]['callAgentFunction'] ?? null;

            return $call ? [[
                'version' => '1.0',
                'agentFunctionResponse' => ['functionCallId' => $call['functionCallId'], 'value' => 'Shipping: '.$call['callFunction']['args']['zip']],
            ]] : [];
        });

        $component = Livewire::test(A2uiRenderer::class, ['transport' => $transport, 'messages' => self::messages([
            ['id' => 'root', 'component' => 'Text', 'text' => ['@call' => 'quote', 'args' => ['zip' => ['@path' => '/zip']]]],
        ], ['zip' => '10115'])]);

        // The first render queues the call; the page then flushes it.
        $this->assertCount(1, $component->get('group')->outbox);
        $component->call('a2uiFlush')->assertSee('Shipping: 10115');
        $this->assertSame('main', $this->requests[0]->messages[0]['callAgentFunction']['surfaceId']);
    }

    public function test_renderer_functions_called_by_the_agent_are_answered(): void
    {
        $this->a2ui()->extendCatalog(BasicCatalog::ID, fn ($c) => $c->withFunction(
            'viewport', fn () => ['width' => 1280], allowedCallers: FunctionDefinition::RENDERER_OR_AGENT,
        ));

        $transport = $this->agent(function (AgentRequest $request) {
            if ($request->text !== null) {
                return [['version' => '1.0', 'callRendererFunction' => ['functionCallId' => 'fc1', 'callFunction' => ['@call' => 'viewport', 'catalogId' => BasicCatalog::ID]]]];
            }

            $response = $request->messages[0]['rendererFunctionResponse'];

            return self::messages([['id' => 'root', 'component' => 'Text', 'text' => 'Width '.$response['value']['width']]]);
        });

        Livewire::test(A2uiRenderer::class, ['transport' => $transport])
            ->call('sendPrompt', 'Show something responsive')
            ->assertSee('Width 1280');

        $this->assertCount(2, $this->requests);
        $this->assertSame('Show something responsive', $this->requests[0]->text);
        $this->assertSame('fc1', $this->requests[1]->messages[0]['rendererFunctionResponse']['functionCallId']);
    }

    public function test_errors_are_reported_to_the_agent(): void
    {
        $transport = $this->agent(fn (AgentRequest $request) => $request->text !== null
            ? [['version' => '1.0', 'updateComponents' => ['surfaceId' => 'ghost', 'components' => [['id' => 'root', 'component' => 'Text', 'text' => 'x']]]]]
            : []);

        Livewire::test(A2uiRenderer::class, ['transport' => $transport])->call('sendPrompt', 'hi');

        $this->assertCount(2, $this->requests);
        $this->assertSame('INTEGRITY_ERROR', $this->requests[1]->messages[0]['error']['code']);
        $this->assertSame('ghost', $this->requests[1]->messages[0]['error']['surfaceId']);
    }

    public function test_channel_messages_are_pulled(): void
    {
        $component = Livewire::test(A2uiRenderer::class, ['channel' => 'chat.42']);
        $component->assertDontSee('From a job');

        $this->a2ui()->channel('chat.42')->push(self::messages([['id' => 'root', 'component' => 'Text', 'text' => 'From a job']]));
        $component->call('syncChannel')->assertSee('From a job');

        $this->a2ui()->channel('chat.42')->push([['version' => '1.0', 'deleteSurface' => ['surfaceId' => 'main']]]);
        $component->dispatch('a2ui-sync', channel: 'chat.42')->assertDontSee('From a job');
    }

    public function test_client_messages_are_ignored_unless_enabled(): void
    {
        $messages = self::messages([['id' => 'root', 'component' => 'Text', 'text' => 'Injected']]);

        Livewire::test(A2uiRenderer::class)->dispatch('a2ui-messages', messages: $messages)->assertDontSee('Injected');
        Livewire::test(A2uiRenderer::class, ['acceptClientMessages' => true])->dispatch('a2ui-messages', messages: $messages)->assertSee('Injected');
    }

    public function test_action_and_input_maps_are_locked(): void
    {
        $component = Livewire::test(A2uiRenderer::class, ['messages' => self::messages([
            ['id' => 'root', 'component' => 'TextField', 'label' => 'x', 'value' => ['@path' => '/x']],
        ])]);

        $this->expectException(\Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException::class);
        $component->set('a2uiInputMap', ['evil' => ['surfaceId' => 'main', 'pointer' => '/admin', 'kind' => 'text']]);
    }

    public function test_only_one_surface_can_be_selected(): void
    {
        $messages = [
            ...self::messages([['id' => 'root', 'component' => 'Text', 'text' => 'First']], id: 'one'),
            ...self::messages([['id' => 'root', 'component' => 'Text', 'text' => 'Second']], id: 'two'),
        ];

        Livewire::test(A2uiRenderer::class, ['messages' => $messages, 'surface' => 'two'])
            ->assertSee('Second')
            ->assertDontSee('First');
    }

    public function test_media_urls_are_sanitised_and_markdown_is_safe(): void
    {
        Livewire::test(A2uiRenderer::class, ['messages' => self::messages([
            ['id' => 'root', 'component' => 'Column', 'children' => ['img', 'txt']],
            ['id' => 'img', 'component' => 'Image', 'url' => 'javascript:alert(1)'],
            ['id' => 'txt', 'component' => 'Text', 'text' => "## Title\n\n[x](javascript:alert(1)) **bold**\n\n<script>alert(1)</script>"],
        ])])
            ->assertDontSee('javascript:alert', false)
            ->assertDontSee('<script>', false)
            ->assertSee('<h2>Title</h2>', false)
            ->assertSee('<strong>bold</strong>', false);
    }

    public function test_accessibility_attributes_are_mapped(): void
    {
        Livewire::test(A2uiRenderer::class, ['messages' => self::messages([
            ['id' => 'label', 'component' => 'Text', 'text' => 'Submit'],
            ['id' => 'root', 'component' => 'Button', 'child' => 'label', 'action' => ['event' => ['name' => 'x']],
                '@accessibility' => ['label' => 'Send form', 'description' => 'Sends it', 'live' => 'polite']],
        ])])
            ->assertSee('aria-label="Send form"', false)
            ->assertSee('aria-description="Sends it"', false)
            ->assertSee('aria-live="polite"', false);
    }
}
