@php
    $tabs = array_values(array_filter((array) $node->raw('tabs', []), fn ($tab) => is_array($tab) && is_string($tab['child'] ?? null)));
    $id = $node->domId();
@endphp
<div {{ $node->attributes(['class' => 'a2ui-tabs w-full min-w-0']) }} x-data="{ tab: 0 }">
    <div role="tablist" class="flex gap-1 overflow-x-auto border-b border-zinc-200 dark:border-white/10" x-on:keydown.right.prevent="tab = (tab + 1) % {{ max(1, count($tabs)) }}; $nextTick(() => $el.querySelectorAll('[role=tab]')[tab]?.focus())" x-on:keydown.left.prevent="tab = (tab + {{ max(1, count($tabs)) - 1 }}) % {{ max(1, count($tabs)) }}; $nextTick(() => $el.querySelectorAll('[role=tab]')[tab]?.focus())">
        @foreach ($tabs as $i => $tab)
            <button
                type="button"
                role="tab"
                id="{{ $id }}-tab-{{ $i }}"
                aria-controls="{{ $id }}-panel-{{ $i }}"
                aria-selected="{{ $i === 0 ? 'true' : 'false' }}"
                x-bind:aria-selected="tab === {{ $i }} ? 'true' : 'false'"
                x-bind:tabindex="tab === {{ $i }} ? 0 : -1"
                x-on:click="tab = {{ $i }}"
                @class([
                    '-mb-px whitespace-nowrap border-b-2 px-3 py-2 text-sm font-medium transition-colors',
                    'border-zinc-800 text-zinc-800 dark:border-white dark:text-white' => $i === 0,
                    'border-transparent text-zinc-500 dark:text-zinc-400' => $i !== 0,
                ])
                x-bind:class="tab === {{ $i }}
                    ? 'border-zinc-800 text-zinc-800 dark:border-white dark:text-white'
                    : 'border-transparent text-zinc-500 hover:text-zinc-700 dark:text-zinc-400 dark:hover:text-zinc-200'"
            >{{ $node->resolveString($tab['title'] ?? '') }}</button>
        @endforeach
    </div>

    @foreach ($tabs as $i => $tab)
        <div
            role="tabpanel"
            id="{{ $id }}-panel-{{ $i }}"
            aria-labelledby="{{ $id }}-tab-{{ $i }}"
            class="pt-4"
            x-show="tab === {{ $i }}"
            @if ($i !== 0) style="display: none" @endif
        >
            {{ $node->renderChild($tab['child']) }}
        </div>
    @endforeach
</div>
