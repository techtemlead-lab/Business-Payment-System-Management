/**
 * Small non-blocking toast for editor feedback. Replaces window.alert(),
 * which freezes the page and cannot be styled or read by screen readers well.
 */
export type NotifyTone = 'info' | 'error' | 'success';

const REGION_ID = 'app-toast-region';

function region(): HTMLElement {
    let element = document.getElementById(REGION_ID);
    if (!element) {
        element = document.createElement('div');
        element.id = REGION_ID;
        element.className = 'app-toast-region';
        element.setAttribute('aria-live', 'polite');
        element.setAttribute('aria-atomic', 'false');
        document.body.appendChild(element);
    }

    return element;
}

export function notify(message: string, tone: NotifyTone = 'info', timeoutMs = 4500): void {
    if (typeof document === 'undefined' || message.trim() === '') {
        return;
    }
    const toast = document.createElement('div');
    toast.className = `app-toast app-toast-${tone}`;
    toast.setAttribute('role', tone === 'error' ? 'alert' : 'status');
    toast.textContent = message;

    const close = document.createElement('button');
    close.type = 'button';
    close.className = 'app-toast-close';
    close.setAttribute('aria-label', '×');
    close.textContent = '×';
    close.addEventListener('click', () => toast.remove());
    toast.appendChild(close);

    region().appendChild(toast);
    window.setTimeout(() => toast.remove(), timeoutMs);
}
