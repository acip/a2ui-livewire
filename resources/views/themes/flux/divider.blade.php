@php
    $vertical = $node->enum('axis', ['horizontal', 'vertical'], 'horizontal') === 'vertical';
    $attributes = $node->attributes(['class' => $vertical ? 'a2ui-divider self-stretch !h-auto min-h-6' : 'a2ui-divider my-1']);
@endphp
@if ($vertical)
    <flux:separator vertical {{ $attributes }} />
@else
    <flux:separator {{ $attributes }} />
@endif
