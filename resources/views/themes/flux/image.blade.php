@php
    $url = $node->mediaUrl('url');
    $variant = $node->enum('variant', ['icon', 'avatar', 'smallFeature', 'mediumFeature', 'largeFeature', 'header'], 'mediumFeature');
    $fit = $node->enum('fit', ['contain', 'cover', 'fill', 'none', 'scaleDown'], in_array($variant, ['avatar', 'header'], true) ? 'cover' : 'fill');

    $fitClass = [
        'contain' => 'object-contain',
        'cover' => 'object-cover',
        'fill' => 'object-fill',
        'none' => 'object-none',
        'scaleDown' => 'object-scale-down',
    ][$fit];

    $sizeClass = [
        'icon' => 'size-6 shrink-0 rounded',
        'avatar' => 'size-10 shrink-0 rounded-full',
        'smallFeature' => 'size-24 shrink-0 rounded-lg',
        'mediumFeature' => 'w-full max-w-[300px] rounded-lg',
        'largeFeature' => 'w-full max-h-[400px] rounded-xl',
        'header' => 'w-full h-[200px] rounded-xl',
    ][$variant];
@endphp
@if ($url !== null)
    <img {{ $node->attributes([
        'src' => $url,
        'alt' => $node->accessibleLabel(''),
        'loading' => 'lazy',
        'decoding' => 'async',
        'referrerpolicy' => 'no-referrer',
        'class' => "a2ui-image block bg-zinc-100 dark:bg-white/5 {$sizeClass} {$fitClass}",
    ]) }}>
@endif
