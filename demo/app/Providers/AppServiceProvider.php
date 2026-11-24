<?php

namespace App\Providers;

use A2uiLivewire\Catalog\Basic\BasicCatalog;
use A2uiLivewire\Catalog\Catalog;
use A2uiLivewire\Facades\A2ui;
use A2uiLivewire\Transport\AgentRequest;
use App\A2ui\DemoAgent;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public const CHAT_CATALOG = 'https://demo.a2ui-livewire.test/catalogs/chat.json';

    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        // The playground agent (in-process). Use the "http" or "a2a"
        // transports from config/a2ui.php to talk to a real agent instead.
        A2ui::extendTransport('demo', fn (AgentRequest $request) => app(DemoAgent::class)->handle($request));

        // The gallery has no agent: actions only show up in the action log.
        A2ui::extendTransport('gallery', fn (AgentRequest $request) => []);

        // Agent-side function the renderer routes with callAgentFunction.
        A2ui::extendCatalog(BasicCatalog::ID, fn (Catalog $catalog) => $catalog->withAgentFunction('estimateShipping', [
            'type' => 'object',
            'properties' => [
                '@call' => ['const' => 'estimateShipping'],
                'args' => ['type' => 'object', 'properties' => ['zip' => ['type' => 'string'], 'kg' => ['type' => 'number']]],
            ],
        ], 'string'));

        // A custom catalog mixed into Basic Catalog surfaces via @catalogId.
        A2ui::registerCatalog([
            'catalogId' => self::CHAT_CATALOG,
            'protocolVersion' => '1.0',
            'title' => 'Demo chat catalog',
            'components' => [
                'ChatBubble' => [
                    'type' => 'object',
                    'properties' => [
                        'component' => ['const' => 'ChatBubble'],
                        'text' => ['$ref' => 'common_types.json#/$defs/DynamicString'],
                        'from' => ['type' => 'string', 'enum' => ['user', 'agent'], 'default' => 'agent'],
                    ],
                    'required' => ['component', 'text'],
                ],
            ],
        ], views: ['ChatBubble' => 'a2ui.chat-bubble'], inline: true);
    }
}
