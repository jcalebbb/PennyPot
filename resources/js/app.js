document.addEventListener('alpine:init', () => {
	Alpine.data('themeToggle', () => ({
		darkMode: localStorage.getItem('pennypot-theme') === 'dark'
			|| (! localStorage.getItem('pennypot-theme') && window.matchMedia('(prefers-color-scheme: dark)').matches),

		init() {
			this.applyTheme();
		},

		toggle() {
			this.darkMode = ! this.darkMode;
			localStorage.setItem('pennypot-theme', this.darkMode ? 'dark' : 'light');
			this.applyTheme();
		},

		applyTheme() {
			document.documentElement.classList.toggle('dark', this.darkMode);
		},
	}));
});
