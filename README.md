# A2UI renderer for Laravel Livewire

[![tests](https://github.com/acip/a2ui-livewire/actions/workflows/tests.yml/badge.svg)](https://github.com/acip/a2ui-livewire/actions/workflows/tests.yml)

**Protocol support: A2UI v1.0** (Basic Catalog v1). Earlier versions (v0.8, v0.9, v0.9.1) are not supported.

Render agent-generated user interfaces in Laravel. This package is an
[A2UI](https://a2ui.org) **v1.0** renderer: agents stream A2UI messages, and a
Livewire component turns them into a live UI. It needs no front-end build step
and no UI library; an optional theme renders with [Flux](https://fluxui.dev) instead.

```blade
<livewire:a2ui :messages="$messages" transport="a2a" />
```

- **Full v1.0 protocol**: `createSurface`, `updateComponents`, `updateDataModel`,
  `deleteSurface`, `callRendererFunction` and `agentFunctionResponse` in;
  `action`, `callAgentFunction`, `rendererFunctionResponse` and `error` out.
- **The whole Basic Catalog**: all 18 components and all 14 functions, plus `@index`.
- **Data binding**: JSON Pointer paths, relative paths in list templates,
  two-way input binding, `@checks` validation and `@accessibility` mapped to ARIA.
- **Transports**: plain HTTP (JSON, JSONL or SSE), A2A JSON-RPC with the A2UI
  extension, in-process callbacks, and server-side channels for queued jobs.
  Streamed replies render progressively.
- **Two themes**: a dependency-free default theme (plain Blade and one CSS
  file, with dark mode, themeable through CSS variables) and an optional Flux theme.
- **Custom catalogs**: add components with your own Blade views, add functions
  in PHP, declare agent-side functions, and mix several catalogs in one surface.
- **Secure by default**: state lives server-side in the Livewire snapshot.
  Input and action targets are locked. Media and `openUrl` URLs are sanitized.
  Markdown is safe. The regex dialect has ReDoS guards.
- **Tested against the spec**: the official A2UI conformance suites (expressions,
  functions, data model, validation) run in CI, and all 43 official
  examples render with no errors.

## Requirements

PHP 8.2+, Laravel 11–13 and Livewire 3.7+ or 4. Install `ext-intl` for
locale-aware number, currency and plural formatting.

## Installation

```bash
composer require acip/a2ui-livewire
php artisan vendor:publish --tag=a2ui-config   # optional
```

Load the stylesheet once, in your layout's `<head>`:

```blade
@a2uiStyles
```

Or import it into your own CSS build instead:

```css
@import '../../vendor/acip/a2ui-livewire/resources/css/a2ui.css';
```

Tabs, modals and option filtering use Alpine.js, which Livewire already ships.

## Themes

| Theme | How to select it | Needs |
|---|---|---|
| `default` | (the default) | Nothing: plain Blade views styled by `a2ui.css`. |
| `flux` | `A2UI_THEME=flux` | [Flux](https://fluxui.dev) 2 in your app (`composer require livewire/flux`) and a Tailwind v4 build. |

The default theme takes its colours from CSS custom properties
(`--a2ui-primary`, `--a2ui-text`, `--a2ui-border`, `--a2ui-radius` and others).
When your app defines Tailwind v4 or Flux theme variables (`--color-accent`,
`--color-zinc-*`), the theme uses them automatically. Dark mode follows a
`.dark` class on an ancestor (the Tailwind and Flux convention) or
`data-a2ui-theme="dark"` on the renderer.

The Flux theme uses only Flux components that have no Pro marker in Flux's docs.
Flux is not bundled with this package and has its own license terms, so check
them for your use. To use the theme, add its views to your Tailwind sources:

```css
@source '../../vendor/acip/a2ui-livewire/resources/views/themes/flux/**/*.blade.php';
```

## Usage

### Render messages you already have

```blade
<livewire:a2ui :messages="$messages" />
```

`messages` can be a PHP array of envelopes, a JSON array, JSONL, an SSE body,
or LLM output wrapped in a Markdown code fence.

### Talk to an agent

Configure a transport in `config/a2ui.php`, or with env vars:

```dotenv
A2UI_TRANSPORT=a2a
A2UI_A2A_URL=https://agent.example.com/a2a
```

```blade
<livewire:a2ui transport="a2a" prompt="Show my open invoices" />
```

| Driver     | Wire format |
|------------|-------------|
| `http`     | POSTs `{"messages", "text", "metadata", "session"}` and accepts a JSON list, `{"messages": [...]}`, JSONL or SSE. Set `stream: true` to render while the reply is still arriving. |
| `a2a`      | JSON-RPC `message/send` (or `message/stream`) with `X-A2A-Extensions: https://a2ui.org/a2a-extension/a2ui/v1.0`. A2UI messages go in `DataPart`s with `mimeType: application/a2ui+json`. `contextId` and `taskId` are kept between turns. |
| `callback` | A PHP closure (an in-process agent). |
| `null`     | No agent: actions are only raised as events. |

Every request carries `a2uiRendererCapabilities` in its metadata. It also
carries `a2uiRendererDataModel` for surfaces created with `sendDataModel: true`.

An in-process agent is just a closure:

```php
use A2uiLivewire\Facades\A2ui;
use A2uiLivewire\Transport\AgentRequest;

A2ui::extendTransport('support-bot', function (AgentRequest $request) {
    $action = $request->action();   // the user's action, if any
    $prompt = $request->text;       // free-text prompt, if any

    return [/* agent-to-renderer envelopes */];   // or a Generator to stream
});
```

### Send prompts from your own chat input

```blade
<livewire:a2ui transport="support-bot" />

<form x-data="{ text: '' }" x-on:submit.prevent="$dispatch('a2ui-prompt', { text }); text = ''">
    <input x-model="text" placeholder="Ask something…">
</form>
```

### Push messages from anywhere (queues, webhooks)

Channels are trusted, server-side mailboxes. A queued job that streams an LLM
response can push messages, and the renderer picks them up:

```php
A2ui::channel("chat.{$chat->id}")->push($messages);
```

```blade
<livewire:a2ui :channel="'chat.'.$chat->id" poll="750ms" />
```

You can also skip polling and dispatch `a2ui-sync` with the channel name.

### React to actions in your app

```php
use A2uiLivewire\Events\ActionDispatched;

Event::listen(function (ActionDispatched $event) {
    $event->action();     // ['name' => ..., 'surfaceId' => ..., 'context' => [...]]
    $event->dataModel;    // a2uiRendererDataModel, when sendDataModel is on
});
```

The component also dispatches these browser and Livewire events:
`a2ui-action`, `a2ui-error`, `a2ui-agent-text`, `a2ui-surface-created`,
`a2ui-surface-deleted` and `a2ui-open-url`. With `:inspect="true"` it also
dispatches `a2ui-inspect`, which carries the data models (handy for devtools).

### Component options

| Prop | Purpose |
|------|---------|
| `messages` | Initial messages (trusted). |
| `transport` | Transport name (default: `a2ui.default_transport`). |
| `prompt` | Prompt sent right after the page loads. |
| `surface` | Render only this surface id. |
| `channel`, `poll` | Pull messages from a server-side channel. |
| `accept-client-messages` | Accept `a2ui-messages` events from the browser. Off by default; these messages can never call renderer functions. |
| `empty-text` | Text shown before any surface exists. |
| `inspect` | Emit `a2ui-inspect` after each render. |

## Custom components and functions

```php
use A2uiLivewire\Catalog\Basic\BasicCatalog;
use A2uiLivewire\Catalog\Catalog;
use A2uiLivewire\Catalog\FunctionDefinition;
use A2uiLivewire\Facades\A2ui;

// Add a component (and its Blade view) to the Basic Catalog...
A2ui::extendCatalog(BasicCatalog::ID, fn (Catalog $catalog) => $catalog
    ->withComponent('Rating', 'components.a2ui.rating', $jsonSchema)
    ->withFunction('slugify', fn (array $args) => Str::slug($args['value']), returnType: 'string')
    ->withFunction('cartTotal', fn ($args, $ctx) => Cart::total(), allowedCallers: FunctionDefinition::RENDERER_OR_AGENT)
    ->withAgentFunction('checkStock'));   // evaluated by the agent through callAgentFunction

// ...or register a whole catalog (a definition array, a JSON file, or a Catalog object).
A2ui::registerCatalog(base_path('a2ui/my_catalog.json'), views: ['Chart' => 'a2ui.chart']);
```

A component view receives `$node`, a `ComponentNode` with helpers for
everything a renderer needs:

```blade
{{-- resources/views/components/a2ui/rating.blade.php --}}
<div {{ $node->attributes(['class' => 'flex gap-1']) }}>
    @for ($i = 1; $i <= $node->number('max', 5); $i++)
        <span aria-hidden="true">{{ $i <= $node->number('value', 0) ? '★' : '☆' }}</span>
    @endfor
</div>
```

The helpers:
- **Values**: `string()`, `number()`, `bool()`, `stringList()`, `value()`, `enum()`.
- **Children**: `child()`, `children()`.
- **Inputs**: `inputAttributes()` for two-way binding.
- **Actions**: `actionCall()` returns a `wire:click` expression.
- **Validation**: `checks()`, `isValid()`, `validationMessage()`.
- **Accessibility**: `aria()`.
- **Content**: `markdown()`, `mediaUrl()`.

To restyle the built-in components, override their views
(`'basic_catalog.views' => ['Button' => 'my.button']`) or publish them with
`--tag=a2ui-views`. The default theme's views are in `resources/views/themes/default`
and the Flux theme's in `resources/views/themes/flux`. For the default theme's
icons, `IconMap::registerSvg('myIcon', '<path d="…"/>')` adds or replaces an icon
(the bundled set is Google's Material icons).

## How it maps to the spec

| A2UI concept | Implementation |
|---|---|
| Surface state | `State\Surface` and `State\SurfaceGroup`, stored in the Livewire snapshot (checksum-protected, `#[Locked]`). |
| Message processing | `Processing\MessageProcessor`: messages are applied in order, an invalid message is reported and skipped, and `createSurface` applies `dataModel` before `components`. |
| Bindings and functions | `Evaluation\DataContext` handles JSON Pointer scopes, `@index` and catalog resolution (explicit `catalogId`, then the surface default, then an error). |
| `formatString` | `Evaluation\ExpressionParser` is a port of the reference parser (depth, length and part limits). |
| `regex` | `Evaluation\RegexDialect` parses the A2UI dialect, applies the 4 safety rules and compiles the AST to PCRE. |
| Two-way binding | Inputs bind to `a2uiInputs.<token>`. The token maps to a locked (surface, pointer). Text syncs after a debounce, toggles sync immediately; set `input_sync: blur` or `deferred` to change this. |
| Checks | Evaluated on every render. A failing check on a `Button` disables it. Field messages appear once the field is touched or an action was attempted. |
| Agent functions | Reactive calls are cached per surface and flushed right after render. Actions always send a new call. |
| Errors | Sent to the agent once each, as `error` messages (`report_errors_to_agent`), and logged in `$a2uiErrors`. |

## Testing

```bash
composer install
vendor/bin/phpunit
```

The suite includes the official A2UI conformance YAML suites
(`tests/Fixtures/conformance`) and renders every example in
`resources/examples`.

## Demo app

`demo/` is a Laravel 13 + Livewire 4 + Tailwind app that uses the package from
this repository with the default theme (it is not part of the Composer package).

```bash
cd demo
composer install && npm install && npm run build
cp .env.example .env && php artisan key:generate && php artisan migrate
php artisan serve
```

- `/` — **Gallery**: all 43 official Basic Catalog examples with a message
  stepper, a live data-model inspector and an action log.
- `/agent` — **Agent playground**. By default it talks to a deterministic PHP
  agent (`app/A2ui/DemoAgent.php`) that needs no API key: a booking form with
  checks, a todo list template, a streamed weather card, an agent-function
  shipping quote and a custom component from a second catalog.

### Try it with a real agent

The official A2UI restaurant agent
([`samples/agent/node/restaurant_finder`](https://github.com/a2ui-project/a2ui/tree/main/samples/agent/node/restaurant_finder))
speaks A2A and v1.0. Start it (`STUB_LLM=true` needs no API key; add
`A2UI_FORMAT=express` for the token-efficient Express format), then set in
`demo/.env`:

```dotenv
PLAYGROUND_TRANSPORT=a2a
A2UI_A2A_URL=http://localhost:10002/a2a/json-rpc
```

With the agent running, `LIVE_AGENT_URL=http://localhost:10002/a2a/json-rpc
php artisan test --filter LiveAgent` (in `demo/`) runs a prompt → list →
"Book Now" → booking form round trip over `message/send` and `message/stream`.

`php artisan serve` handles one request at a time on Windows, so a slow agent
call blocks other pages; serve the gallery and the playground on separate ports
(see `DEMO_GALLERY_URL` / `DEMO_PLAYGROUND_URL`) or use a multi-worker server.

## License

Apache License 2.0 — see [LICENSE](LICENSE) and [NOTICE](NOTICE).

This package includes material from the [A2UI project](https://github.com/a2ui-project/a2ui)
(Copyright 2024 Google LLC, Apache-2.0): the Basic Catalog definition, the
examples and the conformance fixtures unmodified, and four source files ported
from `@a2ui/web_core`. [NOTICE](NOTICE) lists them.
