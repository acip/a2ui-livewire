@php
    $icon = $node->icon('name');
    $flux = $icon['type'] === 'named' ? \A2uiLivewire\Rendering\IconMap::resolve($icon['name']) : null;
    $labelled = $node->accessibleLabel('') !== '';
    $a11y = $labelled ? ['role' => 'img'] : ['aria-hidden' => 'true'];
@endphp
@if ($flux !== null)
    <span {{ $node->attributes(['class' => 'a2ui-icon inline-flex shrink-0 items-center justify-center', ...$a11y]) }}>
        <flux:icon :name="$flux['name']" :variant="$flux['variant']" class="size-6" />
    </span>
@elseif ($icon['type'] === 'svg')
    <span {{ $node->attributes(['class' => 'a2ui-icon inline-flex shrink-0 items-center justify-center', ...$a11y]) }}>
        <svg viewBox="0 0 24 24" fill="currentColor" class="size-6" aria-hidden="true"><path d="{{ $icon['path'] }}" /></svg>
    </span>
@endif
