@php
    // Blade only spreads a bag into a component tag when it is named $attributes.
    $attributes = $node->attributes(['class' => 'a2ui-card w-full min-w-0']);
@endphp
<flux:card {{ $attributes }}>
    {{ $node->child('child') }}
</flux:card>
