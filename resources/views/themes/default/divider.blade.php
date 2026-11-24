@php
    $axis = $node->enum('axis', ['horizontal', 'vertical'], 'horizontal');
@endphp
<div {{ $node->attributes([
    'class' => 'a2ui-divider',
    'role' => 'separator',
    'aria-orientation' => $axis,
    'data-axis' => $axis,
]) }}></div>
