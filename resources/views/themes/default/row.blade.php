<div {{ $node->attributes([
    'class' => 'a2ui-row',
    'data-justify' => $node->enum('justify', ['center', 'end', 'spaceAround', 'spaceBetween', 'spaceEvenly', 'start', 'stretch'], 'start'),
    'data-align' => $node->enum('align', ['start', 'center', 'end', 'stretch'], 'stretch'),
]) }}>
    @foreach ($node->children() as $child)
        {{ $child['html'] }}
    @endforeach
</div>
