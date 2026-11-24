@php
    $panel = 'rounded-2xl border border-zinc-200 bg-white dark:border-white/10 dark:bg-zinc-800/60';
    $panelHeading = 'border-b border-zinc-200 px-4 py-3 text-sm font-semibold dark:border-white/10';
    $btn = 'inline-flex items-center gap-1 rounded-md border border-zinc-300 bg-white px-2.5 py-1 text-sm font-medium hover:bg-zinc-50 disabled:cursor-not-allowed disabled:opacity-40 dark:border-white/15 dark:bg-white/5 dark:hover:bg-white/10';
@endphp
<div class="mx-auto grid max-w-[1600px] gap-6 p-6 lg:grid-cols-[16rem_minmax(0,1fr)_22rem]"
     x-data="{
        models: {},
        // The renderer embeds its data models as JSON (data-a2ui-inspect); read it on
        // load and whenever the preview changes, so no event can be missed.
        read() {
            const el = this.$refs.preview?.querySelector('script[data-a2ui-inspect]');
            this.models = el ? (JSON.parse(el.textContent).surfaces ?? {}) : {};
        },
        init() {
            this.read();
            let queued = false;
            new MutationObserver(() => {
                if (queued) return;
                queued = true;
                requestAnimationFrame(() => { queued = false; this.read(); });
            }).observe(this.$refs.preview, { subtree: true, childList: true, characterData: true });
        },
     }"
     x-on:a2ui-inspect.window="models = $event.detail.surfaces">

    {{-- Left: examples --}}
    <aside class="lg:sticky lg:top-6 lg:max-h-[calc(100vh-7rem)] lg:overflow-y-auto">
        <h2 class="mb-2 px-2 text-xs font-semibold tracking-wide text-zinc-500 uppercase">Basic Catalog examples</h2>
        <nav class="flex flex-col gap-0.5">
            @foreach ($this->examples as $name => $info)
                <button
                    type="button"
                    wire:click="select('{{ $name }}')"
                    wire:key="nav-{{ $name }}"
                    @class([
                        'rounded-md px-2 py-1.5 text-left text-sm transition-colors',
                        'bg-zinc-200/70 font-medium text-zinc-900 dark:bg-white/10 dark:text-white' => $name === $example,
                        'text-zinc-600 hover:bg-zinc-100 hover:text-zinc-900 dark:text-zinc-400 dark:hover:bg-white/5 dark:hover:text-white' => $name !== $example,
                    ])
                >{{ $info['title'] }}</button>
            @endforeach
        </nav>
    </aside>

    {{-- Center: surface + message stepper --}}
    <section class="min-w-0 space-y-6">
        <div>
            <h1 class="text-2xl font-semibold tracking-tight">{{ $this->examples[$example]['title'] ?? $example }}</h1>
            <p class="mt-1 text-sm text-zinc-500 dark:text-zinc-400">{{ $this->examples[$example]['description'] ?? '' }}</p>
        </div>

        <div x-ref="preview" class="{{ $panel }} p-6 shadow-xs">
            <livewire:a2ui
                :messages="$applied"
                transport="gallery"
                :inspect="true"
                empty-text="No surface yet. Step through the messages below."
                :key="'renderer-'.$example.'-'.$current"
            />
        </div>

        <div class="{{ $panel }}">
            <div class="flex flex-wrap items-center justify-between gap-3 border-b border-zinc-200 px-4 py-3 dark:border-white/10">
                <h2 class="text-sm font-semibold">Message stream <span class="font-normal text-zinc-500">({{ $current }} / {{ $total }} applied)</span></h2>
                <div class="flex gap-2">
                    <button type="button" class="{{ $btn }}" wire:click="stepTo(0)" @disabled($current === 0)>Reset</button>
                    <button type="button" class="{{ $btn }}" wire:click="stepTo({{ $current - 1 }})" @disabled($current === 0)>‹ Back</button>
                    <button type="button" class="{{ $btn }}" wire:click="stepTo({{ $current + 1 }})" @disabled($current >= $total)>Next ›</button>
                    <button type="button" class="{{ $btn }} !border-accent !bg-accent !text-accent-foreground" wire:click="stepTo(null)" @disabled($current >= $total)>All »</button>
                </div>
            </div>
            <ol class="divide-y divide-zinc-100 dark:divide-white/5">
                @foreach ($this->messages as $i => $message)
                    @php($type = array_key_first(array_diff_key($message, ['version' => true])))
                    <li wire:key="msg-{{ $example }}-{{ $i }}" @class(['opacity-40' => $i >= $current])>
                        <details>
                            <summary class="flex cursor-pointer items-center gap-3 px-4 py-2 text-sm">
                                <span class="w-6 text-right text-zinc-400 tabular-nums">{{ $i + 1 }}</span>
                                <span @class([
                                    'rounded px-1.5 py-0.5 font-mono text-xs',
                                    'bg-accent/15 text-accent-content' => $i < $current,
                                    'bg-zinc-100 text-zinc-600 dark:bg-white/10 dark:text-zinc-300' => $i >= $current,
                                ])>{{ $type }}</span>
                                <span class="truncate text-zinc-500">{{ $message[$type]['surfaceId'] ?? '' }}</span>
                            </summary>
                            <pre class="max-h-96 overflow-auto bg-zinc-50 px-4 py-3 text-xs dark:bg-black/20">{{ json_encode($message, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) }}</pre>
                        </details>
                    </li>
                @endforeach
            </ol>
        </div>
    </section>

    {{-- Right: data model + actions --}}
    <aside class="space-y-6 lg:sticky lg:top-6 lg:max-h-[calc(100vh-7rem)] lg:overflow-y-auto">
        <div class="{{ $panel }}">
            <h2 class="{{ $panelHeading }}">Data model <span class="font-normal text-zinc-500">(live)</span></h2>
            <template x-for="(surface, id) in models" :key="id">
                <div class="px-4 py-3">
                    <div class="mb-1 font-mono text-xs text-zinc-500" x-text="id"></div>
                    <pre class="overflow-auto text-xs" x-text="JSON.stringify(surface.dataModel, null, 2)"></pre>
                </div>
            </template>
            <p class="px-4 py-3 text-sm text-zinc-500" x-show="Object.keys(models).length === 0">No surfaces yet.</p>
        </div>

        <div class="{{ $panel }}">
            <h2 class="{{ $panelHeading }}">Actions sent to the agent</h2>
            @forelse ($actions as $i => $entry)
                <details class="border-b border-zinc-100 last:border-0 dark:border-white/5" wire:key="action-{{ count($actions) - $i }}" @if ($i === 0) open @endif>
                    <summary class="cursor-pointer px-4 py-2 text-sm">
                        <span class="text-zinc-400 tabular-nums">{{ $entry['time'] }}</span>
                        <span class="font-medium">{{ $entry['message']['action']['name'] ?? '?' }}</span>
                    </summary>
                    <pre class="overflow-auto bg-zinc-50 px-4 py-3 text-xs dark:bg-black/20">{{ json_encode($entry['message'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) }}</pre>
                </details>
            @empty
                <p class="px-4 py-3 text-sm text-zinc-500">Interact with a button to see the <code>action</code> message.</p>
            @endforelse
        </div>
    </aside>
</div>
