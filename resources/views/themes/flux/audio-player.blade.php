@php
    $url = $node->mediaUrl('url');
@endphp
@if ($url !== null)
    <audio {{ $node->attributes([
        'src' => $url,
        'controls' => true,
        'preload' => 'metadata',
        'class' => 'a2ui-audio block w-full',
    ]) }}></audio>
@endif
