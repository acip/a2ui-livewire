<?php

namespace A2uiLivewire\Rendering;

/**
 * Icon lookups for the Basic Catalog `Icon` component.
 *
 * - The default theme uses bundled Material icons (resources/icons/material.php).
 * - The Flux theme maps names to Flux (Heroicons) icons via {@see self::resolve()}.
 */
final class IconMap
{
    /** @var array<string, string>|null A2UI name => inner SVG markup */
    private static ?array $material = null;

    /** @var array<string, string> custom A2UI name => inner SVG markup */
    private static array $customSvg = [];

    /**
     * Inner SVG markup (24x24 viewBox) for an A2UI icon name, or null.
     */
    public static function material(string $name): ?string
    {
        self::$material ??= require dirname(__DIR__, 2).'/resources/icons/material.php';

        return self::$customSvg[$name] ?? self::$material[$name] ?? null;
    }

    /**
     * Registers (or overrides) an icon for the default theme. `$svg` is the
     * inner markup of a 24x24 SVG and must be trusted (it is output as is).
     */
    public static function registerSvg(string $name, string $svg): void
    {
        self::$customSvg[$name] = $svg;
    }

    /** @var array<string, array{0: string, 1: string}> A2UI name => [flux icon, variant] */
    public const ICONS = [
        'accountCircle' => ['user-circle', 'outline'],
        'add' => ['plus', 'outline'],
        'arrowBack' => ['arrow-left', 'outline'],
        'arrowForward' => ['arrow-right', 'outline'],
        'attachFile' => ['paper-clip', 'outline'],
        'calendarToday' => ['calendar', 'outline'],
        'call' => ['phone', 'outline'],
        'camera' => ['camera', 'outline'],
        'check' => ['check', 'outline'],
        'close' => ['x-mark', 'outline'],
        'delete' => ['trash', 'outline'],
        'download' => ['arrow-down-tray', 'outline'],
        'edit' => ['pencil', 'outline'],
        'event' => ['calendar-days', 'outline'],
        'error' => ['exclamation-circle', 'outline'],
        'fastForward' => ['forward', 'solid'],
        'favorite' => ['heart', 'solid'],
        'favoriteOff' => ['heart', 'outline'],
        'folder' => ['folder', 'outline'],
        'help' => ['question-mark-circle', 'outline'],
        'home' => ['home', 'outline'],
        'info' => ['information-circle', 'outline'],
        'locationOn' => ['map-pin', 'outline'],
        'lock' => ['lock-closed', 'outline'],
        'lockOpen' => ['lock-open', 'outline'],
        'mail' => ['envelope', 'outline'],
        'menu' => ['bars-3', 'outline'],
        'moreVert' => ['ellipsis-vertical', 'outline'],
        'moreHoriz' => ['ellipsis-horizontal', 'outline'],
        'notificationsOff' => ['bell-slash', 'outline'],
        'notifications' => ['bell', 'outline'],
        'pause' => ['pause', 'solid'],
        'payment' => ['credit-card', 'outline'],
        'person' => ['user', 'outline'],
        'phone' => ['phone', 'outline'],
        'photo' => ['photo', 'outline'],
        'play' => ['play', 'solid'],
        'print' => ['printer', 'outline'],
        'refresh' => ['arrow-path', 'outline'],
        'rewind' => ['backward', 'solid'],
        'search' => ['magnifying-glass', 'outline'],
        'send' => ['paper-airplane', 'outline'],
        'settings' => ['cog-6-tooth', 'outline'],
        'share' => ['share', 'outline'],
        'shoppingCart' => ['shopping-cart', 'outline'],
        'skipNext' => ['forward', 'outline'],
        'skipPrevious' => ['backward', 'outline'],
        'star' => ['star', 'solid'],
        'starHalf' => ['star', 'mini'],
        'starOff' => ['star', 'outline'],
        'stop' => ['stop', 'solid'],
        'upload' => ['arrow-up-tray', 'outline'],
        'visibility' => ['eye', 'outline'],
        'visibilityOff' => ['eye-slash', 'outline'],
        'volumeDown' => ['speaker-wave', 'outline'],
        'volumeMute' => ['speaker-x-mark', 'outline'],
        'volumeOff' => ['speaker-x-mark', 'outline'],
        'volumeUp' => ['speaker-wave', 'solid'],
        'warning' => ['exclamation-triangle', 'outline'],
    ];

    /** @var array<string, array{0: string, 1: string}> */
    private static array $custom = [];

    /**
     * @return array{name: string, variant: string}|null
     */
    public static function resolve(string $name): ?array
    {
        $entry = self::$custom[$name] ?? self::ICONS[$name] ?? null;

        if ($entry === null) {
            // Allow agents (or custom catalogs) to name Flux icons directly.
            if (preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $name)) {
                return ['name' => $name, 'variant' => 'outline'];
            }

            $kebab = strtolower((string) preg_replace('/(?<!^)[A-Z]/', '-$0', $name));

            return preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $kebab) ? ['name' => $kebab, 'variant' => 'outline'] : null;
        }

        return ['name' => $entry[0], 'variant' => $entry[1]];
    }

    public static function register(string $name, string $fluxIcon, string $variant = 'outline'): void
    {
        self::$custom[$name] = [$fluxIcon, $variant];
    }

    /**
     * SVG path data may only contain path commands and numbers.
     */
    public static function isSafeSvgPath(string $path): bool
    {
        return $path !== '' && strlen($path) <= 20_000 && (bool) preg_match('/^[MmLlHhVvCcSsQqTtAaZz0-9eE\s,.+\-]+$/', $path);
    }
}
