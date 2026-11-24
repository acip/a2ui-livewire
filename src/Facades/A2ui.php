<?php

namespace A2uiLivewire\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @method static \A2uiLivewire\Catalog\CatalogRegistry catalogs()
 * @method static \A2uiLivewire\A2ui registerCatalog(\A2uiLivewire\Catalog\Catalog|array|string $catalog, array $views = [], array $aliases = [], bool $inline = false)
 * @method static \A2uiLivewire\A2ui extendCatalog(string $catalogId, callable $callback)
 * @method static \A2uiLivewire\A2ui extendTransport(string $name, \Closure|\A2uiLivewire\Transport\AgentTransport $factory)
 * @method static \A2uiLivewire\Transport\AgentTransport transport(?string $name = null)
 * @method static \A2uiLivewire\Channel channel(string $name)
 * @method static array capabilities()
 * @method static \A2uiLivewire\Processing\ProcessResult process(mixed $messages, ?\A2uiLivewire\State\SurfaceGroup $group = null)
 * @method static array parse(mixed $payload)
 *
 * @see \A2uiLivewire\A2ui
 */
class A2ui extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return \A2uiLivewire\A2ui::class;
    }
}
