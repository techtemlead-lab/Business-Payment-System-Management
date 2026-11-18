import { useState } from 'react';
import { Link, router, usePage } from '@inertiajs/react';
import { Banknote, PhoneCall } from 'lucide-react';
import { useT } from '../shared/i18n/t';

/**
 * Two big buttons for people who find the full order form hard: pay at the office, or ask the
 * office to call back (it sends one ready-made chat message, so staff answer by phone).
 */
export default function CallMeCard() {
    const translate = useT();
    const { portalBase: base = '', portalCatalog } = usePage<any>().props;
    const sections: string[] = portalCatalog?.sections ?? [];
    const [state, setState] = useState<'idle' | 'sending' | 'sent' | 'failed'>('idle');
    const canChat = sections.includes('messages');
    const canOrder = sections.includes('purchase');
    if (!canChat && !canOrder) {
        return null;
    }
    const askForCall = () => {
        setState('sending');
        router.post('/commands/messages', { body: translate('partner_ux', 'call_me_message') }, {
            preserveScroll: true,
            preserveState: true,
            only: ['navAttention', 'errors'],
            onSuccess: () => setState('sent'),
            onError: () => setState('failed'),
        });
    };

    return (
        <section className="ph-callme" aria-label={translate('partner_ux', 'call_me_title')}>
            <h3>{translate('partner_ux', 'call_me_title')}</h3>
            <div className="ph-callme-actions">
                {canOrder && (
                    <Link href={`${base}/purchase?pay=office`} className="button dark ph-callme-button">
                        <Banknote size={22} aria-hidden="true" /> {translate('partner_ux', 'pay_at_office')}
                    </Link>
                )}
                {canChat && (
                    <button type="button" className="button ph-callme-button" disabled={state === 'sending' || state === 'sent'} onClick={askForCall}>
                        <PhoneCall size={22} aria-hidden="true" /> {translate('partner_ux', 'ask_call')}
                    </button>
                )}
            </div>
            {state === 'sent' && <p className="ph-callme-note" role="status">{translate('partner_ux', 'call_me_sent')}</p>}
            {state === 'failed' && <p className="field-error" role="alert">{translate('partner_ux', 'call_me_failed')}</p>}
        </section>
    );
}
