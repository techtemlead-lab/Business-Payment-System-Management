/**
 * Text size for dealers and sub-dealers. Four steps; the page is zoomed as a whole (see
 * screens-text-size.css), so everything grows together and nothing is left small. The choice is
 * kept in this browser and applied before the first paint by the theme-boot script.
 */
export type TextSize = 'small' | 'normal' | 'large' | 'xlarge';

export const TEXT_SIZES: TextSize[] = ['small', 'normal', 'large', 'xlarge'];
export const TEXT_SIZE_STORAGE_KEY = 'madina-text-size';
export const DEFAULT_TEXT_SIZE: TextSize = 'normal';

function isTextSize(value: unknown): value is TextSize {
    return typeof value === 'string' && (TEXT_SIZES as string[]).includes(value);
}

export function resolveTextSize(): TextSize {
    try {
        const stored = window.localStorage.getItem(TEXT_SIZE_STORAGE_KEY);

        return isTextSize(stored) ? stored : DEFAULT_TEXT_SIZE;
    } catch {
        return DEFAULT_TEXT_SIZE;
    }
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
