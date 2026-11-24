@php
    $text = $node->string('text');
    $caption = $node->enum('variant', ['caption', 'body'], 'body') === 'caption';
    // Inside a button the text inherits the button's colour and stays inline.
    $inline = $node->isInside('Button') || $node->parentType() === 'Button';
@endphp
@if ($inline)
    <span {{ $node->attributes(['class' => 'a2ui-text a2ui-markdown-inline']) }}>{{ $node->markdown($text, inline: true) }}</span>
@else
    <div {{ $node->attributes(['class' => 'a2ui-text a2ui-markdown min-w-0 break-words '.($caption ? 'a2ui-caption text-sm text-zinc-500 dark:text-zinc-400' : 'text-[0.9375rem] leading-relaxed text-zinc-800 dark:text-zinc-100')]) }}>{{ $node->markdown($text) }}</div>
@endif
