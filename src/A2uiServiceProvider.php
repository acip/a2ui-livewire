<?php

namespace A2uiLivewire;

use A2uiLivewire\Livewire\A2uiRenderer;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\ServiceProvider;
use Livewire\Livewire;

class A2uiServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/a2ui.php', 'a2ui');

        $this->app->singleton(A2ui::class, fn ($app) => new A2ui($app, $app['config']->get('a2ui', [])));
    }

    public function boot(): void
    {
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'a2ui');

        Livewire::component('a2ui', A2uiRenderer::class);

        // Inlines the renderer stylesheet: <head> ... @a2uiStyles ... </head>
        Blade::directive('a2uiStyles', fn () => '<?php echo \'<style data-a2ui-styles>\'.file_get_contents(app(\\A2uiLivewire\\A2ui::class)->stylesPath()).\'</style>\'; ?>');

        if ($this->app->runningInConsole()) {
            $this->publishes([__DIR__.'/../config/a2ui.php' => config_path('a2ui.php')], 'a2ui-config');
            $this->publishes([__DIR__.'/../resources/views' => resource_path('views/vendor/a2ui')], 'a2ui-views');
            $this->publishes([__DIR__.'/../resources/css/a2ui.css' => public_path('vendor/a2ui/a2ui.css')], 'a2ui-assets');
        }
    }
}
