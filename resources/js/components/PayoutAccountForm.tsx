import { useForm } from '@inertiajs/react';
import { FormErrors } from './UI';
import { useT } from '../shared/i18n/t';

export type PayoutAccount = { id: number; channel: string; label: string; is_default: boolean; bank_name: string; branch: string; account_name: string; number: string };

export const payoutChannels = ['Bank', 'bKash', 'Nagad', 'Rocket'] as const;

/**
 * The add / change form of one saved payout account. The profile page, the wallet page and the withdrawal dialog all
 * use it, so a partner who has no account yet can add one right where he is asked for it.
 */
export default function PayoutAccountForm({ account, channels = payoutChannels, onSaved }: { account: PayoutAccount | null; channels?: readonly string[]; onSaved: () => void }) {
    const translate = useT();
    const t = (key: string) => translate('leftovers_partner', key);
    const form = useForm({
        channel: account?.channel ?? (channels.includes('bKash') ? 'bKash' : channels[0] ?? 'bKash'),
        number: account?.number ?? '',
        bank_name: account?.bank_name ?? '',
        branch: account?.branch ?? '',
        account_name: account?.account_name ?? '',
    });
    const isBank = form.data.channel === 'Bank';
    const channelName = (channel: string) => translate('portal', `pay_channel_${channel.toLowerCase()}`);
    const save = (event: React.FormEvent) => {
        event.preventDefault();
        form.post(account === null ? '/commands/payout-accounts' : `/commands/payout-accounts/${account.id}`, { preserveScroll: true, onSuccess: onSaved });
    };

    return (
        <form className="action-form" onSubmit={save} data-testid="payout-account-form">
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
    );
}
