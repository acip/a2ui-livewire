<?php

namespace A2uiLivewire\Tests\Feature;

use A2uiLivewire\Catalog\Basic\BasicCatalog;
use A2uiLivewire\Livewire\A2uiRenderer;
use A2uiLivewire\Tests\TestCase;
use Illuminate\Support\Facades\Blade;
use Livewire\Livewire;

class ThemeTest extends TestCase
{
    private static function messages(array $components): array
    {
        return [['version' => '1.0', 'createSurface' => ['surfaceId' => 'main', 'catalogId' => BasicCatalog::ID, 'components' => $components]]];
    }

    public function test_default_theme_is_the_default(): void
    {
        $this->assertSame('default', $this->a2ui()->theme());
        $this->assertSame('a2ui::themes.default.button', $this->a2ui()->catalogs()->get('basic')->component('Button')->view);
    }

    public function test_icons_render_bundled_material_svgs_and_custom_paths(): void
    {
        Livewire::test(A2uiRenderer::class, ['messages' => self::messages([
            ['id' => 'root', 'component' => 'Row', 'children' => ['a', 'b', 'c']],
            ['id' => 'a', 'component' => 'Icon', 'name' => 'mail'],
            ['id' => 'b', 'component' => 'Icon', 'name' => ['svgPath' => 'M0 0h24v24H0z']],
            ['id' => 'c', 'component' => 'Icon', 'name' => 'notAnIcon'],
        ])])
            ->assertSee('<path d="M20 4H4c-1.1', false)            // Material "mail"
            ->assertSee('<path d="M0 0h24v24H0z"/>', false)
            ->assertDontSee('data-a2ui-id="c"', false);          // unknown names render nothing
    }

    public function test_unsafe_svg_paths_are_rejected(): void
    {
        Livewire::test(A2uiRenderer::class, ['messages' => self::messages([
            ['id' => 'root', 'component' => 'Icon', 'name' => ['svgPath' => '"/><script>alert(1)</script>']],
        ])])->assertDontSee('<script>alert', false);
    }

    public function test_chips_use_package_markup(): void
    {
        Livewire::test(A2uiRenderer::class, ['messages' => self::messages([
            ['id' => 'root', 'component' => 'ChoicePicker', 'displayStyle' => 'chips', 'options' => [['label' => 'A', 'value' => 'a']], 'value' => ['@path' => '/v']],
        ])])->assertSee('a2ui-chip', false)->assertDontSee('pills', false);
    }

    public function test_styles_directive_inlines_the_stylesheet(): void
    {
        $html = Blade::render('@a2uiStyles');

        $this->assertStringStartsWith('<style data-a2ui-styles>', $html);
        $this->assertStringContainsString('.a2ui-renderer', $html);
    }

    public function test_flux_theme_requires_flux_to_be_selected_explicitly(): void
    {
        config()->set('a2ui.theme', 'nope');
        $this->app->forgetInstance(\A2uiLivewire\A2ui::class);

        $this->expectException(\InvalidArgumentException::class);
        $this->a2ui()->theme();
    }
}
