export type ColorScheme = 'light' | 'dark';

export const THEME_STORAGE_KEY = 'madina-color-scheme';
export const THEME_COOKIE_NAME = 'madina_color_scheme';

function themeCookieDomain(): string {
    if (typeof window !== 'undefined' && typeof window.__MADINA_THEME_DOMAIN__ === 'string') {
        return window.__MADINA_THEME_DOMAIN__;
    }
    const meta = typeof document !== 'undefined'
        ? document.querySelector('meta[name="madina-theme-domain"]')
        : null;

    return meta ? String(meta.getAttribute('content') || '') : '';
}

function readCookie(name: string): string | null {
    if (typeof document === 'undefined') {
        return null;
    }
    const parts = (';' + document.cookie).split('; ' + name + '=');
    if (parts.length < 2) {
        return null;
    }

    return decodeURIComponent(parts.pop()?.split(';').shift() || '');
}

function writeCookie(name: string, value: string): void {
    if (typeof document === 'undefined') {
        return;
    }
    const domain = themeCookieDomain();
    const secure = typeof location !== 'undefined' && location.protocol === 'https:' ? '; Secure' : '';
    const domainPart = domain && domain !== 'localhost' && !domain.endsWith('.test') && !domain.endsWith('.local')
        ? `; Domain=.${domain}`
        : (domain.endsWith('.test') || domain.endsWith('.local') ? `; Domain=.${domain}` : '');
    document.cookie = `${name}=${encodeURIComponent(value)}; Path=/; Max-Age=31536000; SameSite=Lax${domainPart}${secure}`;
}

export function resolveColorScheme(stored: string | null = null): ColorScheme {
    const value = stored
        ?? readCookie(THEME_COOKIE_NAME)
        ?? (typeof localStorage !== 'undefined' ? localStorage.getItem(THEME_STORAGE_KEY) : null);
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
        // Private browsing / blocked storage — cookie may still sync portals.
    }
    try {
        writeCookie(THEME_COOKIE_NAME, scheme);
    } catch {
        // Ignore cookie write failures.
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

declare global {
    interface Window {
        __MADINA_THEME_DOMAIN__?: string;
    }
}
