import { useState } from 'react';
import { useForm } from '@inertiajs/react';
import { ShieldAlert } from 'lucide-react';
import { Badge, Empty, FormErrors, label } from './UI';
import { useT } from '../shared/i18n/t';

export type OwnerAccount = { id: number; name: string; role: string; status: string };

/** On the employees page, for the Super Admin only: turn an MD or Chairman account off and back on. */
export default function OwnerAccountsPanel({ accounts = [] }: { accounts?: OwnerAccount[] }) {
    const translate = useT();
    const [openId, setOpenId] = useState<number | null>(null);
    const form = useForm({ status: 'suspended', reason: '' });

    const open = (account: OwnerAccount) => {
        form.clearErrors();
        form.setData({ status: account.status === 'active' ? 'suspended' : 'active', reason: '' });
        setOpenId(account.id);
    };

    const save = (event: React.FormEvent, account: OwnerAccount) => {
        event.preventDefault();
        form.patch(`/commands/accounts/${account.id}`, {
            preserveScroll: true,
            onSuccess: () => setOpenId(null),
        });
    };

    return (
        <section className="panel">
            <div className="panel-heading">
                <div>
                    <h2 className="ops-row is-inline"><ShieldAlert size={18} /> {translate('portal', 'owner_status_title')}</h2>
                    <p className="muted">{translate('portal', 'owner_status_hint')}</p>
                </div>
            </div>
            {accounts.length === 0 && <Empty title={translate('portal', 'owner_status_empty')} />}
            {accounts.length > 0 && (
                <ul className="rx-list">
                    {accounts.map((account) => {
                        const turningOff = account.status === 'active';
                        const buttonText = translate('portal', turningOff ? 'owner_status_turn_off' : 'owner_status_turn_on');

                        return (
                            <li key={account.id} className="rx-card">
                                <div className="rx-card-head">
                                    <div>
                                        <b>{account.name}</b>
                                        <small className="cell-sub">{label(account.role, translate)}</small>
                                    </div>
                                    <span className="rx-actions">
                                        <Badge status={account.status} />
                                        {openId !== account.id && <button type="button" className="button small" onClick={() => open(account)}>{buttonText}</button>}
                                    </span>
                                </div>
                                {openId === account.id && (
                                    <form className="action-form ops-mt-3" onSubmit={(event) => save(event, account)}>
                                        <p className="muted">{translate('portal', turningOff ? 'owner_status_off_note' : 'owner_status_on_note')}</p>
                                        <label>{translate('portal', 'owner_status_reason')}
                                            <textarea required maxLength={1000} rows={2} value={form.data.reason} onChange={(event) => form.setData('reason', event.target.value)} />
                                        </label>
                                        <FormErrors errors={form.errors} />
                                        <div className="ops-row is-wrap">
                                            <button type="submit" className="button dark" disabled={form.processing}>{buttonText}</button>
                                            <button type="button" className="button" disabled={form.processing} onClick={() => setOpenId(null)}>{translate('portal', 'owner_status_cancel')}</button>
                                        </div>
                                    </form>
                                )}
                            </li>
                        );
                    })}
                </ul>
            )}
        </section>
    );
}
