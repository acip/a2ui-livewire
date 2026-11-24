@php
    $variant = $node->enum('variant', ['longText', 'number', 'shortText', 'obscured'], 'shortText');
    $label = $node->string('label');
    $placeholder = $node->string('placeholder');
    $error = $node->validationMessage('value');

    $input = $node->inputAttributes('value', $variant === 'number' ? 'number' : 'text', array_filter([
        'id' => $node->domId('input'),
        'placeholder' => $placeholder !== '' ? $placeholder : null,
        'aria-describedby' => $error ? $node->domId('error') : null,
    ]));
    $attributes = $input;
@endphp
<div {{ $node->attributes(['class' => 'a2ui-text-field w-full min-w-0'], withAria: false) }}>
    <flux:field>
        @if ($label !== '')
            <flux:label for="{{ $node->domId('input') }}">{{ $label }}</flux:label>
        @endif

        @if ($variant === 'longText')
            <flux:textarea {{ $attributes }} rows="3" :invalid="(bool) $error" />
        @elseif ($variant === 'obscured')
            <flux:input {{ $attributes }} type="password" viewable :invalid="(bool) $error" />
        @elseif ($variant === 'number')
            <flux:input {{ $attributes }} type="number" step="any" inputmode="decimal" :invalid="(bool) $error" />
        @else
            <flux:input {{ $attributes }} type="text" :invalid="(bool) $error" />
        @endif

        @if ($error)
            <flux:error id="{{ $node->domId('error') }}" :message="$error" />
        @endif
    </flux:field>
</div>
