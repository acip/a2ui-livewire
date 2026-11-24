@php
    $url = $node->mediaUrl('url');
    $variant = $node->enum('variant', ['icon', 'avatar', 'smallFeature', 'mediumFeature', 'largeFeature', 'header'], 'mediumFeature');
@endphp
@if ($url !== null)
    <img {{ $node->attributes(array_filter([
        'src' => $url,
        'alt' => $node->accessibleLabel(''),
        'loading' => 'lazy',
        'decoding' => 'async',
        'referrerpolicy' => 'no-referrer',
        'class' => 'a2ui-image',
        'data-variant' => $variant,
        'data-fit' => $node->has('fit') ? $node->enum('fit', ['contain', 'cover', 'fill', 'none', 'scaleDown'], 'fill') : null,
    ], fn ($value) => $value !== null)) }}>
@endif
