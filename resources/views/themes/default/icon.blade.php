@php
    $svg = $node->iconSvg('name');
    $labelled = $node->accessibleLabel('') !== '';
@endphp
@if ($svg !== null)
    <span {{ $node->attributes(['class' => 'a2ui-icon', ...($labelled ? ['role' => 'img'] : ['aria-hidden' => 'true'])]) }}>
        <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false">{!! $svg !!}</svg>
    </span>
@endif
