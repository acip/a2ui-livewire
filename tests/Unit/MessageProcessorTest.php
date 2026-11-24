<?php

namespace A2uiLivewire\Tests\Unit;

use A2uiLivewire\Catalog\Basic\BasicCatalog;
use A2uiLivewire\Catalog\FunctionDefinition;
use A2uiLivewire\Exceptions\A2uiException;
use A2uiLivewire\Protocol\MessageParser;
use A2uiLivewire\State\SurfaceGroup;
use A2uiLivewire\Tests\TestCase;

class MessageProcessorTest extends TestCase
{
    private function create(string $id = 'main', array $extra = []): array
    {
        return ['version' => '1.0', 'createSurface' => ['surfaceId' => $id, 'catalogId' => BasicCatalog::ID, ...$extra]];
    }

    public function test_create_surface_applies_data_model_and_components(): void
    {
        $group = new SurfaceGroup;
        $result = $this->a2ui()->processor()->process($group, [$this->create('main', [
            'components' => [['id' => 'root', 'component' => 'Text', 'text' => ['@path' => '/name']]],
            'dataModel' => ['name' => 'Ada'],
            'sendDataModel' => true,
        ])]);

        $this->assertSame([], $result->errors);
        $this->assertSame(['main'], $result->created);
        $surface = $group->get('main');
        $this->assertTrue($surface->hasRoot());
        $this->assertSame('Ada', $surface->getData('/name'));
        $this->assertSame(['version' => '1.0', 'surfaces' => ['main' => ['name' => 'Ada']]], $group->rendererDataModel());
    }

    public function test_duplicate_surface_is_an_integrity_error(): void
    {
        $group = new SurfaceGroup;
        $result = $this->a2ui()->processor()->process($group, [$this->create(), $this->create()]);

        $this->assertCount(1, $result->errors);
        $this->assertSame(A2uiException::INTEGRITY_ERROR, $result->errors[0]->errorCode);
        $this->assertSame('error', array_key_first(array_diff_key($result->outbound[0], ['version' => 1])));
    }

    public function test_surface_can_be_recreated_after_delete(): void
    {
        $group = new SurfaceGroup;
        $result = $this->a2ui()->processor()->process($group, [
            $this->create(),
            ['version' => '1.0', 'deleteSurface' => ['surfaceId' => 'main']],
            $this->create(),
        ]);

        $this->assertSame([], $result->errors);
        $this->assertTrue($group->has('main'));
    }

    public function test_rejects_other_protocol_versions_but_keeps_processing(): void
    {
        $group = new SurfaceGroup;
        $result = $this->a2ui()->processor()->process($group, [
            ['version' => 'v0.9', 'createSurface' => ['surfaceId' => 'old', 'catalogId' => 'x']],
            ['surfaceUpdate' => ['surfaceId' => 'legacy', 'components' => []]],
            $this->create('ok'),
        ]);

        $this->assertCount(2, $result->errors);
        $this->assertSame(['ok'], array_keys($group->surfaces));
    }

    public function test_update_data_model_upserts_and_deletes(): void
    {
        $group = new SurfaceGroup;
        $this->a2ui()->processor()->process($group, [
            $this->create('main', ['dataModel' => ['user' => ['name' => 'Ada', 'temp' => 1]]]),
            ['version' => '1.0', 'updateDataModel' => ['surfaceId' => 'main', 'path' => '/user/email', 'value' => 'ada@example.com']],
            ['version' => '1.0', 'updateDataModel' => ['surfaceId' => 'main', 'path' => '/user/temp', 'value' => null]],
            ['version' => '1.0', 'updateDataModel' => ['surfaceId' => 'main', 'path' => '/items/0/title', 'value' => 'First']],
        ]);

        $this->assertSame(['user' => ['name' => 'Ada', 'email' => 'ada@example.com'], 'items' => [['title' => 'First']]], $group->get('main')->dataModel);
    }

    public function test_update_without_value_is_a_partial_snapshot_in_lenient_mode(): void
    {
        $group = new SurfaceGroup;
        $result = $this->a2ui()->processor()->process($group, [
            $this->create('main', ['dataModel' => ['x' => 1]]),
            ['version' => '1.0', 'updateDataModel' => ['surfaceId' => 'main']],
            ['version' => '1.0', 'updateDataModel' => ['surfaceId' => 'main', 'path' => '/x']],
            ['version' => '1.0', 'updateDataModel' => ['surfaceId' => 'main', 'path' => '/x', 'value' => 2]],
        ]);

        $this->assertSame([], $result->errors);
        $this->assertSame(['x' => 2], $group->get('main')->dataModel);
    }

