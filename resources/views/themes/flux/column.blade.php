@php
    $justify = [
        'start' => 'justify-start',
        'center' => 'justify-center',
        'end' => 'justify-end',
        'spaceBetween' => 'justify-between',
        'spaceAround' => 'justify-around',
        'spaceEvenly' => 'justify-evenly',
        'stretch' => 'justify-start [&>*]:flex-1',
    ][$node->enum('justify', ['start', 'center', 'end', 'spaceBetween', 'spaceAround', 'spaceEvenly', 'stretch'], 'start')];

    $align = [
        'start' => 'items-start',
        'center' => 'items-center',
        'end' => 'items-end',
        'stretch' => 'items-stretch',
    ][$node->enum('align', ['center', 'end', 'start', 'stretch'], 'stretch')];
@endphp
<div {{ $node->attributes(['class' => "a2ui-column flex w-full min-w-0 flex-col gap-3 {$justify} {$align}"]) }}>
    @foreach ($node->children() as $child)
        {{ $child['html'] }}
    @endforeach
</div>
