<div class="mx-auto flex max-w-3xl flex-col gap-6 p-6" x-data="{ prompt: '' }">
    <div>
        <h1 class="text-2xl font-semibold tracking-tight">Agent playground</h1>
        <p class="mt-1 text-sm text-zinc-500 dark:text-zinc-400">
            Prompts and button clicks go to an agent over the
            <code>{{ config('services.a2ui_playground.transport') }}</code> transport. Every reply is a stream of
            A2UI v1.0 messages rendered by <code>&lt;livewire:a2ui&gt;</code>.
        </p>
    </div>

    <livewire:a2ui
        :transport="config('services.a2ui_playground.transport')"
        :prompt="config('services.a2ui_playground.prompt')"
        empty-text="Ask the agent something below, e.g. “What are the best restaurants in Bucharest?”. Replies from a live LLM take 15–30 seconds."
        key="playground-renderer"
    />

    <form
        class="sticky bottom-4 flex gap-2 rounded-2xl border border-zinc-200 bg-white p-2 shadow-lg dark:border-white/10 dark:bg-zinc-800"
        x-on:submit.prevent="if (prompt.trim()) { $dispatch('a2ui-prompt', { text: prompt }); prompt = '' }"
    >
        <input
            x-model="prompt"
            placeholder="Ask for some UI… e.g. “{{ $suggestions[0] }}”"
            aria-label="Prompt"
            class="min-w-0 flex-1 rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm outline-none focus:border-accent focus:ring-3 focus:ring-accent/25 dark:border-white/15 dark:bg-zinc-900"
        >
        <button type="submit" class="rounded-lg bg-accent px-4 py-2 text-sm font-medium text-accent-foreground hover:opacity-90">Send</button>
    </form>
</div>