    public function test_update_data_model_requires_value_in_strict_mode(): void
    {
        config()->set('a2ui.strict', true);
        $this->app->forgetInstance(\A2uiLivewire\A2ui::class);

        $group = new SurfaceGroup;
        $result = $this->a2ui()->processor()->process($group, [
            $this->create(),
            ['version' => '1.0', 'updateDataModel' => ['surfaceId' => 'main', 'path' => '/x']],
        ]);

        $this->assertSame(A2uiException::VALIDATION_FAILED, $result->errors[0]->errorCode);
    }

    public function test_updates_to_unknown_surface_fail(): void
    {
        $result = $this->a2ui()->processor()->process(new SurfaceGroup, [
            ['version' => '1.0', 'updateComponents' => ['surfaceId' => 'nope', 'components' => [['id' => 'root', 'component' => 'Text', 'text' => 'x']]]],
        ]);

        $this->assertSame(A2uiException::INTEGRITY_ERROR, $result->errors[0]->errorCode);
    }

    public function test_reserved_surface_type_and_keys_are_rejected(): void
    {
        $group = new SurfaceGroup;
        $result = $this->a2ui()->processor()->process($group, [
            $this->create(),
            ['version' => '1.0', 'updateComponents' => ['surfaceId' => 'main', 'components' => [['id' => 'root', 'component' => 'Surface']]]],
            ['version' => '1.0', 'updateComponents' => ['surfaceId' => 'main', 'components' => [['id' => 'root', 'component' => 'Text', 'text' => 'x', '@bogus' => 1]]]],
        ]);

        $this->assertCount(2, $result->errors);
        $this->assertFalse($group->get('main')->hasRoot());
    }

    public function test_legacy_bare_mixins_are_upgraded(): void
    {
        $group = new SurfaceGroup;
        $this->a2ui()->processor()->process($group, [
            $this->create('main', ['components' => [[
                'id' => 'root', 'component' => 'TextField', 'label' => 'Email', 'value' => ['@path' => '/email'],
                'checks' => [['condition' => ['@call' => 'required', 'args' => ['value' => ['@path' => '/email']]], 'message' => 'Required']],
                'accessibility' => ['label' => 'Email address'],
            ]]]),
        ]);

        $root = $group->get('main')->component('root');
        $this->assertArrayHasKey('@checks', $root);
        $this->assertArrayHasKey('@accessibility', $root);
        $this->assertArrayNotHasKey('checks', $root);
    }

    public function test_lenient_mode_reports_catalog_issues_but_keeps_component(): void
    {
        $group = new SurfaceGroup;
        $result = $this->a2ui()->processor()->process($group, [
            $this->create('main', ['components' => [['id' => 'root', 'component' => 'Text', 'variant' => 'h1']]]),
        ]);

        $this->assertCount(2, $result->errors); // missing "text" + bad enum
        $this->assertTrue($group->get('main')->hasRoot());
    }

    public function test_strict_mode_rejects_invalid_components(): void
    {
        config()->set('a2ui.strict', true);
        $this->app->forgetInstance(\A2uiLivewire\A2ui::class);

        $group = new SurfaceGroup;
        $result = $this->a2ui()->processor()->process($group, [
            $this->create('main', ['components' => [['id' => 'root', 'component' => 'Text']]]),
        ]);

        $this->assertNotEmpty($result->errors);
        $this->assertFalse($group->has('main'));
    }

