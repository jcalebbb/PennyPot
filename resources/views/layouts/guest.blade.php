<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" x-data="themeToggle" :class="{ 'dark': darkMode }">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">

        <title>{{ config('app.name', 'Laravel') }}</title>

        <!-- Fonts -->
        <link rel="preconnect" href="https://fonts.bunny.net">
        <link href="https://fonts.bunny.net/css?family=figtree:400,500,600&display=swap" rel="stylesheet" />

        <!-- Scripts -->
        @vite(['resources/css/app.css', 'resources/js/app.js'])
    </head>
    <body class="font-sans text-[var(--pp-text)] antialiased">
        <div class="min-h-screen flex flex-col sm:justify-center items-center gap-5 px-4 py-8 sm:pt-0 bg-[var(--pp-bg)]">
            <button type="button" @click="toggle" class="self-end rounded-lg border border-slate-300 bg-[var(--pp-surface)] px-3 py-2 text-sm font-semibold text-[var(--pp-brand)] shadow-sm dark:border-slate-600 dark:text-slate-100" aria-label="Toggle color theme">
                <span x-text="darkMode ? 'Light mode' : 'Dark mode'"></span>
            </button>
            <div>
                <a href="/" wire:navigate>
                    <x-application-logo class="h-20 w-20 fill-current text-[var(--pp-brand)]" />
                </a>
            </div>

            <div class="pp-surface w-full max-w-md overflow-hidden rounded-2xl border border-white/70 px-6 py-7 dark:border-slate-700 sm:px-8">
                {{ $slot }}
            </div>
        </div>
    </body>
</html>
