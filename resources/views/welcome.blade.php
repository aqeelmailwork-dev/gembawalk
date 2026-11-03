<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">

        <title>{{ config('app.name', 'GembaWalk') }} — See the floor. Capture what matters.</title>

        <script>
            (function () {
                try {
                    var stored = localStorage.getItem('flux.appearance');
                    var dark = stored === 'dark' || ((!stored || stored === 'system') && window.matchMedia('(prefers-color-scheme: dark)').matches);
                    document.documentElement.classList.toggle('dark', dark);
                } catch (e) {}
            })();
        </script>

        @vite(['resources/css/app.css', 'resources/js/app.js'])
    </head>
    <body class="min-h-screen bg-white font-sans text-zinc-800 antialiased dark:bg-zinc-950 dark:text-zinc-100">
        <header class="mx-auto flex w-full max-w-6xl items-center justify-between gap-4 px-4 py-4 sm:px-6 sm:py-5">
            <a href="{{ route('home') }}" class="flex items-center gap-2" wire:navigate>
                <span class="flex size-8 items-center justify-center rounded-md bg-teal-600 dark:bg-teal-500">
                    <x-app-logo-icon class="size-5 text-white" />
                </span>
                <span class="text-sm font-semibold text-zinc-900 dark:text-white">{{ config('app.name', 'GembaWalk') }}</span>
            </a>

            <nav class="flex items-center gap-2">
                @auth
                    <a
                        href="{{ route('dashboard') }}"
                        wire:navigate
                        class="inline-flex items-center rounded-lg bg-teal-600 px-4 py-2 text-sm font-medium text-white transition hover:bg-teal-500 dark:bg-teal-500 dark:hover:bg-teal-400"
                    >
                        Dashboard
                    </a>
                @else
                    <a
                        href="{{ route('login') }}"
                        class="inline-flex items-center rounded-lg px-4 py-2 text-sm font-medium text-zinc-700 transition hover:bg-zinc-100 dark:text-zinc-200 dark:hover:bg-zinc-800"
                    >
                        Log in
                    </a>

                    @if (Route::has('register'))
                        <a
                            href="{{ route('register') }}"
                            class="inline-flex items-center rounded-lg bg-teal-600 px-4 py-2 text-sm font-medium text-white transition hover:bg-teal-500 dark:bg-teal-500 dark:hover:bg-teal-400"
                        >
                            Sign up
                        </a>
                    @endif
                @endauth
            </nav>
        </header>

        <main>
            {{-- Hero --}}
            <section class="mx-auto grid w-full max-w-6xl gap-12 px-4 py-12 sm:px-6 sm:py-20 lg:grid-cols-2 lg:items-center">
                <div class="flex flex-col items-start gap-6">
                    <h1 class="text-4xl font-semibold tracking-tight text-zinc-900 sm:text-5xl dark:text-white">
                        See the floor. Capture what matters.
                    </h1>

                    <p class="text-lg text-zinc-600 dark:text-zinc-300">
                        GembaWalk turns your gemba walks into photo-backed findings and ready-to-share reports.
                    </p>

                    <div class="flex flex-wrap items-center gap-3">
                        @if (Route::has('register'))
                            <a
                                href="{{ route('register') }}"
                                class="inline-flex items-center gap-2 rounded-lg bg-teal-600 px-5 py-2.5 text-sm font-medium text-white shadow-sm transition hover:bg-teal-500 dark:bg-teal-500 dark:hover:bg-teal-400"
                            >
                                Get started
                                <flux:icon name="arrow-right" class="size-4" />
                            </a>
                        @endif

                        <a
                            href="{{ route('login') }}"
                            class="inline-flex items-center rounded-lg border border-zinc-300 px-5 py-2.5 text-sm font-medium text-zinc-700 transition hover:bg-zinc-50 dark:border-zinc-700 dark:text-zinc-200 dark:hover:bg-zinc-800"
                        >
                            Log in
                        </a>
                    </div>
                </div>

                {{-- Hero visual: mock phone frame with a finding card --}}
                <div class="flex justify-center lg:justify-end">
                    <div class="relative w-full max-w-xs">
                        <div class="absolute -inset-6 rounded-[3rem] bg-teal-500/10 blur-2xl dark:bg-teal-400/10"></div>

                        <div class="relative rounded-[2.5rem] border border-zinc-200 bg-white p-3 shadow-xl dark:border-zinc-700 dark:bg-zinc-900">
                            <div class="mx-auto mb-3 h-1.5 w-16 rounded-full bg-zinc-200 dark:bg-zinc-700"></div>

                            <div class="overflow-hidden rounded-[1.75rem] bg-zinc-100 dark:bg-zinc-800">
                                <div class="p-3">
                                    <div class="flex aspect-[4/3] w-full items-center justify-center overflow-hidden rounded-xl bg-teal-500/20 dark:bg-teal-400/20">
                                        <flux:icon name="photo" class="size-10 text-teal-600 dark:text-teal-400" />
                                    </div>

                                    <div class="mt-3 flex flex-wrap items-center gap-2">
                                        <span class="rounded-md bg-teal-600 px-2 py-0.5 text-xs font-medium text-white">Safety</span>
                                        <span class="rounded-md bg-red-600 px-2 py-0.5 text-xs font-medium text-white">High</span>
                                    </div>

                                    <p class="mt-2 text-sm font-medium text-zinc-800 dark:text-zinc-100">Trip hazard at the end of line 3</p>
                                    <p class="mt-1 text-xs text-zinc-500 dark:text-zinc-400">Action: replace the worn cable guard</p>

                                    <div class="mt-3 flex items-center justify-between">
                                        <span class="inline-flex items-center gap-1 text-xs text-zinc-500 dark:text-zinc-400">
                                            <flux:icon name="photo" class="size-3.5" />
                                            1 photo
                                        </span>

                                        <span class="rounded-full bg-amber-500/15 px-2 py-0.5 text-xs font-medium text-amber-600 dark:text-amber-400">Open</span>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </section>

            {{-- Features --}}
            <section class="mx-auto w-full max-w-6xl px-4 pb-16 sm:px-6 sm:pb-24">
                <div class="grid gap-6 md:grid-cols-3">
                    <div class="rounded-xl border border-zinc-200 bg-white p-6 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
                        <div class="flex size-11 items-center justify-center rounded-lg bg-teal-500/10 dark:bg-teal-400/10">
                            <flux:icon name="camera" class="size-6 text-teal-600 dark:text-teal-400" />
                        </div>
                        <h2 class="mt-4 text-base font-semibold text-zinc-900 dark:text-white">Capture on the go</h2>
                        <p class="mt-1.5 text-sm text-zinc-600 dark:text-zinc-300">Snap photos from the floor with your phone camera or gallery.</p>
                    </div>

                    <div class="rounded-xl border border-zinc-200 bg-white p-6 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
                        <div class="flex size-11 items-center justify-center rounded-lg bg-teal-500/10 dark:bg-teal-400/10">
                            <flux:icon name="tag" class="size-6 text-teal-600 dark:text-teal-400" />
                        </div>
                        <h2 class="mt-4 text-base font-semibold text-zinc-900 dark:text-white">Tag findings</h2>
                        <p class="mt-1.5 text-sm text-zinc-600 dark:text-zinc-300">Categorize, set severity, and assign owners and due dates.</p>
                    </div>

                    <div class="rounded-xl border border-zinc-200 bg-white p-6 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
                        <div class="flex size-11 items-center justify-center rounded-lg bg-teal-500/10 dark:bg-teal-400/10">
                            <flux:icon name="document-chart-bar" class="size-6 text-teal-600 dark:text-teal-400" />
                        </div>
                        <h2 class="mt-4 text-base font-semibold text-zinc-900 dark:text-white">Instant reports</h2>
                        <p class="mt-1.5 text-sm text-zinc-600 dark:text-zinc-300">Turn findings into a ready-to-share report or PDF in one click.</p>
                    </div>
                </div>
            </section>
        </main>

        <footer class="border-t border-zinc-200 py-8 text-center text-xs text-zinc-400 dark:border-zinc-800 dark:text-zinc-500">
            &copy; {{ date('Y') }} {{ config('app.name', 'GembaWalk') }}. Go see, capture what you find.
        </footer>
    </body>
</html>
