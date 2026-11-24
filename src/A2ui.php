<?php

namespace A2uiLivewire;

use A2uiLivewire\Catalog\Basic\BasicCatalog;
use A2uiLivewire\Catalog\Catalog;
use A2uiLivewire\Catalog\CatalogRegistry;
use A2uiLivewire\Exceptions\A2uiException;
use A2uiLivewire\Processing\MessageProcessor;
use A2uiLivewire\Processing\ProcessResult;
use A2uiLivewire\Protocol\MessageParser;
use A2uiLivewire\Rendering\Renderer;
use A2uiLivewire\State\SurfaceGroup;
use A2uiLivewire\Support\Json;
use A2uiLivewire\Transport\A2aTransport;
use A2uiLivewire\Transport\AgentTransport;
use A2uiLivewire\Transport\CallbackTransport;
use A2uiLivewire\Transport\HttpTransport;
use A2uiLivewire\Transport\NullTransport;
use Closure;
use Illuminate\Contracts\Container\Container;
use Illuminate\Http\Client\Factory as HttpFactory;
use InvalidArgumentException;

/**
 * Entry point for configuring the renderer: catalogs, transports and
 * message channels. Available as the `A2ui` facade.
 */
class A2ui
{
    private ?CatalogRegistry $catalogs = null;

    /** @var array<string, Closure(Container, array): AgentTransport> */
    private array $transportFactories = [];

    /** @var array<string, AgentTransport> */
    private array $transports = [];

    /** @var list<Closure(CatalogRegistry): void> */
    private array $catalogCallbacks = [];

    public function __construct(
        private readonly Container $app,
        private readonly array $config = [],
    ) {}

    // -- catalogs ------------------------------------------------------------

    public function catalogs(): CatalogRegistry
    {
        if ($this->catalogs !== null) {
            return $this->catalogs;
        }

        $this->catalogs = new CatalogRegistry;

        if ($this->config['basic_catalog']['enabled'] ?? true) {
            $this->catalogs->register(BasicCatalog::make(
                $this->config['basic_catalog']['views'] ?? [],
                'a2ui::themes.'.$this->theme().'.',
            ), ['basic']);
        }

        foreach ($this->catalogCallbacks as $callback) {
            $callback($this->catalogs);
        }

        return $this->catalogs;
    }

    /**
     * Registers a catalog: a Catalog instance, a definition array, or a path
     * to a catalog JSON file.
     *
     * @param  array<string, string>  $views  component name => Blade view
     */
    public function registerCatalog(Catalog|array|string $catalog, array $views = [], array $aliases = [], bool $inline = false): static
    {
        $resolved = match (true) {
            $catalog instanceof Catalog => $catalog->asInline($inline || $catalog->inline),
            is_array($catalog) => Catalog::fromDefinition($catalog, $views, $inline),
            default => Catalog::fromDefinition(Json::decode((string) file_get_contents($catalog)), $views, $inline),
        };

        return $this->withCatalogs(static fn (CatalogRegistry $registry) => $registry->register($resolved, $aliases));
    }

    /**
     * Modifies a registered catalog, e.g. to add components or functions:
     *
     *     A2ui::extendCatalog(BasicCatalog::ID, fn (Catalog $c) => $c
     *         ->withComponent('Rating', 'components.a2ui.rating', $schema)
     *         ->withFunction('slugify', fn (array $args) => Str::slug($args['value'])));
     *
     * @param  callable(Catalog): Catalog  $callback
     */
    public function extendCatalog(string $catalogId, callable $callback): static
    {
        return $this->withCatalogs(static fn (CatalogRegistry $registry) => $registry->extend($catalogId, $callback));
    }

    private function withCatalogs(Closure $callback): static
    {
        if ($this->catalogs !== null) {
            $callback($this->catalogs);
        } else {
            $this->catalogCallbacks[] = $callback;
        }

        return $this;
    }

    /**
     * The Basic Catalog view theme: "default" (plain Blade + a2ui.css, no
     * dependencies) or "flux" (Flux components; requires livewire/flux).
     */
    public function theme(): string
    {
        $theme = (string) ($this->config['theme'] ?? 'default');

        if (! in_array($theme, ['default', 'flux'], true)) {
            throw new InvalidArgumentException("Unknown A2UI theme [{$theme}]. Use \"default\" or \"flux\".");
        }

        if ($theme === 'flux' && ! class_exists(\Flux\Flux::class)) {
            throw new InvalidArgumentException('The A2UI "flux" theme requires livewire/flux: composer require livewire/flux');
        }

        return $theme;
    }

    /**
     * The renderer stylesheet (for `@a2uiStyles` or your own pipeline).
     */
    public function stylesPath(): string
    {
        return dirname(__DIR__).'/resources/css/a2ui.css';
    }

    /**
     * The `a2uiRendererCapabilities` transport metadata object.
     */
    public function capabilities(): array
    {
        $capabilities = ['supportedCatalogIds' => $this->catalogs()->supportedCatalogIds()];
        $inline = $this->catalogs()->inlineCatalogs();

        if ($inline !== []) {
            $capabilities['inlineCatalogs'] = $inline;
        }

        return ['1.0' => $capabilities];
    }

    // -- processing ----------------------------------------------------------

    public function processor(): MessageProcessor
    {
        return new MessageProcessor($this->catalogs(), $this->evaluationOptions());
    }

