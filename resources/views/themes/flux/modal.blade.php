@php
    $name = $node->domId('modal');
@endphp
<div {{ $node->attributes(['class' => 'a2ui-modal contents']) }}>
    <flux:modal.trigger :name="$name">
        {{ $node->child('trigger') }}
    </flux:modal.trigger>

    <flux:modal :name="$name" class="a2ui-modal-content w-full md:w-[32rem]">
        <div class="pt-2">
            {{ $node->child('content') }}
        </div>
    </flux:modal>
</div>
