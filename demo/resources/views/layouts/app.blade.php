<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $title ?? 'A2UI for Livewire' }}</title>
    {{-- Apply the saved colour scheme before first paint. --}}
    <script>
        (() => {
            const saved = localStorage.getItem('a2ui-demo-theme');
            const dark = saved ? saved === 'dark' : window.matchMedia('(prefers-color-scheme: dark)').matches;
            document.documentElement.classList.toggle('dark', dark);
        })();
    </script>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="min-h-screen bg-zinc-50 text-zinc-900 antialiased dark:bg-zinc-900 dark:text-zinc-100">
    @php
        // Separate dev servers (see config/services.php) are separate origins,
        // so plain links replace wire:navigate when they are configured.
        $galleryUrl = config('services.a2ui_playground.gallery_url') ?: route('gallery');
        $playgroundUrl = config('services.a2ui_playground.playground_url') ?: route('agent');
        $split = (bool) config('services.a2ui_playground.playground_url');
        $navLink = 'rounded-md px-3 py-1.5 text-sm font-medium transition-colors';
        $active = 'bg-zinc-100 text-zinc-900 dark:bg-white/10 dark:text-white';
        $inactive = 'text-zinc-500 hover:text-zinc-900 dark:text-zinc-400 dark:hover:text-white';
    @endphp

    <header class="border-b border-zinc-200 bg-white dark:border-white/10 dark:bg-zinc-900">
        <div class="mx-auto flex h-14 max-w-[1600px] items-center gap-6 px-6">
            <a href="{{ $galleryUrl }}" class="flex items-center gap-2 font-semibold">
                <span class="flex size-6 items-center justify-center rounded-md bg-accent text-xs font-bold text-accent-foreground">A2</span>
                <span class="max-sm:hidden">A2UI · Livewire</span>
            </a>

            <nav class="flex items-center gap-1">
                <a href="{{ $galleryUrl }}" @class([$navLink, request()->routeIs('gallery') ? $active : $inactive]) @unless ($split) wire:navigate @endunless>Gallery</a>
                <a href="{{ $playgroundUrl }}" @class([$navLink, request()->routeIs('agent') ? $active : $inactive]) @unless ($split) wire:navigate @endunless>Agent playground</a>
            </nav>

            <button
                type="button"
                class="ml-auto rounded-md p-2 text-zinc-500 hover:bg-zinc-100 hover:text-zinc-900 dark:text-zinc-400 dark:hover:bg-white/10 dark:hover:text-white"
                aria-label="Toggle dark mode"
                x-data
                x-on:click="const dark = document.documentElement.classList.toggle('dark'); localStorage.setItem('a2ui-demo-theme', dark ? 'dark' : 'light')"
            >
                <svg class="size-5" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M12 3a9 9 0 1 0 9 9c0-.46-.04-.92-.1-1.36a5.389 5.389 0 0 1-4.4 2.26 5.403 5.403 0 0 1-3.14-9.8c-.44-.06-.9-.1-1.36-.1z"/></svg>
            </button>
        </div>
    </header>

    <main>
        {{ $slot }}
    </main>
</body>
</html>