    public function renderer(): Renderer
    {
        return new Renderer($this->app->make('view'));
    }

    /**
     * Parses and applies messages to a surface group (a new one by default).
     */
    public function process(mixed $messages, ?SurfaceGroup $group = null): ProcessResult
    {
        return $this->processor()->process($group ?? new SurfaceGroup, MessageParser::parse($messages));
    }

    /**
     * @return array{strict: bool, locale: string, openUrlSchemes: list<string>, baseUrl: ?string}
     */
    public function evaluationOptions(): array
    {
        $baseUrl = null;
        if ($this->app->bound('url')) {
            try {
                $baseUrl = $this->app->make('url')->to('/');
            } catch (\Throwable) {
                $baseUrl = null;
            }
        }

        return [
            'strict' => (bool) ($this->config['strict'] ?? false),
            'locale' => $this->config['locale'] ?? str_replace('_', '-', (string) ($this->app->bound('config') ? $this->app->make('config')->get('app.locale', 'en') : 'en')),
            'openUrlSchemes' => $this->config['open_url_schemes'] ?? ['http', 'https'],
            'baseUrl' => $baseUrl,
        ];
    }

    public function config(string $key, mixed $default = null): mixed
    {
        return data_get($this->config, $key, $default);
    }

    // -- transports ----------------------------------------------------------

    /**
     * Resolves a configured transport by name (or the default transport).
     */
    public function transport(?string $name = null): AgentTransport
    {
        $name ??= $this->config['default_transport'] ?? 'null';

        if (isset($this->transports[$name])) {
            return $this->transports[$name];
        }

        $config = $this->config['transports'][$name] ?? null;

        if (isset($this->transportFactories[$name])) {
            return $this->transports[$name] = ($this->transportFactories[$name])($this->app, $config ?? []);
        }

        if ($config === null) {
            if (class_exists($name) && is_subclass_of($name, AgentTransport::class)) {
                return $this->transports[$name] = $this->app->make($name);
            }

            throw new InvalidArgumentException("A2UI transport [{$name}] is not defined.");
        }

        return $this->transports[$name] = $this->makeTransport($config);
    }

    /**
     * Registers a transport under a name:
     *
     *     A2ui::extendTransport('my-agent', fn () => new CallbackTransport(fn (AgentRequest $r) => ...));
     *     A2ui::extendTransport('my-agent', fn (AgentRequest $request) => [...messages]);
     */
    public function extendTransport(string $name, Closure|AgentTransport $factory): static
    {
        unset($this->transports[$name]);

        if ($factory instanceof AgentTransport) {
            $this->transports[$name] = $factory;

            return $this;
        }

        $reflection = new \ReflectionFunction($factory);
        $firstType = $reflection->getParameters()[0] ?? null;
        $typeName = $firstType?->getType() instanceof \ReflectionNamedType ? $firstType->getType()->getName() : null;

        // A closure that takes an AgentRequest is an in-process agent.
        if ($typeName === Transport\AgentRequest::class) {
            $this->transports[$name] = new CallbackTransport($factory);

            return $this;
        }

        $this->transportFactories[$name] = $factory;

        return $this;
    }

    public function transportStreams(?string $name = null): bool
    {
        $name ??= $this->config['default_transport'] ?? 'null';

        return (bool) ($this->config['transports'][$name]['stream'] ?? false);
    }

    private function makeTransport(array $config): AgentTransport
    {
        $http = fn () => $this->app->make(HttpFactory::class);

        return match ($config['driver'] ?? null) {
            'null' => new NullTransport,
            'http' => new HttpTransport($http(), $this->requireUrl($config), $config['headers'] ?? [], (int) ($config['timeout'] ?? 60), (bool) ($config['stream'] ?? false)),
            'a2a' => new A2aTransport($http(), $this->requireUrl($config), $config['headers'] ?? [], (int) ($config['timeout'] ?? 120), (bool) ($config['stream'] ?? false), (string) ($config['data_parts'] ?? 'per_message')),
            'callback' => new CallbackTransport(Closure::fromCallable($config['callback'])),
            'class' => $this->app->make($config['class']),
            default => throw new InvalidArgumentException('Unknown A2UI transport driver ['.($config['driver'] ?? 'none').'].'),
        };
    }

    private function requireUrl(array $config): string
    {
        $url = $config['url'] ?? null;

        if (! is_string($url) || $url === '') {
            throw new InvalidArgumentException('A2UI transport ['.($config['driver'] ?? '').'] requires a url.');
        }

        return $url;
    }

    // -- channels ------------------------------------------------------------

    /**
     * A server-side message channel. Code anywhere in the application (a
     * controller, a queued job streaming an LLM response, ...) can push
     * messages that a renderer subscribed to the channel picks up:
     *
     *     A2ui::channel("chat.{$id}")->push($messages);
     *     <livewire:a2ui channel="chat.{{ $id }}" poll="750ms" />
     */
    public function channel(string $name): Channel
    {
        $store = $this->app->make('cache')->store($this->config['channel_store'] ?? null);

        return new Channel($store, $name, (int) ($this->config['channel_ttl'] ?? 3600));
    }

    /**
     * @throws A2uiException
     */
    public function parse(mixed $payload): array
    {
        return MessageParser::parse($payload);
    }
}
