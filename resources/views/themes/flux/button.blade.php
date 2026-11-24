@php
    $call = $node->actionCall('action');
    $variant = [
        'default' => 'outline',
        'primary' => 'primary',
        'borderless' => 'ghost',
    ][$node->enum('variant', ['default', 'primary', 'borderless'], 'default')];

    // A failing @check disables the button (spec: "Example: button validation").
    $valid = $node->isValid();
    $reason = $valid ? null : ($node->failedChecks()[0]['message'] ?? null);

    $attributes = $node->attributes(array_filter([
        'class' => 'a2ui-button',
        'wire:click' => $call,
        'title' => $reason,
        'data-a2ui-action' => $node->actionName(),
    ], fn ($value) => $value !== null));
@endphp
<flux:button {{ $attributes }} :variant="$variant" :disabled="! $valid">
    {{ $node->child('child') }}
</flux:button>
