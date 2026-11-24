@php
    $dates = \A2uiLivewire\Rendering\DateTimeValues::class;

    $kind = $dates::kind($node->raw('enableDate') === true, $node->raw('enableTime') === true);
    $label = $node->string('label');
    $error = $node->validationMessage('value');
    $min = $node->has('min') ? $dates::toInput($node->string('min'), $kind) : '';
    $max = $node->has('max') ? $dates::toInput($node->string('max'), $kind) : '';

    $input = $node->inputAttributes('value', $kind, array_filter([
        'id' => $node->domId('input'),
        'type' => $dates::inputType($kind),
        'min' => $min !== '' ? $min : null,
        'max' => $max !== '' ? $max : null,
    ]));
    $attributes = $input;
@endphp
<div {{ $node->attributes(['class' => 'a2ui-date-time-input w-full min-w-0'], withAria: false) }}>
    <flux:field>
        @if ($label !== '')
            <flux:label for="{{ $node->domId('input') }}">{{ $label }}</flux:label>
        @endif
        <flux:input {{ $attributes }} :invalid="(bool) $error" />
        @if ($error)
            <flux:error :message="$error" />
        @endif
    </flux:field>
</div>
