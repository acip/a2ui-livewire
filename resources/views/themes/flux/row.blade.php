@php
    $justify = [
        'start' => 'justify-start',
        'center' => 'justify-center',
        'end' => 'justify-end',
        'spaceBetween' => 'justify-between',
        'spaceAround' => 'justify-around',
        'spaceEvenly' => 'justify-evenly',
        'stretch' => 'justify-start [&>*]:flex-1',
    ][$node->enum('justify', ['center', 'end', 'spaceAround', 'spaceBetween', 'spaceEvenly', 'start', 'stretch'], 'start')];

    $align = [
        'start' => 'items-start',
        'center' => 'items-center',
        'end' => 'items-end',
        'stretch' => 'items-stretch',
    ][$node->enum('align', ['start', 'center', 'end', 'stretch'], 'stretch')];
@endphp
<div {{ $node->attributes(['class' => "a2ui-row flex w-full min-w-0 flex-row gap-3 {$justify} {$align}"]) }}>
    @foreach ($node->children() as $child)
        {{ $child['html'] }}
    @endforeach
</div>
