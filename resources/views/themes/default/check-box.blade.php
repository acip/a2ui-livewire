@php
    $error = $node->validationMessage('value');
    $input = $node->inputAttributes('value', 'bool', ['type' => 'checkbox', 'id' => $node->domId('input')]);
@endphp
<div {{ $node->attributes(['class' => 'a2ui-field a2ui-field-check'], withAria: false) }}>
    <label class="a2ui-check" for="{{ $node->domId('input') }}">
        <input {{ $input }}>
        <span>{{ $node->string('label') }}</span>
    </label>
    @if ($error)
        <p class="a2ui-error" role="alert">{{ $error }}</p>
    @endif
</div>
