@php
    $values = \A2uiLivewire\Support\Values::class;

    $min = $node->has('min') ? ($node->number('min') ?? 0) : 0;
    $max = $node->number('max') ?? 100;
    if ($max < $min) {
        [$min, $max] = [$max, $min];
    }
    // `steps` is the number of intervals between min and max.
    $steps = $node->raw('steps');
    $step = is_int($steps) && $steps >= 1 && $max > $min ? $values::toString(($max - $min) / $steps) : 'any';
    $display = $values::toString($node->number('value') ?? $min);
    $label = $node->string('label');
    $error = $node->validationMessage('value');

    $input = $node->inputAttributes('value', 'slider', [
        'id' => $node->domId('input'),
        'type' => 'range',
        'class' => 'a2ui-range',
        'min' => $values::toString($min),
        'max' => $values::toString($max),
        'step' => $step,
        'x-on:input' => 'v = $event.target.value',
    ]);
@endphp
<div {{ $node->attributes(['class' => 'a2ui-field'], withAria: false) }} x-data="{ v: {{ \Illuminate\Support\Js::from($display) }} }">
    <div class="a2ui-field-header">
        @if ($label !== '')
            <label class="a2ui-label" for="{{ $node->domId('input') }}">{{ $label }}</label>
        @endif
        <span class="a2ui-value" x-text="v" aria-hidden="true">{{ $display }}</span>
    </div>
    <input {{ $input }}>
    @if ($error)
        <p class="a2ui-error" role="alert">{{ $error }}</p>
    @endif
</div>
