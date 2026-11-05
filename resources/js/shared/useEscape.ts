import { useEffect } from 'react';

/**
 * Close a custom overlay/dialog with the Escape key while it is open.
 * The shared <Modal> in UI.tsx uses <dialog> and gets this for free; hub
 * screens with their own overlay call this instead.
 */
export function useEscape(active: boolean, onClose: () => void): void {
    useEffect(() => {
        if (!active) {
            return undefined;
        }
        const handle = (event: KeyboardEvent) => {
            if (event.key === 'Escape') {
                event.preventDefault();
                onClose();
            }
        };
        window.addEventListener('keydown', handle);

        return () => window.removeEventListener('keydown', handle);
    }, [active, onClose]);
}
