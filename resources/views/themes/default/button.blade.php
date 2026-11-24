@php
    // A failing @check disables the button (spec: "Example: button validation").
    $valid = $node->isValid();
    $reason = $valid ? null : ($node->failedChecks()[0]['message'] ?? null);
@endphp
<button type="button" {{ $node->attributes(array_filter([
    'class' => 'a2ui-btn',
    'data-variant' => $node->enum('variant', ['default', 'primary', 'borderless'], 'default'),
    'wire:click' => $node->actionCall('action'),
    'title' => $reason,
    'data-a2ui-action' => $node->actionName(),
], fn ($value) => $value !== null)) }} @disabled(! $valid)>
    {{ $node->child('child') }}
</button>
