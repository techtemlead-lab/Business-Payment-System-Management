/**
 * One text-size setting for every portal. The choice is kept in this browser under one key and
 * applied before the first paint by the theme-boot script (`html[data-text-size]`).
 *
 * - Dealers and sub-dealers pick one of four steps (TextSizeSwitch); the partner page is zoomed as
 *   a whole (screens-text-size.css), so everything grows together.
 * - Staff and owner panels offer normal or larger (TextSizeToggle); "larger" raises the panel's
 *   font tokens (screens-office-shell.css).
 */
export type TextSize = 'small' | 'normal' | 'large' | 'xlarge';

/** The four steps offered on the partner portals. */
export const TEXT_SIZES: TextSize[] = ['small', 'normal', 'large', 'xlarge'];
/** The two steps offered on the staff and owner panels. */
export const PANEL_TEXT_SIZES: TextSize[] = ['normal', 'large'];
export const TEXT_SIZE_STORAGE_KEY = 'madina-text-size';
export const DEFAULT_TEXT_SIZE: TextSize = 'normal';

function isTextSize(value: unknown): value is TextSize {
    return typeof value === 'string' && (TEXT_SIZES as string[]).includes(value);
}

/** The size this browser chose; "normal" when nothing was chosen or storage is blocked. */
export function resolveTextSize(): TextSize {
    try {
        const stored = window.localStorage.getItem(TEXT_SIZE_STORAGE_KEY);

        return isTextSize(stored) ? stored : DEFAULT_TEXT_SIZE;
    } catch {
        return DEFAULT_TEXT_SIZE;
    }
}

/** The nearest panel step for any stored size: anything above normal reads as "larger". */
export function panelTextSize(size: TextSize): TextSize {
    return size === 'large' || size === 'xlarge' ? 'large' : 'normal';
}

export function applyTextSize(size: TextSize): void {
    document.documentElement.setAttribute('data-text-size', size);
}

export function persistTextSize(size: TextSize): void {
    applyTextSize(size);
    try {
        window.localStorage.setItem(TEXT_SIZE_STORAGE_KEY, size);
    } catch {
        // Storage blocked: the size still applies for this visit.
    }
}
