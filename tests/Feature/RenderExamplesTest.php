<?php

namespace A2uiLivewire\Tests\Feature;

use A2uiLivewire\Livewire\A2uiRenderer;
use A2uiLivewire\Tests\TestCase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Renders every official Basic Catalog example (catalogs/basic/v1/examples).
 */
class RenderExamplesTest extends TestCase
{
    public static function examples(): iterable
    {
        foreach (glob(__DIR__.'/../../resources/examples/*.json') as $file) {
            yield basename($file, '.json') => [basename($file, '.json')];
        }
    }

    #[DataProvider('examples')]
    public function test_example_renders_without_errors(string $name): void
    {
        $component = Livewire::test(A2uiRenderer::class, ['messages' => $this->example($name)]);

        $component->assertOk();
        $html = $component->html();

        $this->assertStringContainsString('data-a2ui-surface=', $html, "{$name}: a surface is rendered");
        $this->assertStringContainsString('data-a2ui-component=', $html, "{$name}: components are rendered");
        $this->assertSame([], $component->get('a2uiErrors'), "{$name}: ".json_encode($component->get('a2uiErrors')));

        // Every referenced component should render (no pending placeholders).
        $this->assertStringNotContainsString('data-a2ui-pending=', $html, "{$name}: all children resolved");

        if ($this->theme === 'default') {
            $this->assertStringNotContainsString('data-flux', $html, "{$name}: the default theme uses no Flux markup");
        }
    }
}
