@php
    $url = $node->mediaUrl('url');
    $poster = $node->has('posterUrl') ? $node->mediaUrl('posterUrl') : null;
@endphp
@if ($url !== null)
    <video {{ $node->attributes(array_filter([
        'src' => $url,
        'poster' => $poster,
        'controls' => true,
        'preload' => 'metadata',
        'playsinline' => true,
        'class' => 'a2ui-video',
    ], fn ($value) => $value !== null)) }}></video>
@endif
