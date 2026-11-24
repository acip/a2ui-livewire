{{-- Custom A2UI component: ChatBubble (demo chat catalog). --}}
@php
    $mine = $node->enum('from', ['user', 'agent'], 'agent') === 'user';
@endphp
<div {{ $node->attributes(['class' => 'flex w-full '.($mine ? 'justify-end' : 'justify-start')]) }}>
    <div @class([
        'max-w-[80%] rounded-2xl px-4 py-2 text-sm',
        'rounded-br-sm bg-accent text-accent-foreground' => $mine,
        'rounded-bl-sm bg-zinc-100 text-zinc-800 dark:bg-white/10 dark:text-zinc-100' => ! $mine,
    ])>
        {{ $node->markdown($node->string('text'), inline: true) }}
    </div>
</div>
