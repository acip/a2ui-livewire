<?php

namespace A2uiLivewire\Catalog\Basic;

use A2uiLivewire\Catalog\Catalog;
use A2uiLivewire\Support\Json;

/**
 * The A2UI v1.0 Basic Catalog, rendered with Flux UI Blade views.
 */
final class BasicCatalog
{
    public const ID = 'https://a2ui.org/specification/v1_0/catalogs/basic/catalog.json';

    public const COMPONENTS = [
        'Text', 'Image', 'Icon', 'Video', 'AudioPlayer', 'Row', 'Column', 'List', 'Card',
        'Tabs', 'Modal', 'Divider', 'Button', 'TextField', 'CheckBox', 'ChoicePicker',
        'Slider', 'DateTimeInput',
    ];

    /**
     * @param  array<string, string>  $viewOverrides  component name => Blade view
     */
    public static function make(array $viewOverrides = [], string $viewPrefix = 'a2ui::components.basic.'): Catalog
    {
        $definition = Json::decode((string) file_get_contents(self::definitionPath()));

        $views = [];
        foreach (self::COMPONENTS as $name) {
            $views[$name] = $viewOverrides[$name] ?? $viewPrefix.self::viewName($name);
        }

        return BasicFunctions::register(Catalog::fromDefinition($definition, $views));
    }

    public static function definitionPath(): string
    {
        return dirname(__DIR__, 3).'/resources/catalogs/basic_catalog.json';
    }

    /**
     * `ChoicePicker` → `choice-picker`.
     */
    public static function viewName(string $component): string
    {
        return strtolower((string) preg_replace('/(?<!^)[A-Z]/', '-$0', $component));
    }
}
