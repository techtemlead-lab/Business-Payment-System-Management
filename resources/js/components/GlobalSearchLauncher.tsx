import { lazy, Suspense, useEffect, useState } from 'react';
import { usePage } from '@inertiajs/react';
import { Search } from 'lucide-react';
import { useT } from '../shared/i18n/t';

const GlobalSearchPalette = lazy(() => import('./GlobalSearchPalette'));

/**
 * Search button in the office header. Ctrl+K opens it too. The search screen and its
 * results load only when opened, so pages stay the same size and make the same queries.
 */
export default function GlobalSearchLauncher() {
    const { portal, auth } = usePage<any>().props;
    const translate = useT();
    const [open, setOpen] = useState(false);
    const staff = portal === 'manage' || portal === 'sadmin' || auth?.user?.workspace_mode === 'staff';

    useEffect(() => {
        if (!staff) {
            return;
        }
        const onKey = (event: KeyboardEvent) => {
            if ((event.ctrlKey || event.metaKey) && event.key.toLowerCase() === 'k') {
                event.preventDefault();
                setOpen(true);
            }
        };
        document.addEventListener('keydown', onKey);
        return () => document.removeEventListener('keydown', onKey);
    }, [staff]);

    if (!staff) {
        return null;
    }

    return (
        <>
            <button
                type="button"
                className="global-search-trigger"
                aria-haspopup="dialog"
                aria-label={translate('productivity', 'search_open')}
                title={translate('productivity', 'search_open')}
                onClick={() => setOpen(true)}
            >
                <Search size={15} aria-hidden="true" />
                <span className="global-search-trigger-label">{translate('productivity', 'search_label')}</span>
                <kbd>Ctrl K</kbd>
            </button>
            {open && (
                <Suspense fallback={null}>
                    <GlobalSearchPalette onClose={() => setOpen(false)} />
                </Suspense>
            )}
        </>
    );
}
