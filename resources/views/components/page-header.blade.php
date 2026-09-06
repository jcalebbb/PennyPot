@props(['title', 'description' => null])

<div class="flex flex-col gap-1 sm:flex-row sm:items-end sm:justify-between">
    <div>
        <h2 class="text-2xl font-semibold tracking-tight text-[var(--pp-brand)] dark:text-white">{{ $title }}</h2>
        @if ($description)
            <p class="mt-1 text-sm text-[var(--pp-muted)]">{{ $description }}</p>
        @endif
    </div>
    @if (isset($actions))
        <div class="flex items-center gap-2">{{ $actions }}</div>
    @endif
</div>
