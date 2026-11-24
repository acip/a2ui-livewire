@php
    $text = $node->string('text');
    $caption = $node->enum('variant', ['caption', 'body'], 'body') === 'caption';
    // Inside a button the text inherits the button's colour and stays inline.
    $inline = $node->isInside('Button') || $node->parentType() === 'Button';
@endphp
@if ($inline)
    <span {{ $node->attributes(['class' => 'a2ui-text-inline']) }}>{{ $node->markdown($text, inline: true) }}</span>
@else
    <div {{ $node->attributes(['class' => 'a2ui-text a2ui-markdown'.($caption ? ' a2ui-caption' : '')]) }}>{{ $node->markdown($text) }}</div>
@endif
