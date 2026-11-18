export type TextSize = 'normal' | 'large';

export const TEXT_SIZE_STORAGE_KEY = 'madina-text-size';

/** The size this browser chose; "normal" when nothing was chosen or storage is blocked. */
export function resolveTextSize(): TextSize {
    try {
        return window.localStorage.getItem(TEXT_SIZE_STORAGE_KEY) === 'large' ? 'large' : 'normal';
    } catch {
        return 'normal';
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
        // The size still applies for this visit.
    }
}
