<button {{ $attributes->merge(['type' => 'button', 'class' => 'inline-flex items-center justify-center rounded-lg border border-[#0097B2] bg-[var(--pp-surface)] px-4 py-2 text-sm font-semibold text-[#007A91] shadow-sm transition hover:bg-[#0097B2]/10 focus:outline-none focus:ring-2 focus:ring-[#0097B2] focus:ring-offset-2 disabled:opacity-50 dark:border-[#0097B2] dark:text-slate-100 dark:hover:bg-[#0097B2]/20']) }}>
    {{ $slot }}
</button>
