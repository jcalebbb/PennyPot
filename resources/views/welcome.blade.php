<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>{{ config('app.name', 'PennyPot') }}</title>
        @vite(['resources/css/app.css', 'resources/js/app.js'])
    </head>
    <body class="min-h-screen bg-slate-950 font-sans text-slate-100 antialiased">
        <main class="relative isolate min-h-screen overflow-hidden">
            <div class="absolute inset-x-0 top-0 -z-10 h-96 bg-gradient-to-br from-emerald-500/20 via-slate-950 to-slate-950"></div>

            <div class="mx-auto flex min-h-screen max-w-7xl flex-col px-6 py-8 sm:px-10 lg:px-12">
                <header class="flex items-center justify-between">
                    <a href="{{ route('home') }}" class="flex items-center gap-3" wire:navigate>
                        <x-application-logo class="h-10 w-10 fill-current text-emerald-400" />
                        <span class="text-lg font-semibold tracking-tight text-white">PennyPot</span>
                    </a>

                    <nav class="flex items-center gap-2 text-sm">
                        @auth
                            <a href="{{ route('dashboard') }}" class="rounded-md px-3 py-2 font-medium text-slate-200 transition hover:bg-white/10 hover:text-white" wire:navigate>{{ __('Dashboard') }}</a>
                        @else
                            <a href="{{ route('login') }}" class="rounded-md px-3 py-2 font-medium text-slate-200 transition hover:bg-white/10 hover:text-white" wire:navigate>{{ __('Log in') }}</a>
                            @if (Route::has('register'))
                                <a href="{{ route('register') }}" class="rounded-md bg-emerald-400 px-4 py-2 font-semibold text-slate-950 transition hover:bg-emerald-300" wire:navigate>{{ __('Register') }}</a>
                            @endif
                        @endauth
                    </nav>
                </header>

                <section class="flex flex-1 items-center py-20 lg:py-28">
                    <div class="max-w-3xl">
                        <p class="text-sm font-semibold uppercase tracking-[0.2em] text-emerald-300">{{ __('Personal finance, made clear') }}</p>
                        <h1 class="mt-6 text-5xl font-semibold tracking-tight text-white sm:text-7xl">{{ __('Know where your money is going.') }}</h1>
                        <p class="mt-6 max-w-2xl text-lg leading-8 text-slate-300">{{ __('PennyPot brings your accounts, transactions, budgets, and recurring money into one calm place to manage.') }}</p>

                        <div class="mt-10 flex flex-wrap gap-3">
                            @auth
                                <a href="{{ route('dashboard') }}" class="rounded-md bg-emerald-400 px-5 py-3 font-semibold text-slate-950 transition hover:bg-emerald-300" wire:navigate>{{ __('Open dashboard') }}</a>
                            @else
                                <a href="{{ route('register') }}" class="rounded-md bg-emerald-400 px-5 py-3 font-semibold text-slate-950 transition hover:bg-emerald-300" wire:navigate>{{ __('Get started') }}</a>
                                <a href="{{ route('login') }}" class="rounded-md border border-slate-700 px-5 py-3 font-semibold text-white transition hover:border-slate-500 hover:bg-white/5" wire:navigate>{{ __('Log in') }}</a>
                            @endauth
                        </div>
                    </div>
                </section>

                <footer class="border-t border-slate-800 pt-5 text-sm text-slate-500">
                    {{ __('Your financial picture, one thoughtful entry at a time.') }}
                </footer>
            </div>
        </main>
    </body>
</html>