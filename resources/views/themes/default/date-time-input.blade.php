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
        'class' => 'a2ui-input',
        'min' => $min !== '' ? $min : null,
        'max' => $max !== '' ? $max : null,
    ]));
@endphp
<div {{ $node->attributes(['class' => 'a2ui-field'], withAria: false) }}>
    @if ($label !== '')
        <label class="a2ui-label" for="{{ $node->domId('input') }}">{{ $label }}</label>
    @endif
    <input {{ $input }}>
    @if ($error)
        <p class="a2ui-error" role="alert">{{ $error }}</p>
    @endif
</div>
