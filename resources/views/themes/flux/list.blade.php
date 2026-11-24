@php
    $horizontal = $node->enum('direction', ['vertical', 'horizontal'], 'vertical') === 'horizontal';

    $align = [
        'start' => 'items-start',
        'center' => 'items-center',
        'end' => 'items-end',
        'stretch' => 'items-stretch',
    ][$node->enum('align', ['start', 'center', 'end', 'stretch'], 'stretch')];

    $classes = $horizontal
        ? "a2ui-list a2ui-list-horizontal flex w-full min-w-0 flex-row gap-3 overflow-x-auto pb-1 [&>*]:shrink-0 [&>*]:max-w-xs {$align}"
        : "a2ui-list a2ui-list-vertical flex w-full min-w-0 flex-col gap-3 overflow-y-auto {$align}";
@endphp
<div {{ $node->attributes(['class' => $classes, 'role' => 'list']) }}>
    @foreach ($node->children() as $child)
        <div role="listitem" class="a2ui-list-item flex min-w-0 [&>*]:w-full" wire:key="{{ $node->key() }}:{{ $child['key'] }}">
            {{ $child['html'] }}
        </div>
    @endforeach
</div>
