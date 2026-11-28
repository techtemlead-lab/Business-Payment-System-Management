import { useState } from 'react';
import { router } from '@inertiajs/react';
import { Modal } from './UI';
import PayoutAccountForm, { type PayoutAccount } from './PayoutAccountForm';
import { useT } from '../shared/i18n/t';

export type { PayoutAccount };

/**
 * V3: a dealer or sub-dealer keeps bank, bKash, Nagad and Rocket accounts here; the withdrawal dialog picks one.
 * Changing the area, place or code is not possible from this page.
 */
export default function PayoutAccounts({ accounts }: { accounts: PayoutAccount[] | null | undefined }) {
    const translate = useT();
    const t = (key: string, replace: Record<string, string> = {}) => translate('leftovers_partner', key, replace);
    const [editing, setEditing] = useState<PayoutAccount | 'new' | null>(null);
    const [removing, setRemoving] = useState<PayoutAccount | null>(null);
    if (!accounts) {
        return null;
    }

    return (
        <section data-testid="payout-accounts">
            <h3>{t('pa_title')}</h3>
            <p className="muted">{t('pa_hint')}</p>
            {accounts.length === 0 && <p className="muted">{t('pa_none')}</p>}
            <ul className="aed-docs">
                {accounts.map(account => (
                    <li key={account.id} data-testid="payout-account-row">
                        <b>{account.label}</b>
                        {account.channel === 'Bank' && <span className="muted"> — {account.branch}, {account.account_name}</span>}
                        {account.is_default && <span className="badge approved"> {t('pa_default_badge')}</span>}
                        {' '}
                        <button type="button" className="text-link" onClick={() => setEditing(account)}>{t('pa_edit')}</button>
                        {!account.is_default && <> · <button type="button" className="text-link" onClick={() => router.post(`/commands/payout-accounts/${account.id}/default`, {}, { preserveScroll: true })}>{t('pa_make_default')}</button></>}
                        {' · '}
                        <button type="button" className="text-link" onClick={() => setRemoving(account)}>{t('pa_remove')}</button>
                    </li>
                ))}
            </ul>
            <button type="button" className="button" data-testid="payout-account-add" onClick={() => setEditing('new')}>{t('pa_add')}</button>

            {editing !== null && (
                <Modal title={editing === 'new' ? t('pa_add') : t('pa_edit')} onClose={() => setEditing(null)}>
                    <PayoutAccountForm key={editing === 'new' ? 'new' : editing.id} account={editing === 'new' ? null : editing} onSaved={() => setEditing(null)} />
                </Modal>
            )}

            {removing !== null && (
                <Modal title={t('pa_remove_ask')} onClose={() => setRemoving(null)}
                    footer={(
                        <>
                            <button type="button" className="button" onClick={() => setRemoving(null)}>{t('pa_cancel')}</button>
                            <button type="button" className="button dark" data-testid="payout-account-remove-yes" onClick={() => { router.post(`/commands/payout-accounts/${removing.id}/remove`, {}, { preserveScroll: true }); setRemoving(null); }}>{t('pa_remove')}</button>
                        </>
                    )}
                >
                    <p>{removing.label}</p>
                </Modal>
            )}
        </section>
    );
}
