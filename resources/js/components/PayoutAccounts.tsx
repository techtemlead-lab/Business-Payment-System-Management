import { useState } from 'react';
import { router, useForm } from '@inertiajs/react';
import { FormErrors, Modal } from './UI';
import { useT } from '../shared/i18n/t';

export type PayoutAccount = { id: number; channel: string; label: string; is_default: boolean; bank_name: string; branch: string; account_name: string; number: string };

const channels = ['Bank', 'bKash', 'Nagad', 'Rocket'] as const;

/**
 * V3: a dealer or sub-dealer keeps bank, bKash, Nagad and Rocket accounts here; the withdrawal dialog picks one.
 * Changing the area, place or code is not possible from this page.
 */
export default function PayoutAccounts({ accounts }: { accounts: PayoutAccount[] | null | undefined }) {
    const translate = useT();
    const t = (key: string, replace: Record<string, string> = {}) => translate('leftovers_partner', key, replace);
    const [editing, setEditing] = useState<PayoutAccount | 'new' | null>(null);
    const [removing, setRemoving] = useState<PayoutAccount | null>(null);
    const form = useForm({ channel: 'bKash', number: '', bank_name: '', branch: '', account_name: '' });
    if (!accounts) {
        return null;
    }
    const open = (account: PayoutAccount | 'new') => {
        form.clearErrors();
        form.setData(account === 'new'
            ? { channel: 'bKash', number: '', bank_name: '', branch: '', account_name: '' }
            : { channel: account.channel, number: account.number, bank_name: account.bank_name, branch: account.branch, account_name: account.account_name });
        setEditing(account);
    };
    const save = (event: React.FormEvent) => {
        event.preventDefault();
        const url = editing === 'new' || editing === null ? '/commands/payout-accounts' : `/commands/payout-accounts/${editing.id}`;
        form.post(url, { preserveScroll: true, onSuccess: () => setEditing(null) });
    };
    const isBank = form.data.channel === 'Bank';
    const channelName = (channel: string) => translate('portal', `pay_channel_${channel.toLowerCase()}`);

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
                        <button type="button" className="text-link" onClick={() => open(account)}>{t('pa_edit')}</button>
                        {!account.is_default && <> · <button type="button" className="text-link" onClick={() => router.post(`/commands/payout-accounts/${account.id}/default`, {}, { preserveScroll: true })}>{t('pa_make_default')}</button></>}
                        {' · '}
                        <button type="button" className="text-link" onClick={() => setRemoving(account)}>{t('pa_remove')}</button>
                    </li>
                ))}
            </ul>
            <button type="button" className="button" data-testid="payout-account-add" onClick={() => open('new')}>{t('pa_add')}</button>

            {editing !== null && (
                <Modal title={editing === 'new' ? t('pa_add') : t('pa_edit')} onClose={() => setEditing(null)}>
                    <form className="action-form" onSubmit={save}>
                        <label>{t('pa_channel')}
                            <select value={form.data.channel} onChange={event => form.setData('channel', event.target.value)}>
                                {channels.map(channel => <option key={channel} value={channel}>{channelName(channel)}</option>)}
                            </select>
                        </label>
                        {isBank && <label>{t('pa_bank_name')}<input type="text" value={form.data.bank_name} onChange={event => form.setData('bank_name', event.target.value)} required />{form.errors.bank_name && <span className="field-error" role="alert">{form.errors.bank_name}</span>}</label>}
                        {isBank && <label>{t('pa_branch')}<input type="text" value={form.data.branch} onChange={event => form.setData('branch', event.target.value)} required />{form.errors.branch && <span className="field-error" role="alert">{form.errors.branch}</span>}</label>}
                        {isBank && <label>{t('pa_account_name')}<input type="text" value={form.data.account_name} onChange={event => form.setData('account_name', event.target.value)} required />{form.errors.account_name && <span className="field-error" role="alert">{form.errors.account_name}</span>}</label>}
                        <label>{isBank ? t('pa_bank_number') : t('pa_mobile_number')}
                            <input type="text" inputMode="numeric" value={form.data.number} onChange={event => form.setData('number', event.target.value)} required />
                            {form.errors.number && <span className="field-error" role="alert">{form.errors.number}</span>}
                        </label>
                        <FormErrors errors={form.errors} besideFields={['number', 'bank_name', 'branch', 'account_name']} />
                        <button className="button dark" disabled={form.processing}>{t('pa_save')}</button>
                    </form>
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
