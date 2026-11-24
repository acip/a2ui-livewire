<?php

namespace A2uiLivewire\Tests\Feature;

/**
 * Renders every official Basic Catalog example with the optional Flux theme.
 */
class FluxThemeRenderExamplesTest extends RenderExamplesTest
{
    protected string $theme = 'flux';

    public function test_flux_theme_is_active(): void
    {
        $this->assertSame('flux', $this->a2ui()->theme());
        $this->assertStringContainsString('a2ui::themes.flux.', $this->a2ui()->catalogs()->get('basic')->component('Button')->view);
    }
}
