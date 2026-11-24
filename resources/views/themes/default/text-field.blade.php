@php
    $variant = $node->enum('variant', ['longText', 'number', 'shortText', 'obscured'], 'shortText');
    $label = $node->string('label');
    $placeholder = $node->string('placeholder');
    $error = $node->validationMessage('value');

    $input = $node->inputAttributes('value', $variant === 'number' ? 'number' : 'text', array_filter([
        'id' => $node->domId('input'),
        'class' => 'a2ui-input',
        'placeholder' => $placeholder !== '' ? $placeholder : null,
        'aria-describedby' => $error ? $node->domId('error') : null,
        'type' => match ($variant) {
            'obscured' => 'password',
            'number' => 'number',
            'longText' => null,
            default => 'text',
        },
        'step' => $variant === 'number' ? 'any' : null,
        'inputmode' => $variant === 'number' ? 'decimal' : null,
        'autocomplete' => $variant === 'obscured' ? 'current-password' : null,
    ], fn ($value) => $value !== null));
@endphp
<div {{ $node->attributes(['class' => 'a2ui-field'], withAria: false) }}>
    @if ($label !== '')
        <label class="a2ui-label" for="{{ $node->domId('input') }}">{{ $label }}</label>
    @endif

    @if ($variant === 'longText')
        <textarea {{ $input }} rows="3"></textarea>
    @else
        <input {{ $input }}>
    @endif

    @if ($error)
        <p class="a2ui-error" id="{{ $node->domId('error') }}" role="alert">{{ $error }}</p>
    @endif
</div>
