@php
    $label = $node->string('label');
    $error = $node->validationMessage('value');
    $input = $node->inputAttributes('value', 'bool', ['id' => $node->domId('input')]);
    $attributes = $input;
@endphp
<div {{ $node->attributes(['class' => 'a2ui-check-box w-full min-w-0'], withAria: false) }}>
    <flux:field variant="inline">
        <flux:checkbox {{ $attributes }} />
        <flux:label for="{{ $node->domId('input') }}">{{ $label }}</flux:label>
        @if ($error)
            <flux:error :message="$error" />
        @endif
    </flux:field>
</div>
