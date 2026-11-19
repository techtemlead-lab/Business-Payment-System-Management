import { useState } from 'react';
import { router } from '@inertiajs/react';
import { useT } from '../shared/i18n/t';

type Doc = { id: string; claimed_nid?: string | null };

/**
 * R5: staff compare the NID number a partner typed with the photo, then accept it (the number moves to the
 * account and the first withdrawal opens) or send it back with a reason the partner can read.
 */
export default function NidReview({ accountId, doc, onDone }: { accountId: number | string; doc: Doc; onDone: () => void }) {
    const translate = useT();
    const t = (key: string) => translate('leftovers_partner', key);
    const [note, setNote] = useState('');
    const [busy, setBusy] = useState(false);
    const send = (decision: 'approve' | 'send_back') => {
        setBusy(true);
        router.post(`/commands/accounts/${accountId}/kyc-documents/${doc.id}/review`, { decision, note }, {
            preserveScroll: true,
            onSuccess: () => onDone(),
            onFinish: () => setBusy(false),
        });
    };

    return (
        <div className="notice" data-testid="nid-review">
            <p><b>{t('review_pending_label')}</b>{doc.claimed_nid ? ` · ${t('review_claimed_label')}: ${doc.claimed_nid}` : ''}</p>
            <label>{t('review_reason_label')}<input type="text" maxLength={300} value={note} onChange={event => setNote(event.target.value)} /></label>
            <button type="button" className="button dark" disabled={busy} onClick={() => send('approve')}>{t('review_approve')}</button>
            <button type="button" className="button" disabled={busy || note.trim() === ''} onClick={() => send('send_back')}>{t('review_send_back')}</button>
        </div>
    );
}
