@php
    $min = $node->has('min') ? ($node->number('min') ?? 0) : 0;
    $max = $node->number('max') ?? 100;
    if ($max < $min) {
        [$min, $max] = [$max, $min];
    }
    $steps = $node->raw('steps');
    $step = is_int($steps) && $steps >= 1 && $max > $min ? ($max - $min) / $steps : 'any';
    $value = $node->number('value') ?? $min;
    $label = $node->string('label');
    $error = $node->validationMessage('value');
    $display = \A2uiLivewire\Support\Values::toString($value);

    $input = $node->inputAttributes('value', 'slider', [
        'id' => $node->domId('input'),
        'type' => 'range',
        'min' => \A2uiLivewire\Support\Values::toString($min),
        'max' => \A2uiLivewire\Support\Values::toString($max),
        'step' => is_string($step) ? $step : \A2uiLivewire\Support\Values::toString($step),
        'x-on:input' => 'v = $event.target.value',
        'class' => 'a2ui-slider-input h-2 w-full cursor-pointer appearance-auto accent-[var(--color-accent,#27272a)]',
    ]);
@endphp
<div {{ $node->attributes(['class' => 'a2ui-slider w-full min-w-0'], withAria: false) }} x-data="{ v: {{ \Illuminate\Support\Js::from($display) }} }">
    <flux:field>
        <div class="flex items-center justify-between gap-3">
            @if ($label !== '')
                <flux:label for="{{ $node->domId('input') }}">{{ $label }}</flux:label>
            @endif
            <span class="text-sm tabular-nums text-zinc-500 dark:text-zinc-400" x-text="v" aria-hidden="true">{{ $display }}</span>
        </div>
        <input {{ $input }}>
        @if ($error)
            <flux:error :message="$error" />
        @endif
    </flux:field>
</div>
