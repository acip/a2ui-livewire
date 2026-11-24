<div
    class="a2ui-renderer"
    data-a2ui-renderer
    @if ($channel && $poll) wire:poll.{{ $poll }}="syncChannel" @endif
    @if ($initialPrompt) wire:init="sendInitialPrompt" @endif
>
    <div
        wire:loading.delay.shortest
        wire:target="a2uiAction, sendPrompt, sendInitialPrompt, a2uiFlush, syncChannel"
        class="a2ui-progress"
        aria-hidden="true"
    ></div>

    <div class="a2ui-surfaces" wire:stream="{{ $streamTarget }}">
        @if ($hasSurfaces)
            {{ $surfacesHtml }}
        @elseif ($emptyText)
            <p class="a2ui-empty">{{ $emptyText }}</p>
        @endif
    </div>

    @if ($debug && $a2uiErrors)
        <details class="a2ui-issues">
            <summary>{{ count($a2uiErrors) }} A2UI {{ \Illuminate\Support\Str::plural('issue', count($a2uiErrors)) }}</summary>
            <ul>
                @foreach ($a2uiErrors as $error)
                    <li>[{{ $error['code'] }}]@isset($error['surfaceId']) {{ $error['surfaceId'] }}@endisset: {{ $error['message'] }}</li>
                @endforeach
            </ul>
        </details>
    @endif

    @if ($inspection !== null)
        {{-- Read by devtools / inspectors: data models of every surface (JSON, not executed). --}}
        <script type="application/json" data-a2ui-inspect wire:key="a2ui-inspect">{!! $inspection !!}</script>
    @endif

    @if ($needsFlush)
        <div wire:key="a2ui-flush-{{ $flushKey }}" x-init="$wire.a2uiFlush()" hidden></div>
    @endif
</div>
