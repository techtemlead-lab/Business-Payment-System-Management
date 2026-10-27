export type ColorScheme = 'light' | 'dark';

export const THEME_STORAGE_KEY = 'madina-color-scheme';

export function resolveColorScheme(stored: string | null = null): ColorScheme {
    const value = stored ?? (typeof localStorage !== 'undefined' ? localStorage.getItem(THEME_STORAGE_KEY) : null);
    if (value === 'light' || value === 'dark') {
        return value;
    }
    if (typeof window !== 'undefined' && window.matchMedia('(prefers-color-scheme: dark)').matches) {
        return 'dark';
    }

    return 'light';
}

export function applyColorScheme(scheme: ColorScheme): void {
    const root = document.documentElement;
    root.setAttribute('data-theme', scheme);
    root.style.colorScheme = scheme;
    const meta = document.querySelector('meta[name="theme-color"]');
    if (meta) {
        meta.setAttribute('content', scheme === 'dark' ? '#0b1220' : '#011627');
    }
}

export function persistColorScheme(scheme: ColorScheme): void {
    try {
        localStorage.setItem(THEME_STORAGE_KEY, scheme);
    } catch {
        // Private browsing / blocked storage — theme still applies for this page.
    }
    applyColorScheme(scheme);
}

export function toggleColorScheme(): ColorScheme {
    const next: ColorScheme = resolveColorScheme() === 'dark' ? 'light' : 'dark';
    persistColorScheme(next);

    return next;
}

export function syncThemeToggleButtons(scheme: ColorScheme = resolveColorScheme()): void {
    document.querySelectorAll<HTMLElement>('[data-theme-toggle]').forEach((button) => {
        const isDark = scheme === 'dark';
        button.setAttribute('aria-pressed', String(isDark));
        button.dataset.themeState = scheme;
        const label = button.getAttribute(isDark ? 'data-label-day' : 'data-label-night')
            || (isDark ? 'Day mode' : 'Night mode');
        button.setAttribute('aria-label', label);
        button.setAttribute('title', label);
    });
}

export function bindThemeToggles(): void {
    applyColorScheme(resolveColorScheme());
    syncThemeToggleButtons();
    document.querySelectorAll<HTMLElement>('[data-theme-toggle]').forEach((button) => {
        if (button.dataset.themeBound === '1') {
            return;
        }
        button.dataset.themeBound = '1';
        button.addEventListener('click', (event) => {
            event.preventDefault();
            syncThemeToggleButtons(toggleColorScheme());
        });
    });
}
