import { useEffect, useRef, useState } from 'react';
import { router } from '@inertiajs/react';
import { LogOut } from 'lucide-react';
import { useT } from '../shared/i18n/t';

/**
 * A plain "Sign out" button for dealers and sub-dealers (V1). One tap opens a small question, a second tap signs out.
 * `bar` sits in the top bar on a desktop; `drawer` is the first item of the menu on a phone. CSS shows one of them.
 */
export default function PartnerSignOut({ variant }: { variant: 'bar' | 'drawer' }) {
    const translate = useT();
    const c = (key: string) => translate('cr21_identity', key);
    const [asking, setAsking] = useState(false);
    const stay = useRef<HTMLButtonElement>(null);

    useEffect(() => {
        if (!asking) {
            return undefined;
        }
        stay.current?.focus();
        const onKey = (event: KeyboardEvent) => {
            if (event.key === 'Escape') {
                setAsking(false);
            }
        };
        window.addEventListener('keydown', onKey);

        return () => window.removeEventListener('keydown', onKey);
    }, [asking]);

    return (
        <>
            <button type="button" className={`psignout psignout-${variant}`} data-testid={`partner-signout-${variant}`} onClick={() => setAsking(true)}>
                <LogOut size={18} aria-hidden="true" />
                <span>{c('sign_out')}</span>
            </button>
            {asking && (
                <div className="psignout-backdrop" onClick={() => setAsking(false)}>
                    <div className="psignout-dialog" role="alertdialog" aria-modal="true" aria-labelledby="psignout-title" onClick={(event) => event.stopPropagation()}>
                        <h2 id="psignout-title">{c('sign_out_ask')}</h2>
                        <div className="psignout-actions">
                            <button type="button" className="button dark" data-testid="partner-signout-confirm" onClick={() => router.post('/logout')}>{c('sign_out_yes')}</button>
                            <button type="button" className="button" ref={stay} onClick={() => setAsking(false)}>{c('sign_out_no')}</button>
                        </div>
                    </div>
                </div>
            )}
        </>
    );
}