    public function test_call_renderer_function_respects_allowed_callers(): void
    {
        $this->a2ui()->extendCatalog(BasicCatalog::ID, fn ($catalog) => $catalog
            ->withFunction('screenSize', fn () => [1920, 1080], allowedCallers: FunctionDefinition::RENDERER_OR_AGENT)
            ->withFunction('userName', fn (array $args, $ctx) => $ctx->data->get('/name'), allowedCallers: FunctionDefinition::AGENT_ONLY));

        $group = new SurfaceGroup;
        $result = $this->a2ui()->processor()->process($group, [
            $this->create('main', ['dataModel' => ['name' => 'Ada']]),
            ['version' => '1.0', 'callRendererFunction' => ['functionCallId' => 'c1', 'callFunction' => ['@call' => 'screenSize', 'catalogId' => BasicCatalog::ID]]],
            ['version' => '1.0', 'callRendererFunction' => ['functionCallId' => 'c2', 'surfaceId' => 'main', 'callFunction' => ['@call' => 'userName', 'catalogId' => BasicCatalog::ID]]],
            ['version' => '1.0', 'callRendererFunction' => ['functionCallId' => 'c3', 'callFunction' => ['@call' => 'formatString', 'catalogId' => BasicCatalog::ID, 'args' => ['value' => 'x']]]],
            ['version' => '1.0', 'callRendererFunction' => ['functionCallId' => 'c4', 'callFunction' => ['@call' => 'nope', 'catalogId' => BasicCatalog::ID]]],
            ['version' => '1.0', 'callRendererFunction' => ['functionCallId' => 'c5', 'surfaceId' => 'ghost', 'callFunction' => ['@call' => 'screenSize', 'catalogId' => BasicCatalog::ID]]],
        ]);

        $responses = array_column(array_column($result->outbound, 'rendererFunctionResponse'), null, 'functionCallId');

        $this->assertSame([1920, 1080], $responses['c1']['value']);
        $this->assertSame('Ada', $responses['c2']['value']);
        $this->assertSame('INVALID_FUNCTION_CALL', $responses['c3']['error']['code']); // rendererOnly
        $this->assertSame('INVALID_FUNCTION_CALL', $responses['c4']['error']['code']);
        $this->assertSame('INVALID_FUNCTION_CALL', $responses['c5']['error']['code']);
    }

    public function test_untrusted_messages_cannot_call_renderer_functions(): void
    {
        $this->a2ui()->extendCatalog(BasicCatalog::ID, fn ($catalog) => $catalog
            ->withFunction('secret', fn () => 'x', allowedCallers: FunctionDefinition::RENDERER_OR_AGENT));

        $result = $this->a2ui()->processor()->process(new SurfaceGroup, [
            ['version' => '1.0', 'callRendererFunction' => ['functionCallId' => 'c1', 'callFunction' => ['@call' => 'secret', 'catalogId' => BasicCatalog::ID]]],
        ], trusted: false);

        $this->assertSame('INVALID_FUNCTION_CALL', $result->outbound[0]['rendererFunctionResponse']['error']['code']);
    }

    public function test_agent_functions_route_and_resolve(): void
    {
        $this->a2ui()->extendCatalog(BasicCatalog::ID, fn ($catalog) => $catalog->withAgentFunction('lookupPrice'));

        $group = new SurfaceGroup;
        $this->a2ui()->processor()->process($group, [$this->create('main', ['dataModel' => ['sku' => 'A1']])]);
        $surface = $group->get('main');
        $call = ['@call' => 'lookupPrice', 'args' => ['sku' => ['@path' => '/sku']]];

        // First evaluation: pending, queues one callAgentFunction.
        $this->assertNull($this->evaluator($group)->context($surface)->resolve($call));
        $this->assertNull($this->evaluator($group)->context($surface)->resolve($call));
        $this->assertCount(1, $group->outbox);

        $outbound = $group->outbox[0]['callAgentFunction'];
        $this->assertSame('main', $outbound['surfaceId']);
        $this->assertSame(['sku' => 'A1'], $outbound['callFunction']['args']);
        $this->assertSame(BasicCatalog::ID, $outbound['callFunction']['catalogId']);

        $this->a2ui()->processor()->process($group, [
            ['version' => '1.0', 'agentFunctionResponse' => ['functionCallId' => $outbound['functionCallId'], 'value' => 42.5]],
        ]);

        $this->assertSame(42.5, $this->evaluator($group)->context($surface)->resolve($call));
    }

    public function test_parser_accepts_jsonl_sse_and_fenced_output(): void
    {
        $line = json_encode($this->create('a'));
        $this->assertCount(2, MessageParser::parse($line."\n".json_encode($this->create('b'))));
        $this->assertCount(1, MessageParser::parse("event: message\ndata: {$line}\n\n"));
        $this->assertCount(1, MessageParser::parse("```json\n[{$line}]\n```"));
        $this->assertCount(1, MessageParser::parse(['messages' => [$this->create()]]));
    }

    public function test_contact_form_jsonl_stream(): void
    {
        $group = new SurfaceGroup;
        $messages = MessageParser::parse(file_get_contents(__DIR__.'/../Fixtures/contact_form_example.jsonl'));
        $result = $this->a2ui()->processor()->process($group, array_slice($messages, 0, 3));

        $this->assertSame([], $result->errors);
        $this->assertSame('John', $group->get('contact_form_1')->getData('/contact/firstName'));
    }
}
