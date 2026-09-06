@props(['disabled' => false])

<input @disabled($disabled) {{ $attributes->merge(['class' => 'pp-inset border-gray-300 focus:border-[#0097B2] focus:ring-[#0097B2] rounded-lg shadow-sm']) }}>
