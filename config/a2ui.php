<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default agent transport
    |--------------------------------------------------------------------------
    |
    | The transport a <livewire:a2ui> renderer uses to send actions, function
    | calls and prompts to the agent when none is given explicitly. "null"
    | only raises events (handle them in your app); see `transports` below.
    |
    */

    'default_transport' => env('A2UI_TRANSPORT', 'null'),

    'transports' => [

        'null' => ['driver' => 'null'],

        // Plain HTTP: POST {"messages", "text", "metadata", "session"} and
        // read JSON / JSONL / SSE envelopes back.
        'http' => [
            'driver' => 'http',
            'url' => env('A2UI_AGENT_URL'),
            'headers' => [],
            'timeout' => 60,
            'stream' => false,
        ],

        // A2A JSON-RPC with the A2UI v1.0 extension.
        'a2a' => [
            'driver' => 'a2a',
            'url' => env('A2UI_A2A_URL'),
            'headers' => [],
            'timeout' => 120,
            'stream' => env('A2UI_A2A_STREAM', false),
            // "per_message" (what the official SDKs send) or "list".
            'data_parts' => 'per_message',
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Theme
    |--------------------------------------------------------------------------
    |
    | "default" renders the Basic Catalog with plain Blade views styled by
    | resources/css/a2ui.css (no Tailwind or Flux needed). "flux" renders it
    | with Flux components and requires livewire/flux in your application.
    |
    */

    'theme' => env('A2UI_THEME', 'default'),

    /*
    |--------------------------------------------------------------------------
    | Catalogs
    |--------------------------------------------------------------------------
    |
    | The Basic Catalog is registered by default. Override how a component is
    | rendered (in any theme) by mapping it to your own Blade view, e.g.
    | 'Button' => 'components.a2ui.button'.
    |
    */

    'basic_catalog' => [
        'enabled' => true,
        'views' => [],
    ],

    /*
    |--------------------------------------------------------------------------
    | Validation
    |--------------------------------------------------------------------------
    |
    | In strict mode component property problems (unknown types, missing
    | required properties, bad enum values, non-UAX #31 ids, unknown keys)
    | reject the whole message. In lenient mode they are reported and the
    | renderer still does its best.
    |
    */

    'strict' => env('A2UI_STRICT', false),

    /*
    |--------------------------------------------------------------------------
    | Input synchronisation
    |--------------------------------------------------------------------------
    |
    | "live"     - text inputs sync after a debounce, toggles immediately, so
    |              other components bound to the same path update as you type.
    | "blur"     - text inputs sync when they lose focus.
    | "deferred" - values sync with the next action (fewest requests).
    |
    */

    'input_sync' => env('A2UI_INPUT_SYNC', 'live'),

    'debounce' => 300,

    /*
    |--------------------------------------------------------------------------
    | Behaviour
    |--------------------------------------------------------------------------
    */

    // Locale for formatNumber / formatCurrency / formatDate / pluralize.
    // Null uses the application locale.
    'locale' => null,

    // URL schemes the openUrl function may open.
    'open_url_schemes' => ['http', 'https'],

    // Send renderer errors (validation, expression failures) to the agent.
    'report_errors_to_agent' => true,

    // Maximum renderer<->agent exchanges triggered by one user interaction
    // (function call round trips).
    'max_round_trips' => 5,

    // Push rendered HTML to the browser while a streaming transport is still
    // receiving messages.
    'progressive_rendering' => true,

    // Cache store used for message channels (A2ui::channel()->push()).
    'channel_store' => env('A2UI_CHANNEL_STORE'),

    'channel_ttl' => 3600,

    // Show an outline and type name for unknown components.
    'debug' => env('A2UI_DEBUG', env('APP_DEBUG', false)),

];
