@php
    $tabs = array_values(array_filter((array) $node->raw('tabs', []), fn ($tab) => is_array($tab) && is_string($tab['child'] ?? null)));
    $count = max(1, count($tabs));
    $id = $node->domId();
@endphp
<div {{ $node->attributes(['class' => 'a2ui-tabs']) }} x-data="{ tab: 0 }">
    <div
        role="tablist"
        class="a2ui-tablist"
        x-on:keydown.right.prevent="tab = (tab + 1) % {{ $count }}; $nextTick(() => $el.querySelectorAll('[role=tab]')[tab]?.focus())"
        x-on:keydown.left.prevent="tab = (tab + {{ $count - 1 }}) % {{ $count }}; $nextTick(() => $el.querySelectorAll('[role=tab]')[tab]?.focus())"
    >
        @foreach ($tabs as $i => $tab)
            <button
                type="button"
                role="tab"
                class="a2ui-tab"
                id="{{ $id }}-tab-{{ $i }}"
                aria-controls="{{ $id }}-panel-{{ $i }}"
                aria-selected="{{ $i === 0 ? 'true' : 'false' }}"
                tabindex="{{ $i === 0 ? '0' : '-1' }}"
                x-bind:aria-selected="tab === {{ $i }} ? 'true' : 'false'"
                x-bind:tabindex="tab === {{ $i }} ? 0 : -1"
                x-on:click="tab = {{ $i }}"
            >{{ $node->resolveString($tab['title'] ?? '') }}</button>
        @endforeach
    </div>

    @foreach ($tabs as $i => $tab)
        <div
            role="tabpanel"
            class="a2ui-tabpanel"
            id="{{ $id }}-panel-{{ $i }}"
            aria-labelledby="{{ $id }}-tab-{{ $i }}"
            x-show="tab === {{ $i }}"
            @if ($i !== 0) style="display: none" @endif
        >
            {{ $node->renderChild($tab['child']) }}
        </div>
    @endforeach
</div>
