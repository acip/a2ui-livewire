<div {{ $node->attributes([
    'class' => 'a2ui-column',
    'data-justify' => $node->enum('justify', ['start', 'center', 'end', 'spaceBetween', 'spaceAround', 'spaceEvenly', 'stretch'], 'start'),
    'data-align' => $node->enum('align', ['center', 'end', 'start', 'stretch'], 'stretch'),
]) }}>
    @foreach ($node->children() as $child)
        {{ $child['html'] }}
    @endforeach
</div>
