<button {{ $attributes->merge(['type' => 'submit', 'class' => 'pp-accent-solid inline-flex items-center justify-center rounded-lg px-4 py-2 text-sm font-semibold shadow-sm transition hover:brightness-90 focus:outline-none focus:ring-2 focus:ring-[#0097B2] focus:ring-offset-2 disabled:opacity-50']) }}>
    {{ $slot }}
</button>
