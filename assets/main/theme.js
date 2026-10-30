(() => {
    const root = document.documentElement;
    const media = window.matchMedia('(prefers-color-scheme: dark)');

    const stored = () => {
        try {
            const theme = window.localStorage.getItem('theme');
            return theme === 'light' || theme === 'dark' ? theme : null;
        } catch {
            return null;
        }
    };

    const syncToggles = () => {
        const dark = root.getAttribute('data-bs-theme') === 'dark';
        document.querySelectorAll('[data-theme-toggle]').forEach((toggle) => {
            toggle.setAttribute('aria-pressed', dark ? 'true' : 'false');
        });
    };

    const apply = (theme) => {
        root.setAttribute('data-bs-theme', theme);
        syncToggles();
    };

    apply(stored() ?? (media.matches ? 'dark' : 'light'));

    media.addEventListener('change', (event) => {
        if (stored() === null) {
            apply(event.matches ? 'dark' : 'light');
        }
    });

    document.addEventListener('DOMContentLoaded', () => {
        document.querySelectorAll('[data-theme-toggle]').forEach((toggle) => {
            toggle.hidden = false;
            toggle.addEventListener('click', () => {
                const theme = root.getAttribute('data-bs-theme') === 'dark' ? 'light' : 'dark';
                apply(theme);
                try {
                    window.localStorage.setItem('theme', theme);
                } catch {
                }
            });
        });
        syncToggles();
    });
})();
