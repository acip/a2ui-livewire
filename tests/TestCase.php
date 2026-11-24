<?php

namespace A2uiLivewire\Tests;

use A2uiLivewire\A2ui;
use A2uiLivewire\A2uiServiceProvider;
use A2uiLivewire\Catalog\Basic\BasicCatalog;
use A2uiLivewire\Evaluation\Evaluator;
use A2uiLivewire\State\Surface;
use A2uiLivewire\State\SurfaceGroup;
use Flux\FluxServiceProvider;
use Livewire\LivewireServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    /** Basic Catalog theme under test. The default theme runs without Flux installed. */
    protected string $theme = 'default';

    protected function getPackageProviders($app): array
    {
        return array_values(array_filter([
            LivewireServiceProvider::class,
            $this->theme === 'flux' ? FluxServiceProvider::class : null,
            A2uiServiceProvider::class,
        ]));
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('app.key', 'base64:2fl+Ktvkfl+Fuz4Qp/A75G2RTiWVA/ZoKZvp6fiiM10=');
        $app['config']->set('cache.default', 'array');
        $app['config']->set('a2ui.debug', false);
        $app['config']->set('a2ui.theme', $this->theme);
    }

    protected function a2ui(): A2ui
    {
        return $this->app->make(A2ui::class);
    }

    protected function surface(array $dataModel = [], string $id = 'main'): Surface
    {
        return new Surface($id, BasicCatalog::ID, dataModel: $dataModel);
    }

    protected function evaluator(?SurfaceGroup $group = null): Evaluator
    {
        return new Evaluator($this->a2ui()->catalogs(), $group ?? new SurfaceGroup, ['locale' => 'en-US', 'baseUrl' => 'https://app.test/']);
    }

    /**
     * @return list<array>
     */
    protected function example(string $name): array
    {
        $data = json_decode((string) file_get_contents(__DIR__.'/../resources/examples/'.$name.'.json'), true);

        return $data['messages'];
    }
}
