import { useState } from 'react';
import { useForm } from '@inertiajs/react';
import { Badge, Empty, FormErrors, Modal, label } from './UI';
import { useT } from '../shared/i18n/t';

export type OwnerAccount = { id: number; name: string; role: string; status: string };

/** On the employees page, for the Super Admin only: turn an MD or Chairman account off and back on. */
export default function OwnerAccountsPanel({ accounts = [] }: { accounts?: OwnerAccount[] }) {
    const translate = useT();
    const [opened, setOpened] = useState<OwnerAccount | null>(null);
    const form = useForm({ status: 'suspended', reason: '' });

    const open = (account: OwnerAccount) => {
        form.clearErrors();
        form.setData({ status: account.status === 'active' ? 'suspended' : 'active', reason: '' });
        setOpened(account);
    };

    const save = (event: React.FormEvent) => {
        event.preventDefault();
        if (opened === null) {
            return;
        }
        form.patch(`/commands/accounts/${opened.id}`, {
            preserveScroll: true,
            onSuccess: () => setOpened(null),
        });
    };

    const turningOff = opened?.status === 'active';
    const buttonText = translate('portal', turningOff ? 'owner_status_turn_off' : 'owner_status_turn_on');

    if (accounts.length === 0) {
        return <Empty line title={translate('portal', 'owner_status_empty')} />;
    }

    return (
        <>
            <ul className="rx-list">
                {accounts.map((account) => (
                    <li key={account.id} className="rx-card">
                        <div className="rx-card-head">
                            <div>
                                <b>{account.name}</b>
                                <small className="cell-sub">{label(account.role, translate)}</small>
                            </div>
                            <span className="rx-actions">
                                <Badge status={account.status} />
                                <button type="button" className="button small" onClick={() => open(account)}>
                                    {translate('portal', account.status === 'active' ? 'owner_status_turn_off' : 'owner_status_turn_on')}
                                </button>
                            </span>
                        </div>
                    </li>
                ))}
            </ul>

            {opened && (
                <Modal title={translate('office_staff', turningOff ? 'owner_turn_off_title' : 'owner_turn_on_title', { name: opened.name })} onClose={() => setOpened(null)}>
                    <form className="action-form" onSubmit={save}>
                        <p className="muted">{translate('portal', turningOff ? 'owner_status_off_note' : 'owner_status_on_note')}</p>
                        <label>{translate('portal', 'owner_status_reason')}
                            <textarea required maxLength={1000} rows={2} value={form.data.reason} onChange={(event) => form.setData('reason', event.target.value)} />
                        </label>
                        <FormErrors errors={form.errors} />
                        <div className="ops-row is-end is-loose">
                            <button type="button" className="button" disabled={form.processing} onClick={() => setOpened(null)}>{translate('portal', 'owner_status_cancel')}</button>
                            <button type="submit" className="button dark" disabled={form.processing}>{buttonText}</button>
                        </div>
                    </form>
                </Modal>
            )}
        </>
    );
}
