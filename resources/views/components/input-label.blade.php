@props(['value'])

<label {{ $attributes->merge(['class' => 'block text-sm font-semibold text-[var(--pp-text)]']) }}>
    {{ $value ?? $slot }}
</label>
